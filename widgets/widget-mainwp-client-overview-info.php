<?php
/**
 * MainWP Client Overview Info Widget
 *
 * Displays the Client Info.
 *
 * @package MainWP/MainWP_Client_Overview_Info
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Overview_Info
 *
 * Displays the Client info.
 */
class MainWP_Client_Overview_Info { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
        $client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( empty( $client_id ) ) {
            return;
        }
        static::render_client_overview( $client_id );
    }


    /**
     * Render client overview Info.
     *
     * @param object $client_id Client ID.
     */
    public static function render_client_overview( $client_id ) {  // phpcs:ignore -- NOSONAR - complex function.
        $params        = array(
            'with_selected_sites' => true,
            'with_tags'           => true,
        );
        $client_info   = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id, ARRAY_A, $params );
        $client_status = '';
        if ( 0 === intval( $client_info['suspended'] ) ) {
            $client_status = '<span class="ui green basic button">' . esc_html__( 'Active', 'mainwp' ) . '</span>';
        } elseif ( 1 === intval( $client_info['suspended'] ) ) {
            $client_status = '<span class="ui yellow button">' . esc_html__( 'Suspended', 'mainwp' ) . '</span>';
        } elseif ( 2 === intval( $client_info['suspended'] ) ) {
            $client_status = '<span class="ui blue button">' . esc_html__( 'Lead', 'mainwp' ) . '</span>';
        } elseif ( 3 === intval( $client_info['suspended'] ) ) {
            $client_status = '<span class="ui red button">' . esc_html__( 'Lost', 'mainwp' ) . '</span>';
        }

        $client_contacts = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'client_id', $client_id, ARRAY_A ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        ?>
        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="twelve wide column">
                    <h2 class="ui header handle-drag">
                        <?php echo esc_html__( 'Client Info', 'mainwp' ); ?>
                    </h2>
                </div>
                <div class="four wide right aligned column">

                </div>
            </div>
        </div>

        <div class="mainwp-widget-client-card mainwp-scrolly-overflow" client-id="<?php echo intval( $client_id ); ?>">
            <?php
            /**
             * Actoin: mainwp_clients_overview_overview_widget_top
             *
             * Fires at the top of the Site Info widget on the Individual site overview page.
             *
             * @param object $client_info Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_clients_overview_overview_widget_top', $client_info );
            ?>
            <?php if ( $client_info ) : ?>
                <?php $selected_sites = isset( $client_info['selected_sites'] ) ? trim( $client_info['selected_sites'] ) : ''; ?>
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
                                    <i class="calendar grey icon"></i> <?php esc_html_e( 'Added ', 'mainwp' ); ?><span data-tooltip="<?php esc_attr_e( MainWP_Utility::format_date( $client_info['created'] ) ); ?>" data-inverted="" data-position="top center"><?php echo MainWP_Utility::time_elapsed_string( $client_info['created'] ); //phpcs:ignore -- ok. ?></span>.
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
            <?php endif; ?>
            <?php if ( $client_contacts ) : ?>
                <div class="ui divider"></div>
                <?php foreach ( $client_contacts as $contact ) : ?>
                    <h2 class="ui header">
                        <?php echo MainWP_Client_Handler::get_client_contact_image( $contact, 'contact', 'small' ); //phpcs:ignore -- ok.  ?>
                        <div class="content">
                            <?php echo esc_html( $contact['contact_name'] ); ?>
                            <div class="sub header"><?php echo esc_html( $contact['contact_role'] ); ?></div>
                        </div>
                    </h2>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php
            /**
             * Action: mainwp_clients_overview_overview_widget_bottom
             *
             * Fires at the bottom of the Site Info widget on the Individual site overview page.
             *
             * @param object $client_info Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_clients_overview_overview_widget_bottom', $client_info );
            ?>
        </div>
        <div class="mainwp-widget-footer" client-id="<?php echo intval( $client_id ); ?>">
            <a href="admin.php?page=ClientAddNew&client_id=<?php echo intval( $client_id ); ?>" class="ui green mini button"><?php esc_html_e( 'Edit Client', 'mainwp' ); ?></a>
            <?php if ( 0 === intval( $client_info['suspended'] ) || 1 === intval( $client_info['suspended'] ) ) : ?>
                <a href="javascript:void(0);" suspend-status="<?php echo intval( $client_info['suspended'] ); ?>" class="ui basic mini button client-suspend-unsuspend-sites"><?php echo empty( $client_info['suspended'] ) ? esc_html__( 'Suspend', 'mainwp' ) : esc_html__( 'Unsuspend', 'mainwp' ); ?></a>
            <?php endif; ?>
            <?php if ( is_plugin_active( 'mainwp-pro-reports-extension/mainwp-pro-reports-extension.php' ) ) { ?>
                <a class="ui basic mini right floated button" href="admin.php?page=Extensions-Mainwp-Pro-Reports-Extension&tab=report&action=newreport&selected_sites=<?php esc_html_e( $selected_sites ); ?>"><?php echo esc_html__( 'Create Report', 'mainwp' ); ?></a>
            <?php } ?>
        </div>
        <?php
    }
}
