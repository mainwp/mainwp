<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit's file loader matches the class name.
/**
 * Regression coverage for legacy REST v1 plugin/theme deletion permissions.
 *
 * Run without WordPress:
 * vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Test_REST_V1_Management_Permissions.php
 *
 * Probes execute the real v1 authentication and callbacks with isolated storage,
 * request and transport doubles. WordPress HTTP routing is not exercised.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use PHPUnit\Framework\TestCase;

/** Verify destructive actions require Delete as well as the existing POST rights. */
class Test_REST_V1_Management_Permissions extends TestCase {

    /**
     * Execute a fresh process so REST bypass constants cannot leak between cases.
     *
     * @param array $input Probe inputs.
     * @return array
     */
    private function probe( array $input ): array {
        $process = proc_open(
            array( PHP_BINARY, __DIR__ . '/fixtures/rest-v1-management-permissions.inc', json_encode( $input, JSON_THROW_ON_ERROR ) ),
            array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
            $pipes
        );
        $this->assertIsResource( $process );
        fclose( $pipes[0] );
        $output = stream_get_contents( $pipes[1] );
        $error  = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $this->assertSame( 0, proc_close( $process ), $error );
        $this->assertSame( '', $error );
        return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
    }

    /**
     * Both credential formats must enforce the same rights for every management action.
     *
     * @return array
     */
    public static function management_permissions(): array {
        $cases = array();
        foreach ( array( 'plugin', 'theme' ) as $type ) {
            foreach ( array( 'query', 'header' ) as $credentials ) {
                foreach ( array( '', 'r', 'w', 'r,w', 'd', 'r,d', 'w,d', 'r,w,d', null ) as $permissions ) {
                    foreach ( array( 'activate', 'deactivate', 'delete' ) as $action ) {
                        $effective = null === $permissions ? 'r,w,d' : $permissions;
                        $allowed   = false !== strpos( $effective, 'w' ) && ( 'delete' !== $action || false !== strpos( $effective, 'd' ) );
                        $label     = $type . '/' . $credentials . '/' . ( null === $permissions ? 'legacy' : $permissions ) . '/' . $action;
                        $cases[ $label ] = array( compact( 'type', 'credentials', 'permissions', 'action' ), $allowed );
                    }
                }
            }
        }
        return $cases;
    }

    /**
     * @dataProvider management_permissions
     * @param array $input   Probe inputs.
     * @param bool  $allowed Whether the operation should be forwarded.
     */
    public function test_management_actions_enforce_key_permissions( array $input, bool $allowed ): void {
        $result = $this->probe( $input );
        $this->assertSame( 'POST', $result['registered_method'] );
        $this->assertSame( $allowed ? 200 : 401, $result['status'] );
        $this->assertSame( $allowed, $result['rest_doing'] );
        $this->assertSame( $allowed ? 1 : 0, $result['db_reads'] );
        if ( $allowed ) {
            $this->assertNull( $result['error_code'] );
            $this->assertSame(
                array( array( $input['type'] . '_action', array( 'action' => $input['action'], $input['type'] => 'fixture-item' ) ) ),
                $result['calls']
            );
        } else {
            $this->assertSame( 'rest_method_not_allowed', $result['error_code'] );
            $this->assertSame( array(), $result['calls'] );
        }
    }

    /** WordPress matches REST routes without regard to case; normalize their edges too. */
    public function test_route_variants_cannot_bypass_delete_permission(): void {
        foreach ( array( 'plugin', 'theme' ) as $type ) {
            $route = '/mainwp/v1/site/site-manage-' . $type;
            foreach ( array( strtoupper( $route ), $route . '/', ltrim( $route, '/' ) ) as $variant ) {
                $result = $this->probe( array( 'type' => $type, 'route' => $variant, 'permissions' => 'w' ) );
                $this->assertSame( 401, $result['status'], $variant );
                $this->assertSame( 0, $result['db_reads'], $variant );
                $this->assertSame( array(), $result['calls'], $variant );
            }
        }
    }

