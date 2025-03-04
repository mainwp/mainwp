<?php
/**
 * MainWP Client Overview Contacts Widget
 *
 * Displays the Client Contacts.
 *
 * @package MainWP/MainWP_Client_Overview_Contacts
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Overview_Contacts
 *
 * Displays the Client Contacts.
 */
class MainWP_Client_Overview_Contacts { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.


    /**
     * The contact variable.
     *
     * @var mixed Default null
     */
    public $contact = null;

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
    public function render() {
        if ( empty( $this->contact ) ) {
            return;
        }
        static::render_contact( $this->contact );
    }


    /**
     * Render client Contacts Info.
     *
     * @param object $contact_info The Contact.
     */
    public static function render_contact( $contact_info ) { // phpcs:ignore -- NOSONAR - complex.

        if ( empty( $contact_info ) ) {
            return;
        }

        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_clients_overview_contact_widget_title
                 *
                 * Filters the Site info widget title text.
                 *
                 * @param object $contact_info Object containing the child site info.
                 *
                 * @since 4.1
                 */
                echo esc_html( apply_filters( 'mainwp_clients_overview_contact_widget_title', __( 'Client Contact', 'mainwp' ), $contact_info ) );
                ?>
                <div class="sub header">
                    <?php echo esc_html( apply_filters( 'mainwp_clients_overview_contact_widget_sutbitle', __( 'Contact Information', 'mainwp' ), $contact_info ) ); ?>
                </div>
            </h2>
        </div>
        <div class="mainwp-widget-client-card mainwp-scrolly-overflow">
            <?php
            /**
             * Actoin: mainwp_clients_overview_contact_widget_top
             *
             * Fires at the top of the Site Info widget on the Individual site overview page.
             *
             * @param object $contact_info Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_clients_overview_contact_widget_top', $contact_info );
            ?>
            <?php if ( $contact_info ) : ?>
                <div class="ui grid">
                    <div class="sixteen wide center aligned column">
                        <div class="ui image">
                            <?php echo MainWP_Client_Handler::get_client_contact_image( $contact_info, 'contact', 'small' ); //phpcs:ignore -- ok. ?>
                        </div>
                    </div>
                    <div class="sixteen wide center aligned column">
                        <h2 class="ui center aligned header">
                            <div class="content">
                                <?php echo esc_html( $contact_info['contact_name'] ); ?>
                                <?php if ( isset( $contact_info['contact_role'] ) && ! empty( $contact_info['contact_role'] ) ) : ?>
                                <div class="sub header">
                                    <?php echo esc_html( $contact_info['contact_role'] ); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </h2>
                    </div>

                    <div class="sixteen wide center aligned column">
                        <?php if ( isset( $contact_info['contact_email'] ) && '' !== $contact_info['contact_email'] ) : ?>
                            <a href="mailto:<?php echo esc_url( $contact_info['contact_email'] ); ?>" class="ui basic icon button" target="_blank" data-tooltip="<?php echo esc_attr( $contact_info['contact_email'] ); ?>" data-inverted="" data-position="top center">
                                <i class="envelope grey icon"></i>
                            </a>
                        <?php endif; ?>
                        <?php if ( isset( $contact_info['contact_phone'] ) && '' !== $contact_info['contact_phone'] ) : ?>
                            <a href="tel:<?php echo esc_url( $contact_info['contact_phone'] ); ?>" class="ui basic icon button" target="_blank" data-tooltip="<?php echo esc_attr( $contact_info['contact_phone'] ); ?>" data-inverted="" data-position="top center">
                                <i class="phone grey icon"></i>
                            </a>
                        <?php endif; ?>


                        <?php if ( isset( $contact_info['facebook'] ) && '' !== $contact_info['facebook'] ) : ?>
                            <a href="<?php echo esc_url( $contact_info['facebook'] ); ?>" class="ui basic icon button" target="_blank"><i class="facebook grey icon"></i></a>
                        <?php endif; ?>
                        <?php if ( isset( $contact_info['twitter'] ) && '' !== $contact_info['twitter'] ) : ?>
                            <a href="<?php echo esc_url( $contact_info['twitter'] ); ?>" class="ui basic icon button" target="_blank"><i class="twitter grey icon"></i></a>
                        <?php endif; ?>
                        <?php if ( isset( $contact_info['instagram'] ) && '' !== $contact_info['instagram'] ) : ?>
                            <a href="<?php echo esc_url( $contact_info['instagram'] ); ?>" class="ui basic icon button" target="_blank"><i class="instagram grey icon"></i></a>
                        <?php endif; ?>
                        <?php if ( isset( $contact_info['linkedin'] ) && '' !== $contact_info['linkedin'] ) : ?>
                            <a href="<?php echo esc_url( $contact_info['linkedin'] ); ?>" class="ui basic icon button" target="_blank"><i class="linkedin grey icon"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_clients_overview_contact_widget_bottom
             *
             * Fires at the bottom of the Site Info widget on the Individual site overview page.
             *
             * @param object $contact_info Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_clients_overview_contact_widget_bottom', $contact_info );
            ?>
        </div>
        <?php
    }
}
