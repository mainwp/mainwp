<?php
/**
 * Restore AJAX registration tests: actions exist with real callbacks
 * and are capability-gated.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Restore_Ajax_Test
 */
class MainWP_Backup_Restore_Ajax_Test extends \WP_UnitTestCase {

	/**
	 * Restore AJAX actions.
	 *
	 * @var string[]
	 */
	private $actions = array(
		'mainwp_restore_snapshot_list',
		'mainwp_restore_initiate',
		'mainwp_restore_progress',
		'mainwp_restore_cancel',
		'mainwp_restore_rollback',
	);

	/**
	 * Whether a hook has a handler-owned callback.
	 *
	 * @param string $hook_name Hook.
	 *
	 * @return bool True when registered.
	 */
	private function handler_registered( $hook_name ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && isset( $function[0] ) && $function[0] instanceof MainWP_Post_Backup_Handler ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * An administrator gets every restore action registered, pointing
	 * at real methods.
	 */
	public function test_restore_actions_registered_for_admin() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_Post_Backup_Handler::instance()->init();

		foreach ( $this->actions as $action ) {
			$this->assertTrue( $this->handler_registered( 'wp_ajax_' . $action ), $action );
			$this->assertTrue( method_exists( MainWP_Post_Backup_Handler::instance(), $action ), $action );
		}
	}

	/**
	 * A user denied the execute_backups capability gets none of them.
	 */
	public function test_restore_actions_denied_without_capability() {
		global $wp_filter;
		foreach ( $this->actions as $action ) {
			unset( $wp_filter[ 'wp_ajax_' . $action ] );
		}

		$deny = function ( $allowed, $cap_type, $cap ) {
			if ( 'dashboard' === $cap_type && 'execute_backups' === $cap ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		MainWP_Post_Backup_Handler::instance()->init();
		remove_filter( 'mainwp_currentusercan', $deny, 10 );

		foreach ( $this->actions as $action ) {
			$this->assertFalse( $this->handler_registered( 'wp_ajax_' . $action ), $action );
		}
	}
}
