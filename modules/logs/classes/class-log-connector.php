<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

/**
 * Class Log_Connector
 *
 * @package MainWP\Dashboard
 */
abstract class Log_Connector {
	/**
	 * Connector slug
	 *
	 * @var string
	 */
	public $name = null;

	/**
	 * Actions registered for this connector
	 *
	 * @var array
	 */
	public $actions = array();


	/**
	 * Holds connector registration status flag.
	 *
	 * @var bool
	 */
	private $is_registered = false;

	/**
	 * Is the connector currently registered?
	 *
	 * @return boolean
	 */
	public function is_registered() {
		return $this->is_registered;
	}

	/**
	 * Register all context hooks
	 */
	public function register() { //phpcs:ignore -- overrided.

		if ( $this->is_registered ) {
			return;
		}

		foreach ( $this->actions as $action ) {
			add_action( $action, array( $this, 'callback' ), 10, 99 );
		}

		$this->is_registered = true;
	}

	/**
	 * Callback for all registered hooks throughout Log
	 * Looks for a class method with the convention: "callback_{action name}"
	 */
	public function callback() {
		$action   = current_filter();
		$callback = array( $this, 'callback_' . preg_replace( '/[^A-Za-z0-9_\-]/', '_', $action ) ); // to fix A-Z charater in callback name.

		// Call the real function.
		if ( is_callable( $callback ) ) {
			return call_user_func_array( $callback, func_get_args() );
		}
	}



	/**
	 * Log handler
	 *
	 * @param string   $message sprintf-ready error message string.
	 * @param array    $args     sprintf (and extra) arguments to use.
	 * @param int      $site_id  Target site id.
	 * @param string   $context Context of the event.
	 * @param string   $action  Action of the event.
	 * @param int|null $state action status: null - N/A, 0 - failed, 1 - success.
	 * @param int      $user_id    User responsible for the event.
	 *
	 * @return bool
	 */
	public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
		$connector = $this->name;

		$data = apply_filters(
			'mainwp_module_log_data',
			compact( 'connector', 'message', 'args', 'site_id', 'context', 'action', 'state', 'user_id' )
		);
		if ( ! $data ) {
			return false;
		} else {
			$connector = $data['connector'];
			$message   = $data['message'];
			$args      = $data['args'];
			$site_id   = $data['site_id'];
			$context   = $data['context'];
			$action    = $data['action'];
			$user_id   = $data['user_id'];
			$state     = $data['state'];
		}
		$created_timestamp = null;
		return call_user_func_array( array( Log_Manager::instance()->log, 'log' ), compact( 'connector', 'message', 'args', 'site_id', 'context', 'action', 'state', 'user_id' ) );
	}

	/**
	 * Compare two values and return changed keys if they are arrays
	 *
	 * @param  mixed    $old_value Value before change.
	 * @param  mixed    $new_value Value after change.
	 * @param  bool|int $deep   Get array children changes keys as well, not just parents.
	 *
	 * @return array
	 */
	public function get_changed_keys( $old_value, $new_value, $deep = false ) {
		if ( ! is_array( $old_value ) && ! is_array( $new_value ) ) {
			return array();
		}

		if ( ! is_array( $old_value ) ) {
			return array_keys( $new_value );
		}

		if ( ! is_array( $new_value ) ) {
			return array_keys( $old_value );
		}

		$diff = array_udiff_assoc(
			$old_value,
			$new_value,
			function ( $value1, $value2 ) {
				// Compare potentially complex nested arrays.
				return wp_json_encode( $value1 ) !== wp_json_encode( $value2 );
			}
		);

		$result = array_keys( $diff );

		// find unexisting keys in old or new value.
		$common_keys     = array_keys( array_intersect_key( $old_value, $new_value ) );
		$unique_keys_old = array_values( array_diff( array_keys( $old_value ), $common_keys ) );
		$unique_keys_new = array_values( array_diff( array_keys( $new_value ), $common_keys ) );

		$result = array_merge( $result, $unique_keys_old, $unique_keys_new );

		// remove numeric indexes.
		$result = array_filter(
			$result,
			function ( $value ) {
				// @codingStandardsIgnoreStart
				// check if is not valid number (is_int, is_numeric and ctype_digit are not enough)
				return (string) (int) $value !== (string) $value;
				// @codingStandardsIgnoreEnd
			}
		);

		$result = array_values( array_unique( $result ) );

		if ( false === $deep ) {
			return $result; // Return an numerical based array with changed TOP PARENT keys only.
		}

		$result = array_fill_keys( $result, null );

		foreach ( $result as $key => $val ) {
			if ( in_array( $key, $unique_keys_old, true ) ) {
				$result[ $key ] = false; // Removed.
			} elseif ( in_array( $key, $unique_keys_new, true ) ) {
				$result[ $key ] = true; // Added.
			} elseif ( $deep ) { // Changed, find what changed, only if we're allowed to explore a new level.
				if ( is_array( $old_value[ $key ] ) && is_array( $new_value[ $key ] ) ) {
					$inner  = array();
					$parent = $key;
					--$deep;
					$changed = $this->get_changed_keys( $old_value[ $key ], $new_value[ $key ], $deep );
					foreach ( $changed as $child => $change ) {
						$inner[ $parent . '::' . $child ] = $change;
					}
					$result[ $key ] = 0; // Changed parent which has a changed children.
					$result         = array_merge( $result, $inner );
				}
			}
		}

		return $result;
	}

	/**
	 * Handle sanitize POST data.
	 *
	 * @param array $data input data.
	 *
	 * @return array
	 */
	protected function sanitize_data( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		// Sanitize all record values.
		return array_map(
			function ( $value ) {
				if ( ! is_array( $value ) ) {
					return wp_strip_all_tags( $value );
				}

				return $value;
			},
			$data
		);
	}
}
