<?php
/**
 * Tests for assistant activity log redaction.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Module\Log\Connector_Assistant;
use MainWP\Dashboard\Module\Log\Log_Manage_Insights_Events_Page;

/**
 * Test assistant log payload summaries.
 */
class Test_Assistant_Logs extends MainWP_Assistant_Test_Case {

	/**
	 * Test encoded assistant payloads do not contain raw prompt or result text.
	 */
	public function test_encode_log_payload_omits_raw_string_content() {
		if ( ! class_exists( Connector_Assistant::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/classes/class-log-connector.php';
			require_once dirname( __DIR__, 2 ) . '/modules/logs/connectors/class-connector-assistant.php';
		}

		$connector  = new Connector_Assistant();
		$reflection = new \ReflectionClass( $connector );
		$method     = $reflection->getMethod( 'encode_log_payload' );
		$method->setAccessible( true );

		$payload = array(
			'message' => 'Deploy the production site and email the admin password.',
			'nested'  => array(
				'result' => 'The API returned secret output that must not be logged.',
			),
		);

		$encoded = $method->invoke( $connector, $payload );
		$decoded = json_decode( $encoded, true );

		$this->assertIsArray( $decoded );
		$this->assertStringNotContainsString( 'Deploy the production site', $encoded );
		$this->assertStringNotContainsString( 'secret output', $encoded );
		$this->assertSame( 'array', $decoded['type'] );
		$this->assertSame( 'string', $decoded['items']['message']['type'] );
		$this->assertArrayHasKey( 'length', $decoded['items']['message'] );
	}

	/**
	 * Test assistant logs redact conversational fields when history logging is disabled.
	 */
	public function test_callbacks_redact_conversational_fields_without_history_logging() {
		if ( ! class_exists( Connector_Assistant::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/classes/class-log-connector.php';
			require_once dirname( __DIR__, 2 ) . '/modules/logs/connectors/class-connector-assistant.php';
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_consent( true );
		update_user_meta( $user_id, 'mainwp_assistant_history_opt_out', 1 );

		$connector = new class() extends Connector_Assistant {
			public $captured_message = '';
			public $captured_args    = array();

			public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
				$this->captured_message = $message;
				$this->captured_args    = $args;
			}
		};

		$connector->callback_mainwp_assistant_ability_executed(
			'mainwp/list-sites-v1',
			array( 'query' => 'show the last conversation' ),
			array( 'result' => 'sensitive model output' ),
			123,
			$user_id,
			1.2,
			'openai',
			'gpt-4o-mini',
			'Assistant returned a sensitive answer.'
		);

		$this->assertSame( '[redacted]', $connector->captured_args['params'] ?? '' );
		$this->assertSame( '[redacted]', $connector->captured_args['result'] ?? '' );
		$this->assertSame( '[redacted]', $connector->captured_args['result_summary'] ?? '' );
		$this->assertStringNotContainsString( 'sensitive', $connector->captured_message );

		delete_user_meta( $user_id, 'mainwp_assistant_history_opt_out' );
	}

	/**
	 * Test ability log rows carry the resolved single-site attribution and
	 * keep 0 for multi-site or non-site abilities.
	 */
	public function test_ability_callbacks_stamp_received_site_id() {
		if ( ! class_exists( Connector_Assistant::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/classes/class-log-connector.php';
			require_once dirname( __DIR__, 2 ) . '/modules/logs/connectors/class-connector-assistant.php';
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_consent( true );

		$connector = new class() extends Connector_Assistant {
			public $captured_site_id = null;

			public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
				$this->captured_site_id = $site_id;
			}
		};

		$connector->callback_mainwp_assistant_ability_executed(
			'mainwp/get-site-v1',
			array( 'site_id_or_domain' => 42 ),
			array( 'ok' => true ),
			123,
			$user_id,
			0.5,
			'openai',
			'gpt-4o-mini',
			'Fetched the site.',
			42
		);
		$this->assertSame( 42, $connector->captured_site_id, 'The executed row must carry the resolved site attribution.' );

		$connector->callback_mainwp_assistant_ability_executed(
			'mainwp/list-sites-v1',
			array(),
			array( 'ok' => true ),
			123,
			$user_id,
			0.5,
			'openai',
			'gpt-4o-mini',
			'Listed sites.'
		);
		$this->assertSame( 0, $connector->captured_site_id, 'A call without the new argument keeps the unattributed 0.' );

		$connector->callback_mainwp_assistant_ability_failed(
			'mainwp/get-site-v1',
			array( 'site_id_or_domain' => 42 ),
			array(
				'error'   => 'boom',
				'message' => 'It broke.',
			),
			123,
			$user_id,
			0.5,
			'openai',
			'gpt-4o-mini',
			42
		);
		$this->assertSame( 42, $connector->captured_site_id, 'The failed row must carry the resolved site attribution.' );

		$connector->callback_mainwp_assistant_ability_confirmed(
			'mainwp/update-site-core-v1',
			array( 'site_id_or_domain' => 42 ),
			array( 'ok' => true ),
			123,
			$user_id,
			true,
			0.5,
			'openai',
			'gpt-4o-mini',
			'Updated core.',
			42
		);
		$this->assertSame( 42, $connector->captured_site_id, 'The confirmed row must carry the resolved site attribution.' );
	}

	/**
	 * Test derive_log_site_id() resolves exactly-one-site params to that ID
	 * and everything else (batch lists, no site param) to 0.
	 */
	public function test_derive_log_site_id_single_site_or_zero() {
		$this->set_current_user_as_admin();

		$site_a = $this->create_test_site( array( 'name' => 'Attribution A' ) );
		$site_b = $this->create_test_site( array( 'name' => 'Attribution B' ) );

		$assistant = \MainWP\Dashboard\MainWP_Assistant::instance();
		$method    = new \ReflectionMethod( \MainWP\Dashboard\MainWP_Assistant::class, 'derive_log_site_id' );
		$method->setAccessible( true );

		$this->assertSame( $site_a, $method->invoke( $assistant, array( 'site_id_or_domain' => $site_a ) ), 'A single resolved site attributes to that site.' );
		$this->assertSame( 0, $method->invoke( $assistant, array( 'site_ids' => array( $site_a, $site_b ) ) ), 'Multi-site targets stay unattributed.' );
		$this->assertSame( 0, $method->invoke( $assistant, array( 'query' => 'hello' ) ), 'Non-site abilities stay unattributed.' );
	}

	/**
	 * Test conversation titles are sanitized before being logged.
	 */
	public function test_conversation_started_sanitizes_title_in_message_and_args() {
		if ( ! class_exists( Connector_Assistant::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/classes/class-log-connector.php';
			require_once dirname( __DIR__, 2 ) . '/modules/logs/connectors/class-connector-assistant.php';
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_consent( true );

		$connector = new class() extends Connector_Assistant {
			public $captured_message = '';
			public $captured_args    = array();

			public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
				$this->captured_message = $message;
				$this->captured_args    = $args;
			}
		};

		$raw_title = "Conversation <script>alert('xss')</script>";
		$connector->callback_mainwp_assistant_conversation_started( 15, $user_id, 'openai', 'gpt-4o-mini', $raw_title );

		$this->assertStringNotContainsString( '<script>', $connector->captured_message );
		$this->assertSame( sanitize_text_field( $raw_title ), $connector->captured_args['title'] ?? '' );
	}

	/**
	 * Test failure logging strips secret-shaped fragments from provider errors.
	 *
	 * The error message is the one free-text field this connector writes, and
	 * provider SDKs and child sites put request URLs, Authorization headers,
	 * and passed-through keys into it. Those reach the log item text and the
	 * error_message meta, so they are scrubbed before the write.
	 */
	public function test_ability_failed_scrubs_secrets_from_error_text() {
		if ( ! class_exists( Connector_Assistant::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/classes/class-log-connector.php';
			require_once dirname( __DIR__, 2 ) . '/modules/logs/connectors/class-connector-assistant.php';
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_consent( true );

		$connector = new class() extends Connector_Assistant {
			public $captured_message = '';
			public $captured_args    = array();

			public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
				$this->captured_message = $message;
				$this->captured_args    = $args;
			}
		};

		$leaky = new \WP_Error(
			'child_http_error',
			'Request to https://child.example.com/wp-admin/admin-ajax.php?mainwpsignature=abc123SIGNATURE&login_required=1 failed; sent Authorization: Bearer sk-live-9f8e7d6c5b4a3210 with adminpassword=hunter2correct'
		);

		$connector->callback_mainwp_assistant_ability_failed(
			'mainwp/sync-sites-v1',
			array( 'site_ids' => array( 4 ) ),
			$leaky,
			12,
			$user_id,
			0.4,
			'openai',
			'gpt-4o-mini',
			4
		);

		$written = $connector->captured_message . ' ' . wp_json_encode( $connector->captured_args );

		$this->assertStringNotContainsString( 'abc123SIGNATURE', $written, 'A signed child URL must not reach the log.' );
		$this->assertStringNotContainsString( 'sk-live-9f8e7d6c5b4a3210', $written, 'A provider key must not reach the log.' );
		$this->assertStringNotContainsString( 'hunter2correct', $written, 'A password must not reach the log.' );

		// The code and the useful part of the message survive: the scrub is a
		// redaction, not a blanket drop.
		$this->assertSame( 'child_http_error', $connector->captured_args['error_code'] ?? '' );
		$this->assertStringContainsString( 'Request to', $connector->captured_args['error_message'] ?? '' );
		$this->assertLessThanOrEqual( 160, mb_strlen( $connector->captured_args['error_message'] ?? '' ) );
	}

	/**
	 * Test a benign error message survives the scrub intact.
	 */
	public function test_ability_failed_keeps_benign_error_text() {
		if ( ! class_exists( Connector_Assistant::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/classes/class-log-connector.php';
			require_once dirname( __DIR__, 2 ) . '/modules/logs/connectors/class-connector-assistant.php';
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_consent( true );

		$connector = new class() extends Connector_Assistant {
			public $captured_args = array();

			public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
				$this->captured_args = $args;
			}
		};

		$connector->callback_mainwp_assistant_ability_failed(
			'mainwp/add-tag-v1',
			array( 'name' => 'Clients' ),
			new \WP_Error( 'tag_exists', 'A tag with that name already exists.' ),
			12,
			$user_id
		);

		$this->assertSame( 'A tag with that name already exists.', $connector->captured_args['error_message'] ?? '' );
	}

	/**
	 * Test the key=value scrub also catches JSON-shaped secret fields.
	 *
	 * A JSON key closes its quote right before the colon (`"password":"..."`),
	 * which the pattern's un-quoted key match used to miss entirely, letting
	 * the value through untouched.
	 */
	public function test_ability_failed_scrubs_secrets_from_json_fragments() {
		if ( ! class_exists( Connector_Assistant::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/classes/class-log-connector.php';
			require_once dirname( __DIR__, 2 ) . '/modules/logs/connectors/class-connector-assistant.php';
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_consent( true );

		$connector = new class() extends Connector_Assistant {
			public $captured_message = '';
			public $captured_args    = array();

			public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
				$this->captured_message = $message;
				$this->captured_args    = $args;
			}
		};

		$leaky = new \WP_Error(
			'child_http_error',
			'Child payload was {"password":"hunter2","api_key": "sk-x"}'
		);

		$connector->callback_mainwp_assistant_ability_failed(
			'mainwp/sync-sites-v1',
			array( 'site_ids' => array( 4 ) ),
			$leaky,
			12,
			$user_id,
			0.4,
			'openai',
			'gpt-4o-mini',
			4
		);

		$written = $connector->captured_message . ' ' . wp_json_encode( $connector->captured_args );

		$this->assertStringNotContainsString( 'hunter2', $written, 'A JSON password field must not reach the log.' );
		$this->assertStringNotContainsString( 'sk-x', $written, 'A JSON api_key field must not reach the log.' );
	}

	/**
	 * Test unsigned filters do not overwrite saved manage insights filters.
	 */
	public function test_manage_insights_filters_do_not_persist_without_nonce() {
		if ( ! class_exists( Log_Manage_Insights_Events_Page::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/pages/page-log-manage-insights-events-page.php';
		}

		$user_id = $this->set_current_user_as_admin();
		update_user_option(
			$user_id,
			'mainwp_module_logs_manage_filters_saved',
			array(
				'ranges'     => 'lastmonth',
				'groups_ids' => '',
				'client_ids' => '',
				'user_ids'   => '',
				'dtsstart'   => '',
				'dtsstop'    => '',
				'source'     => '',
				'sites'      => '',
				'events'     => '',
			),
			true
		);

		$_REQUEST['range']  = 'today';
		$_REQUEST['source'] = 'dashboard';

		$page    = Log_Manage_Insights_Events_Page::instance();
		$filters = $page->get_insights_filters( true );
		$saved   = get_user_option( 'mainwp_module_logs_manage_filters_saved', $user_id );

		$this->assertSame( 'today', $filters['filter_ranges'] );
		$this->assertSame( 'dashboard', $filters['filter_source'] );
		$this->assertSame( 'lastmonth', $saved['ranges'] );
		$this->assertSame( '', $saved['source'] );

		unset( $_REQUEST['range'], $_REQUEST['source'] );
	}

	/**
	 * Test signed filters persist manage insights filter selections.
	 */
	public function test_manage_insights_filters_persist_with_valid_nonce() {
		if ( ! class_exists( Log_Manage_Insights_Events_Page::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/logs/pages/page-log-manage-insights-events-page.php';
		}

		$user_id = $this->set_current_user_as_admin();
		update_user_option(
			$user_id,
			'mainwp_module_logs_manage_filters_saved',
			array(
				'ranges'     => 'lastmonth',
				'groups_ids' => '',
				'client_ids' => '',
				'user_ids'   => '',
				'dtsstart'   => '',
				'dtsstop'    => '',
				'source'     => '',
				'sites'      => '',
				'events'     => '',
			),
			true
		);

		$_REQUEST['range']               = 'today';
		$_REQUEST['source']              = 'dashboard';
		$_REQUEST['_insights_opennonce'] = wp_create_nonce( 'mainwp-admin-nonce' );

		$page    = Log_Manage_Insights_Events_Page::instance();
		$filters = $page->get_insights_filters( true );
		$saved   = get_user_option( 'mainwp_module_logs_manage_filters_saved', $user_id );

		$this->assertSame( 'today', $filters['filter_ranges'] );
		$this->assertSame( 'dashboard', $filters['filter_source'] );
		$this->assertSame( 'today', $saved['ranges'] );
		$this->assertSame( 'dashboard', $saved['source'] );

		unset( $_REQUEST['range'], $_REQUEST['source'], $_REQUEST['_insights_opennonce'] );
	}
}
