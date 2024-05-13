<?php
/**
 * MainWP Deprecated Hooks
 *
 * Init mainwp deprecated hooks
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Deprecated_Hooks
 *
 * @package MainWP\Dashboard
 */
class MainWP_Deprecated_Hooks {

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Array of deprecated filters. Format of 'old' => 'new'.
	 *
	 * @var array
	 */
	public $deprecated_filters = array(
		'mainwp-getsites'                  => 'mainwp_getsites',
		'mainwp-getdbsites'                => 'mainwp_getdbsites',
		'mainwp-getgroups'                 => 'mainwp_getgroups',
		'mainwp-activated-check'           => 'mainwp_activated_check',
		'mainwp-extension-available-check' => '',
		'mainwp-manager-getextensions'     => 'mainwp_manager_getextensions',
		'mainwp-extension-enabled-check'   => 'mainwp_extension_enabled_check',
	);

	/**
	 * Array of deprecated actions. Format of 'old' => 'new'.
	 *
	 * @var array
	 */
	public $deprecated_actions = array(
		'mainwp_activate_extention'    => 'mainwp_activate_extension',
		'mainwp_deactivate_extention'  => 'mainwp_deactivate_extension',
		'mainwp-pageheader-sites'      => 'mainwp_pageheader_sites',
		'mainwp-pagefooter-sites'      => 'mainwp_pagefooter_sites',
		'mainwp-pageheader-extensions' => 'mainwp_pageheader_extensions',
		'mainwp-pagefooter-extensions' => 'mainwp_pagefooter_extensions',
	);

	/**
	 * Array of versions of deprecated hooks.
	 *
	 * @var array
	 */
	public $deprecated_version = array(
		'mainwp-getsites'                  => '4.1',
		'mainwp-getdbsites'                => '4.1',
		'mainwp-activated'                 => '4.1',
		'mainwp-activated-check'           => '4.1',
		'mainwp-extension-available-check' => '4.1',
		'mainwp-getgroups'                 => '4.1',
		'mainwp-manager-getextensions'     => '4.1',
		'mainwp_activate_extention'        => '4.1',
		'mainwp_deactivate_extention'      => '4.1',
		'mainwp-extension-enabled-check'   => '4.1',
		'mainwp-pageheader-sites'          => '4.1',
		'mainwp-pagefooter-sites'          => '4.1',
		'mainwp-pageheader-extensions'     => '4.1',
		'mainwp-pagefooter-extensions'     => '4.1',
		'mainwp-pageheader-post'           => '4.1.5.1',
		'mainwp-pagefooter-post'           => '4.1.5.1',
		'mainwp-pageheader-page'           => '4.1.5.1',
	);

	/**
	 * Method instance()
	 *
	 * @static
	 * @return class instance
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Deprecated_Hooks constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
	}

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Get old hooks to map to replacement hook.
	 *
	 * @param  string $old_hook Old hook name.
	 * @return array
	 */
	public function get_replacement_hooks( $old_hook ) {
		if ( isset( $this->deprecated_filters[ $old_hook ] ) ) {
			return $this->deprecated_filters[ $old_hook ];
		}
		if ( isset( $this->deprecated_actions[ $old_hook ] ) ) {
			return $this->deprecated_actions[ $old_hook ];
		}
		return false;
	}

	/**
	 * Get deprecated version.
	 *
	 * @param string $old_hook Old hook name.
	 * @return string
	 */
	protected function get_deprecated_version( $old_hook ) {
		return ! empty( $this->deprecated_version[ $old_hook ] ) ? $this->deprecated_version[ $old_hook ] : WC_VERSION;
	}

	/**
	 * If the filter is Deprecated, display a deprecated notice.
	 */
	public static function maybe_handle_deprecated_hook() {
		$current_hook = current_filter();
		$new_hook     = self::instance()->get_replacement_hooks( $current_hook );
		if ( false !== $new_hook ) {
			self::instance()->deprecated_message( $current_hook, $new_hook );
		}
	}

	/**
	 * Display a deprecated notice for old hooks.
	 *
	 * @param string $old_hook Old hook.
	 * @param string $new_hook New hook.
	 * @param string $message message.
	 */
	public function deprecated_message( $old_hook, $new_hook, $message = null ) {
		$version = esc_html( $this->get_deprecated_version( $old_hook ) );

		$is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : defined( 'DOING_AJAX' );

		// @codingStandardsIgnoreStart
		if ( $is_ajax ) {
			do_action( 'deprecated_hook_run', $old_hook, $new_hook, $version, $message );
			$log_string	 = "{$old_hook} is deprecated since version {$version}";
			$log_string	 .= $new_hook ? "! Use {$new_hook} instead." : ' with no alternative available.';
			error_log( $log_string );
		} else {
			_deprecated_hook( $old_hook, $version, $new_hook, $message );
		}
		// @codingStandardsIgnoreEnd
	}
}
