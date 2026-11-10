<?php
/**
 * MainWP Assistant Activity Widget
 *
 * Displays the recent activity table and conversation modal
 * on the Log Insights Overview page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Assistant_Activity_Widget
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_Activity_Widget {

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
     * Render the activity widget.
     */
    public function render() {
        if ( ! MainWP_Assistant_Policies::render_widget_guard( __( 'AI Assistant Activity', 'mainwp' ) ) ) {
            return;
        }

        ?>
        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="fourteen wide column">
                    <h2 class="ui header handle-drag">
                        <?php esc_html_e( 'AI Assistant Activity', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'Conversation starts, chat failures, and assistant actions.', 'mainwp' ); ?></div>
                    </h2>
                </div>
                <div class="two wide column right aligned">
                    <button type="button" class="ui mini button" id="assistant-export-btn">
                        <i class="download icon"></i>
                        <?php esc_html_e( 'Export CSV', 'mainwp' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <div class="mainwp-scrolly-overflow">
            <template id="assistant-activity-no-data">
                <?php MainWP_UI::render_empty_element_placeholder( esc_html__( 'No activity found.', 'mainwp' ), esc_html__( 'Nothing was recorded in the selected period.', 'mainwp' ), '<em data-emoji=":bar_chart:" class="medium"></em>' ); ?>
            </template>
            <table id="assistant-activity-table"
                    style="width:100%"
                    class="ui single line unstackable table mainwp-with-preview-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Time', 'mainwp' ); ?></th>
                        <?php if ( \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) : ?>
                            <th scope="col"><?php esc_html_e( 'User', 'mainwp' ); ?></th>
                        <?php endif; ?>
                        <th scope="col"><?php esc_html_e( 'Ability', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Duration', 'mainwp' ); ?></th>
                        <th scope="col" class="no-sort"><?php esc_html_e( 'Details', 'mainwp' ); ?></th>
                    </tr>
                </thead>
            </table>
        </div>
        <div class="ui four columns stackable grid mainwp-widget-footer">
            <div class="column"></div>
            <div class="column"></div>
        </div>

        <!-- Conversation View Modal -->
        <div class="ui large modal" id="assistant-conversation-modal">
            <div class="header">
                <i class="comments icon"></i>
                <?php esc_html_e( 'Conversation Details', 'mainwp' ); ?>
            </div>
            <div class="scrolling content" id="assistant-conversation-content">
                <div class="ui active centered inline loader"></div>
            </div>
            <div class="actions">
                <button type="button" class="ui button" id="assistant-close-conversation-modal"><?php esc_html_e( 'Close', 'mainwp' ); ?></button>
            </div>
        </div>
        <?php
    }
}
