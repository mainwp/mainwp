<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Updates_Helper;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Updates_Handler;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\Rest_Api_V1_Helper;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Common_Functions;
use MainWP\Dashboard\MainWP_Cron_Jobs_Batch;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Auto_Updates_DB;
/**
 * Class MainWP_Rest_Updates_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Updates_Controller extends MainWP_REST_Controller{ //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'mainwp/v2';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'updates';


    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return static::$instance
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method register_routes()
     *
     * Creates the necessary endpoints for the api.
     * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
     */
    public function register_routes() { // phpcs:ignore -- NOSONAR - complex.

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[\d]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_updates_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => array(
                        'id_domain' => array(
                            'description' => __( 'Site ID or domain.', 'mainwp' ),
                            'type'        => 'string',
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}$)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_updates_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => array(
                        'id_domain' => array(
                            'description' => __( 'Site ID or domain.', 'mainwp' ),
                            'type'        => 'string',
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        // retrieves all globally ignored updates.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/ignored',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_all_global_ignored_updates' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // retrieves ignored updates for the site by ID or domain.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/ignored',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_ignored_updates_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // triggers the Update Everything process (all items on all sites).
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/update',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_all_items_start' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Triggers the update process on the site by site ID or domain.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/update',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_all_items_inidividual_site_start' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Triggers the WP core update process on site by site ID or domain.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/update/wp',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_wp_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/update/plugins',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_plugins_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/update/themes',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_themes_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/update/translations',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_translations_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/ignore/wp',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'ignore_update_core_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/ignore/plugins',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'ignore_update_plugins_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>[A-Za-z0-9-\.]+)/ignore/themes',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'ignore_update_themes_of_site' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );
    }

    /**
     * Get all Clients.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_items( $request ) { //phpcs:ignore -- NOSONAR complex function.

        $all_updates = array();
        $websites    = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( true, null, 'wp.url', false, false, null, false, array( 'ignored_wp_upgrades', 'rollback_updates_data' ) ) );

        $args = $this->prepare_objects_query( $request );
        $args = $this->validate_rest_args( $args, $this->get_validate_args_params( 'get_sites' ) );
        if ( is_wp_error( $args ) ) {
            return $args;
        }

        $type   = isset( $args['type'] ) && ! empty( $args['type'] ) ? wp_parse_list( $args['type'] ) : array();
        $type   = array_filter( array_map( 'trim', $type ) );
        $s      = isset( $args['s'] ) ? trim( $args['s'] ) : '';
        $exclud = isset( $args['exclude'] ) ? wp_parse_id_list( $args['exclude'] ) : array();
        $includ = isset( $args['include'] ) ? wp_parse_id_list( $args['include'] ) : array();

        if ( empty( $type ) ) {
            $type[] = 'all';
        }

        $all = in_array( 'all', $type ) ? true : false;

        $userExtension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $decodedIgnoredCores   = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        $decodedIgnoredThemes  = json_decode( $userExtension->ignored_themes, true );
        $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );

        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        if ( ! is_array( $decodedIgnoredPlugins ) ) {
            $decodedIgnoredPlugins = array();
        }

        if ( ! is_array( $decodedIgnoredThemes ) ) {
            $decodedIgnoredThemes = array();
        }

        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {

            if ( ! empty( $exclud ) && in_array( $website->id, $exclud ) ) {
                continue;
            }

            if ( ! empty( $includ ) && ! in_array( $website->id, $includ ) ) {
                continue;
            }

            $wp_upgrades          = MainWP_DB::instance()->get_json_website_option( $website, 'wp_upgrades' );
            $plugin_upgrades      = json_decode( $website->plugin_upgrades, true );
            $theme_upgrades       = json_decode( $website->theme_upgrades, true );
            $translation_upgrades = json_decode( $website->translation_upgrades, true );

            if ( $website->is_ignoreCoreUpdates ) {
                $wp_upgrades = array();
            }

            if ( $website->is_ignorePluginUpdates ) {
                $plugin_upgrades = array();
            }

            if ( $website->is_ignoreThemeUpdates ) {
                $theme_upgrades = array();
            }

            if ( ! is_array( $wp_upgrades ) ) {
                $wp_upgrades = true;
            }

            if ( ! empty( $plugin_upgrades ) ) {
                $ignored_plugins = ! empty( $website->ignored_plugins ) ? json_decode( $website->ignored_plugins, true ) : false;
                if ( is_array( $ignored_plugins ) && ! empty( $ignored_plugins ) ) {
                    $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

                }
                if ( ! empty( $decodedIgnoredPlugins ) ) {
                    $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $decodedIgnoredPlugins );
                }
            }

            if ( ! empty( $theme_upgrades ) ) {
                $ignored_themes = ! empty( $website->ignored_themes ) ? json_decode( $website->ignored_themes, true ) : false;
                if ( is_array( $ignored_themes ) && ! empty( $ignored_themes ) ) {
                    $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                }
                if ( ! empty( $decodedIgnoredThemes ) ) {
                    $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $decodedIgnoredThemes );
                }
            }

            if ( ! empty( $wp_upgrades ) ) {
                $ignored_wp = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : false;
                if ( is_array( $ignored_wp ) && ! empty( $ignored_wp ) && MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_wp, 'core' ) ) {
                    $wp_upgrades = array();
                }
                if ( ! empty( $decodedIgnoredCores ) && ! empty( $wp_upgrades ) && MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
                    $wp_upgrades = array();
                }
            }

            $roll_data        = MainWP_Updates_Helper::instance()->get_roll_items_updates_of_site( $website );
            $rollback_plugins = ! empty( $roll_data['plugins'] ) ? $roll_data['plugins'] : array();
            $rollback_themes  = ! empty( $roll_data['themes'] ) ? $roll_data['themes'] : array();

            if ( ! empty( $s ) ) {
                $wp_upgrades          = mainwp_search_in_array( $wp_upgrades, $s );
                $plugin_upgrades      = mainwp_search_in_array( $plugin_upgrades, $s, array( 'in_sub_fields' => 'Name' ) );
                $theme_upgrades       = mainwp_search_in_array( $theme_upgrades, $s, array( 'in_sub_fields' => 'Name' ) );
                $translation_upgrades = mainwp_search_in_array( $translation_upgrades, $s, array( 'in_sub_fields' => 'Name' ) );
                $rollback_plugins     = mainwp_search_in_array( $rollback_plugins, $s, array( 'in_sub_fields' => 'Name' ) );
                $rollback_themes      = mainwp_search_in_array( $rollback_themes, $s, array( 'in_sub_fields' => 'Name' ) );
            }

            if ( $all || in_array( 'wp', $type ) ) {
                $all_updates[ $website->id ]['wp'] = $wp_upgrades;
            }

            if ( $all || in_array( 'plugins', $type ) ) {
                $all_updates[ $website->id ]['plugins'] = $plugin_upgrades;
                if ( ! empty( $rollback_plugins ) ) {
                    $all_updates[ $website->id ]['rollback_plugins'] = $rollback_plugins;
                }
            }

            if ( $all || in_array( 'themes', $type ) ) {
                $all_updates[ $website->id ]['themes'] = $theme_upgrades;
                if ( ! empty( $rollback_themes ) ) {
                    $all_updates[ $website->id ]['rollback_themes'] = $rollback_themes;
                }
            }

            if ( $all || in_array( 'translations', $type ) ) {
                $all_updates[ $website->id ]['translations'] = $translation_upgrades;
            }
        }

        MainWP_DB::free_result( $websites );

        // get data.
        $data = $all_updates;

        $resp_data = array(
            'success' => 1,
            'data'    => $data,
        );
        return rest_ensure_response( $resp_data );
    }


    /**
     * Get site by.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|Object Item.
     */
    public function get_request_item( $request ) {
        $route = $request->get_route();
        if ( MainWP_Utility::string_ends_by( $route, '/batch' ) ) {
            $by    = 'id';
            $value = $request['id'];
        } else {
            $value = $request['id_domain'];
            $by    = 'domain';
            if ( is_numeric( $value ) ) {
                $by = 'id';
            } else {
                $value = urldecode( $value );
            }
        }
        return $this->get_site_by( $by, $value );
    }

    /**
     * Get updates of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_updates_of_site( $request ) { //phpcs:ignore -- NOSONAR complex function.

        $item = $this->get_request_item( $request );

        if ( is_wp_error( $item ) ) {
            return $item;
        }

        $userExtension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $decodedIgnoredCores   = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        $decodedIgnoredThemes  = json_decode( $userExtension->ignored_themes, true );
        $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );

        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        if ( ! is_array( $decodedIgnoredPlugins ) ) {
            $decodedIgnoredPlugins = array();
        }

        if ( ! is_array( $decodedIgnoredThemes ) ) {
            $decodedIgnoredThemes = array();
        }

        $rollback_plugins = array();
        $rollback_themes  = array();

        // get data.
        $website = MainWP_DB::instance()->get_website_by_id( $item->id, false, array( 'ignored_wp_upgrades', 'wp_upgrades', 'rollback_updates_data' ) );

        $wp_upgrades          = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
        $plugin_upgrades      = ! empty( $website->plugin_upgrades ) ? json_decode( $website->plugin_upgrades, true ) : array();
        $theme_upgrades       = ! empty( $website->theme_upgrades ) ? json_decode( $website->theme_upgrades, true ) : array();
        $translation_upgrades = ! empty( $website->translation_upgrades ) ? json_decode( $website->translation_upgrades, true ) : array();

        if ( ! is_array( $wp_upgrades ) ) {
            $wp_upgrades = array();
        }

        if ( ! is_array( $plugin_upgrades ) ) {
            $plugin_upgrades = array();
        }

        if ( ! is_array( $theme_upgrades ) ) {
            $theme_upgrades = array();
        }

        if ( ! is_array( $translation_upgrades ) ) {
            $translation_upgrades = array();
        }

        if ( $website->is_ignoreCoreUpdates ) {
            $wp_upgrades = array();
        }

        if ( $website->is_ignorePluginUpdates ) {
            $plugin_upgrades = array();
        }

        if ( $website->is_ignoreThemeUpdates ) {
            $theme_upgrades = array();
        }

        if ( ! empty( $wp_upgrades ) ) {
            $ignored_wp = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : false;
            if ( is_array( $ignored_wp ) && ! empty( $ignored_wp ) && MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_wp, 'core' ) ) {
                $wp_upgrades = array();
            }
            if ( ! empty( $decodedIgnoredCores ) && ! empty( $wp_upgrades ) && MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
                $wp_upgrades = array();
            }
        }

        if ( ! empty( $plugin_upgrades ) ) {
            $ignored_plugins = ! empty( $website->ignored_plugins ) ? json_decode( $website->ignored_plugins, true ) : false;
            if ( is_array( $ignored_plugins ) && ! empty( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

            }
            if ( ! empty( $decodedIgnoredPlugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $decodedIgnoredPlugins );
            }
        }

        if ( ! empty( $theme_upgrades ) ) {
            $ignored_themes = ! empty( $website->ignored_themes ) ? json_decode( $website->ignored_themes, true ) : false;
            if ( is_array( $ignored_themes ) && ! empty( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }
            if ( ! empty( $decodedIgnoredThemes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $decodedIgnoredThemes );
            }
        }

        $args = $this->prepare_objects_query( $request );

        $type = isset( $args['type'] ) && ! empty( $args['type'] ) ? wp_parse_list( $args['type'] ) : array();
        $type = array_filter( array_map( 'trim', $type ) );
        $s    = isset( $args['s'] ) ? trim( $args['s'] ) : '';

        if ( empty( $type ) ) {
            $type[] = 'all';
        }

        $all = in_array( 'all', $type ) ? true : false;

        $roll_data = MainWP_Updates_Helper::instance()->get_roll_items_updates_of_site( $website );

        if ( ( $all || in_array( 'plugins', $type ) ) && ! empty( $roll_data['plugins'] ) ) {
            $rollback_plugins = $roll_data['plugins'];
        }

        if ( ( $all || in_array( 'themes', $type ) ) && ! empty( $roll_data['themes'] ) ) {
            $rollback_themes = $roll_data['themes'];
        }

        if ( ! is_array( $rollback_plugins ) ) {
            $rollback_plugins = array();
        }

        if ( ! is_array( $rollback_themes ) ) {
            $rollback_themes = array();
        }

        if ( ! empty( $s ) ) {
            $wp_upgrades          = mainwp_search_in_array( $wp_upgrades, $s );
            $plugin_upgrades      = mainwp_search_in_array( $plugin_upgrades, $s, array( 'in_sub_fields' => 'Name' ) );
            $theme_upgrades       = mainwp_search_in_array( $theme_upgrades, $s, array( 'in_sub_fields' => 'Name' ) );
            $translation_upgrades = mainwp_search_in_array( $translation_upgrades, $s, array( 'in_sub_fields' => 'Name' ) );
            $rollback_plugins     = mainwp_search_in_array( $rollback_plugins, $s, array( 'in_sub_fields' => 'Name' ) );
            $rollback_themes      = mainwp_search_in_array( $rollback_themes, $s, array( 'in_sub_fields' => 'Name' ) );
        }

        if ( $all || in_array( 'wp', $type ) ) {
            $data['wp'] = $wp_upgrades;
        }

        if ( $all || in_array( 'plugins', $type ) ) {
            $data['plugins'] = $plugin_upgrades;
            if ( ! empty( $rollback_plugins ) ) {
                $data['rollback_plugins'] = $rollback_plugins;
            }
        }

        if ( $all || in_array( 'themes', $type ) ) {
            $data['themes'] = $theme_upgrades;
            if ( ! empty( $rollback_themes ) ) {
                $data['rollback_themes'] = $rollback_themes;
            }
        }

        if ( $all || in_array( 'translations', $type ) ) {
            $data['translations'] = $translation_upgrades;
        }

        $resp_data = array(
            'success' => 1,
            'data'    => $data,
        );
        return rest_ensure_response( $resp_data );
    }


    /**
     * Get global ignored updates.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_all_global_ignored_updates( $request ) { //phpcs:ignore -- NOSONAR - complex.

        $userExtension   = MainWP_DB_Common::instance()->get_user_extension();
        $ignored_plugins = ! empty( $userExtension->ignored_plugins ) ? json_decode( $userExtension->ignored_plugins, true ) : array();
        $ignored_themes  = ! empty( $userExtension->ignored_themes ) ? json_decode( $userExtension->ignored_themes, true ) : array();

        if ( ! is_array( $ignored_plugins ) ) {
            $ignored_plugins = array();
        }
        if ( ! is_array( $ignored_themes ) ) {
            $ignored_themes = array();
        }

        $args = $this->prepare_objects_query( $request );

        $type = isset( $args['type'] ) && ! empty( $args['type'] ) ? wp_parse_list( $args['type'] ) : array();
        $type = array_filter( array_map( 'trim', $type ) );
        $s    = isset( $args['s'] ) ? trim( $args['s'] ) : '';

        if ( empty( $type ) || ( ! in_array( 'plugins', $type ) && ! in_array( 'themes', $type ) ) ) {
            $type[] = 'all';
        }

        $all = in_array( 'all', $type ) ? true : false;

        if ( ! empty( $s ) ) {
            $ignored_plugins = mainwp_search_in_array( $ignored_plugins, $s, array( 'in_sub_fields' => 'Name' ) );
            $ignored_themes  = mainwp_search_in_array( $ignored_themes, $s, array( 'in_sub_fields' => 'Name' ) );
        }

        $data = array();

        if ( ( $all || in_array( 'plugins', $type ) ) && ! empty( $ignored_plugins ) ) {
            $data['plugins'] = $ignored_plugins;
        }

        if ( ( $all || in_array( 'themes', $type ) ) && ! empty( $ignored_themes ) ) {
            $data['themes'] = $ignored_themes;
        }

        $resp_data = array(
            'success' => 1,
            'data'    => $data,
        );

        return rest_ensure_response( $resp_data );
    }


    /**
     * Get ignored updates of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function get_ignored_updates_of_site( $request ) { //phpcs:ignore -- NOSONAR complex function.

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $ignored_plugins = ! empty( $website->ignored_plugins ) ? json_decode( $website->ignored_plugins, true ) : array();
        $ignored_themes  = ! empty( $website->ignored_themes ) ? json_decode( $website->ignored_themes, true ) : array();

        if ( ! is_array( $ignored_plugins ) ) {
            $ignored_plugins = array();
        }
        if ( ! is_array( $ignored_themes ) ) {
            $ignored_themes = array();
        }

        $userExtension    = MainWP_DB_Common::instance()->get_user_extension();
        $ignored_plugins2 = ! empty( $userExtension->ignored_plugins ) ? json_decode( $userExtension->ignored_plugins, true ) : array();
        $ignored_themes2  = ! empty( $userExtension->ignored_plugins ) ? json_decode( $userExtension->ignored_themes, true ) : array();

        if ( ! is_array( $ignored_plugins2 ) ) {
            $ignored_plugins2 = array();
        }
        if ( ! is_array( $ignored_themes2 ) ) {
            $ignored_themes2 = array();
        }

        $ignored_plugins = $ignored_plugins + $ignored_plugins2;
        $ignored_themes  = $ignored_themes + $ignored_themes2;

        $args = $this->prepare_objects_query( $request );

        $type = isset( $args['type'] ) && ! empty( $args['type'] ) ? wp_parse_list( $args['type'] ) : array();
        $type = array_filter( array_map( 'trim', $type ) );

        $s = isset( $args['s'] ) ? trim( $args['s'] ) : '';

        if ( empty( $type ) || ( ! in_array( 'plugins', $type ) && ! in_array( 'themes', $type ) ) ) {
            $type[] = 'all';
        }

        $all = in_array( 'all', $type ) ? true : false;

        if ( ! empty( $s ) ) {
            $ignored_plugins = mainwp_search_in_array( $ignored_plugins, $s, array( 'in_sub_fields' => 'Name' ) );
            $ignored_themes  = mainwp_search_in_array( $ignored_themes, $s, array( 'in_sub_fields' => 'Name' ) );
        }

        $data = array();

        if ( ( $all || in_array( 'plugins', $type ) ) && ! empty( $ignored_plugins ) ) {
            $data['plugins'] = $ignored_plugins;
        }

        if ( ( $all || in_array( 'themes', $type ) ) && ! empty( $ignored_themes ) ) {
            $data['themes'] = $ignored_themes;
        }

        $resp_data = array(
            'success' => 1,
            'data'    => $data,
        );

        return rest_ensure_response( $resp_data );
    }


    /**
     * Update all items.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_all_items_start( $request ) {

        $batch_updates_running = get_option( 'mainwp_batch_updates_is_running', 0 );
        $start_time            = get_option( 'mainwp_batch_updates_start_time', 0 );

        if ( $batch_updates_running ) {
            $datetime  = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $start_time ) );
            $resp_data = array(
                'success'                       => 1,
                'message'                       => esc_html__( 'A batch updates all job are running.', 'mainwp' ),
                'last_time_start_batch_updates' => mainwp_rest_prepare_date_response( $datetime ),
            );
            return rest_ensure_response( $resp_data );
        }

        $local_timestamp = MainWP_Utility::get_timestamp();
        $start_time      = $local_timestamp;
        MainWP_Utility::update_option( 'mainwp_batch_updates_start_time', $start_time );
        MainWP_Utility::update_option( 'mainwp_batch_updates_is_running', 1 ); // to start perform batch update job.

        $websites = MainWP_Auto_Updates_DB::instance()->get_websites_to_start_updates( true, true );
        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
            MainWP_Cron_Jobs_Batch::instance()->prepare_bulk_updates( $website );
            $websiteValues = array(
                'dtsAutomaticSyncStart' => $start_time,
            );
            MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );
        }
        MainWP_DB::free_result( $websites );

        MainWP_Logger::instance()->log_update_check( 'Batch updates started' );

        $msg = esc_html__( 'Batch updates all started successfully.', 'mainwp' );

        $resp_data = array(
            'success' => 1,
            'message' => $msg,
        );

        $datetime                                   = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $start_time ) );
        $resp_data['last_time_start_batch_updates'] = mainwp_rest_prepare_date_response( $datetime );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Update all items of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_all_items_inidividual_site_start( $request ) {
        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $fetch_one = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( true, null, 'wp.url', false, false, null, false, array( 'ignored_wp_upgrades', 'batch_individual_queue_time', 'premium_upgrades' ), 'no', array( 'include' => array( $website->id ) ) ) );
        $website   = $fetch_one ? MainWP_DB::fetch_object( $fetch_one ) : false;
        MainWP_DB::free_result( $fetch_one );

        if ( empty( $website ) ) {
            return $this->get_rest_data_error( 'id', 'site' );
        }

        if ( 1 === $website->suspended ) {
            return new \WP_Error( 'mainwp_rest_updates_site_error', __( 'Website suspended. Please unsuspended the website and try again.', 'mainwp' ), array( 'status' => 400 ) );

        }

        $batch_individual_updates_running = get_option( 'mainwp_batch_individual_updates_is_running', 0 );
        $start_individual_time            = get_option( 'mainwp_batch_updates_individual_start_time', 0 );

        // check if the site in batch updates queue.
        if ( $batch_individual_updates_running && ! empty( $website->batch_individual_queue_time ) && (int) $website->batch_individual_queue_time >= (int) $start_individual_time ) {
            $datetime  = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $start_individual_time ) );
            $resp_data = array(
                'success'                       => 1,
                'message'                       => esc_html__( 'A batch updates for the site are queuing to run.', 'mainwp' ),
                'last_time_start_batch_updates' => mainwp_rest_prepare_date_response( $datetime ),
                'site'                          => apply_filters( 'mainwp_rest_routes_sites_controller_filter_allowed_fields_by_context', $website, 'simple_view' ),

            );
            return rest_ensure_response( $resp_data );
        }

        // reload full data.
        $website = MainWP_DB::instance()->get_website_by_id( $website->id );
        MainWP_Cron_Jobs_Batch::instance()->prepare_bulk_updates( $website );

        $local_timestamp = MainWP_Utility::get_timestamp();

        if ( ! $batch_individual_updates_running ) {
            $start_individual_time = $local_timestamp;
            MainWP_Utility::update_option( 'mainwp_batch_updates_individual_start_time', $start_individual_time ); // individual site batch start time.
            MainWP_Utility::update_option( 'mainwp_batch_individual_updates_is_running', 1 ); // individual site batch start time.
            MainWP_Logger::instance()->log_update_check( 'Batch individual updates start: [websiteid=' . $website->id . ']' );
        }

        MainWP_DB::instance()->update_website_option( $website, 'batch_individual_queue_time', $local_timestamp ); // init queue time batch updates process.

        $msg = esc_html__( 'Batch updates for the site started successfully. Please wait to completed.', 'mainwp' );

        $resp_data = array(
            'success' => 1,
            'message' => $msg,
        );

        $datetime                                   = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $start_individual_time ) );
        $resp_data['last_time_start_batch_updates'] = mainwp_rest_prepare_date_response( $datetime );
        $resp_data['site']                          = apply_filters( 'mainwp_rest_routes_sites_controller_filter_allowed_fields_by_context', $website, 'simple_view' );
        return rest_ensure_response( $resp_data );
    }

    /**
     * Update core of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_wp_site( $request ) {

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $success = false;
        $error   = '';
        $data    = array();

        // get data.
        try {
            $info = MainWP_Updates_Handler::upgrade_website( $website );

            if ( ! is_array( $info ) ) {
                $info = array();
            }

            if ( isset( $info['upgrade'] ) && ( 'SUCCESS' === $info['upgrade'] ) ) {
                $success = true;
            } elseif ( ! empty( $info['error'] ) ) {
                $error = esc_html( $error );
            } else {
                $error = esc_html__( 'An undefined error occured. Please try again.', 'mainwp' );
            }

            if ( ! empty( $info['old_version'] ) ) {
                $data['old_version'] = $info['old_version'];
            }

            if ( ! empty( $info['version'] ) ) {
                $data['version'] = $info['version'];
            }
        } catch ( \Exception $e ) {
            $error = MainWP_Error_Helper::get_console_error_message( $e );
        }

        $resp_data = array(
            'success' => $success ? 1 : 0,
        );

        if ( $success ) {
            $resp_data['message'] = esc_html__( 'WordPress was updated to the latest version.', 'mainwp' );
        }

        if ( ! empty( $error ) ) {
            $resp_data['error'] = $error;
        }

        if ( ! empty( $data ) ) {
            $resp_data['data'] = $data;
        }

        return rest_ensure_response( $resp_data );
    }

    /**
     * Update plugins of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_plugins_site( $request ) {  //phpcs:ignore -- NOSONAR - complex.

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $prepared_slugs = ! empty( $request['slug'] ) ? array_map( 'trim', wp_parse_list( $request['slug'] ) ) : array();
        if ( ! is_array( $prepared_slugs ) ) {
            $prepared_slugs = array();
        }

        if ( $website->is_ignorePluginUpdates ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => esc_html__( 'Plugins ignored updates per site.', 'mainwp' ),
                )
            );
        }

        $plugin_upgrades = json_decode( $website->plugin_upgrades, true );

        if ( is_array( $plugin_upgrades ) && ! empty( $plugin_upgrades ) ) {
            $ignored_plugins = ! empty( $website->ignored_plugins ) ? json_decode( $website->ignored_plugins, true ) : false;
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

            }

            $userExtension = MainWP_DB_Common::instance()->get_user_extension();

            $ignored_plugins = ! empty( $userExtension->ignored_plugins ) ? json_decode( $userExtension->ignored_plugins, true ) : false;
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
            }
        }

        $slugs = array();

        foreach ( $plugin_upgrades as $slug => $plugin ) {
            $slugs[] = $slug;
        }

        $slugs = array_intersect( $slugs, $prepared_slugs );

        if ( empty( $slugs ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => esc_html__( 'No Plugins to update.', 'mainwp' ),
                )
            );
        }

        $result = array();

        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'upgradeplugintheme',
                array(
                    'type' => 'plugin',
                    'list' => urldecode( implode( ',', $slugs ) ),
                )
            );

            $result = Rest_Api_V1_Helper::instance()->handle_site_update_item( $website->id, 'plugin', $information );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'mainwp_rest_upgrade_plugins_of_site_error', $e->getMessage() );
        }

        $resp_data = array(
            'success' => 1,
            'data'    => is_array( $result ) && ! empty( $result['updates_info'] ) ? $result['updates_info'] : array(),
        );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Update themes of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_themes_site( $request ) { //phpcs:ignore -- NOSONAR complex function.

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        if ( $website->is_ignoreThemeUpdates ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => esc_html__( 'Themes ignored updates per site.', 'mainwp' ),
                )
            );
        }

        $prepared_slugs = ! empty( $request['slug'] ) ? array_map( 'trim', wp_parse_list( $request['slug'] ) ) : array();
        if ( ! is_array( $prepared_slugs ) ) {
            $prepared_slugs = array();
        }

        $theme_upgrades = ! empty( $website->theme_upgrades ) ? json_decode( $website->theme_upgrades, true ) : array();

        if ( is_array( $theme_upgrades ) ) {
            $ignored_themes = ! empty( $website->ignored_themes ) ? json_decode( $website->ignored_themes, true ) : false;
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }

            $userExtension = MainWP_DB_Common::instance()->get_user_extension();

            $ignored_themes = ! empty( $userExtension->ignored_themes ) ? json_decode( $userExtension->ignored_themes, true ) : false;
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }
        }

        $slugs = array();
        if ( is_array( $theme_upgrades ) ) {
            foreach ( $theme_upgrades as $slug => $theme ) {
                $slugs[] = $slug;
            }
        }

        $slugs = array_intersect( $slugs, $prepared_slugs );

        if ( empty( $slugs ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => esc_html__( 'No Themes to update.', 'mainwp' ),
                )
            );
        }

        $result = array();

        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'upgradeplugintheme',
                array(
                    'type' => 'theme',
                    'list' => urldecode( implode( ',', $slugs ) ),
                )
            );

            $result = Rest_Api_V1_Helper::instance()->handle_site_update_item( $website->id, 'theme', $information );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'mainwp_rest_upgrade_themes_of_site_error', $e->getMessage() );
        }

        $resp_data = array(
            'success' => 1,
            'data'    => is_array( $result ) && ! empty( $result['updates_info'] ) ? $result['updates_info'] : array(),
        );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Update translations of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function update_translations_site( $request ) {

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $prepared_slugs = ! empty( $request['slug'] ) ? array_map( 'trim', wp_parse_list( $request['slug'] ) ) : array();
        if ( ! is_array( $prepared_slugs ) ) {
            $prepared_slugs = array();
        }

        // get data.
        $translation_upgrades = ! empty( $website->translation_upgrades ) ? json_decode( $website->translation_upgrades, true ) : array();
        $slugs                = array();
        if ( is_array( $translation_upgrades ) ) {
            foreach ( $translation_upgrades as $translation_upgrade ) {
                $slugs[] = $translation_upgrade['slug'];
            }
        }

        $slugs = array_intersect( $slugs, $prepared_slugs );

        if ( empty( $slugs ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => esc_html__( 'No Translations to update.', 'mainwp' ),
                )
            );
        }

        try {
            MainWP_Connect::fetch_url_authed(
                $website,
                'upgradetranslation',
                array(
                    'type' => 'translation',
                    'list' => urldecode( implode( ',', $slugs ) ),
                )
            );
            $result = Rest_Api_V1_Helper::instance()->handle_site_update_item( $website->id, 'translation', $information );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'mainwp_rest_upgrade_translations_of_site_error', $e->getMessage() );
        }

        $resp_data = array(
            'success' => 1,
            'data'    => is_array( $result ) && ! empty( $result['updates_info'] ) ? $result['updates_info'] : array(),
        );

        return rest_ensure_response( $resp_data );
    }

        /**
         * Ignore Update plugins of site.
         *
         * @param WP_REST_Request $request Full details about the request.
         * @return WP_Error|WP_REST_Response
         */
    public function ignore_update_plugins_of_site( $request ) {
        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }
        return $this->ignore_update_item_of_site( $request, $website, 'plugin' );
    }

    /**
     * Ignore Update plugins of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function ignore_update_themes_of_site( $request ) {

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        return $this->ignore_update_item_of_site( $request, $website, 'theme' );
    }

    /**
     * Ignore Update plugins of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @param object          $website website.
     * @param string          $type type ignore.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function ignore_update_item_of_site( $request, $website, $type ) {
        // get parameters.

        $slug = isset( $request['slug'] ) ? array_map( 'trim', wp_parse_list( $request['slug'] ) ) : array();

        if ( ! is_array( $slug ) ) {
            $slug = array();
        }

        $resp_data = array(
            'success' => 1,
        );

        if ( ! empty( $slug ) ) {
            $results           = $this->ignore_updates_batch_items( $type, $slug, $website );
            $resp_data['data'] = $results;
        } elseif ( 'plugin' === $type ) {
            $newValues = array( 'is_ignorePluginUpdates' => 1 );
            MainWP_DB::instance()->update_website_values( $website->id, $newValues );
            $website              = MainWP_DB::instance()->get_website_by_id( $website->id );
            $succes               = $website->is_ignorePluginUpdates;
            $resp_data['success'] = $succes ? 1 : 0;
            $resp_data['message'] = $succes ? esc_html__( 'Ignore update plugins per site successfully.', 'mainwp' ) : esc_html__( 'Ignore update plugins per site failed', 'mainwp' );
        } elseif ( 'theme' === $type ) {
            $newValues = array( 'is_ignoreThemeUpdates' => 1 );
            MainWP_DB::instance()->update_website_values( $website->id, $newValues );
            $website              = MainWP_DB::instance()->get_website_by_id( $website->id );
            $succes               = $website->is_ignoreThemeUpdates;
            $resp_data['success'] = $succes ? 1 : 0;
            $resp_data['message'] = $succes ? esc_html__( 'Ignore update themes per site successfully.', 'mainwp' ) : esc_html__( 'Ignore update themes per site failed', 'mainwp' );
        }
        $resp_data['url'] = $website->url;
        return rest_ensure_response( $resp_data );
    }

    /**
     * Add a plugin or theme to the ignor list.
     *
     * @param mixed $type plugin|theme.
     * @param mixed $slugs Plugin or Theme Slug.
     * @param mixed $website Child Site.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function ignore_updates_batch_items( $type, $slugs, $website ) { // phpcs:ignore -- NOSONAR - complex.

        $data = array();

        if ( 'plugin' === $type ) {

            $decodedIgnoredPlugins = ! empty( $website->ignored_plugins ) ? json_decode( $website->ignored_plugins, true ) : array();

            if ( ! is_array( $decodedIgnoredPlugins ) ) {
                $decodedIgnoredPlugins = array();
            }

            foreach ( $slugs as $_slug  => $name ) {
                if ( ! isset( $decodedIgnoredPlugins[ $_slug ] ) ) {
                    $decodedIgnoredPlugins[ $_slug ] = array(
                        'Name'             => urldecode( $name ),
                        'ignored_versions' => array( 'all_versions' ),
                    );
                    $data[ $_slug ]                  = 'success';
                }
            }

            /**
            * Action: mainwp_before_plugin_ignore
            *
            * Fires before plugin ignore.
            *
            * @since 4.1
            */
            do_action( 'mainwp_before_plugin_ignore', $decodedIgnoredPlugins, $website );

            MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ) ) );
            /**
            * Action: mainwp_after_plugin_ignore
            *
            * Fires after plugin ignore.
            *
            * @since 4.1
            */
            do_action( 'mainwp_after_plugin_ignore', $decodedIgnoredPlugins, $website );

        } elseif ( 'theme' === $type ) {

            $decodedIgnoredThemes = json_decode( $website->ignored_themes, true );

            if ( ! is_array( $decodedIgnoredThemes ) ) {
                $decodedIgnoredThemes = array();
            }

            foreach ( $slugs as $_slug  => $name ) {
                if ( ! isset( $decodedIgnoredThemes[ $_slug ] ) ) {
                    $decodedIgnoredThemes[ $_slug ] = array(
                        'Name'             => urldecode( $name ),
                        'ignored_versions' => array( 'all_versions' ),
                    );
                    $data[ $_slug ]                 = 'success';
                }
            }

                /**
                * Action: mainwp_before_theme_ignore
                *
                * Fires before theme ignore.
                *
                * @since 4.1
                */
                do_action( 'mainwp_before_theme_ignore', $decodedIgnoredThemes, $website );
                MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ) ) );
                /**
                * Action: mainwp_after_theme_ignore
                *
                * Fires after theme ignore.
                *
                * @since 4.1
                */
                do_action( 'mainwp_after_theme_ignore', $website, $decodedIgnoredThemes );
        }

        return $data;
    }

    /**
     * Ignore Update core of site.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function ignore_update_core_of_site( $request ) {

        $website = $this->get_request_item( $request );

        if ( is_wp_error( $website ) ) {
            return $website;
        }

        $newValues = array( 'is_ignoreCoreUpdates' => 1 );

        MainWP_DB::instance()->update_website_values( $website->id, $newValues );

        $website = MainWP_DB::instance()->get_website_by_id( $website->id );

        $success = $website->is_ignoreCoreUpdates ? 1 : 0;

        $resp_data = array(
            'success' => $success,
            'message' => $success ? esc_html__( 'Ignore core update successfully.', 'mainwp' ) : esc_html__( 'Ignore core update failed.', 'mainwp' ),
        );

        return rest_ensure_response( $resp_data );
    }

    /**
     * Get the Tags schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
    public function get_item_schema() {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'tags',
            'type'       => 'object',
            'properties' => array(
                'wp'               => array(
                    'type'              => 'array',
                    'description'       => __( 'WP Core Updates.', 'mainwp' ),
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view' ),
                ),
                'plugins'          => array(
                    'type'              => 'array',
                    'description'       => __( 'Plugin Updates.', 'mainwp' ),
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view' ),
                ),
                'themes'           => array(
                    'type'              => 'array',
                    'description'       => __( 'Theme Updates.', 'mainwp' ),
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view' ),
                ),
                'translation'      => array(
                    'type'              => 'array',
                    'description'       => __( 'Translation Updates.', 'mainwp' ),
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view' ),
                ),
                'rollback_plugins' => array(
                    'type'              => 'array',
                    'description'       => __( 'Rollback Plugins.', 'mainwp' ),
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view' ),
                ),
                'rollback_themes'  => array(
                    'type'              => 'array',
                    'description'       => __( 'Rollback Themes.', 'mainwp' ),
                    'validate_callback' => 'rest_validate_request_arg',
                    'context'           => array( 'view' ),
                ),
            ),
        );
    }
}
