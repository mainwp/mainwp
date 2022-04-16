<?php
/**
 * MainWP Auto Cache Purge settings view
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Auto_Cache_Purge_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Auto_Cache_Purge_View {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Method get_class_name()
	 *
	 * Get class name.
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Public static variable to hold Subpages information.
	 *
	 * @var array $subPages
	 */
	public static $subPages;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @static
	 * @return MainWP_Auto_Cache_Purge_View
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * MainWP_Bulk_Post constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
	}

	/**
	 *  Instantiate Hooks for the Settings Page.
	 *  Called from class-mainwp-system.php.
	 */
	public function init() {
		self::instance()->admin_init();
	}

	/**
	 * Method admin_init() initiated by init()
	 *
	 * Instantiate Hooks for the page.
	 */
	public function admin_init() {
		add_filter( 'mainwp_sync_others_data', array( $this, 'cache_control_sync_others_data' ), 10, 2 );
		add_filter( 'mainwp_page_navigation', array( $this, 'cache_control_navigation' ) );
		add_filter( 'mainwp_sitestable_getcolumns', array( $this, 'cache_control_sitestable_column' ), 10, 1 );
		add_filter( 'mainwp_sitestable_item', array( $this, 'cache_control_sitestable_item' ), 10, 1 );
		add_action( 'mainwp_site_synced', array( $this, 'synced_site' ), 10, 2 );
		add_action( 'mainwp_after_upgrade_wp_success', array( $this, 'upgrade_wp_success' ), 10, 2 );
		add_action( 'mainwp_after_plugin_theme_translation_update', array( $this, 'update_plugin_theme_success' ), 10, 4 );
	}

	/**
	 * Cache Control page Header Navigation.
	 *
	 * @param $subPages $subPages is an Array of subpages.
	 * @return array|mixed
	 */
	public function cache_control_navigation( $subPages ) {
		$currentScreen = get_current_screen();

		// Only show on these subpages.
		$show = array(
			'mainwp_page_Settings',
			'mainwp_page_SettingsAdvanced',
			'mainwp_page_SettingsEmail',
			'mainwp_page_MainWPTools',
			'mainwp_page_RESTAPI',
			'mainwp_page_cache-control',
		);
		if ( in_array( $currentScreen->id, $show ) ) {
			if ( isset( $subPages ) && is_array( $subPages ) ) {
				$subPages[] = array(
					'title'  => __( 'Cache Control', 'mainwp' ),
					'href'   => 'admin.php?page=cache-control',
					'active' => ( 'cache-control' == $currentScreen ) ? true : false,
				);
			}
		}
		return $subPages;
	}

	/**
	 * Force Re-sync after Child Site settings have been saved.
	 *
	 * @param mixed $website website data.
	 */
	public function cache_control_settings_sync( $website ) {
		$website = MainWP_DB::instance()->get_website_by_id( $website->id );

		return MainWP_Sync::sync_website( $website, $pForceFetch = true );
	}

	/**
	 * Send data to Child Site on Sync.
	 *
	 * @param array $data Array of data to send.
	 * @param array $website Array of previously saved Child Site data.
	 * @return array|mixed
	 */
	public function cache_control_sync_others_data( $data, $website = null ) {

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$site_options = MainWP_DB::instance()->get_website_options_array( $website, array( 'auto_purge_cache', 'mainwp_cloudflair_key', 'mainwp_cloudflair_email', 'mainwp_cache_override_global_settings', 'mainwp_use_cloudflair_cache' ) );

		$auto_purge_cache                    = isset( $site_options['auto_purge_cache'] ) ? $site_options['auto_purge_cache'] : 2;
		$site_use_cloudflair_cache           = isset( $site_options['mainwp_use_cloudflair_cache'] ) ? $site_options['mainwp_use_cloudflair_cache'] : 0;
		$site_cache_override_global_settings = isset( $site_options['mainwp_cache_override_global_settings'] ) ? $site_options['mainwp_cache_override_global_settings'] : 0;
		$site_cloudflair_key                 = isset( $site_options['mainwp_cloudflair_key'] ) ? $site_options['mainwp_cloudflair_key'] : '';
		$site_cloudflair_email               = isset( $site_options['mainwp_cloudflair_email'] ) ? $site_options['mainwp_cloudflair_email'] : '';

		// Whether to purge child site or not. Sets 'mainwp_child_auto_purge_cache' option.
		if ( 2 === $auto_purge_cache ) {
			$data['auto_purge_cache'] = get_option( 'mainwp_auto_purge_cache' );
		} else {
			$data['auto_purge_cache'] = $auto_purge_cache;
		}

		// Cloudflair settings.
		if ( '1' == get_option( 'mainwp_use_cloudflair_cache' ) && '0' == $site_use_cloudflair_cache ) {
			$data['cloud_flair_enabled']     = true;
			$data['mainwp_cloudflair_key']   = get_option( 'mainwp_cloudflair_key' );
			$data['mainwp_cloudflair_email'] = get_option( 'mainwp_cloudflair_email' );
		} elseif ( '1' == $site_cache_override_global_settings && '0' == get_option( 'mainwp_use_cloudflair_cache' ) || '1' == $site_cache_override_global_settings && '1' == get_option( 'mainwp_use_cloudflair_cache' ) ) {
			$data['cloud_flair_enabled']     = true;
			$data['mainwp_cloudflair_key']   = $site_cloudflair_key;
			$data['mainwp_cloudflair_email'] = $site_cloudflair_email;
		} elseif ( '0' == $site_cache_override_global_settings && '0' == get_option( 'mainwp_use_cloudflair_cache' ) ) {
			$data['cloud_flair_enabled'] = false;
		}

		return $data;
	}

	/**
	 * Grab and update stored Child Site Data.
	 *
	 * @param array $website  Array of previously saved Child Site data.
	 * @param array $information Array of data sent from Child Site.
	 *
	 * @uses  MainWP_DB::instance()->update_website_option() to update the xx_mainwp_wp_options table.
	 */
	public function upgrade_wp_success( $website, $information = array() ) {
		$this->synced_site( $website, $information );
	}

		/**
		 * Grab and update stored Child Site Data.
		 *
		 * @param array  $information Array of data sent from Child Site.
		 * @param string $type  type data.
		 * @param string $list  list data.
		 * @param array  $website  Array of previously saved Child Site data.
		 *
		 * @uses  MainWP_DB::instance()->update_website_option() to update the xx_mainwp_wp_options table.
		 */
	public function update_plugin_theme_success( $information, $type, $list, $website = null ) {
		$this->synced_site( $website, $information );
	}

	/**
	 * Grab data via sync_others_data() from Child Site when synced
	 * and update stored Child Site Data.
	 *
	 * @param array $website  Array of previously saved Child Site data.
	 * @param array $information Array of data sent from Child Site.
	 *
	 * @uses  MainWP_DB::instance()->update_website_option() to update the xx_mainwp_wp_options table.
	 */
	public function synced_site( $website, $information = array() ) {
		if ( is_array( $information ) && isset( $information['mainwp_cache_control_last_purged'] ) ) {

			// Grab last purged time.
			$last_purged_cache = $information['mainwp_cache_control_last_purged'];
			MainWP_DB::instance()->update_website_option( $website, 'mainwp_cache_control_last_purged', $last_purged_cache );
			unset( $information['mainwp_cache_control_last_purged'] );

			// Grab Cache Solution.
			$cache_solution = $information['mainwp_cache_control_cache_solution'];
			MainWP_DB::instance()->update_website_option( $website, 'mainwp_cache_control_cache_solution', $cache_solution );
			unset( $information['mainwp_cache_control_cache_solution'] );

			// Grab Cache Control Log.
			$cache_control_log = $information['mainwp_cache_control_logs'];
			MainWP_DB::instance()->update_website_option( $website, 'mainwp_cache_control_logs', $cache_control_log );
			unset( $information['mainwp_cache_control_logs'] );

		}
	}

	/**
	 * Handle Cache Control form $_POST.
	 *
	 * This method grabs $_POST data and updates the form field options within the DB.
	 *
	 * @uses MainWP_Utility::update_option() to update the xx_options table.
	 *
	 * @return bool
	 */
	public function handle_cache_control_post() {
		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'cache-control' ) ) {

			$auto_cache_purge = ( isset( $_POST['mainwp_auto_purge_cache'] ) ? 1 : 0 );
			MainWP_Utility::update_option( 'mainwp_auto_purge_cache', $auto_cache_purge );

			$mainwp_use_cloudflair_cache = ( isset( $_POST['mainwp_use_cloudflair_cache'] ) ? 1 : 0 );
			MainWP_Utility::update_option( 'mainwp_use_cloudflair_cache', $mainwp_use_cloudflair_cache );

			$mainwp_cloudflair_email = ( isset( $_POST['mainwp_cloudflair_email'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_cloudflair_email'] ) ) : '' );
			MainWP_Utility::update_option( 'mainwp_cloudflair_email', $mainwp_cloudflair_email );

			$mainwp_cloudflair_key = ( isset( $_POST['mainwp_cloudflair_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_cloudflair_key'] ) ) : '' );
			MainWP_Utility::update_option( 'mainwp_cloudflair_key', $mainwp_cloudflair_key );

			return true;
		}
		return false;
	}

	/**
	 * Handle Cache Control form $_POST for Child Site edit page.
	 *
	 * @param mixed $website Website information.
	 */
	public function handle_cache_control_child_site_settings( $website ) {

		$updated = false;
		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'cache-control' ) ) {

			if ( mainwp_current_user_have_right( 'dashboard', 'edit_sites' ) ) {

				// Handle $auto_purge_cache variable.
				$auto_purge_cache = isset( $_POST['mainwp_auto_purge_cache'] ) ? intval( $_POST['mainwp_auto_purge_cache'] ) : 2;
				if ( 2 < $auto_purge_cache ) {
					$auto_purge_cache = 2;
				}

				// Override Global Settings option.
				$mainwp_cache_override_global_settings = ( isset( $_POST['mainwp_cache_override_global_settings'] ) ? 1 : 0 );

				// Cloudflair API Credentials for Child Site.
				$mainwp_cloudflair_email = ( isset( $_POST['mainwp_cloudflair_email'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_cloudflair_email'] ) ) : '' );
				$mainwp_cloudflair_key   = ( isset( $_POST['mainwp_cloudflair_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_cloudflair_key'] ) ) : '' );

				MainWP_DB::instance()->update_website_option( $website, 'auto_purge_cache', $auto_purge_cache );
				MainWP_DB::instance()->update_website_option( $website, 'mainwp_cache_override_global_settings', $mainwp_cache_override_global_settings );
				MainWP_DB::instance()->update_website_option( $website, 'mainwp_cloudflair_email', $mainwp_cloudflair_email );
				MainWP_DB::instance()->update_website_option( $website, 'mainwp_cloudflair_key', $mainwp_cloudflair_key );

				// Force Re-sync Child Site Data.
				self::instance()->cache_control_settings_sync( $website );

				$updated = true;
			}
		}
		return $updated;
	}


	/**
	 * Add Cache Control columns to Site Table.
	 *
	 * @param array $columns Columns information.
	 */
	public function cache_control_sitestable_column( $columns ) {
		$columns['mainwp_cache_control_last_purged'] = 'Last Purged Cache';
		$columns['cache_solution']                   = 'Cache Solution';
		return $columns;
	}

	/**
	 * Display Cache Control data for specified Child Site.
	 *
	 *  @param mixed $item Site information.
	 */
	public function cache_control_sitestable_item( $item ) {
		$site_options   = MainWP_DB::instance()->get_website_options_array( $item, array( 'mainwp_cache_control_last_purged', 'mainwp_cache_control_cache_solution' ) );
		$last_purged    = isset( $site_options['mainwp_cache_control_last_purged'] ) ? $site_options['mainwp_cache_control_last_purged'] : 0;
		$cache_solution = isset( $site_options['mainwp_cache_control_cache_solution'] ) ? $site_options['mainwp_cache_control_cache_solution'] : '';

		// Display Last Purged Cache timestamp.
		if ( ! empty( $last_purged ) ) {

			// Grab UTC Timestamp and convert to local time.
			$utc_timestamp = $last_purged;

			// This is a format that date_create() will accept.
			$utc_timestamp_converted = date( 'Y-m-d H:i:s', $utc_timestamp );

			// Format our output.
			$output_format = 'F j, Y g:ia';

			// Now we can use our timestamp with get_date_from_gmt().
			$local_timestamp = get_date_from_gmt( $utc_timestamp_converted, $output_format );

			// Save local timestamp.
			$item['mainwp_cache_control_last_purged'] = $local_timestamp;
		} else {
			$item['mainwp_cache_control_last_purged'] = 'Never Purged';
		}

		// Check if CloudFlare has been enabled & display correctly.
		if ( ! empty( $cache_solution ) && 'Cloudflare' !== $cache_solution ) {
			$item['cache_solution'] = $cache_solution;
		} elseif ( 'Cloudflare' == $cache_solution ) {
			$item['cache_solution'] = 'Cloudflare';
		} else {
			$item['cache_solution'] = 'N/A';
		}

		return $item;
	}

	/**
	 * Cache Control Log Items.
	 */
	public static function cache_control_log_item() {

		$sql      = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'mainwp_cache_control_logs' ) );
		$websites = MainWP_DB::instance()->query( $sql );
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			$mainwp_cache_control_logs = MainWP_DB::instance()->get_website_option( $website, 'mainwp_cache_control_logs' );
			?>
			<tr>
				<td>
					<a href="<?php echo 'admin.php?page=managesites&dashboard=' . $website->id; ?>" data-tooltip="<?php esc_attr_e( 'Open the site overview', 'mainwp' ); ?>" data-position="right center"  data-inverted=""><?php echo stripslashes( $website->name ); ?></a>
				</td>
				<td>
					<?php if ( ! mainwp_current_user_have_right( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
						<i class="sign in icon"></i>
					<?php else : ?>
						<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website->id; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="right center"  data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
					<?php endif; ?>
				</td>
				<td><?php echo $mainwp_cache_control_logs; ?></td>
			</tr>
			<?php

		}
	}

	/**
	 * Render Global Cache Control settings.
	 *
	 * @param bool $updated Updated or not.
	 */
	public static function render_global_settings( $updated ) {
		if ( ! mainwp_current_user_have_right( 'admin', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );
			return;
		}

		?>
			<div id="mainwp-cache-control-settings" class="ui segment">
				<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-general-cache-control-info-message' ) ) : ?>
					<div class="ui info message">
						<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-general-cache-control-info-message"></i>
						<div><?php echo __( 'MainWP Cache Control allows you to automatically purge the Cache on your child sites after performing an update of WP Core, Theme, or a Plugin through the MainWP Dashboard.', 'mainwp' ); ?></div>
						<p><?php echo __( 'MainWP Cache Control will NOT purge both Cloudflare Cache and Cache from one of the supported plugins. If MainWP detects that a Child site uses one of the supported Plugins, only its Cache will be automatically purged. If a supported Caching plugin is not detected by MainWP, then the Cloudflare cache will be purged.', 'mainwp' ); ?></p>
						<p><?php echo sprintf( __( 'For additional help, review this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/cache-control/" target="_blank">', '</a>' ); ?></p>
					</div>
				<?php endif; ?>
				<?php if ( $updated ) : ?>
					<div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
				<?php endif; ?>
				<h3 class="ui dividing header"><?php esc_html_e( 'Cache Control Settings', 'mainwp' ); ?>
				<div class="sub header"><?php echo sprintf( __( 'See the list of supported %1$scaching systems%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/cache-control/" target="_blank">', '</a>' ); ?> <?php esc_html_e( 'You must Sync your MainWP Dashboard with Child Sites after saving these settings.', 'mainwp' ); ?></div></h3>
				<div class="ui form">
					<form method="POST" action="admin.php?page=cache-control">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'cache-control' ); ?>" />
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php echo __( 'Automatically purge cache', 'mainwp' ); ?></label>
							<div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable to purge all cache after updates.', 'mainwp' ); ?>" data-position="top left" data-inverted="">
								<input type="checkbox" value="1" name="mainwp_auto_purge_cache" <?php checked( get_option( 'mainwp_auto_purge_cache', 0 ), 1 ); ?> id="mainwp_auto_purge_cache">
							</div>
						</div>
						<div class="ui divider"></div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php echo __( 'Use Cloudflare Cache API.', 'mainwp' ); ?></label>
							<div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable to use global Cloudflare API.', 'mainwp' ); ?>" data-position="top left" data-inverted="">
								<input type="checkbox" value="1" name="mainwp_use_cloudflair_cache" <?php checked( get_option( 'mainwp_use_cloudflair_cache', 0 ), 1 ); ?> id="mainwp_use_cloudflair_cache">
							</div>
						</div>
						<h4 class="ui header"><?php esc_html_e( 'Cloudflare API Credentials', 'mainwp' ); ?>
							<div class="sub header"><?php esc_html_e( 'Credentials for global Cloudflare account.', 'mainwp' ); ?></div></h4>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php echo __( 'Cloudflare API Email', 'mainwp' ); ?></label>
							<div class="ten wide column ui" data-tooltip="<?php esc_attr_e( 'Enter your Cloudflare API email address.', 'mainwp' ); ?>" data-position="top left" data-inverted="">
								<input type="text"  name="mainwp_cloudflair_email" placeholder="user@domain.tdl" value="<?php echo esc_attr( get_option( 'mainwp_cloudflair_email' ) ); ?>" autocomplete="off">
							</div>
							<label class="six wide column middle aligned"><?php echo __( 'Cloudflare API Key', 'mainwp' ); ?></label>
							<div class="ten wide column ui" data-tooltip="<?php esc_attr_e( 'Enter your Cloudflare API Key.', 'mainwp' ); ?>" data-position="top left" data-inverted="">
								<input type="text"  name="mainwp_cloudflair_key" placeholder="eg: 55160fc7127bf21e139a52d3a005a62fec798 " value="<?php echo esc_attr( get_option( 'mainwp_cloudflair_key' ) ); ?>" autocomplete="off">
								<label><em><?php echo __( 'Retrieved from the backend after logging in', 'mainwp' ); ?></em></label>
							</div>
						</div>
						<div class="ui divider"></div>
						<input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
						<div style="clear:both"></div>
					</form>
				</div>
			</div>
		<?php
	}

	/**
	 * Render Child Site ( edit page ) Cache Control settings.
	 *
	 * @param int  $websiteid Website id.
	 * @param bool $updated Updated or not.
	 */
	public static function render_child_site_settings( $websiteid, $updated ) {
		MainWP_Manage_Sites::render_header( 'cache-control' );
		if ( ! mainwp_current_user_have_right( 'admin', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );
			return;
		}

		// Grab updated Child Site object.
		$website                             = MainWP_DB::instance()->get_website_by_id( $websiteid );
		$site_options                        = MainWP_DB::instance()->get_website_options_array( $website, array( 'auto_purge_cache', 'mainwp_cloudflair_key', 'mainwp_cloudflair_email', 'mainwp_cache_override_global_settings', 'mainwp_use_cloudflair_cache' ) );
		$auto_purge_cache                    = isset( $site_options['auto_purge_cache'] ) ? $site_options['auto_purge_cache'] : 2;
		$site_use_cloudflair_cache           = isset( $site_options['mainwp_use_cloudflair_cache'] ) ? $site_options['mainwp_use_cloudflair_cache'] : 0;
		$site_cache_override_global_settings = isset( $site_options['mainwp_cache_override_global_settings'] ) ? $site_options['mainwp_cache_override_global_settings'] : 0;
		$site_cloudflair_key                 = isset( $site_options['mainwp_cloudflair_key'] ) ? $site_options['mainwp_cloudflair_key'] : '';
		$site_cloudflair_email               = isset( $site_options['mainwp_cloudflair_email'] ) ? $site_options['mainwp_cloudflair_email'] : '';

		?>
		<div id="mainwp-cache-control-settings" class="ui segment">
			<?php if ( $updated ) : ?>
				<div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
			<?php endif; ?>

			<h3 class="ui dividing header"><?php esc_html_e( 'Cache Control Settings', 'mainwp' ); ?></h3>
			<div class="ui form">
				<form method="POST" action="admin.php?page=managesites&cacheControlId=<?php echo $website->id; ?>" >
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'cache-control' ); ?>" />
					<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Automatically purge cache', 'mainwp' ); ?></label>
						<div class="ui six wide column">
							<select class="ui dropdown" id="mainwp_auto_purge_cache" name="mainwp_auto_purge_cache">
								<option <?php echo ( 1 == $auto_purge_cache ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'Yes', 'mainwp' ); ?></option>
								<option <?php echo ( 0 == $auto_purge_cache ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'No', 'mainwp' ); ?></option>
								<option <?php echo ( 2 == $auto_purge_cache ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
							</select>
							<label><em><?php echo __( 'Enable to purge all cache after updates.', 'mainwp' ); ?></em></label>
						</div>
					</div>
					<div class="ui divider"></div>
					<div class="ui grid field">
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php echo __( 'Use Cloudflair Cache API', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox">
							<input type="checkbox" value="1" name="mainwp_cache_override_global_settings" <?php checked( $site_cache_override_global_settings, 1 ); ?> id="mainwp_cache_override_global_settings">
							<label><em><?php echo __( 'Enable to override global Cloudflare API Key', 'mainwp' ); ?></em></label>
						</div>
					</div>
					<h4 class="ui header"><?php esc_html_e( 'Cloudflare API Credentials', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'Credentials for Individual Child Site.', 'mainwp' ); ?></div></h4>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php echo __( 'Cloudflare API Email', 'mainwp' ); ?></label>
						<div class="ten wide column ui">
							<input type="text"  name="mainwp_cloudflair_email" placeholder="user@domain.tdl" value="<?php echo esc_attr( $site_cloudflair_email ); ?>" autocomplete="off">
						</div>
						<label class="six wide column middle aligned"><?php echo __( 'Cloudflare API Key', 'mainwp' ); ?></label>
						<div class="ten wide column ui">
							<input type="text"  name="mainwp_cloudflair_key" placeholder="eg: 55160fc7127bf21e139a52d3a005a62fec798 " value="<?php echo esc_attr( $site_cloudflair_key ); ?>" autocomplete="off">
							<label><em><?php echo __( 'Retrieved from the backend after logging in', 'mainwp' ); ?></em></label>
						</div>
					</div>
					<div class="ui divider"></div>
					<input type="submit" name="submit" id="submit" class="ui green big button right floated" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
					<div style="clear:both"></div>
				</form>
			</div>
		</div>
		<?php
		MainWP_Manage_Sites::render_footer( 'cache-control' );
	}

	/**
	 * Renders Cache Control Log page.
	 */
	public static function render_cache_control_log_page() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'see_server_information' ) ) {
			mainwp_do_not_have_permissions( 'error log', 'mainwp' );
			return;
		}

		/**
		 * Action: mainwp_before_error_log_table
		 *
		 * Fires before the Error Log table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_cache_control_log_table' );
		?>
		<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-cache-control-log-info-message' ) ) : ?>
			<div class="ui info message">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-cache-control-log-info-message"></i>
				<?php echo __( 'See the Cache Control feature logs.', 'mainwp' ); ?>
			</div>
		<?php endif; ?>
		<table class="ui single line table" id="mainwp-cache-control-log-table">
			<thead>
			<tr>
					<th class="collapsing"><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
					<th class="no-sort collapsing"><i class="sign in icon"></i></th>
				<th><?php esc_html_e( 'Log', 'mainwp' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php self::cache_control_log_item(); ?>
			</tbody>
		</table>

		<script type="text/javascript">
			jQuery( '#mainwp-cache-control-log-table' ).DataTable( {
				"columnDefs": [ {
					"targets": 'no-sort',
					"orderable": false
				} ],
			} );
		</script>
		<?php
		/**
		 * Action: mainwp_after_error_log_table
		 *
		 * Fires after the Error Log table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_cache_control_log_table' );
	}
}
