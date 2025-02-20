<?php
/**
 * MainWP Get Started Widget
 *
 * Grab Child Sites update status & build widget.
 *
 * @package MainWP
 */

namespace MainWP\Dashboard;

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
                <div class="sub header"><?php esc_html_e( 'Kickstart your MainWP experience with our simple checklist.', 'mainwp' ); ?></div>
            </h2>
        </div>
        <div class="mainwp-scrolly-overflow">
            <?php
            $extensions = MainWP_Extensions_Handler::get_extensions();

            $count_sites      = MainWP_Manage_Sites::get_total_sites();
            $count_clients    = MainWP_DB_Client::instance()->count_total_clients();
            $count_costs      = apply_filters( 'mainwp_module_cost_tracker_get_total_cost', 0 );
            $count_extensions = is_array( $extensions ) ? count( $extensions ) : 0;

            $started_done = true;

            if ( empty( $count_sites ) || empty( $count_clients ) || empty( $count_costs ) || empty( $count_extensions ) ) {
                $started_done = false;
            }

            if ( $started_done && MainWP_Utility::show_mainwp_message( 'notice', 'get-started' ) ) {
                ?>
                <div class="ui green message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="get-started"></i>
                    <div class="header"><?php esc_html_e( 'Congratulations!', 'mainwp' ); ?></div>
                    <?php esc_html_e( 'You can now use this widget as a handy list of shortcuts. If you prefer, you can also hide this widget by accessing the Page Settings.', 'mainwp' ); ?>
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
            <div class="ui mini fluid vertical steps">
                <a class="<?php echo empty( $count_sites ) ? '' : 'completed'; ?> step" href="admin.php?page=managesites&do=new">
                    <i class="globe icon"></i>
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Add First Site', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Click here to start connecting your sites.', 'mainwp' ); ?></div>
                    </div>
                </a>
                <a class="<?php echo empty( $count_clients ) ? '' : 'completed'; ?> step" href="admin.php?page=ClientAddNew">
                    <i class="users icon"></i>
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Add First Client', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Click here to start adding your clients.', 'mainwp' ); ?></div>
                    </div>
                </a>
                <a class="<?php echo empty( $count_costs ) ? '' : 'completed'; ?> step" href="admin.php?page=CostTrackerAdd">
                    <i class="dollar sign icon"></i>
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Add First Cost', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Start tracking your expenses.', 'mainwp' ); ?></div>
                    </div>
                </a>
                <a class="<?php echo empty( $count_extensions ) ? '' : 'completed'; ?> step" href="admin.php?page=Extensions">
                    <i class="puzzle icon"></i>
                    <div class="content">
                        <div class="title"><?php esc_html_e( 'Install Extensions', 'mainwp' ); ?></div>
                        <div class="description"><?php esc_html_e( 'Extend your MainWP Dashboard functionality.', 'mainwp' ); ?></div>
                    </div>
                </a>
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
}
