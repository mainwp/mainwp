<?php
/**
 * Tightening a scheduled task's retention requires its deletion right.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Retention_Protection;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

require_once __DIR__ . '/class-mainwp-backup-settings-staff-fixtures.php';

/** Exercise the shared policy guard through browser and ability transports. */
class MainWP_Backup_Task_Edit_Retention_Rights_Test extends MainWP_Abilities_Test_Case {

	use Backup_Settings_Staff_Fixtures;

	/**
	 * Task identity.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Current task site.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Historical task site.
	 *
	 * @var int
	 */
	private $history_site_id;

	/**
	 * Simulated Team Control rights.
	 *
	 * @var callable|null
	 */
	private $rights;

	/**
	 * Saved request globals.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Most recently checked operation right.
	 *
	 * @var string
	 */
	private $operation = '';

	/**
	 * Site checks paired with operation rights.
	 *
	 * @var array
	 */
	private $site_checks = array();

	/** Seed a task without history: future pruning still requires deletion rights. */
	public function setUp(): void {
		parent::setUp();
		$this->skip_when_capabilities_bypassed();
		foreach ( array( '_GET', '_POST', '_REQUEST' ) as $key ) {
			$this->saved[ $key ] = $GLOBALS[ $key ];
		}
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->site_id         = $this->create_test_site();
		$this->history_site_id = $this->create_test_site();
		$task                  = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Retention rights task', 'daily', 'full', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$this->task_id = (int) $task->id;
		$this->store(
			array(
				'authorized_sites'     => (string) $this->site_id,
				'retention_count'      => 0,
				'retention_days'       => 0,
				'retention_storage_mb' => 0,
				'delete_cloud_copies'  => 0,
			)
		);
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
	}

