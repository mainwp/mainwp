<?php
/**
 * REST-to-Abilities authorization probes, loaded by rest-operation-rights.inc.
 *
 * Runs production permissions, REST handlers and cron workers. WordPress hooks,
 * ability execution after permission checks, storage and transport are doubles.
 * Does not test WordPress routing, credential verification or schema validation.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard {
	class MainWP_System_Utility {
		public static function set_time_limit( $seconds ) {}
	}
	class Rest_Rights_Update_Plan {
		public static function build_update_plan( $sites, ...$args ) {
			return array( 'errors' => array(), 'sites' => array( array( 'backup_gate' => array(), 'skipped' => array(), 'items' => array( array( 'type' => 'core', 'slug' => 'wordpress' ) ) ) ) );
		}
	}
}

namespace {
	function current_user_can( $capability ) { return 'manage_options' === $capability && ! empty( $GLOBALS['fixture']['manage_options'] ); }
	function is_user_logged_in() { return get_current_user_id() > 0; }
	function get_userdata( $id ) { return empty( $GLOBALS['fixture']['owner_deleted'] ) ? (object) array( 'ID' => $id ) : false; }
	function wp_set_current_user( $id ) { $GLOBALS['fixture']['user'] = (int) $id; }
	function get_option( $name, $default = false ) { return $default; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	function get_transient( $key ) { return $GLOBALS['fixture_transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $expiry = 0 ) { $GLOBALS['fixture_transients'][ $key ] = $value; }
	function wp_register_ability( $name, $args ) { $GLOBALS['fixture_abilities'][ $name ] = $args; }
	function wp_get_ability( $name ) { return new Rest_Rights_Ability( $name, $GLOBALS['fixture_abilities'][ $name ] ); }

	/** Run registered permissions and, in ability_execute mode, the execution callback. */
	class Rest_Rights_Ability {
		public function __construct( private $name, private $args ) {}
		public function get_name() { return $this->name; }
		public function check_permissions( $input ) { return ( $this->args['permission_callback'] )( $input ); }
		public function execute( $input ) {
			$result = $this->check_permissions( $input );
			if ( is_wp_error( $result ) || true !== $result ) {
				return $result;
			}
			if ( 'ability_execute' === $GLOBALS['fixture']['abilities_mode'] ) {
				return ( $this->args['execute_callback'] )( $input );
			}
			throw new Rest_Rights_Boundary( 'ability:' . $this->name );
		}
	}

	class_alias( MainWP\Dashboard\MainWP_DB::class, 'MainWP_DB' );
	if ( ! array_key_exists( 'sites', $input ) ) {
		$GLOBALS['fixture']['sites'] = array( 1 );
	}
	$auth = MainWP_REST_Authentication::get_instance();
	( new ReflectionProperty( $auth, 'user' ) )->setValue(
		$auth,
		! empty( $input['unauthenticated'] ) ? null : (object) array( 'user_id' => $input['owner'] ?? 42, 'permissions' => $input['scope'] ?? 'read_write' )
	);
	$request = new WP_REST_Request( $input['http_method'] ?? 'GET', $input['route'] ?? '/mainwp/v2/sites/1' );
	$request->set_body_params( $input['params'] ?? array() );
	$result = null;
	$boundary = null;
	$states = array();
	try {
		if ( 'cron' === $input['abilities_mode'] ) {
			define( 'DAY_IN_SECONDS', 86400 );
			class_alias( MainWP\Dashboard\Rest_Rights_Update_Plan::class, MainWP\Dashboard\MainWP_Abilities_Updates::class );
			require $root . '/includes/abilities/class-mainwp-abilities-cron.php';
			add_filter( 'mainwp_abilities_cron_debug_logging', static function () { return false; } );
			set_transient( 'mainwp_update_job_test', array(
				'user_id' => $input['owner'] ?? 42, 'status' => 'queued', 'sites' => array( 1 ), 'processed' => 0,
				'updated' => array(), 'errors' => array(), 'types' => array( 'core' ), 'required_rights' => array( 'update_wordpress' ),
			) );
			MainWP\Dashboard\MainWP_Abilities_Cron::instance()->process_update_job( 'test' );
			$result = get_transient( 'mainwp_update_job_test' );
		} else {
			require $root . '/includes/abilities/class-mainwp-abilities-sites.php';
			require $root . '/includes/abilities/class-mainwp-abilities-updates.php';
			MainWP\Dashboard\MainWP_Abilities_Sites::register();
			MainWP\Dashboard\MainWP_Abilities_Updates::register();
			$begun = empty( $input['no_frame'] );
			if ( $begun ) {
				apply_filters( 'rest_request_before_callbacks', null, array(), $request );
			}
			try {
				if ( 'nested' === $input['abilities_mode'] ) {
					$states[] = MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission();
					$nested = new WP_REST_Request( 'GET', $input['nested_route'] ?? '/wp-abilities/v1/abilities/mainwp/list-sites-v1/run' );
					apply_filters( 'rest_request_before_callbacks', null, array(), $nested );
					$states[] = MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission();
					apply_filters( 'rest_request_after_callbacks', null, array(), $nested );
					$states[] = MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission();
				} elseif ( in_array( $input['abilities_mode'], array( 'ability', 'ability_execute' ), true ) ) {
					$result = wp_get_ability( $input['ability'] )->execute( $input['ability_input'] ?? array() );
				} elseif ( 'gate' === $input['abilities_mode'] ) {
					$result = MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission();
				} else {
					$controller = ( new ReflectionClass( $input['controller'] ) )->newInstanceWithoutConstructor();
					$result = $controller->get_rest_permissions_check( $request );
					if ( ! is_wp_error( $result ) ) {
						$result = $controller->{$input['method']}( $request );
					}
				}
			} finally {
				if ( $begun ) {
					apply_filters( 'rest_request_after_callbacks', null, array(), $request );
				}
			}
		}
	} catch ( Rest_Rights_Boundary $stop ) {
		$boundary = $stop->getMessage();
	}
	$encode_error = static function ( $value ) {
		return is_wp_error( $value ) ? array( 'code' => $value->get_error_code(), 'data' => $value->get_error_data() ) : $value;
	};
	echo json_encode( array(
		'error' => is_wp_error( $result ) ? $encode_error( $result ) : null,
		'result' => is_wp_error( $result ) ? null : $result,
		'boundary' => $boundary,
		'checks' => $GLOBALS['fixture']['checks'],
		'states' => array_map( $encode_error, $states ),
		'after_permission' => $auth->get_abilities_rest_permission(),
		'current_user' => get_current_user_id(),
		'manage_options' => current_user_can( 'manage_options' ),
	), JSON_THROW_ON_ERROR );
}
