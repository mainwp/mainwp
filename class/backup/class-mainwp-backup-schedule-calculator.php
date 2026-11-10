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
     * Start spread window in minutes; 0 keeps every slot exactly on time.
     *
     * @var int
     */
    private $start_offset_window;

    /**
     * Constructor.
     *
     * @param \DateTimeZone $timezone            Site timezone, usually wp_timezone().
     * @param int           $now                 Current UTC timestamp.
     * @param int|null      $start_offset_window Start spread in minutes; null reads the saved setting.
     */
    public function __construct( \DateTimeZone $timezone, int $now, ?int $start_offset_window = null ) {
        $this->timezone            = $timezone;
        $this->now                 = $now;
        $this->start_offset_window = null === $start_offset_window ? MainWP_Backup_Settings::get_backup_start_offset_minutes() : max( 0, $start_offset_window );
    }

    /**
     * A task's delay inside the spread window.
     *
     * Derived from the task id rather than rolled per run, so a preview,
     * a save and every later advance agree on the same slot. An unsaved
     * task has no id yet and runs on its base slot.
     *
     * @param object $task   Task row.
     * @param int    $window Window in minutes.
     *
     * @return int Delay in seconds.
     */
    private static function start_offset_seconds( object $task, int $window ): int {
        $id = isset( $task->id ) ? (int) $task->id : 0;
        if ( $window <= 0 || $id <= 0 ) {
            return 0;
        }
        return ( (int) sprintf( '%u', crc32( 'mainwp_backup_start_offset_' . $id ) ) % ( $window + 1 ) ) * MINUTE_IN_SECONDS;
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

        if ( $this->start_offset_window > 0 ) {
            return $this->next_with_start_offset( $task, $last_run );
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
     * Next wall-clock run with the task's start delay applied.
     *
     * The base slot search starts from now minus the delay, so an
     * occurrence whose delayed start is still ahead stays scheduled even
     * when its base time has passed, including across midnight or a month
     * boundary. It also starts no earlier than the last run, so a base
     * slot a run already served, perhaps under a different window, is
     * never scheduled twice.
     *
     * @param object   $task     Task row with wall-clock schedule fields.
     * @param int|null $last_run Served slot or last run start.
     *
     * @return int Next UTC run timestamp.
     */
    private function next_with_start_offset( object $task, ?int $last_run ): int {
        $offset = self::start_offset_seconds( $task, $this->start_offset_window );
        $served = max( (int) $last_run, isset( $task->last_run ) ? (int) $task->last_run : 0 );
        $ref    = max( $this->now - $offset, $served );
        $base   = ( new self( $this->timezone, $ref, 0 ) )->compute_next_run( $task, $last_run );

        // On a fall-back day a wall-clock time exists twice, and the slot
        // math may land on either instant depending on the starting offset.
        // Both are one occurrence: it is pinned to the first instant, and
        // when that is already behind the search start (served, or its
        // delayed start has passed) the occurrence is not repeated.
        if ( $base > 0 ) {
            $first = $this->first_instant( $base );
            if ( $first <= $ref ) {
                $base  = ( new self( $this->timezone, $base, 0 ) )->compute_next_run( $task, $last_run );
                $first = $base > 0 ? $this->first_instant( $base ) : 0;
            }
            $base = $first;
        }

        return $base > 0 ? $base + $offset : 0;
    }

    /**
     * The earliest instant that shows the same site-local time.
     *
     * An earlier instant with the same wall-clock reading only exists inside
     * a fall-back fold, which lasts 30 minutes in some zones (Lord Howe)
     * and an hour or more in others, so each fold length is checked.
     *
     * @param int $timestamp UTC timestamp.
     *
     * @return int The first instant with this local time.
     */
    private function first_instant( int $timestamp ): int {
        $wall = $this->wall_clock( $timestamp );
        foreach ( array( 2 * HOUR_IN_SECONDS, HOUR_IN_SECONDS, 30 * MINUTE_IN_SECONDS ) as $fold ) {
            if ( $this->wall_clock( $timestamp - $fold ) === $wall ) {
                return $timestamp - $fold;
            }
        }
        return $timestamp;
    }

    /**
     * A UTC instant as a site-local time, for comparing wall-clock slots.
     *
     * @param int $timestamp UTC timestamp.
     *
     * @return string Local time as Y-m-d H:i:s.
     */
    private function wall_clock( int $timestamp ): string {
        return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $this->timezone )->format( 'Y-m-d H:i:s' );
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
