<?php
/**
 * Reconnect diagnostics and Child post links must stay inert.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Bulk_Add;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Sites_View;

/** Regression coverage for diagnostics and href emitters. */
class Test_Remaining_Site_Text_Escaping extends \WP_UnitTestCase {

	use Site_Fixture;

	/** Create an administrator for site ownership checks. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Restore the current user after each fixture. */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Transport text, including legacy entities.
	 *
	 * @return array
	 */
	public static function diagnostic_text(): array {
		return array(
			array( '<img src=x onerror=alert(1)>' ),
			array( '&lt;img src=x onerror=alert(1)&gt;' ),
			array( 'A & B' ),
			array( '"><img src=x onerror=alert(1)>' ),
		);
	}

	/**
	 * HTTP diagnostics remain escaped in the exception returned to the handler.
	 *
	 * @dataProvider diagnostic_text
	 * @param string $extra Transport diagnostic.
	 */
	public function test_reconnect_http_error_escapes_transport_text( $extra ): void {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$fetch   = static function () use ( $extra ) {
			throw new MainWP_Exception( 'HTTPERROR', $extra );
		};
		add_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
		try {
			MainWP_Manage_Sites_View::m_reconnect_site( $website, false );
			$this->fail( 'The transport failure must reach the reconnect catch.' );
		} catch ( MainWP_Exception $exception ) {
			$this->assertSame( 'HTTP error - ' . esc_html( $extra ), $exception->getMessage() );
			$this->assertStringNotContainsString( '<img', $exception->getMessage() );
		} finally {
			remove_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
		}
	}

	/**
	 * Add-site diagnostics use the same transport interception as reconnect.
	 *
	 * @dataProvider diagnostic_text
	 * @param string $extra Transport diagnostic.
	 */
	public function test_add_site_http_error_escapes_transport_text( $extra ): void {
		$fetch = static function () use ( $extra ) {
			throw new MainWP_Exception( 'HTTPERROR', $extra );
		};
		add_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
		add_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		try {
			$params = array(
				'name'    => 'Fixture',
				'url'     => 'https://child.example/',
				'wpadmin' => 'admin',
			);
			$result = MainWP_Manage_Sites_View::add_wp_site( false, $params );
			$this->assertSame( 'HTTP error - ' . esc_html( $extra ), $result[1] );
			$this->assertStringNotContainsString( '<img', $result[1] );
		} finally {
			remove_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
			remove_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		}
	}

	/**
	 * Unsafe schemes and ordinary Child URLs.
	 *
	 * @return array
	 */
	public static function post_links(): array {
		return array(
			array( 'javascript:alert(1)' ),
			array( 'javascript&#58;alert(1)' ),
			array( 'https://child.example/post?a=1&b=2' ),
			array( 'https://child.example/" onmouseover="alert(1)' ),
		);
	}

	/**
	 * Child URLs cannot introduce executable href schemes or attributes.
	 *
	 * @dataProvider post_links
	 * @param string $link Child post link.
	 */
	public function test_bulk_post_child_link_is_url_escaped( $link ): void {
		$website = (object) array(
			'id'  => 999999,
			'url' => 'https://child.example/',
		);
		$output  = (object) array(
			'ok'   => array(),
			'link' => array(),
		);
		$data    = '<mainwp>' . base64_encode(
			wp_json_encode(
				array(
					'added' => true,
					'link'  => $link,
				)
			)
		) . '</mainwp>';
		MainWP_Bulk_Add::posting_bulk_handler( $data, $website, $output );
		$this->assertSame( esc_url( $link ), $output->link[ $website->id ] );
		$this->assertDoesNotMatchRegularExpression( '/^javascript:/i', html_entity_decode( $output->link[ $website->id ], ENT_QUOTES ) );
	}

	/**
	 * Child URLs cannot introduce executable href schemes or attributes.
	 *
	 * @dataProvider post_links
	 * @param string $link Child post link.
	 */
	public function test_both_view_post_emitters_url_escape_the_href( $link ): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/pages/page-mainwp-post-page-handler.php' );
		$lines  = array_values(
			array_filter(
				explode( "\n", $source ),
				static function ( $line ) {
					return false !== strpos( $line, '>View Post</a>' );
				}
			)
		);
		$this->assertCount( 2, $lines, 'Exercise both the page and AJAX href emitters.' );
		foreach ( $lines as $line ) {
			$website        = (object) array( 'id' => 7 );
			$output         = (object) array( 'link' => array( 7 => $link ) );
			$succes_message = 'Created';
			$ajax_result    = '';
			ob_start();
			try {
				// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar
				// nosemgrep: AIK_eval-use -- evaluates href expressions read from this repository's own source, never input
				eval( $line ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Execute the real href expressions without sending a post to a Child or terminating on the AJAX exit.
			} finally {
				$html = ob_get_clean();
			}
			$html .= $ajax_result;
			$this->assertStringContainsString( 'href="' . esc_url( $link ) . '"', $html );
			$this->assertStringNotContainsString( 'href="javascript', $html );
			$this->assertStringNotContainsString( '" onmouseover=', $html );
		}
	}
}
