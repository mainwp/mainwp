<?php

class MainWP_Manage_Backups {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $subPages;
	/** @var $sitesTable MainWP_Manage_Backups_List_Table */
	public static $sitesTable;

	private static $hideSubmenuBackups = false;

	public static function init() {
		/**
		 * This hook allows you to render the Backups page header via the 'mainwp-pageheader-backups' action.
		 * @link http://codex.mainwp.com/#mainwp-pageheader-backups
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-backups'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 *
		 * @see \MainWP_Manage_Backups::renderHeader
		 */
		add_action( 'mainwp-pageheader-backups', array( MainWP_Manage_Backups::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Backups page footer via the 'mainwp-pagefooter-backups' action.
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-backups
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-backups'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 *
		 * @see \MainWP_Manage_Backups::renderFooter
		 */
		add_action( 'mainwp-pagefooter-backups', array( MainWP_Manage_Backups::getClassName(), 'renderFooter' ) );
	}

	public static function initMenu() {
        $enable_legacy_backup = get_option('mainwp_enableLegacyBackupFeature');
        $mainwp_primaryBackup = get_option('mainwp_primaryBackup');

        $customPage = apply_filters( 'mainwp-getcustompage-backups', false );
        if ( is_array( $customPage ) && isset( $customPage['slug'] ) && !empty($mainwp_primaryBackup)) {
			self::$hideSubmenuBackups = true;
			$_page = add_submenu_page( 'mainwp_tab', $customPage['title'], '<span id="mainwp-Backups">' . $customPage['title'] . '</span>', 'read', 'ManageBackups' . $customPage['slug'], $customPage['callback'] );
            MainWP_System::add_sub_left_menu($customPage['title'], 'mainwp_tab', 'ManageBackups' . $customPage, 'admin.php?page=ManageBackups' .  $customPage['slug'], '<i class="fa fa-hdd-o"></i>', '' );
            if ($enable_legacy_backup) {
                add_action( 'load-' . $_page, array( MainWP_Manage_Backups::getClassName(), 'on_load_page' ) );
            }
		} else {
                    if ($enable_legacy_backup) {
			$page = add_submenu_page( 'mainwp_tab', __( 'Schedule Backup', 'mainwp' ), '<span id="mainwp-Backups">' . __( 'Schedule Backup', 'mainwp' ) . '</span>', 'read', 'ManageBackups', array(
				MainWP_Manage_Backups::getClassName(),
				'renderManager',
			) );
			add_action( 'load-' . $page, array( MainWP_Manage_Backups::getClassName(), 'on_load_page' ) );
			if ( mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) {
				$_page = add_submenu_page( 'mainwp_tab', __( 'Add New Schedule', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Add New', 'mainwp' ) . '</div>', 'read', 'ManageBackupsAddNew', array(
					MainWP_Manage_Backups::getClassName(),
					'renderNew',
				) );
				add_action( 'load-' . $_page, array( MainWP_Manage_Backups::getClassName(), 'on_load_page' ) );
			}			
                    } else {
                        return;
                    }
		}

		/**
		 * This hook allows you to add extra sub pages to the Backups page via the 'mainwp-getsubpages-backups' filter.
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 */
		self::$subPages = apply_filters( 'mainwp-getsubpages-backups', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageBackups' . $subPage['slug'], $subPage['callback'] );
			}
		}
        MainWP_Manage_Backups::init_sub_sub_left_menu(self::$subPages, $enable_legacy_backup );
	}
	
	public static function on_load_page() {			
		if ('ManageBackups' == $_GET['page'] && !isset( $_GET['id'])) {
			self::$sitesTable = new MainWP_Manage_Backups_List_Table();	
		} else {
			MainWP_System::enqueue_postbox_scripts();
			MainWP_Manage_Backups::add_meta_boxes();
		}
	}
	
	public static function add_meta_boxes() {		
		$i = 1;	
		add_meta_box(
			'mwp-password-contentbox-' . $i++,
			'<i class="fa fa-history"></i> ' . __( 'Schedule backup', 'mainwp' ),
			array( 'MainWP_Manage_Backups', 'renderScheduleBackup' ),
			'mainwp_postboxes_bulk_schedule_backup',
			'normal',
			'core'
		);	
	}

	public static function initMenuSubPages() {
		if ( self::$hideSubmenuBackups && ( empty( self::$subPages ) || ! is_array( self::$subPages ) ) ) {
			return;
		}
		?>
		<div id="menu-mainwp-Backups" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<?php
					if ( ! self::$hideSubmenuBackups ) { ?>
						<div class="mainwp_boxoutin"></div>
						<a href="<?php echo admin_url( 'admin.php?page=ManageBackups' ); ?>" class="mainwp-submenu"><?php _e( 'Manage Backups', 'mainwp' ); ?></a>
						<?php if ( mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) { ?>
							<a href="<?php echo admin_url( 'admin.php?page=ManageBackupsAddNew' ); ?>" class="mainwp-submenu"><?php _e( 'Add New', 'mainwp' ); ?></a>
						<?php } ?>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							?>
							<a href="<?php echo admin_url( 'admin.php?page=ManageBackups' . $subPage['slug'] ); ?>"
							   class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

    static function init_sub_sub_left_menu( $subPages = array(), $enableLegacyBackup = true ) {
        if (!self::$hideSubmenuBackups && $enableLegacyBackup) {
            MainWP_System::add_sub_left_menu(__('Schedule Backup', 'mainwp'), 'mainwp_tab', 'ManageBackups', 'admin.php?page=ManageBackups', '<i class="fa fa-hdd-o"></i>', '' );
            $init_sub_subleftmenu = array(
                    array(  'title' => __('Manage Backups', 'mainwp'),
                            'parent_key' => 'ManageBackups',
                            'href' => 'admin.php?page=ManageBackups',
                            'slug' => 'ManageBackups',
                            'right' => ''
                        ),
                    array(  'title' => __('Add New', 'mainwp'),
                            'parent_key' => 'ManageBackups',
                            'href' => 'admin.php?page=ManageBackupsAddNew',
                            'slug' => 'ManageBackupsAddNew',
                            'right' => 'add_backup_tasks'
                        )
            );

            MainWP_System::init_subpages_left_menu($subPages, $init_sub_subleftmenu, 'ManageBackups', 'ManageBackups');

            foreach($init_sub_subleftmenu as $item) {
                MainWP_System::add_sub_sub_left_menu($item['title'], $item['parent_key'], $item['slug'], $item['href'], $item['right']);
            }
        }
    }

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
public static function renderHeader( $shownPage ) {
        MainWP_UI::render_left_menu();
	?>
	<div class="mainwp-wrap">
		<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
				src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50"
				alt="MainWP"/></a>

		<h2><i class="fa fa-hdd-o"></i> <?php _e( 'Backups', 'mainwp' ); ?></h2>

		<div style="clear: both;"></div>
		<br/><br/>

		<div class="mainwp-tabs" id="mainwp-tabs">
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == '' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=ManageBackups"><?php _e( 'Manage Backups', 'mainwp' ); ?></a>
			<?php if ( mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) { ?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'AddNew' ) {
					echo 'nav-tab-active';
				} ?>" href="admin.php?page=ManageBackupsAddNew"><?php _e( 'Add New', 'mainwp' ); ?></a>
			<?php } ?>			
			<?php if ( $shownPage == 'ManageBackupsEdit' ) { ?>
				<a class="nav-tab pos-nav-tab nav-tab-active" href="#"><?php _e( 'Edit', 'mainwp' ); ?></a><?php } ?>
			<?php
			if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
				foreach ( self::$subPages as $subPage ) {
					?>
					<a class="nav-tab pos-nav-tab <?php if ( $shownPage === $subPage['slug'] ) {
						echo 'nav-tab-active';
					} ?>" href="admin.php?page=ManageBackups<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
					<?php
				}
			}
			?>
			<div class="clear"></div>
		</div>
		<div id="mainwp_wrap-inside">
			<?php
			}

