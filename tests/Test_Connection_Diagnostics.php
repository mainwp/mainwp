<?php
/**
 * Connection diagnosis classifier and output-boundary tests.
 *
 * @package MainWP/Dashboard
 */

use MainWP\Dashboard\MainWP_Connection_Diagnostics;
use MainWP\Dashboard\MainWP_Credential_Render;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Post_Site_Handler;
use MainWP\Dashboard\MainWP_UI;

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

    /** A refused connection has an actionable diagnosis instead of the generic fallback. */
    public function test_connection_refused_has_specific_presentation() {
        $observation = array(
            'phase'         => 'child_endpoint',
            'curl_errno'    => 7,
            'http_status'   => 0,
            'header_blocks' => array(),
        );
        $export = MainWP_Connection_Diagnostics::export( MainWP_Connection_Diagnostics::diagnose( $observation ), $observation );

        $this->assertSame( 'connection_failed', $export['diagnosis']['diagnosis_id'] );
        $this->assertSame( 'connection_failure', $export['diagnosis']['category'] );
        $this->assertSame( 'The Dashboard could not connect to the site.', $export['presentation']['title'] );
        $this->assertSame( 'MainWP Child — Not tested', $export['presentation']['child']['label'] );
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

    /** The SiteGround CAPTCHA header name is sufficient without a text body. */
    public function test_siteground_header_name_is_high_confidence_provider_evidence() {
        $sentinel    = 'MWP-SITEGROUND-RAW-SENTINEL';
        $observation = array(
            'phase'               => 'child_endpoint',
            'curl_errno'          => 0,
            'http_status'         => 202,
            'peer_ip'             => '8.8.8.8',
            'bounded_body_sample' => '',
            'header_blocks'       => array(
                array(
                    'headers' => array(
                        'sg-captcha' => array( $sentinel ),
                    ),
                ),
            ),
        );

        $diagnosis = MainWP_Connection_Diagnostics::diagnose( $observation );
        $export    = MainWP_Connection_Diagnostics::export( $diagnosis, $observation );

        $this->assertSame( 'siteground_request_blocked', $diagnosis['diagnosis_id'] );
        $this->assertSame( 'siteground', $diagnosis['provider'] );
        $this->assertSame( 'request_blocked', $diagnosis['category'] );
        $this->assertSame( 'high', $diagnosis['category_confidence'] );
        $this->assertSame( 'high', $diagnosis['provider_confidence'] );
        $this->assertSame( 'siteground', $export['support']['provider'] );
        $this->assertSame( 'SiteGround appears to have challenged the MainWP request.', $export['presentation']['title'] );
        $this->assertSame( 'The response contained a distinctive SiteGround CAPTCHA marker.', $export['presentation']['explanation'] );
        $this->assertSame( array( 'Ask SiteGround Support to allow requests from your MainWP Dashboard, then test again.' ), $export['presentation']['steps'] );
        $this->assertSame( array( 'state' => 'success', 'label' => 'Server response — HTTP 202' ), $export['presentation']['server'] );
        $this->assertSame( array( 'state' => 'failure', 'label' => 'MainWP Child — Did not respond' ), $export['presentation']['child'] );
        $this->assertContains( 'SiteGround CAPTCHA marker detected', $export['presentation']['facts'] );
        $this->assertStringNotContainsString( $sentinel, wp_json_encode( $export ) );
    }

    /** Generic SiteGround-like response metadata and HTTP 202 are not attribution evidence. */
    public function test_siteground_generic_metadata_is_not_provider_evidence() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'               => 'child_endpoint',
                'curl_errno'          => 0,
                'http_status'         => 202,
                'peer_ip'             => '8.8.8.8',
                'bounded_body_sample' => '<html>SiteGround CAPTCHA at /.well-known/sgcaptcha/</html>',
                'header_blocks'       => array(
                    array(
                        'headers' => array(
                            'x-robots-tag' => array( 'noindex' ),
                            'cache-control' => array( 'no-store' ),
                            'x-sg-cdn'      => array( '1' ),
                        ),
                    ),
                ),
            )
        );

        $this->assertSame( 'child_did_not_respond', $diagnosis['diagnosis_id'] );
        $this->assertArrayNotHasKey( 'provider', $diagnosis );
    }

    /** SiteGround headers do not bypass direct globally routed peer requirements. */
    public function test_siteground_header_requires_a_direct_global_peer() {
        $observations = array(
            array( 'peer_ip' => '' ),
            array( 'peer_ip' => '127.0.0.1' ),
            array( 'peer_ip' => '8.8.8.8', 'via_proxy' => true ),
        );

        foreach ( $observations as $context ) {
            $diagnosis = MainWP_Connection_Diagnostics::diagnose(
                array_merge(
                    array(
                        'phase'               => 'child_endpoint',
                        'curl_errno'          => 0,
                        'http_status'         => 202,
                        'bounded_body_sample' => '',
                        'header_blocks'       => array(
                            array(
                                'headers' => array(
                                    'sg-captcha' => array( 'challenge' ),
                                ),
                            ),
                        ),
                    ),
                    $context
                )
            );

            $this->assertSame( 'child_did_not_respond', $diagnosis['diagnosis_id'] );
            $this->assertArrayNotHasKey( 'provider', $diagnosis );
        }
    }

    /** Provider attribution requires a known globally routable peer. */
    public function test_provider_detection_requires_a_non_empty_peer() {
        $diagnosis = MainWP_Connection_Diagnostics::diagnose(
            array(
                'phase'               => 'child_endpoint',
                'curl_errno'          => 0,
                'http_status'         => 403,
                'peer_ip'             => '',
                'bounded_body_sample' => '<html>wsidchk</html>',
                'header_blocks'       => array(
                    array(
                        'headers' => array(
                            'cf-mitigated' => array( 'challenge' ),
                        ),
                    ),
                ),
            )
        );

        $this->assertSame( 'request_blocked', $diagnosis['diagnosis_id'] );
        $this->assertArrayNotHasKey( 'provider', $diagnosis );
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

    /** Current Child's strict error-only frame proves it handled an unconnected probe. */
    public function test_unconnected_error_only_frame_proves_child_responded() {
        $method = new ReflectionMethod( MainWP_Connection_Diagnostics::class, 'classify_request_result' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }
        $result = array(
            'body'        => '<mainwp>' . base64_encode( wp_json_encode( array( 'error' => 'Authentication failed.' ) ) ) . '</mainwp>',
            'observation' => array(
                'phase'               => 'child_endpoint',
                'mode'                => 'probe',
                'curl_errno'          => 0,
                'http_status'         => 200,
                'header_blocks'       => array(),
                'bounded_body_sample' => '',
            ),
        );

        $export = $method->invoke( null, $result, array( 'mode' => 'probe', 'authentication_expected' => false ) );
        $this->assertSame( 'success', $export['diagnosis']['verdict'] );
        $this->assertSame( 'child_responded', $export['diagnosis']['diagnosis_id'] );
    }

    /** Arbitrary or augmented framed JSON never proves an unconnected Child response. */
    public function test_unconnected_arbitrary_frames_are_not_successful() {
        $method = new ReflectionMethod( MainWP_Connection_Diagnostics::class, 'classify_request_result' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }
        $result = array(
            'body'        => '',
            'observation' => array(
                'phase'               => 'child_endpoint',
                'mode'                => 'probe',
                'curl_errno'          => 0,
                'http_status'         => 200,
                'header_blocks'       => array(),
                'bounded_body_sample' => '',
            ),
        );
        $frames = array(
            array(),
            array( 'foo' => 'bar' ),
            array( 'error' => '' ),
            array( 'error' => 'Authentication failed.', 'foo' => 'bar' ),
        );

        foreach ( $frames as $frame ) {
            $result['body'] = '<mainwp>' . base64_encode( wp_json_encode( $frame ) ) . '</mainwp>';
            $export         = $method->invoke( null, $result, array( 'mode' => 'probe', 'authentication_expected' => false ) );
            $this->assertSame( 'child_did_not_respond', $export['diagnosis']['diagnosis_id'] );
        }
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

    /** Authenticated connected probes reject a cross-origin target before signing or transport. */
    public function test_connected_probe_rejects_cross_origin_target_before_signing() {
        $website = (object) array(
            'id'        => 24,
            'url'       => 'http://child.example/',
            'adminname' => 'saved-child-admin',
            'privkey'   => '',
        );

        $cross_origin = MainWP_Connection_Diagnostics::test_connected(
            $website,
            array( 'url' => 'https://child.example/' )
        );

        $this->assertSame( 'not_tested', $cross_origin['diagnosis']['verdict'] );
        $this->assertSame( 'dashboard_configuration_error', $cross_origin['diagnosis']['diagnosis_id'] );
        $this->assertSame( 0, $cross_origin['support']['http_status'] );
        $this->assertSame( 0, $cross_origin['support']['curl_errno'] );
    }

    /** Whitespace-bearing or malformed URL origins are rejected locally. */
    public function test_invalid_site_urls_are_rejected_before_request() {
        $this->assertTrue( MainWP_Connection_Diagnostics::is_valid_site_url( 'http://child1.local/wordpress/' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_valid_site_url( 'http://bad host/' ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_valid_site_url( "http://example.test/\npath" ) );
        $this->assertFalse( MainWP_Connection_Diagnostics::is_valid_site_url( 'ftp://example.test/' ) );

        $result = MainWP_Connection_Diagnostics::run_probe( 'http://bad host/', '', array() );
        $this->assertSame( 'not_tested', $result['diagnosis']['verdict'] );
        $this->assertSame( 'dashboard_invalid_url', $result['diagnosis']['diagnosis_id'] );
        $this->assertSame( 0, $result['support']['http_status'] );
    }

    /** Millisecond deadlines are rounded down and never gain an extra second. */
    public function test_deadline_budget_never_rounds_up() {
        $method = new ReflectionMethod( MainWP_Connection_Diagnostics::class, 'remaining_timeout_milliseconds' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }

        $this->assertSame( 24999, $method->invoke( null, 124.9999, 100.0 ) );
        $this->assertSame( 1, $method->invoke( null, 100.0019, 100.0 ) );
        $this->assertSame( 0, $method->invoke( null, 99.0, 100.0 ) );
    }

    /** The browser watchdog includes a bounded margin around the transport deadline. */
    public function test_connection_modal_exports_browser_request_timeout() {
        ob_start();
        MainWP_UI::render_modal_connection_test();
        $html = ob_get_clean();

        $this->assertStringContainsString(
            'data-mainwp-diagnostic-request-timeout="' . ( ( MainWP_Connection_Diagnostics::DEFAULT_DEADLINE_SECONDS + 10 ) * 1000 ) . '"',
            $html
        );
        $this->assertStringContainsString( 'data-mainwp-diagnostic-action="copy"', $html );
        $this->assertStringContainsString( 'data-mainwp-diagnostic-action="learn-more"', $html );
        $this->assertStringNotContainsString( 'data-mainwp-diagnostic-action="test-again"', $html );
        $this->assertStringNotContainsString( 'data-mainwp-diagnostic-action="close"', $html );
        $this->assertStringContainsString( 'class="close icon" role="button" tabindex="0"', $html );
        $this->assertStringContainsString( 'aria-label="Close"', $html );
    }

    /** Connection diagnostic scripts do not recreate removed retry or footer-close actions. */
    public function test_connection_diagnostic_scripts_omit_removed_actions() {
        $script = file_get_contents( MAINWP_PLUGIN_DIR . 'assets/js/mainwp.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
        $setup_script = file_get_contents( MAINWP_PLUGIN_DIR . 'assets/js/mainwp-setup.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

        $this->assertIsString( $script );
        $this->assertIsString( $setup_script );
        $this->assertStringNotContainsString( "text(__('Test again'))", $script );
        $this->assertStringNotContainsString( 'mainwpConnectionDiagnosticRetry', $script );
        $this->assertStringNotContainsString( 'data-mainwp-diagnostic-action="close"', $script );
        $this->assertStringNotContainsString( '{ retry:', $setup_script );
        $this->assertStringContainsString( 'let tableRowContents = wrapElement.children().detach();', $script );
        $this->assertStringContainsString( "wrapElement.empty().append(tableRowContents).removeData('mainwp-reconnect-in-flight').show();", $script );
        $this->assertStringContainsString( "let tableRowFocusTarget = wrapElement.find(':focus');", $script );
        $this->assertStringContainsString( "tableRowFocusTarget.trigger('focus');", $script );
        $this->assertSame( 2, substr_count( $script, 'restoreTableRow();' ) );
        $this->assertStringNotContainsString( 'let tableRowHtml =', $script );
        $this->assertStringContainsString( "if (element.data('mainwp-reconnect-in-flight'))", $script );
        $this->assertStringContainsString( 'let cardActionContents = element.contents().detach();', $script );
        $this->assertStringContainsString( 'element.empty().append(cardActionContents);', $script );
        $this->assertStringContainsString( "element.removeData('mainwp-reconnect-in-flight').removeAttr('aria-busy');", $script );
        $this->assertSame( 2, substr_count( $script, 'restoreCardAction();' ) );
        $this->assertStringNotContainsString( 'let cardActionHtml =', $script );
    }

    /** Cross-origin diagnostics bypass request-header filters and saved site context. */
    public function test_cross_origin_headers_are_isolated_from_filters() {
        $method = new ReflectionMethod( MainWP_Connection_Diagnostics::class, 'request_headers' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }
        $calls  = 0;
        $filter = static function ( $headers ) use ( &$calls ) {
            ++$calls;
            $headers['X-MWP-Saved-Secret'] = 'must-not-cross-origin';
            return $headers;
        };
        add_filter( 'mainwp_connect_http_request_headers', $filter );

        try {
            $unconnected = $method->invoke( null, 'function=connection_check', null, false );
            $this->assertSame( 0, $calls );
            $this->assertStringNotContainsString( 'X-MWP-Saved-Secret', implode( "\n", $unconnected ) );

            $isolated = $method->invoke( null, 'function=connection_check', (object) array( 'id' => 24 ), false );
            $this->assertSame( 0, $calls );
            $this->assertStringNotContainsString( 'X-MWP-Saved-Secret', implode( "\n", $isolated ) );

            $contextual = $method->invoke( null, 'function=connection_check', (object) array( 'id' => 24 ), true );
            $this->assertSame( 1, $calls );
            $this->assertStringContainsString( 'X-MWP-Saved-Secret', implode( "\n", $contextual ) );
        } finally {
            remove_filter( 'mainwp_connect_http_request_headers', $filter );
        }
    }

    /** Cross-origin drafts never reuse saved Basic credentials or the password sentinel. */
    public function test_cross_origin_drafts_do_not_reuse_saved_http_credentials() {
        $method = new ReflectionMethod( MainWP_Post_Site_Handler::class, 'resolve_test_http_credentials' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }
        $website = (object) array(
            'http_user' => 'saved-user',
            'http_pass' => 'saved-pass',
        );

        $unchanged = $method->invoke( null, $website, false, 'saved-user', MainWP_Credential_Render::SENTINEL );
        $this->assertSame( array( 'user' => '', 'pass' => '' ), $unchanged );

        $password_only = $method->invoke( null, $website, false, 'saved-user', 'new-pass' );
        $this->assertSame( array( 'user' => '', 'pass' => 'new-pass' ), $password_only );

        $new_pair = $method->invoke( null, $website, false, 'new-user', 'new-pass' );
        $this->assertSame( array( 'user' => 'new-user', 'pass' => 'new-pass' ), $new_pair );

        $same_origin = $method->invoke( null, $website, true, 'saved-user', MainWP_Credential_Render::SENTINEL );
        $this->assertSame( array( 'user' => 'saved-user', 'pass' => 'saved-pass' ), $same_origin );
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
