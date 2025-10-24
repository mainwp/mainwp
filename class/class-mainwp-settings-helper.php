<?php
/**
 * Class MainWP_Settings_Helper
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Settings JSON Synchronization Helper.
 *
 * - Global settings stored in one JSON option (mainwp_main_settings)
 * - Each field also may have its own WordPress option
 */
class MainWP_Settings_Helper {  // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Global option name.
     *
     * @var string JSON
     * */
    protected static $global_option_name = 'mainwp_main_settings';

    /**
     * Prevent recursion.
     *
     * @var bool
     * */
    protected static $is_syncing = false;


    /**
     * Default filename inside uploads.
     *
     * @var string
     */
    protected static $backup_filename = 'mainwp_main_settings.json';

    /**
     * Mapping between JSON keys and individual WP options.
     */
    public static function json_settings_mapping_fields() {
        return array(
            'general'            => array(
                'time_daily_update'                  => 'mainwp_timeDailyUpdate',
                'frequency_daily_update'             => 'mainwp_frequency_AutoUpdate',
                'gmt_offset'                         => 'gmt_offset',
                'date_format'                        => 'date_format',
                'time_format'                        => 'time_format',
                'timezone_string'                    => 'timezone_string',
                'dayinweek_auto_update'              => 'mainwp_dayinweek_AutoUpdate',
                'dayinmonth_auto_update'             => 'mainwp_dayinmonth_AutoUpdate',
                'plugin_automatic_daily_update'      => 'mainwp_pluginAutomaticDailyUpdate',
                'theme_automatic_daily_update'       => 'mainwp_themeAutomaticDailyUpdate',
                'translation_automatic_daily_update' => 'mainwp_transAutomaticDailyUpdate',
                'automatic_daily_update'             => 'mainwp_automaticDailyUpdate',
                'frequency_auto_update'              => 'mainwp_frequency_AutoUpdate',
                'time_auto_update'                   => 'mainwp_time_AutoUpdate',
                'delay_auto_update'                  => 'mainwp_delay_autoupdate',
                'show_language_updates'              => 'mainwp_show_language_updates',
                'disable_update_confirmations'       => 'mainwp_disable_update_confirmations',
                'backup_before_upgrade_days'         => 'mainwp_backup_before_upgrade_days',
                'numberdays_outdate_plugin_theme'    => 'mainwp_numberdays_Outdate_Plugin_Theme',
                'check_http_response'                => 'mainwp_check_http_response',
                'disable_sites_health_monitoring'    => 'mainwp_disableSitesHealthMonitoring',
                'site_health_threshold'              => 'mainwp_sitehealthThreshold',
                'hide_update_everything'             => 'mainwp_hide_update_everything',
                'uptime_monitor_settings'            => 'mainwp_global_uptime_monitoring_settings',
                'enable_legacy_backup'               => 'mainwp_enableLegacyBackupFeature',
                'primary_backup'                     => 'mainwp_primaryBackup',
            ),
            'advanced'           => array(
                'maximum_requests'                   => 'mainwp_maximumSyncRequests',
                'minimum_delay'                      => 'mainwp_minimumDelay',
                'maximum_ip_requests'                => 'mainwp_maximumIpRequests',
                'minimum_ip_delay'                   => 'mainwp_minimumIpDelay',
                'chunksites_number'                  => 'mainwp_chunksitesNumber',
                'chunk_sleep_interval'               => 'mainwp_chunkSleepInterval',
                'maximum_sync_requests'              => 'mainwp_maximumSyncRequests',
                'maximum_install_update_requests'    => 'mainwp_maximumInstallUpdateRequests',
                'maximum_uptime_monitoring_requests' => 'mainwp_maximum_uptime_monitoring_requests',
                'optimize'                           => 'mainwp_optimize',
                'warm_cache_pages_ttl'               => 'mainwp_warm_cache_pages_ttl',
                'use_wp_cron'                        => 'mainwp_wp_cron',
                'ssl_verify_certificate'             => 'mainwp_sslVerifyCertificate',
                'force_use_ipv4'                     => 'mainwp_forceUseIPv4',
                'verify_connection_method'           => 'mainwp_verify_connection_method',
                'openssl_alg'                        => 'mainwp_openssl_alg',
                'sync_data'                          => 'mainwp_settings_sync_data',
            ),
            'notifications'      => array(
                'emails_settings' => 'mainwp_settings_notification_emails',
            ),
            'cost_tracker'       => array(
                'cost_settings' => 'mainwp_module_cost_tracker_options',
            ),
            'insights'           => array(
                'insights_settings'       => 'mainwp_module_log_settings',
                'insights_sync_selection' => 'mainwp_module_log_settings_logs_selection_data',
            ),
            'api_backups'        => array(
                'enable_cloudways'    => 'mainwp_enable_cloudways_api',
                'enable_vultr'        => 'mainwp_enable_vultr_api',
                'enable_gridpane'     => 'mainwp_enable_gridpane_api',
                'enable_kinsta'       => 'mainwp_enable_kinsta_api',
                'enable_digitalocean' => 'mainwp_enable_digitalocean_api',
                'enable_cpanel'       => 'mainwp_enable_cpanel_api',
                'enable_plesk'        => 'mainwp_enable_plesk_api',
                'enable_linode'       => 'mainwp_enable_linode_api',
            ),
            'tools'              => array(
                'enable_guided_tours'    => 'mainwp_enable_guided_tours',
                'enable_guided_chatbase' => 'mainwp_enable_guided_chatbase',
                'enable_guided_video'    => 'mainwp_enable_guided_video',
            ),
            '__user_meta_values' => array(
                'show_widgets_overview'             => 'mainwp_settings_show_widgets',
                'show_widgets_clients'              => 'mainwp_clients_show_widgets',
                'show_widgets_costs_tracker'        => 'mainwp_module_cost_tracker_summary_show_widgets',
                'show_widgets_insights'             => 'mainwp_module_log_overview_show_widgets',
                'sidebar_position'                  => 'mainwp_sidebarPosition',
                'widgets_sorted_page_mainwp_tab'    => 'mainwp_widgets_sorted_toplevel_page_mainwp_tab',
                'widgets_sorted_page_managesites'   => 'mainwp_widgets_sorted_mainwp_page_managesites',
                'widgets_sorted_page_manageclients' => 'mainwp_widgets_sorted_mainwp_page_manageclients',
            ),
        );
    }

