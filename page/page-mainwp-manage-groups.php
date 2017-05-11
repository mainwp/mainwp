<?php

class MainWP_Manage_Groups {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function initMenu() {
		add_submenu_page( 'mainwp_tab', __( 'Groups', 'mainwp' ), '<div id="mainwp-Groups" class="mainwp-hidden">' . __( 'Groups', 'mainwp' ) . '</div>', 'read', 'ManageGroups', array(
			MainWP_Manage_Groups::getClassName(),
			'renderAllGroups',
		) );
	}

	public static function renderAllGroups() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_groups' ) ) {
			mainwp_do_not_have_permissions( __( 'manage groups', 'mainwp' ) );

			return;
		}
		?>

			<?php do_action( 'mainwp-pageheader-sites', 'ManageGroups' ); ?>			
			<?php MainWP_Tours::renderGroupsTour(); ?>
			<div class="mainwp-notice mainwp-notice-blue">
				<span><?php _e( 'In case you are managing large number of WordPress sites, it would be very useful for you to split them in different groups. Later, you will be able to make site selection by group which will speed up your work and make it much easier.', 'mainwp' ); ?></span>
			</div>
			<div class="metabox-holder columns-2">
			<div id="normal-sortables" class="meta-box-sortables ui-sortable">
				<div class="mainwp-cols-2 mainwp-left">
					<div class="mainwp_managegroups-insidebox postbox">
						<h2 class="hndle ui-sortable-handle"><span><?php _e('Groups','mainwp'); ?></span></h2>
						<div class="mainwp-postbox-actions-top">
							<input id="managegroups-filter" style="margin: 1em 0; width: 40%;" type="text" value="" placeholder="<?php esc_attr_e( 'Type here to filter groups', 'mainwp' );?>" />
							<span id="mainwp_managegroups-addnew-container" class="mainwp-right mainwp-padding-top-20"><a class="managegroups-addnew" href="javascript:void(0)"><i class="fa fa-plus"></i> <?php _e('Create New Group','mainwp'); ?></a></span>
						</div>
						<div class="inside">
							<ul id="managegroups-list">
								<li class="managegroups-listitem mainwp-padding-5 managegroups-group-add hidden">
									<span class="mainwp-right actions-input"><a href="#" class="managegroups-savenew"><i class="fa fa-floppy-o"></i> <?php _e('Save','mainwp'); ?></a> | <a href="#" class="managegroups-cancel"><i class="fa fa-times-circle"></i> <?php _e('Cancel','mainwp'); ?></a></span>
									<input type="text" style="width: 50%;" placeholder="<?php esc_attr_e('Enter Group Name','mainwp'); ?>" name="name" value="" />
								</li>
								<?php echo MainWP_Manage_Groups::getGroupListContent(); ?>
							</ul>
						</div>
					</div>
				</div>
				<div class="mainwp-cols-2 mainwp-right">
					<div class="mainwp_managegroups-insidebox postbox" id="managegroups-sites-list">
						<h2 class="hndle ui-sortable-handle"><span><?php _e('Child Sites','mainwp'); ?></span></h2>
						<div class="mainwp-postbox-actions-top">
							<input id="managegroups_site-filter" style="margin: 1em 0; width: 40%;" type="text" value="" placeholder="<?php esc_attr_e( 'Type here to filter sites', 'mainwp'); ?>" />
							<div style="float: right; margin-top: 20px; margin-left: 1em;"><?php _e('Select: ','mainwp'); ?><a href="#" onClick="return mainwp_managegroups_ss_select(this, true)"><?php _e('All','mainwp'); ?></a> | <a href="#" onClick="return mainwp_managegroups_ss_select(this, false)"><?php _e('None','mainwp'); ?></a></div>
							<div style="float: right; margin-top: 20px;"><?php _e('Display by:','mainwp'); ?> <a href="#" id="group_sites_by_name"><?php _e('Site Name','mainwp'); ?></a> | <a href="#" id="group_sites_by_url"><?php _e('URL','mainwp'); ?></a></div>
						</div>
						<div class="inside">
							<ul id="managegroups-listsites">
								<?php echo MainWP_Manage_Groups::getWebsiteListContent(); ?>
							</ul>
						</div>
					</div>
				</div>
				<div class="mainwp-clear"></div>
			</div>
			</div>
			<input type="button" id="mainwp-save-group-selection" name="Save selection" value="<?php _e( 'Save Selection', 'mainwp' ); ?>" class="managegroups-saveAll button-primary button button-hero"/>
			<span id="managegroups-saved"><?php _e( 'Saved', 'mainwp' ); ?></span>

		<?php do_action( 'mainwp-pagefooter-sites', 'ManageGroups' ); ?>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '#group_sites_by_name' ).live( 'click', function ( event ) {
					//jQuery( this ).addClass( 'mainwp_action_down' );
					//jQuery( '#group_sites_by_url' ).removeClass( 'mainwp_action_down' );
					jQuery( '#managegroups-sites-list' ).find( '.website_url' ).hide();
					jQuery( '#managegroups-sites-list' ).find( '.website_name' ).show();
					return false;
				} );
				jQuery( '#group_sites_by_url' ).live( 'click', function ( event ) {
					//jQuery( this ).addClass( 'mainwp_action_down' );
					//jQuery( '#group_sites_by_name' ).removeClass( 'mainwp_action_down' );
					jQuery( '#managegroups-sites-list' ).find( '.website_name' ).hide();
					jQuery( '#managegroups-sites-list' ).find( '.website_url' ).show();
					return false;
				} );

				jQuery( '.managegroups-listitem' ).live( {
					mouseenter: function () {
						if ( jQuery( this ).find( '.text' ).is( ":visible" ) ) jQuery( this ).find( '.actions-text' ).show();
						else jQuery( this ).find( '.actions-input' ).show();
					},
					mouseleave: function () {
						jQuery( this ).find( '.actions-text' ).hide();
						jQuery( this ).find( '.actions-input' ).hide();
					}
				} );

				jQuery( '.managegroups-rename' ).live( 'click', function () {
					var parentObj = jQuery( this ).parents( '.managegroups-listitem' );
					parentObj.find( '.text' ).hide();
					parentObj.find( '.actions-text' ).hide();
					parentObj.find( '.input' ).show();
					parentObj.find( '.actions-input' ).show();
					return false;
				} );

				jQuery( '.managegroups-save' ).live( 'click', function () {
					var parentObj = jQuery( this ).parents( '.managegroups-listitem' );
					var groupId = parentObj.attr( 'id' );
					var newName = parentObj.find( '.input input' ).val();

					var data = mainwp_secure_data( {
						action: 'mainwp_group_rename',
						groupId: groupId,
						newName: newName
					} );

					jQuery.post( ajaxurl, data, function ( pParentObj ) {
						return function ( response ) {
							if ( response.error ) return;

							response = jQuery.trim( response.result );
							pParentObj.find( '.input input' ).val( response );
							pParentObj.find( '.text' ).html( response );

							pParentObj.find( '.input' ).hide();
							pParentObj.find( '.actions-input' ).hide();
							pParentObj.find( '.text' ).show();
							pParentObj.find( '.actions-text' ).show();
						}
					}( parentObj ), 'json' );

					return false;
				} );

				jQuery( '.managegroups-delete' ).live( 'click', function () {
					var confirmed = confirm( 'This will permanently delete this group. Proceed?' );
					if ( confirmed ) {
						var parentObj = jQuery( this ).parents( '.managegroups-listitem' );
						parentObj.css( 'background-color', '#F8E0E0' );
						var groupId = parentObj.attr( 'id' );

						var data = mainwp_secure_data( {
							action: 'mainwp_group_delete',
							groupId: groupId
						} );

						jQuery.post( ajaxurl, data, function ( pParentObj ) {
							return function ( response ) {
								response = jQuery.trim( response );
								if ( response == 'OK' ) pParentObj.animate( {opacity: 0}, 300, function () {
									pParentObj.remove()
								} );
							}
						}( parentObj ) );
					}
					return false;
				} );

				jQuery( '.managegroups-addnew' ).live( 'click', function () {
					var addNewContainer = jQuery( '.managegroups-group-add' );
					addNewContainer.find( 'input' ).val( '' );
					addNewContainer.show();
				} );

				jQuery( '.managegroups-cancel' ).live( 'click', function () {
					var addNewContainer = jQuery( '.managegroups-group-add' );
					addNewContainer.hide();
					addNewContainer.find( 'input' ).val( '' );
				} );

				jQuery( '.managegroups-savenew' ).live( 'click', function () {
					var parentObj = jQuery( this ).parents( '.managegroups-listitem' );
					var newName = parentObj.find( 'input' ).val();

					var data = mainwp_secure_data( {
						action: 'mainwp_group_add',
						newName: newName
					} );

					jQuery.post( ajaxurl, data, function ( response ) {
						try {
							resp = jQuery.parseJSON( response );

							if ( resp.error != undefined ) return;
						}
						catch ( err ) {

						}

						response = jQuery.trim( response );

						var addNewContainer = jQuery( '.managegroups-group-add' );
						addNewContainer.hide();
						addNewContainer.find( 'input' ).val( '' );

						addNewContainer.after( response );
					} );

					return false;
				} );

				jQuery( '.managegroups-radio' ).live( 'click', function () {
					var parentObj = jQuery( this ).parents( '.managegroups-listitem' );
					var groupId = parentObj.attr( 'id' );

					var data = {
						action: 'mainwp_group_getsites',
						groupId: groupId
					}

					jQuery.post( ajaxurl, data, function ( response ) {
						response = jQuery.trim( response );
						if ( response == 'ERROR' ) return;

						jQuery( 'input[name="sites"]' ).attr( 'checked', false );

						var websiteIds = jQuery.parseJSON( response );
						for ( var i = 0; i < websiteIds.length; i++ ) {
							jQuery( 'input[name="sites"][value="' + websiteIds[i] + '"]' ).attr( 'checked', true );
						}
					} );
				} );

				jQuery( '.managegroups-saveAll' ).live( 'click', function () {
					var checkedGroup = jQuery( 'input[name="groups"]:checked' );
					var groupId = checkedGroup.val();
					if ( groupId == undefined ) return;

					var allCheckedWebsites = jQuery( 'input[name="sites"]:checked' );
					var allCheckedIds = [];
					for ( var i = 0; i < allCheckedWebsites.length; i++ ) {
						allCheckedIds.push( jQuery( allCheckedWebsites[i] ).val() );
					}

					var data = mainwp_secure_data( {
						action: 'mainwp_group_updategroup',
						groupId: groupId,
						websiteIds: allCheckedIds
					} );

					jQuery.post( ajaxurl, data, function ( response ) {
						jQuery( '#managegroups-saved' ).stop( true, true );
						jQuery( '#managegroups-saved' ).show();
						jQuery( '#managegroups-saved' ).fadeOut( 2000 );
						return;
					}, 'json' );
				} );
			} );
		</script>
		<?php
	}

	public static function getGroupListContent() {
		$groups = MainWP_DB::Instance()->getGroupsAndCount();

		foreach ( $groups as $group ) {
			self::createGroupItem( $group );
		}
	}

	private static function createGroupItem( $group ) {
		?>
		<li id="<?php echo $group->id; ?>" class="managegroups-listitem mainwp-padding-5">
			<span class="mainwp-right actions-text hidden"><a href="#" class="managegroups-rename"><i class="fa fa-pencil-square-o"></i> <?php _e('Rename','mainwp'); ?></a> | <a href="#" class="managegroups-delete"><i class="fa fa-trash-o"></i> <?php _e('Delete','mainwp'); ?></a></span>
			<span class="mainwp-right actions-input hidden"><a href="#" class="managegroups-save"><i class="fa fa-floppy-o"></i> <?php _e('Save','mainwp'); ?></a> | <a href="#" class="managegroups-delete"><i class="fa fa-trash-o"></i> <?php _e('Delete','mainwp'); ?></a></span>
            	<input type="radio" name="groups" value="<?php echo $group->id; ?>" class="managegroups-radio" id="<?php echo MainWP_Utility::getNiceURL( $group->id ); ?>">
            	<label for="<?php echo MainWP_Utility::getNiceURL( $group->id ); ?>"></label>
			<span class="text"><?php echo stripslashes( $group->name ); ?></span>
            <span class="input hidden">
                <input type="text" style="width: 50%" name="name" placeholder="<?php esc_attr_e('Enter group name','mainwp'); ?>" value="<?php echo $group->name; ?>" />
            </span>
		</li>
		<?php
	}

	public static function getWebsiteListContent() {
		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );

		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			?>
			<li class="managegroups_site-listitem mainwp-padding-5">
				<input type="checkbox" name="sites" value="<?php echo $website->id; ?>" id="<?php echo MainWP_Utility::getNiceURL( $website->url ); ?>" ><label for="<?php echo MainWP_Utility::getNiceURL( $website->url ); ?>"><span class="website_url" style="display: none;"><?php echo MainWP_Utility::getNiceURL( $website->url ); ?></span><span class="website_name"><?php echo stripslashes( $website->name ); ?></span></label>
			</li>
			<?php
		}
		@MainWP_DB::free_result( $websites );
	}

	public static function renameGroup() {
		if ( isset( $_POST['groupId'] ) && MainWP_Utility::ctype_digit( $_POST['groupId'] ) ) {
			$group = MainWP_DB::Instance()->getGroupById( $_POST['groupId'] );
			if ( MainWP_Utility::can_edit_group( $group ) ) {
				$name = $_POST['newName'];
				if ( $name == '' ) {
					$name = $group->name;
				}

				$name = self::checkGroupName( $name, $group->id );
				//update group
				$nr = MainWP_DB::Instance()->updateGroup( $group->id, $name );

				//Reload group
				$group = MainWP_DB::Instance()->getGroupById( $group->id );
				die( json_encode( array( 'result' => $group->name ) ) );
			}
		}
	}

	public static function deleteGroup() {
		if ( isset( $_POST['groupId'] ) && MainWP_Utility::ctype_digit( $_POST['groupId'] ) ) {
			$group = MainWP_DB::Instance()->getGroupById( $_POST['groupId'] );
			if ( MainWP_Utility::can_edit_group( $group ) ) {
				//Remove from DB
				$nr = MainWP_DB::Instance()->removegroup( $group->id );

				if ( $nr > 0 ) {
					die( 'OK' );
				}
			}
		}
		die( 'ERROR' );
	}

	protected static function checkGroupName( $groupName, $groupId = null ) {
		if ( $groupName == '' ) {
			$groupName = __( 'New group', 'mainwp' );
		}

		$groupName = esc_html($groupName);

		$cnt = null;
		if ( preg_match( '/(.*) \(\d\)/', $groupName, $matches ) ) {
			$groupName = $matches[1];
		}

		$group = MainWP_DB::Instance()->getGroupByNameForUser( $groupName );
		while ( $group && ( ( $groupId == null ) || ( $group->id != $groupId ) ) ) {
			if ( $cnt == null ) {
				$cnt = 1;
			} else {
				$cnt ++;
			}

			$group = MainWP_DB::Instance()->getGroupByNameForUser( $groupName . ' (' . $cnt . ')' );
		}

		return $groupName . ( $cnt == null ? '' : ' (' . $cnt . ')' );
	}

	public static function addGroup() {
		global $current_user;
		if ( isset( $_POST['newName'] ) ) {
			$groupId = MainWP_DB::Instance()->addGroup( $current_user->ID, self::checkGroupName( $_POST['newName'] ) );
			do_action('mainwp_added_new_group', $groupId);
			$group   = MainWP_DB::Instance()->getGroupById( $groupId );
			self::createGroupItem( $group );
			die();
		}
		die( json_encode( array( 'error' => 1 ) ) );
	}

	public static function getSites() {
		if ( isset( $_POST['groupId'] ) && MainWP_Utility::ctype_digit( $_POST['groupId'] ) ) {
			$group = MainWP_DB::Instance()->getGroupById( $_POST['groupId'] );
			if ( MainWP_Utility::can_edit_group( $group ) ) {
				$websites   = MainWP_DB::Instance()->getWebsitesByGroupId( $group->id );
				$websiteIds = array();
				if ( ! empty( $websites ) ) {
					foreach ( $websites as $website ) {
						$websiteIds[] = $website->id;
					}
				}

				return json_encode( $websiteIds );
			}
		}
		die( 'ERROR' );
	}

	public static function updateGroup() {
		if ( isset( $_POST['groupId'] ) && MainWP_Utility::ctype_digit( $_POST['groupId'] ) ) {
			$group = MainWP_DB::Instance()->getGroupById( $_POST['groupId'] );
			if ( MainWP_Utility::can_edit_group( $group ) ) {
				MainWP_DB::Instance()->clearGroup( $group->id );
				if ( isset( $_POST['websiteIds'] ) ) {
					foreach ( $_POST['websiteIds'] as $websiteId ) {
						$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
						if ( MainWP_Utility::can_edit_website( $website ) ) {
							MainWP_DB::Instance()->updateGroupSite( $group->id, $website->id );
						}
					}
				}
				die( json_encode( array( 'result' => true ) ) );
			}
		}

		die( json_encode( array( 'result' => false ) ) );
	}
}
