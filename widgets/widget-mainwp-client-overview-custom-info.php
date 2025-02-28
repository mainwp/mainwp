<?php
/**
 * MainWP Client Overview Custom Info Widget
 *
 * Displays the Client Custom Info.
 *
 * @package MainWP/MainWP_Client_Overview_Custom_Info
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Overview_Custom_Info
 *
 * Displays the Client Custom info.
 */
class MainWP_Client_Overview_Custom_Info { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
        static::render_addition_info( $client_id );
    }


    /**
     * Render client overview Info.
     *
     * @param object $client_id Client ID.
     */
    public static function render_addition_info( $client_id ) { // phpcs:ignore -- NOSONAR - complex.

        $client_info           = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id, ARRAY_A );
        $default_client_fields = MainWP_Client_Handler::get_default_client_fields();
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_clients_overview_info_widget_title
                 *
                 * Filters the Site info widget title text.
                 *
                 * @param object $client_info Object containing the child site info.
                 *
                 * @since 4.1
                 */
                echo esc_html( apply_filters( 'mainwp_clients_overview_info_widget_title', esc_html__( 'Additional Client Info', 'mainwp' ), $client_info ) );
                ?>
                <div class="sub header"></div>
            </h2>
        </div>
            <div class="mainwp-widget-client-card mainwp-scrolly-overflow">
                <?php
                /**
                 * Actoin: mainwp_clients_overview_info_widget_top
                 *
                 * Fires at the top of the Site Info widget on the Individual site overview page.
                 *
                 * @param object $client_info Object containing the child site info.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_clients_overview_info_widget_top', $client_info );
                ?>
                <?php
                if ( $client_info ) {
                    $custom_fields = MainWP_DB_Client::instance()->get_client_fields( true, $client_id );
                    ?>
                <table class="ui very compact mini table">
                    <tbody>
                    <?php
                    /**
                     * Action: mainwp_clients_overview_info_table_top
                     *
                     * Fires at the top of the Site Info table in Site Info widget on the Individual site overview page.
                     *
                     * @param object $client_info Object containing the child site info.
                     *
                     * @since 4.0
                     */
                    do_action( 'mainwp_clients_overview_info_table_top', $client_info );
                    ?>
                    <?php

                    if ( is_array( $custom_fields ) && ! empty( $custom_fields ) ) {
                        foreach ( $custom_fields as $field ) {
                            $field_value = '';
                            if ( isset( $default_client_fields[ $field->field_name ] ) ) {
                                $db_field = $default_client_fields[ $field->field_name ]['db_field'];
                                if ( ! empty( $db_field ) && isset( $client_info[ $db_field ] ) ) {
                                    $field_value = $client_info[ $db_field ];
                                }
                            } else {
                                $field_value = $field->field_value;
                            }

                            if ( empty( $field_value ) ) {
                                continue;
                            }
                            ?>
                            <tr>
                            <td><?php echo esc_html( $field->field_name ); ?></td>
                            <td><?php echo esc_html( $field_value ); ?></td>
                            </tr>
                            <?php
                        }
                    }

                    ?>
                    <?php
                    /**
                     * Action: mainwp_clients_overview_info_table_bottom
                     *
                     * Fires at the bottom of the Site Info table in Site Info widget on the Individual site overview page.
                     *
                     * @param object $client_info Object containing the child site info.
                     *
                     * @since 4.0
                     */
                    do_action( 'mainwp_clients_overview_info_table_bottom', $client_info );
                    ?>
                    </tbody>
                </table>
                    <?php
                } else {
                    MainWP_UI::render_empty_element_placeholder();
                }
                ?>
                <?php
                /**
                 * Action: mainwp_clients_overview_info_widget_bottom
                 *
                 * Fires at the bottom of the Site Info widget on the Individual site overview page.
                 *
                 * @param object $client_info Object containing the child site info.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_clients_overview_info_widget_bottom', $client_info );
                ?>
            </div>
            <div class="ui two columns grid mainwp-widget-footer">
                <div class="column"><a href="admin.php?page=ClientAddField" title="" class="ui mini basic button"><?php echo esc_html__( 'Add Custom Client Info', 'mainwp' ); ?></a></div>
                <div class="column"></div>
            </div>
            <?php
    }
}
