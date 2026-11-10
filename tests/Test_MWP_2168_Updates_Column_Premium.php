<?php
/**
 * The Manage Sites Updates column counts premium-only updates (MWP-2168).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Manage_Sites_List_Table;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Sync stores premium updates as the premium_upgrades site option. The wp
 * table has an empty column with the same name, so the table rows must carry
 * the option value.
 */
class Test_MWP_2168_Updates_Column_Premium extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Sites created for this case.
	 *
	 * @var int[]
	 */
	private $site_ids = array();

	/** Log in as an administrator. */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_System_Utility::set_current_wpid( 0 );
		wp_cache_flush();
	}

	/** Remove the site rows this case inserted. */
	public function tear_down() {
		global $wpdb;

		remove_filter( 'mainwp_sitestable_getcolumns', array( $this, 'update_column_only' ) );

		foreach ( $this->site_ids as $site_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $site_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ), array( '%d' ) );
		}

		parent::tear_down();
		wp_cache_flush();
	}

	/** A site whose only pending update is premium shows 1 in the Updates column. */
	public function test_premium_only_site_counts_one_update() {
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

		$site_id          = $this->create_test_site();
		$this->site_ids[] = $site_id;
		$this->set_site_option( $site_id, 'premium_upgrades', $premium );

		add_filter( 'mainwp_sitestable_getcolumns', array( $this, 'update_column_only' ) );
		$table = new MainWP_Manage_Sites_List_Table();
		$table->prepare_items( true );
		$output = $table->ajax_get_datatable_rows();

		$cell = null;
		foreach ( $output['rowsInfo'] as $index => $info ) {
			if ( (int) $info['siteID'] === $site_id ) {
				$cell = $output['data'][ $index ]['update'];
			}
		}

		$this->assertNotNull( $cell, 'The site is listed.' );
		$this->assertMatchesRegularExpression( '#</i>\s*1\s*</a>#', $cell );
	}

	/**
	 * Keep only the Updates column.
	 *
	 * @return array
	 */
	public function update_column_only() {
		return array( 'update' => 'Updates' );
	}
}
