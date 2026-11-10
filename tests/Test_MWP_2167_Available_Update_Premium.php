<?php
/**
 * The "Available update" site filter includes premium-only updates.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Sync stores premium updates as the premium_upgrades site option, so both
 * site query builders must read it for status=available_update.
 */
class Test_MWP_2167_Available_Update_Premium extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Fixture site IDs keyed by case.
	 *
	 * @var array
	 */
	private $sites = array();

	/** Seed one site per update state, the way sync leaves them. */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_System_Utility::set_current_wpid( 0 );

		$premium = wp_json_encode(
			array(
				'premium/premium.php' => array(
					'Name'   => 'Premium',
					'type'   => 'plugin',
					'update' => array(
						'new_version' => '2.0',
						'premium'     => true,
						'slug'        => 'premium/premium.php',
					),
				),
			)
		);

		$this->sites['premium'] = $this->create_test_site();
		$this->set_site_option( $this->sites['premium'], 'premium_upgrades', $premium );

		$this->sites['premium_empty'] = $this->create_test_site();
		$this->set_site_option( $this->sites['premium_empty'], 'premium_upgrades', '[]' );

		$this->sites['core'] = $this->create_test_site();
		$this->set_site_option( $this->sites['core'], 'wp_upgrades', wp_json_encode( array( 'current' => '6.0', 'new' => '6.1' ) ) );

		$this->sites['plugin'] = $this->create_test_site( array( 'plugin_upgrades' => wp_json_encode( array( 'a/a.php' => array( 'Name' => 'A' ) ) ) ) );

		$this->sites['none'] = $this->create_test_site();
	}

	/** The params builder used by REST v2 and the abilities. */
	public function test_params_query_includes_premium_only_site() {
		$sql = MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( array( 'status' => 'available_update' ) );
		$this->assert_available_sites( $sql );
	}

	/** The legacy builder accepts the same status parameter. */
	public function test_legacy_query_includes_premium_only_site() {
		$sql = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'favi_icon' ), 'no', array( 'status' => 'available_update' ) );
		$this->assert_available_sites( $sql );
	}

	/**
	 * Run a site query and compare the IDs with the sites that have updates.
	 *
	 * @param string $sql Site query.
	 */
	private function assert_available_sites( $sql ) {
		global $wpdb;

		$this->assertIsString( $sql );
		$ids = array_map( 'intval', wp_list_pluck( $wpdb->get_results( $sql ), 'id' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query built by MainWP_DB.
		sort( $ids );

		$expected = array( $this->sites['premium'], $this->sites['core'], $this->sites['plugin'] );
		sort( $expected );

		$this->assertSame( $expected, $ids );
	}
}
