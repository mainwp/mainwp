<?php
/**
 * MainWP Assistant Provider Usage Widget
 *
 * Displays the provider usage chart on the Log Insights Overview page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Assistant_Provider_Usage_Widget
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_Provider_Usage_Widget {

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
     * Render the provider usage widget.
     */
    public function render() {
        if ( ! MainWP_Assistant_Policies::render_widget_guard( __( 'Provider Usage', 'mainwp' ) ) ) {
            return;
        }

        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php esc_html_e( 'Provider Usage', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Whether MainWP docs or your configured AI provider answered each request.', 'mainwp' ); ?></div>
            </h2>
        </div>
        <div class="mainwp-scrolly-overflow">
            <div id="assistant-provider-usage-error" class="ui yellow message" style="display: none;">
                <?php esc_html_e( 'Couldn\'t load usage data. Reload the page to retry.', 'mainwp' ); ?>
            </div>
            <div id="mainwp-assistant-chart-provider-usage" style="min-height: 300px;"></div>
            <div id="assistant-provider-usage-no-data" style="display: none; height: 300px;">
                <?php MainWP_UI::render_empty_element_placeholder( __( 'No provider usage yet', 'mainwp' ), __( 'Nothing was recorded in the selected period.', 'mainwp' ), '<em data-emoji=":robot:" class="medium"></em>' ); ?>
            </div>
        </div>
        <div class="ui four columns stackable grid mainwp-widget-footer">
            <div class="column"></div>
            <div class="column"></div>
        </div>
        <?php
    }
}
