<?php

class MainWP_Documentation {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function initMenu() {
		add_submenu_page( 'mainwp_tab', __( 'Documentation', 'mainwp' ), __( 'Documentation', 'mainwp' ), 'read', 'Documentation', array(
			MainWP_Documentation::getClassName(),
			'render',
		) );
	}

	public static function render() {
		?>
		<div class="wrap">
			<a href="//mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>

			<h2><i class="fa fa-book"></i> <?php _e( 'Documentation', 'mainwp' ); ?></h2>

			<div class="clear"></div>
			<br/><br/>

			<div class="wrap" style="font-size: 16px !important;">
				<div class="postbox" style="padding: 1em !important;">
					<form name="advanced-search-form" method="get" action="//docs.mainwp.com/" class="auto-complete" autocomplete="off" target="_blank">
						<input type="text" style="width: 85%;" class="input-text input-txt" name="s" id="s" value="" placeholder="<?php esc_attr_e( 'Search the MainWP Docs', 'mainwp' ); ?>"/>
						<button type="submit" class="button button-primary mainwp-upgrade-button" style="padding-left: 3em !important; padding-right: 3em !important;"><?php _e( 'Search', 'mainwp' ); ?></button>
					</form>
				</div>
				<div id="mainwp-documentation-box">

					<div id="mainwp-quick-start-box" class="postbox" style="padding: 1em !important;">
						<h2><?php _e( 'Quick Start', 'mainwp' ); ?></h2>
						<ul>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/setting-up-your-mainwp/" target="_blank"><i class="fa fa-file-text-o"></i> Setting Up Your MainWP</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/mainwp-settings-overview/" target="_blank"><i class="fa fa-file-text-o"></i> Settings</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/adding-a-site-to-your-mainwp/" target="_blank"><i class="fa fa-file-text-o"></i> Adding A Site To Your Network</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/quick-start-adding-a-new-group/" target="_blank"><i class="fa fa-file-text-o"></i> Adding A New Group</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/mainwp-dashboard-overview/" target="_blank"><i class="fa fa-file-text-o"></i> Dashboard</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/individual-site-dashboard-overview/" target="_blank"><i class="fa fa-file-text-o"></i> Individual Site Dashboard</a>
							</li>
						</ul>
					</div>
					<div id="mainwp-how-to-box" class="postbox" style="padding: 1em !important;">
						<h2><?php _e( 'How To', 'mainwp' ); ?></h2>
						<ul>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/managing-posts/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Posts</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/managing-pages/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Pages</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/managing-comments/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Comments</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/managing-users/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Users</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/manage-themes/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Themes</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/manage-plugins/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Plugins</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/manage-admin-passwords/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Admin Passwords</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/manage-offline-checks/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Offline Checks</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/manage-backups/" target="_blank"><i class="fa fa-file-text-o"></i> Manage Backups</a>
							</li>
						</ul>
					</div>
					<div id="mainwp-extensions-box" class="postbox" style="padding: 1em !important;">
						<h2>Extensions</h2>
						<ul>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/enter-extensions-api-keys/" target="_blank"><i class="fa fa-file-text-o"></i> How to Download and Activate MainWP Extensions</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/how-to-update-mainwp-extensions/" target="_blank"><i class="fa fa-file-text-o"></i> How to update MainWP Extensions</a>
							</li>
						</ul>
					</div>
					<div id="mainwp-faq-box" class="postbox" style="padding: 1em !important;">
						<h2>FAQ</h2>
						<ul>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/will-mainwp-leave-a-footprint/" target="_blank"><i class="fa fa-file-text-o"></i> Will MainWP leave a Footprint?</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/can-i-rename-the-plugin-folder-on-managed-sites/" target="_blank"><i class="fa fa-file-text-o"></i> Can I rename the Plugin Folder on Managed sites?</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/does-mainwp-handle-custom-post-types/" target="_blank"><i class="fa fa-file-text-o"></i> Does MainWP Handle Custom Post Types?</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/plugin-or-theme-search-does-not-seem-to-find-what-i-am-searching-for/" target="_blank"><i class="fa fa-file-text-o"></i> Plugin or Theme search does not seem to find what I am searching for.</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/install-or-update-of-a-plugin-fails-on-managed-site/" target="_blank"><i class="fa fa-file-text-o"></i> Install or update of a Plugin fails on a managed site</a>
							</li>
							<li>
								<a style="text-decoration: none;" href="//docs.mainwp.com/install-or-update-of-a-theme-fails-on-a-managed-site/" target="_blank"><i class="fa fa-file-text-o"></i> Install or update of a Theme fails on a managed site</a>
							</li>
						</ul>
					</div>
				</div>
			</div>
			<div class="clear"></div>
			<br/>

			<div style="margin-bottom: 2em;">
				<a href="//docs.mainwp.com/mainwp-changelog/" target="_blank" class="add-new-h2">MainWP Changelog</a>
				<a href="//docs.mainwp.com/mainwp-system-requirements/" target="_blank" class="add-new-h2">MainWP System Requirements</a><a href="//mainwp.com/forum/" class="add-new-h2">Support Forum</a>
			</div>
		</div>
		<?php

	}
}
