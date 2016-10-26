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
		<h3><?php echo $mainWP->getVersion(); ?><?php _e( ' Changelog', 'mainwp' ); ?></h3>
		<hr/>
		<ul>
			<li>Fixed: An issue with loading themes on the Auto Updates page</li>
			<li>Fixed: Multiple typos and grammar issues</li>
			<li>Fixed: An issue with displaying disconnected sites in the Sync Status widget</li>
			<li>Fixed: An issue with activating backup extensions from the Quick Start Wizard</li>
			<li>Fixed: An issue with displaying extensions in incorrect categories</li>
			<li>Added: The MainWP Welcome page</li>
			<li>Added: Help tab on all MainWP Dashboard page</li>
			<li>Added: MainWP Blogroll widget</li>
			<li>Added: Helpful Links widget</li>
			<li>Added: Updates per group selection in the Update Overview widget and Updates page</li>
			<li>Added: Help tours on all MainWP Dashboard plugin pages</li>
			<li>Added: Scheduled filter in the Recent Posts widget</li>
			<li>Added: Scheduled filter in the Recent Pages widget</li>
			<li>Added: Ability to move columns in the MainWP tables</li>
			<li>Added: Ability to move and collapse all Option Boxes in the MainWP pages</li>
			<li>Added: New checks in the Server Information page</li>
			<li>Added: Ability to enable/disable auto updates for Plugins, Themes and WP Core separately</li>
			<li>Added: Ability to set multiple email addresses for notifications</li>
			<li>Added: Option on the Settings page to enable legacy backups feature</li>
			<li>Added: Ability to edit users directly from dashboard</li>
			<li>Added: Select2 style to all select form fields</li>
			<li>Added: General UI improvements</li>
			<li>Added: Sites selection to cached search results</li>
			<li>Updated: Add New Site page layout</li>
			<li>Updated: Groups selection field on the Add New Site page</li>
			<li>Updated: Manage Groups page</li>
			<li>Updated: The Select Sites metabox layout</li>
			<li>Updated: Extensions page layout</li>
			<li>Updated: Extensions widget layout</li>
			<li>Updated: Dashboard page renamed to Overview</li>
			<li>Updated: Right Now widget renamed to Update Overview</li>
			<li>Updated: Sync Status Widget renamed to Connection Status</li>
			<li>Updated: Future status renamed to Scheduled on the Search Posts box</li>
			<li>Updated: Future status renamed to Scheduled on the Search Pages box</li>
			<li>Updated: The Sync and Update popup box style</li>
			<li>Updated: The Auto Updates page layout</li>
			<li>Updated: MainWP native backups deactivated for new users</li>
			<li>Updated: Tabs order on the MainWP Settings page</li>
			<li>Updated: Form fields type on the MainWP Advanced Options page</li>
			<li>Updated: Data return options moved to Manage Posts and Manage pages with ability to set separatelly</li>
			<li>Updated: Manage Users page layout</li>
			<li>Updated: Search Users mechanism</li>
			<li>Updated: Import Sites feature extracted to a separate page</li>
			<li>Updated: Import Users feature extracted to a separate page</li>
			<li>Updated: General CSS styles</li>
			<li>Updated: Extension icons optimized for faster loading and plugin size reduction</li>
			<li>Updated: Email Notification template</li>
			<li>Updated: Notes dialog</li>
			<li>Updated: Plugins Widget style</li>
			<li>Updated: Themes Widget style</li>
			<li>Removed: How to widget</li>
			<li>Removed: Help widget</li>
			<li>Removed: Unreferenced CSS classes</li>
			<li>Removed: Tracking basic SEO stats</li>
			<li>Removed: Documentation page</li>
		</ul>
		<hr/>
		<h3><?php _e( 'See older versions changelogs', 'mainwp' ); ?>:</h3>
		<a href="https://wordpress.org/plugins/mainwp/changelog/" target="_blank">MainWP Dashboard</a><br/>
		<a href="https://wordpress.org/plugins/mainwp-child/changelog/" target="_blank">MainWP Child</a><br/>
		<?php
	}
	
}
