<?php
/**
 * This class handles the MainWP Post Backups.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Post_Backup_Handler
 *
 * Handle Backup Post.
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_Post_Base_Handler
 */
class MainWP_Post_Backup_Handler extends MainWP_Post_Base_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Instance class.
     *
     * @var $instance Singleton MainWP_Post_Backup_Handler.
     */
    private static $instance = null;

    /**
     * Create public static instance.
     *
     * @static
     * @return self $instance MainWP_Post_Backup_Handler.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Foreground slice budget, in seconds, for a restore progress poll.
     *
     * Each open-tab poll drives one short restore slice so the run keeps
     * moving without waiting for cron. It sits well under the cron slice
     * budget so the AJAX request returns promptly rather than pinning a
     * PHP-FPM worker; the cron backstop carries a closed tab. Tunable per
     * dashboard via the mainwp_restore_foreground_budget filter.
     *
     * @var int
     */
    const RESTORE_FOREGROUND_BUDGET = 15;

    /**
     * Init backups actions
     */
    public function init() {
        // Page: ManageBackups.
        if ( \mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) {
            $this->add_action( 'mainwp_addbackup', array( &$this, 'mainwp_addbackup' ) );
        }
        if ( \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            $this->add_action( 'mainwp_updatebackup', array( &$this, 'mainwp_updatebackup' ) );
        }
        if ( \mainwp_current_user_can( 'dashboard', 'delete_backup_tasks' ) ) {
            $this->add_action( 'mainwp_removebackup', array( &$this, 'mainwp_removebackup' ) );
        }
        if ( \mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
            $this->add_action( 'mainwp_pausebackup', array( &$this, 'mainwp_pausebackup' ) );
            $this->add_action( 'mainwp_resumebackup', array( &$this, 'mainwp_resumebackup' ) );
            $this->add_action( 'mainwp_native_backup_task_reset', array( &$this, 'mainwp_native_backup_task_reset' ) );
            $this->add_action( 'mainwp_native_backup_task_cancel_run', array( &$this, 'mainwp_native_backup_task_cancel_run' ) );
        }
        if ( \mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
            $this->add_action( 'mainwp_backuptask_run_now', array( &$this, 'mainwp_backuptask_run_now' ) );
            $this->add_action( 'mainwp_backuptask_run_status', array( &$this, 'mainwp_backuptask_run_status' ) );
        }
        // Page: backup.
        if ( \mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
            $this->add_action( 'mainwp_backup_run_site', array( &$this, 'mainwp_backup_run_site' ) );
        }
        if ( \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            $this->add_action( 'mainwp_backup', array( &$this, 'ajax_mainwp_backup' ) );
            $this->add_action( 'mainwp_backup_checkpid', array( &$this, 'mainwp_backup_checkpid' ) );
            $this->add_action( 'mainwp_createbackup_getfilesize', array( &$this, 'mainwp_createbackup_getfilesize' ) );
            $this->add_action( 'mainwp_backup_download_file', array( &$this, 'ajax_mainwp_backup_download_file' ) );
            $this->add_action( 'mainwp_backup_delete_file', array( &$this, 'mainwp_backup_delete_file' ) );
            $this->add_action( 'mainwp_backup_getfilesize', array( &$this, 'mainwp_backup_getfilesize' ) );
            $this->add_action( 'mainwp_backup_refresh_archives', array( &$this, 'mainwp_backup_refresh_archives' ) );
        }
        // Page: ManageBackupsSnapshots (restore).
        if ( \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            $this->add_action( 'mainwp_restore_snapshot_list', array( &$this, 'mainwp_restore_snapshot_list' ) );
            $this->add_action( 'mainwp_backup_snapshot_delete_preview', array( &$this, 'mainwp_backup_snapshot_delete_preview' ) );
            $this->add_action( 'mainwp_backup_snapshot_delete', array( &$this, 'mainwp_backup_snapshot_delete' ) );
            $this->add_action( 'mainwp_backup_snapshot_download_url', array( &$this, 'mainwp_backup_snapshot_download_url' ) );
            $this->add_action( 'mainwp_restore_progress', array( &$this, 'mainwp_restore_progress' ) );
            if ( \mainwp_current_user_can( 'dashboard', 'restore_backups' ) ) {
                $this->add_action( 'mainwp_restore_initiate', array( &$this, 'mainwp_restore_initiate' ) );
                $this->add_action( 'mainwp_restore_cancel', array( &$this, 'mainwp_restore_cancel' ) );
                $this->add_action( 'mainwp_restore_rollback', array( &$this, 'mainwp_restore_rollback' ) );
            }
            $this->add_action( 'mainwp_native_backup_initiate', array( &$this, 'mainwp_native_backup_initiate' ) );
            $this->add_action( 'mainwp_native_backup_progress', array( &$this, 'mainwp_native_backup_progress' ) );
            $this->add_action( 'mainwp_native_backup_cancel', array( &$this, 'mainwp_native_backup_cancel' ) );
            $this->add_action( 'mainwp_snapshots_cleanup', array( &$this, 'mainwp_snapshots_cleanup' ) );
            $this->add_action( 'mainwp_backup_diagnostics_export', array( &$this, 'mainwp_backup_diagnostics_export' ) );
        }
        add_action( 'admin_post_mainwp_backup_diagnostics_download', array( MainWP_Backup_Diagnostics::instance(), 'download_bundle' ) );
        add_action( 'admin_post_mainwp_backup_snapshot_download', array( MainWP_Backup_Download::class, 'download_snapshot' ) );
        add_action( 'admin_post_nopriv_mainwp_backup_snapshot_download', array( MainWP_Backup_Download::class, 'download_snapshot' ) );
        // Intentionally not capability-gated: the backup-before-update check is
        // read-only and used by the updates flow, where a user without backup
        // rights still needs it.
        $this->add_action( 'mainwp_checkbackups', array( &$this, 'mainwp_checkbackups' ) );

        // Remove the per-site hidden on-demand backup task when its site is
        // deleted from the dashboard, so no orphan wp_backup row is left
        // behind. Registered unconditionally (this is a plain action, not an
        // AJAX endpoint) so it fires on the manage-sites deletion path.
        add_action( 'mainwp_delete_site', array( $this, 'hook_delete_manual_backup_task' ), 10, 1 );
    }

    /**
     * Remove a site's hidden on-demand backup task when the site is deleted.
     *
     * One manual wp_backup row (template = 1, schedule = 'manual') is created
     * per site the first time "Back up now" runs; without this it would
     * outlive the site. Hooked on mainwp_delete_site, which the manage-sites
     * deletion path fires after the site row is gone. Its restorable
     * snapshots, cloud copies and upload metadata are purged first so the
     * task row and its backups leave together rather than orphaning them.
     *
     * @param object $site Deleted site object.
     *
     * @return bool True when the cleanup ran (no task existed, or the purge
     *              succeeded and the task was removed); false on an empty
     *              site, or when the purge failed and the task was kept for
     *              retention to retry.
     */
    public function hook_delete_manual_backup_task( $site ) {
        if ( empty( $site ) || empty( $site->id ) ) {
            return false;
        }

        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site->id );
        if ( ! $task ) {
            return true;
        }

        $purge_result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( (int) $task->id, ! empty( $task->delete_cloud_copies ) );

        // The purge deliberately keeps a snapshot when its artifact cannot be
        // reached (recorded outside the uploads root, or an unlink refused).
        // Dropping the task and its diagnostics anyway would orphan those
        // artifacts with no owning task for retention to retry, erase the
        // evidence, and still report the cleanup as done. Keep the task and
        // its diagnostics; log the failure so a site-deletion orphan is at
        // least recorded.
        if ( ! empty( $purge_result['errors'] ) ) {
            MainWP_Logger::instance()->debug_for_website(
                $site,
                'backup',
                'Site deleted but its hidden backup task (id ' . (int) $task->id . ') could not be fully purged; keeping the task and its diagnostics.'
            );
            return false;
        }

        /**
         * Fires after a deleted or removed task's restorable snapshots
         * have been purged.
         *
         * @param int   $task_id Task id whose snapshots were purged.
         * @param array $result  Purge result: pruned, snapshots_pruned, errors.
         */
        do_action( 'mainwp_backup_task_snapshots_purged', (int) $task->id, $purge_result );

        MainWP_DB_Backup::instance()->remove_backup_task( (int) $task->id );

        return true;
    }

    /**
     * Verify the current user may manage backups for a child site.
     *
     * The backup and restore endpoints are gated by capabilities at
     * registration, but that does not scope access to individual sites. Without
     * a per-site check, a user could act on any site by passing its id. Sends a
     * JSON error and returns null when access is denied.
     *
     * @param int    $site_id Child site id.
     * @param string $right Operation right for the calling endpoint.
     *
     * @return object|null The website object when authorized, null otherwise.
     */
    private function authorize_restore_site( $site_id, string $right = 'execute_backups' ) {
        $website = MainWP_Backup_Operations::authorize_site( (int) $site_id, $right );
        if ( is_wp_error( $website ) ) {
            wp_send_json( array( 'error' => esc_html__( 'You are not allowed to manage backups for this site.', 'mainwp' ) ) );
            return null;
        }
        return $website;
    }

    /**
     * Whether the Dashboard's last contact with a site failed.
     *
     * A site with sync errors cannot take a backup or a restore: the request
     * would fail partway, after a safety backup or a transfer had already
     * spent its time. The UI turns the actions off and the endpoints refuse.
     *
     * @param object|null $website Website row.
     *
     * @return bool
     */
    public static function site_is_disconnected( $website ): bool {
        return MainWP_Backup_Restore_Orchestrator::site_is_disconnected( $website );
    }

    /**
     * The refusal sent when a backup or restore targets a disconnected site.
     *
     * @return string
     */
    public static function disconnected_site_error(): string {
        return __( 'This site is disconnected from the Dashboard. Reconnect it, then try again.', 'mainwp' );
    }

    /**
     * Turn a pre-escaped message into plain text for the JSON responses the
     * restore page writes with jQuery .text(), which escapes on its own.
     * Without this, an apostrophe or a ">" shows up as an HTML entity.
     *
     * @param string $message Message escaped for HTML.
     *
     * @return string Plain text.
     */
    private static function plain_text( string $message ): string {
        return wp_specialchars_decode( $message, ENT_QUOTES );
    }

    /**
     * Whether a restore must be explicitly confirmed before it runs.
     *
     * A restore that rewrites the database - anything but a files-only
     * snapshot - overwrites live data, so the server requires the same
     * consent the modal collects and a direct AJAX call cannot skip it. The
     * caller surfaces the WooCommerce/backup-age reason; this only decides
     * whether the gate fires.
     *
     * @param object|null $snapshot            Snapshot row being restored.
     * @param bool        $confirm_destructive Whether the request carried consent.
     *
     * @return bool True when consent is required but absent.
     */
    public static function restore_needs_confirmation( $snapshot, $confirm_destructive ) {
        return MainWP_Backup_Restore_Orchestrator::restore_needs_confirmation( $snapshot, $confirm_destructive );
    }

    /** Issue a snapshot download link after checking the AJAX nonce. */
    public function mainwp_backup_snapshot_download_url() {
        $this->secure_request( 'mainwp_backup_snapshot_download_url' );
        $snapshot_id = isset( $_POST['snapshot_id'] ) && is_scalar( $_POST['snapshot_id'] ) ? (int) $_POST['snapshot_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- secure_request checked above.
        $url         = MainWP_Backup_Download::create_download_url( $snapshot_id );
        wp_send_json( is_wp_error( $url ) ? array( 'error' => $url->get_error_message() ) : array( 'url' => $url ) );
    }

    /**
     * List a site's snapshots with restore availability.
     */
    public function mainwp_restore_snapshot_list() {
        $this->secure_request( 'mainwp_restore_snapshot_list' );
        $site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $website = $this->authorize_restore_site( $site_id );
        if ( null === $website ) {
            return;
        }

        $entries   = MainWP_Backup_Read_Model::list_backup_snapshots( $site_id, false );
        $multisite = MainWP_Backup_Monitor::child_is_multisite( $website );
        $rows      = array();

        $destination_names = array();
        foreach ( MainWP_Backup_Storage_Destinations::instance()->list_destinations() as $destination ) {
            $destination_names[ (int) $destination->id ] = (string) $destination->name;
        }
        foreach ( $entries as $entry ) {
            $uploads = array();
            foreach ( $entry['uploads'] as $job ) {
                $uploads[] = array(
                    'destination_id' => (int) $job->destination_id,
                    /* translators: %d: storage destination ID. */
                    'name'           => $destination_names[ (int) $job->destination_id ] ?? sprintf( __( 'Destination #%d (removed)', 'mainwp' ), (int) $job->destination_id ),
                    'provider'       => (string) $job->provider,
                    'status'         => (string) $job->status,
                    'updated'        => (int) $job->updated,
                    'last_error'     => mb_substr( $job->last_error['source_text'], 0, 200 ),
                );
            }
            $archives        = isset( $entry['snapshot']->archives ) && is_array( $entry['snapshot']->archives ) ? $entry['snapshot']->archives : array();
            $size_bytes      = (int) array_sum( array_column( $archives, 'size' ) );
            $test_restore_at = isset( $entry['snapshot']->test_restore_at ) ? (int) $entry['snapshot']->test_restore_at : 0;
            $skipped         = MainWP_Backup_Read_Model::snapshot_skipped_files( $entry['snapshot'] );
            $rows[]          = array(
                'id'                 => (int) $entry['snapshot']->id,
                'task_id'            => (int) $entry['snapshot']->task_id,
                'type'               => (string) $entry['snapshot']->type,
                'created'            => (int) $entry['snapshot']->created,
                // Formatted here so the page uses the Dashboard date and time
                // settings, as the task list does, not the browser locale.
                'created_label'      => MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( (int) $entry['snapshot']->created ) ),
                'age_days'           => max( 0, (int) floor( ( time() - (int) $entry['snapshot']->created ) / DAY_IN_SECONDS ) ),
                'status'             => (string) $entry['snapshot']->status,
                'offsite_state'      => 'pruned' !== $entry['snapshot']->status && isset( $entry['snapshot']->offsite_state ) ? (string) $entry['snapshot']->offsite_state : '',
                'uploads'            => $uploads,
                'source'             => (string) $entry['source'],
                'restorable'         => (bool) $entry['catalog_restorable'],
                'restore_reason'     => (string) $entry['catalog_restore_reason'],
                'removal_reason'     => (string) $entry['removal_reason'],
                'skipped_count'      => $skipped['count'],
                'skipped_paths'      => $skipped['paths'],
                'size_label'         => $size_bytes > 0 ? MainWP_Utility::human_filesize( $size_bytes ) : '',
                'test_restore_state' => isset( $entry['snapshot']->test_restore_state ) ? (string) $entry['snapshot']->test_restore_state : '',
                'test_restore_at'    => $test_restore_at,
                'test_restore_label' => $test_restore_at > 0 ? MainWP_Utility::format_date( MainWP_Utility::get_timestamp( $test_restore_at ) ) : '',
            );
        }
        // The reload bootstrap: the server knows what is running for this
        // site, so a freshly loaded page (or a site switch) resumes the
        // progress views instead of looking hung while a restore or an
        // on-demand backup carries on in the background.
        $restore_jobs = MainWP_Backup_Read_Model::list_restore_jobs( $site_id );
        $failed_rows  = $restore_jobs['failed_restore_jobs'];
        foreach ( $failed_rows as &$failed_row ) {
            $failed_row['last_error'] = $failed_row['last_error']['source_text'];
        }
        unset( $failed_row );
        $manual_task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        $manual_plan = MainWP_DB_Backup::instance()->manual_backup_destination_plan( $site_id );
        $manual_ids  = $manual_plan['ids'];
        sort( $manual_ids, SORT_NUMERIC );
        $manual_destinations = array();
        foreach ( $manual_ids as $destination_id ) {
            $manual_destinations[] = $destination_names[ $destination_id ] ?? sprintf(
                /* translators: %d: storage destination ID. */
                __( 'Destination #%d (removed)', 'mainwp' ),
                $destination_id
            );
        }

        wp_send_json(
            array(
                'snapshots'             => $rows,
                'multisite'             => $multisite,
                'site_has_woocommerce'  => MainWP_Utility::site_has_woocommerce( (int) $website->id ),
                'site_url'              => (string) $website->url,
                'local_only'            => empty( $manual_ids ),
                'manual_destinations'   => $manual_destinations,
                'destinations_source'   => $manual_plan['source'],
                'active_restore_job_id' => $restore_jobs['active_restore_job_id'],
                'failed_restore_jobs'   => $failed_rows,
                'site_disconnected'     => static::site_is_disconnected( $website ),
                'reconnect_url'         => admin_url( 'admin.php?page=managesites&dashboard=' . (int) $website->id ),
                'snapshot_delete_busy'  => MainWP_Backup_Operations::snapshot_delete_busy( $site_id ),
                'manual_backup_running' => ! empty( $manual_task ) && (int) $manual_task->completed < (int) $manual_task->last_run,
            )
        );
    }

    /** Preview single-snapshot deletion without contacting the site. */
    public function mainwp_backup_snapshot_delete_preview() {
        $this->secure_request( 'mainwp_backup_snapshot_delete_preview' );
        $this->snapshot_delete_request( true );
    }

    /** Confirm only the snapshot set the user reviewed. */
    public function mainwp_backup_snapshot_delete() {
        $this->secure_request( 'mainwp_backup_snapshot_delete' );
        $this->snapshot_delete_request( false );
    }

    /**
     * Keep exact identities intact until the common validator accepts them.
     *
     * @param bool $preview Whether to preview.
     * @return void
     */
    private function snapshot_delete_request( bool $preview ): void {
        $input = array(
            'site_id_or_domain' => isset( $_POST['site_id'] ) ? wp_unslash( $_POST['site_id'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- secure_request verified; exact ids validated below before use.
            'snapshot_id'       => isset( $_POST['snapshot_id'] ) ? wp_unslash( $_POST['snapshot_id'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- exact ids validated below.
        );
        if ( ! $preview ) {
            $input['expected_snapshot_ids'] = isset( $_POST['expected_snapshot_ids'] ) ? wp_unslash( $_POST['expected_snapshot_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw selection validated below.
        }
        $valid = MainWP_Abilities_Backups::validate_id_input( $input, true );
        $cloud = isset( $_POST['delete_cloud_copies'] ) ? wp_unslash( $_POST['delete_cloud_copies'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restricted boolean values checked below.
        if ( is_wp_error( $valid ) || ( null !== $cloud && ! in_array( $cloud, array( '0', '1', 0, 1, false, true ), true ) ) ) {
            wp_send_json( array( 'error' => MainWP_Backup_Operations::safe_error( 'mainwp_invalid_input' )['message'] ) );
            return;
        }
        $cloud  = null === $cloud ? null : (bool) $cloud;
        $result = $preview
            ? MainWP_Backup_Operations::preview_delete_snapshot( (int) $input['site_id_or_domain'], (int) $input['snapshot_id'], $cloud )
            : MainWP_Backup_Operations::delete_snapshot( (int) $input['site_id_or_domain'], (int) $input['snapshot_id'], $input['expected_snapshot_ids'], $cloud );
        wp_send_json(
            is_wp_error( $result ) ? array(
                'error'      => $result->get_error_message(),
                'error_code' => $result->get_error_code(),
            ) : $result
        );
    }

    /**
     * Clean up a site's backups: sweep orphaned snapshot directories left
     * by interrupted runs, remove snapshots whose task was deleted, then
     * prune the on-demand snapshots down to a keep-count.
     *
     * Runs the same guarded deletion sequence retention uses, but
     * synchronously - a low-traffic dashboard whose WP-Cron rarely fires
     * gets its disk back on click instead of waiting for the queue to
     * drain. An explicit keep overrides the task's retention count for
     * this run only; it is floored at 1 so a cleanup can never delete a
     * site's last restorable backup.
     */
    public function mainwp_snapshots_cleanup() {
        $this->secure_request( 'mainwp_snapshots_cleanup' );
        $site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $website = $this->authorize_restore_site( $site_id );
        if ( null === $website ) {
            return;
        }

        $keep = isset( $_POST['keep'] ) && '' !== $_POST['keep'] ? max( 1, (int) $_POST['keep'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $result = MainWP_Backup_Operations::cleanup_site_backups( $site_id, $keep, false );
        if ( is_wp_error( $result ) ) {
            wp_send_json( array( 'error' => esc_html__( 'You are not allowed to manage backups for this site.', 'mainwp' ) ) );
            return;
        }

        wp_send_json(
            array(
                'result'                    => 'ok',
                'orphan_dirs_removed'       => (int) $result['orphan_dirs_removed'],
                'units_pruned'              => (int) $result['pruned'],
                'snapshots_pruned'          => (int) $result['snapshots_pruned'],
                'deleted_ids'               => $result['deleted_ids'],
                'remaining_ids'             => $result['remaining_ids'],
                'cloud_deletes_queued'      => $result['cloud_deletes_queued'],
                'orphaned_snapshots_pruned' => (int) $result['orphaned_snapshots_pruned'],
                'bytes_pruned'              => (int) $result['bytes_pruned'],
                'bytes_pruned_label'        => (int) $result['bytes_pruned'] > 0 ? MainWP_Utility::human_filesize( (int) $result['bytes_pruned'] ) : '',
                'errors'                    => array_map( 'esc_html', (array) $result['errors'] ),
            )
        );
    }

    /**
     * Start a restore for a snapshot.
     */
    public function mainwp_restore_initiate() {
        $this->secure_request( 'mainwp_restore_initiate' );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by secure_request above.
        $site_id     = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
        $snapshot_id = isset( $_POST['snapshot_id'] ) ? (int) $_POST['snapshot_id'] : 0;
        $options     = array(
            'pre_snapshot'        => isset( $_POST['pre_snapshot'] ) && '1' === $_POST['pre_snapshot'],
            'file_rollback'       => isset( $_POST['pre_snapshot'] ) && '1' === $_POST['pre_snapshot'],
            'confirm_destructive' => isset( $_POST['confirm_destructive'] ) && '1' === $_POST['confirm_destructive'],
            'preserve_orders'     => isset( $_POST['preserve_orders'] ) && '1' === $_POST['preserve_orders'],
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        try {
            $result = MainWP_Backup_Operations::restore_snapshot( $site_id, $snapshot_id, $options, 'ui' );
        } catch ( MainWP_Exception $e ) {
            $code = $e->get_message_error_code();
            if ( 'mainwp_confirmation_required' === $code ) {
                wp_send_json( array_merge( array( 'needs_confirmation' => true ), (array) $e->get_data() ) );
            } elseif ( MainWP_Backup_Error_Codes::ERR_SITE_DISCONNECTED === $code || MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND === $code ) {
                wp_send_json( array( 'error' => static::plain_text( $e->getMessage() ) ) );
            } else {
                wp_send_json(
                    array(
                        'error'      => static::plain_text( $e->getMessage() ),
                        'error_code' => $code,
                    )
                );
            }
            return;
        }
        if ( is_wp_error( $result ) ) {
            wp_send_json( array( 'error' => esc_html__( 'You are not allowed to manage backups for this site.', 'mainwp' ) ) );
            return;
        }
        wp_send_json( $result );
    }

    /**
     * Drive and report a restore job's progress.
     *
     * Each poll drives one short restore slice so an open tab advances the
     * run instead of waiting for cron; the cron backstop carries a closed
     * tab between polls. The job lock serializes the slice against a
     * concurrent cron run (a held lock makes the drive a no-op), and the
     * driver advances the next acquirable job - on a dashboard with one
     * active restore that is this one. Job authorization precedes the drive
     * because secure_request checks the nonce, not operation rights.
     */
    public function mainwp_restore_progress() {
        $this->secure_request( 'mainwp_restore_progress' );
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $job = MainWP_Backup_Operations::authorize_restore_job( $job_id );
        if ( is_wp_error( $job ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Restore job not found.', 'mainwp' ) ) );
            return;
        }

        $budget = (int) apply_filters( 'mainwp_restore_foreground_budget', self::RESTORE_FOREGROUND_BUDGET );
        if ( $budget >= 0 ) {
            static::raise_backup_resource_limits();
            MainWP_Backup_Restore_Orchestrator::instance()->process_pending_restore_jobs( $budget, $job_id );
            $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );
            if ( null === $job ) {
                wp_send_json( array( 'error' => esc_html__( 'Restore job not found.', 'mainwp' ) ) );
                return;
            }
        }

        $response = MainWP_Backup_Read_Model::get_restore_job( $job_id );
        if ( is_wp_error( $response ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Restore job not found.', 'mainwp' ) ) );
            return;
        }
        unset( $response['cancel_confirmation'], $response['error'], $response['partial_state_reasons'] );
        $response['last_error'] = $response['last_error']['source_text'];
        wp_send_json( $response );
    }

    /**
     * Cancel a restore job while it is still safe.
     */
    public function mainwp_restore_cancel() {
        $this->secure_request( 'mainwp_restore_cancel' );
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $job = MainWP_Backup_Operations::authorize_restore_job( $job_id, true );
        if ( is_wp_error( $job ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Restore job not found.', 'mainwp' ) ) );
            return;
        }

        try {
            $outcome = MainWP_Backup_Restore_Orchestrator::instance()->cancel_job( $job_id );
        } catch ( MainWP_Exception $e ) {
            wp_send_json(
                array(
                    'error'      => static::plain_text( $e->getMessage() ),
                    'error_code' => $e->get_message_error_code(),
                )
            );
            return;
        }
        wp_send_json( array( 'result' => 'stopped' === $outcome ? 'stopped' : ( 'cancel_pending' === $outcome ? 'pending' : 'ok' ) ) );
    }

    /**
     * Start a rollback restore from a finished job's safety backup.
     */
    public function mainwp_restore_rollback() {
        $this->secure_request( 'mainwp_restore_rollback' );
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.
        try {
            $result = MainWP_Backup_Operations::rollback_restore( $job_id, 'ui' );
        } catch ( MainWP_Exception $e ) {
            if ( MainWP_Backup_Error_Codes::ERR_SITE_DISCONNECTED === $e->get_message_error_code() ) {
                wp_send_json(
                    array(
                        'error'     => static::disconnected_site_error(),
                        'retryable' => true,
                    )
                );
                return;
            }
            $job       = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );
            $retryable = null !== $job
                && in_array( $job->status, array( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED, MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED ), true )
                && MainWP_Backup_Restore_Orchestrator::instance()->has_rollback_source( $job );
            wp_send_json(
                array(
                    'error'      => static::plain_text( $e->getMessage() ),
                    'error_code' => $e->get_message_error_code(),
                    'retryable'  => $retryable,
                )
            );
            return;
        }
        if ( is_wp_error( $result ) ) {
            wp_send_json(
                array(
                    'error'     => esc_html__( 'Restore job not found.', 'mainwp' ),
                    'retryable' => false,
                )
            );
            return;
        }
        wp_send_json( $result );
    }

    /**
     * Start an on-demand native backup for a site.
     *
     * Prepares the per-site hidden manual task, then kicks the first slice.
     * The task is a real wp_backup row, so recording, retention, offsite
     * upload, and the existing continue cron all apply: a closed tab still
     * finishes the backup. Returns a job handle (the task id) for the
     * progress poll to follow.
     */
    public function mainwp_native_backup_initiate() {
        $this->secure_request( 'mainwp_native_backup_initiate' );
        $site_id  = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.
        $response = MainWP_Backup_Operations::start_site_backup( $site_id, 'ui' );
        wp_send_json( $this->serialize_backup_operation( $response ) );
    }


    /**
     * Existing pages retain their error text and field types.
     *
     * @param array|\WP_Error $response Neutral operation result.
     * @return array Existing AJAX payload.
     */
    private function serialize_backup_operation( $response ): array {
        if ( is_wp_error( $response ) ) {
            $auth_codes = array( 'mainwp_permission_denied', 'mainwp_insufficient_right', 'mainwp_site_not_found' );
            return array( 'error' => in_array( $response->get_error_code(), $auth_codes, true ) ? esc_html__( 'You are not allowed to manage backups for this site.', 'mainwp' ) : $response->get_error_message() );
        }
        if ( isset( $response['error'] ) && is_array( $response['error'] ) ) {
            $response['error'] = $response['error']['source_text'];
        }
        return $response;
    }

    /**
     * Schedule one continuation, even when a recurring event is already pending.
     *
     * Cron requests defer the loopback until shutdown, after WordPress releases
     * its lock. Under DISABLE_WP_CRON or ALTERNATE_WP_CRON the shutdown spawn is
     * a no-op; system or page-load cron, respectively, is the backstop.
     *
     * @param int $when Unix timestamp, or zero for the current time.
     * @return void
     */
    public static function kick_backup_continuation( int $when = 0 ) {
        if ( ! wp_next_scheduled( 'mainwp_native_backup_continue_action' ) ) {
            wp_schedule_single_event( 0 === $when ? time() : $when, 'mainwp_native_backup_continue_action' );
        }
        if ( wp_doing_cron() ) {
            if ( false === has_action( 'shutdown', array( self::class, 'spawn_backup_continuation' ) ) ) {
                add_action( 'shutdown', array( self::class, 'spawn_backup_continuation' ) );
            }
            return;
        }
        spawn_cron();
    }

    /**
     * Let the next cron request acquire its own lock after this request ends.
     *
     * @return void
     */
    public static function spawn_backup_continuation(): void {
        if ( ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
            || ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON )
            || ! wp_next_scheduled( 'mainwp_native_backup_continue_action' ) ) {
            return;
        }

        $cron_request = self::get_cron_loopback_request();
        wp_remote_post( $cron_request['url'], $cron_request['args'] );
    }

    /**
     * Build the filtered cron request shared by the continuation sender and loopback probe.
     *
     * @return array Filtered cron URL, key and request arguments.
     */
    public static function get_cron_loopback_request(): array {
        // A timestamped doing_wp_cron argument requires a matching pre-existing lock.
        $cron_url = site_url( 'wp-cron.php' );
        return apply_filters(
            'cron_request',
            array(
                'url'  => $cron_url,
                'key'  => '',
                'args' => array(
                    'timeout'   => 0.01,
                    'blocking'  => false,
                    'sslverify' => apply_filters( 'https_local_ssl_verify', false, $cron_url ),
                ),
            ),
            ''
        );
    }

    /**
     * Drive and report an on-demand native backup.
     *
     * Each poll drives one continuation step while the run is open (and claims
     * a slot for a click whose run never opened), then reports running,
     * completed, or failed. Completion is judged by snapshot freshness, not
     * the child phase.
     */
    public function mainwp_native_backup_progress() {
        $this->secure_request( 'mainwp_native_backup_progress' );
        $site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.
        $since   = isset( $_POST['since'] ) ? (int) $_POST['since'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $website = $this->authorize_restore_site( $site_id );
        if ( null === $website ) {
            return;
        }

        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        if ( empty( $task ) ) {
            wp_send_json( array( 'error' => esc_html__( 'No on-demand backup is running for this site.', 'mainwp' ) ) );
            return;
        }

        static::raise_backup_resource_limits();

        // Drive one step. updateRun = false so the in-flight run is never
        // reset. The cron backstop covers a closed tab between polls.
        if ( empty( $task->paused ) && (int) $task->completed < (int) $task->last_run ) {
            MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );
            $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        } elseif ( empty( $task->paused ) && $since > (int) $task->last_run && $since <= time() ) {
            // The click this poll follows never got its run opened: its
            // initiate lost the concurrency-slot race and answered queued,
            // and neither branch above nor the continue cron ever drives a
            // run that was never stamped - the poll is that click's only
            // driver, so the claim retries here. While the slot stays busy
            // the response says queued; without this, the stale closed run
            // below reads as this click's failure. The time() cap keeps a
            // fabricated future `since` from re-claiming on every poll -
            // a real one is the initiate's own server-side stamp.
            //
            // This claim can OPEN a run, so it honors the same restore and
            // disconnected-site refusals as the initiate handler: a poll left
            // over from a stale tab (or carrying another site's since after
            // a switch) must not start a backup the initiate would refuse.
            if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
                wp_send_json( array( 'error' => esc_html__( 'A restore is in progress for this site. Try again once it finishes.', 'mainwp' ) ) );
                return;
            }
            if ( static::site_is_disconnected( $website ) ) {
                wp_send_json( array( 'error' => static::disconnected_site_error() ) );
                return;
            }
            $settle = MainWP_Backup_Settings::get_manual_run_settle_seconds();
            MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true, $settle );
            $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
            if ( $task && empty( $task->paused ) && (int) $task->completed >= (int) $task->last_run && $since > (int) $task->last_run ) {
                wp_send_json( array( 'status' => 'queued' ) );
                return;
            }
        }

        $response = MainWP_Backup_Read_Model::get_site_backup_progress( $site_id, $since );
        foreach ( array( 'error', 'notice' ) as $key ) {
            if ( isset( $response[ $key ] ) ) {
                $response[ $key ] = static::humanize_backup_errors( $response[ $key ]['source_text'] );
            }
        }
        wp_send_json( $response );
    }

    /**
     * Cancellation can only stop the Child task identified by this run.
     */
    public function mainwp_native_backup_cancel() {
        $this->secure_request( 'mainwp_native_backup_cancel' );
        $site_id  = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.
        $response = MainWP_Backup_Operations::cancel_site_backup( $site_id );
        wp_send_json( $this->serialize_backup_operation( $response ) );
    }

    /**
     * Reset a scheduled backup task's stuck "currently running" state.
     *
     * A crashed scheduled run never advances `completed` past `last_run`,
     * so the list view would otherwise show CURRENTLY RUNNING forever with
     * no way to clear it. Once the run is confirmed stale (elapsed past the
     * same wall-clock threshold MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS
     * already applies to a per-site busy-since stall), this drives the same
     * child-side backup_task_cancel mainwp_native_backup_cancel uses on
     * every site in the task, then closes the local run record. A site
     * whose cancel does not land (unreachable child, child error, or a
     * live runner still holding the lease) is recorded on the task and the
     * dashboard-only bookkeeping close remains the fallback, so the next
     * schedule (or a manual Run Now) is never left blocked.
     */
    public function mainwp_native_backup_task_reset() {
        $this->secure_request( 'mainwp_native_backup_task_reset' );
        $task_id = isset( $_POST['task_id'] ) ? (int) $_POST['task_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $response = MainWP_Backup_Operations::reset_task_run( $task_id );
        if ( is_wp_error( $response ) ) {
            wp_send_json( array( 'error' => $response->get_error_message() ) );
            return;
        }
        unset( $response['sites_uncancelled'] );
        wp_send_json( $response );
    }

    /**
     * Stop the run identity shown by the task list.
     */
    public function mainwp_native_backup_task_cancel_run() {
        $this->secure_request( 'mainwp_native_backup_task_cancel_run' );
        $task_id = isset( $_POST['task_id'] ) ? absint( $_POST['task_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $run     = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $reply   = MainWP_Backup_Operations::cancel_task_run( $task_id, $run );
        wp_send_json( is_wp_error( $reply ) ? array( 'error' => $reply->get_error_message() ) : $reply );
    }

    /**
     * Keep diagnostics downloads bound to the user who requested the export.
     */
    public function mainwp_backup_diagnostics_export() {
        $this->secure_request( 'mainwp_backup_diagnostics_export' );

        if ( ! MainWP_Backup_Diagnostics::is_enabled() ) {
            wp_send_json( array( 'error' => esc_html__( 'Backup diagnostics is turned off. Enable it in the backup settings and run a backup first.', 'mainwp' ) ) );
            return;
        }

        $bundle = MainWP_Backup_Diagnostics::instance()->export_bundle();
        if ( '' === $bundle ) {
            wp_send_json( array( 'error' => esc_html__( 'There is no diagnostics data to export yet. Run a backup or restore with diagnostics enabled first.', 'mainwp' ) ) );
            return;
        }

        wp_send_json(
            array(
                'url' => MainWP_Backup_Diagnostics::instance()->create_download_url( $bundle ),
            )
        );
    }

    /**
     * Flatten the task's stored HTML backup_errors into a readable single
     * line of plain text. The errors are stored as an HTML list, so block
     * boundaries are turned into spaces before the tags are stripped;
     * without this the site name and the message run together. Entities are
     * decoded last: every caller sends the result as JSON to a jQuery .text()
     * sink, which escapes on its own, so an encoded quote would show as
     * "&quot;".
     *
     * @param string $errors Stored backup_errors HTML.
     *
     * @return string A flattened, space-separated plain-text message.
     */
    public static function humanize_backup_errors( $errors ) {
        $spaced = str_replace( array( '<br>', '<br/>', '<br />', '</li>', '<li>', '</ol>', '<ol>' ), ' ', (string) $errors );
        return wp_specialchars_decode( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $spaced ) ) ), ENT_QUOTES );
    }

    /*
     * Page: Backup
     */

    /**
     * Method raise_backup_resource_limits()
     *
     * Raise execution time and memory limits for manually triggered
     * backups so they match the limits the cron path already uses.
     * Without this, AJAX backups inherit default PHP limits and large
     * sites fail mid-transfer.
     *
     * @return void
     */
    public static function raise_backup_resource_limits() {
        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );
        add_filter(
            'admin_memory_limit',
            function () {
                return '512M';
            }
        );
        wp_raise_memory_limit( 'admin' );
    }

    /**
     * Method die_exception_error()
     *
     * Send an exception as a JSON error response, including the error code
     * so the UI can classify the failure severity.
     *
     * @param MainWP_Exception $e Exception to report.
     *
     * @return never
     */
    private function die_exception_error( MainWP_Exception $e ) {
        die(
            wp_json_encode(
                array(
                    'error' => array(
                        'message' => $e->getMessage(),
                        'extra'   => $e->get_message_extra(),
                        'code'    => $e->get_message_error_code(),
                    ),
                )
            )
        );
    }

    /**
     * Method build_task_progress_response()
     *
     * Shape a progress row into the poll response: the human label
     * derives from the child machine phase, and the percentage prefers
     * file counters over bytes.
     *
     * @param object|null $row Progress row, or null when none exists.
     *
     * @return array Response with status, phase, label, counters, percent.
     */
    public static function build_task_progress_response( ?object $row ): array {
        if ( empty( $row ) || ( empty( $row->child_phase ) && empty( $row->child_backup_task_id ) ) ) {
            return array( 'status' => 'idle' );
        }

        $phase = MainWP_Backup_Task_Phase::tryFrom( (string) $row->child_phase );

        $status = 'running';
        if ( MainWP_Backup_Task_Phase::COMPLETED === $phase ) {
            $status = 'completed';
        } elseif ( MainWP_Backup_Task_Phase::FAILED === $phase ) {
            $status = 'failed';
        }

        if ( null !== $phase ) {
            $label = MainWP_Backup_Task_Phase::label( $phase );
        } elseif ( '' === (string) $row->child_phase ) {
            $label = MainWP_Backup_Task_Phase::label( MainWP_Backup_Task_Phase::QUEUED );
        } else {
            // A future phase from a newer child: still working, no known label.
            $label = __( 'Working', 'mainwp' );
        }

        $files_processed = (int) $row->files_processed;
        $files_total     = (int) $row->files_total;
        $bytes_done      = (int) $row->bytes_downloaded;
        $bytes_expected  = (int) $row->bytes_expected;

        // A file ratio is meaningful only while counters advance for the
        // current phase: the database dump (tables done) or the archiving walk
        // once it reports a scanned count. Other phases - and archiving before a
        // live count exists - use the byte ratio, which during archiving is the
        // compressed archive against the raw file total, a rough monotonic
        // estimate. This keeps a stale count from an earlier phase (e.g. the
        // dump's tables) out of the upload percent.
        $file_ratio_phase = MainWP_Backup_Task_Phase::DUMPING_DB === $phase
            || MainWP_Backup_Task_Phase::ARCHIVING_FILES === $phase;

        $percent = null;
        if ( $file_ratio_phase && $files_processed > 0 && $files_total > 0 ) {
            $percent = (int) round( min( 100, $files_processed * 100 / $files_total ) );
        } elseif ( $bytes_expected > 0 ) {
            $percent = (int) round( min( 100, $bytes_done * 100 / $bytes_expected ) );
        }

        // A running phase never reads 100%; completion is signalled by the
        // terminal status, not a full bar mid-phase.
        if ( null !== $percent && 'running' === $status && $percent >= 100 ) {
            $percent = 99;
        }

        return array(
            'status'          => $status,
            'phase'           => (string) $row->child_phase,
            'label'           => $label,
            'current_file'    => (string) $row->current_file,
            'files_processed' => $files_processed,
            'files_total'     => $files_total,
            'bytes_done'      => $bytes_done,
            'bytes_expected'  => $bytes_expected,
            'percent'         => $percent,
        );
    }

    /**
     * Method mainwp_backup_run_site()
     *
     * Run backup task of site
     *
     * @throws MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Executor::backup()
     * @uses \MainWP\Dashboard\MainWP_Exception
     */
    public function mainwp_backup_run_site() {
        $this->secure_request( 'mainwp_backup_run_site' );
        static::raise_backup_resource_limits();
        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        try {
            if ( ! $site_id ) {
                throw new MainWP_Exception( 'Site ID not found. Please reload the page and try again.', 'mainwp' );
            }
            if ( null === $this->authorize_restore_site( $site_id, 'run_backup_tasks' ) ) {
                return;
            }

            $ret = array(
                'result' => MainWP_Backup_Executor::with_backup_hold(
                    $site_id,
                    static function () use ( $site_id ) {
                        return MainWP_Backup_Executor::backup( $site_id, 'full', '', '', 1, 1, 1, 1 );
                    },
                    true
                ),
            );
            wp_send_json( $ret );
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
    }

    /**
     * Method sanitize_adhoc_backup_type()
     *
     * Allowlist the ad-hoc backup type from the wire. Incremental is
     * deliberately absent - its chain lives on a scheduled task - and
     * anything unrecognized folds to a full backup rather than reaching
     * the executor as arbitrary input.
     *
     * @param string $type Requested backup type.
     *
     * @return string One of full, db, or files.
     */
    public static function sanitize_adhoc_backup_type( string $type ): string {
        return in_array( $type, array( 'full', 'db', 'files' ), true ) ? $type : 'full';
    }

    /**
     * Method mainwp_backup()
     *
     * Run backup task
     *
     * @throws MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Executor::backup()
     * @uses \MainWP\Dashboard\MainWP_Exception
     */
    public function ajax_mainwp_backup() {
        $this->secure_request( 'mainwp_backup' );
        static::raise_backup_resource_limits();
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : false;
        try {
            if ( ! $site_id ) {
                throw new MainWP_Exception( 'Site ID not found. Please reload the page and try again.', 'mainwp' );
            }
            if ( null === $this->authorize_restore_site( $site_id ) ) {
                return;
            }

            $excludedFolder = isset( $_POST['exclude'] ) ? trim( wp_unslash( $_POST['exclude'] ), "\n" ) : '';
            $excludedFolder = explode( "\n", $excludedFolder );
            $excludedFolder = array_map( array( MainWP_Utility::get_class_name(), 'trim_slashes' ), $excludedFolder );
            $excludedFolder = array_map( 'htmlentities', $excludedFolder );
            $excludedFolder = MainWP_Backup_Settings::sanitize_exclude_list( $excludedFolder );

            $type                           = static::sanitize_adhoc_backup_type( isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '' );
            $subfolder                      = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';
            $excludebackup                  = isset( $_POST['excludebackup'] ) ? sanitize_text_field( wp_unslash( $_POST['excludebackup'] ) ) : '';
            $excludecache                   = isset( $_POST['excludecache'] ) ? sanitize_text_field( wp_unslash( $_POST['excludecache'] ) ) : '';
            $excludenonwp                   = isset( $_POST['excludenonwp'] ) ? sanitize_text_field( wp_unslash( $_POST['excludenonwp'] ) ) : '';
            $excludezip                     = isset( $_POST['excludezip'] ) ? sanitize_text_field( wp_unslash( $_POST['excludezip'] ) ) : '';
            $filename                       = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
            $fileNameUID                    = isset( $_POST['fileNameUID'] ) ? sanitize_text_field( wp_unslash( $_POST['fileNameUID'] ) ) : '';
            $archiveFormat                  = isset( $_POST['archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['archiveFormat'] ) ) : '';
            $maximumFileDescriptorsOverride = isset( $_POST['maximumFileDescriptorsOverride'] ) ? intval( $_POST['maximumFileDescriptorsOverride'] ) : false;
            $maximumFileDescriptorsAuto     = isset( $_POST['maximumFileDescriptorsAuto'] ) ? intval( $_POST['maximumFileDescriptorsAuto'] ) : false;
            $maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) ? sanitize_text_field( wp_unslash( $_POST['maximumFileDescriptors'] ) ) : '';
            $loadFilesBeforeZip             = isset( $_POST['loadFilesBeforeZip'] ) ? sanitize_text_field( wp_unslash( $_POST['loadFilesBeforeZip'] ) ) : false;
            $pid                            = isset( $_POST['pid'] ) ? intval( $_POST['pid'] ) : false;
            $append                         = isset( $_POST['append'] ) ? intval( $_POST['append'] ) : false;

            $result = MainWP_Backup_Executor::with_backup_hold(
                $site_id,
                static function () use ( $site_id, $type, $subfolder, $excludedFolder, $excludebackup, $excludecache, $excludenonwp, $excludezip, $filename, $fileNameUID, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip, $pid, $append ) {
                    return MainWP_Backup_Executor::backup( $site_id, $type, $subfolder, $excludedFolder, $excludebackup, $excludecache, $excludenonwp, $excludezip, $filename, $fileNameUID, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip, $pid, $append );
                },
                true
            );
            wp_send_json( array( 'result' => $result ) );
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
        // phpcs:enable
    }

    /**
     * Method mainwp_backup_refresh_archives()
     *
     * Return the current "Downloadable archives" list markup for a site so the
     * legacy backup modal can refresh the list in place after an archive
     * finishes, instead of reloading the whole admin page.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites::show_backups()
     */
    public function mainwp_backup_refresh_archives() {
        $this->secure_request( 'mainwp_backup_refresh_archives' );
        $site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $website = $this->authorize_restore_site( $site_id );
        if ( null === $website ) {
            return;
        }

        ob_start();
        MainWP_Manage_Sites::show_backups( $website );
        $html = ob_get_clean();

        wp_send_json( array( 'result' => $html ) );
    }

    /**
     * Method mainwp_backup_checkpid()
     *
     * Check backup task
     *
     * @throws MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Monitor::backup_check_pid()
     * @uses \MainWP\Dashboard\MainWP_Exception
     */
    public function mainwp_backup_checkpid() {
        $this->secure_request( 'mainwp_backup_checkpid' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : false;
        try {
            if ( ! $site_id ) {
                throw new MainWP_Exception( 'Site ID not found. Please reload the page and try again.', 'mainwp' );
            }
            if ( null === $this->authorize_restore_site( $site_id ) ) {
                return;
            }
            $pid       = isset( $_POST['pid'] ) ? intval( $_POST['pid'] ) : false;
            $type      = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
            $subfolder = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';
            $filename  = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
            $result    = MainWP_Backup_Executor::with_backup_hold(
                $site_id,
                static function () use ( $site_id, $pid, $type, $subfolder, $filename ) {
                    return MainWP_Backup_Monitor::backup_check_pid( $site_id, $pid, $type, $subfolder, $filename );
                },
                true
            );
            wp_send_json( $result );
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
        // phpcs:enable
    }

    /**
     * Method mainwp_backup_download_file()
     *
     * Download backup file
     *
     * @throws MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_File_Manager::backup_download_file()
     * @uses \MainWP\Dashboard\MainWP_Exception
     */
    public function ajax_mainwp_backup_download_file() {
        $this->secure_request( 'mainwp_backup_download_file' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : false;
        try {
            if ( ! $site_id ) {
                throw new MainWP_Exception( 'Site ID not found. Please reload the page and try again.', 'mainwp' );
            }
            if ( null === $this->authorize_restore_site( $site_id ) ) {
                return;
            }
            $type   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
            $url    = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
            $local  = isset( $_POST['local'] ) ? sanitize_text_field( wp_unslash( $_POST['local'] ) ) : '';
            $result = MainWP_Backup_Executor::with_backup_hold(
                $site_id,
                static function () use ( $site_id, $type, $url, $local ) {
                    return MainWP_Backup_File_Manager::backup_download_file( $site_id, $type, $url, $local );
                },
                true
            );
            die( wp_json_encode( array( 'result' => $result ) ) );
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
        // phpcs:enable
    }

    /**
     * Method mainwp_backup_delete_file()
     *
     * Delete backup file
     *
     * @throws MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Executor::backup_delete_file()
     * @uses \MainWP\Dashboard\MainWP_Exception
     */
    public function mainwp_backup_delete_file() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        try {
            if ( ! \mainwp_current_user_can( 'dashboard', 'execute_backups' ) && ! \mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
                throw new MainWP_Exception( esc_html__( 'Error - insufficient permissions!', 'mainwp' ) );
            }
            $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : 0;
            if ( ! $site_id ) {
                throw new MainWP_Exception( esc_html__( 'Site ID not found. Please reload the page and try again.', 'mainwp' ) );
            }
            if ( null === $this->authorize_restore_site( $site_id ) ) {
                return;
            }
            if ( ! \mainwp_current_user_can( 'site', $site_id ) ) {
                throw new MainWP_Exception( esc_html__( 'Error - insufficient permissions!', 'mainwp' ) );
            }

            // Sanitizing first can hide a NUL or change a custom archive's basename.
            $file = isset( $_POST['file'] ) && is_string( $_POST['file'] ) ? wp_unslash( $_POST['file'] ) : '';
            if ( ! MainWP_Backup_Handler::is_valid_backup_archive_name( $file ) ) {
                throw new MainWP_Exception( esc_html__( 'Invalid backup archive name.', 'mainwp' ) );
            }
            // Refusals must not record secure_request's duplicate-request marker.
            $this->secure_request( 'mainwp_backup_delete_file' );

            die( wp_json_encode( array( 'result' => MainWP_Backup_Executor::backup_delete_file( $site_id, $file ) ) ) );
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
        // phpcs:enable
    }

    /**
     * Method mainwp_createbackup_getfilesize()
     *
     * Get create backup file size.
     *
     * @throws \MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses  \MainWP\Dashboard\MainWP_Utility::end_session()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function mainwp_createbackup_getfilesize() {
        $this->secure_request( 'mainwp_createbackup_getfilesize' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        try {
            if ( ! isset( $_POST['siteId'] ) ) {
                throw new MainWP_Exception( esc_html__( 'No site selected!', 'mainwp' ) );
            }
            $siteId      = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : '';
            $fileName    = isset( $_POST['fileName'] ) ? sanitize_text_field( wp_unslash( $_POST['fileName'] ) ) : '';
            $fileNameUID = isset( $_POST['fileNameUID'] ) ? sanitize_text_field( wp_unslash( $_POST['fileNameUID'] ) ) : '';
            $type        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

            $website = $this->authorize_restore_site( (int) $siteId );
            if ( null === $website ) {
                return;
            }

            MainWP_Utility::end_session();
            // Send request to the childsite!
            $result = MainWP_Connect::fetch_url_authed(
                $website,
                'createBackupPoll',
                array(
                    'fileName'    => $fileName,
                    'fileNameUID' => $fileNameUID,
                    'type'        => $type,
                )
            );

            if ( ! isset( $result['size'] ) ) {
                throw new MainWP_Exception( esc_html__( 'Invalid response!', 'mainwp' ) );
            }

            if ( MainWP_Utility::ctype_digit( $result['size'] ) ) {
                $output = array( 'size' => $result['size'] );
            } else {
                $output = array();
            }
        } catch ( \Exception $e ) {
            $output = array( 'error' => $e->getMessage() );
        }
        // phpcs:enable

        die( wp_json_encode( $output ) );
    }

    /**
     * Method mainwp_backup_getfilesize()
     *
     * Get backup file size
     *
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites::backup_get_file_size()
     */
    public function mainwp_backup_getfilesize() {
        $this->secure_request( 'mainwp_backup_getfilesize' );
        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified by secure_request above.
        if ( null === $this->authorize_restore_site( $site_id ) ) {
            return;
        }

        try {
            $local = isset( $_POST['local'] ) ? sanitize_text_field( wp_unslash( $_POST['local'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( ! MainWP_Backup_File_Manager::is_within_site_backup_dir( $local, $site_id ) ) {
                die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup file location.', 'mainwp' ) ) ) );
            }
            die( wp_json_encode( array( 'result' => MainWP_Backup_File_Manager::backup_get_file_size( $local ) ) ) );
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
    }

    /*
     * Page: ManageBackups
     */

    /**
     * Method mainwp_addbackup()
     *
     * Add task to the database.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::add_backup()
     */
    public function mainwp_addbackup() {
        $this->secure_request( 'mainwp_addbackup' );

        MainWP_Manage_Backups_Handler::add_backup();
    }

    /**
     * Method mainwp_updatebackup()
     *
     * Update task.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::update_backup()
     */
    public function mainwp_updatebackup() {
        $this->secure_request( 'mainwp_updatebackup' );

        MainWP_Manage_Backups_Handler::update_backup();
    }

    /**
     * Method mainwp_removebackup()
     *
     * Remove a task from MainWP.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::remove_backup()
     */
    public function mainwp_removebackup() {
        $this->secure_request( 'mainwp_removebackup' );

        MainWP_Manage_Backups_Handler::remove_backup();
    }

    /**
     * Method mainwp_resumebackup()
     *
     * Resume backup task.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::resume_backup()
     */
    public function mainwp_resumebackup() {
        $this->secure_request( 'mainwp_resumebackup' );

        MainWP_Manage_Backups_Handler::resume_backup();
    }

    /**
     * Method mainwp_pausebackup()
     *
     * Pause backup task.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::pause_backup()
     */
    public function mainwp_pausebackup() {
        $this->secure_request( 'mainwp_pausebackup' );

        MainWP_Manage_Backups_Handler::pause_backup();
    }

    /**
     * Start a native run of a saved backup task.
     */
    public function mainwp_backuptask_run_now() {
        $this->secure_request( 'mainwp_backuptask_run_now' );
        $task_id = isset( $_POST['task_id'] ) ? (int) $_POST['task_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $result  = MainWP_Backup_Operations::run_task( $task_id );
        if ( is_wp_error( $result ) ) {
            $message = $result->get_error_message();
            if ( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND === $result->get_error_code() ) {
                $task    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
                $message = $task && empty( $task->template ) && MainWP_Manage_Backups_Handler::can_edit_backuptask( $task )
                    ? __( 'You are not allowed to run backups for one or more of this task\'s sites.', 'mainwp' )
                    : esc_html__( 'Insufficient permissions. Is this task set by you?', 'mainwp' );
            }
            wp_send_json( array( 'error' => $message ) );
            return;
        }
        wp_send_json( $result );
    }

    /**
     * Advance and report a native run of a saved backup task.
     *
     * A response can be lost, so retain the click baseline until the client sends
     * a positive started identity to acknowledge receipt; otherwise its TTL applies.
     */
    public function mainwp_backuptask_run_status() {
        $this->secure_request( 'mainwp_backuptask_run_status' );
        $task_id       = isset( $_POST['task_id'] ) ? (int) $_POST['task_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $started       = isset( $_POST['started'] ) ? (int) $_POST['started'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $click         = isset( $_POST['click'] ) ? (int) $_POST['click'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $initiation_id = isset( $_POST['initiation_id'] ) && is_string( $_POST['initiation_id'] ) ? wp_unslash( $_POST['initiation_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- UUID validation below rejects malformed tokens without altering their identity.
        $baseline_key  = 36 === strlen( $initiation_id ) && wp_is_uuid( $initiation_id, 4 ) ? 'mainwp_backup_click_' . $task_id . '_' . get_current_user_id() . '_' . $initiation_id : '';
        $stored        = '' !== $baseline_key ? get_transient( $baseline_key ) : false;
        $baseline      = is_array( $stored ) && ( $stored['click'] ?? null ) === $click ? (int) $stored['baseline'] : null;
        $response      = MainWP_Manage_Backups_Handler::get_backup_task_run_status( $task_id, $started, $click, false, $baseline );
        if ( '' !== $baseline_key && $started > 0 ) {
            delete_transient( $baseline_key );
        }
        wp_send_json( $response );
    }

    /**
     * Method mainwp_checkbackups()
     *
     * Check backup status.
     *
     * @throws \MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Updates_Overview::check_backups()
     */
    public function mainwp_checkbackups() {
        $this->secure_request( 'mainwp_checkbackups' );

        try {
            wp_send_json( array( 'result' => MainWP_Updates_Overview::check_backups() ) );
        } catch ( \Exception $e ) {
            die( wp_json_encode( array( 'error' => $e->getMessage() ) ) );
        }
    }
}
