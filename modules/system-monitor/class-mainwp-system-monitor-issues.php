<?php
/**
 * MainWP System Monitor Issues.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MainWP System Monitor Issues.
 *
 * Builds standardized issue objects from validator failures.
 *
 * Responsibilities:
 *
 * - Normalize issue format.
 * - Assign severity.
 * - Assign category.
 * - Generate title.
 * - Generate description.
 * - Generate recommendation.
 *
 * Issues MUST NOT:
 *
 * - Scan the system.
 * - Validate data.
 * - Save issues.
 */
class MainWP_System_Monitor_Issues {

    /**
     * Severity consts.
     */
    const SEVERITY_INFO    = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR   = 'error';

    private const HELP_BASE_URL = 'https://docs.mainwp.com/';


    /**
     * Method get issue message.
     *
     * @param string $code Issue code.
     * @param array  $payload Issue payload.
     *
     * @return string message.
     */
    public static function get_message( $code, array $payload = array() ) {

        switch ( $code ) {
            case MainWP_System_Monitor_Cron::ISSUE_MONITOR_STALE:
                $delay = isset( $payload['delay'] ) ? (int) $payload['delay'] : 0;
                $human = human_time_diff(
                    time() - $delay,
                    time()
                );
                if ( ! empty( $payload['wp_cron_disabled'] ) ) {
                    return sprintf(
                        __( 'Scheduled tasks has not run for %s. WP-Cron is disabled, so verify that your external cron job is running correctly.', 'mainwp' ),
                        $human
                    );
                }
                return sprintf(
                    __( 'Scheduled tasks has not run for %s. Verify that WP-Cron is functioning correctly.', 'mainwp' ),
                    $human
                );
            case MainWP_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK:
                return __(
                    'The scheduled System Monitor task was overdue, so it was executed during a page request. If this happens frequently, verify that WP-Cron or an external cron job is working correctly.',
                    'mainwp'
                );
            case MainWP_System_Monitor_Cron::ISSUE_USE_WP_CRON_DISABLED:
                return __(
                    'The MainWP "Use WP-Cron" setting is disabled. Ensure that an external cron job is configured to execute scheduled MainWP tasks.',
                    'mainwp'
                );
            default:
                // Nothing.
                break;
        }

        return '';
    }


    /**
     * Get title.
     *
     * @param string $code Issue code.
     *
     * @return string
     */
    public static function get_title( $code ) {
        $definition = self::get_definition( $code );
        return empty( $definition['title'] ) ? '' : $definition['title'];
    }

    /**
     * Get help URL.
     *
     * @param string $code Issue code.
     *
     * @return string
     */
    public static function get_help_url( $code ) {

        $definition = self::get_definition( $code );

        if ( empty( $definition['help'] ) ) {
            return '';
        }

        return self::HELP_BASE_URL . $definition['help'] . '/';
    }


    /**
     * Get severity.
     *
     * @param string $code Issue code.
     *
     * @return string severity.
     */
    public static function get_severity( $code ) {
        $definition = self::get_definition( $code );
        return empty( $definition['severity'] ) ? '' : $definition['severity'];
    }

    /**
     * Method get issue definitions.
     *
     * @param string $code Issue code.
     *
     * @return array definition.
     */
    public static function get_definition( $code ) {
        $definitions = array(
            MainWP_System_Monitor_Cron::ISSUE_MONITOR_STALE          => array(
                'code'        => MainWP_System_Monitor_Cron::ISSUE_MONITOR_STALE,
                'severity'    => self::SEVERITY_WARNING, // default severity.
                'title'       => __( 'Scheduled tasks have not run recently.', 'mainwp' ),
                'description' => __( 'The System Monitor cron has not executed within the expected interval.', 'mainwp' ),
                'help'        => 'customization/delayed-wp-cron',
            ),
            MainWP_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK => array(
                'code'        => MainWP_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK,
                'severity'    => self::SEVERITY_WARNING,
                'title'       => __( 'System Monitor fallback execution was used.', 'mainwp' ),
                'description' => __( 'The scheduled System Monitor task was executed during a page request because it did not run on schedule. Verify that WP-Cron or your external cron job is working correctly.', 'mainwp' ),
                'help'        => 'customization/delayed-wp-cron',
            ),
            MainWP_System_Monitor_Cron::ISSUE_USE_WP_CRON_DISABLED  => array(
                'code'        => MainWP_System_Monitor_Cron::ISSUE_USE_WP_CRON_DISABLED,
                'severity'    => self::SEVERITY_INFO,
                'title'       => __( 'The "Use WP-Cron" setting is disabled.', 'mainwp' ),
                'description' => __( 'MainWP scheduled tasks will not run unless they are triggered by an external cron job.', 'mainwp' ),
                'help'        => 'customization/delayed-wp-cron',
            ),
        );

        return $definitions[ $code ] ?? array();
    }

    /**
     * Add a monitor issue.
     *
     * @param MainWP_System_Monitor_Result $result Monitor result.
     *
     * @return int Insert ID, or 0 on failure.
     */
    public static function add( MainWP_System_Monitor_Result $result ) {
        return MainWP_System_Monitor_Storage::save_result( $result );
    }
}
