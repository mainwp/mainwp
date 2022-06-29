<?php
/**
 * MainWP Manage Groups.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Groups
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Groups {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method init_menu()
	 *
	 * Add Groups Sub Menu.
	 */
	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab',
			__( 'Groups', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Groups', 'mainwp' ) . '</div>',
			'read',
			'ManageGroups',
			array(
				self::get_class_name(),
				'render_all_groups',
			)
		);
	}

	/**
	 * Method get_group_list_content()
	 *
	 * Get group list contents.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_and_count()
	 */
	public static function get_group_list_content() {

		$groups = MainWP_DB_Common::instance()->get_groups_and_count();

		foreach ( $groups as $group ) {
			self::create_group_item( $group );
		}
	}

	/**
	 * Metod create_group_item()
	 *
	 * Group Data Table Row.
	 *
	 * @param array $group Array of group data.
	 */
	private static function create_group_item( $group ) {
		?>
		<a class="item" id="<?php echo $group->id; ?>">
			<div class="ui small label"><?php echo property_exists( $group, 'nrsites' ) ? $group->nrsites : 0; ?></div>
			<input type="hidden" value="<?php echo $group->name; ?>" id="mainwp-hidden-group-name">
			<input type="hidden" value="<?php echo $group->id; ?>" id="mainwp-hidden-group-id">
			<?php echo $group->name; ?>
		</a>
		<?php
	}

	/**
	 * Method get_website_list_content()
	 *
	 * Get the Child Site list content.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
	 */
	public static function get_website_list_content() {
		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			$note       = html_entity_decode( $website->note );
			$esc_note   = MainWP_Utility::esc_content( $note );
			$strip_note = wp_strip_all_tags( $esc_note );
			?>
			<tr id="<?php echo esc_attr( $website->id ); ?>">
				<td>
			<div class="item ui checkbox">
						<input type="checkbox" name="sites" class="mainwp-site-checkbox" value="<?php echo esc_attr( $website->id ); ?>" id="<?php echo 'site-' . esc_attr( $website->id ); ?>" >
			</div>
				</td>
				<td><a href="admin.php?page=managesites&dashboard=<?php echo $website->id; ?>" data-tooltip="<?php esc_attr_e( 'Go to the site overview.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><?php echo $website->name; ?></a></td>
				<td>
					<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>&_opennonce=<?php echo wp_create_nonce( 'mainwp-admin-nonce' ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin.', 'mainwp' ); ?>" data-position="left center" data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
				</td>
				<td><a href="<?php echo $website->url; ?>" target="_blank"><?php echo $website->url; ?></a></td>
				<td>
					<span class="mainwp-preview-item" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'Click to see the site homepage screenshot.', 'mainwp' ); ?>" preview-site-url="<?php echo $website->url; ?>" ><i class="camera icon"></i></span>
				</td>
				<td>
				<?php if ( '' == $website->note ) : ?>
					<a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo $website->id; ?>" data-tooltip="<?php esc_attr_e( 'Click to add a note.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="sticky note outline icon"></i></a>
				<?php else : ?>
					<a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo $website->id; ?>" data-tooltip="<?php echo substr( wp_unslash( $strip_note ), 0, 100 ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
				<?php endif; ?>
					<span style="display: none" id="mainwp-notes-<?php echo $website->id; ?>-note"><?php echo wp_unslash( $esc_note ); ?></span>
				</td>
			</tr>
			<?php
		}
		MainWP_DB::free_result( $websites );
	}

	/**
	 * Method render_all_groups()
	 *
	 * Render MainWP Groups Table.
	 *
	 * @return string MainWP Groups Table.
	 */
	public static function render_all_groups() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_groups' ) ) {
			mainwp_do_not_have_permissions( __( 'manage groups', 'mainwp' ) );

			return;
		}

		$sidebarPosition = get_user_option( 'mainwp_sidebarPosition' );
		if ( false === $sidebarPosition ) {
			$sidebarPosition = 1;
		}

		/**
		 * Sites Page header
		 *
		 * Renders the tabs on the Sites screen.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_pageheader_sites', 'ManageGroups' );
		?>
		<div id="mainwp-manage-groups" class="ui segment">
			<div id="mainwp-message-zone" style="display: none;">
				<div class="ui message green"><?php esc_html_e( 'Selection saved successfully.', 'mainwp' ); ?></div>
			</div>
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp_groups_info' ) ) { ?>
			<div class="ui message info">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp_groups_info"></i>
					<div><?php esc_html_e( 'In case you are managing a large number of WordPress sites, it could be useful for you to split sites into different groups. Later, you will be able to make Site Selection by a group that will speed up your work and makes it much easier.', 'mainwp' ); ?></div>
					<div><?php esc_html_e( 'One child site can be assigned to multiple Groups at the same time.', 'mainwp' ); ?></div>
					<div><?php echo sprintf( __( 'For more information check the %1$sKnowledge Base%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/manage-child-site-groups/" target="_blank">', '</a>' ); ?></div>
			</div>
			<?php } ?>
			<?php
			/**
			 * Action: mainwp_before_groups_table
			 *
			 * Fires before the Manage Groups table.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_before_groups_table' );
			?>
			<div class="ui stackable grid">
				<div class="<?php echo 1 == $sidebarPosition ? 'twelve' : 'four'; ?> wide column">
					<?php if ( 1 == $sidebarPosition ) : ?>
						<?php echo self::render_groups_sites_table_element(); ?>
					<?php else : ?>
						<?php echo self::render_groups_menu_element(); ?>
					<?php endif; ?>
				</div>
				<div class="<?php echo 1 == $sidebarPosition ? 'four' : 'twelve'; ?> wide column">
					<?php if ( 1 == $sidebarPosition ) : ?>
						<?php echo self::render_groups_menu_element(); ?>
					<?php else : ?>
						<?php echo self::render_groups_sites_table_element(); ?>
					<?php endif; ?>
				</div>
				<script type="text/javascript">
				var responsive = true;
				if( jQuery( window ).width() > 1140 ) {
					responsive = false;
				}
				jQuery( document ).ready( function() {
					jQuery( '#mainwp-manage-groups-sites-table' ).dataTable( {
						"searching" : true,
						"responsive" : responsive,
						"colReorder" : true,
						"stateSave":  true,
						"paging": false,
						"info": true,
						"order": [],
						"scrollX" : false,
						"columnDefs": [ {
							"targets": 'no-sort',
							"orderable": false
						} ],
						"preDrawCallback": function( settings ) {
							jQuery( '#mainwp-manage-groups-sites-table .ui.checkbox' ).checkbox();
						}
					} );
				} );
				</script>
			</div>
			<?php MainWP_UI::render_modal_edit_notes(); ?>
			<div class="ui mini modal" id="mainwp-create-group-modal">
				<div class="header"><?php echo __( 'Create Group', 'mainwp' ); ?></div>
				<div class="content">
					<div class="ui form">
						<div class="field">
							<input type="text" value="" name="mainwp-group-name" id="mainwp-group-name" placeholder="<?php esc_attr_e( 'Enter group name', 'mainwp' ); ?>" >
						</div>
					</div>
				</div>
				<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column">
							<a class="ui green button" id="mainwp-save-new-group-button" href="#"><?php echo __( 'Create Group', 'mainwp' ); ?></a>
						</div>
						<div class="right aligned column">
							<div class="ui cancel button"><?php echo __( 'Close', 'mainwp' ); ?></div>
						</div>
					</div>
				</div>
			</div>

			<div class="ui mini modal" id="mainwp-rename-group-modal">
				<div class="header"><?php echo __( 'Rename Group', 'mainwp' ); ?></div>
				<div class="content">
					<div class="ui form">
						<div class="field">
							<input type="text" value="" name="mainwp-group-name" id="mainwp-group-name" placeholder="<?php esc_attr_e( 'Enter group name', 'mainwp' ); ?>" >
						</div>
					</div>
				</div>
				<div class="actions">
					<div class="ui two columns stackable grid">
						<div class="left aligned column">
							<a class="ui green button" id="mainwp-update-new-group-button" href="#"><?php echo __( 'Update Group', 'mainwp' ); ?></a>
						</div>
						<div class="right aligned column">
							<div class="ui cancel button"><?php echo __( 'Close', 'mainwp' ); ?></div>
						</div>
					</div>
				</div>
			</div>
			<?php
			/**
			 * Action: mainwp_after_groups_table
			 *
			 * Fires after the Manage Groups table.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_groups_table' );
			?>
		</div>
		<?php
		/**
		 * Sites Page Footer
		 *
		 * Renders the footer on the Sites screen.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_pagefooter_sites', 'ManageGroups' );
	}

	/**
	 * Method render_groups_menu_element()
	 *
	 * Render the groups menu HTML element.
	 */
	public static function render_groups_menu_element() {
		$sidebarPosition = get_user_option( 'mainwp_sidebarPosition' );
		if ( false === $sidebarPosition ) {
			$sidebarPosition = 1;
		}
		?>
		<div class="ui fluid <?php echo 1 == $sidebarPosition ? 'right' : ''; ?> pointing vertical menu sticky" id="mainwp-groups-menu" style="margin-top:52px">
			<h4 class="item ui header"><?php esc_html_e( 'Sites Groups', 'mainwp' ); ?></h4>
			<?php echo self::get_group_list_content(); ?>
			<div class="item">
				<div class="ui two columns stackable grid">
					<div class="left aligned column">
						<a href="#" class="ui tiny green button" id="mainwp-new-sites-group-button" data-inverted="" data-position="top left" data-tooltip="<?php esc_attr_e( 'Click here to create a new group.', 'mainwp' ); ?>"><?php esc_html_e( 'New Group', 'mainwp' ); ?></a>
					</div>
					<div class="right aligned column">
						<a href="#" class="ui tiny icon green basic button disabled" id="mainwp-rename-group-button" data-inverted="" data-position="top right" data-tooltip="<?php esc_attr_e( 'Edit selected group.', 'mainwp' ); ?>"><i class="edit icon"></i></a>
						<a href="#" class="ui tiny icon button disabled" id="mainwp-delete-group-button" data-inverted="" data-position="top right" data-tooltip="<?php esc_attr_e( 'Delete selected group.', 'mainwp' ); ?>"><i class="trash icon"></i></a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_groups_sites_table_element()
	 *
	 * Render the groups menu HTML element.
	 */
	public static function render_groups_sites_table_element() {
		?>
		<table class="ui table unstackable selection mainwp-with-preview-table" id="mainwp-manage-groups-sites-table">
			<thead>
				<tr>
					<th class="no-sort collapsing"><div class="ui checkbox" data-tooltip="<?php esc_attr_e( 'Click to select all sites.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><input type="checkbox" name="example"></div></th>
					<th><?php esc_html_e( 'Sites', 'mainwp' ); ?></th>
					<th class="no-sort collapsing"><i class="sign in icon"></i></th>
					<th><?php esc_html_e( 'URL', 'mainwp' ); ?></th>
					<th class="no-sort collapsing"><i class="camera icon"></i></th>
					<th class="no-sort collapsing"><i class="sticky note outline icon"></i></th>
				</tr>
			</thead>
			<tbody>
				<?php echo self::get_website_list_content(); ?>
			</tbody>
			<tfoot>
				<tr>
					<th colspan="6">
						<a href="#" class="ui tiny green button" id="mainwp-save-sites-groups-selection-button" data-inverted="" data-position="top left" data-tooltip="<?php esc_attr_e( 'Save the selected group sites selection.', 'mainwp' ); ?>"><?php esc_html_e( 'Save Selection', 'mainwp' ); ?></a>
					</th>
				</tr>
			</tfoot>
		</table>
		<div class="ui inverted dimmer">
			<div class="ui loader"></div>
		</div>
		<?php
	}

	/**
	 * Method rename_group()
	 *
	 * Rename the selected group.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_group()
	 */
	public static function rename_group() {
		if ( isset( $_POST['groupId'] ) ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( intval( $_POST['groupId'] ) );
			if ( ! empty( $group ) ) {
				$name = isset( $_POST['newName'] ) ? sanitize_text_field( wp_unslash( $_POST['newName'] ) ) : '';
				if ( '' == $name ) {
					$name = $group->name;
				}

				$name = self::check_group_name( $name, $group->id );
				// update group.
				$nr = MainWP_DB_Common::instance()->update_group( $group->id, $name );

				// Reload group.
				$group = MainWP_DB_Common::instance()->get_group_by_id( $group->id );
				die( wp_json_encode( array( 'result' => $group->name ) ) );
			}
		}
	}

	/**
	 * Method delete_group()
	 *
	 * Delete the selected group.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::remove_group()
	 */
	public static function delete_group() {
		$groupid = isset( $_POST['groupId'] ) && ! empty( $_POST['groupId'] ) ? intval( $_POST['groupId'] ) : false;
		if ( $groupid ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( $groupid );
			if ( ! empty( $group ) ) {
				// Remove from DB.
				$nr = MainWP_DB_Common::instance()->remove_group( $group->id );

				if ( $nr > 0 ) {
					die( 'OK' );
				}
			}
		}
		die( 'ERROR' );
	}

	/**
	 * Method check_group_name()
	 *
	 * Check if group name already exists
	 * if it does add a number to the end of it.
	 *
	 * @param mixed $groupName Given Group Name.
	 * @param null  $groupId Group ID.
	 *
	 * @return string $groupName Group name + count # if group has bn found.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_name()
	 */
	public static function check_group_name( $groupName, $groupId = null ) {
		if ( '' == $groupName ) {
			$groupName = __( 'New group', 'mainwp' );
		}

		$groupName = esc_html( $groupName );

		$cnt = null;
		if ( preg_match( '/(.*) \(\d\)/', $groupName, $matches ) ) {
			$groupName = $matches[1];
		}

		$group = MainWP_DB_Common::instance()->get_group_by_name( $groupName );
		while ( $group && ( ( null == $groupId ) || ( $group->id != $groupId ) ) ) {
			if ( null == $cnt ) {
				$cnt = 1;
			} else {
				$cnt ++;
			}

			$group = MainWP_DB_Common::instance()->get_group_by_name( $groupName . ' (' . $cnt . ')' );
		}

		return $groupName . ( null == $cnt ? '' : ' (' . $cnt . ')' );
	}

	/**
	 * Method add_group()
	 *
	 * Add Group.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::add_group()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_id()
	 */
	public static function add_group() {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		if ( isset( $_POST['newName'] ) ) {
			$groupId = MainWP_DB_Common::instance()->add_group( $current_user->ID, self::check_group_name( sanitize_text_field( wp_unslash( $_POST['newName'] ) ) ) );

			/**
			 * New Group Added
			 *
			 * Fires after a new sites group has been created.
			 *
			 * @param int $groupId Group ID.
			 */
			do_action( 'mainwp_added_new_group', $groupId );
			$group = MainWP_DB_Common::instance()->get_group_by_id( $groupId );
			self::create_group_item( $group );
			die();
		}
		die( wp_json_encode( array( 'error' => 1 ) ) );
	}

	/**
	 * Method get_sites()
	 *
	 * Get Child Sites by Group ID.
	 *
	 * @return mixed $websiteIds|ERROR Child Site ID or Error is returned.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_group_id()
	 */
	public static function get_sites() {
		$groupid = isset( $_POST['groupId'] ) && ! empty( $_POST['groupId'] ) ? intval( $_POST['groupId'] ) : false;
		if ( $groupid ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( $groupid );
			if ( ! empty( $group ) ) {
				$websites   = MainWP_DB::instance()->get_websites_by_group_id( $group->id );
				$websiteIds = array();
				if ( ! empty( $websites ) ) {
					foreach ( $websites as $website ) {
						$websiteIds[] = $website->id;
					}
				}

				return wp_json_encode( $websiteIds );
			}
		}
		die( 'ERROR' );
	}

	/**
	 * Method update_group()
	 *
	 * Update groups Sites.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::clear_group()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_group_site()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function update_group() {
		$groupid = isset( $_POST['groupId'] ) && ! empty( $_POST['groupId'] ) ? intval( $_POST['groupId'] ) : false;
		if ( $groupid ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( $groupid );
			if ( ! empty( $group ) ) {
				MainWP_DB_Common::instance()->clear_group( $group->id );
				if ( isset( $_POST['websiteIds'] ) ) {
					foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['websiteIds'] ) ) as $websiteId ) {
						$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
						if ( MainWP_System_Utility::can_edit_website( $website ) ) {
							MainWP_DB_Common::instance()->update_group_site( $group->id, $website->id );
						}
					}
				}
				die( wp_json_encode( array( 'result' => true ) ) );
			}
		}

		die( wp_json_encode( array( 'result' => false ) ) );
	}

}
