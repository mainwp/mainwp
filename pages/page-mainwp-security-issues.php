<?php
/**
 * MainWP Security Issues page
 *
 * This page is used to manage child site security issues.
 *
 * @package MainWP/Securtiy_Issues
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Security_Issues
 *
 * Detect, display & fix known Security Issues.
 */
class MainWP_Security_Issues {

	/**
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class Name
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * @param null $website Child Site ID.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function render( $website = null ) {

		if ( empty( $website ) ) {
			$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
			if ( ! $id ) {
				return;
			}
			$website = MainWP_DB::instance()->get_website_by_id( $id );
		}

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			return;
		}
		$unset_scripts = apply_filters( 'mainwp_unset_security_scripts_stylesheets', true );
		?>
		<table class="ui table" id="mainwp-security-issues-table" un-set="<?php echo $unset_scripts ? 'versions,registered_versions' : ''; ?>">
		<thead>
				<tr>
					<th class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Detected security issues', 'mainwp' ); ?></th>
					<th class="collapsing"><?php esc_html_e( '', 'mainwp' ); ?></th>
				</tr>
		</thead>
		<tbody>
				<tr>
					<td>
						<span id="listing_loading"><i class="notched circle big loading icon"></i></span>
						<span id="listing_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="listing_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="listing-status-nok"><?php esc_html_e( '/wp-content/, /wp-content/plugins/, /wp-content/themes/ and /wp-content/uploads/ directories listing has not been prevented', 'mainwp' ); ?></strong>
						<strong id="listing-status-ok" style="display: none;"><?php esc_html_e( '/wp-content/, /wp-content/plugins/, /wp-content/themes/ and /wp-content/uploads/ directories listing has been prevented', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, an empty index.php will be inserted in these directories to prevent listing.', 'mainwp' ); ?></em>
					</td>
					<td>
						<span id="listing_fix" style="display: none"><a href="#" class="ui mini green fluid button"><?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
					</td>
				</tr>
				<tr>
					<td>
						<span id="wp_version_loading"><i class="notched circle big loading icon"></i></span>
						<span id="wp_version_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="wp_version_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="wp_version-status-nok"><?php esc_html_e( 'WordPress version has not been hidden', 'mainwp' ); ?></strong>
						<strong id="wp_version-status-ok" style="display: none;"><?php esc_html_e( 'WordPress version has been hidden', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, WordPress generator meta tag will be removed from the head sections of the Child Site', 'mainwp' ); ?></em>
					</td>
					<td>
						<span id="wp_version_fix" style="display: none"><a href="#" class="ui mini green fluid button"><?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
						<span id="wp_version_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Unfix', 'mainwp' ); ?></a></span>
					</td>
				</tr>
				<tr>
					<td>
						<span id="rsd_loading"><i class="notched circle big loading icon"></i></span>
						<span id="rsd_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="rsd_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="rsd-status-nok"><?php esc_html_e( 'Really Simple Discovery meta tag has not been removed from front-end', 'mainwp' ); ?></strong>
						<strong id="rsd-status-ok" style="display: none;"><?php esc_html_e( 'Really Simple Discovery meta tag has been removed from front-end', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, RSD meta tag will be removed from head sections of the Child Site', 'mainwp' ); ?></em>
					</td>
					<td>
						<span id="rsd_fix" style="display: none"><a href="#" class="ui mini green fluid button"><?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
						<span id="rsd_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Unfix', 'mainwp' ); ?></a></span>
					</td>
				</tr>
				<tr>
					<td>
						<span id="wlw_loading"><i class="notched circle big loading icon"></i></span>
						<span id="wlw_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="wlw_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="wlw-status-nok"><?php esc_html_e( 'Windows Live Writer meta tag has not been removed from front-end', 'mainwp' ); ?></strong>
						<strong id="wlw-status-ok" style="display: none;"><?php esc_html_e( 'Windows Live Writer meta tag has been removed from front-end', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, WLW meta tag will be removed from head sections of the Child Site', 'mainwp' ); ?></em>
					</td>
					<td>
						<span id="wlw_fix" style="display: none"><a href="#" class="ui mini fluid green button"> <?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
						<span id="wlw_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Unfix', 'mainwp' ); ?></a></span>
					</td>
				</tr>
				<tr>
					<td>
						<span id="db_reporting_loading"><i class="notched circle big loading icon"></i></span>
						<span id="db_reporting_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="db_reporting_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="db_reporting-status-nok"><?php esc_html_e( 'Database error reporting has not been disabled', 'mainwp' ); ?></strong>
						<strong id="db_reporting-status-ok" style="display: none;"><?php esc_html_e( 'Database error reporting has been disabled', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, database error reporting will be disabled', 'mainwp' ); ?></em>
					</td>
					<td>
						<span id="db_reporting_fix" style="display: none"><a href="#" class="ui mini fluid green button"><?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
					</td>
				</tr>
				<tr>
					<td>
						<span id="php_reporting_loading"><i class="notched circle big loading icon"></i></span>
						<span id="php_reporting_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="php_reporting_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="php_reporting-status-nok"><?php esc_html_e( 'PHP error reporting has not been disabled', 'mainwp' ); ?></strong>
						<strong id="php_reporting-status-ok" style="display: none;"><?php esc_html_e( 'PHP error reporting has been disabled', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, PHP error reporting will be disabled', 'mainwp' ); ?></em>
					</td>
					<td>
						<span id="php_reporting_fix" style="display: none"><a href="#" class="ui mini fluid green button"> <?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
						<span id="php_reporting_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Unfix', 'mainwp' ); ?></a></span>
					</td>
				</tr>
				<?php if ( ! $unset_scripts ) { ?>
				<tr>
					<td>
						<span id="versions_loading"><i class="notched circle big loading icon"></i></span>
						<span id="versions_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="versions_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>					
					<td>
						<strong id="versions-status-nok"><?php esc_html_e( 'Scripts and Stylesheets version information has not been removed from URLs', 'mainwp' ); ?></strong>
						<strong id="versions-status-ok" style="display: none;"><?php esc_html_e( 'Scripts and Stylesheets version information has been removed from URLs', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, versions will be removed', 'mainwp' ); ?></em>
					</td>					
					<td>
						<span id="versions_fix" style="display: none"><a href="#" class="ui mini green fluid button"><?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
						<span id="versions_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Unfix', 'mainwp' ); ?></a></span>
					</td>
				</tr>
				<tr>
					<td>
						<span id="registered_versions_loading"><i class="notched circle big loading icon"></i></span>
						<span id="registered_versions_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="registered_versions_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>					
					<td>
						<strong id="registered_versions-status-nok"><?php esc_html_e( 'Scripts and Stylesheets registered version information has not been removed from URLs', 'mainwp' ); ?></strong>
						<strong id="registered_versions-status-ok" style="display: none;"><?php esc_html_e( 'Scripts and Stylesheets registered version information has been removed from URLs', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, registered versions will be removed', 'mainwp' ); ?></em>
					</td>					
					<td>
						<span id="registered_versions_fix" style="display: none"><a href="#" class="ui mini fluid green button"><?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
						<span id="registered_versions_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Unfix', 'mainwp' ); ?></a></span>
					</td>
				</tr>
				<?php } ?>
				<?php
					$is_wpengine = false;
				if ( property_exists( $website, 'wpe' ) && 1 === (int) $website->wpe ) {
					$is_wpengine = true;
				}
				?>
				<tr>
					<td>
						<span id="readme_loading"><i class="notched circle big loading icon"></i></span>
						<span id="readme_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="readme_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="readme-status-nok"><?php esc_html_e( 'readme.html file has not been removed from WordPress root', 'mainwp' ); ?></strong>
						<strong id="readme-status-ok" style="display: none;"><?php esc_html_e( 'readme.html file has been removed from WordPress root', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'After fixing this issue, the readme.html file will be removed from the Child Site root directory', 'mainwp' ); ?></em>
						<?php if ( $is_wpengine ) { ?>
						<strong id="readme-wpe-nok"><?php esc_html_e( 'Removing the file on WPEngine hosting can cause issues. If you need to remove the file, please consult the WPEngine support first.', 'mainwp' ); ?></strong>
						<?php } ?>
					</td>
					<td>
						<?php if ( ! $is_wpengine ) { ?>
						<span id="readme_fix" style="display: none"><a href="#" class="ui mini green fluid button"><?php esc_html_e( 'Fix', 'mainwp' ); ?></a></span>
						<span id="readme_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Unfix', 'mainwp' ); ?></a> - <?php esc_html_e( 'You need to re-upload the readme.html file manually to unfix this.', 'mainwp' ); ?></span>
						<?php } else { ?>
						<span><a href="javascript:void(0)" class="ui mini fluid button"><?php esc_html_e( 'Fix', 'mainwp' ); ?></a>
						<?php } ?>
					</td>
				</tr>
				<tr>
					<td>
						<span id="admin_loading"><i class="notched circle big loading icon"></i></span>
						<span id="admin_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="admin_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="admin-status-nok"><?php esc_html_e( 'Administrator username should not be "admin"', 'mainwp' ); ?></strong>
						<strong id="admin-status-ok" style="display: none;"><?php esc_html_e( 'Administrator username is not "admin"', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'You have to change this yourself. If this user was used as your MainWP Secure Link Admin, you will need to change your Administrator Username in the MainWP Dashboard for the site.', 'mainwp' ); ?></em>
					</td>					
					<td></td>
				</tr>

				<tr>
					<td>
						<span id="wp_uptodate_loading"><i class="notched circle big loading icon"></i></span>
						<span id="wp_uptodate_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="wp_uptodate_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="wp_uptodate-status-nok"><?php esc_html_e( 'WordPress is not up to date.', 'mainwp' ); ?></strong>
						<strong id="wp_uptodate-status-ok" style="display: none;"><?php esc_html_e( 'WordPress is up to date.', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'Checks if WP is up to date. You have to change this yourself.', 'mainwp' ); ?></em>
					</td>					
					<td></td>
				</tr>
				<tr>
					<td>
						<span id="phpversion_matched_loading"><i class="notched circle big loading icon"></i></span>
						<span id="phpversion_matched_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="phpversion_matched_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="phpversion_matched-status-nok"><?php esc_html_e( 'PHP version does not match the WordPress requirement', 'mainwp' ); ?></strong>
						<strong id="phpversion_matched-status-ok" style="display: none;"><?php esc_html_e( 'PHP version matches the WordPress requirement', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'Checks if PHP version matches the WP requirement. You have to change this yourself.', 'mainwp' ); ?></em>
					</td>					
					<td></td>
				</tr>

				<tr>
					<td>
						<span id="sslprotocol_loading"><i class="notched circle big loading icon"></i></span>
						<span id="sslprotocol_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="sslprotocol_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="sslprotocol-status-nok"><?php esc_html_e( 'SSL protocol is not in place', 'mainwp' ); ?></strong>
						<strong id="sslprotocol-status-ok" style="display: none;"><?php esc_html_e( 'SSL protocol is in place', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'Checks if SSL protocol is in place. You have to change this yourself.', 'mainwp' ); ?></em>
					</td>					
					<td></td>
				</tr>

				<tr>
					<td>
						<span id="debug_disabled_loading"><i class="notched circle big loading icon"></i></span>
						<span id="debug_disabled_ok" style="display: none;"><i class="big check circle green icon"></i></span>
						<span id="debug_disabled_nok" style="display: none;"><i class="big times circle red icon"></i></span>
					</td>
					<td>
						<strong id="debug_disabled-status-nok"><?php esc_html_e( 'WP Config debugging is enabled', 'mainwp' ); ?></strong>
						<strong id="debug_disabled-status-ok" style="display: none;"><?php esc_html_e( 'WP Config debugging is disabled', 'mainwp' ); ?></strong>
						<br />
						<em><?php esc_html_e( 'Checks if WP Config debugging is disabled. You have to change this yourself.', 'mainwp' ); ?></em>
					</td>					
					<td></td>
				</tr>

		</tbody>
		<?php
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
			<tfoot class="full-width">
				<tr>
				<th colspan="3">
					<?php if ( ! $is_demo ) : ?>
							<input type="button" id="securityIssues_fixAll" class="ui green button right floated" value="<?php esc_html_e( 'Fix All', 'mainwp' ); ?>"/>
							<input type="button" id="securityIssues_refresh" class="ui green basic button" value="<?php esc_html_e( 'Refresh', 'mainwp' ); ?>"/>
							<input type="hidden" id="securityIssueSite" value="<?php echo intval( $website->id ); ?>"/>
					<?php endif; ?>
					</th>
				</tr>
			</tfoot>
		</table>		
		<?php
	}


	/**
	 * Method Fetch Security Issues
	 *
	 * Fetch stored known Child Site Security Issues from DB that were found during Sync.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function fetch_security_issues() {
		$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		if ( ! $id ) {
			return '';
		}
		$website = MainWP_DB::instance()->get_website_by_id( $id );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			return '';
		}

		$information = MainWP_Connect::fetch_url_authed( $website, 'security' );

		/**
		 * Filters security issues
		 *
		 * Filters the default security checks and enables user to disable certain checks.
		 *
		 * @param bool   false        Whether security issues should be filtered.
		 * @param object $information Object containing data from che chid site related to security issues.
		 *                            Available options: 'listing', 'wp_version', 'rsd', 'wlw', 'db_reporting', 'php_reporting', 'versions', 'registered_versions', 'readme'.
		 * @param object $website     Object containing child site data.
		 *
		 * @since 4.1
		 */
		$filterStats = apply_filters( 'mainwp_security_issues_stats', false, $information, $website );
		if ( false !== $filterStats && is_array( $filterStats ) ) {
			$information = array_merge( $information, $filterStats );
		}
		return $information;
	}

	/**
	 * Method Fix Security Issues
	 *
	 * Fix the selected security issue.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function fix_security_issue() {
		$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		if ( ! $id ) {
			return '';
		}
		$website = MainWP_DB::instance()->get_website_by_id( $id );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			return '';
		}

		if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
			return '';
		}

		$skip_features = array(
			'listing',
			'wp_version',
			'rsd',
			'wlw',
			'db_reporting',
			'php_reporting',
			'versions',
			'registered_versions',
			'readme',
			'wp_uptodate',
			'phpversion_matched',
			'sslprotocol',
			'debug_disabled',
		);

		/**
		 * Filters security issues from fixing
		 *
		 * Filters the default security checks and enables user to disable certain issues from being fixed by using the Fix All button.
		 *
		 * @param bool   false          Whether security issues should be filtered.
		 * @param object $skip_features Object containing data from che chid site related to security issues.
		 *                              Available options: 'listing', 'wp_version', 'rsd', 'wlw', 'db_reporting', 'php_reporting', 'versions', 'registered_versions', 'readme'.
		 * @param object $website       Object containing child site data.
		 *
		 * @since 4.1
		 */
		$skip_features = apply_filters( 'mainwp_security_post_data', false, $skip_features, $website );

		$feature   = isset( $_REQUEST['feature'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['feature'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		$post_data = array( 'feature' => $feature );
		if ( ! empty( $skip_features ) && is_array( $skip_features ) ) {
			$post_data['skip_features'] = $skip_features;
		}

		$unset_scripts = apply_filters( 'mainwp_unset_security_scripts_stylesheets', true );
		if ( $unset_scripts ) {
			if ( ! isset( $post_data['skip_features'] ) ) {
				$post_data['skip_features'] = array();
			}

			if ( ! in_array( 'versions', $post_data['skip_features'] ) ) {
				$post_data['skip_features'][] = 'versions';
			}

			if ( ! in_array( 'registered_versions', $post_data['skip_features'] ) ) {
				$post_data['skip_features'][] = 'registered_versions';
			}
		}

		$information = MainWP_Connect::fetch_url_authed( $website, 'securityFix', $post_data );
		if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
			MainWP_Sync::sync_information_array( $website, $information['sync'] );
			unset( $information['sync'] );
		}

		return $information;
	}

	/**
	 * Method un-Fix Security Issues
	 *
	 * Un-Fix the selected security issue.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function unfix_security_issue() {
		$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		if ( ! $id ) {
			return '';
		}
		$website = MainWP_DB::instance()->get_website_by_id( $id );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			return '';
		}

		if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
			return '';
		}

		$feature = isset( $_REQUEST['feature'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['feature'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended

		$information = MainWP_Connect::fetch_url_authed( $website, 'securityUnFix', array( 'feature' => $feature ) );
		if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
			MainWP_Sync::sync_information_array( $website, $information['sync'] );
			unset( $information['sync'] );
		}

		return $information;
	}
}
