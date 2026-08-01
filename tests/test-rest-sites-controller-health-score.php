<?php
/**
 * Tests for REST v2 sites controller health_score reporting.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Rest_Sites_Controller_Health_Score_Test
 *
 * Pins that the v2 sites controller reports the same "Good" / "Should be improved"
 * label REST v1 and WP-CLI derive from the stored Site Health issue counts, rather
 * than the raw mainwp_wp_sync.health_value column. health_value is a sortable
 * composite (score - critical * 100) that goes negative when a site has critical
 * issues, so exposing it as health_score leaked an internal sort key.
 *
 * Reuses MainWP_Abilities_Test_Case only for its generic MainWP-site helpers
 * (create_test_site / set_site_option / set_current_user_as_admin), which the
 * global test bootstrap loads for every suite.
 */
class MainWP_Rest_Sites_Controller_Health_Score_Test extends MainWP_Abilities_Test_Case {

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
	 * Prepare a site through the v2 controller and return its health_score field.
	 *
	 * @param array|string|null $issue_counts        Site Health issue counts to store (array is JSON-encoded,
	 *                                                a string is stored raw to simulate a corrupted value), or null for none.
	 * @param int|null          $stored_health_value Raw health_value composite to store on the sync row.
	 * @return mixed The health_score value from the prepared response, or null if absent.
	 */
	private function prepare_health_score( $issue_counts, $stored_health_value = null ) {
		global $wpdb;

		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		if ( null !== $issue_counts ) {
			$stored = is_string( $issue_counts ) ? $issue_counts : wp_json_encode( $issue_counts );
			$this->set_site_option( $site_id, 'health_site_status', $stored );
		}
		if ( null !== $stored_health_value ) {
			// Seed the raw composite column so a passing assertion proves the label is
			// computed from the issue counts, not read back from health_value.
			$wpdb->update(
				$wpdb->prefix . 'mainwp_wp_sync',
				array( 'health_value' => $stored_health_value ),
				array( 'wpid' => $site_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		$site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );

		$controller = new \MainWP_Rest_Sites_Controller();
		$request    = new \WP_REST_Request( 'GET', '/mainwp/v2/sites/' . $site_id );
		$request->set_param( '_fields', 'health_score' );

		$data = $controller->prepare_item_for_response( $site, $request );

		return is_array( $data ) && array_key_exists( 'health_score', $data ) ? $data['health_score'] : null;
	}

	/**
	 * A site with a critical issue reports the "improve" label, not the raw
	 * negative health_value composite.
	 *
	 * @return void
	 */
	public function test_health_score_reports_label_not_raw_composite_for_critical_site() {
		// good=10, recommended=2, critical=1 -> real score 81, but health_value composite = -19.
		// The critical issue forces "Should be improved" even though the score clears 80.
		$score = $this->prepare_health_score(
			array(
				'good'        => 10,
				'recommended' => 2,
				'critical'    => 1,
			),
			-19
		);

		$this->assertSame( 'Should be improved', $score );
	}

	/**
	 * A healthy site (score >= 80, no critical issues) reports "Good".
	 *
	 * @return void
	 */
	public function test_health_score_reports_good_for_healthy_site() {
		$score = $this->prepare_health_score(
			array(
				'good'        => 20,
				'recommended' => 0,
				'critical'    => 0,
			),
			100
		);

		$this->assertSame( 'Good', $score );
	}

	/**
	 * A corrupted, non-JSON health_site_status value must not fatal and should
	 * fall back to "Good" (empty health data).
	 *
	 * @return void
	 */
	public function test_health_score_handles_malformed_scalar_value() {
		$score = $this->prepare_health_score( 'not-valid-json' );

		$this->assertSame( 'Good', $score );
	}

	/**
	 * A site with no stored health data reports "Good".
	 *
	 * @return void
	 */
	public function test_health_score_defaults_to_good_when_absent() {
		$score = $this->prepare_health_score( null );

		$this->assertSame( 'Good', $score );
	}

	/**
	 * MainWP_Utility::get_site_health() must tolerate a non-array (e.g. a decoded
	 * JSON scalar) without a TypeError and treat it as empty health data.
	 *
	 * @return void
	 */
	public function test_get_site_health_tolerates_non_array_input() {
		$result = \MainWP\Dashboard\MainWP_Utility::get_site_health( 'foo' );

		$this->assertEquals( 100, $result['val'] );
		$this->assertSame( 0, $result['critical'] );
	}

	/**
	 * MainWP_Utility::get_site_health() must not warn on a partial array missing
	 * some of the expected keys.
	 *
	 * @return void
	 */
	public function test_get_site_health_tolerates_partial_array() {
		$result = \MainWP\Dashboard\MainWP_Utility::get_site_health( array( 'good' => 5 ) );

		$this->assertEquals( 100, $result['val'] );
		$this->assertSame( 0, $result['critical'] );
	}

	/**
	 * MainWP_Utility::get_site_health() must normalize non-numeric and negative
	 * counter values instead of fataling on the arithmetic, and return the
	 * normalized (non-negative int) critical count.
	 *
	 * @return void
	 */
	public function test_get_site_health_normalizes_non_numeric_and_negative_counts() {
		$result = \MainWP\Dashboard\MainWP_Utility::get_site_health(
			array(
				'good'        => '10',
				'recommended' => 'foo',
				'critical'    => -3,
			)
		);

		$this->assertIsInt( $result['critical'] );
		$this->assertSame( 0, $result['critical'], 'A negative critical count must normalize to 0.' );
		// recommended 'foo' -> 0, critical -3 -> 0: no failed tests, so the score is 100.
		$this->assertEquals( 100, $result['val'] );
	}

	/**
	 * A nested-array counter must normalize to 0, not 1. intval() maps a
	 * non-empty array to 1, which would count a malformed value as a real
	 * critical issue and flip the label.
	 *
	 * @return void
	 */
	public function test_get_site_health_rejects_non_scalar_counts() {
		$result = \MainWP\Dashboard\MainWP_Utility::get_site_health(
			array(
				'good'        => 10,
				'recommended' => 0,
				'critical'    => array( 'value' => 1 ),
			)
		);

		$this->assertSame( 0, $result['critical'], 'A non-scalar critical count must normalize to 0, not intval() to 1.' );
		$this->assertEquals( 100, $result['val'] );
	}

	/**
	 * Fetch one test site through get_websites_for_current_user() using the same
	 * projection the v2 read endpoints pass.
	 *
	 * @param array $issue_counts Site Health issue counts to store.
	 * @return object The mapped site row object.
	 */
	private function fetch_projected_site( array $issue_counts ) {
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$this->set_site_option( $site_id, 'health_site_status', wp_json_encode( $issue_counts ) );
		// full_data list rows json_decode wp_upgrades; seed an empty value so the test
		// does not trip an unrelated json_decode(null) deprecation on a fresh site.
		$this->set_site_option( $site_id, 'wp_upgrades', '' );

		// Mirror the read-path params: extra_view fetches the SQL column, fields
		// keeps it through map_site().
		$params = array(
			'full_data'    => true,
			'selectgroups' => true,
			'include'      => array( $site_id ),
			'extra_view'   => array( 'health_site_status' ),
			'fields'       => array( 'health_site_status' ),
		);

		$websites = \MainWP\Dashboard\MainWP_DB::instance()->get_websites_for_current_user( $params );

		return $websites ? current( $websites ) : null;
	}

	/**
	 * The v2 read-path projection must keep health_site_status on the mapped row so
	 * the get_website_option() object fast-path hits and no per-site option query runs.
	 *
	 * @return void
	 */
	public function test_list_query_projects_health_site_status_onto_row() {
		$site = $this->fetch_projected_site(
			array(
				'good'        => 20,
				'recommended' => 0,
				'critical'    => 0,
			)
		);

		$this->assertNotNull( $site );
		// Before the fix, map_site() dropped health_site_status (it was only in
		// extra_view, not fields), so prepare_item_for_response() fell back to a
		// per-site option query on every list row. The property being present is
		// what lets the get_website_option() object fast-path avoid that N+1.
		$this->assertTrue(
			property_exists( $site, 'health_site_status' ),
			'health_site_status must survive map_site() so the object fast-path avoids the N+1 option query.'
		);
		$this->assertNotEmpty( $site->health_site_status, 'The projected column must carry the stored value.' );

		// The object fast-path returns the stored value.
		$health = \MainWP\Dashboard\MainWP_DB::instance()->get_website_option( $site, 'health_site_status', array(), true );
		$this->assertSame( 20, (int) $health['good'] );
	}

	/**
	 * The projected health_site_status column must never leak into the prepared
	 * response, and health_score must still be reported.
	 *
	 * @return void
	 */
	public function test_prepared_response_does_not_leak_health_site_status() {
		$site = $this->fetch_projected_site(
			array(
				'good'        => 20,
				'recommended' => 0,
				'critical'    => 0,
			)
		);

		$this->assertNotNull( $site );

		$controller = new \MainWP_Rest_Sites_Controller();
		// No _fields: exercise the full schema projection so any leak would surface.
		$request = new \WP_REST_Request( 'GET', '/mainwp/v2/sites' );

		$data = $controller->prepare_item_for_response( $site, $request );

		$this->assertIsArray( $data );
		$this->assertArrayNotHasKey( 'health_site_status', $data, 'The projected column must not leak into the response.' );
		$this->assertArrayHasKey( 'health_score', $data );
		$this->assertSame( 'Good', $data['health_score'] );
	}

	/**
	 * ?custom_fields=health_site_status must not surface the reserved internal
	 * column. Projecting it onto the row for the fast-path makes property_exists()
	 * true, so the custom_fields bypass could otherwise expose it; the
	 * never-in-response reservation must strip it on every read path, including
	 * when _fields is set (which skips the schema filter but not the strip).
	 *
	 * @return void
	 */
	public function test_custom_fields_cannot_surface_reserved_health_site_status() {
		$site = $this->fetch_projected_site(
			array(
				'good'        => 20,
				'recommended' => 0,
				'critical'    => 0,
			)
		);

		$this->assertNotNull( $site );
		// The projection really put the value on the object, so this exercises the
		// actual bypass rather than a trivially-absent field.
		$this->assertTrue( property_exists( $site, 'health_site_status' ) );

		$controller = new \MainWP_Rest_Sites_Controller();

		// prepare_site_item_for_response_context() is the wrapper every read path
		// uses; it runs strip_never_in_response_fields() last. It is protected.
		$method = new \ReflectionMethod( $controller, 'prepare_site_item_for_response_context' );
		$method->setAccessible( true );

		foreach ( array( null, 'id,health_score' ) as $fields ) {
			$request = new \WP_REST_Request( 'GET', '/mainwp/v2/sites' );
			$request->set_param( 'custom_fields', 'health_site_status' );
			if ( null !== $fields ) {
				$request->set_param( '_fields', $fields );
			}

			$data = $method->invoke( $controller, $site, $request, 'view', array( 'health_site_status' ) );

			$this->assertIsArray( $data );
			$this->assertArrayNotHasKey(
				'health_site_status',
				$data,
				'custom_fields must not surface the reserved internal column (_fields=' . var_export( $fields, true ) . ').'
			);
		}
	}
}
