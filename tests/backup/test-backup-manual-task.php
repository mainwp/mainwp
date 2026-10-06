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
 * scheduled tasks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Backup_Settings;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

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
	 * A site with no scheduled task inherits no destinations (local-only).
	 *
	 * @return void
	 */
	public function test_destinations_empty_when_no_scheduled_task() {
		$this->assertSame( array(), MainWP_DB_Backup::instance()->manual_backup_task_destinations( $this->site_id ) );
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
