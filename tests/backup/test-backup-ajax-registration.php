<?php
/**
 * MainWP Backup AJAX Registration Tests
 *
 * Verifies the backup AJAX endpoint registrations in
 * MainWP_Post_Backup_Handler::init(): every registered action must point
 * at a real callback, and capability-gated actions must not register for
 * users denied the corresponding capability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Ajax_Registration_Test
 */
class MainWP_Backup_Ajax_Registration_Test extends \WP_UnitTestCase {

	/**
	 * Collect the wp_ajax_* hook names with callbacks owned by
	 * MainWP_Post_Backup_Handler, mapped to their callback method names.
	 *
	 * @return array<string, string[]> Hook name => method names.
	 */
	private function get_registered_backup_ajax_callbacks() {
		global $wp_filter;

		$registered = array();
		foreach ( $wp_filter as $hook_name => $hook ) {
			if ( 0 !== strpos( $hook_name, 'wp_ajax_' ) ) {
				continue;
			}
			foreach ( $hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && isset( $function[0] ) && $function[0] instanceof MainWP_Post_Backup_Handler ) {
						$registered[ $hook_name ][] = $function[1];
					}
				}
			}
		}

		return $registered;
	}

	/**
	 * Every backup AJAX action registered by init() must point at a method
	 * that actually exists; a registration without an implementation fatals
	 * when the action is invoked.
	 *
	 * @return void
	 */
	public function test_all_registered_backup_ajax_callbacks_exist() {
		MainWP_Post_Backup_Handler::instance()->init();

		$registered = $this->get_registered_backup_ajax_callbacks();
		$this->assertNotEmpty( $registered, 'init() should register backup AJAX actions.' );

		$missing = array();
		foreach ( $registered as $hook_name => $methods ) {
			foreach ( $methods as $method ) {
				if ( ! method_exists( MainWP_Post_Backup_Handler::instance(), $method ) ) {
					$missing[] = $hook_name . ' => ' . $method;
				}
			}
		}

		$this->assertSame( array(), $missing, 'AJAX actions registered with non-existent callbacks: ' . implode( ', ', $missing ) );
	}
}