    /**
     * Convert mapping to flat format: section.key => option_name
     */
    protected static function get_flattened_mapping() {
        $flat = array();
        foreach ( static::json_settings_mapping_fields() as $section => $pairs ) {
            if ( '__user_meta_values' === $section ) {
                // Skip user meta values in this mapping.
                continue;
            }
            foreach ( $pairs as $key => $option ) {
                $flat[ "{$section}.{$key}" ] = $option;
            }
        }
        return $flat;
    }

    /**
     * Sync all mapped individual options into one global JSON setting.
     * Call this after updating any individual option.
     *
     * @param  mixed $export_to_file To export to file.
     * @return void
     */
    public static function sync_individual_to_global( $export_to_file = true ) {

        if ( static::$is_syncing ) {
            return;
        }

        static::$is_syncing = true;

        $flat       = static::get_flattened_mapping();
        $new_global = array();

        foreach ( $flat as $path => $opt_name ) {
            list( $section, $key ) = explode( '.', $path, 2 );
            $value                 = get_option( $opt_name, null );
            if ( null === $value ) {
                continue;
            }
            $new_global[ $section ][ $key ] = $value;
        }

        $user_meta_value = static::get_section_user_meta_values();

        if ( ! empty( $user_meta_value ) ) {
            $new_global['__user_meta_values'] = $user_meta_value;
        }

        // Merge with existing global JSON to preserve unknown fields.
        $existing = static::get_global_array();
        $merged   = static::array_deep_merge( $existing, $new_global );

        $json = wp_json_encode( $merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        update_option( static::$global_option_name, $json );

        static::$is_syncing = false;

        if ( $export_to_file ) {
            static::export_global_to_file_fs();
        }
    }

    /**
     * Sync global JSON values back into individual options.
     * Call this after updating global JSON.
     *
     * @param  mixed $json_value
     * @return void
     */
    public static function sync_global_to_individuals( $json_value = null ) {
        if ( static::$is_syncing ) {
            return;
        }

        static::$is_syncing = true;

        if ( null === $json_value ) {
            $json_value = get_option( static::$global_option_name );
        }

        $data = is_array( $json_value ) ? $json_value : json_decode( $json_value, true );
        if ( ! is_array( $data ) ) {
            static::$is_syncing = false;
            return;
        }

        $flat = static::get_flattened_mapping();
        foreach ( $flat as $path => $opt_name ) {
            list( $section, $key ) = explode( '.', $path, 2 );
            if ( isset( $data[ $section ][ $key ] ) ) {
                update_option( $opt_name, $data[ $section ][ $key ] );
            }
        }

        static::$is_syncing = false;

        if ( isset( $data['__user_meta_values'] ) ) {
            static::sync_global_user_meta_values_to_user( null, $data );
        }
    }

    /**
     * Get the global JSON setting as array.
     */
    public static function get_global_array() {
        $json = get_option( static::$global_option_name );
        if ( ! $json ) {
            return array();
        }
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Deep merge helper.
     *
     * @param  mixed $a Input array.
     * @param  mixed $b Input array.
     * @return mixed Merged array.
     */
    protected static function array_deep_merge( $a, $b ) {
        foreach ( $b as $k => $v ) {
            if ( isset( $a[ $k ] ) && is_array( $a[ $k ] ) && is_array( $v ) ) {
                $a[ $k ] = static::array_deep_merge( $a[ $k ], $v );
            } else {
                $a[ $k ] = $v;
            }
        }
        return $a;
    }

    /**
     * Return mapping for a specific section (key => option_name).
     *
     * @param string $section Section name (e.g. 'general').
     * @return array
     */
    protected static function get_section_mapping( $section ) {
        $all = static::json_settings_mapping_fields();
        return isset( $all[ $section ] ) && is_array( $all[ $section ] ) ? $all[ $section ] : array();
    }

    /**
     * Sync a single section from individual WP options into the global JSON option.
     *
     * Example: MainWP_Settings_Helper::sync_section_to_global( 'general' );
     *
     * @param string $section Section name, matching keys in json_settings_mapping_fields().
     * @return void
     */
    public static function sync_section_to_global( $section ) {

        if ( '__user_meta_values' === $section ) {
            // Use dedicated method for user meta values.
            static::sync_user_meta_values_to_global();
            return;
        }

        if ( static::$is_syncing ) {
            return;
        }
        static::$is_syncing = true;

        $mapping = static::get_section_mapping( $section );
        if ( empty( $mapping ) ) {
            static::$is_syncing = false;
            return;
        }

        // Build section data from individual options.
        $section_data = array();
        foreach ( $mapping as $json_key => $option_name ) {
            // Use get_option() — it will return the native PHP type (string, array, etc.).
            $val = get_option( $option_name, null );
            if ( null === $val ) {
                // Skip missing options to avoid creating nulls in global JSON.
                continue;
            }
            $section_data[ $json_key ] = $val;
        }

        if ( empty( $section_data ) ) {
            // Nothing to merge.
            static::$is_syncing = false;
            return;
        }

        // Merge into existing global array, preserving unknown fields.
        $global           = static::get_global_array();
        $existing_section = isset( $global[ $section ] ) && is_array( $global[ $section ] ) ? $global[ $section ] : array();

        // Deep merge so new values overwrite per-key but keep nested structure.
        $global[ $section ] = static::array_deep_merge( $existing_section, $section_data );

        // Save back as JSON (pretty for readability).
        $json = wp_json_encode( $global, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        update_option( static::$global_option_name, $json );

        static::$is_syncing = false;

        static::export_global_to_file_fs(); // export to json settings file after sync.
    }

    /**
     * Sync a single section from the global JSON into individual WP options.
     *
     * Example: MainWP_Settings_Helper::sync_global_section_to_individuals( 'general' );
     *
     * @param string      $section    Section name.
     * @param string|null $json_value Optional JSON string/array to use instead of reading option.
     * @return void
     */
    public static function sync_global_section_to_individuals( $section, $json_value = null ) {

        if ( '__user_meta_values' === $section ) {
            // Use dedicated method for user meta values.
            static::sync_global_user_meta_values_to_user( null, $json_value );
            return;
        }

        if ( static::$is_syncing ) {
            return;
        }
        static::$is_syncing = true;

        if ( null === $json_value ) {
            $json_value = get_option( static::$global_option_name );
        }

        $data = is_array( $json_value ) ? $json_value : json_decode( $json_value, true );
        if ( ! is_array( $data ) || ! isset( $data[ $section ] ) || ! is_array( $data[ $section ] ) ) {
            static::$is_syncing = false;
            return;
        }

        $section_values = $data[ $section ];

        $mapping = static::get_section_mapping( $section );
        if ( empty( $mapping ) ) {
            static::$is_syncing = false;
            return;
        }

        foreach ( $mapping as $json_key => $option_name ) {
            if ( array_key_exists( $json_key, $section_values ) ) {
                // Update option with the value from JSON. Use update_option so WP handles serialization.
                update_option( $option_name, $section_values[ $json_key ] );
            }
        }

        static::$is_syncing = false;
    }


    /**
     * Sync the __user_meta_values section from the global JSON
     * into the current user's meta fields.
     *
     * @param int|null          $user_id    Target user ID (defaults to current user).
     * @param string|array|null $json_value Optional JSON string/array to use instead of reading the option.
     * @return void
     */
    public static function sync_global_user_meta_values_to_user( $user_id = null, $json_value = null ) {
        if ( static::$is_syncing ) {
            return;
        }
        static::$is_syncing = true;

        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            static::$is_syncing = false;
            return;
        }

        if ( null === $json_value ) {
            $json_value = get_option( static::$global_option_name );
        }

        $data = is_array( $json_value ) ? $json_value : json_decode( $json_value, true );
        if ( ! is_array( $data ) || ! isset( $data['__user_meta_values'] ) || ! is_array( $data['__user_meta_values'] ) ) {
            static::$is_syncing = false;
            return;
        }

        $section_values = $data['__user_meta_values'];
        $mapping        = static::get_section_mapping( '__user_meta_values' );

        if ( empty( $mapping ) ) {
            static::$is_syncing = false;
            return;
        }

        foreach ( $mapping as $json_key => $meta_key ) {
            if ( array_key_exists( $json_key, $section_values ) ) {
                update_user_meta( $user_id, $meta_key, $section_values[ $json_key ] );
            }
        }

        static::$is_syncing = false;
    }

    /**
     * Get the mapped user meta values for the given user.
     *
     * @param int|null $user_id Target user ID (defaults to current user).
     * @return array Associative array keyed by the JSON keys (e.g. 'show_widgets_overview')
     *               with values pulled from the user's meta. Returns an empty array if nothing found.
     */
    public static function get_section_user_meta_values( $user_id = null ) {

        // Resolve user id (use current user if not supplied).
        if ( empty( $user_id ) ) {
            $user_id = get_current_user_id();
        }

        // If still no valid user, return empty array.
        if ( ! $user_id ) {
            return array();
        }

        // Get mapping and validate it.
        $mapping = static::get_section_mapping( '__user_meta_values' );
        if ( empty( $mapping ) || ! is_array( $mapping ) ) {
            return array();
        }

        $user_meta_values = array();

        foreach ( $mapping as $json_key => $meta_key ) {
            // Ensure keys are strings; skip invalid entries.
            if ( ! is_string( $json_key ) || ! is_string( $meta_key ) || '' === $meta_key ) {
                continue;
            }

            // Use single value retrieval.
            $value = get_user_meta( (int) $user_id, $meta_key, true );

            // Keep the key even if value is null/empty.
            $user_meta_values[ $json_key ] = $value;
        }

        return $user_meta_values;
    }


    /**
     * Sync the current user's meta fields back into the
     * __user_meta_values section of the global JSON option.
     *
     * @param int|null $user_id Target user ID (defaults to current user).
     * @return void
     */
    public static function sync_user_meta_values_to_global( $user_id = null ) {

        if ( static::$is_syncing ) {
            return;
        }

        static::$is_syncing = true;

        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( ! $user_id ) {
            static::$is_syncing = false;
            return;
        }

        $mapping = static::get_section_mapping( '__user_meta_values' );
        if ( empty( $mapping ) ) {
            static::$is_syncing = false;
            return;
        }

        // Load global option.
        $json_value = get_option( static::$global_option_name );
        $data       = is_array( $json_value ) ? $json_value : json_decode( $json_value, true );

        if ( ! is_array( $data ) ) {
            $data = array();
        }
        if ( ! isset( $data['__user_meta_values'] ) || ! is_array( $data['__user_meta_values'] ) ) {
            $data['__user_meta_values'] = array();
        }

        // Fill global section with user meta values.
        foreach ( $mapping as $json_key => $meta_key ) {
            $value                                   = get_user_meta( $user_id, $meta_key, true );
            $data['__user_meta_values'][ $json_key ] = $value;
        }

        // Save back as JSON.
        update_option( static::$global_option_name, wp_json_encode( $data ) );

        static::$is_syncing = false;
    }

    /**
     * Get a portable backup directory path (uploads/mainwp/mainwp-settings) using WP API.
     *
     * NOTE: returns a path suitable for WP_Filesystem methods (absolute).
     *
     * @return string
     */
    protected static function get_backup_settings_dir() {
        $dirs = MainWP_System_Utility::get_mainwp_dir( 'mainwp-settings' );
        return $dirs[0];
    }


    /**
     * Get full filesystem path to the backups file in uploads.
     *
     * @param string|null $filename Optional filename override.
     * @return string Full path.
     */
    protected static function get_backup_settings_file_path( $filename = null ) {
        $filename = $filename ? $filename : static::$backup_filename;
        $dir      = static::get_backup_settings_dir();
        return $dir . sanitize_file_name( $filename );
    }


    /**
     * Initialize WP_Filesystem and return the global instance.
     *
     * @return bool|\WP_Filesystem_Base False on failure, or the instance on success.
     */
    protected static function init_filesystem() {
        if ( ! function_exists( 'request_filesystem_credentials' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; //phpcs:ignore -- ok.
        }

        // Try to initialize; this will populate global $wp_filesystem.
        if ( ! function_exists( '\WP_Filesystem' ) ) {
            return false;
        }

        // Attempt to create a usable filesystem instance.
        // WP_Filesystem() returns true on success and sets global $wp_filesystem.
        if ( ! \WP_Filesystem() ) {
            // Could not initialize without credentials (e.g., remote FS). Caller should handle.
            return false;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            return false;
        }

        return $wp_filesystem;
    }

    /**
     * Export current global JSON option to uploads file using WP_Filesystem.
     *
     * @param string|null $filename Optional filename.
     * @return bool True on success.
     */
    public static function export_global_to_file_fs( $filename = null ) {
        if ( static::$is_syncing ) {
            return false;
        }
        static::$is_syncing = true;

        $wp_filesystem = static::init_filesystem();
        if ( ! $wp_filesystem ) {
            static::$is_syncing = false;
            return false;
        }

        $dir  = static::get_backup_settings_dir();
        $path = static::get_backup_settings_file_path( $filename );

        // Ensure directory exists.
        if ( ! $wp_filesystem->is_dir( $dir ) && ! $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
            static::$is_syncing = false;
            return false;
        }

        $global = static::get_global_array();
        if ( ! isset( $global['__meta'] ) || ! is_array( $global['__meta'] ) ) {
            $global['__meta'] = array();
        }
        $global['__meta']['saved_at'] = gmdate( 'Y-m-d H:i:s' );

        if ( isset( $global['__meta']['force'] ) ) {
            unset( $global['__meta']['force'] ); // to prevent always true force on import.
        }

        $json = wp_json_encode( $global, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( false === $json ) {
            static::$is_syncing = false;
            return false;
        }

        // Use put_contents (WP_Filesystem handles proper transport).
        $written = $wp_filesystem->put_contents( $path, $json, FS_CHMOD_FILE );

        static::$is_syncing = false;
        return false !== $written;
    }

    /**
     * Import file into global option using WP_Filesystem if file is newer (or when forced).
     *
     * @param string|null $filename Optional filename.
     * @param bool        $force    If true, import regardless of timestamps.
     * @param bool        $sync_individuals If true, call sync_global_to_individuals() after import.
     * @return bool True if import+apply done, false otherwise.
     */
    public static function import_file_to_global_if_newer_fs( $filename = null, $force = false, $sync_individuals = true ) {
        if ( static::$is_syncing ) {
            return false;
        }
        static::$is_syncing = true;

        $wp_filesystem = static::init_filesystem();
        if ( ! $wp_filesystem ) {
            static::$is_syncing = false;
            return false;
        }

        $path = static::get_backup_settings_file_path( $filename );

        if ( ! $wp_filesystem->exists( $path ) ) {
            static::$is_syncing = false;
            return false;
        }

        // Try to get file modification time using the filesystem implementation if available.
        $file_mtime = 0;
        if ( method_exists( $wp_filesystem, 'mtime' ) ) {
            // Some implementations provide mtime().
            $file_mtime = (int) $wp_filesystem->mtime( $path );
        } elseif ( method_exists( $wp_filesystem, 'stat' ) ) {
            // stat may return array with 'mtime'.
            $stat = $wp_filesystem->stat( $path );
            if ( is_array( $stat ) && ! empty( $stat['mtime'] ) ) {
                $file_mtime = (int) $stat['mtime'];
            }
        }

        // Fallback: try native filemtime (works for direct FS).
        if ( empty( $file_mtime ) ) {
            $native_path = $path;
            if ( file_exists( $native_path ) && function_exists( 'filemtime' ) ) {
                $file_mtime = (int) filemtime( $native_path );
            }
        }

        // Get file contents via WP_Filesystem.
        $raw = $wp_filesystem->get_contents( $path );
        if ( false === $raw ) {
            static::$is_syncing = false;
            return false;
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            static::$is_syncing = false;
            return false;
        }

        $global = static::get_global_array();

        $file_saved_at_str   = isset( $data['__meta']['saved_at'] ) ? $data['__meta']['saved_at'] : '';
        $global_saved_at_str = isset( $global['__meta']['saved_at'] ) ? $global['__meta']['saved_at'] : '';

        $file_saved_at   = ! empty( $file_saved_at_str ) ? strtotime( $file_saved_at_str ) : 0;
        $global_saved_at = ! empty( $global_saved_at_str ) ? strtotime( $global_saved_at_str ) : 0;

        if ( empty( $file_saved_at ) ) {
            $file_saved_at = $file_mtime ? $file_mtime : 0;
        }

        if ( ! $force ) {
            $force = isset( $data['__meta']['force'] ) ? mainwp_string_to_bool( $data['__meta']['force'] ) : false;
        }

        if ( ! $force && $file_saved_at <= $global_saved_at ) {
            static::$is_syncing = false;
            return false;
        }

        $json_to_save = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( false === $json_to_save ) {
            static::$is_syncing = false;
            return false;
        }

        update_option( static::$global_option_name, $json_to_save );

        static::$is_syncing = false;

        if ( $sync_individuals ) {
            static::sync_global_to_individuals( $data );
        }

        return true;
    }

    /**
     * Check if backup file is newer than the saved global JSON option.
     *
     * Uses WP_Filesystem first for portability, falls back to filemtime() if available.
     *
     * @param string $filename Optional. Backup file name. Default 'mainwp_main_settings.json'.
     * @return bool True if file exists and is newer than saved global option, false otherwise.
     */
    public static function backup_file_is_newer_fs( $filename = 'mainwp_main_settings.json' ) {
        // Initialize WP_Filesystem.
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; //phpcs:ignore -- NOSONAR - ok.
            \WP_Filesystem();
        }

        // Determine backup file path in uploads directory.
        $dir         = static::get_backup_settings_dir();
        $backup_path = $dir . $filename;

        // --- 1️⃣ Check if file exists
        $exists = false;
        if ( $wp_filesystem && method_exists( $wp_filesystem, 'exists' ) ) {
            $exists = $wp_filesystem->exists( $backup_path );
        } elseif ( file_exists( $backup_path ) ) {
            $exists = true;
        }

        if ( ! $exists ) {
            return false; // No file found.
        }

        // --- 2️⃣ Get modification time
        $mtime = false;

        // Try WP_Filesystem first.
        if ( $wp_filesystem && method_exists( $wp_filesystem, 'mtime' ) ) {
            $mtime = (int) $wp_filesystem->mtime( $backup_path );
        }

        // Fallback to native filemtime() if allowed.
        if ( ! $mtime && function_exists( 'filemtime' ) && is_callable( 'filemtime' ) && is_readable( $backup_path ) ) {
            $mtime = @filemtime( $backup_path );
        }

        if ( ! $mtime ) {
            return false; // Could not determine file modification time.
        }

        // --- 3️⃣ Compare against saved global option timestamp
        $global = static::get_global_array();

        $saved_at_str = isset( $global['__meta']['saved_at'] ) ? $global['__meta']['saved_at'] : '';
        $saved_at     = ! empty( $saved_at_str ) ? strtotime( $saved_at_str ) : 0;
        return $mtime > $saved_at;
    }
}
