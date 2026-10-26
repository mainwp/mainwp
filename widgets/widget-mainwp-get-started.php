<?php
/**
 * MainWP Get Started Widget
 *
 * Grab Child Sites update status & build widget.
 *
 * @package MainWP
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Get_Started
 *
 * @package MainWP\Dashboard
 */
class MainWP_Get_Started { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.nore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name
     *
     * @return string __CLASS__ Class Name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method get_name()
     *
     * Define Widget Title.
     */
    public static function get_name() {
        return esc_html__( 'Get Started with MainWP', 'mainwp' );
    }

    /**
     * Method render()
     */
    public static function render() {
        static::render_content();
    }

    /**
     * Method render_sites()
     *
     * Grab available Child Sites updates a build Widget.
     */
    public static function render_content() {
        if ( ! static::is_visible() ) {
            return;
        }
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_get_started_widget_title
                 *
                 * Filters the widget title text.
                 *
                 * @since 5.0
                 */
                echo esc_html( apply_filters( 'mainwp_get_started_widget_title', esc_html__( 'Get Started with MainWP', 'mainwp' ) ) );
                ?>
                <div class="sub header"><?php esc_html_e( 'Connect sites, run updates, and set up your workflow.', 'mainwp' ); ?></div>
            </h2>
        </div>
        <div class="mainwp-scrolly-overflow">
            <?php
            $items        = static::get_items();
            $seen         = get_user_meta( get_current_user_id(), 'mainwp_onboarding_seen_milestones', true );
            $seen         = is_array( $seen ) ? $seen : array();
            $newly_seen   = array();
            $started_done = ! in_array( false, array_column( $items, 'complete' ), true );

            if ( $started_done && MainWP_Utility::show_mainwp_message( 'notice', 'get-started' ) ) {
                ?>
                <div class="ui green message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="get-started"></i>
                    <div class="header"><?php esc_html_e( 'Congratulations!', 'mainwp' ); ?></div>
                    <?php esc_html_e( 'Your checklist is complete. Dismiss this message to hide the widget.', 'mainwp' ); ?>
                </div>
                <?php
            }

            /**
             * Action: mainwp_get_started_widget_top
             *
             * Fires top the widget.
             *
             * @since 5.0
             */
            do_action( 'mainwp_get_started_widget_top' );
            ?>
            <div class="ui mini fluid vertical steps mainwp-get-started-steps">
                <?php foreach ( $items as $item ) : ?>
                    <?php
                    $celebrate = $item['complete'] && ! in_array( $item['id'], $seen, true );
                    if ( $celebrate ) {
                        $newly_seen[] = $item['id'];
                    }
                    ?>
                    <a class="<?php echo $item['complete'] ? 'completed' : ''; ?> step" href="<?php echo esc_url( admin_url( $item['url'] ) ); ?>">
                        <i class="<?php echo esc_attr( $item['icon'] ); ?> icon <?php echo $celebrate ? 'tadaaaa' : ''; ?>"></i>
                        <div class="content"><div class="<?php echo $item['complete'] ? 'description' : 'title'; ?>"><?php echo esc_html( $item['title'] ); ?></div></div>
                    </a>
                <?php endforeach; ?>
                <?php
                if ( $newly_seen ) {
                    update_user_meta( get_current_user_id(), 'mainwp_onboarding_seen_milestones', array_values( array_unique( array_merge( $seen, $newly_seen ) ) ) );
                }
                ?>
            </div>
        </div>
        <?php

        /**
         * Action: mainwp_get_started_widget_bottom
         *
         * Fires bottom the widget.
         *
         * @since 5.0
         */
        do_action( 'mainwp_get_started_widget_bottom' );
    }

    /**
     * Only new Dashboards receive the checklist; dismissal takes effect after completion.
     *
     * @return bool
     */
    public static function is_visible() {
        if ( ! get_option( 'mainwp_onboarding_fresh_install' ) ) {
            return false;
        }
        return in_array( false, array_column( static::get_items(), 'complete' ), true ) || MainWP_Utility::show_mainwp_message( 'notice', 'get-started' );
    }

    /**
     * Checklist state comes from saved outcomes, rather than clicks on its shortcuts.
     *
     * @return array
     */
    public static function get_items() {
        $sites    = MainWP_Manage_Sites::get_total_sites();
        $settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        $items    = array(
            array(
                'id'       => 'connect_site',
                'title'    => __( 'Connect a site', 'mainwp' ),
                'url'      => 'admin.php?page=managesites&do=new',
                'icon'     => 'globe',
                'complete' => 1 <= $sites,
            ),
            array(
                'id'       => 'first_update',
                'title'    => __( 'Run your first update', 'mainwp' ),
                'url'      => 'admin.php?page=UpdatesManage',
                'icon'     => 'sync',
                'complete' => (bool) get_option( 'mainwp_onboarding_first_update' ),
            ),
            array(
                'id'       => 'second_site',
                'title'    => __( 'Connect a second site', 'mainwp' ),
                'url'      => 'admin.php?page=managesites&do=new',
                'icon'     => 'globe',
                'complete' => 2 <= $sites,
            ),
            array(
                'id'       => 'uptime_alerts',
                'title'    => __( 'Confirm uptime alerts', 'mainwp' ),
                'url'      => 'admin.php?page=MonitoringSettings',
                'icon'     => 'heartbeat',
                'complete' => ! empty( $settings['active'] ) && (bool) get_option( 'mainwp_onboarding_uptime_confirmed' ),
            ),
        );
        if ( MainWP_System_Utility::is_admin() && true === MainWP_Abilities_Util::check_rest_api_permission() && ! MainWP_Menu::is_disable_menu_item( 2, 'ai' ) ) {
            $items[] = array(
                'id'       => 'mcp',
                'title'    => __( 'Try MainWP from Claude or Cursor (MCP)', 'mainwp' ),
                'url'      => 'admin.php?page=mainwp-mcp',
                'icon'     => 'terminal',
                'complete' => (bool) get_option( 'mainwp_onboarding_mcp_visited' ),
            );
        }
        $items[] = array(
            'id'       => 'client_report',
            'title'    => __( 'Create a client and report', 'mainwp' ),
            'url'      => 'admin.php?page=ClientAddNew',
            'icon'     => 'users',
            'complete' => 1 <= MainWP_DB_Client::instance()->count_total_clients(),
        );
        return $items;
    }

    /**
     * Failed updates and installs do not count as a first successful update.
     *
     * @param object $website Updated site.
     * @param string $action  Install or update action.
     * @param array  $data    Update result.
     * @param string $type    Component type.
     */
    public static function record_first_update( $website, $action, $data, $type ) {
        if ( ! get_option( 'mainwp_onboarding_fresh_install' ) || 'updated' !== $action || ! is_array( $data ) || empty( $website->id ) ) {
            return;
        }
        $results = 'core' === $type ? array( $data ) : ( isset( $data['updated_data'] ) && is_array( $data['updated_data'] ) ? $data['updated_data'] : array() );
        foreach ( $results as $result ) {
            if ( is_array( $result ) && ! empty( $result['success'] ) && empty( $result['rollback'] ) ) {
                update_option( 'mainwp_onboarding_first_update', 1 );
                return;
            }
        }
    }
}
