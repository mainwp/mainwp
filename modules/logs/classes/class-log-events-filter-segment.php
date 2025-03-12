<?php
/**
 * Manage filter segment Logs List Table.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Manage_Sites_Filter_Segment;
use MainWP\Dashboard\MainWP_Post_Handler;


/**
 * Class Log_Events_Filter_Segment
 *
 * @package MainWP\Dashboard
 */
class Log_Events_Filter_Segment {

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * MainWP_Manage_Sites_Filter_Segment constructor.
     *
     * Run each time the class is called.
     * Add action to generate tabletop.
     */
    public function __construct() {
    }

    /**
     * Create public static instance.
     *
     * @static
     *
     * @return instance.
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method admin_init().
     */
    public function admin_init() {
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_filter_save_segment', array( $this, 'ajax_log_filter_save_segment' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_filter_load_segments', array( $this, 'ajax_log_filter_load_segments' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_filter_delete_segment', array( $this, 'ajax_log_filter_delete_segment' ) );
    }

    // @NO_SONAR_START@ - duplicated issue.
    /**
     * Method render_filters_segment().
     *
     * @param string $filter_name Filter name.
     */
    public function render_filters_segment( $filter_name = 'module_log' ) {
        $saved_segments = MainWP_Manage_Sites_Filter_Segment::set_get_manage_sites_filter_segments( false, array(), $filter_name );
        ?>

        <div class="four wide right aligned column">
            <button class="ui mini green button" id="mainwp-module-log-filter-save-segment-button" selected-segment-id="" selected-segment-name=""><?php esc_html_e( 'Save Segment', 'mainwp' ); ?></button>
            <?php if ( ! empty( $saved_segments ) ) : ?>
                <button class="ui mini button mainwp_module_log_filter_choose_segment"><?php esc_html_e( 'Load Segment', 'mainwp' ); ?></button>
            <?php else : ?>
                <button class="ui mini disabled button"><?php esc_html_e( 'Load Segment', 'mainwp' ); ?></button>
            <?php endif; ?>
        </div>
        <input type="hidden" name="mainwp-common-filter-option-name" id="mainwp-common-filter-option-name"  value="<?php echo esc_attr( $filter_name ); ?>"/>

        <script type="text/javascript">
            jQuery( document ).ready( function( $ ) {
                mainwp_load_logs_filter_segments = function () {
                    jQuery('#mainwp-common-filter-segment-select-fields').hide();
                    var data = mainwp_secure_data({
                        action: 'mainwp_module_log_filter_load_segments',
                        filter_opt_name: jQuery('#mainwp-common-filter-option-name').val().trim(),
                    });
                    jQuery('#mainwp-common-filter-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Loading segments. Please wait...')).show();
                    jQuery.post(ajaxurl, data, function (response) {
                        if (response.error != undefined) {
                            jQuery('#mainwp-common-filter-edit-segment-status').html(response.error).addClass('red');
                        } else if (response.result) {
                            mainwpSegmentModalUiHandle.showResults(response.result);
                        } else {
                            jQuery('#mainwp-common-filter-edit-segment-status').html(__('No saved segments.')).addClass('red');
                        }
                    }, 'json');
                };

                jQuery('#mainwp-module-log-filter-save-segment-button').on( 'click', function () {
                    mainwpSegmentModalUiHandle.showSegment(this);
                } );
                jQuery('.mainwp_module_log_filter_choose_segment').on( 'click', function () {
                    mainwpSegmentModalUiHandle.loadSegment( mainwp_load_logs_filter_segments );
                } );

                jQuery('#mainwp-common-filter-edit-segment-save').on( 'click', function () {

                    mainwpSegmentModalUiHandle.hideSegmentStatus();

                    var seg_name = jQuery('#mainwp-common-filter-edit-segment-name').val().trim();

                    if('' == seg_name){
                        jQuery('#mainwp-common-filter-edit-segment-status').html(__('Please enter segment name.')).addClass('red').show();
                        return false;
                    }
                    const filter_opt = jQuery('#mainwp-common-filter-option-name').val().trim();
                    var data = mainwp_secure_data({
                        action: 'mainwp_module_log_filter_save_segment',
                        name: seg_name,
                        filter_opt_name:filter_opt,
                        seg_ranges: $( '#mainwp-module-log-filter-ranges').dropdown('get value'),
                        seg_dtsstart: $( '#mainwp-module-log-filter-dtsstart input[type=text]').val(),
                        seg_dtsstop: $( '#mainwp-module-log-filter-dtsstop input[type=text]').val(),
                        seg_groups: $( '#mainwp-module-log-filter-groups').dropdown('get value'),
                        seg_clients:$('#mainwp-module-log-filter-clients').dropdown('get value'),
                        seg_users:$('#mainwp-module-log-filter-users').dropdown('get value'),
                    });

                    if('module_log_manage' === filter_opt){
                        data.seg_source =$('#mainwp-module-log-filter-source').dropdown('get value');
                        data.seg_sites =$('#mainwp-module-log-filter-sites').dropdown('get value');
                        data.seg_events =$('#mainwp-module-log-filter-events').dropdown('get value');
                    }

                    jQuery('#mainwp-common-filter-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Saving segment. Please wait...')).show();

                    jQuery.post(ajaxurl, data, function (response) {
                        if (response.error != undefined) {
                            jQuery('#mainwp-common-filter-edit-segment-status').html(response.error).addClass('red');
                        } else if (response.result == 'SUCCESS') {
                            jQuery('#mainwp-common-filter-edit-segment-status').html(__('Segment saved successfully.')).addClass('green');
                            setTimeout(function () {
                                jQuery('#mainwp-common-filter-edit-segment-status').fadeOut(300);
                                jQuery( '#mainwp-common-filter-segment-modal' ).modal('hide');
                            }, 2000);
                        } else {
                            jQuery('#mainwp-common-filter-edit-segment-status').html(__('Undefined error occured while saving your segment!')).addClass('red');
                        }
                    }, 'json');
                    return false;
                });

                jQuery('#mainwp-common-filter-select-segment-choose-button').on( 'click', function () {
                    mainwpSegmentModalUiHandle.hideSegmentStatus();
                    var seg_id = jQuery( '#mainwp-common-filter-segment-select-fields .ui.dropdown').dropdown('get value');
                    var seg_values = '';
                    if('' != seg_id ) {
                        seg_values = jQuery( '#mainwp-common-filter-segment-select-fields select > option[value="' +seg_id+ '"]').attr('segment-filters');
                    }
                    var valErr = true;
                    var arrVal = '';
                    var fieldsAllows = [
                        'seg_ranges',
                        'seg_dtsstart',
                        'seg_dtsstop',
                        'seg_groups',
                        'seg_clients',
                        'seg_users',
                        'seg_source',
                        'seg_sites',
                        'seg_events',
                    ];

                    if('' != seg_values ) {
                        try {
                            seg_values = JSON.parse(seg_values);
                            if('' != seg_values){
                                jQuery( '#mainwp-module-log-filter-save-segment-button' ).attr('selected-segment-id',seg_id);
                                jQuery( '#mainwp-module-log-filter-save-segment-button' ).attr('selected-segment-name',seg_values.name);

                                for (const [key, value] of Object.entries(seg_values)) {
                                    try {
                                        if(fieldsAllows.includes(key)){
                                            if( 'seg_dtsstart' !== key && 'seg_dtsstop' !== key ){
                                                if('seg_ranges' != key){ // to fix onChange filter-ranges issue.
                                                    jQuery( '#mainwp-module-log-filters-row .ui.dropdown.' + key ).dropdown('clear');
                                                }
                                                arrVal = value.split(",");

                                                if(jQuery( '#mainwp-module-log-filters-row .ui.dropdown.' + key ).length){
                                                    jQuery( '#mainwp-module-log-filters-row .ui.dropdown.' + key ).dropdown('set selected', arrVal);
                                                }
                                            } else {
                                                jQuery( '#mainwp-module-log-filters-row .ui.calendar.' + key ).calendar('set date', value );
                                            }
                                        }
                                    } catch (err) {
                                        console.log(err);
                                    }
                                }
                                jQuery( '#mainwp-common-filter-segment-modal' ).modal('hide');
                                mainwp_module_log_overview_content_filter();
                                valErr = false;
                            }
                        } catch (err) {
                            console.log(err);
                        }
                    }
                    if(valErr){
                        jQuery('#mainwp-common-filter-edit-segment-status').html(__('Undefined error segment values! Please try again.')).addClass('red').show();
                    }
                });


                jQuery('#mainwp-common-filter-select-segment-delete-button').on( 'click', function () {
                    mainwpSegmentModalUiHandle.hideSegmentStatus();
                    var delBtn = this;
                    var seg_id = jQuery( '#mainwp-common-filter-segment-select-fields .ui.dropdown').dropdown('get value');
                    if('' == seg_id){
                        return false;
                    }

                    if('yes' === jQuery(delBtn).attr('running')){
                        return false;
                    }

                    jQuery(seg_id).attr('running', 'yes');
                    var data = mainwp_secure_data({
                        action: 'mainwp_module_log_filter_delete_segment',
                        seg_id: seg_id,
                        filter_opt_name: jQuery('#mainwp-common-filter-option-name').val().trim(),
                    });
                    jQuery('#mainwp-common-filter-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Deleting segment. Please wait...')).show();
                    jQuery.post(ajaxurl, data, function (response) {

                        jQuery(delBtn).removeAttr('running');

                        if (response.error != undefined) {
                            jQuery('#mainwp-common-filter-edit-segment-status').html(response.error).addClass('red');
                        } else if (response.result == 'SUCCESS') {
                            jQuery('#mainwp-common-filter-edit-segment-status').html(__('Segment deleted successfully.')).addClass('green');
                            setTimeout(function () {
                                jQuery('#mainwp-common-filter-edit-segment-status').fadeOut(300);
                                jQuery( '#mainwp-common-filter-segment-modal' ).modal('hide');
                            }, 2000);
                        } else {
                            jQuery('#mainwp-common-filter-edit-segment-status').html(__('Undefined error occured while deleting your segment!')).addClass('red');
                        }
                    }, 'json');

                    return false;
                });

            } );
            </script>
        <?php
    }

    /**
     * Method ajax_log_filter_save_segment()
     *
     * Post handler for save segment.
     */
    public function ajax_log_filter_save_segment() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_filter_save_segment' );

        //phpcs:disable WordPress.Security.NonceVerification.Missing
        $opt_name = isset( $_POST['filter_opt_name'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_opt_name'] ) ) : 'module_log';

        $not_filters = array(
            'seg_groups'  => 'alltags',
            'seg_clients' => 'allclients',
            'seg_users'   => 'allusers',
        );

        $fields = array(
            'name',
            'seg_ranges',
            'seg_dtsstart',
            'seg_dtsstop',
            'seg_groups',
            'seg_clients',
            'seg_users',
        );

        if ( 'module_log_manage' === $opt_name ) {
            $fields[] = 'seg_source';
            $fields[] = 'seg_sites';
            $fields[] = 'seg_events';
        }

        $save_fields = array();

        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $val_seg = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
                if ( isset( $not_filters[ $field ] ) && false !== strpos( $val_seg, $not_filters[ $field ] ) ) {
                    $val_seg = '';
                }
                $save_fields[ $field ] = $val_seg;
            }
        }

        $seg_id = ! empty( $_POST['seg_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seg_id'] ) ) : time();
        //phpcs:enable WordPress.Security.NonceVerification.Missing

        $saved_segments = MainWP_Manage_Sites_Filter_Segment::set_get_manage_sites_filter_segments( false, array(), $opt_name );
        if ( ! is_array( $saved_segments ) ) {
            $saved_segments = array();
        }
        $saved_segments[ $seg_id ] = $save_fields;
        MainWP_Manage_Sites_Filter_Segment::set_get_manage_sites_filter_segments( true, $saved_segments, $opt_name );
        die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
    }


    /**
     * Method ajax_log_filter_load_segments()
     *
     * Post handler for save segment.
     */
    public function ajax_log_filter_load_segments() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_filter_load_segments' );

        //phpcs:disable WordPress.Security.NonceVerification.Missing
        $opt_name = isset( $_POST['filter_opt_name'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_opt_name'] ) ) : 'module_log';

        $saved_segments = MainWP_Manage_Sites_Filter_Segment::set_get_manage_sites_filter_segments( false, array(), $opt_name );
        $list_segs      = '';
        if ( is_array( $saved_segments ) && ! empty( $saved_segments ) ) {
            $list_segs .= '<select id="mainwp_module_log_edit_payment_type" class="ui fluid dropdown">';
            $list_segs .= '<option segment-filters="" value="">' . esc_html__( 'Select a segment', 'mainwp' ) . '</option>';
            foreach ( $saved_segments as $sid => $values ) {
                if ( empty( $values['name'] ) ) {
                    continue;
                }
                $list_segs .= '<option segment-filters="' . esc_attr( wp_json_encode( $values ) ) . '" value="' . esc_attr( $sid ) . '">' . esc_html( $values['name'] ) . '</option>';
            }
            $list_segs .= '</select>';
        }
        die( wp_json_encode( array( 'result' => $list_segs ) ) ); //phpcs:ignore -- ok.
    }

    /**
     * Method ajax_log_filter_delete_segment()
     *
     * Post handler for save segment.
     */
    public function ajax_log_filter_delete_segment() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_filter_delete_segment' );

        //phpcs:disable WordPress.Security.NonceVerification.Missing
        $opt_name = isset( $_POST['filter_opt_name'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_opt_name'] ) ) : 'module_log';

        $seg_id = ! empty( $_POST['seg_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seg_id'] ) ) : 0; //phpcs:ignore -- ok.
        //phpcs:enable WordPress.Security.NonceVerification.Missing

        $saved_segments = MainWP_Manage_Sites_Filter_Segment::set_get_manage_sites_filter_segments( false, array(), $opt_name );
        if ( ! empty( $seg_id ) && is_array( $saved_segments ) && isset( $saved_segments[ $seg_id ] ) ) {
            unset( $saved_segments[ $seg_id ] );
            MainWP_Manage_Sites_Filter_Segment::set_get_manage_sites_filter_segments( true, $saved_segments, $opt_name );
            die( wp_json_encode( array( 'result' =>'SUCCESS' ) ) ); //phpcs:ignore -- ok.
        }
        die( wp_json_encode( array( 'error' => esc_html__( 'Segment not found. Please try again.', 'mainwp' ) ) ) ); //phpcs:ignore -- ok.
    }
    // @NO_SONAR_END@  .
}
