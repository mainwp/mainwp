<?php
/**
 * Insights chart labels preserve stored names without opening HTML sinks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\Module\Log\Log_Graph_Clients_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Plugins_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Tags_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Themes_Widget;

/**
 * Covers JSON labels and tooltip escaping in the rendered chart scripts.
 */
class Test_Insights_Chart_Label_Escaping extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Names that exercise entity encoding, script boundaries and numeric keys.
	 *
	 * @var string[]
	 */
	private const NAMES = array(
		'Bramble & Bee Florist',
		"Kevin's Kitchen",
		'</script><script>alert(1)</script>',
		'2024',
	);

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
	 * Both plugin series preserve site names.
	 */
	public function test_plugin_labels_preserve_site_names(): void {
		foreach ( self::NAMES as $name ) {
			$this->create_test_site(
				array(
					'name'    => $name,
					'plugins' => wp_json_encode(
						array(
							'active/active.php'     => array( 'active' => 1 ),
							'inactive/inactive.php' => array( 'active' => 0 ),
						)
					),
				)
			);
		}

		$this->assert_chart_labels( Log_Graph_Plugins_Widget::instance(), 2, true );
	}

	/**
	 * Client labels preserve names from stored client records.
	 */
	public function test_client_labels_preserve_client_names(): void {
		foreach ( self::NAMES as $index => $name ) {
			$client = $this->create_client( $name, $index );
			$this->create_test_site( array( 'client_id' => $client->client_id ) );
		}

		$this->assert_chart_labels( Log_Graph_Clients_Widget::instance(), 1, false );
	}

	/**
	 * Both the Sites and Clients series preserve stored tag names.
	 */
	public function test_tag_labels_preserve_group_names(): void {
		global $wpdb;

		foreach ( self::NAMES as $index => $name ) {
			$client   = $this->create_client( 'Tagged client ' . $index, $index );
			$site_id  = $this->create_test_site( array( 'client_id' => $client->client_id ) );
			$group_id = MainWP_DB_Common::instance()->add_group( get_current_user_id(), 'Chart tag ' . $index );
			$this->assertGreaterThan( 0, $group_id );
			// Store exact names to cover legacy values without write-path normalization.
			$this->assertSame(
				1,
				$wpdb->update( $wpdb->prefix . 'mainwp_group', array( 'name' => $name ), array( 'id' => $group_id ) )
			);
			MainWP_DB_Common::instance()->update_group_site( $group_id, $site_id );
		}

		$this->assert_chart_labels( Log_Graph_Tags_Widget::instance(), 2, true );
	}

	/**
	 * Theme labels preserve names read from each site's JSON options.
	 */
	public function test_theme_labels_preserve_activated_theme_names(): void {
		foreach ( self::NAMES as $name ) {
			$site_id = $this->create_test_site();
			$this->set_site_option( $site_id, 'site_info', wp_json_encode( array( 'themeactivated' => $name ) ) );
		}

		$this->assert_chart_labels( Log_Graph_Themes_Widget::instance(), 1, true );
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
				'client_email' => 'chart-' . $index . '@example.com',
			),
			true
		);
		$this->assertIsObject( $client );
		return $client;
	}

	/**
	 * Decode every data label from the rendered script and check each series.
	 *
	 * @param object $widget       Widget renderer.
	 * @param int    $series_count Number of series containing the fixture names.
	 * @param bool   $is_bar       Whether the chart has an HTML tooltip title.
	 */
	private function assert_chart_labels( $widget, $series_count, $is_bar ): void {
		ob_start();
		try {
			$widget->render_widget_content();
		} finally {
			$html = ob_get_clean();
		}

		$this->assertSame( 1, preg_match_all( '/<script\b/i', $html ), 'Names must not open another script.' );
		$this->assertSame( 1, preg_match_all( '~</script\s*>~i', $html ), 'Names must not close the widget script.' );
		$this->assertSame( 1, preg_match( '~<script\b[^>]*>(.*?)</script\s*>~is', $html, $scripts ) );
		$script = $scripts[1];
		preg_match_all( '/^\s*x:\s*(.+),\s*\R\s*y:/m', $script, $tokens );
		$this->assertCount( count( self::NAMES ) * $series_count, $tokens[1], 'Every series must contain every fixture label.' );

		$labels = array();
		foreach ( $tokens[1] as $token ) {
			$label = json_decode( $token );
			$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'Invalid JSON label: ' . $token );
			$this->assertIsString( $label, 'Numeric names must remain strings.' );
			$this->assertNotSame( '"', substr( $label, -1 ), 'Plugin labels must not gain a trailing quote.' );
			$this->assertStringNotContainsString( '&amp;', $token );
			$this->assertStringNotContainsString( '&#039;', $token );
			$labels[] = $label;
		}

		foreach ( array_chunk( $labels, count( self::NAMES ) ) as $series_labels ) {
			foreach ( self::NAMES as $name ) {
				$this->assertContains( $name, $series_labels, 'Each series must preserve the exact stored name.' );
			}
		}

		if ( $is_bar ) {
			$this->assertMatchesRegularExpression(
				'/tooltip:\s*\{.*?\bx:\s*\{\s*formatter:\s*function\s*\(\s*value\s*\)\s*\{\s*return mainwp_escape_html\(\s*value\s*\);\s*\}/s',
				$script,
				'The HTML tooltip title must escape the decoded label.'
			);
		} else {
			$this->assertMatchesRegularExpression(
				'/legend:\s*\{[^{}]*?\bformatter:\s*function\s*\(\s*name\s*\)\s*\{[^{}]*?\breturn mainwp_escape_html\([^{};]*\);\s*\}/s',
				$script,
				'The pie legend must escape client names inserted as HTML.'
			);
			$this->assertMatchesRegularExpression(
				'/tooltip:\s*\{[^{}]*\by:\s*\{\s*title:\s*\{\s*formatter:\s*function\s*\(\s*name\s*\)\s*\{\s*return name \? mainwp_escape_html\(\s*name\s*\) \+ \': \' : \'\';\s*\}/s',
				$script,
				'The pie tooltip series title must escape client names and keep the default "name: " separator.'
			);
		}
	}
}
