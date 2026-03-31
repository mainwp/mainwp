<?php
/**
 * MainWP Module Cost Tracker Import class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_System_Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Cost_Tracker_Import
 */
class Cost_Tracker_Import {

    /**
     * Static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Cost_Tracker_Import
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor
     *
     * Runs each time the class is called.
     */
    public function __construct() {
    }

    /**
     * Renders the Import Cost form.
     */
    public static function render_import_costs() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_cost_tracker' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage cost tracker', 'mainwp' ) );
            return;
        }

        Cost_Tracker_Admin::render_header( 'ImportCosts' );

        $title_page = esc_html__( 'Import Operational Costs', 'mainwp' );

        $has_import_data = ! empty( $_POST['mainwp_cost_tracker_import_add'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $has_import_data && check_admin_referer( 'mainwp-admin-nonce' ) ) {
            static::render_import_cost_modal();
        }
        ?>
        <div class="ui padded segment" id="mainwp-import-costs">
            <form method="POST" action="" enctype="multipart/form-data" id="mainwp_cost_tracker_import_form" class="ui form">
                <div>
                    <?php $el_id_mes_zn_1 = 'mainwp-message-zone'; ?>
                    <div id="<?php echo esc_attr( $el_id_mes_zn_1 ); ?>" class="ui message" style="display:none"></div>
                    <h3 class="ui dividing header">
                        <?php echo esc_html( $title_page ); ?>
                        <div class="sub header"><?php esc_html_e( 'Import multiple costs to your Cost Tracker.', 'mainwp' ); ?></div>
                    </h3>
                    <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                    <div class="ui grid field">
                        <label class="three wide column middle aligned" for="mainwp_cost_tracker_import_file_bulkupload"><?php esc_html_e( 'Upload CSV', 'mainwp' ); ?> (<a href="<?php echo esc_url( MAINWP_PLUGIN_URL . 'assets/csv/sample_costs.csv' ); ?>" target="_blank"><?php esc_html_e( 'Download Sample', 'mainwp' ); ?></a>)</label>
                        <div class="nine wide column">
                            <div class="ui file input">
                                <input type="file" name="mainwp_cost_tracker_import_file_bulkupload" id="mainwp_cost_tracker_import_file_bulkupload" accept="text/comma-separated-values"/>
                            </div>
                        </div>
                        <div class="ui toggle checkbox four wide column middle aligned">
                            <input type="checkbox" name="mainwp_cost_tracker_import_chk_header_first" checked="checked" id="mainwp_cost_tracker_import_chk_header_first" value="1"/>
                            <label for="mainwp_cost_tracker_import_chk_header_first"><?php esc_html_e( 'CSV file contains a header', 'mainwp' ); ?></label>
                        </div>
                    </div>
                </div>
                <div class="ui segment">
                    <div class="ui divider"></div>
                    <input type="submit" name="mainwp_cost_tracker_import_add" id="mainwp_cost_tracker_import_bulkadd" class="ui big green disabled button" value="<?php echo esc_attr( $title_page ); ?>"/>
                </div>
            </form>
            <script type="text/javascript">
                jQuery( document ).ready( function() {
                    jQuery( '#mainwp_cost_tracker_import_file_bulkupload' ).on( 'change', function() {
                        const messageZone = jQuery( '#<?php echo esc_js( $el_id_mes_zn_1 ); ?>' );
                        messageZone.hide().removeClass( 'red' ).html( '' );

                        if ( this.files.length > 0 ) {
                            const fileName = this.files[0].name;
                            const fileExtension = fileName.split( '.' ).pop().toLowerCase();

                            if ( fileExtension !== 'csv' ) {
                                messageZone.addClass( 'red' ).html( '<?php esc_html_e( 'Please select a valid CSV file.', 'mainwp' ); ?>' ).show();
                                jQuery( '#mainwp_cost_tracker_import_bulkadd' ).addClass( 'disabled' );
                                this.value = '';
                                return;
                            }

                            jQuery( '#mainwp_cost_tracker_import_bulkadd' ).removeClass( 'disabled' );
                        } else {
                            jQuery( '#mainwp_cost_tracker_import_bulkadd' ).addClass( 'disabled' );
                        }
                    } );
                } );
            </script>
        </div>
        <?php
    }

    /**
     * Method render_import_cost_modal()
     *
     * Render HTML import cost modal.
     */
    public static function render_import_cost_modal() {
        ?>
        <div class="ui large modal mainwp-qsw-import-cost-modal" id="mainwp-import-cost-modal">
            <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Import Operational Costs', 'mainwp' ); ?></div>
            <div class="scrolling content">
                <?php static::render_import_cost_row_modal(); ?>
            </div>
            <div class="actions">
                <div class="ui two column grid">
                    <div class="left aligned middle aligned column">
                        <input type="button" name="mainwp_managecosts_btn_import" id="mainwp_managecosts_btn_import" class="ui basic button" value="<?php esc_attr_e( 'Pause', 'mainwp' ); ?>"/>
                        <input type="button" name="mainwp-import-costs-modal-try-again" id="mainwp-import-costs-modal-try-again" class="ui basic button" value="<?php esc_attr_e( 'Try Again', 'mainwp' ); ?>" style="display:none;"/>
                    </div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( "#mainwp-import-cost-modal" ).modal( {
                    closable: false,
                    onHide: function() {
                        location.reload();
                    }
                } ).modal( 'show' );
            } );
        </script>
        <?php
    }

    /**
     * Method render_import_cost_row_modal()
     *
     * Render HTML import cost row modal.
     */
    public static function render_import_cost_row_modal() {
        ?>
        <div id="mainwp-importing-costs" class="ui active dimmer">
            <div class="ui double text loader"><?php esc_html_e( 'Importing...', 'mainwp' ); ?></div>
        </div>
        <div class="ui message" id="mainwp-import-costs-status-message">
            <i class="notched circle loading icon"></i> <?php echo esc_html__( 'Importing...', 'mainwp' ); ?>
        </div>
        <?php
        $has_file_upload = isset( $_FILES['mainwp_cost_tracker_import_file_bulkupload'] ) && isset( $_FILES['mainwp_cost_tracker_import_file_bulkupload']['error'] ) && UPLOAD_ERR_OK === $_FILES['mainwp_cost_tracker_import_file_bulkupload']['error']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified earlier in the request.

        if ( ! $has_file_upload ) {
            ?>
            <div class="ui red message">
                <?php esc_html_e( 'No file was uploaded or there was an error uploading the file. Please try again.', 'mainwp' ); ?>
            </div>
            <script type="text/javascript">
                jQuery(document).ready(function(){
                    jQuery('#mainwp-importing-costs').hide();
                    jQuery('#mainwp-import-costs-status-message').hide();
                    jQuery('#mainwp_managecosts_btn_import').hide();
                    jQuery('#mainwp-import-costs-modal-try-again').show();
                });
            </script>
            <?php
            return;
        }

        $uploaded_filename = isset( $_FILES['mainwp_cost_tracker_import_file_bulkupload']['name'] ) ? sanitize_file_name( $_FILES['mainwp_cost_tracker_import_file_bulkupload']['name'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- File upload field.

        $file_extension = strtolower( pathinfo( $uploaded_filename, PATHINFO_EXTENSION ) );

        if ( 'csv' !== $file_extension ) {
            ?>
            <div class="ui red message">
                <?php esc_html_e( 'Invalid file type. Please upload a CSV file.', 'mainwp' ); ?>
            </div>
            <script type="text/javascript">
                jQuery(document).ready(function(){
                    jQuery('#mainwp-importing-costs').hide();
                    jQuery('#mainwp-import-costs-status-message').hide();
                    jQuery('#mainwp_managecosts_btn_import').hide();
                    jQuery('#mainwp-import-costs-modal-try-again').show();
                });
            </script>
            <?php
            return;
        }

        $import_cost_data = static::handle_cost_import_files();

        if ( empty( $import_cost_data ) || ! is_array( $import_cost_data ) || empty( $import_cost_data['data'] ) ) {
            ?>
            <div class="ui red message">
                <?php esc_html_e( 'No valid cost data found in the uploaded CSV file. Please check the file format and ensure it contains valid cost entries.', 'mainwp' ); ?>
            </div>
            <script type="text/javascript">
                jQuery(document).ready(function(){
                    jQuery('#mainwp-importing-costs').hide();
                    jQuery('#mainwp-import-costs-status-message').hide();
                    jQuery('#mainwp_managecosts_btn_import').hide();
                    jQuery('#mainwp-import-costs-modal-try-again').show();
                });
            </script>
            <?php
            return;
        }

        $row         = 0;
        $header_line = trim( $import_cost_data['header_line'] );
        foreach ( $import_cost_data['data'] as $val_cost_data ) {
            $encoded  = wp_json_encode( $val_cost_data );
            $original = implode(
                ', ',
                array_map(
                    function ( $item ) {
                        return is_array( $item ) ? implode( ';', $item ) : $item;
                    },
                    $val_cost_data
                )
            );
            ?>
            <input type="hidden" id="mainwp_managecosts_import_csv_line_<?php echo esc_attr( $row + 1 ); ?>" value="" encoded-data="<?php echo esc_attr( $encoded ); ?>" original="<?php echo esc_attr( $original ); ?>"/>
            <?php
            ++$row;
        }
        ?>
        <input type="hidden" id="mainwp_managecosts_do_import" value="1"/>
        <input type="hidden" id="mainwp_managecosts_total_import" value="<?php echo esc_attr( $row ); ?>"/>
        <div class="mainwp_cost_tracker_import_listing" id="mainwp_cost_tracker_import_logging">
            <span class="log ui medium text"><?php echo esc_html( $header_line ) . '<br/>'; ?></span>
        </div>
        <div class="mainwp_cost_tracker_import_listing" id="mainwp_cost_tracker_import_fail_logging" style="display: none;"><?php echo esc_html( $header_line ); ?> </div>
        <?php
    }

    /**
     * Handle cost import files.
     *
     * Parses uploaded CSV file and returns structured cost data.
     *
     * @return array Array containing 'header_line' and 'data' keys.
     */
    public static function handle_cost_import_files() { // phpcs:ignore -- NOSONAR - complex method.

        $tmp_path = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified earlier in the request.
        $tmp_path = isset( $_FILES['mainwp_cost_tracker_import_file_bulkupload'] ) && isset( $_FILES['mainwp_cost_tracker_import_file_bulkupload']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['mainwp_cost_tracker_import_file_bulkupload']['tmp_name'] ) ) : '';

        if ( empty( $tmp_path ) ) {
            return array();
        }

        MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        $content = $wp_filesystem->get_contents( $tmp_path );

        $content        = str_replace( "\r\n", "\r", $content );
        $content        = str_replace( "\n", "\r", $content );
        $lines          = explode( "\r", $content );
        $import_data    = array();
        $default_values = array(
            'cost.name'           => '',
            'cost.url'            => '',
            'cost.type'           => '',
            'cost.product_type'   => '',
            'cost.license_type'   => '',
            'cost.price'          => '',
            'cost.payment_method' => '',
            'cost.renewal_type'   => '',
            'cost.last_renewal'   => '',
            'cost.cost_status'    => '',
            'cost.select_sites'   => '',
        );

        if ( is_array( $lines ) && ( ! empty( $lines ) ) ) {
            $header_line = null;
            foreach ( $lines as $original_line ) {
                $line = trim( $original_line );
                if ( \MainWP\Dashboard\MainWP_Utility::starts_with( $line, '#' ) ) {
                    continue;
                }

                $items = str_getcsv( $line, ',', '"', '' );

                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified earlier in the request.
                if ( ( null === $header_line ) && ! empty( $_POST['mainwp_cost_tracker_import_chk_header_first'] ) ) {
                    $header_line = sanitize_text_field( $line ) . "\r";
                    continue;
                }
                if ( 3 > count( $items ) ) {
                    continue;
                }

                $x             = 0;
                $import_fields = array();
                foreach ( $default_values as $field => $val ) {
                    $value                   = isset( $items[ $x ] ) ? $items[ $x ] : $val;
                    $import_fields[ $field ] = sanitize_text_field( $value );
                    ++$x;
                }
                if ( empty( $import_fields['cost.name'] ) ) {
                    continue;
                }
                $import_data[] = $import_fields;
            }
        }

        if ( ! empty( $import_data ) ) {
            foreach ( $import_data as $k_import => $val_import ) {
                if ( ! empty( $val_import['cost.last_renewal'] ) ) {
                    $timestamp                                     = strtotime( $val_import['cost.last_renewal'] );
                    $import_data[ $k_import ]['cost.last_renewal'] = ( false !== $timestamp ) ? $timestamp : 0;
                } else {
                    $import_data[ $k_import ]['cost.last_renewal'] = 0;
                }

                if ( ! empty( $val_import['cost.select_sites'] ) ) {
                    $import_data[ $k_import ]['cost.select_sites'] = array_map( 'esc_url', explode( ';', $val_import['cost.select_sites'] ) );
                }
            }
        }

        return array(
            'header_line' => esc_js( $header_line ),
            'data'        => $import_data,
        );
    }
}
