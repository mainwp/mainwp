<?php
/**
 * Premium-only updates in the Client Overview Sites widget and the Manage Sites Updates sort (MWP-2171).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Client_Overview_Sites;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Manage_Sites_List_Table;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Sync stores premium updates as the premium_upgrades site option; the wp
 * table column of the same name stays empty. The widget count and the
 * Manage Sites sort must read the option, as the Manage Sites cell does.
 */
class Test_MWP_2171_Premium_Updates_Widget_And_Sort extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Sites created for this case.
	 *
	 * @var int[]
	 */
	private $site_ids = array();

	/**
	 * Client created for this case.
	 *
	 * @var int
	 */
	private $client_id = 0;

	/** Log in as an administrator. */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_System_Utility::set_current_wpid( 0 );
		wp_cache_flush();
	}

	/** Remove the rows this case inserted. */
	public function tear_down() {
		global $wpdb;

		remove_filter( 'mainwp_sitestable_getcolumns', array( $this, 'update_column_only' ) );
		unset( $_REQUEST['order'], $_REQUEST['columns'], $_REQUEST['length'] );

		foreach ( $this->site_ids as $site_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $site_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ), array( '%d' ) );
		}

		if ( $this->client_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_clients', array( 'client_id' => $this->client_id ), array( '%d' ) );
		}

		parent::tear_down();
		wp_cache_flush();
	}

	/** The Client Overview Sites widget counts a premium-only update. */
	public function test_widget_counts_premium_only_update() {
		$client          = MainWP_DB_Client::instance()->update_client( array( 'name' => 'MWP-2171 Client', 'client_email' => 'mwp2171@example.com' ), true );
		$this->client_id = (int) $client->client_id;

		$site_id = $this->add_site( 'Premium Only', array( 'client_id' => $this->client_id ) );
		$this->set_site_option( $site_id, 'premium_upgrades', $this->premium_updates( array( 'premium/premium.php' => 'plugin' ) ) );

		$widget = new MainWP_Client_Overview_Sites();
		$widget->prepare_items( $this->client_id );
		ob_start();
		$widget->display_rows();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '#<tr id="child-site-' . $site_id . '"#', $html, 'The site is listed.' );
		$this->assertMatchesRegularExpression( '#updateid=' . $site_id . '">\s*<i class="sync alternate icon"></i>\s*1\s*</a>#', $html );
	}

	/** Sorting by Updates places a premium-only site by its premium count, as its cell shows. */
	public function test_update_sort_counts_premium_updates() {
		$none    = $this->add_site( 'No Updates' );
		$regular = $this->add_site( 'One Regular Update', array(), array( 'plugin_upgrades' => $this->regular_plugin_updates( 1 ) ) );
		$premium = $this->add_site( 'Two Premium Updates' );
		$this->set_site_option(
			$premium,
			'premium_upgrades',
			$this->premium_updates(
				array(
					'premium/premium.php' => 'plugin',
					'premium-theme'       => 'theme',
				)
			)
		);

		$this->assertSame( array( $none, $regular, $premium ), $this->sorted_site_ids( 'asc' ) );
		$this->assertSame( array( $premium, $regular, $none ), $this->sorted_site_ids( 'desc' ) );
	}

	/** The Updates sort still joins premium_upgrades when a filter drops it from the extra view. */
	public function test_update_sort_without_premium_in_extra_view() {
		$none    = $this->add_site( 'No Updates' );
		$premium = $this->add_site( 'One Premium Update' );
		$this->set_site_option( $premium, 'premium_upgrades', $this->premium_updates( array( 'premium/premium.php' => 'plugin' ) ) );

		add_filter( 'mainwp_sitestable_prepare_extra_view', array( $this, 'favicon_only' ) );
		$sorted = $this->sorted_site_ids( 'desc' );
		remove_filter( 'mainwp_sitestable_prepare_extra_view', array( $this, 'favicon_only' ) );

		$this->assertSame( array( $premium, $none ), $sorted );
	}

	/**
	 * Extra view without premium_upgrades.
	 *
	 * @return array
	 */
	public function favicon_only() {
		return array( 'favi_icon' );
	}

	/**
	 * Create a site and its sync row.
	 *
	 * @param string $name Site name.
	 * @param array  $site Site column values.
	 * @param array  $sync Sync column values.
	 *
	 * @return int Site ID.
	 */
	private function add_site( $name, $site = array(), $sync = array() ) {
		$site_id          = $this->create_test_site( array_merge( array( 'name' => $name ), $site ) );
		$this->site_ids[] = $site_id;

		if ( ! empty( $sync ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'mainwp_wp', $sync, array( 'id' => $site_id ) );
		}

		return $site_id;
	}

	/**
	 * Premium updates as sync stores them.
	 *
	 * @param array $items Slug => type.
	 *
	 * @return string JSON.
	 */
	private function premium_updates( $items ) {
		$updates = array();
		foreach ( $items as $slug => $type ) {
			$updates[ $slug ] = array(
				'Name'   => 'Premium ' . $slug,
				'type'   => $type,
				'update' => array(
					'new_version' => '2.0',
					'premium'     => true,
					'slug'        => $slug,
				),
			);
		}
		return wp_json_encode( $updates );
	}

	/**
	 * Regular plugin updates as sync stores them.
	 *
	 * @param int $count Number of updates.
	 *
	 * @return string JSON.
	 */
	private function regular_plugin_updates( $count ) {
		$updates = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$updates[ "plugin-{$i}/plugin-{$i}.php" ] = array(
				'Name'   => "Plugin {$i}",
				'update' => array( 'new_version' => '2.0' ),
			);
		}
		return wp_json_encode( $updates );
	}

	/**
	 * This case's site IDs in Manage Sites order when sorted by Updates.
	 *
	 * @param string $dir asc or desc.
	 *
	 * @return int[]
	 */
	private function sorted_site_ids( $dir ) {
		$_REQUEST['columns'] = array( array( 'data' => 'update' ) );
		$_REQUEST['order']   = array(
			array(
				'column' => '0',
				'dir'    => $dir,
			),
		);
		$_REQUEST['length']  = -1;
		wp_cache_flush();

		add_filter( 'mainwp_sitestable_getcolumns', array( $this, 'update_column_only' ) );
		$table = new MainWP_Manage_Sites_List_Table();
		$table->prepare_items( true );
		$output = $table->ajax_get_datatable_rows();

		$ids = array();
		foreach ( $output['rowsInfo'] as $info ) {
			if ( in_array( (int) $info['siteID'], $this->site_ids, true ) ) {
				$ids[] = (int) $info['siteID'];
			}
		}
		return $ids;
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
