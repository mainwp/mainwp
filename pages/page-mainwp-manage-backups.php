<?php
/**
 * MainWP Legacy Backups Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Backups
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Backups {

	/**
	 * Get Class Name.
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Subpages variable.
	 *
	 * @var undefined $subPages Subpages variable.
	 */
	public static $subPages;

	/**
	 * Whether or not to show the Backups Submenu.
	 *
	 * @var boolean $hideSubmenuBackups true|false,
	 * .
	 */
	private static $hideSubmenuBackups = false;

	/**
	 * Instance variable.
	 *
	 * @var null Instance variable.
	 */
	private static $instance = null;

	/**
	 * Create instance.
	 *
	 * @return self $instance.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Backups
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new MainWP_Manage_Backups();
		}

		return self::$instance;
	}

	/** Instantiate Hooks. */
	public static function init() {
		/**
		 * This hook allows you to render the Backups page header via the 'mainwp-pageheader-backups' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-backups
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-backups'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 *
		 * @see \MainWP_Manage_Backups::render_header
		 */
		add_action( 'mainwp-pageheader-backups', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Backups page footer via the 'mainwp-pagefooter-backups' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-backups
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-backups'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 *
		 * @see \MainWP_Manage_Backups::render_footer
		 */
		add_action( 'mainwp-pagefooter-backups', array( self::get_class_name(), 'render_footer' ) );
	}

	/**
	 * Instantiate Legacy Backups Menu.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_menu() {
		$enable_legacy_backup = get_option( 'mainwp_enableLegacyBackupFeature' );
		$mainwp_primaryBackup = get_option( 'mainwp_primaryBackup' );

		/**
		 * Backups Subpages
		 *
		 * Filters subpages for the Backups page.
		 *
		 * @since Unknown
		 */
		$customPage = apply_filters_deprecated( 'mainwp-getcustompage-backups', array( false ), '4.0.7.2', 'mainwp_getcustompage_backups' ); // @deprecated Use 'mainwp_getcustompage_backups' instead.
		$customPage = apply_filters( 'mainwp_getcustompage_backups', $customPage );

		if ( is_array( $customPage ) && isset( $customPage['slug'] ) && ! empty( $mainwp_primaryBackup ) ) {
			self::$hideSubmenuBackups = true;
			add_submenu_page( 'mainwp_tab', $customPage['title'], '<span id="mainwp-Backups">' . $customPage['title'] . '</span>', 'read', 'ManageBackups' . $customPage['slug'], $customPage['callback'] );
			MainWP_Menu::add_left_menu(
				array(
					'title'      => $customPage['title'],
					'parent_key' => 'mainwp_tab',
					'slug'       => 'ManageBackups' . $customPage['slug'],
					'href'       => 'admin.php?page=ManageBackups' . $customPage['slug'],
					'icon'       => '<i class="hdd outline icon"></i>',
				),
				1
			);

		} elseif ( $enable_legacy_backup ) {
					add_submenu_page( 'mainwp_tab', esc_html__( 'Backups', 'mainwp' ), '<span id="mainwp-Backups">' . esc_html__( 'Backups', 'mainwp' ) . '</span>', 'read', 'ManageBackups', array( self::get_class_name(), 'render_manager' ) );
			if ( mainwp_current_user_have_right( 'dashboard', 'add_backup_tasks' ) ) {
				if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ) {
					add_submenu_page( 'mainwp_tab', esc_html__( 'Add New Schedule', 'mainwp' ), '<div class="mainwp-hidden">' . esc_html__( 'Add New', 'mainwp' ) . '</div>', 'read', 'ManageBackupsAddNew', array( self::get_class_name(), 'render_new' ) );
				}
			}
		} else {
			return;
		}

		/**
		 * This hook allows you to add extra sub pages to the Backups page via the 'mainwp-getsubpages-backups' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-backups
		 */

		$sub_pages      = array();
		$sub_pages      = apply_filters_deprecated( 'mainwp-getsubpages-backups', array( $sub_pages ), '4.0.7.2', 'mainwp_getsubpages_backups' );  // @deprecated Use 'mainwp_getsubpages_backups' instead.
		self::$subPages = apply_filters( 'mainwp_getsubpages_backups', $sub_pages );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageBackups' . $subPage['slug'], $subPage['callback'] );
			}
		}
		self::init_left_menu( self::$subPages, $enable_legacy_backup );
	}

	/**
	 * Instantiate legacy backups subpages.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_subpages_menu() {
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
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackups' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Backups', 'mainwp' ); ?></a>
						<?php if ( mainwp_current_user_have_right( 'dashboard', 'add_backup_tasks' ) ) { ?>
							<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ) { ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackupsAddNew' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
							<?php } ?>
						<?php } ?>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' . $subPage['slug'] ) ) {
									continue;
							}
							?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackups' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Instantiate Legacy Backups Menu.
	 *
	 * @param array $subPages Legacy Backup Subpages.
	 * @param bool  $enableLegacyBackup ture|false, whether or not to enable menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_left_menu( $subPages = array(), $enableLegacyBackup = true ) {
		if ( ! self::$hideSubmenuBackups && $enableLegacyBackup ) {
			MainWP_Menu::add_left_menu(
				array(
					'title'      => esc_html__( 'Backups', 'mainwp' ),
					'parent_key' => 'managesites',
					'slug'       => 'ManageBackups',
					'href'       => 'admin.php?page=ManageBackups',
					'icon'       => '<i class="hdd outline icon"></i>',
				),
				1
			);

			$init_sub_subleftmenu = array(
				array(
					'title'      => esc_html__( 'Manage Backups', 'mainwp' ),
					'parent_key' => 'ManageBackups',
					'href'       => 'admin.php?page=ManageBackups',
					'slug'       => 'ManageBackups',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'Add New', 'mainwp' ),
					'parent_key' => 'ManageBackups',
					'href'       => 'admin.php?page=ManageBackupsAddNew',
					'slug'       => 'ManageBackupsAddNew',
					'right'      => 'add_backup_tasks',
				),
			);

			MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ManageBackups', 'ManageBackups' );

			foreach ( $init_sub_subleftmenu as $item ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
					continue;
				}
				MainWP_Menu::add_left_menu( $item, 2 );
			}
		}
	}

	/**
	 * Render MainWP Legacy Backups Page Header.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	public static function render_header( $shownPage = '' ) {

		$params = array(
			'title' => esc_html__( 'Backups', 'mainwp' ),
		);

		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		$renderItems[] = array(
			'title'    => esc_html__( 'Manage Backups', 'mainwp' ),
			'href'     => 'admin.php?page=ManageBackups',
			'active'   => ( '' === $shownPage ) ? true : false,
			'disabled' => MainWP_Menu::is_disable_menu_item( 3, 'ManageBackups' ) ? true : false,
		);

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ) {
			$renderItems[] = array(
				'title'    => esc_html__( 'Add New', 'mainwp' ),
				'href'     => 'admin.php?page=ManageBackupsAddNew',
				'access'   => mainwp_current_user_have_right( 'dashboard', 'add_backup_tasks' ),
				'active'   => ( 'AddNew' === $shownPage ) ? true : false,
				'disabled' => MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsAddNew' ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageBackupsEdit' ) ) {
			if ( 'ManageBackupsEdit' === $shownPage ) {
				$renderItems[] = array(
					'title'  => esc_html__( 'Edit', 'mainwp' ),
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
				$item['title']  = esc_html( $subPage['title'] );
				$item['href']   = 'admin.php?page=ManageBackups' . $subPage['slug'];
				$item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * Render MainWP Legacy Backups Footer.
	 */
	public static function render_footer() {
		echo '</div>';
	}

	/**
	 * Render Legacy Backups page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_tasks_for_user()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::can_edit_backuptask()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::validate_backup_tasks()
	 */
	public static function render_manager() {
		$backupTask = null;
		//phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['id'] ) ) {
			if ( ! mainwp_current_user_have_right( 'dashboard', 'edit_backup_tasks' ) ) {
				mainwp_do_not_have_permissions( esc_html__( 'edit backup tasks', 'mainwp' ) );
				return;
			}
			$backupTaskId = intval( $_GET['id'] );

			$backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
			if ( ! MainWP_Manage_Backups_Handler::can_edit_backuptask( $backupTask ) ) {
				$backupTask = null;
			}

			if ( ! empty( $backupTask ) ) {
				if ( ! MainWP_Manage_Backups_Handler::validate_backup_tasks( array( $backupTask ) ) ) {
					$backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
				}
			}
		}

		$primary_methods      = array();
		$primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead.
		$primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}

		if ( null === $backupTask ) {

			$backup_items = MainWP_DB_Backup::instance()->get_backup_tasks_for_user();
			if ( ! MainWP_Manage_Backups_Handler::validate_backup_tasks( $backup_items ) ) {
				$backup_items = MainWP_DB_Backup::instance()->get_backup_tasks_for_user();
			}

			self::render_header( '' );
			?>
			<?php if ( 0 === count( $primaryBackupMethods ) ) { ?>
				<div class="mainwp-notice mainwp-notice-blue"><?php printf( esc_html__( 'Did you know that MainWP has extensions for working with popular backup plugins? Visit the %1$sextensions site%2$s for options.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); ?></div>
			<?php } ?>
			<div class="ui alt segment">
				<div id="mainwp_managebackups_add_message" class="mainwp-notice mainwp-notice-green" style="display:
				<?php
				if ( isset( $_GET['a'] ) && '1' === $_GET['a'] ) {
					echo 'block';
				} else {
					echo 'none';
				}
				?>
				">
				<?php
				if ( isset( $_GET['a'] ) && '1' === $_GET['a'] ) {
					echo '<p>' . esc_html__( 'The backup task was added successfully', 'mainwp' ) . '</p>';
				}
				?>
					</div>

				<form method="post" class="mainwp-table-container">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<?php
					MainWP_UI::render_modal_edit_notes();
					self::instance()->display( $backup_items );
					?>
				</form>
			</div>

			<div class="ui modal" id="managebackups-task-status-box" tabindex="0">
				<div class="header">Running task</div>
					<div class="content mainwp-modal-content"></div>
					<div class="actions mainwp-modal-actions">
						<input id="managebackups-task-status-close" type="button" name="Close" value="<?php esc_attr_e( 'Cancel', 'mainwp' ); ?>" class="button" />
					</div>
				</div>
			<?php
			self::render_footer( '' );
		} else {
			self::render_edit( $backupTask );
		}
		//phpcs:enable 
	}

	/**
	 * Render MainWP Legacy Backups Table.
	 *
	 * @param mixed $backup_items List Item.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_group_id()
	 */
	public function display( $backup_items ) {
		$can_trigger = true;
		if ( ! mainwp_current_user_have_right( 'dashboard', 'run_backup_tasks' ) ) {
			$can_trigger = false;
		}
		?>
		<table id="mainwp-backups-table" class="ui padded selectable compact single line table">
			<thead class="full-width">
				<tr>
					<th id="mainwp-title"><?php esc_html_e( 'Task name', 'mainwp' ); ?></th>
					<th id="mainwp-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
					<th id="mainwp-schedule"><?php esc_html_e( 'Schedule', 'mainwp' ); ?></th>
					<th id="mainwp-dest" class="no-sort"><?php esc_html_e( 'Destination', 'mainwp' ); ?></th>
					<th id="mainwp-website"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th id="mainwp-details" class="no-sort"><?php esc_html_e( 'Details', 'mainwp' ); ?></th>
					<?php if ( $can_trigger ) { ?>
					<th id="mainwp-trigger" class="no-sort"><?php esc_html_e( 'Trigger', 'mainwp' ); ?></th>
					<?php } ?>
					<th id="mainwp-actions" class="no-sort"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( $backup_items ) {
					$columns = array(
						'task_name'   => esc_html__( 'Task Name', 'mainwp' ),
						'type'        => esc_html__( 'Type', 'mainwp' ),
						'schedule'    => esc_html__( 'Schedule', 'mainwp' ),
						'destination' => esc_html__( 'Destination', 'mainwp' ),
						'websites'    => esc_html__( 'Websites', 'mainwp' ),
						'details'     => esc_html__( 'Details', 'mainwp' ),
						'trigger'     => esc_html__( 'Trigger', 'mainwp' ),
						'actions'     => esc_html__( 'Trigger', 'mainwp' ),
					);

					if ( ! $can_trigger ) {
						unset( $columns['trigger'] );
					}

					foreach ( $backup_items as $item ) {
						$sites  = ( empty( $item->sites ) ? array() : explode( ',', $item->sites ) );
						$groups = ( empty( $item->groups ) ? array() : explode( ',', $item->groups ) );
						foreach ( $groups as $group ) {
							$websites = MainWP_DB::instance()->get_websites_by_group_id( $group );
							if ( empty( $websites ) ) {
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
					jQuery( '#mainwp-backups-table' ).DataTable( {
							"colReorder" : true,
							"stateSave":  true,
							"pagingType": "full_numbers",
							"order": [],
							"columnDefs": [ {
								"targets": 'no-sort',
								"orderable": false
							} ],
							"drawCallback": function( settings ) {
								jQuery( '#mainwp-backups-table .ui.dropdown' ).dropdown();
							},
					} );
				} );
		</script>
		<?php
	}

	/**
	 * Single row Content.
	 *
	 * @param mixed $item Item to go in column.
	 * @param mixed $columns Columns Array.
	 */
	public function single_row( $item, $columns ) {
		?>
		<tr>
		<?php
		foreach ( $columns as $column_name => $title ) {
			if ( method_exists( $this, 'column_' . $column_name ) ) {
				echo '<td>';
				echo call_user_func( array( $this, 'column_' . $column_name ), $item ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '</td>';
			} else {
				echo '<td></td>';
			}
		}
		?>
		</tr>
		<?php
	}

	/**
	 * Column Actions.
	 *
	 * @param mixed $item Item to go in column.
	 *
	 * @return string Action content.
	 */
	public function column_actions( $item ) {

		$actions = array(
			'edit'   => sprintf( '<a class="item" href="admin.php?page=ManageBackups&id=%s"><i class="edit outline icon"></i> ' . esc_html__( 'Edit', 'mainwp' ) . '</a>', $item->id ),
			'delete' => sprintf( '<a class="submitdelete item" href="#" task_id="%s" onClick="return managebackups_remove(this);"><i class="trash alternate outline icon"></i> ' . esc_html__( 'Delete', 'mainwp' ) . '</a>', $item->id ),
		);

		if ( ! mainwp_current_user_have_right( 'dashboard', 'edit_backup_tasks' ) ) {
			unset( $actions['edit'] );
		}

		if ( ! mainwp_current_user_have_right( 'dashboard', 'delete_backup_tasks' ) ) {
			unset( $actions['delete'] );
		}

		if ( 1 === (int) $item->paused ) {
			if ( mainwp_current_user_have_right( 'dashboard', 'pause_resume_backup_tasks' ) ) {
				$actions['resume'] = sprintf( '<a href="#" class="item" task_id="%s" onClick="return managebackups_resume(this)"><i class="play icon"></i> ' . esc_html__( 'Resume', 'mainwp' ) . '</a>', $item->id );
			}
		} elseif ( mainwp_current_user_have_right( 'dashboard', 'pause_resume_backup_tasks' ) ) {
				$actions['pause'] = sprintf( '<a href="#" class="item" task_id="%s" onClick="return managebackups_pause(this)"><i class="pause icon"></i> ' . esc_html__( 'Pause', 'mainwp' ) . '</a>', $item->id );
		}

		$out = '<div class="ui left pointing dropdown icon mini basic green button" style="z-index:999">
						<i class="ellipsis horizontal icon"></i>
						<div class="menu">
						<div class="header">' . esc_html_e( 'Backup Actions', 'mainwp' ) . '</div>
						<div class="divider"></div>';
		foreach ( $actions as $action => $link ) {
			$out .= $link;
		}
		$out .= '</div>
						</div>';

		return $out;
	}

	/**
	 * Column Task Name.
	 *
	 * @param mixed $item Item to go in column.
	 *
	 * @return string Action content.
	 */
	public function column_task_name( $item ) {
		return stripslashes( $item->name );
	}

	/**
	 * Column Type.
	 *
	 * @param mixed $item Item to go in column.
	 *
	 * @return string Action content.
	 */
	public function column_type( $item ) {
		return ( 'db' === $item->type ? esc_html__( 'DATABASE BACKUP', 'mainwp' ) : esc_html__( 'FULL BACKUP', 'mainwp' ) );
	}

	/**
	 * Column Schdule.
	 *
	 * @param mixed $item Item to go in column.
	 *
	 * @return string Action content.
	 */
	public function column_schedule( $item ) {
		return strtoupper( $item->schedule );
	}

	/**
	 * Column Destination.
	 *
	 * @param mixed $item Item to go in column.
	 *
	 * @return string Action content.
	 */
	public function column_destination( $item ) {
		$extraOutput = apply_filters( 'mainwp_backuptask_column_destination', '', $item->id );
		if ( '' !== $extraOutput ) {
			return trim( $extraOutput, '<br />' );
		}

		return esc_html__( 'SERVER', 'mainwp' );
	}

	/**
	 * Column Websites.
	 *
	 * @param mixed $item Item to go in column.
	 */
	public function column_websites( $item ) {
		if ( 0 === count( $item->the_sites ) ) {
			echo( '<span style="color: red; font-weight: bold; ">' . count( $item->the_sites ) . '</span>' );
		} else {
			echo count( $item->the_sites );
		}
	}

	/**
	 * Column Details.
	 *
	 * @param mixed $item Item to go in column.
	 *
	 * @return string Action content.
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
	 */
	public function column_details( $item ) {
		$output  = '<strong>' . esc_html__( 'LAST RUN MANUALLY: ', 'mainwp' ) . '</strong>' . ( empty( $item->last_run_manually ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->last_run_manually ) ) ) . '<br />';
		$output .= '<strong>' . esc_html__( 'LAST RUN: ', 'mainwp' ) . '</strong>' . ( empty( $item->last_run ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->last_run ) ) ) . '<br />';
		$output .= '<strong>' . esc_html__( 'LAST COMPLETED: ', 'mainwp' ) . '</strong>' . ( empty( $item->completed ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->completed ) ) ) . '<br />';
		$output .= '<strong>' . esc_html__( 'NEXT RUN: ', 'mainwp' ) . '</strong>' . ( empty( $item->last_run ) ? esc_html__( 'Any minute', 'mainwp' ) : MainWP_Utility::format_timestamp( ( 'daily' === $item->schedule ? ( 60 * 60 * 24 ) : ( 'weekly' === $item->schedule ? ( 60 * 60 * 24 * 7 ) : ( 60 * 60 * 24 * 30 ) ) ) + MainWP_Utility::get_timestamp( $item->last_run ) ) );
		$output .= '<strong>';
		if ( ! empty( $item->last_run ) && $item->completed < $item->last_run ) {
			$output         .= esc_html__( '<br />CURRENTLY RUNNING: ', 'mainwp' ) . '</strong>';
			$completed_sites = $item->completed_sites;
			if ( '' !== $completed_sites ) {
				$completed_sites = json_decode( $completed_sites, 1 );
			}
			if ( ! is_array( $completed_sites ) ) {
				$completed_sites = array();
			}
			$output .= count( $completed_sites ) . ' / ' . count( $item->the_sites );
		}
		return $output;
	}

	/**
	 *  Column Trigger.
	 *
	 * @param mixed $item Item to go in column.
	 *
	 * @return string Action content.
	 */
	public function column_trigger( $item ) {
		return '<span class="backup_run_loading"><img src="' . MAINWP_PLUGIN_URL . 'assets/images/loader.gif" /></span>&nbsp;<a href="#" class="backup_run_now" task_id="' . $item->id . '" task_type="' . $item->type . '">' . esc_html__( 'Run now', 'mainwp' ) . '</a>';
	}

	/**
	 * Render edit.
	 *
	 * @param mixed $task Task to edit.
	 */
	public static function render_edit( $task ) {
		self::render_header( 'ManageBackupsEdit' );
		?>
		<div class="ui alt segment">
			<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<form method="POST" action="" class="ui form">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<input type="hidden" name="mainwp_managebackups_edit_id" id="mainwp_managebackups_edit_id" value="<?php echo esc_attr( $task->id ); ?>"/>
				<?php
				self::render_new_edit( $task );
				?>
			</form>
		</div>
		<?php
		self::render_footer( 'ManageBackupsEdit' );
	}

	/** Render New Task Form. */
	public static function render_new() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'add_backup_tasks' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'add backup tasks', 'mainwp' ) );
			return;
		}
		self::render_header( 'AddNew' );
		?>
		<div class="ui alt segment">
			<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<form method="POST" action="" id="mainwp-backup-task-form" class="ui form">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<?php self::render_new_edit( null ); ?>
			</form>
		</div>
		<?php
		self::render_footer( 'AddNew' );
	}

	/**
	 * Render New edit Form.
	 *
	 * @param mixed $task Task to edit.
	 */
	public static function render_new_edit( $task ) {
		$selected_websites = array();
		$selected_groups   = array();
		if ( ! empty( $task ) ) {
			if ( '' !== $task->sites ) {
				$selected_websites = explode( ',', $task->sites );
			}
			if ( '' !== $task->groups ) {
				$selected_groups = explode( ',', $task->groups );
			}
		}
		?>

		<div class="mainwp-main-content">
			<?php self::render_schedule_backup(); ?>
		</div>
		<div class="mainwp-side-content mainwp-no-padding">
			<div class="mainwp-select-sites">
				<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
				<?php
				$sel_params = array(
					'selected_sites'       => $selected_websites,
					'selected_groups'      => $selected_groups,
					'enable_offline_sites' => true,
				);
				MainWP_UI_Select_Sites::select_sites_box( $sel_params );
				?>
			</div>
			<div class="ui divider"></div>
			<div class="mainwp-search-submit">
				<?php if ( ! empty( $task ) ) : ?>
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

	/**
	 * Render Scheduled Backup.
	 *
	 * @return void
	 * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::can_edit_backuptask()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::validate_backup_tasks()
	 */
	public static function render_schedule_backup() {
		$backupTask   = null;
		$backupTaskId = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! empty( $backupTaskId ) ) {
			if ( ! mainwp_current_user_have_right( 'dashboard', 'edit_backup_tasks' ) ) {
				mainwp_do_not_have_permissions( esc_html__( 'edit backup tasks', 'mainwp' ) );
				return;
			}

			$backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
			if ( ! MainWP_Manage_Backups_Handler::can_edit_backuptask( $backupTask ) ) {
				$backupTask = null;
			}

			if ( ! empty( $backupTask ) ) {
				if ( ! MainWP_Manage_Backups_Handler::validate_backup_tasks( array( $backupTask ) ) ) {
					$backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
				}
			}
		}
		$task = $backupTask;

		$selected_websites = array();
		$selected_groups   = array();
		if ( ! empty( $task ) ) {
			if ( '' !== $task->sites ) {
				$selected_websites = explode( ',', $task->sites );
			}
			if ( '' !== $task->groups ) {
				$selected_groups = explode( ',', $task->groups );
			}
		}

		$globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
		if ( false === $globalArchiveFormat ) {
			$globalArchiveFormat = 'tar.gz';
		}
		if ( 'zip' === $globalArchiveFormat ) {
			$globalArchiveFormatText = 'Zip';
		} elseif ( 'tar' === $globalArchiveFormat ) {
			$globalArchiveFormatText = 'Tar';
		} elseif ( 'tar.gz' === $globalArchiveFormat ) {
			$globalArchiveFormatText = 'Tar GZip';
		} elseif ( 'tar.bz2' === $globalArchiveFormat ) {
			$globalArchiveFormatText = 'Tar BZip2';
		}

		$archiveFormat = isset( $task ) ? $task->archiveFormat : 'site';
		$useGlobal     = ( 'global' === $archiveFormat );
		$useSite       = ( empty( $archiveFormat ) || 'site' === $archiveFormat );

		self::render_task_details( $task, $globalArchiveFormatText, $archiveFormat, $useGlobal, $useSite );
	}

	/**
	 * Render Task Details.
	 *
	 * @param mixed $task Task to perform.
	 * @param mixed $globalArchiveFormatText Global Archived Format Text.
	 * @param mixed $archiveFormat Archive Format.
	 * @param mixed $useGlobal Use Global.
	 * @param mixed $useSite Use Site.
	 */
	public static function render_task_details( $task, $globalArchiveFormatText, $archiveFormat, $useGlobal, $useSite ) {
		?>

		<div class="ui divider hidden"></div>
		<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<h3 class="header"><?php esc_html_e( 'Backup Details', 'mainwp' ); ?></h3>
			<div class="ui grid field">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Backup task name', 'mainwp' ); ?></label>
				<div class="ten wide column">
					<input type="text" id="mainwp_managebackups_add_name" name="mainwp_managebackups_add_name" value="<?php echo ( isset( $task ) ? esc_html( stripslashes( $task->name ) ) : '' ); ?>"/>
				</div>
			</div>
			<div class="ui grid field">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Backup file name', 'mainwp' ); ?></label>
				<div class="ten wide column">
					<input type="text" id="backup_filename" name="backup_filename" value="<?php echo( isset( $task ) ? esc_html( stripslashes( $task->filename ) ) : '' ); ?>"/>
				</div>
			</div>
			<div class="ui grid field">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Backup type', 'mainwp' ); ?></label>
				<div class="ten wide column">
					<select name="mainwp-backup-type" id="mainwp-backup-type" class="ui dropdown">
						<option value="full" <?php echo( ! isset( $task ) || 'full' === $task->type ? 'selected' : '' ); ?>><?php esc_html_e( 'Full Backup', 'mainwp' ); ?></option>
						<option value="db" <?php echo( isset( $task ) && 'db' === $task->type ? 'selected' : '' ); ?>><?php esc_html_e( 'Database Backup', 'mainwp' ); ?></option>
					</select>
				</div>
			</div>
			<div class="ui grid field">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Schedule', 'mainwp' ); ?></label>
				<div class="ten wide column">
					<select name="mainwp-backup-task-schedule" id="mainwp-backup-task-schedule" class="ui dropdown">
						<option value="daily" <?php echo( ! isset( $task ) || 'daily' === $task->schedule ? 'selected' : '' ); ?>><?php esc_html_e( 'Daily', 'mainwp' ); ?></option>
						<option value="weekly" <?php echo( ! isset( $task ) || 'weekly' === $task->schedule ? 'selected' : '' ); ?>><?php esc_html_e( 'Weekly', 'mainwp' ); ?></option>
						<option value="monthly" <?php echo( ! isset( $task ) || 'monthly' === $task->schedule ? 'selected' : '' ); ?>><?php esc_html_e( 'Monthly', 'mainwp' ); ?></option>
					</select>
				</div>
			</div>
			<div class="ui grid field">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Archive type', 'mainwp' ); ?></label>
				<div class="ten wide column">
					<select name="mainwp_archiveFormat" id="mainwp_archiveFormat" class="ui dropdown">
						<option value="site"
						<?php
						if ( $useSite ) :
							?>
							selected<?php endif; ?>><?php esc_html_e( 'Site specific setting', 'mainwp' ); ?></option>
						<option value="global"
						<?php
						if ( $useGlobal ) :
							?>
							selected<?php endif; ?>><?php esc_html_e( 'Global setting', 'mainwp' ); ?> (<?php echo esc_html( $globalArchiveFormatText ); ?>)</option>
						<option value="zip"
						<?php
						if ( 'zip' === $archiveFormat ) :
							?>
							selected<?php endif; ?>><?php esc_html_e( 'Zip', 'mainwp' ); ?></option>
						<option value="tar"
						<?php
						if ( 'tar' === $archiveFormat ) :
							?>
							selected<?php endif; ?>><?php esc_html_e( 'Tar', 'mainwp' ); ?></option>
						<option value="tar.gz"
						<?php
						if ( 'tar.gz' === $archiveFormat ) :
							?>
							selected<?php endif; ?>><?php esc_html_e( 'Tar GZip', 'mainwp' ); ?></option>
						<option value="tar.bz2"
						<?php
						if ( 'tar.bz2' === $archiveFormat ) :
							?>
							selected<?php endif; ?>><?php esc_html_e( 'Tar BZip2', 'mainwp' ); ?></option>
					</select>
				</div>
			</div>
			<?php
			$style = isset( $task ) && 'db' === $task->type ? 'style="display: none;"' : '';
			?>
			<div class="mainwp-backup-full-exclude" <?php echo wp_kses( $style ); ?>>
				<h3 class="header"><?php esc_html_e( 'Backup Excludes', 'mainwp' ); ?></h3>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Known backup locations', 'mainwp' ); ?></label>
					<div class="ten wide column ui toggle checkbox">
						<input type="checkbox" id="mainwp-known-backup-locations" <?php echo( ! isset( $task ) || 1 === (int) $task->excludebackup ? 'checked' : '' ); ?>>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"></label>
					<div class="ten wide column ui toggle checkbox">
						<textarea id="mainwp-kbl-content" disabled></textarea><br />
						<em><?php esc_html_e( 'This adds known backup locations of popular WordPress backup plugins to the exclude list. Old backups can take up a lot of space and can cause your current MainWP backup to timeout.', 'mainwp' ); ?></em>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Known cache locations', 'mainwp' ); ?></label>
					<div class="ten wide column ui toggle checkbox">
						<input type="checkbox" id="mainwp-known-cache-locations" <?php echo( ! isset( $task ) || 1 === (int) $task->excludecache ? 'checked' : '' ); ?>><br />
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"></label>
					<div class="ten wide column ui toggle checkbox">
						<textarea id="mainwp-kcl-content" disabled></textarea><br />
						<em><?php esc_html_e( 'This adds known cache locations of popular WordPress cache plugins to the exclude list. A cache can be massive with thousands of files and can cause your current MainWP backup to timeout. Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp' ); ?></em>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Non-WordPress folders', 'mainwp' ); ?></label>
					<div class="ten wide column ui toggle checkbox">
						<input type="checkbox" id="mainwp-non-wordpress-folders" <?php echo( ! isset( $task ) || 1 === (int) $task->excludenonwp ? 'checked' : '' ); ?>><br />
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"></label>
					<div class="ten wide column ui toggle checkbox">
						<textarea id="mainwp-nwl-content" disabled></textarea><br />
						<em><?php esc_html_e( 'This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp' ); ?></em>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'ZIP archives', 'mainwp' ); ?></label>
					<div class="ten wide column ui toggle checkbox">
						<input type="checkbox" id="mainwp-zip-archives" <?php echo( ! isset( $task ) || 1 === (int) $task->excludezip ? 'checked' : '' ); ?>>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Custom excludes', 'mainwp' ); ?></label>
					<div class="ten wide column ui toggle checkbox">
						<textarea id="excluded_folders_list">
						<?php
							$excluded = ( isset( $task ) ? $task->exclude : '' );
						if ( '' !== $excluded ) {
							$excluded = explode( ',', $excluded );
							$excluded = is_array( $excluded ) ? array_map( 'sanitize_text_field', $excluded ) : array();
							echo implode( "/\n", $excluded ) . "/\n"; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
						</textarea>
					</div>
				</div>
			</div>
		<?php
	}


	/**
	 * Method render_settings()
	 *
	 * Render backup settings.
	 */
	public static function render_settings() {

		$backupsOnServer            = get_option( 'mainwp_backupsOnServer' );
		$backupOnExternalSources    = get_option( 'mainwp_backupOnExternalSources' );
		$archiveFormat              = get_option( 'mainwp_archiveFormat' );
		$maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
		$maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
		$maximumFileDescriptorsAuto = ( 1 === $maximumFileDescriptorsAuto || false === $maximumFileDescriptorsAuto );

		$notificationOnBackupFail  = get_option( 'mainwp_notificationOnBackupFail' );
		$notificationOnBackupStart = get_option( 'mainwp_notificationOnBackupStart' );
		$chunkedBackupTasks        = get_option( 'mainwp_chunkedBackupTasks' );
		$enableLegacyBackupFeature = get_option( 'mainwp_enableLegacyBackupFeature' );

		$loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
		$loadFilesBeforeZip = ( 1 === $loadFilesBeforeZip || false === $loadFilesBeforeZip );

		$primaryBackup        = get_option( 'mainwp_primaryBackup' );
		$primary_methods      = array();
		$primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead.
		$primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}

		/**
		 * MainWP use external primary backup method.
		 *
		 * @global string
		 */
		global $mainwpUseExternalPrimaryBackupsMethod;

		$hidden_elems = false;
		if ( ! $enableLegacyBackupFeature || ( ! empty( $primaryBackup ) && $primaryBackup === $mainwpUseExternalPrimaryBackupsMethod ) ) {
			$hidden_elems = true;
		}
		?>
		<h3 class="ui dividing header">
			<?php esc_html_e( 'Backup Settings', 'mainwp' ); ?>
			<div class="sub header"><?php printf( esc_html__( 'MainWP is actively moving away from further development of the native backups feature. The best long-term solution would be one of the %1$sBackup Extensions%2$s.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); ?></div>
		</h3>

		<?php if ( 0 < count( $primaryBackupMethods ) ) : ?>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Select primary backup system', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<select class="ui dropdown" name="mainwp_primaryBackup" id="mainwp_primaryBackup">
					<?php if ( $enableLegacyBackupFeature ) { ?>
						<option value="" ><?php esc_html_e( 'Native backups', 'mainwp' ); ?></option>
					<?php } ?>
					<?php
					foreach ( $primaryBackupMethods as $method ) {
						echo '<option value="' . esc_attr( $method['value'] ) . '" ' . ( ( $primaryBackup === $method['value'] ) ? 'selected' : '' ) . '>' . esc_html( $method['title'] ) . '</option>';
					}
					?>
					
				</select>
			</div>
		</div>
		<?php endif; ?>

		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Enable legacy backup feature', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox">
				<input type="checkbox" name="mainwp_options_enableLegacyBackupFeature" id="mainwp_options_enableLegacyBackupFeature" <?php echo ( 0 === (int) $enableLegacyBackupFeature ? '' : 'checked="true"' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?>>
			<label class="six wide column middle aligned"><?php esc_html_e( 'Backups on server', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<input type="text" name="mainwp_options_backupOnServer" value="<?php echo ( false === $backupsOnServer ? 1 : intval( $backupsOnServer ) ); ?>"/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?>>
			<label class="six wide column middle aligned"><?php esc_html_e( 'Backups on remote storage', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<span data-tooltip="<?php esc_attr_e( 'The number of backups to keep on your external sources. This does not affect backups on the server. 0 sets unlimited.', 'mainwp' ); ?>" data-inverted=""><input type="text" name="mainwp_options_backupOnExternalSources" value="<?php echo ( false === $backupOnExternalSources ? 1 : intval( $backupOnExternalSources ) ); ?>"/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?>>
			<label class="six wide column middle aligned"><?php esc_html_e( 'Archive format', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<select class="ui dropdown" name="mainwp_archiveFormat" id="mainwp_archiveFormat">
					<option value="zip"
					<?php
					if ( 'zip' === $archiveFormat ) :
						?>
						selected<?php endif; ?>>Zip</option>
					<option value="tar"
					<?php
					if ( 'tar' === $archiveFormat ) :
						?>
						selected<?php endif; ?>>Tar</option>
					<option value="tar.gz"
					<?php
					if ( ( false === $archiveFormat ) || ( 'tar.gz' === $archiveFormat ) ) :
						?>
						selected<?php endif; ?>>Tar GZip</option>
					<option value="tar.bz2"
					<?php
					if ( 'tar.bz2' === $archiveFormat ) :
						?>
						selected<?php endif; ?>>Tar BZip2</option>
				</select>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> <?php
		if ( empty( $hidden_elems ) && 'zip' !== $archiveFormat ) {
			echo 'style="display: none;"';}
		?>
		>
			<label class="six wide column middle aligned"><?php esc_html_e( 'Auto detect maximum file descriptors on child sites', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox">
				<input type="checkbox" name="mainwp_maximumFileDescriptorsAuto" id="mainwp_maximumFileDescriptorsAuto" value="1" <?php echo ( $maximumFileDescriptorsAuto ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> <?php
		if ( empty( $hidden_elems ) && 'zip' !== $archiveFormat ) {
			echo 'style="display: none;"';}
		?>
		>
			<label class="six wide column middle aligned"><?php esc_html_e( 'Maximum file descriptors fallback value', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<input type="text" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors" value="<?php echo ( false === $maximumFileDescriptors ? 150 : intval( $maximumFileDescriptors ) ); ?>"/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> <?php
		if ( empty( $hidden_elems ) && 'zip' !== $archiveFormat ) {
			echo 'style="display: none;"';}
		?>
		>
			<label class="six wide column middle aligned"><?php esc_html_e( 'Load files in memory before zipping', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox">
				<input type="checkbox" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip" value="1" <?php echo ( $loadFilesBeforeZip ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> >
			<label class="six wide column middle aligned"><?php esc_html_e( 'Send email when backup fails', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox">
				<input type="checkbox" name="mainwp_options_notificationOnBackupFail" id="mainwp_options_notificationOnBackupFail" value="1" <?php echo ( $notificationOnBackupFail ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> >
			<label class="six wide column middle aligned"><?php esc_html_e( 'Send email when backup starts', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox">
				<input type="checkbox" name="mainwp_options_notificationOnBackupStart"  id="mainwp_options_notificationOnBackupStart" value="1" <?php echo ( $notificationOnBackupStart ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> >
			<label class="six wide column middle aligned"><?php esc_html_e( 'Execute backup tasks in chunks', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox">
				<input type="checkbox" name="mainwp_options_chunkedBackupTasks"  id="mainwp_options_chunkedBackupTasks" value="1" <?php echo ( $chunkedBackupTasks ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>
		<?php
	}
}
