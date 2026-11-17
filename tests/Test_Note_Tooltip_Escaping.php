<?php
/**
 * Site/client note tooltip escaping (self-audit A1).
 *
 * Notes are stored through MainWP_Utility::esc_content(), which is wp_kses() with
 * a tag allowlist. wp_kses() sanitizes *tags*; it does not encode a bare double
 * quote. A note therefore survives storage with its quotes intact, and every
 * place that echoes it into an HTML attribute must escape at the sink.
 *
 * MainWP_Utility::esc_note_attr() owns that rule so the ~7 tooltip sinks cannot
 * drift apart again. These tests pin both halves of it:
 *   - the payload cannot break out of data-tooltip="..." (the vulnerability), and
 *   - truncation happens on a character boundary, so a multibyte note is not
 *     silently blanked by esc_attr()'s wp_check_invalid_utf8() pass.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_Note_Tooltip_Escaping
 */
class Test_Note_Tooltip_Escaping extends \WP_UnitTestCase {

	/**
	 * The stored-XSS payload: no angle brackets, so wp_kses() and
	 * wp_strip_all_tags() both pass it through untouched.
	 */
	const PAYLOAD = 'x" onmouseover="alert(document.cookie)';

	/**
	 * Reproduce what the render path does to a stored note before it reaches the
	 * attribute sink: decode, run the input filter, strip tags.
	 *
	 * @param string $note Raw note as typed by the user.
	 * @return string
	 */
	private function stored_then_stripped( string $note ): string {
		$esc_note = MainWP_Utility::esc_content( html_entity_decode( $note ) );
		return wp_strip_all_tags( $esc_note );
	}

	/**
	 * Root cause, pinned: the input filter does NOT make a note attribute-safe.
	 *
	 * If someone later "hardens" esc_content() and deletes the sink escaping on
	 * the strength of it, this test is the thing that says the sink still matters.
	 */
	public function test_esc_content_does_not_neutralize_quotes(): void {
		$stored = $this->stored_then_stripped( self::PAYLOAD );

		$this->assertStringContainsString( '"', $stored, 'wp_kses does not encode a bare double quote' );
		$this->assertStringContainsString( 'onmouseover=', $stored, 'wp_kses only strips tags, not bare attribute text' );
	}

	/**
	 * The sink helper must make the payload inert.
	 */
	public function test_esc_note_attr_neutralizes_attribute_breakout(): void {
		$out = MainWP_Utility::esc_note_attr( $this->stored_then_stripped( self::PAYLOAD ) );

		$this->assertStringNotContainsString( '"', $out, 'no bare double quote may survive into the attribute' );
		$this->assertStringNotContainsString( '" onmouseover=', $out, 'the payload must not be able to close the attribute' );
		$this->assertStringContainsString( '&quot;', $out, 'the quote must come out entity-encoded' );
	}

	/**
	 * Single quotes are an attribute break-out too, in single-quoted markup.
	 */
	public function test_esc_note_attr_encodes_single_quotes(): void {
		$out = MainWP_Utility::esc_note_attr( "x' onmouseover='alert(1)" );

		$this->assertStringNotContainsString( "'", $out );
		$this->assertStringContainsString( '&#039;', $out );
	}

	/**
	 * Regression guard for the escape/truncate ORDER.
	 *
	 * esc_attr() runs wp_check_invalid_utf8() first, which returns '' for a
	 * string that is not valid UTF-8. Byte-based substr() on a multibyte note can
	 * cut a character in half, so escaping AFTER a byte-truncation would blank the
	 * whole tooltip for any accented or non-Latin note. Truncating on a character
	 * boundary keeps the value intact.
	 */
	public function test_multibyte_note_is_truncated_without_being_blanked(): void {
		// 120 two-byte characters: a byte-based substr(…, 0, 100) lands mid-character.
		$note = str_repeat( 'é', 120 );

		$out = MainWP_Utility::esc_note_attr( $note );

		$this->assertNotSame( '', $out, 'a multibyte note must not be blanked by invalid-UTF-8 scrubbing' );
		$this->assertSame( 100, mb_strlen( html_entity_decode( $out, ENT_QUOTES, 'UTF-8' ) ), 'truncation is by character, not byte' );
	}

	/**
	 * The 100-character cap the tooltips have always had is preserved.
	 */
	public function test_esc_note_attr_truncates_to_one_hundred_characters(): void {
		$out = MainWP_Utility::esc_note_attr( str_repeat( 'a', 250 ) );

		$this->assertSame( 100, strlen( $out ) );
	}

	/**
	 * A note shorter than the cap is returned whole.
	 */
	public function test_short_note_is_returned_intact(): void {
		$this->assertSame( 'a short note', MainWP_Utility::esc_note_attr( 'a short note' ) );
	}

	/**
	 * Non-string input must not fatal (notes can come back null from the DB).
	 */
	public function test_non_string_input_returns_empty_string(): void {
		$this->assertSame( '', MainWP_Utility::esc_note_attr( null ) );
		$this->assertSame( '', MainWP_Utility::esc_note_attr( array() ) );
	}
}
