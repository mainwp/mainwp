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
			'<div class="mainwp-hidden">' . esc_html__( 'Admin Passwords', 'mainwp' ) . '</div>',
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
	 */
	public static function render_footer() {
		echo '</div>';
	}

	/**
	 * Renders the Admin Passwords page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Bulk_Add::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_User::render_header()
	 * @uses \MainWP\Dashboard\MainWP_User::render_footer()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::map_site()
	 */
	public static function render() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$show_form = true;
		$errors    = array();

		if ( isset( $_POST['bulk_updateadminpassword'] ) ) {
			check_admin_referer( 'mainwp_updateadminpassword', 'security' );

			if ( isset( $_POST['select_by'] ) ) {
				$selected_sites   = ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_sites'] ) ) : array();
				$selected_groups  = ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_groups'] ) ) : array();
				$selected_clients = ( isset( $_POST['selected_clients'] ) && is_array( $_POST['selected_clients'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_clients'] ) ) : array();

				if ( ( 'group' === $_POST['select_by'] && 0 === count( $selected_groups ) ) || ( 'site' === $_POST['select_by'] && 0 === count( $selected_sites ) ) || ( 'client' === $_POST['select_by'] && 0 === count( $selected_clients ) ) ) {
					$errors[] = esc_html__( 'Please select the sites or groups or clients where you want to change the administrator password.', 'mainwp' );
				}
			} else {
				$errors[] = esc_html__( 'Please select whether you want to change the administrator password for specific sites or groups or clients.', 'mainwp' );
			}

			if ( ! isset( $_POST['password'] ) || '' === trim( wp_unslash( $_POST['password'] ) ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ok.
				$errors[] = esc_html__( 'Please enter the password.', 'mainwp' );
			}

			$data_fields = MainWP_System_Utility::get_default_map_site_fields();

			if ( 0 === count( $errors ) ) {
				$show_form = false;

				$new_password = wp_unslash( $_POST['password'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ok.

				$dbwebsites = array();
				if ( 'site' === $_POST['select_by'] ) { // Get all selected websites.
					foreach ( $selected_sites as $k ) {
						if ( MainWP_Utility::ctype_digit( $k ) ) {
							$website = MainWP_DB::instance()->get_website_by_id( $k );
							if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								$data_fields
							);
						}
					}
				} elseif ( 'client' === $_POST['select_by'] ) { // Get all selected websites.
					$websites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
						$selected_clients,
						array(
							'select_data' => $data_fields,
						)
					);
					if ( $websites ) {
						foreach ( $websites as $website ) {
							if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								$data_fields
							);
						}
					}
				} else { // Get all websites from the selected groups.
					foreach ( $selected_groups as $k ) {
						if ( MainWP_Utility::ctype_digit( $k ) ) {
							$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $k ) );
							while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
								if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
									continue;
								}
								$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
									$website,
									$data_fields
								);
							}
							MainWP_DB::free_result( $websites );
						}
					}
				}

				if ( count( $dbwebsites ) > 0 ) {
					$post_data      = array( 'new_password' => base64_encode( $new_password ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
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
		<i class="close icon"></i>
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
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a>
							<span class="right floated content">
								<?php echo( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ? '<i class="green check icon"></i>' : '<i class="red times icon"></i> ' . $output->errors[ $website->id ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
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
	 * @param object $websites Object containing child sites info.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 */
	public static function render_bulk_form( $websites ) {
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
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
				<input type="hidden" name="security" value="<?php echo esc_attr( wp_create_nonce( 'mainwp_updateadminpassword' ) ); ?>"/>
				<div class="mainwp-main-content" >
					<div class="ui em hidden divider"></div>
					<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-admin-pass-info-message' ) ) : ?>
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-admin-pass-info-message"></i>
							<?php printf( esc_html__( 'See the list of Admininstrator users used to establish secure connection between your MainWP Dashboard and child sites.  If needed, use the provided form to set a new password for these accounts.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/bulk-update-administrator-passwords/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
						</div>
					<?php endif; ?>
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
						<table  id="mainwp-admin-users-table" class="ui single line unstackable table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
									<th class="no-sort collapsing"><i class="sign in icon"></i></th>
									<th><?php esc_html_e( 'Admin Username', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Admin Name', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Admin Email', 'mainwp' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								while ( $websites && $website = MainWP_DB::fetch_object( $websites ) ) :
									$adminname = $website->adminname;
									?>
									<tr>
									<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></td>
									<td><a target="_blank" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><i class="sign in icon"></i></a></td>
									<td><?php echo esc_html( $adminname ); ?></td>
									<td><?php echo esc_html( $website->admin_nicename ); ?></td>
									<td><?php echo esc_html( $website->admin_useremail ); ?></td>
								</tr>
								<?php endwhile; ?>
								<?php MainWP_DB::free_result( $websites ); ?>
							</tbody>
							<tfoot>
								<tr>
									<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
									<th><i class="sign in icon"></i></th>
									<th><?php esc_html_e( 'Admin Username', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Admin Name', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Admin Email', 'mainwp' ); ?></th>
								</tr>
							</tfoot>
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
							'responsive' => 'true',
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
						var responsive = <?php echo esc_html( $table_features['responsive'] ); ?>;
						if( jQuery( window ).width() > 1140 ) {
							responsive = false;
						}
						jQuery( document ).ready( function () {
							jQuery( '#mainwp-admin-users-table' ).DataTable( {
								"searching" : <?php echo esc_html( $table_features['searching'] ); ?>,
								"paging" : <?php echo esc_html( $table_features['paging'] ); ?>,
								"info" : <?php echo esc_html( $table_features['info'] ); ?>,
								"colReorder" : <?php echo esc_html( $table_features['colReorder'] ); ?>,
								"stateSave":  <?php echo esc_html( $table_features['stateSave'] ); ?>,
								"order": [],
								"columnDefs": [ { "targets": 'no-sort', "orderable": false } ],
								"responsive": responsive,
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
						<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
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
							<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
							<div class="content active">
								<?php
								$sel_params = array(
									'show_client' => true,
								);
								MainWP_UI_Select_Sites::select_sites_box( $sel_params );
								?>
								</div>
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
						<div class="ui fitted divider"></div>
						<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
							<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Update Admin Password', 'mainwp' ); ?></div>
							<div class="content active">
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
								<div class="ui fluid input" data-tooltip="<?php esc_attr_e( 'Enter a new password or use the Generate Password button.', 'mainwp' ); ?>" data-inverted="" data-position="top right">
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
						</div>
						<div class="ui fitted divider"></div>
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
							if ( $is_demo ) {
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="submit" disabled="disabled" class="ui big green fluid button" value="' . esc_attr__( 'Update Password', 'mainwp' ) . '"/>' );
							} else {
								?>
								<input type="submit" name="bulk_updateadminpassword" id="bulk_updateadminpassword" class="ui big green fluid button" value="<?php esc_attr_e( 'Update Password', 'mainwp' ); ?> "/>
								<?php
							}
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