			/**
			 * @param string $shownPage The page slug shown at this moment
			 */
			public static function renderFooter( $shownPage ) {
			?>
		</div>
	</div>
	<?php
}

	/**
	 * @param $pBackupTasks
	 *
	 * @return bool
	 */
	public static function validateBackupTasks( $pBackupTasks ) {
		if ( ! is_array( $pBackupTasks ) ) {
			return true;
		}

		$nothingChanged = true;
		foreach ( $pBackupTasks as $backupTask ) {
			if ( $backupTask->groups == '' ) {
				//Check if sites exist
				$newSiteIds = '';
				$siteIds    = ( $backupTask->sites == '' ? array() : explode( ',', $backupTask->sites ) );
				foreach ( $siteIds as $siteId ) {
					$site = MainWP_DB::Instance()->getWebsiteById( $siteId );
					if ( ! empty( $site ) ) {
						$newSiteIds .= ',' . $siteId;
					}
				}

				$newSiteIds = trim( $newSiteIds, ',' );

				if ( $newSiteIds != $backupTask->sites ) {
					$nothingChanged = false;
					MainWP_DB::Instance()->updateBackupTaskWithValues( $backupTask->id, array( 'sites' => $newSiteIds ) );
				}
			} else {
				//Check if groups exist
				$newGroupIds = '';
				$groupIds    = explode( ',', $backupTask->groups );
				foreach ( $groupIds as $groupId ) {
					$group = MainWP_DB::Instance()->getGroupById( $groupId );
					if ( ! empty( $group ) ) {
						$newGroupIds .= ',' . $groupId;
					}
				}
				$newGroupIds = trim( $newGroupIds, ',' );

				if ( $newGroupIds != $backupTask->groups ) {
					$nothingChanged = false;
					MainWP_DB::Instance()->updateBackupTaskWithValues( $backupTask->id, array( 'groups' => $newGroupIds ) );
				}
			}
		}

		return $nothingChanged;
	}

	public static function renderManager() {
		$backupTask = null;
		if ( isset( $_GET['id'] ) && MainWP_Utility::ctype_digit( $_GET['id'] ) ) {
			if ( ! mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
				mainwp_do_not_have_permissions( __( 'edit backup tasks', 'mainwp' ) );

				return;
			}
			$backupTaskId = $_GET['id'];

			$backupTask = MainWP_DB::Instance()->getBackupTaskById( $backupTaskId );
			if ( ! MainWP_Utility::can_edit_backuptask( $backupTask ) ) {
				$backupTask = null;
			}

			if ( $backupTask != null ) {
				if ( ! self::validateBackupTasks( array( $backupTask ) ) ) {
					$backupTask = MainWP_DB::Instance()->getBackupTaskById( $backupTaskId );
				}
			}
		}

		$primaryBackupMethods = apply_filters( 'mainwp-getprimarybackup-methods', array() );
		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}

