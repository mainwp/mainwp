<?php

/**
 * MainWP Bulk Update Admin Passwords
 * 
 * @uses MainWP_Bulk_Add
 */
class MainWP_Bulk_Update_Admin_Passwords {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function initMenu() {
		$_page = add_submenu_page( 'mainwp_tab', __( 'Admin Passwords', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Admin Passwords', 'mainwp' ) . '</div>', 'read', 'UpdateAdminPasswords', array(
			MainWP_Bulk_Update_Admin_Passwords::getClassName(),
			'render',
		) );
		//add_action( 'load-' . $_page, array('MainWP_Bulk_Update_Admin_Passwords', 'on_load_page'));
	}

	public static function renderFooter( $shownPage ) {
		echo "</div>";
	}

	public static function render() {
		$show_form = true;
        $errors = array();

		if ( isset( $_POST['bulk_updateadminpassword'] ) ) {
			check_admin_referer( 'mainwp_updateadminpassword', 'security' );

			if ( isset( $_POST['select_by'] ) ) {
				$selected_sites = array();
				if ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) {
					foreach ( $_POST['selected_sites'] as $selected ) {
						$selected_sites[] = $selected;
					}
				}
				$selected_groups = array();
				if ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) {
					foreach ( $_POST['selected_groups'] as $selected ) {
						$selected_groups[] = $selected;
					}
				}
				if ( ( $_POST['select_by'] == 'group' && count( $selected_groups ) == 0 ) || ( $_POST['select_by'] == 'site' && count( $selected_sites ) == 0 ) ) {
					$errors[] = __( 'Please select the sites or groups where you want to change the administrator password.', 'mainwp' );
				}
			} else {
				$errors[] = __( 'Please select whether you want to change the administrator password for specific sites or groups.', 'mainwp' );
			}

//			if ( ! isset( $_POST['pass1'] ) || $_POST['pass1'] == '' || ! isset( $_POST['pass2'] ) || $_POST['pass2'] == '' ) {
//				$errors[] = __( 'Please enter the password twice.', 'mainwp' );
//			} else if ( $_POST['pass1'] != $_POST['pass2'] ) {
//				$errors[] = __( 'Please enter the same password in the both password fields.', 'mainwp' );
//			}

            if ( ! isset( $_POST['password'] ) || $_POST['password'] == '' ) {
                $errors[] = __( 'Please enter the password.', 'mainwp' );
            }

