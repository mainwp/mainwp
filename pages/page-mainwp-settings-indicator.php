<?php
/**
 * MainWP Settings Indicator
 *
 * @package MainWP/Settings
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Settings_Indicator
 *
 * @package MainWP\Dashboard
 */
class MainWP_Settings_Indicator { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.


	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Public static varable to hold Subpages information.
	 *
	 * @var array $subPages
	 */
	public static $saved_indicator_status = null;


	/**
	 * Return the single instance of the class.
	 *
	 * @return mixed $instance The single instance of the class.
	 */
	public static function get_instance() {
		if ( is_null( static::$instance ) ) {
			static::$instance = new self();
		}
		return static::$instance;
	}


	/**
	 * Method get_indicator().
	 *
	 * @param bool $what header indicator.
	 * @param bool $field_indi_wrapper_cls field wrapper class.
	 * @param bool $attr_indi menu/header indicator attribute.
	 * @param bool $current_active Current menu indicator is active or not.
	 */
	public static function get_indicator( $what = 'field', $field_indi_wrapper_cls = '', $attr_indi = '', $current_active = false ) {
		if ( 'header' === $what ) {
			return '<i style="display:none;" indicator-status="hide" menu-indicator="' . esc_attr( $attr_indi ) . '" field-indicator-wrapper-class="' . esc_html( $field_indi_wrapper_cls ) . '" class="ui circle icon tiny yellow settings-field-header-indicator"></i>';
		} elseif ( 'menu' === $what ) {
			return '<i style="display:none;" indicator-status="hide" menu-indicator-active="' . intval( $current_active ) . '" menu-indicator="' . esc_attr( $attr_indi ) . '" class="ui circle icon tiny yellow settings-field-menu-indicator"></i>';
		} else {
			return '<i style="display:inline-block;" indicator-status="show" class="ui circle icon tiny yellow settings-field-icon-indicator"></i>';
		}
	}

	/**
	 * Method render_not_default_indicator().
	 *
	 * @param string $field setting field to check.
	 * @param mixed  $current_value setting current value.
	 * @param bool   $render_indi to render indication.
	 */
	public static function render_not_default_indicator( $field, $current_value, $render_indi = true ) {
		$def  = static::get_defaults_value( $field );
		$indi = '';
		if ( ( 'none_preset_value' !== $field && $current_value !== $def ) || ( 'none_preset_value' === $field && ! empty( $current_value ) ) ) {
			$indi = static::get_indicator();
		}
		$indi = apply_filters( 'mainwp_default_settings_indicator', $indi, $field, $def, $current_value, $render_indi );
		if ( $render_indi ) {
            echo $indi; //phpcs:ignore -- ok.
		}
		return $indi;
	}

	/**
	 * Method render_not_default_email_settings_indicator().
	 *
	 * @param string $type setting type to get default value.
	 * @param string $field setting field to check.
	 * @param mixed  $current_value setting current value.
	 * @param bool   $general general setting.
	 * @param bool   $render_indi to render indication.
	 */
	public static function render_not_default_email_settings_indicator( $type, $field, $current_value, $general = true, $render_indi = true ) {
		$def  = static::get_defaults_email_settings_value( $type, $field, $general );
		$indi = '';
		if ( null !== $def && $current_value !== $def ) {
			$indi = static::get_indicator();
		}
		$indi = apply_filters( 'mainwp_default_settings_indicator', $indi, $field, $def, $current_value, $render_indi, $type );
		if ( $render_indi ) {
            echo $indi; //phpcs:ignore -- ok.
		}
		return $indi;
	}

