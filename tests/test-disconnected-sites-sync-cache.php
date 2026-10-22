<?php
/**
 * Sites cache invalidation after connection status writes.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Cache_Helper;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Manage_Screenshots;
use MainWP\Dashboard\MainWP_Manage_Sites_List_Table;
use MainWP\Dashboard\MainWP_Monitoring_Sites_List_Table;
use MainWP\Dashboard\MainWP_Sync;

/**
 * Exercises connection status changes through the list tables and DB caches.
 *
 * @group disconnected-sites-cache
 */
class Test_Disconnected_Sites_Sync_Cache extends MainWP_Abilities_Test_Case {

	/**
	 * Original request globals.
	 *
	 * @var array
	 */
	private $request_globals;

	/**
	 * Prepare an administrator and independent cache prefixes.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->request_globals = array( $_GET, $_POST, $_REQUEST );
		$_GET                  = array();
		$_POST                 = array();
		$_REQUEST              = array();
		$this->set_current_user_as_admin();
		MainWP_Manage_Sites_List_Table::invalidate_manage_sites_cache();
	}

	/**
	 * Restore request globals before fixture cleanup.
	 */
	public function tearDown(): void {
		list( $_GET, $_POST, $_REQUEST ) = $this->request_globals;
		parent::tearDown();
	}

