<?php
/**
 * MainWP Abilities Cron Handlers
 *
 * Processes batch operations (sync, update, batch) queued via the Abilities API.
 * Jobs >200 sites are queued to transients and processed in chunks by these handlers.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Cron
 *
 * Handles cron processing for batch Abilities API operations.
 * Processes jobs in chunks of 20 sites with timeout protection and reschedule logic.
 */
class MainWP_Abilities_Cron { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Singleton instance.
     *
     * @var null|self
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Private to enforce singleton pattern via instance().
     * Registers cron action handlers for batch processing.
     */
    private function __construct() {
        // Register cron handlers for batch job processing.
        add_action( 'mainwp_process_sync_job', array( $this, 'process_sync_job' ) );
        add_action( 'mainwp_process_update_job', array( $this, 'process_update_job' ) );
        add_action( 'mainwp_process_batch_job', array( $this, 'process_batch_job' ) );
    }

    /**
     * Prevent cloning of the singleton instance.
     *
     * @return void
     */
    private function __clone() {}

    /**
     * Prevent unserialization of the singleton instance.
     *
     * @return void
     * @throws \Exception Always throws to prevent unserialization.
     */
    public function __wakeup() {
        throw new \Exception( 'Cannot unserialize singleton.' ); // NOSONAR - generic exception ok.
    }

    /**
     * Check if debug logging is enabled for cron handlers.
     *
     * Use the `mainwp_abilities_cron_debug_logging` filter to disable verbose
     * logging during cron processing. Defaults to true (logging enabled).
     *
     * @return bool True if debug logging is enabled, false otherwise.
     */
    private function is_debug_logging_enabled(): bool {
        return (bool) apply_filters( 'mainwp_abilities_cron_debug_logging', true );
    }

    /**
     * Log a debug message if debug logging is enabled.
     *
     * @param string $message Message to log.
     * @return void
     */
    private function log_debug( string $message ): void {
        if ( $this->is_debug_logging_enabled() ) {
            MainWP_Logger::instance()->log_events( 'debug-abilities', $message );
        }
    }

    /**
     * Restore the job owner and confirm the account can still manage sites.
     *
     * Cron has no authenticated owner by default. Site scope and rights must
     * be checked as the user who queued the job. Deleted accounts and accounts
     * that lost manage_options must not continue driving remote calls.
     *
     * @param array $job Job data (reads user_id).
     *
     * @return bool True when the owner is valid (or the job predates owner
     *              stamping), false when a stamped owner no longer exists or cannot manage sites.
     */
    private function restore_job_owner( array $job ): bool {
        $owner_id = isset( $job['user_id'] ) ? (int) $job['user_id'] : 0;

        if ( $owner_id <= 0 ) {
            // Legacy job queued before owner stamping: nothing to revalidate
            // against, preserve prior behaviour.
            return true;
        }

        if ( ! get_userdata( $owner_id ) ) {
            return false;
        }

        if ( ! user_can( $owner_id, 'manage_options' ) ) {
            return false;
        }

        wp_set_current_user( $owner_id );
        return true;
    }

    /**
     * Preserve enqueue-time scope for legacy jobs without an owner to recheck.
     *
     * @param array $job Job data (reads user_id).
     * @return string|int[] 'all' for legacy jobs or the owner's allowed site IDs.
     */
    private function get_job_allowed_site_ids( array $job ) {
        $owner_id = isset( $job['user_id'] ) ? (int) $job['user_id'] : 0;

        return $owner_id <= 0 ? 'all' : MainWP_Abilities_Util::get_allowed_site_ids();
    }

    /**
     * Whether the restored owner may still act on a site.
     *
     * @param int          $site_id       Site ID.
     * @param string|int[] $allowed_sites 'all' or the owner's allowed site IDs.
     *
     * @return bool
     */
    private function owner_can_access_site( $site_id, $allowed_sites ): bool {
        return 'all' === $allowed_sites || in_array( (int) $site_id, $allowed_sites, true );
    }

    /**
     * Fail a whole job because its owner no longer exists or cannot manage sites.
     *
     * Remaining sites must not run after the owner loses enqueue permission.
     *
     * @param array  $job          Job data.
     * @param string $transient_key Transient key prefix.
     * @param string $job_id       Job ID.
     *
     * @return void
     */
    private function fail_job_owner_revoked( array $job, string $transient_key, string $job_id ): void {
        $job['errors']    = isset( $job['errors'] ) && is_array( $job['errors'] ) ? $job['errors'] : array();
        $job['status']    = 'failed';
        $job['completed'] = time();
        $job['errors'][]  = array(
            'site_id' => 0,
            'code'    => 'access_revoked',
            'message' => __( 'The account that queued this job no longer exists or can no longer manage sites; remaining sites were not processed.', 'mainwp' ),
        );
        set_transient( $transient_key . $job_id, $job, DAY_IN_SECONDS );
        $this->log_debug( 'Job owner no longer exists or cannot manage sites, failing job: ' . $job_id );
    }

