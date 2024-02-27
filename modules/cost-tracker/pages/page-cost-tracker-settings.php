<?php
/**
 * MainWP Module Cost Tracker Settings class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_Utility;
use function MainWP\Dashboard\mainwp_current_user_have_right;
use function MainWP\Dashboard\mainwp_do_not_have_permissions;

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
	 * Get Instance
	 *
	 * Creates public static instance.
	 *
	 * @static
	 *
	 * @return Cost_Tracker_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
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
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_cost_tracker' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage cost tracker', 'mainwp' ) );
			return;
		}
		Cost_Tracker_Admin::render_header( 'settings' );

		?>
	
		<div class="ui segment" id="mainwp-module-cost-tracker-settings-tab">				
			<form id="mainwp-module-cost-tracker-settings-form" method="post" action="admin.php?page=CostTrackerSettings" class="ui form">
				<?php $this->render_settings_content(); ?>
			</form>
			<div class="ui clearing hidden divider"></div>
		</div>
		<?php
	}

	/**
	 * Render settings content.
	 *
	 * Renders the extension settings page.
	 */
	public function render_settings_content() {

		$currencies        = Cost_Tracker_Utility::get_all_currency_symbols();
		$selected_currency = Cost_Tracker_Utility::get_instance()->get_option( 'currency' );
		$currency_format   = Cost_Tracker_Utility::get_instance()->get_option( 'currency_format', array() );

		if ( ! is_array( $currency_format ) ) {
			$currency_format = array();
		}

		$default         = Cost_Tracker_Utility::default_currency_settings();
		$currency_format = array_merge( $default, $currency_format );

		$currency_position  = $currency_format['currency_position'];
		$thousand_separator = $currency_format['thousand_separator'];
		$decimal_separator  = $currency_format['decimal_separator'];
		$decimals           = $currency_format['decimals'];

		$cust_product_types   = Cost_Tracker_Utility::get_instance()->get_option( 'custom_product_types', array() );
		$cust_payment_methods = Cost_Tracker_Utility::get_instance()->get_option( 'custom_payment_methods', array() );

		if ( isset( $_GET['message'] ) && ! empty( $_GET['message'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = esc_html__( 'Settings saved.', 'mainwp' );
			?>
			<div class="ui green message" id="mainwp-module-cost-tracker-message-zone" >
				<?php echo esc_html( $message ); ?>
				<i class="ui close icon"></i>
			</div>
			<?php
		}
		?>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Currency', 'mainwp' ); ?></label>
			<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select preferred currency.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<select id="mainwp_module_cost_tracker_settings_currency" name="mainwp_module_cost_tracker_settings_currency" class="ui search selection dropdown">
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
		?>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Currency symbol position ', 'mainwp' ); ?></label>
			<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Choose the position of the currency symbol: before or after the amount.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<div class="ui selection dropdown">
					<input type="hidden" name="mainwp_module_cost_tracker_currency_format[currency_position]" value="<?php echo esc_attr( $currency_position ); ?>">
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
			
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Thousand separator', 'mainwp' ); ?></label>
			<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select a separator for thousands to enhance number readability.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<input type="text" name="mainwp_module_cost_tracker_currency_format[thousand_separator]" value="<?php echo esc_html( $thousand_separator ); ?>" class="regular-text"/>
			</div>
		</div>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Decimal separator', 'mainwp' ); ?></label>
			<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Choose a symbol to separate decimal portions in numbers.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<input type="text" name="mainwp_module_cost_tracker_currency_format[decimal_separator]" value="<?php echo esc_html( $decimal_separator ); ?>" class="regular-text"/>
			</div>
		</div>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Decimal places', 'mainwp' ); ?></label>
			<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Set the number of decimal places for numerical values.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
			<input type="number" name="mainwp_module_cost_tracker_currency_format[decimals]" id="mainwp_module_cost_tracker_currency_format[decimals]" class="small-text" placeholder="" min="1" max="8" step="1" value="<?php echo intval( $decimals ); ?>">
			</div>
		</div>

		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Custom product types', 'mainwp' ); ?></label>
			<div class="ui ten wide column module-cost-tracker-settings-custom-product-types-wrapper" data-tooltip="<?php esc_attr_e( 'Create custom product types you need track.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<?php
				foreach ( $cust_product_types as $slug => $title ) {
					if ( empty( $slug ) || empty( $title ) ) {
						continue;
					}
					?>
					<div class="ui two columns grid cost-tracker-product-types-item">
						<div class="ui column">
							<input type="hidden" value="<?php echo esc_attr( $slug ); ?>" name="cost_tracker_custom_product_types[slug][]"/>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $title ); ?>" name="cost_tracker_custom_product_types[title][]"/>
						</div>									
					</div>								
					<?php
				}
				?>
				<div class="ui hidden divider cost-tracker-product-types-bottom"></div>	
				<a href="javascript:void(0);" class="module-cost-tracker-add-custom-product-types" add-custom-product-types-tmpl="<?php echo esc_attr( $this->add_custom_product_types_tmpl() ); ?>"><span class="ui green text "><?php esc_html_e( 'Add new', 'mainwp' ); ?></span></a>
			
			</div>
		</div>

		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Custom payment methods', 'mainwp' ); ?></label>
			<div class="ui ten wide column module-cost-tracker-settings-custom-payment-methods-wrapper" data-tooltip="<?php esc_attr_e( 'Create custom payment methods.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<?php
				foreach ( $cust_payment_methods as $slug => $title ) {
					if ( empty( $slug ) || empty( $title ) ) {
						continue;
					}
					?>
					<div class="ui two columns grid cost-tracker-payment-methods-item">
						<div class="ui column">
							<input type="hidden" value="<?php echo esc_attr( $slug ); ?>" name="cost_tracker_custom_payment_methods[slug][]"/>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $title ); ?>" name="cost_tracker_custom_payment_methods[title][]" >
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
		<?php
	}

	/**
	 * Method add_custom_product_types_tmpl().
	 */
	public function add_custom_product_types_tmpl() {
		ob_start();
		?>
		<div class="ui two columns grid cost-tracker-product-types-item">
			<div class="ui column">
				<input type="hidden" value="" name="cost_tracker_custom_product_types[slug][]"/>
				<input type="text" class="regular-text" value="" placeholder="<?php esc_attr_e( 'Title', 'mainwp' ); ?>" name="cost_tracker_custom_product_types[title][]"/>
			</div>									
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
				<input type="text" class="regular-text" value="" placeholder="<?php esc_attr_e( 'Title', 'mainwp' ); ?>" name="cost_tracker_custom_payment_methods[title][]"/>
			</div>									
		</div>	
		<?php
		return ob_get_clean();
	}
}