	/**
	 * Method get_defaults_value().
	 *
	 * @param string $field setting field to get default value.
	 */
	public static function get_defaults_value( $field = 'all' ) {
		$defauls_currency_settings = is_callable( '\MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility::default_currency_settings' ) ? \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility::default_currency_settings() : array();
		$defaults                  = array(
			'none_preset_value'                          => '',
			'mainwp_frequencyDailyUpdate'                => 2,
			'date_formats'                               => 'F j, Y',
			'time_formats'                               => 'g:i a',
			'mainwp_sidebarPosition'                     => 1,
			'mainwp_hide_update_everything'              => 0,
			'show_default_widgets'                       => 'all',
			'mainwp_pluginAutomaticDailyUpdate'          => 0,
			'mainwp_themeAutomaticDailyUpdate'           => 0,
			'mainwp_automaticDailyUpdate'                => 0,
			'mainwp_delay_autoupdate'                    => 1,
			'mainwp_disable_update_confirmations'        => 0,
			'mainwp_show_language_updates'               => 1,
			'mainwp_check_http_response'                 => 0,
			'mainwp_ignore_HTTP_response_status'         => '',
			'mainwp_backup_before_upgrade'               => 0,
			'mainwp_backup_before_upgrade_days'          => 7,
			'mainwp_numberdays_Outdate_Plugin_Theme'     => 365,
			'mainwp_disableSitesChecking'                => 1,
			'mainwp_disableSitesHealthMonitoring'        => 1,
			'mainwp_sitehealthThreshold'                 => 80,
			'mainwp_enableLegacyBackupFeature'           => 0,
			'mainwp_backupsOnServer'                     => 1,
			'mainwp_backupOnExternalSources'             => 1,
			'mainwp_archiveFormat'                       => 'tar.gz',
			'mainwp_options_loadFilesBeforeZip'          => 1,
			'mainwp_maximumFileDescriptorsAuto'          => 1,
			'mainwp_maximumFileDescriptors'              => 155,
			'mainwp_notificationOnBackupFail'            => 1,
			'mainwp_notificationOnBackupStart'           => 1,
			'mainwp_chunkedBackupTasks'                  => 1,
			'mainwp_maximumRequests'                     => 4,
			'mainwp_minimumDelay'                        => 200,
			'mainwp_maximumIPRequests'                   => 1,
			'mainwp_minimumIPDelay'                      => 1000,
			'mainwp_maximumSyncRequests'                 => 8,
			'mainwp_maximumInstallUpdateRequests'        => 3,
			'mainwp_optimize'                            => 1,
			'mainwp_wp_cron'                             => 1,
			'mainwp_verify_connection_method'            => 1,
			'mainwp_connect_signature_algo'              => defined( 'OPENSSL_ALGO_SHA256' ) ? (int) OPENSSL_ALGO_SHA256 : 1,
			'mainwp_forceUseIPv4'                        => 0,
			'mainwp_module_log_enabled'                  => 1,
			'mainwp_selected_theme'                      => 'default',
			'mainwp_enable_guided_tours'                 => 0,
			'mainwp_site_backup_before_upgrade'          => 2,
			'mainwp_site_suspended_site'                 => 0,
			'mainwp_site_automatic_update'               => 0,
			'mainwp_site_is_ignoreCoreUpdates'           => 0,
			'mainwp_site_is_ignorePluginUpdates'         => 0,
			'mainwp_site_is_ignoreThemeUpdates'          => 0,
			'mainwp_site_status_check_interval'          => 0, // Use global setting.
			'mainwp_site_monitoring_notification_emails' => '',
			'mainwp_site_disable_health_check'           => 0,
			'mainwp_site_verify_certificate'             => 1,
			'mainwp_site_ssl_version'                    => 0,
			'mainwp_site_verify_method'                  => 3,
			'mainwp_site_signature_algo'                 => 9999, // Use global setting.
			'mainwp_site_force_use_ipv4'                 => 2, // Use global setting.
			'mainwp_site_http_user'                      => '',
			'mainwp_site_http_pass'                      => '',
			'mainwp_site_client_id'                      => 0,
			'mainwp_timeDailyUpdate'                     => '',
			'cost_tracker_currency_selected'             => 'USD',
			'cost_tracker_currency_position'             => is_array( $defauls_currency_settings ) && isset( $defauls_currency_settings['currency_position'] ) ? $defauls_currency_settings['currency_position'] : null,
			'cost_tracker_thousand_separator'            => is_array( $defauls_currency_settings ) && isset( $defauls_currency_settings['thousand_separator'] ) ? $defauls_currency_settings['thousand_separator'] : null,
			'cost_tracker_decimal_separator'             => is_array( $defauls_currency_settings ) && isset( $defauls_currency_settings['decimal_separator'] ) ? $defauls_currency_settings['decimal_separator'] : null,
			'cost_tracker_decimals'                      => is_array( $defauls_currency_settings ) && isset( $defauls_currency_settings['decimals'] ) ? (int) $defauls_currency_settings['decimals'] : null,
			'mainwp_frequencySitesChecking'              => 60,
		);

		if ( 'all' === $field ) {
			return $defaults;
		}
		return isset( $defaults[ $field ] ) ? ( $defaults[ $field ] ) : null;
	}


	/**
	 * Method get_defaults_email_settings_value().
	 *
	 * @param string $type setting type to get default value.
	 * @param string $field setting field to get default value.
	 * @param bool   $general general setting.
	 */
	public static function get_defaults_email_settings_value( $type, $field, $general = true ) {
		$recipients = MainWP_System_Utility::get_notification_email();
		$disable    = $general ? 0 : 1;

		$defaults = array(
			'daily_digest'              => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => $general ? 'Daily Digest' : '[site.name] Daily Digest',
				'heading'    => $general ? 'Daily Digest' : '[site.name] Daily Digest',
				'overrided'  => 0,
			),
			'uptime'                    => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => $general ? 'Uptime Monitoring' : '[site.name] Uptime Monitoring',
				'heading'    => $general ? 'Uptime Monitoring' : '[site.name] Uptime Monitoring',
				'overrided'  => 0,
			),
			'site_health'               => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => $general ? 'Site Health Monitoring' : '[site.name] Site Health Monitoring',
				'heading'    => $general ? 'Site Health Monitoring' : '[site.name] Site Health Monitoring',
				'overrided'  => 0,
			),
			'http_check'                => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => 'HTTP response check',
				'heading'    => 'Sites Check',
				'overrided'  => 0,
			),
			'deactivated_license_alert' => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => 'Extension License Deactivation Notification',
				'heading'    => 'Extension License Deactivation Notification',
				'overrided'  => 0,
			),
		);

		$addition_default_fields = apply_filters( 'mainwp_default_emails_fields', array(), $recipients, $type, $field, $general );
		if ( ! empty( $addition_default_fields && is_array( $addition_default_fields ) ) ) {
			$defaults = array_merge( $defaults, $addition_default_fields );
		}

		if ( isset( $defaults[ $type ] ) && 'overrided' === $field ) {
			return 0; // for additions.
		}

		return isset( $defaults[ $type ] ) && isset( $defaults[ $type ][ $field ] ) ? $defaults[ $type ][ $field ] : null;
	}


	/**
	 * Method get_saved_indicator_status().
	 */
	public function get_saved_indicator_status() {

		if ( null === static::$saved_indicator_status ) {
			$values         = get_option( 'mainwp_status_saved_values' );
			$current_values = is_array( $values ) && isset( $values['save_indicator_values'] ) && ! empty( $values['save_indicator_values'] ) ? json_decode( $values['save_indicator_values'], true ) : array();
			if ( ! is_array( $current_values ) ) {
				$current_values = array();
			}
			static::$saved_indicator_status = $current_values;
		}

		return static::$saved_indicator_status;
	}
}
