<?php
/**
 * MainWP get_websites_for_current_user() full_data Tests
 *
 * The full_data branch of MainWP_DB::get_websites_for_current_user() sums
 * upgrade counts by json_decode()-ing the plugin_upgrades, theme_upgrades,
 * and wp_upgrades values of every returned site. plugin/theme_upgrades are
 * mainwp_wp columns, but wp_upgrades is selected from mainwp_wp_options via
 * subquery and comes back NULL for any site that has never reported a core
 * upgrade check. The '' !== guards let NULL through into json_decode(),
 * which is a deprecation on PHP 8.1+ and printed output under the test
 * harness.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;

/**
 * Class Test_Get_Websites_Full_Data
 */
class Test_Get_Websites_Full_Data extends MainWP_Abilities_Test_Case {

	/**
	 * A site with no wp_upgrades option row (the never-synced shape) must
	 * flow through the full_data upgrade summing without emitting any
	 * deprecation output, and its missing values must count as zero
	 * upgrades rather than poisoning the sum.
	 *
	 * @return void
	 */
	public function test_full_data_handles_missing_upgrade_values_without_output() {
		global $wpdb;

		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();

		// Precondition: the fixture creates no wp_upgrades option row, so the
		// full_data subquery selects NULL for it - the production shape of a
		// site that never completed a core-upgrade sync.
		$option_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid = %d AND name = 'wp_upgrades'", $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- precondition read of the test fixture.
		$this->assertSame( 0, $option_rows, 'Precondition: the test site must have no wp_upgrades option row.' );

		ob_start();
		$websites = MainWP_DB::instance()->get_websites_for_current_user(
			array(
				'full_data' => true,
				'format'    => 'array',
			)
		);
		$output   = ob_get_clean();

		$this->assertSame( '', $output, 'full_data must not emit deprecation output for a site whose upgrade values are NULL.' );

		$found = null;
		foreach ( (array) $websites as $website ) {
			if ( (int) $website->id === $site_id ) {
				$found = $website;
				break;
			}
		}

		$this->assertNotNull( $found, 'The test site must be returned by get_websites_for_current_user().' );
		$this->assertSame( 0, $found->sum_of_upgrades, 'Missing upgrade values must sum to zero upgrades.' );
	}
}
