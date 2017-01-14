<?php

class MainWP_Security_Issues {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function render( $website = null ) {		
		
		if ( empty( $website ) ) {
			if ( ! isset( $_REQUEST['id'] ) || ! MainWP_Utility::ctype_digit( $_REQUEST['id'] ) ) {
				return;
			}
			$website = MainWP_DB::Instance()->getWebsiteById( $_REQUEST['id'] );
		} 

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			return;
		}
			?>
		<div class="mainwp-postbox-actions-top">
			<?php _e( 'We highly suggest you make a full backup before you run the Security Update.', 'mainwp' ); ?>
		</div>
		<div>
				<table id="mainwp-security-issues-table">
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="listing_loading"><i class="fa fa-spinner fa-2x fa-pulse"></i></span><span id="listing_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="listing_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td class="mainwp-padding-10 ">
						<strong id="listing-status-nok"><?php _e( '/wp-content/, /wp-content/plugins/, /wp-content/themes/ and /wp-content/uploads/ directories listing has not been prevented', 'mainwp' ); ?></strong>
						<strong id="listing-status-ok" style="display: none;"><?php _e( '/wp-content/, /wp-content/plugins/, /wp-content/themes/ and /wp-content/uploads/ directories listing has been prevented', 'mainwp' ); ?></strong>
						<br />
						<em><?php _e( 'After fixing this issue, an empty index.php will inserted in these directories to prevent listing', 'mainwp' ); ?></em>
					</td>
					<td class="mainwp-padding-10 mainwp-cols-10">
							<span id="listing_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="wp_version_loading"><i class="fa fa-spinner fa-2x fa-pulse"></i></span><span id="wp_version_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="wp_version_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td class="mainwp-padding-10">
						<strong id="wp_version-status-nok"><?php _e( 'WordPress version has not been hidden', 'mainwp' ); ?></strong>
						<strong id="wp_version-status-ok" style="display: none;"><?php _e( 'WordPress version has been hidden', 'mainwp' ); ?></strong>
						<br />
						<em><?php _e( 'After fixing this issue, WordPress generator meta tag will be removed from the head sections of the Child Site', 'mainwp' ); ?></em>
					</td>
					<td class="mainwp-padding-10 mainwp-cols-10">
							<span id="wp_version_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="wp_version_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="rsd_loading"><i class="fa fa-spinner fa-2x fa-pulse"></i></span><span id="rsd_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="rsd_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td class="mainwp-padding-10">
						<strong id="rsd-status-nok"><?php _e( 'Really Simple Discovery meta tag has not been removed from front-end', 'mainwp' ); ?></strong>
						<strong id="rsd-status-ok" style="display: none;"><?php _e( 'Really Simple Discovery meta tag has been removed from front-end', 'mainwp' ); ?></strong>
						<br />
						<em><?php _e( 'After fixing this issue, RSD meta tag will be removed from head sections of the Child Site', 'mainwp' ); ?></em>
					</td>
					<td class="mainwp-padding-10 mainwp-cols-10">
							<span id="rsd_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="rsd_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="wlw_loading"><i class="fa fa-spinner fa-2x fa-pulse"></i></span><span id="wlw_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="wlw_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td class="mainwp-padding-10">
						<strong id="wlw-status-nok"><?php _e( 'Windows Live Writer meta tag has not been removed from front-end', 'mainwp' ); ?></strong>
						<strong id="wlw-status-ok" style="display: none;"><?php _e( 'Windows Live Writer meta tag has been removed from front-end', 'mainwp' ); ?></strong>
						<br />
						<em><?php _e( 'After fixing this issue, WLW meta tag will be removed from head sections of the Child Site', 'mainwp' ); ?></em>
					</td>
					<td class="mainwp-padding-10 mainwp-cols-10">
							<span id="wlw_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="wlw_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="db_reporting_loading"><i class="fa fa-2x fa-spinner fa-pulse"></i></span><span id="db_reporting_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="db_reporting_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td class="mainwp-padding-10">
						<strong id="db_reporting-status-nok"><?php _e( 'Database error reporting has not been disabled', 'mainwp' ); ?></strong>
						<strong id="db_reporting-status-ok" style="display: none;"><?php _e( 'Database error reporting has been disabled', 'mainwp' ); ?></strong>
						<br />
						<em><?php _e( 'After fixing this issue, database error reporting will be disabled', 'mainwp' ); ?></em>
					</td>
					<td class="mainwp-padding-10 mainwp-cols-10">
							<span id="db_reporting_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="php_reporting_loading"><i class="fa fa-2x fa-spinner fa-pulse"></i></span><span id="php_reporting_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="php_reporting_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td class="mainwp-padding-10">
						<strong id="php_reporting-status-nok"><?php _e( 'PHP error reporting has not been disabled', 'mainwp' ); ?></strong>
						<strong id="php_reporting-status-ok" style="display: none;"><?php _e( 'PHP error reporting has been disabled', 'mainwp' ); ?></strong>
						<br />
						<em><?php _e( 'After fixing this issue, PHP error reporting will be disabled', 'mainwp' ); ?></em>
					</td>
					<td class="mainwp-padding-10 mainwp-cols-10">
							<span id="php_reporting_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="php_reporting_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="versions_loading"><i class="fa fa-spinner fa-2x fa-pulse"></i></span><span id="versions_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="versions_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td class="mainwp-padding-10">
						<strong id="versions-status-nok"><?php _e( 'Scripts and Stylesheets version information has not been removed from URLs', 'mainwp' ); ?></strong>
						<strong id="versions-status-ok" style="display: none;"><?php _e( 'Scripts and Stylesheets version information has been removed from URLs', 'mainwp' ); ?></strong>
						<br />
						<em><?php _e( 'After fixing this issue, versions will be removed', 'mainwp' ); ?></em>
					</td>
					<td class="mainwp-padding-10 mainwp-cols-10">
							<span id="versions_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="versions_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="readme_loading"><i class="fa fa-spinner fa-2x fa-pulse"></i></span><span id="readme_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="readme_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td class="mainwp-padding-10">
						<strong id="readme-status-nok"><?php _e( 'readme.html file has not been removed from WordPress root', 'mainwp' ); ?></strong>
						<strong id="readme-status-ok" style="display: none;"><?php _e( 'readme.html file has been removed from WordPress root', 'mainwp' ); ?></strong>
						<br />
						<em><?php _e( 'After fixing this issue, the readme.html file will be removed from the Child Site root directory', 'mainwp' ); ?></em>
					</td>
					<td class="mainwp-padding-10 mainwp-cols-10">
							<span id="readme_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="readme_unfix" style="display: none"><font color="gray"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</font> - <?php _e( 'You need to re-upload the readme.html file manually to unfix this.', 'mainwp' ); ?></span>
						</td>
					</tr>
					<tr>
					<td class="mainwp-padding-10 mainwp-cols-10 mainwp-center">
						<span id="admin_loading"><i class="fa fa-spinner fa-2x fa-pulse"></i></span><span id="admin_ok" class="mainwp-green" style="display: none;"><i class="fa fa-check fa-2x"></i></span><span id="admin_nok" class="mainwp-red" style="display: none;"><i class="fa fa-times fa-2x"></i></span>
						</td>
					<td colspan="2" class="mainwp-padding-10">
						<strong id="admin-status-nok"><?php _e( 'Administrator username should not be "admin"', 'mainwp' ); ?></strong>
						<strong id="admin-status-ok" style="display: none;"><?php _e( 'Administrator username is not "admin"', 'mainwp' ); ?></strong>
						<span id="admin_fix" style="display: none"></span>
						<br />
						<em>
							<?php _e( 'If this user was used as your MainWP Secure Link Admin, you will need to change your Administrator Username in the MainWP Dashboard for the site.', 'mainwp' ); ?>
							<br />
							<?php _e( 'You have to change this yourself', 'mainwp' ); ?>
						</em>
						</td>
					</tr>
				</table>
			<div class="mainwp-postbox-actions-bottom">
				<input type="button" id="securityIssues_fixAll" class="button-primary button button-hero" value="<?php _e( 'Fix All', 'mainwp' ); ?>"/>
				<input type="button" id="securityIssues_refresh" class="button button-hero" value="<?php _e( 'Refresh', 'mainwp' ); ?>"/>
				<input type="hidden" id="securityIssueSite" value="<?php echo $website->id; ?>"/>
			</div>
		</div>
		<?php
	}

	public static function fetchSecurityIssues() {
		if ( ! isset( $_REQUEST['id'] ) || ! MainWP_Utility::ctype_digit( $_REQUEST['id'] ) ) {
			return '';
		}
		$website = MainWP_DB::Instance()->getWebsiteById( $_REQUEST['id'] );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			return '';
		}

		$information = MainWP_Utility::fetchUrlAuthed( $website, 'security' );

		return $information;
	}

	public static function fixSecurityIssue() {
		if ( ! isset( $_REQUEST['id'] ) || ! MainWP_Utility::ctype_digit( $_REQUEST['id'] ) ) {
			return '';
		}
		$website = MainWP_DB::Instance()->getWebsiteById( $_REQUEST['id'] );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			return '';
		}

		$information = MainWP_Utility::fetchUrlAuthed( $website, 'securityFix', array( 'feature' => $_REQUEST['feature'] ) );
		if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
			MainWP_Sync::syncInformationArray( $website, $information['sync'] );
			unset( $information['sync'] );
		}

		return $information;
	}

	public static function unfixSecurityIssue() {
		if ( ! isset( $_REQUEST['id'] ) || ! MainWP_Utility::ctype_digit( $_REQUEST['id'] ) ) {
			return '';
		}
		$website = MainWP_DB::Instance()->getWebsiteById( $_REQUEST['id'] );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			return '';
		}

		$information = MainWP_Utility::fetchUrlAuthed( $website, 'securityUnFix', array( 'feature' => $_REQUEST['feature'] ) );
		if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
			MainWP_Sync::syncInformationArray( $website, $information['sync'] );
			unset( $information['sync'] );
		}

		return $information;
	}

	public static function getMetaboxName() {
		return '<i class="fa fa-shield"></i> ' . __( 'Security issues', 'mainwp' );
	}

	public static function renderMetabox() {
		?>
		<div id="securityissues_list" xmlns="http://www.w3.org/1999/html"><?php self::renderSites(); ?></div>
		<?php
	}

	public static function renderSites() {
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
		} else {
			$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser();
		}

		$websites = MainWP_DB::Instance()->query( $sql );

		if ( ! $websites ) {
			return;
		}

		$total_securityIssues = 0;

		@MainWP_DB::data_seek( $websites, 0 );
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			if ( MainWP_Utility::ctype_digit( $website->securityIssues ) ) {
				$total_securityIssues += $website->securityIssues;
			}
		}

		if ( $total_securityIssues == 0 ) {
			$mainwp_si_color_code = "mainwp-green";
		} else {
			$mainwp_si_color_code = "mainwp-red";
		}

		//We found some with security issues!
		if ( $total_securityIssues > 0 ) {
			?>
			<div class="mainwp-clear">
				<div class="mainwp-row-top">
					<div class="mainwp-cols-2 mainwp-left">
						<span class="fa-stack fa-lg">
						<i class="fa fa-circle fa-stack-2x <?php echo $mainwp_si_color_code; ?>"></i>
						<strong class="fa-stack-1x mainwp-white"><?php echo $total_securityIssues; ?></strong> 
						</span>
						<a href="#" id="mainwp_securityissues_show" onClick="return rightnow_show('securityissues');">
							<?php echo _n( 'Security issue', 'Security issues', $total_securityIssues, 'mainwp' ); ?>
						</a>
				</div>
					<div class="mainwp-cols-2 mainwp-right mainwp-t-align-right mainwp-padding-top-5">						
						<input type="button" class="securityIssues_dashboard_allFixAll button button-primary" value="<?php _e( 'Fix All', 'mainwp' ); ?>"/>
					</div>
					<div class="mainwp-clear"></div>
				</div>
				<div id="wp_securityissues" class="mainwp-sub-section" style="display: none">
					<?php
					@MainWP_DB::data_seek( $websites, 0 );
					while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
						if ( ! MainWP_Utility::ctype_digit( $website->securityIssues ) || $website->securityIssues == 0 ) {
							continue;
						}
						?>
						<div class="mainwp-sub-row" siteid="<?php echo $website->id; ?>">
							<div class="mainwp-left mainwp-cols-3 mainwp-padding-top-5">
								<a href="admin.php?page=managesites&scanid=<?php echo $website->id; ?>"><?php echo stripslashes( $website->name ); ?></a>
							</div>
							<div class="mainwp-left mainwp-cols-3">
								<span class="fa-stack fa-lg">
									<i class="fa fa-circle fa-stack-2x <?php echo( $website->securityIssues > 0 ? 'mainwp-red' : 'mainwp-green' ); ?>"></i>
									<strong class="fa-stack-1x mainwp-white"><?php echo $website->securityIssues; ?></strong> 
								</span>
									<?php echo _n( 'Issue', 'Issues', $website->securityIssues, 'mainwp' ); ?>
							</div>
							<div class="mainwp-right mainwp-cols-3 mainwp-t-align-right mainwp-padding-top-5">
								<?php if ( $website->securityIssues == 0 ) { ?>
									<input type="button" class="securityIssues_dashboard_unfixAll button" value="<?php esc_attr_e( 'Unfix all', 'mainwp' ); ?>"/>
								<?php } else { ?>
									<input type="button" class="securityIssues_dashboard_fixAll button button-primary" value="<?php esc_attr_e( 'Fix all', 'mainwp' ); ?>"/>
								<?php } ?>
								<i class="fa fa-spinner fa-pulse img-loader" style="display: none;"></i>
						</div>
							<div class="mainwp-clear"></div>
						</div>
					<?php } ?>
				</div>
			</div>
			<?php
		} else {
			esc_html_e( 'No security issues detected!', 'mainwp' );
		}
	}
}
