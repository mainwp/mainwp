<?php
/**
 * Download credentials bind their file, and public DB orders accept identifiers only.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The transport probe records serving without sending files or terminating PHPUnit.
// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Fixtures exercise the unauthenticated download request.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the production handler to capture its exits.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write small files under the test-owned temporary directory.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inspect executed SQL and insert a test-owned group membership.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Execute production SQL builders to verify escaping rather than preparing their output again.

use MainWP\Dashboard\Download_Order_System;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_System_Utility;

/** Capture only file transport; validation still runs against production code. */
class Download_Order_Transport {

    /**
     * Served paths.
     *
     * @var array
     */
    public static $served = array();

    /**
     * Create the transport probe.
     *
     * @return self Transport probe.
     */
    public static function instance() {
        return new self();
    }

    /**
     * Record a successful download.
     *
     * @param string $file Served file.
     */
    public function upload_file( $file ) {
        self::$served[] = $file;
    }

    /**
     * CLI cannot emit headers after PHPUnit output.
     *
     * @param string $header Response header.
     */
    public static function header( $header ) {
        unset( $header );
    }
}

/** Exercise the SQL executors and the source-derived download handler. */
class Test_Dashboard_Download_Signature_And_Order extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Owned temporary root.
     *
     * @var string
     */
    private $directory;

    /**
     * Same-size files.
     *
     * @var array
     */
    private $files;

    /**
     * Original request, user and filesystem state.
     *
     * @var array
     */
    private $state;

    /** Preserve the handler's guards and resolution, replacing only transport and exits. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $method = new \ReflectionMethod( MainWP_System::class, 'parse_init' );
        $lines  = explode( "\n", file_get_contents( $method->getFileName() ) );
        $source = "<?php\n" . implode( "\n", array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
        $code   = '';
        foreach ( token_get_all( $source ) as $token ) {
            if ( ! is_array( $token ) ) {
                $code .= $token;
            } elseif ( T_OPEN_TAG !== $token[0] ) {
                if ( T_EXIT === $token[0] ) {
                    $code .= 'throw new \\RuntimeException';
                } elseif ( T_STRING === $token[0] && 'MainWP_System_Handler' === $token[1] ) {
                    $code .= '\\MainWP\\Dashboard\\Tests\\Download_Order_Transport';
                } elseif ( T_STRING === $token[0] && 'header' === $token[1] ) {
                    $code .= '\\MainWP\\Dashboard\\Tests\\Download_Order_Transport::header';
                } else {
                    $code .= $token[1];
                }
            }
        }
        eval( 'namespace MainWP\\Dashboard; class Download_Order_System { ' . $code . ' }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture the real parse_init exit without terminating the test process.
    }

    /** Isolate filesystem fixtures from installed sites. */
    public function setUp(): void {
        parent::setUp();
        global $wp_filesystem;
        $this->state     = array( $_GET, $_POST, $_REQUEST, get_current_user_id(), $wp_filesystem );
        $this->directory = get_temp_dir() . 'mainwp-download-order-' . wp_generate_uuid4();
        wp_mkdir_p( $this->directory . '/mainwp/0/bulk' );
        $this->files = array( $this->directory . '/mainwp/0/bulk/a file.zip', $this->directory . '/mainwp/0/bulk/b.zip' );
        foreach ( $this->files as $file ) {
            file_put_contents( $file, 'test' );
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $wp_filesystem = new \WP_Filesystem_Direct( null );
        add_filter( 'mainwp_get_wp_upload_dir', array( $this, 'upload_directory' ) );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_GET                             = array();
        $_POST                            = array();
        $_REQUEST                         = array();
        Download_Order_Transport::$served = array();
    }

    /** Restore the request and remove only owned files. */
    public function tearDown(): void {
        global $wp_filesystem;
        remove_filter( 'mainwp_get_wp_upload_dir', array( $this, 'upload_directory' ) );
        $wp_filesystem->rmdir( $this->directory, true );
        list( $_GET, $_POST, $_REQUEST, $user, $wp_filesystem ) = $this->state;
        wp_set_current_user( $user );
        parent::tearDown();
    }

    /**
     * Redirect MainWP's uploads into the fixture.
     *
     * @param array $upload WordPress upload settings.
     * @return array Fixture settings.
     */
    public function upload_directory( $upload ) {
        $upload['basedir'] = $this->directory;
        return $upload;
    }

    /** New links carry a version and three-hour expiry, without per-link transients. */
    public function test_signature_format_and_stable_upgrade_timestamp() {
        global $wpdb;
        delete_option( 'mainwp_download_sig_upgrade_timestamp' );
        $before = time();
        $sig    = MainWP_System_Utility::get_download_sig( $this->files[0] );
        $value  = json_decode( rawurldecode( $sig ), true );
        $this->assertSame( array( 'version', 'root', 'path', 'expires', 'mac' ), array_keys( $value ) );
        $this->assertSame( 1, $value['version'] );
        $this->assertSame( 'mainwp', $value['root'] );
        $this->assertSame( '0/bulk/a file.zip', $value['path'] );
        $this->assertGreaterThanOrEqual( $before + 3 * HOUR_IN_SECONDS, $value['expires'] );
        $this->assertLessThanOrEqual( time() + 3 * HOUR_IN_SECONDS, $value['expires'] );
        $this->assertSame( $this->encode_signature( $value ), $sig );
        $stamp = get_option( 'mainwp_download_sig_upgrade_timestamp' );
        $this->assertGreaterThanOrEqual( $before, $stamp );
        update_option( 'mainwp_download_sig_upgrade_timestamp', $before - HOUR_IN_SECONDS, false );
        MainWP_System_Utility::get_download_sig( $this->files[0] );
        $this->assertSame( $before - HOUR_IN_SECONDS, (int) get_option( 'mainwp_download_sig_upgrade_timestamp' ) );
        $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", 'mainwp_private_filename_secret' ) );
        $this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );
        $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", 'mainwp_download_sig_upgrade_timestamp' ) );
        $this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );
    }

    /** Same-size files must not share a download credential. */
    public function test_signature_binds_file_and_custom_root() {
        $sig = MainWP_System_Utility::get_download_sig( $this->files[0] );
        $this->assertTrue( MainWP_System_Utility::valid_download_sig( $this->files[0], $sig ) );
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[1], $sig ) );
        $external = $this->directory . '/custom.zip';
        file_put_contents( $external, 'test' );
        $sig   = MainWP_System_Utility::get_download_sig( $external );
        $value = json_decode( rawurldecode( $sig ), true );
        $this->assertSame( 'absolute', $value['root'] );
        $this->assertSame( wp_normalize_path( realpath( $external ) ), $value['path'] );
        $this->assertTrue( MainWP_System_Utility::valid_download_sig( $external, $sig ) );
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[0], $sig ) );
    }

    /** POSIX backslash filenames must retain distinct identities in both roots. */
    public function test_signature_distinguishes_posix_backslash_filenames() {
        if ( '\\' === DIRECTORY_SEPARATOR ) {
            $this->markTestSkipped( 'Backslash is a directory separator on Windows.' );
        }

        wp_mkdir_p( $this->directory . '/mainwp/0/bulk/pkg' );
        wp_mkdir_p( $this->directory . '/ext' );
        $this->files[] = $this->directory . '/mainwp/0/bulk/pkg/item.zip';
        $this->files[] = $this->directory . '/mainwp/0/bulk/pkg\item.zip';
        $this->files[] = $this->directory . '/ext/item.zip';
        $this->files[] = $this->directory . '/ext\item.zip';
        foreach ( array_slice( $this->files, 2 ) as $file ) {
            file_put_contents( $file, 'test' );
            $this->assertFileExists( $file );
        }
        $this->assertNotSame( realpath( $this->files[2] ), realpath( $this->files[3] ) );
        $this->assertSame( filesize( $this->files[2] ), filesize( $this->files[3] ) );
        $sig = MainWP_System_Utility::get_download_sig( $this->files[2] );
        $this->assertTrue( MainWP_System_Utility::valid_download_sig( $this->files[2], $sig ) );
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[3], $sig ) );
        $backslash_sig = MainWP_System_Utility::get_download_sig( $this->files[3] );
        $value         = json_decode( rawurldecode( $backslash_sig ), true );
        $this->assertTrue( MainWP_System_Utility::valid_download_sig( $this->files[3], $backslash_sig ) );
        $this->assertSame( '0/bulk/pkg\item.zip', $value['path'] );

        $result = $this->parse_download(
            array(
                'mwpdl' => rawurlencode( '0/bulk/pkg\item.zip' ),
                'sig'   => $sig,
            )
        );
        $this->assertSame( array( 403 ), $result['status'] );
        $this->assertTrue( $result['exited'] );
        $this->assertSame( 'Download link is invalid or expired.', trim( $result['body'] ) );
        $this->assertSame( array(), Download_Order_Transport::$served );

        $this->assertNotSame( realpath( $this->files[4] ), realpath( $this->files[5] ) );
        $this->assertSame( filesize( $this->files[4] ), filesize( $this->files[5] ) );
        $sig   = MainWP_System_Utility::get_download_sig( $this->files[4] );
        $value = json_decode( rawurldecode( $sig ), true );
        $this->assertSame( 'absolute', $value['root'] );
        $this->assertTrue( MainWP_System_Utility::valid_download_sig( $this->files[4], $sig ) );
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[5], $sig ) );
    }

    /**
     * Payload fields whose modification invalidates the MAC.
     *
     * @return array Tampering cases.
     */
    public static function tampered_fields() {
        return array( array( 'mac', str_repeat( '0', 64 ) ), array( 'path', '0/bulk/b.zip' ), array( 'root', 'private' ), array( 'expires', PHP_INT_MAX ), array( 'version', 2 ) );
    }

    /**
     * Modifying signed fields cannot retain the credential.
     *
     * @dataProvider tampered_fields
     * @param string $field Field changed by a caller.
     * @param mixed  $replacement Altered value.
     */
    public function test_tampering_is_rejected( $field, $replacement ) {
        $value           = json_decode( rawurldecode( MainWP_System_Utility::get_download_sig( $this->files[0] ) ), true );
        $value[ $field ] = $replacement;
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[0], rawurlencode( wp_json_encode( $value ) ) ) );
    }

    /** Correct MACs do not extend an expired credential. */
    public function test_expired_signature_and_missing_file_are_rejected() {
        $value = array(
            'version' => 1,
            'root'    => 'mainwp',
            'path'    => '0/bulk/a file.zip',
            'expires' => time() - 1,
        );
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[0], $this->encode_signature( $value ) ) );
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->directory . '/missing.zip', MainWP_System_Utility::get_download_sig( $this->files[0] ) ) );
    }

    /**
     * Untrusted values, including nested wrong types.
     *
     * @return array Malformed inputs.
     */
    public static function malformed_signatures() {
        return array( array( array() ), array( '' ), array( 'garbage' ), array( 'null' ), array( '{}' ), array( '{"sig":[],"key_value":[],"hash_key":[]}' ), array( '{"sig":"x","key_value":[],"hash_key":"x"}' ), array( '{"version":1,"root":[],"path":[],"expires":[],"mac":[]}' ) );
    }

    /**
     * Reject malformed input without PHP warnings or type errors.
     *
     * @dataProvider malformed_signatures
     * @param mixed $sig Untrusted signature.
     */
    public function test_malformed_signatures_have_no_notices( $sig ) {
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[0], $sig ) );
    }

    /** Old links retain their transient lifetime, bounded by the upgrade drain. */
    public function test_legacy_drain_and_expired_transient() {
        $minted_at = time() - 2 * HOUR_IN_SECONDS;
        $key       = uniqid( 'sig_', true ) . filesize( $this->files[0] ) . $minted_at;
        $secret    = uniqid( 'sig_secret_', true ) . filesize( $this->files[0] ) . $minted_at;
        $sig       = rawurlencode(
            wp_json_encode(
                array(
                    'sig'       => md5( filesize( $this->files[0] ) ),
                    'key_value' => $key,
                    'hash_key'  => $secret,
                )
            )
        );
        set_site_transient( 'mainwp_fdl_' . wp_hash( $key ), $secret, HOUR_IN_SECONDS );
        update_option( 'mainwp_download_sig_upgrade_timestamp', time() - HOUR_IN_SECONDS, false );
        $this->assertTrue( MainWP_System_Utility::valid_download_sig( $this->files[0], $sig ) );
        update_option( 'mainwp_download_sig_upgrade_timestamp', time() - 3 * HOUR_IN_SECONDS, false );
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[0], $sig ) );
        update_option( 'mainwp_download_sig_upgrade_timestamp', time(), false );
        delete_site_transient( 'mainwp_fdl_' . wp_hash( $key ) );
        $this->assertFalse( MainWP_System_Utility::valid_download_sig( $this->files[0], $sig ) );
    }

    /** Opaque URL transport and parse_init's extra decode preserve normal file paths. */
    public function test_download_url_round_trip_and_unauthenticated_serving() {
        $url = MainWP_System_Utility::get_download_url( 'bulk', basename( $this->files[0] ) );
        $this->assertSame( $url, esc_url_raw( $url ) );
        parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $params );
        $dirs = MainWP_System_Utility::get_mainwp_dir();
        $file = trailingslashit( $dirs[0] ) . rawurldecode( $params['mwpdl'] );
        $this->assertTrue( MainWP_System_Utility::valid_download_sig( $file, $params['sig'] ) );
        wp_set_current_user( 0 );
        $result = $this->parse_download( $params );
        $this->assertTrue( $result['exited'] );
        $this->assertSame( array( $file ), Download_Order_Transport::$served );
    }

    /** The URL filter can move the signed link to the front end, where parse_init still serves it. */
    public function test_download_url_filter_moves_link_to_front_end() {
        $this->assertStringStartsWith( admin_url( '?sig=' ), MainWP_System_Utility::get_download_url( 'bulk', basename( $this->files[0] ) ) );
        $front = static function ( $url, $query ) {
            unset( $url );
            return home_url( '/' . $query );
        };
        add_filter( 'mainwp_download_url', $front, 10, 2 );
        $url = MainWP_System_Utility::get_download_url( 'bulk', basename( $this->files[0] ) );
        remove_filter( 'mainwp_download_url', $front, 10 );
        $this->assertStringStartsWith( home_url( '/?sig=' ), $url );
        parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $params );
        $dirs = MainWP_System_Utility::get_mainwp_dir();
        $file = trailingslashit( $dirs[0] ) . rawurldecode( $params['mwpdl'] );
        wp_set_current_user( 0 );
        $result = $this->parse_download( $params );
        $this->assertTrue( $result['exited'] );
        $this->assertSame( array( $file ), Download_Order_Transport::$served );
    }

    /** Wrong request types return before any validation or transport. */
    public function test_parse_init_array_parameters_and_no_download() {
        foreach ( array(
            array(
                'mwpdl' => array( 'a' ),
                'sig'   => 'x',
            ),
            array(
                'mwpdl' => 'a',
                'sig'   => array( 'x' ),
            ),
            array( 'sig' => 'x' ),
        ) as $params ) {
            $result = $this->parse_download( $params );
            $this->assertFalse( $result['exited'] );
            $this->assertSame( '', $result['body'] );
            $this->assertSame( array(), $result['status'] );
            $this->assertSame( array(), Download_Order_Transport::$served );
        }
    }

    /** Invalid, expired, unresolved and traversal requests must end in a 403. */
    public function test_parse_init_refuses_with_403_and_no_serving() {
        $value = array(
            'version' => 1,
            'root'    => 'mainwp',
            'path'    => '0/bulk/a file.zip',
            'expires' => time() - 1,
        );
        foreach ( array(
            array(
                'mwpdl' => '0/bulk/b.zip',
                'sig'   => MainWP_System_Utility::get_download_sig( $this->files[0] ),
            ),
            array(
                'mwpdl' => '0/bulk/a file.zip',
                'sig'   => $this->encode_signature( $value ),
            ),
            array(
                'mwpdl' => 'missing.zip',
                'sig'   => 'bad',
            ),
            array(
                'mwpdl' => '../custom.zip',
                'sig'   => 'bad',
            ),
        ) as $params ) {
            $result = $this->parse_download( $params );
            $this->assertSame( array( 403 ), $result['status'] );
            $this->assertTrue( $result['exited'] );
            $this->assertSame( 'Download link is invalid or expired.', trim( $result['body'] ) );
            $this->assertSame( array(), Download_Order_Transport::$served );
        }
    }

    /**
     * Every simple caller sort in the sweep, plus rejected SQL shapes.
     *
     * @return array Order validation cases.
     */
    public static function order_values() {
        $cases = array();
        foreach ( array( 'wp.url', 'wp.name', 'wp.id ASC', 'wp_sync.dtsSync DESC, wp.url ASC', ' wp_sync.dtsAutomaticSyncStart ASC ', 'name', 'wp.name asc', 'wp.name desc', 'wp.url asc', 'wp.url desc', 'client_name', 'added_timestamp', 'mo.interval', 'wpversion_order_num', 'wp.ip', 'wp_sync.last_post_gmt', 'wp.http_response_code', 'wp_sync.health_value', 'wp_sync.dtsSync', 'wp.securityIssues', 'primary_lasttime_backup', 'wp.offline_checks_last', 'mo.type', 'wp.url aSc, wp.name dEsC' ) as $order ) {
            $cases[ $order ] = array( $order, trim( $order ) );
        }
        foreach ( array( 'wp.url; DROP', '(SELECT 1)', 'wp.url,SLEEP(1)', '`wp`.`url`', 'wp.url -- comment', 'wp.url /* comment */', 'unknown order', 'wp.url,', '', array(), null ) as $index => $order ) {
            $cases[ 'invalid-' . $index ] = array( $order, 'wp.url' );
        }
        return $cases;
    }

    /**
     * Caller orders keep their spelling and directions after trimming.
     *
     * @dataProvider order_values
     * @param mixed  $order Caller value.
     * @param string $expected Accepted sort or fallback.
     */
    public function test_order_validator( $order, $expected ) {
        $this->assertSame( $expected, MainWP_DB::validate_order_by( $order ) );
    }

    /** All six order sinks reject unsupported syntax before rewriting URL sorts. */
    public function test_each_db_method_falls_back_to_its_default() {
        global $wpdb;
        $db = MainWP_DB::instance();
        foreach ( array( 'unknown order', 'wp.url; DROP', array() ) as $order ) {
            foreach ( array( false, true ) as $groups ) {
                $this->assertSame( $db->get_sql_websites_by_user_id( 1, $groups ), $db->get_sql_websites_by_user_id( 1, $groups, null, $order ) );
                $this->assertSame( $db->get_sql_websites_for_current_user( $groups ), $db->get_sql_websites_for_current_user( $groups, null, $order ) );
                $this->assertSame( $db->get_sql_websites_by_group_id( 1, $groups ), $db->get_sql_websites_by_group_id( 1, $groups, $order ) );
                $this->assertSame(
                    $db->get_sql_websites_for_current_user_by_params( array( 'with_tags' => $groups ) ),
                    $db->get_sql_websites_for_current_user_by_params(
                        array(
                            'with_tags' => $groups,
                            'orderby'   => $order,
                        )
                    )
                );
                $this->assertSame(
                    $db->get_sql_wp_for_current_user( array( 'select_groups' => $groups ) ),
                    $db->get_sql_wp_for_current_user(
                        array(
                            'select_groups' => $groups,
                            'order_by'      => $order,
                        )
                    )
                );
            }
            MainWP_DB_Backup::instance()->get_backup_tasks( null, $order );
            $this->assertStringEndsWith( 'ORDER BY name', $wpdb->last_query );
        }
        MainWP_DB_Backup::instance()->get_backup_tasks();
        $this->assertStringNotContainsString( 'ORDER BY', $wpdb->last_query );
    }

    /** Search text stays a literal on the actual wpdb execution path. */
    public function test_search_injection_and_literal_like_wildcards() {
        global $wpdb;
        $db      = MainWP_DB::instance();
        $literal = $this->create_test_site( array( 'name' => 'literal %_ marker' ) );
        $this->create_test_site( array( 'name' => 'ordinary marker' ) );
        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp_group',
            array(
                'wpid'    => $literal,
                'groupid' => 123,
            ),
            array( '%d', '%d' )
        );
        $payload = '") OR 1=1 -- ';
        foreach ( array(
            $db->get_sql_websites_by_user_id( get_current_user_id(), false, $payload ),
            $db->get_sql_websites_for_current_user( false, $payload ),
            $db->get_sql_websites_for_current_user_by_params( array( 's' => $payload ) ),
            $db->get_sql_wp_for_current_user( array( 'search_site' => $payload ) ),
            $db->get_sql_websites_by_group_id( 123, false, 'wp.url', false, false, null, $payload ),
        ) as $sql ) {
            $this->assertSame( array(), $wpdb->get_results( $sql ), $sql );
            $this->assertSame( '', $wpdb->last_error );
        }
        $sites = $db->get_websites_by_user_id( get_current_user_id(), false, '%_' );
        $this->assertSame( array( $literal ), array_map( 'intval', array_keys( $sites ) ) );
    }

    /** Caller LIMIT strings cannot append SQL. */
    public function test_user_id_limits_are_integers() {
        $db = MainWP_DB::instance();
        $this->assertStringEndsWith( ' LIMIT 0, 1', $db->get_sql_websites_by_user_id( 1, false, null, 'wp.url', '0 UNION SELECT', '1; DROP' ) );
        $this->assertStringEndsWith( ' LIMIT 2', $db->get_sql_websites_by_user_id( 1, false, null, 'wp.url', false, '2; DROP' ) );
    }

    /**
     * Sign fixture payloads, including expired credentials.
     *
     * @param array $value Signature fields.
     * @return string Encoded signature.
     */
    private function encode_signature( $value ) {
        $value['mac'] = hash_hmac( 'sha256', wp_json_encode( array( $value['version'], $value['root'], $value['path'], $value['expires'] ) ), MainWP_System_Utility::get_or_create_filename_secret() );
        return rawurlencode( wp_json_encode( $value ) );
    }

    /**
     * Capture status, body and termination without changing request resolution.
     *
     * @param array $params Download request.
     * @return array Observed response.
     */
    private function parse_download( $params ) {
        $_GET     = wp_slash( $params );
        $_REQUEST = $_GET;
        $status   = array();
        $capture  = static function ( $header, $code ) use ( &$status ) {
            $status[] = $code;
            return $header;
        };
        add_filter( 'status_header', $capture, 10, 2 );
        $exited = false;
        ob_start();
        try {
            ( new Download_Order_System() )->parse_init();
        } catch ( \RuntimeException $error ) {
            $exited = true;
        } finally {
            $body = ob_get_clean();
            remove_filter( 'status_header', $capture );
        }
        return array(
            'status' => $status,
            'body'   => $body,
            'exited' => $exited,
        );
    }
}
