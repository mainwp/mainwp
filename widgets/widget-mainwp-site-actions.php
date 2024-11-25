<?php
/**
 * MainWP  Site Actions Widget
 *
 * Displays the Site Actions Info.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Site_Actions
 *
 * Displays the Site Actions.
 */
class MainWP_Site_Actions { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     *
     * @return mixed render_site_info()
     */
    public static function render() {
        $current_wpid = MainWP_System_Utility::get_current_wpid();
        $website      = null;
        $actions_info = array();
        if ( $current_wpid ) {
            $params       = array(
                'wpid'        => $current_wpid,
                'where_extra' => ' AND dismiss = 0 ',
            );
            $website      = MainWP_DB::instance()->get_website_by_id( $current_wpid );
            $actions_info = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
        } elseif ( isset( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $websites  = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client_id );
            $site_ids  = array();

            foreach ( $websites as $website ) {
                $site_ids[] = $website->id;
            }

            if ( ! empty( $site_ids ) ) {
                $limit        = apply_filters( 'mainwp_widget_site_actions_limit_number', 50 );
                $params       = array(
                    'limit'       => $limit,
                    'where_extra' => ' AND dismiss = 0 ',
                    'wpid'        => $site_ids,
                );
                $actions_info = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
            }
        } else {
            $limit        = apply_filters( 'mainwp_widget_site_actions_limit_number', 50 );
            $params       = array(
                'limit'       => $limit,
                'where_extra' => ' AND dismiss = 0 ',
            );
            $actions_info = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
        }
        static::render_info( $actions_info, $website );
    }

    /**
     * Render Sites actions Info.
     *
     * @param object $actions_info Sites actions info.
     * @param object $website Sites info.
     */
    private static function render_info( $actions_info, $website ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $actions_info ) ) {
            $actions_info = array();
        }
        ?>

        <div class="mainwp-widget-header">
            <h3 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_non_mainwp_changes_widget_title
                 *
                 * Filters the Site info widget title text.
                 *
                 * @param object $website Object containing the child site info.
                 *
                 * @since 4.1
                 */
                echo esc_html( apply_filters( 'mainwp_non_mainwp_changes_widget_title', esc_html__( 'Non-MainWP Changes', 'mainwp' ), $website ) );
                ?>
                <div class="sub header"><?php esc_html_e( 'The most recent changes made to your Child Sites that were not done through your MainWP Dashboard.', 'mainwp' ); ?></div>
            </h3>
        </div>

        <div id="mainwp-widget-site-actions" class="mainwp-scrolly-overflow">
            <?php
            /**
             * Actoin: mainwp_non_mainwp_changes_widget_top
             *
             * Fires at the top of the Site Info widget on the Individual site overview page.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_non_mainwp_changes_widget_top', $website );
            ?>
            <?php if ( $actions_info ) : ?>
                <?php
                /**
                 * Action: mainwp_non_mainwp_changes_table_top
                 *
                 * Fires at the top of the Site Info table in Site Info widget on the Individual site overview page.
                 *
                 * @param object $website Object containing the child site info.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_non_mainwp_changes_table_top', $website );
                ?>
                <div class="ui small feed" id="mainwp-non-mainwp-changes-feed">
                    <?php foreach ( $actions_info as $data ) : ?>
                        <?php
                        if ( empty( $data->action_user ) || empty( $data->meta_data ) ) {
                            continue;
                        }

                        $meta_data = json_decode( $data->meta_data );

                        $action_class = '';
                        if ( 'activated' === $data->action ) {
                            $action_class = 'green check';
                        } elseif ( 'deactivated' === $data->action ) {
                            $action_class = 'yellow times';
                        } elseif ( 'installed' === $data->action ) {
                            $action_class = 'download blue';
                        } elseif ( 'updated' === $data->action ) {
                            $action_class = 'sync grey';
                        } elseif ( 'deleted' === $data->action ) {
                            $action_class = 'trash alternate outline red';
                        }
                        ?>
                    <div class="event">
                        <div class="label">
                            <i class="<?php echo esc_attr( $action_class ); ?> icon"></i>
                        </div>
                        <div class="content">
                            <div class="summary">
                                <a href="javascript:void(0)" class="mainwp-event-action-dismiss right floated" action-id="<?php echo intval( $data->action_id ); ?>"><i class="times icon"></i></a>
                                <?php echo esc_html( $data->action_user ); ?>
                                <?php echo esc_html( ucfirst( $data->action ) ); ?> <?php echo isset( $meta_data->name ) && '' !== $meta_data->name ? esc_html( $meta_data->name ) : 'WP Core'; ?> <?php echo 'wordpress' !== $data->context ? esc_html( rtrim( $data->context, 's' ) ) : 'WordPress'; //phpcs:ignore -- text. ?>
                                <?php if ( empty( $website ) || isset( $_GET['client_id'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
                                on <a href="admin.php?page=managesites&dashboard=<?php echo esc_attr( $data->wpid ); ?>"><?php echo esc_html( $data->name ); ?></a>
                                <?php endif; ?>
                                <div class="date">
                                    <?php echo esc_html( MainWP_Utility::time_elapsed_string( $data->created ) ); ?>
                                </div>
                                
                            </div>
                            
                        </div>

                    </div>
                    <?php endforeach; ?>
                    <?php
                    /**
                     * Action: mainwp_non_mainwp_changes_table_bottom
                     *
                     * Fires at the bottom of the Site Info table in Site Info widget on the Individual site overview page.
                     *
                     * @param object $website Object containing the child site info.
                     *
                     * @since 4.0
                     */
                    do_action( 'mainwp_non_mainwp_changes_table_bottom', $website );
                    ?>
                </div>
            <?php else : ?>
                <?php MainWP_UI::render_empty_element_placeholder(); ?>
            <?php endif; ?>
        </div>
        <?php
        $params       = array(
            'total_count' => true,
            'dismiss'     => 0,
        );
        $totalRecords = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
        ?>
        <div class="mainwp-widget-footer">
            <div class="ui two columns stackable grid">
                <div class="left aligned middle aligned column">

                </div>
                <div class="right aligned middle aligned column">
                    <?php if ( $totalRecords ) : ?>
                        <a href="admin.php?page=NonMainWPChanges"><?php printf( esc_html__( 'See all %d', 'mainwp' ), intval( $totalRecords ) ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        /**
         * Action: mainwp_non_mainwp_changes_widget_bottom
         *
         * Fires at the bottom of the Site Info widget on the Individual site overview page.
         *
         * @param object $website Object containing the child site info.
         *
         * @since 4.0
         */
        do_action( 'mainwp_non_mainwp_changes_widget_bottom', $website );
    }
}
