<?php
/**
 * MainWP Backup Cloud Delete Driver.
 *
 * Drains the resumable cloud delete jobs retention enqueues for pruned
 * snapshots. Each cron tick acquires runnable kind=delete jobs under
 * a lease lock and works within a time budget: walk the checkpointed
 * key list through the destination provider (deletes are idempotent, a
 * missing key counts as success), then sweep the snapshot's remote
 * prefix for anything the checkpoints missed - partial uploads, drift -
 * before completing. Nothing outside the {site}/{snapshot}/ prefix is
 * ever touched. Failures map exactly like uploads: auth rejections park
 * the job for re-entered credentials, transient trouble backs off
 * exponentially, exhausted attempts go permanent.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Cloud_Delete_Driver
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Cloud_Delete_Driver { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Default per-tick work budget in seconds. Smaller than the upload
     * driver's: both ride the same five-minute lane.
     *
     * @var int
     */
    const DEFAULT_BUDGET_SECONDS = 60;

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Cloud_Delete_Driver or null.
     */
    private static $instance = null;

    /**
     * The lease token held for the job being run, null outside a run.
     * Every store write during the run is fenced on it, so a worker whose
     * lease was reclaimed can no longer overwrite the new holder's state.
     *
     * @var string|null
     */
    private ?string $lock_token = null;

    /**
     * The lease duration renewals extend by, matching the acquisition.
     *
     * @var int
     */
    private int $lease_seconds = 300;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Cloud_Delete_Driver
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Drain pending cloud delete jobs within a shared time budget.
     * Rides the backups-continue cron lane after the upload driver.
     *
     * @param int      $budget_seconds Work budget for this tick.
     * @param int|null $now            Clock override for tests.
     *
     * @return void
     */
    public function process_pending_delete_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $now = null ): void {
        $now           = null === $now ? time() : $now;
        $store         = MainWP_Backup_Upload_Job_Store::instance();
        $deadline      = microtime( true ) + $budget_seconds;
        $lease_seconds = max( 300, $budget_seconds + 60 );

        do {
            $token = uniqid( 'delete_', true );
            $job   = $store->acquire_next_job( $token, $lease_seconds, $now, MainWP_Backup_Upload_Job_Store::KIND_DELETE );
            if ( null === $job ) {
                return;
            }

            $this->lock_token    = $token;
            $this->lease_seconds = $lease_seconds;
            try {
                $finished = $this->run_job( $job, $deadline, $now );
            } finally {
                $this->lock_token = null;
                $store->release_lock( (int) $job->id, $token );
            }
        } while ( $finished && microtime( true ) < $deadline );
    }

    /**
     * Renew the run's lease between provider round trips.
     *
     * False means the lease was already reclaimed: the caller stops its
     * work instead of re-deleting keys the new holder is walking.
     *
     * @param object $job Job row.
     *
     * @return bool True when work on the job may continue.
     */
    private function lease_still_held( object $job ): bool {
        if ( null === $this->lock_token ) {
            return true;
        }
        return MainWP_Backup_Upload_Job_Store::instance()->renew_lease( (int) $job->id, $this->lock_token, $this->lease_seconds );
    }

    /**
     * Run one delete job until it completes, fails, or the deadline
     * passes. The job stays pending across deadline interrupts; the lease
     * lock alone serializes the work.
     *
     * @param object $job      The acquired job row.
     * @param float  $deadline Monotonic deadline from microtime(true).
     * @param int    $now      Wall-clock for retry scheduling.
     *
     * @throws \RuntimeException When a provider refuses a delete, handled as a job failure.
     *
     * @return bool False when an unfinished walk must yield this tick.
     */
    private function run_job( object $job, float $deadline, int $now ): bool {
        $store = MainWP_Backup_Upload_Job_Store::instance();

        $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $job->destination_id );
        if ( null === $destination ) {
            $store->fail_permanent( (int) $job->id, 'Storage destination was deleted; its objects are unreachable.', $this->lock_token );
            return true;
        }

        if ( $destination->credentials_invalid ) {
            $store->invalidate_credentials( (int) $job->id, 'Stored credentials no longer decrypt; re-enter them on the destination.', $this->lock_token );
            return true;
        }

        try {
            $provider = MainWP_Backup_Storage_Destinations::instance()->make_provider( $destination );
        } catch ( \RuntimeException $e ) {
            $store->fail_permanent( (int) $job->id, $e->getMessage(), $this->lock_token );
            return true;
        }

        try {
            $checkpoint = $job->checkpoint;
            $keys       = isset( $checkpoint['keys'] ) && is_array( $checkpoint['keys'] ) ? $checkpoint['keys'] : array();
            $cursor     = isset( $checkpoint['cursor'] ) ? (int) $checkpoint['cursor'] : 0;

            $total = count( $keys );
            for ( $i = $cursor; $i < $total; $i++ ) {
                if ( microtime( true ) >= $deadline ) {
                    $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                    return false;
                }
                // Renew across the blocking provider delete; a reclaimed
                // lease stops the walk instead of racing the new holder.
                if ( ! $this->lease_still_held( $job ) ) {
                    return false;
                }

                if ( empty( $keys[ $i ]['deleted'] ) && ! empty( $keys[ $i ]['key'] ) ) {
                    if ( false === $provider->delete_object( (string) $keys[ $i ]['key'] ) ) {
                        throw new \RuntimeException( esc_html( 'Could not delete object: ' . $keys[ $i ]['key'] ) );
                    }
                }

                $checkpoint['keys'][ $i ]['deleted'] = true;
                $checkpoint['cursor']                = $i + 1;
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
            }

            if ( empty( $checkpoint['swept'] ) ) {
                if ( ! $this->sweep_prefix( $job, $checkpoint, $provider, $deadline ) ) {
                    return false; // Deadline or lost lease; the sweep restarts next tick - deletes are idempotent.
                }
                $checkpoint['swept'] = true;
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
            }

            $store->complete_job( (int) $job->id, $this->lock_token );
        } catch ( \Throwable $e ) {
            $this->handle_failure( $job, $destination, $e, $now );
        }
        return true;
    }

    /**
     * Delete everything still listed under the snapshot's remote prefix.
     * Catches objects no checkpoint mentions: chunks from a partial
     * upload attempt, or drift between checkpoint and destination - the
     * destination's listing is authoritative. The prefix is wholly owned
     * by the pruned snapshot, so deleting all of it is safe; nothing
     * wider is ever listed.
     *
     * @param object                         $job        Job row.
     * @param array                          $checkpoint Checkpoint.
     * @param MainWP_Backup_Storage_Provider $provider   Destination provider.
     * @param float                          $deadline   Monotonic deadline.
     *
     * @throws \RuntimeException When a provider refuses a delete.
     *
     * @return bool True when the sweep finished; false on deadline.
     */
    private function sweep_prefix( object $job, array $checkpoint, MainWP_Backup_Storage_Provider $provider, float $deadline ): bool {
        $prefix = isset( $checkpoint['prefix'] ) ? (string) $checkpoint['prefix'] : '';
        if ( '' === $prefix ) {
            return true; // No prefix recorded; the key walk covered everything known.
        }

        $token = null;
        do {
            if ( microtime( true ) >= $deadline || ! $this->lease_still_held( $job ) ) {
                return false;
            }

            $page = $provider->list_objects( $prefix, $token );

            foreach ( $page['objects'] as $object ) {
                if ( microtime( true ) >= $deadline ) {
                    return false;
                }
                if ( false === $provider->delete_object( (string) $object['key'] ) ) {
                    throw new \RuntimeException( esc_html( 'Could not delete object: ' . $object['key'] ) );
                }
            }

            $token = isset( $page['next_token'] ) ? $page['next_token'] : null;
        } while ( ! empty( $page['truncated'] ) && null !== $token );

        return true;
    }

    /**
     * Map a failure to its terminal or retryable outcome, mirroring the
     * upload driver.
     *
     * @param object     $job         Job row.
     * @param object     $destination Destination row.
     * @param \Throwable $e           The failure.
     * @param int        $now         Wall-clock for retry scheduling.
     *
     * @return void
     */
    private function handle_failure( object $job, object $destination, \Throwable $e, int $now ): void {
        $store = MainWP_Backup_Upload_Job_Store::instance();
        $code  = (int) $e->getCode();

        $error = '[' . $this->error_code_for_provider( (string) $destination->provider ) . '] ' . $e->getMessage();

        // Fenced like every other write in the run: a reclaimed worker's
        // failure belongs to a run the new holder may yet complete.
        if ( 401 === $code || 403 === $code ) {
            $store->invalidate_credentials( (int) $job->id, $error, $this->lock_token );
            return;
        }

        $attempts = (int) $job->attempt_count + 1;
        if ( MainWP_Backup_Backoff::attempts_exhausted( $attempts ) ) {
            $store->fail_permanent( (int) $job->id, $error, $this->lock_token );
            return;
        }

        $store->fail_retryable( (int) $job->id, $error, $now + MainWP_Backup_Backoff::delay_for_attempt( $attempts ), $this->lock_token );
    }

    /**
     * The registry error code matching a provider.
     *
     * @param string $provider Provider slug.
     *
     * @return string
     */
    private function error_code_for_provider( string $provider ): string {
        switch ( $provider ) {
            case 's3':
                return MainWP_Backup_Error_Codes::ERR_S3_FAILED;
            case 'gcs':
                return MainWP_Backup_Error_Codes::ERR_GCS_FAILED;
            case 'dropbox':
                return MainWP_Backup_Error_Codes::ERR_DROPBOX_FAILED;
            case 'ftp':
            case 'ftps':
            case 'sftp':
                return MainWP_Backup_Error_Codes::ERR_FTP_FAILED;
            default:
                return MainWP_Backup_Error_Codes::ERR_S3_FAILED;
        }
    }
}
