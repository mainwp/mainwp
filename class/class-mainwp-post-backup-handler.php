<?php
/**
 * This class handles the MainWP Post Backups.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Post_Backup_Handler
 *
 * Handle Backup Post
 *
 * @deprecated moved to external Extension.
 */
class MainWP_Post_Backup_Handler extends MainWP_Post_Base_Handler {

	/** @var $instance Singleton MainWP_Post_Backup_Handler. */
	private static $instance = null;

	/**
	 * Create public static instance.
	 *
	 * @static
	 * @return self $instance MainWP_Post_Backup_Handler.
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init backups actions
	 */
	public function init() {
		// Page: ManageBackups.
		$this->add_action( 'mainwp_addbackup', array( &$this, 'mainwp_addbackup' ) );
		if ( mainwp_current_user_have_right( 'dashboard', 'edit_backup_tasks' ) ) {
			$this->add_action( 'mainwp_updatebackup', array( &$this, 'mainwp_updatebackup' ) );
		}
		if ( mainwp_current_user_have_right( 'dashboard', 'delete_backup_tasks' ) ) {
			$this->add_action( 'mainwp_removebackup', array( &$this, 'mainwp_removebackup' ) );
		}
		$this->add_action( 'mainwp_pausebackup', array( &$this, 'mainwp_pausebackup' ) );
		$this->add_action( 'mainwp_resumebackup', array( &$this, 'mainwp_resumebackup' ) );

		$this->add_action( 'mainwp_backuptask_get_sites', array( &$this, 'mainwp_backuptask_get_sites' ) );

		if ( mainwp_current_user_have_right( 'dashboard', 'run_backup_tasks' ) ) {
			$this->add_action( 'mainwp_backuptask_run_site', array( &$this, 'mainwp_backuptask_run_site' ) );
		}
		$this->add_action( 'mainwp_backup_upload_file', array( &$this, 'mainwp_backup_upload_file' ) );

		// Page: backup.
		if ( mainwp_current_user_have_right( 'dashboard', 'run_backup_tasks' ) ) {
			$this->add_action( 'mainwp_backup_run_site', array( &$this, 'mainwp_backup_run_site' ) );
		}
		if ( mainwp_current_user_have_right( 'dashboard', 'execute_backups' ) ) {
			$this->add_action( 'mainwp_backup', array( &$this, 'mainwp_backup' ) );
		}
		$this->add_action( 'mainwp_checkbackups', array( &$this, 'mainwp_checkbackups' ) );
		$this->add_action( 'mainwp_backup_checkpid', array( &$this, 'mainwp_backup_checkpid' ) );
		$this->add_action( 'mainwp_createbackup_getfilesize', array( &$this, 'mainwp_createbackup_getfilesize' ) );
		$this->add_action( 'mainwp_backup_download_file', array( &$this, 'mainwp_backup_download_file' ) );
		$this->add_action( 'mainwp_backup_delete_file', array( &$this, 'mainwp_backup_delete_file' ) );
		$this->add_action( 'mainwp_backup_getfilesize', array( &$this, 'mainwp_backup_getfilesize' ) );
		$this->add_action( 'mainwp_backup_upload_getprogress', array( &$this, 'mainwp_backup_upload_getprogress' ) );
		$this->add_action( 'mainwp_backup_upload_checkstatus', array( &$this, 'mainwp_backup_upload_checkstatus' ) );
	}

	/*
	 * Page: Backup
	 */

