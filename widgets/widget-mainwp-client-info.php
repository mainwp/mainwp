<?php
/**
 * MainWP Client Info Widget
 *
 * Displays the Client Info.
 *
 * @package MainWP/MainWP_Client_Info
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Info
 *
 * Displays the Client info.
 */
class MainWP_Client_Info { //phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace  -- NOSONAR - complexity.

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
        static::render_site_info();
    }

    /**
     * Method render_site_info()
     *
     * Grab Child Site Info and render.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function render_site_info() {
        $current_wpid = MainWP_System_Utility::get_current_wpid();
        if ( empty( $current_wpid ) ) {
            return;
        }

        $website = MainWP_DB::instance()->get_website_by_id( $current_wpid, true );

        static::render_info( $website );
    }

    /**
     * Render Sites Info.
     *
     * @param object $website Object containing the child site info.
     */
    public static function render_info( $website ) { //phpcs:ignore -- NOSONAR - complex.
        $client_info = $website->client_id ? MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $website->client_id, ARRAY_A ) : false;

        ?>
        <h2 class="ui header handle-drag mainwp-widget-header">
            <?php
            /**
             * Filter: mainwp_clients_info_widget_title
             *
             * Filters the Site info widget title text.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.1
             */
            echo esc_html( apply_filters( 'mainwp_clients_info_widget_title', esc_html__( 'Client Info', 'mainwp' ), $website ) );
            ?>
            <div class="sub header"><?php esc_html_e( 'Client Information', 'mainwp' ); ?></div>
        </h2>
        <div class="mainwp-widget-site-info mainwp-scrolly-overflow">
            <?php
            /**
             * Actoin: mainwp_clients_info_widget_top
             *
             * Fires at the top of the Site Info widget on the Individual site overview page.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_clients_info_widget_top', $website );
            ?>
            <?php if ( $client_info ) : ?>
                <?php
                if ( 0 === intval( $client_info['suspended'] ) ) {
                    $client_status = '<span class="ui green basic button">' . esc_html__( 'Active', 'mainwp' ) . '</span>';
                } elseif ( 1 === intval( $client_info['suspended'] ) ) {
                    $client_status = '<span class="ui yellow button">' . esc_html__( 'Suspended', 'mainwp' ) . '</span>';
                } elseif ( 2 === intval( $client_info['suspended'] ) ) {
                    $client_status = '<span class="ui blue button">' . esc_html__( 'Lead', 'mainwp' ) . '</span>';
                } elseif ( 3 === intval( $client_info['suspended'] ) ) {
                    $client_status = '<span class="ui red button">' . esc_html__( 'Lost', 'mainwp' ) . '</span>';
                }

                /**
                 * Action: mainwp_clients_info_table_top
                 *
                 * Fires at the top of the Site Info table in Site Info widget on the Individual site overview page.
                 *
                 * @param object $website Object containing the child site info.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_clients_info_table_top', $website );
                ?>
                <div class="ui grid">
                    <div class="sixteen wide center aligned column">
                        <div class="ui image">
                            <?php echo MainWP_Client_Handler::get_client_contact_image( $client_info, 'client', 'small' ); //phpcs:ignore -- ok.  ?>
                        </div>
                    </div>
                    <div class="sixteen wide center aligned column">
                        <h2 class="ui center aligned header">
                            <div class="content">
                                <?php echo esc_html( $client_info['name'] ); ?>
                                <?php if ( isset( $client_info['created'] ) && ! empty( $client_info['created'] ) ) : ?>
                                <div class="sub header">
                                    <i class="calendar grey icon"></i> <?php esc_html_e( 'Added ', 'mainwp' ); ?><span data-tooltip="<?php echo esc_attr( MainWP_Utility::format_date( $client_info['created'] ) ); ?>" data-inverted="" data-position="top center"><?php echo MainWP_Utility::time_elapsed_string( $client_info['created'] ); //phpcs:ignore -- ok. ?></span>.
                                </div>
                                <?php endif; ?>
                            </div>
                        </h2>
                    </div>
                    <div class="sixteen wide center aligned column">
                        <?php echo $client_status; //phpcs:ignore -- ok. ?>
                    </div>

                    <div class="sixteen wide center aligned column">
                        <?php if ( isset( $client_info['client_email'] ) && '' !== $client_info['client_email'] ) : ?>
                            <a href="mailto:<?php echo esc_url( $client_info['client_email'] ); ?>" class="ui basic icon button" target="_blank" data-tooltip="<?php echo esc_attr( $client_info['client_email'] ); ?>" data-inverted="" data-position="top center">
                                <i class="envelope grey icon"></i>
                            </a>
                        <?php endif; ?>
                        <?php if ( isset( $client_info['client_phone'] ) && '' !== $client_info['client_phone'] ) : ?>
                            <a href="tel:<?php echo esc_url( $client_info['client_phone'] ); ?>" class="ui basic icon button" target="_blank" data-tooltip="<?php echo esc_attr( $client_info['client_phone'] ); ?>" data-inverted="" data-position="top center">
                                <i class="phone grey icon"></i>
                            </a>
                        <?php endif; ?>


                        <?php if ( isset( $client_info['client_facebook'] ) && '' !== $client_info['client_facebook'] ) : ?>
                            <a href="<?php echo esc_url( $client_info['client_facebook'] ); ?>" class="ui basic icon button" target="_blank"><i class="facebook grey icon"></i></a>
                        <?php endif; ?>
                        <?php if ( isset( $client_info['client_twitter'] ) && '' !== $client_info['client_twitter'] ) : ?>
                            <a href="<?php echo esc_url( $client_info['client_twitter'] ); ?>" class="ui basic icon button" target="_blank"><i class="twitter grey icon"></i></a>
                        <?php endif; ?>
                        <?php if ( isset( $client_info['client_instagram'] ) && '' !== $client_info['client_instagram'] ) : ?>
                            <a href="<?php echo esc_url( $client_info['client_instagram'] ); ?>" class="ui basic icon button" target="_blank"><i class="instagram grey icon"></i></a>
                        <?php endif; ?>
                        <?php if ( isset( $client_info['client_linkedin'] ) && '' !== $client_info['client_linkedin'] ) : ?>
                            <a href="<?php echo esc_url( $client_info['client_linkedin'] ); ?>" class="ui basic icon button" target="_blank"><i class="linkedin grey icon"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                /**
                 * Action: mainwp_clients_info_table_bottom
                 *
                 * Fires at the bottom of the Site Info table in Site Info widget on the Individual site overview page.
                 *
                 * @param object $website Object containing the child site info.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_clients_info_table_bottom', $website );
                ?>
            <?php else : ?>
                <?php MainWP_UI::render_empty_element_placeholder(); ?>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_clients_info_widget_bottom
             *
             * Fires at the bottom of the Site Info widget on the Individual site overview page.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_clients_info_widget_bottom', $website );
            ?>
        </div>
        <div class="mainwp-widget-footer"></div>
        <?php
    }
}
