<?php
/**
 * MainWP System Monitor.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * MainWP System Monitor UI.
 */
class MainWP_System_Monitor_UI {

    /**
     * UI init.
     */
    public static function init() {
        add_action( 'mainwp_before_overview_widgets', array( self::class, 'render_issues' ), 10, 3 );
    }

    /**
     * Render System Monitor issues.
     *
     * @param string            $what           Optional section or issue type to render.
     * @param int|false         $current_siteid Optional site ID. Pass `false` to use the current context.
     * @param object|array|null $website        Optional website data.
     *
     * @return void
     */
    public static function render_issues( $what = '', $current_siteid = false, $website = null ) { // phpcs:ignore -- NOSONAR - complex.

        $monitor      = new MainWP_System_Monitor_Cron();
        $monitor_name = $monitor->get_name();

        if ( 'dashboard' === $what && is_numeric( $current_siteid ) && ! empty( $current_siteid ) ) {
            $is_individual_overview = true;
            $monitor_data           = ! empty( $website ) && is_object( $website ) && ! empty( $website->child_monitor_data ) ? json_decode( $website->child_monitor_data, true ) : array();
            if ( ! is_array( $monitor_data ) ) {
                $monitor_data = array();
            }
            $issues = isset( $monitor_data['issues'] ) && is_array( $monitor_data['issues'] ) ? $monitor_data['issues'] : array();
        } else {
            $issues                 = MainWP_System_Monitor_Storage::get_issues( $monitor_name );
            $is_individual_overview = false;
        }

        if ( empty( $issues ) ) {
            return;
        }

        $new_issues = array();

        if ( $is_individual_overview ) {
            $new_issues = $issues;
        } else {
            foreach ( $issues as $issue ) {
                $key = MainWP_System_Monitor_Storage::get_notice_key(
                    $issue['issue_code'],
                    $issue['entity']
                );
                if ( MainWP_Utility::is_short_term_notice( $issue['monitor'], $key ) ) {
                    $new_issues[ $monitor_name . '_' . $key ] = $issue;
                }
            }
        }

        unset( $issues );

        if ( empty( $new_issues ) ) {
            return;
        }

        $keys = implode( ';', array_keys( $new_issues ) );

        ?>
        <div class="ui message yellow" style="margin: 1em;">
            <?php if ( ! $is_individual_overview ) { ?>
            <i class="close icon mainwp-notice-dismiss" notice-id="<?php echo esc_attr( $keys ); ?>" shortterm-notice="1"></i>
                <?php
            }
            foreach ( $new_issues as $issue ) {
                self::render_issue( $issue, $is_individual_overview );
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render a single System Monitor issue.
     *
     * @param array $issue          Issue data.
     * @param bool  $is_individual_overview Whether the issue originates from dashboard or child site.
     *
     * @return void
     */
    private static function render_issue( array $issue, $is_individual_overview = false ) {

        $issue_code = isset( $issue['issue_code'] ) ? $issue['issue_code'] : '';
        if ( empty( $issue_code ) ) {
            return;
        }

        if ( $is_individual_overview ) {
            $issue_code = MainWP_System_Monitor_Cron::map_child_monitor_issue_code( $issue_code ); // Used to display the corresponding message for child issues.
        }

        echo '<div class="mainwp-system-monitor-issue">';

        echo '<strong>' .
            esc_html(
                MainWP_System_Monitor_Issues::get_title(
                    $issue_code
                )
            ) .
            '</strong>';

        echo '<p>' .
            esc_html(
                MainWP_System_Monitor_Issues::get_message(
                    $issue_code,
                    $issue['payload']
                )
            ) .
            '</p>';

        $url = MainWP_System_Monitor_Issues::get_help_url(
            $issue_code
        );

        if ( ! empty( $url ) ) {

            printf(
                '<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
                esc_url( $url ),
                esc_html__( 'Learn more', 'mainwp' )
            );
        }

        echo '</div>';
    }
}
