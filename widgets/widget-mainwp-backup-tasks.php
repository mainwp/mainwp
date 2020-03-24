<?php
namespace MainWP\Dashboard;

/**
 * MainWP Backup Tasks
 *
 * Displays the MainWP > Backups Page ( Legacy ).
 */


/**
 * Class MainWP_Backup_Tasks
 */
class MainWP_Backup_Tasks {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return string CLASS Class Name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method get_name()
	 *
	 * Get backup tasks name.
	 *
	 * @return string backup tasks
	 */
	public static function get_name() {
		return __( 'Backup Tasks', 'mainwp' );
	}

	/**
	 * Method render()
	 *
	 * Render MainWP Legacy Backups List
	 */
	public static function render() {

		$tasks = MainWP_DB::instance()->get_backup_tasks_for_user();
		// MainWP_UI::renderBeginReadyPopup();
		?>
		<h3><?php esc_html_e('Backup tasks', 'mainwp'); ?></h3>
		<?php
		if ( count( $tasks ) == 0 ) {
			echo 'You have no scheduled backup tasks. <a href="admin.php?page=ManageBackupsAddNew">Go create one!</a>';
		} else {
			?>
			<div class="mainwp-row-top" style="text-align: right; margin-bottom: 1em;">
				 <a href="admin.php?page=ManageBackups" class="button" ><?php esc_html_e( 'Manage Backups', 'mainwp' ); ?></a>
					 <?php if ( mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) { ?>
					&nbsp;&nbsp;<a href="admin.php?page=ManageBackupsAddNew" class="button-primary" ><?php esc_html_e( 'Add new task', 'mainwp' ); ?></a>
				<?php } ?>
			</div>
			<div id="mainwp-backup-tasks-widget">
				<style>
					@keyframes blinker {
						0% { background: #7fb100 ;}
						100% { background: #446200 ;}
					}
					@-webkit-keyframes blinker {
						0% { background: #7fb100 ;}
						100% { background: #446200 ;}
					}

					.mainwp-blink-me {
						animation: blinker 1s linear 0s infinite alternate;
						-webkit-animation: blinker 1s linear 0s infinite alternate;
					}
				</style>
				<?php
				foreach ( $tasks as $task ) {
					$sites = array();
					if ( $task->groups != '' ) {
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
					} elseif ( $task->sites != '' ) {
						$sites = explode( ',', $task->sites );
					}
					?>
					<div class="ui grid mainwp-recent">
						<div class="eight wide column">
							<strong><a href="admin.php?page=ManageBackups&id=<?php echo esc_attr($task->id); ?>"><?php echo stripslashes( $task->name ); ?></a></strong><br />
							<span style="font-size: 11px">(<?php echo strtoupper( $task->schedule ); ?> - <?php echo ( $task->type == 'db' ? __( 'Database backup', 'mainwp' ) : __( 'Full backup', 'mainwp' ) ); ?>)</span>
						</div>
						<div class="two wide column">
							<?php
							if ( $task->paused == 1 ) {
								echo ( '<span title="Paused"  style="background: #999; padding: .3em 1em; color: white; border-radius: 15px; -moz-border-radius: 15px; -webkit-border-radius: 15px;">' . count( $sites ) . '</span>' );
							} elseif ( count( $sites ) == 0 ) {
								echo ( '<span title="0 Scheduled Websites" style="background: #c80000; padding: .3em 1em; color: white; border-radius: 15px; -moz-border-radius: 15px; -webkit-border-radius: 15px;">0</span>' );
							} elseif ( $task->last_run != 0 && $task->completed < $task->last_run ) {
								echo ( '<span title="Backup in Progress" class="mainwp-blink-me" style="padding: .3em 1em; color: white; border-radius: 15px; -moz-border-radius: 15px; -webkit-border-radius: 15px;">' . count( $sites ) . '</span>' );
							} else {
								echo ( '<span title="Scheduled Websites" style="background: #7fb100; padding: .3em 1em; color: white; border-radius: 15px; -moz-border-radius: 15px; -webkit-border-radius: 15px;">' . count( $sites ) . '</span>' );
							}
							?>
						</div>
						<div class="six wide column">
							<strong><?php esc_html_e( 'LAST RUN: ', 'mainwp' ); ?></strong>&nbsp;<?php echo ( $task->last_run == 0 ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $task->last_run ) ) ); ?><br />
							<strong><?php esc_html_e( 'NEXT RUN: ', 'mainwp' ); ?></strong>&nbsp;<?php echo ( $task->last_run == 0 ? __( 'Any minute', 'mainwp' ) : MainWP_Utility::format_timestamp( ( $task->schedule == 'daily' ? ( 60 * 60 * 24 ) : ( $task->schedule == 'weekly' ? ( 60 * 60 * 24 * 7 ) : ( 60 * 60 * 24 * 30 ) ) ) + MainWP_Utility::get_timestamp( $task->last_run ) ) ); ?>
						</div>
					</div>
					<?php
				}
				?>
			</div>
			<?php
		}

		// MainWP_UI::renderEndReadyPopup();
	}

}
