<?php
/**
 * Manage MainWP Ui Layout Segment.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Ui_Manage_Widgets_Layout
 *
 * @package MainWP\Dashboard
 */
class MainWP_Ui_Manage_Widgets_Layout { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

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
     * MainWP_Ui_Manage_Widgets_Layout constructor.
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
        MainWP_Post_Handler::instance()->add_action( 'mainwp_ui_save_widgets_layout', array( $this, 'ajax_save_widgets_layout' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_ui_load_widgets_layout', array( $this, 'ajax_load_widgets_layout' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_ui_delete_widgets_layout', array( $this, 'ajax_delete_widgets_layout' ) );
    }

    /**
     * Method render_edit_layout().
     *
     * @param string $screen_id Current screen id.
     */
    public static function render_edit_layout( $screen_id ) {
        $screen_slug    = strtolower( $screen_id );
        $saved_segments = static::set_get_widgets_layout( false, array(), $screen_slug );
        ?>

        <a class="ui mini button" id="mainwp-manage-widgets-load-saved-layout-button" selected-layout-id="" settings-slug="<?php echo esc_attr( $screen_slug ); ?>"><?php esc_html_e( 'Save Layout', 'mainwp' ); ?></a>
        <?php if ( ! empty( $saved_segments ) ) : ?>
            <a class="ui mini button mainwp_manage_widgets_ui_choose_layout"><?php esc_html_e( 'Load Layout', 'mainwp' ); ?></a>
        <?php else : ?>
            <a class="ui mini disabled button"><?php esc_html_e( 'Load Layout', 'mainwp' ); ?></a>
        <?php endif; ?>

        <script type="text/javascript">
            jQuery( document ).ready( function( $ ) {
                mainwp_load_manage_widgets_layout = function () {
                    jQuery('#mainwp-common-layout-widgets-select-fields').hide();
                    let data = mainwp_secure_data({
                        action: 'mainwp_ui_load_widgets_layout',
                        settings_slug: jQuery('#mainwp-manage-widgets-load-saved-layout-button').attr('settings-slug')
                    });
                    mainwpUIHandleWidgetsLayout.showWorkingStatus('<i class="notched circle loading icon"></i> ' + __('Loading layouts. Please wait...'), 'green');
                    jQuery.post(ajaxurl, data, function (response) {
                        if (response.error != undefined) {
                            mainwpUIHandleWidgetsLayout.showWorkingStatus(response.error, 'red');
                        } else if (response.result) {
                            mainwpUIHandleWidgetsLayout.showResults(response.result);
                        } else {
                            mainwpUIHandleWidgetsLayout.showWorkingStatus(__('No saved layouts.'), 'red');
                        }
                    }, 'json');
                };

                jQuery('#mainwp-manage-widgets-load-saved-layout-button').on( 'click', function () {
                    mainwpUIHandleWidgetsLayout.showLayout(this);
                } );
                jQuery('.mainwp_manage_widgets_ui_choose_layout').on( 'click', function () {
                    let field_name = '';
                    mainwpUIHandleWidgetsLayout.loadSegment(mainwp_load_manage_widgets_layout);
                } );

                jQuery('#mainwp-common-edit-widgets-layout-save-button').on( 'click', function () {

                    mainwpUIHandleWidgetsLayout.hideWorkingStatus();

                    let seg_name = jQuery('#mainwp-common-edit-widgets-layout-name').val().trim();

                    if('' == seg_name){
                        mainwpUIHandleWidgetsLayout.showWorkingStatus(__('Please enter layout name.'), 'red' );
                        return false;
                    }

                    let data = {
                        name: seg_name,
                        seg_id:$('#mainwp-manage-widgets-load-saved-layout-button').attr('selected-layout-id'),
                        settings_slug: $('#mainwp-manage-widgets-load-saved-layout-button').attr('settings-slug')
                    };

                    mainwpUIHandleWidgetsLayout.showWorkingStatus( '<i class="notched circle loading icon"></i> ' + __('Saving layout. Please wait...'), 'green' );

                    mainwp_common_ui_widgets_save_layout( '.grid-stack-item', data, function(response){
                        if (response.error != undefined) {
                            mainwpUIHandleWidgetsLayout.showWorkingStatus(response.error, 'red');
                        } else if (response.result == 'SUCCESS') {
                            mainwpUIHandleWidgetsLayout.showWorkingStatus(__('Segment saved successfully.'), 'green');
                            setTimeout(function () {
                                jQuery('#mainwp-common-edit-widgets-layout-status').fadeOut(300);
                                jQuery( '#mainwp-common-edit-widgets-layout-modal' ).modal('hide');
                                window.location.href = location.href;
                            }, 2000);
                        } else {
                            mainwpUIHandleWidgetsLayout.showWorkingStatus(__('Undefined error occured while saving your layout!'), 'red');
                        }
                    });
                    return false;
                });

                jQuery('#mainwp-common-edit-widgets-select-layout-button').on( 'click', function () {
                    mainwpUIHandleWidgetsLayout.hideWorkingStatus();
                    let seg_id = jQuery( '#mainwp-common-layout-widgets-select-fields .ui.dropdown').dropdown('get value');
                    let screen_slug = $('#mainwp-manage-widgets-load-saved-layout-button').attr('settings-slug');
                    let loc_url = removeUrlParams(location.href, [ 'screen_slug', 'updated', '_opennonce'] );
                    if('' == loc_url){
                        loc_url = location.href;
                    }
                    window.location.href = loc_url + '&select_layout=1&screen_slug=' + encodeURIComponent( screen_slug ) + '&updated=' + encodeURIComponent( seg_id ) + '&_opennonce=<?php echo esc_js( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>';
                });


                jQuery('#mainwp-common-edit-widgets-layout-delete-button').on( 'click', function () {
                    mainwpUIHandleWidgetsLayout.hideWorkingStatus();
                    let delBtn = this;
                    let seg_id = jQuery( '#mainwp-common-layout-widgets-select-fields .ui.dropdown').dropdown('get value');
                    if('' == seg_id){
                        return false;
                    }

                    if('yes' === jQuery(delBtn).attr('running')){
                        return false;
                    }

                    jQuery(seg_id).attr('running', 'yes');
                    let data = mainwp_secure_data({
                        action: 'mainwp_ui_delete_widgets_layout',
                        seg_id: seg_id,
                        settings_slug: $('#mainwp-manage-widgets-load-saved-layout-button').attr('settings-slug')
                    });
                    mainwpUIHandleWidgetsLayout.showWorkingStatus('<i class="notched circle loading icon"></i> ' + __('Deleting layout. Please wait...'), 'green' );
                    jQuery.post(ajaxurl, data, function (response) {

                        jQuery(delBtn).removeAttr('running');

                        if (response.error != undefined) {
                            mainwpUIHandleWidgetsLayout.showWorkingStatus(response.error, 'red');
                        } else if (response.result == 'SUCCESS') {
                            mainwpUIHandleWidgetsLayout.showWorkingStatus(__('Segment deleted successfully.'), 'green');
                            setTimeout(function () {
                                jQuery('#mainwp-common-edit-widgets-layout-status').fadeOut(300);
                                jQuery( '#mainwp-common-edit-widgets-layout-modal' ).modal('hide');
                            }, 2000);
                        } else {
                            mainwpUIHandleWidgetsLayout.showWorkingStatus(__('Undefined error occured while deleting your layout!'), 'red');
                        }
                    }, 'json');

                    return false;
                });

            } );
            </script>
        <?php
    }