    /**
     * Whether the restored job owner still holds every operation right the
     * job was stamped with at enqueue.
     *
     * Evaluated per chunk run, after restore_job_owner() has set the owner as
     * the current user. The global wrapper bypasses under DOING_CRON and
     * would make this a no-op, so the direct filter is required. Jobs queued
     * before rights stamping carry no key and are treated as requiring
     * nothing, so in-flight jobs survive a deploy.
     *
     * @param array $job Job data.
     *
     * @return bool True when every stamped right is still held.
     */
    private function owner_holds_required_rights( array $job ): bool {
        $rights = isset( $job['required_rights'] ) && is_array( $job['required_rights'] ) ? $job['required_rights'] : array();

        foreach ( $rights as $right ) {
            if ( ! MainWP_Abilities_Util::current_user_has_operation_right( (string) $right ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fail a job because the owner lost a required operation right mid-job.
     *
     * Every remaining (unprocessed) target is marked access_revoked without
     * any remote call, then the job terminates the same way the owner-deleted
     * terminal does: status failed, completed stamped, transient saved.
     *
     * @param array  $job           Job data.
     * @param string $transient_key Transient key prefix.
     * @param string $job_id        Job ID.
     * @param string $success_key   Job key holding successful site IDs ('synced'|'updated'|'successful').
     *
     * @return void
     */
    private function fail_job_rights_revoked( array $job, string $transient_key, string $job_id, string $success_key ): void {
        $successes     = isset( $job[ $success_key ] ) && is_array( $job[ $success_key ] ) ? $job[ $success_key ] : array();
        $job['errors'] = isset( $job['errors'] ) && is_array( $job['errors'] ) ? $job['errors'] : array();
        $sites         = isset( $job['sites'] ) && is_array( $job['sites'] ) ? $job['sites'] : array();

        $processed_ids = array_merge( $successes, array_column( $job['errors'], 'site_id' ) );
        $remaining     = array_values( array_diff( $sites, $processed_ids ) );

        foreach ( $remaining as $site_id ) {
            $job['errors'][] = array(
                'site_id' => (int) $site_id,
                'code'    => 'access_revoked',
                'message' => __( 'A right this job requires was revoked before this site was processed.', 'mainwp' ),
            );
        }

        $job['processed'] = ( isset( $job['processed'] ) ? (int) $job['processed'] : 0 ) + count( $remaining );
        $job['status']    = 'failed';
        $job['completed'] = time();
        set_transient( $transient_key . $job_id, $job, DAY_IN_SECONDS );
        $this->log_debug( 'Job owner lost a required operation right, failing job: ' . $job_id );
    }

    /**
     * Process a batch sync job.
     *
     * Called via WP-Cron when a sync job is scheduled.
     * Processes sites in chunks, updates progress, and reschedules until complete.
     *
     * @param string $job_id Job ID to process.
     * @return void
     */
    public function process_sync_job( $job_id ): void { // phpcs:ignore -- NOSONAR - complex function.
        // Environment setup for long-running process.
        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );

        // Sanitize job_id for safe transient lookup and logging.
        $job_id = sanitize_key( (string) $job_id );
        if ( empty( $job_id ) ) {
            $this->log_debug( 'Sync job ID is empty after sanitization' );
            return;
        }

        // Load job from transient.
        $job = get_transient( 'mainwp_sync_job_' . $job_id );
        if ( empty( $job ) || ! is_array( $job ) ) {
            $this->log_debug( 'Sync job not found or expired: ' . $job_id );
            return;
        }

        // Timeout protection (4 hours max).
        if ( ! empty( $job['started'] ) && time() > $job['started'] + 4 * HOUR_IN_SECONDS ) {
            $job['status']        = 'failed';
            $job['completed']     = time();
            $job['job_timed_out'] = true;
            $job['errors'][]      = array(
                'site_id' => 0,
                'code'    => 'timeout',
                'message' => __( 'Job timed out after 4 hours.', 'mainwp' ),
            );
            set_transient( 'mainwp_sync_job_' . $job_id, $job, DAY_IN_SECONDS );
            $this->log_debug( 'Sync job timed out: ' . $job_id );
            return;
        }

        // Later callbacks must retain their own user context.
        $previous_user_id = get_current_user_id();
        try {
            if ( ! $this->restore_job_owner( $job ) ) {
                $this->fail_job_owner_revoked( $job, 'mainwp_sync_job_', $job_id );
                return;
            }
            if ( ! $this->owner_holds_required_rights( $job ) ) {
                $this->fail_job_rights_revoked( $job, 'mainwp_sync_job_', $job_id, 'synced' );
                return;
            }
            $allowed_sites = $this->get_job_allowed_site_ids( $job );

            // Status transition: queued -> processing.
            if ( 'queued' === $job['status'] ) {
                $job['status']  = 'processing';
                $job['started'] = time();
            }

            // Chunk processing (default 20 sites per run).
            // Cast to int and clamp to minimum of 1 to prevent infinite loop from misconfigured filter.
            $chunk_size = (int) apply_filters( 'mainwp_abilities_cron_chunk_size', 20 );
            if ( $chunk_size < 1 ) {
                $chunk_size = 1;
            }

            // Normalize result arrays defensively (transient data could be corrupted).
            $job['synced'] = isset( $job['synced'] ) && is_array( $job['synced'] ) ? $job['synced'] : array();
            $job['errors'] = isset( $job['errors'] ) && is_array( $job['errors'] ) ? $job['errors'] : array();

            // Calculate already processed site IDs.
            $processed_ids = array_merge(
                $job['synced'],
                array_column( $job['errors'], 'site_id' )
            );

            // Get remaining sites to process.
            $remaining = array_values( array_diff( $job['sites'], $processed_ids ) );
            $chunk     = array_slice( $remaining, 0, $chunk_size );

            $this->log_debug(
                sprintf(
                    'Processing sync job %s: %d sites in chunk, %d remaining',
                    $job_id,
                    count( $chunk ),
                    count( $remaining )
                )
            );

            // Process each site in the chunk.
            foreach ( $chunk as $site_id ) {
                try {
                    if ( ! $this->owner_can_access_site( $site_id, $allowed_sites ) ) {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => 'access_revoked',
                            'message' => __( 'Access to this site was revoked before it was processed.', 'mainwp' ),
                        );
                        ++$job['processed'];
                        continue;
                    }

                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );

                    if ( empty( $website ) ) {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => 'site_not_found',
                            'message' => __( 'Site not found.', 'mainwp' ),
                        );
                        ++$job['processed'];
                        continue;
                    }

                    // Call sync_site: false for force fetch, true for allow disconnect, false for clear session.
                    $result = MainWP_Sync::sync_site( $website, false, true, false );

                    if ( true === $result ) {
                        $job['synced'][] = $site_id;
                    } else {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => 'sync_failed',
                            'message' => __( 'Sync returned false.', 'mainwp' ),
                        );
                    }
                } catch ( \Exception $e ) {
                    $job['errors'][] = array(
                        'site_id' => $site_id,
                        'code'    => 'exception',
                        'message' => $e->getMessage(),
                    );
                }

                ++$job['processed'];
                $job['progress'] = $job['total'] > 0
                    ? round( ( $job['processed'] / $job['total'] ) * 100 )
                    : 0;
            }

            // Save progress.
            set_transient( 'mainwp_sync_job_' . $job_id, $job, DAY_IN_SECONDS );

            // Recalculate remaining after processing.
            $processed_ids = array_merge(
                $job['synced'],
                array_column( $job['errors'], 'site_id' )
            );
            $remaining     = array_values( array_diff( $job['sites'], $processed_ids ) );

            // Reschedule or complete.
            if ( count( $remaining ) > 0 ) {
                // Schedule next chunk (30 second delay).
                if ( ! wp_next_scheduled( 'mainwp_process_sync_job', array( $job_id ) ) ) {
                    wp_schedule_single_event( time() + 30, 'mainwp_process_sync_job', array( $job_id ) );
                }
                $this->log_debug(
                    sprintf( 'Sync job %s rescheduled: %d sites remaining', $job_id, count( $remaining ) )
                );
            } else {
                // Job complete.
                $job['status']    = 'completed';
                $job['completed'] = time();
                $job['progress']  = 100;
                set_transient( 'mainwp_sync_job_' . $job_id, $job, DAY_IN_SECONDS );
                $this->log_debug(
                    sprintf(
                        'Sync job %s completed: %d synced, %d errors',
                        $job_id,
                        count( $job['synced'] ),
                        count( $job['errors'] )
                    )
                );
            }
        } finally {
            wp_set_current_user( $previous_user_id );
        }
    }

    /**
     * Process a batch update job.
     *
     * Called via WP-Cron when an update job is scheduled.
     * Processes sites in chunks, applying updates by type, and reschedules until complete.
     *
     * @param string $job_id Job ID to process.
     * @return void
     */
    public function process_update_job( $job_id ): void { // phpcs:ignore -- NOSONAR - complex function.
        // Environment setup for long-running process.
        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );

        // Sanitize job_id for safe transient lookup and logging.
        $job_id = sanitize_key( (string) $job_id );
        if ( empty( $job_id ) ) {
            $this->log_debug( 'Update job ID is empty after sanitization' );
            return;
        }

        // Load job from transient.
        $job = get_transient( 'mainwp_update_job_' . $job_id );
        if ( empty( $job ) || ! is_array( $job ) ) {
            $this->log_debug( 'Update job not found or expired: ' . $job_id );
            return;
        }

        // Timeout protection (4 hours max).
        if ( ! empty( $job['started'] ) && time() > $job['started'] + 4 * HOUR_IN_SECONDS ) {
            $job['status']        = 'failed';
            $job['completed']     = time();
            $job['job_timed_out'] = true;
            $job['errors'][]      = array(
                'site_id' => 0,
                'code'    => 'timeout',
                'message' => __( 'Job timed out after 4 hours.', 'mainwp' ),
            );
            set_transient( 'mainwp_update_job_' . $job_id, $job, DAY_IN_SECONDS );
            $this->log_debug( 'Update job timed out: ' . $job_id );
            return;
        }

        // Later callbacks must retain their own user context.
        $previous_user_id = get_current_user_id();
        try {
            if ( ! $this->restore_job_owner( $job ) ) {
                $this->fail_job_owner_revoked( $job, 'mainwp_update_job_', $job_id );
                return;
            }
            if ( ! $this->owner_holds_required_rights( $job ) ) {
                $this->fail_job_rights_revoked( $job, 'mainwp_update_job_', $job_id, 'updated' );
                return;
            }
            $allowed_sites = $this->get_job_allowed_site_ids( $job );

            // Status transition: queued -> processing.
            if ( 'queued' === $job['status'] ) {
                $job['status']  = 'processing';
                $job['started'] = time();
            }

            // Chunk processing (default 20 sites per run).
            // Cast to int and clamp to minimum of 1 to prevent infinite loop from misconfigured filter.
            $chunk_size = (int) apply_filters( 'mainwp_abilities_cron_chunk_size', 20 );
            if ( $chunk_size < 1 ) {
                $chunk_size = 1;
            }

            // Normalize result arrays defensively (transient data could be corrupted).
            $job['updated'] = isset( $job['updated'] ) && is_array( $job['updated'] ) ? $job['updated'] : array();
            $job['errors']  = isset( $job['errors'] ) && is_array( $job['errors'] ) ? $job['errors'] : array();

            // Calculate already processed site IDs.
            $processed_ids = array_merge(
                $job['updated'],
                array_column( $job['errors'], 'site_id' )
            );

            // Get remaining sites to process.
            $remaining = array_values( array_diff( $job['sites'], $processed_ids ) );
            $chunk     = array_slice( $remaining, 0, $chunk_size );

            $this->log_debug(
                sprintf(
                    'Processing update job %s: %d sites in chunk, %d remaining, types: %s',
                    $job_id,
                    count( $chunk ),
                    count( $remaining ),
                    implode( ',', $job['types'] )
                )
            );

            // Process each site in the chunk.
            foreach ( $chunk as $site_id ) {
                try {
                    if ( ! $this->owner_can_access_site( $site_id, $allowed_sites ) ) {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => 'access_revoked',
                            'message' => __( 'Access to this site was revoked before it was processed.', 'mainwp' ),
                        );
                        ++$job['processed'];
                        continue;
                    }

                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );

                    if ( empty( $website ) ) {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => 'site_not_found',
                            'message' => __( 'Site not found.', 'mainwp' ),
                        );
                        ++$job['processed'];
                        continue;
                    }

                    $site_success = true;
                    $site_errors  = array();

                    // Normalize specific_items to avoid TypeError if malformed in transient data.
                    $specific_items = isset( $job['specific_items'] ) && is_array( $job['specific_items'] )
                        ? $job['specific_items']
                        : array();

                    // Process each update type.
                    foreach ( $job['types'] as $type ) {
                        try {
                            switch ( $type ) {
                                case 'core':
                                    /**
                                     * Fires before WordPress core update action.
                                     *
                                     * @since 5.4
                                     *
                                     * @param object $website Site object.
                                     */
                                    do_action( 'mainwp_before_core_update', $website );

                                    $information = MainWP_Connect::fetch_url_authed( $website, 'upgrade' );

                                    // Validate response - check for transport failure or API error.
                                    if ( false === $information ) {
                                        $site_success  = false;
                                        $site_errors[] = 'core: ' . __( 'Connection failed - no response from child site.', 'mainwp' );
                                        $this->log_debug(
                                            sprintf(
                                                'Core upgrade failed for site %d: transport failure (false response)',
                                                $website->id
                                            )
                                        );

                                        /**
                                         * Fires after WordPress core update action.
                                         *
                                         * @since 5.4
                                         *
                                         * @param array|false $information Response from child site (false on failure).
                                         * @param object      $website     Site object.
                                         */
                                        do_action( 'mainwp_after_core_update', $information, $website );
                                    } elseif ( is_array( $information ) && isset( $information['error'] ) ) {
                                        $site_success  = false;
                                        $site_errors[] = 'core: ' . $information['error'];
                                        $this->log_debug(
                                            sprintf(
                                                'Core upgrade failed for site %d: %s',
                                                $website->id,
                                                $information['error']
                                            )
                                        );

                                        /** This action is documented above in this switch case. */
                                        do_action( 'mainwp_after_core_update', $information, $website );
                                    } elseif ( ! is_array( $information ) ) {
                                        $site_success  = false;
                                        $site_errors[] = 'core: ' . __( 'Invalid response from child site.', 'mainwp' );
                                        $this->log_debug(
                                            sprintf(
                                                'Core upgrade failed for site %d: invalid response type (%s), raw: %s',
                                                $website->id,
                                                gettype( $information ),
                                                is_scalar( $information ) ? $information : wp_json_encode( $information )
                                            )
                                        );

                                        /** This action is documented above in this switch case. */
                                        do_action( 'mainwp_after_core_update', $information, $website );
                                    } else {
                                        // Success - fire after-action and sync.
                                        /** This action is documented above in this switch case. */
                                        do_action( 'mainwp_after_core_update', $information, $website );

                                        // Sync site data immediately if child returned sync info.
                                        if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                                            MainWP_Sync::sync_information_array( $website, $information['sync'] );
                                        }
                                    }
                                    break;

                                case 'plugins':
                                    $slugs = $this->get_update_slugs_for_site( $website, 'plugin', $specific_items );
                                    if ( ! empty( $slugs ) ) {
                                        $slugs_list = implode( ',', $slugs );

                                        /**
                                         * Fires before plugin/theme/translation update actions.
                                         *
                                         * @since 4.1
                                         *
                                         * @param string $type    Update type: 'plugin', 'theme', or 'translation'.
                                         * @param string $list    Comma-separated list of slugs being updated.
                                         * @param object $website Site object.
                                         */
                                        do_action( 'mainwp_before_plugin_theme_translation_update', 'plugin', $slugs_list, $website );

                                        $information = MainWP_Connect::fetch_url_authed(
                                            $website,
                                            'upgradeplugintheme',
                                            array(
                                                'type' => 'plugin',
                                                'list' => urldecode( $slugs_list ),
                                            )
                                        );

                                        // Validate response - check for transport failure or API error.
                                        if ( false === $information ) {
                                            $site_success  = false;
                                            $site_errors[] = 'plugins: ' . __( 'Connection failed - no response from child site.', 'mainwp' );
                                            $this->log_debug(
                                                sprintf(
                                                    'Plugin upgrade failed for site %d (slugs: %s): transport failure (false response)',
                                                    $website->id,
                                                    $slugs_list
                                                )
                                            );

                                            // Fire after-action even on failure so hooks always run.
                                            /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', null, 'plugin', $slugs_list, $website );
                                        } elseif ( is_array( $information ) && isset( $information['error'] ) ) {
                                            $site_success  = false;
                                            $site_errors[] = 'plugins: ' . $information['error'];
                                            $this->log_debug(
                                                sprintf(
                                                    'Plugin upgrade failed for site %d (slugs: %s): %s',
                                                    $website->id,
                                                    $slugs_list,
                                                    $information['error']
                                                )
                                            );

                                            // Fire after-action even on failure so hooks always run.
                                            /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'plugin', $slugs_list, $website );
                                        } elseif ( ! is_array( $information ) ) {
                                            $site_success  = false;
                                            $site_errors[] = 'plugins: ' . __( 'Invalid response from child site.', 'mainwp' );
                                            $this->log_debug(
                                                sprintf(
                                                    'Plugin upgrade failed for site %d (slugs: %s): invalid response type (%s), raw: %s',
                                                    $website->id,
                                                    $slugs_list,
                                                    gettype( $information ),
                                                    is_scalar( $information ) ? $information : wp_json_encode( $information )
                                                )
                                            );

                                            // Fire after-action even on failure so hooks always run.
                                            /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', null, 'plugin', $slugs_list, $website );
                                        } elseif ( isset( $information['upgrades_error'] ) && ! empty( $information['upgrades_error'] ) ) {
                                            // Partial failure - some plugins failed to update.
                                            $error_msgs = array();
                                            foreach ( $information['upgrades_error'] as $slug => $error_msg ) {
                                                $error_msgs[] = $slug . ': ' . $error_msg;
                                            }
                                            $site_success  = false;
                                            $site_errors[] = 'plugins: ' . implode( '; ', $error_msgs );
                                            $this->log_debug(
                                                sprintf(
                                                    'Plugin upgrade partial failure for site %d (slugs: %s): %s',
                                                    $website->id,
                                                    $slugs_list,
                                                    implode( '; ', $error_msgs )
                                                )
                                            );

                                            // Still fire after-action and sync for partial success.
                                            /**
                                             * Fires after plugin/theme/translation update actions.
                                             *
                                             * @since 4.1
                                             *
                                             * @param array  $information Response from child site.
                                             * @param string $type        Update type: 'plugin', 'theme', or 'translation'.
                                             * @param string $list        Comma-separated list of slugs that were updated.
                                             * @param object $website     Site object.
                                             */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'plugin', $slugs_list, $website );

                                            // Sync site data immediately if child returned sync info.
                                            if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                                                MainWP_Sync::sync_information_array( $website, $information['sync'] );
                                            }
                                        } else {
                                            // Success - fire after-action and sync.
                                            /** This action is documented above in this switch case. */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'plugin', $slugs_list, $website );

                                            // Sync site data immediately if child returned sync info.
                                            if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                                                MainWP_Sync::sync_information_array( $website, $information['sync'] );
                                            }
                                        }
                                    }
                                    break;

                                case 'themes':
                                    $slugs = $this->get_update_slugs_for_site( $website, 'theme', $specific_items );
                                    if ( ! empty( $slugs ) ) {
                                        $slugs_list = implode( ',', $slugs );

                                        /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                        do_action( 'mainwp_before_plugin_theme_translation_update', 'theme', $slugs_list, $website );

                                        $information = MainWP_Connect::fetch_url_authed(
                                            $website,
                                            'upgradeplugintheme',
                                            array(
                                                'type' => 'theme',
                                                'list' => urldecode( $slugs_list ),
                                            )
                                        );

                                        // Validate response - check for transport failure or API error.
                                        if ( false === $information ) {
                                            $site_success  = false;
                                            $site_errors[] = 'themes: ' . __( 'Connection failed - no response from child site.', 'mainwp' );
                                            $this->log_debug(
                                                sprintf(
                                                    'Theme upgrade failed for site %d (slugs: %s): transport failure (false response)',
                                                    $website->id,
                                                    $slugs_list
                                                )
                                            );

                                            // Fire after-action even on failure so hooks always run.
                                            /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', null, 'theme', $slugs_list, $website );
                                        } elseif ( is_array( $information ) && isset( $information['error'] ) ) {
                                            $site_success  = false;
                                            $site_errors[] = 'themes: ' . $information['error'];
                                            $this->log_debug(
                                                sprintf(
                                                    'Theme upgrade failed for site %d (slugs: %s): %s',
                                                    $website->id,
                                                    $slugs_list,
                                                    $information['error']
                                                )
                                            );

                                            // Fire after-action even on failure so hooks always run.
                                            /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'theme', $slugs_list, $website );
                                        } elseif ( ! is_array( $information ) ) {
                                            $site_success  = false;
                                            $site_errors[] = 'themes: ' . __( 'Invalid response from child site.', 'mainwp' );
                                            $this->log_debug(
                                                sprintf(
                                                    'Theme upgrade failed for site %d (slugs: %s): invalid response type (%s), raw: %s',
                                                    $website->id,
                                                    $slugs_list,
                                                    gettype( $information ),
                                                    is_scalar( $information ) ? $information : wp_json_encode( $information )
                                                )
                                            );

                                            // Fire after-action even on failure so hooks always run.
                                            /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', null, 'theme', $slugs_list, $website );
                                        } elseif ( isset( $information['upgrades_error'] ) && ! empty( $information['upgrades_error'] ) ) {
                                            // Partial failure - some themes failed to update.
                                            $error_msgs = array();
                                            foreach ( $information['upgrades_error'] as $slug => $error_msg ) {
                                                $error_msgs[] = $slug . ': ' . $error_msg;
                                            }
                                            $site_success  = false;
                                            $site_errors[] = 'themes: ' . implode( '; ', $error_msgs );
                                            $this->log_debug(
                                                sprintf(
                                                    'Theme upgrade partial failure for site %d (slugs: %s): %s',
                                                    $website->id,
                                                    $slugs_list,
                                                    implode( '; ', $error_msgs )
                                                )
                                            );

                                            // Still fire after-action and sync for partial success.
                                            /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'theme', $slugs_list, $website );

                                            // Sync site data immediately if child returned sync info.
                                            if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                                                MainWP_Sync::sync_information_array( $website, $information['sync'] );
                                            }
                                        } else {
                                            // Success - fire after-action and sync.
                                            /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'theme', $slugs_list, $website );

                                            // Sync site data immediately if child returned sync info.
                                            if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                                                MainWP_Sync::sync_information_array( $website, $information['sync'] );
                                            }
                                        }
                                    }
                                    break;

                                case 'translations':
                                    /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                    do_action( 'mainwp_before_plugin_theme_translation_update', 'translation', '', $website );

                                    $information = MainWP_Connect::fetch_url_authed( $website, 'upgradetranslation' );

                                    // Validate response - check for transport failure or API error.
                                    if ( false === $information ) {
                                        $site_success  = false;
                                        $site_errors[] = 'translations: ' . __( 'Connection failed - no response from child site.', 'mainwp' );
                                        $this->log_debug(
                                            sprintf(
                                                'Translation upgrade failed for site %d: transport failure (false response)',
                                                $website->id
                                            )
                                        );

                                        // Fire after-action even on failure so hooks always run.
                                        /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                        do_action( 'mainwp_after_plugin_theme_translation_update', null, 'translation', '', $website );
                                    } elseif ( is_array( $information ) && isset( $information['error'] ) ) {
                                        $site_success  = false;
                                        $site_errors[] = 'translations: ' . $information['error'];
                                        $this->log_debug(
                                            sprintf(
                                                'Translation upgrade failed for site %d: %s',
                                                $website->id,
                                                $information['error']
                                            )
                                        );

                                        // Fire after-action even on failure so hooks always run.
                                        /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                        do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'translation', '', $website );
                                    } elseif ( ! is_array( $information ) ) {
                                        $site_success  = false;
                                        $site_errors[] = 'translations: ' . __( 'Invalid response from child site.', 'mainwp' );
                                        $this->log_debug(
                                            sprintf(
                                                'Translation upgrade failed for site %d: invalid response type (%s)',
                                                $website->id,
                                                gettype( $information )
                                            )
                                        );

                                        // Fire after-action even on failure so hooks always run.
                                        /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                        do_action( 'mainwp_after_plugin_theme_translation_update', null, 'translation', '', $website );
                                    } elseif ( isset( $information['upgrades_error'] ) && ! empty( $information['upgrades_error'] ) ) {
                                        // Partial failure - some translations failed to update.
                                        $error_msgs = array();
                                        foreach ( $information['upgrades_error'] as $slug => $error_msg ) {
                                            $error_msgs[] = $slug . ': ' . $error_msg;
                                        }
                                        $site_success  = false;
                                        $site_errors[] = 'translations: ' . implode( '; ', $error_msgs );
                                        $this->log_debug(
                                            sprintf(
                                                'Translation upgrade partial failure for site %d: %s',
                                                $website->id,
                                                implode( '; ', $error_msgs )
                                            )
                                        );

                                        // Still fire after-action and sync for partial success.
                                        /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                        do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'translation', '', $website );

                                        // Sync site data immediately if child returned sync info.
                                        if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                                            MainWP_Sync::sync_information_array( $website, $information['sync'] );
                                        }
                                    } else {
                                        // Success - fire after-action and sync.
                                        /** This action is documented in includes/abilities/class-mainwp-abilities-cron.php */
                                        do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'translation', '', $website );

                                        // Sync site data immediately if child returned sync info.
                                        if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                                            MainWP_Sync::sync_information_array( $website, $information['sync'] );
                                        }
                                    }
                                    break;
                                default:
                                    // do nothing for unknown type.
                                    break;
                            }
                        } catch ( \Exception $e ) {
                            $site_success  = false;
                            $site_errors[] = $type . ': ' . $e->getMessage();
                        }
                    }

                    if ( $site_success && empty( $site_errors ) ) {
                        $job['updated'][] = $site_id;
                    } else {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => 'update_failed',
                            'message' => implode( '; ', $site_errors ),
                        );
                    }
                } catch ( \Exception $e ) {
                    $job['errors'][] = array(
                        'site_id' => $site_id,
                        'code'    => 'exception',
                        'message' => $e->getMessage(),
                    );
                }

                ++$job['processed'];
                $job['progress'] = $job['total'] > 0
                    ? round( ( $job['processed'] / $job['total'] ) * 100 )
                    : 0;
            }

            // Save progress.
            set_transient( 'mainwp_update_job_' . $job_id, $job, DAY_IN_SECONDS );

            // Recalculate remaining after processing.
            $processed_ids = array_merge(
                $job['updated'],
                array_column( $job['errors'], 'site_id' )
            );
            $remaining     = array_values( array_diff( $job['sites'], $processed_ids ) );

            // Reschedule or complete.
            if ( count( $remaining ) > 0 ) {
                // Schedule next chunk (30 second delay).
                if ( ! wp_next_scheduled( 'mainwp_process_update_job', array( $job_id ) ) ) {
                    wp_schedule_single_event( time() + 30, 'mainwp_process_update_job', array( $job_id ) );
                }
                $this->log_debug(
                    sprintf( 'Update job %s rescheduled: %d sites remaining', $job_id, count( $remaining ) )
                );
            } else {
                // Job complete.
                $job['status']    = 'completed';
                $job['completed'] = time();
                $job['progress']  = 100;
                set_transient( 'mainwp_update_job_' . $job_id, $job, DAY_IN_SECONDS );
                $this->log_debug(
                    sprintf(
                        'Update job %s completed: %d updated, %d errors',
                        $job_id,
                        count( $job['updated'] ),
                        count( $job['errors'] )
                    )
                );
            }
        } finally {
            wp_set_current_user( $previous_user_id );
        }
    }

    /**
     * Get update slugs for a site, optionally filtered by specific items.
     *
     * @param object $website        Site object.
     * @param string $type           Type: 'plugin' or 'theme'.
     * @param array  $specific_items Optional array of specific slugs to filter.
     * @return array Array of slugs to update.
     */
    private function get_update_slugs_for_site( $website, string $type, array $specific_items = array() ): array {
        $slugs = array();

        if ( 'plugin' === $type ) {
            $updates = json_decode( $website->plugin_upgrades, true );
        } else {
            $updates = json_decode( $website->theme_upgrades, true );
        }

        if ( ! is_array( $updates ) ) {
            $updates = array();
        }

        foreach ( array_keys( $updates ) as $slug ) {
            // If specific items provided, filter to only those.
            if ( ! empty( $specific_items ) && ! in_array( $slug, $specific_items, true ) ) {
                continue;
            }
            $slugs[] = $slug;
        }

        return $slugs;
    }

    /**
     * Process a batch operation job (reconnect, disconnect, check, suspend).
     *
     * Called via WP-Cron when a batch operation is scheduled.
     * Processes sites in chunks and reschedules until complete.
     *
     * @param string $job_id Job ID to process.
     * @return void
     */
    public function process_batch_job( $job_id ): void { // phpcs:ignore -- NOSONAR - complex method.
        // Environment setup for long-running process.
        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );

        // Sanitize job_id for safe transient lookup and logging.
        $job_id = sanitize_key( (string) $job_id );
        if ( empty( $job_id ) ) {
            $this->log_debug( 'Batch job ID is empty after sanitization' );
            return;
        }

        // Load job from transient.
        $job = get_transient( 'mainwp_batch_job_' . $job_id );
        if ( empty( $job ) || ! is_array( $job ) ) {
            $this->log_debug( 'Batch job not found or expired: ' . $job_id );
            return;
        }

        // Timeout protection (4 hours max).
        if ( ! empty( $job['started'] ) && time() > $job['started'] + 4 * HOUR_IN_SECONDS ) {
            $job['status']        = 'failed';
            $job['completed']     = time();
            $job['job_timed_out'] = true;
            $job['errors'][]      = array(
                'site_id' => 0,
                'code'    => 'timeout',
                'message' => __( 'Job timed out after 4 hours.', 'mainwp' ),
            );
            set_transient( 'mainwp_batch_job_' . $job_id, $job, DAY_IN_SECONDS );
            $this->log_debug( 'Batch job timed out: ' . $job_id );
            return;
        }

        // Later callbacks must retain their own user context.
        $previous_user_id = get_current_user_id();
        try {
            if ( ! $this->restore_job_owner( $job ) ) {
                $this->fail_job_owner_revoked( $job, 'mainwp_batch_job_', $job_id );
                return;
            }
            if ( ! $this->owner_holds_required_rights( $job ) ) {
                $this->fail_job_rights_revoked( $job, 'mainwp_batch_job_', $job_id, 'successful' );
                return;
            }
            $allowed_sites = $this->get_job_allowed_site_ids( $job );

            // Status transition: queued -> processing.
            if ( 'queued' === $job['status'] ) {
                $job['status']  = 'processing';
                $job['started'] = time();
            }

            // Chunk processing (default 20 sites per run).
            // Cast to int and clamp to minimum of 1 to prevent infinite loop from misconfigured filter.
            $chunk_size = (int) apply_filters( 'mainwp_abilities_cron_chunk_size', 20 );
            if ( $chunk_size < 1 ) {
                $chunk_size = 1;
            }

            // Normalize result arrays defensively (transient data could be corrupted).
            $job['successful'] = isset( $job['successful'] ) && is_array( $job['successful'] ) ? $job['successful'] : array();
            $job['errors']     = isset( $job['errors'] ) && is_array( $job['errors'] ) ? $job['errors'] : array();

            // Calculate already processed site IDs.
            $processed_ids = array_merge(
                $job['successful'],
                array_column( $job['errors'], 'site_id' )
            );

            // Get remaining sites to process.
            $remaining = array_values( array_diff( $job['sites'], $processed_ids ) );
            $chunk     = array_slice( $remaining, 0, $chunk_size );

            $this->log_debug(
                sprintf(
                    'Processing batch job %s (%s): %d sites in chunk, %d remaining',
                    $job_id,
                    $job['job_type'],
                    count( $chunk ),
                    count( $remaining )
                )
            );

            // Process each site in the chunk.
            foreach ( $chunk as $site_id ) {
                try {
                    if ( ! $this->owner_can_access_site( $site_id, $allowed_sites ) ) {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => 'access_revoked',
                            'message' => __( 'Access to this site was revoked before it was processed.', 'mainwp' ),
                        );
                        ++$job['processed'];
                        continue;
                    }

                    $website = MainWP_DB::instance()->get_website_by_id( $site_id );

                    if ( empty( $website ) ) {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => 'site_not_found',
                            'message' => __( 'Site not found.', 'mainwp' ),
                        );
                        ++$job['processed'];
                        continue;
                    }

                    $success = false;
                    $error   = '';

                    // Execute operation based on job type.
                    switch ( $job['job_type'] ) {
                        case 'reconnect':
                            try {
                                $result  = MainWP_Manage_Sites_View::m_reconnect_site( $website );
                                $success = (bool) $result;
                                if ( ! $success ) {
                                    $error = __( 'Reconnect returned false.', 'mainwp' );
                                }
                            } catch ( \Exception $e ) {
                                $error = $e->getMessage();
                            }
                            break;

                        case 'disconnect':
                            $result = MainWP_DB::instance()->update_website_sync_values(
                                $website->id,
                                array( 'sync_errors' => __( 'Manually disconnected', 'mainwp' ) )
                            );
                            // wpdb->update() returns int|false: rows affected or false on error.
                            $success = ( false !== $result );
                            if ( ! $success ) {
                                $error = __( 'Database update failed during disconnect.', 'mainwp' );
                                $this->log_debug(
                                    sprintf( 'Disconnect failed for site %d: database update returned false', $website->id )
                                );
                            }
                            break;

                        case 'check':
                            $result = MainWP_Monitoring_Handler::handle_check_website( $website->id );
                            if ( is_wp_error( $result ) ) {
                                $error = $result->get_error_message();
                            } else {
                                $success = true;
                            }
                            break;

                        case 'suspend':
                            $result = MainWP_DB::instance()->update_website_values(
                                $website->id,
                                array( 'suspended' => 1 )
                            );
                            // wpdb->update() returns int|false: rows affected or false on error.
                            $success = ( false !== $result );
                            if ( $success ) {
                                /**
                                 * Fires when a site is suspended.
                                 *
                                 * @param object $website Site object.
                                 * @param int    $status  Suspension status (1 = suspended).
                                 */
                                do_action( 'mainwp_site_suspended', $website, 1 );
                            } else {
                                $error = __( 'Database update failed during suspend.', 'mainwp' );
                                $this->log_debug(
                                    sprintf( 'Suspend failed for site %d: database update returned false', $website->id )
                                );
                            }
                            break;

                        default:
                            $error = __( 'Unknown operation type.', 'mainwp' );
                    }

                    if ( $success ) {
                        $job['successful'][] = $site_id;
                    } else {
                        $job['errors'][] = array(
                            'site_id' => $site_id,
                            'code'    => $job['job_type'] . '_failed',
                            'message' => $error,
                        );
                    }
                } catch ( \Exception $e ) {
                    $job['errors'][] = array(
                        'site_id' => $site_id,
                        'code'    => 'exception',
                        'message' => $e->getMessage(),
                    );
                }

                ++$job['processed'];
                $job['progress'] = $job['total'] > 0
                    ? round( ( $job['processed'] / $job['total'] ) * 100 )
                    : 0;
            }

            // Save progress.
            set_transient( 'mainwp_batch_job_' . $job_id, $job, DAY_IN_SECONDS );

            // Recalculate remaining after processing.
            $processed_ids = array_merge(
                $job['successful'],
                array_column( $job['errors'], 'site_id' )
            );
            $remaining     = array_values( array_diff( $job['sites'], $processed_ids ) );

            // Reschedule or complete.
            if ( count( $remaining ) > 0 ) {
                // Schedule next chunk (30 second delay).
                if ( ! wp_next_scheduled( 'mainwp_process_batch_job', array( $job_id ) ) ) {
                    wp_schedule_single_event( time() + 30, 'mainwp_process_batch_job', array( $job_id ) );
                }
                $this->log_debug(
                    sprintf( 'Batch job %s rescheduled: %d sites remaining', $job_id, count( $remaining ) )
                );
            } else {
                // Job complete.
                $job['status']    = 'completed';
                $job['completed'] = time();
                $job['progress']  = 100;
                set_transient( 'mainwp_batch_job_' . $job_id, $job, DAY_IN_SECONDS );
                $this->log_debug(
                    sprintf(
                        'Batch job %s (%s) completed: %d successful, %d errors',
                        $job_id,
                        $job['job_type'],
                        count( $job['successful'] ),
                        count( $job['errors'] )
                    )
                );
            }
        } finally {
            wp_set_current_user( $previous_user_id );
        }
    }
}
