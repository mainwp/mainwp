<?php
/**
 * Application Password activity retains its actor and request origin.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Module\Log\Log_Author;
use MainWP\Dashboard\Module\Log\Log_Manager;
use MainWP\Dashboard\Module\Log\Log_Query;
use MainWP\Dashboard\Module\Log\Log_Events_List_Table;

/** Exercise WordPress authentication, storage and the User column. */
class Test_Network_Activity_MCP_Agent extends \WP_UnitTestCase {
	use Site_Fixture;

	/** @var int Fixture site ID. */
	private $site;
	/**
	 * Request origins and expected stored agents.
	 *
	 * @return array
	 */
	public static function origins() {
		return array(
			'mcp'          => array( '/wp-abilities/v1/abilities/mainwp/update_site_v1/run', true, 'mainwp_mcp' ),
			'trailing'     => array( '/wp-abilities/v1/abilities/mainwp/update_site_v1/run/', true, 'mainwp_mcp' ),
			'generic rest' => array( '/wp/v2/posts', true, '' ),
			'other owner'  => array( '/wp-abilities/v1/abilities/example/update/run', true, '' ),
			'catalog'      => array( '/wp-abilities/v1/abilities/mainwp/update_site_v1', true, '' ),
			'cookie'       => array( '/wp-abilities/v1/abilities/mainwp/update_site_v1/run', false, '' ),
			'ui click'     => array( '', false, '' ),
		);
	}

	/**
	 * Persist the authenticated origin and render the stored named actor.
	 *
	 * @dataProvider origins
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param string $route Request route.
	 * @param bool   $app_password Whether to authenticate an Application Password.
	 * @param string $expected Expected agent.
	 */
	public function test_request_origin( $route, $app_password, $expected ) {
		global $wp, $wp_rest_application_password_uuid;
		define( 'REST_REQUEST', true );
		$actor = self::factory()->user->create( array( 'role' => 'administrator', 'user_login' => 'network_actor' ) );
		wp_set_current_user( $actor );
		$this->site = $this->create_test_site();
		$wp_rest_application_password_uuid = null;
		if ( $app_password ) {
			add_filter( 'wp_is_application_passwords_available', '__return_true' );
			add_filter( 'application_password_is_api_request', '__return_true' );
			$password = \WP_Application_Passwords::create_new_application_password( $actor, array( 'name' => 'Network fixture' ) );
			$this->assertIsArray( $password );
			$authenticated = wp_authenticate_application_password( null, 'network_actor', $password[0] );
			$this->assertInstanceOf( \WP_User::class, $authenticated );
			$this->assertSame( $password[1]['uuid'], rest_get_authenticated_app_password() );
		}
		$wp->query_vars['rest_route'] = $route;
		$server = new \WP_REST_Server();
		$server->register_route(
			'fixture',
			$route ?: '/fixture-click',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => array( $this, 'write_event' ),
				),
			)
		);
		$response = $server->dispatch( new \WP_REST_Request( 'POST', $route ?: '/fixture-click' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThan( 0, $response->get_data() );
		$records = ( new Log_Query() )->query( array( 'view' => 'events_list', 'start' => 0, 'records_per_page' => 20, 'orderby' => 'created', 'order' => 'DESC' ) );
		$rows = array_values( array_filter( $records['items'], static function ( $row ) use ( $actor ) { return (int) $row->user_id === $actor; } ) );
		$this->assertNotEmpty( $rows );
		$meta = json_decode( $rows[0]->user_meta_json, true );
		$this->assertSame( $expected, $meta['agent'] );
		$this->assertSame( 'network_actor', $meta['user_login'] );
		$this->assertSame( $actor, (int) $rows[0]->user_id );
		$output = ( new Log_Events_List_Table( Log_Manager::instance() ) )->column_default( $rows[0], 'user_id' );
		$this->assertSame( 'network_actor' . ( $expected ? ' via MainWP MCP' : '' ), $output );
	}

	/**
	 * Write through the production connector and metadata path.
	 *
	 * @return int Log ID.
	 */
	public function write_event() {
		return Log_Manager::instance()->connectors->connectors['site']->log( '%1$s', array( 'item' => 'Origin fixture' ), $this->site, 'sites', 'updated', 1 );
	}

	/**
	 * Existing automation keeps precedence over an MCP-shaped request.
	 *
	 * @dataProvider automation
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param string $constant Request constant.
	 * @param string $agent Expected agent.
	 * @param string $label Expected label.
	 */
	public function test_existing_automation( $constant, $agent, $label ) {
		global $wp, $wp_rest_application_password_uuid;
		define( 'REST_REQUEST', true );
		$wp->query_vars['rest_route']       = '/wp-abilities/v1/abilities/mainwp/update_site_v1/run';
		$wp_rest_application_password_uuid = wp_generate_uuid4();
		define( $constant, true );
		$author = new Log_Author( 0 );
		$this->assertSame( $agent, $author->get_current_agent() );
		$this->assertSame( $label, $author->get_agent_label( $agent ) );
	}

	/**
	 * Existing automation labels.
	 *
	 * @return array
	 */
	public static function automation() {
		return array(
			array( 'WP_CLI', 'wp_cli', 'via WP-CLI' ),
			array( 'DOING_CRON', 'wp_cron', 'during WP Cron' ),
			array( 'MAINWP_REST_API_DOING', 'wp_rest_api', 'during WP REST API' ),
		);
	}
}
