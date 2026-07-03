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
        }
        if ( \mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
            $this->add_action( 'mainwp_backuptask_get_sites', array( &$this, 'mainwp_backuptask_get_sites' ) );
            $this->add_action( 'mainwp_backuptask_run_site', array( &$this, 'mainwp_backuptask_run_site' ) );
            $this->add_action( 'mainwp_backup_task_progress', array( &$this, 'mainwp_backup_task_progress' ) );
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
            $this->add_action( 'mainwp_backup_upload_getprogress', array( &$this, 'mainwp_backup_upload_getprogress' ) );
            $this->add_action( 'mainwp_backup_upload_checkstatus', array( &$this, 'mainwp_backup_upload_checkstatus' ) );
            $this->add_action( 'mainwp_backup_refresh_archives', array( &$this, 'mainwp_backup_refresh_archives' ) );
        }
        // Page: ManageBackupsSnapshots (restore).
        if ( \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            $this->add_action( 'mainwp_restore_snapshot_list', array( &$this, 'mainwp_restore_snapshot_list' ) );
            $this->add_action( 'mainwp_restore_initiate', array( &$this, 'mainwp_restore_initiate' ) );
            $this->add_action( 'mainwp_restore_progress', array( &$this, 'mainwp_restore_progress' ) );
            $this->add_action( 'mainwp_restore_cancel', array( &$this, 'mainwp_restore_cancel' ) );
            $this->add_action( 'mainwp_restore_rollback', array( &$this, 'mainwp_restore_rollback' ) );
            $this->add_action( 'mainwp_native_backup_initiate', array( &$this, 'mainwp_native_backup_initiate' ) );
            $this->add_action( 'mainwp_native_backup_progress', array( &$this, 'mainwp_native_backup_progress' ) );
            $this->add_action( 'mainwp_native_backup_cancel', array( &$this, 'mainwp_native_backup_cancel' ) );
            $this->add_action( 'mainwp_backup_diagnostics_export', array( &$this, 'mainwp_backup_diagnostics_export' ) );
        }
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
     * deletion path fires after the site row is gone.
     *
     * @param object $site Deleted site object.
     *
     * @return bool True when the cleanup ran, false on an empty site.
     */
    public function hook_delete_manual_backup_task( $site ) {
        if ( empty( $site ) || empty( $site->id ) ) {
            return false;
        }

        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site->id );
        if ( $task ) {
            MainWP_DB_Backup::instance()->remove_backup_task( (int) $task->id );
        }

        return true;
    }

    /**
     * Verify the current user may manage restores for a child site.
     *
     * The restore endpoints are gated by the `execute_backups` capability at
     * registration, but that does not scope access to individual sites. Without
     * a per-site check, a user could act on any site by passing its id. Sends a
     * JSON error and returns null when access is denied.
     *
     * @param int $site_id Child site id.
     *
     * @return object|null The website object when authorized, null otherwise.
     */
    private function authorize_restore_site( $site_id ) {
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( ! MainWP_System_Utility::can_edit_website( $website ) || ! \mainwp_current_user_can( 'site', $site_id ) ) {
            wp_send_json( array( 'error' => esc_html__( 'You are not allowed to manage backups for this site.', 'mainwp' ) ) );
            return null;
        }
        return $website;
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
        $destructive = null !== $snapshot && 'files' !== (string) $snapshot->type;
        return $destructive && ! $confirm_destructive;
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

        $entries   = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
        $multisite = MainWP_Backup_Monitor::child_is_multisite( $website );
        $rows      = array();
        foreach ( $entries as $entry ) {
            $archives   = isset( $entry['snapshot']->archives ) && is_array( $entry['snapshot']->archives ) ? $entry['snapshot']->archives : array();
            $size_bytes = (int) array_sum( array_column( $archives, 'size' ) );
            $rows[]     = array(
                'id'                 => (int) $entry['snapshot']->id,
                'task_id'            => (int) $entry['snapshot']->task_id,
                'type'               => (string) $entry['snapshot']->type,
                'created'            => (int) $entry['snapshot']->created,
                'age_days'           => max( 0, (int) floor( ( time() - (int) $entry['snapshot']->created ) / DAY_IN_SECONDS ) ),
                'status'             => (string) $entry['snapshot']->status,
                'offsite_state'      => isset( $entry['snapshot']->offsite_state ) ? (string) $entry['snapshot']->offsite_state : '',
                'source'             => (string) $entry['source'],
                'restorable'         => (bool) $entry['restorable'],
                'restore_reason'     => (string) $entry['restore_reason'],
                'size_label'         => $size_bytes > 0 ? MainWP_Utility::human_filesize( $size_bytes ) : '',
                'test_restore_state' => isset( $entry['snapshot']->test_restore_state ) ? (string) $entry['snapshot']->test_restore_state : '',
                'test_restore_at'    => isset( $entry['snapshot']->test_restore_at ) ? (int) $entry['snapshot']->test_restore_at : 0,
            );
        }
        // The reload bootstrap: the server knows what is running for this
        // site, so a freshly loaded page (or a site switch) resumes the
        // progress views instead of looking hung while a restore or an
        // on-demand backup carries on in the background.
        $active_job  = MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id );
        $manual_task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );

        wp_send_json(
            array(
                'snapshots'             => $rows,
                'multisite'             => $multisite,
                'site_has_woocommerce'  => MainWP_Utility::site_has_woocommerce( (int) $website->id ),
                'site_url'              => (string) $website->url,
                'local_only'            => empty( MainWP_DB_Backup::instance()->manual_backup_task_destinations( $site_id ) ),
                'active_restore_job_id' => null !== $active_job ? (int) $active_job->id : 0,
                'manual_backup_running' => ! empty( $manual_task ) && (int) $manual_task->completed < (int) $manual_task->last_run,
            )
        );
    }

    /**
     * Start a restore for a snapshot.
     */
    public function mainwp_restore_initiate() {
        $this->secure_request( 'mainwp_restore_initiate' );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by secure_request above.
        $site_id             = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
        $snapshot_id         = isset( $_POST['snapshot_id'] ) ? (int) $_POST['snapshot_id'] : 0;
        $pre_snapshot        = isset( $_POST['pre_snapshot'] ) && '1' === $_POST['pre_snapshot'];
        $confirm_destructive = isset( $_POST['confirm_destructive'] ) && '1' === $_POST['confirm_destructive'];
        $preserve_orders     = isset( $_POST['preserve_orders'] ) && '1' === $_POST['preserve_orders'];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $website = $this->authorize_restore_site( $site_id );
        if ( null === $website ) {
            return;
        }

        // Re-derive destructive risk server-side. The modal collects consent,
        // but a direct AJAX call can omit it, so a database-bearing restore is
        // refused here until confirm_destructive is set. The response carries
        // the reason (WooCommerce, backup age) for the modal to explain.
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        if ( self::restore_needs_confirmation( $snapshot, $confirm_destructive ) ) {
            wp_send_json(
                array(
                    'needs_confirmation'   => true,
                    'site_has_woocommerce' => MainWP_Utility::site_has_woocommerce( (int) $website->id ),
                    'age_days'             => max( 0, (int) floor( ( time() - (int) $snapshot->created ) / DAY_IN_SECONDS ) ),
                )
            );
            return;
        }

        // Order preservation applies only to a database-bearing restore; the
        // backup's created time bounds the customer capture on the child.
        $preserve_orders = $preserve_orders && null !== $snapshot && 'files' !== (string) $snapshot->type;
        $woo_order_since = $preserve_orders ? (int) $snapshot->created : 0;

        // A preserve-orders request the child's apply-phase gate is certain
        // to refuse gets refused here, before any job, pre-snapshot, or
        // transfer spends hours on it. Best effort: any doubt proceeds and
        // the apply-phase gate stays the authoritative backstop.
        if ( $preserve_orders ) {
            $refusal = MainWP_Backup_Restore_Orchestrator::instance()->woo_preserve_refusal( $website, $snapshot );
            if ( null !== $refusal ) {
                wp_send_json(
                    array(
                        'error'      => MainWP_Backup_Error_Codes::message( $refusal ),
                        'error_code' => $refusal,
                    )
                );
                return;
            }
        }

        try {
            $job_id = MainWP_Backup_Restore_Orchestrator::instance()->initiate_restore(
                $site_id,
                $snapshot_id,
                array(
                    'pre_snapshot'    => $pre_snapshot,
                    'file_rollback'   => $pre_snapshot,
                    'preserve_orders' => $preserve_orders,
                    'woo_order_since' => $woo_order_since,
                )
            );
        } catch ( MainWP_Exception $e ) {
            wp_send_json(
                array(
                    'error'      => $e->getMessage(),
                    'error_code' => $e->get_message_error_code(),
                )
            );
            return;
        }

        wp_send_json( array( 'job_id' => $job_id ) );
    }

    /**
     * Drive and report a restore job's progress.
     *
     * Each poll drives one short restore slice so an open tab advances the
     * run instead of waiting for cron; the cron backstop carries a closed
     * tab between polls. The job lock serializes the slice against a
     * concurrent cron run (a held lock makes the drive a no-op), and the
     * driver advances the next acquirable job - on a dashboard with one
     * active restore that is this one. The execute_backups capability was
     * verified by secure_request before any work runs.
     */
    public function mainwp_restore_progress() {
        $this->secure_request( 'mainwp_restore_progress' );
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );
        if ( null === $job ) {
            wp_send_json( array( 'error' => esc_html__( 'Restore job not found.', 'mainwp' ) ) );
            return;
        }

        if ( null === $this->authorize_restore_site( (int) $job->site_id ) ) {
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

        $transfer = isset( $job->checkpoint['transfer'] ) ? (array) $job->checkpoint['transfer'] : array();
        $plan     = isset( $job->checkpoint['plan'] ) ? (array) $job->checkpoint['plan'] : array();

        wp_send_json(
            array(
                'status'           => (string) $job->status,
                'last_error'       => (string) $job->last_error,
                'error_code'       => isset( $job->checkpoint['last_error_code'] ) ? (string) $job->checkpoint['last_error_code'] : '',
                'attempt_count'    => (int) $job->attempt_count,
                'next_retry_at'    => (int) $job->next_retry_at,
                'artifact_index'   => isset( $transfer['artifact_index'] ) ? (int) $transfer['artifact_index'] : 0,
                'artifact_total'   => count( $plan ),
                'has_rollback'     => ! empty( $job->checkpoint['pre_snapshot_artifacts'] ),
                'cancel_requested' => ! empty( $job->checkpoint['cancel_intent'] ),
                'verification'     => isset( $job->checkpoint['verification'] ) ? $job->checkpoint['verification'] : array(),
            )
        );
    }

    /**
     * Cancel a restore job while it is still safe.
     */
    public function mainwp_restore_cancel() {
        $this->secure_request( 'mainwp_restore_cancel' );
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );
        if ( null === $job ) {
            wp_send_json( array( 'error' => esc_html__( 'Restore job not found.', 'mainwp' ) ) );
            return;
        }

        if ( null === $this->authorize_restore_site( (int) $job->site_id ) ) {
            return;
        }

        try {
            $outcome = MainWP_Backup_Restore_Orchestrator::instance()->cancel_job( $job_id );
        } catch ( MainWP_Exception $e ) {
            wp_send_json(
                array(
                    'error'      => $e->getMessage(),
                    'error_code' => $e->get_message_error_code(),
                )
            );
            return;
        }
        wp_send_json( array( 'result' => 'cancel_pending' === $outcome ? 'pending' : 'ok' ) );
    }

    /**
     * Start a rollback restore from a failed job's safety backup.
     */
    public function mainwp_restore_rollback() {
        $this->secure_request( 'mainwp_restore_rollback' );
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );
        if ( null === $job ) {
            wp_send_json( array( 'error' => esc_html__( 'Restore job not found.', 'mainwp' ) ) );
            return;
        }

        if ( null === $this->authorize_restore_site( (int) $job->site_id ) ) {
            return;
        }

        try {
            $rollback_id = MainWP_Backup_Restore_Orchestrator::instance()->initiate_rollback( $job );
        } catch ( MainWP_Exception $e ) {
            wp_send_json(
                array(
                    'error'      => $e->getMessage(),
                    'error_code' => $e->get_message_error_code(),
                )
            );
            return;
        }
        wp_send_json( array( 'job_id' => $rollback_id ) );
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
        $site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            wp_send_json( array( 'error' => esc_html__( 'Native backups are not enabled.', 'mainwp' ) ) );
            return;
        }

        $website = $this->authorize_restore_site( $site_id );
        if ( null === $website ) {
            return;
        }

        // A restore in flight for this site holds the child's single-backup
        // slot; refuse until it finishes so the two heavy operations cannot
        // collide on the same child.
        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
            wp_send_json( array( 'error' => esc_html__( 'A restore is in progress for this site. Try again once it finishes.', 'mainwp' ) ) );
            return;
        }

        static::raise_backup_resource_limits();

        $task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
        if ( empty( $task ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Could not prepare the on-demand backup.', 'mainwp' ) ) );
            return;
        }

        // A run already in flight (last_run newer than completed): follow it
        // instead of resetting the run mid-flight.
        if ( (int) $task->completed < (int) $task->last_run ) {
            wp_send_json(
                array(
                    'job_id'  => (int) $task->id,
                    'started' => (int) $task->last_run,
                    'status'  => 'running',
                )
            );
            return;
        }

        // A run that closed successfully seconds ago makes this initiate a
        // duplicate of the click that just finished (a second tab, a repeat
        // click landing as the run closed). Absorb it: point the poll at the
        // run that produced the snapshot instead of reopening it, which would
        // fire a second preflight, orphan a child task, and poison the
        // original click's freshness check into a false failure.
        $settle = MainWP_Backup_Settings::get_manual_run_settle_seconds();
        if ( $settle > 0 && '' === (string) $task->backup_errors && (int) $task->completed > time() - $settle ) {
            /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
            do_action( 'mainwp_backup_diag_run_reinit', (int) $task->id, 'settle_absorbed', (int) $task->last_run, (int) $task->completed );
            wp_send_json(
                array(
                    'job_id'  => (int) $task->id,
                    'started' => (int) $task->last_run,
                    'status'  => 'queued',
                )
            );
            return;
        }

        // Kick the first step. updateRun = true resets completed_sites and
        // stamps last_run through a claim the settle window can refuse, which
        // both starts the run and makes it eligible for the
        // cron_backups_continue backstop. A false return means no concurrency
        // slot was free (or a racing initiate won the claim); the poll and
        // the cron retry it.
        $started  = time();
        $had_slot = ( false !== MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true, $settle ) );

        // last_run is now stamped, so the task is eligible for a continue.
        // Kick one immediately so a tab-closed run finishes within seconds
        // instead of waiting for the 5-minute recurring backup cron.
        static::kick_backup_continuation();

        // Re-read the row: a successful claim stamped last_run, and a racing
        // initiate that won the claim stamped its own. Keying the response's
        // started value to the row (when it advanced) makes the poll judge
        // freshness against the run that actually owns this backup; a slot
        // miss leaves the row untouched and keeps the click's own timestamp.
        $task        = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        $started_out = null !== $task && (int) $task->last_run >= $started ? (int) $task->last_run : $started;

        wp_send_json(
            array(
                'job_id'  => (int) $task->id,
                'started' => $started_out,
                'status'  => $had_slot ? 'running' : 'queued',
            )
        );
    }

    /**
     * Schedule an immediate backup continuation.
     *
     * Mirrors the restore orchestrator's near-term lane: a dedicated
     * single-event hook driven by spawn_cron, guarded so repeated kicks
     * collapse into one pending event. The hook reuses cron_backups_continue;
     * the recurring mainwp_cronbackups_continue_action cannot serve here
     * because WordPress de-duplicates a single event against the recurring
     * occurrence and spawn_cron will not run a future-dated recurring event
     * early. Under DISABLE_WP_CRON spawn_cron is a no-op and the 5-minute
     * recurring cron remains the backstop.
     *
     * @return void
     */
    protected static function kick_backup_continuation() {
        if ( ! wp_next_scheduled( 'mainwp_native_backup_continue_action' ) ) {
            wp_schedule_single_event( time(), 'mainwp_native_backup_continue_action' );
        }
        spawn_cron();
    }

    /**
     * Drive and report an on-demand native backup.
     *
     * Each poll pushes the run one child round-trip (the child advances on
     * its own; polling only checks phase) and reports running, completed, or
     * failed. On completion the caller refreshes the snapshot list.
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
        if ( (int) $task->completed < (int) $task->last_run ) {
            MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );
            $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        }

        if ( $task && (int) $task->completed < (int) $task->last_run ) {
            // Reuse the shared progress shape (label, percent, counters, bytes)
            // and pin the status to running: snapshot freshness, checked below,
            // is the authoritative completion gate, not the child phase.
            $progress           = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
            $response           = static::build_task_progress_response( $progress );
            $response['status'] = 'running';
            wp_send_json( $response );
            return;
        }

        // The run is closed: success when a snapshot fresh for THIS poll's
        // click landed, otherwise surface the recorded failure. The client
        // sends the started value the initiate handed it as `since`, so a
        // later re-init bumping last_run cannot poison the freshness check
        // of the click that produced the snapshot; without it (a tab on
        // older JavaScript) the comparison stays keyed to last_run.
        $errors = $task ? (string) $task->backup_errors : '';
        $latest = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
        $cutoff = $since > 0 ? $since : (int) $task->last_run;
        $fresh  = null !== $latest && (int) $latest->created >= $cutoff;

        // backup_errors clears at every run open, so it only ever describes
        // the run tied to the CURRENT last_run. With a snapshot, the errors
        // belong to the snapshot's own run only while last_run has not moved
        // past it; with none, they are this click's when the current run
        // opened at or after the click.
        $errors_own = null !== $latest
            ? (int) $task->last_run <= (int) $latest->created
            : ( 0 === $since || (int) $task->last_run >= $since );

        if ( $fresh && ( '' === $errors || ! $errors_own ) ) {
            $response = array(
                'status'      => 'completed',
                'snapshot_id' => (int) $latest->id,
            );
            // Advisory run notes (like the large-site ZIP auto-switch) ride
            // along so the page shows them with the success status instead of
            // mistaking them for a failure.
            $notices = $task ? (string) ( $task->backup_notices ?? '' ) : '';
            if ( '' !== $notices ) {
                $response['notice'] = static::humanize_backup_errors( $notices );
            }
            wp_send_json( $response );
            return;
        }

        wp_send_json(
            array(
                'status' => 'failed',
                'error'  => '' !== $errors && $errors_own ? static::humanize_backup_errors( $errors ) : esc_html__( 'The on-demand backup did not produce a snapshot.', 'mainwp' ),
            )
        );
    }

    /**
     * Cancel a stuck or unwanted native backup on a child.
     *
     * An interrupted backup leaves a non-terminal task on the child that blocks
     * every new backup, and on an idle child the watchdog cron may never fire to
     * clear it. This is the Dashboard escape hatch: it tells the child to cancel
     * its active backup (passing the known child task id, or letting the child
     * resolve its single active backup when the Dashboard no longer holds the
     * id), then stops treating the local manual run as in-flight so the UI
     * reflects the cancel at once.
     */
    public function mainwp_native_backup_cancel() {
        $this->secure_request( 'mainwp_native_backup_cancel' );
        $site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $website = $this->authorize_restore_site( $site_id );
        if ( null === $website ) {
            return;
        }

        // Pass the child task id when the Dashboard still has it (precise), and
        // fall back to the child resolving its single active backup otherwise.
        // A creating marker is not a task id the child knows: a cancel inside
        // the create window falls back to the child resolving it too.
        $child_task_id = '';
        $task          = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        if ( ! empty( $task ) ) {
            $progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
            if ( ! empty( $progress->child_backup_task_id ) && ! MainWP_Backup_Executor::is_creating_marker( $progress->child_backup_task_id ) ) {
                $child_task_id = (string) $progress->child_backup_task_id;
            }
        }

        try {
            $information = MainWP_Connect::fetch_url_authed( $website, 'backup_task_cancel', array( 'task_id' => $child_task_id ), false, false, false );
        } catch ( \Exception $e ) {
            wp_send_json( array( 'error' => $e->getMessage() ) );
            return;
        }

        if ( isset( $information['error'] ) ) {
            wp_send_json( array( 'error' => is_string( $information['error'] ) ? $information['error'] : esc_html__( 'The child site could not cancel the backup.', 'mainwp' ) ) );
            return;
        }

        // Close the local manual run so the next poll does not re-report it as
        // running; the child task is already terminal. The cancelled note and
        // log line are the diagnostic trace: without them a cancelled run is
        // indistinguishable from an ordinary completion later.
        MainWP_Logger::instance()->debug_for_website(
            $website,
            'backup',
            'Backup cancelled from the dashboard (child task: ' . ( '' !== $child_task_id ? $child_task_id : 'auto-resolved' ) . ')'
        );
        if ( ! empty( $task ) ) {
            MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'completed' => time() ) );
            MainWP_DB_Backup::instance()->update_backup_notices( (int) $task->id, esc_html__( 'Backup cancelled from the dashboard.', 'mainwp' ) . "\n" );
        }

        wp_send_json(
            array(
                'status' => 'cancelled',
                'result' => isset( $information['result'] ) ? $information['result'] : 'ok',
            )
        );
    }

    /**
     * Build the diagnostics export bundle and hand back a signed download
     * URL served by the existing chunked mwpdl path, so no new
     * file-serving code is involved.
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

        $mwp_dir = MainWP_System_Utility::get_mainwp_dir();
        $mwp_dir = $mwp_dir[0];

        wp_send_json(
            array(
                'url' => admin_url( '?sig=' . MainWP_System_Utility::get_download_sig( $bundle ) . '&mwpdl=' . rawurlencode( str_replace( $mwp_dir, '', $bundle ) ) ),
            )
        );
    }

    /**
     * Flatten the task's stored HTML backup_errors into a readable single
     * line. The errors are stored as an HTML list, so block boundaries are
     * turned into spaces before the tags are stripped; without this the
     * site name and the message run together (e.g. "child6-4.localRequired").
     *
     * @param string $errors Stored backup_errors HTML.
     *
     * @return string A flattened, space-separated message.
     */
    protected static function humanize_backup_errors( $errors ) {
        $spaced = str_replace( array( '<br>', '<br/>', '<br />', '</li>', '<li>', '</ol>', '<ol>' ), ' ', (string) $errors );
        return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $spaced ) ) );
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
     * Method mainwp_backup_task_progress()
     *
     * Report the recorded progress of a running scheduled backup for one
     * site from the local progress row - no child round trip.
     *
     * @return void
     */
    public function mainwp_backup_task_progress() {
        $this->secure_request( 'mainwp_backup_task_progress' );
        $task_id = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified by secure_request.
        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified by secure_request.

        if ( empty( $task_id ) || empty( $site_id ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Invalid request. Please reload the page and try again.', 'mainwp' ) ) );
        }

        $row = MainWP_DB_Backup::instance()->get_backup_task_progress( $task_id, $site_id );

        wp_send_json( array( 'result' => static::build_task_progress_response( $row ) ) );
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
        if ( MainWP_Backup_Task_Phase::Completed === $phase ) {
            $status = 'completed';
        } elseif ( MainWP_Backup_Task_Phase::Failed === $phase ) {
            $status = 'failed';
        }

        if ( null !== $phase ) {
            $label = $phase->label();
        } elseif ( '' === (string) $row->child_phase ) {
            $label = MainWP_Backup_Task_Phase::Queued->label();
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
        $file_ratio_phase = MainWP_Backup_Task_Phase::DumpingDb === $phase
            || MainWP_Backup_Task_Phase::ArchivingFiles === $phase;

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

            $ret = array( 'result' => MainWP_Backup_Executor::backup( $site_id, 'full', '', '', 1, 1, 1, 1 ) );
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

            $result = MainWP_Backup_Executor::backup( $site_id, $type, $subfolder, $excludedFolder, $excludebackup, $excludecache, $excludenonwp, $excludezip, $filename, $fileNameUID, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip, $pid, $append );
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
            $pid       = isset( $_POST['pid'] ) ? intval( $_POST['pid'] ) : false;
            $type      = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
            $subfolder = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';
            $filename  = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
            wp_send_json( MainWP_Backup_Monitor::backup_check_pid( $site_id, $pid, $type, $subfolder, $filename ) );
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
            $type  = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
            $url   = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
            $local = isset( $_POST['local'] ) ? sanitize_text_field( wp_unslash( $_POST['local'] ) ) : '';
            die( wp_json_encode( array( 'result' => MainWP_Backup_File_Manager::backup_download_file( $site_id, $type, $url, $local ) ) ) );
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
        $this->secure_request( 'mainwp_backup_delete_file' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : false;
        try {
            if ( ! $site_id ) {
                throw new MainWP_Exception( esc_html__( 'Site ID not found. Please reload the page and try again.', 'mainwp' ) );
            }

            $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : 0;
            $file    = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';

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

            $website = MainWP_DB::instance()->get_website_by_id( $siteId );
            if ( ! $website ) {
                throw new MainWP_Exception( esc_html__( 'No site selected!', 'mainwp' ) );
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

        try {
            $local = isset( $_POST['local'] ) ? sanitize_text_field( wp_unslash( $_POST['local'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            die( wp_json_encode( array( 'result' => MainWP_Backup_File_Manager::backup_get_file_size( $local ) ) ) );
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
    }

    /**
     * Method mainwp_backup_upload_checkstatus()
     *
     * Check upload status
     *
     * @throws MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Exception
     */
    public function mainwp_backup_upload_checkstatus() {
        $this->secure_request( 'mainwp_backup_upload_checkstatus' );

        try {
            $unique = isset( $_POST['unique'] ) ? sanitize_text_field( wp_unslash( $_POST['unique'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $array  = get_option( 'mainwp_upload_progress' );
            $info   = apply_filters( 'mainwp_remote_destination_info', array(), ( isset( $_POST['remote_destination'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_destination'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

            if ( ! is_array( $array ) || empty( $unique ) || empty( $array[ $unique ] ) || empty( $array[ $unique ]['dts'] ) ) {
                die(
                    wp_json_encode(
                        array(
                            'status' => 'stalled',
                            'info'   => $info,
                        )
                    )
                );
            } elseif ( isset( $array[ $unique ]['finished'] ) ) {
                die(
                    wp_json_encode(
                        array(
                            'status' => 'done',
                            'info'   => $info,
                        )
                    )
                );
            } elseif ( isset( $array[ $unique ]['dts'] ) && intval( $array[ $unique ]['dts'] ) < ( time() - ( 2 * 60 ) ) ) {

                // 2minutes.
                    die(
                        wp_json_encode(
                            array(
                                'status' => 'stalled',
                                'info'   => $info,
                            )
                        )
                    );
            } else {
                die(
                    wp_json_encode(
                        array(
                            'status' => 'busy',
                            'info'   => $info,
                        )
                    )
                );
            }
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
    }

    /**
     * Method mainwp_backup_upload_getprogress()
     *
     * Get progress status.
     *
     * @throws MainWP_Exception On finished or errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Exception
     */
    public function mainwp_backup_upload_getprogress() {
        $this->secure_request( 'mainwp_backup_upload_getprogress' );

        try {
            $array  = get_option( 'mainwp_upload_progress' );
            $unique = isset( $_POST['unique'] ) ? sanitize_text_field( wp_unslash( $_POST['unique'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

            if ( ! is_array( $array ) || ! isset( $array[ $unique ] ) ) {
                die( wp_json_encode( array( 'result' => 0 ) ) );
            } elseif ( isset( $array[ $unique ]['finished'] ) ) {
                throw new MainWP_Exception( esc_html__( 'finished...', 'mainwp' ) );
            } else {
                wp_send_json( array( 'result' => ( isset( $array[ $unique ]['offset'] ) ? $array[ $unique ]['offset'] : $array[ $unique ] ) ) );
            }
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
     * Method mainwp_backuptask_get_sites()
     *
     * Get sites of backup task.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::get_backup_task_sites()
     */
    public function mainwp_backuptask_get_sites() {
        $this->secure_request( 'mainwp_backuptask_get_sites' );

        $taskID = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        wp_send_json( array( 'result' => MainWP_Manage_Backups_Handler::get_backup_task_sites( $taskID ) ) );
    }

    /**
     * Method mainwp_backuptask_run_site()
     *
     * Run backup task of site.
     *
     * @throws MainWP_Exception On errors.
     *
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::backup()
     */
    public function mainwp_backuptask_run_site() {
        try {
            $this->secure_request( 'mainwp_backuptask_run_site' );
            $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $task_id = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( ! $site_id || ! $task_id ) {
                throw new MainWP_Exception( esc_html__( 'Site ID or backup task ID not found. Please reload the page and try again.', 'mainwp' ) );
            }

            $fileNameUID = isset( $_POST['fileNameUID'] ) ? sanitize_text_field( wp_unslash( $_POST['fileNameUID'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            wp_send_json( array( 'result' => MainWP_Manage_Backups_Handler::backup( $task_id, $site_id, $fileNameUID ) ) );
        } catch ( MainWP_Exception $e ) {
            $this->die_exception_error( $e );
        }
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
