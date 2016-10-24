<?php

class MainWP_Backup_Tasks {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function getName() {
		return '<i class="fa fa-tasks"></i> ' . __( 'Backup tasks','mainwp' );
	}

	public static function render() {

		$tasks = MainWP_DB::Instance()->getBackupTasksForUser();

		if ( count( $tasks ) == 0 ) {
			echo 'You have no scheduled backup tasks. <a href="admin.php?page=ManageBackupsAddNew">Go create one!</a>' ;
		} else {
		?>
		<div class"mainwp-row-top" style="text-align: right; margin-bottom: 1em;">
			<a href="admin.php?page=ManageBackups" class="button" ><?php _e( 'Manage Backups','mainwp' ); ?></a>
			<?php if ( mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) { ?>
				&nbsp;&nbsp;<a href="admin.php?page=ManageBackupsAddNew" class="button-primary" ><?php _e( 'Add new task','mainwp' ); ?></a>
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
					$group_sites = MainWP_DB::Instance()->getWebsitesByGroupId( $groupid );
					foreach ( $group_sites as $group_site ) {
						if ( in_array( $group_site->id, $sites ) ) {continue;}
						$sites[] = $group_site->id;
					}
				}
			} else if ( $task->sites != '' ) {
				$sites = explode( ',', $task->sites );
			}
			?>
			<div class="mainwp-row mainwp-recent">
				<span class="mainwp-left-col" style="width: 40%">
					<strong><a href="admin.php?page=ManageBackups&id=<?php echo $task->id; ?>"><?php echo stripslashes($task->name); ?></a></strong><br />
					<span style="font-size: 11px">(<?php echo strtoupper( $task->schedule ); ?> - <?php echo ($task->type == 'db' ? __( 'Database backup','mainwp' ) : __( 'Full backup','mainwp' )); ?>)</span>
				</span>
				<span class="mainwp-mid-col">
					<?php
					if ( $task->paused == 1 ) {
						echo ('<span title="Paused"  style="background: #999; padding: .3em 1em; color: white; border-radius: 15px; -moz-border-radius: 15px; -webkit-border-radius: 15px;">' . count( $sites ) . '</span>') ;
					} else if ( count( $sites ) == 0 ) {
						echo ('<span title="0 Scheduled Websites" style="background: #c80000; padding: .3em 1em; color: white; border-radius: 15px; -moz-border-radius: 15px; -webkit-border-radius: 15px;">0</span>') ;
					} else if ( $task->last_run != 0 && $task->completed < $task->last_run ) {
						echo ('<span title="Backup in Progress" class="mainwp-blink-me" style="padding: .3em 1em; color: white; border-radius: 15px; -moz-border-radius: 15px; -webkit-border-radius: 15px;">' . count( $sites ) . '</span>') ;
					} else {
						echo ('<span title="Scheduled Websites" style="background: #7fb100; padding: .3em 1em; color: white; border-radius: 15px; -moz-border-radius: 15px; -webkit-border-radius: 15px;">' . count( $sites ) . '</span>') ;
					}
					?>
				</span>
				<span class="mainwp-right-col" style="width: 40%; text-align: left;">
					<strong><?php _e( 'LAST RUN: ','mainwp' ); ?></strong>&nbsp;<?php echo ($task->last_run == 0 ? '-' : MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $task->last_run ) )); ?><br />
					<strong><?php _e( 'NEXT RUN: ','mainwp' ); ?></strong>&nbsp;<?php echo ($task->last_run == 0 ? __( 'Any minute','mainwp' ) : MainWP_Utility::formatTimestamp( ($task->schedule == 'daily' ? (60 * 60 * 24) : ($task->schedule == 'weekly' ? (60 * 60 * 24 * 7) : (60 * 60 * 24 * 30))) + MainWP_Utility::getTimestamp( $task->last_run ) )); ?>
				</span>
				
			</div>
			<div class="mainwp-clear"></div>
			<?php
		} ?>
		</div>
		<?php
		}
	}
}
