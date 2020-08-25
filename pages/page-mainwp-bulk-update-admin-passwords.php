<?php
/**
 * Bulk Update Admin Passwords.
 *
 * Handles bulk updating of Administrator Passwords.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Bulk_Update_Admin_Passwords
 *
 * @uses MainWP_Bulk_Add
 */
class MainWP_Bulk_Update_Admin_Passwords {

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
	 * Add Users sub menu "Admin Passwords".
	 */
	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab',
			__( 'Admin Passwords', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Admin Passwords', 'mainwp' ) . '</div>',
			'read',
			'UpdateAdminPasswords',
			array(
				self::get_class_name(),
				'render',
			)
		);
	}

	/**
	 * Renders the Admin Passwords page footer.
	 *
	 * @param string $shownPage Current page.
	 */
	public static function render_footer( $shownPage ) {
		echo '</div>';
	}

	/**
	 * Renders the Admin Passwords page.
	 */
	public static function render() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$show_form = true;
		$errors    = array();

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
				if ( ( 'group' == $_POST['select_by'] && 0 == count( $selected_groups ) ) || ( 'site' == $_POST['select_by'] && 0 == count( $selected_sites ) ) ) {
					$errors[] = __( 'Please select the sites or groups where you want to change the administrator password.', 'mainwp' );
				}
			} else {
				$errors[] = __( 'Please select whether you want to change the administrator password for specific sites or groups.', 'mainwp' );
			}

			if ( ! isset( $_POST['password'] ) || '' == $_POST['password'] ) {
				$errors[] = __( 'Please enter the password.', 'mainwp' );
			}

			if ( count( $errors ) == 0 ) {
				$show_form = false;

				$new_password = array(
					'user_pass' => wp_unslash( $_POST['password'] ),
				);

				$dbwebsites = array();
				if ( 'site' == $_POST['select_by'] ) { // Get all selected websites.
					foreach ( $selected_sites as $k ) {
						if ( MainWP_Utility::ctype_digit( $k ) ) {
							$website                    = MainWP_DB::instance()->get_website_by_id( $k );
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								array(
									'id',
									'url',
									'name',
									'adminname',
									'nossl',
									'privkey',
									'nosslkey',
									'http_user',
									'http_pass',
									'ssl_version',
								)
							);
						}
					}
				} else { // Get all websites from the selected groups.
					foreach ( $selected_groups as $k ) {
						if ( MainWP_Utility::ctype_digit( $k ) ) {
							$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $k ) );
							while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
								if ( '' != $website->sync_errors ) {
									continue;
								}
								$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
									$website,
									array(
										'id',
										'url',
										'name',
										'adminname',
										'nossl',
										'privkey',
										'nosslkey',
										'http_user',
										'http_pass',
										'ssl_version',
									)
								);
							}
							MainWP_DB::free_result( $websites );
						}
					}
				}

				if ( count( $dbwebsites ) > 0 ) {
					$post_data      = array( 'new_password' => base64_encode( serialize( $new_password ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
					$output         = new \stdClass();
					$output->ok     = array();
					$output->errors = array();

					MainWP_Connect::fetch_urls_authed(
						$dbwebsites,
						'newadminpassword',
						$post_data,
						array(
							MainWP_Bulk_Add::get_class_name(),
							'posting_bulk_handler',
						),
						$output
					);
				}
			}
		}
		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'admin_nicename', 'admin_useremail' ) ) );

		MainWP_User::render_header( 'UpdateAdminPasswords' );
		if ( ! $show_form ) {
			self::render_modal( $dbwebsites, $output );
		}
		self::render_bulk_form( $websites );
		MainWP_User::render_footer( 'UpdateAdminPasswords' );
	}

	/**
	 * Renders update password results.
	 *
	 * @param object $dbwebsites The websites object.
	 * @param object $output Result of update password.
	 */
	public static function render_modal( $dbwebsites, $output ) {
		?>
		<div class="ui modal" id="mainwp-reset-admin-passwords-modal">
			<div class="header"><?php esc_html_e( 'Update Admin Password', 'mainwp' ); ?></div>
			<div class="scrolling content">
				<?php
				/**
				 * Action: mainwp_reset_admin_pass_modal_top
				 *
				 * Fires at the top of the Update Admin Passwords modal.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_reset_admin_pass_modal_top' );
				?>
				<div class="ui relaxed divided list">
					<?php foreach ( $dbwebsites as $website ) : ?>
						<div class="item">
							<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
							<span class="right floated content">
								<?php echo( isset( $output->ok[ $website->id ] ) && 1 == $output->ok[ $website->id ] ? '<i class="green check icon"></i>' : '<i class="red times icon"></i> ' . $output->errors[ $website->id ] ); ?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
				<?php
				/**
				 * Action: mainwp_reset_admin_pass_modal_bottom
				 *
				 * Fires at the bottom of the Update Admin Passwords modal.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_reset_admin_pass_modal_bottom' );
				?>
			</div>
			<div class="actions">
				<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery( '#mainwp-reset-admin-passwords-modal' ).modal( 'show' );
		</script>
		<?php
	}

	/**
	 * Renders bulk update administrator password form.
	 *
	 * @param object $websites Object conaining child sites info.
	 */
	public static function render_bulk_form( $websites ) {

		/**
		 * Filter: mainwp_update_admin_password_complexity
		 *
		 * Filters the Password lenght for the Update Admin Password, Password field.
		 *
		 * Since 4.1
		 */
		$pass_complexity = apply_filters( 'mainwp_update_admin_password_complexity', '24' );
		?>
		<div class="ui alt segment" id="mainwp-bulk-update-admin-passwords">
				<form action="" method="post" name="createuser" id="createuser">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<input type="hidden" name="security" value="<?php echo wp_create_nonce( 'mainwp_updateadminpassword' ); ?>"/>
					<div class="mainwp-main-content">
						<div class="ui hidden divider"></div>
						<?php
						/**
						 * Action: mainwp_admin_pass_before_users_table
						 *
						 * Fires before the Connected Admin Users mysql_list_tables
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_admin_pass_before_users_table' );
						?>
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
								<?php while ( $websites && $website = MainWP_DB::fetch_object( $websites ) ) : ?>
									<tr>
									<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
									<td><?php echo esc_html( $website->adminname ); ?></td>
									<td><?php echo esc_html( $website->admin_nicename ); ?></td>
									<td><?php echo esc_html( $website->admin_useremail ); ?></td>
								</tr>
								<?php endwhile; ?>
								<?php MainWP_DB::free_result( $websites ); ?>
							</tbody>
						</table>
						<?php
						/**
						 * Action: mainwp_admin_pass_after_users_table
						 *
						 * Fires after the Connected Admin Users mysql_list_tables
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_admin_pass_after_users_table' );

						$table_features = array(
							'searching'  => 'true',
							'paging'     => 'true',
							'info'       => 'true',
							'colReorder' => 'true',
							'stateSave'  => 'true',
						);
						/**
						 * Filter: mainwp_admin_users_table_fatures
						 *
						 * Filters Admin Users table features.
						 *
						 * @since 4.1
						 */
						$table_features = apply_filters( 'mainwp_admin_users_table_fatures', $table_features );
						?>
						<script type="text/javascript">
						jQuery( document ).ready( function () {
							jQuery( '#mainwp-admin-users-table' ).DataTable( {
								"searching" : <?php echo $table_features['searching']; ?>,
								"paging" : <?php echo $table_features['paging']; ?>,
								"info" : <?php echo $table_features['info']; ?>,
								"colReorder" : <?php echo $table_features['colReorder']; ?>,
								"stateSave":  <?php echo $table_features['stateSave']; ?>,
								"order": [],
								"columnDefs": [ { "targets": 'no-sort', "orderable": false } ],
							} );
						} );
						</script>
					</div>
					<div class="mainwp-side-content mainwp-no-padding">
						<?php
						/**
						 * Action: mainwp_admin_pass_sidebar_top
						 *
						 * Fires at the top of the sidebar on Admin Passwords page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_admin_pass_sidebar_top' );
						?>
						<div class="mainwp-select-sites">
							<?php
							/**
							 * Action: mainwp_admin_pass_before_select_sites
							 *
							 * Fires before the Select Sites section on the Admin Passwords page.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_admin_pass_before_select_sites' );
							?>
							<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
							<?php MainWP_UI::select_sites_box(); ?>
							<?php
							/**
							 * Action: mainwp_admin_pass_after_select_sites
							 *
							 * Fires after the Select Sites section on the Admin Passwords page.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_admin_pass_after_select_sites' );
							?>
						</div>
						<div class="ui divider"></div>
						<div class="mainwp-search-options">
							<div class="ui header"><?php esc_html_e( 'Update Admin Password', 'mainwp' ); ?></div>
							<?php
							/**
							 * Action: mainwp_admin_pass_before_pass_form
							 *
							 * Fires before the New password form on the Admin Passwords page.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_admin_pass_before_pass_form' );
							?>
							<div class="ui mini form">
								<div class="field">
									<label><?php esc_html_e( 'New Password', 'mainwp' ); ?></label>
									<div class="ui fluid input">
										<input class="hidden" value=" "/>
										<input type="text" id="password" name="password" autocomplete="off" value="<?php echo esc_attr( wp_generate_password( $pass_complexity ) ); ?>">
									</div>
									<br />
									<button class="ui basic green fluid button wp-generate-pw"><?php esc_html_e( 'Generate New Password', 'mainwp' ); ?></button>
								</div>
							</div>
							<?php
							/**
							 * Action: mainwp_admin_pass_after_pass_form
							 *
							 * Fires after the New password form on the Admin Passwords page.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_admin_pass_after_pass_form' );
							?>
						</div>
						<div class="ui divider"></div>
						<div class="mainwp-search-submit">
							<?php
							/**
							 * Action: mainwp_admin_pass_before_submit_button
							 *
							 * Fires before the Submit button on the Admin Passwords page.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_admin_pass_before_submit_button' );
							?>
							<input type="submit" name="bulk_updateadminpassword" id="bulk_updateadminpassword" class="ui big green fluid button" value="<?php esc_attr_e( 'Update Password', 'mainwp' ); ?> "/>
							<?php
							/**
							 * Action: mainwp_admin_pass_after_submit_button
							 *
							 * Fires after the Submit button on the Admin Passwords page.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_admin_pass_after_submit_button' );
							?>
						</div>
						<?php
						/**
						 * Action: mainwp_admin_pass_sidebar_bottom
						 *
						 * Fires at the bottom of the sidebar on Admin Passwords page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_admin_pass_sidebar_bottom' );
						?>
					</div>
					<div style="clear:both"></div>
				</form>
			</div>
		<?php
	}
}

?>
