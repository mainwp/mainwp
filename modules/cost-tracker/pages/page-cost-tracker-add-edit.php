<?php
/**
 * MainWP Module Cost Tracker Dashboard class.
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
	 * Method render_add_edit_page()
	 *
	 * When the page loads render the body content.
	 */
	public function render_add_edit_page() {

		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_cost_tracker' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage cost tracker', 'mainwp' ) );
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
	}

	/**
	 * Render settings
	 *
	 * Renders the extension settings page.
	 *
	 * @param int $edit_id Cost Id to edit.
	 */
	public function render_add_edit_content( $edit_id ) {

		$edit_cost                    = false;
		$selected_payment_type        = '';
		$selected_product_type        = '';
		$selected_license_type        = '';
		$selected_renewal             = '';
		$selected_cost_tracker_status = '';
		$last_renewal                 = time();
		$next_renewal                 = 0;
		$selected_payment_method      = 'paypal';
		$slug                         = '';

		$is_plugintheme = true;

		if ( $edit_id ) {
			$edit_cost = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $edit_id );
			if ( $edit_cost ) {
				$selected_payment_type        = $edit_cost->type;
				$selected_product_type        = $edit_cost->product_type;
				$selected_license_type        = $edit_cost->license_type;
				$selected_cost_tracker_status = $edit_cost->cost_status;
				$selected_renewal             = $edit_cost->renewal_type;
				$last_renewal                 = $edit_cost->last_renewal;
				$next_renewal                 = $edit_cost->next_renewal;
				$selected_payment_method      = $edit_cost->payment_method;
				$slug                         = $edit_cost->slug;
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

		?>
		<div class="mainwp-main-content">
			<div class="ui segment">
				<?php
				if ( isset( $_GET['message'] ) && ! empty( $_GET['message'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$message = esc_html__( 'Subscription saved.', 'mainwp' );
					?>
				<div class="ui green message" id="mainwp-module-cost-tracker-message-zone" >
					<?php echo esc_html( $message ); ?>
					<i class="ui close icon"></i>
				</div>
				<?php } ?>
				<div class="ui red message" id="mainwp-module-cost-tracker-error-zone" style="display:none">
					<div class="error-message"></div>			
					<i class="ui close icon"></i>
				</div>
				<?php if ( $edit_cost ) : ?>
					<h3 class="ui dividing header"><?php echo esc_html__( 'Edit ', 'mainwp' ) . esc_html__( $edit_cost->name ); ?></h3>
				<?php else : ?>
					<h3 class="ui dividing header"><?php esc_html_e( 'Add New Cost', 'mainwp' ); ?></h3>
				<?php endif; ?>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Name', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter the Company (Product) name.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="text" name="mainwp_module_cost_tracker_edit_name" id="mainwp_module_cost_tracker_edit_name" value="<?php echo $edit_cost ? esc_html( $edit_cost->name ) : ''; ?>">
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Product URL', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter the URL of the product (optional).', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="text" name="mainwp_module_cost_tracker_edit_url" id="mainwp_module_cost_tracker_edit_url" value="<?php echo $edit_cost ? esc_html( $edit_cost->url ) : ''; ?>">
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Type', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select the type of this cost.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select id="mainwp_module_cost_tracker_edit_payment_type" name="mainwp_module_cost_tracker_edit_payment_type" class="ui dropdown not-auto-init">
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
				
				<div class="ui grid field hide-if-lifetime-subscription-selected" <?php echo $lifetime_selected ? 'style="display:none;"' : ''; ?>>
					<label class="six wide column middle aligned"><?php esc_html_e( 'Renewal frequency', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter renewal frequency.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select id="mainwp_module_cost_tracker_edit_renewal_type" name="mainwp_module_cost_tracker_edit_renewal_type" class="ui dropdown">
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
				<div class="ui grid field hide-if-lifetime-subscription-selected" <?php echo $lifetime_selected ? 'style="display:none;"' : ''; ?>>
					<label class="six wide column middle aligned"><?php esc_html_e( 'Subscription status', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter subscription status.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select id="mainwp_module_cost_tracker_edit_cost_tracker_status" name="mainwp_module_cost_tracker_edit_cost_tracker_status" class="ui dropdown">
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
					<label class="six wide column middle aligned"><?php esc_html_e( 'Next renewal', 'mainwp' ); ?></label>
						<div class="five wide column" data-inverted="" data-position="top left">
						<?php Cost_Tracker_Admin::generate_next_renewal( $edit_cost ); ?>
					</div>
				</div>
				<?php endif; ?>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Category', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select the category for this cost.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select id="mainwp_module_cost_tracker_edit_product_type" name="mainwp_module_cost_tracker_edit_product_type" class="ui dropdown not-auto-init">
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
				<div class="ui grid field hide-if-product-type-isnot-plugintheme" <?php echo $is_plugintheme ? '' : 'style="display:none;"'; ?>>
					<label class="six wide column middle aligned"><?php esc_html_e( 'Slug', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter the product slug.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="text" name="mainwp_module_cost_tracker_edit_product_slug" id="mainwp_module_cost_tracker_edit_product_slug" value="<?php echo esc_attr( $slug ); ?>">
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'License type', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Select the license type of this cost.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select id="mainwp_module_cost_tracker_edit_license_type" name="mainwp_module_cost_tracker_edit_license_type" class="ui dropdown">
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
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Price', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter Subscription Price.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<label for="mainwp_module_cost_tracker_edit_price" class="ui label">$</label>
							<input type="text" name="mainwp_module_cost_tracker_edit_price" id="mainwp_module_cost_tracker_edit_price" value="<?php echo $edit_cost ? esc_html( $edit_cost->price ) : ''; ?>">
						</div>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Purchase date', 'mainwp' ); ?></label>
					<div class="five wide column" data-tooltip="<?php esc_attr_e( 'Enter the purchase date.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui calendar mainwp_datepicker">
							<div class="ui input left icon">
								<i class="calendar icon"></i>
								<input type="text" placeholder="<?php esc_attr_e( 'Select date', 'mainwp' ); ?>" id="mainwp_module_cost_tracker_edit_last_renewal" name="mainwp_module_cost_tracker_edit_last_renewal" value="<?php echo $last_renewal ? esc_attr( MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $last_renewal ) ) ) : ''; ?>" />
							</div>
						</div>
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Payment method', 'mainwp' ); ?></label>
					<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the payment method.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select id="mainwp_module_cost_tracker_edit_payment_method" name="mainwp_module_cost_tracker_edit_payment_method" class="ui dropdown">
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
				
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Notes', 'mainwp' ); ?></label>
					<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the description for this cost tracking item.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<textarea id="mainwp_module_cost_tracker_edit_note" name="mainwp_module_cost_tracker_edit_note"><?php echo $edit_cost ? esc_html( $edit_cost->note ) : ''; ?></textarea>
					</div>
				</div>
				<input type="hidden" name="mainwp_module_cost_tracker_edit_id" value="<?php echo $edit_cost ? intval( $edit_cost->id ) : 0; ?>">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'module_cost_tracker_edit_nonce' ) ); ?>">
				<input type="hidden" name="mwp_cost_tracker_editing_submit" value="1">
			</div>
		</div>
		<div class="mainwp-side-content mainwp-no-padding">
			<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
				<div class="title active"><i class="dropdown icon"></i> <?php echo esc_html__( 'Select Sites', 'mainwp' ); ?></div>
				<div class="content active">
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
			});
		</script>
		<?php
	}
}
