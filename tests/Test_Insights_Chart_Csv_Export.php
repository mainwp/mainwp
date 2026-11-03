<?php
/**
 * Insights bar chart CSV downloads keep names intact and inert.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\Module\Log\Log_Graph_Csv_Labels;
use MainWP\Dashboard\Module\Log\Log_Graph_Plugins_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Tags_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Themes_Widget;

/**
 * Covers CSV fields and the export formatter in each bar chart.
 */
class Test_Insights_Chart_Csv_Export extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Names that ApexCharts would write as a formula or split across fields.
	 *
	 * @var string[]
	 */
	private const CSV_NAMES = array( '=SUM(1,2)', 'a,"b"' );

	/**
	 * CSV map entries expected for CSV_NAMES: the label ApexCharts passes, then the field.
	 *
	 * @var array[]
	 */
	private const CSV_ENTRIES = array(
		array( '=SUM(12)', '"\'=SUM(1,2)"' ),
		array( 'a"b"', '"a,""b"""' ),
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
	 * Each formula prefix is written as text in the CSV.
	 *
	 * @dataProvider formula_names
	 *
	 * @param string $name     Name as typed.
	 * @param string $expected CSV field.
	 */
	public function test_csv_cell_neutralizes_formula_prefix( $name, $expected ): void {
		$this->assertSame( $expected, Log_Graph_Csv_Labels::get_csv_cell( $name ) );
	}

	/**
	 * Names that start with a formula character.
	 *
	 * @return array[]
	 */
	public function formula_names(): array {
		return array(
			'equals' => array( '=SUM(1,2)', '"\'=SUM(1,2)"' ),
			'plus'   => array( '+1', '"\'+1"' ),
			'minus'  => array( '-1', '"\'-1"' ),
			'at'     => array( '@x', '"\'@x"' ),
			'tab'    => array( "\tx", "\"'\tx\"" ),
			'cr'     => array( "\rx", "\"'\rx\"" ),
			'lf'     => array( "\n=1", "\"'\n=1\"" ),
		);
	}

	/**
	 * Other names are quoted and otherwise written as typed.
	 *
	 * @dataProvider plain_names
	 *
	 * @param string $name     Name as typed.
	 * @param string $expected CSV field.
	 */
	public function test_csv_cell_quotes_plain_names( $name, $expected ): void {
		$this->assertSame( $expected, Log_Graph_Csv_Labels::get_csv_cell( $name ) );
	}

	/**
	 * Names without a formula prefix.
	 *
	 * @return array[]
	 */
	public function plain_names(): array {
		return array(
			'comma and quotes' => array( 'a,"b"', '"a,""b"""' ),
			'newline'          => array( "two\nlines", "\"two\nlines\"" ),
			'inner equals'     => array( 'a=b', '"a=b"' ),
			'numeric'          => array( '2024', '"2024"' ),
			'entity'           => array( 'Bramble &amp; Bee', '"Bramble &amp; Bee"' ),
			'empty'            => array( '', '""' ),
		);
	}

	/**
	 * Entries are keyed by the label ApexCharts passes after it drops commas.
	 */
	public function test_csv_categories_key_by_label_without_commas(): void {
		$this->assertSame(
			array_merge( self::CSV_ENTRIES, array( array( 'Plain', '"Plain"' ) ) ),
			Log_Graph_Csv_Labels::get_csv_categories( array_merge( self::CSV_NAMES, array( 'Plain', 'Plain' ) ) )
		);
	}

	/**
	 * Names that differ only by commas fall back to the label ApexCharts passes.
	 */
	public function test_csv_categories_ambiguous_names_use_the_label(): void {
		$this->assertSame(
			array( array( 'ab', '"ab"' ) ),
			Log_Graph_Csv_Labels::get_csv_categories( array( 'a,b', 'ab' ) )
		);
		$this->assertSame(
			array( array( '=1', '"\'=1"' ) ),
			Log_Graph_Csv_Labels::get_csv_categories( array( '=1', '=,1' ) )
		);
	}

	/**
	 * The themes chart CSV writes theme names through the safe map.
	 */
	public function test_themes_chart_csv_uses_safe_names(): void {
		foreach ( self::CSV_NAMES as $name ) {
			$site_id = $this->create_test_site();
			$this->set_site_option( $site_id, 'site_info', wp_json_encode( array( 'themeactivated' => $name ) ) );
		}

		$this->assert_csv_entries( Log_Graph_Themes_Widget::instance() );
	}

	/**
	 * The tags chart CSV writes tag names through the safe map.
	 */
	public function test_tags_chart_csv_uses_safe_names(): void {
		global $wpdb;

		foreach ( self::CSV_NAMES as $name ) {
			$tag = MainWP_DB_Common::instance()->add_group( get_current_user_id(), 'CSV tag ' . md5( $name ) );
			$this->assertGreaterThan( 0, $tag );
			// Store exact names to cover values without write-path normalization.
			$this->assertSame( 1, $wpdb->update( $wpdb->prefix . 'mainwp_group', array( 'name' => $name ), array( 'id' => $tag ) ) );
			MainWP_DB_Common::instance()->update_group_site( $tag, $this->create_test_site() );
		}

		$this->assert_csv_entries( Log_Graph_Tags_Widget::instance() );
	}

	/**
	 * The plugins chart CSV writes site names through the safe map.
	 */
	public function test_plugins_chart_csv_uses_safe_names(): void {
		foreach ( self::CSV_NAMES as $name ) {
			$this->create_test_site(
				array(
					'name'    => $name,
					'plugins' => wp_json_encode( array( 'active/active.php' => array( 'active' => 1 ) ) ),
				)
			);
		}

		$this->assert_csv_entries( Log_Graph_Plugins_Widget::instance() );
	}

	/**
	 * The chart's CSV formatter reads the expected entries for CSV_NAMES.
	 *
	 * @param object $widget Widget renderer.
	 */
	private function assert_csv_entries( $widget ): void {
		ob_start();
		try {
			$widget->render_widget_content();
		} finally {
			$html = ob_get_clean();
		}
		$this->assertSame( 1, preg_match( '~<script\b[^>]*>(.*?)</script\s*>~is', $html, $scripts ) );
		$script = $scripts[1];

		$this->assertSame( 1, preg_match( '/^\s*let csvCategories = new Map\( (.+) \);$/m', $script, $map ), 'The chart must define its CSV map.' );
		$entries = json_decode( $map[1], true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'Invalid CSV map JSON.' );
		foreach ( self::CSV_ENTRIES as $entry ) {
			$this->assertContains( $entry, $entries );
		}
		$this->assertMatchesRegularExpression(
			'/toolbar:\s*\{\s*export:\s*\{\s*csv:\s*\{\s*categoryFormatter:\s*function\s*\(\s*name\s*\)\s*\{[^}]*csvCategories\.get\(/s',
			$script,
			'The CSV export must format names through the map.'
		);
	}
}
