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

        <div class="ui grid mainwp-widget-header">
            <div class="sixteen wide column">
                <h3 class="ui header handle-drag">
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
                </h3>
            </div>
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

        if ( $started_done ) {
            if ( MainWP_Utility::show_mainwp_message( 'notice', 'get-started' ) ) {
                ?>
            <div class="ui green message">
                <i class="close icon mainwp-notice-dismiss" notice-id="get-started"></i>
                <div class="header"><?php esc_html_e( 'Congratulations!', 'mainwp' ); ?></div>
                <?php esc_html_e( 'You can now use this widget as a handy list of shortcuts. If you prefer, you can also hide this widget by accessing the Page Settings.', 'mainwp' ); ?>
            </div>
                <?php
            }
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
            <div class="ui hidden divider"></div>
            <div class="ui middle aligned relaxed list mainwp-get-started-widget-check-lists">
                <div class="item">
                    <?php
                    $text = esc_html__( 'Add first site', 'mainwp' );
                    if ( empty( $count_sites ) ) {
                        ?>
						<span style="min-width:45px;"><span class="circular ui large label" style="margin:0px 10px 0 5px">1</span></span> <a href="admin.php?page=managesites&do=new"><?php echo $text; //phpcs:ignore -- ok. ?></a>
                        <?php
                    } else {
                        ?>
						<i class="check circle green icon big" style="display:inline-block"></i> <a href="admin.php?page=managesites&do=new"><?php echo $text; //phpcs:ignore -- ok. ?></a>
                        <?php
                    }
                    ?>
                </div>

                <div class="item">
                    <?php
                    $text = esc_html__( 'Add first client', 'mainwp' );
                    if ( empty( $count_clients ) ) {
                        ?>
						<span style="min-width:45px;"><span class="circular ui large label" style="margin:0px 10px 0 5px">2</span></span><a href="admin.php?page=ClientAddNew"><?php echo $text; //phpcs:ignore -- ok. ?></a>
                        <?php
                    } else {
                        ?>
						<i class="check circle icon green big" style="display:inline-block"></i> <a href="admin.php?page=ClientAddNew"><?php echo $text; //phpcs:ignore -- ok. ?></a>
                        <?php
                    }
                    ?>
                </div>

                <div class="item">
                    <?php
                    $text = esc_html__( 'Add first thing to Cost Tracker', 'mainwp' );
                    if ( empty( $count_costs ) ) {
                        ?>
						<span style="min-width:45px;"><span class="circular ui large label" style="margin:0px 10px 0 5px">3</span></span> <a href="admin.php?page=CostTrackerAdd"><?php echo $text; //phpcs:ignore -- ok. ?></a>
                        <?php

                    } else {
                        ?>
						<i class="check circle icon green big" style="display:inline-block"></i> <a href="admin.php?page=CostTrackerAdd"><?php echo $text; //phpcs:ignore -- ok. ?></a>
                        <?php
                    }
                    ?>
                </div>
                
                <div class="item">
                    <?php
                    $text = esc_html__( 'Add first extension', 'mainwp' );
                    if ( empty( $count_extensions ) ) {
                        ?>
						<span style="min-width:45px;"><span class="circular ui large label" style="margin:0px 10px 0 5px">4</span></span> <a href="admin.php?page=Extensions"><?php echo $text; //phpcs:ignore -- ok. ?></a>
                        <?php
                    } else {
                        ?>
						<i class="check circle icon green big" style="display:inline-block"></i> <a href="admin.php?page=Extensions"><?php echo $text; //phpcs:ignore -- ok. ?></a>
                        <?php
                    }
                    ?>
                </div>
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
