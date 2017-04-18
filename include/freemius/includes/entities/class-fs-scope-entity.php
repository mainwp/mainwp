<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.0.4
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	class FS_Scope_Entity extends FS_Entity {
		/**
		 * @var string
		 */
		public $public_key;
		/**
		 * @var string
		 */
		public $secret_key;

		/**
		 * @param bool|stdClass $scope_entity
		 */
		function __construct( $scope_entity = false ) {
			parent::__construct( $scope_entity );
		}
	}