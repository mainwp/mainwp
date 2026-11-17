<?php
/**
 * MainWP Assistant Summary Widget
 *
 * Displays the AI Assistant summary statistics
 * on the Log Insights Overview page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Assistant_Summary_Widget
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_Summary_Widget {

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
     * Render the assistant summary widget.
     */
    public function render() {
        if ( ! MainWP_Assistant_Policies::render_widget_guard( __( 'AI Assistant Summary', 'mainwp' ) ) ) {
            return;
        }

        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php esc_html_e( 'AI Assistant Summary', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'How much the AI assistant was used in the selected period.', 'mainwp' ); ?></div>
            </h2>
        </div>

        <div class="mainwp-scrolly-overflow">
            <div id="assistant-summary-error" class="ui yellow message" style="display: none;">
                <?php esc_html_e( 'Couldn\'t load usage data. Reload the page to retry.', 'mainwp' ); ?>
            </div>
            <!-- Summary Cards -->
            <div class="ui four column stackable grid" id="assistant-summary-cards">
                <div class="column">
                    <div class="ui cards">
                        <div class="ui fluid small card">
                            <div class="content">
                                <div class="header">
                                    <span class="ui large text"><i class="play circle icon assistant-success"></i> <span id="assistant-total-executions">--</span></span>
                                </div>
                                <div class="description" data-tooltip="<?php esc_attr_e( 'Total assistant actions run in the selected period.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                    <strong><?php esc_html_e( 'Abilities Executed', 'mainwp' ); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column">
                    <div class="ui cards">
                        <div class="ui fluid small card">
                            <div class="content">
                                <div class="header">
                                    <span class="ui large text"><i class="exclamation triangle icon assistant-error"></i> <span id="assistant-failed-count">--</span></span>
                                </div>
                                <div class="description" data-tooltip="<?php esc_attr_e( 'Chat requests and assistant actions that returned an error in the selected period.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                    <strong><?php esc_html_e( 'Failed requests', 'mainwp' ); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column">
                    <div class="ui cards">
                        <div class="ui fluid small card">
                            <div class="content">
                                <div class="header">
                                    <span class="ui large text"><i class="check double icon assistant-warning"></i> <span id="assistant-confirmed-count">--</span></span>
                                </div>
                                <div class="description" data-tooltip="<?php esc_attr_e( 'Actions you approved before they ran.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                    <strong><?php esc_html_e( 'Confirmed Actions', 'mainwp' ); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column">
                    <div class="ui cards">
                        <div class="ui fluid small card">
                            <div class="content">
                                <div class="header">
                                    <span class="ui large text"><i class="comments icon assistant-info"></i> <span id="assistant-conversation-count">--</span></span>
                                </div>
                                <div class="description" data-tooltip="<?php esc_attr_e( 'Chat conversations started in the selected period.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                    <strong><?php esc_html_e( 'Conversations', 'mainwp' ); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ui four columns stackable grid mainwp-widget-footer">
            <div class="column"></div>
            <div class="column"></div>
        </div>
        <?php
    }
}
