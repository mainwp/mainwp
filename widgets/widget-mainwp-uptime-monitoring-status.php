<?php
/**
 * MainWP Connection Status
 *
 * Build the MainWP Overview page Connection Status Widget.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Uptime_Monitoring_Status
 *
 * Build the Connection Status Widget.
 */
class MainWP_Uptime_Monitoring_Status { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class Name
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Render connection status summary section.
     */
    public static function render_status() {
        $result = MainWP_DB_Uptime_Monitoring::instance()->get_count_up_down_monitors();
        if ( ! is_array( $result ) ) {
            $result = array();
        }
        $up_number   = isset( $result['count_up'] ) ? intval( $result['count_up'] ) : 0;
        $down_number = isset( $result['count_down'] ) ? intval( $result['count_down'] ) : 0;
        ?>
        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="twelve wide column">
                    <h2 class="ui header handle-drag">
                        <?php
                        /**
                         * Filter: mainwp_uptime_monitoring_status_widget_title
                         *
                         * Filters the Status widget title text.
                         *
                         * @since 5.3
                         */
                        echo esc_html( apply_filters( 'mainwp_uptime_monitoring_status_widget_title', esc_html__( 'Uptime Monitoring', 'mainwp' ) ) );
                        ?>
                        <div class="sub header"><?php esc_html_e( 'Current uptime status.', 'mainwp' ); ?></div>
                    </h2>
                </div>
            </div>
        </div>
        <div class="mainwp-scrolly-overflow">
            <div class="ui mainwp-cards small cards">
                <div class="ui card">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text"><span class="ui big circular icon green looping pulsating transition label"><i class="chevron up icon"></i></span> <?php echo esc_html( MainWP_Utility::short_number_format( $up_number ) ); ?></span>
                        </div>
                        <div class="description"><strong><?php esc_html_e( 'Monitors Up', 'mainwp' ); ?></strong></div>
                    </div>
                </div>
                <div class="ui card">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text"><span class="ui big circular icon red looping pulsating transition label"><i class="chevron down icon"></i></span> <?php echo esc_html( MainWP_Utility::short_number_format( $down_number ) ); ?></span>
                        </div>
                        <div class="description"><strong><?php esc_html_e( 'Monitors Down', 'mainwp' ); ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ui stackable grid mainwp-widget-footer">
            <div class="eight wide left aligned middle aligned column"></div>
            <div class="eight wide right aligned middle aligned column">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=MonitoringSites' ) ); ?>"><?php esc_html_e( 'See Monitors', 'mainwp' ); ?></a>
            </div>
        </div>
        <?php
    }
}
