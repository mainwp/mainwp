<?php
/**
 * Storage adapters must use the existing saved-destination flows.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_DB_Backup;

/** Saved storage behavior and safe provider failures. */
class MainWP_Backup_Operations_Storage_Test extends MainWP_Abilities_Test_Case {
	/**
	 * Local destination fixture.
	 *
	 * @var string
	 */
	private $dir;
	/**
	 * Destination identity.
	 *
	 * @var int
	 */
	private $id;

	/** Stored local settings isolate adapters from remote credentials. */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->dir = sys_get_temp_dir() . '/mainwp-storage-adapter-' . uniqid();
		mkdir( $this->dir );
		$this->id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Stored adapter fixture',
				'settings' => array( 'base_path' => $this->dir ),
			)
		);
	}

	/** The public destination model supplies an absent minimum as zero. */
	public function test_remote_minimum_defaults_to_zero(): void {
		$detail = \MainWP\Dashboard\MainWP_Backup_Read_Model::get_destination( $this->id );
		$this->assertArrayHasKey( 'minimum_remote_retention_days', $detail );
		$this->assertSame( 0, $detail['minimum_remote_retention_days'] );
	}

	/** Form-style string values are validated and retained across partial settings edits. */
	public function test_storage_minimum_validation_and_partial_preservation(): void {
		$handler = \MainWP\Dashboard\MainWP_Backup_Storage_Handler::class;
		$input   = array(
			'id'       => $this->id,
			'provider' => 'local',
			'name'     => 'Minimum fixture',
			'settings' => array(
				'base_path'                     => $this->dir,
				'minimum_remote_retention_days' => '90',
			),
		);
		$this->assertArrayHasKey( 'id', $handler::save_destination( $input ) );
		$this->assertSame( 90, MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->settings['minimum_remote_retention_days'] );
		unset( $input['settings']['minimum_remote_retention_days'] );
		$this->assertArrayHasKey( 'id', $handler::save_destination( $input ) );
		$this->assertSame( 90, MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->settings['minimum_remote_retention_days'] );
		foreach ( array( '-1', '36501', '1.5', 'abc', array( 1 ) ) as $value ) {
			$input['settings']['minimum_remote_retention_days'] = $value;
			$this->assertArrayHasKey( 'error', $handler::save_destination( $input ) );
			$this->assertSame( 90, MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->settings['minimum_remote_retention_days'] );
		}
		$input['settings']['minimum_remote_retention_days'] = '0';
		$this->assertArrayHasKey( 'id', $handler::save_destination( $input ) );
		$this->assertSame( 0, MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->settings['minimum_remote_retention_days'] );
	}

	/** Connection-test scratch must not survive the fixture. */
	public function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	/** The saved provider and name must accompany settings:null. */
	public function test_saved_test_rename_default_and_delete_reuse_existing_flows(): void {
		$this->assertTrue( MainWP_Backup_Operations::test_destination( $this->id )['ok'] );
		$this->assertFalse( is_wp_error( MainWP_Backup_Operations::update_destination( $this->id, array( 'name' => 'Renamed adapter' ) ) ) );
		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id );
		$this->assertSame( 'Renamed adapter', $row->name );
		$this->assertSame( array( 'base_path' => $this->dir ), $row->settings );
		$this->assertTrue( MainWP_Backup_Operations::set_default_destination( $this->id )['ok'] );
		$this->assertSame( 1, (int) MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->is_default );
		$task = MainWP_Backup_Operations::create_task(
			array(
				'name'     => 'Adapter task',
				'type'     => 'full',
				'schedule' => 'daily',
				'site_ids' => array( $this->create_test_site() ),
			),
			'ability'
		);
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task['task_id'], array( 'destinations' => wp_json_encode( array( $this->id ) ) ) );
		$this->assertTrue( MainWP_Backup_Operations::delete_destination( $this->id )['deleted'] );
		$this->assertNull( MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id ) );
		$this->assertSame( array(), json_decode( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task['task_id'] )->destinations, true ) );
	}

	/** Provider errors and filesystem locations must not reach machine callers. */
	public function test_safe_failures_and_authorization_precede_storage_actions(): void {
		MainWP_Backup_Storage_Destinations::instance()->update_destination( $this->id, array( 'settings' => array( 'base_path' => '/private/missing-adapter-path' ) ) );
		$result = MainWP_Backup_Operations::test_destination( $this->id );
		$this->assertWPError( $result );
		$this->assertStringNotContainsString( '/private/', $result->get_error_message() );
		$this->assertSame( 'mainwp_invalid_input', MainWP_Backup_Operations::update_destination( $this->id, array( 'settings' => array() ) )->get_error_code() );
		add_filter( 'mainwp_currentusercan', '__return_false' );
		try {
			foreach ( array( 'test_destination', 'set_default_destination', 'delete_destination' ) as $method ) {
				$this->assertSame( 'mainwp_insufficient_right', MainWP_Backup_Operations::$method( $this->id )->get_error_code() );
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', '__return_false' );
		}
		$this->assertNotNull( MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id ) );
	}
}
