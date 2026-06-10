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

	/**
	 * The capability each backup AJAX action must be gated by.
	 *
	 * @return array<string, string> Action name => dashboard capability.
	 */
	private function get_capability_gated_actions() {
		return array(
			'mainwp_addbackup'                 => 'add_backup_tasks',
			'mainwp_updatebackup'              => 'edit_backup_tasks',
			'mainwp_removebackup'              => 'delete_backup_tasks',
			'mainwp_pausebackup'               => 'pause_resume_backup_tasks',
			'mainwp_resumebackup'              => 'pause_resume_backup_tasks',
			'mainwp_backuptask_get_sites'      => 'run_backup_tasks',
			'mainwp_backuptask_run_site'       => 'run_backup_tasks',
			'mainwp_backup_task_progress'      => 'run_backup_tasks',
			'mainwp_backup_run_site'           => 'run_backup_tasks',
			'mainwp_backup'                    => 'execute_backups',
			'mainwp_backup_checkpid'           => 'execute_backups',
			'mainwp_createbackup_getfilesize'  => 'execute_backups',
			'mainwp_backup_getfilesize'        => 'execute_backups',
			'mainwp_backup_download_file'      => 'execute_backups',
			'mainwp_backup_delete_file'        => 'execute_backups',
			'mainwp_backup_upload_getprogress' => 'execute_backups',
			'mainwp_backup_upload_checkstatus' => 'execute_backups',
		);
	}

	/**
	 * Run init() with one capability denied via the Team Control filter and
	 * report whether the action got registered.
	 *
	 * @param string $action     AJAX action name.
	 * @param string $denied_cap Dashboard capability to deny; empty string denies none.
	 *
	 * @return bool Whether wp_ajax_{action} has a callback after init().
	 */
	private function action_registered_with_denied_cap( $action, $denied_cap ) {
		$deny = function ( $allowed, $cap_type, $cap ) use ( $denied_cap ) {
			if ( 'dashboard' === $cap_type && $cap === $denied_cap ) {
				return false;
			}
			return $allowed;
		};

		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		remove_all_actions( 'wp_ajax_' . $action );
		MainWP_Post_Backup_Handler::instance()->init();
		remove_filter( 'mainwp_currentusercan', $deny, 10 );

		$registered = false !== has_action( 'wp_ajax_' . $action );

		remove_all_actions( 'wp_ajax_' . $action );

		return $registered;
	}

	/**
	 * A user denied a backup capability must not get the matching AJAX
	 * action registered at all.
	 *
	 * @return void
	 */
	public function test_denied_capability_blocks_action_registration() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) ) {
			$this->markTestSkipped( 'Capability checks are bypassed by design under cron, WP-CLI, and REST constants; another suite defined one for this process.' );
		}

		$leaks = array();
		foreach ( $this->get_capability_gated_actions() as $action => $cap ) {
			if ( $this->action_registered_with_denied_cap( $action, $cap ) ) {
				$leaks[] = $action . ' (denied ' . $cap . ')';
			}
		}

		$this->assertSame( array(), $leaks, 'Actions registered despite the capability being denied: ' . implode( ', ', $leaks ) );
	}

	/**
	 * With no capability denied, every gated action registers normally.
	 *
	 * @return void
	 */
	public function test_allowed_capability_registers_actions() {
		$missing = array();
		foreach ( array_keys( $this->get_capability_gated_actions() ) as $action ) {
			if ( ! $this->action_registered_with_denied_cap( $action, '' ) ) {
				$missing[] = $action;
			}
		}

		$this->assertSame( array(), $missing, 'Actions not registered although the capability is allowed: ' . implode( ', ', $missing ) );
	}

	/**
	 * The backup-before-update check stays available regardless of backup
	 * capabilities: it is read-only and consumed by the updates flow, where
	 * a Team Control user without backup rights still needs the check.
	 *
	 * @return void
	 */
	public function test_checkbackups_stays_registered_without_backup_capabilities() {
		$deny_all_backup_caps = function ( $allowed, $cap_type, $cap ) {
			if ( 'dashboard' === $cap_type && in_array( $cap, $this->get_capability_gated_actions(), true ) ) {
				return false;
			}
			return $allowed;
		};

		add_filter( 'mainwp_currentusercan', $deny_all_backup_caps, 10, 3 );
		remove_all_actions( 'wp_ajax_mainwp_checkbackups' );
		MainWP_Post_Backup_Handler::instance()->init();
		remove_filter( 'mainwp_currentusercan', $deny_all_backup_caps, 10 );

		$this->assertNotFalse( has_action( 'wp_ajax_mainwp_checkbackups' ), 'mainwp_checkbackups must stay available to the updates flow.' );
	}
}
