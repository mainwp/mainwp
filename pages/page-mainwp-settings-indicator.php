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
     * Method render_indicator().
     *
     * @param string $indi_type Indicator type.
     * @param string $wrapper_cls field wrapper class.
     * @param bool   $visible Current indicator status.
     */
    public static function render_indicator( $indi_type = 'field', $wrapper_cls = '', $visible = true ) {
        echo static::get_indicator( $indi_type, $wrapper_cls, $visible );  //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    /**
     * Method get_indicator().
     *
     * @param bool $indi_type Indicator type.
     * @param bool $wrapper_cls field wrapper class.
     * @param bool $visible Current indicator status.
     */
    public static function get_indicator( $indi_type = 'field', $wrapper_cls = '', $visible = true ) {
        $cls = $visible ? 'visible-indicator' : '';
        if ( 'header' === $indi_type ) {
            return '<i style="display:none;" field-indicator-wrapper-class="' . esc_html( $wrapper_cls ) . '" class="ui circle icon tiny yellow settings-field-header-indicator ' . $cls . ' "></i>';
        } else {
            return '<i class="ui circle icon tiny yellow settings-field-icon-indicator ' . $cls . '"></i>';
        }
    }

    /**
     * Method render_not_default_indicator().
     *
     * @param string $field setting field to check.
     * @param mixed  $current_value setting current value.
     * @param bool   $render_indi to render indication.
     * @param mixed  $default_val default value directly.
     */
    public static function render_not_default_indicator( $field, $current_value, $render_indi = true, $default_val = null ) {
        $indi_value = static::get_defaults_value( $field );

        if ( null !== $default_val ) {
            $indi_value = $default_val;
        }

        $visible = false;
        if ( ( 'none_preset_value' !== $field && $current_value !== $indi_value ) || ( 'none_preset_value' === $field && ! empty( $current_value ) ) ) {
            $visible = true;
        }
        $indi = static::get_indicator( 'field', '', $visible );
        $indi = apply_filters( 'mainwp_default_settings_indicator', $indi, $field, $indi_value, $current_value, $render_indi, $default_val );
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
        $def     = static::get_defaults_email_settings_value( $type, $field, $general );
        $visible = false;
        if ( null !== $def && $current_value !== $def ) {
            $visible = true;
        }
        $indi = static::get_indicator( 'field', '', $visible );
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
            'date_format'                                => 'F j, Y',
            'time_format'                                => 'g:i a',
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
            'mainwp_backup_before_upgrade'               => 0,
            'mainwp_backup_before_upgrade_days'          => 7,
            'mainwp_numberdays_Outdate_Plugin_Theme'     => 365,
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
            'mainwp_maximum_uptime_monitoring_requests'  => 10,
            'mainwp_optimize'                            => 1,
            'mainwp_wp_cron'                             => 1,
            'mainwp_sslVerifyCertificate'                => 1,
            'mainwp_verify_connection_method'            => 1,
            'mainwp_connect_signature_algo'              => defined( 'OPENSSL_ALGO_SHA256' ) ? (int) OPENSSL_ALGO_SHA256 : 1,
            'mainwp_forceUseIPv4'                        => 0,
            'mainwp_module_log_enabled'                  => 1,
            'mainwp_selected_theme'                      => 'default',
            'mainwp_enable_guided_tours'                 => 0,
            'mainwp_enable_guided_video'                 => 0,
            'mainwp_enable_guided_chatbase'              => 0,
            'mainwp_site_backup_before_upgrade'          => 2,
            'mainwp_site_suspended_site'                 => 0,
            'mainwp_site_automatic_update'               => 0,
            'mainwp_site_is_ignoreCoreUpdates'           => 0,
            'mainwp_site_is_ignorePluginUpdates'         => 0,
            'mainwp_site_is_ignoreThemeUpdates'          => 0,
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
            'mainwp_frequency_AutoUpdate'                => 'daily',
            'mainwp_dayinweek_AutoUpdate'                => 0,
            'mainwp_dayinmonth_AutoUpdate'               => 1,
            'mainwp_time_AutoUpdate'                     => '00:00',
            'mainwp_edit_monitor_up_status_codes'        => 'useglobal',
            'mainwp_edit_monitor_maxretries'             => -1, // use global.
            'mainwp_edit_monitor_maxretries_global'      => 1,
            'mainwp_edit_monitor_monitoring_emails'      => '',
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
}
