<?php
/**
 * Centralized manager for WordPress backend functionality.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Utility;

defined( 'ABSPATH' ) || exit;

/**
 * Class - Log_Settings
 */
class Log_Settings {

	/**
	 * Holds Instance of manager object
	 *
	 * @var Log_manager
	 */
	public $manager;

	/**
	 * Holds settings values.
	 *
	 * @var options
	 */
	public $options;

	/**
	 * Current page.
	 *
	 * @static
	 * @var string $page Current page.
	 */
	public static $page;


	/**
	 * Class constructor.
	 *
	 * @param Log_Manager $manager Instance of manager object.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;

		$this->options = get_option( 'mainwp_module_log_settings', array() );
		if ( ! is_array( $this->options ) ) {
			$this->options = array();
		}
		if ( ! isset( $this->options['enabled'] ) ) {
			$this->options['enabled'] = 1;
			MainWP_Utility::update_option( 'mainwp_module_log_settings', $this->options );
		}

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_filter( 'mainwp_getsubpages_settings', array( $this, 'add_subpage_menu_settings' ) );
		add_filter( 'mainwp_init_primary_menu_items', array( $this, 'hook_init_primary_menu_items' ), 10, 2 );
	}


	/**
	 * Handle admin_init action.
	 */
	public function admin_init() {
		//phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['mainwp_module_log_settings_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['mainwp_module_log_settings_nonce'] ), 'logs_settings_nonce' ) ) {
			$this->options['enabled']     = isset( $_POST['mainwp_module_log_enabled'] ) && ! empty( $_POST['mainwp_module_log_enabled'] ) ? 1 : 0;
			$this->options['auto_purge']  = isset( $_POST['mainwp_module_log_enable_auto_purge'] ) && ! empty( $_POST['mainwp_module_log_enable_auto_purge'] ) ? 1 : 0;
			$this->options['records_ttl'] = isset( $_POST['mainwp_module_log_records_ttl'] ) ? intval( $_POST['mainwp_module_log_records_ttl'] ) : 100;
			MainWP_Utility::update_option( 'mainwp_module_log_settings', $this->options );
		}
	}

	/**
	 * Init sub menu logs settings.
	 *
	 * @param array $subpages Sub pages.
	 *
	 * @action init
	 */
	public function add_subpage_menu_settings( $subpages = array() ) {
		$subpages[] = array(
			'title'    => esc_html__( 'Dashboard Insights', 'mainwp' ),
			'slug'     => 'Insights',
			'callback' => array( $this, 'render_settings_page' ),
			'class'    => '',
		);
		return $subpages;
	}

	/**
	 * Init sub menu logs settings.
	 *
	 * @param array  $items Sub menu items.
	 * @param string $which_menu first|second.
	 *
	 * @return array $tmp_items Menu items.
	 */
	public function hook_init_primary_menu_items( $items, $which_menu ) {
		if ( ! is_array( $items ) || 'first' !== $which_menu ) {
			return $items;
		}
		$items[] = array(
			'slug'               => 'InsightsOverview',
			'menu_level'         => 2,
			'menu_rights'        => array(
				'dashboard' => array(
					'access_insights_dashboard',
				),
			),
			'init_menu_callback' => array( self::class, 'init_menu' ),
			'leftbar_order'      => 2.9,
		);
		return $items;
	}

	/**
	 * Method init_menu()
	 *
	 * Add Insights Overview sub menu "Insights".
	 */
	public static function init_menu() {

		self::$page = add_submenu_page(
			'mainwp_tab',
			esc_html__( 'Insights', 'mainwp' ),
			'<span id="mainwp-insights">' . esc_html__( 'Insights', 'mainwp' ) . '</span>',
			'read',
			'InsightsOverview',
			array(
				Log_Insights_Page::instance(),
				'render_insights_overview',
			)
		);

		Log_Insights_Page::init_left_menu();

		if ( isset( $_GET['page'] ) && 'InsightsOverview' === $_GET['page'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'mainwp_enqueue_script_gridster', '__return_true' );
		}

		add_action( 'load-' . self::$page, array( self::class, 'on_load_page' ) );
	}

	/**
	 * Method on_load_page()
	 *
	 * Run on page load.
	 */
	public static function on_load_page() {
		Log_Insights_Page::instance()->on_load_page( self::$page );
	}

	/**
	 * Render Insights settings page.
	 */
	public function render_settings_page() {
		/** This action is documented in ../pages/page-mainwp-manage-sites.php */
		do_action( 'mainwp_pageheader_settings', 'Insights' );
		$enabled = ! empty( $this->options['enabled'] ) ? true : false;

		$enabled_auto_purge = isset( $this->options['auto_purge'] ) && ! empty( $this->options['auto_purge'] ) ? true : false;
		?>
		<div id="mainwp-module-log-settings-wrapper" class="ui segment">
			<div class="ui info message">
				<div><?php esc_html_e( 'Dashboard Insights is a feature that will provide you with analytics data about your MainWP Dashboard usage. This version of the MainWP Dashboard contains only the logging part of this feature, which only logs actions performed in the MainWP Dashboard. Once the feature is fully completed, a new version will be released, and the logged data will be available.', 'mainwp' ); ?></div>
				<div><?php esc_html_e( 'Important Note: Collected data stays on your server, and it will never be sent to MainWP servers or 3rd party. Logged data will only be used by you for informative purposes.', 'mainwp' ); ?></div>
			</div>
			<div class="ui form">
				<form method="post" class="mainwp-table-container">
					<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
						<h3 class="ui dividing header"><?php esc_html_e( 'Dashboard Insights Settings', 'mainwp' ); ?></h3>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Enable insights logging', 'mainwp' ); ?></label>
							<div class="ten wide column ui toggle checkbox"  data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will enable logging.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
								<input type="checkbox" name="mainwp_module_log_enabled" id="mainwp_module_log_enabled" <?php echo ( $enabled ? 'checked="true"' : '' ); ?> /><label><?php esc_html_e( 'Default: Enabled', 'mainwp' ); ?></label>
							</div>
						</div>
						<?php $hide_field_class = 'log-settings-hidden-field'; ?>
						<div class="ui grid field <?php echo esc_attr( $hide_field_class ); ?>">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Enable auto purge', 'mainwp' ); ?></label>
							<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements"  hide-parent="auto-purge" data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will auto purge logs.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
								<input type="checkbox" name="mainwp_module_log_enable_auto_purge" id="mainwp_module_log_enable_auto_purge" <?php echo ( $enabled_auto_purge ? 'checked="true"' : '' ); ?> /><label><?php esc_html_e( 'Default: Off', 'mainwp' ); ?></label>
							</div>
						</div>
						<div class="ui grid field <?php echo esc_attr( $hide_field_class ); ?>" <?php echo $enabled ? '' : 'style="display:none"'; ?> hide-element="auto-purge">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Keep records for', 'mainwp' ); ?></label>
							<div class="ten wide column ui" data-tooltip="<?php esc_attr_e( 'Maximum number of days to keep activity records.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
								<input type="number" name="mainwp_module_log_records_ttl" id="mainwp_module_log_records_ttl" class="small-text" placeholder="" min="1" max="999" step="1" value="<?php echo isset( $this->options['records_ttl'] ) ? intval( $this->options['records_ttl'] ) : 100; ?>">
							</div>
						</div>
						<h3 class="ui dividing header <?php echo esc_attr( $hide_field_class ); ?>"><?php esc_html_e( 'Dashboard Insights Tools', 'mainwp' ); ?></h3>					
						<div class="ui grid field <?php echo esc_attr( $hide_field_class ); ?>">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Delete records', 'mainwp' ); ?></label>
							<div class="ten wide column ui">
								<div class="three fields">
									<div class="field">
										<div class="ui calendar mainwp_datepicker" >
											<div class="ui input left icon">
												<i class="calendar icon"></i>
												<input type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'Start Date', 'mainwp' ); ?>" id="log_delete_records_startdate" value=""/>
											</div>
										</div>
									</div>
									<div class="field">
										<div class="ui calendar mainwp_datepicker" >
											<div class="ui input left icon">
												<i class="calendar icon"></i>
												<input type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'End Date', 'mainwp' ); ?>" id="log_delete_records_enddate" value=""/>
											</div>
										</div>
									</div>
									<div class="field">
										<input type="button" id="logs_delete_records_button" class="ui button" value="<?php esc_html_e( 'Delete', 'mainwp' ); ?>">
									</div>
								</div>
							</div>
						</div>

						<div class="ui grid field <?php echo esc_attr( $hide_field_class ); ?>">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Compact insights data', 'mainwp' ); ?></label>
							<div class="ten wide column ui">
								<div class="ui selection dropdown" id="mainwp_module_log_compact_year" init-value="0">
									<input name="mainwp_module_log_compact_year" value="0" type="hidden">
									<i class="dropdown icon"></i>
									<div class="default text"><?php esc_html_e( 'Select year', 'mainwp' ); ?></div>
									<div class="menu">
										<?php
										$first = 2022;
										$last  = gmdate( 'Y' );
										?>
										<?php
										for ( $y = $last; $y >= $first; $y-- ) {
											?>
											<div class="item" data-value="<?php echo intval( $y ); ?>"><?php echo esc_html( $y ); ?></div>
										<?php } ?>
									</div>
								</div>
								<input type="button" id="logs_compact_records_button" class="ui button" value="<?php esc_html_e( 'Compact', 'mainwp' ); ?>">
							</div>
						</div>
						<div class="ui divider"></div>
						<input type="submit" name="submit" id="submit" class="ui button green big" value="<?php esc_html_e( 'Save Settings', 'mainwp' ); ?>">
						<input type="hidden" name="mainwp_module_log_settings_nonce" value="<?php echo esc_attr( wp_create_nonce( 'logs_settings_nonce' ) ); ?>">
				</div>
			</form>
		</div>		

		<?php
		/** This action is documented in ../pages/page-mainwp-manage-sites.php */
		do_action( 'mainwp_pagefooter_settings', 'Insights' );
	}
}
