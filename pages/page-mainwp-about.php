<?php
/**
 * MainWP About Page
 *
 * This page shows only when there has been an update to MainWP Dashboard
 *
 * @package MainWP/About
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_About
 *
 * Build the MainWP About Page
 *
 * @package MainWP/About
 */
class MainWP_About {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * init
	 */
	public static function init() {
	}

	/**
	 * Initiate Menue item
	 *
	 * Add WP Submenu Page "About MainWP"
	 */
	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab',
			__( 'About MainWP', 'mainwp' ),
			' <div class="mainwp-hidden">' . __( 'About MainWP', 'mainwp' ) . '</div>',
			'read',
			'mainwp_about',
			array(
				self::get_class_name(),
				'render',
			)
		);
	}

	/**
	 * About Page Wrapper
	 *
	 * Create About Page Wrapper for the What's new & Changelog
	 */
	public static function render() {
		global $mainWP;

		$showtab = 'whatsnew';
		if ( isset( $_GET['do'] ) ) {
			if ( 'changelog' == $_GET['do'] ) {
				$showtab = 'changelog';
			}
		}
		?>
		<div class="wrap about-wrap">
			<h1><?php esc_html_e( 'Welcome to MainWP Dashboard', 'mainwp' ) . '&nbsp;' . $mainWP->get_version(); ?></h1>
			<div class="about-text"><?php esc_html_e( 'Thank you for updating your MainWP Dashboard to', 'mainwp' ) . ' ' . $mainWP->get_version(); ?></div>
			<div class="mainwp-badge"><?php esc_html_e( 'Version ', 'mainwp' ) . $mainWP->get_version(); ?></div>
			<h2 class="nav-tab-wrapper wp-clearfix">
				<a class="nav-tab <?php echo 'whatsnew' == $showtab ? 'nav-tab-active' : ''; ?>" href="admin.php?page=mainwp_about&do=whatsnew"><?php esc_html_e( 'What\'s New', 'mainwp' ); ?></a>
				<a class="nav-tab <?php echo 'changelog' == $showtab ? 'nav-tab-active' : ''; ?>" href="admin.php?page=mainwp_about&do=changelog"><?php esc_html_e( 'Version Changelog', 'mainwp' ); ?></a>
			</h2>
			<?php
			if ( 'whatsnew' == $showtab ) {
				self::render_whats_new();
			} elseif ( 'changelog' == $showtab ) {
				self::render_mainwp_change_log();
			}
			?>
			<br/>
		</div>

		<?php
	}

	/**
	 * Render What's New
	 *
	 * Render the What's new content block
	 */
	public static function render_whats_new() {
		global $mainWP;
		?>
		<br/>
		<div class="mainwp-notice mainwp-notice-blue">
			<strong class="mainwp-large">Important note</strong>
			<p>This version introduces CSS and Javascript updates. To ensure you see the latest versions of scripts you need to clear your browser cache memory. This is done by doing a force refresh.</p>
			<p>Depending on your operating system all you need to do is to press the following key combination:</p>
			<ul>
				<li>Windows: ctrl + F5</li>
				<li>Mac/Apple: Apple + R or command + R</li>
				<li>Linux: F5</li>
			</ul>
		</div>
		<hr/>
		<?php
	}

	/**
	 * Render MainWP Change Log
	 *
	 * Render the MainWP Change Log Block
	 */
	public static function render_mainwp_change_log() {
		global $mainWP;
		?>
		<br/>
		<h3><?php echo esc_html( $mainWP->get_version() ); ?>&nbsp;<?php esc_html_e( 'Changelog', 'mainwp' ); ?></h3>
		<hr/>
		<ul>
			<li></li>
		</ul>
		<hr/>
		<h3><?php esc_html_e( 'See older versions changelogs', 'mainwp' ); ?>:</h3>
		<a href="https://wordpress.org/plugins/mainwp/#developers" target="_blank">MainWP Dashboard</a><br/>
		<a href="https://wordpress.org/plugins/mainwp-child/#developers" target="_blank">MainWP Child</a><br/>
		<?php
	}

}
