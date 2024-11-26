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
        <h3 class="ui header handle-drag mainwp-widget-header">
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
        </h3>
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

            <?php
            if ( $client_info ) {
                ?>
                <?php
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
                <div class="ui cards">
                    <div class="ui fluid small card">
                        <div class="content">
                            <div class="ui right floated">
                                <?php $client_display_image = MainWP_Client_Handler::get_client_contact_image( $client_info ); ?>
                                <?php echo $client_display_image; //phpcs:ignore -- NOSONAR - ok. ?>
                            </div>
                            <div class="header" style="margin-bottom:0">
                                <a href="admin.php?page=ManageClients&client_id=<?php echo intval( $client_info['client_id'] ); ?>"><?php echo esc_html( $client_info['name'] ); ?></a>
                            </div>
                            <div class="meta">
                                <a href="admin.php?page=ClientAddNew&client_id=<?php echo intval( $client_info['client_id'] ); ?>"><i class="envelope icon"></i> <?php echo esc_html( $client_info['client_email'] ); ?></a>
                            </div>
                            <?php if ( isset( $client_info['note'] ) && ! empty( $client_info['note'] ) ) : ?>
                            <div class="description">
                                <?php
                                $note     = html_entity_decode( $client_info['note'] );
                                $esc_note = MainWP_Utility::esc_content( $note );
                                echo $esc_note; //phpcs:ignore -- NOSONAR -ok.
                                ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="extra content">
                            <span class="right floated" data-tooltip="<?php echo esc_attr__( 'Created on ', 'mainwp' ) . esc_attr( MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $client_info['created'] ) ) ); ?>" data-inverted="" data-position="top right"><?php echo esc_html( MainWP_Utility::time_elapsed_string( $client_info['created'] ) ); ?></span>
                            <?php if ( isset( $client_info['client_facebook'] ) && ! empty( $client_info['client_facebook'] ) ) : ?>
                                <a href="<?php echo esc_url( $client_info['client_facebook'] ); ?>"><i class="facebook icon"></i></a>
                            <?php endif; ?>
                            <?php if ( isset( $client_info['client_twitter'] ) && ! empty( $client_info['client_twitter'] ) ) : ?>
                                <a href="<?php echo esc_url( $client_info['client_twitter'] ); ?>"><i class="twitter icon"></i></a>
                            <?php endif; ?>
                            <?php if ( isset( $client_info['client_instagram'] ) && ! empty( $client_info['client_instagram'] ) ) : ?>
                                <a href="<?php echo esc_url( $client_info['client_instagram'] ); ?>"><i class="instagram icon"></i></a>
                            <?php endif; ?>
                            <?php if ( isset( $client_info['client_linkedin'] ) && ! empty( $client_info['client_linkedin'] ) ) : ?>
                                <a href="<?php echo esc_url( $client_info['client_linkedin'] ); ?>"><i class="linkedin icon"></i></a>
                            <?php endif; ?>
                        </div>
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
                <?php
            } else {
                MainWP_UI::render_empty_element_placeholder();
            }
            ?>
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

        <div class="ui stackable two columns grid mainwp-widget-footer">
            <div class="left aligne middle aligned column">

            </div>
            <div class="right aligned middle aligned column">

            </div>
        </div>
        <?php
    }
}
