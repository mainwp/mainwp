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
}
