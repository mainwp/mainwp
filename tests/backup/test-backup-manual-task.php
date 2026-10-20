<?php
/**
 * MainWP On-demand ("manual") Backup Task Tests
 *
 * The per-site hidden manual task is how an on-demand "Back up now" lands
 * in the restore catalog: a real wp_backup row (template = 1, schedule =
 * 'manual') reused on every click, so recording, retention, and the cron
 * backstop flow through the existing scheduled-backup machinery. These
 * tests cover the get-or-create, its hidden + standalone shape, the
 * configurable retention, and the destinations inherited from the site's
 * scheduled tasks or the Default for sites outside those tasks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Backup_Settings;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Handler;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;

/**
 * Class MainWP_Backup_Manual_Task_Test
 */
class MainWP_Backup_Manual_Task_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Fake site id base; no real website row is required because
	 * ensure_manual_backup_task() takes the website object directly.
	 *
	 * @var int
	 */
	private $site_id = 990010;

	/**
	 * Remove fixture task rows and the retention option between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->query( "DELETE FROM {$table} WHERE name = 'On-demand backup' OR name LIKE 'Manual task test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		delete_option( 'mainwp_on_demand_retention_count' );
		delete_option( 'mainwp_enableNativeBackups' );

		parent::tear_down();
	}

	/**
	 * Build a fake website object accepted by ensure_manual_backup_task().
	 *
	 * @param int $userid Owner user id.
	 *
	 * @return object Website stand-in.
	 */
	private function website( $userid = 1 ) {
		return (object) array(
			'id'     => $this->site_id,
			'userid' => $userid,
		);
	}

	/**
	 * Insert a scheduled (template = 0) task targeting some sites.
	 *
	 * @param string $sites        Comma-separated site ids.
	 * @param array  $destinations Destination ids.
	 *
	 * @return int Task id.
	 */
	private function insert_scheduled_task( $sites, array $destinations ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'userid'          => 1,
				'name'            => 'Manual task test ' . uniqid(),
				'schedule'        => 'daily',
				'type'            => 'full',
				'exclude'         => '',
				'sites'           => $sites,
				'groups'          => '',
				'subfolder'       => '',
				'filename'        => '',
				'completed_sites' => '',
				'backup_errors'   => '',
				'archiveFormat'   => 'site',
				'template'        => 0,
				'destinations'    => wp_json_encode( $destinations ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * No manual task exists for a site until it is ensured.
	 *
	 * @return void
	 */
	public function test_get_manual_backup_task_null_when_absent() {
		$this->assertNull( MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id ) );
	}

	/**
	 * ensure_manual_backup_task() creates a hidden, standalone manual task.
	 *
	 * @return void
	 */
	public function test_ensure_creates_hidden_manual_task() {
		$initiator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $initiator );
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $this->website( 7 ) );

		$this->assertIsObject( $task );
		$this->assertSame( 1, (int) $task->template, 'Manual task must be hidden (template = 1).' );
		$this->assertSame( 'manual', $task->schedule );
		$this->assertSame( 'full', $task->type, 'Manual snapshots must be standalone full backups, never incremental.' );
		$this->assertSame( (string) $this->site_id, (string) $task->sites );
		$this->assertSame( $initiator, (int) $task->userid, 'Manual task must run as the authorized initiator.' );
		$this->assertSame( 'On-demand backup', $task->name );
		$this->assertSame( 0, (int) $task->next_run, 'next_run must stay 0 so the schedulers never auto-run it.' );
	}

	/**
	 * The hidden manual task is excluded from the management list.
	 *
	 * @return void
	 */
	public function test_manual_task_excluded_from_management_list() {
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $this->website() );

		$listed = MainWP_DB_Backup::instance()->get_backup_tasks( null, 'name' );
		$ids    = array_map(
			function ( $t ) {
				return (int) $t->id;
			},
			(array) $listed
		);

		$this->assertNotContains( (int) $task->id, $ids, 'template = 1 keeps the manual task off the schedule list.' );
	}

	/**
	 * Calling ensure twice reuses the same row instead of duplicating it.
	 *
	 * @return void
	 */
	public function test_ensure_is_idempotent() {
		$first  = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $this->website() );
		$second = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $this->website() );

		$this->assertSame( (int) $first->id, (int) $second->id );

		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE template = 1 AND schedule = 'manual' AND sites = %s", (string) $this->site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test assertion, identifier cannot be prepared.

		$this->assertSame( 1, $count, 'Only one manual task per site may exist.' );
	}

	/**
	 * Legacy hidden tasks need the caller's authorized scope before they can run.
	 */
	public function test_b320_existing_manual_task_refreshes_authorized_sites() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$db   = MainWP_DB_Backup::instance();
		$task = $db->ensure_manual_backup_task( $this->website() );
		$this->assertSame(
			1,
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup' ),
				array( 'authorized_sites' => null ),
				array( 'id' => (int) $task->id )
			)
		);
		$this->assertNull( $db->get_manual_backup_task( $this->site_id )->authorized_sites );

		$refreshed = $db->ensure_manual_backup_task( $this->website() );
		$this->assertSame( (int) $task->id, (int) $refreshed->id );
		$this->assertSame( (string) $this->site_id, $refreshed->authorized_sites );
	}

	/**
	 * The configurable on-demand retention count lands on the task.
	 *
	 * @return void
	 */
	public function test_ensure_applies_retention_setting() {
		update_option( 'mainwp_on_demand_retention_count', 3 );

		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $this->website() );

		$this->assertSame( 3, (int) $task->retention_count );
		$this->assertSame( 3, MainWP_Backup_Settings::get_on_demand_retention_count() );
	}

	/**
	 * Retention defaults to 5 when nothing is configured.
	 *
	 * @return void
	 */
	public function test_retention_default_is_five() {
		$this->assertSame( 5, MainWP_Backup_Settings::get_on_demand_retention_count() );

		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $this->website() );
		$this->assertSame( 5, (int) $task->retention_count );
	}

	/**
	 * Destinations are the deduped union of the site's scheduled tasks,
	 * including a site reached through a multi-site task list.
	 *
	 * @return void
	 */
	public function test_destinations_union_across_scheduled_tasks() {
		$this->insert_scheduled_task( (string) $this->site_id, array( 3, 7 ) );
		$this->insert_scheduled_task( $this->site_id . ',990011', array( 7, 9 ) );

		$dest = MainWP_DB_Backup::instance()->manual_backup_task_destinations( $this->site_id );

		sort( $dest );
		$this->assertSame( array( 3, 7, 9 ), $dest );

		$task    = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $this->website() );
		$on_task = json_decode( (string) $task->destinations, true );
		sort( $on_task );
		$this->assertSame( array( 3, 7, 9 ), $on_task );
	}

	/**
	 * With neither a scheduled task nor a Default, the backup stays local.
	 *
	 * @return void
	 */
	public function test_destinations_empty_when_no_scheduled_task() {
		$this->assertSame( array(), MainWP_DB_Backup::instance()->manual_backup_task_destinations( $this->site_id ) );
	}

	/**
	 * Coverage must not depend on destinations, pause state or hidden rows.
	 *
	 * @return array Cases.
	 */
	public static function destination_plans() {
		return array(
			'default'             => array( 'default', 'default' ),
			'none'                => array( 'none', 'none' ),
			'task union'          => array( 'union', 'tasks' ),
			'empty covering task' => array( 'empty', 'tasks' ),
			'group only'          => array( 'group', 'tasks' ),
			'staging group only'  => array( 'staging_group', 'tasks' ),
			'staging group union' => array( 'staging_group_dest', 'tasks' ),
			'paused only'         => array( 'paused', 'tasks' ),
			'hidden only'         => array( 'hidden', 'default' ),
			'invalid credentials' => array( 'invalid', 'default' ),
		);
	}

	/**
	 * The persisted plan must drive the existing snapshot upload listener.
	 *
	 * @dataProvider destination_plans
	 * @param string $variant Fixture variant.
	 * @param string $source Expected origin.
	 */
	public function test_plan_drives_hidden_task_and_uploads( $variant, $source ) {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store   = MainWP_Backup_Storage_Destinations::instance();
		$db      = MainWP_DB_Backup::instance();
		$default = $this->destination( true );
		$other   = $this->destination( false );
		$ids     = array( $default );
		if ( 'none' === $variant ) {
			$this->assertTrue( $store->update_destination( $default, array( 'is_default' => 0 ) ) );
			$ids = array();
		} elseif ( 'union' === $variant ) {
			$extra = $this->destination( false );
			$this->insert_scheduled_task( (string) $this->site_id, array( $extra, $other ) );
			$this->insert_scheduled_task( (string) $this->site_id, array( $other, $extra ) );
			$ids = array( $extra, $other );
		} elseif ( in_array( $variant, array( 'empty', 'group', 'paused', 'staging_group', 'staging_group_dest' ), true ) ) {
			$task_id = $this->insert_scheduled_task( (string) $this->site_id, 'staging_group_dest' === $variant ? array( $other ) : array() );
			$ids     = 'staging_group_dest' === $variant ? array( $other ) : array();
			if ( 'paused' === $variant ) {
				$db->update_backup_task_with_values( $task_id, array( 'paused' => 1 ) );
			} elseif ( 'empty' !== $variant ) {
				// The scheduled run includes staging members of its groups, so they count as covered too.
				$this->site_id = $this->create_test_site( 'group' === $variant ? array() : array( 'is_staging' => 1 ) );
				$this->assertNotFalse(
					$wpdb->insert(
						$wpdb->prefix . 'mainwp_wp_group',
						array(
							'wpid'    => $this->site_id,
							'groupid' => 990019,
						)
					)
				);
				$db->update_backup_task_with_values(
					$task_id,
					array(
						'sites'  => '',
						'groups' => '990019',
					)
				);
			}
		} elseif ( 'hidden' === $variant ) {
			$task = $db->ensure_manual_backup_task( $this->website() );
			$db->update_backup_task_with_values( (int) $task->id, array( 'destinations' => wp_json_encode( array( $other ) ) ) );
		} elseif ( 'invalid' === $variant ) {
			$this->assertNotFalse( $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ), array( 'settings' => 'invalid-envelope' ), array( 'id' => $default ) ) );
			$this->assertTrue( $store->get_destination( $default )->credentials_invalid );
		}

		$this->assertSame(
			array(
				'ids'    => $ids,
				'source' => $source,
			),
			$db->manual_backup_destination_plan( $this->site_id )
		);
		$this->assertSame( $ids, $db->manual_backup_task_destinations( $this->site_id ) );
		$task = $db->ensure_manual_backup_task( $this->website() );
		$this->assertSame( $ids, json_decode( $task->destinations, true ) );
		$this->assertSame( $ids, $this->snapshot_upload_destinations( $task ) );
	}

	/**
	 * Public storage writes keep the fixture on the production credential path.
	 *
	 * @param bool $is_default Whether to select it.
	 * @return int Destination identity.
	 */
	private function destination( $is_default ) {
		$id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider'   => 's3',
				'name'       => 'Manual task destination',
				'is_default' => $is_default,
			)
		);
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	/**
	 * Snapshot recording must read the hidden row rather than resolve again.
	 *
	 * @param object $task Hidden task.
	 * @return int[] Enqueued destinations.
	 */
	private function snapshot_upload_destinations( $task ) {
		$id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'site_id'  => $this->site_id,
				'task_id'  => (int) $task->id,
				'type'     => 'full',
				'archives' => array(),
			)
		);
		$this->assertGreaterThan( 0, $id );
		MainWP_Backup_Upload_Driver::instance()->on_snapshot_recorded( $id, (int) $task->id, $this->site_id );
		return array_map(
			static function ( $job ) {
				return (int) $job->destination_id;
			},
			MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id )
		);
	}

	/**
	 * A rendered destination is informational; each click must read current state.
	 *
	 * @return array Changes.
	 */
	public static function default_changes() {
		return array(
			'cleared' => array( 'clear' ),
			'changed' => array( 'change' ),
			'deleted' => array( 'delete' ),
		);
	}

	/**
	 * Reusing a hidden task must discard the previous Default after a change.
	 *
	 * @dataProvider default_changes
	 * @param string $change Storage mutation.
	 */
	public function test_next_start_uses_current_default( $change ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$db    = MainWP_DB_Backup::instance();
		$store = MainWP_Backup_Storage_Destinations::instance();
		$a     = $this->destination( true );
		$task  = $db->ensure_manual_backup_task( $this->website() );
		$this->assertSame( array( $a ), json_decode( $task->destinations, true ) );
		ob_start();
		update_option( 'mainwp_enableNativeBackups', 1 );
		\MainWP\Dashboard\MainWP_Backup_Snapshots_View::render_restore_surface( $this->site_id, true );
		$rendered = ob_get_clean();
		$this->assertStringContainsString( 'data-local-only="0"', $rendered );
		$ids = array();
		if ( 'change' === $change ) {
			$ids = array( $this->destination( true ) );
		} elseif ( 'clear' === $change ) {
			$this->assertTrue( $store->update_destination( $a, array( 'is_default' => 0 ) ) );
		} else {
			$this->assertSame( array( 'deleted' => true ), MainWP_Backup_Storage_Handler::delete_destination_flow( $a, true ) );
		}
		$next = $db->ensure_manual_backup_task( $this->website() );
		$this->assertSame( (int) $task->id, (int) $next->id );
		$this->assertSame( $ids, json_decode( $next->destinations, true ) );
		$this->assertSame(
			array(
				'ids'    => $ids,
				'source' => empty( $ids ) ? 'none' : 'default',
			),
			$db->manual_backup_destination_plan( $this->site_id )
		);
	}

	/** A confirmed deletion must detach the Default before the listener runs. */
	public function test_deleted_default_after_start_enqueues_nothing() {
		$id   = $this->destination( true );
		$db   = MainWP_DB_Backup::instance();
		$task = $db->ensure_manual_backup_task( $this->website() );
		$this->assertSame( array( $id ), json_decode( $task->destinations, true ) );
		$this->assertSame( array( 'deleted' => true ), MainWP_Backup_Storage_Handler::delete_destination_flow( $id, true ) );
		$this->assertSame( array(), json_decode( $db->get_manual_backup_task( $this->site_id )->destinations, true ) );
		$this->assertSame( array(), $this->snapshot_upload_destinations( $task ) );
	}

	/** The fallback must not depend on who owns tasks or can edit storage. */
	public function test_restricted_user_has_identical_plan() {
		$id    = $this->destination( true );
		$db    = MainWP_DB_Backup::instance();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$staff = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$scope = function ( $allowed ) use ( $staff ) {
			return get_current_user_id() === $staff ? array( $this->site_id ) : $allowed;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		try {
			foreach ( array( 'default', 'tasks' ) as $source ) {
				if ( 'tasks' === $source ) {
					$this->insert_scheduled_task( (string) $this->site_id, array( $this->destination( false ) ) );
				}
				wp_set_current_user( $admin );
				$plan = $db->manual_backup_destination_plan( $this->site_id );
				$this->assertSame( $source, $plan['source'] );
				wp_set_current_user( $staff );
				$this->assertFalse( current_user_can( 'manage_options' ) );
				$this->assertSame( $plan, $db->manual_backup_destination_plan( $this->site_id ) );
			}
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}
	}

	/** Removing the last coverage must release the site to the Default. */
	public function test_removed_coverage_restores_default() {
		global $wpdb;
		$id = $this->destination( true );
		$db = MainWP_DB_Backup::instance();
		foreach ( array( 'edit', 'delete' ) as $change ) {
			$task_id = $this->insert_scheduled_task( (string) $this->site_id, array() );
			$this->assertSame(
				array(
					'ids'    => array(),
					'source' => 'tasks',
				),
				$db->manual_backup_destination_plan( $this->site_id )
			);
			if ( 'edit' === $change ) {
				$db->update_backup_task_with_values( $task_id, array( 'sites' => '990011' ) );
			} else {
				$this->assertSame( 1, $wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $task_id ) ) );
			}
			$this->assertSame(
				array(
					'ids'    => array( $id ),
					'source' => 'default',
				),
				$db->manual_backup_destination_plan( $this->site_id )
			);
		}
	}

	/** Stray Default flags must resolve consistently without loading credentials. */
	public function test_default_id_matches_latest_default_without_settings() {
		global $wpdb;
		$store = MainWP_Backup_Storage_Destinations::instance();
		$this->assertSame( 0, $store->get_default_id() );
		$a = $this->destination( true );
		$b = $this->destination( true );
		$this->assertSame( 1, $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ), array( 'is_default' => 1 ), array( 'id' => $a ) ) );
		$queries = array();
		$capture = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $capture );
		try {
			$this->assertSame( $b, $store->get_default_id() );
		} finally {
			remove_filter( 'query', $capture );
		}
		$this->assertCount( 1, $queries );
		$this->assertSame( 'SELECT id FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ) . ' WHERE is_default = 1 ORDER BY id DESC LIMIT 1', $queries[0] );
		$this->assertSame( $b, (int) $store->get_default()->id );
	}

	/**
	 * Deleting a site removes its hidden manual task so no orphan row is
	 * left behind.
	 *
	 * @return void
	 */
	public function test_delete_site_removes_manual_task() {
		MainWP_DB_Backup::instance()->ensure_manual_backup_task( $this->website() );
		$this->assertIsObject( MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id ) );

		MainWP_Post_Backup_Handler::instance()->hook_delete_manual_backup_task( (object) array( 'id' => $this->site_id ) );

		$this->assertNull( MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id ), 'Deleting the site must remove its hidden manual task.' );
	}

	/**
	 * The cleanup handler ignores an empty site object instead of fataling.
	 *
	 * @return void
	 */
	public function test_delete_site_handler_ignores_empty_site() {
		$this->assertFalse( MainWP_Post_Backup_Handler::instance()->hook_delete_manual_backup_task( null ) );
	}

	/**
	 * init() registers the cleanup on mainwp_delete_site so the row is
	 * removed when a site is deleted from the dashboard.
	 *
	 * @return void
	 */
	public function test_delete_site_listener_registered_on_init() {
		MainWP_Post_Backup_Handler::instance()->init();

		$this->assertNotFalse(
			has_action( 'mainwp_delete_site', array( MainWP_Post_Backup_Handler::instance(), 'hook_delete_manual_backup_task' ) ),
			'init() must register the manual-task cleanup on site deletion.'
		);
	}
}
