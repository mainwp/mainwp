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
		if ( isset( $_GET[ 'do' ] ) ) {
			if ( 'changelog' == $_GET[ 'do' ] ) {
				$showtab = 'changelog';
			}
		}
		?>
		<div class="wrap about-wrap">
			<h1><?php echo __( 'Welcome to MainWP Dashboard', 'mainwp' ) . '&nbsp;' . $mainWP->getVersion(); ?></h1>

			<div class="about-text"><?php echo __( 'Thank you for updating your MainWP Dashboard to', 'mainwp' ) . ' ' . $mainWP->getVersion(); ?></div>
			<div class="mainwp-badge"><?php echo __( 'Version ', 'mainwp' ) . $mainWP->getVersion(); ?></div>
			<h2 class="nav-tab-wrapper wp-clearfix">
				<a class="nav-tab <?php echo $showtab == 'whatsnew' ? 'nav-tab-active' : ''; ?>" href="admin.php?page=mainwp_about&do=whatsnew"><?php _e( 'What\'s New', 'mainwp' ); ?></a>
				<a class="nav-tab <?php echo $showtab == 'changelog' ? 'nav-tab-active' : ''; ?>" href="admin.php?page=mainwp_about&do=changelog"><?php _e( 'Version Changelog', 'mainwp' ); ?></a>
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

	public static function renderMainWPChangelog() {
		global $mainWP;
		?>
		<br/>
		<h3><?php echo esc_html($mainWP->getVersion()); ?>&nbsp;<?php _e( 'Changelog', 'mainwp' ); ?></h3>
		<hr/>
		<ul>
			<li>Fixed: an issue with sorting posts and pages by the publish date</li>
			<li>Fixed: sync error caused by the WP-SpamShield plugin</li>
			<li>Fixed: JavaScript (moment.js) conflict with the Gutenberg plugin</li>
			<li>Fixed: an issue with sending email notifications about available updates for some users</li>
			<li>Fixed: an issue with triggering unwanted backups</li>
			<li>Fixed: a usability issue with displaying incorrect last sync time</li>
			<li>Fixed: incorrect changelog links</li>
			<li>Added: mainwp_updatescheck_disable_notification_mail hook to disable email notifications about available updates</li>
			<li>Updated: the Update Everything process includes Translations updates</li>
			<li>Updated: the Update process will not check for required backups if a primary backup system is not set</li>
		</ul>
		<hr/>
		<h3><?php _e( 'See older versions changelogs', 'mainwp' ); ?>:</h3>
		<a href="https://wordpress.org/plugins/mainwp/#developers" target="_blank">MainWP Dashboard</a><br/>
		<a href="https://wordpress.org/plugins/mainwp-child/#developers" target="_blank">MainWP Child</a><br/>
		<?php
	}

}
