<?php
/**
 * MainWP Module Cost Tracker Dashboard class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Settings_Indicator;

/**
 * Class Cost_Tracker_Add_Edit
 */
class Cost_Tracker_Add_Edit {

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
     * @return Cost_Tracker_Add_Edit
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
     * Method render_add_edit_page()
     *
     * When the page loads render the body content.
     */
    public function render_add_edit_page() {

        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_cost_tracker' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage cost tracker', 'mainwp' ) );
            return;
        }

        $edit_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : false; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $show    = $edit_id ? 'edit' : 'add';
        Cost_Tracker_Admin::render_header( $show );
        $params = $edit_id ? '&id=' . $edit_id : '';
        ?>
        <div class="ui alt segment" id="mainwp-module-cost-tracker-add-edit-tab" style="margin-bottom:0px">
            <form id="mainwp-module-cost-tracker-settings-form" method="post" action="admin.php?page=CostTrackerAdd<?php echo $params; //phpcs:ignore -- escaped. ?>" class="ui form">
                <?php $this->render_add_edit_content( $edit_id ); ?>
            </form>
            <div class="ui clearing hidden divider"></div>
        </div>
        <?php
        MainWP_UI::render_modal_upload_icon();
    }

    /**
     * Render settings
     *
     * Renders the extension settings page.
     *
     * @param int $edit_id Cost Id to edit.
     */
    public function render_add_edit_content( $edit_id ) { //phpcs:ignore -- NOSONAR - complex method.

        $edit_cost                    = false;
        $selected_payment_type        = '';
        $selected_product_type        = '';
        $selected_license_type        = '';
        $selected_renewal             = '';
        $selected_cost_tracker_status = '';
        $last_renewal                 = time();
        $selected_payment_method      = 'paypal';
        $slug                         = '';
        $selected_default_icon        = '';
        $selected_prod_icon           = '';
        $selected_prod_color          = '';
        $is_plugintheme               = true;

        if ( $edit_id ) {
            $edit_cost = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $edit_id );
            if ( $edit_cost ) {
                $selected_payment_type        = $edit_cost->type;
                $selected_product_type        = $edit_cost->product_type;
                $selected_license_type        = $edit_cost->license_type;
                $selected_cost_tracker_status = $edit_cost->cost_status;
                $selected_renewal             = $edit_cost->renewal_type;
                $last_renewal                 = $edit_cost->last_renewal;
                $selected_payment_method      = $edit_cost->payment_method;
                $slug                         = $edit_cost->slug;
                $selected_prod_icon           = $edit_cost->cost_icon;
                $selected_prod_color          = $edit_cost->cost_color;
                $is_plugintheme               = 'plugin' === $selected_product_type || 'theme' === $selected_product_type ? true : false;
            }
        }

        $all_defaults = Cost_Tracker_Admin::get_default_fields_values();

        $license_types     = $all_defaults['license_types'];
        $product_types     = $all_defaults['product_types'];
        $payment_types     = $all_defaults['payment_types'];
        $payment_methods   = $all_defaults['payment_methods'];
        $renewal_frequency = $all_defaults['renewal_frequency'];
        $cost_status       = $all_defaults['cost_status'];

        $currency        = Cost_Tracker_Utility::get_instance()->get_option( 'currency' );
        $currency_symbol = Cost_Tracker_Utility::get_instance()->get_currency_symbol( $currency );
        $product_icons   = Cost_Tracker_Utility::get_product_default_icons();

        $cust_prod_src       = '';
        $cust_prod_icon_file = '';

        if ( ! empty( $selected_prod_icon ) ) {
            if ( false !== strpos( $selected_prod_icon, 'deficon:' ) ) {
                $selected_default_icon = str_replace( 'deficon:', '', $selected_prod_icon );
                $cust_prod_src         = '';
            } else {
                $dirs                = MainWP_System_Utility::get_mainwp_dir( Cost_Tracker_Settings::$icon_sub_dir, true );
                $icon_base           = $dirs[1];
                $cust_prod_icon_file = $selected_prod_icon;
                $cust_prod_src       = $icon_base . $cust_prod_icon_file;
            }
        }

        // new cost.
        if ( ! $edit_id ) {
            $selected_default_icon = 'archive';
            $selected_prod_icon    = 'deficon:' . $selected_default_icon;
            $selected_prod_color   = '#34424D';
            $cust_prod_src         = '';
        }

        ?>
        <div class="mainwp-main-content">
            <div>
                <?php
                if ( isset( $_GET['message'] ) && ! empty( $_GET['message'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $msg     = (int) $_GET['message']; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $id      = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $err     = false;
                    $message = '';
                    if ( 1 === $msg ) {
                        $message = esc_html__( 'Cost saved successfully.', 'mainwp' );
                    } elseif ( 2 === $msg ) {
                        $err     = true;
                        $message = get_transient( 'mainwp_cost_tracker_update_error_' . $id );
                        delete_transient( 'mainwp_cost_tracker_update_error_' . $id );
                    }
                    if ( ! empty( $message ) ) {
                        ?>
                        <div class="ui <?php echo $err ? 'yellow' : 'green'; ?> message" id="mainwp-module-cost-tracker-message-zone" >
                        <?php echo esc_html( $message ); ?>
                            <i class="ui close icon"></i>
                        </div>
                        <?php
                    }
                }
                ?>
                <div class="ui red message" id="mainwp-module-cost-tracker-error-zone" style="display:none">
                    <div class="error-message"></div>
                    <i class="ui close icon"></i>
                </div>
                <?php if ( $edit_cost ) : ?>
                    <h2 class="ui dividing header">
                        <?php echo esc_html__( 'Edit ', 'mainwp' ) . esc_html__( $edit_cost->name ); ?>
                        <div class="sub header"><?php esc_html_e( 'Update your expense details to keep your cost tracking accurate and up to date.', 'mainwp' ); ?></div>
                    </h2>
                <?php else : ?>
                    <h2 class="ui dividing header">
                        <?php esc_html_e( 'Add New Cost', 'mainwp' ); ?>
                        <div class="sub header"><?php esc_html_e( 'Track your expenses with ease. Add hosting fees, plugin licenses, or any other costs to keep your agency finances organized.', 'mainwp' ); ?></div>
                    </h2>
                <?php endif; ?>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? $edit_cost->name : '' );
                    esc_html_e( 'Name', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter the Company (Product) name.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <input type="text" class="settings-field-value-change-handler" aria-label="<?php esc_attr_e( 'Enter the Company (Product) name.', 'mainwp' ); ?>" name="mainwp_module_cost_tracker_edit_name" id="mainwp_module_cost_tracker_edit_name" value="<?php echo $edit_cost ? esc_html( $edit_cost->name ) : ''; ?>">
                    </div>
                </div>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? $edit_cost->url : '' );
                    esc_html_e( 'Product URL', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter the URL of the product (optional).', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <input type="text" class="settings-field-value-change-handler" aria-label="<?php esc_attr_e( 'Enter the URL of the product (optional).', 'mainwp' ); ?>" name="mainwp_module_cost_tracker_edit_url" id="mainwp_module_cost_tracker_edit_url" value="<?php echo $edit_cost ? esc_html( $edit_cost->url ) : ''; ?>">
                    </div>
                </div>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $cust_prod_src );
                    esc_html_e( 'Upload product icon', 'mainwp' );
                    $delnonce = MainWP_System_Utility::get_custom_nonce( 'product', esc_attr( $cust_prod_icon_file ) );
                    ?>
                    </label>
                    <input type="hidden" name="mainwp_module_cost_tracker_edit_icon_hidden" class="settings-field-value-change-handler" id="mainwp_module_cost_tracker_edit_icon_hidden" value="<?php echo esc_attr( $selected_prod_icon ); ?>">
                    <div class="three wide middle aligned column" >
                        <span class="ui circular bordered image">
                            <?php if ( ! empty( $edit_cost ) ) { ?>
                                <?php echo Cost_Tracker_Admin::get_instance()->get_product_icon_display( $edit_cost, 'module_cost_tracker_upload_custom_icon_img_display' ); //phpcs:ignore --ok. ?>
                            <?php } else { ?>
                                <div style="display:inline-block;" id="module_cost_tracker_upload_custom_icon_img_display"></div>
                            <?php } ?>
                        </span>
                        <div class="ui basic button module-cost-tracker-product-icon-customable"
                            iconItemId="<?php echo intval( $edit_id ); ?>"
                            iconFileSlug="<?php echo esc_attr( $cust_prod_icon_file ); ?>"
                            del-icon-nonce="<?php echo esc_attr( $delnonce ); ?>"
                            icon-src="<?php echo esc_attr( $cust_prod_src ); ?>">
                            <i class="image icon"></i> <?php echo ! empty( $edit_cost ) ? esc_html__( 'Change Image', 'mainwp' ) : esc_html__( 'Upload Image', 'mainwp' ); ?>
                        </div>
                    </div>
                </div>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? $selected_default_icon : '' );
                    esc_html_e( 'Select icon', 'mainwp' );
                    ?>
                    </label>
                    <input type="hidden"  class="settings-field-value-change-handler" id="mainwp_module_cost_tracker_edit_select_icon_hidden" value="<?php echo esc_attr( $selected_prod_icon ); ?>">
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select an icon if not using original product icon.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <div class="ui left action input">
                            <div class="ui five column selection search dropdown not-auto-init" style="min-width:21em" id="mainwp_module_cost_tracker_edit_icon_select">
                                <div class="text">
                                    <span style="color:<?php echo esc_attr( $selected_prod_color ); ?>" ><?php echo ! empty( $selected_default_icon ) ? '<i class="' . esc_attr( $selected_default_icon ) . ' icon"></i>' : ''; ?></span>
                                </div>
                                <i class="dropdown icon"></i>
                                <div class="menu">
                                    <?php foreach ( $product_icons as $icon ) : ?>
                                        <?php echo '<div class="item" style="color:' . esc_attr( $selected_prod_color ) . '" data-value="' . esc_attr( $icon ) . '"><i class="' . esc_attr( $icon ) . ' icon"></i></div>'; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="color" data-tooltip="Color will update on save" data-position="top center" data-inverted="" name="mainwp_module_cost_tracker_edit_product_color" class="mainwp-color-picker-input" id="mainwp_module_cost_tracker_edit_product_color"  value="<?php echo esc_attr( $selected_prod_color ); ?>" />
                        </div>
                    </div>
                    <div class="one wide column"></div>
                </div>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit" default-indi-value="subscription">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? ( 'subscription' !== $selected_payment_type ) : '' );
                    esc_html_e( 'Type', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select the type of this cost.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <select id="mainwp_module_cost_tracker_edit_payment_type" name="mainwp_module_cost_tracker_edit_payment_type" class="ui dropdown not-auto-init settings-field-value-change-handler">
                            <?php foreach ( $payment_types as $key => $val ) : ?>
                                <?php
                                $_select = '';
                                if ( $key === $selected_payment_type ) {
                                    $_select = ' selected ';
                                }
                                echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $_select ) . '>' . esc_html( $val ) . '</option>';
                                ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php $lifetime_selected = ( 'lifetime' === $selected_payment_type ) ? true : false; ?>

                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit hide-if-lifetime-subscription-selected" default-indi-value="weekly" <?php echo $lifetime_selected ? 'style="display:none;"' : ''; ?>>
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? ( 'weekly' !== $selected_renewal ) : '' );
                    esc_html_e( 'Renewal frequency', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter renewal frequency.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <select id="mainwp_module_cost_tracker_edit_renewal_type" name="mainwp_module_cost_tracker_edit_renewal_type" class="ui dropdown settings-field-value-change-handler">
                            <?php foreach ( $renewal_frequency as $key => $val ) : ?>
                                <?php
                                $_select = '';
                                if ( $key === $selected_renewal ) {
                                    $_select = ' selected ';
                                }
                                echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $_select ) . '>' . esc_html( $val ) . '</option>';
                                ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit hide-if-lifetime-subscription-selected" default-indi-value="active" <?php echo $lifetime_selected ? 'style="display:none;"' : ''; ?>>
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? ( 'active' !== $selected_cost_tracker_status ) : '' );
                    esc_html_e( 'Subscription status', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter subscription status.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <select id="mainwp_module_cost_tracker_edit_cost_tracker_status" name="mainwp_module_cost_tracker_edit_cost_tracker_status" class="ui dropdown settings-field-value-change-handler">
                            <?php foreach ( $cost_status as $key => $val ) : ?>
                                <?php
                                $_select = '';
                                if ( $key === $selected_cost_tracker_status ) {
                                    $_select = ' selected ';
                                }
                                echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $_select ) . '>' . esc_html( $val ) . '</option>';
                                ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if ( $edit_id ) : ?>
                <div class="ui grid field hide-if-lifetime-subscription-selected" <?php echo $lifetime_selected ? 'style="display:none;"' : ''; ?>>
                    <label class="six wide column middle aligned">
                    <?php
                    $next_rl = Cost_Tracker_Admin::get_next_renewal( $edit_cost->last_renewal, $edit_cost->renewal_type );
                    esc_html_e( 'Next renewal', 'mainwp' );
                    ?>
                    </label>
                        <div class="five wide column" data-inverted="" data-position="left center">
                        <?php

                        Cost_Tracker_Admin::generate_next_renewal( $edit_cost, $next_rl );
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit" default-indi-value="plugin">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? ( 'plugin' !== $selected_product_type ) : '' );
                    ?>
                    <?php esc_html_e( 'Category', 'mainwp' ); ?></label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select the category for this cost.', 'mainwp' ); ?>
                    " data-inverted="" data-position="left center">
                        <select id="mainwp_module_cost_tracker_edit_product_type" name="mainwp_module_cost_tracker_edit_product_type" class="ui dropdown not-auto-init settings-field-value-change-handler">
                            <?php foreach ( $product_types as $key => $val ) : ?>
                                <?php
                                $_select = '';
                                if ( $key === $selected_product_type ) {
                                    $_select = ' selected ';
                                }
                                echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $_select ) . '>' . esc_html( $val ) . '</option>';
                                ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit hide-if-product-type-isnot-plugintheme" <?php echo $is_plugintheme ? '' : 'style="display:none;"'; ?>>
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $slug );
                    esc_html_e( 'Slug', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter the product slug.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <input type="text" class="settings-field-value-change-handler" aria-label="<?php esc_attr_e( 'Enter the product slug.', 'mainwp' ); ?>" name="mainwp_module_cost_tracker_edit_product_slug" id="mainwp_module_cost_tracker_edit_product_slug" value="<?php echo esc_attr( $slug ); ?>">
                    </div>
                </div>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit" default-indi-value="single_site">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? ( 'single_site' !== $selected_license_type ) : '' );
                    esc_html_e( 'License type', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select the license type of this cost.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <select id="mainwp_module_cost_tracker_edit_license_type" name="mainwp_module_cost_tracker_edit_license_type" class="ui dropdown settings-field-value-change-handler">
                            <?php foreach ( $license_types as $key => $val ) : ?>
                                <?php
                                $_select = '';
                                if ( $key === $selected_license_type ) {
                                    $_select = ' selected ';
                                }
                                echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $_select ) . '>' . esc_html( $val ) . '</option>';
                                ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php $dec = Cost_Tracker_Utility::cost_tracker_format_price( 0, true, array( 'get_decimals' => true ) ); ?>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? $currency_symbol : '' );
                    esc_html_e( 'Price', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Please input a value using a single decimal point (.) without thousand separators or currency symbols.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <div class="ui left labeled input">
                            <label for="mainwp_module_cost_tracker_edit_price" class="ui label"><?php echo esc_html( $currency_symbol ); ?></label>
                            <input type="text" class="settings-field-value-change-handler" name="mainwp_module_cost_tracker_edit_price" id="mainwp_module_cost_tracker_edit_price" value="<?php echo $edit_cost ? esc_html( round( $edit_cost->price, $dec ) ) : ''; ?>">
                        </div>
                    </div>
                </div>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit">
                    <label class="six wide column middle aligned">
                    <?php
                    esc_html_e( 'Purchase date', 'mainwp' );
                    ?>
                    </label>
                    <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter the purchase date.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <div class="ui calendar mainwp_datepicker">
                            <div class="ui input left icon">
                                <i class="calendar icon"></i>
                                <input type="text" class="settings-field-value-change-handler" placeholder="<?php esc_attr_e( 'Select date', 'mainwp' ); ?>" id="mainwp_module_cost_tracker_edit_last_renewal" name="mainwp_module_cost_tracker_edit_last_renewal" value="<?php echo $last_renewal ? esc_attr( gmdate( 'Y-m-d', $last_renewal ) ) : ''; ?>" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit" default-indi-value="paypal">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? ( 'paypal' !== $selected_payment_method ) : '' );
                    esc_html_e( 'Payment method', 'mainwp' );
                    ?>
                    </label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the payment method.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <select id="mainwp_module_cost_tracker_edit_payment_method" name="mainwp_module_cost_tracker_edit_payment_method" class="ui dropdown settings-field-value-change-handler">
                            <?php foreach ( $payment_methods as $key => $val ) : ?>
                                <?php
                                $_select = '';
                                if ( $key === $selected_payment_method ) {
                                    $_select = ' selected ';
                                }
                                echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $_select ) . '>' . esc_html( $val ) . '</option>';
                                ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-add-edit">
                    <label class="six wide column middle aligned">
                    <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_cost ? $edit_cost->note : '' );
                    esc_html_e( 'Notes', 'mainwp' );
                    ?>
                    </label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the description for this cost tracking item.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                        <textarea id="mainwp_module_cost_tracker_edit_note" class="settings-field-value-change-handler" name="mainwp_module_cost_tracker_edit_note"><?php echo $edit_cost ? esc_html( $edit_cost->note ) : ''; ?></textarea>
                    </div>
                </div>
                <input type="hidden" name="mainwp_module_cost_tracker_edit_id" value="<?php echo $edit_cost ? intval( $edit_cost->id ) : 0; ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'module_cost_tracker_edit_nonce' . ( $edit_cost ? $edit_cost->id : '' ) ) ); ?>">
                <input type="hidden" name="mwp_cost_tracker_editing_submit" value="1">
            </div>
        </div>
        <div class="mainwp-side-content mainwp-no-padding">
            <?php

            $sel_sites   = array();
            $sel_groups  = array();
            $sel_clients = array();
            if ( ! empty( $edit_cost ) ) {
                $sel_sites   = json_decode( $edit_cost->sites, true );
                $sel_groups  = json_decode( $edit_cost->groups, true );
                $sel_clients = json_decode( $edit_cost->clients, true );
                if ( ! is_array( $sel_sites ) ) {
                    $sel_sites = array();
                }
                if ( ! is_array( $sel_groups ) ) {
                    $sel_groups = array();
                }
                if ( ! is_array( $sel_clients ) ) {
                    $sel_clients = array();
                }
            }
            ?>
            <div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
                <div class="title active"><i class="dropdown icon"></i>
                <?php echo esc_html__( 'Select Sites', 'mainwp' ); ?></div>
                <div class="content active">
                    <?php
                    do_action( 'mainwp_select_sites_box', '', 'checkbox', true, true, '', '', $sel_sites, $sel_groups, true, $sel_clients );
                    ?>
                </div>
            </div>
            <div class="ui divider"></div>
            <div class="mainwp-search-submit">
                <div class="ui hidden fitted divider"></div>
                <input type="submit" value="<?php esc_html_e( 'Save Cost Tracking Item', 'mainwp' ); ?>" class="ui green big fluid button" id="mainwp-module-cost-tracker-save-tracker-button">
            </div>
        </div>
        <div class="ui clearing hidden divider"></div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( '#mainwp_module_cost_tracker_edit_payment_type' ).dropdown( {
                    onChange: function( val ) {
                        if ( val == 'lifetime' ) {
                            jQuery( '.hide-if-lifetime-subscription-selected' ).hide();
                        } else {
                            jQuery( '.hide-if-lifetime-subscription-selected' ).show();
                        }
                    }
                } );

                jQuery( '#mainwp_module_cost_tracker_edit_icon_select' ).dropdown( {
                    onChange: function( val ) {
                        jQuery( '#mainwp_module_cost_tracker_edit_icon_hidden' ).val('deficon:' + val);
                        jQuery( '#mainwp_module_cost_tracker_edit_select_icon_hidden' ).val('deficon:' + val); // for visual indicator only.
                        jQuery('#mainwp_module_cost_tracker_edit_select_icon_hidden').trigger('change'); // for visual indicator only.
                    }
                } );

                jQuery( '#mainwp_module_cost_tracker_edit_product_type' ).dropdown( {
                    onChange: function( val ) {
                        if ( val == 'plugin' || val == 'theme') {
                            jQuery( '.hide-if-product-type-isnot-plugintheme' ).show();
                            jQuery( '.hide-if-product-type-isnot-plugintheme input[type=text]' ).attr('disabled', false);
                        } else {
                            jQuery( '.hide-if-product-type-isnot-plugintheme' ).hide();
                            jQuery( '.hide-if-product-type-isnot-plugintheme input[type=text]' ).attr('disabled','disabled');
                        }
                    }
                } );

                jQuery(document).on('click', '.module-cost-tracker-product-icon-customable', function () {
                    let iconObj = jQuery(this);
                    jQuery('#mainwp_delete_image_field').hide();
                    jQuery('#mainwp-upload-custom-icon-modal').modal('setting', 'closable', false).modal('show');
                    jQuery('#update_custom_icon_btn').removeAttr('disabled');
                    jQuery('#mainwp_delete_image_field').find('#mainwp_delete_image_chk').attr('iconItemId', iconObj.attr('iconItemId') ); // @see used by mainwp_upload_custom_types_icon().
                    jQuery('#mainwp_delete_image_field').find('#mainwp_delete_image_chk').attr('iconFileSlug', iconObj.attr('iconFileSlug') ); // @see used by mainwp_upload_custom_types_icon().

                    if (iconObj.attr('icon-src') != '') {
                        jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', iconObj.attr('icon-src'));
                        jQuery('#mainwp_delete_image_field').show();
                    }

                    jQuery(document).on('click', '#update_custom_icon_btn', function () {
                            let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked');
                            let iconItemId = iconObj.attr('iconItemId');
                            let iconFileSlug = iconObj.attr('iconFileSlug'); // to support delete file when iconItemId = 0.

                            // upload/delete icon action.
                            mainwp_upload_custom_types_icon(iconObj, 'mainwp_module_cost_tracker_upload_product_icon', iconItemId, iconFileSlug, deleteIcon, function(response){
                                if (jQuery('#mainwp_module_cost_tracker_edit_icon_hidden').length > 0) {
                                    if (typeof response.iconfile !== undefined) {
                                        jQuery('#mainwp_module_cost_tracker_edit_icon_hidden').val(response.iconfile);
                                    } else {
                                        jQuery('#mainwp_module_cost_tracker_edit_icon_hidden').val('');
                                    }
                                    jQuery('#mainwp_module_cost_tracker_edit_icon_hidden').trigger('change');
                                }
                                let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked'); // to delete.
                                if(deleteIcon){
                                    jQuery('#module_cost_tracker_upload_custom_icon_img_display').hide();
                                } else if (jQuery('#module_cost_tracker_upload_custom_icon_img_display').length > 0) {
                                    if (typeof response.iconfile !== undefined) {
                                        let icon_img = typeof response.iconimg !== undefined ? response.iconimg : '';
                                        let icon_src = typeof response.iconsrc !== undefined ? response.iconsrc : '';
                                        iconObj.attr('icon-src', icon_src);
                                        iconObj.attr('iconFileSlug', response.iconfile); // to support delete file when iconItemId = 0.
                                        iconObj.attr('del-icon-nonce', response.iconnonce);
                                        jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', icon_src);
                                        jQuery('#module_cost_tracker_upload_custom_icon_img_display').html(icon_img);
                                        jQuery('#module_cost_tracker_upload_custom_icon_img_display').show();
                                    }
                                }
                                setTimeout(function () {
                                    //window.location.href = location.href;
                                    jQuery('#mainwp-upload-custom-icon-modal').modal('hide')
                                }, 1000);
                            });
                            return false;
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Method ajax_upload_product_icon()
     */
    public function ajax_upload_product_icon() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_module_cost_tracker_upload_product_icon' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $iconfile_slug   = isset( $_POST['iconFileSlug'] ) ? sanitize_text_field( wp_unslash( $_POST['iconFileSlug'] ) ) : '';
        $delete          = isset( $_POST['delete'] ) ? intval( $_POST['delete'] ) : 0;
        $icon_product_id = isset( $_POST['iconItemId'] ) ? intval( $_POST['iconItemId'] ) : 0;
        $delnonce        = isset( $_POST['delnonce'] ) ? sanitize_key( $_POST['delnonce'] ) : '';

        if ( $delete ) {
            if ( ! MainWP_System_Utility::is_valid_custom_nonce( 'product', $iconfile_slug, $delnonce ) ) {
                die( 'Invalid nonce!' );
            }
            if ( $icon_product_id ) {
                $cost = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $icon_product_id );
                if ( $cost && '' !== $cost->cost_icon && false === strpos( $cost->cost_icon, 'deficon:' ) ) {
                    $update = array(
                        'id'        => $icon_product_id,
                        'cost_icon' => '',
                    );
                    Cost_Tracker_DB::get_instance()->update_cost_tracker( $update );
                    $this->delete_product_icon_file( $cost->cost_icon );
                }
            } elseif ( ! empty( $iconfile_slug ) ) {
                $this->delete_product_icon_file( $iconfile_slug );
            }
            wp_die( wp_json_encode( array( 'result' => 'success' ) ) );
        }

        $output = isset( $_FILES['mainwp_upload_icon_uploader'] ) ? MainWP_System_Utility::handle_upload_image( Cost_Tracker_Settings::$icon_sub_dir, $_FILES['mainwp_upload_icon_uploader'], 0 ) : null;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $uploaded_icon = 'NOTCHANGE';
        if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
            $uploaded_icon = $output['filename'];
        }

        if ( 'NOTCHANGE' !== $uploaded_icon ) {
            $dirs      = MainWP_System_Utility::get_mainwp_dir( Cost_Tracker_Settings::$icon_sub_dir, true );
            $cust_icon = $dirs[1] . $uploaded_icon;

            wp_die(
                wp_json_encode(
                    array(
                        'result'    => 'success',
                        'iconfile'  => esc_html( $uploaded_icon ),
                        'iconsrc'   => esc_html( $cust_icon ),
                        'iconimg'   => '<img class="ui circular image" src="' . esc_attr( $cust_icon ) . '" style="width:32px;height:auto;display:inline-block;" alt="Cost custom icon">',
                        'iconnonce' => MainWP_System_Utility::get_custom_nonce( 'product', esc_html( $uploaded_icon ) ),
                    )
                )
            );
        } else {
            $result = array(
                'result' => 'failed',
            );
            $error  = MainWP_Post_Handler::get_upload_icon_error( $output );
            if ( ! empty( $error ) ) {
                $result['error'] = esc_html( $error );
            }
            wp_die( wp_json_encode( $result ) );
        }
    }

    /**
     * Delete product icon file.
     *
     * @param string $cost_icon file icon.
     */
    public function delete_product_icon_file( $cost_icon ) {
        $valid_file = 0 === validate_file( $cost_icon ) ? true : false;
        if ( $valid_file ) {
            $dirs = MainWP_System_Utility::get_mainwp_dir( Cost_Tracker_Settings::$icon_sub_dir, true );
            $f    = $dirs[0] . $cost_icon;
            if ( file_exists( $f ) ) {
                wp_delete_file( $f );
            }
        }
    }
}