		if ( $backupTask == null ) {
			self::renderHeader( '' ); ?>
			<?php if ( count( $primaryBackupMethods ) == 0 ) { ?>
				<tr>
					<div class="mainwp-notice mainwp-notice-blue"><?php echo sprintf( __( 'Did you know that MainWP has extensions for working with popular backup plugins? Visit the %sextensions site%s for options.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); ?></div>
				</tr>
			<?php } ?>
			<div id="mainwp_managebackups_content">
				<div id="mainwp_managebackups_add_errors" class="mainwp-notice mainwp-notice-red"></div>
				<div id="mainwp_managebackups_add_message" class="mainwp-notice mainwp-notice-green" style="display: <?php if ( isset( $_GET['a'] ) && $_GET['a'] == '1' ) {
					echo 'block';
				} else {
					echo 'none';
				} ?>"><?php if ( isset( $_GET['a'] ) && $_GET['a'] == '1' ) {
						echo '<p>' . __( 'The backup task was added successfully', 'mainwp' ) . '</p>';
					} ?></div>
				<p></p>
				<?php
				self::$sitesTable->prepare_items();
				?>
				<div id="mainwp_managebackups_content">
					<form method="post" class="mainwp-table-container">
						<input type="hidden" name="page" value="sites_list_table">
						<?php
						MainWP_Manage_Sites_View::_renderNotes();
						self::$sitesTable->display();
						self::$sitesTable->clear_items();
						?>
					</form>
				</div>
				<div id="managebackups-task-status-box" title="Running task" style="display: none; text-align: center">
					<div style="height: 190px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="managebackups-task-status-text">
					</div>
					<input id="managebackups-task-status-close" type="button" name="Close" value="<?php _e( 'Cancel', 'mainwp' ); ?>" class="button"/>
				</div>
			</div>
			<?php
			self::renderFooter( '' );
		} else {
			MainWP_Manage_Backups::renderEdit( $backupTask );
		}
	}

	public static function renderEdit( $task ) {
		self::renderHeader( 'ManageBackupsEdit' ); ?>
		<div id="mainwp_managebackups_add_errors" class="mainwp-notice mainwp-notice-red"></div>
		<div id="mainwp_managebackups_add_message" class="mainwp-notice mainwp-notice-green" style="display: none"></div>
		<div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
		<div id="ajax-information-zone" class="updated" style="display: none;"></div>
		<div id="mainwp_managbackups_cont">
			<form method="POST" action="" id="mainwp_managebackups_add_form">
				<input type="hidden" name="mainwp_managebackups_edit_id" id="mainwp_managebackups_edit_id" value="<?php echo $task->id ?>"/>
				<?php
				MainWP_Manage_Backups::renderNewEdit( $task );
				?>
				<p class="submit">
					<input type="button" name="mainwp_managebackups_update" id="mainwp_managebackups_update" class="button-primary" value="<?php esc_attr_e( 'Update task', 'mainwp' ); ?>"/>
				</p>
			</form>
		</div>
		<?php
		self::renderFooter( 'ManageBackupsEdit' );
	}

	public static function renderNew() {
		if ( ! mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) {
			mainwp_do_not_have_permissions( __( 'add backup tasks', 'mainwp' ) );

			return;
		}

		self::renderHeader( 'AddNew' ); ?>
		<div class="mainwp-notice mainwp-notice-yellow"><?php _e( 'We recommend only scheduling 1 site per backup, multiples sites can cause unintended issues.', 'mainwp' ); ?></div>
		<div id="mainwp_managebackups_add_errors" class="mainwp-notice mainwp-notice-red"></div>
		<div id="mainwp_managebackups_add_message" class="mainwp-notice mainwp-notice-green" style="display: none"></div>
		<div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
		<div id="ajax-information-zone" class="updated" style="display: none;"></div>
		<div id="mainwp_managbackups_cont">
			<form method="POST" action="" id="mainwp_managebackups_add_form">
				<?php
				MainWP_Manage_Backups::renderNewEdit( null );
				?>

				<p class="submit">
					<input type="button" name="mainwp_managebackups_add" id="mainwp_managebackups_add" class="button-primary button button-hero" value="<?php esc_attr_e( 'Add new task', 'mainwp' ); ?>"/>
				</p>
			</form>
		</div>
		<?php
		self::renderFooter( 'AddNew' );
	}

	public static function renderNewEdit( $task ) {
		$selected_websites = array();
		$selected_groups   = array();
		if ( $task != null ) {
			if ( $task->sites != '' ) {
				$selected_websites = explode( ',', $task->sites );
			}
			if ( $task->groups != '' ) {
				$selected_groups = explode( ',', $task->groups );
			}
		}
		?>
		<div class="mainwp_managbackups_taskoptions">
			<?php
			//to add CSS Styling to the select sites box use the one below (this adds the css class mainwp_select_sites_box_right to the box)
			//MainWP_UI::select_sites_box(__("Select Sites"), 'checkbox', true, true, 'mainwp_select_sites_box_right', '', $selected_websites, $selected_groups);
			?>

			<?php MainWP_UI::select_sites_box( __( 'Select Sites', 'mainwp' ), 'checkbox', true, true, 'mainwp_select_sites_box_right', 'float: right !important; clear: both;', $selected_websites, $selected_groups, true ); ?>
			<div class="mainwp_config_box_left" style="width: calc(100% - 290px);">
				<?php MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_bulk_schedule_backup'); ?>					
			</div>
			<div class="clear"></div>

		</div>
		<?php
		if ( $task != null ) {
			?>
			<input type="hidden" id="backup_task_id" value="<?php echo $task->id; ?>"/>
			<script>mainwp_managebackups_updateExcludefolders();</script>
			<?php
		}
	}
	
	public static function renderScheduleBackup() {				
		$backupTask = null;
		if ( isset( $_GET['id'] ) && MainWP_Utility::ctype_digit( $_GET['id'] ) ) {
			if ( ! mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
				mainwp_do_not_have_permissions( __( 'edit backup tasks', 'mainwp' ) );

				return;
			}
			$backupTaskId = $_GET['id'];

			$backupTask = MainWP_DB::Instance()->getBackupTaskById( $backupTaskId );
			if ( ! MainWP_Utility::can_edit_backuptask( $backupTask ) ) {
				$backupTask = null;
			}

			if ( $backupTask != null ) {
				if ( ! self::validateBackupTasks( array( $backupTask ) ) ) {
					$backupTask = MainWP_DB::Instance()->getBackupTaskById( $backupTaskId );
				}
			}
		}
		$task = $backupTask;
		
		$selected_websites = array();
		$selected_groups   = array();
		if ( $task != null ) {
			if ( $task->sites != '' ) {
				$selected_websites = explode( ',', $task->sites );
			}
			if ( $task->groups != '' ) {
				$selected_groups = explode( ',', $task->groups );
			}
		}

		$remote_destinations   = apply_filters( 'mainwp_backups_remote_get_destinations', null, ( $task != null ? array( 'task' => $task->id ) : array() ) );
		$hasRemoteDestinations = ( $remote_destinations == null ? $remote_destinations : count( $remote_destinations ) );
		
	?>
		<table class="form-table" style="width: 100%">
				<tr class="form-field form-required">
					<th scope="row"><?php _e( 'Task name:', 'mainwp' ); ?></th>
					<td>
						<input type="text" id="mainwp_managebackups_add_name" class="" name="mainwp_managebackups_add_name" value="<?php echo( isset( $task ) ? stripslashes($task->name) : '' ); ?>"/>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Task schedule:', 'mainwp' ); ?></th>
					<td>
						<a class="mainwp_action left backuptaskschedule <?php echo( ! isset( $task ) || $task->schedule == 'daily' ? 'mainwp_action_down' : '' ); ?>" href="#" id="mainwp_managebackups_schedule_daily"><?php _e( 'DAILY', 'mainwp' ); ?></a><a class="mainwp_action mid backuptaskschedule <?php echo( isset( $task ) && $task->schedule == 'weekly' ? 'mainwp_action_down' : '' ); ?>" href="#" id="mainwp_managebackups_schedule_weekly"><?php _e( 'WEEKLY', 'mainwp' ); ?></a><a class="mainwp_action right backuptaskschedule <?php echo( isset( $task ) && $task->schedule == 'monthly' ? 'mainwp_action_down' : '' ); ?>" href="#" id="mainwp_managebackups_schedule_monthly"><?php _e( 'MONTHLY', 'mainwp' ); ?></a>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Backup tile name:', 'mainwp' ); ?></th>
					<td>
						<input type="text" name="backup_filename" id="backup_filename" class="" value="<?php echo( isset( $task ) ? $task->filename : '' ); ?>"/>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<hr/>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e( 'Backup type:', 'mainwp' ); ?></th>
					<td>
						<a class="mainwp_action left <?php echo( ! isset( $task ) || $task->type != 'db' ? 'mainwp_action_down' : '' ); ?>" href="#" id="backup_type_full"><?php _e( 'FULL BACKUP', 'mainwp' ); ?></a><a class="mainwp_action right <?php echo( isset( $task ) && $task->type == 'db' ? 'mainwp_action_down' : '' ); ?>" href="#" id="backup_type_db"><?php _e( 'DATABASE BACKUP', 'mainwp' ); ?></a>
					</td>
				</tr>
				<tr class="mainwp_backup_exclude_files_content" <?php echo( isset( $task ) && $task->type == 'db' ? 'style="display: none;"' : '' ); ?>>
					<td colspan="2">
						<hr/>
					</td>
				</tr>
				<tr class="mainwp-exclude-suggested">
					<th scope="row" style="vertical-align: top"><?php _e( 'Suggested exclude', 'mainwp' ); ?>:</th>
					<td>
						<p style="background: #7fb100; color: #ffffff; padding: .5em;"><?php _e( 'Every WordPress website is different but the sections below generally do not need to be backed up and since many of them are large in size they can even cause issues with your backup including server timeouts.', 'mainwp' ); ?></p>
					</td>
				</tr>
				<tr class="mainwp-exclude-backup-locations">
					<td colspan="2"><h4>
							<i class="fa fa-cloud-upload"></i> <?php _e( 'Known backup locations', 'mainwp' ); ?>
						</h4></td>
				</tr>
				<tr class="mainwp-exclude-backup-locations">
					<td>
						<label for="mainwp-known-backup-locations"><?php _e( 'Exclude', 'mainwp' ); ?></label><input type="checkbox" id="mainwp-known-backup-locations" <?php echo( ! isset( $task ) || $task->excludebackup == 1 ? 'checked' : '' ); ?>>
					</td>
					<td class="mainwp-td-des">
						<a href="#" id="mainwp-show-kbl-folders"><?php _e( '+ Show excluded folders', 'mainwp' ); ?></a><a href="#" id="mainwp-hide-kbl-folders"><?php _e( '- Hide excluded folders', 'mainwp' ); ?></a><br/>
						<textarea id="mainwp-kbl-content" disabled></textarea>
						<br/><?php _e( 'This adds known backup locations of popular WordPress backup plugins to the exclude list.  Old backups can take up a lot of space and can cause your current MainWP backup to timeout.', 'mainwp' ); ?>
					</td>
				</tr>
				<tr class="mainwp-exclude-separator">
					<td colspan="2" style="padding: 0 !important;">
						<hr/>
					</td>
				</tr>
				<tr class="mainwp-exclude-cache-locations">
					<td colspan="2"><h4>
							<i class="fa fa-cubes"></i> <?php _e( 'Known cache locations', 'mainwp' ); ?>
						</h4></td>
				</tr>
				<tr class="mainwp-exclude-cache-locations">
					<td>
						<label for="mainwp-known-cache-locations"><?php _e( 'Exclude', 'mainwp' ); ?></label><input type="checkbox" id="mainwp-known-cache-locations" <?php echo( ! isset( $task ) || $task->excludecache == 1 ? 'checked' : '' ); ?>>
					</td>
					<td class="mainwp-td-des">
						<a href="#" id="mainwp-show-kcl-folders"><?php _e( '+ Show excluded folders', 'mainwp' ); ?></a><a href="#" id="mainwp-hide-kcl-folders"><?php _e( '- Hide excluded folders', 'mainwp' ); ?></a><br/>
						<textarea id="mainwp-kcl-content" disabled></textarea>
						<br/><?php _e( 'This adds known cache locations of popular WordPress cache plugins to the exclude list.  A cache can be massive with thousands of files and can cause your current MainWP backup to timeout.  Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp' ); ?>
					</td>
				</tr>
				<tr class="mainwp-exclude-separator">
					<td colspan="2" style="padding: 0 !important;">
						<hr/>
					</td>
				</tr>
				<tr class="mainwp-exclude-nonwp-folders">
					<td colspan="2"><h4>
							<i class="fa fa-folder"></i> <?php _e( 'Non-WordPress folders', 'mainwp' ); ?>
						</h4></td>
				</tr>
				<tr class="mainwp-exclude-nonwp-folders">
					<td>
						<label for="mainwp-non-wordpress-folders"><?php _e( 'Exclude', 'mainwp' ); ?></label><input type="checkbox" id="mainwp-non-wordpress-folders" <?php echo( ! isset( $task ) || $task->excludenonwp == 1 ? 'checked' : '' ); ?>>
					</td>
					<td class="mainwp-td-des">
						<a href="#" id="mainwp-show-nwl-folders"><?php _e( '+ Show excluded folders', 'mainwp' ); ?></a><a href="#" id="mainwp-hide-nwl-folders"><?php _e( '- Hide excluded folders', 'mainwp' ); ?></a><br/>
						<textarea id="mainwp-nwl-content" disabled></textarea>
						<br/><?php _e( 'This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp' ); ?>
					</td>
				</tr>
				<tr class="mainwp-exclude-separator">
					<td colspan="2" style="padding: 0 !important;">
						<hr/>
					</td>
				</tr>
				<tr class="mainwp-exclude-zips">
					<td colspan="2"><h4>
							<i class="fa fa-file-archive-o"></i> <?php _e( 'ZIP archives', 'mainwp' ); ?>
						</h4></td>
				</tr>
				<tr class="mainwp-exclude-zips">
					<td>
						<label for="mainwp-zip-archives"><?php _e( 'Exclude', 'mainwp' ); ?></label><input type="checkbox" id="mainwp-zip-archives" <?php echo( ! isset( $task ) || $task->excludezip == 1 ? 'checked' : '' ); ?>>
					</td>
					<td class="mainwp-td-des"><?php _e( 'Zip files can be large and are often not needed for a WordPress backup. Be sure to deselect this option if you do have zip files you need backed up.', 'mainwp' ); ?></td>
				</tr>
				<tr class="mainwp-exclude-separator">
					<td colspan="2" style="padding: 0 !important;">
						<hr/>
					</td>
				</tr>
				<tr class="mainwp_backup_exclude_files_content" <?php echo( isset( $task ) && $task->type == 'db' ? 'style="display: none;"' : '' ); ?>>
					<th scope="row" style="vertical-align: top"><h4 class="mainwp-custom-excludes">
							<i class="fa fa-minus-circle"></i> <?php _e( 'Custom excludes', 'mainwp' ); ?>
						</h4></th>
					<td>
						<p style="background: #7fb100; color: #ffffff; padding: .5em;"><?php _e( 'Exclude any additional files that you do not need backed up for this site. Click a folder name to drill down into the directory.', 'mainwp' ); ?></p>
						<br/>
						<?php printf( __( 'Click directories to navigate. Click the red sign ( %s ) to exclude a folder.', 'mainwp' ), '<img style="margin-bottom: -3px;" src="' . plugins_url( 'images/exclude.png', dirname( __FILE__ ) ) . '">' ); ?>
						<br/><br/>
						<table class="mainwp_excluded_folders_cont">
							<tr>
								<td style="width: 280px">
									<div id="backup_exclude_folders" class="mainwp_excluded_folders"></div>
								</td>
								<td>
									<?php _e( 'Excluded files & directories:', 'mainwp' ); ?><br/>
					<textarea id="excluded_folders_list"><?php
						$excluded = ( isset( $task ) ? $task->exclude : '' );
						if ( $excluded != '' ) {
							$excluded = explode( ',', $excluded );
							echo implode( "/\n", $excluded ) . "/\n";
						}
						?></textarea>
								</td>
							</tr>
						</table>
						<span class="description"><strong><?php _e( 'ATTENTION:', 'mainwp' ); ?></strong> <?php _e( 'Do not exclude any folders if you are using this backup to clone or migrate the wordpress installation.', 'mainwp' ); ?></span>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<hr/>
					</td>
				</tr>
				<?php
				if ( $hasRemoteDestinations !== null ) {
					?>
					<tr>
						<th scope="row"><?php _e( 'Store backup in:', 'mainwp' ); ?></th>
						<td>
							<a class="mainwp_action left <?php echo( ! $hasRemoteDestinations ? 'mainwp_action_down' : '' ); ?>" href="#" id="backup_location_local"><?php _e( 'LOCAL SERVER ONLY', 'mainwp' ); ?></a><a class="mainwp_action right <?php echo( $hasRemoteDestinations ? 'mainwp_action_down' : '' ); ?>" href="#" id="backup_location_remote"><?php _e( 'REMOTE DESTINATION', 'mainwp' ); ?></a>
						</td>
					</tr>
					<tr class="mainwp_backup_destinations" <?php echo( ! $hasRemoteDestinations ? 'style="display: none;"' : '' ); ?>>
						<th scope="row"><?php _e( 'Backup subfolder:', 'mainwp' ); ?></th>
						<td>
							<input type="text" id="mainwp_managebackups_add_subfolder" name="backup_subfolder"
								   value="<?php echo( isset( $task ) ? $task->subfolder : 'MainWP Backups/%url%/%type%/%date%' ); ?>"/>
						</td>
					</tr>
					<?php
				}
				?>
				<?php do_action( 'mainwp_backups_remote_settings', array( 'task' => $task ) ); ?>
				<tr>
					<td colspan="2">
						<hr/>
					</td>
				</tr>
				<?php
				$globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
				if ( $globalArchiveFormat == false ) {
					$globalArchiveFormat = 'tar.gz';
				}
				if ( $globalArchiveFormat == 'zip' ) {
					$globalArchiveFormatText = 'Zip';
				} else if ( $globalArchiveFormat == 'tar' ) {
					$globalArchiveFormatText = 'Tar';
				} else if ( $globalArchiveFormat == 'tar.gz' ) {
					$globalArchiveFormatText = 'Tar GZip';
				} else if ( $globalArchiveFormat == 'tar.bz2' ) {
					$globalArchiveFormatText = 'Tar BZip2';
				}

				$archiveFormat = isset( $task ) ? $task->archiveFormat : 'site';
				$useGlobal     = ( $archiveFormat == 'global' );
				$useSite       = ( $archiveFormat == '' || $archiveFormat == 'site' );
				?>
				<tr>
					<th scope="row"><?php _e( 'Archive format', 'mainwp' ); ?></th>
					<td>
						<table class="mainwp-nomarkup">
							<tr>
								<td valign="top">
					<span class="mainwp-select-bg"><select name="mainwp_archiveFormat" class="mainwp-select2" id="mainwp_archiveFormat">							
							<option value="site" <?php if ( $useSite ) : ?>selected<?php endif; ?>>Site specific setting</option>
							<option value="global" <?php if ( $useGlobal ) : ?>selected<?php endif; ?>>Global setting (<?php echo $globalArchiveFormatText; ?>)</option>
							<option value="zip" <?php if ( $archiveFormat == 'zip' ) : ?>selected<?php endif; ?>>Zip</option>
							<option value="tar" <?php if ( $archiveFormat == 'tar' ) : ?>selected<?php endif; ?>>Tar</option>
							<option value="tar.gz" <?php if ( $archiveFormat == 'tar.gz' ) : ?>selected<?php endif; ?>>Tar GZip</option>
							<option value="tar.bz2" <?php if ( $archiveFormat == 'tar.bz2' ) : ?>selected<?php endif; ?>>Tar BZip2</option>
						</select><label></label></span>
								</td>
								<td>
									<i>
										<span id="info_site" class="archive_info" <?php if ( ! $useSite ) : ?>style="display: none;"<?php endif; ?>>Depends on the settings of the child site</span>
					<span id="info_global" class="archive_info" <?php if ( ! $useGlobal ) : ?>style="display: none;"<?php endif; ?>><?php
						if ( $globalArchiveFormat == 'zip' ) : ?>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)<?php
						elseif ( $globalArchiveFormat == 'tar' ) : ?>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)<?php
						elseif ( $globalArchiveFormat == 'tar.gz' ) : ?>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)<?php
						elseif ( $globalArchiveFormat == 'tar.bz2' ) : ?>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)<?php endif; ?></span>
										<span id="info_zip" class="archive_info" <?php if ( $archiveFormat != 'zip' ) : ?>style="display: none;"<?php endif; ?>>Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)</span>
										<span id="info_tar" class="archive_info" <?php if ( $archiveFormat != 'tar' ) : ?>style="display: none;"<?php endif; ?>>Creates an uncompressed tar-archive. (No compression, fast, low memory usage)</span>
										<span id="info_tar.gz" class="archive_info" <?php if ( $archiveFormat != 'tar.gz' ) : ?>style="display: none;"<?php endif; ?>>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)</span>
										<span id="info_tar.bz2" class="archive_info" <?php if ( $archiveFormat != 'tar.bz2' ) : ?>style="display: none;"<?php endif; ?>>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)</span>
									</i>
								</td>
							</tr>
						</table>
					</td>
				</tr>

				<?php
				$maximumFileDescriptorsOverride = isset( $task ) ? ( $task->maximumFileDescriptorsOverride == 1 ) : false;
				$maximumFileDescriptorsAuto     = isset( $task ) ? ( $task->maximumFileDescriptorsAuto == 1 ) : false;
				$maximumFileDescriptors         = isset( $task ) ? $task->maximumFileDescriptors : 150;
				?>
				<tr class="archive_method archive_zip" <?php if ( $archiveFormat != 'zip' ) : ?>style="display: none;"<?php endif; ?>>
					<th scope="row"><?php _e( 'Maximum file descriptors on child', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'The maximum number of open file descriptors on the child hosting.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/' ); ?></th>
					<td>
						<div class="mainwp-radio" style="float: left;">
							<input type="radio" value="" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_global" <?php echo( ! $maximumFileDescriptorsOverride ? 'checked="true"' : '' ); ?>"/>
							<label for="mainwp_options_maximumFileDescriptorsOverride_global"></label>
						</div>
						Global Setting (<a href="<?php echo admin_url( 'admin.php?page=Settings' ); ?>">Change Here</a>)<br/>

						<div class="mainwp-radio" style="float: left;">
							<input type="radio" value="override" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_override" <?php echo( $maximumFileDescriptorsOverride ? 'checked="true"' : '' ); ?>"/>
							<label for="mainwp_options_maximumFileDescriptorsOverride_override"></label>
						</div>
						Override<br/><br/>

						<div style="float: left"><?php _e( 'Auto detect:', 'mainwp' ); ?>&nbsp;</div>
						<div class="mainwp-checkbox">
							<input type="checkbox" id="mainwp_maximumFileDescriptorsAuto" name="mainwp_maximumFileDescriptorsAuto" <?php echo( $maximumFileDescriptorsAuto ? 'checked="checked"' : '' ); ?> />
							<label for="mainwp_maximumFileDescriptorsAuto"></label></div>
						<div style="float: left">
							<i>(<?php _e( 'Enter a fallback value because not all hosts support this function.', 'mainwp' ); ?>)</i>
						</div>
						<div style="clear:both"></div>
						<input type="text" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors"
							   value="<?php echo $maximumFileDescriptors; ?>"/>
					</td>
				</tr>
				<tr class="archive_method archive_zip" <?php if ( $archiveFormat != 'zip' ) : ?>style="display: none;"<?php endif; ?>>
					<th scope="row"><?php _e( 'Load files in memory before zipping', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'This causes the files to be opened and closed immediately, using less simultaneous I/O operations on the disk. For huge sites with a lot of files we advise to disable this, memory usage will drop but we will use more file handlers when backing up.', 'http://docs.mainwp.com/load-files-memory/' ); ?></th>
					<td>
						<input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_global" value="1" <?php if ( ! isset( $task ) || $task->loadFilesBeforeZip == false || $task->loadFilesBeforeZip == 1 ) : ?>checked="true"<?php endif; ?>/> Global setting (<a href="<?php echo admin_url( 'admin.php?page=Settings' ); ?>">Change Here</a>)<br/>
						<input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_yes" value="2" <?php if ( isset( $task ) && $task->loadFilesBeforeZip == 2 ) : ?>checked="true"<?php endif; ?>/> Yes<br/>
						<input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_no" value="0" <?php if ( isset( $task ) && $task->loadFilesBeforeZip == 0 ) : ?>checked="true"<?php endif; ?>/> No<br/>
					</td>
				</tr>
			</table>
	<?php
	}

	public static function updateBackup() {
		global $current_user;
		$name = $_POST['name'];
		if ( $name == '' ) {
			die( json_encode( array( 'error' => __('Please enter a valid name for your backup task') ) ) );
		}
		$backupId = $_POST['id'];
		$task     = MainWP_DB::Instance()->getBackupTaskById( $backupId );
		if ( ! MainWP_Utility::can_edit_backuptask( $task ) ) {
			die( json_encode( array( 'error' => 'This is not your task' ) ) );
		}

		$schedule       = $_POST['schedule'];
		$type           = $_POST['type'];
		$excludedFolder = trim( $_POST['exclude'], "\n" );
		$excludedFolder = explode( "\n", $excludedFolder );
		$excludedFolder = array_map( array( 'MainWP_Utility', 'trimSlashes' ), $excludedFolder );
		$excludedFolder = array_map( 'htmlentities' , $excludedFolder );
		$excludedFolder = implode( ',', $excludedFolder );
		$sites          = '';
		$groups         = '';
		if ( isset( $_POST['sites'] ) ) {
			foreach ( $_POST['sites'] as $site ) {
				if ( $sites != '' ) {
					$sites .= ',';
				}
				$sites .= $site;
			}
		}
		if ( isset( $_POST['groups'] ) ) {
			foreach ( $_POST['groups'] as $group ) {
				if ( $groups != '' ) {
					$groups .= ',';
				}
				$groups .= $group;
			}
		}

		do_action( 'mainwp_update_backuptask', $task->id );

		$archiveFormat                  = isset( $_POST['archiveFormat'] ) ? $_POST['archiveFormat'] : 'site';
		$maximumFileDescriptorsOverride = $_POST['maximumFileDescriptorsOverride'] == 1;
		$maximumFileDescriptorsAuto     = $_POST['maximumFileDescriptorsAuto'] == 1;
		$maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) && MainWP_Utility::ctype_digit( $_POST['maximumFileDescriptors'] ) ? $_POST['maximumFileDescriptors'] : 150;

		if ( MainWP_DB::Instance()->updateBackupTask( $task->id, $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, $_POST['subfolder'], $_POST['filename'], $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $_POST['loadFilesBeforeZip'] ) === false ) {
			die( json_encode( array( 'error' => 'An unspecified error occured!' ) ) );
		} else {
			die( json_encode( array( 'result' => 'The backup task was updated successfully!' ) ) );
		}
	}

	public static function addBackup() {
		global $current_user;
		$name = $_POST['name'];
		if ( $name == '' ) {
			die( json_encode( array( 'error' => __('Please enter a valid name for your backup task.') ) ) );
		}
		$schedule       = $_POST['schedule'];
		$type           = $_POST['type'];
		$excludedFolder = trim( $_POST['exclude'], "\n" );
		$excludedFolder = explode( "\n", $excludedFolder );
		$excludedFolder = array_map( array( 'MainWP_Utility', 'trimSlashes' ), $excludedFolder );
		$excludedFolder = array_map( 'htmlentities' , $excludedFolder );
		$excludedFolder = implode( ',', $excludedFolder );

		$sites  = '';
		$groups = '';
		if ( isset( $_POST['sites'] ) ) {
			foreach ( $_POST['sites'] as $site ) {
				if ( $sites != '' ) {
					$sites .= ',';
				}
				$sites .= $site;
			}
		}
		if ( isset( $_POST['groups'] ) ) {
			foreach ( $_POST['groups'] as $group ) {
				if ( $groups != '' ) {
					$groups .= ',';
				}
				$groups .= $group;
			}
		}

		$archiveFormat                  = isset( $_POST['archiveFormat'] ) ? $_POST['archiveFormat'] : 'site';
		$maximumFileDescriptorsOverride = $_POST['maximumFileDescriptorsOverride'] == 1;
		$maximumFileDescriptorsAuto     = $_POST['maximumFileDescriptorsAuto'] == 1;
		$maximumFileDescriptors         = isset( $_POST['maximumFileDescriptors'] ) && MainWP_Utility::ctype_digit( $_POST['maximumFileDescriptors'] ) ? $_POST['maximumFileDescriptors'] : 150;

		$task = MainWP_DB::Instance()->addBackupTask( $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $_POST['filename'], 0, $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $_POST['loadFilesBeforeZip'] );
		if ( ! $task ) {
			die( json_encode( array( 'error' => 'An unspecified error occured.' ) ) );
		} else {
			do_action( 'mainwp_add_backuptask', $task->id );

			die( json_encode( array( 'result' => __('The backup task has been added successfully.', 'mainwp') ) ) );
		}
	}

	public static function executeBackupTask( $task, $nrOfSites = 0, $updateRun = true ) {
		if ( $updateRun ) {
			MainWP_DB::Instance()->updateBackupRun( $task->id );
		}

		$task = MainWP_DB::Instance()->getBackupTaskById( $task->id );

		$completed_sites = $task->completed_sites;

		if ( $completed_sites != '' ) {
			$completed_sites = json_decode( $completed_sites, true );
		}
		if ( ! is_array( $completed_sites ) ) {
			$completed_sites = array();
		}

		$sites = array();

		if ( $task->groups == '' ) {
			if ( $task->sites != '' ) {
				$sites = explode( ',', $task->sites );
			}
		} else {
			$groups = explode( ',', $task->groups );
			foreach ( $groups as $groupid ) {
				$group_sites = MainWP_DB::Instance()->getWebsitesByGroupId( $groupid );
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
		if ( $updateRun && ( get_option( 'mainwp_notificationOnBackupStart' ) == 1 ) && ( $lastStartNotification < $task->last_run ) ) {
			$email = MainWP_DB::Instance()->getUserNotificationEmail( $task->userid );
			if ( $email != '' ) {
				$output = 'A scheduled backup has started with MainWP on ' . MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( time() ) ) . ' for the following ' . count( $sites ) . ' sites:<br />';

				foreach ( $sites as $siteid ) {
					$website = MainWP_DB::Instance()->getWebsiteById( $siteid );
					$output .= '&nbsp;&bull;&nbsp;<a href="' . $website->url . '">' . MainWP_Utility::getNiceURL( $website->url ) . '</a><br />';
				}

				$output .= '<br />Backup details:<br /><br />';
				$output .= '<strong>Backup task</strong>' . ' - ' . $task->name . '<br />';
				$output .= '<strong>Backup type</strong>' . ' - ' . ( $task->type == 'db' ? 'DATABASE BACKUP' : 'FULL BACKUP' ) . '<br />';
				$output .= '<strong>Backup schedule</strong>' . ' - ' . strtoupper( $task->schedule ) . '<br />';
				wp_mail( $email, $mail_title = 'A Scheduled Backup has been Started - MainWP', MainWP_Utility::formatEmail( $email, $output, $mail_title ), 'content-type: text/html' );
				MainWP_DB::Instance()->updateBackupTaskWithValues( $task->id, array( 'lastStartNotificationSent' => time() ) );
			}
		}

		$currentCount = 0;
		foreach ( $sites as $siteid ) {
			if ( isset( $completed_sites[ $siteid ] ) && ( $completed_sites[ $siteid ] == true ) ) {
				continue;
			}
			$website = MainWP_DB::Instance()->getWebsiteById( $siteid );

			try {
				$subfolder = str_replace( '%task%', MainWP_Utility::sanitize( $task->name ), $task->subfolder );

				$backupResult = MainWP_Manage_Sites::backupSite( $siteid, $task, $subfolder );

				//When we receive a timeout, we return false..
				if ( $backupResult === false ) {
					continue;
				}

				if ( $errorOutput == null ) {
					$errorOutput = '';
				}
				$error          = false;
				$tmpErrorOutput = '';
				if ( isset( $backupResult['error'] ) ) {
					$tmpErrorOutput .= $backupResult['error'] . '<br />';
					$error = true;
				}
				if ( isset( $backupResult['ftp'] ) && $backupResult['ftp'] != 'success' ) {
					$tmpErrorOutput .= 'FTP: ' . $backupResult['ftp'] . '<br />';
					$error = true;
				}
				if ( isset( $backupResult['dropbox'] ) && $backupResult['dropbox'] != 'success' ) {
					$tmpErrorOutput .= 'Dropbox: ' . $backupResult['dropbox'] . '<br />';
					$error = true;
				}

				if ( isset( $backupResult['amazon'] ) && $backupResult['amazon'] != 'success' ) {
					$tmpErrorOutput .= 'Amazon: ' . $backupResult['amazon'] . '<br />';
					$error = true;
				}

				if ( $error ) {
					$errorOutput .= 'Site: <strong>' . MainWP_Utility::getNiceURL( $website->url ) . '</strong><br />';
					$errorOutput .= $tmpErrorOutput . '<br />';
				}
			} catch ( Exception $e ) {
				if ( $errorOutput == null ) {
					$errorOutput = '';
				}
				$errorOutput .= 'Site: <strong>' . MainWP_Utility::getNiceURL( $website->url ) . '</strong><br />';
				$errorOutput .= MainWP_Error_Helper::getErrorMessage( $e ) . '<br />';
				$_error_output = MainWP_Error_Helper::getErrorMessage( $e );
			}

			$_backup_result = isset( $backupResult ) ? $backupResult : ( isset( $_error_output ) ? $_error_output : '' );
			do_action( 'mainwp_managesite_schedule_backup', $website, array( 'type' => $task->type ), $_backup_result );

			$currentCount ++;

			$task = MainWP_DB::Instance()->getBackupTaskById( $task->id );

			$completed_sites = $task->completed_sites;

			if ( $completed_sites != '' ) {
				$completed_sites = json_decode( $completed_sites, true );
			}
			if ( ! is_array( $completed_sites ) ) {
				$completed_sites = array();
			}

			$completed_sites[ $siteid ] = true;
			MainWP_DB::Instance()->updateCompletedSites( $task->id, $completed_sites );

			if ( ( $nrOfSites != 0 ) && ( $nrOfSites <= $currentCount ) ) {
				break;
			}
		}

		//update completed sites
		if ( $errorOutput != null ) {
			MainWP_DB::Instance()->updateBackupErrors( $task->id, $errorOutput );
		}

		if ( count( $completed_sites ) == count( $sites ) ) {
			MainWP_DB::Instance()->updateBackupCompleted( $task->id );

			if ( get_option( 'mainwp_notificationOnBackupFail' ) == 1 ) {
				$email = MainWP_DB::Instance()->getUserNotificationEmail( $task->userid );
				if ( $email != '' ) {
					$task = MainWP_DB::Instance()->getBackupTaskById( $task->id );
					if ( $task->backup_errors != '' ) {
						$errorOutput = 'Errors occurred while executing task: <strong>' . $task->name . '</strong><br /><br />' . $task->backup_errors;
						wp_mail( $email, $mail_title = 'A scheduled backup had an Error - MainWP', MainWP_Utility::formatEmail( $email, $errorOutput, $mail_title ), 'content-type: text/html' );

						MainWP_DB::Instance()->updateBackupErrors( $task->id, '' );
					}
				}
			}
		}

		return ( $errorOutput == '' );
	}

	public static function backup( $pTaskId, $pSiteId, $pFileNameUID ) {
		$backupTask = MainWP_DB::Instance()->getBackupTaskById( $pTaskId );

		$subfolder = str_replace( '%task%', MainWP_Utility::sanitize( $backupTask->name ), $backupTask->subfolder );

		if ( $backupTask->archiveFormat == 'site' ) {
			$loadFilesBeforeZip             = false;
			$maximumFileDescriptorsOverride = false;
			$maximumFileDescriptorsAuto     = false;
			$maximumFileDescriptors         = 150;
			$archiveFormat                  = false;
		} else if ( $backupTask->archiveFormat == 'global' ) {
			$loadFilesBeforeZip             = false;
			$maximumFileDescriptorsOverride = false;
			$maximumFileDescriptorsAuto     = false;
			$maximumFileDescriptors         = 150;
			$archiveFormat                  = 'global';
		} else {
			$loadFilesBeforeZip             = $backupTask->loadFilesBeforeZip;
			$maximumFileDescriptorsOverride = ( $backupTask->archiveFormat == 'zip' ) && ( $backupTask->maximumFileDescriptorsOverride == 1 );
			$maximumFileDescriptorsAuto     = ( $backupTask->archiveFormat == 'zip' ) && ( $backupTask->maximumFileDescriptorsAuto == 1 );
			$maximumFileDescriptors         = $backupTask->maximumFileDescriptors;
			$archiveFormat                  = $backupTask->archiveFormat;
		}

		return MainWP_Manage_Sites::backup( $pSiteId, $backupTask->type, $subfolder, $backupTask->exclude, $backupTask->excludebackup, $backupTask->excludecache, $backupTask->excludenonwp, $backupTask->excludezip, $backupTask->filename, $pFileNameUID, $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip );
	}

	public static function getBackupTaskSites( $pTaskId ) {
		$sites      = array();
		$backupTask = MainWP_DB::Instance()->getBackupTaskById( $pTaskId );
		if ( $backupTask->groups == '' ) {
			if ( $backupTask->sites != '' ) {
				$sites = explode( ',', $backupTask->sites );
			}
		} else {
			$groups = explode( ',', $backupTask->groups );
			foreach ( $groups as $groupid ) {
				$group_sites = MainWP_DB::Instance()->getWebsitesByGroupId( $groupid );
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
			$website    = MainWP_DB::Instance()->getWebsiteById( $site );
			$allSites[] = array(
				'id'       => $website->id,
				'name'     => $website->name,
				'fullsize' => $website->totalsize * 1024,
				'dbsize'   => $website->dbsize,
			);
		}

		$remoteDestinations = apply_filters( 'mainwp_backuptask_remotedestinations', array(), $backupTask );
		MainWP_DB::Instance()->updateBackupRunManually( $pTaskId );

		return array( 'sites' => $allSites, 'remoteDestinations' => $remoteDestinations );
	}

	public static function getSiteDirectories() {
		$websites = array();
		if ( isset( $_REQUEST['site'] ) && ( $_REQUEST['site'] != '' ) ) {
			$siteId  = $_REQUEST['site'];
			$website = MainWP_DB::Instance()->getWebsiteById( $siteId );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$websites[] = $website;
			}
		} else if ( isset( $_REQUEST['sites'] ) && ( $_REQUEST['sites'] != '' ) ) {
			$siteIds          = explode( ',', urldecode( $_REQUEST['sites'] ) );
			$siteIdsRequested = array();
			foreach ( $siteIds as $siteId ) {
				$siteId = $siteId;

				if ( ! MainWP_Utility::ctype_digit( $siteId ) ) {
					continue;
				}
				$siteIdsRequested[] = $siteId;
			}

			$websites = MainWP_DB::Instance()->getWebsitesByIds( $siteIdsRequested );
		} else if ( isset( $_REQUEST['groups'] ) && ( $_REQUEST['groups'] != '' ) ) {
			$groupIds          = explode( ',', urldecode( $_REQUEST['groups'] ) );
			$groupIdsRequested = array();
			foreach ( $groupIds as $groupId ) {
				$groupId = $groupId;

				if ( ! MainWP_Utility::ctype_digit( $groupId ) ) {
					continue;
				}
				$groupIdsRequested[] = $groupId;
			}

			$websites = MainWP_DB::Instance()->getWebsitesByGroupIds( $groupIdsRequested );
		}

		if ( count( $websites ) == 0 ) {
			die( '<i><strong>Select a site or group first.</strong></i>' );
		} //Nothing selected!

		$allFiles            = array();
		$excludedBackupFiles = array();
		$excludedCacheFiles  = array();
		$excludedNonWPFiles  = array();
		foreach ( $websites as $website ) {
			$files = null;

			$result = json_decode( $website->directories, true );
			$dir    = urldecode( $_POST['dir'] );

			if ( $dir == '' ) {
				if ( is_array( $result ) ) {
					$files = array_keys( $result );
					self::addExcludedBackups( $result, $excludedBackupFiles );
					self::addExcludedCache( $result, $excludedCacheFiles );
					self::addExcludedNonWP( $files, $excludedNonWPFiles );
				}
			} else {
				$dirExploded = explode( '/', $dir );

				$tmpResult = $result;
				foreach ( $dirExploded as $innerDir ) {
					if ( $innerDir == '' ) {
						continue;
					}

					if ( isset( $tmpResult[ $innerDir ] ) ) {
						$tmpResult = $tmpResult[ $innerDir ];
					} else {
						$tmpResult = null;
						break;
					}
				}
				if ( $tmpResult != null && is_array( $tmpResult ) ) {
					$files = array_keys( $tmpResult );
				} else {
					$files = null;
				}
			}

			if ( ( $files != null ) && ( count( $files ) > 0 ) ) {
				$allFiles = array_unique( array_merge( $allFiles, $files ) );
			}
		}

		if ( $allFiles != null && count( $allFiles ) > 0 ) {
			natcasesort( $allFiles );
			echo '<ul class="jqueryFileTree" style="display: none;">';
			// All dirs
			foreach ( $allFiles as $file ) {
				echo '<li class="directory collapsed"><a href="#" rel="' . esc_attr( $_POST['dir'] . $file ) . '/">' . esc_html( $file ) . '<div title="Exclude form backup" class="exclude_folder_control"><img src="' . plugins_url( 'images/exclude.png', dirname( __FILE__ ) ) . '" /></div></a></li>';
			}
			echo '</ul>';

			if ( count( $excludedBackupFiles ) > 0 ) {
				echo '<div id="excludedBackupFiles" style="display:none">';
				foreach ( $excludedBackupFiles as $excludedBackupFile ) {
					echo $excludedBackupFile . "\n";
				}
				echo '</div>';
			}

			if ( count( $excludedCacheFiles ) > 0 ) {
				echo '<div id="excludedCacheFiles" style="display:none">';
				foreach ( $excludedCacheFiles as $excludedCacheFile ) {
					echo $excludedCacheFile . "\n";
				}
				echo '</div>';
			}

			if ( count( $excludedNonWPFiles ) > 0 ) {
				echo '<div id="excludedNonWPFiles" style="display:none">';
				foreach ( $excludedNonWPFiles as $excludedNonWPFile ) {
					echo $excludedNonWPFile . "\n";
				}
				echo '</div>';
			}
		}
	}

	private static function addExcludedBackups( &$files, &$arr ) {
		$newExcludes = array();

		//Backup buddy
		$newExcludes[] = 'wp-content/uploads/backupbuddy_backups';
		$newExcludes[] = 'wp-content/uploads/backupbuddy_temp';
		$newExcludes[] = 'wp-content/uploads/pb_backupbuddy';

		//ManageWP
		$newExcludes[] = 'wp-content/managewp';

		//InfiniteWP
		$newExcludes[] = 'wp-content/infinitewp';

		//WordPress Backup to Dropbox
		$newExcludes[] = 'wp-content/backups';

		//BackWPUp
		$newExcludes[] = 'wp-content/uploads/backwpup*';

		//WP Complete Backup
		$newExcludes[] = 'wp-content/plugins/wp-complete-backup/storage';

		//Online Backup for WordPress
		$newExcludes[] = 'wp-content/backups';

		//XCloner
		$newExcludes[] = 'administrator/backups';

		foreach ( $newExcludes as $newExclude ) {
			$path             = explode( '/', $newExclude );
			$found            = true;
			$newExcludeSuffix = null;

			$currentArr = null;
			foreach ( $path as $pathPart ) {
				if ( $currentArr == null ) {
					if ( isset( $files[ $pathPart ] ) ) {
						$currentArr = $files[ $pathPart ];
					}
				} else {
					if ( isset( $currentArr[ $pathPart ] ) ) {
						$currentArr = $currentArr[ $pathPart ];
					} else {
						if ( MainWP_Utility::endsWith( $pathPart, '*' ) ) {
							foreach ( $currentArr as $key => $val ) {
								if ( MainWP_Utility::startsWith( $key, substr( $pathPart, 0, strlen( $pathPart ) - 1 ) ) ) {
									if ( $newExcludeSuffix == null ) {
										$newExcludeSuffix = array();
									}
									$newExcludeSuffix[] = $key;
								}
							}
							if ( $newExcludeSuffix != null && count( $newExcludeSuffix ) > 0 ) {
								break;
							}
						}
						$currentArr = null;
					}
				}

				if ( ! is_array( $currentArr ) ) {
					$found = false;
					break;
				}
			}

			if ( $found ) {
				if ( $newExcludeSuffix != null ) {
					$newExclude = substr( $newExclude, 0, strrpos( $newExclude, '/' ) + 1 );
					foreach ( $newExcludeSuffix as $newExcludeSuff ) {
						$arr[] = $newExclude . $newExcludeSuff;
					}
				} else {
					$arr[] = $newExclude;
				}
			}
		}
	}

	private static function addExcludedCache( &$files, &$arr ) {
		$newExcludes = array();

		//W3 Total Cache
		$newExcludes[] = 'wp-content/w3tc-cache';
		$newExcludes[] = 'wp-content/w3tc';
		$newExcludes[] = 'wp-content/cache/config';
		$newExcludes[] = 'wp-content/cache/minify';
		$newExcludes[] = 'wp-content/cache/page_enhanced';
		$newExcludes[] = 'wp-content/cache/tmp';

		//WP Super Cache
		$newExcludes[] = 'wp-content/cache/supercache';

		//Quick Cache
		$newExcludes[] = 'wp-content/cache/quick-cache';

		//Hyper Cache
		$newExcludes[] = 'wp-content/hyper-cache/cache';

		//WP Fastest Cache
		$newExcludes[] = 'wp-content/cache/all';

		//WP-Rocket
		$newExcludes[] = 'wp-content/cache/wp-rocket';

		foreach ( $newExcludes as $newExclude ) {
			$path  = explode( '/', $newExclude );
			$found = true;

			$currentArr = null;
			foreach ( $path as $pathPart ) {
				if ( $currentArr == null ) {
					if ( isset( $files[ $pathPart ] ) ) {
						$currentArr = $files[ $pathPart ];
					}
				} else {
					if ( isset( $currentArr[ $pathPart ] ) ) {
						$currentArr = $currentArr[ $pathPart ];
					} else {
						$currentArr = null;
					}
				}

				if ( ! is_array( $currentArr ) ) {
					$found = false;
					break;
				}
			}

			if ( $found ) {
				$arr[] = $newExclude;
			}
		}
	}

	private static function addExcludedNonWP( &$files, &$arr ) {
		foreach ( $files as $file ) {
			if ( $file != 'wp-content' && $file != 'wp-includes' && $file != 'wp-admin' ) {
				$arr[] = $file;
			}
		}
	}

	public static function removeBackup() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$task = MainWP_DB::Instance()->getBackupTaskById( $_POST['id'] );
			if ( MainWP_Utility::can_edit_backuptask( $task ) ) {
				//Remove from DB
				MainWP_DB::Instance()->removeBackupTask( $task->id );
				die( json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( json_encode( array( 'notask' => true ) ) );
	}

	public static function resumeBackup() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$task = MainWP_DB::Instance()->getBackupTaskById( $_POST['id'] );
			if ( MainWP_Utility::can_edit_backuptask( $task ) ) {
				MainWP_DB::Instance()->updateBackupTaskWithValues( $task->id, array( 'paused' => 0 ) );
				die( json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( json_encode( array( 'notask' => true ) ) );
	}

	public static function pauseBackup() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$task = MainWP_DB::Instance()->getBackupTaskById( $_POST['id'] );
			if ( MainWP_Utility::can_edit_backuptask( $task ) ) {
				MainWP_DB::Instance()->updateBackupTaskWithValues( $task->id, array( 'paused' => 1 ) );
				die( json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( json_encode( array( 'notask' => true ) ) );
	}

	public static function getMetaboxName() {
		return '<i class="fa fa-hdd-o"></i> Backups';
	}

	public static function renderMetabox() {
		$website = MainWP_Utility::get_current_wpid();
		if ( ! $website ) {
			return;
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $website );

		MainWP_Manage_Sites::showBackups( $website );
		?>
		<?php if ( mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) { ?>
			<hr/>
			<div style="text-align: center;">
				<a href="<?php echo admin_url( 'admin.php?page=managesites&backupid=' . $website->id ); ?>" class="button-primary"><?php _e( 'Backup now', 'mainwp' ); ?></a>
			</div>
		<?php } ?>
		<?php
	}

}
