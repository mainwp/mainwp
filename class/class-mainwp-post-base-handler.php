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

		if ( isset( $_POST['dts'] ) ) {
			$ajaxPosts = get_option( 'mainwp_ajaxposts' );
			if ( ! is_array( $ajaxPosts ) ) {
				$ajaxPosts = array();
			}

			// If already processed, just quit!
			if ( isset( $ajaxPosts[ $action ] ) && ( $ajaxPosts[ $action ] == $_POST['dts'] ) ) {
				die( wp_json_encode( array( 'error' => __( 'Double request!', 'mainwp' ) ) ) );
			}

			$ajaxPosts[ $action ] = sanitize_text_field( wp_unslash( $_POST['dts'] ) );
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
	 * @param bool   $die return or exit.
	 *
	 * @return bool true or false
	 */
	public function check_security( $action = - 1, $query_arg = 'security', $die = true ) {
		$secure = true;
		if ( - 1 === $action ) {
			$secure = false;
		} else {
			$adminurl = strtolower( admin_url() );
			$referer  = strtolower( wp_get_referer() );
			$result   = isset( $_REQUEST[ $query_arg ] ) ? wp_verify_nonce( sanitize_key( $_REQUEST[ $query_arg ] ), $action ) : false;
			if ( ! $result && 0 !== strpos( $referer, $adminurl ) ) {
				$secure = false;
			}
		}

		if ( ! $secure ) {
			if ( $die ) {
				die( wp_json_encode( array( 'error' => __( 'Insecure request! Please try again. If you keep experiencing the problem, please contact the MainWP Support.', 'mainwp' ) ) ) );
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
	 */
	public function add_action( $action, $callback ) {
		add_action( 'wp_ajax_' . $action, $callback );
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
	 * Method add_security_nonce()
	 *
	 * Add security nonce.
	 *
	 * @param string $action Action to perform.
	 */
	public function add_security_nonce( $action ) {
		if ( ! is_array( self::$security_nonces ) ) {
			self::$security_nonces = array();
		}

		if ( ! function_exists( 'wp_create_nonce' ) ) {
			include_once ABSPATH . WPINC . '/pluggable.php';
		}
		self::$security_nonces[ $action ] = wp_create_nonce( $action );
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
