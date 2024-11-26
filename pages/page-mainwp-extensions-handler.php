<?php
/**
 * MainWP Extensions Page Handler
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Extensions_Handler
 */
class MainWP_Extensions_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

// phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * All extensions.
     *
     * @var array $extensions
     */
    public static $extensions;

    /**
     * All disabled extensions.
     *
     * @var array $extensions
     */
    public static $extensions_disabled;

    /**
     * Get Extension Slug.
     *
     * @param mixed $slug Extension Slug.
     *
     * @return string Extensions Slug.
     */
    public static function get_extension_slug( $slug ) {
        $currentExtensions = static::get_extensions();
        if ( ! is_array( $currentExtensions ) || empty( $currentExtensions ) ) {
            return $slug;
        }

        foreach ( $currentExtensions as $extension ) {
            if ( isset( $extension['api'] ) && ( $extension['api'] === $slug ) ) {
                return $extension['slug'];
            }
        }

        return $slug;
    }

    /**
     * Get all extension slugs.
     *
     * @return array am_slugs|slugs.
     */
    public static function get_slugs() {
        $currentExtensions = static::get_extensions();

        if ( empty( $currentExtensions ) ) {
            return array(
                'slugs'    => '',
                'am_slugs' => '',
            );
        }

        $out    = '';
        $am_out = '';
        foreach ( $currentExtensions as $extension ) {
            if ( ! isset( $extension['api'] ) || '' === $extension['api'] ) {
                continue;
            }

            if ( isset( $extension['apiManager'] ) && ! empty( $extension['apiManager'] ) && 'Activated' === $extension['activated_key'] ) {
                if ( '' !== $am_out ) {
                    $am_out .= ',';
                }
                $am_out .= $extension['api'];
            } else {
                if ( '' !== $out ) {
                    $out .= ',';
                }
                $out .= $extension['api'];
            }
        }

        return array(
            'slugs'    => $out,
            'am_slugs' => $am_out,
        );
    }

    /**
     * Clean up MainWP Extention names.
     *
     * @param string $extension Array of MainWP Extentsions.
     * @param bool   $forced forced polish.
     *
     * @return string $menu_name Final Menu Name.
     */
    public static function polish_ext_name( $extension, $forced = false ) {
        if ( $forced || ( isset( $extension['mainwp'] ) && $extension['mainwp'] ) ) {
            $menu_name = static::polish_string_name( $extension['name'] );
        } else {
            $menu_name = $extension['name'];
        }
        return $menu_name;
    }

    /**
     * Clean up MainWP Extention names.
     *
     * @param string $name Extention name string.
     *
     * @return string $menu_name Final Name.
     */
    public static function polish_string_name( $name ) {
        if ( false !== stripos( $name, 'for Mainwp' ) ) {
            return $name; // skip.
        }
        $new_name = str_replace(
            array(
                'Extensions',
                'Mainwp',
                'Extension',
                'MainWP',
            ),
            '',
            $name
        );
        $new_name = trim( $new_name );
        return $new_name;
    }


    /**
     * Load MainWP Extensions.
     *
     * @param bool $forced Forced reload value.
     *
     * @return array Array of loaded Extensions.
     */
    public static function get_extensions( $forced = false ) {
        if ( ! isset( static::$extensions ) || $forced ) {
            static::$extensions = array();
            $extensions         = get_option( 'mainwp_extensions', array() );
            foreach ( $extensions as $extension ) {
                $slug = $extension['slug'];
                if ( function_exists( '\mainwp_current_user_can' ) ) { // to fix later init, it's ok to check user have right.
                    if ( \mainwp_current_user_can( 'extension', dirname( $slug ) ) ) {
                        static::$extensions[] = $extension;
                    }
                } else {
                    static::$extensions[] = $extension;
                }
            }
        }
        return static::$extensions;
    }


    /**
     * Get disabled MainWP Extensions.
     *
     * @param bool $compatible_api_response To get compatible api response values.
     *
     * @return array Array of disabled Extensions.
     */
    public static function get_extensions_disabled( $compatible_api_response = false ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! isset( static::$extensions_disabled ) || $compatible_api_response ) {
            static::$extensions_disabled = array();
            $all_available_extensions    = MainWP_Extensions_View::get_available_extensions( 'all' );
            $all_plugins                 = get_plugins();

            $exts_disabled = array();

            if ( $all_plugins ) {
                foreach ( $all_plugins as $plugin => $plugin_data ) {
                    if ( is_plugin_active( $plugin ) ) {
                        continue;
                    }
                    $slug = dirname( $plugin );
                    if ( isset( $all_available_extensions[ $slug ] ) ) {

                        $ext = $all_available_extensions[ $slug ];

                        $extension = array();

                        $extension['name']             = $plugin_data['Name'];
                        $extension['slug']             = $plugin;
                        $extension['version']          = $plugin_data['Version'];
                        $extension['description']      = $plugin_data['Description'];
                        $extension['author']           = $plugin_data['Author'];
                        $extension['img']              = isset( $ext['img'] ) ? $ext['img'] : '';
                        $extension['iconURI']          = isset( $plugin_data['IconURI'] ) ? $plugin_data['IconURI'] : '';
                        $extension['SupportForumURI']  = '';
                        $extension['DocumentationURI'] = '';
                        $extension['page']             = 'Extensions-' . str_replace( ' ', '-', ucwords( str_replace( '-', ' ', dirname( $slug ) ) ) );

                        $extension['api_key']             = '';
                        $extension['activated_key']       = 'Deactivated';
                        $extension['deactivate_checkbox'] = 'off';
                        $extension['product_id']          = $ext['product_id'];
                        $extension['instance_id']         = '';
                        $extension['software_version']    = '';
                        $extension['product_item_id']     = '';
                        $extension['type']                = $ext['type'];

                        if ( $compatible_api_response ) {
                            $exts_disabled[ $ext['product_id'] ] = $extension;
                        } else {
                            $exts_disabled[] = $extension;
                        }
                    }
                }
            }

            if ( $compatible_api_response ) {
                return $exts_disabled;
            } else {
                static::$extensions_disabled = $exts_disabled;
            }
        }
        return static::$extensions_disabled;
    }

    /**
     * Get not installed MainWP Extensions.
     *
     * @return array Array of not installed Extensions.
     */
    public static function get_extensions_not_installed() {
        $all_available_extensions = MainWP_Extensions_View::get_available_extensions( 'all' );

        $all_plugins = get_plugins();

        $installed_slugs = array();
        foreach ( $all_plugins as $plugin => $plugin_data ) {
            $slug = dirname( $plugin );
            if ( ! isset( $installed_slugs[ $slug ] ) ) {
                $installed_slugs[] = $slug;
            }
        }

        $exts_not_installed = array();
        foreach ( $all_available_extensions as $slug => $info ) {
            if ( ! in_array( $slug, $installed_slugs ) ) {
                $exts_not_installed[ $slug ] = array(
                    'name'        => $info['title'],
                    'slug'        => $info['slug'],
                    'link'        => $info['link'],
                    'img'         => isset( $info['img'] ) ? $info['img'] : '',
                    'description' => isset( $info['desc'] ) ? $info['desc'] : '',
                );
            }
        }
        return $exts_not_installed;
    }

    /**
     * Get MainWP Extensions infor array.
     *
     * @param array $args Empty Array.
     * @param bool  $deactivated_license Get extensions that deactivated license or not.
     *
     * @return array Array of Extensions.
     */
    public static function get_indexed_extensions_infor( $args = array(), $deactivated_license = null ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $args ) ) {
            $args = array();
        }
        $extensions = static::get_extensions();
        $return     = array();
        foreach ( $extensions as $extension ) {
            if ( ( isset( $args['activated'] ) && ! empty( $args['activated'] ) && isset( $extension['apiManager'] ) && $extension['apiManager'] ) && ( ! isset( $extension['activated_key'] ) || 'Activated' !== $extension['activated_key'] ) ) {
                continue;
            }
            $apiManager            = isset( $extension['apiManager'] ) && $extension['apiManager'] ? true : false;
            $ext                   = array();
            $ext['version']        = $extension['version'];
            $ext['apiManager']     = $apiManager;
            $ext['name']           = $extension['name'];
            $ext['page']           = $extension['page'];
            $ext['mainwp_version'] = isset( $extension['mainwp_version'] ) ? $extension['mainwp_version'] : '';

            if ( isset( $extension['activated_key'] ) && 'Activated' === $extension['activated_key'] ) {
                $ext['activated_key'] = 'Activated';
            }

            if ( null !== $deactivated_license && ( ( $deactivated_license && $apiManager && isset( $ext['activated_key'] ) && 'Activated' === $ext['activated_key'] ) || ( ! $deactivated_license && ( ! isset( $ext['activated_key'] ) || 'Activated' !== $ext['activated_key'] ) ) ) ) {
                continue; // get deactivated license, skip activated license.
            }

            $return[ $extension['slug'] ] = $ext;
        }
        return $return;
    }

    /**
     * Generate API Password.
     *
     * @param integer $length Lenght of password.
     * @param bool    $special_chars true|false, allow special characters.
     * @param bool    $extra_special_chars true|false, allow extra special characters.
     *
     * @return MainWP_Api_Manager_Password_Management::generate_password()
     */
    public static function gen_api_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
        return MainWP_Api_Manager_Password_Management::generate_password( $length, $special_chars, $extra_special_chars );
    }


    /**
     * Add Extension Menu.
     *
     * @param mixed $slug Extension slug.
     *
     * @return boolean true|false.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public static function add_extension_menu( $slug ) {
        $snMenuExtensions = get_option( 'mainwp_extmenu' );
        if ( ! is_array( $snMenuExtensions ) ) {
            $snMenuExtensions = array();
        }

        $snMenuExtensions[] = $slug;

        MainWP_Utility::update_option( 'mainwp_extmenu', $snMenuExtensions );

        /**
         * Adds Extension to the navigation menu
         *
         * Adds Extension instance to the Extensions located in the main MainWP navigation menu.
         *
         * @param string $slug Extension slug.
         *
         * @since 4.0
         */
        do_action( 'mainwp_added_extension_menu', $slug );

        return true;
    }

    /**
     * HTTP Request Reject Unsafe Urls.
     *
     * @param bool $args args.
     *
     * @return mixed args.
     */
    public static function http_request_reject_unsafe_urls( $args ) {
        $args['reject_unsafe_urls'] = false;

        return $args;
    }

    /**
     * No SSL Filter Function.
     *
     * @param bool $args args.
     *
     * @return mixed args.
     */
    public static function no_ssl_filter_function( $args ) {
        $args['sslverify'] = false;
        return $args;
    }

    /**
     * No SSL Filter Extention Upgrade.
     *
     * @param bool  $r Results.
     * @param mixed $url Upgrade Extension URL.
     *
     * @return mixed false|$r.
     */
    public static function no_ssl_filter_extension_upgrade( $r, $url ) {
        if ( ( false !== strpos( $url, 'am_download_file=' ) ) && ( false !== strpos( $url, 'am_email=' ) ) ) {
            $r['sslverify'] = false;
        }

        return $r;
    }

    /**
     * Install MainWP Extension.
     *
     * @param mixed $url MainWP Extension update URL.
     * @param bool  $activatePlugin true|false Whether or not to activate extension.
     *
     * @return mixed $return
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     */
    public static function install_plugin( $url, $activatePlugin = false ) { //phpcs:ignore -- NOSONAR - complex.

        MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
            include_once ABSPATH . '/wp-admin/includes/screen.php'; // NOSONAR - WP compatible.
        }

        include_once ABSPATH . '/wp-admin/includes/template.php'; // NOSONAR - WP compatible.
        include_once ABSPATH . '/wp-admin/includes/misc.php'; // NOSONAR - WP compatible.
        include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php'; // NOSONAR - WP compatible.
        include_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR - WP compatible.

        $installer          = new \WP_Upgrader();
        $ssl_verifyhost     = get_option( 'mainwp_sslVerifyCertificate' );
        $ssl_api_verifyhost = ( ( false === get_option( 'mainwp_api_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_api_sslVerifyCertificate' ) ) ) ? 1 : 0;

        if ( empty( $ssl_api_verifyhost ) ) {
            add_filter( 'http_request_args', array( static::get_class_name(), 'no_ssl_filter_function' ), 99, 2 );
        }

        add_filter( 'http_request_args', array( static::get_class_name(), 'http_request_reject_unsafe_urls' ), 99, 2 );

        $result = $installer->run(
            array(
                'package'           => $url,
                'destination'       => WP_PLUGIN_DIR,
                'clear_destination' => false,
                'clear_working'     => true,
                'hook_extra'        => array(),
            )
        );

        remove_filter( 'http_request_args', array( static::get_class_name(), 'http_request_reject_unsafe_urls' ), 99, 2 );

        if ( '0' === $ssl_verifyhost ) {
            remove_filter( 'http_request_args', array( static::get_class_name(), 'no_ssl_filter_function' ), 99 );
        }

        $error       = null;
        $output      = null;
        $plugin_slug = null;

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            if ( $result->get_error_data() && is_string( $result->get_error_data() ) ) {
                $error = $error_code . ' - ' . $result->get_error_data();
            } else {
                $error = $error_code;
            }
        } else {
            $path = $result['destination'];

            foreach ( $result['source_files'] as $srcFile ) {

                if ( 'readme.txt' === $srcFile ) {
                    continue;
                }

                $thePlugin = get_plugin_data( $path . $srcFile );

                if ( null !== $thePlugin && '' !== $thePlugin && '' !== $thePlugin['Name'] ) {
                    $the_name    = static::polish_string_name( $thePlugin['Name'] );
                    $output     .= esc_html( $the_name ) . ' ' . esc_html__( 'installed successfully. Do not forget to activate the extension API license.', 'mainwp' );
                    $plugin_slug = $result['destination_name'] . '/' . $srcFile;

                    if ( $activatePlugin ) {
                        activate_plugin( $path . $srcFile, '', false, true );

                        /**
                         * Extension API activation
                         *
                         * Activates the extension API license upon the extension installation.
                         *
                         * @since Unknown
                         * @ignore
                         */
                        do_action( 'mainwp_api_extension_activated', $path . $srcFile );
                    }

                    break;
                }
            }
        }

        if ( ! empty( $error ) ) {
            $return['error'] = $error;
        } else {
            $return['result'] = 'SUCCESS';
            $return['output'] = $output;
            $return['slug']   = esc_html( $plugin_slug );
        }

        return $return;
    }

    /**
     * Check if MainWP Extension is available.
     *
     * @param mixed $pAPI MainWP Extension API Key.
     *
     * @return boolean true|false.
     */
    public static function is_extension_available( $pAPI ) {

        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();

        $extensions = static::get_extensions();
        if ( isset( $extensions ) && is_array( $extensions ) ) {
            foreach ( $extensions as $extension ) {
                $slug = dirname( $extension['slug'] );
                if ( $slug === $pAPI ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if MainWP Extension is enabled.
     *
     * @param mixed $pluginFile MainWP Extension to bo verified.
     *
     * @return array 'key' => md5( $pluginFile . '-SNNonceAdder').
     */
    public static function is_extension_enabled( $pluginFile ) {
        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
        return array( 'key' => md5( $pluginFile . '-SNNonceAdder' ) ); // NOSONAR - safe for sig file name.
    }

    /**
     * Create Menu Extension Array.
     *
     * @param mixed $slug menu slug.
     *
     * @return array Menu Array.
     */
    public static function added_on_menu( $slug ) {
        $snMenuExtensions = get_option( 'mainwp_extmenu' );
        if ( ! is_array( $snMenuExtensions ) ) {
            $snMenuExtensions = array();
        }
        return in_array( $slug, $snMenuExtensions );
    }

    /**
     * Check if MainWP Extension is activated or not.
     *
     * @param mixed $plugin_slug MainWP Extension slug.
     *
     * @return boolean true|false.
     */
    public static function is_extension_activated( $plugin_slug ) {
        $extensions = static::get_indexed_extensions_infor( array( 'activated' => true ) );
        return isset( $extensions[ $plugin_slug ] ) ? true : false;
    }

    /**
     * Verify MainWP Extension.
     *
     * @param mixed $pluginFile MainWP Extensoin to verify.
     * @param mixed $key Child Site Key.
     *
     * @return mixed md5( $pluginFile . '-SNNonceAdder' ) === $key
     */
    public static function hook_verify( $pluginFile, $key ) {
        return md5( $pluginFile . '-SNNonceAdder' ) === $key; // NOSONAR - safe for sig file name.
    }

    /**
     * Get sql websites for current user.
     *
     * @param mixed $pluginFile Extension plugin file to verify.
     * @param mixed $key PThe child-key.
     *
     * @return mixed null|sql query.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     */
    public static function hook_get_dashboard_sites( $pluginFile, $key ) {
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return null;
        }

        $current_wpid = MainWP_System_Utility::get_current_wpid();

        if ( $current_wpid ) {
            $sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
        } else {
            $sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
        }

        return MainWP_DB::instance()->query( $sql );
    }

    /**
     * Fetch Authorized URLS.
     *
     * @param mixed  $pluginFile Extension plugin file to verify.
     * @param string $key The child key.
     * @param object $dbwebsites The websites.
     * @param string $what Action to perorm.
     * @param mixed  $params Request parameters.
     * @param mixed  $handle Request handle.
     * @param mixed  $output Request output.
     *
     * @uses MainWP_Connect::fetch_urls_authed()
     *
     * @return mixed false|MainWP_Connect::fetch_urls_authed()
     */
    public static function hook_fetch_urls_authed( $pluginFile, $key, $dbwebsites, $what, $params, $handle, $output ) {
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }

        return MainWP_Connect::fetch_urls_authed( $dbwebsites, $what, $params, $handle, $output );
    }

    /**
     * Fetch Authorized URL.
     *
     * @throws MainWP_Exception On incorrect website.
     * @param mixed $pluginFile Extension plugin file to verify.
     * @param mixed $key The child-key.
     * @param mixed $websiteId Child Site ID.
     * @param mixed $what What.
     * @param mixed $params Parameters.
     * @param null  $rawResponse Raw responce.
     *
     * @return mixed false|throw|error
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function hook_fetch_url_authed( $pluginFile, $key, $websiteId, $what, $params, $rawResponse = null ) {
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }
        return static::fetch_url_authed( $websiteId, $what, $params, $rawResponse );
    }

    /**
     * Fetch Authorized URL.
     *
     * @throws MainWP_Exception On incorrect website.
     * @param mixed $websiteId Child Site ID.
     * @param mixed $what What.
     * @param mixed $params Parameters.
     * @param null  $rawResponse Raw responce.
     *
     * @return mixed false|throw|error
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function fetch_url_authed( $websiteId, $what, $params, $rawResponse = null ) {
        try {
            $website = MainWP_DB::instance()->get_website_by_id( $websiteId );
            if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
                throw new MainWP_Exception( 'You can not edit this website.' );
            }

            return MainWP_Connect::fetch_url_authed( $website, $what, $params, false, false, true, $rawResponse );
        } catch ( MainWP_Exception $e ) {
            return array(
                'error'     => MainWP_Error_Helper::get_error_message( $e ),
                'errorCode' => 'excep_fetch_url_authed',
            );
        }
    }

    /**
     * Get DB Sites.
     *
     * @param mixed  $pluginFile Extension plugin file to verify.
     * @param mixed  $key The child-key.
     * @param mixed  $sites Child Sites.
     * @param string $groups Groups.
     * @param bool   $options Options.
     *
     * @return array $dbwebsites.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses  \MainWP\Dashboard\MainWP_Utility::map_site()
     */
    public static function hook_get_db_sites( $pluginFile, $key, $sites, $groups = '', $options = false ) {
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }

        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
        $params = array(
            'fields' => $options,
            'sites'  => $sites,
            'groups' => $groups,
        );
        return MainWP_DB::instance()->get_db_sites( $params );
    }

    /**
     * Get DB Sites.
     *
     * @since 4.4.2
     *
     * @param mixed $pluginFile Extension plugin file to verify.
     * @param mixed $key The child-key.
     * @param mixed $params params.
     *
     * @return array $dbwebsites.
     */
    public static function hook_get_db_websites( $pluginFile, $key, $params = array() ) {
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }

        if ( empty( $params ) || ! is_array( $params ) ) {
            return false;
        }
        return MainWP_DB::instance()->get_db_sites( $params );
    }


    /**
     * Get Sites.
     *
     * @param string $pluginFile Extension plugin file to verify.
     * @param string $key The child-key.
     * @param int    $websiteid The id of the child site you wish to retrieve.
     * @param bool   $for_manager Check Team Control.
     * @param array  $others Array of others.
     *
     * @return array $output Array of content to output.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     */
    public static function hook_get_sites( $pluginFile, $key, $websiteid = null, $for_manager = false, $others = array() ) { // phpcs:ignore -- NOSONAR - not quite complex function.
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }

        if ( $for_manager && ( ! defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) || ! \mainwp_current_user_can( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) ) {
            return false;
        }
        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();

        return MainWP_DB::instance()->get_sites( $websiteid, $for_manager, $others );
    }

    /**
     * Method hook_get_groups()
     *
     * Get Child Sites within groups & store them in an array.
     *
     * @param string $pluginFile Extension plugin file to verify.
     * @param string $key The child-key.
     * @param int    $groupid The id of the group you wish to retrieve.
     * @param bool   $for_manager Check Team Control.
     *
     * @return array|bool $output|false An array of arrays, the inner-array contains the id/name/array of site ids for the supplied groupid/all groups. False when something goes wrong.
     */
    public static function hook_get_groups( $pluginFile, $key, $groupid, $for_manager = false ) {
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }

        if ( $for_manager && ( ! defined( 'MWP_TEAMCONTROL_PLUGIN_SLUG' ) || ! \mainwp_current_user_can( 'extension', dirname( MWP_TEAMCONTROL_PLUGIN_SLUG ) ) ) ) {
            return false;
        }

        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();

        if ( isset( $groupid ) ) {
            $group = MainWP_DB_Common::instance()->get_group_by_id( $groupid );
            if ( empty( $group ) ) {
                return false;
            }

            $websites    = MainWP_DB::instance()->get_websites_by_group_id( $group->id );
            $websitesOut = array();
            foreach ( $websites as $website ) {
                $websitesOut[] = $website->id;
            }

            return array(
                array(
                    'id'       => $groupid,
                    'name'     => $group->name,
                    'websites' => $websitesOut,
                ),
            );
        }

        $groups = MainWP_DB_Common::instance()->get_groups_and_count( null, $for_manager );
        $output = array();
        foreach ( $groups as $group ) {
            $websites    = MainWP_DB::instance()->get_websites_by_group_id( $group->id );
            $websitesOut = array();
            foreach ( $websites as $website ) {
                if ( in_array( $website->id, $websitesOut ) ) {
                    continue;
                }
                $websitesOut[] = $website->id;
            }
            $output[] = array(
                'id'       => $group->id,
                'name'     => $group->name,
                'websites' => $websitesOut,
            );
        }

        return $output;
    }


    /**
     * Get all loaded extensions.
     *
     * @return mainwp_extensions value.
     */
    public static function hook_get_all_extensions() {
        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
        return get_option( 'mainwp_extensions' );
    }

    /**
     * Clone Site.
     *
     * @param mixed $pluginFile Extension plugin file to verify.
     * @param mixed $key The child-key.
     * @param mixed $websiteid Child Site ID.
     * @param mixed $cloneID Clone ID.
     * @param mixed $clone_url URL to CLone to.
     * @param bool  $force_update true|false, force an update.
     *
     * @return mixed false|$ret
     *
     * @uses \MainWP\Dashboard\MainWP_Sync::sync_site()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses  \MainWP\Dashboard\MainWP_Utility::remove_http_www_prefix()
     */
    public static function hook_clone_site( $pluginFile, $key, $websiteid, $cloneID, $clone_url, $force_update = false ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }

        if ( ! empty( $websiteid ) && ! empty( $cloneID ) ) {

            $sql      = MainWP_DB::instance()->get_sql_website_by_id( $websiteid );
            $websites = MainWP_DB::instance()->query( $sql );
            $website  = MainWP_DB::fetch_object( $websites );

            if ( empty( $website ) ) {
                return array( 'error' => esc_html__( 'Website not found.', 'mainwp' ) );
            }

            $ret = array();

            if ( '/' !== substr( $clone_url, - 1 ) ) {
                $clone_url .= '/';
            }

            $tmp1 = MainWP_Utility::remove_http_www_prefix( $website->url );
            $tmp2 = MainWP_Utility::remove_http_www_prefix( $clone_url );

            if ( false === strpos( $tmp2, $tmp1 ) ) {
                return false;
            }

            $clone_sites = MainWP_DB::instance()->get_websites_by_url( $clone_url );
            if ( $clone_sites ) {
                $clone_site = current( $clone_sites );
                if ( $clone_site && $clone_site->is_staging ) {

                    // try to decrypt priv key.
                    $de_privkey = MainWP_Encrypt_Data_Lib::instance()->decrypt_privkey( base64_decode( $website->privkey ), $website->id ); // phpcs:ignore -- NOSONAR - base64_encode trust.
                    if ( ! empty( $de_privkey ) ) {
                        $en_privkey = MainWP_Encrypt_Data_Lib::instance()->encrypt_privkey( $de_privkey, $clone_site->id, true ); // create encrypt priv key for clone site.
                    } else {
                        $en_privkey = base64_decode( $website->privkey ); // phpcs:ignore -- NOSONAR -trust - compatible.
                    }

                    if ( $force_update ) {
                        MainWP_DB::instance()->update_website_values(
                            $clone_site->id,
                            array(
                                'adminname'          => $website->adminname,
                                'pubkey'             => $website->pubkey,
                                'privkey'            => base64_encode( $en_privkey ), //phpcs:ignore -- NOSONAR -ok.
                                'verify_certificate' => $website->verify_certificate,
                                'uniqueId'           => ( null !== $website->uniqueId ? $website->uniqueId : '' ),
                                'http_user'          => $website->http_user,
                                'http_pass'          => $website->http_pass,
                                'ssl_version'        => $website->ssl_version,
                            )
                        );
                    }
                    $ret['siteid']   = $clone_site->id;
                    $ret['response'] = esc_html__( 'Site updated.', 'mainwp' );
                }
                return $ret;
            }
            $clone_name = $website->name . ' - ' . $cloneID;

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            $others = array(
                'groupids'          => array(),
                'groupnames'        => array(),
                'verifyCertificate' => $website->verify_certificate,
                'addUniqueId'       => ( null !== $website->uniqueId ? $website->uniqueId : '' ),
                'http_user'         => $website->http_user,
                'http_pass'         => $website->http_pass,
                'sslVersion'        => $website->ssl_version,
                'wpe'               => $website->wpe,
                'isStaging'         => 1,
            );

            $de_privkey = base64_decode( $website->privkey ); //phpcs:ignore -- NOSONAR -trust - compatible.
            $de_privkey = MainWP_Encrypt_Data_Lib::instance()->decrypt_privkey( $de_privkey, $site_id );

            if ( ! empty( $de_privkey ) ) {
                $de_privkey = base64_encode( $de_privkey ); //phpcs:ignore -- NOSONAR -ok.
            } else {
                $de_privkey = $website->privkey; // compatible - encoded.
            }

            $id = MainWP_DB::instance()->add_website( $current_user->ID, $clone_name, $clone_url, $website->adminname, $website->pubkey, $de_privkey, $others );

            /** This action is documented in class\class-mainwp-manage-sites-view.php */
            do_action( 'mainwp_added_new_site', $id, $website );

            if ( $id ) {
                $group_id = get_option( 'mainwp_stagingsites_group_id' );
                if ( $group_id ) {
                    $website = MainWP_DB::instance()->get_website_by_id( $id );
                    if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                        MainWP_Sync::sync_site( $website, false, false );
                        $group = MainWP_DB_Common::instance()->get_group_by_id( $group_id );
                        if ( ! empty( $group ) ) {
                            MainWP_DB_Common::instance()->update_group_site( $group->id, $id );
                        }
                    }
                }
                $ret['response'] = esc_html__( 'Site successfully added.', 'mainwp' );
                $ret['siteid']   = $id;
            }
            return $ret;
        }

        return false;
    }

    /**
     * Delete Clones Site.
     *
     * @param mixed $pluginFile Extension plugin file to verify.
     * @param mixed $key The child-key.
     * @param mixed $clone_url URL to Clone to.
     * @param bool  $clone_site_id Cloned Site ID.
     *
     * @return mixed false|array Array => "Success".
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_icons_dir()
     */
    public static function hook_delete_clone_site( $pluginFile, $key, $clone_url = '', $clone_site_id = false ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }

        if ( empty( $clone_url ) && empty( $clone_site_id ) ) {
            return false;
        }

        $clone_site = null;
        if ( ! empty( $clone_url ) ) {
            if ( '/' !== substr( $clone_url, - 1 ) ) {
                $clone_url .= '/';
            }
            $clone_sites = MainWP_DB::instance()->get_websites_by_url( $clone_url );
            if ( ! empty( $clone_sites ) ) {
                $clone_site = current( $clone_sites );

            }
        } elseif ( ! empty( $clone_site_id ) ) {
            $sql        = MainWP_DB::instance()->get_sql_website_by_id( $clone_site_id );
            $websites   = MainWP_DB::instance()->query( $sql );
            $clone_site = MainWP_DB::fetch_object( $websites );
        }

        if ( empty( $clone_site ) ) {
            return array( 'error' => esc_html__( 'Not found the clone website', 'mainwp' ) );
        }

        if ( $clone_site ) {
            if ( empty( $clone_site->is_staging ) ) {
                return false;
            }

            MainWP_System_Utility::get_wp_file_system();

            /**
             * WordPress files system object.
             *
             * @global object
             */
            global $wp_filesystem;

            $favi = MainWP_DB::instance()->get_website_option( $clone_site, 'favi_icon', '' );
            if ( ! empty( $favi ) && ( false !== strpos( $favi, 'favi-' . $clone_site->id . '-' ) ) ) {
                $dirs = MainWP_System_Utility::get_icons_dir();
                if ( $wp_filesystem->exists( $dirs[0] . $favi ) ) {
                    $wp_filesystem->delete( $dirs[0] . $favi );
                }
            }

            MainWP_DB::instance()->remove_website( $clone_site->id );

            /** This action is documented in pages\page-mainwp-manage-sites-handler.php */
            do_action( 'mainwp_delete_site', $clone_site );
            return array( 'result' => 'SUCCESS' );
        }

        return false;
    }

    /**
     * Add Groups.
     *
     * @param mixed $pluginFile  Extension plugin file to verify.
     * @param mixed $key The child-key.
     * @param mixed $newName Name that you want to give the group.
     *
     * @return mixed false|$groupId
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Groups::check_group_name()
     */
    public static function hook_add_group( $pluginFile, $key, $newName ) {

        if ( ! static::hook_verify( $pluginFile, $key ) ) {
            return false;
        }

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        if ( ! empty( $newName ) ) {
            $groupId = MainWP_DB_Common::instance()->add_group( $current_user->ID, MainWP_Manage_Groups::check_group_name( $newName ) );

            /** This action is documented in pages\page-mainwp-manage-groups.php */
            do_action( 'mainwp_added_new_group', $groupId );
            return $groupId;
        }
        return false;
    }
}
