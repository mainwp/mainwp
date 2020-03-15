<?php

/**
 * MainWP Manage Backups
 */
class MainWP_Manage_Backups {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $subPages;

	private static $hideSubmenuBackups = false;


	private static $instance = null;

	public static function Instance() {
		if ( self::$instance == null ) {
			self::$instance = new MainWP_Manage_Backups();
		}

		return self::$instance;
	}

	public static function init() {
		/**
		 * This hook allows you to render the Backups page header via the 'mainwp-pageheader-backups' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-backups
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-backups'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 *
		 * @see \MainWP_Manage_Backups::renderHeader
		 */
		add_action( 'mainwp-pageheader-backups', array( self::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Backups page footer via the 'mainwp-pagefooter-backups' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-backups
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-backups'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 *
		 * @see \MainWP_Manage_Backups::renderFooter
		 */
		add_action( 'mainwp-pagefooter-backups', array( self::getClassName(), 'renderFooter' ) );
	}

	public static function initMenu() {
		$enable_legacy_backup = get_option('mainwp_enableLegacyBackupFeature');
		$mainwp_primaryBackup = get_option('mainwp_primaryBackup');
		$customPage           = apply_filters( 'mainwp-getcustompage-backups', false );
		if ( is_array( $customPage ) && isset( $customPage['slug'] ) && ! empty( $mainwp_primaryBackup) ) {
			self::$hideSubmenuBackups = true;
			add_submenu_page( 'mainwp_tab', $customPage['title'], '<span id="mainwp-Backups">' . $customPage['title'] . '</span>', 'read', 'ManageBackups' . $customPage['slug'], $customPage['callback'] );
			MainWP_Menu::add_left_menu( array(
				'title'      => $customPage['title'],
				'parent_key' => 'mainwp_tab',
				'slug'       => 'ManageBackups' . $customPage['slug'],
				'href'       => 'admin.php?page=ManageBackups' . $customPage['slug'],
				'icon'       => '<i class="hdd outline icon"></i>',
			), 1 ); // level 1

		} else {
			if ( $enable_legacy_backup ) {
					add_submenu_page( 'mainwp_tab', __( 'Backups', 'mainwp' ), '<span id="mainwp-Backups">' . __( 'Backups', 'mainwp' ) . '</span>', 'read', 'ManageBackups', array( self::getClassName(), 'renderManager' ) );
				if ( mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) {
					if ( ! MainWP_Menu::is_disable_menu_item(3, 'ManageBackupsAddNew') ) {
						add_submenu_page( 'mainwp_tab', __( 'Add New Schedule', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Add New', 'mainwp' ) . '</div>', 'read', 'ManageBackupsAddNew', array( self::getClassName(), 'renderNew' ) );
					}
				}
			} else {
				return;
			}
		}

		/**
		 * This hook allows you to add extra sub pages to the Backups page via the 'mainwp-getsubpages-backups' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 */
		self::$subPages = apply_filters( 'mainwp-getsubpages-backups', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item(3, 'ManageBackups' . $subPage['slug']) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageBackups' . $subPage['slug'], $subPage['callback'] );
			}
		}
		self::init_left_menu(self::$subPages, $enable_legacy_backup );
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
					if ( ! self::$hideSubmenuBackups ) {
						?>
						<div class="mainwp_boxoutin"></div>
						<a href="<?php echo admin_url( 'admin.php?page=ManageBackups' ); ?>" class="mainwp-submenu"><?php _e( 'Manage Backups', 'mainwp' ); ?></a>
						<?php if ( mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) { ?>
							<?php if ( ! MainWP_Menu::is_disable_menu_item(3, 'ManageBackupsAddNew') ) { ?>
							<a href="<?php echo admin_url( 'admin.php?page=ManageBackupsAddNew' ); ?>" class="mainwp-submenu"><?php _e( 'Add New', 'mainwp' ); ?></a>
							<?php } ?>
						<?php } ?>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( MainWP_Menu::is_disable_menu_item(3, 'ManageBackups' . $subPage['slug']) ) {
									continue;
							}
							?>
							<a href="<?php echo admin_url( 'admin.php?page=ManageBackups' . $subPage['slug'] ); ?>"
							   class="mainwp-submenu"><?php echo esc_html($subPage['title']); ?></a>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function init_left_menu( $subPages = array(), $enableLegacyBackup = true ) {
		if ( ! self::$hideSubmenuBackups && $enableLegacyBackup ) {
			MainWP_Menu::add_left_menu( array(
				'title'             => __('Backups', 'mainwp'),
				'parent_key'        => 'mainwp_tab',
				'slug'              => 'ManageBackups',
				'href'              => 'admin.php?page=ManageBackups',
				'icon'              => '<i class="hdd outline icon"></i>',
			), 1 ); // level 1

			$init_sub_subleftmenu = array(
				array(
					'title'          => __('Manage Backups', 'mainwp'),
					'parent_key'     => 'ManageBackups',
					'href'           => 'admin.php?page=ManageBackups',
					'slug'           => 'ManageBackups',
					'right'          => '',
				),
				array(
					'title'          => __('Add New', 'mainwp'),
					'parent_key'     => 'ManageBackups',
					'href'           => 'admin.php?page=ManageBackupsAddNew',
					'slug'           => 'ManageBackupsAddNew',
					'right'          => 'add_backup_tasks',
				),
			);

			MainWP_Menu::init_subpages_left_menu($subPages, $init_sub_subleftmenu, 'ManageBackups', 'ManageBackups');

			foreach ( $init_sub_subleftmenu as $item ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
					continue;
				}
				MainWP_Menu::add_left_menu( $item, 2);
			}
		}
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage = '' ) {

		$params = array(
			'title' => __( 'Backups', 'mainwp' ),
		);

		MainWP_UI::render_top_header($params);

		$renderItems = array();

		$renderItems[] = array(
			'title'    => __( 'Manage Backups', 'mainwp' ),
			'href'     => 'admin.php?page=ManageBackups',
			'active'   => ( $shownPage == '' ) ? true : false,
			'disabled' => MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' ) ? true : false,
		);

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ) {
			$renderItems[] = array(
				'title'    => __( 'Add New', 'mainwp' ),
				'href'     => 'admin.php?page=ManageBackupsAddNew',
				'access'   => mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ),
				'active'   => ( $shownPage == 'AddNew' ) ? true : false,
				'disabled' => MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsEdit' ) ) {
			if ( $shownPage == 'ManageBackupsEdit' ) {
				$renderItems[] = array(
					'title'  => __( 'Edit', 'mainwp' ),
					'href'   => '#',
					'active' => true,
				);
			}
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' . $subPage['slug'] ) ) {
						continue;
				}

				$item           = array();
				$item['title']  = esc_html($subPage['title']);
				$item['href']   = 'admin.php?page=ManageBackups' . $subPage['slug'];
				$item['active'] = ( $subPage['slug'] == $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderFooter( $shownPage ) {
			echo '</div>';
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
				// Check if sites exist
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
				// Check if groups exist
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

			$backup_items = MainWP_DB::Instance()->getBackupTasksForUser();
			if ( ! self::validateBackupTasks( $backup_items ) ) {
				$backup_items = MainWP_DB::Instance()->getBackupTasksForUser();
			}

			self::renderHeader( '' );
			?>
			<?php if ( count( $primaryBackupMethods ) == 0 ) { ?>
				<div class="mainwp-notice mainwp-notice-blue"><?php echo sprintf( __( 'Did you know that MainWP has extensions for working with popular backup plugins? Visit the %1$sextensions site%2$s for options.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); ?></div>
			<?php } ?>
			<div class="ui alt segment">
				<div id="mainwp_managebackups_add_message" class="mainwp-notice mainwp-notice-green" style="display: 
				<?php
				if ( isset( $_GET['a'] ) && $_GET['a'] == '1' ) {
					echo 'block';
				} else {
					echo 'none';
				}
				?>
				">
				<?php
				if ( isset( $_GET['a'] ) && $_GET['a'] == '1' ) {
						echo '<p>' . __( 'The backup task was added successfully', 'mainwp' ) . '</p>';
				}
				?>
					</div>

				<form method="post" class="mainwp-table-container">
					<?php
					MainWP_UI::render_modal_edit_notes();
					self::Instance()->display( $backup_items );
					?>
				</form>
			</div>

			<div class="ui modal" id="managebackups-task-status-box" tabindex="0">
					   <div class="header">Running task</div>
					   <div class="content mainwp-modal-content">
					   </div>
					   <div class="actions mainwp-modal-actions">
						   <input id="managebackups-task-status-close" type="button" name="Close" value="<?php _e( 'Cancel', 'mainwp' ); ?>" class="button"/>
					   </div>

			   </div>
			<?php
			self::renderFooter( '' );
		} else {
			self::renderEdit( $backupTask );
		}
	}

	public function display( $backup_items ) {
		$can_trigger = true;
		if ( ! mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
			$can_trigger = false;
		}
		?>
		<table id="mainwp-backups-table" class="ui padded selectable compact single line table">
			<thead class="full-width">
				<tr>
					<th id="mainwp-title"><?php _e( 'Task name', 'mainwp' ); ?></th>
					<th id="mainwp-type"><?php _e( 'Type', 'mainwp' ); ?></th>
					<th id="mainwp-schedule"><?php _e( 'Schedule', 'mainwp' ); ?></th>
					<th id="mainwp-dest" class="no-sort"><?php _e( 'Destination', 'mainwp' ); ?></th>
					<th id="mainwp-website"><?php _e( 'Website', 'mainwp' ); ?></th>
					<th id="mainwp-details" class="no-sort"><?php _e( 'Details', 'mainwp' ); ?></th>
					<?php if ( $can_trigger ) { ?>
					<th id="mainwp-trigger" class="no-sort"><?php _e( 'Trigger', 'mainwp' ); ?></th>
					<?php } ?>
					<th id="mainwp-actions" class="no-sort"></th>
				</tr>
			</thead>
			<tbody id="mainwp-posts-list">
				<?php

				if ( $backup_items ) {

					$columns = array(
						'task_name'      => __( 'Task Name', 'mainwp' ),
						'type'           => __( 'Type', 'mainwp' ),
						'schedule'       => __( 'Schedule', 'mainwp' ),
						'destination'    => __( 'Destination', 'mainwp' ),
						'websites'       => __( 'Websites', 'mainwp' ),
						'details'        => __( 'Details', 'mainwp' ),
						'trigger'        => __( 'Trigger', 'mainwp' ),
						'actions'        => __( 'Trigger', 'mainwp' ),
					);

					if ( ! $can_trigger ) {
						unset($columns['trigger']);
					}

					foreach ( $backup_items as $item ) {

						$sites  = ( $item->sites == '' ? array() : explode( ',', $item->sites ) );
						$groups = ( $item->groups == '' ? array() : explode( ',', $item->groups ) );
						foreach ( $groups as $group ) {
							$websites = MainWP_DB::Instance()->getWebsitesByGroupId( $group );
							if ( $websites == null ) {
								continue;
							}

							foreach ( $websites as $website ) {
								if ( ! in_array( $website->id, $sites ) ) {
									$sites[] = $website->id;
								}
							}
						}

						$item->the_sites = $sites;

						$this->single_row( $item, $columns );
					}
				}
				?>
			</tbody>
		</table>
	<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery('#mainwp-backups-table').DataTable( {
						"colReorder" : true,
						"stateSave":  true,
						"pagingType": "full_numbers",
						"order": [],
						"columnDefs": [ {
						  "targets": 'no-sort',
						  "orderable": false
						} ],
						"drawCallback": function( settings ) {
							jQuery( '#mainwp-backups-table .ui.dropdown').dropdown();
						},
				} );
			} );
	</script>
		<?php
	}

	public function single_row( $item, $columns ) {
		?>
		<tr>
		<?php
		foreach ( $columns as $column_name => $title ) {
			if ( method_exists( $this, 'column_' . $column_name ) ) {
				echo '<td>';
				echo call_user_func( array( $this, 'column_' . $column_name ), $item );
				echo '</td>';
			} else {
				echo '<td></td>';
			}
		}
		?>
		</tr>
		<?php
	}

	public function column_actions( $item ) {

		$actions = array(
			'edit'   => sprintf( '<a class="item" href="admin.php?page=ManageBackups&id=%s"><i class="edit outline icon"></i> ' . __( 'Edit', 'mainwp' ) . '</a>', $item->id ),
			'delete' => sprintf( '<a class="submitdelete item" href="#" task_id="%s" onClick="return managebackups_remove(this);"><i class="trash alternate outline icon"></i> ' . __( 'Delete', 'mainwp' ) . '</a>', $item->id ),
		);

		if ( ! mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
			unset( $actions['edit'] );
		}

		if ( ! mainwp_current_user_can( 'dashboard', 'delete_backup_tasks' ) ) {
			unset( $actions['delete'] );
		}

		if ( $item->paused == 1 ) {
			if ( mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
				$actions['resume'] = sprintf( '<a href="#" class="item" task_id="%s" onClick="return managebackups_resume(this)"><i class="play icon"></i> ' . __( 'Resume', 'mainwp' ) . '</a>', $item->id );
			}
		} else {
			if ( mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
				$actions['pause'] = sprintf( '<a href="#" class="item" task_id="%s" onClick="return managebackups_pause(this)"><i class="pause icon"></i> ' . __( 'Pause', 'mainwp' ) . '</a>', $item->id );
			}
		}

		$out = '<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
          <i class="ellipsis horizontal icon"></i>
          <div class="menu">
            <div class="header">' . esc_html( 'Backup Actions', 'mainwp' ) . '</div>
                <div class="divider"></div>';
		foreach ( $actions as $action => $link ) {
			$out .= $link;
		}
		$out .= '</div>
        </div>';

		return $out;
	}

	public function column_task_name( $item ) {
		return stripslashes( $item->name );
	}

	public function column_type( $item ) {
		return ( $item->type == 'db' ? __( 'DATABASE BACKUP', 'mainwp' ) : __( 'FULL BACKUP', 'mainwp' ) );
	}

	public function column_schedule( $item ) {
		return strtoupper( $item->schedule );
	}

	public function column_destination( $item ) {
		$extraOutput = apply_filters( 'mainwp_backuptask_column_destination', '', $item->id );
		if ( $extraOutput != '' ) {
			return trim( $extraOutput, '<br />' );
		}

		return __( 'SERVER', 'mainwp' );
	}

	public function column_websites( $item ) {
		if ( count( $item->the_sites ) == 0 ) {
			echo( '<span style="color: red; font-weight: bold; ">' . count( $item->the_sites ) . '</span>' );
		} else {
			echo count( $item->the_sites );
		}
	}

	public function column_details( $item ) {
		$output  = '<strong>' . __( 'LAST RUN MANUALLY: ', 'mainwp' ) . '</strong>' . ( $item->last_run_manually == 0 ? '-' : MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $item->last_run_manually ) ) ) . '<br />';
		$output .= '<strong>' . __( 'LAST RUN: ', 'mainwp' ) . '</strong>' . ( $item->last_run == 0 ? '-' : MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $item->last_run ) ) ) . '<br />';
		$output .= '<strong>' . __( 'LAST COMPLETED: ', 'mainwp' ) . '</strong>' . ( $item->completed == 0 ? '-' : MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $item->completed ) ) ) . '<br />';
		$output .= '<strong>' . __( 'NEXT RUN: ', 'mainwp' ) . '</strong>' . ( $item->last_run == 0 ? __( 'Any minute', 'mainwp' ) : MainWP_Utility::formatTimestamp( ( $item->schedule == 'daily' ? ( 60 * 60 * 24 ) : ( $item->schedule == 'weekly' ? ( 60 * 60 * 24 * 7 ) : ( 60 * 60 * 24 * 30 ) ) ) + MainWP_Utility::getTimestamp( $item->last_run ) ) );
		$output .= '<strong>';
		if ( $item->last_run != 0 && $item->completed < $item->last_run ) {
			$output         .= __( '<br />CURRENTLY RUNNING: ', 'mainwp' ) . '</strong>';
			$completed_sites = $item->completed_sites;
			if ( $completed_sites != '' ) {
				$completed_sites = json_decode( $completed_sites, 1 );
			}
			if ( ! is_array( $completed_sites ) ) {
				$completed_sites = array();
			}

			$output .= count( $completed_sites ) . ' / ' . count( $item->the_sites );
		}

		return $output;
	}

	public function column_trigger( $item ) {
		return '<span class="backup_run_loading"><img src="' . MAINWP_PLUGIN_URL . 'assets/images/loader.gif" /></span>&nbsp;<a href="#" class="backup_run_now" task_id="' . $item->id . '" task_type="' . $item->type . '">' . __( 'Run now', 'mainwp' ) . '</a>';
	}


	public static function renderEdit( $task ) {
		self::renderHeader( 'ManageBackupsEdit' );
		?>
		<div class="ui alt segment">
			<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<form method="POST" action="" class="ui form">
				<input type="hidden" name="mainwp_managebackups_edit_id" id="mainwp_managebackups_edit_id" value="<?php echo esc_attr($task->id); ?>"/>
				<?php
				self::renderNewEdit( $task );
				?>
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
		self::renderHeader( 'AddNew' );
		?>
		<div class="ui alt segment">
			<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<form method="POST" action="" id="mainwp-backup-task-form" class="ui form">
				<?php self::renderNewEdit( null ); ?>
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

		<div class="mainwp-main-content">
			<?php self::renderScheduleBackup(); ?>
		</div>
		<div class="mainwp-side-content mainwp-no-padding">
			<div class="mainwp-select-sites">
				<div class="ui header"><?php echo __( 'Select Sites', 'mainwp' ); ?></div>
				<?php MainWP_UI::select_sites_box( 'checkbox', true, true, '', '', $selected_websites, $selected_groups, true ); ?>
			</div>
			<div class="ui divider"></div>
			<div class="mainwp-search-submit">
				<?php if ( $task != null ) : ?>
				<input type="hidden" id="backup_task_id" value="<?php echo esc_attr( $task->id ); ?>"/>
				<input type="button" name="mainwp_managebackups_update" id="mainwp_managebackups_update" class="ui big green fluid button" value="<?php esc_attr_e( 'Update Schedule Backup', 'mainwp' ); ?>"/>
				<?php else : ?>
				<input type="button" name="mainwp_managebackups_add" id="mainwp_managebackups_add" class="ui big green fluid button" value="<?php esc_attr_e( 'Schedule Backup', 'mainwp' ); ?>"/>
				<?php endif; ?>
			</div>
		</div>
		<div class="ui hidden clearing divider"></div>
			<?php
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

		$globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
		if ( $globalArchiveFormat == false ) {
			$globalArchiveFormat = 'tar.gz';
		}
		if ( $globalArchiveFormat == 'zip' ) {
			$globalArchiveFormatText = 'Zip';
		} elseif ( $globalArchiveFormat == 'tar' ) {
			$globalArchiveFormatText = 'Tar';
		} elseif ( $globalArchiveFormat == 'tar.gz' ) {
			$globalArchiveFormatText = 'Tar GZip';
		} elseif ( $globalArchiveFormat == 'tar.bz2' ) {
			$globalArchiveFormatText = 'Tar BZip2';
		}

		$archiveFormat = isset( $task ) ? $task->archiveFormat : 'site';
		$useGlobal     = ( $archiveFormat == 'global' );
		$useSite       = ( $archiveFormat == '' || $archiveFormat == 'site' );
		?>

	<div class="ui divider hidden"></div>
	<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
	<h3 class="header"><?php echo __( 'Backup Details', 'mainwp' ); ?></h3>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'Backup task name', 'mainwp' ); ?></label>
		<div class="ten wide column">
			<input type="text" id="mainwp_managebackups_add_name" name="mainwp_managebackups_add_name" value="<?php echo( isset( $task ) ? stripslashes($task->name) : '' ); ?>"/>
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'Backup file name', 'mainwp' ); ?></label>
	  <div class="ten wide column">
			<input type="text" id="backup_filename" name="backup_filename" value="<?php echo( isset( $task ) ? $task->filename : '' ); ?>"/>
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'Backup type', 'mainwp' ); ?></label>
	  <div class="ten wide column">
			<select name="mainwp-backup-type" id="mainwp-backup-type" class="ui dropdown">
				<option value="full" <?php echo( ! isset( $task ) || $task->type == 'full' ? 'selected' : '' ); ?>><?php _e( 'Full Backup', 'mainwp' ); ?></option>
				<option value="db" <?php echo( isset( $task ) && $task->type == 'db' ? 'selected' : '' ); ?>><?php _e( 'Database Backup', 'mainwp' ); ?></option>
			</select>
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'Schedule', 'mainwp' ); ?></label>
	  <div class="ten wide column">
			<select name="mainwp-backup-task-schedule" id="mainwp-backup-task-schedule" class="ui dropdown">
				<option value="daily" <?php echo( ! isset( $task ) || $task->schedule == 'daily' ? 'selected' : '' ); ?>><?php _e( 'Daily', 'mainwp' ); ?></option>
				<option value="weekly" <?php echo( ! isset( $task ) || $task->schedule == 'weekly' ? 'selected' : '' ); ?>><?php _e( 'Weekly', 'mainwp' ); ?></option>
				<option value="monthly" <?php echo( ! isset( $task ) || $task->schedule == 'monthly' ? 'selected' : '' ); ?>><?php _e( 'Monthly', 'mainwp' ); ?></option>
			</select>
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'Archive type', 'mainwp' ); ?></label>
	  <div class="ten wide column">
			<select name="mainwp_archiveFormat" id="mainwp_archiveFormat" class="ui dropdown">
				<option value="site" 
				<?php
				if ( $useSite ) :
					?>
					selected<?php endif; ?>><?php echo __( 'Site specific setting', 'mainwp' ); ?></option>
				<option value="global" 
				<?php
				if ( $useGlobal ) :
					?>
					selected<?php endif; ?>><?php echo __( 'Global setting', 'mainwp' ); ?> (<?php echo $globalArchiveFormatText; ?>)</option>
				<option value="zip" 
				<?php
				if ( $archiveFormat == 'zip' ) :
					?>
					selected<?php endif; ?>><?php echo __( 'Zip', 'mainwp' ); ?></option>
				<option value="tar" 
				<?php
				if ( $archiveFormat == 'tar' ) :
					?>
					selected<?php endif; ?>><?php echo __( 'Tar', 'mainwp' ); ?></option>
				<option value="tar.gz" 
				<?php
				if ( $archiveFormat == 'tar.gz' ) :
					?>
					selected<?php endif; ?>><?php echo __( 'Tar GZip', 'mainwp' ); ?></option>
				<option value="tar.bz2" 
				<?php
				if ( $archiveFormat == 'tar.bz2' ) :
					?>
					selected<?php endif; ?>><?php echo __( 'Tar BZip2', 'mainwp' ); ?></option>
			</select>
		</div>
	</div>
		<?php
		$style = isset( $task ) && $task->type == 'db' ? 'style="display: none;"' : '';
		?>
	<div class="mainwp-backup-full-exclude" <?php echo $style; ?>>
	<h3 class="header"><?php echo __( 'Backup Excludes', 'mainwp' ); ?></h3>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'Known backup locations', 'mainwp' ); ?></label>
	  <div class="ten wide column ui toggle checkbox">
			<input type="checkbox" id="mainwp-known-backup-locations" <?php echo( ! isset( $task ) || $task->excludebackup == 1 ? 'checked' : '' ); ?>>
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"></label>
	  <div class="ten wide column ui toggle checkbox">
			<textarea id="mainwp-kbl-content" disabled></textarea><br />
			<em><?php echo __( 'This adds known backup locations of popular WordPress backup plugins to the exclude list. Old backups can take up a lot of space and can cause your current MainWP backup to timeout.', 'mainwp' ); ?></em>
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'Known cache locations', 'mainwp' ); ?></label>
	  <div class="ten wide column ui toggle checkbox">
			<input type="checkbox" id="mainwp-known-cache-locations" <?php echo( ! isset( $task ) || $task->excludecache == 1 ? 'checked' : '' ); ?>><br />
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"></label>
	  <div class="ten wide column ui toggle checkbox">
			<textarea id="mainwp-kcl-content" disabled></textarea><br />
			<em><?php echo __( 'This adds known cache locations of popular WordPress cache plugins to the exclude list. A cache can be massive with thousands of files and can cause your current MainWP backup to timeout. Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp' ); ?></em>
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'Non-WordPress folders', 'mainwp' ); ?></label>
	  <div class="ten wide column ui toggle checkbox">
			<input type="checkbox" id="mainwp-non-wordpress-folders" <?php echo( ! isset( $task ) || $task->excludenonwp == 1 ? 'checked' : '' ); ?>><br />
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"></label>
	  <div class="ten wide column ui toggle checkbox">
			<textarea id="mainwp-nwl-content" disabled></textarea><br />
			<em><?php echo __( 'This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp' ); ?></em>
		</div>
	</div>
	<div class="ui grid field">
		<label class="six wide column middle aligned"><?php _e( 'ZIP archives', 'mainwp' ); ?></label>
	  <div class="ten wide column ui toggle checkbox">
			<input type="checkbox" id="mainwp-zip-archives" <?php echo( ! isset( $task ) || $task->excludezip == 1 ? 'checked' : '' ); ?>>
		</div>
	</div>
	<div class="ui grid field">
	<label class="six wide column middle aligned"><?php _e( 'Custom excludes', 'mainwp' ); ?></label>
	  <div class="ten wide column ui toggle checkbox">
			<textarea id="excluded_folders_list">
			<?php
				$excluded = ( isset( $task ) ? $task->exclude : '' );
			if ( $excluded != '' ) {
				$excluded = explode( ',', $excluded );
				echo implode( "/\n", $excluded ) . "/\n";
			}
			?>
				</textarea>
		</div>
	</div>

	</div>
		<?php
	}

	public static function updateBackup() {
		global $current_user;

		$name = $_POST['name'];

		if ( $name == '' ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' ) ) ) );
		}

		$backupId = $_POST['id'];
		$task     = MainWP_DB::Instance()->getBackupTaskById( $backupId );

		if ( ! MainWP_Utility::can_edit_backuptask( $task ) ) {
			die( wp_json_encode( array( 'error' => __( 'Insufficient permissions. Is this task set by you?', 'mainwp' ) ) ) );
		}

		$schedule       = $_POST['schedule'];
		$type           = $_POST['type'];
		$excludedFolder = trim( $_POST['exclude'], "\n" );
		$excludedFolder = explode( "\n", $excludedFolder );
		$excludedFolder = array_map( array( 'MainWP_Utility', 'trimSlashes' ), $excludedFolder );
		$excludedFolder = array_map( 'htmlentities', $excludedFolder );
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
		$loadFilesBeforeZip             = isset( $_POST['loadFilesBeforeZip'] ) ? 1 : 0;

		if ( MainWP_DB::Instance()->updateBackupTask( $task->id, $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $_POST['filename'], $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip ) === false ) {
			die( wp_json_encode( array( 'error' => __( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
		} else {
			die( wp_json_encode( array( 'result' => __( 'Task updated successfully.', 'mainwp' ) ) ) );
		}
	}

	public static function addBackup() {
		global $current_user;

		$name = $_POST['name'];

		if ( $name == '' ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' ) ) ) );
		}

		$schedule       = $_POST['schedule'];
		$type           = $_POST['type'];
		$excludedFolder = trim( $_POST['exclude'], "\n" );
		$excludedFolder = explode( "\n", $excludedFolder );
		$excludedFolder = array_map( array( 'MainWP_Utility', 'trimSlashes' ), $excludedFolder );
		$excludedFolder = array_map( 'htmlentities', $excludedFolder );
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
		$loadFilesBeforeZip             = isset($_POST['loadFilesBeforeZip'] ) ? 1 : 0;

		$task = MainWP_DB::Instance()->addBackupTask( $current_user->ID, htmlentities( $name ), $schedule, $type, $excludedFolder, $sites, $groups, ( isset( $_POST['subfolder'] ) ? $_POST['subfolder'] : '' ), $_POST['filename'], 0, $_POST['excludebackup'], $_POST['excludecache'], $_POST['excludenonwp'], $_POST['excludezip'], $archiveFormat, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip);

		if ( ! $task ) {
			die( wp_json_encode( array( 'error' => __( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
		} else {
			do_action( 'mainwp_add_backuptask', $task->id );

			die( wp_json_encode( array( 'result' => __( 'Task created successfully.', 'mainwp' ) ) ) );
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
				$output .= '<strong>Backup task</strong> - ' . $task->name . '<br />';
				$output .= '<strong>Backup type</strong> - ' . ( $task->type == 'db' ? 'DATABASE BACKUP' : 'FULL BACKUP' ) . '<br />';
				$output .= '<strong>Backup schedule</strong> - ' . strtoupper( $task->schedule ) . '<br />';
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

				// When we receive a timeout, we return false..
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
					$error           = true;
				}
				if ( isset( $backupResult['ftp'] ) && $backupResult['ftp'] != 'success' ) {
					$tmpErrorOutput .= 'FTP: ' . $backupResult['ftp'] . '<br />';
					$error           = true;
				}
				if ( isset( $backupResult['dropbox'] ) && $backupResult['dropbox'] != 'success' ) {
					$tmpErrorOutput .= 'Dropbox: ' . $backupResult['dropbox'] . '<br />';
					$error           = true;
				}

				if ( isset( $backupResult['amazon'] ) && $backupResult['amazon'] != 'success' ) {
					$tmpErrorOutput .= 'Amazon: ' . $backupResult['amazon'] . '<br />';
					$error           = true;
				}

				if ( $error ) {
					$errorOutput .= 'Site: <strong>' . MainWP_Utility::getNiceURL( $website->url ) . '</strong><br />';
					$errorOutput .= $tmpErrorOutput . '<br />';
				}
			} catch ( Exception $e ) {
				if ( $errorOutput == null ) {
					$errorOutput = '';
				}
				$errorOutput  .= 'Site: <strong>' . MainWP_Utility::getNiceURL( $website->url ) . '</strong><br />';
				$errorOutput  .= MainWP_Error_Helper::getErrorMessage( $e ) . '<br />';
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

		// update completed sites
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
		} elseif ( $backupTask->archiveFormat == 'global' ) {
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

		return array(
			'sites'              => $allSites,
			'remoteDestinations' => $remoteDestinations,
		);
	}

	public static function getSiteDirectories() {
		$websites = array();
		if ( isset( $_REQUEST['site'] ) && ( $_REQUEST['site'] != '' ) ) {
			$siteId  = $_REQUEST['site'];
			$website = MainWP_DB::Instance()->getWebsiteById( $siteId );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$websites[] = $website;
			}
		} elseif ( isset( $_REQUEST['sites'] ) && ( $_REQUEST['sites'] != '' ) ) {
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
		} elseif ( isset( $_REQUEST['groups'] ) && ( $_REQUEST['groups'] != '' ) ) {
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
				echo '<li class="directory collapsed"><a href="#" rel="' . esc_attr( $_POST['dir'] . $file ) . '/">' . esc_html( $file ) . '<div title="Exclude form backup" class="exclude_folder_control"><img src="' . MAINWP_PLUGIN_URL . 'assets/images/exclude.png" /></div></a></li>';
			}
			echo '</ul>';

			if ( count( $excludedBackupFiles ) > 0 ) {
				echo '<div id="excludedBackupFiles" style="display:none">';
				foreach ( $excludedBackupFiles as $excludedBackupFile ) {
					echo esc_html($excludedBackupFile) . "\n";
				}
				echo '</div>';
			}

			if ( count( $excludedCacheFiles ) > 0 ) {
				echo '<div id="excludedCacheFiles" style="display:none">';
				foreach ( $excludedCacheFiles as $excludedCacheFile ) {
					echo esc_html($excludedCacheFile) . "\n";
				}
				echo '</div>';
			}

			if ( count( $excludedNonWPFiles ) > 0 ) {
				echo '<div id="excludedNonWPFiles" style="display:none">';
				foreach ( $excludedNonWPFiles as $excludedNonWPFile ) {
					echo esc_html( $excludedNonWPFile ) . "\n";
				}
				echo '</div>';
			}
		}
	}

	private static function addExcludedBackups( &$files, &$arr ) {
		$newExcludes = array();

		// Backup buddy
		$newExcludes[] = 'wp-content/uploads/backupbuddy_backups';
		$newExcludes[] = 'wp-content/uploads/backupbuddy_temp';
		$newExcludes[] = 'wp-content/uploads/pb_backupbuddy';

		// ManageWP
		$newExcludes[] = 'wp-content/managewp';

		// InfiniteWP
		$newExcludes[] = 'wp-content/infinitewp';

		// WordPress Backup to Dropbox
		$newExcludes[] = 'wp-content/backups';

		// BackWPUp
		$newExcludes[] = 'wp-content/uploads/backwpup*';

		// WP Complete Backup
		$newExcludes[] = 'wp-content/plugins/wp-complete-backup/storage';

		// Online Backup for WordPress
		$newExcludes[] = 'wp-content/backups';

		// XCloner
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

		// W3 Total Cache
		$newExcludes[] = 'wp-content/w3tc-cache';
		$newExcludes[] = 'wp-content/w3tc';
		$newExcludes[] = 'wp-content/cache/config';
		$newExcludes[] = 'wp-content/cache/minify';
		$newExcludes[] = 'wp-content/cache/page_enhanced';
		$newExcludes[] = 'wp-content/cache/tmp';

		// WP Super Cache
		$newExcludes[] = 'wp-content/cache/supercache';

		// Quick Cache
		$newExcludes[] = 'wp-content/cache/quick-cache';

		// Hyper Cache
		$newExcludes[] = 'wp-content/hyper-cache/cache';

		// WP Fastest Cache
		$newExcludes[] = 'wp-content/cache/all';

		// WP-Rocket
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
				// Remove from DB
				MainWP_DB::Instance()->removeBackupTask( $task->id );
				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( wp_json_encode( array( 'notask' => true ) ) );
	}

	public static function resumeBackup() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$task = MainWP_DB::Instance()->getBackupTaskById( $_POST['id'] );
			if ( MainWP_Utility::can_edit_backuptask( $task ) ) {
				MainWP_DB::Instance()->updateBackupTaskWithValues( $task->id, array( 'paused' => 0 ) );
				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( wp_json_encode( array( 'notask' => true ) ) );
	}

	public static function pauseBackup() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$task = MainWP_DB::Instance()->getBackupTaskById( $_POST['id'] );
			if ( MainWP_Utility::can_edit_backuptask( $task ) ) {
				MainWP_DB::Instance()->updateBackupTaskWithValues( $task->id, array( 'paused' => 1 ) );
				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			}
		}
		die( wp_json_encode( array( 'notask' => true ) ) );
	}

}
