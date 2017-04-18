<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2016, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.0.0
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	class FS_Payment extends FS_Entity {

		#region Properties

		/**
		 * @var number
		 */
		public $plugin_id;
		/**
		 * @var number
		 */
		public $user_id;
		/**
		 * @var number
		 */
		public $install_id;
		/**
		 * @var number
		 */
		public $subscription_id;
		/**
		 * @var number
		 */
		public $plan_id;
		/**
		 * @var number
		 */
		public $license_id;
		/**
		 * @var float
		 */
		public $gross;
		/**
		 * @var number
		 */
		public $bound_payment_id;
		/**
		 * @var string
		 */
		public $external_id;
		/**
		 * @var string
		 */
		public $gateway;
		/**
		 * @var string ISO 3166-1 alpha-2 - two-letter country code.
		 *
		 * @link http://www.wikiwand.com/en/ISO_3166-1_alpha-2
		 */
		public $country_code;
		/**
		 * @var string
		 */
		public $vat_id;
		/**
		 * @var float Actual Tax / VAT in $$$
		 */
		public $vat;

		#endregion Properties

		/**
		 * @param object|bool $payment
		 */
		function __construct( $payment = false ) {
			parent::__construct( $payment );
		}

		static function get_type() {
			return 'payment';
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.0
		 *
		 * @return bool
		 */
		function is_refund() {
			return ( parent::is_valid_id( $this->bound_payment_id ) && 0 > $this->gross );
		}
	}