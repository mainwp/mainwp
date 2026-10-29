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
	 * javascript: links and on* attributes are not live markup in a saved note.
	 *
	 * @return void
	 */
	public function test_javascript_links_and_event_attributes_are_stripped(): void {
		$client_id = $this->create_client( 'original note' );
		$note      = '<a href="javascript:alert(1)" onclick="alert(1)" onmouseover="alert(2)">click</a>';

		$response = $this->dispatch_notes_save( $client_id, $note );
		$stored   = $this->read_note( $client_id );

		$this->assertSame( 'SUCCESS', $response['result'] );
		$this->assert_note_not_live( $stored );
		$this->assert_note_not_live( $response['esc_note_content'] );
		$this->assertSame( $note, $response['note'] );
		$this->assertStringContainsString( 'click', $stored );
	}

	/** Invalid REST notes leave the existing note intact. */
	public function test_array_note_is_refused_and_stored_note_is_unchanged(): void {
		$client_id = $this->create_client( 'original note' );
		$result = MainWP_Client_Handler::rest_api_add_client( array( 'client_id' => $client_id, 'note' => array( 'not', 'a string' ) ), true );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'original note', $this->read_note( $client_id ) );
	}

	/** The edit form must not replace a note with an array. */
	public function test_edit_form_refuses_array_note_and_keeps_stored_note(): void {
		$client_id = $this->create_client( 'original note' );
		$this->save_note_through_edit_form( $client_id, array( 'not', 'a string' ), false );
		$this->assertSame( 'original note', $this->read_note( $client_id ) );
	}

	/** The modal returns its existing error shape for invalid note types. */
	public function test_modal_refuses_array_note_and_keeps_stored_note(): void {
		$client_id = $this->create_client( 'original note' );
		$result = $this->dispatch_notes_save( $client_id, array( 'not', 'a string' ) );
		$this->assertSame( array( 'undefined_error' => true ), $result );
		$this->assertSame( 'original note', $this->read_note( $client_id ) );
	}

	/** Invalid notes refuse creation before a client row is inserted. */
	public function test_rest_add_refuses_array_note_without_creating_client(): void {
		global $wpdb;
		$before = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_clients ORDER BY client_id", ARRAY_A );
		$result = MainWP_Client_Handler::rest_api_add_client(
			array( 'name' => 'Invalid note client', 'client_email' => 'invalid-note@example.com', 'note' => array( 'not', 'a string' ) ),
			false
		);
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( $before, $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_clients ORDER BY client_id", ARRAY_A ) );
	}

	/**
	 * Bracket text is stored encoded and comes back as typed in the editor.
	 *
	 * @return void
	 */
	public function test_plain_angle_brackets_round_trip(): void {
		$samples = array(
			'a < b',
			'a < b and c > d',
			'x<y',
			'5 > 3',
			'<john@example.com>',
			'<https://example.com>',
			'Array<int>',
			'std::vector<std::string>',
			'x<y>z',
			'<strong>5 > 3</strong> and <em>x<y</em>',
			'& a<b.',
			'<p>Hi</p>',
		);

		foreach ( $samples as $note ) {
			$this->assert_editor_round_trip( $note );
		}
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
				$this->assert_note_neutralized( $stored );
				$this->assertSame( $note, MainWP_Utility::client_note_for_editor( $stored ) );
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
	 * A nested handler that is not an allowlisted tag is stored with no raw brackets.
	 *
	 * @return void
	 */
	public function test_note_sanitize_fails_closed_when_the_pass_cap_is_hit(): void {
		$note = 'a < b <<b>img src=x onerror=alert(1)>';

		foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
			$this->assert_fail_closed_note( $stored );
		}
		$this->assert_fail_closed_note( MainWP_Utility::client_note_html( $note ) );
		$this->assert_fail_closed_note( MainWP_Utility::sanitize_client_note( $note ) );
	}

	/**
	 * An unfinished tag, and tags that swallow or carry extra attributes, stay text.
	 *
	 * @return void
	 */
	public function test_incomplete_tail_and_swallow_tags_stay_text(): void {
		$samples = array(
			'x <details open ontoggle=alert(1) ',
			'x <p onclick=alert(1) ',
			'x <strong tabindex=1 autofocus onfocus=alert(1) ',
			'Note: <a href="javascript:alert(document.cookie)" ',
			'<xmp>rest of the report',
			'<noembed>rest of the report',
			'<noframes>rest of the report',
			'<plaintext>rest of the report',
			'<listing>rest of the report',
			'<zzz style="color:red" id="i" form="f" tabindex="1" autofocus>rest</zzz>',
		);

		foreach ( $samples as $note ) {
			$this->assert_editor_round_trip( $note );
		}
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
	 * Bracket text that is not an allowlisted tag is edited as typed.
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
			$this->assert_editor_round_trip( $note );
			$html = MainWP_Utility::client_note_html( $note );
			$this->assert_note_not_live( $html );
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

		$multibyte = str_repeat( 'é', MainWP_Utility::CLIENT_NOTE_MAX_LENGTH );
		$this->assertFalse( MainWP_Utility::client_note_exceeds_limit( $multibyte ) );
		$this->assertTrue( MainWP_Utility::client_note_exceeds_limit( $multibyte . 'é' ) );
		$this->assertGreaterThan( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, strlen( $multibyte ) );

		$level = 3325;
		$tail  = 'img src=x onerror=alert(1)>';
		$worst = str_repeat( '<', $level ) . str_repeat( 'b>', $level - 1 ) . $tail;
		$this->assertSame( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, strlen( $worst ) );
		$this->assertSame( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, mb_strlen( $worst, 'UTF-8' ) );

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
		$this->assert_note_not_live( $stored );
		$this->assertDoesNotMatchRegularExpression( '/<\s*\/?\s*script\b/i', $stored );
		$this->assertDoesNotMatchRegularExpression( '/<\s*\/?\s*img\b/i', $stored );
		$this->assertDoesNotMatchRegularExpression( '/<\s*\/?\s*svg\b/i', $stored );
	}

	/**
	 * A stored note must not become live markup, including after a template closer.
	 *
	 * Liveness is judged with an HTML5 parser, not the note tokenizer. The
	 * letters of a handler may remain as text.
	 *
	 * @param string $stored Note text.
	 * @return void
	 */
	private function assert_note_not_live( $stored ) {
		$this->assertIsString( $stored );
		$suffixes = array( '', '</p>', '<br>', '</td>', '</p><br></td>' );
		foreach ( $suffixes as $suffix ) {
			$context = $stored . $suffix;
			$root    = $this->note_root( $context );
			$this->assertNotNull( $root, $context );
			$this->assert_note_tree_is_safe( $root, $context );
		}
	}

	/**
	 * Parse a note the way a browser would, inside a container.
	 *
	 * @param string $html Note HTML, possibly followed by a template closer.
	 * @return \DOMElement|null
	 */
	private function note_root( $html ) {
		$parser = new \Masterminds\HTML5();
		$dom    = $parser->loadHTML( '<!DOCTYPE html><html><body><div id="note-root">' . $html . '</div></body></html>' );
		$nodes  = ( new \DOMXPath( $dom ) )->query( '//*[@id="note-root"]' );
		if ( ! $nodes || 0 === $nodes->length ) {
			return null;
		}
		$node = $nodes->item( 0 );
		return $node instanceof \DOMElement ? $node : null;
	}

	/**
	 * Allowlisted tags may remain. Handlers, unsafe URLs, and other elements may not.
	 *
	 * @param \DOMNode $node    Node to walk.
	 * @param string   $context Note HTML being judged.
	 * @return void
	 */
	private function assert_note_tree_is_safe( $node, $context ) {
		$allowed = array( 'a', 'br', 'em', 'strong', 'p', 'hr', 'ul', 'ol', 'li', 'h1', 'h2' );
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$name = strtolower( $child->nodeName );
			$this->assertContains( $name, $allowed, $context );
			if ( $child->hasAttributes() ) {
				foreach ( $child->attributes as $attr ) {
					$attr_name = strtolower( $attr->nodeName );
					$this->assertNotSame( 0, strpos( $attr_name, 'on' ), $context );
					$this->assertNotSame( 'style', $attr_name, $context );
					$this->assertNotContains( $attr_name, array( 'autofocus', 'tabindex', 'form', 'id' ), $context );
					$urls = array(
						'href',
						'src',
						'action',
						'formaction',
						'poster',
						'cite',
						'background',
						'data',
						'codebase',
						'srcset',
						'dynsrc',
						'lowsrc',
						'xlink:href',
					);
					if ( in_array( $attr_name, $urls, true ) ) {
						$value = $attr->nodeValue;
						$this->assertSame( $value, wp_kses_bad_protocol( $value, wp_allowed_protocols() ), $context );
					}
				}
			}
			$this->assert_note_tree_is_safe( $child, $context );
		}
	}

	/**
	 * Saving, loading, and saving again keeps the typed note and does not double-encode.
	 *
	 * @param string $note Typed note.
	 * @return void
	 */
	private function assert_editor_round_trip( $note ) {
		foreach ( $this->store_note_on_every_path( $note ) as $stored ) {
			$this->assert_note_not_live( $stored );
			$this->assertSame( $note, MainWP_Utility::client_note_for_editor( $stored ), $note );
			$this->assertSame( $note, MainWP_Utility::client_note_plain_text( $stored ), $note );
			if ( false === strpos( $note, '&' ) ) {
				$this->assertStringNotContainsString( '&amp;lt;', $stored, $note );
				$this->assertStringNotContainsString( '&amp;gt;', $stored, $note );
			}
			$this->assertSame( $stored, MainWP_Utility::sanitize_client_note( MainWP_Utility::client_note_for_editor( $stored ) ), $note );
			$this->assert_visible_note( $note, MainWP_Utility::client_note_html( $stored ) );
		}

		$client_id = $this->create_client( 'original note' );
		$response  = $this->dispatch_notes_save( $client_id, $note );
		$stored    = $this->read_note( $client_id );

		$this->assertSame( $note, $response['note'], $note );
		$this->assertSame( $stored, $response['esc_note_content'], $note );
		$this->assertSame(
			$response['esc_note_content'],
			MainWP_Utility::client_note_html( $response['esc_note_content'] ),
			$note
		);
		if ( false === strpos( $note, '&' ) ) {
			$this->assertStringNotContainsString( '&amp;lt;', $stored, $note );
		}

		$column = $this->render_notes_column( $client_id, $stored );
		$this->assertSame( $note, $this->read_data_note( $column, $client_id ), $note );

		ob_start();
		\MainWP\Dashboard\MainWP_Client_Overview_Note::render_addition_info( $client_id );
		$widget = ob_get_clean();
		$this->assertSame( $note, $this->read_data_note( $widget, $client_id ), $note );

		$form = $this->render_edit_form( $client_id );
		$this->assertSame( $note, $this->read_note_textarea( $form ), $note );

		$this->dispatch_notes_save( $client_id, $response['note'] );
		$this->assertSame( $stored, $this->read_note( $client_id ), $note );
		$this->save_note_through_edit_form( $client_id, $response['note'] );
		$this->assertSame( $stored, $this->read_note( $client_id ), $note );
		$this->dispatch_notes_save( $client_id, $this->read_note_textarea( $this->render_edit_form( $client_id ) ) );
		$this->assertSame( $stored, $this->read_note( $client_id ), $note );
	}

	/**
	 * The visible text of a display note matches what was typed.
	 *
	 * @param string $typed Typed note.
	 * @param string $html  Display HTML.
	 * @return void
	 */
	private function assert_visible_note( $typed, $html ) {
		$root = $this->note_root( $html );
		$this->assertNotNull( $root, $typed );
		if ( '<strong>5 > 3</strong> and <em>x<y</em>' === $typed ) {
			$this->assertSame( '5 > 3 and x<y', $root->textContent );
			$this->assertSame( 1, $root->getElementsByTagName( 'strong' )->length );
			$this->assertSame( 1, $root->getElementsByTagName( 'em' )->length );
			$this->assertSame( '5 > 3', $root->getElementsByTagName( 'strong' )->item( 0 )->textContent );
			$this->assertSame( 'x<y', $root->getElementsByTagName( 'em' )->item( 0 )->textContent );
			return;
		}
		if ( '<p>Hi</p>' === $typed ) {
			$this->assertSame( 'Hi', $root->getElementsByTagName( 'p' )->item( 0 )->textContent );
			return;
		}
		if ( '<a title="a<b &copy;" href="https://example.com">x</a>' === $typed ) {
			$link = $root->getElementsByTagName( 'a' )->item( 0 );
			$this->assertInstanceOf( \DOMElement::class, $link );
			$this->assertSame( 'x', $link->textContent );
			$this->assertSame( 'a<b &copy;', $link->getAttribute( 'title' ) );
			$this->assertSame( 'https://example.com', $link->getAttribute( 'href' ) );
			return;
		}
		$this->assertSame( $typed, $root->textContent, $typed );
	}

	/**
	 * Render the clients-table notes cell.
	 *
	 * @param int    $client_id Client ID.
	 * @param string $note      Stored note.
	 * @return string
	 */
	private function render_notes_column( $client_id, $note ) {
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

		return $table->render_notes_column(
			array(
				'client_id' => $client_id,
				'note'      => $note,
			)
		);
	}

	/**
	 * Read the editor text from a rendered data-note attribute.
	 *
	 * @param string $html      Rendered markup.
	 * @param int    $client_id Client ID.
	 * @return string
	 */
	private function read_data_note( $html, $client_id ) {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		$node = $dom->getElementById( 'mainwp-notes-' . $client_id . '-note' );
		$this->assertNotNull( $node, $html );
		return $node->getAttribute( 'data-note' );
	}

	/**
	 * Render the client edit form.
	 *
	 * @param int $client_id Client ID.
	 * @return string
	 */
	private function render_edit_form( $client_id ) {
		$client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
		ob_start();
		MainWP_Client::render_add_client_content( $client );
		return ob_get_clean();
	}

	/**
	 * Read the note textarea from the edit form.
	 *
	 * @param string $html Rendered form.
	 * @return string
	 */
	private function read_note_textarea( $html ) {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//textarea[contains(@name, "client.note")]' );
		$this->assertSame( 1, $nodes->length, $html );
		return $nodes->item( 0 )->textContent;
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
	 * Entities the user types are stored encoded and come back unchanged.
	 *
	 * @return void
	 */
	public function test_typed_entities_round_trip_on_the_first_save_and_later_saves(): void {
		$samples = array(
			'&lt;',
			'&amp;',
			'&copy;',
			'&lt;p&gt;',
			'Tom &amp; Jerry',
		);

		foreach ( $samples as $note ) {
			$this->assert_editor_round_trip( $note );
			$stored = MainWP_Utility::sanitize_client_note( $note );
			$html   = MainWP_Utility::client_note_html( $stored );
			$root   = $this->note_root( $html );
			$this->assertNotNull( $root, $note );
			$this->assertSame( 0, $root->getElementsByTagName( 'p' )->length, $note );
			$this->assertSame( $note, $root->textContent, $note );
			$this->assertStringNotContainsString( '©', $html, $note );
			$this->assertSame( $note, MainWP_Utility::client_note_plain_text( $stored ), $note );
		}

		$with_title = '<a title="a<b &copy;" href="https://example.com">x</a>';
		$stored     = MainWP_Utility::sanitize_client_note( $with_title );
		$this->assertStringContainsString( 'title="a&lt;b &amp;copy;"', $stored );
		$this->assertDoesNotMatchRegularExpression( '/title="[^"]*</', $stored );
		$this->assertSame( $with_title, MainWP_Utility::client_note_for_editor( $stored ) );
		$this->assert_editor_round_trip( $with_title );
	}

	/**
	 * A note accepted at the typed cap still renders when storage is longer.
	 *
	 * @return void
	 */
	public function test_display_cap_uses_the_typed_length(): void {
		$inner = str_repeat( 'a<', 4416 ) . 'b';
		$typed = '<strong>' . $inner . '</strong>';
		$this->assertSame( 8850, mb_strlen( $typed, 'UTF-8' ) );

		$stored = MainWP_Utility::sanitize_client_note( $typed );
		$this->assertGreaterThan( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, mb_strlen( $stored, 'UTF-8' ) );
		$this->assertSame( $typed, MainWP_Utility::client_note_for_editor( $stored ) );

		$html = MainWP_Utility::client_note_html( $stored );
		$this->assertStringContainsString( '<strong>', $html );
		$this->assertStringNotContainsString( '&lt;strong&gt;', $html );
		$root = $this->note_root( $html );
		$this->assertNotNull( $root );
		$this->assertSame( 1, $root->getElementsByTagName( 'strong' )->length );
		$this->assertStringContainsString( 'a<', $root->getElementsByTagName( 'strong' )->item( 0 )->textContent );

		$client_id = $this->create_client( $stored );
		$column    = $this->render_notes_column( $client_id, $stored );
		$this->assertSame( $typed, $this->read_data_note( $column, $client_id ) );
		$column_root = $this->note_root( $column );
		$this->assertNotNull( $column_root );
		$this->assertSame( 1, $column_root->getElementsByTagName( 'strong' )->length );

		ob_start();
		\MainWP\Dashboard\MainWP_Client_Overview_Note::render_addition_info( $client_id );
		$widget = ob_get_clean();
		$this->assertSame( $typed, $this->read_data_note( $widget, $client_id ) );
		$widget_root = $this->note_root( $widget );
		$this->assertNotNull( $widget_root );
		$this->assertGreaterThanOrEqual( 1, $widget_root->getElementsByTagName( 'strong' )->length );
	}

	/**
	 * Notes written by the previous sanitizer still edit and display.
	 *
	 * @return void
	 */
	public function test_legacy_stored_notes_still_display_and_edit(): void {
		$cases = array(
			'<strong>Hello</strong>'           => '<strong>Hello</strong>',
			'a &lt; b &amp; c'                  => 'a < b & c',
			'<p>Already decoded</p>'           => '<p>Already decoded</p>',
			'©'                                 => '©',
			'<img src=x onerror=alert(1)>'     => '<img src=x onerror=alert(1)>',
		);

		foreach ( $cases as $stored_note => $editor ) {
			$client_id = $this->create_client( $stored_note );
			$this->assertSame( $editor, $this->read_note_textarea( $this->render_edit_form( $client_id ) ), $stored_note );
			$this->assertSame( $editor, MainWP_Utility::client_note_for_editor( $stored_note ), $stored_note );

			$html = MainWP_Utility::client_note_html( $stored_note );
			$this->assert_note_not_live( $html );
			if ( '<img src=x onerror=alert(1)>' === $stored_note ) {
				$this->assertStringNotContainsString( '<img', $html );
				$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
			}

			$this->save_note_through_edit_form( $client_id, $editor );
			$this->assertSame( $editor, MainWP_Utility::client_note_for_editor( $this->read_note( $client_id ) ), $stored_note );
			$this->dispatch_notes_save( $client_id, $editor );
			$this->assertSame( $editor, MainWP_Utility::client_note_for_editor( $this->read_note( $client_id ) ), $stored_note );
		}
	}

	/**
	 * Quote characters in attributes stay typed, and re-saves do not grow entities.
	 *
	 * @return void
	 */
	public function test_attribute_quotes_round_trip(): void {
		$samples = array(
			'<a title="Bob\'s note" href="https://example.com">x</a>',
			'<a title=\'say "hi"\' href="https://example.com">x</a>',
			'<a title="Bob\'s note" href=\'https://example.com/?q="hi"\'>x</a>',
		);

		foreach ( $samples as $note ) {
			$stored = MainWP_Utility::sanitize_client_note( $note );
			$this->assertSame( $note, $stored, $note );
			$this->assertSame( $note, MainWP_Utility::client_note_for_editor( $stored ), $note );
			$this->assertStringNotContainsString( '&#039;', $stored, $note );
			$this->assertStringNotContainsString( '&quot;', $stored, $note );
			$this->assertStringNotContainsString( '&amp;#039;', $stored, $note );
			$this->assert_note_not_live( $stored );

			$root = $this->note_root( MainWP_Utility::client_note_html( $stored ) );
			$this->assertNotNull( $root, $note );
			$link = $root->getElementsByTagName( 'a' )->item( 0 );
			$this->assertInstanceOf( \DOMElement::class, $link, $note );
			$this->assertNotSame( '', $link->getAttribute( 'title' ) . $link->getAttribute( 'href' ), $note );
		}

		$apostrophe = $samples[0];
		$link       = $this->note_root( MainWP_Utility::client_note_html( $apostrophe ) )->getElementsByTagName( 'a' )->item( 0 );
		$this->assertSame( 'Bob\'s note', $link->getAttribute( 'title' ) );
		$this->assertSame( 'https://example.com', $link->getAttribute( 'href' ) );

		$quoted = $samples[1];
		$link   = $this->note_root( MainWP_Utility::client_note_html( $quoted ) )->getElementsByTagName( 'a' )->item( 0 );
		$this->assertSame( 'say "hi"', $link->getAttribute( 'title' ) );

		$both = $samples[2];
		$link = $this->note_root( MainWP_Utility::client_note_html( $both ) )->getElementsByTagName( 'a' )->item( 0 );
		$this->assertSame( 'Bob\'s note', $link->getAttribute( 'title' ) );
		$this->assertSame( 'https://example.com/?q="hi"', $link->getAttribute( 'href' ) );

		$literal = 'Use &quot;quotes&quot; and &#039;ok&#039;';
		$this->assertSame( $literal, MainWP_Utility::client_note_for_editor( $literal ) );
		$this->assertSame( $literal, MainWP_Utility::client_note_plain_text( $literal ) );
		$stored_literal = MainWP_Utility::sanitize_client_note( $literal );
		$this->assertStringContainsString( '&amp;quot;', $stored_literal );
		$this->assertStringContainsString( '&amp;#039;', $stored_literal );
		$this->assertSame( $literal, MainWP_Utility::client_note_for_editor( $stored_literal ) );
		$this->assertStringNotContainsString( '"quotes"', MainWP_Utility::client_note_for_editor( $stored_literal ) );

		$legacy = '<a title="Bob&#039;s note" href="https://example.com">x</a>';
		$this->assertSame( $legacy, MainWP_Utility::client_note_for_editor( $legacy ) );
		$this->assertSame( $legacy, MainWP_Utility::sanitize_client_note( $legacy ) );

		$this->assertSame( 'say &quot;it&#039;s&quot;', MainWP_Utility::client_note_plain_text( 'say &quot;it&#039;s&quot;' ) );
		$this->assertSame( '&quot;', MainWP_Utility::client_note_plain_text( '&amp;quot;' ) );
		$this->assertSame( '&#039;', MainWP_Utility::client_note_plain_text( '&amp;#039;' ) );

		$method = new \ReflectionMethod( MainWP_Utility::class, 'client_note_format_attribute_value' );
		$method->setAccessible( true );
		$both_quotes = 'Bob\'s "hi"';
		$formatted   = $method->invoke( null, $both_quotes, '"' );
		$this->assertSame( '"Bob\'s &quot;hi&quot;"', $formatted );
		$this->assertSame( 'Bob\'s &quot;hi&quot;', MainWP_Utility::client_note_plain_text( substr( $formatted, 1, -1 ) ) );
		$this->assertSame( $formatted, $method->invoke( null, $both_quotes, '"' ) );
		$this->assertSame( $formatted, $method->invoke( null, 'Bob\'s &quot;hi&quot;', '"' ) );

		$formatted = $method->invoke( null, $both_quotes, "'" );
		$this->assertSame( '\'Bob&#039;s "hi"\'', $formatted );
		$this->assertSame( 'Bob&#039;s "hi"', MainWP_Utility::client_note_plain_text( substr( $formatted, 1, -1 ) ) );
		$this->assertSame( $formatted, $method->invoke( null, 'Bob&#039;s "hi"', "'" ) );
	}

	/**
	 * Unquoted attributes and the spaces around them are stored as typed.
	 *
	 * @return void
	 */
	public function test_unquoted_attributes_keep_their_typed_form(): void {
		$samples = array(
			'<a href=https://example.com>x</a>',
			'<a href=x>x</a>',
			'<a  href = x >x</a>',
		);

		foreach ( $samples as $note ) {
			$stored = MainWP_Utility::sanitize_client_note( $note );
			$this->assertSame( $note, $stored, $note );
			$this->assertSame( $note, MainWP_Utility::client_note_for_editor( $stored ), $note );
			$this->assertSame( mb_strlen( $note, 'UTF-8' ), mb_strlen( MainWP_Utility::client_note_for_editor( $stored ), 'UTF-8' ), $note );
			$this->assertStringNotContainsString( 'href="', $stored, $note );
			$this->assert_note_not_live( $stored );
			$html = MainWP_Utility::client_note_html( $stored );
			$this->assertStringContainsString( '<a ', $html, $note );
			$this->assertStringNotContainsString( '&lt;a', $html, $note );
		}
	}

	/**
	 * A 10,000-character note that contains a tag still renders that tag.
	 *
	 * @return void
	 */
	public function test_exactly_10000_character_note_with_a_tag_stays_at_the_cap(): void {
		$prefix = '<a href=x title="a<b">';
		$this->assertLessThan( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, strlen( $prefix ) );
		$typed = $prefix . str_repeat( 'n', MainWP_Utility::CLIENT_NOTE_MAX_LENGTH - strlen( $prefix ) );
		$this->assertSame( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, mb_strlen( $typed, 'UTF-8' ) );
		$this->assertSame( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, strlen( $typed ) );

		$stored = MainWP_Utility::sanitize_client_note( $typed );
		$this->assertGreaterThan( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, strlen( $stored ) );
		$this->assertSame( $typed, MainWP_Utility::client_note_for_editor( $stored ) );
		$this->assertSame( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, mb_strlen( MainWP_Utility::client_note_for_editor( $stored ), 'UTF-8' ) );
		$this->assertStringContainsString( 'title="a&lt;b"', $stored );
		$this->assertStringContainsString( 'href=x', $stored );
		$this->assertStringNotContainsString( 'href="x"', $stored );

		$html = MainWP_Utility::client_note_html( $stored );
		$this->assertStringContainsString( '<a href=x', $html );
		$this->assertStringNotContainsString( '&lt;a', $html );
		$root = $this->note_root( $html );
		$this->assertNotNull( $root );
		$link = $root->getElementsByTagName( 'a' )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $link );
		$this->assertSame( 'a<b', $link->getAttribute( 'title' ) );
		$this->assertSame( 'x', $link->getAttribute( 'href' ) );

		$client_id = $this->create_client( 'original note' );
		$response  = $this->dispatch_notes_save( $client_id, $typed );
		$this->assertSame( 'SUCCESS', $response['result'] );
		$this->assertSame( $typed, $response['note'] );
		$this->assertSame( $stored, $this->read_note( $client_id ) );

		$column = $this->render_notes_column( $client_id, $this->read_note( $client_id ) );
		$this->assertSame( $typed, $this->read_data_note( $column, $client_id ) );
		$column_root = $this->note_root( $column );
		$this->assertNotNull( $column_root );
		$this->assert_note_link( $column_root, 'x', 'a<b' );

		ob_start();
		\MainWP\Dashboard\MainWP_Client_Overview_Note::render_addition_info( $client_id );
		$widget = ob_get_clean();
		$this->assertSame( $typed, $this->read_data_note( $widget, $client_id ) );
		$widget_root = $this->note_root( $widget );
		$this->assertNotNull( $widget_root );
		$this->assert_note_link( $widget_root, 'x', 'a<b' );
	}

	/**
	 * Find a rendered note link by its href.
	 *
	 * @param \DOMNode $root  Parsed note container.
	 * @param string   $href  Expected href.
	 * @param string   $title Expected title.
	 * @return void
	 */
	private function assert_note_link( $root, $href, $title ) {
		foreach ( $root->getElementsByTagName( 'a' ) as $link ) {
			if ( $href === $link->getAttribute( 'href' ) ) {
				$this->assertSame( $title, $link->getAttribute( 'title' ) );
				return;
			}
		}
		$this->fail( 'Missing note link with href ' . $href );
	}

	/**
	 * Saving the editor text again leaves the stored note byte for byte.
	 *
	 * @return void
	 */
	public function test_resaving_a_note_four_times_is_byte_stable(): void {
		$note      = '<a title="Bob\'s note" href=https://example.com>x</a> <a title=\'say "hi"\'>y</a>';
		$client_id = $this->create_client( 'original note' );
		$this->dispatch_notes_save( $client_id, $note );
		$stored = $this->read_note( $client_id );
		$this->assertSame( $note, $stored );
		$this->assertSame( $note, MainWP_Utility::client_note_for_editor( $stored ) );

		for ( $cycle = 0; $cycle < 4; $cycle++ ) {
			$editor = MainWP_Utility::client_note_for_editor( $this->read_note( $client_id ) );
			if ( 0 === $cycle % 2 ) {
				$this->dispatch_notes_save( $client_id, $editor );
			} else {
				$this->save_note_through_edit_form( $client_id, $editor );
			}
			$this->assertSame( $stored, $this->read_note( $client_id ), 'cycle ' . $cycle );
		}
	}

	/**
	 * The notes tooltip cuts characters after decoding, not bytes of entities.
	 *
	 * @return void
	 */
	public function test_client_list_tooltip_counts_characters_and_whole_entities(): void {
		$note = 'x' . str_repeat( 'é', 48 ) . '&' . str_repeat( 'é', 80 );
		$html = $this->render_notes_column( 1, $note );
		$expected = 'x' . str_repeat( 'é', 48 ) . '&' . str_repeat( 'é', 50 );
		$this->assertSame( 100, mb_strlen( $expected, 'UTF-8' ) );
		$this->assertSame( $expected, $this->read_data_tooltip( $html ) );
		$this->assertDoesNotMatchRegularExpression( '/data-tooltip="[^"]*&am"/', $html );

		$multibyte = 'x' . str_repeat( 'é', 80 );
		$html      = $this->render_notes_column( 1, $multibyte );
		$this->assertSame( $multibyte, $this->read_data_tooltip( $html ) );
	}

	/**
	 * A typed entity stays typed in the tooltip, and an empty tooltip uses the default label.
	 *
	 * @return void
	 */
	public function test_tooltip_keeps_typed_entities_and_uses_the_default_label_when_empty(): void {
		$typed  = 'see &copy; &amp;';
		$stored = MainWP_Utility::sanitize_client_note( $typed );
		$html   = $this->render_notes_column( 1, $stored );

		$this->assertSame( $typed, $this->read_data_tooltip( $html ) );
		$this->assertSame( $typed, $this->read_holder_text( $html, 1 ) );
		$this->assertMatchesRegularExpression( '/data-tooltip="see &amp;copy; &amp;amp;"/', $html );
		$this->assertStringNotContainsString( '©', $html );

		$empty = $this->render_notes_column( 1, '<br>' );
		$this->assertSame( 'Edit client notes.', $this->read_data_tooltip( $empty ) );
	}

	/**
	 * The tooltip keeps a 4-byte emoji and a CJK character that sit on the 100-character cut.
	 *
	 * @return void
	 */
	public function test_client_list_tooltip_keeps_emoji_and_cjk_at_the_boundary(): void {
		$emoji = "\u{1F600}";
		$this->assertSame( 4, strlen( $emoji ) );
		$this->assertSame( 1, mb_strlen( $emoji, 'UTF-8' ) );
		$emoji_expected = str_repeat( 'a', 99 ) . $emoji;
		$this->assertSame( 100, mb_strlen( $emoji_expected, 'UTF-8' ) );
		$emoji_html = $this->render_notes_column( 1, $emoji_expected . str_repeat( 'b', 10 ) );
		$this->assertSame( $emoji_expected, $this->read_tooltip_text( $emoji_html ) );
		$this->assertStringNotContainsString( 'Edit client notes.', $emoji_html );

		$cjk = '字';
		$this->assertSame( 3, strlen( $cjk ) );
		$this->assertSame( 1, mb_strlen( $cjk, 'UTF-8' ) );
		$cjk_expected = str_repeat( 'a', 99 ) . $cjk;
		$this->assertSame( 100, mb_strlen( $cjk_expected, 'UTF-8' ) );
		$cjk_html = $this->render_notes_column( 1, $cjk_expected . str_repeat( 'b', 10 ) );
		$this->assertSame( $cjk_expected, $this->read_tooltip_text( $cjk_html ) );
	}

	/**
	 * A typed ESC byte in an attribute is stored as that byte.
	 *
	 * @return void
	 */
	public function test_esc_byte_in_an_attribute_is_not_rewritten(): void {
		$note   = '<a title="a' . "\x1B" . 'q;b" href="https://example.com">x</a>';
		$stored = MainWP_Utility::sanitize_client_note( $note );
		$this->assertSame( $note, $stored );
		$this->assertStringContainsString( "a\x1Bq;b", $stored );
		$this->assertStringNotContainsString( '&quot;', $stored );
		$this->assertSame( $note, MainWP_Utility::client_note_for_editor( $stored ) );

		$with_entity = '<a title="a' . "\x1B" . 'q; &quot;b">x</a>';
		$stored_both = MainWP_Utility::sanitize_client_note( $with_entity );
		$this->assertStringContainsString( "a\x1Bq; &quot;b", $stored_both );
		$this->assertStringNotContainsString( '&amp;quot;', $stored_both );
		$this->assertSame( $with_entity, MainWP_Utility::client_note_for_editor( $stored_both ) );

		$body = "keep \x1Bq; and the rest";
		$this->assertSame( $body, MainWP_Utility::sanitize_client_note( $body ) );
		$this->assertSame( $body, MainWP_Utility::client_note_for_editor( $body ) );
	}

	/**
	 * Displayed client notes keep backslashes in the list and the overview widget.
	 *
	 * @return void
	 */
	public function test_note_display_keeps_backslashes(): void {
		$note      = 'C:\\Users\\bob';
		$client_id = $this->create_client( $note );

		$column = $this->render_notes_column( $client_id, $note );
		$this->assertSame( $note, $this->read_holder_text( $column, $client_id ) );
		$this->assertSame( $note, $this->read_data_note( $column, $client_id ) );
		$this->assertStringNotContainsString( 'C:Usersbob', $column );

		ob_start();
		\MainWP\Dashboard\MainWP_Client_Overview_Note::render_addition_info( $client_id );
		$widget = ob_get_clean();
		$this->assertSame( $note, $this->read_holder_text( $widget, $client_id ) );
		$this->assertSame( $note, $this->read_data_note( $widget, $client_id ) );
		$this->assertStringNotContainsString( 'C:Usersbob', $widget );
	}

	/**
	 * Quoted and mixed-quote titles at exactly 10,000 characters still render as tags.
	 *
	 * @return void
	 */
	public function test_exactly_10000_character_note_with_quoted_titles_stays_a_tag(): void {
		$cases = array(
			'<a title="Bob\'s note">' => array(
				'href'  => '',
				'title' => 'Bob\'s note',
			),
			'<a title="Bob\'s note" href=\'https://example.com/?q="hi"\'>' => array(
				'href'  => 'https://example.com/?q="hi"',
				'title' => 'Bob\'s note',
			),
		);

		foreach ( $cases as $prefix => $expected ) {
			$this->assertLessThan( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, mb_strlen( $prefix, 'UTF-8' ), $prefix );
			$typed = $prefix . str_repeat( 'n', MainWP_Utility::CLIENT_NOTE_MAX_LENGTH - mb_strlen( $prefix, 'UTF-8' ) );
			$this->assertSame( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, mb_strlen( $typed, 'UTF-8' ), $prefix );

			$stored = MainWP_Utility::sanitize_client_note( $typed );
			$this->assertSame( $typed, $stored, $prefix );
			$this->assertSame( $typed, MainWP_Utility::client_note_for_editor( $stored ), $prefix );
			$this->assertSame( MainWP_Utility::CLIENT_NOTE_MAX_LENGTH, mb_strlen( MainWP_Utility::client_note_for_editor( $stored ), 'UTF-8' ), $prefix );

			$html = MainWP_Utility::client_note_html( $stored );
			$this->assertStringContainsString( '<a ', $html, $prefix );
			$this->assertStringNotContainsString( '&lt;a', $html, $prefix );
			$root = $this->note_root( $html );
			$this->assertNotNull( $root, $prefix );
			$this->assert_note_link( $root, $expected['href'], $expected['title'] );

			$client_id = $this->create_client( 'original note' );
			$response  = $this->dispatch_notes_save( $client_id, $typed );
			$this->assertSame( 'SUCCESS', $response['result'], $prefix );
			$this->assertSame( $typed, $response['note'], $prefix );
			$this->assertSame( $stored, $this->read_note( $client_id ), $prefix );

			$column = $this->render_notes_column( $client_id, $this->read_note( $client_id ) );
			$this->assertSame( $typed, $this->read_data_note( $column, $client_id ) );
			$column_root = $this->note_root( $column );
			$this->assertNotNull( $column_root, $prefix );
			$this->assert_note_link( $column_root, $expected['href'], $expected['title'] );
		}
	}

	/**
	 * Read the notes tooltip from a rendered list cell.
	 *
	 * @param string $html Rendered markup.
	 * @return string
	 */
	private function read_data_tooltip( $html ) {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//a[contains(@class, "mainwp-edit-client-note")]' );
		$this->assertSame( 1, $nodes->length, $html );
		return $nodes->item( 0 )->getAttribute( 'data-tooltip' );
	}

	/**
	 * Tooltip text, using the raw attribute when the DOM drops a 4-byte character.
	 *
	 * @param string $html Rendered markup.
	 * @return string
	 */
	private function read_tooltip_text( $html ) {
		$from_dom = $this->read_data_tooltip( $html );
		if ( '' !== $from_dom ) {
			return $from_dom;
		}
		$this->assertSame( 1, preg_match( '/data-tooltip="([^"]*)"/u', $html, $matches ), $html );
		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Text of the hidden note holder.
	 *
	 * @param string $html      Rendered markup.
	 * @param int    $client_id Client ID.
	 * @return string
	 */
	private function read_holder_text( $html, $client_id ) {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		$node = $dom->getElementById( 'mainwp-notes-' . $client_id . '-note' );
		$this->assertNotNull( $node, $html );
		return $node->textContent;
	}

	/**
	 * Plain text inverts storage entities and does not decode anything else.
	 *
	 * @return void
	 */
	public function test_plain_text_decodes_only_storage_entities(): void {
		$typed  = 'Cost &amp; <extra> &copy; &lt;p&gt;';
		$stored = MainWP_Utility::sanitize_client_note( $typed );

		$this->assertSame( $typed, MainWP_Utility::client_note_plain_text( $stored ) );
		$this->assertNotSame( $typed, $stored );
		$this->assertStringContainsString( '&amp;amp;', $stored );
		$this->assertStringContainsString( '&amp;copy;', $stored );
		$this->assertStringContainsString( '&amp;lt;p&amp;gt;', $stored );
		$this->assertStringNotContainsString( '©', MainWP_Utility::client_note_plain_text( $stored ) );
		$this->assertStringNotContainsString( '<p>', MainWP_Utility::client_note_plain_text( $stored ) );
		$this->assertSame( '&amp;lt;', MainWP_Utility::client_note_plain_text( '&amp;amp;lt;' ) );
		$this->assertSame( '&copy;', MainWP_Utility::client_note_plain_text( '&amp;copy;' ) );
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
	 * @param bool  $expect_success Whether the form should accept the note.
	 * @return void
	 */
	private function save_note_through_edit_form( $client_id, $note, $expect_success = true ) {
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
		if ( $expect_success ) {
			$this->assertSame( 'yes', $decoded['success'] );
		} else {
			$this->assertArrayHasKey( 'error', $decoded );
		}
	}
}
