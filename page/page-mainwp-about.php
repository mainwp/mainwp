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
		<h3>Usabilty, Maintanence and Style Update</h3>
		<p>MainWP <?php echo $mainWP->getVersion(); ?> introduces significant usabitlity and style updates. </p>
		<hr/>
		<h3>All new page tours</h3>
		<p>This is our favorite update to 3.2! Each page on the MainWP Dashboard now includes a step by step tour explaining each section of the page and how to use it. </p> 
		<p>This update should really help new users to get up and running by showing them exactly what to do on each page.</p>
		<p>If you are experienced user I highly recommend taking the tours, you may just find something you didn’t know was there! :-) </p>
		<p>We plan to have this type tour added to every Extension by the end of 2016.</p> 
		<h3>Updates Page</h3>
		<p>While not truly new it was scheduled for this release we just pushed it forward unannounced when we had a influx of new users a few weeks ago. :-) </p>
		<p>The Updates page allows you to quickly see the updates available on your WordPress sites. You can choose to view the updates page in three different ways.</p>
		<p>Per site: The default setting that allows you to drill down and look at each update for each of your sites.  </p>
		<p>Per Plugin/Theme:  Choose this setting to see the plugins / theme updates available by name.  This setting can be useful if you only want to update  a certain plugin / theme across all your WordPress sites.</p> 
		<p>Per Group (new with 3.2):  If you only want to see updates available to a certain group of sites this would be the option to select.</p>
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
			<li>Change 1</li>
			<li>Change 2</li>
			<li>Change 3</li>
			<li>Change 4</li>
		</ul>
		<hr/>
		<?php
	}
	
}
