<?php
/**
 * Uptime response time chart tests (MWP-2103).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Tests that grouped chart points show the average response time, not the sum.
 */
class Test_Uptime_Response_Time_Chart extends \WP_UnitTestCase {

	/**
	 * Site ID.
	 *
	 * @var int
	 */
	protected $site_id = 0;

	/**
	 * Set up a site with one monitor and three checks on each of two days.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			array(
				'userid'    => 1,
				'url'       => 'https://test-resp-' . wp_generate_uuid4() . '.example.com/',
				'name'      => 'Test Site',
				'adminname' => 'admin',
				'pubkey'    => 'test-pubkey',
				'privkey'   => 'test-privkey',
			)
		);
		$this->site_id = (int) $wpdb->insert_id;

		$monitor_id = MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor(
			array(
				'wpid'  => $this->site_id,
				'issub' => 0,
			)
		);

		$checks = array(
			'2026-10-01 01:00:00' => 100,
			'2026-10-01 02:00:00' => 200,
			'2026-10-01 03:00:00' => 300,
			'2026-10-02 01:00:00' => 400,
			'2026-10-02 02:00:00' => 500,
			'2026-10-02 03:00:00' => 600,
		);
		foreach ( $checks as $time => $ping_ms ) {
			$wpdb->insert(
				$wpdb->prefix . 'mainwp_monitor_heartbeat',
				array(
					'monitor_id' => $monitor_id,
					'msg'        => '',
					'status'     => 1,
					'time'       => $time,
					'ping_ms'    => $ping_ms,
				)
			);
		}
	}

	/**
	 * Get the plotted values for a grouping.
	 *
	 * @param string $group_time_by Grouping passed by the widget.
	 *
	 * @return array
	 */
	protected function chart_values( $group_time_by ) {
		$result = MainWP_Uptime_Monitoring_Handle::instance()->get_site_response_time_chart_data(
			$this->site_id,
			array(
				'start'         => '2026-10-01',
				'end'           => '2026-10-02',
				'group_time_by' => $group_time_by,
			)
		);
		return array_column( $result['response_time_data_lists'], 'value' );
	}

	/**
	 * Day ranges (7/30/365 days) plot the daily average, in line with the Average card.
	 *
	 * @return void
	 */
	public function test_date_grouping_plots_the_daily_average() {
		$this->assertSame( array( '0.20', '0.50' ), $this->chart_values( 'date' ) );

		$result = MainWP_Uptime_Monitoring_Handle::instance()->get_site_response_time_chart_data(
			$this->site_id,
			array(
				'start'         => '2026-10-01',
				'end'           => '2026-10-02',
				'group_time_by' => 'date',
			)
		);
		$this->assertSame( '0.35', $result['resp_stats']['avg_resp_time'] );
		$this->assertSame( '0.10', $result['resp_stats']['min_resp_time'] );
		$this->assertSame( '0.60', $result['resp_stats']['max_resp_time'] );
	}

	/**
	 * Hour grouping (used by the 7-day view for hourly monitors) plots the average too.
	 *
	 * @return void
	 */
	public function test_hour_grouping_plots_the_average() {
		$this->assertSame( array( '0.25', '0.35', '0.45' ), $this->chart_values( 'hour' ) );
	}

	/**
	 * The ungrouped 24-hour view still plots each check.
	 *
	 * @return void
	 */
	public function test_get_all_plots_each_check() {
		$this->assertSame( array( '0.10', '0.20', '0.30', '0.40', '0.50', '0.60' ), $this->chart_values( 'get_all' ) );
	}
}