    /**
     * Requests whose final URL-encoded newline survives route decoding.
     *
     * @return array
     */
    public static function newline_routes(): array {
        $cases = array();
        foreach ( array( 'plugin', 'theme' ) as $type ) {
            $canonical = '/mainwp/v1/site/site-manage-' . $type;
            foreach ( array( $canonical, strtoupper( $canonical ) ) as $path ) {
                $route = rawurldecode( $path . '%0A' );
                foreach ( array( 'query', 'header' ) as $credentials ) {
                    foreach ( array( 'w', 'r,w', 'w,d' ) as $permissions ) {
                        $label           = $path . '/' . $credentials . '/' . $permissions;
                        $cases[ $label ] = array( compact( 'type', 'route', 'credentials', 'permissions' ), 'w,d' === $permissions );
                    }
                }
            }
        }
        return $cases;
    }

    /**
     * PCRE accepts a final newline before WordPress's route end anchor.
     *
     * @dataProvider newline_routes
     * @param array $input   Probe inputs.
     * @param bool  $allowed Whether Delete permission was granted.
     */
    public function test_newline_routes_enforce_delete_permission( array $input, bool $allowed ): void {
        $result = $this->probe( $input );
        $this->assertTrue( $result['matches_registered_route'] );
        $this->assertSame( $allowed ? 200 : 401, $result['status'] );
        $this->assertSame( $allowed, $result['rest_doing'] );
        $this->assertSame( $allowed ? 1 : 0, $result['db_reads'] );
        if ( $allowed ) {
            $this->assertSame(
                array( array( $input['type'] . '_action', array( 'action' => 'delete', $input['type'] => 'fixture-item' ) ) ),
                $result['calls']
            );
        } else {
            $this->assertSame( 'rest_method_not_allowed', $result['error_code'] );
            $this->assertSame( array(), $result['calls'] );
        }
    }

    /** Failed authentication still stops before site lookup or transport. */
    public function test_invalid_credentials_and_disabled_keys_are_rejected(): void {
        foreach ( array( 'plugin', 'theme' ) as $type ) {
            foreach ( array( 'disabled', 'wrong_key', 'wrong_secret', 'wrong_key_hash' ) as $failure ) {
                $result = $this->probe( array( 'type' => $type, 'permissions' => 'w,d', 'failure' => $failure ) );
                $this->assertSame( 401, $result['status'], $failure );
                $this->assertFalse( $result['rest_doing'], $failure );
                $this->assertSame( 0, $result['db_reads'], $failure );
                $this->assertSame( array(), $result['calls'], $failure );
            }
        }
    }

    /** Existing DELETE-method routes retain their original permission model. */
    public function test_delete_method_permissions_are_preserved(): void {
        foreach ( array( 'w' => 401, 'd' => 200, 'w,d' => 200 ) as $permissions => $status ) {
            $result = $this->probe( array( 'permissions' => $permissions, 'method' => 'DELETE', 'auth_only' => true, 'route' => '/mainwp/v1/site/remove-site' ) );
            $this->assertSame( $status, $result['status'] );
            $this->assertSame( array(), $result['calls'] );
        }
    }

    /** An action parameter on another route must not acquire a new Delete requirement. */
    public function test_other_post_routes_keep_write_permission(): void {
        $result = $this->probe( array( 'permissions' => 'w', 'route' => '/mainwp/v1/site/disconnect-site', 'auth_only' => true ) );
        $this->assertSame( 200, $result['status'] );
        $this->assertSame( array(), $result['calls'] );
    }

    /** Unsupported management actions still fail input validation without transport. */
    public function test_invalid_actions_are_rejected(): void {
        foreach ( array( 'plugin', 'theme' ) as $type ) {
            $result = $this->probe( array( 'type' => $type, 'permissions' => 'w', 'action' => 'unsupported' ) );
            $this->assertSame( 400, $result['status'] );
            $this->assertSame( 0, $result['db_reads'] );
            $this->assertSame( array(), $result['calls'] );
        }
    }
}