	/**
	 * Method mainwp_backup_run_site()
	 *
	 * Run backup task of site
	 *
	 * @throws MainWP_Exception on errors.
	 */
	public function mainwp_backup_run_site() {
		$this->secure_request( 'mainwp_backup_run_site' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			$ret = array( 'result' => MainWP_Backup_Handler::backup( $_POST['site_id'], 'full', '', '', 1, 1, 1, 1 ) );
			wp_send_json( $ret );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_backup()
	 *
	 * Run backup task
	 *
	 * @throws MainWP_Exception on errors.
	 */
	public function mainwp_backup() {
		$this->secure_request( 'mainwp_backup' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			$excludedFolder = trim( $_POST['exclude'], "\n" );
			$excludedFolder = explode( "\n", $excludedFolder );
			$excludedFolder = array_map( array( 'MainWP_Utility', 'trim_slashes' ), $excludedFolder );
			$excludedFolder = array_map( 'htmlentities', $excludedFolder );
			$excludedFolder = implode( ',', $excludedFolder );

			$result = MainWP_Backup_Handler::backup( $_POST['site_id'], $_POST['type'], ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $excludedFolder, $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $_POST['filename'], isset( $_POST['fileNameUID'] ) ? $_POST['fileNameUID'] : '', $_POST['archiveFormat'], ( isset( $_POST['maximumFileDescriptorsOverride'] ) && 1 === $_POST['maximumFileDescriptorsOverride'] ), ( 1 === $_POST['maximumFileDescriptorsAuto'] ), ( isset( $_POST['maximumFileDescriptors'] ) ? $_POST['maximumFileDescriptors'] : '' ), ( isset( $_POST['loadFilesBeforeZip'] ) ? $_POST['loadFilesBeforeZip'] : '' ), $_POST['pid'], ( isset( $_POST['append'] ) && ( 1 === $_POST['append'] ) ) );
			wp_send_json( array( 'result' => $result ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_backup_checkpid()
	 *
	 * Check backup task
	 *
	 * @throws MainWP_Exception on errors.
	 */
	public function mainwp_backup_checkpid() {
		$this->secure_request( 'mainwp_backup_checkpid' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			wp_send_json( MainWP_Backup_Handler::backup_check_pid( $_POST['site_id'], $_POST['pid'], $_POST['type'], ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $_POST['filename'] ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_backup_download_file()
	 *
	 * Download backup file
	 *
	 * @throws MainWP_Exception on errors.
	 */
	public function mainwp_backup_download_file() {
		$this->secure_request( 'mainwp_backup_download_file' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			die( wp_json_encode( array( 'result' => MainWP_Backup_Handler::backup_download_file( $_POST['site_id'], $_POST['type'], $_POST['url'], $_POST['local'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_backup_delete_file()
	 *
	 * Delete backup file
	 *
	 * @throws MainWP_Exception on errors.
	 */
	public function mainwp_backup_delete_file() {
		$this->secure_request( 'mainwp_backup_delete_file' );

		try {
			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
				throw new MainWP_Exception( __( 'Invalid request!', 'mainwp' ) );
			}

			die( wp_json_encode( array( 'result' => MainWP_Backup_Handler::backup_delete_file( $_POST['site_id'], $_POST['file'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_createbackup_getfilesize()
	 *
	 * Get create backup file size.
	 *
	 * @throws \Exception on errors.
	 */
	public function mainwp_createbackup_getfilesize() {
		$this->secure_request( 'mainwp_createbackup_getfilesize' );

		try {
			if ( ! isset( $_POST['siteId'] ) ) {
				throw new \Exception( __( 'No site selected!', 'mainwp' ) );
			}
			$siteId      = $_POST['siteId'];
			$fileName    = $_POST['fileName'];
			$fileNameUID = $_POST['fileNameUID'];
			$type        = $_POST['type'];

			$website = MainWP_DB::instance()->get_website_by_id( $siteId );
			if ( ! $website ) {
				throw new \Exception( __( 'No site selected!', 'mainwp' ) );
			}

			MainWP_Utility::end_session();
			// Send request to the childsite!
			$result = MainWP_Connect::fetch_url_authed(
				$website,
				'createBackupPoll',
				array(
					'fileName'       => $fileName,
					'fileNameUID'    => $fileNameUID,
					'type'           => $type,
				)
			);

			if ( ! isset( $result['size'] ) ) {
				throw new \Exception( __( 'Invalid response!', 'mainwp' ) );
			}

			if ( MainWP_Utility::ctype_digit( $result['size'] ) ) {
				$output = array( 'size' => $result['size'] );
			} else {
				$output = array();
			}
		} catch ( \Exception $e ) {
			$output = array( 'error' => $e->getMessage() );
		}

		die( wp_json_encode( $output ) );
	}

	/**
	 * Method mainwp_backup_getfilesize()
	 *
	 * Get backup file size
	 *
	 * @throws MainWP_Exception on errors.
	 */
	public function mainwp_backup_getfilesize() {
		$this->secure_request( 'mainwp_backup_getfilesize' );

		try {
			die( wp_json_encode( array( 'result' => MainWP_Manage_Sites::backupGetFilesize( $_POST['local'] ) ) ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_backup_upload_checkstatus()
	 *
	 * Check upload status
	 *
	 * @throws MainWP_Exception on errors.
	 */
	public function mainwp_backup_upload_checkstatus() {
		$this->secure_request( 'mainwp_backup_upload_checkstatus' );

		try {
			$array = get_option( 'mainwp_upload_progress' );
			$info  = apply_filters( 'mainwp_remote_destination_info', array(), $_POST['remote_destination'] );

			if ( ! is_array( $array ) || ! isset( $array[ $_POST['unique'] ] ) || ! isset( $array[ $_POST['unique'] ]['dts'] ) ) {
				die(
					wp_json_encode(
						array(
							'status' => 'stalled',
							'info'   => $info,
						)
					)
				);
			} elseif ( isset( $array[ $_POST['unique'] ]['finished'] ) ) {
				die(
					wp_json_encode(
						array(
							'status' => 'done',
							'info'   => $info,
						)
					)
				);
			} else {
				if ( $array[ $_POST['unique'] ]['dts'] < ( time() - ( 2 * 60 ) ) ) { // 2minutes.
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
			}
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_backup_upload_getprogress()
	 *
	 * Get progress status
	 *
	 * @throws MainWP_Exception on finished or errors.
	 */
	public function mainwp_backup_upload_getprogress() {
		$this->secure_request( 'mainwp_backup_upload_getprogress' );

		try {
			$array = get_option( 'mainwp_upload_progress' );

			if ( ! is_array( $array ) || ! isset( $array[ $_POST['unique'] ] ) ) {
				die( wp_json_encode( array( 'result' => 0 ) ) );
			} elseif ( isset( $array[ $_POST['unique'] ]['finished'] ) ) {
				throw new MainWP_Exception( __( 'finished...', 'maiwnp' ) );
			} else {
				wp_send_json( array( 'result' => ( isset( $array[ $_POST['unique'] ]['offset'] ) ? $array[ $_POST['unique'] ]['offset'] : $array[ $_POST['unique'] ] ) ) );
			}
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/*
	 * Page: ManageBackups
	 */

	/**
	 * Method mainwp_addbackup()
	 *
	 * Add task to the database.
	 */
	public function mainwp_addbackup() {
		$this->secure_request( 'mainwp_addbackup' );

		MainWP_Manage_Backups_Handler::add_backup();
	}

	/**
	 * Method mainwp_updatebackup()
	 *
	 * Update task.
	 */
	public function mainwp_updatebackup() {
		$this->secure_request( 'mainwp_updatebackup' );

		MainWP_Manage_Backups_Handler::update_backup();
	}

	/**
	 * Method mainwp_removebackup()
	 *
	 * Remove a task from MainWP.
	 */
	public function mainwp_removebackup() {
		$this->secure_request( 'mainwp_removebackup' );

		MainWP_Manage_Backups_Handler::remove_backup();
	}

	/**
	 * Method mainwp_resumebackup()
	 *
	 * Resume backup task.
	 */
	public function mainwp_resumebackup() {
		$this->secure_request( 'mainwp_resumebackup' );

		MainWP_Manage_Backups_Handler::resume_backup();
	}

	/**
	 * Method mainwp_pausebackup()
	 *
	 * Pause backup task.
	 */
	public function mainwp_pausebackup() {
		$this->secure_request( 'mainwp_pausebackup' );

		MainWP_Manage_Backups_Handler::pause_backup();
	}

	/**
	 * Method mainwp_backuptask_get_sites()
	 *
	 * Get sites of backup task.
	 */
	public function mainwp_backuptask_get_sites() {
		$this->secure_request( 'mainwp_backuptask_get_sites' );

		$taskID = $_POST['task_id'];

		wp_send_json( array( 'result' => MainWP_Manage_Backups_Handler::get_backup_task_sites( $taskID ) ) );
	}

	/**
	 * Method mainwp_backuptask_run_site()
	 *
	 * Run backup task of site.
	 *
	 * @throws MainWP_Exception on errors.
	 */
	public function mainwp_backuptask_run_site() {
		try {
			$this->secure_request( 'mainwp_backuptask_run_site' );

			if ( ! isset( $_POST['site_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['site_id'] ) || ! isset( $_POST['task_id'] ) || ! MainWP_Utility::ctype_digit( $_POST['task_id'] ) ) {
				throw new MainWP_Exception( 'Invalid request' );
			}

			wp_send_json( array( 'result' => MainWP_Manage_Backups_Handler::backup( $_POST['task_id'], $_POST['site_id'], $_POST['fileNameUID'] ) ) );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_checkbackups()
	 *
	 * Check backup status.
	 *
	 * @throws \Exception on errors.
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
