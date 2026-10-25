<?php
/**
 * Isolated REST operation-rights fixture. Invoked in a fresh PHP subprocess.
 *
 * Uses the real controllers, authentication scope checks and rights evaluator.
 * WordPress requests, filters, storage and transport are test doubles; request
 * schema validation is not exercised here. No database or network is accessed.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard {
	class MainWP_Uptime_Monitoring_Connect {
		const UP = 1;
		const DOWN = 0;
		const PENDING = 2;
		const FIRST = 3;
	}
	class MainWP_Utility {
		public static function string_ends_by( $value, $suffix ) { return str_ends_with( $value, $suffix ); }
	}
	class MainWP_Connect {
		public static function fetch_url_authed( $site, $action, $payload = array() ) {
			throw new \Rest_Rights_Boundary( $action );
		}
		public static function fetch_urls_authed( $sites, $action, ...$args ) {
			throw new \Rest_Rights_Boundary( $action );
		}
	}
	class MainWP_Bulk_Add {
		public static function get_class_name() { return self::class; }
	}
	class MainWP_DB {
		public static function instance() { return new self(); }
		public function get_website_by_id( $id, $groups = false ) {
			return (object) array( 'id' => (int) $id, 'url' => 'https://example.test/', 'translation_upgrades' => '[{"slug":"example/example.php"}]' );
		}
		public function get_website_option( $site, $option ) { return $site->{$option} ?? null; }
		public function get_rest_api_key_by( $id ) { return (object) array( 'key_id' => $id, 'user_id' => 99 ); }
		public function remove_rest_api_key( $id ) { throw new \Rest_Rights_Boundary( 'key deletion' ); }
	}
	class MainWP_DB_Common {
		public static function instance() { return new self(); }
		public function add_tag( $item ) { throw new \Rest_Rights_Boundary( 'tag write' ); }
	}
	class MainWP_DB_Client {
		public static function instance() { return new self(); }
		public function get_client_fields_by_params( $params ) { throw new \Rest_Rights_Boundary( 'client fields read' ); }
	}
	class MainWP_Uptime_Monitoring_Handle {
		public static function get_global_monitoring_settings() { return array( 'active' => 1 ); }
		public static function update_uptime_global_settings( $settings ) { throw new \Rest_Rights_Boundary( 'monitor settings write' ); }
	}
}

namespace {
	$input = json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR );
	define( 'ABSPATH', __DIR__ );
	if ( empty( $input['cost_browser'] ) ) {
		define( 'MAINWP_REST_API_DOING', true );
	}

	class Rest_Rights_Boundary extends \Error {}
	class WP_REST_Controller {
		public function get_public_item_schema() { return array( 'properties' => array() ); }
		public function filter_response_by_context( $data, $context ) { return $data; }
	}
	class WP_REST_Server {
		const CREATABLE = 'POST';
		const EDITABLE = 'POST, PUT, PATCH';
		public function response_to_data( $response, $embed ) { return $response; }
	}
	class WP_Error {
		public function __construct( private $code, private $message = '', private $data = null ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	class WP_REST_Request extends \ArrayObject {
		private $body;
		public function __construct( private $method = 'POST', private $route = '/' ) { parent::__construct( array() ); }
		public function get_method() { return $this->method; }
		public function get_route() { return $this->route; }
		public function get_param( $name ) { return $this[ $name ] ?? null; }
		public function get_params() { return $this->getArrayCopy(); }
		public function set_param( $name, $value ) { $this[ $name ] = $value; }
		public function has_param( $name ) { return $this->offsetExists( $name ); }
		public function get_json_params() { return $this->getArrayCopy(); }
		public function get_body_params() { return $this->getArrayCopy(); }
		public function get_query_params() { return array(); }
		public function get_body() { return $this->body ?? json_encode( (object) $this->getArrayCopy() ); }
		public function set_body( $body ) { $this->body = $body; }
		public function get_header( $name ) { return 'content-type' === $name ? 'application/json' : ''; }
		public function set_default_params( $params ) { $this->exchangeArray( $params ); }
		public function set_body_params( $params ) { $this->exchangeArray( array_merge( $this->getArrayCopy(), $params ) ); }
		public function set_query_params( $params ) { $this->set_body_params( $params ); }
		public function set_attributes( $attributes ) {}
		public function has_valid_params() { return true; }
		public function sanitize_params() { return true; }
	}

	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fixture_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
	function add_action( ...$args ) {}
	function do_action( ...$args ) {}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function __( $text, $domain = '' ) { return $text; }
	function esc_html__( $text, $domain = '' ) { return $text; }
	function get_current_user_id() { return $GLOBALS['fixture']['user']; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_parse_list( $value ) { return is_array( $value ) ? $value : explode( ',', $value ); }
	function wp_is_numeric_array( $value ) { return array_is_list( $value ); }
	function rest_ensure_response( $value ) { return $value; }
	function rest_get_endpoint_args_for_schema( $schema, $method ) { return array(); }
	function apply_filters( $hook, $value, ...$args ) {
		$fixture = &$GLOBALS['fixture'];
		if ( 'mainwp_currentusercan' === $hook ) {
			$fixture['checks'][] = array( $args[0], $args[1], get_current_user_id() );
			return $fixture['rights'][ $args[0] ][ $args[1] ] ?? $value;
		}
		if ( 'mainwp_currentuserallowedaccesssites' === $hook ) {
			return $fixture['sites'];
		}
		$callbacks = $GLOBALS['fixture_hooks'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as list( $callback, $accepted_args ) ) {
				$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}
		return $value;
	}

	$GLOBALS['fixture'] = array_merge(
		array( 'user' => 42, 'rights' => array(), 'checks' => array(), 'sites' => 'all' ),
		$input
	);
	$root = dirname( __DIR__, 2 );
	require $root . '/class/class-mainwp-common-handler.php';
	require $root . '/includes/rest-api/class-mainwp-rest-authentication.php';
	require $root . '/includes/abilities/class-mainwp-abilities-util.php';
	require $root . '/includes/rest-api/controller/version2/class-mainwp-rest-controller.php';
	foreach ( glob( $root . '/includes/rest-api/controller/version2/*.php' ) as $file ) {
		require_once $file;
	}
	require $root . '/includes/rest-api/class-mainwp-rest-server.php';
	require $root . '/modules/cost-tracker/rest-api/version2/class-mainwp-rest-costs-controller.php';

	class Rest_Rights_Users extends MainWP_Rest_Users_Controller {
		protected function get_db_websites_by_filter( $sites, $groups, $clients ) {
			$site = $this->get_site_by( 'id', 1 );
			return is_wp_error( $site ) ? array() : array( 'db_websites' => array( $site ) );
		}
	}
	class Rest_Rights_Keys extends MainWP_Rest_API_Keys_Controller {
		protected function prepare_objects_query( $request, $type = 'object' ) { throw new Rest_Rights_Boundary( 'key list' ); }
	}
	trait Rest_Rights_Batch_Schema {
		public function get_batch_create_args() { return array(); }
		public function get_batch_update_args() { return array(); }
	}
	class Rest_Rights_Batch_Sites extends MainWP_Rest_Sites_Controller {
		use Rest_Rights_Batch_Schema;
	}
	class Rest_Rights_Batch_Clients extends MainWP_Rest_Clients_Controller {
		use Rest_Rights_Batch_Schema;
	}
	class Rest_Rights_Batch_Tags extends MainWP_Rest_Tags_Controller {
		use Rest_Rights_Batch_Schema;
	}
	class Rest_Rights_Batch_Costs extends MainWP_Rest_Costs_Controller {
		use Rest_Rights_Batch_Schema;
	}

	if ( isset( $input['cost_settings_mode'] ) ) {
		require __DIR__ . '/cost-settings-rights.inc';
		exit;
	}

	if ( isset( $input['abilities_mode'] ) ) {
		require __DIR__ . '/rest-abilities-authorization.inc';
		exit;
	}

	$auth = MainWP_REST_Authentication::get_instance();
	( new \ReflectionProperty( $auth, 'user' ) )->setValue(
		$auth,
		! empty( $input['unauthenticated'] ) ? null : (object) array( 'user_id' => 42, 'permissions' => $input['scope'] ?? 'read_write' )
	);
	$request = new WP_REST_Request( $input['http_method'] ?? 'POST', $input['route'] ?? '/mainwp/v2/sites/1' );
	$request->set_body_params( $input['params'] ?? array() );
	$controller = ( new \ReflectionClass( $input['controller'] ) )->newInstanceWithoutConstructor();
	if ( $controller instanceof MainWP_Rest_API_Keys_Controller ) {
		( new \ReflectionProperty( MainWP_Rest_API_Keys_Controller::class, 'db' ) )->setValue( $controller, new \MainWP\Dashboard\MainWP_DB() );
	}
	$method = $input['method'];
	$result = null;
	$boundary = null;
	try {
		if ( 'global_batch' === $method ) {
			$group = $input['batch_group'] ?? 'tags';
			$namespace = ! empty( $input['nested_namespace'] ) ? 'mainwp/v2/' . $group : 'mainwp/v2';
			$registered = array( $namespace => array( $group => $controller ) );
			( new \ReflectionProperty( MainWP_Rest_Server::class, 'controllers' ) )->setValue( MainWP_Rest_Server::instance(), $registered );
			$controller = new MainWP_Rest_Global_Batch_Controller();
			$method = 'batch_items';
		}
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		if ( ! empty( $input['route_check'] ) ) {
			$result = ! empty( $input['unrestricted'] ) ? $controller->get_unrestricted_permissions_check( $request ) : $controller->get_rest_permissions_check( $request );
		}
		if ( ! is_wp_error( $result ) && empty( $input['permission_only'] ) ) {
			$result = $controller->$method( $request );
		}
	} catch ( Rest_Rights_Boundary $stop ) {
		$boundary = $stop->getMessage();
	}
	echo json_encode(
		array(
			'error' => is_wp_error( $result ) ? array( 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ) : null,
			'result' => is_wp_error( $result ) ? null : $result,
			'boundary' => $boundary,
			'checks' => $GLOBALS['fixture']['checks'],
		),
		JSON_THROW_ON_ERROR
	);
}
