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
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
		<div class="mainwp-widget-header">
		<h3 class="ui header handle-drag">
			<?php
			/**
			 * Filter: mainwp_security_issues_widget_title
			 *
			 * Filters the Security Issues widget title text.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_security_issues_widget_title', esc_html__( 'Security Issues', 'mainwp' ) ) );
			?>
			<div class="sub header"><?php esc_html_e( 'Detected security issues', 'mainwp' ); ?></div>
		</h3>
		</div>

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
			<div class="ui two columns grid">
				<div class="column">
				<div class="ui horizontal statistics">
					<div class="statistic" style="margin: 0px;">
						<div class="value">
							<?php echo intval( $total_securityIssues ); ?>
						</div>
						<div class="label">
							<?php echo esc_html( _n( 'Security Issue Detected', 'Security Issues Detected', $total_securityIssues, 'mainwp' ) ); ?>
						</div>
					</div>
				</div>
			</div>
			<div class="right aligned middle aligned column">
				<a href="#" class="ui button mini basic" id="show-security-issues-widget-list" data-tooltip="<?php esc_attr_e( 'Click here to see the list of all sites and detected security issues.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
				<a href="#" class="<?php echo $is_demo ? 'disabled' : ''; ?> fix-all-security-issues ui button mini green" id="show-security-issues-widget-list" data-tooltip="<?php esc_attr_e( 'Clicking this buttin will resolve all detected security issue on all your child sites.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Fix All Issues', 'mainwp' ); ?></a>

			</div>
		</div>

			<div class="mainwp-scrolly-overflow">
		<div id="mainwp-security-issues-widget-list" class="ui middle aligned divided selection list" style="display: none;">
			<?php
			$count_security_issues = '';
			MainWP_DB::data_seek( $websites, 0 );
			while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( '[]' === $website->securityIssues ) {
					$count_security_issues = '';
				} else {
					$count_security_issues = intval( $website->securityIssues );
				}

				?>
				<div class="item" <?php echo '' !== $count_security_issues && $count_security_issues > 0 ? 'status="queue"' : ''; ?> siteid="<?php echo intval( $website->id ); ?>">
				<div class="ui grid stackable">
					<div class="eight wide middle aligned column">
					<a href="
					<?php
					/**
					 * Filter: mainwp_security_issues_list_item_title_url
					 *
					 * Filters the Security Issues widget list item title URL.
					 *
					 * @since 4.1
					 */
					echo esc_url( apply_filters( 'mainwp_security_issues_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
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
					echo esc_attr( stripslashes( apply_filters( 'mainwp_security_issues_list_item_title', $website->name, $website ) ) );
					?>
					</a>
				</div>
				<div class="five wide middle aligned column">
					<?php if ( 0 === $count_security_issues ) : ?>
						<span class="ui green small empty circular label"></span> <span class="ui small text"><?php esc_html_e( 'No issues detected', 'mainwp' ); ?></span>
					<?php elseif ( '' === $count_security_issues ) : ?>
						<span class="ui grey small empty circular label"></span> <span class="ui small text"><?php esc_html_e( 'No data available', 'mainwp' ); ?></span>
					<?php else : ?>
						<span class="ui red small empty circular label"></span> <span class="ui small text"><?php echo esc_html( $count_security_issues ); ?> <?php echo esc_html( _n( 'issue detected', 'issues detected', $count_security_issues, 'mainwp' ) ); ?></span>
					<?php endif; ?>
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
						<div class="three wide middle aligned column">
							<div class="ui mini icon fluid buttons">
								<a href="admin.php?page=managesites&scanid=<?php echo esc_attr( $website->id ); ?>" class="ui button basic" data-tooltip="<?php esc_attr_e( 'Click here to see details.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="info icon"></i></a>
							<?php if ( empty( $count_security_issues ) ) { ?>
								<a href="javascript:void(0)" class="<?php echo $is_demo ? 'disabled' : ''; ?> unfix-all-site-security-issues ui button basic green" data-position="left center" data-tooltip="<?php esc_attr_e( 'Click here to unfix all security issues on the child site.', 'mainwp' ); ?>" data-inverted=""><i class="undo alternate icon"></i></a>
					<?php } else { ?>
							<a href="javascript:void(0)" class="<?php echo $is_demo ? 'disabled' : ''; ?> fix-all-site-security-issues ui button green" data-position="left center" data-tooltip="<?php esc_attr_e( 'Click here to fix all security issues on the child site.', 'mainwp' ); ?>" data-inverted=""><i class="wrench icon"></i></a>
					<?php } ?>
				</div>
				</div>
				</div>
						</div>
			<?php } ?>
		</div>
			</div>
		<div class="ui active inverted dimmer" style="display:none" id="mainwp-secuirty-issues-loader"><div class="ui text loader"><?php esc_html_e( 'Please wait...', 'mainwp' ); ?></div></div>
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
