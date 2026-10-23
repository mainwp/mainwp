<?php
/**
 * Exercise the real PHP check and scan handlers using in-memory WP services.
 * The .inc suffix excludes this executable fixture from PHPUnit discovery.
 *
 * @package MainWP/Dashboard/Tests
 */

namespace MainWP\Dashboard {
	function time() {
		return $GLOBALS['clock'];
	}
	class MainWP_DB {
		public static function instance() {
			return new self();
		}
		public function get_website_option( $site, $key ) {
			return $GLOBALS['site_options'][ $site->id ][ $key ] ?? false;
		}
		public function update_website_option( $site, $key, $value ) {
			$GLOBALS['site_options'][ $site->id ][ $key ] = $value;
		}
		public function update_website_values( $id, $values ) {
			$GLOBALS['site_values'][ $id ] = $values;
		}
		public function get_website_by_id( $id ) {
			return (object) array( 'id' => $id, 'name' => 'QA site' );
		}
	}
	class MainWP_Connect {
		public static function fetch_url_authed( $site, $action, $data = array() ) {
			$GLOBALS['child_request'] = array( $site->id, $action, $data );
			return $GLOBALS['child_reply'];
		}
	}
	class MainWP_System_Utility {
		public static function can_edit_website( $site ) {
			return $GLOBALS['authorized'];
		}
	}
	class MainWP_Post_Base_Handler {
		protected function secure_request( $action ) {
			$GLOBALS['secured_action'] = $action;
		}
	}
	class MainWP_Backup_Run_Cancel {
		public static function process_pending( $lane ) {}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	class WP_Error {}
	class JSON_Response extends \RuntimeException {
		public $data;
		public function __construct( $data ) {
			$this->data = $data;
		}
	}
	function __( $text, $domain ) {
		return $text;
	}
	function esc_html_e( $text, $domain ) {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr_e( $text, $domain ) {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
	function get_option( $key, $default = false ) {
		return $GLOBALS['options'][ $key ] ?? $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['options'][ $key ] = $value;
		$GLOBALS['autoload'][ $key ] = $autoload;
	}
	function get_transient( $key ) {
		$value = $GLOBALS['transients'][ $key ] ?? null;
		return $value && $value['expires'] > $GLOBALS['clock'] ? $value['value'] : false;
	}
	function set_transient( $key, $value, $ttl ) {
		$GLOBALS['transients'][ $key ] = array( 'value' => $value, 'expires' => $GLOBALS['clock'] + $ttl );
	}
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
	function wp_send_json( $data ) {
		throw new JSON_Response( $data );
	}
	function add_query_arg( $query, $url ) {
		return $url . '?' . http_build_query( $query );
	}
	function wp_safe_remote_get( $url, $args ) {
		$GLOBALS['requests'][] = array( 'url' => $url, 'args' => $args );
		$response             = array_shift( $GLOBALS['responses'] );
		return null === $response || ! empty( $response['error'] ) ? new WP_Error() : array(
			'code' => $response['code'] ?? 200,
			'body' => $response['body'] ?? json_encode( $response['data'] ),
		);
	}
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
	function wp_remote_retrieve_response_code( $response ) {
		return $response['code'];
	}
	function wp_remote_retrieve_body( $response ) {
		return $response['body'];
	}
	function apply_filters( $hook, $default, ...$args ) {
		return $GLOBALS['filter_stats'] ?? $default;
	}

	$input                    = json_decode( file_get_contents( 'php://stdin' ), true );
	$GLOBALS['clock']         = 1800000000;
	$GLOBALS['options']       = $input['options'] ?? array();
	$GLOBALS['site_options']  = $input['site_options'] ?? array();
	$GLOBALS['responses']     = $input['responses'] ?? array();
	$GLOBALS['authorized']    = $input['authorized'] ?? true;
	$GLOBALS['child_reply']   = $input['child_reply'] ?? array();
	$GLOBALS['filter_stats']  = $input['filter_stats'] ?? false;
	$GLOBALS['transients']    = array();
	$GLOBALS['requests']      = array();
	$GLOBALS['site_values']   = array();
	$GLOBALS['autoload']      = array();
	$GLOBALS['child_request'] = null;
	$GLOBALS['secured_action'] = null;

	require dirname( __DIR__, 3 ) . '/class/class-mainwp-php-security.php';
	require dirname( __DIR__, 3 ) . '/pages/page-mainwp-security-issues.php';
	require dirname( __DIR__, 3 ) . '/class/class-mainwp-post-handler.php';
	require dirname( __DIR__, 3 ) . '/class/class-mainwp-system-cron-jobs.php';

	$outputs = array();
	foreach ( $input['actions'] as $action ) {
		$site           = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $action['site'] ?? 1 );
		$_REQUEST['id'] = $site->id;
		switch ( $action['method'] ) {
			case 'check':
				$outputs[] = \MainWP\Dashboard\MainWP_PHP_Security::check( $action['version'] );
				break;
			case 'refresh':
				\MainWP\Dashboard\MainWP_PHP_Security::refresh_cache();
				break;
			case 'general_cron':
				$cron = new class() extends \MainWP\Dashboard\MainWP_System_Cron_Jobs {
					public function perform_sequence_process() {}
				};
				$cron->cron_perform_general_process();
				break;
			case 'tick':
				$GLOBALS['clock'] += $action['seconds'];
				break;
			case 'apply':
				$outputs[] = \MainWP\Dashboard\MainWP_PHP_Security::apply_to_stats( $action['stats'], $action['version'] );
				break;
			case 'scan':
				$outputs[] = \MainWP\Dashboard\MainWP_Security_Issues::fetch_security_issues();
				break;
			case 'post':
				$handler = ( new \ReflectionClass( \MainWP\Dashboard\MainWP_Post_Handler::class ) )->newInstanceWithoutConstructor();
				try {
					$handler->mainwp_security_issues_request();
				} catch ( JSON_Response $response ) {
					$outputs[] = $response->data;
				}
				break;
			case 'render':
				ob_start();
				\MainWP\Dashboard\MainWP_Security_Issues::render( $site );
				$outputs[] = ob_get_clean();
				break;
			default:
				throw new \RuntimeException( 'Unknown fixture action' );
		}
	}
	echo json_encode(
		array(
			'outputs'        => $outputs,
			'options'        => $GLOBALS['options'],
			'site_options'   => $GLOBALS['site_options'],
			'site_values'    => $GLOBALS['site_values'],
			'requests'       => $GLOBALS['requests'],
			'autoload'       => $GLOBALS['autoload'],
			'child_request'  => $GLOBALS['child_request'],
			'secured_action' => $GLOBALS['secured_action'],
		)
	);
}
