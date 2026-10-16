<?php
/**
 * MainWP Backup Snapshots.
 *
 * Durable per-run backup records. Each completed backup run is recorded
 * as a snapshot row carrying the run type, the chain parent, where the
 * downloaded artifacts and manifest live locally, and a status used by
 * retention. Incremental backups resolve their base through these rows.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Snapshots
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Snapshots extends MainWP_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, table identifiers cannot be prepared.

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Snapshots or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Snapshots
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Backup-health bucket keys. Internal identifiers - the UI maps these
     * to user-facing labels and badge colors.
     */
    const HEALTH_BUCKET_PROTECTED = 'protected';
    const HEALTH_BUCKET_AT_RISK   = 'at_risk';
    const HEALTH_BUCKET_CRITICAL  = 'critical';
    const HEALTH_BUCKET_UNKNOWN   = 'unknown';

    /**
     * Keep removal causes separate from the complete/pruned lifecycle.
     */
    const REMOVAL_REASON_UNKNOWN      = '';
    const REMOVAL_REASON_MANUAL       = 'manual';
    const REMOVAL_REASON_RETENTION    = 'retention';
    const REMOVAL_REASON_TASK_DELETED = 'task_deleted';
    const REMOVAL_REASON_CLEANUP      = 'cleanup';

    /**
     * Age thresholds for backup-health classification, in seconds. A
     * snapshot newer than the at-risk window can be Protected; one older
     * than the critical window is Critical.
     */
    const HEALTH_WINDOW_AT_RISK  = DAY_IN_SECONDS;
    const HEALTH_WINDOW_CRITICAL = 7 * DAY_IN_SECONDS;

    /**
     * Request-scoped backup-health map cache, keyed by user id. The fleet
     * health map is computed once per user per request and reused by the
     * column, the count-bar, the status filter and the summary widget so a
     * page render issues a single latest-per-site query.
     *
     * @var array<int, array<int, string>>
     */
    private static $health_map_cache = array();

    /**
     * Record a completed backup run as a snapshot row.
     *
     * @param array $data {
     *     Snapshot fields.
     *
     *     @type int      $task_id       Scheduled task id.
     *     @type int      $site_id       Child site id.
     *     @type string   $type          What the run produced: 'full' or 'incremental'.
     *     @type string|null $run_key    Durable run identity; null keeps legacy insert behavior.
     *     @type int|null $parent_id     Parent snapshot id, null for a base full.
     *     @type array    $archives      Downloaded artifact list: file, size, role per entry.
     *     @type string   $manifest_path Local path of the downloaded file manifest.
     *     @type array    $woo_meta      Optional Woo order-storage facts the child
     *                                   captured at dump time (has_wc_orders,
     *                                   woocommerce_db_version), read by the restore
     *                                   initiate handler's preserve-orders gate.
     * }
     *
     * @return int Snapshot id, 0 on failure.
     */
    public function record_snapshot( array $data ): int {
        $archives = '[]';
        if ( isset( $data['archives'] ) && is_array( $data['archives'] ) ) {
            $archives = wp_json_encode( $data['archives'] );
            if ( false === $archives ) {
                return 0;
            }
        }

        $row = array(
            'task_id'       => isset( $data['task_id'] ) ? (int) $data['task_id'] : 0,
            'site_id'       => isset( $data['site_id'] ) ? (int) $data['site_id'] : 0,
            'type'          => isset( $data['type'] ) ? (string) $data['type'] : 'full',
            'created'       => time(),
            'archives'      => $archives,
            'manifest_path' => isset( $data['manifest_path'] ) ? (string) $data['manifest_path'] : '',
            'status'        => 'complete',
        );

        $format = array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' );

        // Persist intent with the initial insert, before another finisher or
        // upload listener can read this row, even when the key is unavailable.
        $requires_encryption = MainWP_Backup_Encryption::instance()->is_enabled_setting();
        if ( $requires_encryption ) {
            $row['encryption'] = '{"required":1}';
            $format[]          = '%s';
        }

        if ( ! empty( $data['parent_id'] ) ) {
            $row['parent_id'] = (int) $data['parent_id'];
            $format[]         = '%d';
        }

        if ( ! empty( $data['woo_meta'] ) && is_array( $data['woo_meta'] ) ) {
            $woo_meta = wp_json_encode( $data['woo_meta'] );
            if ( false !== $woo_meta ) {
                $row['woo_meta'] = $woo_meta;
                $format[]        = '%s';
            }
        }

        if ( isset( $data['run_key'] ) && MainWP_Install::backup_snapshot_run_key_ready() ) {
            $row['run_key'] = (string) $data['run_key'];
            $format[]       = '%s';
            $snapshot_id    = (int) $this->wpdb->get_var(
                $this->wpdb->prepare( 'SELECT id FROM ' . $this->table_name( 'wp_backup_snapshot' ) . ' WHERE run_key = %s', $row['run_key'] )
            );
            if ( $snapshot_id > 0 ) {
                return $snapshot_id;
            }

            $suppress = $this->wpdb->suppress_errors();
            try {
                $inserted = $this->wpdb->insert( $this->table_name( 'wp_backup_snapshot' ), $row, $format );
                $error    = $this->wpdb->last_error;
            } finally {
                $this->wpdb->suppress_errors( $suppress );
            }
            if ( false === $inserted ) {
                // The unique key arbitrates concurrent finishers that both passed the lookup.
                if ( false !== strpos( $error, 'Duplicate entry' ) && false !== strpos( $error, 'uniq_run_key' ) ) {
                    return (int) $this->wpdb->get_var(
                        $this->wpdb->prepare( 'SELECT id FROM ' . $this->table_name( 'wp_backup_snapshot' ) . ' WHERE run_key = %s', $row['run_key'] )
                    );
                }
                return 0;
            }
            $snapshot_id = (int) $this->wpdb->insert_id;
        } else {
            $inserted = $this->wpdb->insert( $this->table_name( 'wp_backup_snapshot' ), $row, $format );
            if ( ! $inserted ) {
                return 0;
            }
            $snapshot_id = (int) $this->wpdb->insert_id;
        }

        // When encryption is armed, mint this snapshot's key material and store
        // it before any upload job can run, so the wrapped DEK is bound to this
        // exact snapshot id. Fail closed: if the metadata cannot be persisted,
        // drop the row so no upload ever ships this snapshot's bytes in clear.
        if ( $requires_encryption && MainWP_Backup_Encryption::instance()->is_armed() && ! $this->attach_encryption_meta( $snapshot_id ) ) {
            $this->wpdb->delete( $this->table_name( 'wp_backup_snapshot' ), array( 'id' => $snapshot_id ), array( '%d' ) );
            return 0;
        }

        /**
         * Fires after a completed backup run is recorded as a snapshot.
         *
         * The cloud upload driver listens here to enqueue upload jobs for
         * the task's configured storage destinations.
         *
         * @param int $snapshot_id Snapshot id.
         * @param int $task_id     Scheduled task id.
         * @param int $site_id     Child site id.
         */
        do_action( 'mainwp_backup_snapshot_recorded', $snapshot_id, $row['task_id'], $row['site_id'] );

        return $snapshot_id;
    }

    /**
     * Get a snapshot row by id, with the archives list decoded.
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return object|null Snapshot row or null when not found.
     */
    public function get_snapshot_by_id( int $snapshot_id ): ?object {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_snapshot' ) . ' WHERE id = %d',
                $snapshot_id
            )
        );
        return $this->decode_row( $row );
    }

    /**
     * Get the newest complete snapshot for a site and task - the base the
     * next incremental run diffs against. Null means no usable base exists
     * and the caller runs a full backup.
     *
     * @param int $site_id Child site id.
     * @param int $task_id Scheduled task id.
     *
     * @return object|null Snapshot row or null when none exists.
     */
    public function get_latest_complete( int $site_id, int $task_id ): ?object {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_snapshot' ) . " WHERE site_id = %d AND task_id = %d AND status = 'complete' ORDER BY id DESC LIMIT 1",
                $site_id,
                $task_id
            )
        );
        return $this->decode_row( $row );
    }

    /**
     * Get the newest complete snapshot for a task across all of its sites
     * - the run the backup list summarizes upload state from. Pruned rows
     * stay for audit but never represent the task.
     *
     * @param int $task_id Scheduled task id.
     *
     * @return object|null Snapshot row or null when none exists.
     */
    public function get_latest_for_task( int $task_id ): ?object {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_snapshot' ) . " WHERE task_id = %d AND status = 'complete' ORDER BY id DESC LIMIT 1",
                $task_id
            )
        );
        return $this->decode_row( $row );
    }

    /**
     * Get the newest complete snapshot for each of the given sites in one
     * query - the bulk read behind the fleet backup-health view.
     *
     * "Newest" is the highest id complete row per site, matching every
     * other snapshot read; id is monotonic with insert order. Pruned and
     * other non-complete rows are excluded, so a site whose only rows are
     * pruned is absent from the result and the caller treats it as having
     * no backup. No archives decode - callers only need created/state.
     *
     * @param int[] $site_ids Child site ids. Empty or all-invalid returns [].
     *
     * @return array<int, object> Rows keyed by site_id: id, site_id,
     *                            created, offsite_state, status.
     */
    public function get_latest_per_site( array $site_ids ): array {
        $site_ids = array_values(
            array_filter(
                array_map( 'intval', $site_ids ),
                static function ( $id ) {
                    return $id > 0;
                }
            )
        );

        if ( empty( $site_ids ) ) {
            return array();
        }

        $table        = $this->table_name( 'wp_backup_snapshot' );
        $placeholders = implode( ',', array_fill( 0, count( $site_ids ), '%d' ) );

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT s.id, s.site_id, s.type, s.created, s.offsite_state, s.verified_at, s.status, s.test_restore_state, s.test_restore_at, s.test_restore_result_at FROM ' . $table . ' s '
                . 'JOIN ( SELECT site_id, MAX(id) AS max_id FROM ' . $table . ' '
                . "WHERE status = 'complete' AND site_id IN ( {$placeholders} ) GROUP BY site_id ) latest ON s.id = latest.max_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a counted %d list over intval-sanitized ids; table identifier from table_name().
                ...$site_ids
            )
        );

        $keyed = array();
        foreach ( (array) $rows as $row ) {
            $keyed[ (int) $row->site_id ] = $row;
        }
        return $keyed;
    }

    /**
     * Classify a site's backup health from its latest complete snapshot.
     *
     * Pure: takes the snapshot row (or null) and the current epoch so the
     * thresholds are testable without clock dependence. A recent complete
     * snapshot whose offsite upload failed stays At-Risk, not Critical -
     * the local copy still exists; offsite verification only separates
     * Protected from At-Risk inside the 24h window.
     *
     * @param object|null $snapshot Latest complete snapshot row, or null.
     * @param int         $now      Current epoch.
     *
     * @return string One of the HEALTH_BUCKET_* constants.
     */
    public static function classify_backup_health( ?object $snapshot, int $now ): string {
        if ( null === $snapshot ) {
            return self::HEALTH_BUCKET_UNKNOWN;
        }

        $age = $now - (int) $snapshot->created;

        if ( $age > self::HEALTH_WINDOW_CRITICAL ) {
            return self::HEALTH_BUCKET_CRITICAL;
        }

        if ( $age <= self::HEALTH_WINDOW_AT_RISK && 'verified' === (string) $snapshot->offsite_state ) {
            return self::HEALTH_BUCKET_PROTECTED;
        }

        // 24h < age <= 7d (any state), or age <= 24h but not verified.
        return self::HEALTH_BUCKET_AT_RISK;
    }

    /**
     * Build the backup-health bucket map for every site the current user
     * can see, computed once per request.
     *
     * Gated: returns an empty map (and runs no query) unless native backups
     * are enabled and the user holds the execute_backups capability - the
     * same gate the legacy backup column and the snapshots page use. The
     * result is memoized per user for the request so the column, count-bar,
     * status filter and summary widget share a single latest-per-site read.
     *
     * @return array<int, string> Bucket key (HEALTH_BUCKET_*) keyed by site_id.
     */
    public function get_health_map_for_current_user(): array {
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled()
            || ! \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            return array();
        }

        $user_id = get_current_user_id();
        if ( isset( static::$health_map_cache[ $user_id ] ) ) {
            return static::$health_map_cache[ $user_id ];
        }

        $sql    = MainWP_DB::instance()->get_sql_websites_for_current_user_by_params(
            array( 'select_wp_fields' => array( 'id' ) )
        );
        $result = MainWP_DB::instance()->query( $sql );

        $site_ids = array();
        while ( $result && ( $site = MainWP_DB::fetch_object( $result ) ) ) {
            $site_ids[] = (int) $site->id;
        }

        $snapshots = $this->get_latest_per_site( $site_ids );
        $now       = time();
        $map       = array();
        foreach ( $site_ids as $id ) {
            $map[ $id ] = static::classify_backup_health( isset( $snapshots[ $id ] ) ? $snapshots[ $id ] : null, $now );
        }

        static::$health_map_cache[ $user_id ] = $map;
        return $map;
    }

    /**
     * Clear the request-scoped backup-health map cache. For tests and any
     * caller that mutates snapshots and needs a fresh classification.
     *
     * @return void
     */
    public static function flush_health_map_cache(): void {
        static::$health_map_cache = array();
    }

    /**
     * Get every snapshot recorded for a site, newest first.
     *
     * Pruned rows are included - the restore catalog shows them greyed
     * out so the history stays honest about what retention removed.
     *
     * @param int $site_id Child site id.
     *
     * @return object[] Snapshot rows, newest first.
     */
    public function get_snapshots_for_site( int $site_id ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_snapshot' ) . ' WHERE site_id = %d ORDER BY id DESC',
                $site_id
            )
        );

        $decoded = array();
        foreach ( (array) $rows as $row ) {
            $decoded[] = $this->decode_row( $row );
        }
        return $decoded;
    }

    /**
     * Protect artifacts until their owning snapshots are pruned, including manifests.
     * Retention excludes its own row so shared bytes survive while unshared bytes can be removed.
     *
     * @param int $site_id Child site id.
     * @param int $exclude_snapshot_id Snapshot being pruned, or zero to protect all owners.
     *
     * @return array<string, true> Set keyed by normalised absolute path.
     */
    public function get_protected_paths_for_site( int $site_id, int $exclude_snapshot_id = 0 ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT archives, manifest_path FROM ' . $this->table_name( 'wp_backup_snapshot' ) . " WHERE site_id = %d AND status <> 'pruned' AND id <> %d",
                $site_id,
                $exclude_snapshot_id
            )
        );

        $paths = array();
        foreach ( (array) $rows as $row ) {
            $archives = json_decode( (string) $row->archives, true );
            $archives = is_array( $archives ) ? $archives : array();
            if ( ! empty( $row->manifest_path ) ) {
                $archives[] = array( 'path' => $row->manifest_path );
            }
            foreach ( $archives as $archive ) {
                if ( ! empty( $archive['path'] ) && is_string( $archive['path'] ) ) {
                    $paths[ static::normalise_path( $archive['path'] ) ] = true;
                    $real = realpath( $archive['path'] );
                    if ( false !== $real ) {
                        $paths[ static::normalise_path( $real ) ] = true;
                    }
                }
            }
        }
        return $paths;
    }

    /**
     * Lexically normalise an absolute path for set-membership checks.
     *
     * Collapses doubled separators and trims a trailing one. Deliberately
     * not realpath(): the sweeper compares paths for files that may not
     * exist on disk at comparison time, where realpath() returns false.
     *
     * @param string $path Absolute path.
     *
     * @return string Normalised path.
     */
    public static function normalise_path( string $path ): string {
        return rtrim(
            str_replace( DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path ),
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * Walk a snapshot chain from a leaf back through its parents.
     *
     * Returns the chain base first so a restore can apply rows in order.
     * A parent link pointing at a missing row ends the walk at the break;
     * the caller treats a chain that does not start with a full backup as
     * having no usable base.
     *
     * @param int $snapshot_id Leaf snapshot id.
     *
     * @return object[] Snapshot rows, base first.
     */
    public function get_chain( int $snapshot_id ): array {
        $chain   = array();
        $current = $snapshot_id;
        $seen    = array();

        while ( $current && ! isset( $seen[ $current ] ) ) {
            $seen[ $current ] = true;

            $row = $this->get_snapshot_by_id( $current );
            if ( null === $row ) {
                break;
            }

            array_unshift( $chain, $row );
            $current = empty( $row->parent_id ) ? 0 : (int) $row->parent_id;
        }

        return $chain;
    }

    /**
     * Update a snapshot's status.
     *
     * @param int    $snapshot_id Snapshot id.
     * @param string $status      New status, e.g. 'pruned'.
     *
     * @return bool False when the status write fails.
     */
    public function set_status( int $snapshot_id, string $status ): bool {
        return false !== $this->wpdb->update(
            $this->table_name( 'wp_backup_snapshot' ),
            array( 'status' => $status ),
            array( 'id' => $snapshot_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Preserve the first removal cause even when a site reservation has expired.
     *
     * @param int    $snapshot_id    Snapshot identity.
     * @param string $removal_reason Removal cause; unrecognized values stay unknown.
     * @return bool Whether this call changed a live row to pruned.
     */
    public function mark_pruned( int $snapshot_id, string $removal_reason ): bool {
        if ( ! in_array( $removal_reason, array( self::REMOVAL_REASON_MANUAL, self::REMOVAL_REASON_RETENTION, self::REMOVAL_REASON_TASK_DELETED, self::REMOVAL_REASON_CLEANUP ), true ) ) {
            $removal_reason = self::REMOVAL_REASON_UNKNOWN;
        }
        $table = $this->table_name( 'wp_backup_snapshot' );
        if ( MainWP_Install::backup_snapshot_removal_reason_ready() ) {
            $sql = $this->wpdb->prepare(
                "UPDATE `{$table}` SET `status` = 'pruned', `removal_reason` = %s WHERE `id` = %d AND `status` <> 'pruned'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifiers cannot be prepared.
                $removal_reason,
                $snapshot_id
            );
        } else {
            $sql = $this->wpdb->prepare(
                "UPDATE `{$table}` SET `status` = 'pruned' WHERE `id` = %d AND `status` <> 'pruned'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifiers cannot be prepared.
                $snapshot_id
            );
        }
        return 1 === $this->wpdb->query( $sql );
    }

    /**
     * Only native run identities can name a Child task safely after pruning.
     *
     * @param int $limit        Maximum rows to select.
     * @param int $max_attempts Failed attempts allowed.
     * @return object[] Undecoded cleanup rows, least attempted first.
     */
    public function get_child_prune_candidates( int $limit, int $max_attempts ): array {
        if ( ! MainWP_Install::backup_snapshot_child_prune_ready() || $limit < 1 || $max_attempts < 1 ) {
            return array();
        }
        $table = $this->table_name( 'wp_backup_snapshot' );
        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, site_id, task_id, run_key, child_prune_attempts FROM `{$table}` WHERE status = 'pruned' AND child_prune_confirmed_at = 0 AND child_prune_attempts < %d AND run_key LIKE CONCAT(site_id, ':', task_id, %s) ORDER BY child_prune_attempts ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifiers cannot be prepared.
                $max_attempts,
                ':c:%',
                $limit
            )
        );
    }

    /**
     * Preserve the first confirmation when cleanup writers overlap.
     *
     * @param array $snapshot_ids Snapshot identities.
     * @return int Rows changed.
     */
    public function mark_child_prune_confirmed( array $snapshot_ids ): int {
        if ( ! MainWP_Install::backup_snapshot_child_prune_ready() ) {
            return 0;
        }
        $ids = array_filter(
            array_unique( array_map( 'intval', $snapshot_ids ) ),
            static function ( $id ) {
                return $id > 0;
            }
        );
        if ( empty( $ids ) ) {
            return 0;
        }
        $table = $this->table_name( 'wp_backup_snapshot' );
        $ids   = implode( ',', $ids );
        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE `{$table}` SET child_prune_confirmed_at = %d WHERE id IN ({$ids}) AND status = 'pruned' AND child_prune_confirmed_at = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier and integer-only id list.
                time()
            )
        );
    }

    /**
     * A late failure must not change a row another writer already confirmed.
     *
     * @param int $snapshot_id Snapshot identity.
     * @return int New attempt count, or zero when no row changed.
     */
    public function record_child_prune_attempt( int $snapshot_id ): int {
        if ( ! MainWP_Install::backup_snapshot_child_prune_ready() ) {
            return 0;
        }
        $table   = $this->table_name( 'wp_backup_snapshot' );
        $changed = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE `{$table}` SET child_prune_attempts = child_prune_attempts + 1 WHERE id = %d AND status = 'pruned' AND child_prune_confirmed_at = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifiers cannot be prepared.
                $snapshot_id
            )
        );
        if ( 1 !== $changed ) {
            return 0;
        }
        return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT child_prune_attempts FROM `{$table}` WHERE id = %d", $snapshot_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifiers cannot be prepared.
    }

    /**
     * A deleted site has no Child connection left to retry.
     *
     * @param int $snapshot_id Snapshot identity.
     * @param int $max_attempts Attempt cap.
     * @return void
     */
    public function give_up_child_prune( int $snapshot_id, int $max_attempts ): void {
        if ( ! MainWP_Install::backup_snapshot_child_prune_ready() ) {
            return;
        }
        $table = $this->table_name( 'wp_backup_snapshot' );
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE `{$table}` SET child_prune_attempts = %d WHERE id = %d AND status = 'pruned' AND child_prune_confirmed_at = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifiers cannot be prepared.
                $max_attempts,
                $snapshot_id
            )
        );
    }

    /**
     * Update a snapshot's offsite-verified state and verified time. Kept
     * separate from status so retention, chaining and the catalog keep
     * reading status as a strict complete/pruned lifecycle.
     *
     * @param int    $snapshot_id Snapshot id.
     * @param string $state       '', pending, verified, partial or failed.
     * @param int    $verified_at Epoch when verified; 0 for any other state.
     *
     * @return void
     */
    public function set_offsite_state( int $snapshot_id, string $state, int $verified_at = 0 ): void {
        $this->wpdb->update(
            $this->table_name( 'wp_backup_snapshot' ),
            array(
                'offsite_state' => $state,
                'verified_at'   => $verified_at,
            ),
            array( 'id' => $snapshot_id ),
            array( '%s', '%d' ),
            array( '%d' )
        );
    }

    /**
     * Record the outcome of a sandbox test-restore on a snapshot. Kept
     * separate from offsite_state so the offsite state machine (bytes are
     * retrievable) and the verified-restore result (bytes reconstitute a
     * working site) stay independent signals.
     *
     * @param int    $snapshot_id Snapshot id.
     * @param string $state       '', pass, fail, or unsupported.
     * @param int    $tested_at   Epoch when the test-restore ran; 0 to clear.
     *
     * @return void
     */
    public function set_test_restore_result( int $snapshot_id, string $state, int $tested_at = 0 ): void {
        $this->wpdb->update(
            $this->table_name( 'wp_backup_snapshot' ),
            array(
                'test_restore_state'     => $state,
                'test_restore_at'        => $tested_at,
                'test_restore_result_at' => $tested_at,
            ),
            array( 'id' => $snapshot_id ),
            array( '%s', '%d', '%d' ),
            array( '%d' )
        );
    }

    /**
     * Snapshots due for a sandbox test-restore: the newest complete snapshot
     * per site whose last test-restore is older than the cadence cutoff
     * (never-tested counts as overdue and sorts first).
     *
     * Uses the same latest-per-site join the fleet-health map relies on, and
     * runs unscoped by user - the sampler is a background cron lane that
     * covers every site, not a per-operator view.
     *
     * @param int $limit  Most rows to return.
     * @param int $cutoff Epoch; a snapshot is due when test_restore_at is below it.
     *
     * @return object[] Rows of id, site_id, created, test_restore_state, test_restore_at.
     */
    public function get_test_restore_candidates( int $limit, int $cutoff ): array {
        $limit = max( 1, $limit );
        $table = $this->table_name( 'wp_backup_snapshot' );

        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT s.id, s.site_id, s.created, s.test_restore_state, s.test_restore_at FROM ' . $table . ' s '
                . 'JOIN ( SELECT site_id, MAX(id) AS max_id FROM ' . $table . " WHERE status = 'complete' GROUP BY site_id ) latest ON s.id = latest.max_id "
                . 'WHERE s.test_restore_at < %d ORDER BY s.test_restore_at ASC, s.id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table identifier from table_name(); the %d placeholders are prepared.
                $cutoff,
                $limit
            )
        );
    }

    /**
     * Atomically claim a snapshot for test-restore.
     *
     * Stamps test_restore_at to now, but only while the snapshot is still due
     * (a compare-and-swap on the same cutoff the candidate query used). Two
     * overlapping cron ticks cannot both win the same row, and a claimed run
     * that dies or returns without recording still backs the snapshot off for
     * the cadence window instead of being re-selected every tick.
     *
     * @param int $snapshot_id Snapshot id.
     * @param int $now         Epoch to stamp on a winning claim.
     * @param int $cutoff      Due threshold; the claim wins only if test_restore_at is below it.
     *
     * @return bool True when this caller won the claim (exactly one row updated).
     */
    public function claim_for_test_restore( int $snapshot_id, int $now, int $cutoff ): bool {
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table_name( 'wp_backup_snapshot' ) . ' SET test_restore_at = %d WHERE id = %d AND test_restore_at < %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier from table_name(); values are prepared.
                $now,
                $snapshot_id,
                $cutoff
            )
        );

        return 1 === (int) $updated;
    }

    /**
     * Decode the archives JSON on a snapshot row and normalize parent_id.
     *
     * @param object|null $row Raw snapshot row.
     *
     * @return object|null Row with archives as an array, or null.
     */
    private function decode_row( ?object $row ): ?object {
        if ( null === $row ) {
            return null;
        }

        $decoded        = json_decode( (string) $row->archives, true );
        $row->archives  = is_array( $decoded ) ? $decoded : array();
        $row->parent_id = isset( $row->parent_id ) && null !== $row->parent_id ? (int) $row->parent_id : null;

        // Empty/absent encryption decodes to an empty array, the mixed-mode
        // "this snapshot is plaintext" signal legacy rows carry implicitly.
        $encryption      = property_exists( $row, 'encryption' ) ? json_decode( (string) $row->encryption, true ) : null;
        $row->encryption = is_array( $encryption ) ? $encryption : array();

        // Empty/absent woo_meta decodes to an empty array: files-only runs
        // and backups from older children carry no Woo facts, and the
        // preserve-orders initiate gate defers on an empty set.
        $woo_meta      = property_exists( $row, 'woo_meta' ) ? json_decode( (string) $row->woo_meta, true ) : null;
        $row->woo_meta = is_array( $woo_meta ) ? $woo_meta : array();

        return $row;
    }

    /**
     * Mint and persist a snapshot's archive-encryption metadata: a fresh DEK
     * wrapped under the dashboard KEK, plus the cipher and key-version markers
     * a later upload and restore read back. The raw DEK is never stored.
     *
     * @param int $snapshot_id The snapshot to attach metadata to.
     *
     * @return bool True when the metadata was written; false on any failure
     *              (the caller drops the snapshot rather than ship it in clear).
     */
    private function attach_encryption_meta( int $snapshot_id ): bool {
        try {
            $meta             = MainWP_Backup_Encryption::instance()->snapshot_encryption_meta( $snapshot_id );
            $meta['required'] = 1;
            $json             = wp_json_encode( $meta );
            if ( false === $json ) {
                return false;
            }
            $updated = $this->wpdb->update(
                $this->table_name( 'wp_backup_snapshot' ),
                array( 'encryption' => $json ),
                array( 'id' => $snapshot_id ),
                array( '%s' ),
                array( '%d' )
            );
            return false !== $updated;
        } catch ( \Throwable $e ) {
            MainWP_Logger::instance()->debug( 'backup encryption :: snapshot[' . $snapshot_id . '] :: setup failed[' . $e->getMessage() . ']' );
            return false;
        }
    }
}
