<?php
/**
 * MainWP Check-Backups Widget Authorization Tests
 *
 * The mainwp_checkbackups AJAX action is deliberately registered without a
 * dashboard capability gate (any logged-in dashboard user drives the
 * updates-overview widget), which makes the per-site check inside
 * check_backups() the only thing standing between a request and the
 * backup-freshness status of arbitrary site ids. These tests pin that
 * check: a Team Control-restricted site never appears in the response, and
 * a nonexistent site id cannot fatal the handler.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Updates_Overview;

/**
 * Class MainWP_Backup_Checkbackups_Auth_Test
 */
class MainWP_Backup_Checkbackups_Auth_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Clean up the request array between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_POST['sites'] );
		parent::tearDown();
	}

	/**
	 * Deny the per-site capability for one site id via the capability
	 * filter, simulating a Team Control site restriction.
	 *
	 * @param int $site_id Site id to deny.
	 *
	 * @return callable The filter, for later removal.
	 */
	private function deny_site( $site_id ) {
		$deny = function ( $allowed, $cap_type, $cap ) use ( $site_id ) {
			if ( 'site' === $cap_type && (int) $cap === (int) $site_id ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		return $deny;
	}

	/**
	 * Create a site whose backup_before_upgrade flag makes it eligible for
	 * a freshness answer, so the accessible one is a positive control.
	 *
	 * @param int $user_id Owner user id.
	 *
	 * @return int Site id.
	 */
	private function create_eligible_site( $user_id ) {
		global $wpdb;

		$site_id = $this->create_test_site( array( 'userid' => $user_id ) );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'backup_before_upgrade' => 1 ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.

		return $site_id;
	}

	/**
	 * A Team Control-restricted site must not appear in the response even
	 * when its id is requested directly; an accessible site still answers.
	 *
	 * @return void
	 */
	public function test_check_backups_excludes_restricted_site() {
		$this->skip_when_capabilities_bypassed();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$allowed = $this->create_eligible_site( $user );
		$denied  = $this->create_eligible_site( $user );

		$deny            = $this->deny_site( $denied );
		$_POST['sites'] = array( (string) $allowed, (string) $denied );
		try {
			$output = MainWP_Updates_Overview::check_backups();
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		$this->assertIsArray( $output );
		$this->assertArrayHasKey( 'sites', $output, 'The accessible site must still get a freshness answer.' );
		$this->assertArrayHasKey( $allowed, $output['sites'], 'The accessible site must still get a freshness answer.' );
		$this->assertArrayNotHasKey( $denied, $output['sites'], 'A restricted site id must not leak its backup status.' );
		$this->assertArrayNotHasKey( (string) $denied, $output['sites'], 'A restricted site id must not leak its backup status.' );
	}

	/**
	 * A nonexistent site id must be skipped, not fatal the handler on a
	 * null website object.
	 *
	 * @return void
	 */
	public function test_check_backups_survives_unknown_site_id() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$_POST['sites'] = array( '999999321' );
		$output          = MainWP_Updates_Overview::check_backups();

		if ( is_array( $output ) && isset( $output['sites'] ) ) {
			$this->assertArrayNotHasKey( '999999321', $output['sites'], 'An unknown site id must produce no answer.' );
			$this->assertArrayNotHasKey( 999999321, $output['sites'], 'An unknown site id must produce no answer.' );
		} else {
			$this->assertTrue( true === $output || array() === $output, 'An unknown site id must be skipped without a fatal.' );
		}
	}
}
