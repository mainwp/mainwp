<?php

class MainWP_Options {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function handleSettingsPost() {
		if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
			$userExtension             = MainWP_DB::Instance()->getUserExtension();
                        $save_emails = array();
                        $user_emails = $_POST['mainwp_options_email'];
                        if (is_array($user_emails)) {
                            foreach($user_emails as $email) {
                                $email = esc_html(trim($email)); 
                                if (!empty($email) && !in_array($email, $save_emails)) {
                                    $save_emails[] = $email;
                                }
                            }
                        }
                        
                        $save_emails = implode(',', $save_emails);
			$userExtension->user_email = $save_emails;
			$userExtension->site_view  = ( ! isset( $_POST['mainwp_options_siteview'] ) ? 0 : 1 );

			$userExtension->heatMap   = ( ! isset( $_POST['mainwp_options_footprint_heatmap'] ) ? 1 : 0 );
			$userExtension->pluginDir = ( isset( $_POST['mainwp_options_footprint_plugin_folder'] ) ? $_POST['mainwp_options_footprint_plugin_folder'] : 'default' );

			MainWP_DB::Instance()->updateUserExtension( $userExtension );
			if ( MainWP_Utility::isAdmin() ) {
				MainWP_Utility::update_option( 'mainwp_optimize', ( ! isset( $_POST['mainwp_optimize'] ) ? 0 : 1 ) );
				$val = ( ! isset( $_POST['mainwp_automaticDailyUpdate'] ) ? 2 : $_POST['mainwp_automaticDailyUpdate'] );
				MainWP_Utility::update_option( 'mainwp_automaticDailyUpdate', $val );
				$val = ( ! isset( $_POST['mainwp_show_language_updates'] ) ? 0 : 1 );
				MainWP_Utility::update_option( 'mainwp_show_language_updates', $val );
				$val = ( ! isset( $_POST['mainwp_backup_before_upgrade'] ) ? 0 : 1 );
				MainWP_Utility::update_option( 'mainwp_backup_before_upgrade', $val );
				$val  = ( ! isset( $_POST['mainwp_backup_before_upgrade_days'] ) ? 7 : $_POST['mainwp_backup_before_upgrade_days'] );
				MainWP_Utility::update_option( 'mainwp_backup_before_upgrade_days', $val );

				//MainWP_Utility::update_option( 'mainwp_maximumPosts', MainWP_Utility::ctype_digit( $_POST['mainwp_maximumPosts'] ) ? intval( $_POST['mainwp_maximumPosts'] ) : 50 );
				if ( MainWP_Extensions::isExtensionAvailable('mainwp-comments-extension') ) { 
					MainWP_Utility::update_option( 'mainwp_maximumComments', MainWP_Utility::ctype_digit( $_POST['mainwp_maximumComments'] ) ? intval( $_POST['mainwp_maximumComments'] ) : 50 );
				}
				MainWP_Utility::update_option( 'mainwp_wp_cron', ( ! isset( $_POST['mainwp_options_wp_cron'] ) ? 0 : 1 ) );
				//MainWP_Utility::update_option('mainwp_use_favicon', (!isset($_POST['mainwp_use_favicon']) ? 0 : 1));
				MainWP_Utility::update_option( 'mainwp_numberdays_Outdate_Plugin_Theme', MainWP_Utility::ctype_digit( $_POST['mainwp_numberdays_Outdate_Plugin_Theme'] ) ? intval( $_POST['mainwp_numberdays_Outdate_Plugin_Theme'] ) : 365 );
                $ignore_http = isset($_POST['mainwp_ignore_http_response_status']) ? $_POST['mainwp_ignore_http_response_status'] : '';
                MainWP_Utility::update_option( 'mainwp_ignore_HTTP_response_status', $ignore_http );
			}

			return true;
		}

