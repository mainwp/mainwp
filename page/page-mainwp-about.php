<?php

class MainWP_About {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function init() {

	}

	public static function initMenu() {
		add_submenu_page( 'mainwp_tab', __( 'About MainWP', 'mainwp' ), ' <div class="mainwp-hidden">' . __( 'About MainWP', 'mainwp' ) . '</div>', 'read', 'mainwp_about', array(
			MainWP_About::getClassName(),
			'render',
		) );
	}

	public static function render() {
		global $mainWP;

		$showtab = 'whatsnew';
		if( isset( $_GET['do'] ) ) {
			if ( 'changelog' == $_GET['do'] ) {
				$showtab = 'changelog';
			}
		}

		?>
		<div class="wrap about-wrap">
			<h1><?php echo __('Welcome to MainWP Dashboard', 'mainwp') . '&nbsp;' . $mainWP->getVersion(); ?></h1>

			<div class="about-text"><?php echo __('Thank you for updating your MainWP Dashboard to', 'mainwp') . ' ' . $mainWP->getVersion();?></div>
			<div class="mainwp-badge"><?php echo __('Version ', 'mainwp') . $mainWP->getVersion(); ?></div>
			<h2 class="nav-tab-wrapper wp-clearfix">
				<a class="nav-tab <?php echo $showtab == 'whatsnew' ? 'nav-tab-active' : ''; ?>" href="admin.php?page=mainwp_about&do=whatsnew"><?php _e('What\'s New', 'mainwp');?></a>
				<a class="nav-tab <?php echo $showtab == 'changelog' ? 'nav-tab-active' : ''; ?>" href="admin.php?page=mainwp_about&do=changelog"><?php _e('Version Changelog', 'mainwp');?></a>
			</h2>
			<?php
			if ( 'whatsnew' == $showtab ) {
				self::renderWhatSNew();
			} else if ( 'changelog' == $showtab ) {
				self::renderMainWPChangelog();
			}
			?>
			<br/>
		</div>

		<?php
	}

	public static function renderWhatSNew() {
		global $mainWP;
		?>
		<br/>
		<h3>MainWP 3.2 a Usability Update</h3>
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
		<p>You can read our <a href="https://mainwp.com/mainwp-3-2-usuability-update/?utm_source=dashboard&utm_campaign=welcome-page&utm_medium=plugin" target="_blank">Blog Article</a> to see the full list of updates.</p>
		<hr/>
		<?php
	}

	public static function renderMainWPChangelog() {
		global $mainWP;
		?>
		<br/>
		<h3><?php echo $mainWP->getVersion(); ?>&nbsp;<?php _e( 'Changelog', 'mainwp' ); ?></h3>
		<hr/>
		<ul>
			<li>Fixed: an issue with selecting Posts, Pages and Users</li>
			<li>Fixed: an issue with selecting Plugins and Themes on the Manage Plugins and Manage Themes page</li>
			<li>Fixed: security checking issue</li>
			<li>Fixed: multiple PHP Warnings and Notices</li>
			<li>Added: site Info widget in the individual site overview page</li>
			<li>Added: PHP Version column in the manage sites table</li>
			<li>Added: Sync Now button for all online sites in connection status widget</li>
			<li>Added: verification when user tries to delete Plugins, Pages, Posts, Themes and Users</li>
			<li>Added: ability to sort Manage Sites table by WP, Plugin and Theme updates column</li>
			<li>Added: Coding for future WooCommerce Extension</li>
			<li>Preventative: Security improvements</li>
		</ul>
		<hr/>
		<h3><?php _e( 'See older versions changelogs', 'mainwp' ); ?>:</h3>
		<a href="https://wordpress.org/plugins/mainwp/changelog/" target="_blank">MainWP Dashboard</a><br/>
		<a href="https://wordpress.org/plugins/mainwp-child/changelog/" target="_blank">MainWP Child</a><br/>
		<?php
	}

}
