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
class MainWP_Backup_Upload_Job_Store extends MainWP_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, table identifiers cannot be prepared.

    const KIND_UPLOAD = 'upload';
    const KIND_DELETE = 'delete';

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

            // The unique key on (snapshot_id, destination_id) makes the
            // duplicate insert a no-op rather than an error.
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    'INSERT IGNORE INTO ' . $this->table_name( 'wp_backup_upload_job' ) .
                    ' (snapshot_id, destination_id, status, checkpoint, last_error, created, updated) VALUES (%d, %d, %s, %s, %s, %d, %d)',
                    $snapshot_id,
                    $destination_id,
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

        // Single-table UPDATE with ORDER BY + LIMIT is atomic under InnoDB
        // row locking; only one caller wins the row.
        $claimed = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table_name( 'wp_backup_upload_job' ) .
                ' SET locked_until = %d, locked_by = %s, updated = %d WHERE kind = %s AND status IN (%s, %s, %s, %s, %s) AND next_retry_at <= %d AND locked_until < %d ORDER BY id ASC LIMIT 1',
                array_merge( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the merged arrays supply all eleven replacements.
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
     * Mark a job complete and release its lock.
     *
     * @param int $job_id Job id.
     *
     * @return void
     */
    public function complete_job( int $job_id ): void {
        $this->update_job(
            $job_id,
            array(
                'status'       => static::STATUS_COMPLETE,
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            array( '%s', '%d', '%s' )
        );
    }

    /**
     * Record a retryable failure: count the attempt, schedule the next
     * one, release the lock.
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
     * @return void
     */
    public function fail_permanent( int $job_id, string $error ): void {
        $this->update_job(
            $job_id,
            array(
                'status'       => static::STATUS_FAILED_PERMANENT,
                'last_error'   => $error,
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            array( '%s', '%s', '%d', '%s' )
        );
    }

    /**
     * Mark a job blocked on credentials and release its lock. The UI
     * surfaces this as a re-enter-credentials state on the destination.
     *
     * @param int    $job_id Job id.
     * @param string $error  Error description.
     *
     * @return void
     */
    public function invalidate_credentials( int $job_id, string $error ): void {
        $this->update_job(
            $job_id,
            array(
                'status'       => static::STATUS_CREDENTIALS_INVALID,
                'last_error'   => $error,
                'locked_until' => 0,
                'locked_by'    => '',
            ),
            array( '%s', '%s', '%d', '%s' )
        );
    }

    /**
     * Release a job's lock, only for the token that holds it.
     *
     * @param int    $job_id Job id.
     * @param string $token  The holder's lock token.
     *
     * @return void
     */
    public function release_lock( int $job_id, string $token ): void {
        $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table_name( 'wp_backup_upload_job' ) .
                ' SET locked_until = 0, locked_by = %s, updated = %d WHERE id = %d AND locked_by = %s',
                '',
                time(),
                $job_id,
                $token
            )
        );
    }

    /**
     * Update job columns by id.
     *
     * @param int   $job_id Job id.
     * @param array $row    Columns to set.
     * @param array $format Column formats.
     *
     * @return void
     */
    private function update_job( int $job_id, array $row, array $format ): void {
        $row['updated'] = time();
        $format[]       = '%d';

        $this->wpdb->update(
            $this->table_name( 'wp_backup_upload_job' ),
            $row,
            array( 'id' => $job_id ),
            $format,
            array( '%d' )
        );
    }

    /**
     * Decode the checkpoint JSON on a job row.
     *
     * @param object|null $row Raw job row.
     *
     * @return object|null Row with checkpoint as array.
     */
    private function decode_row( ?object $row ): ?object {
        if ( null === $row ) {
            return null;
        }

        $decoded         = json_decode( (string) $row->checkpoint, true );
        $row->checkpoint = is_array( $decoded ) ? $decoded : array();

        return $row;
    }
}
