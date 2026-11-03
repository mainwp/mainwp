<?php
/**
 * Uptime alert email event timestamp formatting.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Utility;

/** Covers MainWP_Utility::format_timezone() output used by the uptime alert email and the logs module. */
class Test_MWP_2115_Uptime_Email_Timestamp extends \WP_UnitTestCase {

	/** 2026-11-01 16:05:00 UTC, 11:05 AM in New York. */
	const EVENT_TIME = 1793549100;

	/** Sets the Dashboard date, time and timezone settings used by every test. */
	public function set_up() {
		parent::set_up();
		update_option( 'date_format', 'D, d M Y' );
		update_option( 'time_format', 'h:i A' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'gmt_offset', 0 );
	}

	/** The email timestamp is the formatted date followed by the timezone name. */
	public function test_timezone_info_is_appended_after_the_formatted_date() {
		$this->assertSame(
			'Sun, 01 Nov 2026 11:05 AM ( America/New_York )',
			MainWP_Utility::format_timezone( self::EVENT_TIME, true )
		);
	}

	/** Callers that don't ask for timezone info keep the plain date and time. */
	public function test_without_timezone_info_is_unchanged() {
		$this->assertSame( 'Sun, 01 Nov 2026 11:05 AM', MainWP_Utility::format_timezone( self::EVENT_TIME ) );
	}

	/** A Dashboard set to a UTC offset keeps its existing output. */
	public function test_gmt_offset_path_is_unchanged() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -5 );

		$this->assertSame(
			'Sun, 01 Nov 2026 11:05 AM ( UTC -5 )',
			MainWP_Utility::format_timezone( self::EVENT_TIME, true )
		);
	}

	/** The child site format used by the logs module keeps its existing output. */
	public function test_child_site_format_is_unchanged() {
		$child = array(
			'timezone_string' => 'Europe/Paris',
			'date_format'     => 'Y-m-d',
			'time_format'     => 'H:i',
		);

		$this->assertSame( '2026-11-01 17:05', MainWP_Utility::format_timezone( self::EVENT_TIME, false, $child ) );
	}
}
