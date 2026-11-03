<?php
/**
 * Insights bar charts show each name with its own count.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\Module\Log\Log_Graph_Tags_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Themes_Widget;

/**
 * Covers theme counts and tag name and count pairing.
 */
class Test_Insights_Chart_Counts extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Give each widget an administrator and an admin screen.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'dashboard' );
	}

	/**
	 * Restore the current user after rendering.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Each theme's bar equals the number of sites with it active.
	 */
	public function test_theme_counts_match_active_sites(): void {
		foreach ( array( 'Astra', 'Astra', 'Astra', 'Kadence' ) as $theme ) {
			$site_id = $this->create_test_site();
			$this->set_site_option( $site_id, 'site_info', wp_json_encode( array( 'themeactivated' => $theme ) ) );
		}

		$series = $this->render_series( Log_Graph_Themes_Widget::instance() );
		$this->assertSame( array( 'Sites' ), array_keys( $series ) );
		$this->assertCount( 2, $series['Sites'], 'Each theme must have one bar.' );

		// Themes follow site URL order, which is random in these fixtures.
		$counts = array_column( $series['Sites'], 1, 0 );
		ksort( $counts );
		$this->assertSame(
			array(
				'Astra'   => 3,
				'Kadence' => 1,
			),
			$counts
		);
	}

	/**
	 * Tag names stay on their own counts when name order differs from ID order.
	 */
	public function test_tag_names_keep_their_own_counts(): void {
		$zeta    = $this->create_tag( 'Zeta' );
		$alpha   = $this->create_tag( 'Alpha' );
		$beta    = $this->create_tag( 'Beta' );
		$staging = $this->create_tag( 'Staging Sites' );
		$this->assertLessThan( $alpha, $zeta, 'Zeta needs the lower ID so name order differs from ID order.' );

		$first = $this->create_client( 'First client', 1 );
		$site  = $this->create_test_site( array( 'client_id' => $first->client_id ) );
		MainWP_DB_Common::instance()->update_group_site( $zeta, $site );
		MainWP_DB_Common::instance()->update_group_site( $alpha, $site );
		MainWP_DB_Common::instance()->update_group_site( $staging, $site );

		$second = $this->create_client( 'Second client', 2 );
		$site   = $this->create_test_site( array( 'client_id' => $second->client_id ) );
		MainWP_DB_Common::instance()->update_group_site( $zeta, $site );

		// A tag with sites but no clients must still line up with the Sites bars.
		MainWP_DB_Common::instance()->update_group_site( $beta, $this->create_test_site() );

		$series = $this->render_series( Log_Graph_Tags_Widget::instance() );

		$this->assertSame(
			array(
				'Sites'   => array(
					array( 'Alpha', 1 ),
					array( 'Beta', 1 ),
					array( 'Zeta', 2 ),
				),
				'Clients' => array(
					array( 'Alpha', 1 ),
					array( 'Beta', 0 ),
					array( 'Zeta', 2 ),
				),
			),
			$series
		);
	}

	/**
	 * A client whose sites share a tag counts once for that tag.
	 */
	public function test_tag_client_count_counts_each_client_once(): void {
		$tag    = $this->create_tag( 'Shared' );
		$client = $this->create_client( 'Two-site client', 1 );
		MainWP_DB_Common::instance()->update_group_site( $tag, $this->create_test_site( array( 'client_id' => $client->client_id ) ) );
		MainWP_DB_Common::instance()->update_group_site( $tag, $this->create_test_site( array( 'client_id' => $client->client_id ) ) );

		$series = $this->render_series( Log_Graph_Tags_Widget::instance() );

		$this->assertSame( array( array( 'Shared', 2 ) ), $series['Sites'] );
		$this->assertSame( array( array( 'Shared', 1 ) ), $series['Clients'] );
	}

	/**
	 * Create a tag owned by the current user.
	 *
	 * @param string $name Tag name.
	 * @return int Tag ID.
	 */
	private function create_tag( $name ) {
		$tag = MainWP_DB_Common::instance()->add_group( get_current_user_id(), $name );
		$this->assertGreaterThan( 0, $tag );
		return (int) $tag;
	}

	/**
	 * Create a client with a unique email within each test.
	 *
	 * @param string $name  Client name.
	 * @param int    $index Fixture index.
	 * @return object Stored client.
	 */
	private function create_client( $name, $index ) {
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => $name,
				'client_email' => 'chart-count-' . $index . '@example.com',
			),
			true
		);
		$this->assertIsObject( $client );
		return $client;
	}

	/**
	 * Render a widget and return its chart script.
	 *
	 * @param object $widget Widget renderer.
	 * @return string Script body.
	 */
	private function render_script( $widget ) {
		ob_start();
		try {
			$widget->render_widget_content();
		} finally {
			$html = ob_get_clean();
		}

		$this->assertSame( 1, preg_match( '~<script\b[^>]*>(.*?)</script\s*>~is', $html, $scripts ) );
		return $scripts[1];
	}

	/**
	 * Read each series as name => list of [label, count] in chart order.
	 *
	 * @param object $widget Widget renderer.
	 * @return array[] Series points.
	 */
	private function render_series( $widget ) {
		$script = $this->render_script( $widget );
		preg_match_all( "/name:\s*'([^']+)',\s*data:\s*\[(.*?)\]\s*\}/s", $script, $matches, PREG_SET_ORDER );
		$this->assertNotEmpty( $matches, 'The chart must have at least one series.' );

		$series = array();
		foreach ( $matches as $match ) {
			preg_match_all( '/^\s*x:\s*(.+),\s*\R\s*y:\s*(\d+),/m', $match[2], $points, PREG_SET_ORDER );
			$series[ $match[1] ] = array();
			foreach ( $points as $point ) {
				$series[ $match[1] ][] = array( json_decode( $point[1] ), (int) $point[2] );
			}
		}
		return $series;
	}
}
