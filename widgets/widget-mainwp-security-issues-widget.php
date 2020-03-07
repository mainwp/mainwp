<?php

class MainWP_Security_Issues_Widget {

	public static function getClassName() {
		return __CLASS__;
	}

	public static function renderWidget() {
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
		} else {
			$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser();
		}

		$websites = MainWP_DB::Instance()->query( $sql );

		$total_securityIssues = 0;

		@MainWP_DB::data_seek( $websites, 0 );
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			if ( MainWP_Utility::ctype_digit( $website->securityIssues ) ) {
				$total_securityIssues += $website->securityIssues;
			}
		}
		?>

		<h3 class="ui header handle-drag">
			<?php esc_html_e('Security Issues', 'mainwp'); ?>
			<div class="sub header"><?php _e( 'Detected security issues', 'mainwp' ); ?></div>
		</h3>

		<div class="ui section hidden divider"></div>

		<?php
		// We found some with security issues!
		if ( $total_securityIssues > 0 ) {
			?>
		<div class="ui two column grid stackable">
			<div class="column">
				<div class="ui horizontal statistics">
					<div class="statistic" style="margin: 0px;">
						<div class="value">
							<?php echo $total_securityIssues; ?>
						</div>
						<div class="label">
							<?php echo _n( 'Security Issue Detected', 'Security Issues Detected', $total_securityIssues, 'mainwp' ); ?>
						</div>
					</div>
				</div>
			</div>
			<div class="column right aligned">
				<a href="#" class="ui button basic" id="show-security-issues-widget-list" data-tooltip="<?php esc_attr_e( 'Click here to see the list of all sites and detected security issues.', 'mainwp' ); ?>" data-inverted=""><?php echo __( 'See Details', 'mainwp' ); ?></a>
				<input type="button" class="fix-all-security-issues ui button green" value="<?php _e( 'Fix All Issues', 'mainwp' ); ?>" data-tooltip="<?php esc_attr_e( 'Clicking this buttin will resolve all detected security issue on all your child sites.', 'mainwp' ); ?>" data-inverted=""/>
			</div>
		</div>

		<div class="ui section hidden divider"></div>

		<div id="mainwp-security-issues-widget-list" class="ui middle aligned divided selection list" style="display: none;">
			<?php
			@MainWP_DB::data_seek( $websites, 0 );
			while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
				// if ( !MainWP_Utility::ctype_digit( $website->securityIssues ) || $website->securityIssues == 0 ) {
				// continue;
				// }
				?>
				<div class="item" siteid="<?php echo $website->id; ?>">
				<div class="ui three column grid stackable">
				  <div class="column middle aligned">
					<a href="admin.php?page=managesites&dashboard=<?php echo esc_attr($website->id); ?>"><?php echo stripslashes( $website->name ); ?></a>
				  </div>
				  <div class="column middle aligned">
							<?php
							if ( $website->securityIssues > 0 ) {
								?>
								<div class="ui label basic medium red">
									<?php echo esc_html( $website->securityIssues ); ?> <?php echo _n( 'issue', 'issues', $website->securityIssues, 'mainwp' ); ?>
								</div>
								<?php
							} else {
								echo __( 'No security issues detected.', 'mainwp' );
							}
							?>
				  </div>
				  <div class="column right aligned">
							<a href="admin.php?page=managesites&scanid=<?php echo esc_attr($website->id); ?>" class="ui button mini basic" data-tooltip="<?php esc_attr_e( 'Click here to see details.', 'mainwp' ); ?>" data-inverted=""><?php _e( 'Details', 'mainwp' ); ?></a>
					  <?php if ( $website->securityIssues == 0 ) { ?>
					  <input type="button" class="unfix-all-site-security-issues ui button basic green mini" value="<?php esc_attr_e( 'Unfix All', 'mainwp' ); ?>" data-tooltip="<?php esc_attr_e( 'Click here to unfix all security issues on the child site.', 'mainwp' ); ?>" data-inverted=""/>
					<?php } else { ?>
					  <input type="button" class="fix-all-site-security-issues ui button green mini" value="<?php esc_attr_e( 'Fix All', 'mainwp' ); ?>" data-tooltip="<?php esc_attr_e( 'Click here to fix all security issues on the child site.', 'mainwp' ); ?>" data-inverted=""/>
					<?php } ?>
				  </div>
				</div>
				</div>
			<?php } ?>
		</div>

		<div class="ui active inverted dimmer" style="display:none" id="mainwp-secuirty-issues-loader"><div class="ui text loader">Please wait...</div></div>

			<?php
		} else {
			?>
		<h2 class="ui icon header">
			<i class="thumbs up outline icon"></i>
			<div class="content">
				<?php _e( 'Well done!', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'No security issues detected!', 'mainwp' ); ?></div>
			</div>
		</h2>
			<?php
		}

	}

}
