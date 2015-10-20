<?php

class MainWP_API_Settings_View {
	public static function initMenu() {
		//        add_submenu_page('plugins.php', __('MainWP Configuration', 'mainwp'), __('MainWP Configuration', 'mainwp'), 'manage_options', 'mainwp-config', array(MainWP_API_Settings::getClassName(), 'render'));
	}

	public static function maximumInstallationsReached() {
		return __( 'Maximum number of main installations on your MainWP plan have been reached. <a href="https://mainwp.com/member/login/index" target="_blank">Upgrade your plan for more sites</a>', 'mainwp' );
	}

	public static function render() {
		$username      = get_option( 'mainwp_api_username' );
		$password      = MainWP_Utility::decrypt( get_option( 'mainwp_api_password' ), 'MainWPAPI' );
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		$pluginDir     = ( ( $userExtension == null ) || ( ( $userExtension->pluginDir == null ) || ( $userExtension->pluginDir == '' ) ) ? 'default' : $userExtension->pluginDir );
		?>
		<div class="wrap"><a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
					src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>
			<img src="<?php echo plugins_url( 'images/icons/mainwp-passwords.png', dirname( __FILE__ ) ); ?>"
				style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Password" height="32"/>

			<h2><?php _e( 'MainWP Login Settings', 'mainwp' ); ?></h2>

			<div id="mainwp_api_errors" class="mainwp_error error" style="display: none"></div>
			<div id="mainwp_api_message" class="mainwp_updated updated" style="display: none"></div>
			<br/>

			<h3><?php _e( 'Initial MainWP Settings', 'mainwp' ); ?></h3>
			<table class="form-table">
				<tbody>
				<tr>
					<th scope="row"><?php _e( 'Hide Network on Child Sites', 'mainwp' ); ?></th>
					<td>
						<table>
							<tr>
								<td valign="top" style="padding-left: 0; padding-right: 5px; padding-top: 0px; padding- bottom: 0px;">
									<input type="checkbox" value="default" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_default" <?php echo( $pluginDir == 'hidden' ? 'checked="true"' : '' ); ?>/>
								</td>
								<td valign="top" style="padding: 0">
									<label for="mainwp_options_footprint_plugin_folder_default">
										<?php _e( 'This will make anyone including Search Engines trying find your Child Plugin encounter a 404 page. Hiding the Child Plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.', 'mainwp' ); ?>
									</label>

									<div class="mainwp_info-box" style="width: 650px; font-weight: bold; margin-top: 5px;"><?php _e( 'We recommend you have this option checked. You can change these settings any time on the settings page.', 'mainwp' ); ?></div>
								</td>
							</tr>
						</table>
					</td>
				</tr>
				</tbody>
			</table>

			<h3><?php _e( 'MainWP login', 'mainwp' ); ?></h3>
			<table class="form-table">
				<tbody>
				<tr>
					<th scope="row"><label for="mainwp_api_username"><?php _e( 'Username', 'mainwp' ); ?></label></th>
					<td>
						<input type="text" name="mainwp_api_username" id="mainwp_api_username" size="35"
							value="<?php echo $username; ?>"/>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mainwp_api_password"><?php _e( 'Password', 'mainwp' ); ?></label></th>
					<td>
						<input type="password" name="mainwp_api_password" id="mainwp_api_password" size="35"
							value="<?php echo $password; ?>"/>
					</td>
				</tr>
				</tbody>
			</table>
			<p class="submit">
				<input type="button" name="submit" id="mainwp-api-submit" class="button-primary" value="<?php _e( 'Save Settings', 'mainwp' ); ?>"/>
			</p>
		</div>
		<?php
	}

	public static function renderForumSignup() {
		?>
		<div class="postbox" style="padding: 1em;">
			<h3 style="border-bottom: none !important;"><?php _e( 'Have a question? Would you like to discuss MainWP with other users?', 'mainwp' ); ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
				<a href="https://mainwp.com/member/signup/index/c/forumsignup" target="_blank" class="button button-primary mainwp-upgrade-button button-hero" style="margin-top: -1em"><?php _e( 'Sign Up Here', 'mainwp' ); ?></a>
			</h3>
		</div>
		<?php
	}

	public static function renderSettings() {
		$username = get_option( 'mainwp_api_username' );
		$password = MainWP_Utility::decrypt( get_option( 'mainwp_api_password' ), 'MainWPAPI' );
		?>
		<div class="postbox mainwp_postbox" id="mainwp-account-information" section="setting-1">
			<div class="handlediv"><br/></div>
			<h3 class="mainwp_box_title">
				<span><i class="fa fa-cog"></i> <?php _e( 'MainWP Account Information', 'mainwp' ); ?></span></h3>

			<div class="inside">
				<div id="mainwp_api_errors" class="mainwp_error error" style="display: none"></div>
				<div id="mainwp_api_message" class="mainwp_updated updated" style="display: none"></div>
				<div class="mainwp_info-box-red" style="margin-top: 5px;"><?php _e( '<strong>IMPORTANT</strong>: This section is being retired and is replaced by the new Extension API and <a href="http://docs.mainwp.com/backups-scheduled-events-occurring/" target="_blank">Uptime Robot Cron wp-cron</a> trigger directions. You no longer need to add any information to this section it is here for a limited time to allow previous users time to update their Extensions to the new API system and setup their Uptime Robot accounts.', 'mainwp' ); ?></div>
				<table class="form-table">
					<tbody>
					<tr>
						<th scope="row"><label for="mainwp_api_username"><?php _e( 'Username', 'mainwp' ); ?></label>
						</th>
						<td>
							<input type="text" name="mainwp_api_username" class="mainwp-field mainwp-username" id="mainwp_api_username" size="35"
								value="<?php echo $username; ?>"/>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mainwp_api_password"><?php _e( 'Password', 'mainwp' ); ?></label>
						</th>
						<td>
							<input type="password" name="mainwp_api_password" class="mainwp-field mainwp-password" id="mainwp_api_password" size="35"
								value="<?php echo $password; ?>"/>
						</td>
					</tr>
            <span class="submit">
            <tr>
	            <th scope="row" colspan="2">
		            <input type="button" name="submit" id="mainwp-api-submit" class="button-primary" value="<?php _e( 'Save Login', 'mainwp' ); ?>"/>
	            </th>
            </tr>
            </span>
					<tr>
						<th scope="row"><?php _e( 'Use MainWP Cron Trigger', 'mainwp' ); ?><?php MainWP_Utility::renderToolTip( __( 'Enables the cron jobs triggered from the MainWP Member area. A MainWP login is required for this option.', 'mainwp' ) ); ?></th>
						<td>
							<div class="mainwp-checkbox">
								<input type="checkbox" name="mainwp_options_cron_jobs"
									id="mainwp_options_cron_jobs" <?php echo( ( get_option( 'mainwp_cron_jobs' ) == 1 ) ? 'checked="true"' : '' ); ?>/>
								<label for="mainwp_options_cron_jobs"></label>
							</div>
							<em style="display: inline;"><?php _e( 'Requires MainWP Login', 'mainwp' ); ?></em>
						</td>
					</tr>
					</tbody>
				</table>
				</p>
			</div>
		</div>
		<?php
	}
}
