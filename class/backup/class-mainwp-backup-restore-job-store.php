<?php
/**
 * MainWP Backup Restore Job Store - persisted dashboard restore state.
 *
 * One row per restore: which snapshot onto which site, the phase the
 * orchestration is in, the child task handles, and a checkpoint JSON
 * carrying the staging/transfer cursors. The cron driver acquires jobs
 * with the same atomic locked_until/locked_by gate as the upload job
 * store, and failures back off exponentially with the same constants.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_Restore_Job_Store
 */
class MainWP_Backup_Restore_Job_Store extends MainWP_Backup_Job_Store_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- dedicated restore job table; identifiers cannot be prepared.

    const STATUS_PENDING          = 'pending';
    const STATUS_PREFLIGHT        = 'preflight';
    const STATUS_PRE_SNAPSHOT     = 'pre_snapshot';
    const STATUS_STAGING          = 'staging';
    const STATUS_TRANSFERRING     = 'transferring';
    const STATUS_RUNNING          = 'running';
    const STATUS_VERIFYING        = 'verifying';
    const STATUS_COMPLETED        = 'completed';
    const STATUS_FAILED_RETRYABLE = 'failed_retryable';
    const STATUS_FAILED_PERMANENT = 'failed_permanent';
    const STATUS_CANCELLED        = 'cancelled';

    /**
     * Expired-lease reclaims allowed before a job is routed to
     * fail_permanent instead of being handed out again. A reclaim means
     * the previous worker died mid-restore (a hard fatal, not a caught
     * retryable failure), so attempt_count never bounds it. Three is
     * deliberately tighter than the seven-attempt retryable budget in
     * MainWP_Backup_Backoff: three straight worker deaths on the same
     * row is strong evidence of a poison job rather than transient infra
     * flakiness, and this ceiling exists precisely to stop that job from
     * looping forever.
     */
    const RECLAIM_CEILING = 3;

    /**
     * Elapsed time preserves the rollback window under browser polling and sparse cron ticks.
     */
    const CANCEL_CONFIRMATION_TIMEOUT_SECONDS = 15 * 60;

    /**
     * Singleton.
     *
     * @var MainWP_Backup_Restore_Job_Store|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_Restore_Job_Store
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Table name.
     *
     * @return string Table name.
     */
    protected function table(): string {
        return $this->table_name( 'wp_backup_restore_job' );
    }

    /**
     * The statuses an in-flight job can hold.
     *
     * @return string[] Active statuses.
     */
    public static function active_statuses(): array {
        return array(
            self::STATUS_PENDING,
            self::STATUS_PREFLIGHT,
            self::STATUS_PRE_SNAPSHOT,
            self::STATUS_STAGING,
            self::STATUS_TRANSFERRING,
            self::STATUS_RUNNING,
            self::STATUS_VERIFYING,
            self::STATUS_FAILED_RETRYABLE,
        );
    }

    /**
     * Create a restore job, refusing a duplicate while one is already
     * active for the site.
     *
     * The unique site identity closes the race between the NOT EXISTS
     * check and a concurrent insert.
     *
     * @param int   $site_id     Child site id.
     * @param int   $snapshot_id Snapshot id.
     * @param array $checkpoint  Initial checkpoint (plan + options).
     *
     * @return int Job id (> 0) on insert; 0 when an active job already holds
     *             the site; -1 on a database error (kept distinct so a real
     *             failure is never read as a benign duplicate).
     */
    public function create_job( int $site_id, int $snapshot_id, array $checkpoint ): int {
        $now          = time();
        $statuses     = self::active_statuses();
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $suppress = $this->wpdb->suppress_errors();
        try {
            $result = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the atomic create gate; must hit the DB engine.
                $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements and placeholders come from the same counted array.
                    'INSERT INTO ' . $this->table() . ' ( site_id, active_site_id, snapshot_id, status, checkpoint, created, updated ) SELECT %d, %d, %d, %s, %s, %d, %d FROM DUAL WHERE NOT EXISTS ( SELECT 1 FROM ' . $this->table() . " WHERE site_id = %d AND status IN ( {$placeholders} ) LIMIT 1 )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from a counted array; table name from trusted helper.
                    array_merge( array( $site_id, $site_id, $snapshot_id, self::STATUS_PENDING, wp_json_encode( $checkpoint ), $now, $now, $site_id ), $statuses )
                )
            );
        } finally {
            $this->wpdb->suppress_errors( $suppress );
        }
        if ( false === $result ) {
            return null !== $this->get_active_job_for_site( $site_id ) ? 0 : -1;
        }
        return 1 === (int) $result ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * Fetch one job.
     *
     * @param int $job_id Job id.
     *
     * @return object|null Job row with checkpoint decoded.
     */
    public function get_job( int $job_id ): ?object {
        $row = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
        );
        return $this->decode_row( $row );
    }

    /**
     * The site's active restore job, if any (one restore per site).
     *
     * @param int $site_id Child site id.
     *
     * @return object|null Active job or null.
     */
    public function get_active_job_for_site( int $site_id ): ?object {
        $statuses     = self::active_statuses();
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $row          = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table() . " WHERE site_id = %d AND status IN ( {$placeholders} ) ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from a counted array.
                array_merge( array( $site_id ), $statuses )
            )
        );
        return $this->decode_row( $row );
    }

    /**
     * Does a nonterminal restore job reference any of these snapshots?
     *
     * Retention asks this before pruning a chain: deleting artifacts a
     * mid-flight restore still stages from destroys its recovery source,
     * so a chain any active job references is skipped for this sweep and
     * revisited once the restore settles.
     *
     * @param int[] $snapshot_ids Snapshot ids forming one restorable unit.
     *
     * @return bool True when an active restore references one of them.
     */
    public function has_active_job_for_snapshots( array $snapshot_ids ): bool {
        $snapshot_ids = array_values( array_filter( array_map( 'intval', $snapshot_ids ) ) );
        if ( empty( $snapshot_ids ) ) {
            return false;
        }

        $statuses         = self::active_statuses();
        $status_holders   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $snapshot_holders = implode( ',', array_fill( 0, count( $snapshot_ids ), '%d' ) );

        $found = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare(
                'SELECT id FROM ' . $this->table() . " WHERE snapshot_id IN ( {$snapshot_holders} ) AND status IN ( {$status_holders} ) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder lists built from counted arrays.
                array_merge( $snapshot_ids, $statuses )
            )
        );

        return null !== $found;
    }

    /**
     * Atomically acquire the next runnable job.
     *
     * @param string   $token         Lock token.
     * @param int      $lease_seconds Lock duration.
     * @param int|null $now           Clock override for tests.
     * @param int|null $job_id        Restrict the claim to this job id; null
     *                                claims the next runnable job of any site.
     *                                A foreground progress poll passes the polled
     *                                id so it only ever advances that restore.
     *
     * @return object|null Locked job or null.
     */
    public function acquire_next_job( string $token, int $lease_seconds = 300, ?int $now = null, ?int $job_id = null ): ?object {
        $now          = null === $now ? time() : $now;
        $statuses     = self::active_statuses();
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        // Scope the claim to one job when an id is given (a foreground poll
        // advancing the job it polls), otherwise take the next runnable job.
        $id_clause = null === $job_id ? '' : ' AND id = %d';
        $binds     = array_merge( array( $now + $lease_seconds, $token, $now ), $statuses, array( $now, $now, static::RECLAIM_CEILING, $now ) );
        if ( null !== $job_id ) {
            $binds[] = $job_id;
        }

        // The site_id NOT IN (...) clause serializes runs per site: a site
        // that already holds a live lease is skipped, so two rows for one
        // site never lock at once. The inner select is wrapped in a derived
        // table so MySQL does not reject reading the table it is updating.
        // reclaim_count's CASE WHEN must read locked_by before locked_by is
        // itself reassigned later in the same SET list: MySQL evaluates SET
        // expressions left to right using each column's already-updated
        // value, so the reclaim_count assignment comes first to see the
        // pre-claim locked_by (non-empty only when a prior lease is being
        // reclaimed, not on a fresh pickup or a post-fail_retryable backoff
        // pickup, both of which leave locked_by empty).
        $claimed = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the atomic claim gate; must hit the DB engine.
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements and placeholders come from the same counted array.
                'UPDATE ' . $this->table() . " SET reclaim_count = CASE WHEN locked_by <> '' THEN reclaim_count + 1 ELSE reclaim_count END, locked_until = %d, locked_by = %s, updated = %d WHERE status IN ( {$placeholders} ) AND next_retry_at <= %d AND locked_until < %d AND ( locked_by = '' OR reclaim_count < %d ) AND site_id NOT IN ( SELECT site_id FROM ( SELECT site_id FROM " . $this->table() . " WHERE locked_until >= %d ) AS mainwp_locked_sites ){$id_clause} ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from a counted array; table name from trusted helper.
                $binds
            )
        );
        if ( 1 !== $claimed ) {
            return null;
        }

        $row = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE locked_by = %s AND locked_until = %d LIMIT 1', $token, $now + $lease_seconds ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
        );
        return $this->decode_row( $row );
    }

    /**
     * Find expired leases the orchestrator must fail instead of reclaiming.
     *
     * Hard worker failures never reach fail_retryable(), so attempt_count
     * cannot bound them. The orchestrator owns terminal cleanup and events.
     * A foreground poll must only clean up the job it was asked to advance.
     *
     * @param string[] $statuses Runnable statuses.
     * @param int      $now      Current time.
     * @param int|null $job_id   Restrict the search to this job id, or null for every job.
     *
     * @return object[] Full job rows with decoded checkpoints.
     */
    public function find_jobs_over_reclaim_ceiling( array $statuses, int $now, ?int $job_id = null ): array {
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $id_clause    = null === $job_id ? '' : ' AND id = %d';
        $binds        = array_merge( $statuses, array( $now, static::RECLAIM_CEILING ) );
        if ( null !== $job_id ) {
            $binds[] = $job_id;
        }

        $rows = $this->wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements and placeholders come from the same counted array.
                'SELECT * FROM ' . $this->table() . " WHERE status IN ( {$placeholders} ) AND locked_by <> '' AND locked_until < %d AND reclaim_count >= %d{$id_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from a counted array; table name from trusted helper.
                $binds
            )
        );

        return array_map( array( $this, 'decode_row' ), $rows );
    }

    /**
     * The JSON fragment a stored cancel intent serializes to. The key is
     * written only by this store's own arm/save paths and the value is
     * only ever boolean true, so a substring match on the stored row is
     * an exact intent probe - no JSON functions needed, which keeps the
     * merge below portable across MySQL and MariaDB.
     *
     * @var string
     */
    const CANCEL_INTENT_FRAGMENT = '"cancel_intent":true';

    /**
     * Persist the checkpoint.
     *
     * A cancel intent recorded on the row is sticky: the UI writes it
     * unfenced from outside the lease, so a worker saving phase data
     * built from an older in-memory copy would silently erase it. The
     * carry-forward happens INSIDE the UPDATE - the statement itself
     * picks the flag-carrying encoding when the stored row holds the
     * intent - so there is no read-then-write window in which a cancel
     * landing mid-save could still be lost. Only the explicit
     * $clear_cancel_intent (the applied-cancel path) may drop it.
     *
     * @param int         $job_id              Job id.
     * @param array       $checkpoint          Checkpoint.
     * @param string|null $lock_token          Fence the write on this lease token; null writes unfenced.
     * @param bool        $clear_cancel_intent Drop a stored cancel_intent instead of preserving it.
     *
     * @return bool True when the write landed; false when the fence
     *              refused it because the lease was lost.
     */
    public function save_checkpoint( int $job_id, array $checkpoint, ?string $lock_token = null, bool $clear_cancel_intent = false ): bool {
        unset( $checkpoint['cancel_intent'] );
        if ( $clear_cancel_intent ) {
            return $this->update_job(
                $job_id,
                array( 'checkpoint' => wp_json_encode( $checkpoint ) ),
                array( '%s' ),
                $lock_token
            );
        }

        $plain                       = (string) wp_json_encode( $checkpoint );
        $checkpoint['cancel_intent'] = true;
        $flagged                     = (string) wp_json_encode( $checkpoint );

        $fence = '';
        $binds = array( '%' . $this->wpdb->esc_like( self::CANCEL_INTENT_FRAGMENT ) . '%', $flagged, $plain, time(), $job_id );
        if ( null !== $lock_token ) {
            $fence   = ' AND locked_by = %s';
            $binds[] = $lock_token;
        }

        $updated = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list and bind array grow together.
                'UPDATE ' . $this->table() . " SET checkpoint = IF( checkpoint LIKE %s, %s, %s ), updated = %d WHERE id = %d{$fence}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fence is a fixed SQL fragment; its value binds via placeholder.
                $binds
            )
        );

        if ( null === $lock_token ) {
            return false !== $updated;
        }
        if ( 1 === $updated ) {
            return true;
        }
        if ( false === $updated ) {
            return false;
        }
        // Zero affected rows is ambiguous under MySQL: the fence refused
        // the write, or the values were already identical. Only a
        // confirmed token mismatch is a lost lease (mirrors update_job).
        $holder = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare( 'SELECT locked_by FROM ' . $this->table() . ' WHERE id = %d', $job_id )
        );
        return $holder === $lock_token;
    }

    /**
     * Atomically record a cancel intent on the stored checkpoint.
     *
     * The UI-cancel path holds no lease, and a full-checkpoint overwrite
     * from its own read could revert a worker's newer checkpoint (racing
     * fenced writes land between the read and the write). The intent is
     * therefore injected with a bounded compare-and-swap: the UPDATE only
     * lands while the checkpoint still matches the exact value read, and
     * a miss re-reads and retries against the fresher row.
     *
     * @param int         $job_id     Job id.
     * @param array       $failure    Original failure to preserve when combined deferral failed.
     * @param string|null $lock_token Worker lease token, or null for an unleased cancellation.
     *
     * @return bool True when the intent is recorded on the row.
     */
    public function arm_cancel_intent( int $job_id, array $failure = array(), ?string $lock_token = null ): bool {
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $row = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
                $this->wpdb->prepare( 'SELECT checkpoint, status, locked_by FROM ' . $this->table() . ' WHERE id = %d', $job_id )
            );
            if ( null === $row || ! in_array( $row->status, self::active_statuses(), true ) || ( null !== $lock_token && $row->locked_by !== $lock_token ) ) {
                return false;
            }

            $stored  = (string) $row->checkpoint;
            $decoded = json_decode( $stored, true );
            if ( ! is_array( $decoded ) ) {
                $decoded = array();
            }
            if ( ! empty( $decoded['cancel_intent'] ) && ( empty( $failure ) || ! empty( $decoded['terminal_failure'] ) ) ) {
                return true; // Already armed.
            }
            $decoded['cancel_intent'] = true;
            if ( ! empty( $failure ) && empty( $decoded['terminal_failure'] ) ) {
                $decoded['terminal_failure'] = $failure;
            }
            $fence = '';
            $binds = array( wp_json_encode( $decoded ), time(), $job_id, $stored, $row->status );
            if ( null !== $lock_token ) {
                $fence   = ' AND locked_by = %s';
                $binds[] = $lock_token;
            }

            $swapped = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-swap on the exact stored value.
                $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the optional lease fence adds its bind.
                    'UPDATE ' . $this->table() . " SET checkpoint = %s, updated = %d WHERE id = %d AND checkpoint = %s AND status = %s{$fence}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fence with a bound token.
                    $binds
                )
            );
            if ( 1 === $swapped ) {
                return true;
            }
            // A worker's fenced save landed between the read and the swap;
            // retry against the fresher checkpoint.
        }
        return false;
    }

    /**
     * Keep the failure and its cancel intent indivisible across worker crashes.
     *
     * @param int         $job_id     Job id.
     * @param string|null $error      Original failure text, or null for a user cancellation.
     * @param string      $error_code Original failure code.
     * @param string|null $lock_token Worker lease token, or null for the sweep.
     *
     * @return array|null Cancellation attempt record, or null when the write was refused.
     */
    public function defer_terminal_failure( int $job_id, ?string $error, string $error_code, ?string $lock_token = null ): ?array {
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $row = $this->wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
                $this->wpdb->prepare( 'SELECT checkpoint, status, locked_by, last_error FROM ' . $this->table() . ' WHERE id = %d', $job_id )
            );
            if ( null === $row || ! in_array( $row->status, self::active_statuses(), true ) || ( null !== $lock_token && $row->locked_by !== $lock_token ) ) {
                return null;
            }

            $stored  = (string) $row->checkpoint;
            $decoded = json_decode( $stored, true );
            $decoded = is_array( $decoded ) ? $decoded : array();
            if ( null !== $error && empty( $decoded['terminal_failure'] ) ) {
                $decoded['terminal_failure'] = array(
                    'error'      => $error,
                    'error_code' => $error_code,
                );
            }
            $previous                       = $decoded['cancel_confirmation'] ?? array();
            $deferred                       = array(
                'first_attempt_at' => (int) ( $previous['first_attempt_at'] ?? time() ),
                'attempt_count'    => (int) ( $previous['attempt_count'] ?? 0 ) + 1,
            );
            $decoded['cancel_confirmation'] = $deferred;
            $decoded['cancel_intent']       = true;
            $last_error                     = $decoded['terminal_failure']['error'] ?? $row->last_error;
            if ( ! empty( $decoded['terminal_failure'] ) ) {
                $decoded['last_error_code'] = $decoded['terminal_failure']['error_code'];
            }

            $fence = '';
            $binds = array( wp_json_encode( $decoded ), $last_error, time(), $job_id, $stored, $row->status );
            if ( null !== $lock_token ) {
                $fence   = ' AND locked_by = %s';
                $binds[] = $lock_token;
            }
            $swapped = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-swap on the exact stored value.
                $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the optional lease fence adds its bind.
                    'UPDATE ' . $this->table() . " SET checkpoint = %s, last_error = %s, updated = %d WHERE id = %d AND checkpoint = %s AND status = %s{$fence}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fence with a bound token.
                    $binds
                )
            );
            if ( 1 === $swapped ) {
                return $deferred;
            }
        }
        return null;
    }

    /**
     * Move a job to a status, optionally recording handles.
     *
     * Transition matrix (failed_retryable is active):
     * - Active -> active or terminal: write; a supplied token must match.
     * - Terminal -> active or terminal: no write, with or without a token.
     * - Changed row: true. Query failure or missing row: false.
     * - Unchanged row, terminal destination: false; only the winning writer succeeds.
     * - Unchanged row, active destination: a supplied token must still own it;
     *   without a token, true only if its status already matches.
     *
     * @param int         $job_id     Job id.
     * @param string      $status     New status.
     * @param array       $extra      Extra columns (child_task_id, pre_snapshot_task_id).
     * @param string|null $lock_token Fence the write on this lease token; null writes unfenced.
     *
     * @return bool True when the write landed; false when the fence
     *              refused it because the lease was lost.
     */
    public function set_status( int $job_id, string $status, array $extra = array(), ?string $lock_token = null ): bool {
        $row = array_merge( $extra, array( 'status' => $status ) );
        unset( $row['active_site_id'] );
        if ( ! in_array( $status, array( self::STATUS_FAILED_RETRYABLE, self::STATUS_FAILED_PERMANENT, self::STATUS_CANCELLED ), true ) && ! array_key_exists( 'last_error', $row ) ) {
            // A job moving forward has outlived its recorded failure;
            // leaving the message in place keeps it displaying through
            // every later phase.
            $row['last_error'] = '';
        }
        $active         = in_array( $status, self::active_statuses(), true );
        $row['updated'] = time();
        $set            = array();
        $values         = array();
        foreach ( $row as $column => $value ) {
            $set[] = '`' . str_replace( '`', '``', $column ) . '` = ' . ( null === $value ? 'NULL' : ( is_int( $value ) ? '%d' : '%s' ) );
            if ( null !== $value ) {
                $values[] = $value;
            }
        }
        $set[]    = 'active_site_id = ' . ( $active ? 'site_id' : 'NULL' );
        $values[] = $job_id;
        // Every transition preserves the first terminal writer's result.
        $statuses = self::active_statuses();
        $guard    = ' AND status IN ( ' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ' )';
        $values   = array_merge( $values, $statuses );
        if ( null !== $lock_token ) {
            $guard   .= ' AND locked_by = %s';
            $values[] = $lock_token;
        }
        $suppress = $this->wpdb->suppress_errors();
        try {
            $changed = $this->wpdb->query(
                $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list and bind array grow together.
                    'UPDATE ' . $this->table() . ' SET ' . implode( ', ', $set ) . " WHERE id = %d{$guard}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fence is a fixed SQL fragment; its value binds via placeholder.
                    $values
                )
            );
        } finally {
            $this->wpdb->suppress_errors( $suppress );
        }
        if ( false === $changed || 1 === $changed ) {
            return 1 === $changed;
        }
        if ( ! $active ) {
            return false;
        }
        // Resolve an unchanged active write, including same-second no-ops.
        $current = $this->get_job( $job_id );
        if ( null === $current ) {
            return false;
        }
        return null !== $lock_token ? $current->locked_by === $lock_token : $current->status === $status;
    }

    /**
     * Record a retryable failure with exponential backoff.
     *
     * Deliberately not shared with the upload store's fail_retryable:
     * the orchestrator owns the retry count and passes it in, while the
     * upload store increments attempt_count inside SQL.
     *
     * @param int         $job_id        Job id.
     * @param string      $error         Error text.
     * @param int         $attempt_count New attempt count.
     * @param int         $next_retry_at Retry epoch.
     * @param string|null $lock_token    Fence the write on this lease token; null writes unfenced.
     *
     * @return bool True when the write landed; false when the fence
     *              refused it because the lease was lost.
     */
    public function fail_retryable( int $job_id, string $error, int $attempt_count, int $next_retry_at, ?string $lock_token = null ): bool {
        return $this->set_status(
            $job_id,
            self::STATUS_FAILED_RETRYABLE,
            array(
                'last_error'    => $error,
                'attempt_count' => $attempt_count,
                'next_retry_at' => $next_retry_at,
                'locked_until'  => 0,
                'locked_by'     => '',
            ),
            $lock_token
        );
    }

    /**
     * Record a permanent failure.
     *
     * @param int         $job_id     Job id.
     * @param string      $error      Error text.
     * @param string|null $lock_token Fence the transition on this lease token; null skips the fence.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal (or the fence refused a stale
     *              token) and the write was refused.
     */
    public function fail_permanent( int $job_id, string $error, ?string $lock_token = null ): bool {
        return $this->set_status(
            $job_id,
            self::STATUS_FAILED_PERMANENT,
            array(
                'last_error'   => $error,
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            $lock_token
        );
    }

    /**
     * Complete a job.
     *
     * @param int         $job_id     Job id.
     * @param string|null $lock_token Fence the transition on this lease token; null skips the fence.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal (or the fence refused a stale
     *              token) and the write was refused.
     */
    public function complete_job( int $job_id, ?string $lock_token = null ): bool {
        return $this->set_status(
            $job_id,
            self::STATUS_COMPLETED,
            array(
                'last_error'   => '',
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            $lock_token
        );
    }

    /**
     * Cancel a job.
     *
     * @param int         $job_id     Job id.
     * @param string|null $lock_token Fence the transition on this lease token; null skips the
     *                                fence (the UI/CLI cancel path legitimately holds no lease).
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal (or the fence refused a stale
     *              token) and the write was refused.
     */
    public function cancel_job( int $job_id, ?string $lock_token = null ): bool {
        return $this->set_status(
            $job_id,
            self::STATUS_CANCELLED,
            array(
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            $lock_token
        );
    }
}
