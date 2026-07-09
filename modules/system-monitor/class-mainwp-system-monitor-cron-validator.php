<?php
/**
 * MainWP System Monitor Cron Validator.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validate WP-Cron health.
 */
class MainWP_System_Monitor_Cron_Validator {

    /**
     * Validator consts.
     */
    const STALE_WARNING          = 15 * MINUTE_IN_SECONDS;
    const STALE_ERROR            = 60 * MINUTE_IN_SECONDS;
    const FIRST_RUN_GRACE_PERIOD = 5 * MINUTE_IN_SECONDS;


    /**
     * Validate scan results.
     *
     * @param array $scan Scanner data.
     * @param array $context Context data.
     *
     * @return array
     */
    public function validate( array $scan, array $context = array() ) {

        $issues = array();

        $last_cron_run = $scan['last_cron_run'] ?? 0;
        $next_run      = (int) $scan['next_run'];

        if ( 0 === $last_cron_run ) {
            // The monitor has never executed via the scheduled hook.
            if ( $next_run > 0 && time() > ( $next_run + self::FIRST_RUN_GRACE_PERIOD ) ) {

                $issues[] = array(
                    'check_name' => 'heartbeat',
                    'entity'     => 'wp_cron',
                    'code'       => MainWP_System_Monitor_Cron::ISSUE_MONITOR_STALE,
                    'severity'   => MainWP_System_Monitor_Issues::SEVERITY_WARNING,
                    'data'       => array(
                        'delay'            => time() - $next_run,
                        'wp_cron_disabled' => ! empty( $scan['wp_cron_disabled'] ),
                    ),
                );
            }
        } else {

            $delay = time() - $last_cron_run;

            $severity = $this->get_monitor_stale_severity( $delay );

            if ( null !== $severity ) {
                $issues[] = array(
                    'check_name' => 'heartbeat',
                    'entity'     => 'wp_cron',
                    'code'       => MainWP_System_Monitor_Cron::ISSUE_MONITOR_STALE,
                    'severity'   => $severity,
                    'data'       => array(
                        'delay'            => $delay,
                        'wp_cron_disabled' => ! empty( $scan['wp_cron_disabled'] ),
                    ),
                );
            } elseif ( is_array( $context ) && ! empty( $context['fallback'] ) ) {
                // Report monitor_fallback_used.
                $issues[] = array(
                    'code'       => MainWP_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK,
                    'check_name' => 'heartbeat',
                    'entity'     => 'wp_cron',
                    'severity'   => MainWP_System_Monitor_Issues::SEVERITY_WARNING,
                    'data'       => array(
                        'delay'            => $delay,
                        'wp_cron_disabled' => ! empty( $scan['wp_cron_disabled'] ),
                    ),
                );

            }
        }

        return $issues;
    }

    /**
     * Determine severity.
     *
     * @param int $delay Delay in seconds.
     *
     * @return string|null
     */
    private function get_monitor_stale_severity( $delay ) {

        if ( $delay > self::STALE_ERROR ) {
            return MainWP_System_Monitor_Issues::SEVERITY_ERROR;
        }

        if ( $delay > self::STALE_WARNING ) {
            return MainWP_System_Monitor_Issues::SEVERITY_WARNING;
        }

        return null;
    }
}
