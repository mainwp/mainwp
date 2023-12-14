<?php
/**
 * This class handles the security for MainWP Post.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Post_Base_Handler
 *
 * @package MainWP\Dashboard
 */
abstract class MainWP_Post_Base_Handler {

	/**
	 * Protected static variable to hold security nounces.
	 *
	 * @var string Security nonce.
	 */
	protected static $security_nonces;

	/**
	 * Protected static variable to hold security nounces.
	 *
	 * @var string Security nonce.
	 */
	protected static $security_names;

	/**
	 * Method init()
	 *
	 * Force Extending class to define this method.
	 *
	 * @return void
	 */
	abstract protected function init();


	/**
	 * Method secure_request()
	 *
	 * Add security check to request parameter
	 *
	 * @param string $action Action to perform.
	 * @param string $query_arg Query argument.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function secure_request( $action = '', $query_arg = 'security' ) {
		if ( ! MainWP_System_Utility::is_admin() ) {
			die( 0 );
		}
		if ( '' === $action ) {
			return;
		}

		$this->check_security( $action, $query_arg );

		if ( isset( $_POST['dts'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ajaxPosts = get_option( 'mainwp_ajaxposts' );
			if ( ! is_array( $ajaxPosts ) ) {
				$ajaxPosts = array();
			}

			// If already processed, just quit!
			if ( isset( $ajaxPosts[ $action ] ) && ( $ajaxPosts[ $action ] === $_POST['dts'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				die( wp_json_encode( array( 'error' => esc_html__( 'Double request!', 'mainwp' ) ) ) );
			}

			$ajaxPosts[ $action ] = sanitize_text_field( wp_unslash( $_POST['dts'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			MainWP_Utility::update_option( 'mainwp_ajaxposts', $ajaxPosts );
		}
	}

	/**
	 * Method check_security()
	 *
	 * Check security request.
	 *
	 * @param string $action Action to perform.
	 * @param string $query_arg Query argument.
	 * @param bool   $out_die return or exit.
	 *
	 * @return bool true or false
	 */
	public function check_security( $action = - 1, $query_arg = 'security', $out_die = true ) {
		$secure = true;
		if ( - 1 === $action ) {
			$secure = false;
		} else {
			$result = isset( $_REQUEST[ $query_arg ] ) ? wp_verify_nonce( sanitize_key( $_REQUEST[ $query_arg ] ), $action ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( ! $result ) {
				$secure = false;
			}
		}

		if ( ! $secure ) {
			if ( $out_die ) {
				die( wp_json_encode( array( 'error' => esc_html__( 'Insecure request! Please try again. If you keep experiencing the problem, please review MainWP Knowledgebase, and if you still have issues, please let us know in the MainWP Community.', 'mainwp' ) ) ) );
			} else {
				return false;
			}
		}
		return true;
	}

	/**
	 * Method add_action()
	 *
	 * Add ajax action.
	 *
	 * @param string $action Action to perform.
	 * @param string $callback Callback to perform.
	 * @param int    $priority priority aciton.
	 * @param int    $accepted number args.
	 */
	public function add_action( $action, $callback, $priority = 10, $accepted = 2 ) {
		add_action( 'wp_ajax_' . $action, $callback, $priority, $accepted );
		$this->add_action_nonce( $action ); // to fix conflict with Post S M T P plugin.
	}

	/**
	 * Method add_action_nonce()
	 *
	 * Add security nonce.
	 *
	 * @param string $action Action to perform.
	 */
	public function add_action_nonce( $action ) {
		if ( ! is_array( self::$security_names ) ) {
			self::$security_names = array();
		}
		self::$security_names[] = $action;
	}

	/**
	 * Create the security nonces.
	 *
	 * @return self $security_nonces.
	 */
	public function create_security_nonces() {

		if ( ! is_array( self::$security_nonces ) ) {
			self::$security_nonces = array();
		}
		self::$security_names = apply_filters( 'mainwp_create_security_nonces', self::$security_names );
		if ( ! empty( self::$security_names ) ) {
			if ( ! function_exists( 'wp_create_nonce' ) ) {
				include_once ABSPATH . WPINC . '/pluggable.php';
			}
			foreach ( self::$security_names as $action ) {
				self::$security_nonces[ $action ] = wp_create_nonce( $action );
			}
		}

		return self::$security_nonces;
	}
}
