<?php
/**
 * Insights chart legends match their bars and leave the clients pie room to draw.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\Module\Log\Log_Graph_Clients_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Php_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Plugins_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Status_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_Tags_Widget;
use MainWP\Dashboard\Module\Log\Log_Graph_WP_Widget;

/**
 * Covers the legend and layout options printed by the Insights chart widgets.
 */
class Test_Insights_Chart_Legends extends \WP_UnitTestCase {

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
	 * The Sites and Clients legend swatches use the bar colors, and the legend text follows the theme.
	 */
	public function test_tag_chart_legend_uses_the_bar_colors(): void {
		$client   = $this->create_client( 'Legend client' );
		$site_id  = $this->create_test_site( array( 'client_id' => $client->client_id ) );
		$group_id = MainWP_DB_Common::instance()->add_group( get_current_user_id(), 'Legend tag' );
		$this->assertGreaterThan( 0, $group_id );
		MainWP_DB_Common::instance()->update_group_site( $group_id, $site_id );

		$script = $this->render_script( Log_Graph_Tags_Widget::instance() );

		$this->assert_series_colors( array( 'Sites', 'Clients' ), array( '#18a4e0', '#7fb100' ), $script );
		$this->assert_legend_follows_theme_text( $script );
	}

	/**
	 * The Active and Inactive Plugins legend swatches use the bar colors, and the legend text follows the theme.
	 */
	public function test_plugin_chart_legend_uses_the_bar_colors(): void {
		$this->create_test_site(
			array(
				'plugins' => wp_json_encode(
					array(
						'active/active.php'     => array( 'active' => 1 ),
						'inactive/inactive.php' => array( 'active' => 0 ),
					)
				),
			)
		);

		$script = $this->render_script( Log_Graph_Plugins_Widget::instance() );

		$this->assert_series_colors( array( 'Active Plugins', 'Inactive Plugins' ), array( '#18a4e0', '#00e396' ), $script );
		$this->assert_legend_follows_theme_text( $script );
	}

	/**
	 * Long client names go to a shortened legend under the pie, and the tooltip stays inside the chart.
	 */
	public function test_client_pie_keeps_its_size_with_long_names(): void {
		$client = $this->create_client( str_repeat( 'Northwind Traders International ', 3 ) );
		$this->create_test_site( array( 'client_id' => $client->client_id ) );

		$script = $this->render_script( Log_Graph_Clients_Widget::instance() );

		$this->assertSame( 1, preg_match( '/\blegend:\s*\{(.*?)\n\s{20}\},/s', $script, $legend ), 'The pie must have a legend block.' );
		$this->assertMatchesRegularExpression( "/\bposition:\s*'bottom'/", $legend[1], 'A side legend squeezes the pie when names are long.' );
		$this->assertMatchesRegularExpression(
			"/\bchart:\s*\{\s*type:\s*'pie',.*?\bheight:\s*'100%',/s",
			$script,
			'A fixed-height pie pushes its bottom legend below the widget fold.'
		);
		$this->assertMatchesRegularExpression(
			'/formatter:\s*function\s*\(\s*name\s*\)\s*\{\s*let chars = Array\.from\(\s*String\(\s*name\s*\)\s*\);\s*return mainwp_escape_html\(\s*chars\.length > 30 \? chars\.slice\(\s*0,\s*29\s*\)\.join\(\s*\'\'\s*\) \+ \'\\\\u2026\' : name\s*\);\s*\}/',
			$legend[1],
			'The legend must shorten names by character before escaping them.'
		);
		$this->assertMatchesRegularExpression(
			"/\btooltip:\s*\{.*?\bfixed:\s*\{\s*enabled:\s*true,\s*position:\s*'topLeft'\s*\}/s",
			$script,
			'A cursor-centred tooltip runs past the widget edge with a long name.'
		);
		$this->assertMatchesRegularExpression(
			'/\bplotOptions:\s*\{\s*pie:\s*\{\s*dataLabels:\s*\{\s*minAngleToShowLabel:\s*25\s*\}/',
			$script,
			'Thin slices must not print overlapping percentage labels.'
		);
	}

	/**
	 * The pie legends take the widget's text color instead of a fixed grey.
	 *
	 * @dataProvider pie_widgets
	 *
	 * @param string $widget_class Pie widget class.
	 */
	public function test_pie_legend_follows_theme_text( string $widget_class ): void {
		$client = $this->create_client( 'Pie legend client' );
		$this->create_test_site( array( 'client_id' => $client->client_id ) );

		$script = $this->render_script( $widget_class::instance() );

		$this->assertSame( 1, preg_match( '/\blegend:\s*\{(.*?)\n\s{20}\},/s', $script, $legend ), 'The pie must have a legend block.' );
		$this->assertMatchesRegularExpression( "/\blabels:\s*\{\s*colors:\s*'inherit',?\s*\}/", $legend[1] );
		$this->assertStringNotContainsString( '#999', $legend[1], 'A fixed grey legend is hard to read in light and dark themes.' );
	}