    /**
     * Method set_get_widgets_layout()
     *
     * @param bool   $set_val Get or set value.
     * @param array  $saved_segments segments data.
     * @param string $save_field segments data.
     *
     * @return array values
     */
    public static function set_get_widgets_layout( $set_val = false, $saved_segments = array(), $save_field = 'overview' ) {
        global $current_user;

        $field = 'mainwp_' . sanitize_text_field( $save_field ) . '_widgets_saved_layout';

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
     * Method ajax_save_widgets_layout()
     *
     * Post handler for save layout.
     */
    public function ajax_save_widgets_layout() { //phpcs:ignore -- NOSONAR - complex.
        MainWP_Post_Handler::instance()->check_security( 'mainwp_ui_save_widgets_layout' );
        //phpcs:disable WordPress.Security.NonceVerification.Missing

        $seg_id = ! empty( $_POST['seg_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seg_id'] ) ) : time();
        $wgids  = is_array( $_POST['wgids'] ) ? $_POST['wgids'] : array(); // phpcs:ignore -- NOSONAR - ok.
        $items  = is_array( $_POST['order'] ) ? $_POST['order'] : array(); // phpcs:ignore -- NOSONAR - ok.

        $slug  = isset( $_POST['settings_slug'] ) ? sanitize_text_field( wp_unslash($_POST['settings_slug'] ))  : 'overview'; // phpcs:ignore -- NOSONAR - ok.

        if ( empty( $slug ) ) {
            $slug = 'overview';
        }

        $layout_items = array();
        if ( is_array( $wgids ) && is_array( $items ) ) {
            foreach ( $wgids as $idx => $wgid ) {
                if ( isset( $items[ $idx ] ) ) {
                    $pre = 'widget-'; // compatible with #compatible-widgetid.
                    if ( 0 === strpos( $wgid, $pre ) ) {
                        $wgid = substr( $wgid, strlen( $pre ) );
                    }
                    $layout_items[ $wgid ] = $items[ $idx ];
                }
            }
        }

        $save_layout = array(
            'name'   => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : 'N/A',
            'layout' => $layout_items,
        );

        //phpcs:enable WordPress.Security.NonceVerification.Missing

        $saved_segments = static::set_get_widgets_layout( false, array(), $slug );
        if ( ! is_array( $saved_segments ) ) {
            $saved_segments = array();
        }
        $saved_segments[ $seg_id ] = $save_layout;
        static::set_get_widgets_layout( true, $saved_segments, $slug );
        die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
    }