		return false;
	}

	public static function renderSettings() {
		MainWP_Tours::renderGeneralSettingsTour();
		MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_global_settings'); 		
	}
	
	public static function renderNetworkOptimization() {
		$userExtension          = MainWP_DB::Instance()->getUserExtension();
		$pluginDir              = ( ( $userExtension == null ) || ( ( $userExtension->pluginDir == null ) || ( $userExtension->pluginDir == '' ) ) ? 'default' : $userExtension->pluginDir );
		?>
			<div class="mainwp-postbox-actions-top">
				<?php _e( '<strong>STOP BEFORE TURNING ON!</strong>', 'mainwp' ); ?>
				<br />
				<?php _e( 'Hiding the child plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.', 'mainwp' ); ?>
			</div>
			<div class="inside">
				<table class="form-table">
					<tbody>
					<tr>
						<th scope="row"><?php _e('Hide MainWP Child plugin from search engines','mainwp'); ?><br/>
							<em style="font-size: 12px;">(<?php _e('does not hide from users','mainwp'); ?>)</em>
						</th>
						<td>
							<table>
								<tr>
									<td valign="top" style="padding-left: 0; padding-right: 5px; padding-top: 0px; padding-bottom: 0px; vertical-align: top;">
										<div class="mainwp-checkbox">
											<input type="checkbox" value="hidden" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_default" <?php echo( $pluginDir == 'hidden' ? 'checked="true"' : '' ); ?>/><label for="mainwp_options_footprint_plugin_folder_default"></label>
										</div>
									</td>
									<td valign="top" style="padding: 0">
										<label for="mainwp_options_footprint_plugin_folder_default">
											<em><?php _e( 'This will make anyone including Search Engines trying find your Child Plugin encounter a 404 page. Hiding the Child Plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.', 'mainwp' ); ?></em>
										</label>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Optimize for shared hosting or big networks','mainwp'); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __('Updates will be cached for quick loading. A manual refresh from the Dashboard is required to view new plugins, themes, pages or users. Recommended for networks over 50 sites.', 'mainwp' )); ?></th>
						<td>
							<div class="mainwp-checkbox">
								<input type="checkbox" name="mainwp_optimize"
								       id="mainwp_optimize" <?php echo ((get_option('mainwp_optimize') == 1) ? 'checked="true"' : ''); ?> />
								<label for="mainwp_optimize"></label>
							</div>
						</td>
					</tr>
					</tbody>
				</table>
			</div>
		<?php
	}
	
	public static function renderGlobalOptions() {
		$user_emails             = MainWP_Utility::getNotificationEmail();
                $user_emails = explode(',', $user_emails);                
                $i = 0;
		?>
		<table class="form-table">
				<tbody>                               
				<tr>
					<th scope="row"><?php _e( 'Notification Emails', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Those addresses are used to send monitoring alerts.', 'mainwp' ) ); ?></th>
					<td>
                                             <?php foreach($user_emails as $email) { 
                                                $i++;                                        
                                                ?>
                                                <div class="mwp_email_box">
						<input type="text" class="" id="mainwp_options_email" name="mainwp_options_email[<?php echo $i; ?>]" size="35" value="<?php echo $email; ?>"/>&nbsp;
                                                <?php if ($i != 1) { ?>
                                                <a href="#" class="mwp_remove_email"><i class="fa fa-minus-circle fa-lg mainwp-red" aria-hidden="true"></i></a>
                                                <?php } ?>
                                                </div>                                                
                                            <?php } ?>
                                            <a href="#" id="mwp_add_other_email" class="mainwp-small"><?php _e( '+ Add New'); ?></a>
					</td>
				</tr>
                                
				<tr>
					<th scope="row"><?php _e( 'Use WP-Cron', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'When not using WP-Cron you will need to set up a cron job via your hosting.', 'mainwp' ), 'http://docs.mainwp.com/disable-wp-cron/' ); ?></th>
					<td>
						<div class="mainwp-checkbox">
							<input type="checkbox" name="mainwp_options_wp_cron"
								   id="mainwp_options_wp_cron" <?php echo( ( get_option( 'mainwp_wp_cron' ) == 1 ) || ( get_option( 'mainwp_wp_cron' ) === false ) ? 'checked="true"' : '' ); ?>/>
							<label for="mainwp_options_wp_cron"></label>
						</div>
					</td>
				</tr>			
				</tbody>
			</table>
                <script type="text/javascript">
                    jQuery(document).ready(function () {                                
                            jQuery('.mwp_remove_email').live('click', function () {                                        
                                jQuery(this).closest('.mwp_email_box').remove();                                        
                                return false;
                            });
                            jQuery('#mwp_add_other_email').live('click', function () {                                        
                                jQuery('#mwp_add_other_email').before('<div class="mwp_email_box"><input type="text" name="mainwp_options_email[]" size="35" value=""/>&nbsp;&nbsp;<a href="#" class="mwp_remove_email"><i class="fa fa-minus-circle fa-lg mainwp-red" aria-hidden="true"></i></a></div>');
                                return false;
                            });
                    });
                </script>
		<?php
	}
	
	public static function renderUpdateOptions() {
		$snAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );
		$backup_before_upgrade  = get_option( 'mainwp_backup_before_upgrade' );
		$mainwp_backup_before_upgrade_days  = get_option( 'mainwp_backup_before_upgrade_days' );
		if ( empty( $mainwp_backup_before_upgrade_days ) || !ctype_digit( $mainwp_backup_before_upgrade_days ) ) $mainwp_backup_before_upgrade_days = 7;
		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );
		
                $update_time    = MainWP_Utility::getWebsitesAutomaticUpdateTime();                
                $lastAutomaticUpdate = $update_time['last'];
                $nextAutomaticUpdate = $update_time['next'];
                
                $enableLegacyBackupFeature = get_option( 'mainwp_enableLegacyBackupFeature' );
                $primaryBackup = get_option('mainwp_primaryBackup');  
                $style = (($enableLegacyBackupFeature && empty($primaryBackup)) || (empty($enableLegacyBackupFeature) && !empty($primaryBackup))) ? '' : 'style="display:none"';
                
	?>
		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row"><?php _e( 'Show WordPress Language Updates', 'mainwp' ); ?></th>
				<td>
					<div class="mainwp-checkbox">
						<input type="checkbox" name="mainwp_show_language_updates" id="mainwp_show_language_updates" size="35" <?php echo( $mainwp_show_language_updates == 1 ? 'checked="true"' : '' ); ?>/>
						<label for="mainwp_show_language_updates"></label>
					</div>
				</td>
			</tr>
                        <?php 
                        
                        ?>
			<tr <?php echo $style; ?>>
				<th scope="row"><?php _e( 'Require Backup Before Update', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'With this option enabled, when you try to update a plugin, theme or WordPress core, MainWP will check if there is a full backup created for the site(s) you are trying to update in last 7 days. If you have a fresh backup of the site(s) MainWP will proceed to the update process, if not it will ask you to create a full backup.', 'mainwp' ) ); ?></th>
				<td>
					<div class="mainwp-checkbox">
						<input type="checkbox" name="mainwp_backup_before_upgrade" id="mainwp_backup_before_upgrade" size="35" <?php echo( $backup_before_upgrade == 1 ? 'checked="true"' : '' ); ?>/>
						<label for="mainwp_backup_before_upgrade"></label>
					</div>
					If a full backup has not been taken in the last <input type="text" name="mainwp_backup_before_upgrade_days" id="mainwp_backup_before_upgrade_days" size="2" value="<?php echo $mainwp_backup_before_upgrade_days; ?>" /> days.
				</td>
			</tr>                        
			<tr>
				<th scope="row"><?php _e( 'WP Core auto updates', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Choose to have MainWP install updates, or notify you by email of available updates.  Updates apply to WordPress Core files.', 'mainwp' ) ); ?></th>
				<td>
					<table class="mainwp-nomarkup">
						<tr>
							<td valign="top">
								<span class="mainwp-select-bg">
									<select class="mainwp-select2-super" name="mainwp_automaticDailyUpdate" id="mainwp_automaticDailyUpdate">
										<option value="2" <?php if ( ( $snAutomaticDailyUpdate === false ) || ( $snAutomaticDailyUpdate == 2 ) ) { ?>selected<?php } ?>>E-mail Notifications of New Updates</option>
										<option value="1" <?php if ( $snAutomaticDailyUpdate == 1 ) {?>selected<?php } ?>>Install Trusted Updates</option>
										<option value="0" <?php if ( $snAutomaticDailyUpdate !== false && $snAutomaticDailyUpdate == 0 ) {?>selected<?php } ?>>Off</option>
									</select>
									<label></label>
								</span>
								<br/><em><?php _e( 'Last run: ', 'mainwp' ); ?><?php echo $lastAutomaticUpdate; ?></em>
								<br /><em><?php _e( 'Next run: ', 'mainwp' ); ?><?php echo $nextAutomaticUpdate; ?></em>
							</td>
						</tr>
					</table>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php _e( 'Abandoned Plugins/Themes Tolerance', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( "In case the plugin or theme author didn't release an update for the set number of days, the plugin/theme will be marked and Possibly Abandoned.", 'mainwp' ) ); ?></th>
				<td>
					<input type="text" name="mainwp_numberdays_Outdate_Plugin_Theme" class=""
						   id="mainwp_numberdays_Outdate_Plugin_Theme" value="<?php echo( ( get_option( 'mainwp_numberdays_Outdate_Plugin_Theme' ) === false ) ? 365 : get_option( 'mainwp_numberdays_Outdate_Plugin_Theme' ) ); ?>"/>
				</td>
			</tr>
            <tr>
				<th scope="row"><?php _e( 'Ignore HTTP response status', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( "Ignore HTTP response status.", 'mainwp' ) ); ?></th>
				<td>
					<input type="text" name="mainwp_ignore_http_response_status" class=""
						   id="mainwp_ignore_http_response_status" value="<?php echo( get_option( 'mainwp_ignore_HTTP_response_status', '' ) ); ?>"/>
				</td>
			</tr>
			</tbody>
		</table>
	<?php
	}
	
	public static function renderDataReturnOptions() {
	?>
		<table class="form-table">
				<tbody>							
				<?php //if ( MainWP_Extensions::isExtensionAvailable('mainwp-comments-extension') ) { ?>
				<tr>
					<th scope="row"><?php _e( 'Maximum Number of Comments', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( '0 for unlimited, CAUTION: a large amount will decrease the speed and might crash the communication.', 'mainwp' ) ); ?></th>
					<td>
						<input type="text" name="mainwp_maximumComments" class=""
							   id="mainwp_maximumComments" value="<?php echo( ( get_option( 'mainwp_maximumComments' ) === false ) ? 50 : get_option( 'mainwp_maximumComments' ) ); ?>"/>
					</td>
				</tr>
				<?php //} ?>
				</tbody>
			</table>
	<?php
	}
}
