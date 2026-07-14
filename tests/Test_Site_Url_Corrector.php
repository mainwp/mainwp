<?php
/**
 * Site URL corrector tests (MWP-1662).
 *
 * Covers MainWP_Site_Url_Corrector: the classifier (scheme + host + port
 * comparison, paths ignored, containment), the Layer-1 browser target helper,
 * the add-time correction rules, the user-set-URL auto-lock, and the daily
 * sweep + queued verify/apply pipeline with the child fetch mocked through
 * the mainwp_fetch_url_authed_pre testing filter.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Site_Url_Corrector as Corrector;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Site_Url_Corrector extends \WP_UnitTestCase {

	/**
	 * Captured probe URLs from the mocked verify fetch.
	 *
	 * @var array
	 */
	protected $probe_urls = array();

	/**
	 * Mocked verify fetch result to return.
	 *
	 * @var mixed
	 */
	protected $mock_fetch_result = array( 'ok' => 1 );

	public function setUp(): void {
		parent::setUp();
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->probe_urls        = array();
		$this->mock_fetch_result = array( 'ok' => 1 );
	}

	public function tearDown(): void {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'mock_fetch' ), 10 );
		delete_option( 'mainwp_auto_correct_site_url' );
		delete_option( 'mainwp_url_correct_last_sweep' );
		delete_option( 'mainwp_url_correct_has_queue' );
		parent::tearDown();
	}

	/**
	 * Mock for the authed verify fetch; captures the probe URL.
	 *
	 * @param mixed  $pre     Short-circuit value (false).
	 * @param object $website Website (clone) being fetched.
	 * @param string $what    Requested function.
	 * @param array  $params  Request params.
	 * @return mixed
	 */
	public function mock_fetch( $pre, $website, $what, $params ) {
		$this->probe_urls[] = $website->url;
		return $this->mock_fetch_result;
	}

	/**
	 * Insert a minimal site row + sync row directly (same approach as the
	 * abilities test case helper).
	 *
	 * @param string   $url      Stored dashboard URL.
	 * @param string   $siteurl  Child-reported URL.
	 * @param int|null $dts_sync Last sync timestamp; defaults to now.
	 * @return int Site ID.
	 */
	protected function create_site( $url, $siteurl, $dts_sync = null ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			array(
				'userid'               => get_current_user_id() > 0 ? get_current_user_id() : 1,
				'url'                  => $url,
				'siteurl'              => $siteurl,
				'name'                 => 'URL Corrector Test Site',
				'adminname'            => 'admin',
				'pubkey'               => 'test-pubkey',
				'privkey'              => 'test-privkey',
				'ssl_version'          => 0,
				'http_user'            => '',
				'http_pass'            => '',
				'suspended'            => 0,
				'offline_check_result' => 1,
				'client_id'            => 0,
				'is_staging'           => 0,
				'plugin_upgrades'      => '',
				'theme_upgrades'       => '',
				'translation_upgrades' => '',
				'premium_upgrades'     => '',
			)
		);

		$site_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			array(
				'wpid'        => $site_id,
				'version'     => '5.0.0',
				'sync_errors' => '',
				'dtsSync'     => null === $dts_sync ? time() : $dts_sync,
			)
		);

		return $site_id;
	}

	// ---------------------------------------------------------------------
	// classify()
	// ---------------------------------------------------------------------

	public function test_classify_identical_modulo_slash_case_and_path(): void {
		$this->assertSame( Corrector::CATEGORY_IDENTICAL, Corrector::classify( 'https://example.com/', 'https://example.com' ) );
		$this->assertSame( Corrector::CATEGORY_IDENTICAL, Corrector::classify( 'https://Example.COM/', 'https://example.com' ) );
		// Subdirectory install: path differences are ignored.
		$this->assertSame( Corrector::CATEGORY_IDENTICAL, Corrector::classify( 'https://example.com/', 'https://example.com/wp' ) );
	}

	public function test_classify_scheme_upgrade_and_downgrade(): void {
		$this->assertSame( Corrector::CATEGORY_SCHEME_UPGRADE, Corrector::classify( 'http://example.com/', 'https://example.com' ) );
		$this->assertSame( Corrector::CATEGORY_SCHEME_DOWNGRADE, Corrector::classify( 'https://example.com/', 'http://example.com' ) );
	}

	public function test_classify_www_only_both_directions(): void {
		$this->assertSame( Corrector::CATEGORY_WWW_ONLY, Corrector::classify( 'https://example.com/', 'https://www.example.com' ) );
		$this->assertSame( Corrector::CATEGORY_WWW_ONLY, Corrector::classify( 'https://www.example.com/', 'https://example.com' ) );
	}

	public function test_classify_scheme_and_www_combined(): void {
		$this->assertSame( Corrector::CATEGORY_SCHEME_WWW_UPGRADE, Corrector::classify( 'http://example.com/', 'https://www.example.com' ) );
		$this->assertSame( Corrector::CATEGORY_SCHEME_WWW_DOWNGRADE, Corrector::classify( 'https://www.example.com/', 'http://example.com' ) );
	}

	public function test_classify_containment_other_categories(): void {
		// Different domain.
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( 'https://example.com/', 'https://example.org' ) );
		// Different subdomain (www-stripping must not equate these).
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( 'https://example.com/', 'https://blog.example.com' ) );
		// Different port.
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( 'https://example.com/', 'https://example.com:8443' ) );
		// Reverse proxy reporting an internal address.
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( 'https://example.com/', 'http://localhost:8080' ) );
	}

	public function test_classify_guards_malformed_input(): void {
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( 'https://example.com/', '' ) );
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( '', 'https://example.com' ) );
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( 'https://example.com/', 'not a url' ) );
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( 'https://example.com/', 'ftp://example.com' ) );
	}

	public function test_auto_fixable_policy(): void {
		$this->assertTrue( Corrector::is_auto_fixable_category( Corrector::CATEGORY_WWW_ONLY ) );
		$this->assertTrue( Corrector::is_auto_fixable_category( Corrector::CATEGORY_SCHEME_UPGRADE ) );
		$this->assertTrue( Corrector::is_auto_fixable_category( Corrector::CATEGORY_SCHEME_WWW_UPGRADE ) );
		$this->assertFalse( Corrector::is_auto_fixable_category( Corrector::CATEGORY_SCHEME_DOWNGRADE ) );
		$this->assertFalse( Corrector::is_auto_fixable_category( Corrector::CATEGORY_SCHEME_WWW_DOWNGRADE ) );
		$this->assertFalse( Corrector::is_auto_fixable_category( Corrector::CATEGORY_OTHER ) );
		$this->assertFalse( Corrector::is_auto_fixable_category( Corrector::CATEGORY_IDENTICAL ) );
	}

	// ---------------------------------------------------------------------
	// browser_target_url() (Layer 1)
	// ---------------------------------------------------------------------

	public function test_browser_target_swaps_scheme_host_and_keeps_stored_path(): void {
		$website = (object) array(
			'id'      => 0,
			'url'     => 'http://example.com/sub/',
			'siteurl' => 'https://www.example.com',
		);
		$this->assertSame( 'https://www.example.com/sub/', Corrector::browser_target_url( $website ) );
	}

	public function test_browser_target_returns_stored_when_identical_or_unsafe(): void {
		$identical = (object) array(
			'id'      => 0,
			'url'     => 'https://example.com/',
			'siteurl' => 'https://example.com',
		);
		$this->assertSame( 'https://example.com/', Corrector::browser_target_url( $identical ) );

		$downgrade = (object) array(
			'id'      => 0,
			'url'     => 'https://example.com/',
			'siteurl' => 'http://example.com',
		);
		$this->assertSame( 'https://example.com/', Corrector::browser_target_url( $downgrade ) );

		$cross_domain = (object) array(
			'id'      => 0,
			'url'     => 'https://example.com/',
			'siteurl' => 'https://evil.example.org',
		);
		$this->assertSame( 'https://example.com/', Corrector::browser_target_url( $cross_domain ) );
	}

	public function test_browser_target_respects_lock_and_kill_switch(): void {
		$website = (object) array(
			'id'               => 0,
			'url'              => 'http://example.com/',
			'siteurl'          => 'https://example.com',
			'url_correct_lock' => 1,
		);
		$this->assertSame( 'http://example.com/', Corrector::browser_target_url( $website ) );

		$unlocked = (object) array(
			'id'      => 0,
			'url'     => 'http://example.com/',
			'siteurl' => 'https://example.com',
		);
		add_filter( 'mainwp_open_site_use_reported_url', '__return_false' );
		try {
			$this->assertSame( 'http://example.com/', Corrector::browser_target_url( $unlocked ) );
		} finally {
			remove_filter( 'mainwp_open_site_use_reported_url', '__return_false' );
		}
		$this->assertSame( 'https://example.com/', Corrector::browser_target_url( $unlocked ) );
	}

	public function test_browser_target_fallback_parity_with_old_ternary(): void {
		// Empty stored url falls back to siteurl (old `$website->url ?: $website->siteurl`).
		$website = (object) array(
			'id'      => 0,
			'url'     => '',
			'siteurl' => 'https://example.com',
		);
		$this->assertSame( 'https://example.com', Corrector::browser_target_url( $website ) );

		// Empty siteurl: stored url unchanged.
		$website = (object) array(
			'id'      => 0,
			'url'     => 'http://example.com/',
			'siteurl' => '',
		);
		$this->assertSame( 'http://example.com/', Corrector::browser_target_url( $website ) );
	}

	// ---------------------------------------------------------------------
	// correct_entered_url() (Layer 3, add path)
	// ---------------------------------------------------------------------

	public function test_correct_entered_url_adopts_www_and_scheme_upgrade(): void {
		$this->assertSame( 'https://www.example.com/', Corrector::correct_entered_url( 'https://example.com/', 'https://www.example.com' ) );
		$this->assertSame( 'https://example.com/', Corrector::correct_entered_url( 'http://example.com/', 'https://example.com' ) );
		$this->assertSame( 'https://www.example.com/', Corrector::correct_entered_url( 'http://example.com/', 'https://www.example.com' ) );
	}

	public function test_correct_entered_url_never_downgrades_scheme(): void {
		// Scheme downgrade: keep everything as entered.
		$this->assertSame( 'https://example.com/', Corrector::correct_entered_url( 'https://example.com/', 'http://example.com' ) );
		// Combined downgrade: adopt www variant, keep entered https.
		$this->assertSame( 'https://example.com/', Corrector::correct_entered_url( 'https://www.example.com/', 'http://example.com' ) );
	}

	public function test_correct_entered_url_never_crosses_domains(): void {
		$this->assertSame( 'https://example.com/', Corrector::correct_entered_url( 'https://example.com/', 'https://example.org' ) );
		$this->assertSame( 'https://example.com/', Corrector::correct_entered_url( 'https://example.com/', '' ) );
	}

	// ---------------------------------------------------------------------
	// after_user_set_url() auto-lock
	// ---------------------------------------------------------------------

	public function test_after_user_set_url_locks_on_divergence_only(): void {
		$site_id = $this->create_site( 'https://example-lock.com/', 'https://example-lock.com' );

		Corrector::after_user_set_url( $site_id, 'https://example-lock.com/' );
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_lock', 0 ) );

		Corrector::after_user_set_url( $site_id, 'http://www.example-lock.com/' );
		$this->assertNotEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_lock', 0 ) );
	}

	// ---------------------------------------------------------------------
	// cron_sweep() (Layer 2 detection)
	// ---------------------------------------------------------------------

	public function test_sweep_ignores_trailing_slash_only_difference(): void {
		$site_id = $this->create_site( 'https://slash-test.com/', 'https://slash-test.com' );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		Corrector::instance()->cron_sweep();

		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_pending', '' ) );
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', '' ) );
	}

	public function test_sweep_queues_www_mismatch_in_auto_mode(): void {
		$site_id = $this->create_site( 'https://queue-test.com/', 'https://www.queue-test.com' );

		// Deliberately no option write: automatic correction is ON by default.
		Corrector::instance()->cron_sweep();

		$pending = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_pending', null, true );
		$this->assertIsArray( $pending );
		$this->assertSame( 'https://www.queue-test.com/', $pending['candidate'] );
		$this->assertSame( Corrector::CATEGORY_WWW_ONLY, $pending['category'] );
	}

	public function test_sweep_suggest_only_when_auto_disabled(): void {
		$site_id = $this->create_site( 'https://suggest-test.com/', 'https://www.suggest-test.com' );

		update_option( 'mainwp_auto_correct_site_url', 0 );
		Corrector::instance()->cron_sweep();

		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_pending', '' ) );
		$suggest = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', null, true );
		$this->assertIsArray( $suggest );
		$this->assertSame( 'suggest_only_mode', $suggest['reason'] );
	}

	public function test_sweep_never_queues_downgrade_or_cross_domain(): void {
		$downgrade_id = $this->create_site( 'https://downgrade-test.com/', 'http://downgrade-test.com' );
		$cross_id     = $this->create_site( 'https://cross-test.com/', 'https://cross-test.org' );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		Corrector::instance()->cron_sweep();

		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $downgrade_id, 'url_correct_pending', '' ) );
		$suggest = MainWP_DB::instance()->get_website_option( $downgrade_id, 'url_correct_suggest', null, true );
		$this->assertIsArray( $suggest );
		$this->assertSame( 'policy', $suggest['reason'] );

		// Cross-domain differences are not scheme/www-class: no queue, no journal.
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $cross_id, 'url_correct_pending', '' ) );
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $cross_id, 'url_correct_suggest', '' ) );
	}

	public function test_sweep_respects_lock_and_freshness_gate(): void {
		$locked_id = $this->create_site( 'https://locked-test.com/', 'https://www.locked-test.com' );
		MainWP_DB::instance()->update_website_option( $locked_id, 'url_correct_lock', 1 );

		$stale_id = $this->create_site( 'https://stale-test.com/', 'https://www.stale-test.com', time() - 60 * DAY_IN_SECONDS );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		Corrector::instance()->cron_sweep();

		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $locked_id, 'url_correct_pending', '' ) );
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $stale_id, 'url_correct_pending', '' ) );
	}

	// ---------------------------------------------------------------------
	// process_queue() (Layer 2 verify + apply)
	// ---------------------------------------------------------------------

	/**
	 * Run a full sweep + drain cycle fleet-wide with the verify fetch mocked.
	 */
	protected function drain_all() {
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'mock_fetch' ), 10, 4 );
		try {
			Corrector::instance()->cron_sweep();
			Corrector::instance()->process_queue();
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'mock_fetch' ), 10 );
		}
	}

	public function test_queue_applies_verified_correction(): void {
		$site_id = $this->create_site( 'http://apply-test.com/', 'https://www.apply-test.com' );

		update_option( 'mainwp_auto_correct_site_url', 1 );

		$fired = array();
		$hook  = function ( $website_id, $old_url, $new_url, $source ) use ( &$fired ) {
			$fired[] = array( $website_id, $old_url, $new_url, $source );
		};
		add_action( 'mainwp_site_url_corrected', $hook, 10, 4 );

		try {
			$this->drain_all();
		} finally {
			remove_action( 'mainwp_site_url_corrected', $hook, 10 );
		}

		// Verify probe targeted the candidate URL (trailing-slashed).
		$this->assertContains( 'https://www.apply-test.com/', $this->probe_urls );

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( 'https://www.apply-test.com/', $website->url );

		// Pending cleared, history appended, hook fired once.
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_pending', '' ) );
		$history = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_history', null, true );
		$this->assertIsArray( $history );
		$this->assertCount( 1, $history );
		$this->assertSame( 'http://apply-test.com/', $history[0]['old'] );
		$this->assertSame( 'https://www.apply-test.com/', $history[0]['new'] );

		$this->assertCount( 1, $fired );
		$this->assertSame( array( $site_id, 'http://apply-test.com/', 'https://www.apply-test.com/', 'auto' ), $fired[0] );
	}

	public function test_queue_verify_failure_records_suggestion_and_keeps_url(): void {
		$site_id = $this->create_site( 'http://verifyfail-test.com/', 'https://verifyfail-test.com' );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		$this->mock_fetch_result = array( 'error' => 'connect failed' );

		$this->drain_all();

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( 'http://verifyfail-test.com/', $website->url );

		$suggest = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', null, true );
		$this->assertIsArray( $suggest );
		$this->assertSame( 'verify_failed', $suggest['reason'] );
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_pending', '' ) );
	}

	public function test_queue_duplicate_guard_never_collapses_two_sites(): void {
		// The www variant is already registered as its own site.
		$this->create_site( 'https://www.duptest.com/', 'https://www.duptest.com' );
		$site_id = $this->create_site( 'https://duptest.com/', 'https://www.duptest.com' );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		$this->drain_all();

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( 'https://duptest.com/', $website->url );

		$suggest = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', null, true );
		$this->assertIsArray( $suggest );
		$this->assertSame( 'duplicate_site', $suggest['reason'] );
	}

	public function test_queue_damper_blocks_second_correction_within_window(): void {
		$site_id = $this->create_site( 'http://damper-test.com/', 'https://damper-test.com' );

		// A correction was already applied recently.
		MainWP_DB::instance()->update_website_option(
			$site_id,
			'url_correct_history',
			wp_json_encode(
				array(
					array(
						'old'      => 'https://damper-test.com/',
						'new'      => 'http://damper-test.com/',
						'category' => Corrector::CATEGORY_WWW_ONLY,
						'source'   => 'auto',
						'ts'       => time() - DAY_IN_SECONDS,
					),
				)
			)
		);

		update_option( 'mainwp_auto_correct_site_url', 1 );
		$this->drain_all();

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( 'http://damper-test.com/', $website->url );

		$suggest = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', null, true );
		$this->assertIsArray( $suggest );
		$this->assertSame( 'damper', $suggest['reason'] );
	}

	public function test_queue_is_inert_when_auto_mode_disabled(): void {
		$site_id = $this->create_site( 'http://inert-test.com/', 'https://inert-test.com' );

		update_option( 'mainwp_auto_correct_site_url', 0 );

		// Manually seed a pending entry, then run the drain with auto mode off.
		MainWP_DB::instance()->update_website_option(
			$site_id,
			'url_correct_pending',
			wp_json_encode(
				array(
					'candidate' => 'https://inert-test.com/',
					'category'  => Corrector::CATEGORY_SCHEME_UPGRADE,
					'ts'        => time(),
				)
			)
		);

		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'mock_fetch' ), 10, 4 );
		try {
			Corrector::instance()->process_queue();
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'mock_fetch' ), 10 );
		}

		$this->assertSame( array(), $this->probe_urls );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( 'http://inert-test.com/', $website->url );
	}

	public function test_queue_retry_damper_and_timestamp_refresh(): void {
		$site_id = $this->create_site( 'http://retry-test.com/', 'https://retry-test.com' );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		$this->mock_fetch_result = array( 'error' => 'connect failed' );

		$this->drain_all();
		$this->assertCount( 1, $this->probe_urls );
		$first = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', null, true );
		$this->assertSame( 'verify_failed', $first['reason'] );

		// Within the retry window the sweep must not re-queue the failed candidate.
		Corrector::instance()->cron_sweep();
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_pending', '' ) );

		// Age the failure past RETRY_DAYS: the sweep re-queues and a repeated
		// failure must REFRESH the journal timestamp (no daily-probe runaway).
		$aged       = $first;
		$aged['ts'] = time() - ( Corrector::RETRY_DAYS + 1 ) * DAY_IN_SECONDS;
		MainWP_DB::instance()->update_website_option( $site_id, 'url_correct_suggest', wp_json_encode( $aged ) );

		$this->drain_all();
		$this->assertCount( 2, $this->probe_urls );
		$second = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', null, true );
		$this->assertGreaterThan( $aged['ts'], $second['ts'] );
	}

	public function test_set_lock_clears_pending_and_blocks_drain(): void {
		$site_id = $this->create_site( 'https://lockdrain-test.com/', 'https://www.lockdrain-test.com' );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		Corrector::instance()->cron_sweep();
		$this->assertNotEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_pending', '' ) );

		Corrector::set_lock( $site_id, true );
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_pending', '' ) );

		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'mock_fetch' ), 10, 4 );
		try {
			Corrector::instance()->process_queue();
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'mock_fetch' ), 10 );
		}

		$this->assertSame( array(), $this->probe_urls );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( 'https://lockdrain-test.com/', $website->url );
	}

	public function test_get_get_data_authed_keeps_stored_url_unless_custom_passed(): void {
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		if ( false === $key || ! openssl_pkey_export( $key, $privkey_pem ) ) {
			$this->markTestSkipped( 'OpenSSL key generation unavailable in this environment.' );
		}

		$website = (object) array(
			'id'        => 0,
			'url'       => 'https://stored.example.com/',
			'siteurl'   => 'https://www.stored.example.com',
			'adminname' => 'admin',
			'privkey'   => base64_encode( $privkey_pem ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- test fixture.
		);

		// Default behavior (backup downloads, premium updates): stored URL, never the reported one.
		$default_url = \MainWP\Dashboard\MainWP_Connect::get_get_data_authed( $website, 'index.php' );
		$this->assertStringStartsWith( 'https://stored.example.com/?', $default_url );

		// SiteOpen passes the browser target explicitly.
		$custom_url = \MainWP\Dashboard\MainWP_Connect::get_get_data_authed( $website, 'index.php', 'where', false, array(), Corrector::browser_target_url( $website ) );
		$this->assertStringStartsWith( 'https://www.stored.example.com/?', $custom_url );
	}

	public function test_queue_history_cap_keeps_newest_entries(): void {
		$site_id = $this->create_site( 'http://histcap-test.com/', 'https://histcap-test.com' );

		$old_ts  = time() - ( Corrector::DAMPER_DAYS + 10 ) * DAY_IN_SECONDS;
		$history = array();
		for ( $i = 0; $i < Corrector::HISTORY_MAX_ENTRIES; $i++ ) {
			$history[] = array(
				'old'      => 'http://histcap-test.com/v' . $i . '/',
				'new'      => 'https://histcap-test.com/v' . $i . '/',
				'category' => Corrector::CATEGORY_SCHEME_UPGRADE,
				'source'   => 'auto',
				'ts'       => $old_ts + $i,
			);
		}
		MainWP_DB::instance()->update_website_option( $site_id, 'url_correct_history', wp_json_encode( $history ) );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		$this->drain_all();

		$stored = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_history', null, true );
		$this->assertCount( Corrector::HISTORY_MAX_ENTRIES, $stored );
		// Oldest seeded entry dropped, newest entry is the fresh correction.
		$this->assertNotSame( 'http://histcap-test.com/v0/', $stored[0]['old'] );
		$this->assertSame( 'https://histcap-test.com/', end( $stored )['new'] );
	}

	public function test_sweep_is_idempotent_across_runs(): void {
		$auto_id = $this->create_site( 'https://idem-auto.com/', 'https://www.idem-auto.com' );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		Corrector::instance()->cron_sweep();
		$first = MainWP_DB::instance()->get_website_option( $auto_id, 'url_correct_pending', null, true );
		Corrector::instance()->cron_sweep();
		$second = MainWP_DB::instance()->get_website_option( $auto_id, 'url_correct_pending', null, true );
		$this->assertSame( $first['ts'], $second['ts'] );

		update_option( 'mainwp_auto_correct_site_url', 0 );
		$suggest_id = $this->create_site( 'https://idem-suggest.com/', 'https://www.idem-suggest.com' );
		Corrector::instance()->cron_sweep();
		$first = MainWP_DB::instance()->get_website_option( $suggest_id, 'url_correct_suggest', null, true );
		Corrector::instance()->cron_sweep();
		$second = MainWP_DB::instance()->get_website_option( $suggest_id, 'url_correct_suggest', null, true );
		$this->assertSame( $first['ts'], $second['ts'] );
	}

	public function test_ports_matching_and_default_port_edge(): void {
		$this->assertSame( Corrector::CATEGORY_SCHEME_UPGRADE, Corrector::classify( 'http://example.com:8080/', 'https://example.com:8080' ) );

		$website = (object) array(
			'id'      => 0,
			'url'     => 'http://example.com:8080/sub/',
			'siteurl' => 'https://example.com:8080',
		);
		$this->assertSame( 'https://example.com:8080/sub/', Corrector::browser_target_url( $website ) );

		// Explicit default port vs none is treated conservatively as `other`.
		$this->assertSame( Corrector::CATEGORY_OTHER, Corrector::classify( 'https://example.com/', 'https://example.com:443' ) );
	}

	public function test_after_user_set_url_object_input_and_edge_cases(): void {
		// Production callers pass the website object.
		$site_id = $this->create_site( 'https://objlock-test.com/', 'https://objlock-test.com' );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		// Cross-domain divergence must also lock (deliberate user choice).
		Corrector::after_user_set_url( $website, 'https://elsewhere.example.org/' );
		$this->assertNotEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_lock', 0 ) );

		// Never-synced site (empty siteurl): no lock.
		$fresh_id = $this->create_site( 'https://freshlock-test.com/', '' );
		Corrector::after_user_set_url( MainWP_DB::instance()->get_website_by_id( $fresh_id ), 'http://freshlock-test.com/' );
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $fresh_id, 'url_correct_lock', 0 ) );
	}

	public function test_correct_entered_url_scheme_upgrade_can_be_disallowed(): void {
		// With the upgrade disallowed ( failed https probe at add time ), only the www variant is adopted.
		$this->assertSame( 'http://www.example.com/', Corrector::correct_entered_url( 'http://example.com/', 'https://www.example.com', false ) );
		$this->assertSame( 'http://example.com/', Corrector::correct_entered_url( 'http://example.com/', 'https://example.com', false ) );
	}

	public function test_queue_duplicate_guard_matches_slashless_sibling(): void {
		// Sibling row stored WITHOUT the trailing slash ( abilities/legacy writes ).
		$this->create_site( 'https://www.duptest2.com', 'https://www.duptest2.com' );
		$site_id = $this->create_site( 'https://duptest2.com/', 'https://www.duptest2.com' );

		update_option( 'mainwp_auto_correct_site_url', 1 );
		$this->drain_all();

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( 'https://duptest2.com/', $website->url );
		$suggest = MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', null, true );
		$this->assertSame( 'duplicate_site', $suggest['reason'] );
	}

	public function test_run_scheduled_tasks_sweeps_at_most_daily(): void {
		$site_id = $this->create_site( 'https://gate-test.com/', 'https://www.gate-test.com' );

		// Suggest-only mode so the drain stays inert ( no fetch mock installed here ).
		update_option( 'mainwp_auto_correct_site_url', 0 );
		Corrector::instance()->run_scheduled_tasks();
		$this->assertNotEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', '' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'mainwp_url_correct_last_sweep', 0 ) );

		// Second invocation within the same day: gated, no re-journal.
		MainWP_DB::instance()->update_website_option( $site_id, 'url_correct_suggest', '' );
		Corrector::instance()->run_scheduled_tasks();
		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $site_id, 'url_correct_suggest', '' ) );
	}
}
