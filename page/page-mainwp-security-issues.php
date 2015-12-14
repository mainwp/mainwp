<?php

class MainWP_Security_Issues {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function initMenu() {
		if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
			add_submenu_page( 'mainwp_tab', 'SecurityIssues', '<div class="mainwp-hidden">' . __( 'SecurityIssues', 'mainwp' ) . '</div>', 'read', 'SecurityIssues', array(
				MainWP_Security_Issues::getClassName(),
				'render',
			) );
		}
	}

	public static function render( $website = null ) {
		$with_header = true;
		if ( empty( $website ) ) {
			if ( ! isset( $_REQUEST['id'] ) || ! MainWP_Utility::ctype_digit( $_REQUEST['id'] ) ) {
				return;
			}
			$website = MainWP_DB::Instance()->getWebsiteById( $_REQUEST['id'] );
		} else {
			$with_header = false;
		}

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			return;
		}

		if ( $with_header ) {
			?>
			<div class="wrap">
			<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>
			<img src="<?php echo plugins_url( 'images/icons/mainwp-security.png', dirname( __FILE__ ) ); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Security Issues" height="32"/>
			<h2><?php _e( 'Security Issues', 'mainwp' ); ?></h2>
			<div style="clear: both;"></div><br/>
			<div id="mainwp_background-box">
		<?php } ?>
		<div class="mainwp_info-box"><?php _e( 'We highly suggest you make a full backup before you run the Security Update.', 'mainwp' ); ?></div>
		<div class="postbox">
			<h3 class="mainwp_box_title">
				<span><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a> (<?php echo $website->url; ?>)</span>
			</h3>

			<div class="inside">
				<table id="mainwp-security-issues-table">
					<tr>
						<td>
							<span id="listing_loading"><i class="fa fa-spinner fa-lg fa-pulse"></i></span><span id="listing_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="listing_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'Prevent listing wp-content, wp-content/plugins, wp-content/themes, wp-content/uploads', 'mainwp' ); ?></td>
						<td>
							<span id="listing_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
						<td>
							<span id="wp_version_loading"><i class="fa fa-spinner fa-lg fa-pulse"></i></span><span id="wp_version_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="wp_version_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'Removed wp-version', 'mainwp' ); ?></td>
						<td>
							<span id="wp_version_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="wp_version_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
						<td>
							<span id="rsd_loading"><i class="fa fa-spinner fa-lg fa-pulse"></i></span><span id="rsd_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="rsd_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'Removed Really Simple Discovery meta tag', 'mainwp' ); ?></td>
						<td>
							<span id="rsd_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="rsd_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
						<td>
							<span id="wlw_loading"><i class="fa fa-spinner fa-lg fa-pulse"></i></span><span id="wlw_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="wlw_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'Removed Windows Live Writer meta tag', 'mainwp' ); ?></td>
						<td>
							<span id="wlw_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="wlw_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
						<td>
							<span id="db_reporting_loading"><i class="fa fa-lg fa-spinner fa-pulse"></i></span><span id="db_reporting_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="db_reporting_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'Database error reporting turned off', 'mainwp' ); ?></td>
						<td>
							<span id="db_reporting_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
						<td>
							<span id="php_reporting_loading"><i class="fa fa-lg fa-spinner fa-pulse"></i></span><span id="php_reporting_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="php_reporting_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'PHP error reporting turned off', 'mainwp' ); ?></td>
						<td>
							<span id="php_reporting_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="php_reporting_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
						<td>
							<span id="versions_loading"><i class="fa fa-spinner fa-lg fa-pulse"></i></span><span id="versions_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="versions_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'Removed version information for scripts/stylesheets', 'mainwp' ); ?></td>
						<td>
							<span id="versions_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="versions_unfix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</a></span></td>
					</tr>
					<tr>
						<td>
							<span id="readme_loading"><i class="fa fa-spinner fa-lg fa-pulse"></i></span><span id="readme_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="readme_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'readme.html removed', 'mainwp' ); ?></td>
						<td>
							<span id="readme_fix" style="display: none"><a href="#" style="text-decoration: none;"><i class="fa fa-wrench"></i> <?php _e( 'Fix', 'mainwp' ); ?>
								</a></span><span id="readme_unfix" style="display: none"><font color="gray"><i class="fa fa-wrench"></i> <?php _e( 'Unfix', 'mainwp' ); ?>
								</font> - <?php _e( 'You need to re-upload the readme.html file manually to unfix this.', 'mainwp' ); ?></span>
						</td>
					</tr>
					<tr>
						<td>
							<span id="admin_loading"><i class="fa fa-spinner fa-lg fa-pulse"></i></span><span id="admin_ok" class="mainwp-success-green" style="display: none;"><i class="fa fa-check fa-lg"></i></span><span id="admin_nok" class="mainwp-error-red" style="display: none;"><i class="fa fa-times fa-lg"></i></span>
						</td>
						<td><?php _e( 'Administrator username should not be Admin', 'mainwp' ); ?></td>
						<td><span id="admin_fix" style="display: none"></span>
							<ol>
								<li><?php _e( 'If this user was used as your MainWP Secure Link Admin, you will need to change your Administrator Username in the MainWP Dashboard for the site.', 'mainwp' ); ?> -
									<a href="http://docs.mainwp.com/deleting-secure-link-admin/" style="text-decoration: none;"><?php _e( 'Documentation', 'mainwp' ); ?></a>
								</li>
								<li><?php _e( 'You have to change this yourself', 'mainwp' ); ?> -
									<a href="http://blog.mainwp.com/change-default-wordpress-admin-username/" target="_blank" style="text-decoration: none;"><?php _e( 'Tutorial', 'mainwp' ); ?></a>
								</li>
							</ol>
						</td>
					</tr>
				</table>
				<br/><input type="button" id="securityIssues_fixAll" class="button-primary button button-hero" value="<?php _e( 'Fix All', 'mainwp' ); ?>"/>
				<input type="button" id="securityIssues_refresh" class="button button-hero" value="<?php _e( 'Refresh', 'mainwp' ); ?>"/>
			</div>
		</div>
		<?php if ( $with_header ) { ?>
			</div>
			</div>
		<?php } ?>
		<input type="hidden" id="securityIssueSite" value="<?php echo $website->id; ?>"/>
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
		return __( '<i class="fa fa-shield"></i> Security Issues', 'mainwp' );
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

		//We found some with security issues!
		if ( $total_securityIssues > 0 ) {
			?>
			<div class="clear">
				<div class="mainwp-row-top darkred">
					<span class="mainwp-left-col"><span class="mainwp-rightnow-number"><?php echo $total_securityIssues; ?></span> <?php _e( 'Security issue', 'mainwp' ); ?><?php echo( ( $total_securityIssues > 1 ) ? 's' : '' ); ?></span>
					<span class="mainwp-mid-col">&nbsp;</span>
					<span class="mainwp-right-col"><a href="#" id="mainwp_securityissues_show" onClick="return rightnow_show('securityissues');"><?php _e( 'Show All', 'mainwp' ); ?></a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="button" class="securityIssues_dashboard_allFixAll button-primary" value="<?php _e( 'Fix All', 'mainwp' ); ?>"/></span>
				</div>
				<div id="wp_securityissues" style="display: none">
					<?php
					@MainWP_DB::data_seek( $websites, 0 );
					while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
						if ( ! MainWP_Utility::ctype_digit( $website->securityIssues ) || $website->securityIssues == 0 ) {
							continue;
						}
						?>
						<div class="mainwp-row" siteid="<?php echo $website->id; ?>">
							<span class="mainwp-left-col"><a href="admin.php?page=managesites&scanid=<?php echo $website->id; ?>"><?php echo stripslashes( $website->name ); ?></a></span>
							<span class="mainwp-mid-col"><span class="<?php echo( $website->securityIssues > 0 ? 'darkred' : 'mainwp_ga_plus' ); ?>"><span class="mainwp-rightnow-number"><?php echo $website->securityIssues; ?></span> Issue<?php echo( ( $website->securityIssues > 1 ) ? 's' : '' ); ?></span></span>
							<span class="mainwp-right-col">
								<?php if ( $website->securityIssues == 0 ) { ?>
									<input type="button" class="securityIssues_dashboard_unfixAll button" value="<?php _e( 'Unfix All', 'mainwp' ); ?>"/>
								<?php } else { ?>
									<input type="button" class="securityIssues_dashboard_fixAll button-primary" value="<?php _e( 'Fix All', 'mainwp' ); ?>"/>
								<?php } ?>
								<i class="fa fa-spinner fa-pulse img-loader" style="display: none;"></i>
							</span>
						</div>
					<?php } ?>
				</div>
			</div>
			<?php
		} else {
			esc_html_e( 'No security issues detected.', 'mainwp' );
		}
	}
}
