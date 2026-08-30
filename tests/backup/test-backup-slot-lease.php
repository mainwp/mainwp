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
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
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
	 * The bound a request gets once its holder has lost the slot: short
	 * enough that the request in flight ends and the holder reaches its
	 * own stop. Mirrors the monitor's private floor.
	 *
	 * @var int
	 */
	const LOST_SLOT_BOUND = 30;

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
	 * on - is flagged and handed a request short enough to end quickly.
	 * Stopping is the holder's own decision, so nothing is thrown here.
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
		$this->assertFalse( MainWP_Backup_Monitor::native_lease_lost(), 'A covered holder has not lost anything.' );

		$this->purge_slot_rows();

		$this->assertSame(
			self::LOST_SLOT_BOUND,
			apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null ),
			'A request with no coverage left is cut to the floor, not given the bound it asked for.'
		);
		$this->assertTrue( MainWP_Backup_Monitor::native_lease_lost(), 'The holder is flagged as having lost its slot.' );

		$timeouts = apply_filters(
			'mainwp_download_to_file_timeouts',
			array(
				'connect_timeout' => 30,
				'timeout'         => 7200,
			)
		);
		$this->assertSame( self::LOST_SLOT_BOUND, (int) $timeouts['timeout'], 'A transfer with no coverage left is cut the same way.' );
	}

	/**
	 * The matrix the bound resolver decides on, driven through the filter
	 * the transport actually calls. Partial coverage - a row that is still
	 * there but carries less than the request asks for - is not in the
	 * table because it cannot be produced in-process: the read and the
	 * write are one call, and the only way the write reports no rows is a
	 * value that is already what the write would store, which is full
	 * coverage by definition.
	 *
	 * @return void
	 */
	public function test_the_bound_a_request_gets_follows_its_scope_and_its_coverage() {
		$cap = MainWP_Backup_Monitor::NATIVE_REQUEST_TIMEOUT_SECONDS;

		// With nothing in scope there is no row to bound against and no
		// filter installed either, so this row of the matrix is only
		// reachable by calling the resolver the way the callbacks do.
		$resolve = new \ReflectionMethod( MainWP_Backup_Monitor::class, 'resolve_native_bound' );
		$resolve->setAccessible( true );

		$out_of_scope = array(
			'no scope, no timeout offered'  => array( 0, $cap ),
			'no scope, negative timeout'    => array( -1, $cap ),
			'no scope, non-numeric timeout' => array( 'none', $cap ),
			'no scope, shorter timeout'     => array( 120, 120 ),
		);
		foreach ( $out_of_scope as $label => $case ) {
			$this->assertSame( $case[1], $resolve->invoke( null, $case[0], $cap ), $label );
			$this->assertFalse( MainWP_Backup_Monitor::native_lease_lost(), $label . ' - nothing in scope can lose a slot.' );
		}

		$this->acquire_leased_slot();

		$in_scope = array(
			'the transport backstop is clamped to the native bound' => array( 20 * HOUR_IN_SECONDS, $cap ),
			'a shorter bound already in force wins'      => array( 120, 120 ),
			'zero is libcurl\'s no-timeout, so it takes the bound' => array( 0, $cap ),
			'a negative timeout is no timeout either'    => array( -1, $cap ),
			'a non-numeric timeout is no timeout either' => array( 'none', $cap ),
			'half a second still buys a whole second'    => array( '0.5', 1 ),
			'a fraction rounds up rather than down'      => array( '1.5', 2 ),
			'so does a fraction under the cap'           => array( '30.5', 31 ),
			'an infinite timeout is no timeout at all'   => array( INF, $cap ),
			'neither is one past what a float can count' => array( PHP_FLOAT_MAX, $cap ),
			'nor one written in exponent form'           => array( '1e100', $cap ),
			'nor the largest integer there is'           => array( PHP_INT_MAX, $cap ),
		);
		foreach ( $in_scope as $label => $case ) {
			$this->assertSame( $case[1], apply_filters( 'mainwp_fetch_url_site_timeout', $case[0], null ), $label );
			$this->assertFalse( MainWP_Backup_Monitor::native_lease_lost(), $label . ' - and the slot is still held.' );
		}

		$lost = 0;
		add_action(
			'mainwp_backup_diag_slot',
			function ( $event ) use ( &$lost ) {
				if ( 'lost' === $event ) {
					++$lost;
				}
			},
			10,
			4
		);

		$this->purge_slot_rows();

		$this->assertSame( self::LOST_SLOT_BOUND, apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null ), 'No coverage left cuts the request to the floor.' );
		$this->assertTrue( MainWP_Backup_Monitor::native_lease_lost() );
		$this->assertSame( 1, $lost, 'The loss is traced once.' );

		$this->assertSame( self::LOST_SLOT_BOUND, apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null ) );
		$this->assertSame( 1, $lost, 'Every later request in the same run finds the same thing and must not re-trace it.' );
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
	 * the zombie is flagged as having lost its slot and its next request
	 * is cut to the floor instead of running on under the new owner's
	 * slot, the new holder's lease is untouched, and the zombie's release
	 * still frees nothing.
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

		$this->assertSame(
			self::LOST_SLOT_BOUND,
			apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null ),
			'A zombie holder must not be handed a timeout to keep working under.'
		);
		$this->assertTrue( MainWP_Backup_Monitor::native_lease_lost(), 'The zombie knows it lost its slot.' );

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
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Slot lease site loop task', 'manual', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
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

	/**
	 * Replace slot 1 with a row another process owns, the way a reclaim
	 * plus a fresh claim would leave it.
	 *
	 * @return void
	 */
	private function steal_slot_row() {
		global $wpdb;

		$name = MainWP_Backup_Monitor::SLOT_OPTION_PREFIX . '1';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture for the DB semaphore.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture for the DB semaphore.
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				(string) wp_json_encode(
					array(
						'token'     => 'slot-stolen-by-another-process',
						'started'   => time(),
						'pid'       => 2,
						'lane'      => 'native',
						'heartbeat' => time(),
						'lease'     => MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS,
					)
				)
			)
		);
	}

	/**
	 * The decision the filters no longer make themselves: a holder whose
	 * slot went to another process stops the run instead of driving the
	 * rest of the task under a slot it does not own. Nothing is marked
	 * complete, the reason reaches the task row, and the scope closes
	 * clean.
	 *
	 * @return void
	 */
	public function test_a_holder_that_loses_its_slot_stops_the_run_without_completing_a_site() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$first  = $this->create_test_site(
			array(
				'userid' => $user,
				'url'    => 'http://127.0.0.1:9/first/',
			)
		);
		$second = $this->create_test_site(
			array(
				'userid' => $user,
				'url'    => 'http://127.0.0.1:9/second/',
			)
		);
		$task   = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Slot lease lost holder task', 'manual', 'full', '', $first . ',' . $second, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		$stolen       = false;
		$lost         = 0;
		$flag_at_lost = null;
		add_action(
			'mainwp_backup_diag_slot',
			function ( $event ) use ( &$stolen, &$lost, &$flag_at_lost ) {
				if ( 'acquired' === $event && ! $stolen ) {
					$stolen = true;
					$this->steal_slot_row();
				}
				if ( 'lost' === $event ) {
					++$lost;
					$flag_at_lost = MainWP_Backup_Monitor::native_lease_lost();
				}
			},
			10,
			4
		);

		$marked = array();
		add_action(
			'mainwp_backup_diag_site_mark',
			function ( $task_id, $site_id ) use ( &$marked ) {
				$marked[] = (int) $site_id;
			},
			10,
			4
		);

		$result = MainWP_Manage_Backups_Handler::execute_backup_task( $task );

		$this->assertTrue( $stolen, 'Precondition: the run must claim a slot for another process to take.' );
		$this->assertFalse( $result, 'A run that lost its slot has not completed.' );
		$this->assertSame( 1, $lost, 'The loss is traced once, not once per site.' );
		$this->assertTrue( $flag_at_lost, 'The flag the holder reads is set before the loss is traced.' );
		$this->assertSame( array(), $marked, 'Neither site may be driven under a slot this holder no longer owns.' );

		$row = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertNotEmpty( $row, 'Precondition: the task row must still be there.' );
		$this->assertEmpty( json_decode( (string) $row->completed_sites, true ), 'No site is retired by a run that lost its slot.' );
		$this->assertStringContainsString(
			MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SLOT_LOST ),
			(string) $row->backup_errors,
			'The reason the run stopped reaches the task row and the failure email path.'
		);

		$this->assertFalse( MainWP_Backup_Monitor::native_lease_lost(), 'Closing the scope clears the flag for the next run in this process.' );
		$this->assertFalse( has_filter( 'mainwp_fetch_url_site_timeout' ), 'The scope closes with the run.' );
		$this->assertFalse( has_filter( 'mainwp_download_to_file_timeouts' ), 'The scope closes with the run.' );
	}

	/**
	 * A child answer that arrives intact is no proof the holder still owns
	 * the slot: the loss can land after the request was bounded and before
	 * the answer is consumed, so the holder's last touch remembers a slot
	 * it no longer has. The site is stopped at that answer anyway, so none
	 * of what it would have produced - a snapshot row, a completed mark -
	 * is written, and the run says why it stopped.
	 *
	 * @return void
	 */
	public function test_a_slot_lost_inside_a_successful_request_records_no_snapshot() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site(
			array(
				'userid' => $user,
				'url'    => 'http://127.0.0.1:9/',
			)
		);
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Slot lost mid-request task', 'manual', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		// A child task already created and not yet consumed, so the one
		// round trip this run makes is the slice call answered below.
		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			(int) $task->id,
			$site_id,
			array(
				'dtsFetched'           => time(),
				'child_backup_task_id' => 'child-task-slot-lost',
				'fetchResult'          => '',
			)
		);

		$answered = false;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$answered ) {
				unset( $website );
				if ( 'backup_task_run' !== $what ) {
					return $result;
				}
				// The request is bounded against a healthy row, and only
				// then does the slot go to another process. The holder's
				// last touch therefore says it still owns the slot, which
				// is the whole point: what it remembers is not what the
				// row says by the time the answer is consumed.
				apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null );
				$this->steal_slot_row();
				$answered = true;

				return array(
					'phase' => 'completed',
					'file'  => 'full-slot-lost.zip',
					'size'  => 4096,
				);
			},
			10,
			3
		);

		$lost = 0;
		add_action(
			'mainwp_backup_diag_slot',
			function ( $event ) use ( &$lost ) {
				if ( 'lost' === $event ) {
					++$lost;
				}
			},
			10,
			4
		);

		$result = MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$this->assertTrue( $answered, 'Precondition: the run must reach the child round trip.' );
		$this->assertFalse( $result, 'A run that lost its slot has not completed.' );
		$this->assertSame( 1, $lost, 'The loss is traced once.' );
		$this->assertNull(
			MainWP_Backup_Snapshots::instance()->get_latest_complete( (int) $site_id, (int) $task->id ),
			'An answer received outside the slot must record no snapshot.'
		);

		// The stop is at the answer, not further down: an unclaimed
		// fetchResult and an unspent attempt are what say the completion was
		// never consumed and no download was tried under the lost slot.
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertEmpty( $progress->fetchResult, 'A completion answered outside the slot must not be claimed.' );
		$this->assertSame( 'child-task-slot-lost', (string) $progress->child_backup_task_id, 'The slice is left for the next cycle to run again.' );
		$this->assertSame( 0, (int) $progress->attempts, 'Stopping at the answer spends no retry attempt.' );

		$row = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertNotEmpty( $row, 'Precondition: the task row must still be there.' );
		$this->assertEmpty( json_decode( (string) $row->completed_sites, true ), 'The site must not be retired on the strength of that answer.' );
		$this->assertStringContainsString(
			MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SLOT_LOST ),
			(string) $row->backup_errors,
			'The reason the run stopped reaches the task row.'
		);
	}

	/**
	 * A site and a task set up for one create round trip under a lease.
	 *
	 * @return array Site id and task row.
	 */
	private function site_and_task_for_create(): array {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site(
			array(
				'userid' => $user,
				'url'    => 'http://127.0.0.1:9/create/',
			)
		);
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Slot lost after create task', 'manual', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		return array( (int) $site_id, $task );
	}

	/**
	 * Answer the create round trip with a real child task id, and take the
	 * slot to another process while that answer is in hand.
	 *
	 * @param int         $task_id     Task id, for the replacement claim.
	 * @param int         $site_id     Site id, for the replacement claim.
	 * @param string|null $replacement Claim a replacement driver writes over this driver's marker, null for none.
	 *
	 * @return string The child task id the create answers with.
	 */
	private function answer_create_after_losing_the_slot( int $task_id, int $site_id, ?string $replacement = null ): string {
		$child_task_id = 'child-task-created-after-loss';

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task_id, $site_id, $replacement, $child_task_id ) {
				unset( $website );
				if ( 'backup_preflight' === $what ) {
					return array( 'task_spine' => 1 );
				}
				if ( 'backup_task_create' !== $what ) {
					return $result;
				}

				// Bounded against a healthy row first, so the driver's own
				// memory still says it holds the slot when the answer lands.
				apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null );
				$this->steal_slot_row();
				if ( null !== $replacement ) {
					MainWP_DB_Backup::instance()->update_backup_task_progress( $task_id, $site_id, array( 'child_backup_task_id' => $replacement ) );
				}

				return array(
					'task_id' => $child_task_id,
					'phase'   => 'queued',
				);
			},
			10,
			3
		);

		return $child_task_id;
	}

	/**
	 * A slot lost right after a successful create leaves a real task
	 * building on the child under an id only this driver has seen. The
	 * driver stops, but the id goes onto the row it still owns, so the
	 * next cycle resumes that task instead of asking for a second one.
	 *
	 * @return void
	 */
	public function test_a_slot_lost_after_a_create_leaves_the_child_task_id_on_the_row() {
		list( $site_id, $task ) = $this->site_and_task_for_create();
		$this->acquire_leased_slot();
		$child_task_id = $this->answer_create_after_losing_the_slot( (int) $task->id, $site_id );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A driver that lost its slot stops without completing the site.' );
		$this->assertTrue( MainWP_Backup_Monitor::native_lease_lost(), 'The guard asked the row rather than trusting the last touch.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( $child_task_id, (string) $progress->child_backup_task_id, 'The created task is left on the row for the next cycle to resume.' );

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertEmpty(
			MainWP_DB::instance()->get_website_option( $website, MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION ),
			'A task the row adopted is still wanted, so it is not owed as a cancel.'
		);
	}

	/**
	 * When a replacement driver has already claimed the row, the created
	 * task has nowhere to go: the row is left exactly as it is and the id
	 * is owed to the child as a cancel, since nothing else can name it.
	 *
	 * @return void
	 */
	public function test_a_slot_lost_after_a_create_owes_a_cancel_when_another_driver_holds_the_row() {
		list( $site_id, $task ) = $this->site_and_task_for_create();
		$this->acquire_leased_slot();
		$child_task_id = $this->answer_create_after_losing_the_slot( (int) $task->id, $site_id, 'child-task-replacement-driver' );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A driver that lost its slot stops without completing the site.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'child-task-replacement-driver', (string) $progress->child_backup_task_id, 'A row a replacement driver claimed is left untouched.' );

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$owed    = json_decode( (string) MainWP_DB::instance()->get_website_option( $website, MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION ), true );
		$this->assertIsArray( $owed, 'The abandoned child task must be owed a cancel.' );
		$this->assertArrayHasKey( $child_task_id, $owed, 'The cancel is owed by the exact id the child answered with.' );
	}

	/**
	 * The progress table's index is not unique, so a duplicate row for the
	 * same task/site - an existing read-then-insert race - can carry this
	 * driver's own creating marker too. Both rows then belong to this
	 * driver, so the created task is adopted on both and nothing is owed
	 * as a cancel, even though only one row's update would once have
	 * counted as a match.
	 *
	 * @return void
	 */
	public function test_a_duplicate_progress_row_does_not_turn_a_kept_task_id_into_an_owed_cancel() {
		list( $site_id, $task ) = $this->site_and_task_for_create();
		$this->acquire_leased_slot();
		$child_task_id = 'child-task-created-after-loss';

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task, $site_id, $child_task_id ) {
				unset( $website );
				if ( 'backup_preflight' === $what ) {
					return array( 'task_spine' => 1 );
				}
				if ( 'backup_task_create' !== $what ) {
					return $result;
				}

				apply_filters( 'mainwp_fetch_url_site_timeout', 20 * HOUR_IN_SECONDS, null );
				$this->steal_slot_row();

				// Duplicate the row the way the existing read-then-insert race
				// would: same task/site, carrying the marker the executor
				// already wrote before this round trip left.
				$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
				global $wpdb;
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture, duplicating a progress row the way an existing race does.
					MainWP_DB_Backup::instance()->get_table_name( 'wp_backup_progress' ),
					array(
						'task_id'              => $task->id,
						'wp_id'                => $site_id,
						'dtsFetched'           => $progress->dtsFetched,
						'fetchResult'          => wp_json_encode( array() ),
						'removedFiles'         => 0,
						'downloadedDB'         => '',
						'downloadedFULL'       => '',
						'child_backup_task_id' => $progress->child_backup_task_id,
					)
				);

				return array(
					'task_id' => $child_task_id,
					'phase'   => 'queued',
				);
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A driver that lost its slot stops without completing the site.' );

		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test assertion, reading both duplicate rows back.
			$wpdb->prepare( 'SELECT child_backup_task_id FROM ' . MainWP_DB_Backup::instance()->get_table_name( 'wp_backup_progress' ) . ' WHERE task_id = %d AND wp_id = %d', $task->id, $site_id )
		);
		$this->assertCount( 2, $rows, 'Precondition: both duplicate rows exist.' );
		foreach ( $rows as $row ) {
			$this->assertSame( $child_task_id, (string) $row->child_backup_task_id, 'Every row carrying the marker is adopted, not just the first one matched.' );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertEmpty(
			MainWP_DB::instance()->get_website_option( $website, MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION ),
			'Both rows already belong to this driver, so nothing is owed as a cancel.'
		);
	}

	/**
	 * PHP turns numeric-string keys into ints across a JSON round trip, so
	 * an all-digits child task id must still be findable after
	 * record_pending_child_cancel() writes it and the site option is read
	 * back.
	 *
	 * @return void
	 */
	public function test_an_all_numeric_child_task_id_survives_the_owed_cancel_round_trip() {
		list( $site_id, $task ) = $this->site_and_task_for_create();
		unset( $task );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		// The second call is what re-reads the stored option through the
		// key filter; a lone call never reaches it.
		MainWP_Manage_Backups_Handler::record_pending_child_cancel( $website, '123456' );
		MainWP_Manage_Backups_Handler::record_pending_child_cancel( $website, 'task-1788000000-abcdef' );

		$owed = json_decode( (string) MainWP_DB::instance()->get_website_option( $website, MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION ), true );
		$this->assertIsArray( $owed, 'The all-numeric id must still be owed a cancel.' );
		$this->assertArrayHasKey( '123456', $owed, 'The id survives the JSON round trip.' );
		$this->assertArrayHasKey( 'task-1788000000-abcdef', $owed, 'The later id is owed alongside it.' );
	}

	/**
	 * Hashing a downloaded artifact in chunks, so the lease can be
	 * refreshed while it runs, produces the digest hash_file() would.
	 *
	 * @return void
	 */
	public function test_the_chunked_artifact_hash_matches_hash_file() {
		// Larger than one chunk, so the loop runs more than once.
		$file  = wp_tempnam( 'mainwp-hash-stream' );
		$bytes = MainWP_Backup_File_Manager::HASH_CHUNK_BYTES + 4096;
		file_put_contents( $file, str_repeat( 'mainwp-artifact-', (int) ceil( $bytes / 16 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$hash = new \ReflectionMethod( MainWP_Backup_File_Manager::class, 'hash_artifact' );
		$hash->setAccessible( true );

		$this->assertSame( hash_file( 'sha256', $file ), $hash->invoke( null, $file ) );

		wp_delete_file( $file );
	}

	/**
	 * A lease lost while an artifact is being hashed stops the hash and
	 * removes the file, the same shape a bad download takes: the slot may
	 * already be re-owned by a process writing this same path, so the
	 * bytes cannot be trusted.
	 *
	 * @return void
	 */
	public function test_the_artifact_hash_stops_and_clears_the_file_when_the_slot_is_lost() {
		$this->acquire_leased_slot();
		$this->purge_slot_rows();
		MainWP_Backup_Monitor::touch_native_leases( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS );
		$this->assertTrue( MainWP_Backup_Monitor::native_lease_lost(), 'Precondition: the direct tick must see the row is gone.' );

		$file = wp_tempnam( 'mainwp-hash-lost' );
		file_put_contents( $file, 'artifact bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$hash = new \ReflectionMethod( MainWP_Backup_File_Manager::class, 'hash_artifact' );
		$hash->setAccessible( true );

		try {
			$hash->invoke( null, $file );
			$this->fail( 'An artifact hashed under a lost slot must not be reported as verified.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SLOT_LOST, $e->get_message_error_code() );
		}

		$this->assertFileDoesNotExist( $file, 'The unverifiable artifact is removed the way a bad download is.' );
	}
}
