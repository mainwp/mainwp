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

		$errors = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Assert that upgrade counting emits no PHP diagnostics.
			static function ( $severity, $message ) use ( &$errors ) {
				$errors[] = $message;
				return true;
			}
		);
		ob_start();
		try {
			$websites = MainWP_DB::instance()->get_websites_for_current_user(
				array(
					'full_data' => true,
					'format'    => 'array',
				)
			);
		} finally {
			$output = ob_get_clean();
			restore_error_handler();
		}

		$this->assertSame( array(), $errors, 'SQL NULL upgrade values must not emit PHP diagnostics.' );
		$this->assertSame( '', $output, 'full_data must not emit deprecation output for a site whose upgrade values are NULL.' );

		$found = null;
		foreach ( (array) $websites as $website ) {
			if ( (int) $website->id === $site_id ) {
				$found = $website;
				break;
			}
		}

		$this->assertNotNull( $found, 'The test site must be returned by get_websites_for_current_user().' );
		$this->assertNull( $found->wp_upgrades, 'The missing option must map to SQL NULL.' );
		$this->assertSame( 0, $found->sum_of_upgrades, 'Missing upgrade values must sum to zero upgrades.' );
	}

	/**
	 * Arrays copied by map_site() are ignored; JSON strings still count.
	 *
	 * @return void
	 */
	public function test_full_data_ignores_array_upgrade_values_and_counts_json_without_output() {
		$this->set_current_user_as_admin();
		$payload = wp_json_encode( array( 'first', 'second' ) );
		$site_id = $this->create_test_site(
			array(
				'plugin_upgrades' => $payload,
				'theme_upgrades'  => $payload,
			)
		);
		$this->set_site_option( $site_id, 'wp_upgrades', $payload );

		$array_db = new class() extends MainWP_DB {
			/**
			 * Supply non-string properties for the real map_site() call.
			 *
			 * @param mixed $result Query result.
			 * @return object|false|null Site row or end of results.
			 */
			public static function fetch_object( $result ) {
				$website = parent::fetch_object( $result );
				if ( is_object( $website ) ) {
					$website->plugin_upgrades = array( 'first', 'second' );
					$website->theme_upgrades  = array( 'first', 'second' );
					$website->wp_upgrades     = array( 'first', 'second' );
				}
				return $website;
			}
		};

		foreach ( array( $array_db, MainWP_DB::instance() ) as $index => $database ) {
			$errors = array();
			set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Assert that upgrade counting emits no PHP diagnostics.
				static function ( $severity, $message ) use ( &$errors ) {
					$errors[] = $message;
					return true;
				}
			);
			ob_start();
			try {
				$websites = $database->get_websites_for_current_user(
					array(
						'full_data' => true,
						'format'    => 'array',
					)
				);
			} finally {
				$output = ob_get_clean();
				restore_error_handler();
			}

			$this->assertSame( array(), $errors, 'Upgrade values must not emit PHP diagnostics.' );
			$this->assertSame( '', $output, 'Upgrade values must not emit output.' );
			$found = null;
			foreach ( $websites as $website ) {
				if ( (int) $website->id === $site_id ) {
					$found = $website;
					break;
				}
			}
			$this->assertNotNull( $found );
			foreach ( array( 'plugin_upgrades', 'theme_upgrades', 'wp_upgrades' ) as $field ) {
				$this->assertSame( 0 === $index ? array( 'first', 'second' ) : $payload, $found->$field );
			}
			$this->assertSame( 0 === $index ? 0 : 6, $found->sum_of_upgrades );
		}
	}

	/**
	 * Connection counts include every unrestricted fixture site.
	 *
	 * @return void
	 */
	public function test_sites_connections_status_counts_unrestricted_sites() {
		$this->set_current_user_as_admin();
		$site_ids = array(
			$this->create_test_site(),
			$this->create_test_site(),
			$this->create_test_site( array( 'sync_errors' => 'Connection failed' ) ),
		);

		$status = MainWP_DB::instance()->get_sites_connections_status();

		$this->assertIsArray( $status );
		$this->assertSame( count( $site_ids ), (int) $status['total_sites'] );
		$this->assertSame( 2, (int) $status['connected_sites'] );
		$this->assertSame( 1, (int) $status['disconnected_sites'] );
		$this->assertSame( (int) $status['total_sites'], (int) $status['connected_sites'] + (int) $status['disconnected_sites'] );
	}

	/**
	 * Connection counts honor the current user's allowed-site subset.
	 *
	 * @return void
	 */
	public function test_sites_connections_status_counts_only_allowed_sites() {
		$this->skip_when_capabilities_bypassed();
		$this->set_current_user_as_admin();
		$allowed_ids = array(
			$this->create_test_site(),
			$this->create_test_site( array( 'sync_errors' => 'Connection failed' ) ),
		);
		$this->create_test_site();
		$allowed = static function () use ( $allowed_ids ) {
			return $allowed_ids;
		};

		add_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		try {
			$status = MainWP_DB::instance()->get_sites_connections_status();

			$this->assertIsArray( $status );
			$this->assertSame( count( $allowed_ids ), (int) $status['total_sites'] );
			$this->assertSame( 1, (int) $status['connected_sites'] );
			$this->assertSame( 1, (int) $status['disconnected_sites'] );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		}
	}
}
