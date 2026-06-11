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
        }
        // Page: ManageBackupsSnapshots (restore).
        if ( \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            $this->add_action( 'mainwp_restore_snapshot_list', array( &$this, 'mainwp_restore_snapshot_list' ) );
            $this->add_action( 'mainwp_restore_initiate', array( &$this, 'mainwp_restore_initiate' ) );
            $this->add_action( 'mainwp_restore_progress', array( &$this, 'mainwp_restore_progress' ) );
            $this->add_action( 'mainwp_restore_cancel', array( &$this, 'mainwp_restore_cancel' ) );
            $this->add_action( 'mainwp_restore_rollback', array( &$this, 'mainwp_restore_rollback' ) );
        }
        // Intentionally not capability-gated: the backup-before-update check is
        // read-only and used by the updates flow, where a user without backup
        // rights still needs it.
        $this->add_action( 'mainwp_checkbackups', array( &$this, 'mainwp_checkbackups' ) );
    }

    /**
     * List a site's snapshots with restore availability.
     */
    public function mainwp_restore_snapshot_list() {
        $this->secure_request( 'mainwp_restore_snapshot_list' );
        $site_id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $entries = MainWP_Backup_Snapshot_Catalog::instance()->list_snapshots_for_site( $site_id );
        $rows    = array();
        foreach ( $entries as $entry ) {
            $rows[] = array(
                'id'         => (int) $entry['snapshot']->id,
                'task_id'    => (int) $entry['snapshot']->task_id,
                'type'       => (string) $entry['snapshot']->type,
                'created'    => (int) $entry['snapshot']->created,
                'status'     => (string) $entry['snapshot']->status,
                'source'     => (string) $entry['source'],
                'restorable' => (bool) $entry['restorable'],
            );
        }
        wp_send_json( array( 'snapshots' => $rows ) );
    }

    /**
     * Start a restore for a snapshot.
     */
    public function mainwp_restore_initiate() {
        $this->secure_request( 'mainwp_restore_initiate' );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by secure_request above.
        $site_id      = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
        $snapshot_id  = isset( $_POST['snapshot_id'] ) ? (int) $_POST['snapshot_id'] : 0;
        $pre_snapshot = isset( $_POST['pre_snapshot'] ) && '1' === $_POST['pre_snapshot'];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        try {
            $job_id = MainWP_Backup_Restore_Orchestrator::instance()->initiate_restore(
                $site_id,
                $snapshot_id,
                array(
                    'pre_snapshot'  => $pre_snapshot,
                    'file_rollback' => $pre_snapshot,
                )
            );
        } catch ( MainWP_Exception $e ) {
            wp_send_json( array( 'error' => $e->getMessage() ) );
            return;
        }

        wp_send_json( array( 'job_id' => $job_id ) );
    }

    /**
     * Report a restore job's progress.
     */
    public function mainwp_restore_progress() {
        $this->secure_request( 'mainwp_restore_progress' );
        $job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by secure_request above.

        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $job_id );
        if ( null === $job ) {
            wp_send_json( array( 'error' => esc_html__( 'Restore job not found.', 'mainwp' ) ) );
            return;
        }

        $transfer = isset( $job->checkpoint['transfer'] ) ? (array) $job->checkpoint['transfer'] : array();
        $plan     = isset( $job->checkpoint['plan'] ) ? (array) $job->checkpoint['plan'] : array();

        wp_send_json(
            array(
                'status'         => (string) $job->status,
                'last_error'     => (string) $job->last_error,
                'attempt_count'  => (int) $job->attempt_count,
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

        try {
            $outcome = MainWP_Backup_Restore_Orchestrator::instance()->cancel_job( $job_id );
        } catch ( MainWP_Exception $e ) {
            wp_send_json( array( 'error' => $e->getMessage() ) );
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

        try {
            $rollback_id = MainWP_Backup_Restore_Orchestrator::instance()->initiate_rollback( $job );
        } catch ( MainWP_Exception $e ) {
            wp_send_json( array( 'error' => $e->getMessage() ) );
            return;
        }
        wp_send_json( array( 'job_id' => $rollback_id ) );
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

        $percent = null;
        if ( $files_total > 0 ) {
            $percent = (int) round( min( 100, $files_processed * 100 / $files_total ) );
        } elseif ( $bytes_expected > 0 ) {
            $percent = (int) round( min( 100, $bytes_done * 100 / $bytes_expected ) );
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

            $type                           = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
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
