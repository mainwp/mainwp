<?php
/**
 * MainWP Module Cost Tracker Settings class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Settings_Indicator;
use MainWP\Dashboard\MainWP_Settings;

/**
 * Class Cost_Tracker_Settings
 */
class Cost_Tracker_Settings {

    /**
     * Static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Static variable to hold the single instance variable.
     *
     * @static
     *
     * @var string sub dir.
     */
    public static $icon_sub_dir = 'cost-tracker-products-icons';

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Cost_Tracker_Settings
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
     * Render settings
     *
     * Renders the extension settings page.
     */
    public function render_settings_page() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_cost_tracker' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage cost tracker', 'mainwp' ) );
            return;
        }
        MainWP_Settings::render_header( 'CostTrackerSettings' );
        ?>
        <div class="ui segment" id="mainwp-module-cost-tracker-settings-tab">
            <h2 class="ui dividing header">
                <?php esc_html_e( 'Cost Tracker Settings ', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Customize how you track and manage costs. Adjust currency, categories, and other settings to fit your workflow.', 'mainwp' ); ?></div>
            </h2>
            <form id="mainwp-module-cost-tracker-settings-form" method="post" action="admin.php?page=CostTrackerSettings" class="ui form">
                <?php $this->render_settings_content(); ?>
            </form>
            <div class="ui clearing hidden divider"></div>
        </div>
        <?php
        MainWP_Settings::render_footer( 'CostTrackerSettings' );
    }

    /**
     * Render settings content.
     *
     * Renders the extension settings page.
     */
    public function render_settings_content() { //phpcs:ignore -- NOSONAR - complex.

        $currencies        = Cost_Tracker_Utility::get_all_currency_symbols();
        $selected_currency = Cost_Tracker_Utility::get_instance()->get_option( 'currency' );
        if ( empty( $selected_currency ) ) {
            $selected_currency = 'USD';
        }
        $currency_format = Cost_Tracker_Utility::get_instance()->get_option( 'currency_format', array() );

        if ( ! is_array( $currency_format ) ) {
            $currency_format = array();
        }

        $default         = Cost_Tracker_Utility::default_currency_settings();
        $currency_format = array_merge( $default, $currency_format );

        $currency_position  = $currency_format['currency_position'];
        $thousand_separator = $currency_format['thousand_separator'];
        $decimal_separator  = $currency_format['decimal_separator'];
        $decimals           = $currency_format['decimals'];

        $cust_product_types   = Cost_Tracker_Utility::get_instance()->get_option( 'custom_product_types', array(), true );
        $cust_payment_methods = Cost_Tracker_Utility::get_instance()->get_option( 'custom_payment_methods', array(), true );

        $default_product_types = Cost_Tracker_Admin::get_default_product_types();
        $product_colors        = Cost_Tracker_Admin::get_product_colors();

        $product_default_icons       = Cost_Tracker_Utility::get_product_default_icons();
        $product_types_icons         = Cost_Tracker_Admin::get_product_type_icons();
        $product_types_default_icons = Cost_Tracker_Admin::get_default_product_types_icons();

        if ( isset( $_GET['message'] ) && ! empty( $_GET['message'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = esc_html__( 'Cost Tracker settings saved successfully.', 'mainwp' );
            ?>
            <div class="ui green message" id="mainwp-module-cost-tracker-message-zone" >
                <?php echo esc_html( $message ); ?>
                <i class="ui close icon"></i>
            </div>
            <?php
        }
        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-settings" default-indi-value="USD">
            <label class="six wide column middle aligned">
            <?php MainWP_Settings_Indicator::render_not_default_indicator( 'cost_tracker_currency_selected', $selected_currency ); ?>
            <?php esc_html_e( 'Currency', 'mainwp' ); ?></label>
            <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select preferred currency.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                <select id="mainwp_module_cost_tracker_settings_currency" name="mainwp_module_cost_tracker_settings_currency" class="ui search selection dropdown settings-field-value-change-handler" aria-label="<?php esc_attr_e( 'Select preferred currency.', 'mainwp' ); ?>">
                    <?php foreach ( $currencies as $code => $name ) : ?>
                        <?php
                        $_select = '';
                        if ( $code === $selected_currency ) {
                            $_select = ' selected ';
                        }
                            echo '<option value="' . esc_html( $code ) . '" ' . esc_html( $_select ) . '>' . esc_html( $name['symbol'] ) . ' - ' . esc_html( $name['name'] ) . '</option>';
                        ?>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php
        $positions = array(
            'left'        => esc_html__( 'Left', 'mainwp' ),
            'right'       => esc_html__( 'Right', 'mainwp' ),
            'left_space'  => esc_html__( 'Left Space', 'mainwp' ),
            'right_space' => esc_html__( 'Right Space', 'mainwp' ),
        );

        $def_val = is_array( $default ) && isset( $default['currency_position'] ) ? $default['currency_position'] : '';

        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-settings" default-indi-value="<?php echo esc_attr( $def_val ); ?>">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'cost_tracker_currency_position', $currency_position );
            esc_html_e( 'Currency symbol position', 'mainwp' );
            ?>
            </label>
            <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Choose the position of the currency symbol: before or after the amount.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                <div class="ui selection dropdown">
                    <input type="hidden" class="settings-field-value-change-handler" aria-label="<?php esc_attr_e( 'Select preferred currency symbol position.', 'mainwp' ); ?>"  name="mainwp_module_cost_tracker_currency_format[currency_position]" value="<?php echo esc_attr( $currency_position ); ?>">
                    <i class="dropdown icon"></i>
                    <div class="default text"><?php echo esc_html__( 'Left', 'mainwp' ); ?></div>
                    <div class="menu">
                        <?php
                        foreach ( $positions as $code => $name ) {
                            ?>
                            <div class="item" data-value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <?php
        $def_val = is_array( $default ) && isset( $default['thousand_separator'] ) ? $default['thousand_separator'] : '';
        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-settings" default-indi-value="<?php echo esc_attr( $def_val ); ?>">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'cost_tracker_thousand_separator', $thousand_separator );
            esc_html_e( 'Thousand separator', 'mainwp' );
            ?>
            </label>
            <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select a separator for thousands to enhance number readability.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                <input type="text" class="regular-text settings-field-value-change-handler" aria-label="<?php esc_attr_e( 'Select a separator for thousands.', 'mainwp' ); ?>"  name="mainwp_module_cost_tracker_currency_format[thousand_separator]" value="<?php echo esc_html( $thousand_separator ); ?>" />
            </div>
        </div>
        <?php
        $def_val = is_array( $default ) && isset( $default['decimal_separator'] ) ? $default['decimal_separator'] : '';
        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-settings"  default-indi-value="<?php echo esc_attr( $def_val ); ?>">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'cost_tracker_decimal_separator', $decimal_separator );
            esc_html_e( 'Decimal separator', 'mainwp' );
            ?>
            </label>
            <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Choose a symbol to separate decimal portions in numbers.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                <input type="text" aria-label="<?php esc_attr_e( 'Choose a symbol to separate decimal portions in numbers.', 'mainwp' ); ?>" name="mainwp_module_cost_tracker_currency_format[decimal_separator]" value="<?php echo esc_html( $decimal_separator ); ?>" class="settings-field-value-change-handler regular-text"/>
            </div>
        </div>
        <?php
        $def_val = is_array( $default ) && isset( $default['decimals'] ) ? $default['decimals'] : '';
        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cost-settings" default-indi-value="<?php echo esc_attr( $def_val ); ?>">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'cost_tracker_decimals', intval( $decimals ) );
            esc_html_e( 'Decimal places', 'mainwp' );
            ?>
            </label>
            <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Set the number of decimal places for numerical values.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
            <input type="number" aria-label="<?php esc_attr_e( 'Set the number of decimal places for numerical values.', 'mainwp' ); ?>" class="regular-text small-text settings-field-value-change-handler" name="mainwp_module_cost_tracker_currency_format[decimals]" id="mainwp_module_cost_tracker_currency_format[decimals]" placeholder="" min="1" max="8" step="1" value="<?php echo intval( $decimals ); ?>">
            </div>
        </div>
        <div class="ui grid field settings-field-indicator-wrapper default-product-categories settings-field-indicator-cost-settings ">
            <label class="six wide column middle aligned">
            <?php
            $indi_val = $cust_product_types ? 1 : 0;
            MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
            esc_html_e( 'Default product categories', 'mainwp' );
            ?>
            </label>
            <div class="ui five wide column" data-tooltip="<?php esc_attr_e( 'Customize and create product categories.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                <?php
                if ( is_array( $default_product_types ) ) {
                    foreach ( $default_product_types as $slug => $title ) {
                        ?>
                        <div class="cost-tracker-product-types-item">

                            <input type="hidden" value="<?php echo esc_attr( $slug ); ?>" name="cost_tracker_default_product_types[slug][]"/>
                            <input type="hidden" value="<?php echo esc_attr( $title ); ?>" name="cost_tracker_default_product_types[title][]"/>

                            <div class="ui left right action input">
                                <div class="cost_tracker_settings_product_categories_icon_wrapper">
                                    <?php
                                    $selected_default_icon = '';
                                    $selected_prod_icon    = isset( $product_types_icons[ $slug ] ) ? $product_types_icons[ $slug ] : '';
                                    if ( empty( $selected_prod_icon ) && isset( $product_types_default_icons[ $slug ] ) ) {
                                        $selected_default_icon = $product_types_default_icons[ $slug ];
                                        $selected_prod_icon    = 'deficon:' . $selected_default_icon;
                                    } elseif ( ! empty( $selected_prod_icon ) && false !== strpos( $selected_prod_icon, 'deficon:' ) ) {
                                        $selected_default_icon = str_replace( 'deficon:', '', $selected_prod_icon );
                                    }
                                    $selected_color = isset( $product_colors[ $slug ] ) ? $product_colors[ $slug ] : '#34424D';
                                    $this->render_icons_select( $product_default_icons, $selected_default_icon, $selected_color );
                                    ?>
                                    <input type="hidden" name="cost_tracker_default_product_types[icon][]" id="cost_tracker_default_product_types[icon][]"  value="<?php echo esc_attr( $selected_prod_icon ); ?>" />
                                </div>
                                <input type="text" style="width:200px;border-radius:0px" class="regular-text ui disabled input" readonly="readonly" value="<?php echo esc_attr( $title ); ?>"/>
                                <input type="color" data-tooltip="Color will update on save" data-position="top center" data-inverted="" name="cost_tracker_default_product_types[color][]" class="mainwp-color-picker-input" id="cost_tracker_default_product_types[color][]"  value="<?php echo esc_attr( $selected_color ); ?>" />
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                <?php } ?>
            </div>
        </div>
        <div class="ui grid field">
            <label class="six wide column middle aligned"></label>
            <div class="ui five wide column module-cost-tracker-settings-custom-product-types-wrapper">
                <?php
                foreach ( $cust_product_types as $slug => $title ) {
                    if ( empty( $slug ) || empty( $title ) ) {
                        continue;
                    }
                    ?>
                    <div class="cost-tracker-product-types-item">
                            <input type="hidden" value="<?php echo esc_attr( $slug ); ?>" name="cost_tracker_custom_product_types[slug][]"/>
                            <div class="ui left right action input">
                            <div style="display:inline-block;" class="cost_tracker_settings_product_categories_icon_wrapper">
                                <?php
                                $selected_default_icon = '';
                                $selected_prod_icon    = isset( $product_types_icons[ $slug ] ) ? $product_types_icons[ $slug ] : '';
                                if ( empty( $selected_prod_icon ) ) {
                                    $selected_default_icon = Cost_Tracker_Utility::get_product_default_icons( false, 'default_custom_product_type' );
                                    $selected_prod_icon    = 'deficon:' . $selected_default_icon;
                                } elseif ( ! empty( $selected_prod_icon ) && false !== strpos( $selected_prod_icon, 'deficon:' ) ) {
                                    $selected_default_icon = str_replace( 'deficon:', '', $selected_prod_icon );
                                }
                                $selected_color = isset( $product_colors[ $slug ] ) ? $product_colors[ $slug ] : '#34424D';
                                $this->render_icons_select( $product_default_icons, $selected_default_icon, $selected_color, 'mainwp-module-cost-tracker-select-custom-product-types-icons' );
                                ?>
                                <input type="hidden" name="cost_tracker_custom_product_types[icon][]" id="cost_tracker_custom_product_types[icon][]"  value="<?php echo esc_attr( $selected_prod_icon ); ?>" />
                            </div>
                            <input type="text" style="width:200px;border-radius:0px" class="regular-text settings-field-value-change-handler" value="<?php echo esc_attr( $title ); ?>" name="cost_tracker_custom_product_types[title][]"/>
                            <input type="color" data-tooltip="Color will update on save" data-position="top center" data-inverted="" name="cost_tracker_custom_product_types[color][]" class="mainwp-color-picker-input" id="cost_tracker_custom_product_types[color][]"  value="<?php echo esc_attr( $selected_color ); ?>" />
                        </div>
                    </div>
                    <?php
                }
                ?>
                <div class="ui hidden divider cost-tracker-product-types-bottom"></div>
                <a href="javascript:void(0);" class="module-cost-tracker-add-custom-product-types" add-custom-product-types-tmpl="<?php echo esc_attr( $this->add_custom_product_types_tmpl( $product_default_icons ) ); ?>"><span class="ui green text "><?php esc_html_e( 'Add new', 'mainwp' ); ?></span></a>

            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper custom-payment-methods settings-field-indicator-cost-settings">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $cust_payment_methods );
            esc_html_e( 'Custom payment methods', 'mainwp' );
            ?>
            </label>
            <div class="ui ten wide column module-cost-tracker-settings-custom-payment-methods-wrapper" data-tooltip="<?php esc_attr_e( 'Create custom payment methods.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                <?php
                foreach ( $cust_payment_methods as $slug => $title ) {
                    if ( empty( $slug ) || empty( $title ) ) {
                        continue;
                    }
                    ?>
                    <div class="ui two columns grid cost-tracker-payment-methods-item">
                        <div class="ui column">
                            <input type="hidden" value="<?php echo esc_attr( $slug ); ?>" name="cost_tracker_custom_payment_methods[slug][]"/>
                            <input type="text" class="regular-text settings-field-value-change-handler" value="<?php echo esc_attr( $title ); ?>" name="cost_tracker_custom_payment_methods[title][]" >
                        </div>
                    </div>
                    <?php
                }
                ?>
                <div class="ui hidden divider cost-tracker-payment-methods-bottom"></div>
                <a href="javascript:void(0);" class="module-cost-tracker-add-custom-payment-methods" add-custom-payment-methods-tmpl="<?php echo esc_attr( $this->add_custom_payment_methods_tmpl() ); ?>"><span class="ui green text "><?php esc_html_e( 'Add new', 'mainwp' ); ?></span></a>
            </div>
        </div>

        <?php do_action( 'mainwp_module_cost_tracker_settings_bottom' ); ?>

        <input type="hidden" name="nonce" value="<?php echo esc_html( wp_create_nonce( 'module_cost_tracker_settings_nonce' ) ); ?>">
        <input type="hidden" name="mwp_cost_tracker_settings_submit" value="1">
        <div class="ui divider"></div>
        <input type="submit" value="<?php esc_html_e( 'Save Settings', 'mainwp' ); ?>" class="ui green big button" id="mainwp-module-cost-tracker-manager-save-settings-button" <?php echo apply_filters( 'mainwp_module_cost_tracker_manager_check_status', false ) ? 'disabled' : ''; ?>>
        <script type="text/javascript">
                    jQuery( document ).ready( function() {
                        jQuery( '.mainwp-module-cost-tracker-select-default-icons' ).dropdown( {
                            onChange: function( val ) {
                                let parent = jQuery( this ).closest('.cost_tracker_settings_product_categories_icon_wrapper');
                                jQuery(parent).find('input[name="cost_tracker_default_product_types[icon][]"]' ).val('deficon:' + val);
                            }
                        } );

                        jQuery( '.mainwp-module-cost-tracker-select-custom-product-types-icons' ).dropdown( {
                            onChange: function( val ) {
                                let parent = jQuery( this ).closest('.cost_tracker_settings_product_categories_icon_wrapper');
                                jQuery(parent).find('input[name="cost_tracker_custom_product_types[icon][]"]' ).val('deficon:' + val);
                            }
                        } );
                    } );
                </script>
        <?php
    }

    /**
     * Method render_icons_select().
     *
     * @param array  $default_icons default icons.
     * @param string $selected_def_icon selected default icon.
     * @param string $icon_color icon color.
     * @param string $select_img_cls icon class.
     */
    public function render_icons_select( $default_icons, $selected_def_icon = '', $icon_color = '', $select_img_cls = 'mainwp-module-cost-tracker-select-default-icons' ) {
        $color_style = ! empty( $icon_color ) ? ' style="color:' . esc_attr( $icon_color ) . '" ' : '';
        ?>
        <div class="ui four column selection search dropdown not-auto-init <?php echo esc_attr( $select_img_cls ); ?>" style="min-width:160px;">
            <div class="text">
                <?php echo empty( $selected_def_icon ) ? esc_html_e( 'Select icon', 'mainwp' ) : '<i class="' . esc_attr( $selected_def_icon ) . ' icon card" ' . $color_style . ' ></i>'; //phpcs:ignore -- ok. ?>
            </div>
            <i class="dropdown icon"></i>
            <div class="menu">
                <?php foreach ( $default_icons as $icon ) : ?>
                    <?php echo '<div class="item" data-value="' . esc_attr( $icon ) . '"><i class="' . esc_attr( $icon ) . ' icon card" ' . $color_style . '></i></div>'; //phpcs:ignore -- ok. ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Method add_custom_product_types_tmpl().
     *
     * @param array $product_default_icons default icons.
     */
    public function add_custom_product_types_tmpl( $product_default_icons ) {
        ob_start();
        ?>
        <div class="cost-tracker-product-types-item ui left right action input">
            <input type="hidden" value="" name="cost_tracker_custom_product_types[slug][]"/>
            <div style="display:inline-block;" class="cost_tracker_settings_product_categories_icon_wrapper">
                <?php
                    $selected_default_icon = Cost_Tracker_Utility::get_product_default_icons( false, 'default_custom_product_type' );
                    $selected_prod_icon    = 'deficon:' . $selected_default_icon;
                    $default_color         = '#34424D';
                    $this->render_icons_select( $product_default_icons, $selected_default_icon, $default_color, 'mainwp-module-cost-tracker-select-custom-product-types-icons' );
                ?>
                <input type="hidden" name="cost_tracker_custom_product_types[icon][]" id="cost_tracker_custom_product_types[icon][]"  value="<?php echo esc_attr( $selected_prod_icon ); ?>" />
            </div>
            <input type="text" style="width:200px;border-radius:0px" class="regular-text settings-field-value-change-handler" value="" name="cost_tracker_custom_product_types[title][]"/>
            <input type="color" data-tooltip="Color will update on save" data-position="top center" data-inverted="" name="cost_tracker_custom_product_types[color][]" class="mainwp-color-picker-input" id="cost_tracker_custom_product_types[color][]"  value="#ad0000" />
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Method add_custom_payment_methods_tmpl().
     */
    public function add_custom_payment_methods_tmpl() {
        ob_start();
        ?>
        <div class="ui two columns grid cost-tracker-payment-methods-item">
            <div class="ui column">
                <input type="hidden" value="" name="cost_tracker_custom_payment_methods[slug][]"/>
                <input type="text" class="regular-text settings-field-value-change-handler" value="" placeholder="<?php esc_attr_e( 'Title', 'mainwp' ); ?>" name="cost_tracker_custom_payment_methods[title][]"/>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
