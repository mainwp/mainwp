<?php
/**
 * MWP-1802 client note access and sanitizer.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

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
		$this->set_note_pass_cap( null );

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
	 * javascript: links and on* attributes are removed from a saved note.
	 *
	 * @return void
	 */
	public function test_javascript_links_and_event_attributes_are_stripped(): void {
		$client_id = $this->create_client( 'original note' );
		$note      = '<a href="javascript:alert(1)" onclick="alert(1)" onmouseover="alert(2)">click</a>';

		$response = $this->dispatch_notes_save( $client_id, $note );
		$stored   = $this->read_note( $client_id );

		$this->assertSame( 'SUCCESS', $response['result'] );
		$this->assertStringNotContainsString( 'javascript:', $stored );
		$this->assertStringNotContainsString( 'javascript:', $response['esc_note_content'] );
		$this->assertDoesNotMatchRegularExpression( '/\son[a-z0-9_-]*\s*=/i', $stored );
		$this->assertDoesNotMatchRegularExpression( '/\son[a-z0-9_-]*\s*=/i', $response['esc_note_content'] );
		$this->assertStringContainsString( 'click', $stored );
	}

	/**
	 * A non-string note is stored as an empty string and does not raise a notice.
	 *
	 * @return void
	 */
	public function test_array_note_is_stored_as_empty_string(): void {
		$client_id = $this->create_client( 'original note' );
		$note      = array( 'not', 'a string' );

		$edited = MainWP_Client_Handler::rest_api_add_client(
			array(
				'client_id' => $client_id,
				'note'      => $note,
			),
			true
		);
		$this->assertTrue( ! empty( $edited['success'] ) );
		$this->assertSame( '', $this->read_note( $client_id ) );

		$this->save_note_through_edit_form( $client_id, $note );
		$this->assertSame( '', $this->read_note( $client_id ) );

		$added = MainWP_Client_Handler::rest_api_add_client(
			array(
				'name'         => 'Test Client MWP-1802 array ' . wp_generate_password( 6, false ),
				'client_email' => 'test-mwp1802-array-' . wp_generate_password( 8, false ) . '@example.com',
				'note'         => $note,
			),
			false
		);
		$this->assertTrue( ! empty( $added['success'] ) );
		$added_id           = (int) $added['client_id'];
		$this->client_ids[] = $added_id;
		$this->assertSame( '', $this->read_note( $added_id ) );
	}

	/**
	 * Comparison text keeps its < and > characters on every write path.
	 *
	 * @return void
	 */
	public function test_plain_angle_brackets_round_trip(): void {
		$samples = array(
			'a < b and c > d',
			'x<y',
			'5 > 3',
		);

		foreach ( $samples as $note ) {
			foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
				$this->assertSame( $note, $stored );
			}
		}

		$note      = 'a < b and c > d';
		$client_id = $this->create_client( 'original note' );
		$response  = $this->dispatch_notes_save( $client_id, $note );
		$stored    = $this->read_note( $client_id );

		$this->assertSame( $note, $stored );
		$this->assertSame( $note, $response['note'] );
		$this->assertSame( 'a &lt; b and c &gt; d', $response['esc_note_content'] );
		$this->assertStringNotContainsString( '&amp;', $response['esc_note_content'] );
		$this->assertSame( 'a &lt; b and c &gt; d', esc_html( $stored ) );
		$this->assertSame(
			$response['esc_note_content'],
			MainWP_Utility::client_note_html( $response['esc_note_content'] )
		);

		$this->save_note_through_edit_form( $client_id, $stored );
		$this->assertSame( $note, $this->read_note( $client_id ) );
	}

	/**
	 * Script tags, javascript: links, and on* attributes do not survive a save.
	 *
	 * @return void
	 */
	public function test_dangerous_note_markup_is_neutralized(): void {
		$samples = array(
			'<script>alert(1)</script>',
			'<a href="javascript:alert(1)">x</a>',
			'<b onclick="x">y</b>',
		);

		foreach ( $samples as $note ) {
			foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
				$this->assertStringNotContainsString( '<script', strtolower( $stored ) );
				$this->assertStringNotContainsString( 'javascript:', strtolower( $stored ) );
				$this->assertStringNotContainsString( '<b', strtolower( $stored ) );
				$this->assertDoesNotMatchRegularExpression( '/\son[a-z0-9_-]*\s*=/i', $stored );
			}
		}
	}

	/**
	 * Nested tags and their entity-encoded forms cannot rebuild a live tag.
	 *
	 * @return void
	 */
	public function test_nested_note_markup_stays_neutral_at_every_depth(): void {
		$bases = array(
			'<<script>script>alert(1)<</script>/script>',
			'<scr<b>ipt>',
			'<<b>img src=x onerror=alert(1)>',
			'<<b>a href="javascript:alert(1)">',
		);

		for ( $level = 2; $level <= 5; $level++ ) {
			foreach ( $bases as $base ) {
				$nested = $this->nest_note_payload( $base, $level );
				$forms  = array(
					$nested,
					str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $nested ),
				);
				foreach ( $forms as $note ) {
					foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
						$this->assert_note_neutralized( $stored );
					}
					$this->assert_note_neutralized( MainWP_Utility::client_note_html( $note ) );
					$this->assert_note_neutralized( MainWP_Utility::sanitize_client_note( html_entity_decode( $note, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				}
			}
		}
	}

	/**
	 * Nesting past the old 16-pass cap cannot leave a live tag.
	 *
	 * @return void
	 */
	public function test_deep_nested_note_markup_stays_neutral(): void {
		$tails = array(
			'img src=x onerror=alert(1)>',
			'a href="javascript:alert(1)">',
			'script>alert(1)</script>',
		);

		foreach ( array( 18, 52 ) as $level ) {
			foreach ( $tails as $tail ) {
				$note = str_repeat( '<', $level ) . str_repeat( 'b>', $level - 1 ) . $tail;
				foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
					$this->assert_note_neutralized( $stored );
				}
				$this->assert_note_neutralized( MainWP_Utility::client_note_html( $note ) );
			}
		}
	}

	/**
	 * A note that is still changing at the pass cap is stored with no raw brackets.
	 *
	 * @return void
	 */
	public function test_note_sanitize_fails_closed_when_the_pass_cap_is_hit(): void {
		$note = 'a < b <<b>img src=x onerror=alert(1)>';
		$this->set_note_pass_cap( 1 );

		foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
			$this->assert_fail_closed_note( $stored );
		}
		$this->assert_fail_closed_note( MainWP_Utility::client_note_html( $note ) );
		$this->assert_fail_closed_note( MainWP_Utility::sanitize_client_note( $note ) );
	}

	/**
	 * An allowlisted tag hidden inside an outer tag cannot leave a live handler.
	 *
	 * @return void
	 */
	public function test_allowlisted_tag_inside_outer_brackets_stays_neutral(): void {
		$samples = array(
			'<img src=x onerror=alert(1)<p>x>',
			'<svg onload=alert(1)<br>>',
			'<img src=x onerror=alert(1)<a href=#>>',
			'x<img "</a>>',
		);

		foreach ( $samples as $note ) {
			foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
				$this->assert_note_neutralized( $stored );
			}
			$this->assert_note_neutralized( MainWP_Utility::client_note_html( $note ) );
		}
	}

	/**
	 * Random mixes of tags and handlers do not survive a save.
	 *
	 * @return void
	 */
	public function test_fuzzed_note_markup_stays_neutral(): void {
		mt_srand( 1802 );
		$chunks = array(
			'<p>',
			'</p>',
			'<br>',
			'<a href=#>',
			'</a>',
			'<img',
			' src=x',
			' onerror=alert(1)',
			'<svg',
			' onload=alert(1)',
			'javascript:alert(1)',
			'<script>',
			'</script>',
			'<',
			'>',
			'"',
			'x',
		);

		for ( $i = 0; $i < 24; $i++ ) {
			$note = '';
			$span = mt_rand( 4, 14 );
			for ( $piece = 0; $piece < $span; $piece++ ) {
				$note .= $chunks[ mt_rand( 0, count( $chunks ) - 1 ) ];
			}
			foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
				$this->assert_note_not_live( $stored );
			}
			$this->assert_note_not_live( MainWP_Utility::client_note_html( $note ) );
		}
	}

	/**
	 * Bracket text that is not an allowlisted tag is stored as typed.
	 *
	 * @return void
	 */
	public function test_non_allowlisted_bracket_text_round_trips(): void {
		$samples = array(
			'<john@example.com>',
			'<https://example.com>',
			'Array<int>',
			'a<b, c>d',
			'x<y>z',
			'<p>Hi</p>',
		);

		foreach ( $samples as $note ) {
			foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
				$this->assertSame( $note, $stored );
			}
			$html = MainWP_Utility::client_note_html( $note );
			$this->assert_note_neutralized( $html );
			if ( '<p>Hi</p>' === $note ) {
				$this->assertSame( $note, $html );
			} else {
				$this->assertStringNotContainsString( '<', $html );
				$this->assertStringContainsString( '&lt;', $html );
			}
		}
	}

	/**
	 * Notes longer than the cap are rejected, and a full-size nested note stays fast.
	 *
	 * @return void
	 */
	public function test_note_length_cap_rejects_writes_and_display_stays_fast(): void {
		$client_id = $this->create_client( 'original note' );
		$too_long  = str_repeat( 'a', MainWP_Utility::CLIENT_NOTE_MAX_LENGTH + 1 );

		$modal = $this->dispatch_notes_save( $client_id, $too_long );
		$this->assertSame( MainWP_Utility::client_note_length_error(), $modal['error'] );
		$this->assertSame( 'original note', $this->read_note( $client_id ) );

		$_POST = array(
			'nonce_client_id' => wp_create_nonce( 'editing-client-' . $client_id ),
			'client_fields'   => array(
				'client_id'     => $client_id,
				'default_field' => array(
					'client.name' => 'Test Client MWP-1802',
					'client.note' => $too_long,
				),
			),
		);
		ob_start();
		MainWP_Client::add_client();
		$form = json_decode( ob_get_clean(), true );
		$this->assertSame( MainWP_Utility::client_note_length_error(), $form['error'] );
		$this->assertSame( 'original note', $this->read_note( $client_id ) );

		$edited = MainWP_Client_Handler::rest_api_add_client(
			array(
				'client_id' => $client_id,
				'note'      => $too_long,
			),
			true
		);
		$this->assertSame( MainWP_Utility::client_note_length_error(), $edited['error'] );
		$this->assertSame( 'original note', $this->read_note( $client_id ) );

		$added = MainWP_Client_Handler::rest_api_add_client(
			array(
				'name' => 'Test Client MWP-1802 long',
				'note' => $too_long,
			),
			false
		);
		$this->assertSame( MainWP_Utility::client_note_length_error(), $added['error'] );
		$this->assertArrayNotHasKey( 'client_id', $added );

		$level = 3325;
		$tail  = 'img src=x onerror=alert(1)>';
		$worst = str_repeat( '<', $level ) . str_repeat( 'b>', $level - 1 ) . $tail;
		$this->assertSame( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, strlen( $worst ) );

		$started = microtime( true );
		$stored  = MainWP_Utility::sanitize_client_note( $worst );
		$elapsed = microtime( true ) - $started;
		$this->assertLessThan( 2, $elapsed );
		$this->assert_note_neutralized( $stored );

		$legacy = $too_long . "\n<img src=x onerror=alert(1)>";
		$this->create_client( $legacy );
		$started = microtime( true );
		$html    = MainWP_Utility::client_note_html( $legacy );
		$elapsed = microtime( true ) - $started;
		$this->assertLessThan( 2, $elapsed );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringContainsString( '<br>', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	/**
	 * A quote inside comparison text cannot break out of the notes tooltip.
	 *
	 * @return void
	 */
	public function test_client_list_tooltip_escapes_attribute_breakout(): void {
		$note  = 'x < a" onmouseover="alert(1)" > y';
		$table = new class() extends \MainWP\Dashboard\MainWP_Client_List_Table {
			/**
			 * Render only the notes column.
			 *
			 * @param array $item Client row.
			 * @return string
			 */
			public function render_notes_column( $item ) {
				$this->column_headers = array(
					array( 'notes' => 'Notes' ),
					array(),
					array(),
				);
				ob_start();
				$this->single_row_columns( $item );
				return ob_get_clean();
			}
		};

		$html = $table->render_notes_column(
			array(
				'client_id' => 1,
				'note'      => $note,
			)
		);

		$this->assertMatchesRegularExpression( '/data-tooltip="[^"]*"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-tooltip="[^"]*"\s+onmouseover\s*=/i', $html );
		$this->assertStringContainsString( 'onmouseover=&quot;alert(1)&quot;', $html );
	}

	/**
	 * Wrap each < so one kses pass reveals the previous payload.
	 *
	 * Level 2 is the payload itself.
	 *
	 * @param string $payload Base payload.
	 * @param int    $level   Nesting level, 2 through 5.
	 * @return string
	 */
	private function nest_note_payload( $payload, $level ) {
		$nested = $payload;
		for ( $depth = 2; $depth < $level; $depth++ ) {
			$nested = str_replace( '<', '<<b>', $nested );
		}
		return $nested;
	}

	/**
	 * A stored or displayed note must not contain an executable tag.
	 *
	 * @param string $stored Note text.
	 * @return void
	 */
	private function assert_note_neutralized( $stored ) {
		$this->assertIsString( $stored );
		$lower = strtolower( $stored );
		$this->assertStringNotContainsString( '<script', $lower );
		$this->assertStringNotContainsString( '</script', $lower );
		$this->assertStringNotContainsString( 'javascript:', $lower );
		$this->assertStringNotContainsString( 'onerror', $lower );
		$this->assertStringNotContainsString( '<img', $lower );
		$this->assertDoesNotMatchRegularExpression( '/\son[a-z0-9_-]*\s*=/i', $stored );
	}

	/**
	 * A stored note must not parse as an executable tag.
	 *
	 * Plain text may still contain the letters of a handler. It is live only
	 * when a tag or attribute carries them.
	 *
	 * @param string $stored Note text.
	 * @return void
	 */
	private function assert_note_not_live( $stored ) {
		$this->assertIsString( $stored );
		$this->assertDoesNotMatchRegularExpression( '/<\s*\/?\s*script\b/i', $stored );
		$this->assertDoesNotMatchRegularExpression( '/<\s*\/?\s*img\b/i', $stored );
		$this->assertDoesNotMatchRegularExpression( '/<\s*\/?\s*svg\b/i', $stored );

		$processor = new \WP_HTML_Tag_Processor( $stored );
		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = strtolower( (string) $processor->get_tag() );
			$this->assertStringNotContainsString( '<', $tag );
			$handlers = $processor->get_attribute_names_with_prefix( 'on' );
			$this->assertTrue( empty( $handlers ), $stored );
			foreach ( array( 'href', 'src', 'action' ) as $url_name ) {
				$value = $processor->get_attribute( $url_name );
				if ( is_string( $value ) ) {
					$this->assertStringNotContainsString( 'javascript:', strtolower( $value ) );
				}
			}
		}
	}

	/**
	 * The fail-closed result has no raw brackets and no live handler.
	 *
	 * @param string $stored Note text.
	 * @return void
	 */
	private function assert_fail_closed_note( $stored ) {
		$this->assert_note_neutralized( $stored );
		$this->assertStringNotContainsString( '<', $stored );
		$this->assertStringNotContainsString( '>', $stored );
		$this->assertStringContainsString( '&lt;', $stored );
	}

	/**
	 * Override the allowlist pass cap. Null restores the decoded-length bound.
	 *
	 * @param int|null $cap Pass cap.
	 * @return void
	 */
	private function set_note_pass_cap( $cap ) {
		$property = new \ReflectionProperty( MainWP_Utility::class, 'client_note_pass_cap' );
		$property->setAccessible( true );
		$property->setValue( null, $cap );
	}

	/**
	 * Ordinary text, including newlines, is stored and read back unchanged.
	 *
	 * @return void
	 */
	public function test_plain_text_note_round_trip(): void {
		$note      = "First line\nSecond line\nOrdinary text.";
		$client_id = $this->create_client( 'original note' );

		$response = $this->dispatch_notes_save( $client_id, $note );

		$this->assertSame( 'SUCCESS', $response['result'] );
		$this->assertSame( $note, $response['esc_note_content'] );
		$this->assertSame( $note, $this->read_note( $client_id ) );
	}

	/**
	 * Save a note through the modal, the edit form, and both REST branches.
	 *
	 * @param string $note Note field.
	 * @return string[] Stored notes, one per path.
	 */
	private function store_note_on_every_path( $note ) {
		$stored = array();

		$modal_id        = $this->create_client( 'original note' );
		$modal           = $this->dispatch_notes_save( $modal_id, $note );
		$this->assertSame( 'SUCCESS', $modal['result'] );
		$stored['modal'] = $this->read_note( $modal_id );

		$form_id = $this->create_client( 'original note' );
		$this->save_note_through_edit_form( $form_id, $note );
		$stored['form'] = $this->read_note( $form_id );

		$edit_id = $this->create_client( 'original note' );
		$edited  = MainWP_Client_Handler::rest_api_add_client(
			array(
				'client_id' => $edit_id,
				'note'      => $note,
			),
			true
		);
		$this->assertTrue( ! empty( $edited['success'] ) );
		$stored['rest_edit'] = $this->read_note( $edit_id );

		$added = MainWP_Client_Handler::rest_api_add_client(
			array(
				'name'         => 'Test Client MWP-1802 note ' . wp_generate_password( 6, false ),
				'client_email' => 'test-mwp1802-note-' . wp_generate_password( 8, false ) . '@example.com',
				'note'         => $note,
			),
			false
		);
		$this->assertTrue( ! empty( $added['success'] ) );
		$added_id           = (int) $added['client_id'];
		$this->client_ids[] = $added_id;
		$stored['rest_add'] = $this->read_note( $added_id );

		return $stored;
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

		// get_var() returns null for an empty string, so read the row instead.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT note FROM {$wpdb->prefix}mainwp_wp_clients WHERE client_id = %d",
				$client_id
			)
		);

		return $row ? $row->note : null;
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
	 * @param int   $client_id Client ID.
	 * @param mixed $note      Note field.
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