	/** Remove only fixture rows and filters owned by this class. */
	public function tearDown(): void {
		$this->clear_actor();
		if ( $this->task_id ) {
			global $wpdb;
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'task_id' => $this->task_id ) );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $this->task_id ) );
			delete_option( MainWP_Backup_Retention_Protection::TASK_OPTION_PREFIX . $this->task_id );
		}
		$this->clear_settings_staff_fixtures();
		foreach ( $this->saved as $key => $value ) {
			$GLOBALS[ $key ] = $value;
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tearDown();
	}

	/**
	 * Write checked fixture overrides.
	 *
	 * @param array $values Stored fields.
	 */
	private function store( array $values ): void {
		$this->assertNotFalse( MainWP_DB_Backup::instance()->update_backup_task_with_values( $this->task_id, $values ) );
	}

	/**
	 * Complete persisted task row.
	 *
	 * @return array
	 */
	private function row(): array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', MainWP_DB::instance()->get_table_name( 'wp_backup' ), $this->task_id ), ARRAY_A );
	}

	/** Clear the preceding actor before selecting another. */
	private function clear_actor(): void {
		if ( $this->rights ) {
			remove_filter( 'mainwp_currentusercan', $this->rights, 10 );
		}
		remove_filter( 'mainwp_currentuserallowedaccesssites', array( self::class, 'settings_all_scope' ) );
		remove_filter( 'user_has_cap', array( self::class, 'settings_bridge_caps' ) );
		$this->rights = null;
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
	}

	/**
	 * Staff have level_10, no manage_options and explicit task rights.
	 *
	 * @param bool $delete Whether the deletion right is granted.
	 */
	private function staff( bool $delete ): void {
		$this->clear_actor();
		$this->settings_actor( 'S' );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->store( array( 'userid' => get_current_user_id() ) );
		$this->rights = function ( $allowed, $type, $cap ) use ( $delete ) {
			if ( 'dashboard' === $type ) {
				$this->operation = $cap;
				return 'edit_backup_tasks' === $cap || ( $delete && 'delete_backup_tasks' === $cap );
			}
			if ( 'site' === $type ) {
				$this->site_checks[] = array( $this->operation, (int) $cap );
				return in_array( (int) $cap, array( $this->site_id, $this->history_site_id ), true );
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $this->rights, 10, 3 );
		add_filter( 'mainwp_currentuserallowedaccesssites', array( self::class, 'settings_all_scope' ) );
	}

	/**
	 * Seven policy transitions can remove future history even with no snapshots today.
	 *
	 * @return array Stored column, retention key, old value, new value.
	 */
	public static function tightenings(): array {
		return array(
			'count unlimited to limited'   => array( 'retention_count', 'count', 0, 3 ),
			'count lower'                  => array( 'retention_count', 'count', 7, 3 ),
			'days unlimited to limited'    => array( 'retention_days', 'days', 0, 3 ),
			'days lower'                   => array( 'retention_days', 'days', 7, 3 ),
			'storage unlimited to limited' => array( 'retention_storage_mb', 'storage_mb', 0, 100 ),
			'storage lower'                => array( 'retention_storage_mb', 'storage_mb', 200, 100 ),
			'cloud deletion enabled'       => array( 'delete_cloud_copies', 'delete_cloud_copies', 0, true ),
		);
	}

	/**
	 * Independent refusal cases identify every transport on the base.
	 *
	 * @return array
	 */
	public static function refused_tightenings(): array {
		$cases = array();
		foreach ( self::tightenings() as $name => $transition ) {
			foreach ( array( 'ui', 'ability', 'preview', 'ajax' ) as $path ) {
				$cases[ $name . '/' . $path ] = array_merge( $transition, array( $path ) );
			}
		}
		return $cases;
	}

	/**
	 * Exercise the public execution callback and real browser handler.
	 *
	 * @param string $path Transport.
	 * @param array  $fields Partial task fields.
	 * @return mixed
	 */
	private function invoke( string $path, array $fields ) {
		if ( 'ui' === $path ) {
			return MainWP_Backup_Operations::update_task( $this->task_id, $fields, 'ui' );
		}
		if ( 'ajax' === $path ) {
			return $this->ajax( $fields );
		}
		add_filter( 'user_has_cap', array( self::class, 'settings_bridge_caps' ) );
		try {
			if ( 'preview' === $path ) {
				return MainWP_Backup_Operations::preview_task_update( $this->task_id, $fields );
			}
			return MainWP_Abilities_Backups::execute_update_backup_task(
				array_merge(
					$fields,
					array(
						'task_id' => $this->task_id,
						'confirm' => true,
					)
				)
			);
		} finally {
			remove_filter( 'user_has_cap', array( self::class, 'settings_bridge_caps' ) );
		}
	}

	/**
	 * Capture the real AJAX response after its browser and nonce guards.
	 *
	 * @param array $fields Partial service fields translated to the task form.
	 * @return array
	 */
	private function ajax( array $fields ): array {
		$row      = $this->row();
		$_GET     = array();
		$_POST    = array(
			'id'                   => $this->task_id,
			'name'                 => $fields['name'] ?? $row['name'],
			'type'                 => 'full',
			'schedule'             => 'daily',
			'sites'                => array( $this->site_id ),
			'archiveFormat'        => 'zip',
			'retention_count'      => $fields['retention']['count'] ?? $row['retention_count'],
			'retention_days'       => $fields['retention']['days'] ?? $row['retention_days'],
			'retention_storage_mb' => $fields['retention']['storage_mb'] ?? $row['retention_storage_mb'],
			'delete_cloud_copies'  => $fields['retention']['delete_cloud_copies'] ?? $row['delete_cloud_copies'],
			'security'             => wp_create_nonce( 'mainwp_updatebackup' ),
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'json' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			( new MainWP_Post_Backup_Handler() )->mainwp_updatebackup();
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'json', $error->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_die_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		$result = json_decode( $raw, true );
		$this->assertIsArray( $result, 'The AJAX handler must return a JSON response.' );
		return $result;
	}

	/**
	 * An edit-only actor must receive the missing right before any row changes.
	 *
	 * @dataProvider refused_tightenings
	 * @param string $column Stored column.
	 * @param string $key Retention input key.
	 * @param mixed  $old Stored value.
	 * @param mixed  $submitted Submitted value.
	 * @param string $path Transport.
	 */
	public function test_edit_only_tightening_is_refused( string $column, string $key, $old, $submitted, string $path ): void {
		$this->store( array( $column => $old ) );
		$this->staff( false );
		$before = $this->row();
		$result = $this->invoke(
			$path,
			array(
				'name'      => 'Must not save',
				'retention' => array( $key => $submitted ),
			)
		);
		$this->assertSame( $before, $this->row(), 'Refusing a policy change must preserve the complete task row.' );
		if ( 'ajax' === $path ) {
			$this->assertArrayHasKey( 'error', $result );
			$this->assertStringContainsString( 'Delete Backup Tasks', $result['error'] );
			return;
		}
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_insufficient_right', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'delete_backup_tasks', $result->get_error_data()['right'] );
		if ( 'ability' === $path ) {
			$this->assertStringContainsString( 'Delete Backup Tasks', $result->get_error_message() );
		}
	}

	/**
	 * Granting both rights permits the same policies through every transport.
	 *
	 * @dataProvider tightenings
	 * @param string $column Stored column.
	 * @param string $key Retention input key.
	 * @param mixed  $old Stored value.
	 * @param mixed  $submitted Submitted value.
	 */
	public function test_both_rights_allow_tightening( string $column, string $key, $old, $submitted ): void {
		$this->staff( true );
		foreach ( array( 'preview', 'ui', 'ability', 'ajax' ) as $path ) {
			$this->store( array( $column => $old ) );
			$before = $this->row();
			$result = $this->invoke( $path, array( 'retention' => array( $key => $submitted ) ) );
			$this->assertNotWPError( $result );
			if ( 'preview' === $path ) {
				$this->assertTrue( $result['can_proceed'] );
				$this->assertSame( $before, $this->row() );
			} else {
				if ( 'ajax' === $path ) {
					$this->assertSame( array( 'result' => 'Task updated successfully.' ), $result );
				}
				$this->assertSame( (int) $submitted, (int) $this->row()[ $column ], $path );
			}
		}
	}

	/**
	 * Changes that require only the edit right.
	 *
	 * @return array
	 */
	public static function ordinary_edits(): array {
		$cases = array();
		foreach ( array(
			'count'      => 'retention_count',
			'days'       => 'retention_days',
			'storage_mb' => 'retention_storage_mb',
		) as $key => $column ) {
			foreach ( array(
				'higher'    => 20,
				'unlimited' => 0,
				'unchanged' => 10,
			) as $name => $value ) {
				$cases[ $key . '/' . $name ] = array( array( $column => 10 ), array( 'retention' => array( $key => $value ) ), $column, $value );
			}
		}
		$cases['cloud disabled']  = array( array( 'delete_cloud_copies' => 1 ), array( 'retention' => array( 'delete_cloud_copies' => false ) ), 'delete_cloud_copies', 0 );
		$cases['cloud unchanged'] = array( array( 'delete_cloud_copies' => 1 ), array( 'retention' => array( 'delete_cloud_copies' => true ) ), 'delete_cloud_copies', 1 );
		$cases['name only']       = array(
			array(
				'retention_count'      => 10,
				'retention_days'       => 10,
				'retention_storage_mb' => 10,
				'delete_cloud_copies'  => 1,
			),
			array( 'name' => 'Ordinary renamed task' ),
			'name',
			'Ordinary renamed task',
		);
		return $cases;
	}

	/**
	 * Partial edits preserve omitted policy values without demanding deletion rights.
	 *
	 * @dataProvider ordinary_edits
	 * @param array  $stored Stored overrides.
	 * @param array  $fields Input fields.
	 * @param string $column Changed column.
	 * @param mixed  $expected Expected value.
	 */
	public function test_edit_only_ordinary_changes_succeed( array $stored, array $fields, string $column, $expected ): void {
		$this->staff( false );
		foreach ( array( 'preview', 'ui', 'ability', 'ajax' ) as $path ) {
			$this->store( $stored );
			$before = $this->row();
			$result = $this->invoke( $path, $fields );
			$this->assertNotWPError( $result );
			$after = $this->row();
			if ( 'preview' === $path ) {
				$this->assertTrue( $result['can_proceed'] );
				$this->assertSame( $before, $after );
				continue;
			}
			if ( 'ajax' === $path ) {
				$this->assertSame( array( 'result' => 'Task updated successfully.' ), $result );
			}
			$this->assertSame( (string) $expected, (string) $after[ $column ], $path );
			foreach ( array( 'retention_count', 'retention_days', 'retention_storage_mb', 'delete_cloud_copies' ) as $policy_column ) {
				if ( $column !== $policy_column ) {
					$this->assertSame( $before[ $policy_column ], $after[ $policy_column ], 'Omitted retention fields must survive partial edits.' );
				}
			}
		}
	}

	/** Admins without any Team Control filters retain the ordinary save path. */
	public function test_administrator_without_team_control_can_tighten(): void {
		foreach ( array( 'preview', 'ui', 'ability', 'ajax' ) as $path ) {
			$this->store( array( 'retention_count' => 0 ) );
			$result = $this->invoke( $path, array( 'retention' => array( 'count' => 1 ) ) );
			$this->assertNotWPError( $result );
			if ( 'preview' !== $path ) {
				$this->assertSame( 1, (int) $this->row()['retention_count'] );
			}
			if ( 'ajax' === $path ) {
				$this->assertSame( array( 'result' => 'Task updated successfully.' ), $result );
			}
		}
	}

	/** The deletion right must be checked against current and historical sites. */
	public function test_tightening_checks_delete_right_for_current_and_history_sites(): void {
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'site_id'  => $this->history_site_id,
					'task_id'  => $this->task_id,
					'type'     => 'full',
					'created'  => time(),
					'archives' => '[]',
				)
			)
		);
		$this->staff( true );
		foreach ( array( 'preview', 'ui', 'ability' ) as $path ) {
			$this->store( array( 'retention_days' => 0 ) );
			$this->site_checks = array();
			$this->assertNotWPError( $this->invoke( $path, array( 'retention' => array( 'days' => 1 ) ) ) );
			$this->assertContains( array( 'delete_backup_tasks', $this->site_id ), $this->site_checks, $path );
			$this->assertContains( array( 'delete_backup_tasks', $this->history_site_id ), $this->site_checks, $path );
		}
	}
}
