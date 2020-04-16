<?php
/**
 * MainWP Legacy Backups Handler.
 */
namespace MainWP\Dashboard;

/**
 * MainWP Manage Backups
 */
class MainWP_Manage_Backups_Handler {
	
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
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
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
	 * @param $pBackupTasks Backup tasks. 
	 *
	 * @return bool true|false.
	 */
	public static function validate_backup_tasks( $pBackupTasks ) {
		if ( ! is_array( $pBackupTasks ) ) {
			return true;
		}

		$nothingChanged = true;
		foreach ( $pBackupTasks as $backupTask ) {
			if ( '' == $backupTask->groups ) {
				$newSiteIds = '';
				$siteIds    = ( '' == $backupTask->sites ? array() : explode( ',', $backupTask->sites ) );
				foreach ( $siteIds as $siteId ) {
					$site = MainWP_DB::instance()->get_website_by_id( $siteId );
					if ( ! empty( $site ) ) {
						$newSiteIds .= ',' . $siteId;
					}
				}

				$newSiteIds = trim( $newSiteIds, ',' );

				if ( $newSiteIds != $backupTask->sites ) {
					$nothingChanged = false;
					MainWP_DB::instance()->update_backup_task_with_values( $backupTask->id, array( 'sites' => $newSiteIds ) );
				}
			} else {
				$newGroupIds = '';
				$groupIds    = explode( ',', $backupTask->groups );
				foreach ( $groupIds as $groupId ) {
					$group = MainWP_DB::instance()->get_group_by_id( $groupId );
					if ( ! empty( $group ) ) {
						$newGroupIds .= ',' . $groupId;
					}
				}
				$newGroupIds = trim( $newGroupIds, ',' );

				if ( $newGroupIds != $backupTask->groups ) {
					$nothingChanged = false;
					MainWP_DB::instance()->update_backup_task_with_values( $backupTask->id, array( 'groups' => $newGroupIds ) );
				}
			}
		}

		return $nothingChanged;
	}

	/** Update backup task. */
	public static function update_backup() {
		global $current_user;

		$name = $_POST['name'];

		if ( '' == $name ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' ) ) ) );
		}

		$backupId = $_POST['id'];
		$task     = MainWP_DB::instance()->get_backup_task_by_id( $backupId );

		if ( ! MainWP_Utility::can_edit_backuptask( $task ) ) {
			die( wp_json_encode( array( 'error' => __( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) ) ) );
		}

		$schedule       = $_POST['schedule'];
		$type           = $_POST['type'];
		$excludedFolder = trim( $_POST['exclude'], "\n" );
		$excludedFolder = explode( "\n", $excludedFolder );
		$excludedFolder = array_map( array( 'MainWP_Utility', 'trim_slashes' ), $excludedFolder );
		$excludedFolder = array_map( 'htmlentities', $excludedFolder );
		$excludedFolder = implode( ',', $excludedFolder );
		$sites          = '';
		$groups         = '';

		if ( isset( $_POST['sites'] ) ) {
			foreach ( $_POST['sites'] as $site ) {
				if ( '' != $sites ) {
					$sites .= ',';
				}
				$sites .= $site;
			}
		}

		if ( isset( $_POST['groups'] ) ) {
			foreach ( $_POST['groups'] as $group ) {
				if ( '' != $groups ) {
					$groups .= ',';
				}
				$groups .= $group;
			}
		}

		do_action( 'mainwp_update_backuptask', $task->id );

		$archiveFormat                  = isset( $_POST['archiveFormat'] ) ? $_POST['archiveFormat'] : 'site';
		$maximumFileDescriptorsOverride = 1 == $_POST['maximumFileDescriptorsOverride'];
		$maximumFileDescriptorsAuto     = 1 == $_POST['maximumFileDescriptorsAuto'];
		$maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) && MainWP_Utility::ctype_digit( $_POST['maximumFileDescriptors'] ) ? $_POST['maximumFileDescriptors'] : 150;
		$loadFilesBeforeZip             = isset( $_POST['loadFilesBeforeZip'] ) ? 1 : 0;

