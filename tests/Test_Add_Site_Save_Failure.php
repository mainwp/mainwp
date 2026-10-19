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
		$table = $wpdb->prefix . 'mainwp_wp';
		// wpdb returns false for an empty query, which is how a failed insert reaches add_website().
		add_filter(
			'query',
			static function ( $query ) use ( $table ) {
				return 0 === strpos( $query, 'INSERT INTO `' . $table . '`' ) ? '' : $query;
			}
		);
		$url   = 'https://save-failure-' . wp_generate_uuid4() . '.example.com/';
		$_POST = array(
			'managesites_add_wpurl'   => $url,
			'managesites_add_wpname'  => 'Save failure',
			'managesites_add_wpadmin' => 'admin',
		);

		list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_site();

		$this->assertSame( 'The site could not be saved to the Dashboard. Please try again.', $error );
		$this->assertEmpty( $site_id );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url = %s", $url ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
	}
}
