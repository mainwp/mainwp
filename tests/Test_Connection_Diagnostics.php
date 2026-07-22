<?php
/**
 * Connection diagnosis classifier and output-boundary tests.
 *
 * @package MainWP/Dashboard
 */

use MainWP\Dashboard\MainWP_Connection_Diagnostics;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Tests the closed connection diagnosis contract without making network calls.
 */
class Test_Connection_Diagnostics extends \WP_UnitTestCase {

    /** A valid Child frame wins over transport and HTTP evidence. */
    public function test_child_frame_has_highest_precedence() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'          => 'child_endpoint',
                'mode'           => 'probe',
                'child_frame'    => true,
                'curl_errno'     => 28,
                'http_status'    => 403,
                'header_blocks'  => array(),
            )
        );

        $this->assertSame( 'success', $diagnosis['verdict'] );
        $this->assertSame( 'child_responded', $diagnosis['diagnosis_id'] );
    }

    /** Transport failures win over provider-shaped response bytes. */
    public function test_transport_error_precedes_provider_detection() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'               => 'child_endpoint',
                'curl_errno'          => 28,
                'http_status'         => 403,
                'peer_ip'             => '8.8.8.8',
                'bounded_body_sample' => 'wsidchk',
                'header_blocks'       => array(),
            )
        );

        $this->assertSame( 'connection_timed_out', $diagnosis['diagnosis_id'] );
        $this->assertArrayNotHasKey( 'provider', $diagnosis );
    }

    /** Distinctive provider evidence wins over the generic HTTP classifier. */
    public function test_provider_marker_precedes_generic_http_block() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'               => 'child_endpoint',
                'curl_errno'          => 0,
                'http_status'         => 403,
                'peer_ip'             => '8.8.8.8',
                'bounded_body_sample' => '<html>wsidchk</html>',
                'header_blocks'       => array(),
            )
        );

        $this->assertSame( 'imunify360_request_blocked', $diagnosis['diagnosis_id'] );
        $this->assertSame( 'imunify360', $diagnosis['provider'] );
    }

    /** A generic Cloudflare server header is not enough to name a provider. */
    public function test_cloudflare_header_alone_is_not_provider_evidence() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'               => 'child_endpoint',
                'curl_errno'          => 0,
                'http_status'         => 403,
                'peer_ip'             => '8.8.8.8',
                'bounded_body_sample' => '<html>Forbidden</html>',
                'header_blocks'       => array(
                    array(
                        'headers' => array(
                            'server' => array( 'cloudflare' ),
                            'cf-ray' => array( 'test' ),
                        ),
                    ),
                ),
            )
        );

        $this->assertSame( 'request_blocked', $diagnosis['diagnosis_id'] );
        $this->assertArrayNotHasKey( 'provider', $diagnosis );
    }

    /** The explicit Cloudflare mitigation header is sufficient without a text body. */
    public function test_cloudflare_mitigation_header_works_without_body() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'               => 'child_endpoint',
                'curl_errno'          => 0,
                'http_status'         => 403,
                'peer_ip'             => '8.8.8.8',
                'bounded_body_sample' => '',
                'header_blocks'       => array(
                    array(
                        'headers' => array(
                            'cf-mitigated' => array( 'challenge' ),
                        ),
                    ),
                ),
            )
        );

        $this->assertSame( 'cloudflare_request_blocked', $diagnosis['diagnosis_id'] );
        $this->assertSame( 'cloudflare', $diagnosis['provider'] );
    }

    /** A proxy peer is not sufficient evidence that origin response bytes are public. */
    public function test_proxy_requests_skip_provider_body_detection() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'               => 'child_endpoint',
                'curl_errno'          => 0,
                'http_status'         => 403,
                'peer_ip'             => '8.8.8.8',
                'via_proxy'           => true,
                'bounded_body_sample' => 'wsidchk',
                'header_blocks'       => array(),
            )
        );

        $this->assertSame( 'request_blocked', $diagnosis['diagnosis_id'] );
        $this->assertArrayNotHasKey( 'provider', $diagnosis );
    }

    /** Body/provider inference is disabled when the connected peer is private. */
    public function test_private_peer_skips_body_provider_detection() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'               => 'child_endpoint',
                'curl_errno'          => 0,
                'http_status'         => 403,
                'peer_ip'             => '127.0.0.1',
                'bounded_body_sample' => 'wsidchk',
                'header_blocks'       => array(),
            )
        );

        $this->assertSame( 'request_blocked', $diagnosis['diagnosis_id'] );
        $this->assertArrayNotHasKey( 'provider', $diagnosis );
    }

    /** Non-public special ranges never enable body/provider inference. */
    public function test_non_global_special_ranges_are_rejected() {
        $this->assertFalse( MainWP_Connection_Diagnostics::is_global_ip( '100.64.0.1' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_global_ip( '198.18.0.1' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_global_ip( '224.0.0.1' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_global_ip( '::ffff:127.0.0.1' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_global_ip( '100::1' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_global_ip( '64:ff9b:1::1' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_global_ip( '2001:db8::1' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_global_ip( 'ff02::1' ) );
        $this->assertTrue( MainWP_Connection_Diagnostics::is_global_ip( '8.8.8.8' ) );
    }

    /** Valid framing requires strict base64 and a JSON array. */
    public function test_child_frame_parser_is_strict() {
        $method = new ReflectionMethod( MainWP_Connection_Diagnostics::class, 'parse_child_frame' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }

        $valid = '<mainwp>' . base64_encode( wp_json_encode( array( 'error_code' => 'PARSE_ERROR1' ) ) ) . '</mainwp>';
        $this->assertSame( array( 'error_code' => 'PARSE_ERROR1' ), $method->invoke( null, $valid ) );
        $this->assertNull( $method->invoke( null, '<mainwp>not-base64!</mainwp>' ) );
        $this->assertNull( $method->invoke( null, '<mainwp>' . base64_encode( '"scalar"' ) . '</mainwp>' ) );
        $this->assertNull( $method->invoke( null, '<html>' . $valid . '</html>' ) );
        $this->assertSame( array(), $method->invoke( null, '<mainwp>' . base64_encode( '{}' ) . '</mainwp>' ) );
    }

    /** An empty framed object is not sufficient Child/authentication evidence. */
    public function test_empty_frame_is_not_a_successful_probe() {
        $method = new ReflectionMethod( MainWP_Connection_Diagnostics::class, 'classify_request_result' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }
        $result = array(
            'body'        => '<mainwp>' . base64_encode( '{}' ) . '</mainwp>',
            'observation' => array(
                'phase'               => 'child_endpoint',
                'mode'                => 'probe',
                'curl_errno'          => 0,
                'http_status'         => 200,
                'header_blocks'       => array(),
                'bounded_body_sample' => '',
            ),
        );

        $export = $method->invoke( null, $result, array( 'mode' => 'probe', 'authentication_expected' => true ) );
        $this->assertSame( 'child_did_not_respond', $export['diagnosis']['diagnosis_id'] );

        $result['body'] = '<mainwp>' . base64_encode( wp_json_encode( array( 'foo' => 'bar' ) ) ) . '</mainwp>';
        $export         = $method->invoke( null, $result, array( 'mode' => 'probe', 'authentication_expected' => true ) );
        $this->assertSame( 'child_did_not_respond', $export['diagnosis']['diagnosis_id'] );
    }

    /** PARSE_ERROR3/4 are authenticated Child errors even without free-form text. */
    public function test_authenticated_parse_error_code_is_a_failure_without_error_text() {
        $method = new ReflectionMethod( MainWP_Connection_Diagnostics::class, 'classify_request_result' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }
        $result = array(
            'body'        => '<mainwp>' . base64_encode( wp_json_encode( array( 'error_code' => 'PARSE_ERROR3' ) ) ) . '</mainwp>',
            'observation' => array(
                'phase'               => 'child_endpoint',
                'mode'                => 'probe',
                'curl_errno'          => 0,
                'http_status'         => 200,
                'header_blocks'       => array(),
                'bounded_body_sample' => '',
            ),
        );

        $export = $method->invoke( null, $result, array( 'mode' => 'probe', 'authentication_expected' => true ) );
        $this->assertSame( 'failure', $export['diagnosis']['verdict'] );
        $this->assertSame( 'mainwp_child_error', $export['diagnosis']['diagnosis_id'] );
    }

    /** Public output contains no raw observation values. */
    public function test_export_does_not_leak_raw_observation() {
        $sentinel = 'MWP-RAW-SENTINEL';
        $observation = array(
            'phase'               => 'child_endpoint',
            'curl_errno'          => 6,
            'curl_error'          => $sentinel . '-curl',
            'http_status'         => 0,
            'requested_url'       => 'https://' . $sentinel . '.example/',
            'effective_url'       => 'https://' . $sentinel . '.example/redirect',
            'peer_ip'             => '203.0.113.10',
            'bounded_body_sample' => $sentinel . '-body',
            'header_blocks'       => array(
                array( 'headers' => array( 'x-secret' => array( $sentinel . '-header' ) ) ),
            ),
        );

        $export = MainWP_Connection_Diagnostics::export( MainWP_Connection_Diagnostics::diagnose( $observation ), $observation );
        $json   = wp_json_encode( $export );

        $this->assertStringNotContainsString( $sentinel, $json );
        $this->assertSame( 'failure', $export['support']['verdict'] );
        $this->assertSame( 6, $export['support']['curl_errno'] );
        $this->assertSame( 'dns_resolution_failed', $export['support']['diagnosis_id'] );
    }

    /** A subdirectory install keeps its path when building the Child endpoint. */
    public function test_endpoint_builder_preserves_subdirectory() {
        $method = new ReflectionMethod( MainWP_Connection_Diagnostics::class, 'endpoint_url' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }

        $this->assertSame(
            'https://example.test/wordpress/wp-admin/admin-ajax.php',
            $method->invoke( null, 'https://example.test/wordpress/?ignored=yes#fragment' )
        );
    }

    /** Saved transport context may be reused only for the exact saved origin. */
    public function test_same_origin_comparison_is_strict() {
        $this->assertTrue( MainWP_Connection_Diagnostics::is_same_origin( 'https://EXAMPLE.test/path', 'https://example.test:443/other' ) );
        $this->assertTrue( MainWP_Connection_Diagnostics::is_same_origin( 'http://[::1]/path', 'http://[::1]:80/other' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_same_origin( 'https://example.test/', 'http://example.test/' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_same_origin( 'https://example.test/', 'https://other.test/' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_same_origin( 'https://example.test/', 'https://example.test:444/' ) );
    }

    /** Only endpoint-shaped responses may use the same-origin compatibility fallback. */
    public function test_fallback_ambiguity_excludes_terminal_http_statuses() {
        $diagnosis_401 = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'         => 'child_endpoint',
                'curl_errno'    => 0,
                'http_status'   => 401,
                'header_blocks' => array(),
            )
        );
        $diagnosis_200 = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'         => 'child_endpoint',
                'curl_errno'    => 0,
                'http_status'   => 200,
                'header_blocks' => array(),
            )
        );

        $this->assertFalse( MainWP_Connection_Diagnostics::is_ambiguous( $diagnosis_401 ) );
        $this->assertTrue( MainWP_Connection_Diagnostics::is_ambiguous( $diagnosis_200 ) );
    }

    /** Diagnosis attachment leaves the legacy exception constructor and data intact. */
    public function test_exception_diagnosis_is_separate_from_legacy_data() {
        $exception = new MainWP_Exception( 'NOMAINWP', 'legacy extra', 'legacy_code' );
        $exception->set_data( '[hidden response data]' );
        $exception->set_diagnosis( array( 'diagnosis' => array( 'diagnosis_id' => 'child_did_not_respond' ) ) );

        $this->assertSame( 'NOMAINWP', $exception->getMessage() );
        $this->assertSame( '[hidden response data]', $exception->get_data() );
        $this->assertSame( 'legacy_code', $exception->get_message_error_code() );
        $this->assertSame( 'child_did_not_respond', $exception->get_diagnosis()['diagnosis']['diagnosis_id'] );
    }
}
