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
	 *  Called from class-mainwp-system.php line 691.
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
	 * Grab data via sync_others_data() from Child Site when synced
	 * and update stored Child Site Data.
	 *
	 * @param array $website  Array of previously saved Child Site data.
	 * @param array $information Array of data sent from Child Site.
	 */
	public function synced_site( $website, $information = array() ) {
		if ( is_array( $information ) && isset( $information['mainwp_cache_control_last_purged'] ) ) {
			// Grab synced data from child site.
			$last_purged_cache = $information['mainwp_cache_control_last_purged'];
			$cache_solution    = $information['mainwp_cache_control_cache_solution'];
			MainWP_DB::instance()->update_website_option( $website, 'mainwp_cache_control_last_purged', $last_purged_cache );
			MainWP_DB::instance()->update_website_option( $website, 'mainwp_cache_control_cache_solution', $cache_solution );
			unset( $information['mainwp_cache_control_last_purged'] );
			unset( $information['mainwp_cache_control_cache_solution'] );
		}
	}

	/**
	 * Handle Cache Control form $_POST.
	 *
	 * This method runs every time the page is loaded.
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
	 * @param mixed $website Website infor.
	 */
	public function handle_cache_control_child_site_settings( $website ) {

		$updated = false;
		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'cache-control' ) ) {

			if ( mainwp_current_user_have_right( 'dashboard', 'edit_sites' ) ) {

				// Handle $auto_purge_cache variable. 'wp_mainwp_wp'.
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
	 * @param array $columns Columns infor.
	 */
	public function cache_control_sitestable_column( $columns ) {
		$columns['mainwp_cache_control_last_purged'] = 'Last Purged Cache';
		$columns['cache_solution']                   = 'Cache Solution';
		return $columns;
	}

	/**
	 * Display Cache Control data for specified Child Site.
	 *
	 *  @param mixed $item Site infor.
	 */
	public function cache_control_sitestable_item( $item ) {
		$site_options   = MainWP_DB::instance()->get_website_options_array( $item, array( 'mainwp_cache_control_last_purged', 'mainwp_cache_control_cache_solution' ) );
		$last_purged    = isset( $site_options['mainwp_cache_control_last_purged'] ) ? $site_options['mainwp_cache_control_last_purged'] : 0;
		$cache_solution = isset( $site_options['mainwp_cache_control_cache_solution'] ) ? $site_options['mainwp_cache_control_cache_solution'] : '';

		// Display Last Purged Cache timestamp.
		if ( ! empty( $last_purged ) ) {
			$date_time                                = date( 'F j, Y g:ia', $last_purged ); // phpcs:ignore -- date local.
			$item['mainwp_cache_control_last_purged'] = $date_time;
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
				<?php if ( $updated ) : ?>
					<div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
				<?php endif; ?>

				<h3 class="ui dividing header"><?php esc_html_e( 'Cache Control Settings', 'mainwp' ); ?>
				<div class="sub header">You must Sync Dashboard with Child Sites after saving these settings.</div></h3>
				<div class="ui form">
					<form method="POST" action="admin.php?page=cache-control">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'cache-control' ); ?>" />
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php echo __( 'Automatically purge cache', 'mainwp' ); ?></label>
							<div class="ten wide column ui toggle checkbox">
								<input type="checkbox" value="1" name="mainwp_auto_purge_cache" <?php checked( get_option( 'mainwp_auto_purge_cache', 0 ), 1 ); ?> id="mainwp_auto_purge_cache">
								<label><em><?php echo __( 'Enable to purge all cache after updates.', 'mainwp' ); ?></em></label>
							</div>
						</div>
						<div class="ui divider"></div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php echo __( 'Use Cloudflare Cache API.', 'mainwp' ); ?></label>
							<div class="ten wide column ui toggle checkbox">
								<input type="checkbox" value="1" name="mainwp_use_cloudflair_cache" <?php checked( get_option( 'mainwp_use_cloudflair_cache', 0 ), 1 ); ?> id="mainwp_use_cloudflair_cache">
								<label><em><?php echo __( 'Enable to use global CloudFlare API.', 'mainwp' ); ?></em></label>
							</div>
						</div>
						<h4 class="ui header"><?php esc_html_e( 'Cloudflare API Credentials', 'mainwp' ); ?>
							<div class="sub header">Credentials for global Cloudflare account.</div></h4>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php echo __( 'Cloudflare API Email', 'mainwp' ); ?></label>
							<div class="ten wide column ui">
								<input type="text"  name="mainwp_cloudflair_email" placeholder="user@domain.tdl" value="<?php echo esc_attr( get_option( 'mainwp_cloudflair_email' ) ); ?>" autocomplete="off">
							</div>
							<label class="six wide column middle aligned"><?php echo __( 'Cloudflare API Key', 'mainwp' ); ?></label>
							<div class="ten wide column ui">
								<input type="text"  name="mainwp_cloudflair_key" placeholder="eg: 55160fc7127bf21e139a52d3a005a62fec798 " value="<?php echo esc_attr( get_option( 'mainwp_cloudflair_key' ) ); ?>" autocomplete="off">
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
}
