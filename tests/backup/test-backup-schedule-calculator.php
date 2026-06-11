<?php
/**
 * MainWP Backup Schedule Calculator Tests
 *
 * The calculator turns a task's schedule fields into the next UTC run
 * timestamp. It is pure: the clock and timezone are injected, so every
 * case here is table-driven against fixed instants, including DST
 * transitions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Schedule_Calculator;

/**
 * Class MainWP_Backup_Schedule_Calculator_Test
 */
class MainWP_Backup_Schedule_Calculator_Test extends \WP_UnitTestCase {

	/**
	 * Build a task row object with schedule fields.
	 *
	 * @param array $fields Field overrides.
	 *
	 * @return object
	 */
	private function make_task( array $fields = array() ) {
		return (object) array_merge(
			array(
				'schedule'       => 'daily',
				'interval_hours' => 0,
				'time_of_day'    => '',
				'day_of_week'    => 0,
				'day_of_month'   => 1,
			),
			$fields
		);
	}

	/**
	 * A wall-clock moment in a timezone as a UTC timestamp.
	 *
	 * @param string $datetime Local date-time string.
	 * @param string $tz       Timezone identifier.
	 *
	 * @return int
	 */
	private function local_ts( $datetime, $tz ) {
		return ( new \DateTimeImmutable( $datetime, new \DateTimeZone( $tz ) ) )->getTimestamp();
	}

	/**
	 * Tasks without interval or time-of-day fields stay in legacy elapsed
	 * mode, signalled by a zero next_run.
	 *
	 * @return void
	 */
	public function test_legacy_mode_returns_zero() {
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-06-10 10:00:00', 'America/New_York' ) );

		foreach ( array( 'daily', 'weekly', 'monthly' ) as $schedule ) {
			$task = $this->make_task( array( 'schedule' => $schedule ) );
			$this->assertSame( 0, $calculator->compute_next_run( $task, 12345 ), "Legacy {$schedule} task must stay in elapsed mode." );
		}
	}

	/**
	 * An interval task that never ran is due immediately.
	 *
	 * @return void
	 */
	public function test_interval_mode_never_ran_is_due_now() {
		$now        = $this->local_ts( '2026-06-10 10:00:00', 'America/New_York' );
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $now );

