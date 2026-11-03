<?php
/**
 * Transport messages at WordPress HTTP, REST and onboarding boundaries.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Api_Manager_Key;
use MainWP\Dashboard\MainWP_Error_Helper;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Server_Information_Handler;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/** Checks the message and response contracts at their public boundaries. */
class Test_Readable_Transport_Surfaces extends \WP_UnitTestCase {

	/**
	 * Keep XML constants from being replayed before the isolated WP bootstrap.
	 *
	 * @param \PHPUnit\Framework\TestResult|null $result Result collector.
	 * @return \PHPUnit\Framework\TestResult
	 */
	public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
		if ( ! in_array( $this->getName( false ), array( 'test_connectivity_wp_errors_and_memoized_probe', 'test_outbound_http_response_is_memoized', 'test_loopback_classification_rule' ), true ) ) {
			return parent::run( $result );
		}
		$configuration                           = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? null;
		$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = '';
		try {
			return parent::run( $result );
		} finally {
			$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration;
		}
	}

	/** License JSON retains diagnostics without allowing markup. */
	public function test_license_wp_error_is_escaped_once(): void {
		$raw  = 'cURL error 2: getaddrinfo() thread failed to start <img src=x> > & "quoted"';
		$http = static function () use ( $raw ) {
			return new \WP_Error( 'http_request_failed', $raw );
		};
		add_filter( 'pre_http_request', $http );
		try {
			MainWP_Api_Manager_Key::instance()->verify_api_key( array() );
			$this->fail( 'A failed HTTP request must throw.' );
		} catch ( MainWP_Exception $error ) {
			$expected = MainWP_Error_Helper::classify_transport_error( null, $raw, 'mainwp_api' );
			$this->assertSame( esc_html( $expected['message'] ), $error->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
	}

	/**
	 * Function-local caches belong to one request, so this needs a fresh process.
	 *
	 * @dataProvider outbound_failures
	 * @param string $raw Transport message.
	 * @param string $reason Expected issue category.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_connectivity_wp_errors_and_memoized_probe( $raw, $reason ): void {
		$requests = array();
		$http     = static function ( $pre, $args, $url ) use ( $raw, &$requests ) {
			$requests[] = array( $url, $args );
			return new \WP_Error( 'http_request_failed', $raw );
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$expected = MainWP_Error_Helper::classify_transport_error( null, $raw, 'mainwp_api' );
			$first    = MainWP_Server_Information_Handler::get_outbound_connections_data();
			$this->assertSame(
				array(
					'value'  => $expected['message'],
					'status' => 'error',
					'reason' => $reason,
				),
				$first
			);
			$this->assertSame( $first, MainWP_Server_Information_Handler::get_outbound_connections_data() );
			$this->assertCount( 1, $requests );
			$this->assertTrue( $requests[0][1]['sslverify'] );
			$this->assertSame( 'https', wp_parse_url( $requests[0][0], PHP_URL_SCHEME ) );
			$this->assertSame( wp_parse_url( \MainWP\Dashboard\MainWP_Api_Manager::instance()->get_upgrade_url(), PHP_URL_HOST ), wp_parse_url( $requests[0][0], PHP_URL_HOST ) );

			foreach ( array( 'get_rest_api_reachability_data', 'get_server_self_connect_data' ) as $method ) {
				$reflection = new \ReflectionMethod( MainWP_Server_Information_Handler::class, $method );
				$reflection->setAccessible( true );
				$result   = $reflection->invoke( null );
				$loopback = MainWP_Error_Helper::classify_transport_error( null, $raw );
				$this->assertSame( 'Request failed: ' . ( 'server_resources' === $reason ? $loopback['message'] : $raw ), $result['value'] );
			}
			$rows = MainWP_Server_Information_Handler::get_connectivity_report_rows();
			$row  = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'Outbound connections' === $row['label'];
					}
				)
			);
			$this->assertCount( 1, $row );
			$this->assertSame( $expected['message'], $row[0]['value'] );
			$renderer = new \ReflectionMethod( '\MainWP\Dashboard\MainWP_Server_Information', 'render_report_row' );
			$renderer->setAccessible( true );
			ob_start();
			$renderer->invoke( null, $row[0] );
			$row_html = ob_get_clean();
			$this->assertStringContainsString( esc_html( $expected['message'] ), $row_html );
			$this->assertStringNotContainsString( '<img src=x>', $row_html );
			$issues = MainWP_Server_Information_Handler::get_primary_issues();
			$this->assertContains( 'Outbound connections', array_column( $issues, 'title' ) );
			$connectivity_titles = array( 'Outbound connections', 'WordPress REST API is not fully reachable', 'Dashboard self-connect check failed' );
			$connectivity_issues = array_values(
				array_filter(
					$issues,
					static function ( $issue ) use ( $connectivity_titles ) {
						return in_array( $issue['title'], $connectivity_titles, true );
					}
				)
			);
			$this->assertCount( 'server_resources' === $reason ? 1 : 3, $connectivity_issues );
			if ( 'server_resources' === $reason ) {
				$this->assertSame( 'Outbound connections', $connectivity_issues[0]['title'] );
			}

			ob_start();
			\MainWP\Dashboard\MainWP_Server_Information::render_quick_setup_system_check();
			$html = ob_get_clean();
			$this->assertStringContainsString( '<td style="white-space: normal;">' . esc_html( $expected['message'] ) . '</td>', $html );
			$this->assertStringNotContainsString( '<img src=x>', $html );
		} finally {
			remove_filter( 'pre_http_request', $http, 10 );
		}
	}

	/**
	 * Failures that indicate a Dashboard-side issue before a Child is added.
	 *
	 * @return array Cases.
	 */
	public static function outbound_failures(): array {
		return array(
			array( 'cURL error 6: getaddrinfo() thread failed to start <img src=x> > & "quoted"', 'server_resources' ),
			array( 'cURL error 6: Could not resolve host <img src=x> > & "quoted"', 'dns' ),
		);
	}

	/**
	 * An HTTP response proves outbound connectivity even when the root denies access.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_outbound_http_response_is_memoized(): void {
		$count = 0;
		$http  = static function () use ( &$count ) {
			++$count;
			return array(
				'response' => array( 'code' => 403 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $http );
		try {
			$first = MainWP_Server_Information_Handler::get_outbound_connections_data();
			$this->assertSame( 'pass', $first['status'] );
			$this->assertSame( 'Reachable (HTTP 403)', $first['value'] );
			$this->assertSame( $first, MainWP_Server_Information_Handler::get_outbound_connections_data() );
			$this->assertSame( 1, $count );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
	}

	/**
	 * Loopback URLs use only the guidance that applies to the Dashboard itself.
	 *
	 * @dataProvider loopback_errors
	 * @param string $raw WP_Error text.
	 * @param bool   $readable Whether shared host guidance applies.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loopback_classification_rule( $raw, $readable ): void {
		$http = static function () use ( $raw ) {
			return new \WP_Error( 'http_request_failed', $raw );
		};
		add_filter( 'pre_http_request', $http );
		try {
			$classified = MainWP_Error_Helper::classify_transport_error( null, $raw );
			$expected   = 'Request failed: ' . ( $readable ? $classified['message'] : $raw );
			foreach ( array( 'get_rest_api_reachability_data', 'get_server_self_connect_data' ) as $name ) {
				$method = new \ReflectionMethod( MainWP_Server_Information_Handler::class, $name );
				$method->setAccessible( true );
				$result = $method->invoke( null );
				$this->assertSame( $expected, $result['value'] );
			}
		} finally {
			remove_filter( 'pre_http_request', $http );
		}
	}

	/**
	 * All other classified reasons and unknown failures retain raw loopback details.
	 *
	 * @return array Cases.
	 */
	public static function loopback_errors(): array {
		$cases = array( array( 'getaddrinfo() thread failed to start <raw> & "quoted"', true ) );
		foreach ( array( 2, 6, 7, 28, 35, 58, 60, 77, 56 ) as $errno ) {
			$cases[] = array( 'cURL error ' . $errno . ': fixture <raw> > & "quoted"', in_array( $errno, array( 58, 77 ), true ) );
		}
		return $cases;
	}

	/** The check response keeps its code and adds plain-text details. */
	public function test_check_step_keeps_http_error_response_and_plain_message(): void {
		if ( ! class_exists( '\MainWP\Dashboard\Transport_Check_Handler', false ) ) {
			$source = file_get_contents( dirname( __DIR__ ) . '/pages/page-mainwp-manage-sites-handler.php' );
			$source = str_replace( 'class MainWP_Manage_Sites_Handler ', 'class Transport_Check_Handler ', $source );
			$source = str_replace( 'die( wp_json_encode( $ret ) );', 'return $ret;', $source );
			eval( '?>' . $source ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture the final response without changing production exits.
		}
		$raw        = 'getaddrinfo() thread failed to start <img src=x> > & "quoted"';
		$classified = MainWP_Error_Helper::classify_transport_error( 2, $raw );
		$fetch      = static function () use ( $classified ) {
			throw new MainWP_Exception( 'HTTPERROR', $classified['message'] );
		};
		$previous   = $_POST;
		$_POST      = array(
			'url'   => 'https://not-connected.example.com/',
			'admin' => 'admin',
		);
		add_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
		try {
			$result = \MainWP\Dashboard\Transport_Check_Handler::check_site();
			$this->assertSame( 'HTTPERROR', $result['response'] );
			$this->assertSame( 'HTTP error - ' . $classified['message'], $result['error_message'] );
		} finally {
			$_POST = $previous;
			remove_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
		}
	}

	/** REST and Abilities preserve codes and status while retaining details. */
	public function test_rest_and_ability_keep_error_contracts(): void {
		$raw        = 'getaddrinfo() thread failed to start <img src=x> > & "quoted"';
		$classified = MainWP_Error_Helper::classify_transport_error( 2, $raw );
		$fetch      = static function () use ( $classified ) {
			throw new MainWP_Exception( 'HTTPERROR', $classified['message'] );
		};
		$site       = (object) array(
			'id'   => 1,
			'url'  => 'https://example.com/',
			'name' => 'Fixture',
		);
		$controller = new class( $site ) extends \MainWP_Rest_Sites_Controller {
			/**
			 * Site returned by the request lookup.
			 *
			 * @var object
			 */
			private $site;
			/**
			 * Avoids a database lookup so the catch path can be exercised directly.
			 *
			 * @param object $site Site fixture.
			 */
			public function __construct( $site ) {
				$this->site = $site; }
			/**
			 * Returns the fixture without altering the controller catch path.
			 *
			 * @param object $request REST request.
			 * @return object Site.
			 */
			public function get_request_item( $request ) {
				return $this->site; }
		};
		$before     = static function () use ( $fetch ) {
			$fetch();
		};
		add_action( 'mainwp_before_wp_update', $before );
		add_filter( 'mainwp_fetch_url_authed_pre', $fetch );
		try {
			$result = $controller->security_item( new \WP_REST_Request( 'GET' ) );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_rest_get_secure_site_error', $result->get_error_code() );
			$this->assertSame( array( 'status' => 404 ), $result->get_error_data() );
			$this->assertSame( 'HTTP error - ' . $classified['message'], $result->get_error_message() );
			$method = new \ReflectionMethod( '\MainWP\Dashboard\MainWP_Abilities_Updates', 'execute_core_update' );
			$method->setAccessible( true );
			$result = $method->invoke( null, $site, array() );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_update_exception', $result->get_error_code() );
			$this->assertSame( 'HTTP error - ' . $classified['message'], $result->get_error_message() );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $fetch );
			remove_action( 'mainwp_before_wp_update', $before );
		}
	}
}
