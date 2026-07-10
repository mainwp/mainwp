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
     * The conditional INSERT writes a row only when no active job exists
     * for the site, so a racing double-submit cannot persist two pending
     * restores. The NOT EXISTS subquery reads a different alias from the
     * insert target, so the engine accepts reading the table it writes.
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

        $result = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the atomic create gate; must hit the DB engine.
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements and placeholders come from the same counted array.
                'INSERT INTO ' . $this->table() . ' ( site_id, snapshot_id, status, checkpoint, created, updated ) SELECT %d, %d, %s, %s, %d, %d FROM DUAL WHERE NOT EXISTS ( SELECT 1 FROM ' . $this->table() . " WHERE site_id = %d AND status IN ( {$placeholders} ) LIMIT 1 )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from a counted array; table name from trusted helper.
                array_merge( array( $site_id, $snapshot_id, self::STATUS_PENDING, wp_json_encode( $checkpoint ), $now, $now, $site_id ), $statuses )
            )
        );

        if ( false === $result ) {
            return -1; // A real engine failure, not a suppressed duplicate.
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
        $statuses     = array(
            self::STATUS_PENDING,
            self::STATUS_PREFLIGHT,
            self::STATUS_PRE_SNAPSHOT,
            self::STATUS_STAGING,
            self::STATUS_TRANSFERRING,
            self::STATUS_RUNNING,
            self::STATUS_VERIFYING,
            self::STATUS_FAILED_RETRYABLE,
        );
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        // A job whose expired lease has already been reclaimed past the
        // ceiling is killed here, before it can be handed out again. A
        // scoped foreground poll (job_id given) only ever fail-permanents
        // its own job, honoring the per-job scoping contract below; the
        // unscoped background/CLI drainer sweeps every over-ceiling job.
        $this->fail_jobs_over_reclaim_ceiling( $statuses, $placeholders, $now, $job_id );

        // Scope the claim to one job when an id is given (a foreground poll
        // advancing the job it polls), otherwise take the next runnable job.
        $id_clause = null === $job_id ? '' : ' AND id = %d';
        $binds     = array_merge( array( $now + $lease_seconds, $token, $now ), $statuses, array( $now, $now, $now ) );
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
                'UPDATE ' . $this->table() . " SET reclaim_count = CASE WHEN locked_by <> '' THEN reclaim_count + 1 ELSE reclaim_count END, locked_until = %d, locked_by = %s, updated = %d WHERE status IN ( {$placeholders} ) AND next_retry_at <= %d AND locked_until < %d AND site_id NOT IN ( SELECT site_id FROM ( SELECT site_id FROM " . $this->table() . " WHERE locked_until >= %d ) AS mainwp_locked_sites ){$id_clause} ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from a counted array; table name from trusted helper.
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
     * Permanently fail any job whose expired lease has been reclaimed at
     * least RECLAIM_CEILING times, before a fresh claim attempt can
     * reclaim it again. A job that keeps losing its worker to a hard
     * fatal never reaches fail_retryable(), so attempt_count never bounds
     * it; this is the check that stops the loop instead.
     *
     * When $job_id is given, the sweep is scoped to that job only - a
     * foreground progress poll must never fail-permanent an unrelated
     * restore for another site just because it polled its own job. The
     * unscoped background/CLI drainer (job_id null) sweeps every
     * over-ceiling job across the table.
     *
     * @param string[] $statuses     Runnable statuses.
     * @param string   $placeholders Comma-joined %s placeholders matching $statuses.
     * @param int      $now          Current time.
     * @param int|null $job_id       Restrict the sweep to this job id, or null for every job.
     *
     * @return void
     */
    private function fail_jobs_over_reclaim_ceiling( array $statuses, string $placeholders, int $now, ?int $job_id = null ): void {
        $id_clause = null === $job_id ? '' : ' AND id = %d';
        $binds     = array_merge( $statuses, array( $now, static::RECLAIM_CEILING ) );
        if ( null !== $job_id ) {
            $binds[] = $job_id;
        }

        $ids = $this->wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements and placeholders come from the same counted array.
                'SELECT id FROM ' . $this->table() . " WHERE status IN ( {$placeholders} ) AND locked_by <> '' AND locked_until < %d AND reclaim_count >= %d{$id_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from a counted array; table name from trusted helper.
                $binds
            )
        );

        foreach ( $ids as $id ) {
            $this->fail_permanent(
                (int) $id,
                sprintf( 'Reclaimed %d times without completing a phase; the worker keeps dying on this restore, so it is stopped instead of retried forever.', static::RECLAIM_CEILING )
            );
        }
    }

    /**
     * Renew a held lease, only for the token that holds it.
     *
     * A slow-but-alive worker calls this between long operations so its
     * lease never expires under it; a worker whose lease was already
     * reclaimed gets false and must stop its slice instead of writing
     * over the new holder.
     *
     * @param int      $job_id        Job id.
     * @param string   $token         The holder's lock token.
     * @param int      $lease_seconds New lease duration from now.
     * @param int|null $now           Clock override for tests.
     *
     * @return bool True when the token still holds the lease.
     */
    public function renew_lease( int $job_id, string $token, int $lease_seconds, ?int $now = null ): bool {
        $now = null === $now ? time() : $now;

        $renewed = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare(
                'UPDATE ' . $this->table() . ' SET locked_until = %d, updated = %d WHERE id = %d AND locked_by = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                $now + $lease_seconds,
                $now,
                $job_id,
                $token
            )
        );
        if ( 1 === $renewed ) {
            return true;
        }

        // Zero affected rows is ambiguous: a same-second renewal writes
        // identical values, which MySQL reports as unaffected. Only a
        // confirmed token mismatch means the lease was lost.
        $holder = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->wpdb->prepare( 'SELECT locked_by FROM ' . $this->table() . ' WHERE id = %d', $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
        );
        return $holder === $token;
    }

    /**
     * Persist the checkpoint.
     *
     * @param int         $job_id     Job id.
     * @param array       $checkpoint Checkpoint.
     * @param string|null $lock_token Fence the write on this lease token; null writes unfenced.
     *
     * @return bool True when the write landed; false when the fence
     *              refused it because the lease was lost.
     */
    public function save_checkpoint( int $job_id, array $checkpoint, ?string $lock_token = null ): bool {
        return $this->update_job(
            $job_id,
            array( 'checkpoint' => wp_json_encode( $checkpoint ) ),
            array( '%s' ),
            $lock_token
        );
    }

    /**
     * Move a job to a status, optionally recording handles.
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
        $row = array_merge( array( 'status' => $status ), $extra );
        if ( ! in_array( $status, array( self::STATUS_FAILED_RETRYABLE, self::STATUS_FAILED_PERMANENT ), true ) && ! array_key_exists( 'last_error', $row ) ) {
            // A job moving forward has outlived its recorded failure;
            // leaving the message in place keeps it displaying through
            // every later phase.
            $row['last_error'] = '';
        }
        $format = array_fill( 0, count( $row ), '%s' );
        return $this->update_job( $job_id, $row, $format, $lock_token );
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
        return $this->update_job(
            $job_id,
            array(
                'status'        => self::STATUS_FAILED_RETRYABLE,
                'last_error'    => $error,
                'attempt_count' => $attempt_count,
                'next_retry_at' => $next_retry_at,
                'locked_until'  => 0,
                'locked_by'     => '',
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s' ),
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
        return $this->terminal_update(
            $job_id,
            array(
                'status'       => self::STATUS_FAILED_PERMANENT,
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
        return $this->terminal_update(
            $job_id,
            array(
                'status'       => self::STATUS_COMPLETED,
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
        return $this->terminal_update(
            $job_id,
            array(
                'status'       => self::STATUS_CANCELLED,
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            $lock_token
        );
    }
}
