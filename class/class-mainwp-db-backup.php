<?php
/**
 * MainWP Database Controller
 *
 * This file handles all interactions with the DB.
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB
 */
class MainWP_DB_Backup extends MainWP_DB {
	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

	/**
	 * @static
	 * @var $instance instance of this
	 */
	private static $instance = null;

	/**
	 * @static
	 * @return MainWP_DB
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_website_backup_settings( $websiteid ) {
		if ( ! MainWP_Utility::ctype_digit( $websiteid ) ) {
			return null;
		}

		return $this->get_row_result( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_settings_backup' ) . ' WHERE wpid = %d ', $websiteid ) );
	}

	public function update_backup_task_progress( $task_id, $wp_id, $values ) {
		$this->wpdb->update(
			$this->table_name( 'wp_backup_progress' ),
			$values,
			array(
				'task_id'    => $task_id,
				'wp_id'      => $wp_id,
			)
		);

		return $this->get_backup_task_progress( $task_id, $wp_id );
	}

	public function add_backup_task_progress( $task_id, $wp_id, $information ) {
		$values = array(
			'task_id'        => $task_id,
			'wp_id'          => $wp_id,
			'dtsFetched'     => time(),
			'fetchResult'    => wp_json_encode( $information ),
			'removedFiles'   => 0,
			'downloadedDB'   => '',
			'downloadedFULL' => '',
		);

		if ( $this->wpdb->insert( $this->table_name( 'wp_backup_progress' ), $values ) ) {
			return $this->get_backup_task_progress( $task_id, $wp_id );
		}

		return null;
	}

	public function get_backup_task_progress( $task_id, $wp_id ) {
		$progress = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_backup_progress' ) . ' WHERE task_id= %d AND wp_id = %d ', $task_id, $wp_id ) );

		if ( '' !== $progress->fetchResult ) {
			$progress->fetchResult = json_decode( $progress->fetchResult, true );
		}

		return $progress;
	}

	public function backup_full_task_running( $wp_id ) {

		if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			return false;
		}

		$progresses = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_backup_progress' ) . ' WHERE wp_id = %d AND dtsFetched > %d ', $wp_id, time() - ( 30 * 60 ) ) );
		if ( is_array( $progresses ) ) {
			foreach ( $progresses as $progress ) {
				if ( ( 0 == $progress->downloadedDBComplete ) && ( 0 == $progress->downloadedFULLComplete ) ) {
					$task = $this->get_backup_task_by_id( $progress->task_id );
					if ( $task ) {
						if ( ( 'full' == $task->type ) && ! $task->paused ) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	public function remove_backup_task( $id ) {
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_backup' ) . ' WHERE id = %d', $id ) );
	}

	public function get_backup_task_by_id( $id ) {
		return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_backup' ) . ' WHERE id= %d ', $id ) );
	}

	public function get_backup_tasks_for_user( $orderBy = 'name' ) {
		if ( MainWP_System::instance()->is_single_user() ) {
			return $this->get_backup_tasks( null, $orderBy );
		}

		global $current_user;

		return $this->get_backup_tasks( $current_user->ID, $orderBy );
	}

	public function get_backup_tasks( $userid = null, $orderBy = null ) {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp_backup' ) . ' WHERE ' . ( null == $userid ? '' : 'userid= ' . $userid . ' AND ' ) . ' template = 0 ' . ( null != $orderBy ? 'ORDER BY ' . $orderBy : '' ), OBJECT );
	}

	public function add_backup_task( $userid, $name, $schedule, $type, $exclude, $sites, $groups, $subfolder, $filename,
								$template, $excludebackup, $excludecache, $excludenonwp, $excludezip, $archiveFormat,
								$maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip ) {

		// to fix null value.
		if ( empty( $exclude ) ) {
			$exclude = '';
		}

		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			$values = array(
				'userid'                         => $userid,
				'name'                           => $name,
				'schedule'                       => $schedule,
				'type'                           => $type,
				'exclude'                        => $exclude,
				'sites'                          => $sites,
				'groups'                         => $groups,
				'last'                           => 0,
				'last_run'                       => 0,
				'last_run_manually'              => 0,
				'completed_sites'                => '',
				'completed'                      => 0,
				'backup_errors'                  => '',
				'subfolder'                      => MainWP_Utility::remove_preslash_spaces( $subfolder ),
				'filename'                       => $filename,
				'paused'                         => 0,
				'template'                       => $template,
				'excludebackup'                  => $excludebackup,
				'excludecache'                   => $excludecache,
				'excludenonwp'                   => $excludenonwp,
				'excludezip'                     => $excludezip,
				'archiveFormat'                  => $archiveFormat,
				'loadFilesBeforeZip'             => $loadFilesBeforeZip,
				'maximumFileDescriptorsOverride' => $maximumFileDescriptorsOverride,
				'maximumFileDescriptorsAuto'     => $maximumFileDescriptorsAuto,
				'maximumFileDescriptors'         => $maximumFileDescriptors,
			);

			if ( $this->wpdb->insert( $this->table_name( 'wp_backup' ), $values ) ) {
				return $this->get_backup_task_by_id( $this->wpdb->insert_id );
			}
		}

		return false;
	}

	public function update_backup_task( $id, $userid, $name, $schedule, $type, $exclude, $sites, $groups, $subfolder,
									$filename, $excludebackup, $excludecache, $excludenonwp, $excludezip, $archiveFormat,
									$maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip ) {
		if ( MainWP_Utility::ctype_digit( $userid ) && MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update(
				$this->table_name( 'wp_backup' ),
				array(
					'userid'                         => $userid,
					'name'                           => $name,
					'schedule'                       => $schedule,
					'type'                           => $type,
					'exclude'                        => $exclude,
					'sites'                          => $sites,
					'groups'                         => $groups,
					'subfolder'                      => MainWP_Utility::remove_preslash_spaces( $subfolder ),
					'filename'                       => $filename,
					'excludebackup'                  => $excludebackup,
					'excludecache'                   => $excludecache,
					'excludenonwp'                   => $excludenonwp,
					'excludezip'                     => $excludezip,
					'archiveFormat'                  => $archiveFormat,
					'loadFilesBeforeZip'             => $loadFilesBeforeZip,
					'maximumFileDescriptorsOverride' => $maximumFileDescriptorsOverride,
					'maximumFileDescriptorsAuto'     => $maximumFileDescriptorsAuto,
					'maximumFileDescriptors'         => $maximumFileDescriptors,
				),
				array( 'id' => $id )
			);
		}

		return false;
	}

	public function update_backup_task_with_values( $id, $values ) {
		if ( ! is_array( $values ) ) {
			return false;
		}

		return $this->wpdb->update( $this->table_name( 'wp_backup' ), $values, array( 'id' => $id ) );
	}

	public function update_backup_run( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update(
				$this->table_name( 'wp_backup' ),
				array(
					'last_run'           => time(),
					'last'               => time(),
					'completed_sites'    => wp_json_encode( array() ),
				),
				array( 'id' => $id )
			);
		}

		return false;
	}

	public function update_backup_run_manually( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'last_run_manually' => time() ), array( 'id' => $id ) );
		}

		return false;
	}

	public function update_backup_completed( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'completed' => time() ), array( 'id' => $id ) );
		}

		return false;
	}

	public function update_backup_errors( $id, $errors ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			if ( '' === $errors ) {
				return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'backup_errors' => '' ), array( 'id' => $id ) );
			} else {
				$task = $this->get_backup_task_by_id( $id );

				return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'backup_errors' => $task->backup_errors . $errors ), array( 'id' => $id ) );
			}
		}

		return false;
	}

	public function update_completed_sites( $id, $completedSites ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'completed_sites' => wp_json_encode( $completedSites ) ), array( 'id' => $id ) );
		}

		return false;
	}

	public function get_backup_tasks_to_complete() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp_backup' ) . ' WHERE paused = 0 AND completed < last_run', OBJECT );
	}

	public function get_backup_tasks_todo_daily() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp_backup' ) . ' WHERE paused = 0 AND schedule="daily" AND ' . time() . ' - last_run >= ' . ( 60 * 60 * 24 ), OBJECT );
	}

	public function get_backup_tasks_todo_weekly() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp_backup' ) . ' WHERE paused = 0 AND schedule="weekly" AND ' . time() . ' - last_run >= ' . ( 60 * 60 * 24 * 7 ), OBJECT );
	}

	public function get_backup_tasks_todo_monthly() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp_backup' ) . ' WHERE paused = 0 AND schedule="monthly" AND ' . time() . ' - last_run >= ' . ( 60 * 60 * 24 * 30 ), OBJECT );
	}

}
