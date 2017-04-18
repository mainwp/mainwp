<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2016, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.2.0
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * @var array $VARS
	 */
	$slug = $VARS['slug'];
	/**
	 * @var Freemius $fs
	 */
	$fs = freemius( $slug );

	/**
	 * @var FS_Plugin_Tag $update
	 */
	$update = $fs->get_update( false, false );

	$is_paying              = $fs->is_paying();
	$user                   = $fs->get_user();
	$site                   = $fs->get_site();
	$name                   = $user->get_name();
	$license                = $fs->_get_license();
	$subscription           = $fs->_get_subscription();
	$plan                   = $fs->get_plan();
	$is_active_subscription = ( is_object( $subscription ) && $subscription->is_active() );
	$is_paid_trial          = $fs->is_paid_trial();
	$show_upgrade           = ( ! $is_paying && ! $is_paid_trial );

	$billing     = $fs->_fetch_billing();
	$has_billing = ( $billing instanceof FS_Billing );
	if ( ! $has_billing ) {
		$billing = new FS_Billing();
	}

	$readonly_attr = $has_billing ? 'readonly' : '';
?>

	<div id="fs_account" class="wrap">
		<h2 class="nav-tab-wrapper">
			<a href="<?php echo $fs->get_account_url() ?>" class="nav-tab"><?php _efs( 'account', $slug ) ?></a>
			<?php if ( $fs->has_addons() ) : ?>
				<a href="<?php echo $fs->_get_admin_page_url( 'addons' ) ?>"
				   class="nav-tab"><?php _efs( 'add-ons', $slug ) ?></a>
			<?php endif ?>
			<?php if ( $fs->is_not_paying() && $fs->has_paid_plan() ) : ?>
				<a href="<?php echo $fs->get_upgrade_url() ?>" class="nav-tab"><?php _efs( 'upgrade', $slug ) ?></a>
				<?php if ( $fs->apply_filters( 'show_trial', true ) && ! $fs->is_trial_utilized() && $fs->has_trial_plan() ) : ?>
					<a href="<?php echo $fs->get_trial_url() ?>"
					   class="nav-tab"><?php _efs( 'free-trial', $slug ) ?></a>
				<?php endif ?>
			<?php endif ?>
			<?php if ( ! $plan->is_free() ) : ?>
				<a href="<?php echo $fs->get_account_tab_url( 'billing' ) ?>"
				   class="nav-tab nav-tab-active"><?php _efs( 'billing', $slug ) ?></a>
			<?php endif ?>
		</h2>

		<div id="poststuff">
			<div id="fs_billing">
				<div class="has-sidebar has-right-sidebar">
					<div class="has-sidebar-content">
						<div class="postbox">
							<h3><span class="dashicons dashicons-businessman"></span> <?php _efs( 'billing', $slug ) ?></h3>
							<table id="fs_billing_address"<?php if ( $has_billing ) {
								echo ' class="fs-read-mode"';
							} ?>>
								<tr>
									<td><label><span><?php _efs( 'business-name', $slug ) ?>:</span> <input id="business_name" value="<?php echo $billing->business_name ?>" placeholder="<?php _efs( 'business-name', $slug ) ?>"></label></td>
									<td><label><span><?php _efs( 'tax-vat-id', $slug ) ?>:</span> <input id="tax_id" value="<?php echo $billing->tax_id ?>" placeholder="<?php _efs( 'tax-vat-id', $slug ) ?>"></label></td>
								</tr>
								<tr>
									<td><label><span><?php printf( __fs( 'address-line-n', $slug ), 1 ) ?>:</span> <input id="address_street" value="<?php echo $billing->address_street ?>" placeholder="<?php printf( __fs( 'address-line-n', $slug ), 1 ) ?>"></label></td>
									<td><label><span><?php printf( __fs( 'address-line-n', $slug ), 2 ) ?>:</span> <input id="address_apt" value="<?php echo $billing->address_apt ?>" placeholder="<?php printf( __fs( 'address-line-n', $slug ), 2 ) ?>"></label></td>
								</tr>
								<tr>
									<td><label><span><?php _efs( 'city', $slug ) ?> / <?php _efs( 'town', $slug ) ?>:</span> <input id="address_city" value="<?php echo $billing->address_city ?>" placeholder="<?php _efs( 'city', $slug ) ?> / <?php _efs( 'town', $slug ) ?>"></label></td>
									<td><label><span><?php _efs( 'zip-postal-code', $slug ) ?>:</span> <input id="address_zip" value="<?php echo $billing->address_zip ?>" placeholder="<?php _efs( 'zip-postal-code', $slug ) ?>"></label></td>
								</tr>
								<tr>
									<?php $countries = array(
										'AF' => 'Afghanistan',
										'AX' => 'Aland Islands',
										'AL' => 'Albania',
										'DZ' => 'Algeria',
										'AS' => 'American Samoa',
										'AD' => 'Andorra',
										'AO' => 'Angola',
										'AI' => 'Anguilla',
										'AQ' => 'Antarctica',
										'AG' => 'Antigua and Barbuda',
										'AR' => 'Argentina',
										'AM' => 'Armenia',
										'AW' => 'Aruba',
										'AU' => 'Australia',
										'AT' => 'Austria',
										'AZ' => 'Azerbaijan',
										'BS' => 'Bahamas',
										'BH' => 'Bahrain',
										'BD' => 'Bangladesh',
										'BB' => 'Barbados',
										'BY' => 'Belarus',
										'BE' => 'Belgium',
										'BZ' => 'Belize',
										'BJ' => 'Benin',
										'BM' => 'Bermuda',
										'BT' => 'Bhutan',
										'BO' => 'Bolivia',
										'BQ' => 'Bonaire, Saint Eustatius and Saba',
										'BA' => 'Bosnia and Herzegovina',
										'BW' => 'Botswana',
										'BV' => 'Bouvet Island',
										'BR' => 'Brazil',
										'IO' => 'British Indian Ocean Territory',
										'VG' => 'British Virgin Islands',
										'BN' => 'Brunei',
										'BG' => 'Bulgaria',
										'BF' => 'Burkina Faso',
										'BI' => 'Burundi',
										'KH' => 'Cambodia',
										'CM' => 'Cameroon',
										'CA' => 'Canada',
										'CV' => 'Cape Verde',
										'KY' => 'Cayman Islands',
										'CF' => 'Central African Republic',
										'TD' => 'Chad',
										'CL' => 'Chile',
										'CN' => 'China',
										'CX' => 'Christmas Island',
										'CC' => 'Cocos Islands',
										'CO' => 'Colombia',
										'KM' => 'Comoros',
										'CK' => 'Cook Islands',
										'CR' => 'Costa Rica',
										'HR' => 'Croatia',
										'CU' => 'Cuba',
										'CW' => 'Curacao',
										'CY' => 'Cyprus',
										'CZ' => 'Czech Republic',
										'CD' => 'Democratic Republic of the Congo',
										'DK' => 'Denmark',
										'DJ' => 'Djibouti',
										'DM' => 'Dominica',
										'DO' => 'Dominican Republic',
										'TL' => 'East Timor',
										'EC' => 'Ecuador',
										'EG' => 'Egypt',
										'SV' => 'El Salvador',
										'GQ' => 'Equatorial Guinea',
										'ER' => 'Eritrea',
										'EE' => 'Estonia',
										'ET' => 'Ethiopia',
										'FK' => 'Falkland Islands',
										'FO' => 'Faroe Islands',
										'FJ' => 'Fiji',
										'FI' => 'Finland',
										'FR' => 'France',
										'GF' => 'French Guiana',
										'PF' => 'French Polynesia',
										'TF' => 'French Southern Territories',
										'GA' => 'Gabon',
										'GM' => 'Gambia',
										'GE' => 'Georgia',
										'DE' => 'Germany',
										'GH' => 'Ghana',
										'GI' => 'Gibraltar',
										'GR' => 'Greece',
										'GL' => 'Greenland',
										'GD' => 'Grenada',
										'GP' => 'Guadeloupe',
										'GU' => 'Guam',
										'GT' => 'Guatemala',
										'GG' => 'Guernsey',
										'GN' => 'Guinea',
										'GW' => 'Guinea-Bissau',
										'GY' => 'Guyana',
										'HT' => 'Haiti',
										'HM' => 'Heard Island and McDonald Islands',
										'HN' => 'Honduras',
										'HK' => 'Hong Kong',
										'HU' => 'Hungary',
										'IS' => 'Iceland',
										'IN' => 'India',
										'ID' => 'Indonesia',
										'IR' => 'Iran',
										'IQ' => 'Iraq',
										'IE' => 'Ireland',
										'IM' => 'Isle of Man',
										'IL' => 'Israel',
										'IT' => 'Italy',
										'CI' => 'Ivory Coast',
										'JM' => 'Jamaica',
										'JP' => 'Japan',
										'JE' => 'Jersey',
										'JO' => 'Jordan',
										'KZ' => 'Kazakhstan',
										'KE' => 'Kenya',
										'KI' => 'Kiribati',
										'XK' => 'Kosovo',
										'KW' => 'Kuwait',
										'KG' => 'Kyrgyzstan',
										'LA' => 'Laos',
										'LV' => 'Latvia',
										'LB' => 'Lebanon',
										'LS' => 'Lesotho',
										'LR' => 'Liberia',
										'LY' => 'Libya',
										'LI' => 'Liechtenstein',
										'LT' => 'Lithuania',
										'LU' => 'Luxembourg',
										'MO' => 'Macao',
										'MK' => 'Macedonia',
										'MG' => 'Madagascar',
										'MW' => 'Malawi',
										'MY' => 'Malaysia',
										'MV' => 'Maldives',
										'ML' => 'Mali',
										'MT' => 'Malta',
										'MH' => 'Marshall Islands',
										'MQ' => 'Martinique',
										'MR' => 'Mauritania',
										'MU' => 'Mauritius',
										'YT' => 'Mayotte',
										'MX' => 'Mexico',
										'FM' => 'Micronesia',
										'MD' => 'Moldova',
										'MC' => 'Monaco',
										'MN' => 'Mongolia',
										'ME' => 'Montenegro',
										'MS' => 'Montserrat',
										'MA' => 'Morocco',
										'MZ' => 'Mozambique',
										'MM' => 'Myanmar',
										'NA' => 'Namibia',
										'NR' => 'Nauru',
										'NP' => 'Nepal',
										'NL' => 'Netherlands',
										'NC' => 'New Caledonia',
										'NZ' => 'New Zealand',
										'NI' => 'Nicaragua',
										'NE' => 'Niger',
										'NG' => 'Nigeria',
										'NU' => 'Niue',
										'NF' => 'Norfolk Island',
										'KP' => 'North Korea',
										'MP' => 'Northern Mariana Islands',
										'NO' => 'Norway',
										'OM' => 'Oman',
										'PK' => 'Pakistan',
										'PW' => 'Palau',
										'PS' => 'Palestinian Territory',
										'PA' => 'Panama',
										'PG' => 'Papua New Guinea',
										'PY' => 'Paraguay',
										'PE' => 'Peru',
										'PH' => 'Philippines',
										'PN' => 'Pitcairn',
										'PL' => 'Poland',
										'PT' => 'Portugal',
										'PR' => 'Puerto Rico',
										'QA' => 'Qatar',
										'CG' => 'Republic of the Congo',
										'RE' => 'Reunion',
										'RO' => 'Romania',
										'RU' => 'Russia',
										'RW' => 'Rwanda',
										'BL' => 'Saint Barthelemy',
										'SH' => 'Saint Helena',
										'KN' => 'Saint Kitts and Nevis',
										'LC' => 'Saint Lucia',
										'MF' => 'Saint Martin',
										'PM' => 'Saint Pierre and Miquelon',
										'VC' => 'Saint Vincent and the Grenadines',
										'WS' => 'Samoa',
										'SM' => 'San Marino',
										'ST' => 'Sao Tome and Principe',
										'SA' => 'Saudi Arabia',
										'SN' => 'Senegal',
										'RS' => 'Serbia',
										'SC' => 'Seychelles',
										'SL' => 'Sierra Leone',
										'SG' => 'Singapore',
										'SX' => 'Sint Maarten',
										'SK' => 'Slovakia',
										'SI' => 'Slovenia',
										'SB' => 'Solomon Islands',
										'SO' => 'Somalia',
										'ZA' => 'South Africa',
										'GS' => 'South Georgia and the South Sandwich Islands',
										'KR' => 'South Korea',
										'SS' => 'South Sudan',
										'ES' => 'Spain',
										'LK' => 'Sri Lanka',
										'SD' => 'Sudan',
										'SR' => 'Suriname',
										'SJ' => 'Svalbard and Jan Mayen',
										'SZ' => 'Swaziland',
										'SE' => 'Sweden',
										'CH' => 'Switzerland',
										'SY' => 'Syria',
										'TW' => 'Taiwan',
										'TJ' => 'Tajikistan',
										'TZ' => 'Tanzania',
										'TH' => 'Thailand',
										'TG' => 'Togo',
										'TK' => 'Tokelau',
										'TO' => 'Tonga',
										'TT' => 'Trinidad and Tobago',
										'TN' => 'Tunisia',
										'TR' => 'Turkey',
										'TM' => 'Turkmenistan',
										'TC' => 'Turks and Caicos Islands',
										'TV' => 'Tuvalu',
										'VI' => 'U.S. Virgin Islands',
										'UG' => 'Uganda',
										'UA' => 'Ukraine',
										'AE' => 'United Arab Emirates',
										'GB' => 'United Kingdom',
										'US' => 'United States',
										'UM' => 'United States Minor Outlying Islands',
										'UY' => 'Uruguay',
										'UZ' => 'Uzbekistan',
										'VU' => 'Vanuatu',
										'VA' => 'Vatican',
										'VE' => 'Venezuela',
										'VN' => 'Vietnam',
										'WF' => 'Wallis and Futuna',
										'EH' => 'Western Sahara',
										'YE' => 'Yemen',
										'ZM' => 'Zambia',
										'ZW' => 'Zimbabwe',
									) ?>
									<td><label><span><?php _efs( 'country', $slug ) ?>:</span> <select id="address_country_code">
												<?php if ( empty( $billing->address_country_code ) ) : ?>
													<option value=""
													        selected><?php _efs( 'select-country', $slug ) ?></option>
												<?php endif ?>
												<?php foreach ( $countries as $code => $country ) : ?>
													<option
														value="<?php echo $code ?>" <?php selected( $billing->address_country_code, $code ) ?>><?php echo $country ?></option>
												<?php endforeach ?>
											</select></label></td>
									<td><label><span><?php _efs( 'state', $slug ) ?> / <?php _efs( 'province', $slug ) ?>:</span>
											<input id="address_state" value="<?php echo $billing->address_state ?>" placeholder="<?php _efs( 'state', $slug ) ?> / <?php _efs( 'province', $slug ) ?>"></label></td>
								</tr>
								<tr>
									<td colspan="2">
										<button
											class="button"><?php _efs( $has_billing ? 'edit' : 'update', $slug ) ?></button>
									</td>
								</tr>
							</table>
						</div>
						<div class="postbox">
							<h3><span class="dashicons dashicons-paperclip"></span> <?php _efs( 'payments', $slug ) ?></h3>

							<?php
								$payments = $fs->_fetch_payments();
							?>

							<div class="inside">
								<table class="widefat">
									<thead>
									<tr>
										<th><?php _efs( 'id', $slug ) ?></th>
										<th><?php _efs( 'date', $slug ) ?></th>
										<!--		<th>--><?php //_efs( 'transaction' ) ?><!--</th>-->
										<th><?php _efs( 'amount', $slug ) ?></th>
										<th><?php _efs( 'invoice', $slug ) ?></th>
									</tr>
									</thead>
									<tbody>
									<?php $odd = true ?>
									<?php foreach ( $payments as $payment ) : ?>
										<tr<?php echo $odd ? ' class="alternate"' : '' ?>>
											<td><?php echo $payment->id ?></td>
											<td><?php echo date( 'M j, Y', strtotime( $payment->created ) ) ?></td>
											<td>$<?php echo $payment->gross ?></td>
											<td><a href="<?php echo $fs->_get_invoice_api_url( $payment->id ) ?>"
											       class="button button-small"
											       target="_blank"><?php _efs( 'invoice', $slug ) ?></a></td>
										</tr>
										<?php $odd = ! $odd; endforeach ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<script type="text/javascript">
		(function($){
			var $billingAddress = $('#fs_billing_address'),
				$billingInputs = $billingAddress.find('input, select');

			var setPrevValues = function () {
				$billingInputs.each(function () {
					$(this).attr('data-val', $(this).val());
				});
			};

			setPrevValues();

			var hasBillingChanged = function () {
				for (var i = 0, len = $billingInputs.length; i < len; i++){
					var $this = $($billingInputs[i]);
					if ($this.attr('data-val') !== $this.val()) {
						return true;
					}
				}

				return false;
			};

			var isEditAllFieldsMode = false;

			$billingAddress.find('.button').click(function(){
				$billingAddress.toggleClass('fs-read-mode');

				var isEditMode = !$billingAddress.hasClass('fs-read-mode');

				$(this)
					.html(isEditMode ? <?php echo json_encode(__fs('update', $slug)) ?> : <?php echo json_encode(__fs('edit', $slug)) ?>)
					.toggleClass('button-primary');

				if (isEditMode) {
					$('#business_name').focus().select();
					isEditAllFieldsMode = true;
				} else {
					isEditAllFieldsMode = false;

					if (!hasBillingChanged())
						return;

					var billing = {};

					$billingInputs.each(function(){
						if ($(this).attr('data-val') !== $(this).val()) {
							billing[$(this).attr('id')] = $(this).val();
						}
					});

					$.ajax({
						url    : ajaxurl,
						method : 'POST',
						data   : {
							action  : '<?php echo $fs->get_action_tag( 'update_billing' ) ?>',
							security: '<?php echo wp_create_nonce( $fs->get_action_tag( 'update_billing' ) ) ?>',
							slug    : '<?php echo $slug ?>',
							billing : billing
						},
						success: function (resultObj) {
							if (resultObj.success) {
								setPrevValues();
							} else {
								alert(resultObj.error);
							}
						}
					});
				}
			});

			$billingInputs
				// Get into edit mode upon selection.
				.focus(function () {
					var isEditMode = !$billingAddress.hasClass('fs-read-mode');

					if (isEditMode) {
						return;
					}

					$billingAddress.toggleClass('fs-read-mode');
					$billingAddress.find('.button')
						.html(<?php echo json_encode( __fs( 'update', $slug ) ) ?>)
						.toggleClass('button-primary');
				})
				// If blured after editing only one field without changes, exit edit mode.
				.blur(function () {
					if (!isEditAllFieldsMode && !hasBillingChanged()) {
						$billingAddress.toggleClass('fs-read-mode');
						$billingAddress.find('.button')
							.html(<?php echo json_encode( __fs( 'edit', $slug ) ) ?>)
							.toggleClass('button-primary');
					}
				});
		})(jQuery);
	</script>
<?php
	$params = array(
		'page'           => 'account',
		'module_id'      => $fs->get_id(),
		'module_slug'    => $slug,
		'module_version' => $fs->get_plugin_version(),
	);
	fs_require_template( 'powered-by.php', $params );
?>