	/**
	 * Insights pie widgets.
	 *
	 * @return array<string, string[]>
	 */
	public static function pie_widgets(): array {
		return array(
			'php'     => array( Log_Graph_Php_Widget::class ),
			'wp'      => array( Log_Graph_WP_Widget::class ),
			'status'  => array( Log_Graph_Status_Widget::class ),
			'clients' => array( Log_Graph_Clients_Widget::class ),
		);
	}

	/**
	 * A client with no sites has no slice to hover, so its shortened legend name keeps the full name as a title.
	 */
	public function test_client_legend_keeps_the_full_name_in_a_title(): void {
		$this->create_client( str_repeat( 'Contoso Holdings Worldwide ', 3 ) );

		$script = $this->render_script( Log_Graph_Clients_Widget::instance() );

		$this->assertMatchesRegularExpression(
			'/\bevents:\s*\{\s*mounted:\s*set_legend_titles,\s*updated:\s*set_legend_titles,?\s*\}/',
			$script,
			'ApexCharts rebuilds the legend on every update, so the titles must be set again.'
		);
		$this->assertMatchesRegularExpression(
			"/querySelectorAll\(\s*'\.apexcharts-legend-series'\s*\)/",
			$script,
			'ApexCharts turns off pointer events inside the legend text, so the title must sit on the legend item.'
		);
		$this->assertMatchesRegularExpression(
			'/Array\.from\(\s*String\(\s*point\.x\s*\)\s*\)\.length > 30\s*\)\s*\{\s*item\.title = String\(\s*point\.x\s*\);/',
			$script,
			'Only a shortened name needs a title, and a DOM property keeps the name out of HTML parsing.'
		);
		$this->assertStringNotContainsString( 'title="', $script, 'A title attribute inside the legend text is never under the pointer.' );
	}

	/**
	 * Thin slices print no percentage label, so the tooltip adds the percentage to the site count.
	 */
	public function test_client_tooltip_shows_the_slice_percentage(): void {
		$client = $this->create_client( 'Percent client' );
		$this->create_test_site( array( 'client_id' => $client->client_id ) );

		$script = $this->render_script( Log_Graph_Clients_Widget::instance() );

		$this->assertMatchesRegularExpression(
			"/\btooltip:\s*\{.*?\by:\s*\{.*?\bformatter:\s*function\s*\(\s*value,\s*opts\s*\)\s*\{\s*let globals = \(\s*opts\.w \|\| opts\s*\)\.globals;\s*let percent = globals \? globals\.seriesPercent\[\s*opts\.seriesIndex\s*\] : null;\s*percent = percent \? Number\(\s*percent\[0\]\s*\) : NaN;\s*return isFinite\(\s*percent\s*\) \? value \+ ' \(' \+ percent\.toFixed\(\s*1\s*\) \+ '%\)' : value;\s*\}/s",
			$script,
			'The tooltip must read the pie globals from opts itself, add the slice percentage, and fall back to the count when every client has no sites.'
		);
	}

	/**
	 * Create a client with a unique email.
	 *
	 * @param string $name Client name.
	 * @return object Stored client.
	 */
	private function create_client( $name ) {
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => $name,
				'client_email' => 'legend-' . wp_generate_password( 8, false ) . '@example.com',
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
	private function render_script( $widget ): string {
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
	 * ApexCharts draws legend swatches from the chart colors, so per-point colors would split bars from their legend.
	 *
	 * @param string[] $series Series names in order.
	 * @param string[] $colors Expected color for each series.
	 * @param string   $script Chart script.
	 */
	private function assert_series_colors( array $series, array $colors, string $script ): void {
		preg_match_all( "/^\s*name:\s*'([^']+)',/m", $script, $names );
		$this->assertSame( $series, $names[1] );
		$this->assertStringContainsString( 'x:', $script, 'The fixture must produce data points.' );

		$this->assertSame( 1, preg_match( '/^\s*colors:\s*\[([^\]]*)\],/m', $script, $list ), 'The chart must set one color per series.' );
		preg_match_all( "/'(#[0-9a-f]{6})'/", $list[1], $found );
		$this->assertSame( $colors, $found[1] );
		$this->assertStringNotContainsString( 'fillColor', $script, 'A per-point color would not reach the legend swatch.' );
	}

	/**
	 * ApexCharts' default legend text is dark grey; inherit takes the widget's text color in light and dark themes.
	 *
	 * @param string $script Chart script.
	 */
	private function assert_legend_follows_theme_text( string $script ): void {
		$this->assertMatchesRegularExpression( "/\blegend:\s*\{\s*labels:\s*\{\s*colors:\s*'inherit',?\s*\}/", $script );
	}
}
