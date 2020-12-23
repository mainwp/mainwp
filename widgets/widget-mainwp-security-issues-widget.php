<?php
/**
 * MainWP Security Widget
 *
 * Displays detected security issues on Child Sites.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Security_Issues_Widget
 *
 * Detect security issues on CHild Sites & Build Widget.
 */
class MainWP_Security_Issues_Widget {

	/**
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class Name
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render_widget()
	 *
	 * Fetch Child Site issues from db & build widget.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_search_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public static function render_widget() {
		$current_wpid = MainWP_System_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		} else {
			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
		}

		$websites = MainWP_DB::instance()->query( $sql );

		$total_securityIssues = 0;

		MainWP_DB::data_seek( $websites, 0 );
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( MainWP_Utility::ctype_digit( $website->securityIssues ) ) {
				$total_securityIssues += $website->securityIssues;
			}
		}

		self::render_issues( $websites, $total_securityIssues );
	}

	/**
	 *
	 * Method render_html_widget().
	 *
	 * Render html themes widget for current site
	 *
	 * @param mixed $websites Array of websites.
	 * @param mixed $total_securityIssues Total security Issues.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 */
	public static function render_issues( $websites, $total_securityIssues ) {
		?>
		<h3 class="ui header handle-drag">
			<?php
			/**
			 * Filter: mainwp_security_issues_widget_title
			 *
			 * Filters the Security Issues widget title text.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_security_issues_widget_title', __( 'Security Issues', 'mainwp' ) ) );
			?>
			<div class="sub header"><?php esc_html_e( 'Detected security issues', 'mainwp' ); ?></div>
		</h3>

		<div class="ui section hidden divider"></div>

		<?php
		/**
		 * Action: mainwp_security_issues_widget_top
		 *
		 * Fires at the bottom of the Security Issues widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_security_issues_widget_top' );
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
				<a href="#" class="ui button basic" id="show-security-issues-widget-list" data-tooltip="<?php esc_attr_e( 'Click here to see the list of all sites and detected security issues.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
				<input type="button" class="fix-all-security-issues ui button green" value="<?php esc_html_e( 'Fix All Issues', 'mainwp' ); ?>" data-tooltip="<?php esc_attr_e( 'Clicking this buttin will resolve all detected security issue on all your child sites.', 'mainwp' ); ?>" data-inverted=""/>
			</div>
		</div>

		<div class="ui section hidden divider"></div>

		<div id="mainwp-security-issues-widget-list" class="ui middle aligned divided selection list" style="display: none;">
			<?php
			MainWP_DB::data_seek( $websites, 0 );
			while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
				?>
				<div class="item" siteid="<?php echo intval( $website->id ); ?>">
				<div class="ui three column grid stackable">
				<div class="column middle aligned">
							<a href="
							<?php
							/**
							 * Filter: mainwp_security_issues_list_item_title_url
							 *
							 * Filters the Security Issues widget list item title URL.
							 *
							 * @since 4.1
							 */
							echo esc_attr( apply_filters( 'mainwp_security_issues_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
							?>
							">
								<?php
								/**
								 * Filter: mainwp_security_issues_list_item_title
								 *
								 * Filters the Security Issues widget list item title text.
								 *
								 * @since 4.1
								 */
								echo stripslashes( apply_filters( 'mainwp_security_issues_list_item_title', $website->name, $website ) );
								?>
							</a>
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
								esc_html_e( 'No security issues detected.', 'mainwp' );
							}
							?>
				</div>
						<?php
						/**
						 * Action: mainwp_security_issues_list_item_column
						 *
						 * Fires before the last (actions) colum in the security issues list.
						 *
						 * Preferred HTML structure:
						 *
						 * <div class="column middle aligned">
						 * Your content here!
						 * </div>
						 *
						 * @param object $website Object containing the child site info.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_security_issues_list_item_column', $website );
						?>
				<div class="column right aligned">
					<a href="admin.php?page=managesites&scanid=<?php echo esc_attr( $website->id ); ?>" class="ui button mini basic" data-tooltip="<?php esc_attr_e( 'Click here to see details.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Details', 'mainwp' ); ?></a>
					<?php if ( 0 == $website->securityIssues ) { ?>
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
				<?php esc_html_e( 'Well done!', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'No security issues detected!', 'mainwp' ); ?></div>
			</div>
		</h2>
			<?php
		}
		/**
		 * Action: mainwp_security_issues_widget_bottom
		 *
		 * Fires at the bottom of the Security Issues widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_security_issues_widget_bottom' );
	}

}
