<?php
/**
 * MainWP Backup Slot Lease Tests
 *
 * A native backup holder refreshes a lease on its concurrency slot row
 * before every child request, so a driver that dies mid-run frees its
 * slot minutes later instead of holding it for two hours. The refresh
 * rides the transport's own timeout filters, which is also where the
 * request gets a bound worth leasing against, so these tests drive the
 * filters and - once - the transport itself.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Slot_Lease_Test
 */
class MainWP_Backup_Slot_Lease_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Tokens whose lease scope this test opened.
	 *
	 * @var string[]
	 */
	private $leased_tokens = array();

	/**
	 * Start every test with an empty semaphore and no lease scope.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->leased_tokens = array();
		$this->purge_slot_rows();
		delete_option( 'mainwp_backup_max_concurrent' );
		delete_option( 'mainwp_enableNativeBackups' );
	}

	/**
	 * Close any lease scope the test left open, so neither the scope stack
	 * nor the filters it installs leak into the next test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->leased_tokens as $token ) {
			MainWP_Backup_Monitor::end_native_lease( $token );
		}
		$this->leased_tokens = array();
		$this->purge_slot_rows();
		delete_option( 'mainwp_backup_max_concurrent' );
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tearDown();
	}

	/**
	 * Remove every DB-semaphore slot row.
	 *
	 * @return void
	 */
	private function purge_slot_rows() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup of the DB semaphore.
	}

	/**
	 * The decoded slot rows, keyed by option name.
	 *
	 * @return array<string, array> Decoded slot values by option name.
	 */
	private function slot_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct read of the DB semaphore.
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' )
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->option_name ] = (array) json_decode( (string) $row->option_value, true );
		}
		return $out;
	}

	/**
	 * The decoded row a token owns.
	 *
	 * @param string $token Slot token.
	 *
	 * @return array Decoded row, empty when the token owns none.
	 */
	private function row_for_token( string $token ): array {
		foreach ( $this->slot_rows() as $row ) {
			if ( ( $row['token'] ?? '' ) === $token ) {
				return $row;
			}
		}
		return array();
	}

	/**
	 * Push a token's stored heartbeat into the past, so a later refresh
	 * shows up as a heartbeat that moved rather than one the claim wrote.
	 *
	 * @param string $token   Slot token.
	 * @param int    $seconds How far back to set the heartbeat.
	 *
	 * @return int|null The heartbeat written, null when the token owns no row.
	 */
	private function age_slot_heartbeat( string $token, int $seconds ) {
		global $wpdb;

		foreach ( $this->slot_rows() as $name => $row ) {
			if ( ( $row['token'] ?? '' ) !== $token ) {
				continue;
			}
			$row['heartbeat'] = time() - $seconds;
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture for the DB semaphore.
				$wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", (string) wp_json_encode( $row ), $name )
			);
			return (int) $row['heartbeat'];
		}

		return null;
	}

	/**
	 * Acquire a native slot and open its lease scope, the way the
	 * scheduled holder does.
	 *
	 * @param int $max_concurrent Slot limit.
	 *
	 * @return string Slot token.
	 */
	private function acquire_leased_slot( int $max_concurrent = 1 ): string {
		$token = MainWP_Backup_Monitor::acquire_backup_slot( $max_concurrent, 'native' );
		$this->assertIsString( $token, 'Precondition: a slot must be free.' );
		MainWP_Backup_Monitor::begin_native_lease( $token );
		$this->leased_tokens[] = $token;
		return $token;
	}

	/**
	 * Callback test. Under a lease, a child round trip is bounded to the
	 * native request timeout and the value that reaches cURL becomes the
	 * lease, plus its margin. A request that is already shorter passes
	 * through and the lease falls back to its floor - the holder's own
	 * work between requests needs that much regardless.
	 *
	 * @return void
	 */
	public function test_request_filter_bounds_the_call_and_stamps_the_lease() {
		$token   = $this->acquire_leased_slot();
		$called  = time();
		$bounded = apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null );

		$this->assertSame( MainWP_Backup_Monitor::NATIVE_REQUEST_TIMEOUT_SECONDS, $bounded, 'The transport backstop must be clamped to the native bound.' );

		$row = $this->row_for_token( $token );
		$this->assertSame( MainWP_Backup_Monitor::NATIVE_REQUEST_TIMEOUT_SECONDS + MainWP_Backup_Monitor::SLOT_LEASE_MARGIN_SECONDS, (int) ( $row['lease'] ?? 0 ) );
		$this->assertGreaterThanOrEqual( $called, (int) ( $row['heartbeat'] ?? 0 ) );
		$this->assertSame( 'native', $row['lane'] ?? '' );

		$short = apply_filters( 'mainwp_fetch_url_site_timeout', 120, null );
		$this->assertSame( 120, $short, 'A shorter bound already in force wins.' );
		$this->assertSame( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS, (int) ( $this->row_for_token( $token )['lease'] ?? 0 ), 'The lease never drops below its floor.' );
	}

	/**
	 * Callback test. An artifact transfer attempt is bounded the same way,
	 * and its lease covers the transfer bound only - connect time is inside
	 * that bound, so raising connect_timeout buys no lease.
	 *
	 * @return void
	 */
	public function test_transfer_filter_bounds_the_attempt_and_stamps_the_lease() {
		$token    = $this->acquire_leased_slot();
		$timeouts = apply_filters(
			'mainwp_download_to_file_timeouts',
			array(
				'connect_timeout' => 30,
				'timeout'         => 7200,
				'low_speed_limit' => 1024,
				'low_speed_time'  => 60,
			)
		);

		$leased = MainWP_Backup_Monitor::NATIVE_TRANSFER_TIMEOUT_SECONDS + MainWP_Backup_Monitor::SLOT_LEASE_MARGIN_SECONDS;
		$this->assertSame( MainWP_Backup_Monitor::NATIVE_TRANSFER_TIMEOUT_SECONDS, (int) $timeouts['timeout'] );
		$this->assertSame( 30, (int) $timeouts['connect_timeout'], 'Only the transfer bound is clamped.' );
		$this->assertSame( $leased, (int) ( $this->row_for_token( $token )['lease'] ?? 0 ) );

		$raised = apply_filters(
			'mainwp_download_to_file_timeouts',
			array(
				'connect_timeout' => 7200,
				'timeout'         => 7200,
			)
		);
		$this->assertSame( MainWP_Backup_Monitor::NATIVE_TRANSFER_TIMEOUT_SECONDS, (int) $raised['timeout'] );
		$this->assertSame( $leased, (int) ( $this->row_for_token( $token )['lease'] ?? 0 ), 'A raised connect_timeout must not inflate the lease.' );
	}

	/**
	 * Callback test. A timeout of 0 or less is libcurl's "no timeout at
	 * all", so it resolves to the native bound instead of passing through
	 * as an unlimited request under a lease that expires minutes later.
	 *
	 * @return void
	 */
	public function test_non_positive_timeouts_resolve_to_the_native_bound() {
		$token = $this->acquire_leased_slot();

		foreach ( array( 0, -1 ) as $unbounded ) {
			$this->assertSame(
				MainWP_Backup_Monitor::NATIVE_REQUEST_TIMEOUT_SECONDS,
				apply_filters( 'mainwp_fetch_url_site_timeout', $unbounded, null ),
				'An unbounded request must be given the native bound.'
			);
			$this->assertSame(
				MainWP_Backup_Monitor::NATIVE_REQUEST_TIMEOUT_SECONDS + MainWP_Backup_Monitor::SLOT_LEASE_MARGIN_SECONDS,
				(int) ( $this->row_for_token( $token )['lease'] ?? 0 )
			);
		}

		$timeouts = apply_filters(
			'mainwp_download_to_file_timeouts',
			array(
				'connect_timeout' => 30,
				'timeout'         => 0,
			)
		);
		$this->assertSame( MainWP_Backup_Monitor::NATIVE_TRANSFER_TIMEOUT_SECONDS, (int) $timeouts['timeout'] );
	}

	/**
	 * A request never outlives the coverage its slot row actually carries:
	 * a touch that lands buys the full bound, and a holder whose row is
	 * gone - it lost its slot, which another process may already be running
	 * on - fails closed rather than being handed a shorter request.
	 *
	 * @return void
	 */
	public function test_a_request_is_bounded_by_the_coverage_its_row_has() {
		$token = $this->acquire_leased_slot();

		$this->assertSame(
			MainWP_Backup_Monitor::NATIVE_REQUEST_TIMEOUT_SECONDS,
			apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null ),
			'A touch that lands covers the full bound.'
		);
		$this->assertNotSame( array(), $this->row_for_token( $token ), 'Precondition: the touch wrote the holder\'s row.' );

		$this->purge_slot_rows();

		try {
			apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null );
			$this->fail( 'A request with no coverage left must not be given a timeout.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SLOT_LOST, $e->get_message_error_code() );
		}

		try {
			apply_filters(
				'mainwp_download_to_file_timeouts',
				array(
					'connect_timeout' => 30,
					'timeout'         => 7200,
				)
			);
			$this->fail( 'A transfer with no coverage left must not be given a timeout either.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SLOT_LOST, $e->get_message_error_code() );
		}
	}

	/**
	 * Callback test. The scope, not token possession, is what arms the
	 * filters: a native slot held without an open scope is untouched, a
	 * legacy holder in the same process is never clamped, and closing the
	 * last scope removes both callbacks.
	 *
	 * @return void
	 */
	public function test_filters_are_absent_outside_a_native_lease_scope() {
		$held = MainWP_Backup_Monitor::acquire_backup_slot( 2, 'native' );
		$this->assertIsString( $held );
		$before = $this->row_for_token( $held );

		$untouched = array(
			'connect_timeout' => 30,
			'timeout'         => 7200,
		);
		$this->assertSame( 20 * HOUR_IN_SECONDS, apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null ), 'A slot without a scope arms nothing.' );
		$this->assertSame( $untouched, apply_filters( 'mainwp_download_to_file_timeouts', $untouched ) );
		$this->assertSame( $before, $this->row_for_token( $held ), 'No scope, no heartbeat.' );

		$legacy = MainWP_Backup_Monitor::acquire_backup_slot( 2, 'legacy' );
		$this->assertIsString( $legacy );
		$this->assertArrayNotHasKey( 'heartbeat', $this->row_for_token( $legacy ), 'A legacy row carries no lease.' );
		$this->assertSame( 20 * HOUR_IN_SECONDS, apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null ) );
		$this->assertSame( $untouched, apply_filters( 'mainwp_download_to_file_timeouts', $untouched ), 'A legacy holder\'s transfer is never clamped.' );

		MainWP_Backup_Monitor::release_backup_slot( $held );
		MainWP_Backup_Monitor::release_backup_slot( $legacy );

		$token = $this->acquire_leased_slot();
		$this->assertNotFalse( has_filter( 'mainwp_fetch_url_site_timeout' ), 'The scope installs the request callback.' );
		$this->assertNotFalse( has_filter( 'mainwp_download_to_file_timeouts' ), 'The scope installs the transfer callback.' );

		MainWP_Backup_Monitor::end_native_lease( $token );
		$this->assertFalse( has_filter( 'mainwp_fetch_url_site_timeout' ), 'Closing the last scope removes the request callback.' );
		$this->assertFalse( has_filter( 'mainwp_download_to_file_timeouts' ), 'Closing the last scope removes the transfer callback.' );
	}

	/**
	 * Callback test. Two holders in scope both get their rows refreshed by
	 * one request; ending one stops its refreshes and leaves the other
	 * running; ending the last removes the callbacks.
	 *
	 * @return void
	 */
	public function test_every_token_in_scope_is_refreshed() {
		$first  = $this->acquire_leased_slot( 2 );
		$second = $this->acquire_leased_slot( 2 );

		apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null );
		$leased = MainWP_Backup_Monitor::NATIVE_REQUEST_TIMEOUT_SECONDS + MainWP_Backup_Monitor::SLOT_LEASE_MARGIN_SECONDS;
		$this->assertSame( $leased, (int) ( $this->row_for_token( $first )['lease'] ?? 0 ) );
		$this->assertSame( $leased, (int) ( $this->row_for_token( $second )['lease'] ?? 0 ) );

		MainWP_Backup_Monitor::end_native_lease( $first );
		apply_filters( 'mainwp_fetch_url_site_timeout', 300, null );
		$this->assertSame( $leased, (int) ( $this->row_for_token( $first )['lease'] ?? 0 ), 'A holder out of scope is no longer refreshed.' );
		$this->assertSame( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS, (int) ( $this->row_for_token( $second )['lease'] ?? 0 ) );

		MainWP_Backup_Monitor::end_native_lease( $second );
		$this->assertFalse( has_filter( 'mainwp_fetch_url_site_timeout' ) );
	}

	/**
	 * A holder whose row was reclaimed and re-owned while it was away
	 * refreshes nothing: the compare-and-update misses the new value, so
	 * the zombie's next request fails closed instead of running on under
	 * the new owner's slot, the new holder's lease is untouched, and the
	 * zombie's release still frees nothing.
	 *
	 * @return void
	 */
	public function test_zombie_holder_cannot_refresh_or_free_the_new_owner() {
		global $wpdb;

		$token = $this->acquire_leased_slot();
		$name  = MainWP_Backup_Monitor::SLOT_OPTION_PREFIX . '1';

		// Another process reclaimed the row and claimed the slot.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture for the DB semaphore.
		$replacement = (string) wp_json_encode(
			array(
				'token'     => 'new-owner',
				'started'   => time() - 5,
				'pid'       => 2,
				'lane'      => 'native',
				'heartbeat' => time() - 5,
				'lease'     => 1234,
			)
		);
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $replacement ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture for the DB semaphore.

		try {
			apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null );
			$this->fail( 'A zombie holder must not be handed a timeout to keep working under.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SLOT_LOST, $e->get_message_error_code() );
		}

		$rows = $this->slot_rows();
		$this->assertSame( 'new-owner', $rows[ $name ]['token'] ?? '' );
		$this->assertSame( 1234, (int) ( $rows[ $name ]['lease'] ?? 0 ), 'The zombie must not refresh the new owner\'s lease.' );

		MainWP_Backup_Monitor::release_backup_slot( $token );
		$this->assertSame( 'new-owner', $this->slot_rows()[ $name ]['token'] ?? '', 'The zombie release must not free the new holder\'s slot.' );
	}

	/**
	 * The property the callback tests cannot show: the transport itself
	 * resolves the seam once per request and consumes what it returns, so
	 * a real round trip - here against a closed local port, so it fails
	 * without leaving the machine - leaves the lease stamped.
	 *
	 * @return void
	 */
	public function test_a_real_child_request_stamps_the_lease() {
		$site_id = $this->create_test_site( array( 'url' => 'http://127.0.0.1:9/' ) );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertNotEmpty( $website, 'Precondition: the site row must exist.' );

		$token = $this->acquire_leased_slot();

		try {
			MainWP_Connect::fetch_url_site( $website, 'http://127.0.0.1:9/', array() );
		} catch ( \Throwable $e ) {
			unset( $e ); // The connection is refused; the seam fires before the attempt.
		}

		$this->assertSame(
			MainWP_Backup_Monitor::NATIVE_REQUEST_TIMEOUT_SECONDS + MainWP_Backup_Monitor::SLOT_LEASE_MARGIN_SECONDS,
			(int) ( $this->row_for_token( $token )['lease'] ?? 0 ),
			'The transport must resolve the timeout filter, which is what refreshes the lease.'
		);
	}

	/**
	 * The scheduled holder runs inside the scope it opened: mid-run its
	 * row is a native, heartbeating one and both callbacks are armed, and
	 * the scope and the slot are both gone when the run returns.
	 *
	 * @return void
	 */
	public function test_scheduled_holder_runs_under_a_native_lease() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$task = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Slot lease native lane task', 'manual', 'full', '', '', '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		$seen  = null;
		$armed = null;
		add_action(
			'mainwp_backup_diag_run_close',
			function () use ( &$seen, &$armed ) {
				$seen  = $this->slot_rows();
				$armed = has_filter( 'mainwp_fetch_url_site_timeout' );
			}
		);

		$started = time();
		MainWP_Manage_Backups_Handler::execute_backup_task( $task );

		$this->assertIsArray( $seen, 'Precondition: the run must be observed while it still holds its slot.' );
		$row = array_values( $seen )[0] ?? array();
		$this->assertSame( 'native', $row['lane'] ?? '', 'The scheduled lane holds a native slot.' );
		$this->assertGreaterThanOrEqual( $started, (int) ( $row['heartbeat'] ?? 0 ) );
		$this->assertNotFalse( $armed, 'The run happens inside the lease scope.' );

		$this->assertSame( array(), $this->slot_rows(), 'The slot is released when the run returns.' );
		$this->assertFalse( has_filter( 'mainwp_fetch_url_site_timeout' ), 'The scope closes with the run.' );
	}

	/**
	 * The site loop ticks the lease before it drives a site, so the work
	 * between two child requests - which carries no request timeout to
	 * lease against - runs on a row that was just refreshed and under the
	 * cron lanes' no-limit policy rather than a request's own bound.
	 *
	 * @return void
	 */
	public function test_the_site_loop_ticks_the_lease_before_each_site() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site(
			array(
				'userid' => $user,
				'url'    => 'http://127.0.0.1:9/',
			)
		);
		$task = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Slot lease site loop task', 'manual', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		// Age the heartbeat the claim wrote, so a heartbeat back at "now"
		// inside the loop can only have come from the loop's own tick.
		$aged = null;
		add_action(
			'mainwp_backup_diag_slot',
			function ( $event, $token ) use ( &$aged ) {
				if ( 'acquired' !== $event || null !== $aged ) {
					return;
				}
				$aged = $this->age_slot_heartbeat( (string) $token, 500 );
			},
			10,
			4
		);

		$seen  = null;
		$limit = null;
		add_action(
			'mainwp_backup_diag_site_mark',
			function () use ( &$seen, &$limit ) {
				if ( null === $seen ) {
					$seen  = array_values( $this->slot_rows() )[0] ?? array();
					$limit = ini_get( 'max_execution_time' );
				}
			},
			10,
			4
		);

		$started = time();
		// On CLI the limit is already 0, so a bound has to be in force for
		// the loop re-asserting the policy to be worth observing.
		set_time_limit( 900 );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task );
		set_time_limit( 0 );

		$this->assertNotNull( $aged, 'Precondition: the run must claim a slot.' );
		$this->assertIsArray( $seen, 'Precondition: the run must reach the site loop.' );
		$this->assertNotEmpty( $seen, 'Precondition: the holder must still own its row inside the loop.' );
		$this->assertGreaterThanOrEqual( $started, (int) ( $seen['heartbeat'] ?? 0 ), 'The loop refreshes the heartbeat before the site is driven.' );
		$this->assertGreaterThanOrEqual( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS, (int) ( $seen['lease'] ?? 0 ) );
		$this->assertSame( '0', $limit, 'The loop re-asserts the cron lanes\' no-limit policy before the site is driven.' );
	}

	/**
	 * Run Now stays on the legacy lane: its row carries no lease and its
	 * one long round trip is never clamped by the native callbacks.
	 *
	 * @return void
	 */
	public function test_run_now_holder_stays_on_the_legacy_lane() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Slot lease legacy lane task', 'manual', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		$seen  = null;
		$armed = null;
		add_action(
			'mainwp_backup_diag_slot',
			function ( $event ) use ( &$seen, &$armed ) {
				if ( 'acquired' !== $event || null !== $seen ) {
					return;
				}
				$seen  = $this->slot_rows();
				$armed = has_filter( 'mainwp_fetch_url_site_timeout' );
			},
			10,
			4
		);

		try {
			// Native backups are left disabled, so the executor refuses
			// before any child contact; the slot is held either way.
			MainWP_Manage_Backups_Handler::backup( $task->id, $site_id, 'uid-slot-lease-legacy' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$this->assertIsArray( $seen, 'Precondition: Run Now must acquire a slot.' );
		$row = array_values( $seen )[0] ?? array();
		$this->assertSame( 'legacy', $row['lane'] ?? '', 'Run Now holds a legacy slot.' );
		$this->assertArrayNotHasKey( 'heartbeat', $row, 'A legacy row carries no lease to expire.' );
		$this->assertFalse( $armed, 'A legacy holder must not clamp its own long round trip.' );
	}
}
