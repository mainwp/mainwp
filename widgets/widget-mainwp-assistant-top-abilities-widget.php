<?php
/**
 * MainWP Assistant Top Abilities Widget
 *
 * Displays the top abilities chart on the Log Insights Overview page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Assistant_Top_Abilities_Widget
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_Top_Abilities_Widget {

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
     * Render the top abilities widget.
     */
    public function render() {
        if ( ! MainWP_Assistant_Policies::render_widget_guard( __( 'Top Abilities', 'mainwp' ) ) ) {
            return;
        }

        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php esc_html_e( 'Top Abilities', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Most frequently used assistant actions in the selected period.', 'mainwp' ); ?></div>
            </h2>
        </div>
        <div class="mainwp-scrolly-overflow">
            <div id="assistant-top-abilities-error" class="ui yellow message" style="display: none;">
                <?php esc_html_e( 'Couldn\'t load usage data. Reload the page to retry.', 'mainwp' ); ?>
            </div>
            <div id="assistant-top-abilities-chart" style="min-height: 300px;"></div>
            <div id="assistant-top-abilities-no-data" style="display: none; height: 300px;">
                <?php MainWP_UI::render_empty_element_placeholder( __( 'No ability usage yet', 'mainwp' ), __( 'Nothing was recorded in the selected period.', 'mainwp' ), '<em data-emoji=":bar_chart:" class="medium"></em>' ); ?>
            </div>
        </div>
        <div class="ui four columns stackable grid mainwp-widget-footer">
            <div class="column"></div>
            <div class="column"></div>
        </div>
        <?php
    }
}
