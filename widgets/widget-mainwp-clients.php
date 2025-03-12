<?php
/**
 * MainWP Clients Widget
 *
 * Displays the Clients list.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Clients
 *
 * Displays the Site Actions.
 */
class MainWP_Clients { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * @return mixed render_clients()
     */
    public static function render() {
        $clients = MainWP_DB_Client::instance()->get_wp_clients();
        static::render_clients( $clients );
    }

    /**
     * Render Clients Info.
     *
     * @param mixed $clients Clients data.
     */
    private static function render_clients( $clients ) { // phpcs:ignore -- NOSONAR - complex.
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_clients_widget_title
                 *
                 * Filters the Clients widget title text.
                 *
                 * @since 4.4
                 */
                echo esc_html( apply_filters( 'mainwp_clients_widget_title', esc_html__( 'Clients', 'mainwp' ) ) );
                ?>
                <div class="sub header"><?php esc_html_e( 'View and manage all clients connected to your MainWP Dashboard.', 'mainwp' ); ?></div>
            </h2>
        </div>
        <div id="mainwp-clients-widget"  class="mainwp-scrolly-overflow">
                <?php
                /**
                 * Actoin: mainwp_clients_widget_top
                 *
                 * Fires at the top of the Clients widget on the overview page.
                 *
                 * @param object $clients Object containing the clients info.
                 *
                 * @since 4.4
                 */
                do_action( 'mainwp_clients_widget_top', $clients );
                ?>
                <?php if ( $clients ) : ?>
                <table class="ui table" id="mainwp-clients-widget-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
                            <th scope="col" class="collapsing"><?php esc_html_e( 'Primary Contact', 'mainwp' ); ?></th>
                            <th scope="col" class="no-sort collapsing"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $clients as $client ) : ?>
                        <?php $client_display_image = MainWP_Client_Handler::get_client_contact_image( $client ); ?>
                        <tr>
                            <td class="left aligned middle aligned">
                                <a href="admin.php?page=ManageClients&client_id=<?php echo intval( $client['client_id'] ); ?>">
                                <?php echo $client_display_image; //phpcs:ignore -- ok. ?> <?php echo esc_html( $client['name'] ); ?>
                                </a>
                                <br/>
                                <?php if ( isset( $client['client_email'] ) && '' !== $client['client_email'] ) : ?>
                                    <span class="ui small text"><a href="mailto:<?php echo esc_attr( $client['client_email'] ); ?>" class="ui grey text"><i class="envelope grey icon"></i> <?php echo esc_html( $client['client_email'] ); ?></a></span><br/>
                                <?php endif; ?>
                                <?php if ( isset( $client['client_phone'] ) && '' !== $client['client_phone'] ) : ?>
                                    <span class="ui small text"><a href="tel:<?php echo esc_attr( $client['client_phone'] ); ?>" class="ui grey text"><i class="phone alternate grey icon"></i> <?php echo esc_html( $client['client_phone'] ); ?></a></span>
                                <?php endif; ?>
                            </td>
                            <td class="left aligned middle aligned">
                                <?php
                                $contact = false;
                                if ( isset( $client['primary_contact_id'] ) && '' !== $client['primary_contact_id'] ) {
                                    $contact = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_id', $client['primary_contact_id'] );
                                    if ( $contact ) {
                                        ?>
                                        <?php echo esc_html( $contact->contact_name ); ?> <?php echo ( isset( $contact->contact_role ) && '' !== $contact->contact_role ) ? ' - ' . esc_html( $contact->contact_role ) : ''; ?><br/>
                                        <?php if ( isset( $contact->contact_email ) && '' !== $contact->contact_email ) : ?>
                                            <span class="ui small text"><a href="mailto:<?php echo esc_attr( $contact->contact_email ); ?>" class="ui grey text"><i class="envelope grey icon"></i> <?php echo esc_html( $contact->contact_email ); ?></a></span><br/>
                                        <?php endif; ?>
                                        <?php if ( isset( $contact->contact_phone ) && '' !== $contact->contact_phone ) : ?>
                                            <span class="ui small text"><a href="tel:<?php echo esc_attr( $contact->contact_phone ); ?>" class="ui grey text"><i class="phone alternate grey icon"></i> <?php echo esc_html( $contact->contact_phone ); ?></a></span>
                                        <?php endif; ?>
                                        <?php
                                    }
                                }
                                if ( empty( $contact ) ) {
                                    echo esc_html__( 'No Contacts', 'mainwp' );
                                }
                                ?>
                            </td>
                            <td class="right aligned collapsing">
                                <div class="ui right pointing dropdown mainwp-768-hide" style="z-index:999">
                                <i class="ellipsis vertical icon"></i>
                                    <div class="menu">
                                        <a class="item" href="admin.php?page=ManageClients&client_id=<?php echo intval( $client['client_id'] ); ?>"><?php esc_html_e( 'View', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=ClientAddNew&client_id=<?php echo intval( $client['client_id'] ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <script type="text/javascript">
                jQuery( document ).ready( function() {
                    jQuery.fn.DataTable.ext.pager.numbers_length = 4;
                    jQuery( '#mainwp-clients-widget-table' ).DataTable( {
                        "info": false,
                        "layout": {
                            "bottom": 'paging',
                            "bottomStart": null,
                            "bottomEnd": null
                        },
                        "lengthMenu": [ [5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"] ],
                        "stateSave" : true,
                        "order"     : [ [1, 'asc'] ],
                        "columnDefs": [ {
                            "targets": 'no-sort',
                            "orderable": false
                        } ],
                    } );
                    setTimeout(() => {
                        mainwp_datatable_fix_menu_overflow('#mainwp-clients-widget-table', -50, 0 );
                    }, 2000 );
                } );
                </script>
                <?php else : ?>
                    <?php MainWP_UI::render_empty_element_placeholder(); ?>
                <?php endif; ?>
                <?php
                /**
                 * Action: mainwp_clients_widget_bottom
                 *
                 * Fires at the bottom of the Clients widget on the overview page.
                 *
                 * @param object $clients Object containing the child site info.
                 *
                 * @since 4.4
                 */
                do_action( 'mainwp_clients_widget_bottom', $clients );
                ?>
            </div>
            <div class="ui two columns stackable grid mainwp-widget-footer">
                <div class="left aligned middle aligned column">

                </div>
                <div class="right aligned middle aligned column">

                </div>
            </div>
        <?php
    }
}
