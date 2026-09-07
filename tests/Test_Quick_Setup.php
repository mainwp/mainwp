<?php
/**
 * Quick Setup client-contract tests.
 *
 * @package MainWP/Dashboard
 */

/**
 * Tests Quick Setup behavior that is implemented in the browser script.
 */
class Test_Quick_Setup extends \WP_UnitTestCase {

    /** A blank optional site title must fall back to the normalized site URL. */
    public function test_optional_site_title_uses_url_fallback() {
        $script = file_get_contents( MAINWP_PLUGIN_DIR . 'assets/js/mainwp-setup.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

        $this->assertIsString( $script );
        $this->assertStringNotContainsString( 'Please enter a title for the website.', $script );
        $this->assertStringContainsString( "name = '' === name ? url : name;", $script );
    }
}
