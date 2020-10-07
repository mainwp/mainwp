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
		<tr group-id="<?php echo esc_attr( $group->id ); ?>" class="mainwp-group-row">
			<td>
				<span class="ui text">
					<?php echo esc_html( stripslashes( $group->name ) ); ?>
				</span>
				<span class="ui mini input fluid" style="display:none;">
					<input type="text" placeholder="<?php esc_attr_e( 'Enter group name', 'mainwp' ); ?>" value="<?php echo esc_attr( $group->name ); ?>" />
				</span>
			</td>
			<td></td>
			<td class="right aligned">
				<a href="#" class="managegroups-edit ui button green mini"> <?php esc_html_e( 'Edit Group', 'mainwp' ); ?></a>
				<a href="#" class="managegroups-rename ui button mini"><?php esc_html_e( 'Rename Group', 'mainwp' ); ?></a>
				<a href="#" class="managegroups-save ui button basic green mini" style="display:none;"> <?php esc_html_e( 'Save Group Name', 'mainwp' ); ?></a>
				<a href="#" class="managegroups-delete ui button basic red mini"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
				<?php
				/**
				 * Action: mainwp_groups_action
				 *
				 * Adds action to the Group actions row.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_groups_action', $group );
				?>
			</td>
		</tr>
		<tr id="mainwp-group-<?php echo esc_attr( $group->id ); ?>-sites" class="mainwp-group-sites-row">
			<td colspan="3">
				<div class="ui list">
					<?php echo self::get_website_list_content(); ?>
				</div>
			</td>
		</tr>
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
	 */
	public static function get_website_list_content() {
		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			?>
			<div class="item ui checkbox">
				<input type="checkbox" name="sites" value="<?php echo esc_attr( $website->id ); ?>" id="<?php echo MainWP_Utility::get_nice_url( $website->url ); ?>" >
				<label for="<?php echo MainWP_Utility::get_nice_url( $website->url ); ?>"><?php echo MainWP_Utility::get_nice_url( $website->url ); ?></label>
			</div>
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
			<table id="mainwp-groups-table" class="ui table">
				<thead>
					<tr>
						<th colspan="3">
							<?php esc_html_e( 'Groups', 'mainwp' ); ?>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php echo self::get_group_list_content(); ?>
					<tr class="managegroups-group-add" style="display:none;">
						<td>
							<span class="ui mini input fluid"><input type="text" placeholder="<?php esc_attr_e( 'Group name', 'mainwp' ); ?>" value="" /></span>
						</td>
						<td></td>
						<td class="right aligned">
							<a href="#" class="managegroups-savenew ui button green mini"><?php esc_html_e( 'Save Group', 'mainwp' ); ?></a>
							<a href="#" class="managegroups-cancel ui button basic red mini"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></a>
						</td>
					</tr>
				</tbody>
				<tfoot class="full-width">
					<tr>
						<th colspan="3">
							<input type="button" value="<?php esc_attr_e( 'Save Selection', 'mainwp' ); ?>" class="managegroups-saveAll ui right floated green button" style="display:none" />
							<a class="managegroups-addnew ui green basic button" href="javascript:void(0)"><?php esc_html_e( 'Create New Group', 'mainwp' ); ?></a>
						</th>
					</tr>
				</tfoot>
			</table>
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
		?>

		<script type="text/javascript">
			jQuery( document ).ready( function () {

				jQuery( document ).on( 'click', '.managegroups-rename', function () {
					var parentObj = jQuery( this ).closest( 'tr' );
					parentObj.find( '.text' ).hide();
					parentObj.find( '.input' ).show();
					parentObj.find( '.managegroups-rename' ).hide();
					parentObj.find( '.managegroups-save' ).show();
					parentObj.addClass('active');
					return false;
				} );

				jQuery( document ).on( 'click', '.managegroups-save', function () {
					var parentObj = jQuery( this ).closest( 'tr' );
					var groupId = parentObj.attr( 'group-id' );
					var newName = parentObj.find( '.input input' ).val();

					var data = mainwp_secure_data( {
						action: 'mainwp_group_rename',
						groupId: groupId,
						newName: newName
					} );

					jQuery.post( ajaxurl, data, function ( pParentObj ) {
						return function ( response ) {
							if ( response.error )
								return;

							response = jQuery.trim( response.result );
							pParentObj.find( '.input input' ).val( response );
							pParentObj.find( '.text' ).html( response );

							pParentObj.find( '.input' ).hide();
							pParentObj.find( '.managegroups-save' ).hide();
							pParentObj.find( '.text' ).show();
							pParentObj.find( '.managegroups-rename' ).show();
							parentObj.removeClass('active');
						}
					}( parentObj ), 'json' );

					return false;
				} );

				jQuery( document ).on( 'click', '.managegroups-delete', function () {

					var msg = 'Are you sure you want to delete this sites group?';
					var me = this;
					var confirmed = function() {
						var parentObj = jQuery( me ).closest( 'tr' );
						parentObj.addClass( 'negative' );
						var groupId = parentObj.attr( 'group-id' );

						var data = mainwp_secure_data( {
							action: 'mainwp_group_delete',
							groupId: groupId
						} );

						jQuery.post( ajaxurl, data, function ( pParentObj ) {
							return function ( response ) {
								response = jQuery.trim( response );
								if ( response == 'OK' )
									pParentObj.animate( { opacity: 0 }, 300, function () {
										pParentObj.remove()
									} );
							}
						}( parentObj ) );
					};
					mainwp_confirm( msg, confirmed);
					return false;
				} );

				jQuery( document ).on( 'click', '.managegroups-addnew', function () {
					var addNewContainer = jQuery( '.managegroups-group-add' );
					addNewContainer.find( 'input' ).val( '' );
					addNewContainer.show();
				} );

				jQuery( document ).on( 'click', '.managegroups-cancel', function () {
					var addNewContainer = jQuery( '.managegroups-group-add' );
					addNewContainer.hide();
					addNewContainer.find( 'input' ).val( '' );
				} );

				jQuery( document ).on( 'click', '.managegroups-savenew', function () {
					var parentObj = jQuery( this ).closest( 'tr' );
					var newName = parentObj.find( 'input' ).val();

					var data = mainwp_secure_data( {
						action: 'mainwp_group_add',
						newName: newName
					} );

					jQuery.post( ajaxurl, data, function ( response ) {
						try {
							resp = jQuery.parseJSON( response );

							if ( resp.error != undefined )
								return;
						} catch ( err ) {

						}

						response = jQuery.trim( response );

						var addNewContainer = jQuery( '.managegroups-group-add' );
						addNewContainer.hide();
						addNewContainer.find( 'input' ).val( '' );

						addNewContainer.after( response );
					} );

					return false;
				} );

				jQuery( document ).on( 'click', '.managegroups-edit', function () {

					var parentObj = jQuery( this ).closest( '.mainwp-group-row' );
					var curActive = parentObj.hasClass('active') ? true : false;

					jQuery('.mainwp-group-row').removeClass('active'); // remove all active.
					jQuery('.mainwp-group-sites-row').removeClass('active'); // hide all sites row.

					if ( curActive ) {
						parentObj.removeClass('active');
						parentObj.next('.mainwp-group-sites-row').removeClass('active');
					} else {
						parentObj.addClass('active');
						parentObj.next('.mainwp-group-sites-row').addClass('active');
					}

					if ( jQuery( '.mainwp-group-row.active' ).length > 0 ) {
						jQuery( '.managegroups-saveAll' ).show();
					} else {
						jQuery( '.managegroups-saveAll' ).hide();
					}

					var groupId = parentObj.attr( 'group-id' );

					var data = mainwp_secure_data( {
						action: 'mainwp_group_getsites',
						groupId: groupId
					} );

					jQuery( '.managegroups-saveAll' ).attr( "disabled", true );
					jQuery.post( ajaxurl, data, function ( response ) {
						jQuery('.managegroups-saveAll').removeAttr("disabled");

						response = jQuery.trim( response );
						if ( response == 'ERROR' )
							return;

						jQuery( 'input[name="sites"]' ).attr( 'checked', false );

						var websiteIds = jQuery.parseJSON( response );
						for ( var i = 0; i < websiteIds.length; i++ ) {
							parentObj.next( 'tr' ).find( 'input[name="sites"][value="' + websiteIds[i] + '"]' ).attr( 'checked', true );
						}
					} );
				} );

				jQuery( document ).on( 'click', '.managegroups-saveAll', function () {
					var checkedGroup = jQuery( '#mainwp-manage-groups tr.mainwp-group-row.active' );
					var groupId = checkedGroup.attr( 'group-id' );

					if ( groupId == undefined )
						return;


					var allCheckedWebsites = jQuery( '#mainwp-manage-groups tr.mainwp-group-sites-row.active' ).find( 'input[name="sites"]:checked' );
					var allCheckedIds = [ ];
					for ( var i = 0; i < allCheckedWebsites.length; i++ ) {
						allCheckedIds.push( jQuery( allCheckedWebsites[i] ).val() );
					}

					var data = mainwp_secure_data( {
						action: 'mainwp_group_updategroup',
						groupId: groupId,
						websiteIds: allCheckedIds
					} );

					var btn = this;
					jQuery(btn).attr("disabled", true);
					jQuery.post( ajaxurl, data, function ( response ) {
						jQuery(btn).removeAttr("disabled");
						jQuery( '#mainwp-message-zone' ).stop( true, true );
						jQuery( '#mainwp-message-zone' ).show();
							jQuery( '#mainwp-message-zone' ).fadeOut( 3000 );
						return;
					}, 'json' );
				} );
			} );
		</script>
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
