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
            self::STATUS_PRE_SNAPSHOT,
            self::STATUS_STAGING,
            self::STATUS_TRANSFERRING,
            self::STATUS_RUNNING,
            self::STATUS_VERIFYING,
            self::STATUS_FAILED_RETRYABLE,
        );
    }

    /**
     * Create a restore job.
     *
     * @param int   $site_id     Child site id.
     * @param int   $snapshot_id Snapshot id.
     * @param array $checkpoint  Initial checkpoint (plan + options).
     *
     * @return int Job id, or 0 on failure.
     */
    public function create_job( int $site_id, int $snapshot_id, array $checkpoint ): int {
        $now      = time();
        $inserted = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated restore job table.
            $this->table(),
            array(
                'site_id'     => $site_id,
                'snapshot_id' => $snapshot_id,
                'status'      => self::STATUS_PENDING,
                'checkpoint'  => wp_json_encode( $checkpoint ),
                'created'     => $now,
                'updated'     => $now,
            ),
            array( '%d', '%d', '%s', '%s', '%d', '%d' )
        );
        return $inserted ? (int) $this->wpdb->insert_id : 0;
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
     *
     * @return object|null Locked job or null.
     */
    public function acquire_next_job( string $token, int $lease_seconds = 300, ?int $now = null ): ?object {
        $now          = null === $now ? time() : $now;
        $statuses     = array(
            self::STATUS_PENDING,
            self::STATUS_PRE_SNAPSHOT,
            self::STATUS_STAGING,
            self::STATUS_TRANSFERRING,
            self::STATUS_RUNNING,
            self::STATUS_VERIFYING,
            self::STATUS_FAILED_RETRYABLE,
        );
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $claimed = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the atomic claim gate; must hit the DB engine.
            $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements and placeholders come from the same counted array.
                'UPDATE ' . $this->table() . " SET locked_until = %d, locked_by = %s, updated = %d WHERE status IN ( {$placeholders} ) AND next_retry_at <= %d AND locked_until < %d ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from a counted array.
                array_merge( array( $now + $lease_seconds, $token, $now ), $statuses, array( $now, $now ) )
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
     * Persist the checkpoint.
     *
     * @param int   $job_id     Job id.
     * @param array $checkpoint Checkpoint.
     */
    public function save_checkpoint( int $job_id, array $checkpoint ): void {
        $this->update_job(
            $job_id,
            array( 'checkpoint' => wp_json_encode( $checkpoint ) ),
            array( '%s' )
        );
    }

    /**
     * Move a job to a status, optionally recording handles.
     *
     * @param int    $job_id Job id.
     * @param string $status New status.
     * @param array  $extra  Extra columns (child_task_id, pre_snapshot_task_id).
     */
    public function set_status( int $job_id, string $status, array $extra = array() ): void {
        $row    = array_merge( array( 'status' => $status ), $extra );
        $format = array_fill( 0, count( $row ), '%s' );
        $this->update_job( $job_id, $row, $format );
    }

    /**
     * Record a retryable failure with exponential backoff.
     *
     * @param int    $job_id        Job id.
     * @param string $error         Error text.
     * @param int    $attempt_count New attempt count.
     * @param int    $next_retry_at Retry epoch.
     */
    public function fail_retryable( int $job_id, string $error, int $attempt_count, int $next_retry_at ): void {
        $this->update_job(
            $job_id,
            array(
                'status'        => self::STATUS_FAILED_RETRYABLE,
                'last_error'    => $error,
                'attempt_count' => $attempt_count,
                'next_retry_at' => $next_retry_at,
                'locked_until'  => 0,
                'locked_by'     => '',
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s' )
        );
    }

    /**
     * Record a permanent failure.
     *
     * @param int    $job_id Job id.
     * @param string $error  Error text.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal and the write was refused.
     */
    public function fail_permanent( int $job_id, string $error ): bool {
        return $this->terminal_update(
            $job_id,
            array(
                'status'       => self::STATUS_FAILED_PERMANENT,
                'last_error'   => $error,
                'locked_until' => 0,
                'locked_by'    => '',
            )
        );
    }

    /**
     * Complete a job.
     *
     * @param int $job_id Job id.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal and the write was refused.
     */
    public function complete_job( int $job_id ): bool {
        return $this->terminal_update(
            $job_id,
            array(
                'status'       => self::STATUS_COMPLETED,
                'last_error'   => '',
                'locked_until' => 0,
                'locked_by'    => '',
            )
        );
    }

    /**
     * Cancel a job.
     *
     * @param int $job_id Job id.
     *
     * @return bool True when the job left an active status; false when it
     *              was already terminal and the write was refused.
     */
    public function cancel_job( int $job_id ): bool {
        return $this->terminal_update(
            $job_id,
            array(
                'status'       => self::STATUS_CANCELLED,
                'locked_until' => 0,
                'locked_by'    => '',
            )
        );
    }

}
