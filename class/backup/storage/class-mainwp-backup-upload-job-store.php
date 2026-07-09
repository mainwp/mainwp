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
        // both of which leave locked_by empty).
        $claimed = $this->wpdb->query(
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the merged arrays supply all eleven replacements.
                'UPDATE ' . $this->table_name( 'wp_backup_upload_job' ) .
                " SET reclaim_count = CASE WHEN locked_by <> '' THEN reclaim_count + 1 ELSE reclaim_count END, locked_until = %d, locked_by = %s, updated = %d WHERE kind = %s AND status IN (%s, %s, %s, %s, %s) AND next_retry_at <= %d AND locked_until < %d ORDER BY id ASC LIMIT 1",
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
                " WHERE kind = %s AND status IN (%s, %s, %s, %s, %s) AND locked_by <> '' AND locked_until < %d AND reclaim_count >= %d",
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
     * Enqueue a cloud delete job for a pruned snapshot at one destination.
     * Idempotent: a delete job that already exists for the pair is left
     * untouched.
     *
     * @param int   $snapshot_id    Snapshot id.
     * @param int   $destination_id Storage destination id.
     * @param array $checkpoint     Initial checkpoint: prefix, keys, cursor, swept.
     *
     * @return bool Whether a row was inserted.
     */
    public function enqueue_delete_job( int $snapshot_id, int $destination_id, array $checkpoint ): bool {
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

        return ! empty( $result );
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
     * @param int   $job_id     Job id.
     * @param array $checkpoint Checkpoint data.
     *
     * @return void
     */
    public function save_checkpoint( int $job_id, array $checkpoint ): void {
        $this->update_job( $job_id, array( 'checkpoint' => (string) wp_json_encode( $checkpoint ) ), array( '%s' ) );
    }

    /**
     * Move a job to a new in-flight phase.
     *
     * @param int    $job_id Job id.
     * @param string $status New status.
     *
     * @return void
     */
    public function set_status( int $job_id, string $status ): void {
        $this->update_job( $job_id, array( 'status' => $status ), array( '%s' ) );
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
     * @param int $job_id Job id.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal and the write was refused.
     */
    public function complete_job( int $job_id ): bool {
        $settled = $this->terminal_update(
            $job_id,
            array(
                'status'       => static::STATUS_COMPLETE,
                'locked_until' => 0,
                'locked_by'    => '',
            )
        );
        if ( $settled ) {
            $this->fire_settled( $job_id );
        }
        return $settled;
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
     * @param int    $job_id        Job id.
     * @param string $error         Error description.
     * @param int    $next_retry_at Epoch after which the job may run again.
     *
     * @return void
     */
    public function fail_retryable( int $job_id, string $error, int $next_retry_at ): void {
        $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table_name( 'wp_backup_upload_job' ) .
                ' SET status = %s, attempt_count = attempt_count + 1, next_retry_at = %d, last_error = %s, locked_until = 0, locked_by = %s, updated = %d WHERE id = %d',
                static::STATUS_FAILED_RETRYABLE,
                $next_retry_at,
                $error,
                '',
                time(),
                $job_id
            )
        );
    }

    /**
     * Mark a job permanently failed and release its lock.
     *
     * @param int    $job_id Job id.
     * @param string $error  Error description.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal and the write was refused.
     */
    public function fail_permanent( int $job_id, string $error ): bool {
        $settled = $this->terminal_update(
            $job_id,
            array(
                'status'       => static::STATUS_FAILED_PERMANENT,
                'last_error'   => $error,
                'locked_until' => 0,
                'locked_by'    => '',
            )
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
     * @param int    $job_id Job id.
     * @param string $error  Error description.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal and the write was refused.
     */
    public function invalidate_credentials( int $job_id, string $error ): bool {
        $settled = $this->terminal_update(
            $job_id,
            array(
                'status'       => static::STATUS_CREDENTIALS_INVALID,
                'last_error'   => $error,
                'locked_until' => 0,
                'locked_by'    => '',
            )
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
