<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit's file loader matches the class name.
/**
 * REST v1 unignore callbacks must reach the handlers with every required argument.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Read actual storage without scoped lookups or caches.

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\Rest_Api_V1;

/** Both v1 unignore endpoints clear the stored ignore instead of throwing ArgumentCountError. */
class Test_MWP_2160_REST_V1_Unignore extends \WP_UnitTestCase {

    use Site_Fixture;

    const SLUG = 'fixture/plugin.php';

    /**
     * Fixture site ID.
     *
     * @var int
     */
    private $site;

    /** Seed the same plugin ignore globally and on one site. */
    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $this->site = $this->create_test_site();

        $ignored = wp_json_encode(
            array(
                self::SLUG => array(
                    'Name'             => 'Fixture',
                    'ignored_versions' => array( '1.0' ),
                ),
                'keep'     => 'Keep',
            )
        );

        $extension                    = (array) MainWP_DB_Common::instance()->get_user_extension();
        $extension['ignored_plugins'] = $ignored;
        MainWP_DB_Common::instance()->update_user_extension( $extension );
        MainWP_DB::instance()->update_website_values( $this->site, array( 'ignored_plugins' => $ignored ) );
    }

    /**
     * Key validation has its own coverage; skipping it here also keeps the
     * process-wide MAINWP_REST_API_DOING constant out of later tests.
     *
     * @return Rest_Api_V1
     */
    private function controller(): Rest_Api_V1 {
        return new class() extends Rest_Api_V1 {
            /**
             * Accept every request.
             *
             * @param mixed $request Request.
             * @return bool
             */
            public function mainwp_validate_request( $request ) {
                return true;
            }
        };
    }

    /**
     * Build a v1 request carrying the given body params.
     *
     * @param array $params Params.
     * @return \WP_REST_Request
     */
    private function request( array $params ): \WP_REST_Request {
        $request = new \WP_REST_Request( 'POST' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return $request;
    }

    /**
     * Assert the v1 success response.
     *
     * @param mixed $response Callback response.
     */
    private function assert_success( $response ): void {
        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array( 'SUCCESS' => 'Process ran.' ), $response->get_data() );
    }

    /** POST updates/unignore-updates clears the global ignore for every version. */
    public function test_unignore_updates_clears_global_ignore(): void {
        global $wpdb;

        $response = $this->controller()->mainwp_rest_api_unignore_updates_callback(
            $this->request(
                array(
                    'type' => 'plugin',
                    'slug' => self::SLUG,
                )
            )
        );

        $this->assert_success( $response );
        $stored = $wpdb->get_var( "SELECT ignored_plugins FROM {$wpdb->prefix}mainwp_users WHERE userid = 0" );
        $this->assertSame( array( 'keep' => 'Keep' ), json_decode( $stored, true ) );
    }

    /** POST updates/unignore-update clears the site's ignore for every version. */
    public function test_unignore_update_clears_site_ignore(): void {
        global $wpdb;

        $response = $this->controller()->mainwp_rest_api_unignore_update_callback(
            $this->request(
                array(
                    'type'    => 'plugin',
                    'slug'    => self::SLUG,
                    'site_id' => (string) $this->site,
                )
            )
        );

        $this->assert_success( $response );
        $stored = $wpdb->get_var( $wpdb->prepare( "SELECT ignored_plugins FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $this->site ) );
        $this->assertSame( array( 'keep' => 'Keep' ), json_decode( $stored, true ) );
    }
}
