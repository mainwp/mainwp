<?php
/**
 * Credential handling in Sites import display rows.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Manage_Sites_View;

/** Exercise both import entry paths through their rendered attributes. */
class Test_MWP_2121_Site_Import_Output extends \WP_UnitTestCase {
    /** Uploaded rows keep credentials only in the operational payload. */
    public function test_csv_display_masks_credentials_and_preserves_other_columns() {
        $csv = '"Site, ""quoted"" &amp;",https://import.example.test,admin,fixture-admin-password,Tag,fixture-security-id,http-user,http-password,Y,auto';
        $process = proc_open(
            array( PHP_BINARY, __DIR__ . '/fixtures/site-import-output.inc' ),
            array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ),
            $pipes
        );
        $this->assertIsResource( $process );
        fwrite( $pipes[0], wp_json_encode( array( 'csv' => $csv ) ) );
        fclose( $pipes[0] );
        $html = stream_get_contents( $pipes[1] );
        $err  = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $this->assertSame( 0, proc_close( $process ), $err );
        $this->assert_display_row( $html );
    }

    /** Form imports use the same safe display row as CSV uploads. */
    public function test_form_display_masks_credentials_without_changing_import_data() {
        $post    = $_POST;
        $request = $_REQUEST;
        $user_id = get_current_user_id();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_POST = array(
            'mainwp_managesites_import' => array(
                array(
                    'site_name'      => 'Site, "quoted" &amp;',
                    'site_url'       => 'https://import.example.test',
                    'admin_name'     => 'admin',
                    'admin_password' => 'fixture-admin-password',
                    'tag'            => 'Tag',
                    'security_id'    => 'fixture-security-id',
                    'http_username'  => 'http-user',
                    'http_password'  => 'http-password',
                    'ssl_version'    => 'auto',
                ),
            ),
        );
        $_REQUEST['_wpnonce'] = wp_create_nonce( 'mainwp-admin-nonce' );
        ob_start();
        try {
            MainWP_Manage_Sites_View::render_import_sites();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
            $_POST    = $post;
            $_REQUEST = $request;
            wp_set_current_user( $user_id );
        }
        $this->assert_display_row( $html );
    }

    /** Empty credentials stay unused; a literal zero is still a credential. */
    public function test_empty_credentials_stay_empty_and_zero_credentials_are_masked() {
        $formatter = new \ReflectionMethod( MainWP_Manage_Sites_View::class, 'import_display_line' );
        $formatter->setAccessible( true );
        $fields = array( 'Site', 'https://import.example.test', 'admin', '', 'Tag', '', '', '', 'Y', 'auto' );
        $this->assertSame( 'Site,https://import.example.test,admin,,Tag,,,,Y,auto', $formatter->invoke( null, $fields ) );

        $fields[3] = '0';
        $fields[5] = '0';
        $fields[7] = '0';
        $this->assertSame( 'Site,https://import.example.test,admin,********,Tag,********,,********,Y,auto', $formatter->invoke( null, $fields ) );
    }

    /**
     * Check the browser-decoded display and operational attributes separately.
     *
     * @param string $html Production renderer output.
     */
    private function assert_display_row( $html ) {
        $document = new \DOMDocument();
        @$document->loadHTML( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The renderer returns an HTML fragment.
        $input = $document->getElementById( 'mainwp_managesites_import_csv_line_1' );
        $this->assertInstanceOf( \DOMElement::class, $input );
        $display = $input->getAttribute( 'original' );
        $fields  = str_getcsv( $display, ',', '"', '' );
        $this->assertSame( '********', $fields[3], 'Admin password must be masked in display/export.' );
        $this->assertSame( '********', $fields[5], 'Security ID must be masked in display/export.' );
        $this->assertSame( '********', $fields[7], 'HTTP password must be masked in display/export.' );
        $this->assertStringNotContainsString( 'fixture-admin-password', $display );
        $this->assertStringNotContainsString( 'fixture-security-id', $display );
        $this->assertStringNotContainsString( 'http-password', $display );
        $this->assertSame( 'Site, "quoted" &amp;', $fields[0] );
        $this->assertSame( array( 'https://import.example.test', 'admin', 'Tag', 'http-user', '********', 'auto' ), array( $fields[1], $fields[2], $fields[4], $fields[6], $fields[7], $fields[9] ) );
        $this->assertStringContainsString( '&quot;', $html, 'Attribute quotes remain escaped.' );
        $operational = json_decode( $input->getAttribute( 'encoded-data' ), true );
        $this->assertIsArray( $operational );
        $this->assertSame( 'fixture-admin-password', $operational['adminpwd'] );
        $this->assertSame( 'fixture-security-id', $operational['uniqueId'] );
        $this->assertSame( 'http-password', $operational['http_pass'] );
    }
}
