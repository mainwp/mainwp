<?php
/**
 * MainWP setup
 *
 * @package MainWP\Dashboard
 * @since   5.1.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * MainWP Setup Class.
 */
final class MainWP_Setup {

    /**
     * The single instance of the class.
     *
     * @var Setup
     */
    protected static $_instance = null; //phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

    /**
     * API instance
     *
     * @var MainWP_API
     */
    public $api;

    /**
     * Main Instance.
     *
     * Ensures only one instance loaded or can be loaded.
     *
     * @static
     * @return Main instance.
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->includes();
        $this->init_hooks();
    }


    /**
     * Hook into actions and filters.
     *
     * @since 5.1.1
     */
    private function init_hooks() {
        add_action( 'init', array( $this, 'load_rest_api' ) );
    }

    /**
     * Returns true if the request is a non-legacy REST API request.
     *
     * Legacy REST requests should still run some extra code for backwards compatibility.
     *
     * @to_do: replace this function once core WP function is available: https://core.trac.wordpress.org/ticket/42061.
     *
     * @return bool
     */
    public function is_rest_api_request() {

        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }

        $rest_prefix         = trailingslashit( rest_get_url_prefix() );
        $is_rest_api_request = ( false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix ) ); // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        /**
         * Whether this is a REST API request.
         *
         * @since 5.1.1
         */
        return apply_filters( 'mainwp_is_rest_api_request', $is_rest_api_request );
    }


    /**
     * Load REST API.
     */
    public function load_rest_api() {
        MainWP_Rest_Server::instance()->init();
    }


    /**
     * Include required core files used in admin.
     */
    public function includes() {
        /**
         * REST API.
         */
        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version1/class-mainwp-rest-api-v1.php';
        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version1/class-mainwp-rest-api-v1-helper.php';

        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/class-mainwp-rest-authentication.php';
        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/class-mainwp-rest-server.php';

        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-controller.php';
        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-sites-controller.php';
        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-clients-controller.php';
        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-tags-controller.php';
        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-updates-controller.php';
        include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-global-batch-controller.php';
    }
}
MainWP_Setup::instance();