			if ( count( $errors ) == 0 ) {
				$show_form = false;

				$new_password = array(
					'user_pass' => $_POST['password'],
				);

				$dbwebsites = array();
				if ( $_POST['select_by'] == 'site' ) { //Get all selected websites
					foreach ( $selected_sites as $k ) {
						if ( MainWP_Utility::ctype_digit( $k ) ) {
							$website                    = MainWP_DB::Instance()->getWebsiteById( $k );
							$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
								'id',
								'url',
								'name',
								'adminname',
								'nossl',
								'privkey',
								'nosslkey',
                                'http_user',
                                'http_pass'
							) );
						}
					}
				} else { //Get all websites from the selected groups
					foreach ( $selected_groups as $k ) {
						if ( MainWP_Utility::ctype_digit( $k ) ) {
							$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $k ) );
							while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
								if ( $website->sync_errors != '' ) {
									continue;
								}
								$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
									'id',
									'url',
									'name',
									'adminname',
									'nossl',
									'privkey',
									'nosslkey',
                                    'http_user',
                                    'http_pass'
								) );
							}
							@MainWP_DB::free_result( $websites );
						}
					}
				}

				if ( count( $dbwebsites ) > 0 ) {
					$post_data      = array( 'new_password' => base64_encode( serialize( $new_password ) ) );
					$output         = new stdClass();
					$output->ok     = array();
					$output->errors = array();

					MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'newadminpassword', $post_data, array(
						MainWP_Bulk_Add::getClassName(),
						'PostingBulk_handler',
					), $output );
				}
			}
		}

        MainWP_User::renderHeader( 'UpdateAdminPasswords' );

		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.url', false, false, null, false, array( 'admin_nicename', 'admin_useremail' )) );
			?>
		<?php if ( ! $show_form ) : ?>
			<div class="ui modal" id="mainwp-reset-admin-passwords-modal">
				<div class="header"><?php esc_html_e( 'Update Admin Password', 'mainwp' ); ?></div>
        <div class="scrolling content">
					<div class="ui relaxed divided list">
						<?php foreach ( $dbwebsites as $website ) : ?>
							<div class="item">
								<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
								<span class="right floated content">
									<?php echo( isset( $output->ok[ $website->id ] ) && $output->ok[ $website->id ] == 1 ? '<i class="green check icon"></i>' : '<i class="red times icon"></i> ' . $output->errors[ $website->id ] ); ?>
								</span>
                    </div>
						<?php endforeach; ?>
                </div>
			</div>
        <div class="actions">
					<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
				</div>
      </div>
			<script type="text/javascript">
				jQuery( '#mainwp-reset-admin-passwords-modal' ).modal( 'show' );
			</script>
		<?php endif; ?>
        <div class="ui alt segment" id="mainwp-bulk-update-admin-passwords">
				<form action="" method="post" name="createuser" id="createuser">
				<input type="hidden" name="security" value="<?php echo wp_create_nonce( 'mainwp_updateadminpassword' ); ?>"/>
					<div class="mainwp-main-content">
						<div class="ui hidden divider"></div>
						<h3 class="ui dividing header"><?php esc_html_e( 'Connected Admin Users', 'mainwp' ); ?></h3>
						<table  id="mainwp-admin-users-table" class="ui padded selectable compact single line table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Admin Username', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Admin Name', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Admin Email', 'mainwp' ); ?></th>
                </tr>
							</thead>
							<tbody>
								<?php while ( $websites && $website = @MainWP_DB::fetch_object( $websites ) ) : ?>
                <tr>
									<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
									<td><?php echo esc_html($website->adminname); ?></td>
									<td><?php echo esc_html($website->admin_nicename); ?></td>
									<td><?php echo esc_html($website->admin_useremail); ?></td>
                </tr>
								<?php endwhile; ?>
								<?php @MainWP_DB::free_result( $websites ); ?>
							</tbody>
            </table>
						<script type="text/javascript">
					    jQuery( document ).ready( function () {
					      jQuery( '#mainwp-admin-users-table' ).DataTable( {
							"colReorder" : true,
							"stateSave":  true,
					        "pagingType": "full_numbers",
					        "order": [],
					        "columnDefs": [ { "targets": 'no-sort', "orderable": false } ],
					      } );
					    } );
					  </script>
					</div>
					<div class="mainwp-side-content mainwp-no-padding">
						<div class="mainwp-select-sites">
							<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
							<?php MainWP_UI::select_sites_box(); ?>
						</div>
						<div class="ui divider"></div>
						<div class="mainwp-search-options">
							<div class="ui header"><?php esc_html_e( 'Update Admin Password', 'mainwp' ); ?></div>
							<div class="ui mini form">
								<div class="field">
									<label><?php esc_html_e( 'New Password', 'mainwp' ); ?></label>
									<div class="ui fluid input">
										<input class="hidden" value=" "/>
										<input type="text" id="password" name="password" autocomplete="off" value="<?php echo esc_attr( wp_generate_password( 24 ) ); ?>">
                  </div>
									<br />
									<button class="ui basic green fluid button wp-generate-pw"><?php esc_html_e( 'Generate New Password', 'mainwp' ); ?></button>
	              </div>
							</div>
						</div>
						<div class="ui divider"></div>
						<div class="mainwp-search-submit">
							<input type="submit" name="bulk_updateadminpassword" id="bulk_updateadminpassword" class="ui big green fluid button" value="<?php esc_attr_e( 'Update Password', 'mainwp' ); ?> "/>
						</div>
					</div>
					<div style="clear:both"></div>
				</form>
			</div>
	<?php
        MainWP_User::renderFooter( 'UpdateAdminPasswords' );
    }
}

?>