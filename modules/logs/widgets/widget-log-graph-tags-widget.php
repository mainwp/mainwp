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
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_UI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Log_Graph_Tags_Widget
 *
 * Displays the Logs info.
 */
class Log_Graph_Tags_Widget {

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
        $sites_count = MainWP_DB::instance()->get_websites_count();
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php esc_html_e( 'Tag Allocation Overview', 'mainwp' ); ?>
                <div class="sub header">
                <?php esc_html_e( 'Distribution of sites and clients associated with each tag for efficient resource categorization and insight.', 'mainwp' ); ?>
                </div>
            </h2>
        </div>

        <div class="mainwp-widget-insights-card mainwp-scrolly-overflow">
                <?php
                /**
                 * Action: mainwp_logs_widget_top
                 *
                 * Fires at the top of the widget.
                 *
                 * @since 4.6
                 */
                do_action( 'mainwp_logs_widget_top', 'status' );
                ?>
                <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
                <?php
                MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' );
                if ( 0 < intval( $sites_count ) ) {
                    $this->render_widget_content();
                } else {
                    MainWP_UI::render_empty_element_placeholder( __( 'No Tags Data', 'mainwp' ), '<a href="admin.php?page=managesites&do=new">' . __( 'Start connecting your sites now', 'mainwp' ) . '</a>', '<em data-emoji=":bar_chart:" class="medium"></em>' );
                }
                ?>
                <?php
                /**
                 * Action: mainwp_logs_widget_bottom
                 *
                 * Fires at the bottom of the widget.
                 *
                 * @since 4.6
                 */
                do_action( 'mainwp_logs_widget_bottom', 'status' );
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
    public function render_widget_content() { //phpcs:ignore -- NOSONAR - complex method.

        $groups         = MainWP_DB_Common::instance()->get_groups_and_count();
        $tags_names     = array();
        $groups_nrsites = array();
        if ( ! empty( $groups ) ) {
            foreach ( $groups as $group ) {
                if ( 'Staging Sites' === $group->name ) {
                    continue;
                }
                $tags_names[ $group->id ]     = $group->name;
                $groups_nrsites[ $group->id ] = ! empty( $group->nrsites ) ? $group->nrsites : 0;
            }
        }
        asort( $tags_names );

        $params  = array(
            'with_tags' => true,
        );
        $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all', null, ARRAY_A, $params );

        // Count each client once per tag. The query's name and ID lists are sorted differently, so only the IDs are used.
        $tags_count = array();
        if ( ! empty( $clients ) ) {
            foreach ( $clients as $client ) {
                $tags_ids = ! empty( $client['wpgroupids'] ) ? array_unique( explode( ',', $client['wpgroupids'] ) ) : array();
                foreach ( $tags_ids as $tagid ) {
                    if ( ! isset( $tags_count[ $tagid ] ) ) {
                        $tags_count[ $tagid ] = 0;
                    }
                    $tags_count[ $tagid ] += 1;
                }
            }
        }
        ?>
        <div id="mainwp-module-log-chart-tags-wrapper" ></div>
        <script type="text/javascript">
            jQuery( document ).ready( function() {
                // ApexCharts drops commas from names and writes CSV fields unquoted.
                let csvCategories = new Map( <?php echo wp_json_encode( Log_Graph_Csv_Labels::get_csv_categories( array_map( 'strval', array_values( $tags_names ) ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?> );
                let options = {
                    chart: {
                        type: 'bar',
                        height: 350,
                        toolbar: {
                            export: {
                                csv: {
                                    categoryFormatter: function( name ) {
                                        return csvCategories.has( String( name ) ) ? csvCategories.get( String( name ) ) : '"\'' + String( name ).replace( /"/g, '""' ) + '"';
                                    },
                                },
                            },
                        },
                    },
                    series: [ {
                        name: 'Sites',
                        data: [
                            <?php foreach ( $tags_names as $tagid => $tagname ) : ?>
                                {
                                x: <?php echo wp_json_encode( (string) $tagname, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>,
                                y: <?php echo intval( $groups_nrsites[ $tagid ] ); ?>,
                                fillColor: "#18a4e0",
                                },
                            <?php endforeach; ?>
                        ]
                    },{
                        name: 'Clients',
                        data: [
                            <?php foreach ( $tags_names as $tagid => $tagname ) : ?>
                                {
                                x: <?php echo wp_json_encode( (string) $tagname, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>,
                                y: <?php echo isset( $tags_count[ $tagid ] ) ? intval( $tags_count[ $tagid ] ) : 0; ?>,
                                fillColor: "#7fb100",
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
                    // ApexCharts inserts these labels as HTML.
                    tooltip: {
                        theme: 'dark',
                        x: { formatter: function( value ) { return mainwp_escape_html( value ); } },
                    },
                }
                let tags = new ApexCharts(document.querySelector("#mainwp-module-log-chart-tags-wrapper"), options);
                setTimeout(() => {
                    tags.render();
                }, 1000);
            } );
        </script>
        <?php
    }
}
