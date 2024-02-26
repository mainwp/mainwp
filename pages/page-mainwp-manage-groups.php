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
	 * Public static varable to hold Subpages information.
	 *
	 * @var array $subPages
	 */
	public static $subPages;

	/**
	 * Method init()
	 *
	 * Initiate hooks for the users page.
	 */
	public static function init() {
		/**
		 * This hook allows you to render the Tags page header via the 'mainwp_pageheader_tags' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-tags
		 *
		 * This hook is normally used in the same context of 'mainwp_getsubpages_tags'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-tags
		 *
		 * @see \MainWP_Manage_Groups::render_header
		 */
		add_action( 'mainwp_pageheader_tags', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Tags page footer via the 'mainwp_pagefooter_tags' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-tags
		 *
		 * This hook is normally used in the same context of 'mainwp_getsubpages_tags'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-tags
		 *
		 * @see \MainWP_Manage_Groups::render_footer
		 */
		add_action( 'mainwp_pagefooter_tags', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );
	}


	/**
	 * Method init_menu()
	 *
	 * Add Groups Sub Menu.
	 */
	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab',
			__( 'Tags', 'mainwp' ),
			'<div class="mainwp-hidden">' . esc_html__( 'Tags', 'mainwp' ) . '</div>',
			'read',
			'ManageGroups',
			array(
				self::get_class_name(),
				'render_all_groups',
			)
		);

		/**
		 * This hook allows you to add extra sub pages to the Tags page via the 'mainwp-getsubpages-tags' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-tags
		 */
		self::$subPages = apply_filters( 'mainwp_getsubpages_tags', self::$subPages );

		self::init_left_menu( self::$subPages );
	}

	/**
	 * Initiates Tags menu.
	 *
	 * @param array $subPages Sub pages array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_left_menu( $subPages = array() ) {
		MainWP_Menu::add_left_menu(
			array(
				'title'         => esc_html__( 'Tags', 'mainwp' ),
				'parent_key'    => 'managesites',
				'slug'          => 'ManageGroups',
				'href'          => 'admin.php?page=ManageGroups',
				'icon'          => '<i class="tags icon"></i>',
				'desc'          => 'Manage tags on your MainWP Dashboard',
				'leftsub_order' => 3,
			),
			1
		);

		$init_sub_subleftmenu = array(
			array(
				'title'      => esc_html__( 'Manage Tags', 'mainwp' ),
				'parent_key' => 'ManageGroups',
				'href'       => 'admin.php?page=ManageGroups',
				'slug'       => 'ManageGroups',
				'right'      => 'manage_groups',
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ManageGroups', 'ManageGroups' );

		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Method render_header()
	 *
	 * Render Tags page header.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	public static function render_header( $shownPage = '' ) {
		$params = array(
			'title' => esc_html__( 'Tags', 'mainwp' ),
		);
		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		if ( mainwp_current_user_have_right( 'dashboard', 'manage_groups' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Manage Tags', 'mainwp' ),
				'href'   => 'admin.php?page=ManageGroups',
				'active' => ( 'ManageGroups' === $shownPage ) ? true : false,
			);
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageGroups' . $subPage['slug'] ) ) {
					continue;
				}

				$item           = array();
				$item['title']  = $subPage['title'];
				$item['href']   = 'admin.php?page=ManageGroups' . $subPage['slug'];
				$item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * Method render_footer()
	 *
	 * Render Tags page footer. Closes the page container.
	 */
	public static function render_footer() {
		echo '</div>';
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
		<a class="item" id="<?php echo intval( $group->id ); ?>" style="border-left: 4px solid <?php echo empty( $group->color ) ? '#fff' : esc_attr( $group->color ); ?>">
			<div class="ui small label"><?php echo property_exists( $group, 'nrsites' ) ? intval( $group->nrsites ) : 0; ?></div>
			<input type="hidden" value="<?php echo esc_html( stripslashes( $group->name ) ); ?>" id="mainwp-hidden-group-name">
			<input type="hidden" value="<?php echo esc_html( $group->color ); ?>" id="mainwp-hidden-group-color">
			<input type="hidden" value="<?php echo intval( $group->id ); ?>" id="mainwp-hidden-group-id">
			<?php echo esc_html( stripslashes( $group->name ) ); ?>
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
				<td><a href="admin.php?page=managesites&dashboard=<?php echo intval( $website->id ); ?>" data-tooltip="<?php esc_attr_e( 'Go to the site overview.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><?php echo esc_html( stripslashes( $website->name ) ); ?></a></td>
				<td>
					<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin . ', 'mainwp' ); ?>" data-position="left center" data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
				</td>
				<td><a href="<?php echo esc_url( $website->url ); ?>" target="_blank"><?php echo esc_html( $website->url ); ?></a></td>
				<td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
				<td>
					<span class="mainwp-preview-item" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'Click to see the site homepage screenshot . ', 'mainwp' ); ?>" preview-site-url="<?php echo esc_url( $website->url ); ?>" ><i class="camera icon"></i></span>
				</td>
				<td>
				<?php if ( empty( $website->note ) ) : ?>
					<a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo intval( $website->id ); ?>" data-tooltip="<?php esc_attr_e( 'Click to add a note . ', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="sticky note outline icon"></i></a>
				<?php else : ?>
					<a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo intval( $website->id ); ?>" data-tooltip="<?php echo substr( wp_unslash( $strip_note ), 0, 100 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
				<?php endif; ?>
					<span style="display: none" id="mainwp-notes-<?php echo intval( $website->id ); ?>-note"><?php echo wp_unslash( $esc_note ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
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
			mainwp_do_not_have_permissions( esc_html__( 'manage groups', 'mainwp' ) );

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
		do_action( 'mainwp_pageheader_tags', 'ManageGroups' );
		?>
		<div id="mainwp-manage-groups" class="ui segment">
			<div id="mainwp-message-zone" style="display: none;">
				<div class="ui message green"><?php esc_html_e( 'Selection saved successfully . ', 'mainwp' ); ?></div>
			</div>
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp_groups_info' ) ) { ?>
			<div class="ui message info">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp_groups_info"></i>
					<div><?php esc_html_e( 'In case you are managing a large number of WordPress sites, it could be useful for you to mark them with different tags . Later, you will be able to make Site Selection by a tag that will speed up your work and makes it much easier.', 'mainwp' ); ?></div>
					<div><?php esc_html_e( 'One child site can be assigned to multiple Tags at the same time.', 'mainwp' ); ?></div>
					<div><?php printf( esc_html__( 'for more information check the %1$sKnowledge Base %2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/manage-child-site-groups/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></div>
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
				<div class="<?php echo 1 === (int) $sidebarPosition ? 'twelve' : 'four'; ?> wide column">
					<?php if ( 1 === (int) $sidebarPosition ) : ?>
						<?php self::render_groups_sites_table_element(); ?>
					<?php else : ?>
						<?php self::render_groups_menu_element(); ?>
					<?php endif; ?>
				</div>
				<div class="<?php echo 1 === (int) $sidebarPosition ? 'four' : 'twelve'; ?> wide column">
					<?php if ( 1 === (int) $sidebarPosition ) : ?>
						<?php self::render_groups_menu_element(); ?>
					<?php else : ?>
						<?php self::render_groups_sites_table_element(); ?>
					<?php endif; ?>
				</div>
				<script type="text/javascript">
				var responsive = true;
				if( jQuery( window ).width() > 1140 ) {
					responsive = false;
				}
				jQuery( document ).ready( function() {
					jQuery( '#mainwp-manage-groups-sites-table' ).dataTable( {
						'searching' : true,
						'responsive' : responsive,
						'colReorder' : true,
						'stateSave':  true,
						'paging': false,
						'info': true,
						'order': [ [ 1, "asc" ] ],
						'scrollX' : false,
						'columnDefs': [ {
							"targets": 'no-sort',
							"orderable": false
						} ],
						'preDrawCallback': function( settings ) {
							jQuery( '#mainwp-manage-groups-sites-table .ui.checkbox' ).checkbox();
						}
				} );
	} );
				</script>
			</div>
			<?php MainWP_UI::render_modal_edit_notes(); ?>
			<div class="ui mini modal" id="mainwp-create-group-modal">
			<i class="close icon"></i>
				<div class="header"><?php echo esc_html__( 'Create Tag', 'mainwp' ); ?></div>
				<div class="content">
					<div class="ui form">
						<div class="field">
							<label><?php esc_html_e( 'Enter tag name', 'mainwp' ); ?></label>
							<input type="text" value="" name="mainwp-group-name" id="mainwp-group-name">
						</div>
						<div class="field">
							<label><?php esc_html_e( 'Select tag color', 'mainwp' ); ?></label>
							<input type="text" name="mainwp-new-tag-color" class="mainwp-tag-color-picker" id="mainwp-new-tag-color"  value="" />
						</div>
					</div>
				</div>
				<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column">
							
						</div>
						<div class="right aligned column">
						<a class="ui green button" id="mainwp-save-new-group-button" href="#"><?php echo esc_html__( 'Create Tag', 'mainwp' ); ?></a>
						</div>
					</div>
				</div>
				<style>
					.mainwp-ui .ui.modal .wp-picker-clear {
						display:none;
					}
					.mainwp-ui .ui.modal #mainwp-new-tag-color {
						height: 28px;
						margin-left: 5px;
					}
				</style>
				<script type="text/javascript">
					jQuery( document ).ready( function() {
						jQuery('.mainwp-tag-color-picker').wpColorPicker({
							hide: true,
							clear: false,
							palettes: [ '#18a4e0','#0253b3','#7fb100','#446200','#ad0000','#ffd300','#2d3b44','#6435c9','#e03997','#00b5ad' ],
						});
					} );
				</script>
			</div>

			<div class="ui mini modal" id="mainwp-rename-group-modal">
			<i class="close icon"></i>
				<div class="header"><?php echo esc_html__( 'Rename Tag', 'mainwp' ); ?></div>
				<div class="content">
					<div class="ui form">
						<div class="field">
							<label><?php esc_html_e( 'Enter tag name', 'mainwp' ); ?></label>
							<input type="text" value="" name="mainwp-group-name" id="mainwp-group-name">
						</div>
						<div class="field">
							<label><?php esc_html_e( 'Select tag color', 'mainwp' ); ?></label>
							<input type="text" name="mainwp-new-tag-color" class="mainwp-tag-color-picker" id="mainwp-new-tag-color" value="" />
						</div>
					</div>
				</div>
				<div class="actions">
					<div class="ui two columns stackable grid">
						<div class="left aligned column">
							
						</div>
						<div class="right aligned column">
						<a class="ui green button" id="mainwp-update-new-group-button" href="#"><?php echo esc_html__( 'Update Tag', 'mainwp' ); ?></a>
						</div>
					</div>
				</div>
				<style>
					.mainwp-ui .ui.modal .wp-picker-clear {
						display:none;
					}
					.mainwp-ui .ui.modal #mainwp-new-tag-color {
						height: 28px;
						margin-left: 5px;
					}
				</style>
				<script type="text/javascript">
					jQuery( document ).ready( function() {
						jQuery('.mainwp-tag-color-picker').wpColorPicker({
							hide: true,
							clear: false,
							palettes: [ '#18a4e0','#0253b3','#7fb100','#446200','#ad0000','#ffd300','#2d3b44','#6435c9','#e03997','#00b5ad' ],
						});
					} );
				</script>
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
		do_action( 'mainwp_pagefooter_tags', 'ManageGroups' );
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
		<div class="ui fluid <?php echo 1 === (int) $sidebarPosition ? 'right' : ''; ?> pointing vertical menu sticky" id="mainwp-groups-menu" style="margin-top:52px">
			<h4 class="item ui header"><?php esc_html_e( 'Tags', 'mainwp' ); ?></h4>
		<?php self::get_group_list_content(); ?>
			<div class="item">
				<div class="ui two columns stackable grid">
					<div class="left aligned column">
						<a href="javascript:void(0);" class="ui tiny green button" id="mainwp-new-sites-group-button" data-inverted="" data-position="top left" data-tooltip="<?php esc_attr_e( 'Click here to create a new tag.', 'mainwp' ); ?>"><?php esc_html_e( 'New Tag', 'mainwp' ); ?></a>
					</div>
					<div class="right aligned column">
						<a href="javascript:void(0);" class="ui tiny icon green basic button disabled" id="mainwp-rename-group-button" data-inverted="" data-position="top right" data-tooltip="<?php esc_attr_e( 'Edit selected tag.', 'mainwp' ); ?>"><i class="edit icon"></i></a>
						<a href="javascript:void(0);" class="ui tiny icon button disabled" id="mainwp-delete-group-button" data-inverted="" data-position="top right" data-tooltip="<?php esc_attr_e( 'Delete selected tag.', 'mainwp' ); ?>"><i class="trash icon"></i></a>
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
		<table class="ui very compact table mainwp-with-preview-table" id="mainwp-manage-groups-sites-table">
			<thead>
				<tr>
					<th class="no-sort collapsing"><div class="ui checkbox" data-tooltip="<?php esc_attr_e( 'Click to select all sites.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><input type="checkbox" name="example"></div></th>
					<th><?php esc_html_e( 'Sites', 'mainwp' ); ?></th>
					<th class="no-sort collapsing"><i class="sign in icon"></i></th>
					<th><?php esc_html_e( 'URL', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
					<th class="no-sort collapsing"><i class="camera icon"></i></th>
					<th class="no-sort collapsing"><i class="sticky note outline icon"></i></th>
				</tr>
			</thead>
			<tbody>
			<?php self::get_website_list_content(); ?>
			</tbody>
			<tfoot>
				<tr>
					<th colspan="7">
						<a href="#" class="ui tiny green button" id="mainwp-save-sites-groups-selection-button" data-inverted="" data-position="top left" data-tooltip="<?php esc_attr_e( 'Save the selected tag sites selection.', 'mainwp' ); ?>"><?php esc_html_e( 'Save Selection', 'mainwp' ); ?></a>
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
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['groupId'] ) ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( intval( $_POST['groupId'] ) );
			if ( ! empty( $group ) ) {
				$old_name = $group->name;
				$name     = isset( $_POST['newName'] ) ? sanitize_text_field( wp_unslash( $_POST['newName'] ) ) : '';
				if ( empty( $name ) ) {
					$name = $group->name;
				}

				$name = self::check_group_name( $name, $group->id );

				if ( isset( $_POST['newColor'] ) && ! empty( $_POST['newColor'] ) ) {
					$color = sanitize_hex_color( wp_unslash( $_POST['newColor'] ) );
					if ( empty( $color ) ) {
						$color = $group->color;
					}
				} else {
					$color = '';
				}

				// update group.
				$nr = MainWP_DB_Common::instance()->update_group( $group->id, $name, $color );

				// Reload group.
				$group = MainWP_DB_Common::instance()->get_group_by_id( $group->id );

				$data = array(
					'old_name' => $old_name,
				);

				/**
				 * Fires after a new sites tag has been created.
				 *
				 * @param object $group tag created.
				 * @param string tag action.
				 * @param array other data array.
				 */
				do_action( 'mainwp_site_tag_action', $group, 'updated', $data );

				die( wp_json_encode( array( 'result' => $group->name ) ) );
			}
		}
		//phpcs:enable
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
		//phpcs:disable WordPress.Security.NonceVerification.Missing
		$groupid = isset( $_POST['groupId'] ) && ! empty( $_POST['groupId'] ) ? intval( $_POST['groupId'] ) : false;
		//phpcs:enable
		if ( $groupid ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( $groupid );
			if ( ! empty( $group ) ) {
				// Remove from DB.
				$nr = MainWP_DB_Common::instance()->remove_group( $group->id );

				/**
				 * Fires after a tag has been deleted.
				 *
				 * @param object $group group created.
				 * @param string group action.
				 */
				do_action( 'mainwp_site_tag_action', $group, 'deleted' );

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
		if ( empty( $groupName ) ) {
			$groupName = esc_html__( 'New tag', 'mainwp' );
		}

		$groupName = esc_html( $groupName );

		$cnt = null;
		if ( preg_match( '/(.*) \(\d\)/', $groupName, $matches ) ) {
			$groupName = $matches[1];
		}

		$group = MainWP_DB_Common::instance()->get_group_by_name( $groupName );
		while ( $group && ( ( null === $groupId ) || ( (int) $group->id !== (int) $groupId ) ) ) {
			if ( null === $cnt ) {
				$cnt = 1;
			} else {
				++$cnt;
			}

			$group = MainWP_DB_Common::instance()->get_group_by_name( $groupName . ' (' . $cnt . ')' );
		}

		return $groupName . ( null === $cnt ? '' : ' (' . $cnt . ')' );
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

		//phpcs:disable WordPress.Security.NonceVerification.Missing
		$newName  = isset( $_POST['newName'] ) ? sanitize_text_field( wp_unslash( $_POST['newName'] ) ) : '';
		$newColor = isset( $_POST['newColor'] ) ? sanitize_hex_color( wp_unslash( $_POST['newColor'] ) ) : '';
		//phpcs:enable

		if ( ! empty( $newName ) ) {
			$groupId = MainWP_DB_Common::instance()->add_group( $current_user->ID, self::check_group_name( $newName ), $newColor );

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
	 * Method add_group_sites()
	 *
	 * Add Group sites.
	 *
	 * @param string $gname Group Name.
	 * @param array  $site_ids Sites IDs.
	 * @param string $gcolor Tag color.
	 */
	public static function add_group_sites( $gname, $site_ids, $gcolor = '' ) {
		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$groupId = MainWP_DB_Common::instance()->add_group( $current_user->ID, self::check_group_name( $gname ), $gcolor );

		if ( $groupId ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( $groupId );
			if ( ! empty( $group ) ) {
				if ( ! empty( $site_ids ) ) {
					foreach ( $site_ids as $websiteId ) {
						$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
						if ( MainWP_System_Utility::can_edit_website( $website ) ) {
							MainWP_DB_Common::instance()->update_group_site( $group->id, $website->id );
						}
					}
				}
			}

			/**
			 * New Group Added
			 *
			 * Fires after a new sites group has been created.
			 *
			 * @param int $groupId Group ID.
			 */
			do_action( 'mainwp_added_new_group', $groupId );
			return true;
		}

		return false;
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
 		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
		// phpcs:enable

		die( wp_json_encode( array( 'result' => false ) ) );
	}

	/**
	 * Hooks the section help content to the Help Sidebar element.
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && 'ManageGroups' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			?>
			<p><?php esc_html_e( 'If you need help with managing tags, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="" target="_blank">Manage Tags</a></div>
			<?php
			/**
			 * Action: mainwp_tags_help_item
			 *
			 * Fires at the bottom of the help articles list in the Help sidebar on the Users page.
			 *
			 * Suggested HTML markup:
			 *
			 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_tags_help_item' );
			?>
			</div>
			<?php
		}
	}
}