    /**
     * Method ajax_load_widgets_layout()
     *
     * Post handler for save segment.
     */
    public function ajax_load_widgets_layout() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_ui_load_widgets_layout' );
        $slug  = isset( $_POST['settings_slug'] ) ? sanitize_text_field( wp_unslash($_POST['settings_slug'] ))  : 'overview'; // phpcs:ignore -- NOSONAR - ok.
        if ( empty( $slug ) ) {
            $slug = 'overview';
        }
        $saved_segments = static::set_get_widgets_layout( false, array(), $slug );
        $list_segs      = '';
        if ( is_array( $saved_segments ) && ! empty( $saved_segments ) ) {
            $list_segs .= '<select id="mainwp-edit-layout-filters" class="ui fluid dropdown">';
            $list_segs .= '<option value="">' . esc_html__( 'Select layout', 'mainwp' ) . '</option>';
            foreach ( $saved_segments as $sid => $values ) {
                if ( empty( $values['name'] ) ) {
                    continue;
                }
                $list_segs .= '<option value="' . esc_attr( $sid ) . '">' . esc_html( $values['name'] ) . '</option>';
            }
            $list_segs .= '</select>';
        }
        die( wp_json_encode( array( 'result' => $list_segs ) ) ); //phpcs:ignore -- ok.
    }

    /**
     * Method ajax_delete_widgets_layout()
     *
     * Post handler for save segment.
     */
    public function ajax_delete_widgets_layout() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_ui_delete_widgets_layout' );
        $seg_id = ! empty( $_POST['seg_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seg_id'] ) ) : 0; //phpcs:ignore -- ok.
        $slug = ! empty( $_POST['settings_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['settings_slug'] ) ) : 'overview'; //phpcs:ignore -- ok.

        $saved_segments = static::set_get_widgets_layout( false, array(), $slug );
        if ( ! empty( $seg_id ) && is_array( $saved_segments ) && isset( $saved_segments[ $seg_id ] ) ) {
            unset( $saved_segments[ $seg_id ] );
            static::set_get_widgets_layout( true, $saved_segments, $slug );
            die( wp_json_encode( array( 'result' =>'SUCCESS' ) ) ); //phpcs:ignore -- ok.
        }
        die( wp_json_encode( array( 'error' => esc_html__( 'Layout not found. Please try again.', 'mainwp' ) ) ) ); //phpcs:ignore -- ok.
    }

    /**
     * Method render_modal_save_layout()
     *
     * Render modal window.
     *
     * @return void
     */
    public static function render_modal_save_layout() {
        ?>
        <div id="mainwp-common-edit-widgets-layout-modal" class="ui tiny modal">
            <i class="close icon" id="mainwp-common-filter-layout-cancel"></i>
            <div class="header"><?php esc_html_e( 'Save Layout', 'mainwp' ); ?></div>
            <div class="content" id="mainwp-common-widgets-layout-content">
                <div id="mainwp-common-edit-widgets-layout-status" class="ui message" style="display:none;"></div>
                <div id="mainwp-common-edit-widgets-layout-edit-fields" class="ui form">
                    <div class="field">
                        <label><?php esc_html_e( 'Layout name', 'mainwp' ); ?></label>
                    </div>
                    <div class="field">
                        <input type="text" id="mainwp-common-edit-widgets-layout-name" value=""/>
                    </div>
                </div>
                <div id="mainwp-common-layout-widgets-select-fields" style="display:none;">
                    <div class="field">
                        <div id="mainwp-common-edit-widgets-layout-lists-wrapper"></div>
                    </div>
                </div>
            </div>
            <div class="actions">
                <div class="ui grid">
                    <div class="eight wide left aligned middle aligned column">
                        <input type="button" class="ui green button" id="mainwp-common-edit-widgets-layout-save-button" value="<?php esc_attr_e( 'Save', 'mainwp' ); ?>"/>
                        <input type="button" class="ui green button" id="mainwp-common-edit-widgets-select-layout-button" value="<?php esc_attr_e( 'Choose', 'mainwp' ); ?>" style="display:none;"/>
                    </div>
                    <div class="eight wide column">
                        <input type="button" class="ui basic button" id="mainwp-common-edit-widgets-layout-delete-button" value="<?php esc_attr_e( 'Delete', 'mainwp' ); ?>" style="display:none;"/>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
