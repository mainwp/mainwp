<?php
/**
 * New user passwords reach the Child as the same ISO-8859-1 bytes with or without mbstring.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_User;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Stops MainWP_User::action() at the hook that carries the converted password.
 */
class MWP_2170_Password_Captured extends \Exception {}

/**
 * Class Test_MWP_2170_Password_Latin1
 */
class Test_MWP_2170_Password_Latin1 extends \WP_UnitTestCase {

	use Site_Fixture;

	/** Clear request state before the next test. */
	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Inputs and the bytes utf8_decode() produced for them.
	 *
	 * @return array
	 */
	public static function passwords(): array {
		return array(
			'ascii'             => array( 'Pass-123!', '506173732d31323321' ),
			'latin-1 letter'    => array( "P\xC3\xA4ss", '50e47373' ),
			'top of latin-1'    => array( "\xC3\xBF", 'ff' ),
			'euro sign'         => array( "\xE2\x82\xAC5", '3f35' ),
			'emoji'             => array( "a\xF0\x9F\x98\x80b", '613f62' ),
			'lone continuation' => array( "\x80a", '3f61' ),
			'overlong'          => array( "\xC0\xAFx", '3f3f78' ),
			'truncated 3-byte'  => array( "\xE2\x82", '3f' ),
			'lead then ascii'   => array( "\xC3(", '3f28' ),
			'surrogate'         => array( "\xED\xA0\x80z", '3f7a' ),
			'above U+10FFFF'    => array( "\xF4\x90\x80\x80", '3f' ),
		);
	}

	/**
	 * The no-mbstring path keeps the bytes the old utf8_decode() fallback sent.
	 *
	 * @dataProvider passwords
	 * @param string $input UTF-8 input.
	 * @param string $hex Expected bytes.
	 */
	public function test_fallback_matches_utf8_decode_bytes( $input, $hex ): void {
		$this->assertSame( $hex, bin2hex( MainWP_Utility::utf8_to_latin1_fallback( $input ) ) );
		if ( function_exists( 'utf8_decode' ) ) {
			$this->assertSame( bin2hex( @utf8_decode( $input ) ), bin2hex( MainWP_Utility::utf8_to_latin1_fallback( $input ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Generic.PHP.DeprecatedFunctions.Deprecated -- Compare with the deprecated original.
		}
	}

	/** With mbstring the conversion is the same mb_convert_encoding() call as before. */
	public function test_mbstring_path_is_unchanged(): void {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			$this->markTestSkipped( 'mbstring is not installed.' );
		}
		foreach ( self::passwords() as $case ) {
			$this->assertSame( bin2hex( mb_convert_encoding( $case[0], 'ISO-8859-1', 'UTF-8' ) ), bin2hex( MainWP_Utility::utf8_to_latin1( $case[0] ) ) );
		}
		// Valid UTF-8 gives the same bytes on both paths.
		foreach ( array( 'Pass-123!', "P\xC3\xA4ss", "\xE2\x82\xAC5", "a\xF0\x9F\x98\x80b" ) as $valid ) {
			$this->assertSame( MainWP_Utility::utf8_to_latin1_fallback( $valid ), MainWP_Utility::utf8_to_latin1( $valid ) );
		}
	}

	/** The Users page converts the posted password before it builds the Child request. */
	public function test_users_page_sends_converted_password(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id  = $this->create_test_site();
		$_POST    = array(
			'userId'          => '7',
			'userName'        => 'someone',
			'websiteId'       => (string) $site_id,
			'update_password' => rawurlencode( "P\xC3\xA4ss\xE2\x82\xAC" ),
		);
		$_REQUEST = $_POST;
		$listener = static function ( $action, $user_id, $extra, $pass ) {
			throw new MWP_2170_Password_Captured( $pass ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test capture only.
		};
		add_action( 'mainwp_before_user_action', $listener, 10, 4 );
		try {
			MainWP_User::action( 'update_password' );
			$this->fail( 'The action should reach mainwp_before_user_action.' );
		} catch ( MWP_2170_Password_Captured $captured ) {
			$this->assertSame( '50e473733f', bin2hex( $captured->getMessage() ) );
		} finally {
			remove_action( 'mainwp_before_user_action', $listener, 10 );
		}
	}
}
