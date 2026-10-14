<?php
/**
 * Allocated snapshots and storage for single-delete tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/** Fixtures never contact a live site or allocate explicit row ids. */
trait Snapshot_Delete_Fixture {
	use Backup_Private_Root_Fixture;

	/**
	 * Fixture site.
	 *
	 * @var int
	 */
	private $site_id;
	/**
	 * Fixture task.
	 *
	 * @var object
	 */
	private $task;

	/** Allocate a hidden Back Up Now task and private storage. */
	public function set_up() {
		parent::set_up();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->set_up_private_root();
		$this->site_id = $this->create_test_site();
		$this->task    = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'delete_child_reply' ) );
	}

	/** Remove only this case's rows and files. */
	public function tear_down() {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'delete_child_reply' ) );
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' WHERE site_id = %d', $this->site_id ) );
		foreach ( $ids as $id ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'snapshot_id' => $id ) );
		}
		foreach ( array( 'wp_backup_snapshot', 'wp_backup_restore_job' ) as $table ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( $table ), array( 'site_id' => $this->site_id ) );
		}
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ), array( 'wp_id' => $this->site_id ) );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $this->task->id ) );
		$this->tear_down_private_root();
		$_POST    = array();
		$_REQUEST = array();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tear_down();
	}

	/**
	 * Child pruning stays local to the test.
	 *
	 * @return array
	 */
	public function delete_child_reply() {
		return array( 'result' => 'ok' );
	}

	/**
	 * Record a snapshot with a real owned artifact.
	 *
	 * @param int|null $parent_id Parent identity.
	 * @param string   $type Snapshot type.
	 * @return int
	 */
	private function snapshot( $parent_id = null, $type = 'full' ) {
		$dir = MainWP_Backup_Private_Root::site_path( $this->site_id );
		wp_mkdir_p( $dir );
		$file = wp_generate_password( 12, false ) . '.zip';
		file_put_contents( $dir . $file, 'backup' );
		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'site_id'   => $this->site_id,
				'task_id'   => (int) $this->task->id,
				'type'      => null === $parent_id ? $type : 'incremental',
				'parent_id' => $parent_id,
				'archives'  => array(
					array(
						'path' => $dir . $file,
						'file' => $file,
						'size' => 6,
						'role' => 'full',
					),
				),
			)
		);
	}

	/**
	 * Assert the missing feature before dispatch, so red evidence is a failure.
	 *
	 * @param int       $id Snapshot identity.
	 * @param bool|null $cloud Cloud choice, null uses the task default.
	 * @return array
	 */
	private function preview( $id, $cloud = null ) {
		$this->assertTrue( is_callable( array( MainWP_Backup_Operations::class, 'preview_delete_snapshot' ) ), 'X2 preview is missing.' );
		return MainWP_Backup_Operations::preview_delete_snapshot( $this->site_id, $id, $cloud );
	}

	/**
	 * Confirmation uses the exact previewed identities.
	 *
	 * @param int   $id Target identity.
	 * @param array $preview Consent preview.
	 * @param bool  $cloud Cloud choice.
	 * @return array|\WP_Error
	 */
	private function confirm( $id, $preview, $cloud = true ) {
		return MainWP_Backup_Operations::delete_snapshot( $this->site_id, $id, $preview['would_affect']['snapshot_ids'], $cloud );
	}
}
