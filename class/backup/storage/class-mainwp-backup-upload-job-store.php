<?php
/**
 * MainWP Backup Upload Job Store.
 *
 * Resumable upload state, one row per snapshot and destination. The row
 * carries the job status, a lease lock so concurrent cron lanes never
 * run the same job, retry scheduling with an attempt counter, and a
 * checkpoint blob holding the per-artifact chunk lists and cursors that
 * let an interrupted upload resume from verified work.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Upload_Job_Store
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Upload_Job_Store extends MainWP_Backup_Job_Store_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, table identifiers cannot be prepared.

    const KIND_UPLOAD = 'upload';
    const KIND_DELETE = 'delete';

    /**
     * Expired-lease reclaims allowed before a job is routed to
     * fail_permanent instead of being handed out again. A reclaim means
     * the previous worker died mid-job (a hard fatal, not a caught
     * retryable failure), so attempt_count never bounds it. Three is
     * deliberately tighter than the seven-attempt retryable budget in
     * MainWP_Backup_Backoff: three straight worker deaths on the same
     * row is strong evidence of a poison job (bad payload, a
     * deterministic fatal) rather than transient infra flakiness, and
     * this ceiling exists precisely to stop that job from looping
     * forever.
     */
    const RECLAIM_CEILING = 3;

    /**
     * Age past which prune treats an expired worker lease as abandoned. The
     * upload lane reclaims a dead worker's row within a tick, but it does not
     * run at all while native backups are off, and prune must not wait on it.
     */
    const PRUNE_STALE_LEASE_SECONDS = HOUR_IN_SECONDS;

    const STATUS_PENDING             = 'pending';
    const STATUS_CHUNKING            = 'chunking';
    const STATUS_UPLOADING           = 'uploading';
    const STATUS_SUBMITTING          = 'submitting';
    const STATUS_COMPLETE            = 'complete';
    const STATUS_FAILED_RETRYABLE    = 'failed_retryable';
    const STATUS_FAILED_PERMANENT    = 'failed_permanent';
    const STATUS_CREDENTIALS_INVALID = 'credentials_invalid';

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Upload_Job_Store or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Upload_Job_Store
     */
    public static function instance() {
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
        return $this->table_name( 'wp_backup_upload_job' );
    }

    /**
     * Enqueue one pending upload job per destination for a snapshot.
     * Idempotent: a (snapshot, destination) pair that already has a row
     * is left untouched.
     *
     * @param int   $snapshot_id     Snapshot id.
     * @param array $destination_ids Storage destination ids.
     *
     * @return int Number of jobs inserted.
     */
    public function enqueue_for_snapshot( int $snapshot_id, array $destination_ids ): int {
        $inserted = 0;

        foreach ( $destination_ids as $destination_id ) {
            $destination_id = (int) $destination_id;
            if ( $destination_id < 1 ) {
                continue;
            }

            // Denormalize the destination's provider onto the job so the
            // offsite rollup can tell local from cloud even if the
            // destination row is later deleted.
            $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( $destination_id );
            $provider    = ( null !== $destination && isset( $destination->provider ) ) ? (string) $destination->provider : '';

            // The unique key on (snapshot_id, destination_id) makes the
            // duplicate insert a no-op rather than an error.
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    'INSERT IGNORE INTO ' . $this->table_name( 'wp_backup_upload_job' ) .
                    ' (snapshot_id, destination_id, provider, status, checkpoint, last_error, created, updated) VALUES (%d, %d, %s, %s, %s, %s, %d, %d)',
                    $snapshot_id,
                    $destination_id,
                    $provider,
                    static::STATUS_PENDING,
                    '',
                    '',
                    time(),
                    time()
                )
            );

            $inserted += (int) $result;
        }

        return $inserted;
    }

    /**
     * Atomically acquire the next runnable job: pending, mid-phase with an
     * expired lease, or retryable past its backoff window. Each driver
     * acquires only its own kind, so the upload and cloud delete lanes
     * never steal each other's work.
     *
     * @param string   $token         Caller's lock token.
     * @param int      $lease_seconds Lease length.
     * @param int|null $now           Clock override for tests.
     * @param string   $kind          Job kind: 'upload' or 'delete'.
     *
     * @return object|null The locked job, or null when none is runnable.
     */
    public function acquire_next_job( string $token, int $lease_seconds = 300, ?int $now = null, string $kind = self::KIND_UPLOAD ): ?object {
        $now = null === $now ? time() : $now;

        $runnable = array(
            static::STATUS_PENDING,
            static::STATUS_CHUNKING,
            static::STATUS_UPLOADING,
            static::STATUS_SUBMITTING,
            static::STATUS_FAILED_RETRYABLE,
        );

        // A job whose expired lease has already been reclaimed past the
        // ceiling is killed here, before it can be handed out again.
        $this->fail_jobs_over_reclaim_ceiling( $kind, $runnable, $now );

        // Single-table UPDATE with ORDER BY + LIMIT is atomic under InnoDB
        // row locking; only one caller wins the row. reclaim_count's CASE
        // WHEN must read locked_by before locked_by is itself reassigned
        // later in the same SET list: MySQL evaluates SET expressions left
        // to right using each column's already-updated value, so the
        // reclaim_count assignment comes first to see the pre-claim
        // locked_by (non-empty only when a prior lease is being reclaimed,
        // not on a fresh pickup or a post-fail_retryable backoff pickup,
        // both of which leave locked_by empty). Expired prune claims are
        // also fresh pickups, never worker reclaims.
        $claimed = $this->wpdb->query(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the merged arrays supply all eleven replacements.
                'UPDATE ' . $this->table_name( 'wp_backup_upload_job' ) .
                " SET reclaim_count = CASE WHEN locked_by <> '' AND LEFT(locked_by, 6) <> 'prune:' THEN reclaim_count + 1 ELSE reclaim_count END, locked_until = %d, locked_by = %s, updated = %d WHERE kind = %s AND status IN (%s, %s, %s, %s, %s) AND next_retry_at <= %d AND locked_until < %d ORDER BY id ASC LIMIT 1",
                array_merge(
                    array( $now + $lease_seconds, $token, $now, $kind ),
                    $runnable,
                    array( $now, $now )
                )
            )
        );

        if ( empty( $claimed ) ) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_upload_job' ) . ' WHERE locked_by = %s AND locked_until = %d ORDER BY updated DESC LIMIT 1',
                $token,
                $now + $lease_seconds
            )
        );

        return $this->decode_row( $row );
    }

    /**
     * Permanently fail any job of this kind whose expired lease has been
     * reclaimed at least RECLAIM_CEILING times, before a fresh claim
     * attempt can reclaim it again. A job that keeps losing its worker to
     * a hard fatal never reaches fail_retryable(), so attempt_count never
     * bounds it; this is the check that stops the loop instead.
     *
     * @param string $kind     Job kind.
     * @param array  $runnable Runnable statuses (fixed 5-element list).
     * @param int    $now      Current time.
     *
     * @return void
     */
    private function fail_jobs_over_reclaim_ceiling( string $kind, array $runnable, int $now ): void {
        $ids = $this->wpdb->get_col(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the merged arrays supply all eight replacements.
                'SELECT id FROM ' . $this->table_name( 'wp_backup_upload_job' ) .
                " WHERE kind = %s AND status IN (%s, %s, %s, %s, %s) AND locked_by <> '' AND LEFT(locked_by, 6) <> 'prune:' AND locked_until < %d AND reclaim_count >= %d",
                array_merge( array( $kind ), $runnable, array( $now, static::RECLAIM_CEILING ) )
            )
        );

        foreach ( $ids as $id ) {
            $this->fail_permanent(
                (int) $id,
                sprintf( 'Reclaimed %d times without completing a phase; the worker keeps dying on this job, so it is stopped instead of retried forever.', static::RECLAIM_CEILING )
            );
        }
    }

    /**
     * Get a job by id.
     *
     * @param int $job_id Job id.
     *
     * @return object|null
     */
    public function get_job( int $job_id ): ?object {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_upload_job' ) . ' WHERE id = %d',
                $job_id
            )
        );
        return $this->decode_row( $row );
    }

    /**
     * All jobs of one kind for a snapshot, every destination's status.
     *
     * @param int    $snapshot_id Snapshot id.
     * @param string $kind        Job kind: 'upload' or 'delete'.
     *
     * @return object[]
     */
    public function get_jobs_for_snapshot( int $snapshot_id, string $kind = self::KIND_UPLOAD ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_upload_job' ) . ' WHERE snapshot_id = %d AND kind = %s ORDER BY id ASC',
                $snapshot_id,
                $kind
            )
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_values( array_filter( array_map( array( $this, 'decode_row' ), $rows ) ) );
    }

    /**
     * Upload workers protect their source with job leases rather than site reservations.
     *
     * @param int[] $snapshot_ids Snapshot ids forming one restorable unit.
     *
     * @return bool True when an upload owns a live lease for one of them.
     */
    public function has_live_upload_lease( array $snapshot_ids ): bool {
        $snapshot_ids = array_values( array_filter( array_map( 'intval', $snapshot_ids ) ) );
        if ( empty( $snapshot_ids ) ) {
            return false;
        }

        $snapshot_holders = implode( ',', array_fill( 0, count( $snapshot_ids ), '%d' ) );
        $found            = $this->wpdb->get_var(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- snapshot placeholders and bind array grow together.
                'SELECT id FROM ' . $this->table() . " WHERE snapshot_id IN ( {$snapshot_holders} ) AND kind = %s AND locked_by <> '' AND locked_until >= %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder list built from the counted snapshot array.
                array_merge( $snapshot_ids, array( self::KIND_UPLOAD, time() ) )
            )
        );

        return null !== $found;
    }

    /**
     * Claim a whole prune unit on the same rows upload acquisition locks.
     *
     * @param int[]  $ids           Snapshot ids in the unit.
     * @param string $token         Prune token, including the prune: prefix.
     * @param int    $lease_seconds Short hold while deleting local artifacts.
     * @return bool True when no upload worker owns any row in the unit.
     */
    public function claim_uploads_for_prune( array $ids, string $token, int $lease_seconds = 300 ): bool {
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( empty( $ids ) || ! str_starts_with( $token, 'prune:' ) ) {
            return false;
        }
        $holders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $now      = time();
        $runnable = self::active_statuses();
        $statuses = implode( ',', array_fill( 0, count( $runnable ), '%s' ) );
        $stale    = $now - self::PRUNE_STALE_LEASE_SECONDS;
        // Terminal writes clear locked_by; an expired runnable worker lease still belongs to the reclaim lane until it goes stale.
        $claimed = $this->wpdb->query(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- counted ids and placeholders grow together.
                'UPDATE ' . $this->table() . " SET locked_by = %s, locked_until = %d, updated = %d WHERE snapshot_id IN ({$holders}) AND kind = %s AND ((locked_until < %d AND (locked_by = '' OR locked_by LIKE %s OR status NOT IN ({$statuses}) OR locked_until < %d)) OR locked_by = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder lists from counted arrays.
                array_merge( array( $token, $now + $lease_seconds, $now ), $ids, array( self::KIND_UPLOAD, $now, 'prune:%' ), $runnable, array( $stale, $token ) )
            )
        );
        if ( false === $claimed ) {
            return false;
        }
        $other = $this->wpdb->get_var(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- counted ids and placeholders grow together.
                'SELECT id FROM ' . $this->table() . " WHERE snapshot_id IN ({$holders}) AND kind = %s AND locked_by <> %s AND (locked_until >= %d OR (status IN ({$statuses}) AND locked_by <> '' AND locked_by NOT LIKE %s AND locked_until >= %d)) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder lists from counted arrays.
                array_merge( $ids, array( self::KIND_UPLOAD, $token, $now ), $runnable, array( 'prune:%', $stale ) )
            )
        );
        return null === $other && '' === $this->wpdb->last_error;
    }

    /**
     * Release only this prune's claims; successful snapshots purge their rows.
     *
     * @param string $token Prune token.
     * @return void
     */
    public function release_prune_claims( string $token ): void {
        if ( ! str_starts_with( $token, 'prune:' ) ) {
            return;
        }
        $this->wpdb->query(
            $this->wpdb->prepare( 'UPDATE ' . $this->table() . " SET locked_by = '', locked_until = 0 WHERE kind = %s AND locked_by = %s", self::KIND_UPLOAD, $token )
        );
    }

    /**
     * Enqueue a cloud delete job for a pruned snapshot at one destination.
     * Idempotent: a delete job that already exists for the pair is left
     * untouched.
     *
     * @param int   $snapshot_id    Snapshot id.
     * @param int   $destination_id Storage destination id.
     * @param array $checkpoint     Initial checkpoint: prefix, keys, cursor, swept.
     *
     * @return bool True when the job is queued (inserted now or already
     *              present); false only on a real write failure.
     */
    public function enqueue_delete_job( int $snapshot_id, int $destination_id, array $checkpoint ): bool {
        return 'failed' !== $this->enqueue_delete_job_result( $snapshot_id, $destination_id, $checkpoint );
    }

    /**
     * Distinguish this pass's insert from a delete owned by another flow.
     *
     * @param int   $snapshot_id    Snapshot id.
     * @param int   $destination_id Storage destination id.
     * @param array $checkpoint     Initial delete checkpoint.
     * @return string inserted, existing, or failed.
     */
    public function enqueue_delete_job_result( int $snapshot_id, int $destination_id, array $checkpoint ): string {
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                'INSERT IGNORE INTO ' . $this->table_name( 'wp_backup_upload_job' ) .
                ' (snapshot_id, destination_id, kind, status, checkpoint, last_error, created, updated) VALUES (%d, %d, %s, %s, %s, %s, %d, %d)',
                $snapshot_id,
                $destination_id,
                static::KIND_DELETE,
                static::STATUS_PENDING,
                (string) wp_json_encode( $checkpoint ),
                '',
                time(),
                time()
            )
        );

        if ( ! empty( $result ) ) {
            return 'inserted';
        }

        // INSERT IGNORE reports 0 rows both for a duplicate (the job is
        // already queued - success for an idempotent enqueue) and for a
        // real failure. Only the latter may report failed: retention
        // holds a snapshot's prune on this answer, and treating an
        // already-queued job as failure would wedge the prune, while
        // treating a failed insert as success would orphan the cloud
        // copy forever.
        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT id FROM ' . $this->table_name( 'wp_backup_upload_job' ) . ' WHERE snapshot_id = %d AND destination_id = %d AND kind = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                $snapshot_id,
                $destination_id,
                static::KIND_DELETE
            )
        );
        if ( null !== $existing && ! empty( $checkpoint['manual_override'] ) ) {
            $current = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT checkpoint, locked_until FROM ' . $this->table() . ' WHERE id = %d', (int) $existing ) );
            if ( null === $current || (int) $current->locked_until >= time() ) {
                return 'failed';
            }
            $previous = json_decode( (string) $current->checkpoint, true );
            $previous = is_array( $previous ) ? $previous : array();
            if ( empty( $previous['manual_override'] ) ) {
                // Preserve every key and cursor. A changed checkpoint or new lease
                // refuses this upgrade so the caller retains its local source.
                $previous['manual_override'] = true;
                $previous['retention_cause'] = 'manual';
                $updated                     = $this->wpdb->query(
                    $this->wpdb->prepare(
                        'UPDATE ' . $this->table() . ' SET checkpoint = %s, next_retry_at = 0 WHERE id = %d AND checkpoint = %s AND locked_until < %d',
                        (string) wp_json_encode( $previous ),
                        (int) $existing,
                        (string) $current->checkpoint,
                        time()
                    )
                );
                if ( 1 !== $updated ) {
                    return 'failed';
                }
            }
        }
        return null !== $existing ? 'existing' : 'failed';
    }

    /**
     * Withdraw only newly inserted deletes that the delete lane has not claimed.
     *
     * @param int   $snapshot_id    Snapshot id.
     * @param int[] $destination_ids Destinations inserted by this prune pass.
     * @return int[] Destinations whose delete row could not be withdrawn.
     */
    public function withdraw_delete_jobs( int $snapshot_id, array $destination_ids ): array {
        $destination_ids = array_values( array_unique( array_filter( array_map( 'intval', $destination_ids ) ) ) );
        if ( empty( $destination_ids ) ) {
            return array();
        }
        $holders = implode( ',', array_fill( 0, count( $destination_ids ), '%d' ) );
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- counted destinations and placeholders grow together.
                'DELETE FROM ' . $this->table() . " WHERE snapshot_id = %d AND destination_id IN ({$holders}) AND kind = %s AND status = %s AND locked_by = '' AND locked_until < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list from counted destinations.
                array_merge( array( $snapshot_id ), $destination_ids, array( self::KIND_DELETE, self::STATUS_PENDING, time() ) )
            )
        );
        if ( false === $deleted ) {
            return $destination_ids;
        }
        $remaining = $this->wpdb->get_col(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- counted destinations and placeholders grow together.
                'SELECT destination_id FROM ' . $this->table() . " WHERE snapshot_id = %d AND destination_id IN ({$holders}) AND kind = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list from counted destinations.
                array_merge( array( $snapshot_id ), $destination_ids, array( self::KIND_DELETE ) )
            )
        );
        return '' === $this->wpdb->last_error ? array_map( 'intval', $remaining ) : $destination_ids;
    }

    /**
     * Hard-delete a pruned snapshot's upload rows. The snapshot row keeps
     * the audit trail; the resumable upload state has no further use once
     * its remote keys are collected into delete jobs.
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return void
     */
    public function purge_upload_jobs_for_snapshot( int $snapshot_id ): void {
        $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM ' . $this->table_name( 'wp_backup_upload_job' ) . ' WHERE snapshot_id = %d AND kind = %s',
                $snapshot_id,
                static::KIND_UPLOAD
            )
        );
    }

    /**
     * Persist a job's resumable checkpoint.
     *
     * @param int         $job_id     Job id.
     * @param array       $checkpoint Checkpoint data.
     * @param string|null $lock_token Fence the write on this lease token; null writes unfenced.
     *
     * @return bool True when persistence is confirmed; false on a changed
     *              checkpoint, a lost lease or an unconfirmed write.
     */
    public function save_checkpoint( int $job_id, array $checkpoint, ?string $lock_token = null ): bool {
        $row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT kind, checkpoint FROM %i WHERE id = %d', $this->table(), $job_id ) );
        if ( null === $row ) {
            return false;
        }
        if ( self::KIND_UPLOAD !== $row->kind ) {
            return $this->update_job( $job_id, array( 'checkpoint' => (string) wp_json_encode( $checkpoint ) ), array( '%s' ), $lock_token );
        }
        $current = json_decode( (string) $row->checkpoint, true );
        if ( (int) ( $current['storage_age_floor'] ?? 0 ) > 0 ) {
            $checkpoint['storage_age_floor'] = (int) $current['storage_age_floor'];
        }
        $encoded = wp_json_encode( $checkpoint );
        if ( false === $encoded ) {
            return false;
        }
        $now = time();
        // Compare a digest so the UPDATE only sends one copy of a large checkpoint.
        $binds = array( $this->table(), $encoded, $now, $job_id, md5( (string) $row->checkpoint ) );
        $fence = '';
        if ( null !== $lock_token ) {
            $fence   = ' AND locked_by = %s AND locked_until >= %d';
            $binds[] = $lock_token;
            $binds[] = $now;
        }
        $changed = $this->wpdb->query(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- optional fence and bind array grow together.
                "UPDATE %i SET `checkpoint` = %s, `updated` = %d WHERE id = %d AND MD5(checkpoint) = %s{$fence}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed fence, placeholder values.
                $binds
            )
        );
        if ( false === $changed ) {
            return false;
        }
        $expected_checkpoint = json_decode( $encoded, true );
        $expected_checkpoint = is_array( $expected_checkpoint ) ? $expected_checkpoint : array();
        $saved               = $this->get_job( $job_id );
        return null !== $saved && $saved->checkpoint === $expected_checkpoint
            && ( null === $lock_token || ( $saved->locked_by === $lock_token && (int) $saved->locked_until >= $now ) );
    }

    /**
     * Persist an unfinished upload's first retention observation exactly once.
     *
     * @param int $job_id Upload identity.
     * @param int $now Observation time.
     * @return int Durable floor, or zero when persistence is unconfirmed.
     */
    public function storage_age_floor( int $job_id, int $now ): int {
        $row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT checkpoint FROM %i WHERE id = %d AND kind = %s', $this->table(), $job_id, self::KIND_UPLOAD ) );
        if ( null === $row || $now <= 0 ) {
            return 0;
        }
        $checkpoint = json_decode( (string) $row->checkpoint, true );
        $checkpoint = is_array( $checkpoint ) ? $checkpoint : array();
        if ( (int) ( $checkpoint['storage_age_floor'] ?? 0 ) > 0 ) {
            return (int) $checkpoint['storage_age_floor'];
        }
        $checkpoint['storage_age_floor'] = $now;
        $changed                         = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE %i SET checkpoint = %s WHERE id = %d AND kind = %s AND MD5(checkpoint) = %s',
                $this->table(),
                (string) wp_json_encode( $checkpoint ),
                $job_id,
                self::KIND_UPLOAD,
                md5( (string) $row->checkpoint )
            )
        );
        if ( false === $changed ) {
            return 0;
        }
        $saved = $this->get_job( $job_id );
        return (int) ( $saved->checkpoint['storage_age_floor'] ?? 0 );
    }

    /**
     * Move a job to a new in-flight phase.
     *
     * @param int         $job_id     Job id.
     * @param string      $status     New status.
     * @param string|null $lock_token Fence the write on this lease token; null writes unfenced.
     *
     * @return bool True when the write landed; false when the fence
     *              refused it because the lease was lost.
     */
    public function set_status( int $job_id, string $status, ?string $lock_token = null ): bool {
        return $this->update_job( $job_id, array( 'status' => $status ), array( '%s' ), $lock_token );
    }

    /**
     * The statuses an in-flight job can hold. Terminal writes are only
     * allowed from these, so the first terminal writer wins and a
     * finished, failed, or credentials-parked job is never relabeled.
     *
     * @return string[] Active statuses.
     */
    public static function active_statuses(): array {
        return array(
            self::STATUS_PENDING,
            self::STATUS_CHUNKING,
            self::STATUS_UPLOADING,
            self::STATUS_SUBMITTING,
            self::STATUS_FAILED_RETRYABLE,
        );
    }

    /**
     * Mark a job complete and release its lock.
     *
     * @param int         $job_id     Job id.
     * @param string|null $lock_token Fence the transition on this lease token; null skips the fence.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal (or the fence refused a lost
     *              lease) and the write was refused.
     */
    public function complete_job( int $job_id, ?string $lock_token = null ): bool {
        $settled = $this->terminal_update(
            $job_id,
            array(
                'status'       => static::STATUS_COMPLETE,
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            $lock_token
        );
        if ( $settled ) {
            $this->fire_settled( $job_id );
        }
        return $settled;
    }

    /**
     * Defer protected deletion without recording a failed upload attempt.
     *
     * @param int         $job_id Delete job identity.
     * @param int         $until Next protection check.
     * @param string|null $lock_token Current lease owner.
     * @return bool Whether the fenced defer persisted.
     */
    public function defer_delete_for_retention( int $job_id, int $until, ?string $lock_token = null ): bool {
        return $this->update_job(
            $job_id,
            array(
                'next_retry_at' => $until,
                'locked_until'  => 0,
                'locked_by'     => '',
            ),
            array( '%d', '%d', '%s' ),
            $lock_token
        );
    }

    /**
     * Record a retryable failure: count the attempt, schedule the next
     * one, release the lock.
     *
     * Deliberately not shared with the restore store's fail_retryable:
     * this one increments attempt_count inside SQL so racing lanes each
     * count their own attempt, while the restore orchestrator owns its
     * retry count and passes it in.
     *
     * @param int         $job_id        Job id.
     * @param string      $error         Error description.
     * @param int         $next_retry_at Epoch after which the job may run again.
     * @param string|null $lock_token    Fence the write on this lease token; null writes unfenced.
     *
     * @return bool True when the write landed; false when the fence
     *              refused it because the lease was lost.
     */
    public function fail_retryable( int $job_id, string $error, int $next_retry_at, ?string $lock_token = null ): bool {
        $now   = time();
        $fence = '';
        $binds = array(
            static::STATUS_FAILED_RETRYABLE,
            $next_retry_at,
            $error,
            '',
            $now,
            $job_id,
        );
        if ( null !== $lock_token ) {
            $fence   = ' AND locked_by = %s AND locked_until >= %d';
            $binds[] = $lock_token;
            $binds[] = $now;
        }

        $changed = $this->wpdb->query(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list and bind array grow together.
                'UPDATE ' . $this->table_name( 'wp_backup_upload_job' ) .
                " SET status = %s, attempt_count = attempt_count + 1, next_retry_at = %d, last_error = %s, locked_until = 0, locked_by = %s, updated = %d WHERE id = %d{$fence}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fence is a fixed SQL fragment; its value binds via placeholder.
                $binds
            )
        );

        if ( 1 === $changed ) {
            return true;
        }
        if ( false === $changed || null === $lock_token ) {
            return false;
        }

        // An unchanged same-second write succeeds only while the lease is live.
        $holder = $this->wpdb->get_row(
            $this->wpdb->prepare( 'SELECT locked_by, locked_until FROM ' . $this->table() . ' WHERE id = %d', $job_id )
        );
        return null !== $holder && $holder->locked_by === $lock_token && (int) $holder->locked_until >= $now;
    }

    /**
     * Mark a job permanently failed and release its lock.
     *
     * @param int         $job_id     Job id.
     * @param string      $error      Error description.
     * @param string|null $lock_token Fence the transition on this lease token; null skips the fence.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal (or the fence refused a lost
     *              lease) and the write was refused.
     */
    public function fail_permanent( int $job_id, string $error, ?string $lock_token = null ): bool {
        $settled = $this->terminal_update(
            $job_id,
            array(
                'status'       => static::STATUS_FAILED_PERMANENT,
                'last_error'   => $error,
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            $lock_token
        );
        if ( $settled ) {
            $this->fire_settled( $job_id );
        }
        return $settled;
    }

    /**
     * Mark a job blocked on credentials and release its lock. The UI
     * surfaces this as a re-enter-credentials state on the destination.
     *
     * @param int         $job_id     Job id.
     * @param string      $error      Error description.
     * @param string|null $lock_token Fence the transition on this lease token; null skips the fence.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal (or the fence refused a lost
     *              lease) and the write was refused.
     */
    public function invalidate_credentials( int $job_id, string $error, ?string $lock_token = null ): bool {
        $settled = $this->terminal_update(
            $job_id,
            array(
                'status'       => static::STATUS_CREDENTIALS_INVALID,
                'last_error'   => $error,
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            $lock_token
        );
        if ( $settled ) {
            $this->fire_settled( $job_id );
        }
        return $settled;
    }

    /**
     * Announce that an upload job reached a terminal state so the
     * snapshot's offsite-verified rollup recomputes once. Delete-kind
     * jobs never affect offsite state, so only upload jobs fire.
     *
     * @param int $job_id Job id.
     *
     * @return void
     */
    private function fire_settled( int $job_id ): void {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT snapshot_id, kind FROM ' . $this->table_name( 'wp_backup_upload_job' ) . ' WHERE id = %d',
                $job_id
            )
        );
        if ( null === $row || static::KIND_UPLOAD !== $row->kind ) {
            return;
        }

        /**
         * Fires when an upload job reaches a terminal state (complete,
         * failed_permanent, credentials_invalid), so a single listener can
         * recompute the snapshot's offsite-verified state.
         *
         * @param int $snapshot_id Snapshot id.
         */
        do_action( 'mainwp_backup_upload_job_settled', (int) $row->snapshot_id );
    }
}
