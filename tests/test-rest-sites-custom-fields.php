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

	/**
	 * Return one site as the list, include and single-site paths show it.
	 *
	 * @param int    $site_id       Site ID.
	 * @param string $custom_fields Value of ?custom_fields.
	 * @return array Site data keyed by path.
	 */
	private function get_site_from_each_path( $site_id, $custom_fields ) {
		$controller = new \MainWP_Rest_Sites_Controller();
		$request    = new \WP_REST_Request( 'GET', '/mainwp/v2/sites/' . $site_id );
		$request->set_param( 'id_domain', (string) $site_id );
		$request->set_param( 'custom_fields', $custom_fields );

		$response = $controller->get_item( $request );
		$this->assertNotWPError( $response );
		$single = $response->get_data();

		return array(
			'list'    => $this->list_sites_with_foo( array( 'custom_fields' => $custom_fields ) )[ $site_id ],
			'include' => $this->list_sites_with_foo(
				array(
					'custom_fields' => $custom_fields,
					'include'       => (string) $site_id,
				)
			)[ $site_id ],
			'single'  => $single['data'],
		);
	}

	/**
	 * Custom field names that match a site column, in any case.
	 *
	 * @return array
	 */
	public function column_named_fields() {
		return array(
			'id'  => array( 'id' ),
			'ID'  => array( 'ID' ),
			'url' => array( 'url' ),
		);
	}

	/**
	 * Each custom field becomes a column alias selected after the site's own
	 * columns, so a name like id or url used to replace the site's value with
	 * the option's, NULL when no such option exists (MWP-2163). The site must
	 * come back exactly as it does for ?custom_fields=foo.
	 *
	 * @dataProvider column_named_fields
	 *
	 * @param string $field Custom field name.
	 * @return void
	 */
	public function test_custom_field_named_like_site_column( $field ) {
		$this->set_current_user_as_admin();
		$site_id = $this->create_site_with_foo();
		$url     = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id )->url;

		$expected = $this->get_site_from_each_path( $site_id, 'foo' );
		$actual   = $this->get_site_from_each_path( $site_id, $field . ',foo' );

		foreach ( $actual as $path => $site ) {
			$this->assertEquals( $site_id, $site['id'], $path );
			$this->assertSame( $url, $site['url'], $path );
			$this->assertSame( 'bar', $site['foo'], $path );
			$this->assertSame( $expected[ $path ], $site, $path );
		}
	}

	/**
	 * premium_upgrades is a site column that nothing writes; the sync keeps the
	 * live value in the site option of that name, so ?custom_fields must still
	 * read the option.
	 *
	 * @return void
	 */
	public function test_premium_upgrades_custom_field_returns_option_value() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_site_with_foo();
		$value   = wp_json_encode( array( 'premium-plugin/premium-plugin.php' => array( 'Name' => 'Premium Plugin' ) ) );
		$this->set_site_option( $site_id, 'premium_upgrades', $value );

		foreach ( $this->get_site_from_each_path( $site_id, 'premium_upgrades,foo' ) as $path => $site ) {
			$this->assertSame( $value, $site['premium_upgrades'], $path );
			$this->assertSame( 'bar', $site['foo'], $path );
		}
	}
}
