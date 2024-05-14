<?php
/**
 * MainWP Logs Widget
 *
 * Displays the Logs Info.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Log_Graph_Php_Widget
 *
 * Displays the Logs info.
 */
class Log_Graph_Php_Widget {

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Return the single instance of the class.
     *
     * @return mixed $instance The single instance of the class.
     */
    public static function instance() {
        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }
        return static::$instance;
    }


    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render()
     */
    public function render() {
        $this->render_widget();
    }


    /**
     * Render client overview Info.
     */
    public function render_widget() {
        ?>
        <div class="mainwp-widget-header">
            <h3 class="ui header handle-drag">
                <?php esc_html_e( 'PHP Version Distribution', 'mainwp' ); ?>
                <div class="sub header">
                <?php esc_html_e( 'Distribution of PHP versions across the sites. Track which PHP versions are most prevalent in your network.', 'mainwp' ); ?>
                </div>
            </h3>
        </div>

        <div class="mainwp-widget-insights-card">
                <?php
                /**
                 * Action: mainwp_logs_widget_top
                 *
                 * Fires at the top of the widget.
                 *
                 * @since 4.6
                 */
                do_action( 'mainwp_logs_widget_top', 'php' );
                ?>
                <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
                <?php
                wp_nonce_field( 'mainwp-admin-nonce' );
                $this->render_widget_content();
                ?>
                <?php
                /**
                 * Action: mainwp_logs_widget_bottom
                 *
                 * Fires at the bottom of the widget.
                 *
                 * @since 4.6
                 */
                do_action( 'mainwp_logs_widget_bottom', 'php' );
                ?>
            </div>
        <div class="mainwp-widget-footer ui four columns stackable grid">
            <div class="column">
            </div>
            <div class="column">
            </div>
        </div>
        <?php
    }

    /**
     * Method render_widget_content()
     */
    public function render_widget_content() {
        $versions = array();
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites() );
        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
            if ( ! empty( $website->phpversion ) ) {
                $ver = substr( $website->phpversion, 0, 5 );
            } else {
                $ver = 'N/A';
            }
            if ( ! isset( $versions[ $ver ] ) ) {
                $versions[ $ver ] = 1;
            } else {
                $versions[ $ver ] += 1;
            }
        }
        MainWP_DB::free_result( $websites );

        ?>
        <div id="mainwp-module-log-chart-php-wrapper" ></div>
        <script type="text/javascript">
            jQuery( document ).ready( function() {
                let options = {
                    chart: {
                        type: 'bar'
                    },
                    series: [ {
                        name: 'Sites',
                        data: [
                            <?php foreach ( $versions as $version => $count ) : ?>
                            {
                            x: '<?php echo esc_js( $version ); ?>',
                            y: <?php echo intval( $count ); ?>,
                            fillColor: '#18a4e0'
                            },
                            <?php endforeach; ?>
                        ]
                    } ],
                    xaxis: {
                        labels: {
                            style: {
                                colors: '#999999',
                            }
                        }
                    },
                    yaxis: {
                        labels: {
                            style: {
                                colors: '#999999',
                            }
                        }
                    },
                    tooltip: {
                        theme: 'dark'
                    },
                }
                let php = new ApexCharts(document.querySelector("#mainwp-module-log-chart-php-wrapper"), options);
                setTimeout(() => {
                    php.render();
                }, 1000);
            } );
        </script>
        <?php
    }
}
