<?php
/**
 * MainWP Legacy Backups Handler.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Manage_Backups_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Backups_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Instance variable.
     *
     * @var null $instance.
     */
    private static $instance = null;

    /**
     * Create instance.
     *
     * @return self $instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Validate Backup Tasks.
     *
     * @param array $pBackupTasks Backup tasks.
     *
     * @return bool true|false.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     */
    public static function validate_backup_tasks( $pBackupTasks ) {  //phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $pBackupTasks ) ) {
            return true;
        }

        $nothingChanged = true;
        foreach ( $pBackupTasks as $backupTask ) {
            if ( '' === $backupTask->groups ) {
                $newSiteIds = '';
                $siteIds    = ( '' === $backupTask->sites ? array() : explode( ',', $backupTask->sites ) );
                foreach ( $siteIds as $siteId ) {
                    $site = MainWP_DB::instance()->get_website_by_id( $siteId );
                    if ( ! empty( $site ) ) {
                        $newSiteIds .= ',' . $siteId;
                    }
                }

                $newSiteIds = trim( $newSiteIds, ',' );

                if ( $newSiteIds !== $backupTask->sites ) {
                    $nothingChanged = false;
                    MainWP_DB_Backup::instance()->update_backup_task_with_values( $backupTask->id, array( 'sites' => $newSiteIds ) );
                }
            } else {
                $newGroupIds = '';
                $groupIds    = explode( ',', $backupTask->groups );
                foreach ( $groupIds as $groupId ) {
                    $group = MainWP_DB_Common::instance()->get_group_by_id( $groupId );
                    if ( ! empty( $group ) ) {
                        $newGroupIds .= ',' . $groupId;
                    }
                }
                $newGroupIds = trim( $newGroupIds, ',' );

                if ( $newGroupIds !== $backupTask->groups ) {
                    $nothingChanged = false;
                    MainWP_DB_Backup::instance()->update_backup_task_with_values( $backupTask->id, array( 'groups' => $newGroupIds ) );
                }
            }
        }

        return $nothingChanged;
    }

    /**
     * Can Edit Backup Task.
     *
     * @param object $task Backup task.
     *
     * @return bool true|false.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
     */
    public static function can_edit_backuptask( &$task ) {
        if ( null === $task ) {
            return false;
        }

        if ( MainWP_System::instance()->is_single_user() ) {
            return true;
        }

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        return $task->userid === $current_user->ID;
    }

    /**
     * Update backup task.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::add_backup_task()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task()
     */
    public static function update_backup() {  //phpcs:ignore -- NOSONAR - complex.

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        //phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified.
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        if ( '' === $name ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' ) ) ) );
        }

        $backupId = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $task     = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupId );

        if ( ! static::can_edit_backuptask( $task ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) ) ) );
        }

        $schedule = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
        $type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

        if ( ! in_array( $type, array( 'full', 'db', 'incremental', 'files' ), true ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup type. Select Full, Database, Incremental, or Files Only and try again.', 'mainwp' ) ) ) );
        }

        $excludedFolder = isset( $_POST['exclude'] ) ? trim( wp_unslash( $_POST['exclude'] ), "\n" ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ok.
        $excludedFolder = explode( "\n", $excludedFolder );
        $excludedFolder = array_map( array( MainWP_Utility::get_class_name(), 'trim_slashes' ), $excludedFolder );
        $excludedFolder = array_map( 'htmlentities', $excludedFolder );
        $excludedFolder = MainWP_Backup_Settings::sanitize_exclude_list( $excludedFolder );
        $sites          = '';
        $groups         = '';

        if ( isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ) {
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) as $site ) {
                if ( '' !== $sites ) {
                    $sites .= ',';
                }
                $sites .= $site;
            }
        }

        if ( isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ) {
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) as $group ) {
                if ( '' !== $groups ) {
                    $groups .= ',';
                }
                $groups .= $group;
            }
        }

        do_action( 'mainwp_update_backuptask', $task->id );

        $archiveFormat                  = isset( $_POST['archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['archiveFormat'] ) ) : 'site';
        $maximumFileDescriptorsOverride = isset( $_POST['maximumFileDescriptorsOverride'] ) && 1 === (int) $_POST['maximumFileDescriptorsOverride'];
        $maximumFileDescriptorsAuto     = isset( $_POST['maximumFileDescriptorsAuto'] ) && 1 === (int) $_POST['maximumFileDescriptorsAuto'];
        $maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) ? intval( $_POST['maximumFileDescriptors'] ) : 150;
        $loadFilesBeforeZip             = isset( $_POST['loadFilesBeforeZip'] ) ? 1 : 0;

        $subfolder     = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';
        $filename      = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        $excludebackup = isset( $_POST['excludebackup'] ) ? sanitize_text_field( wp_unslash( $_POST['excludebackup'] ) ) : '';
        $excludecache  = isset( $_POST['excludecache'] ) ? sanitize_text_field( wp_unslash( $_POST['excludecache'] ) ) : '';
        $excludenonwp  = isset( $_POST['excludenonwp'] ) ? sanitize_text_field( wp_unslash( $_POST['excludenonwp'] ) ) : '';
        $excludezip    = isset( $_POST['excludezip'] ) ? sanitize_text_field( wp_unslash( $_POST['excludezip'] ) ) : '';

        //phpcs:enable.Missing

        if ( MainWP_DB_Backup::instance()->update_backup_task( $task->id, $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, $subfolder, $filename, $excludebackup, $excludecache, $excludenonwp, $excludezip, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip ) === false ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Could not save the backup task. Please check that all required fields are filled in and try again. If the issue persists, check your server error log.', 'mainwp' ) ) ) );
        } else {
            $destinations = isset( $_POST['destinations'] ) && is_array( $_POST['destinations'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['destinations'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified.
            MainWP_Backup_Storage_Handler::save_task_destinations( $task->id, $destinations );
            static::save_task_schedule_fields( $task->id );
            static::save_task_retention_fields( $task->id );
            die( wp_json_encode( array( 'result' => esc_html__( 'Task updated successfully.', 'mainwp' ) ) ) );
        }
    }

    /** Add backup task. */
    public static function add_backup() {  //phpcs:ignore -- NOSONAR - complex.

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        //phpcs:disable WordPress.Security.NonceVerification -- ok.
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        if ( empty( $name ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' ) ) ) );
        }

        $schedule = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
        $type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

        if ( ! in_array( $type, array( 'full', 'db', 'incremental', 'files' ), true ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid backup type. Select Full, Database, Incremental, or Files Only and try again.', 'mainwp' ) ) ) );
        }

        $excludedFolder = isset( $_POST['exclude'] ) ? trim( wp_unslash( $_POST['exclude'] ), "\n" ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ok.
        $excludedFolder = explode( "\n", $excludedFolder );
        $excludedFolder = array_map( array( MainWP_Utility::get_class_name(), 'trim_slashes' ), $excludedFolder );
        $excludedFolder = array_map( 'htmlentities', $excludedFolder );
        $excludedFolder = MainWP_Backup_Settings::sanitize_exclude_list( $excludedFolder );

        $sites  = '';
        $groups = '';

        if ( isset( $_POST['sites'] ) ) {
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) as $site ) {
                if ( '' !== $sites ) {
                    $sites .= ',';
                }
                $sites .= $site;
            }
        }

        if ( isset( $_POST['groups'] ) ) {
            foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) as $group ) {
                if ( '' !== $groups ) {
                    $groups .= ',';
                }
                $groups .= $group;
            }
        }

        $archiveFormat                  = isset( $_POST['archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['archiveFormat'] ) ) : 'site';
        $maximumFileDescriptorsOverride = isset( $_POST['maximumFileDescriptorsOverride'] ) && 1 === (int) $_POST['maximumFileDescriptorsOverride'];
        $maximumFileDescriptorsAuto     = isset( $_POST['maximumFileDescriptorsAuto'] ) && 1 === (int) $_POST['maximumFileDescriptorsAuto'];
        $maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) ? intval( $_POST['maximumFileDescriptors'] ) : 150;
        $loadFilesBeforeZip             = isset( $_POST['loadFilesBeforeZip'] ) ? 1 : 0;

        $subfolder     = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';
        $filename      = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        $excludebackup = isset( $_POST['excludebackup'] ) ? sanitize_text_field( wp_unslash( $_POST['excludebackup'] ) ) : '';
        $excludecache  = isset( $_POST['excludecache'] ) ? sanitize_text_field( wp_unslash( $_POST['excludecache'] ) ) : '';
        $excludenonwp  = isset( $_POST['excludenonwp'] ) ? sanitize_text_field( wp_unslash( $_POST['excludenonwp'] ) ) : '';
        $excludezip    = isset( $_POST['excludezip'] ) ? sanitize_text_field( wp_unslash( $_POST['excludezip'] ) ) : '';
        //phpcs:enable

        $task = MainWP_DB_Backup::instance()->add_backup_task( $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, $subfolder, $filename, 0, $excludebackup, $excludecache, $excludenonwp, $excludezip, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip );

        if ( ! $task ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Could not save the backup task. Please check that all required fields are filled in and try again. If the issue persists, check your server error log.', 'mainwp' ) ) ) );
        } else {
            $destinations = isset( $_POST['destinations'] ) && is_array( $_POST['destinations'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['destinations'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified.
            MainWP_Backup_Storage_Handler::save_task_destinations( $task->id, $destinations );
            static::save_task_schedule_fields( $task->id );
            static::save_task_retention_fields( $task->id );

            do_action( 'mainwp_add_backuptask', $task->id );

            die( wp_json_encode( array( 'result' => esc_html__( 'Task created successfully.', 'mainwp' ) ) ) );
        }
    }

    /**
     * Validate and save the schedule fields posted with a task, and store
     * the computed next_run.
     *
     * Reads schedule_interval_hours, schedule_time_of_day,
     * schedule_day_of_week and schedule_day_of_month from the request the
     * caller already nonce-verified. Interval hours only apply to interval
     * tasks; a time of day only applies to daily, weekly and monthly tasks
     * and must match HH:MM. A task without either drops back to legacy
     * elapsed mode with next_run = 0.
     *
     * @param int $task_id Task id, already saved with its schedule.
     *
     * @return array The saved column values.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Schedule_Calculator::compute_next_run()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function save_task_schedule_fields( $task_id ) {
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        if ( empty( $task ) ) {
            return array();
        }

        //phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified.
        $interval_hours = isset( $_POST['schedule_interval_hours'] ) ? absint( $_POST['schedule_interval_hours'] ) : 0;
        $time_raw       = isset( $_POST['schedule_time_of_day'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_time_of_day'] ) ) : '';
        $day_of_week    = isset( $_POST['schedule_day_of_week'] ) ? min( 6, absint( $_POST['schedule_day_of_week'] ) ) : 0;
        $day_of_month   = isset( $_POST['schedule_day_of_month'] ) ? max( 1, min( 28, absint( $_POST['schedule_day_of_month'] ) ) ) : 1;
        //phpcs:enable

        $interval_hours = 'interval' === $task->schedule ? max( 1, min( 8760, $interval_hours ) ) : 0;

        $time_of_day = '';
        if ( in_array( $task->schedule, array( 'daily', 'weekly', 'monthly' ), true ) && preg_match( '/^(\d{1,2}):(\d{1,2})$/', $time_raw, $matches ) ) {
            $hour   = (int) $matches[1];
            $minute = (int) $matches[2];
            if ( $hour <= 23 && $minute <= 59 ) {
                $time_of_day = sprintf( '%02d:%02d', $hour, $minute );
            }
        }

        $values = array(
            'interval_hours' => $interval_hours,
            'time_of_day'    => $time_of_day,
            'day_of_week'    => $day_of_week,
            'day_of_month'   => $day_of_month,
        );

        $sched_task = (object) array_merge( array( 'schedule' => $task->schedule ), $values );

        $calculator         = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
        $values['next_run'] = $calculator->compute_next_run( $sched_task, empty( $task->last_run ) ? null : (int) $task->last_run );

        MainWP_DB_Backup::instance()->update_backup_task_with_values( $task_id, $values );

        return $values;
    }

    /**
     * Save the retention policy fields posted with a task. Zero means no
     * limit; absent or junk values save as zero, and the cloud copies
     * toggle persists explicitly so unchecking it sticks.
     *
     * @param int $task_id Task id.
     *
     * @return array The saved column values.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function save_task_retention_fields( $task_id ) {
        //phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified.
        $values = array(
            'retention_count'      => isset( $_POST['retention_count'] ) ? absint( $_POST['retention_count'] ) : 0,
            'retention_days'       => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 0,
            'retention_storage_mb' => isset( $_POST['retention_storage_mb'] ) ? absint( $_POST['retention_storage_mb'] ) : 0,
            'delete_cloud_copies'  => empty( $_POST['delete_cloud_copies'] ) ? 0 : 1,
            'max_chain_length'     => isset( $_POST['max_chain_length'] ) ? absint( $_POST['max_chain_length'] ) : 0,
        );
        //phpcs:enable

        MainWP_DB_Backup::instance()->update_backup_task_with_values( $task_id, $values );

        return $values;
    }

    /**
     * Execute the backup task.
     *
     * @param mixed   $task Task to perform.
     * @param integer $nrOfSites Number of Child Sites to perform the task on.
     * @param bool    $updateRun ture|false.
     *
     * @return mixed $errorOutput.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Executor::backup_site()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_run()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_completed_sites()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_errors()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_completed()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_notification_email()
     * @uses \MainWP\Dashboard\MainWP_Format::format_email()
     * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_group_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize()
     */
    public static function execute_backup_task( $task, $nrOfSites = 0, $updateRun = true ) { // phpcs:ignore -- NOSONAR - not quite complex function.

        // Cap concurrent backup tasks; a refused task stays queued for the next cron cycle.
        $slot_token = MainWP_Backup_Monitor::acquire_backup_slot( MainWP_Backup_Settings::get_max_concurrent_backups() );
        if ( false === $slot_token ) {
            return false;
        }

        try {
            return static::execute_backup_task_run( $task, $nrOfSites, $updateRun );
        } finally {
            MainWP_Backup_Monitor::release_backup_slot( $slot_token );
        }
    }

    /**
     * Run the backup task once a concurrency slot is held.
     *
     * @param mixed   $task Task to perform.
     * @param integer $nrOfSites Number of Child Sites to perform the task on.
     * @param bool    $updateRun ture|false.
     *
     * @return mixed $errorOutput.
     */
    private static function execute_backup_task_run( $task, $nrOfSites = 0, $updateRun = true ) { // phpcs:ignore -- NOSONAR - not quite complex function.

        if ( $updateRun ) {
            MainWP_DB_Backup::instance()->update_backup_run( $task->id );
        }

        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

        $completed_sites = $task->completed_sites;

        if ( '' !== $completed_sites ) {
            $completed_sites = json_decode( $completed_sites, true );
        }

        if ( ! is_array( $completed_sites ) ) {
            $completed_sites = array();
        }

        $sites = array();

        if ( empty( $task->groups ) ) {
            if ( '' !== $task->sites ) {
                $sites = explode( ',', $task->sites );
            }
        } else {
            $groups = explode( ',', $task->groups );
            foreach ( $groups as $groupid ) {
                $group_sites = MainWP_DB::instance()->get_websites_by_group_id( $groupid );
                foreach ( $group_sites as $group_site ) {
                    if ( in_array( $group_site->id, $sites ) ) {
                        continue;
                    }
                    $sites[] = $group_site->id;
                }
            }
        }
        $errorOutput  = null;
        $errorEntries = array();

        $lastStartNotification = $task->lastStartNotificationSent;
        if ( $updateRun && ( 1 === get_option( 'mainwp_notificationOnBackupStart' ) ) && ( $lastStartNotification < $task->last_run ) ) {
            $email = MainWP_DB_Common::instance()->get_user_notification_email( $task->userid );
            if ( '' !== $email ) {
                $output = 'A scheduled backup has started with MainWP on ' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp() ) . ' for the following ' . count( $sites ) . ' sites:<br />';

                foreach ( $sites as $siteid ) {
                    $website = MainWP_DB::instance()->get_website_by_id( $siteid );
                    $output .= '&nbsp;&bull;&nbsp;<a href="' . $website->url . '">' . MainWP_Utility::get_nice_url( $website->url ) . '</a><br />';
                }

                $output .= '<br />Backup details:<br /><br />';
                $output .= '<strong>Backup task</strong> - ' . $task->name . '<br />';
                if ( 'db' === $task->type ) {
                    $type_label = 'DATABASE BACKUP';
                } elseif ( 'files' === $task->type ) {
                    $type_label = 'FILES BACKUP';
                } else {
                    $type_label = 'FULL BACKUP';
                }
                $output .= '<strong>Backup type</strong> - ' . $type_label . '<br />';
                $output .= '<strong>Backup schedule</strong> - ' . strtoupper( $task->schedule ) . '<br />';

                $mail_title       = 'A Scheduled Backup has been Started - MainWP';
                $formated_content = MainWP_Format::format_email( $email, $output, $mail_title );
                wp_mail( $email, $mail_title, $formated_content, 'content-type: text/html' );
                MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'lastStartNotificationSent' => time() ) );
            }
        }

        $currentCount = 0;

        foreach ( $sites as $siteid ) {
            if ( isset( $completed_sites[ $siteid ] ) && ( true === $completed_sites[ $siteid ] ) ) {
                continue;
            }
            $website = MainWP_DB::instance()->get_website_by_id( $siteid );

            try {
                $subfolder = str_replace( '%task%', MainWP_Utility::sanitize( $task->name ), $task->subfolder );

                $backupResult = MainWP_Backup_Executor::backup_site( $siteid, $task, $subfolder );

                if ( false === $backupResult ) {
                    continue;
                }

                if ( null === $errorOutput ) {
                    $errorOutput = '';
                }
                $error          = false;
                $tmpErrorOutput = '';
                if ( isset( $backupResult['error'] ) ) {
                    $tmpErrorOutput .= $backupResult['error'] . '<br />';
                    $error           = true;
                }
                if ( isset( $backupResult['ftp'] ) && 'success' !== $backupResult['ftp'] ) {
                    $tmpErrorOutput .= 'FTP: ' . $backupResult['ftp'] . '<br />';
                    $error           = true;
                }
                if ( isset( $backupResult['dropbox'] ) && 'success' !== $backupResult['dropbox'] ) {
                    $tmpErrorOutput .= 'Dropbox: ' . $backupResult['dropbox'] . '<br />';
                    $error           = true;
                }

                if ( isset( $backupResult['amazon'] ) && 'success' !== $backupResult['amazon'] ) {
                    $tmpErrorOutput .= 'Amazon: ' . $backupResult['amazon'] . '<br />';
                    $error           = true;
                }

                if ( $error ) {
                    $errorOutput   .= 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />';
                    $errorOutput   .= $tmpErrorOutput . '<br />';
                    $errorEntries[] = 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />' . $tmpErrorOutput;
                }
            } catch ( \Exception $e ) {
                if ( null === $errorOutput ) {
                    $errorOutput = '';
                }
                $errorOutput   .= 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />';
                $errorOutput   .= MainWP_Error_Helper::get_error_message( $e ) . '<br />';
                $_error_output  = MainWP_Error_Helper::get_error_message( $e );
                $errorEntries[] = 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />' . $_error_output;
            }

            $_backup_result = isset( $backupResult ) ? $backupResult : ( isset( $_error_output ) ? $_error_output : '' );
            do_action( 'mainwp_managesite_schedule_backup', $website, array( 'type' => $task->type ), $_backup_result );

            ++$currentCount;

            $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

            $completed_sites = $task->completed_sites;

            if ( '' !== $completed_sites ) {
                $completed_sites = json_decode( $completed_sites, true );
            }
            if ( ! is_array( $completed_sites ) ) {
                $completed_sites = array();
            }

            $completed_sites[ $siteid ] = true;
            MainWP_DB_Backup::instance()->update_completed_sites( $task->id, $completed_sites );

            if ( ( 0 !== $nrOfSites ) && ( $nrOfSites <= $currentCount ) ) {
                break;
            }
        }

        if ( null !== $errorOutput ) {
            MainWP_DB_Backup::instance()->update_backup_errors( $task->id, MainWP_Backup_Monitor::format_backup_errors( $errorEntries ) );
        }

        if ( count( $completed_sites ) === count( $sites ) ) {
            MainWP_DB_Backup::instance()->update_backup_completed( $task->id );

            if ( 1 === get_option( 'mainwp_notificationOnBackupFail' ) ) {
                $email = MainWP_DB_Common::instance()->get_user_notification_email( $task->userid );
                if ( '' !== $email ) {
                    $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
                    if ( '' !== $task->backup_errors ) {
                        $errorOutput      = 'Errors occurred while executing task: <strong>' . esc_html( $task->name ) . '</strong><br /><br />' . wp_kses_post( MainWP_Backup_Error_Codes::sanitize_message( $task->backup_errors ) );
                        $mail_title       = 'A scheduled backup had an Error - MainWP';
                        $formated_content = MainWP_Format::format_email( $email, $errorOutput, $mail_title );
                        wp_mail( $email, $mail_title, $formated_content, 'content-type: text/html' );

                        MainWP_DB_Backup::instance()->update_backup_errors( $task->id, '' );
                    }
                }
            }
        }

        return empty( $errorOutput );
    }

    /**
     * Prepare Child Site to be backed up.
     *
     * @param mixed $pTaskId      Task ID.
     * @param mixed $pSiteId      Child Site ID.
     * @param mixed $pFileNameUID Filename Unique ID.
     *
     * @return self MainWP_Manage_Sites_Handler()
     *
     * @throws MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_Backup_Executor::backup()
     * @uses  \MainWP\Dashboard\MainWP_Utility::sanitize()
     */
    public static function backup( $pTaskId, $pSiteId, $pFileNameUID ) {
        $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $pTaskId );

        $subfolder = str_replace( '%task%', MainWP_Utility::sanitize( $backupTask->name ), $backupTask->subfolder );

        if ( 'site' === $backupTask->archiveFormat ) {
            $loadFilesBeforeZip             = false;
            $maximumFileDescriptorsOverride = false;
            $maximumFileDescriptorsAuto     = false;
            $maximumFileDescriptors         = 150;
            $archiveFormat                  = false;
        } elseif ( 'global' === $backupTask->archiveFormat ) {
            $loadFilesBeforeZip             = false;
            $maximumFileDescriptorsOverride = false;
            $maximumFileDescriptorsAuto     = false;
            $maximumFileDescriptors         = 150;
            $archiveFormat                  = 'global';
        } else {
            $loadFilesBeforeZip             = $backupTask->loadFilesBeforeZip;
            $maximumFileDescriptorsOverride = ( 'zip' === $backupTask->archiveFormat ) && ( 1 === (int) $backupTask->maximumFileDescriptorsOverride );
            $maximumFileDescriptorsAuto     = ( 'zip' === $backupTask->archiveFormat ) && ( 1 === (int) $backupTask->maximumFileDescriptorsAuto );
            $maximumFileDescriptors         = $backupTask->maximumFileDescriptors;
            $archiveFormat                  = $backupTask->archiveFormat;
        }

        return MainWP_Backup_Executor::backup( $pSiteId, $backupTask->type, $subfolder, $backupTask->exclude, $backupTask->excludebackup, $backupTask->excludecache, $backupTask->excludenonwp, $backupTask->excludezip, $backupTask->filename, $pFileNameUID, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip );
    }

    /**
     * Get backup tasks and site ID.
     *
     * @param mixed $pTaskId Task ID.
     *
     * @return array $allSites All Sites array.
     * $remoteDestinations Remote destinations array.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_run_manually()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_group_id()
     */
    public static function get_backup_task_sites( $pTaskId ) {
        $sites      = array();
        $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $pTaskId );
        if ( empty( $backupTask->groups ) ) {
            if ( '' !== $backupTask->sites ) {
                $sites = explode( ',', $backupTask->sites );
            }
        } else {
            $groups = explode( ',', $backupTask->groups );
            foreach ( $groups as $groupid ) {
                $group_sites = MainWP_DB::instance()->get_websites_by_group_id( $groupid );
                foreach ( $group_sites as $group_site ) {
                    if ( in_array( $group_site->id, $sites ) ) {
                        continue;
                    }
                    $sites[] = $group_site->id;
                }
            }
        }

        $allSites = array();
        foreach ( $sites as $site ) {
            $website    = MainWP_DB::instance()->get_website_by_id( $site );
            $allSites[] = array(
                'id'       => $website->id,
                'name'     => $website->name,
                'fullsize' => $website->totalsize * 1024,
                'dbsize'   => $website->dbsize,
            );
        }

        $remoteDestinations = apply_filters( 'mainwp_backuptask_remotedestinations', array(), $backupTask );
        MainWP_DB_Backup::instance()->update_backup_run_manually( $pTaskId );

        return array(
            'sites'              => $allSites,
            'remoteDestinations' => $remoteDestinations,
        );
    }

    /**
     * Remove Backup.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::remove_backup_task()
     */
    public static function remove_backup() {
        if ( isset( $_POST['id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
            $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( intval( $_POST['id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( static::can_edit_backuptask( $task ) ) {
                MainWP_DB_Backup::instance()->remove_backup_task( $task->id );
                die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
            }
        }
        die( wp_json_encode( array( 'notask' => true ) ) );
    }

    /**
     * Resume Backup.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function resume_backup() {
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $id ) {
            $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
            if ( static::can_edit_backuptask( $task ) ) {
                $values = array( 'paused' => 0 );

                // A next_run stored before the pause may be stale; recompute
                // so the task neither fires immediately nor skips a window.
                if ( (int) $task->next_run > 0 ) {
                    $calculator         = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
                    $values['next_run'] = $calculator->compute_next_run( $task, empty( $task->last_run ) ? null : (int) $task->last_run );
                }

                MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, $values );
                die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
            }
        }
        die( wp_json_encode( array( 'notask' => true ) ) );
    }

    /**
     * Pause Backup.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_with_values()
     */
    public static function pause_backup() {
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $id ) {
            $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
            if ( static::can_edit_backuptask( $task ) ) {
                MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'paused' => 1 ) );
                die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
            }
        }
        die( wp_json_encode( array( 'notask' => true ) ) );
    }
}
