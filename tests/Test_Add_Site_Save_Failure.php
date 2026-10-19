<?php
/**
 * Add Site save failure tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Manage_Sites_View;

/**
 * Add Site must not report success when the site row is not saved.
 */
class Test_Add_Site_Save_Failure extends \WP_UnitTestCase {

	/** The Child accepted the key but the Dashboard row was never written, so the add must not report success. */
	public function test_failed_site_insert_is_reported_as_an_error(): void {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		add_filter(
			'mainwp_fetch_url_not_authed_pre',
			static function () {
				return array( 'register' => 'OK' );
			}
		);
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function () {
				return array();
			}
		);
		$table       = $wpdb->prefix . 'mainwp_wp';
		$intercepted = false;
		// wpdb returns false for an empty query, which is how a failed insert reaches add_website().
		add_filter(
			'query',
			static function ( $query ) use ( $table, &$intercepted ) {
				if ( 0 !== strpos( $query, 'INSERT INTO `' . $table . '`' ) ) {
					return $query;
				}
				$intercepted = true;
				return '';
			}
		);
		$url   = 'https://save-failure-' . wp_generate_uuid4() . '.example.com/';
		$_POST = array(
			'managesites_add_wpurl'   => $url,
			'managesites_add_wpname'  => 'Save failure',
			'managesites_add_wpadmin' => 'admin',
		);

		$output                            = array();
		list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_site( false, $output );

		$this->assertTrue( $intercepted, 'The site insert must be the failure under test.' );
		$this->assertSame( 'The site could not be saved to the Dashboard. Please try again.', $error );
		$this->assertSame( '<p>' . $error . '</p>', $output['failure_ux'] );
		$this->assertEmpty( $site_id );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url = %s", $url ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
	}
}