		if ( MainWP_DB::instance()->update_backup_task( $task->id, $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $_POST['filename'], $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip ) === false ) {
			die( wp_json_encode( array( 'error' => __( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
		} else {
			die( wp_json_encode( array( 'result' => __( 'Task updated successfully.', 'mainwp' ) ) ) );
		}
	}

	/** Add backup task. */
	public static function add_backup() {
		global $current_user;

		$name = $_POST['name'];

		if ( '' == $name ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' ) ) ) );
		}

		$schedule       = $_POST['schedule'];
		$type           = $_POST['type'];
		$excludedFolder = trim( $_POST['exclude'], "\n" );
		$excludedFolder = explode( "\n", $excludedFolder );
		$excludedFolder = array_map( array( 'MainWP_Utility', 'trim_slashes' ), $excludedFolder );
		$excludedFolder = array_map( 'htmlentities', $excludedFolder );
		$excludedFolder = implode( ',', $excludedFolder );

		$sites  = '';
		$groups = '';

		if ( isset( $_POST['sites'] ) ) {
			foreach ( $_POST['sites'] as $site ) {
				if ( '' != $sites ) {
					$sites .= ',';
				}
				$sites .= $site;
			}
		}

		if ( isset( $_POST['groups'] ) ) {
			foreach ( $_POST['groups'] as $group ) {
				if ( '' != $groups ) {
					$groups .= ',';
				}
				$groups .= $group;
			}
		}

		$archiveFormat                  = isset( $_POST['archiveFormat'] ) ? $_POST['archiveFormat'] : 'site';
		$maximumFileDescriptorsOverride = 1 == $_POST['maximumFileDescriptorsOverride'];
		$maximumFileDescriptorsAuto     = 1 == $_POST['maximumFileDescriptorsAuto'];
		$maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) && MainWP_Utility::ctype_digit( $_POST['maximumFileDescriptors'] ) ? $_POST['maximumFileDescriptors'] : 150;
		$loadFilesBeforeZip             = isset( $_POST['loadFilesBeforeZip'] ) ? 1 : 0;

		$task = MainWP_DB::instance()->add_backup_task( $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $_POST['filename'], 0, $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip );

		if ( ! $task ) {
			die( wp_json_encode( array( 'error' => __( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
		} else {
			do_action( 'mainwp_add_backuptask', $task->id );

			die( wp_json_encode( array( 'result' => __( 'Task created successfully.', 'mainwp' ) ) ) );
		}
	}

	/**
	 * Execute the backup task. 
	 * 
	 * @param mixed $task Task to perform
	 * @param integer $nrOfSites Number of Child Sites to perform the task on.
	 * @param boolean $updateRun ture|false 
	 * 
	 * @return mixed $errorOutput
	 * 
	 * phpcs:ignore -- not quite complex function
	 */
	public static function execute_backup_task( $task, $nrOfSites = 0, $updateRun = true ) {

		if ( $updateRun ) {
			MainWP_DB::instance()->update_backup_run( $task->id );
		}

		$task = MainWP_DB::instance()->get_backup_task_by_id( $task->id );

		$completed_sites = $task->completed_sites;

		if ( '' != $completed_sites ) {
			$completed_sites = json_decode( $completed_sites, true );
		}

		if ( ! is_array( $completed_sites ) ) {
			$completed_sites = array();
		}

		$sites = array();

		if ( '' == $task->groups ) {
			if ( '' != $task->sites ) {
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
		$errorOutput = null;

		$lastStartNotification = $task->lastStartNotificationSent;
		if ( $updateRun && ( 1 == get_option( 'mainwp_notificationOnBackupStart' ) ) && ( $lastStartNotification < $task->last_run ) ) {
			$email = MainWP_DB::instance()->get_user_notification_email( $task->userid );
			if ( '' != $email ) {
				$output = 'A scheduled backup has started with MainWP on ' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( time() ) ) . ' for the following ' . count( $sites ) . ' sites:<br />';

				foreach ( $sites as $siteid ) {
					$website = MainWP_DB::instance()->get_website_by_id( $siteid );
					$output .= '&nbsp;&bull;&nbsp;<a href="' . $website->url . '">' . MainWP_Utility::get_nice_url( $website->url ) . '</a><br />';
				}

				$output .= '<br />Backup details:<br /><br />';
				$output .= '<strong>Backup task</strong> - ' . $task->name . '<br />';
				$output .= '<strong>Backup type</strong> - ' . ( 'db' == $task->type ? 'DATABASE BACKUP' : 'FULL BACKUP' ) . '<br />';
				$output .= '<strong>Backup schedule</strong> - ' . strtoupper( $task->schedule ) . '<br />';
				wp_mail( $email, $mail_title = 'A Scheduled Backup has been Started - MainWP', MainWP_Utility::format_email( $email, $output, $mail_title ), 'content-type: text/html' );
				MainWP_DB::instance()->update_backup_task_with_values( $task->id, array( 'lastStartNotificationSent' => time() ) );
			}
		}

		$currentCount = 0;

		foreach ( $sites as $siteid ) {
			if ( isset( $completed_sites[ $siteid ] ) && ( true == $completed_sites[ $siteid ] ) ) {
				continue;
			}
			$website = MainWP_DB::instance()->get_website_by_id( $siteid );

			try {
				$subfolder = str_replace( '%task%', MainWP_Utility::sanitize( $task->name ), $task->subfolder );

				$backupResult = MainWP_Manage_Sites_Handler::backup_site( $siteid, $task, $subfolder );

				if ( false === $backupResult ) {
					continue;
				}

				if ( null == $errorOutput ) {
					$errorOutput = '';
				}
				$error          = false;
				$tmpErrorOutput = '';
				if ( isset( $backupResult['error'] ) ) {
					$tmpErrorOutput .= $backupResult['error'] . '<br />';
					$error           = true;
				}
				if ( isset( $backupResult['ftp'] ) && 'success' != $backupResult['ftp'] ) {
					$tmpErrorOutput .= 'FTP: ' . $backupResult['ftp'] . '<br />';
					$error           = true;
				}
				if ( isset( $backupResult['dropbox'] ) && 'success' != $backupResult['dropbox'] ) {
					$tmpErrorOutput .= 'Dropbox: ' . $backupResult['dropbox'] . '<br />';
					$error           = true;
				}

				if ( isset( $backupResult['amazon'] ) && 'success' != $backupResult['amazon'] ) {
					$tmpErrorOutput .= 'Amazon: ' . $backupResult['amazon'] . '<br />';
					$error           = true;
				}

				if ( $error ) {
					$errorOutput .= 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />';
					$errorOutput .= $tmpErrorOutput . '<br />';
				}
			} catch ( Exception $e ) {
				if ( null == $errorOutput ) {
					$errorOutput = '';
				}
				$errorOutput  .= 'Site: <strong>' . MainWP_Utility::get_nice_url( $website->url ) . '</strong><br />';
				$errorOutput  .= MainWP_Error_Helper::get_error_message( $e ) . '<br />';
				$_error_output = MainWP_Error_Helper::get_error_message( $e );
			}

			$_backup_result = isset( $backupResult ) ? $backupResult : ( isset( $_error_output ) ? $_error_output : '' );
			do_action( 'mainwp_managesite_schedule_backup', $website, array( 'type' => $task->type ), $_backup_result );

			$currentCount ++;

			$task = MainWP_DB::instance()->get_backup_task_by_id( $task->id );

			$completed_sites = $task->completed_sites;

			if ( '' != $completed_sites ) {
				$completed_sites = json_decode( $completed_sites, true );
			}
			if ( ! is_array( $completed_sites ) ) {
				$completed_sites = array();
			}

			$completed_sites[ $siteid ] = true;
			MainWP_DB::instance()->update_completed_sites( $task->id, $completed_sites );

			if ( ( 0 != $nrOfSites ) && ( $nrOfSites <= $currentCount ) ) {
				break;
			}
		}

		if ( null != $errorOutput ) {
			MainWP_DB::instance()->update_backup_errors( $task->id, $errorOutput );
		}

		if ( count( $completed_sites ) == count( $sites ) ) {
			MainWP_DB::instance()->update_backup_completed( $task->id );

			if ( 1 == get_option( 'mainwp_notificationOnBackupFail' ) ) {
				$email = MainWP_DB::instance()->get_user_notification_email( $task->userid );
				if ( '' != $email ) {
					$task = MainWP_DB::instance()->get_backup_task_by_id( $task->id );
					if ( '' != $task->backup_errors ) {
						$errorOutput = 'Errors occurred while executing task: <strong>' . $task->name . '</strong><br /><br />' . $task->backup_errors;
						wp_mail( $email, $mail_title = 'A scheduled backup had an Error - MainWP', MainWP_Utility::format_email( $email, $errorOutput, $mail_title ), 'content-type: text/html' );

						MainWP_DB::instance()->update_backup_errors( $task->id, '' );
					}
				}
			}
		}

		return ( '' == $errorOutput );
	}

	/**
	 * Prepair Child Site to be backed up.
	 * 
	 * @param mixed $pTaskId Task ID.
	 * @param mixed $pSiteId Child Site ID.
	 * @param mixed $pFileNameUID Filename Eunique ID.
	 * 
	 * @return self MainWP_Manage_Sites_Handler()
	 */
	public static function backup( $pTaskId, $pSiteId, $pFileNameUID ) {
		$backupTask = MainWP_DB::instance()->get_backup_task_by_id( $pTaskId );

		$subfolder = str_replace( '%task%', MainWP_Utility::sanitize( $backupTask->name ), $backupTask->subfolder );

		if ( 'site' == $backupTask->archiveFormat ) {
			$loadFilesBeforeZip             = false;
			$maximumFileDescriptorsOverride = false;
			$maximumFileDescriptorsAuto     = false;
			$maximumFileDescriptors         = 150;
			$archiveFormat                  = false;
		} elseif ( 'global' == $backupTask->archiveFormat ) {
			$loadFilesBeforeZip             = false;
			$maximumFileDescriptorsOverride = false;
			$maximumFileDescriptorsAuto     = false;
			$maximumFileDescriptors         = 150;
			$archiveFormat                  = 'global';
		} else {
			$loadFilesBeforeZip             = $backupTask->loadFilesBeforeZip;
			$maximumFileDescriptorsOverride = ( 'zip' == $backupTask->archiveFormat ) && ( 1 == $backupTask->maximumFileDescriptorsOverride );
			$maximumFileDescriptorsAuto     = ( 'zip' == $backupTask->archiveFormat ) && ( 1 == $backupTask->maximumFileDescriptorsAuto );
			$maximumFileDescriptors         = $backupTask->maximumFileDescriptors;
			$archiveFormat                  = $backupTask->archiveFormat;
		}

		return MainWP_Manage_Sites_Handler::backup( $pSiteId, $backupTask->type, $subfolder, $backupTask->exclude, $backupTask->excludebackup, $backupTask->excludecache, $backupTask->excludenonwp, $backupTask->excludezip, $backupTask->filename, $pFileNameUID, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip );
	}

	/**
	 * Get backup tasks and site ID.
	 * 
	 * @param mixed $pTaskId Task ID.
	 * 
	 * @return array $allSites All Sites array.
	 * @return array $remoteDestinations Remote destinations array.
	 */
	public static function get_backup_task_sites( $pTaskId ) {
		$sites      = array();
		$backupTask = MainWP_DB::instance()->get_backup_task_by_id( $pTaskId );
		if ( '' == $backupTask->groups ) {
			if ( '' != $backupTask->sites ) {
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
		MainWP_DB::instance()->update_backup_run_manually( $pTaskId );

		return array(
			'sites'              => $allSites,
			'remoteDestinations' => $remoteDestinations,
		);
	}

	/** Remove Backup. */
	public static function remove_backup() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$task = MainWP_DB::instance()->get_backup_task_by_id( $_POST['id'] );
			if ( MainWP_Utility::can_edit_backuptask( $task ) ) {
				MainWP_DB::instance()->remove_backup_task( $task->id );
				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( wp_json_encode( array( 'notask' => true ) ) );
	}

	/** Resume Backup. */
	public static function resume_backup() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$task = MainWP_DB::instance()->get_backup_task_by_id( $_POST['id'] );
			if ( MainWP_Utility::can_edit_backuptask( $task ) ) {
				MainWP_DB::instance()->update_backup_task_with_values( $task->id, array( 'paused' => 0 ) );
				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( wp_json_encode( array( 'notask' => true ) ) );
	}

	/** Pause Backup. */
	public static function pause_backup() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$task = MainWP_DB::instance()->get_backup_task_by_id( $_POST['id'] );
			if ( MainWP_Utility::can_edit_backuptask( $task ) ) {
				MainWP_DB::instance()->update_backup_task_with_values( $task->id, array( 'paused' => 1 ) );
				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( wp_json_encode( array( 'notask' => true ) ) );
	}

}
