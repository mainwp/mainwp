<?php
/**
 * Assistant confirmation and backup-gate input checks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use MainWP\Dashboard\MainWP_Assistant_Policies;

/** Exercise model tool calls through the execution entry point. */
class Test_Assistant_Backup_Controls extends MainWP_Assistant_Test_Case {

	/** Disabling backups preserves the native stop and configuration actions. */
	public function test_disabled_backups_keep_native_recovery_tools() {
		update_option( 'mainwp_enableNativeBackups', 0 );
		$method = new \ReflectionMethod( MainWP_Assistant::class, 'is_ability_module_active' );
		$method->setAccessible( true );
		$write = array( 'category' => 'mainwp-backups', 'annotations' => array( 'readonly' => false ) );
		foreach ( array( 'cancel-site-backup', 'reset-backup-task-run', 'pause-backup-task', 'cancel-restore', 'update-backup-settings' ) as $name ) {
			$this->assertTrue( $method->invoke( MainWP_Assistant::instance(), 'mainwp/' . $name . '-v1', $write ), $name );
		}
		$this->assertFalse( $method->invoke( MainWP_Assistant::instance(), 'mainwp/run-site-backup-v1', $write ) );
	}

	/** Existing Read & Write actions retain their preview-and-confirm flow. */
	public function test_read_write_keeps_existing_confirmed_actions() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_READ_WRITE_CONFIRM );
		$policies = MainWP_Assistant_Policies::instance();
		foreach ( array( 'run-updates', 'update-all', 'update-site-core', 'update-site-plugins', 'update-site-themes', 'update-site-translations', 'activate-site-plugins', 'deactivate-site-plugins', 'activate-site-theme' ) as $name ) {
			$this->assertTrue( $policies->is_ability_allowed( 'mainwp/' . $name . '-v1', $user_id ), $name );
			$this->assertTrue( $policies->requires_confirmation( 'mainwp/' . $name . '-v1' ), $name );
		}
		$this->assertFalse( $policies->is_ability_allowed( 'mainwp/delete-backup-task-v1', $user_id ) );
	}

	/** The declaration cap does not revoke permission to use an advertised category. */
	public function test_authorized_catalog_is_not_truncated_by_declaration_cap() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$method = new \ReflectionMethod( MainWP_Assistant::class, 'build_filtered_tools' );
		$method->setAccessible( true );
		$assistant = MainWP_Assistant::instance();
		$advertised = $method->invoke( $assistant, $user_id, array( 'mainwp-sites', 'mainwp-monitoring' ) );
		$this->assertArrayHasKey( 'mainwp/list-monitor-incidents-v1', $advertised );
		$authorized = $method->invoke( $assistant, $user_id, null, false );
		$this->assertArrayHasKey( 'mainwp/list-monitor-incidents-v1', $authorized );
		$this->assertGreaterThan( 96, count( $authorized ) );
		$this->assertLessThanOrEqual( 96, count( $method->invoke( $assistant, $user_id ) ) );
	}

	/** Indirect backup targets must reach the protected-tag policy. */
	public function test_indirect_backup_targets_require_protected_confirmation() {
		$user_id = $this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$tag_id = $this->create_test_tag( array( 'name' => 'Protected backup target' ) );
		$this->assign_site_to_tag( $site_id, $tag_id );
		$this->set_assistant_enabled( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		$this->set_user_protected_tags( $user_id, array( $tag_id ) );
		$task = \MainWP\Dashboard\MainWP_DB_Backup::instance()->add_backup_task( $user_id, 'Protected backup', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 155, 1 );
		$this->assertIsObject( $task );
		$job_id = \MainWP\Dashboard\MainWP_Backup_Restore_Job_Store::instance()->create_job( $site_id, 1, array() );
		$this->assertGreaterThan( 0, $job_id );
		$method = new \ReflectionMethod( MainWP_Assistant::class, 'check_ability_policy' );
		$method->setAccessible( true );
		foreach ( array( array( 'task_id' => (int) $task->id ), array( 'restore_job_id' => $job_id ), array( 'group_ids' => array( $tag_id ) ) ) as $params ) {
			$result = $method->invoke( MainWP_Assistant::instance(), 'mainwp/pause-backup-task-v1', $params, $user_id );
			$this->assertTrue( $result['extra_confirm'] ?? false, wp_json_encode( $params ) );
			$this->assertContains( $site_id, $result['protected_sites'] );
		}
	}

	/** A model cannot approve an update-gate override. */
	public function test_model_cannot_supply_backup_gate_override() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		MainWP_Assistant_Policies::instance()->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_ALL, $user_id );
		$method = new \ReflectionMethod( MainWP_Assistant::class, 'execute_tool_call' );
		$method->setAccessible( true );
		$result = $method->invoke( MainWP_Assistant::instance(), 'mainwp/add-tag-v1', array( 'name' => 'Gate input', 'ignore_backup_gate' => true ), $user_id );
		$this->assertTrue( $result['requires_confirmation'] ?? false );
		$this->assertArrayNotHasKey( 'ignore_backup_gate', $result['params'] );
	}

	/** Native confirmation requirements survive the user's destructive-only preference. */
	public function test_schema_confirmation_requirement_is_preserved() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		MainWP_Assistant_Policies::instance()->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE, $user_id );
		$method = new \ReflectionMethod( MainWP_Assistant::class, 'execute_tool_call' );
		$method->setAccessible( true );
		$result = $method->invoke(
			MainWP_Assistant::instance(),
			'mainwp/pause-backup-task-v1',
			array(
				'task_id' => 1,
				'confirm' => true,
			),
			$user_id
		);
		$this->assertTrue( $result['requires_confirmation'] ?? false );
		$this->assertArrayNotHasKey( 'confirm', $result['params'] );
	}
}
