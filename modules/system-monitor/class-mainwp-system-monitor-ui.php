<?php
/**
 * MainWP System Monitor.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

use MainWP\Dashboard\MainWP_DB;

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
        add_action( 'mainwp_before_overview_widgets', array( self::class, 'render_issues' ) );
    }

    /**
     * Render all monitor issues.
     */
    public static function render_issues() {

        $issues = MainWP_System_Monitor_Storage::get_issues( 'cron' );

        if ( empty( $issues ) ) {
            return;
        }

        ?>
        <div class="ui message yellow" style="margin: 1em;">
            <i class="close icon mainwp-notice-dismiss" notice-id="cron-monitor"></i>
            <?php
            foreach ( $issues as $issue ) {

                self::render_issue( $issue );
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render a single issue.
     */
    private static function render_issue( array $issue ) {

        echo '<div class="mainwp-system-monitor-issue">';

        echo '<strong>' .
            esc_html(
                MainWP_System_Monitor_Issues::get_title(
                    $issue['issue_code']
                )
            ) .
            '</strong>';

        echo '<p>' .
            esc_html(
                MainWP_System_Monitor_Issues::get_message(
                    $issue['issue_code'],
                    $issue['payload']
                )
            ) .
            '</p>';

        $url = MainWP_System_Monitor_Issues::get_help_url(
            $issue['issue_code']
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
