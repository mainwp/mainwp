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
        $total       = $up_number + $down_number;

        ?>

        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="twelve wide column">
                    <h3 class="ui header handle-drag">
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
                    </h3>
                </div>

            <div class="ui two small cards">
                <div class="ui fluid card">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text"><span class="ui big circular icon green looping pulsating transition label"><i class="chevron up icon"></i></span> <?php echo esc_html( MainWP_Utility::short_number_format( $up_number ) ); ?></span>
                        </div>
                        <div class="meta">
                            <div class="ui tiny progress mainwp-monitors-status-progress" id="" data-total="<?php echo esc_attr( $total ); ?>" data-value="<?php echo esc_attr( $up_number ); ?>">
                                <div class="green bar"></div>
                            </div>
                        </div>
                        <div class="description"><strong><?php esc_html_e( 'Up', 'mainwp' ); ?></strong></div>
                    </div>
                </div>
                <div class="ui fluid card">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text"><span class="ui big circular icon red looping pulsating transition label"><i class="chevron down icon"></i></span> <?php echo esc_html( MainWP_Utility::short_number_format( $down_number ) ); ?></span>
                        </div>
                        <div class="meta">
                            <div class="ui tiny progress mainwp-monitors-status-progress" id="" data-total="<?php echo esc_attr( $total ); ?>" data-value="<?php echo esc_attr( $down_number ); ?>">
                                <div class="red bar"></div>
                            </div>
                        </div>
                        <div class="description"><strong><?php esc_html_e( 'Down', 'mainwp' ); ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
        <script type="text/javascript">
            jQuery('.mainwp-monitors-status-progress').progress();
        </script>
        <?php
    }
}
