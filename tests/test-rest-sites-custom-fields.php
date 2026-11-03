<?php
/**
 * Regression tests for ?custom_fields on the REST v2 sites list.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Rest_Sites_Custom_Fields_Test
 *
 * get_items() skips the list ability when include or exclude is set and builds
 * the query itself. That branch joined the requested options into the query but
 * left them out of `fields`, and map_site() keeps only `fields`, so each custom
 * field came back as an empty string (MWP-2162).
 *
 * Reuses MainWP_Abilities_Test_Case only for its MainWP-site helpers, as
 * test-db-option-name-injection.php does.
 */
class MainWP_Rest_Sites_Custom_Fields_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Load the v2 REST controller classes, which are only included during REST
	 * setup rather than by the test bootstrap.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( '\MainWP_Rest_Sites_Controller' ) ) {
			include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-controller.php';
			include_once MAINWP_PLUGIN_DIR . 'includes/rest-api/controller/version2/class-mainwp-rest-sites-controller.php';
		}
	}

	/**
	 * Create a site carrying option foo=bar.
	 *
	 * @return int Site ID.
	 */
	private function create_site_with_foo() {
		$site_id = $this->create_test_site();
		$this->set_site_option( $site_id, 'foo', 'bar' );
		return $site_id;
	}

	/**
	 * Run GET /mainwp/v2/sites?custom_fields=foo with extra params and return the
	 * listed sites keyed by ID.
	 *
	 * @param array $params Extra request params.
	 * @return array
	 */
	private function list_sites_with_foo( array $params ) {
		$controller = new \MainWP_Rest_Sites_Controller();
		$request    = new \WP_REST_Request( 'GET', '/mainwp/v2/sites' );
		$request->set_param( 'custom_fields', 'foo' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = $controller->get_items( $request );
		$this->assertNotWPError( $response );

		$data  = $response->get_data();
		$sites = array();
		foreach ( $data['data'] as $site ) {
			$sites[ (int) $site['id'] ] = $site;
		}
		return $sites;
	}

	/**
	 * Without include or exclude the list returns the stored option value.
	 *
	 * @return void
	 */
	public function test_custom_field_value_without_include() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_site_with_foo();

		$sites = $this->list_sites_with_foo( array() );

		$this->assertArrayHasKey( $site_id, $sites );
		$this->assertSame( 'bar', $sites[ $site_id ]['foo'] );
	}

	/**
	 * With include the list returns the stored option value, not an empty string.
	 *
	 * @return void
	 */
	public function test_custom_field_value_with_include() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_site_with_foo();

		$sites = $this->list_sites_with_foo( array( 'include' => (string) $site_id ) );

		$this->assertSame( array( $site_id ), array_keys( $sites ) );
		$this->assertSame( 'bar', $sites[ $site_id ]['foo'] );
	}

	/**
	 * With exclude the remaining sites carry the stored option value.
	 *
	 * @return void
	 */
	public function test_custom_field_value_with_exclude() {
		$this->set_current_user_as_admin();
		$site_id  = $this->create_site_with_foo();
		$other_id = $this->create_site_with_foo();

		$sites = $this->list_sites_with_foo( array( 'exclude' => (string) $other_id ) );

		$this->assertArrayHasKey( $site_id, $sites );
		$this->assertArrayNotHasKey( $other_id, $sites );
		$this->assertSame( 'bar', $sites[ $site_id ]['foo'] );
	}
}
