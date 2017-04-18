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

	class FS_Plugin_Tag extends FS_Entity {
		public $version;
		public $url;

		function __construct( $tag = false ) {
			parent::__construct( $tag );
		}

		static function get_type() {
			return 'tag';
		}
	}