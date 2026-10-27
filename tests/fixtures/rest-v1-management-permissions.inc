<?php
/**
 * Isolated legacy REST authentication and management callback probe.
 *
 * Loads the production v1 controller and exception. All credentials are synthetic;
 * option storage, WordPress services and Child transport are doubles. No database,
 * network or plugin/theme filesystem operation is performed.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard {
    class MainWP_Rest_Api_Page {
        public static function check_rest_api_updates() { return $GLOBALS['v1_fixture']['keys']; }
    }
    class MainWP_DB {
        public static function instance() { return new self(); }
        public function get_website_by_id( $id ) {
            ++$GLOBALS['v1_fixture']['db_reads'];
            return (object) array( 'id' => $id );
        }
    }
    class MainWP_Connect {
        public static function fetch_url_authed( &$website, $action, $params ) {
            $GLOBALS['v1_fixture']['calls'][] = array( $action, $params );
            return array( 'status' => 'SUCCESS' );
        }
    }
}

namespace {
    $input = json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR );
    define( 'ABSPATH', __DIR__ );

    class WP_Error {
        public function __construct( public $code, public $message, public $data ) {}
    }
    class WP_REST_Response {
        public $status = 200;
        public function __construct( public $data ) {}
        public function set_status( $status ) { $this->status = $status; }
    }
    class WP_REST_Request extends \ArrayObject {
        public function __construct( private $method, private $route, array $params ) { parent::__construct( $params ); }
        public function get_method() { return $this->method; }
        public function get_route() { return $this->route; }
    }

    function add_action( ...$args ) {}
    function esc_html__( $text, $domain ) { return $text; }
    function esc_html( $text ) { return $text; }
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
    function wp_check_password( $value, $hash ) { return password_verify( $value, $hash ); }
    function apache_request_headers() { return $GLOBALS['v1_fixture']['headers']; }
    function register_rest_route( $namespace, $route, $args ) { $GLOBALS['v1_fixture']['routes'][ '/' . $namespace . $route ] = $args; }

    $credentials = array( 'consumer_key' => 'fixture-key', 'consumer_secret' => 'fixture-secret' );
    $key         = array(
        'enabled'   => 'disabled' === ( $input['failure'] ?? '' ) ? 0 : 1,
        'ck_hashed' => password_hash( 'wrong_key_hash' === ( $input['failure'] ?? '' ) ? 'other-fixture-key' : $credentials['consumer_key'], PASSWORD_BCRYPT, array( 'cost' => 4 ) ),
        'cs'        => password_hash( $credentials['consumer_secret'], PASSWORD_BCRYPT, array( 'cost' => 4 ) ),
    );
    if ( ! array_key_exists( 'permissions', $input ) || null !== $input['permissions'] ) {
        $key['perms'] = $input['permissions'] ?? 'w';
    }
    $GLOBALS['v1_fixture'] = array(
        'keys'     => array( $credentials['consumer_key'] => $key ),
        'headers'  => array(),
        'routes'   => array(),
        'db_reads' => 0,
        'calls'    => array(),
    );
    if ( 'wrong_secret' === ( $input['failure'] ?? '' ) ) {
        $credentials['consumer_secret'] = 'wrong-fixture-secret';
    } elseif ( 'wrong_key' === ( $input['failure'] ?? '' ) ) {
        $credentials['consumer_key'] = 'wrong-fixture-key';
    }

    $root = dirname( __DIR__, 2 );
    require $root . '/class/class-mainwp-exception.php';
    require $root . '/includes/rest-api/controller/version1/class-mainwp-rest-api-v1.php';
    $api = \MainWP\Dashboard\Rest_Api_V1::instance();
    $api->mainwp_register_routes();

    $type      = $input['type'] ?? 'plugin';
    $canonical = '/mainwp/v1/site/site-manage-' . $type;
    $params    = array( 'site_id' => 1, $type => 'fixture-item', 'action' => $input['action'] ?? 'delete' );
    if ( 'header' === ( $input['credentials'] ?? 'query' ) ) {
        $GLOBALS['v1_fixture']['headers']['X-Api-Key'] = json_encode( $credentials, JSON_THROW_ON_ERROR );
    } else {
        $params = array_merge( $params, $credentials );
    }
    $request = new WP_REST_Request( $input['method'] ?? 'POST', $input['route'] ?? $canonical, $params );
    $handler = $GLOBALS['v1_fixture']['routes'][ $canonical ];
    if ( ! empty( $input['auth_only'] ) ) {
        $response = $api->mainwp_validate_request( $request );
        $status   = is_wp_error( $response ) ? $response->data['status'] : ( true === $response ? 200 : 401 );
    } else {
        $response = call_user_func( $handler['callback'], $request );
        $status   = is_wp_error( $response ) ? $response->data['status'] : $response->status;
    }
    // Use the same route pattern as WP_REST_Server::match_request_to_handler().
    $matches_registered_route = (bool) preg_match( '@^' . $canonical . '$@i', $request->get_route() );
    echo json_encode(
        array(
            'status'                   => $status,
            'error_code'               => is_wp_error( $response ) ? $response->code : null,
            'registered_method'        => $handler['methods'],
            'db_reads'                 => $GLOBALS['v1_fixture']['db_reads'],
            'calls'                    => $GLOBALS['v1_fixture']['calls'],
            'rest_doing'               => defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING,
            'matches_registered_route' => $matches_registered_route,
        ),
        JSON_THROW_ON_ERROR
    );
}
