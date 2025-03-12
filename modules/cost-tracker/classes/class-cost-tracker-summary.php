<?php
/**
 * MainWP Module Cost Tracker Summary class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Overview;

/**
 * Class class Cost_Tracker_Summary {
 *
 * @package MainWP\Dashboard
 */
class Cost_Tracker_Summary {

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * The single instance of the class
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Enabled widgets
     *
     * @var array $enable_widgets
     */
    private static $enable_widgets = array(
        'cost_upcomming_renewals'       => true,
        'cost_monthly_renewals'         => true,
        'cost_yearly_renewals'          => true,
        'cost_monthly_totals'           => true,
        'cost_category_totals'          => true,
        'cost_payments_left_this_month' => true,
    );

    /**
     * Current page.
     *
     * @static
     * @var string $page Current page.
     */
    public static $page;

    /**
     * Check if there is a session,
     * if there isn't one create it.
     *
     *  @return static::singlton Overview Page Session.
     *
     * @uses \MainWP\Dashboard\MainWP_Overview
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Overview constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'admin_init' ) );
    }

    /**
     * Hook admin init.
     */
    public function admin_init() {
        $this->handle_update_screen_options();
    }


    /**
     * Handle update screen options.
     */
    public function handle_update_screen_options() { //phpcs:ignore -- NOSONAR - complex.
        $update_opts = false;
        if ( isset( $_POST['module_cost_tracker_summay_options_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['module_cost_tracker_summay_options_nonce'] ), 'module_cost_tracker_summay_options_nonce' ) ) {
            $update_opts = true;
        }
        if ( $update_opts ) {
            $show_wids = array();
            if ( isset( $_POST['mainwp_show_widgets'] ) && is_array( $_POST['mainwp_show_widgets'] ) ) {
                $selected_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_show_widgets'] ) );
                foreach ( $selected_wids as $name ) {
                    $show_wids[ $name ] = 1;
                }
            }

            if ( isset( $_POST['mainwp_widgets_name'] ) && is_array( $_POST['mainwp_widgets_name'] ) ) {
                $name_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_widgets_name'] ) );
                foreach ( $name_wids as $name ) {
                    if ( ! isset( $show_wids[ $name ] ) ) {
                        $show_wids[ $name ] = 0;
                    }
                }
            }

            global $current_user;

            if ( $current_user ) {
                update_user_option( $current_user->ID, 'mainwp_module_cost_tracker_summary_show_widgets', $show_wids, true );
                if ( isset( $_POST['reset_module_cost_tracker_summary_widgets_settings'] ) && ! empty( $_POST['reset_module_cost_tracker_summary_widgets_settings'] ) ) {
                    update_user_option( $current_user->ID, 'mainwp_module_cost_tracker_summary_show_widgets', false, true );
                    update_user_option( $current_user->ID, 'mainwp_widgets_sorted_' . strtolower( 'mainwp_page_CostSummary' ), false, true );
                }
            }
        }
    }


    /**
     * Method on_load_page()
     *
     * Run on page load.
     *
     * @param mixed $page Page name.
     */
    public function on_load_page( $page ) {

        static::$page = $page;

        $val = get_user_option( 'screen_layout_' . $page );
        if ( ! $val ) {
            global $current_user;
            update_user_option( $current_user->ID, 'screen_layout_' . $page, 2, true );
        }

        wp_enqueue_script( 'common' );
        wp_enqueue_script( 'wp-lists' );
        wp_enqueue_script( 'postbox' );
        wp_enqueue_script( 'dashboard' );
        wp_enqueue_script( 'widgets' );

        static::add_meta_boxes( $page );

        add_filter( 'mainwp_header_actions_right', array( $this, 'screen_options' ), 10, 2 );
        add_filter( 'mainwp_widget_boxes_show_widgets', array( $this, 'hook_show_widgets' ), 10, 2 );
    }

    /**
     * Method screen_options()
     *
     * Create Page Settings button.
     *
     * @param mixed $input Page Settings button HTML.
     *
     * @return mixed Page Settings button.
     */
    public function screen_options( $input ) {
        return $input .
            '<a class="ui button basic icon" onclick="mainwp_module_cost_tracker_summary_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '" aria-label="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
                <i class="cog icon"></i>
            </a>';
    }

    /**
     * Method hook_show_widgets()
     *
     * Hook show widgets.
     *
     * @param array  $values Show widgets settings.
     * @param string $page Page slug.
     *
     * @return array $values Show widgets settings.
     */
    public function hook_show_widgets( $values, $page ) {
        if ( strtolower( $page ) === strtolower( static::$page ) ) {
            return get_user_option( 'mainwp_module_cost_tracker_summary_show_widgets' );
        }
        return $values;
    }


    /**
     * Method add_meta_boxes()
     *
     * Add MainWP Overview Page Widgets.
     *
     * @param array $page Current page.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Handler::apply_filters()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     * @uses \MainWP\Dashboard\MainWP_UI::add_widget_box()
     * @uses \MainWP\Dashboard\MainWP_Connection_Status::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Recent_Pages::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Security_Issues_Widget::get_class_name()
     */
    public static function add_meta_boxes( $page ) { //phpcs:ignore -- NOSONAR - complex method.

        /**
         * Get getmetaboxes
         *
         * Adds metaboxes (widgets) to the Overview page.
         *
         * @since 4.6
         */
        $extMetaBoxs = array();
        $extMetaBoxs = apply_filters( 'mainwp_cost_summary_getmetaboxes', $extMetaBoxs );

        foreach ( $extMetaBoxs as $box ) {
            if ( isset( $box['plugin'] ) ) {
                $name                            = basename( $box['plugin'], '.php' );
                static::$enable_widgets[ $name ] = true;
            } elseif ( ! empty( $box['widget_id'] ) ) {
                static::$enable_widgets[ $box['widget_id'] ] = true;
            }
        }

        /**
         * Unset unwanted Widgets
         *
         * Contains the list of enabled widgets and allows user to unset unwanted widgets.
         *
         * @param array $enable_widgets           Array containing enabled widgets.
         * @param int   $dashboard_siteid Child site (Overview) ID.
         *
         * @since 4.6
         */
        $values                 = apply_filters( 'mainwp_module_cost_tracker_summary_enabled_widgets', static::$enable_widgets, null );
        static::$enable_widgets = array_merge( static::$enable_widgets, $values );

        // Load the widget.
        if ( ! empty( static::$enable_widgets['cost_monthly_totals'] ) ) {
            MainWP_UI::add_widget_box( 'cost_monthly_totals', array( Cost_Tracker_Monthly_Totals::instance(), 'render' ), $page, array( 1, 1, 12, 40 ) );
        }
        // Load the widget.
        if ( ! empty( static::$enable_widgets['cost_payments_left_this_month'] ) ) {
            MainWP_UI::add_widget_box( 'cost_payments_left_this_month', array( Cost_Tracker_Payment_Left_This_Month::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }
        // Load the widget.
        if ( ! empty( static::$enable_widgets['cost_category_totals'] ) ) {
            MainWP_UI::add_widget_box( 'cost_category_totals', array( Cost_Tracker_Category_Totals::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }
        // Load the widget.
        if ( ! empty( static::$enable_widgets['cost_upcomming_renewals'] ) ) {
            MainWP_UI::add_widget_box( 'cost_upcomming_renewals', array( Cost_Tracker_Upcoming_Renewals::instance(), 'render' ), $page, array( -1, -1, 4, 30 ) );
        }
        // Load the widget.
        if ( ! empty( static::$enable_widgets['cost_yearly_renewals'] ) ) {
            MainWP_UI::add_widget_box( 'cost_yearly_renewals', array( Cost_Tracker_Yearly_Renewals::instance(), 'render' ), $page, array( -1, -1, 4, 30 ) );
        }
        // Load the widget.
        if ( ! empty( static::$enable_widgets['cost_monthly_renewals'] ) ) {
            MainWP_UI::add_widget_box( 'cost_monthly_renewals', array( Cost_Tracker_Monthly_Renewals::instance(), 'render' ), $page, array( -1, -1, 4, 30 ) );
        }

        $i = 1;
        foreach ( $extMetaBoxs as $metaBox ) {
            $enabled = true;
            if ( isset( $metaBox['plugin'] ) ) {
                $name = basename( $metaBox['plugin'], '.php' );
                if ( isset( static::$enable_widgets[ $name ] ) && ! static::$enable_widgets[ $name ] ) {
                    $enabled = false;
                }
            }

            $id = isset( $metaBox['id'] ) ? $metaBox['id'] : $i++;
            $id = 'advanced-' . $id;

            $layout = ! empty( $metaBox['layout'] ) && is_array( $metaBox['layout'] ) ? $metaBox['layout'] : array( -1, -1, 6, 30 );

            if ( $enabled ) {
                MainWP_UI::add_widget_box( $id, $metaBox['callback'], $page, $layout );
            }
        }
    }

    /**
     * Method get default logs filters()
     */
    public static function get_default_filters() {
        $format = 'Y-m-d';
        return array(
            'ranges'   => 'thismonth',
            'dtsstart' => gmdate( $format, strtotime( gmdate( 'Y-m-01' ) ) ),
            'dtsstop'  => gmdate( $format, time() ),
        );
    }

    /**
     * Method render_footer()
     *
     * Render Insights page footer. Closes the page container.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Render summary page.
     *
     * @return void
     */
    public function render_summary_page() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'access_cost_summary_dashboard' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'cost summary', 'mainwp' ) );
            return;
        }
        $this->on_show_page();
    }


    /**
     * Method on_show_page()
     *
     * When the page loads render the body content.
     */
    public function on_show_page() {
        static::render_header();
        static::render_summary_body();
    }

    /**
     * Method render_header()
     *
     * Render Insights page header.
     */
    public static function render_header() {
        $params = array(
            'title'      => esc_html__( 'Cost Summary', 'mainwp' ),
            'which'      => 'page_cost_summary',
            'wrap_class' => 'mainwp-module-cost-summary-wrapper',
        );
        MainWP_UI::render_top_header( $params );
    }


    /**
     * Method render_summary_body()
     *
     * Render the summary costs content.
     */
    public static function render_summary_body() {
        $screen     = get_current_screen();
        $costs_data = Cost_Tracker_DB::get_instance()->get_summary_data( array( 'sum_data' => 'all' ) );
        ?>
        <div class="mainwp-primary-content-wrap">
        <div class="ui segment" style="padding-top:0;padding-bottom:0;">

        <?php MainWP_Overview::render_layout_selection(); ?>

        <div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'cost-summany-widgets' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="cost-summany-widgets"></i>
                    <?php printf( esc_html__( 'To hide or show a widget, click the Cog (%1$s) icon.', 'mainwp' ), '<i class="cog icon"></i>' ); ?>
                </div>
            <?php endif; ?>
        </div>
            <?php
            /**
             * Action: mainwp_before_overview_widgets
             *
             * Fires at the top of the Overview page (before first widget).
             *
             * @since 4.6
             */
            do_action( 'mainwp_before_overview_widgets', 'costsummary' );
            ?>
            <div id="mainwp-grid-wrapper" class="gridster">
                <?php
                MainWP_UI::do_widget_boxes(
                    $screen->id,
                    array(
                        'costs_data' => $costs_data,
                    )
                );
                ?>
            </div>
            <?php
            /**
             * Action: 'mainwp_after_overview_widgets'
             *
             * Fires at the bottom of the Overview page (after the last widget).
             *
             * @since 4.6
             */
            do_action( 'mainwp_after_overview_widgets', 'costsummary' );
            ?>
            <script type="text/javascript">
                jQuery(function( $ ) {
                    jQuery( '.mainwp-widget .mainwp-dropdown-tab .item' ).tab();
                    mainwp_module_cost_tracker_summary_screen_options = function () {
                        jQuery( '#mainwp-module-log-overview-screen-options-modal' ).modal( {
                            allowMultiple: true,
                            onHide: function () {
                            }
                        } ).modal( 'show' );
                        return false;
                    };
                    jQuery('#reset-costs-summary-widgets-settings').on('click', function () {
                        mainwp_confirm(__('Are you sure.'), function(){
                            jQuery('.mainwp_hide_wpmenu_checkboxes input[name="mainwp_show_widgets[]"]').prop('checked', true);
                            jQuery('input[name=reset_module_cost_tracker_summary_widgets_settings]').attr('value', 1);
                            jQuery('#submit-cost-summary-widgets-settings').click();
                        }, false, false, true);
                        return false;
                    });

                    jQuery( '#mainwp-upcoming-renewals-table-today' ).DataTable();
                    jQuery( '#mainwp-upcoming-renewals-table-tomorrow' ).DataTable();
                    jQuery( '#mainwp-upcoming-renewals-table-week' ).DataTable();
                    jQuery( '#mainwp-upcoming-renewals-table-next_week' ).DataTable();
                    jQuery( '#mainwp-upcoming-renewals-table-month' ).DataTable();
                    jQuery( '#mainwp-upcoming-renewals-table-next_month' ).DataTable();
                    jQuery( '#mainwp-upcoming-renewals-table-year' ).DataTable();
                    jQuery( '#mainwp-upcoming-renewals-table-next_year' ).DataTable();
                } );
            </script>
        <div class="ui modal" id="mainwp-module-log-overview-screen-options-modal">
        <i class="close icon"></i>
                <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
                <div class="content ui form">
                    <?php
                    /**
                     * Action: mainwp_module_cost_tracker_summary_screen_options_top
                     *
                     * Fires at the top of the Sceen Options modal on the Overview page.
                     *
                     * @since 4.6
                     */
                    do_action( 'mainwp_module_cost_tracker_summary_screen_options_top' );
                    ?>
                    <form method="POST" action="" name="mainwp_module_cost_tracker_summary_screen_options_form" id="mainwp-module-log-overview-screen-options-form">
                        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <input type="hidden" name="module_cost_tracker_summay_options_nonce" value="<?php echo esc_attr( wp_create_nonce( 'module_cost_tracker_summay_options_nonce' ) ); ?>" />
                        <?php static::render_screen_options( false ); ?>
                        <?php
                        /**
                         * Action: mainwp_module_cost_tracker_summary_screen_options_bottom
                         *
                         * Fires at the bottom of the Sceen Options modal on the Overview page.
                         *
                         * @since 4.6
                         */
                        do_action( 'mainwp_module_cost_tracker_summary_screen_options_bottom' );
                        ?>
                </div>
                <div class="actions">
                    <div class="ui two columns grid">
                        <div class="left aligned column">
                            <span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated widgets.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><input type="button" class="ui button" name="reset" id="reset-costs-summary-widgets-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                        </div>
                        <div class="ui right aligned column">
                            <input type="submit" class="ui green button" id="submit-cost-summary-widgets-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                        </div>
                    </div>
                </div>

                <input type="hidden" name="reset_module_cost_tracker_summary_widgets_settings" value="" />
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_screen_options()
     *
     * Render Page Settings.
     *
     * @return void  Render Page Settings html.
     */
    public static function render_screen_options() {
        $default_widgets = array(
            'cost_upcomming_renewals'       => esc_html__( 'Upcoming Renewals', 'mainwp' ),
            'cost_monthly_renewals'         => esc_html__( 'Upcoming Monthly Renewals', 'mainwp' ),
            'cost_yearly_renewals'          => esc_html__( 'Upcoming Yearly Renewals', 'mainwp' ),
            'cost_monthly_totals'           => esc_html__( 'Monthly Totals', 'mainwp' ),
            'cost_category_totals'          => esc_html__( 'Annual Expense Distribution by Category', 'mainwp' ),
            'cost_payments_left_this_month' => esc_html__( 'Payment Left For This Month', 'mainwp' ),
        );

        $custom_opts = array();
        /**
         * Filter: mainwp_module_cost_tracker_summary_widgets_screen_options
         *
         * Filters available widgets on the Overview page allowing users to unsent unwanted widgets.
         *
         * @since 4.6
         */
        $custom_opts = apply_filters( 'mainwp_module_cost_tracker_summary_widgets_screen_options', $custom_opts );

        if ( is_array( $custom_opts ) && ! empty( $custom_opts ) ) {
            $default_widgets = array_merge( $default_widgets, $custom_opts );
        }

        $show_widgets = get_user_option( 'mainwp_module_cost_tracker_summary_show_widgets' );
        if ( ! is_array( $show_widgets ) ) {
            $show_widgets = array();
        }

        /**
         * Action: mainwp_screen_options_modal_top
         *
         * Fires at the top of the Page Settings modal element.
         *
         * @since 4.6
         */
        do_action( 'mainwp_screen_options_modal_top', 'costsummary' );
        ?>
        <?php if ( isset( $_GET['page'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
        <div class="ui grid field">
            <label class="six wide column"><?php esc_html_e( 'Show widgets', 'mainwp' ); ?></label>
            <div class="ten wide column" <?php echo 'data-tooltip="' . esc_attr__( 'Select widgets that you want to hide in the MainWP Overview page.', 'mainwp' ); ?> data-inverted="" data-position="top left">
                <ul class="mainwp_hide_wpmenu_checkboxes">
                <?php
                foreach ( $default_widgets as $name => $title ) {
                    $_selected = '';
                    if ( ! isset( $show_widgets[ $name ] ) || 1 === (int) $show_widgets[ $name ] ) {
                        $_selected = 'checked';
                    }
                    ?>
                    <li>
                        <div class="ui checkbox">
                            <input type="checkbox" id="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" name="mainwp_show_widgets[]" <?php echo esc_html( $_selected ); ?> value="<?php echo esc_attr( $name ); ?>">
                            <label for="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
                        </div>
                        <input type="hidden" name="mainwp_widgets_name[]" value="<?php echo esc_attr( $name ); ?>">
                    </li>
                    <?php
                }
                ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        <?php
        /**
         * Action: mainwp_screen_options_modal_bottom
         *
         * Fires at the bottom of the Page Settings modal element.
         *
         * @since 4.6
         */
        do_action( 'mainwp_screen_options_modal_bottom', 'costsummary' );
    }
}