	/**
	 * Replace cached disconnected rows after reconnects and new failures.
	 */
	public function test_populated_disconnected_cache_tracks_both_flip_directions(): void {
		$a = $this->site( 'A', 'Connection failed' );
		$b = $this->site( 'B' );
		$c = $this->site( 'C' );
		$this->assert_sites( array( $a ), 1 );
		$this->assert_sites( array( $a ), 1 );

		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $a, array( 'sync_errors' => '' ) ) );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $b, array( 'sync_errors' => 'New failure B' ) ) );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $c, array( 'sync_errors' => 'New failure C' ) ) );

		$this->assert_sites( array( $b, $c ), 2 );
		$this->assert_sites( array( $b, $c ), 2 );
	}

	/**
	 * Refresh a cached empty list when a site disconnects.
	 */
	public function test_empty_disconnected_cache_does_not_hide_new_failure(): void {
		$site = $this->site( 'A' );
		$this->assert_sites( array(), 0 );
		$this->assert_sites( array(), 0 );
		$cached = $this->site_transients();
		$this->assertContains( serialize( array() ), array_values( $cached ), 'The empty sites_ids transient must be warm.' );

		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $site, array( 'sync_errors' => 'New failure' ) ) );
		$this->assert_sites( array( $site ), 1 );
		$this->assert_sites( array( $site ), 1 );
	}

	/**
	 * Refresh both warm pages and their shared total after status flips.
	 */
	public function test_cached_pages_one_and_two_and_total_refresh_together(): void {
		$sites = array();
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $name ) {
			$sites[] = $this->site( $name, 'Connection failed' );
		}
		$f = $this->site( 'F' );
		$this->assert_sites(
			array( $sites[0], $sites[1] ),
			5,
			array(
				'length' => 2,
				'start'  => 0,
			)
		);
		$this->assert_sites(
			array( $sites[2], $sites[3] ),
			5,
			array(
				'length' => 2,
				'start'  => 2,
			)
		);
		$this->assert_sites(
			array( $sites[0], $sites[1] ),
			5,
			array(
				'length' => 2,
				'start'  => 0,
			)
		);
		$this->assert_sites(
			array( $sites[2], $sites[3] ),
			5,
			array(
				'length' => 2,
				'start'  => 2,
			)
		);

		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $sites[0], array( 'sync_errors' => '' ) ) );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $sites[2], array( 'sync_errors' => '' ) ) );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $f, array( 'sync_errors' => 'New failure' ) ) );

		for ( $request = 0; $request < 2; ++$request ) {
			$this->assert_sites(
				array( $sites[1], $sites[3] ),
				4,
				array(
					'length' => 2,
					'start'  => 0,
				)
			);
			$this->assert_sites(
				array( $sites[4], $f ),
				4,
				array(
					'length' => 2,
					'start'  => 2,
				)
			);
		}
	}

	/**
	 * Refresh the Connected filter after each transition direction.
	 */
	public function test_connected_cache_tracks_reconnects_and_new_failures(): void {
		$a      = $this->site( 'A', 'Connection failed' );
		$b      = $this->site( 'B' );
		$filter = array( 'status' => 'connected' );
		$this->assert_sites( array( $b ), 1, $filter );
		$this->assert_sites( array( $b ), 1, $filter );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $a, array( 'sync_errors' => '' ) ) );
		$this->assert_sites( array( $a, $b ), 2, $filter );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $b, array( 'sync_errors' => 'New failure' ) ) );
		$this->assert_sites( array( $a ), 1, $filter );
		$this->assert_sites( array( $a ), 1, $filter );
	}

	/**
	 * Keep unrelated sites outside the selected scope after invalidation.
	 *
	 * @dataProvider scoped_filters
	 *
	 * @param string $scope Group or client filter.
	 */
	public function test_group_and_client_filters_survive_invalidation( $scope ): void {
		$client = $this->create_test_client();
		$group  = $this->create_test_tag();
		$a      = $this->site( 'A', 'Connection failed', array( 'client_id' => $client ) );
		$b      = $this->site( 'B', '', array( 'client_id' => $client ) );
		$this->site( 'Outside scope', 'Connection failed' );
		$this->assign_site_to_tag( $a, $group );
		$this->assign_site_to_tag( $b, $group );
		$filter = 'group' === $scope ? array( 'g' => (string) $group ) : array( 'client' => (string) $client );
		$this->assert_sites( array( $a ), 1, $filter );
		$this->assert_sites( array( $a ), 1, $filter );

		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $a, array( 'sync_errors' => '' ) ) );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $b, array( 'sync_errors' => 'New failure' ) ) );
		$this->assert_sites( array( $b ), 1, $filter );
		$this->assert_sites( array( $b ), 1, $filter );
	}

	/**
	 * Provide the supported scope filters.
	 *
	 * @return array
	 */
	public static function scoped_filters(): array {
		return array(
			'group'  => array( 'group' ),
			'client' => array( 'client' ),
		);
	}

	/**
	 * Keep both prefixes when a status write leaves connectivity unchanged.
	 *
	 * @dataProvider steady_statuses
	 *
	 * @param string $before Stored error.
	 * @param string $after Replacement error.
	 * @param int    $affected Expected affected rows.
	 */
	public function test_no_flip_preserves_both_cache_prefixes( $before, $after, $affected ): void {
		$site     = $this->site( 'A', $before );
		$expected = '' === $before ? array() : array( $site );
		$this->assert_sites( $expected, count( $expected ) );
		$prefixes                 = $this->prefixes();
		list( $result, $queries ) = $this->write_queries( $site, array( 'sync_errors' => $after ) );

		$this->assertSame( $affected, $result );
		$this->assertSame( $prefixes, $this->prefixes(), 'A steady status must preserve CGR_SITES and CGR_UPDATES.' );
		$this->assert_status_lookup( $site, $queries );
		$this->assert_sites( $expected, count( $expected ) );
	}

	/**
	 * Provide unchanged connection states, including changed error text.
	 *
	 * @return array
	 */
	public static function steady_statuses(): array {
		return array(
			'connected unchanged' => array( '', '', 0 ),
			'error unchanged'     => array( 'Error', 'Error', 0 ),
			'error text changed'  => array( 'Error', 'Other error', 1 ),
			'zero is an error'    => array( '0', 'Other error', 1 ),
		);
	}

	/**
	 * Invalidate both prefixes after a persisted connection status flip.
	 *
	 * @dataProvider flipped_statuses
	 *
	 * @param string $before Stored error.
	 * @param string $after Replacement error.
	 */
	public function test_flip_changes_both_cache_prefixes_with_one_status_lookup( $before, $after ): void {
		$site     = $this->site( 'A', $before );
		$expected = '' === $before ? array() : array( $site );
		$this->assert_sites( $expected, count( $expected ) );
		$prefixes                 = $this->prefixes();
		list( $result, $queries ) = $this->write_queries( $site, array( 'sync_errors' => $after ) );

		$this->assertSame( 1, $result );
		foreach ( $this->prefixes() as $group => $prefix ) {
			$this->assertNotSame( $prefixes[ $group ], $prefix, $group . ' must be invalidated on a status flip.' );
		}
		$this->assert_status_lookup( $site, $queries );
		$expected = '' === $after ? array() : array( $site );
		$this->assert_sites( $expected, count( $expected ) );
	}

	/**
	 * Provide both transition directions, including a nonempty zero string.
	 *
	 * @return array
	 */
	public static function flipped_statuses(): array {
		return array(
			'new failure' => array( '', 'Error' ),
			'reconnect'   => array( 'Error', '' ),
			'zero error'  => array( '', '0' ),
			'clear zero'  => array( '0', '' ),
		);
	}

	/**
	 * Keep unrelated sync writes free of status reads and invalidation.
	 */
	public function test_write_without_sync_errors_has_no_select_or_invalidation(): void {
		$site = $this->site( 'A', 'Connection failed' );
		$this->assert_sites( array( $site ), 1 );
		$prefixes                 = $this->prefixes();
		list( $result, $queries ) = $this->write_queries( $site, array( 'version' => '6.1.2' ) );

		$this->assertSame( 1, $result );
		$this->assertCount( 0, preg_grep( '/^\s*SELECT\b/i', $queries ), 'A write without sync_errors must not issue a SELECT.' );
		$this->assertCount( 1, preg_grep( '/^\s*UPDATE\b/i', $queries ) );
		$this->assertSame( $prefixes, $this->prefixes() );
		$this->assert_sites( array( $site ), 1 );
	}

	/**
	 * Keep the stored status and both prefixes when the UPDATE fails.
	 *
	 * @dataProvider flipped_statuses
	 *
	 * @param string $before Stored error.
	 * @param string $after Attempted replacement error.
	 */
	public function test_failed_update_returns_false_and_preserves_both_prefixes( $before, $after ): void {
		global $wpdb;
		$site     = $this->site( 'A', $before );
		$expected = '' === $before ? array() : array( $site );
		$this->assert_sites( $expected, count( $expected ) );
		$prefixes = $this->prefixes();
		$failures = 0;
		$table    = $wpdb->prefix . 'mainwp_wp_sync';
		$fail     = static function ( $query ) use ( $table, &$failures ) {
			if ( preg_match( '/^UPDATE\s+`' . preg_quote( $table, '/' ) . '`\s/i', $query ) ) {
				++$failures;
				return 'UPDATE `' . $table . '` SET `mwp_1826_nonexistent_column` = 1';
			}
			return $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$result = MainWP_DB::instance()->update_website_sync_values( $site, array( 'sync_errors' => $after ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}

		$this->assertSame( 1, $failures, 'The intended UPDATE must reach the failure filter.' );
		$this->assertFalse( $result );
		$this->assertSame( $before, $this->stored_status( $site ) );
		$this->assertSame( $prefixes, $this->prefixes() );
		$this->assert_sites( $expected, count( $expected ) );
	}

	/**
	 * Distinguish a missing sync row from a connected site.
	 */
	public function test_missing_sync_row_returns_zero_without_invalidating(): void {
		global $wpdb;
		$site = $this->site( 'A' );
		$this->assertSame( 1, $wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site ) ) );
		$this->assert_sites( array(), 0 );
		$prefixes = $this->prefixes();
		foreach ( array( 'Error', '' ) as $status ) {
			list( $result, $queries ) = $this->write_queries( $site, array( 'sync_errors' => $status ) );
			$this->assertSame( 0, $result );
			$this->assert_status_lookup( $site, $queries );
			$this->assertNull( $this->stored_status( $site ) );
			$this->assertSame( $prefixes, $this->prefixes(), 'A missing row is distinct from a connected row.' );
		}
		$this->assert_sites( array(), 0 );
	}

	/**
	 * Persist a failed reconnect sync result without changing connectivity.
	 */
	public function test_failed_reconnect_sync_result_keeps_disconnected_site(): void {
		$site = $this->site( 'A', 'Original failure' );
		$this->assert_sites( array( $site ), 1 );
		$prefixes    = $this->prefixes();
		$website     = MainWP_DB::instance()->get_website_by_id( $site );
		$information = array( 'error' => 'Reconnect failed' );
		MainWP_Sync::sync_information_array( $website, $information );

		$this->assertStringContainsString( 'Reconnect failed', $this->stored_status( $site ) );
		$this->assertSame( $prefixes, $this->prefixes() );
		$this->assert_sites( array( $site ), 1 );
	}

	/**
	 * Refresh screenshots through its own cached prepare_items path.
	 */
	public function test_screenshots_disconnected_cache_refreshes_after_status_flips(): void {
		$a = $this->site( 'A', 'Connection failed', array( 'url' => 'https://test-a.example.com/' ) );
		$b = $this->site( 'B', '', array( 'url' => 'https://test-b.example.com/' ) );
		$this->assertSame( array( $a ), $this->screenshots() );
		$this->assertSame( array( $a ), $this->screenshots() );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $a, array( 'sync_errors' => '' ) ) );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $b, array( 'sync_errors' => 'New failure' ) ) );
		$this->assertSame( array( $b ), $this->screenshots() );
		$this->assertSame( array( $b ), $this->screenshots() );
	}

	/**
	 * Keep monitoring online rows correct after connection status invalidation.
	 */
	public function test_monitoring_online_filter_survives_status_cache_invalidation(): void {
		$a = $this->site( 'A', 'Connection failed' );
		$this->site( 'Offline', '', array( 'offline_check_result' => -1 ) );
		$this->assert_sites( array( $a ), 1, array( 'status' => 'online' ), true );
		$this->assert_sites( array( $a ), 1, array( 'status' => 'online' ), true );
		$this->assertSame( 1, MainWP_DB::instance()->update_website_sync_values( $a, array( 'sync_errors' => '' ) ) );
		$this->assert_sites( array( $a ), 1, array( 'status' => 'online' ), true );
	}

	/**
	 * Create a site with an explicit connection status.
	 *
	 * @param string $name Site name.
	 * @param string $status Stored error.
	 * @param array  $args Additional site fields.
	 * @return int
	 */
	private function site( $name, $status = '', $args = array() ): int {
		$site = $this->create_test_site(
			array_merge(
				$args,
				array(
					'name'        => $name,
					'sync_errors' => $status,
				)
			)
		);
		$this->assertGreaterThan( 0, $site );
		return $site;
	}

	/**
	 * Read both group prefixes for the current user.
	 *
	 * @return array
	 */
	private function prefixes(): array {
		$prefixes = array();
		foreach ( array( MainWP_Cache_Helper::CGR_SITES, MainWP_Cache_Helper::CGR_UPDATES ) as $group ) {
			$prefixes[ $group ] = MainWP_Cache_Helper::get_cache_prefix( $group );
		}
		return $prefixes;
	}

	/**
	 * Read the persisted error, retaining null for a missing row.
	 *
	 * @param int $site Site ID.
	 * @return string|null
	 */
	private function stored_status( $site ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT sync_errors FROM %i WHERE wpid = %d', $wpdb->prefix . 'mainwp_wp_sync', $site ) );
		return null === $row ? null : $row->sync_errors;
	}

	/**
	 * Observe the real wpdb queries during a sync write.
	 *
	 * @param int   $site Site ID.
	 * @param array $fields Sync fields.
	 * @return array
	 */
	private function write_queries( $site, $fields ): array {
		$queries = array();
		$observe = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $observe );
		try {
			$result = MainWP_DB::instance()->update_website_sync_values( $site, $fields );
		} finally {
			remove_filter( 'query', $observe );
		}
		return array( $result, $queries );
	}

	/**
	 * Require one prepared lookup of the sync row by wpid.
	 *
	 * @param int   $site Site ID.
	 * @param array $queries Observed queries.
	 */
	private function assert_status_lookup( $site, $queries ): void {
		global $wpdb;
		$table   = preg_quote( $wpdb->prefix . 'mainwp_wp_sync', '/' );
		$selects = array_values( preg_grep( '/^\s*SELECT\b.*\bFROM\s+`?' . $table . '`?\b/i', $queries ) );
		$this->assertSame(
			array( $wpdb->prepare( 'SELECT sync_errors FROM %i WHERE wpid = %d', $wpdb->prefix . 'mainwp_wp_sync', $site ) ),
			$selects,
			'A status write must issue exactly one indexed status lookup.'
		);
	}

	/**
	 * Read DB transients under the current Sites prefix.
	 *
	 * @return array
	 */
	private function site_transients(): array {
		global $wpdb;
		$prefix = MainWP_Cache_Helper::get_cache_prefix( MainWP_Cache_Helper::CGR_SITES );
		$rows   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( '_transient_' . $prefix ) . '%' ),
			OBJECT_K
		);
		$values = array();
		foreach ( $rows as $name => $row ) {
			$values[ $name ] = $row->option_value;
		}
		return $values;
	}

	/**
	 * Clear request-local objects while retaining the DB transients.
	 *
	 * @param array $request List request parameters.
	 */
	private function next_request( $request ): void {
		$before = $this->site_transients();
		wp_cache_flush();
		$this->assertSame( $before, $this->site_transients(), 'Simulated requests must preserve Sites transients.' );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array_merge(
			array(
				'status'  => 'disconnected',
				'length'  => 25,
				'start'   => 0,
				'columns' => array( array( 'data' => 'site' ) ),
				'order'   => array(
					array(
						'column' => 0,
						'dir'    => 'asc',
					),
				),
			),
			$request
		);
	}

	/**
	 * Read IDs from the real DB result, allowing MainWP's empty-result sentinel.
	 *
	 * @param mixed $result MainWP DB query result.
	 * @return array
	 */
	private function result_ids( $result ): array {
		global $wpdb;
		// MainWP_DB::query() returns false for a successful query with no rows.
		if ( false === $result ) {
			$this->assertSame( 0, $wpdb->dbh->errno, 'An empty result must not hide a SQL error.' );
			return array();
		}
		$this->assertTrue( MainWP_DB::is_result( $result ), 'prepare_items() must return a real DB result.' );
		$ids = array();
		if ( MainWP_DB::num_rows( $result ) > 0 ) {
			MainWP_DB::data_seek( $result, 0 );
			while ( $row = MainWP_DB::fetch_object( $result ) ) {
				$ids[] = (int) $row->id;
			}
		}
		MainWP_DB::free_result( $result );
		return $ids;
	}

	/**
	 * Check rows, totals and persistent caches through prepare_items.
	 *
	 * @param array $expected Expected site IDs in order.
	 * @param int   $total Expected total across pages.
	 * @param array $request List request parameters.
	 * @param bool  $monitoring Whether to use the monitoring list.
	 */
	private function assert_sites( $expected, $total, $request = array(), $monitoring = false ): void {
		$this->next_request( $request );
		$table = $monitoring ? new MainWP_Monitoring_Sites_List_Table() : new MainWP_Manage_Sites_List_Table();
		try {
			$table->prepare_items();
			$this->assertSame( $expected, $this->result_ids( $table->items ), 'Rows for ' . wp_json_encode( $_REQUEST ) );
			$this->assertSame( $total, (int) $table->total_items, 'Total for ' . wp_json_encode( $_REQUEST ) );
			$keys = array_keys( $this->site_transients() );
			$this->assertNotEmpty( preg_grep( '/sites_ids$/', $keys ), 'prepare_items() must populate the IDs transient.' );
			$this->assertNotEmpty( preg_grep( '/total_sites$/', $keys ), 'prepare_items() must populate the total transient.' );
		} finally {
			remove_action( 'mainwp_managesites_tabletop', array( $table, 'generate_tabletop' ) );
		}
	}

	/**
	 * Read screenshots after a simulated request boundary.
	 *
	 * @phpstan-impure
	 * @return array
	 */
	private function screenshots(): array {
		$this->next_request( array() );
		return $this->result_ids( MainWP_Manage_Screenshots::prepare_items() );
	}
}
