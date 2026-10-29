<?php
/**
 * MainWP Backup Schedule Calculator.
 *
 * Turns a backup task's schedule fields into the next UTC run timestamp.
 * Interval tasks project forward from their last run in elapsed hours;
 * daily, weekly and monthly tasks with a time of day resolve the next
 * wall-clock slot in the site timezone. Tasks with neither stay in the
 * legacy elapsed mode, signalled by a zero, and keep being selected by
 * the original threshold queries.
 *
 * All wall-clock math runs through DateTimeImmutable in the injected
 * timezone, so DST transitions behave predictably: a time of day inside
 * the spring-forward gap normalizes forward and fires about an hour late
 * once, and the fall-back repeated hour cannot double-fire because the
 * result is a single UTC instant recomputed from the last run.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Schedule_Calculator
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Schedule_Calculator { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Site timezone the wall-clock fields are interpreted in.
     *
     * @var \DateTimeZone
     */
    private $timezone;

    /**
     * Current UTC timestamp, injected so calculations are testable.
     *
     * @var int
     */
    private $now;

    /**
     * Constructor.
     *
     * @param \DateTimeZone $timezone Site timezone, usually wp_timezone().
     * @param int           $now      Current UTC timestamp.
     */
    public function __construct( \DateTimeZone $timezone, int $now ) {
        $this->timezone = $timezone;
        $this->now      = $now;
    }

    /**
     * Compute the next UTC run timestamp for a task.
     *
     * A positive interval_hours selects interval mode. Otherwise a
     * non-empty time_of_day selects wall-clock mode for the task's
     * daily, weekly or monthly schedule. Tasks with neither return zero,
     * the legacy elapsed-mode sentinel.
     *
     * @param object   $task     Task row with schedule, interval_hours,
     *                           time_of_day, day_of_week and day_of_month.
     * @param int|null $last_run UTC timestamp of the last run start, null
     *                           when the task never ran.
     *
     * @return int Next UTC run timestamp, or 0 for legacy elapsed mode.
     */
    public function compute_next_run( object $task, ?int $last_run = null ): int {
        $interval_hours = isset( $task->interval_hours ) ? (int) $task->interval_hours : 0;
        $time_of_day    = isset( $task->time_of_day ) ? (string) $task->time_of_day : '';

        if ( $interval_hours > 0 ) {
            return $this->next_for_interval( $interval_hours, $last_run );
        }

        if ( '' === $time_of_day ) {
            return 0;
        }

        $schedule = isset( $task->schedule ) ? (string) $task->schedule : '';

        switch ( $schedule ) {
            case 'daily':
                return $this->next_for_daily( $time_of_day );
            case 'weekly':
                $day_of_week = isset( $task->day_of_week ) ? (int) $task->day_of_week : 0;
                return $this->next_for_weekly( $time_of_day, $day_of_week );
            case 'monthly':
                $day_of_month = isset( $task->day_of_month ) ? (int) $task->day_of_month : 1;
                return $this->next_for_monthly( $time_of_day, $day_of_month );
            default:
                return 0;
        }
    }

    /**
     * Next run for an every-N-hours task.
     *
     * Pure elapsed time: the next slot after now on the last_run + N*k
     * series. A task that never ran is due immediately. Overdue tasks
     * land on the next future slot, never several at once.
     *
     * @param int      $interval_hours Hours between runs.
     * @param int|null $last_run       Last run start, null when never ran.
     *
     * @return int Next UTC run timestamp.
     */
    private function next_for_interval( int $interval_hours, ?int $last_run ): int {
        if ( null === $last_run || $last_run <= 0 ) {
            return $this->now;
        }

        $step      = $interval_hours * HOUR_IN_SECONDS;
        $candidate = $last_run + $step;

        if ( $candidate <= $this->now ) {
            $missed     = (int) floor( ( $this->now - $candidate ) / $step ) + 1;
            $candidate += $missed * $step;
        }

        return $candidate;
    }

    /**
     * Next run for a daily task at a wall-clock time.
     *
     * @param string $time_of_day Time as HH:MM.
     *
     * @return int Next UTC run timestamp.
     */
    private function next_for_daily( string $time_of_day ): int {
        $candidate = $this->at_time_of_day( $this->local_now(), $time_of_day );

        if ( $candidate->getTimestamp() <= $this->now ) {
            $candidate = $this->at_time_of_day( $candidate->modify( '+1 day' ), $time_of_day );
        }

        return $candidate->getTimestamp();
    }

    /**
     * Next run for a weekly task on a day of week at a wall-clock time.
     *
     * @param string $time_of_day Time as HH:MM.
     * @param int    $day_of_week Target day, 0 = Sunday through 6 = Saturday.
     *
     * @return int Next UTC run timestamp.
     */
    private function next_for_weekly( string $time_of_day, int $day_of_week ): int {
        $local = $this->local_now();
        $delta = ( $day_of_week - (int) $local->format( 'w' ) + 7 ) % 7;

        $candidate = $this->at_time_of_day( $local->modify( "+{$delta} days" ), $time_of_day );

        if ( $candidate->getTimestamp() <= $this->now ) {
            $candidate = $this->at_time_of_day( $candidate->modify( '+7 days' ), $time_of_day );
        }

        return $candidate->getTimestamp();
    }

    /**
     * Next run for a monthly task on a day of month at a wall-clock time.
     *
     * A day beyond the month's length caps at the month's last day rather
     * than spilling into the next month.
     *
     * @param string $time_of_day  Time as HH:MM.
     * @param int    $day_of_month Target day of the month, from 1.
     *
     * @return int Next UTC run timestamp.
     */
    private function next_for_monthly( string $time_of_day, int $day_of_month ): int {
        $local     = $this->local_now();
        $candidate = $this->at_day_of_month( $local, $day_of_month, $time_of_day );

        if ( $candidate->getTimestamp() <= $this->now ) {
            $candidate = $this->at_day_of_month( $local->modify( 'first day of next month' ), $day_of_month, $time_of_day );
        }

        return $candidate->getTimestamp();
    }

    /**
     * The current moment in the site timezone.
     *
     * @return \DateTimeImmutable
     */
    private function local_now(): \DateTimeImmutable {
        return ( new \DateTimeImmutable( '@' . $this->now ) )->setTimezone( $this->timezone );
    }

    /**
     * A moment's day with the wall-clock time applied.
     *
     * A time inside a DST spring-forward gap normalizes forward to the
     * next valid instant.
     *
     * @param \DateTimeImmutable $day         The day to pin the time on.
     * @param string             $time_of_day Time as HH:MM.
     *
     * @return \DateTimeImmutable
     */
    private function at_time_of_day( \DateTimeImmutable $day, string $time_of_day ): \DateTimeImmutable {
        list( $hour, $minute ) = array_map( 'intval', array_pad( explode( ':', $time_of_day, 2 ), 2, 0 ) );

        return $day->setTime( $hour, $minute, 0 );
    }

    /**
     * A moment's month with the day of month and wall-clock time applied,
     * capping the day at the month's length.
     *
     * @param \DateTimeImmutable $month        Any moment inside the month.
     * @param int                $day_of_month Target day, from 1.
     * @param string             $time_of_day  Time as HH:MM.
     *
     * @return \DateTimeImmutable
     */
    private function at_day_of_month( \DateTimeImmutable $month, int $day_of_month, string $time_of_day ): \DateTimeImmutable {
        $day = min( max( 1, $day_of_month ), (int) $month->format( 't' ) );

        $pinned = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day );

        return $this->at_time_of_day( $pinned, $time_of_day );
    }
}
