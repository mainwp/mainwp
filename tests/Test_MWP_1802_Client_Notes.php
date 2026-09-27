<?php
/**
 * MWP-1802 client note access and sanitizer.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Clients;
use MainWP\Dashboard\MainWP_Client;
use MainWP\Dashboard\MainWP_Client_Handler;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MWP_1802_Client_Notes
 */
class Test_MWP_1802_Client_Notes extends \WP_UnitTestCase {

	/**
	 * Note HTML used by every write path.
	 *
	 * @var string
	 */
	const FORMATTED_NOTE = '<p>Hello</p><ul><li>One</li></ul><br><strong>Bold</strong><script>alert(1)</script>';

	/**
	 * Client IDs created by this test.
	 *
	 * @var int[]
	 */
	private $client_ids = array();

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * Set up an administrator. Note saves still require that role for secure_request().
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->client_ids = array();
		$this->admin_id   = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Remove clients and filters created by a test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		remove_filter( 'mainwp_currentusercan', array( $this, 'deny_manage_clients' ), 10 );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$_POST    = array();
		$_REQUEST = array();

		foreach ( $this->client_ids as $client_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_clients', array( 'client_id' => $client_id ), array( '%d' ) );
		}

		parent::tearDown();
	}

	/**
	 * Deny the manage clients page permission.
	 *
	 * @param bool   $allowed  Current decision.
	 * @param string $cap_type Capability group.
	 * @param string $cap      Capability name.
	 * @return bool
	 */
	public function deny_manage_clients( $allowed, $cap_type, $cap ) {
		if ( 'dashboard' === $cap_type && 'manage_clients' === $cap ) {
			return false;
		}
		return $allowed;
	}

	/**
	 * A user without manage clients cannot change the note.
	 *
	 * @return void
	 */
	public function test_user_without_manage_clients_cannot_save_note(): void {
		$client_id = $this->create_client( 'original note' );
		add_filter( 'mainwp_currentusercan', array( $this, 'deny_manage_clients' ), 10, 3 );

		$this->assertFalse( \mainwp_current_user_can( 'dashboard', 'manage_clients' ) );

		$response = $this->dispatch_notes_save( $client_id, 'replaced note' );

		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( 'original note', $this->read_note( $client_id ) );
	}

	/**
	 * A user with manage clients can save the note.
	 *
	 * @return void
	 */
	public function test_user_with_manage_clients_can_save_note(): void {
		$client_id = $this->create_client( 'original note' );

		$this->assertTrue( \mainwp_current_user_can( 'dashboard', 'manage_clients' ) );

		$response = $this->dispatch_notes_save( $client_id, 'saved note' );

		$this->assertSame( 'SUCCESS', $response['result'] );
		$this->assertSame( 'saved note', $this->read_note( $client_id ) );
	}

	/**
	 * An unknown client id fails and does not create a note.
	 *
	 * @return void
	 */
	public function test_nonexistent_client_id_fails(): void {
		$response = $this->dispatch_notes_save( 99999999, 'replaced note' );

		$this->assertArrayHasKey( 'error', $response );
		$this->assertNull( MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', 99999999 ) );
	}

	/**
	 * Paragraphs, lists, line breaks, and strong survive every note write path.
	 *
	 * Script tags are removed the same way on each path.
	 *
	 * @return void
	 */
	public function test_formatted_note_survives_every_write_path(): void {
		$expected = MainWP_Utility::esc_content( self::FORMATTED_NOTE );

		$this->assertStringContainsString( '<p>Hello</p>', $expected );
		$this->assertStringContainsString( '<ul>', $expected );
		$this->assertStringContainsString( '<li>One</li>', $expected );
		$this->assertStringContainsString( '<strong>Bold</strong>', $expected );
		$this->assertMatchesRegularExpression( '/<br\s*\/?>/', $expected );
		$this->assertStringNotContainsString( '<script', $expected );

		$modal_id = $this->create_client( 'original note' );
		$modal    = $this->dispatch_notes_save( $modal_id, self::FORMATTED_NOTE );
		$this->assertSame( 'SUCCESS', $modal['result'] );
		$this->assertSame( $expected, $this->read_note( $modal_id ) );
		$this->assertSame( $expected, $modal['esc_note_content'] );

		$form_id = $this->create_client( 'original note' );
		$this->save_note_through_edit_form( $form_id, self::FORMATTED_NOTE );
		$this->assertSame( $expected, $this->read_note( $form_id ) );

		$ability_id = $this->create_client( 'original note' );
		$updated    = MainWP_Abilities_Clients::execute_update_client(
			array(
				'client_id_or_email' => $ability_id,
				'note'               => self::FORMATTED_NOTE,
			)
		);
		$this->assertIsArray( $updated );
		$this->assertSame( $expected, $updated['note'] );
		$this->assertSame( $expected, $this->read_note( $ability_id ) );

		$added = MainWP_Client_Handler::rest_api_add_client(
			array(
				'name'         => 'Test Client MWP-1802 add ' . wp_generate_password( 6, false ),
				'client_email' => 'test-mwp1802-add-' . wp_generate_password( 8, false ) . '@example.com',
				'note'         => self::FORMATTED_NOTE,
			),
			false
		);
		$this->assertTrue( ! empty( $added['success'] ) );
		$added_id           = (int) $added['client_id'];
		$this->client_ids[] = $added_id;
		$this->assertSame( $expected, $this->read_note( $added_id ) );
	}

	/**
	 * Insert a client row.
	 *
	 * @param string $note Starting note.
	 * @return int Client ID.
	 */
	private function create_client( $note ) {
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => 'Test Client MWP-1802 ' . wp_generate_password( 8, false ),
				'client_email' => 'test-mwp1802-' . wp_generate_password( 8, false ) . '@example.com',
				'note'         => $note,
				'created'      => time(),
				'suspended'    => 0,
			),
			true
		);

		$client_id          = (int) $client->client_id;
		$this->client_ids[] = $client_id;
		return $client_id;
	}

	/**
	 * Read the stored note column.
	 *
	 * @param int $client_id Client ID.
	 * @return string|null
	 */
	private function read_note( $client_id ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT note FROM {$wpdb->prefix}mainwp_wp_clients WHERE client_id = %d",
				$client_id
			)
		);
	}

	/**
	 * Post a notes-modal save and return the JSON body.
	 *
	 * @param int    $client_id Client ID.
	 * @param string $note      Note field.
	 * @return array
	 */
	private function dispatch_notes_save( $client_id, $note ) {
		$this->assertTrue( MainWP_System_Utility::is_admin() );

		$nonce                = wp_create_nonce( 'mainwp_clients_notes_save' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['clientid']    = $client_id;
		$_POST['note']        = $note;
		unset( $_POST['dts'] );

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );

		$body = '';
		ob_start();
		try {
			MainWP_Post_Handler::instance()->mainwp_clients_notes_save();
		} catch ( \WPDieException $exception ) {
			$body = $exception->getMessage();
		} finally {
			$buffered = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		if ( '' === $body ) {
			$body = $buffered;
		}

		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded, 'notes save should return JSON, got: ' . $body );
		return $decoded;
	}

	/**
	 * Return the AJAX die handler used to capture the response.
	 *
	 * @param mixed $handler Previous handler. Unused.
	 * @return callable
	 */
	public function ajax_die_handler( $handler ) {
		unset( $handler );
		return static function ( $message, $title = '', $args = array() ) {
			unset( $title, $args );
			throw new \WPDieException( is_scalar( $message ) ? (string) $message : '' );
		};
	}

	/**
	 * Save a note through the client edit form handler.
	 *
	 * @param int    $client_id Client ID.
	 * @param string $note      Note field.
	 * @return void
	 */
	private function save_note_through_edit_form( $client_id, $note ) {
		$_POST = array(
			'nonce_client_id' => wp_create_nonce( 'editing-client-' . $client_id ),
			'client_fields'   => array(
				'client_id'     => $client_id,
				'default_field' => array(
					'client.name' => 'Test Client MWP-1802',
					'client.note' => $note,
				),
			),
		);

		ob_start();
		MainWP_Client::add_client();
		$body = ob_get_clean();

		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded, 'edit form should return JSON, got: ' . $body );
		$this->assertSame( 'yes', $decoded['success'] );
	}
}
