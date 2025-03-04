<?php
/**
 * Manage Sites List Table.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Sites_Filter_Segment
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Sites_Filter_Segment { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Method admin_init().
     */
    public function admin_init() {
        MainWP_Post_Handler::instance()->add_action( 'mainwp_manage_sites_filter_save_segment', array( $this, 'ajax_sites_filter_save_segment' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_manage_sites_filter_load_segments', array( $this, 'ajax_sites_filter_load_segments' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_manage_sites_filter_delete_segment', array( $this, 'ajax_sites_filter_delete_segment' ) );
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
     * Method render_filters_segment().
     */
    public function render_filters_segment() {
        $saved_segments = static::set_get_manage_sites_filter_segments();
        ?>
        <div class="right aligned four wide column">
            <a class="ui mini button" id="mainwp-manage-sites-filter-save-segment-button" selected-segment-id="" selected-segment-name=""><?php esc_html_e( 'Save Segment', 'mainwp' ); ?></a>
            <?php if ( ! empty( $saved_segments ) ) : ?>
                <a class="ui mini button mainwp_manage_sites_filter_choose_segment"><?php esc_html_e( 'Load Segment', 'mainwp' ); ?></a>
            <?php else : ?>
                <a class="ui mini disabled button"><?php esc_html_e( 'Load Segment', 'mainwp' ); ?></a>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
            jQuery( document ).ready( function( $ ) {
                mainwp_load_sites_filter_segments = function () {
                    jQuery('#mainwp-common-filter-segment-select-fields').hide();
                    let data = mainwp_secure_data({
                        action: 'mainwp_manage_sites_filter_load_segments',
                    });
                    mainwpSegmentModalUiHandle.showStatus('<i class="notched circle loading icon"></i> ' + __('Loading segments. Please wait...'));
                    jQuery.post(ajaxurl, data, function (response) {
                        if (response.error != undefined) {
                            mainwpSegmentModalUiHandle.showStatus(response.error, 'red');
                        } else if (response.result) {
                            mainwpSegmentModalUiHandle.showResults(response.result);
                        } else {
                            mainwpSegmentModalUiHandle.showStatus(__('No saved segments.'), 'red');
                        }
                    }, 'json');
                };

                jQuery('#mainwp-manage-sites-filter-save-segment-button').on( 'click', function () {
                    mainwpSegmentModalUiHandle.showSegment(this);
                } );
                jQuery('.mainwp_manage_sites_filter_choose_segment').on( 'click', function () {
                    mainwpSegmentModalUiHandle.loadSegment(mainwp_load_sites_filter_segments);
                } );

                jQuery('#mainwp-common-filter-edit-segment-save').on( 'click', function () {

                    mainwpSegmentModalUiHandle.hideSegmentStatus();

                    let seg_name = jQuery('#mainwp-common-filter-edit-segment-name').val().trim();

                    if('' == seg_name){
                        jQuery('#mainwp-common-filter-edit-segment-status').html(__('Please enter segment name.')).addClass('red').show();
                        return false;
                    }

                    let data = mainwp_secure_data({
                        action: 'mainwp_manage_sites_filter_save_segment',
                        name: seg_name,
                        seg_is_not: $( '#mainwp-sites-filters-row #mainwp_is_not_site').dropdown('get value'),
                        seg_site_tags: $( '#mainwp-sites-filters-row #mainwp-filter-sites-group').dropdown('get value'),
                        seg_site_status: $( '#mainwp-sites-filters-row #mainwp-filter-sites-status').dropdown('get value'),
                        seg_site_clients: $( '#mainwp-sites-filters-row #mainwp-filter-clients').dropdown('get value'),
                        seg_id:$('#mainwp-manage-sites-filter-save-segment-button').attr('selected-segment-id'),
                    });

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
                    let seg_id = jQuery( '#mainwp-common-filter-segment-select-fields .ui.dropdown').dropdown('get value');
                    let seg_values = '';
                    if('' != seg_id ) {
                        seg_values = jQuery( '#mainwp-common-filter-segment-select-fields select > option[value="' +seg_id+ '"]').attr('segment-filters');
                    }
                    let valErr = true;
                    let arrVal = '';
                    let fieldsAllows = [
                        'seg_is_not',
                        'seg_site_tags',
                        'seg_site_status',
                        'seg_site_clients',
                    ];
                    if('' != seg_values ) {
                        try {
                            seg_values = JSON.parse(seg_values);
                            if('' != seg_values){
                                jQuery( '#mainwp-manage-sites-filter-save-segment-button' ).attr('selected-segment-id',seg_id);
                                jQuery( '#mainwp-manage-sites-filter-save-segment-button' ).attr('selected-segment-name',seg_values.name);

                                for (const [key, value] of Object.entries(seg_values)) {
                                    try {
                                        if(fieldsAllows.includes(key)){
                                            console.log(key + ' === '+ value);
                                            jQuery( '#mainwp-sites-filters-row .ui.dropdown.' + key ).dropdown('clear');
                                            arrVal = value.split(",");
                                            jQuery( '#mainwp-sites-filters-row .ui.dropdown.' + key ).dropdown('set selected', arrVal);
                                        }
                                    } catch (err) {
                                        console.log(key + ' === '+ value);
                                        console.log(err);
                                    }
                                }
                                jQuery( '#mainwp-common-filter-segment-modal' ).modal('hide');
                                if(jQuery('.manage-sites-screenshots-filter-top').length > 0 ){
                                    mainwp_screenshots_sites_filter();
                                }else {
                                    mainwp_manage_sites_filter();
                                }
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
                    let delBtn = this;
                    let seg_id = jQuery( '#mainwp-common-filter-segment-select-fields .ui.dropdown').dropdown('get value');
                    if('' == seg_id){
                        return false;
                    }

                    if('yes' === jQuery(delBtn).attr('running')){
                        return false;
                    }

                    jQuery(seg_id).attr('running', 'yes');
                    let data = mainwp_secure_data({
                        action: 'mainwp_manage_sites_filter_delete_segment',
                        seg_id: seg_id,
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
     * Method set_get_manage_sites_filter_segments()
     *
     * @param bool   $set_val Get or set value.
     * @param array  $saved_segments segments data.
     * @param string $save_field segments data.
     *
     * @return array values
     */
    public static function set_get_manage_sites_filter_segments( $set_val = false, $saved_segments = array(), $save_field = 'manage_sites' ) {
        global $current_user;

        $field = 'mainwp_' . sanitize_text_field( $save_field ) . '_filter_saved_segments';

        if ( $current_user && ! empty( $current_user->ID ) ) {
            if ( $set_val ) {
                update_user_option( $current_user->ID, $field, $saved_segments );
            } else {
                $values = get_user_option( $field, array() );
                if ( ! is_array( $values ) ) {
                    $values = array();
                }
                return $values;
            }
        }
        return array();
    }


    /**
     * Method ajax_sites_filter_save_segment()
     *
     * Post handler for save segment.
     */
    public function ajax_sites_filter_save_segment() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_manage_sites_filter_save_segment' );
        //phpcs:disable WordPress.Security.NonceVerification.Missing

        $not_filters = array(
            'seg_site_tags'    => 'nogroups',
            'seg_site_status'  => 'all',
            'seg_site_clients' => 'noclients',
        );

        $fields = array(
            'name',
            'seg_is_not',
            'seg_site_tags',
            'seg_site_status',
            'seg_site_clients',
        );

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

        $saved_segments = static::set_get_manage_sites_filter_segments();
        if ( ! is_array( $saved_segments ) ) {
            $saved_segments = array();
        }
        $saved_segments[ $seg_id ] = $save_fields;
        static::set_get_manage_sites_filter_segments( true, $saved_segments );
        die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
    }


    /**
     * Method ajax_sites_filter_load_segments()
     *
     * Post handler for save segment.
     */
    public function ajax_sites_filter_load_segments() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_manage_sites_filter_load_segments' );
        $saved_segments = static::set_get_manage_sites_filter_segments();
        $list_segs      = '';
        if ( is_array( $saved_segments ) && ! empty( $saved_segments ) ) {
            $list_segs .= '<select id="mainwp-edit-segment-filters" class="ui fluid dropdown">';
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
     * Method ajax_sites_filter_delete_segment()
     *
     * Post handler for save segment.
     */
    public function ajax_sites_filter_delete_segment() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_manage_sites_filter_delete_segment' );
        $seg_id = ! empty( $_POST['seg_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seg_id'] ) ) : 0; //phpcs:ignore -- ok.

        $saved_segments = static::set_get_manage_sites_filter_segments();
        if ( ! empty( $seg_id ) && is_array( $saved_segments ) && isset( $saved_segments[ $seg_id ] ) ) {
            unset( $saved_segments[ $seg_id ] );
            static::set_get_manage_sites_filter_segments( true, $saved_segments );
            die( wp_json_encode( array( 'result' =>'SUCCESS' ) ) ); //phpcs:ignore -- ok.
        }
        die( wp_json_encode( array( 'error' => esc_html__( 'Segment not found. Please try again.', 'mainwp' ) ) ) ); //phpcs:ignore -- ok.
    }
}