		$task = $this->make_task(
			array(
				'schedule'       => 'interval',
				'interval_hours' => 4,
			)
		);
		$this->assertSame( $now, $calculator->compute_next_run( $task, null ) );
	}

	/**
	 * An interval task inside its window projects forward from last_run.
	 *
	 * @return void
	 */
	public function test_interval_mode_not_yet_due_projects_from_last_run() {
		$now        = $this->local_ts( '2026-06-10 10:00:00', 'America/New_York' );
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $now );

		$task = $this->make_task(
			array(
				'schedule'       => 'interval',
				'interval_hours' => 4,
			)
		);
		$this->assertSame( $now - HOUR_IN_SECONDS + 4 * HOUR_IN_SECONDS, $calculator->compute_next_run( $task, $now - HOUR_IN_SECONDS ) );
	}

	/**
	 * An interval task overdue by several periods lands on the next future
	 * slot, never a past one and never several at once.
	 *
	 * @return void
	 */
	public function test_interval_mode_overdue_advances_past_now() {
		$now        = $this->local_ts( '2026-06-10 10:00:00', 'America/New_York' );
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $now );

		$task = $this->make_task(
			array(
				'schedule'       => 'interval',
				'interval_hours' => 4,
			)
		);
		// 10 hours overdue: slots at -6h, -2h, +2h. The next future slot wins.
		$this->assertSame( $now + 2 * HOUR_IN_SECONDS, $calculator->compute_next_run( $task, $now - 10 * HOUR_IN_SECONDS ) );
	}

	/**
	 * A daily time-of-day still ahead today runs today.
	 *
	 * @return void
	 */
	public function test_daily_time_of_day_later_today_runs_today() {
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-06-10 10:00:00', 'America/New_York' ) );

		$task = $this->make_task( array( 'time_of_day' => '22:00' ) );
		$this->assertSame(
			$this->local_ts( '2026-06-10 22:00:00', 'America/New_York' ),
			$calculator->compute_next_run( $task, null )
		);
	}

	/**
	 * A daily time-of-day already past rolls to tomorrow.
	 *
	 * @return void
	 */
	public function test_daily_time_of_day_past_rolls_to_tomorrow() {
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-06-10 20:00:00', 'America/New_York' ) );

		$task = $this->make_task( array( 'time_of_day' => '06:00' ) );
		$this->assertSame(
			$this->local_ts( '2026-06-11 06:00:00', 'America/New_York' ),
			$calculator->compute_next_run( $task, null )
		);
	}

	/**
	 * A weekly schedule lands on the requested day of week.
	 *
	 * @return void
	 */
	public function test_weekly_lands_on_requested_day() {
		// 2026-06-08 is a Monday.
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-06-08 12:00:00', 'America/New_York' ) );

		$task = $this->make_task(
			array(
				'schedule'    => 'weekly',
				'time_of_day' => '09:00',
				'day_of_week' => 3, // Wednesday.
			)
		);
		$this->assertSame(
			$this->local_ts( '2026-06-10 09:00:00', 'America/New_York' ),
			$calculator->compute_next_run( $task, null )
		);
	}

	/**
	 * A weekly schedule whose slot already passed today rolls a full week.
	 *
	 * @return void
	 */
	public function test_weekly_same_day_past_time_rolls_a_week() {
		// 2026-06-08 is a Monday.
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-06-08 20:00:00', 'America/New_York' ) );

		$task = $this->make_task(
			array(
				'schedule'    => 'weekly',
				'time_of_day' => '06:00',
				'day_of_week' => 1, // Monday.
			)
		);
		$this->assertSame(
			$this->local_ts( '2026-06-15 06:00:00', 'America/New_York' ),
			$calculator->compute_next_run( $task, null )
		);
	}

	/**
	 * A monthly schedule ahead in the current month runs this month.
	 *
	 * @return void
	 */
	public function test_monthly_day_ahead_runs_this_month() {
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-06-05 12:00:00', 'America/New_York' ) );

		$task = $this->make_task(
			array(
				'schedule'     => 'monthly',
				'time_of_day'  => '03:00',
				'day_of_month' => 15,
			)
		);
		$this->assertSame(
			$this->local_ts( '2026-06-15 03:00:00', 'America/New_York' ),
			$calculator->compute_next_run( $task, null )
		);
	}

	/**
	 * A monthly schedule already past rolls to next month.
	 *
	 * @return void
	 */
	public function test_monthly_day_past_rolls_to_next_month() {
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-06-10 12:00:00', 'America/New_York' ) );

		$task = $this->make_task(
			array(
				'schedule'     => 'monthly',
				'time_of_day'  => '03:00',
				'day_of_month' => 5,
			)
		);
		$this->assertSame(
			$this->local_ts( '2026-07-05 03:00:00', 'America/New_York' ),
			$calculator->compute_next_run( $task, null )
		);
	}

	/**
	 * A day-of-month beyond the month's length caps at the last day instead
	 * of spilling into the next month.
	 *
	 * @return void
	 */
	public function test_monthly_day_caps_at_month_length() {
		// June has 30 days; a raw row could carry 31.
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-06-10 12:00:00', 'America/New_York' ) );

		$task = $this->make_task(
			array(
				'schedule'     => 'monthly',
				'time_of_day'  => '03:00',
				'day_of_month' => 31,
			)
		);
		$this->assertSame(
			$this->local_ts( '2026-06-30 03:00:00', 'America/New_York' ),
			$calculator->compute_next_run( $task, null )
		);
	}

	/**
	 * A time-of-day inside the spring-forward gap normalizes to a valid
	 * instant instead of failing; the run fires about an hour late once.
	 *
	 * @return void
	 */
	public function test_dst_spring_forward_gap_normalizes_forward() {
		// US DST began 2026-03-08 at 02:00 America/New_York; 02:30 does not exist.
		$calculator = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( 'America/New_York' ), $this->local_ts( '2026-03-08 00:30:00', 'America/New_York' ) );

		$task   = $this->make_task( array( 'time_of_day' => '02:30' ) );
		$result = $calculator->compute_next_run( $task, null );

		$this->assertSame(
			$this->local_ts( '2026-03-08 03:30:00', 'America/New_York' ),
			$result,
			'The non-existent wall-clock time must normalize forward to 03:30 EDT.'
		);
	}

	/**
	 * Across the fall-back transition the schedule advances exactly one wall
	 * clock day (25 elapsed hours), never firing twice in the repeated hour.
	 *
	 * @return void
	 */
	public function test_dst_fall_back_advances_one_wall_clock_day() {
		// US DST ended 2026-11-01 at 02:00 America/New_York; 01:30 happens twice.
		$tz    = new \DateTimeZone( 'America/New_York' );
		$task  = $this->make_task( array( 'time_of_day' => '01:30' ) );
		$now   = $this->local_ts( '2026-10-31 23:00:00', 'America/New_York' );
		$first = ( new MainWP_Backup_Schedule_Calculator( $tz, $now ) )->compute_next_run( $task, null );

		$this->assertGreaterThan( $now, $first );

		// Recompute as the cron would right after the run fires.
		$second = ( new MainWP_Backup_Schedule_Calculator( $tz, $first ) )->compute_next_run( $task, $first );

		$this->assertSame(
			25 * HOUR_IN_SECONDS,
			$second - $first,
			'The next 01:30 wall-clock slot is 25 elapsed hours away across fall-back; anything else double-fires or skips.'
		);
	}

	/**
	 * The same wall-clock time in different site timezones produces
	 * different UTC instants.
	 *
	 * @return void
	 */
	public function test_timezone_is_honored() {
		$now     = $this->local_ts( '2026-06-10 00:00:00', 'UTC' );
		$task    = $this->make_task( array( 'time_of_day' => '06:00' ) );
		$results = array();

		foreach ( array( 'UTC', 'Asia/Karachi', 'America/Los_Angeles' ) as $tz ) {
			$calculator      = new MainWP_Backup_Schedule_Calculator( new \DateTimeZone( $tz ), $now );
			$results[ $tz ] = $calculator->compute_next_run( $task, null );
			$this->assertSame(
				$this->local_ts( '2026-06-10 06:00:00', $tz ),
				$results[ $tz ],
				"06:00 must mean 06:00 in {$tz}."
			);
		}

		$this->assertCount( 3, array_unique( $results ), 'Different timezones must yield different UTC instants.' );
	}
}
