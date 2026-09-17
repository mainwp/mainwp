<?php
/**
 * MainWP Backup Incremental Sender Tests
 *
 * Covers the dashboard side of incremental backups: choosing what to
 * send for an incremental task (incremental against the latest snapshot,
 * or a manifest-emitting full when no base or capability exists),
 * recovering from a child-reported missing base by pushing the parent
 * manifest and then falling back to full, and turning a completed
 * multi-artifact run into downloaded files plus a durable snapshot row.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Incremental_Sender_Test
 */
class MainWP_Backup_Incremental_Sender_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Captured child calls: list of [what, params].
	 *
	 * @var array
	 */
	protected $child_calls = array();

	/**
	 * Remote artifact content map: filename => bytes.
	 *
	 * @var array
	 */
	protected $remote_files = array();

	/**
	 * Manifest fixture path.
	 *
	 * @var string
	 */
	protected $manifest_fixture;

	/**
	 * Enable native backups and reset capture state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->child_calls  = array();
		$this->remote_files = array();

		$this->manifest_fixture = tempnam( sys_get_temp_dir(), 'mainwp-manifest-' );
		file_put_contents( $this->manifest_fixture, "a.php\t10\t100\nb.php\t20\t200\n" ); // phpcs:ignore -- test fixture.
	}

	/**
	 * Clean up progress and snapshot rows.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_snapshot" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		if ( file_exists( $this->manifest_fixture ) ) {
			unlink( $this->manifest_fixture );
		}
		parent::tearDown();
	}

	/**
	 * A healthy preflight response advertising the spine and incremental
	 * capabilities (no download tokens, so downloads run token-less).
	 *
	 * @param array $overrides Key overrides.
	 *
	 * @return array Preflight payload.
	 */
	protected function preflight_response( $overrides = array() ) {
		return array_merge(
			array(
				'disk_free'       => 10 * GB_IN_BYTES,
				'dir_writable'    => true,
				'db_connected'    => true,
				'zip_support'     => true,
				'time_limit_ok'   => true,
				'backup_dir_hash' => 'abc123def456gh78',
				'task_spine'      => 1,
				'incremental'     => 1,
			),
			$overrides
		);
	}

	/**
	 * Mock per-action child responses, capturing every call.
	 *
	 * @param array $responses Map of action => response array or callable.
	 *
	 * @return void
	 */
	protected function mock_child_actions( $responses ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $responses ) {
				unset( $website );
				$this->child_calls[] = array( $what, is_array( $params ) ? $params : array() );
				if ( ! isset( $responses[ $what ] ) ) {
					return $result;
				}
				$response = $responses[ $what ];
				return is_callable( $response ) ? call_user_func( $response, $params ) : $response;
			},
			10,
			4
		);
	}

	/**
	 * Serve artifact downloads from the remote content map.
	 *
	 * @return void
	 */
	protected function mock_artifact_downloads() {
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) {
				foreach ( $this->remote_files as $name => $content ) {
					if ( false !== strpos( (string) $url, rawurlencode( $name ) ) || false !== strpos( (string) $url, $name ) ) {
						file_put_contents( $file, $content ); // phpcs:ignore -- test fixture write.
						return true;
					}
				}
				return $pre;
			},
			10,
			3
		);
	}

	/**
	 * The standard completed multi-artifact payload and its remote bytes.
	 *
	 * @param bool $with_full Include a base full archive instead of parts only.
	 *
	 * @return array Artifacts list.
	 */
	protected function arrange_remote_artifacts( $with_full = false ) {
		$zip_bytes = function ( $size ) {
			return "PK\x03\x04" . str_repeat( 'z', $size - 4 );
		};

		$this->remote_files = array(
			'files.list'      => str_repeat( 'm', 40 ),
			'tombstones.list' => str_repeat( 't', 12 ),
		);

		$artifacts = array(
			array(
				'file' => 'files.list',
				'size' => 40,
				'role' => 'manifest',
			),
			array(
				'file' => 'tombstones.list',
				'size' => 12,
				'role' => 'tombstones',
			),
		);

		if ( $with_full ) {
			$this->remote_files['backup-base.zip'] = $zip_bytes( 400 );
			$artifacts[]                           = array(
				'file' => 'backup-base.zip',
				'size' => 400,
				'role' => 'full',
			);
		} else {
			$this->remote_files['dbBackup-1.zip']      = $zip_bytes( 120 );
			$this->remote_files['uploads.part001.zip'] = $zip_bytes( 200 );
			$artifacts[]                               = array(
				'file' => 'dbBackup-1.zip',
				'size' => 120,
				'role' => 'db',
			);
			$artifacts[]                               = array(
				'file' => 'uploads.part001.zip',
				'size' => 200,
				'role' => 'part',
			);
		}

		return $artifacts;
	}

	/**
	 * A scheduled incremental task object.
	 *
	 * @param object $website Website row.
	 * @param int    $task_id Task id.
	 * @param array  $extra   Field overrides.
	 *
	 * @return object Task.
	 */
	protected function make_task( $website, $task_id = 11, $extra = array() ) {
		return (object) array_merge(
			array(
				'id'                             => $task_id,
				'userid'                         => $website->userid,
				'type'                           => 'incremental',
				'exclude'                        => '',
				'excludebackup'                  => 0,
				'excludecache'                   => 0,
				'excludenonwp'                   => 0,
				'excludezip'                     => 0,
				'filename'                       => '',
				'archiveFormat'                  => 'zip',
				'loadFilesBeforeZip'             => 1,
				'maximumFileDescriptorsOverride' => 0,
				'maximumFileDescriptorsAuto'     => 1,
				'maximumFileDescriptors'         => 150,
				'last_run'                       => time() - 100,
				'subfolder'                      => 'backups',
			),
			$extra
		);
	}

	/**
	 * Give a site a real RSA private key so the token-less download URL
	 * can be signed.
	 *
	 * @param int $site_id Site id.
	 *
	 * @return void
	 */
	protected function give_site_real_privkey( $site_id ) {
		global $wpdb;
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $privkey_pem );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'privkey' => base64_encode( $privkey_pem ) ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions -- test fixture.
	}

	/**
	 * Record a base snapshot row for a site and task.
	 *
	 * @param int $site_id Site id.
	 * @param int $task_id Task id.
	 *
	 * @return int Snapshot id.
	 */
	protected function record_base_snapshot( $site_id, $task_id = 11 ) {
		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => $task_id,
				'site_id'       => $site_id,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => array(),
				'manifest_path' => $this->manifest_fixture,
			)
		);
	}

	/**
	 * Record an incremental snapshot row chained onto a parent.
	 *
	 * @param int $site_id   Site id.
	 * @param int $parent_id Parent snapshot id.
	 * @param int $task_id   Task id.
	 *
	 * @return int Snapshot id.
	 */
	protected function record_increment( $site_id, $parent_id, $task_id = 11 ) {
		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => $task_id,
				'site_id'       => $site_id,
				'type'          => 'incremental',
				'parent_id'     => $parent_id,
				'archives'      => array(),
				'manifest_path' => $this->manifest_fixture,
			)
		);
	}

	/**
	 * The captured params of the most recent call to an action.
	 *
	 * @param string $what Action name.
	 *
	 * @return array|null Params or null when never called.
	 */
	protected function last_call_params( $what ) {
		$params = null;
		foreach ( $this->child_calls as $call ) {
			if ( $call[0] === $what ) {
				$params = $call[1];
			}
		}
		return $params;
	}

	/**
	 * All captured calls to an action.
	 *
	 * @param string $what Action name.
	 *
	 * @return array[] Params per call, in order.
	 */
	protected function all_call_params( $what ) {
		$calls = array();
		foreach ( $this->child_calls as $call ) {
			if ( $call[0] === $what ) {
				$calls[] = $call[1];
			}
		}
		return $calls;
	}

	/**
	 * Run one backup_site cycle, tolerating thrown backup errors.
	 *
	 * @param int    $site_id Site id.
	 * @param object $task    Task object.
	 *
	 * @return array{result: mixed, exception: MainWP_Exception|null}
	 */
	protected function run_cycle( $site_id, $task ) {
		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			return array(
				'result'    => $result,
				'exception' => null,
			);
		} catch ( MainWP_Exception $e ) {
			return array(
				'result'    => null,
				'exception' => $e,
			);
		}
	}

	/**
	 * With a usable base snapshot the dashboard schedules an incremental
	 * run referencing it, without pushing the manifest up front.
	 *
	 * @return void
	 */
	public function test_create_sends_incremental_with_base_reference() {
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website ) );

		$this->assertNull( $cycle['exception'] );
		$this->assertFalse( $cycle['result'], 'The create cycle defers; following cycles drive the task.' );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertNotNull( $params );
		$this->assertSame( 'incremental', $params['type'] );
		$this->assertSame( (string) $base_id, (string) $params['parent_snapshot_id'] );
		$this->assertArrayNotHasKey( 'parent_manifest', $params, 'The manifest is only pushed when the child copy is gone.' );
	}

	/**
	 * A chain at its configured length restarts: the next run is a
	 * manifest-emitting full that becomes the base of a new chain, so the
	 * old chain turns into a complete, prunable retention unit.
	 *
	 * @return void
	 */
	public function test_chain_restart_runs_full_at_limit() {
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );
		$mid_id  = $this->record_increment( $site_id, $base_id );
		$this->record_increment( $site_id, $mid_id );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website, 11, array( 'max_chain_length' => 3 ) ) );

		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'full', $params['type'], 'A chain at its limit restarts with a new full.' );
		$this->assertSame( '1', (string) $params['emit_manifest'] );
	}

	/**
	 * A chain below the limit keeps extending incrementally.
	 *
	 * @return void
	 */
	public function test_chain_below_limit_stays_incremental() {
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );
		$tip_id  = $this->record_increment( $site_id, $base_id );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website, 11, array( 'max_chain_length' => 3 ) ) );

		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'incremental', $params['type'] );
		$this->assertSame( (string) $tip_id, (string) $params['parent_snapshot_id'] );
	}

	/**
	 * A pruned ancestor breaks the chain even below the length limit:
	 * its artifacts are gone, so every further increment would be
	 * unrestorable. The next run restarts with a new full base.
	 *
	 * @return void
	 */
	public function test_pruned_ancestor_restarts_chain_with_full() {
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );
		$this->record_increment( $site_id, $base_id );
		MainWP_Backup_Snapshots::instance()->set_status( $base_id, 'pruned' );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website, 11, array( 'max_chain_length' => 5 ) ) );

		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'full', $params['type'], 'A chain over a pruned base cannot restore; the run restarts full.' );
		$this->assertSame( '1', (string) $params['emit_manifest'] );
	}

	/**
	 * A zero limit means unlimited - the chain keeps growing, matching
	 * the behavior before the limit existed.
	 *
	 * @return void
	 */
	public function test_chain_unlimited_when_zero() {
		$site_id = $this->create_test_site();
		$parent  = $this->record_base_snapshot( $site_id );
		for ( $i = 0; $i < 4; $i++ ) {
			$parent = $this->record_increment( $site_id, $parent );
		}

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website, 11, array( 'max_chain_length' => 0 ) ) );

		$this->assertNull( $cycle['exception'] );
		$this->assertSame( 'incremental', $this->last_call_params( 'backup_task_create' )['type'] );
	}

	/**
	 * A chain whose walk does not reach a full base is broken - it cannot
	 * restore completely, so the safe answer is a restart full.
	 *
	 * @return void
	 */
	public function test_broken_chain_restarts_full() {
		$site_id = $this->create_test_site();
		// An increment whose parent row is gone: the chain walk stops at it.
		$this->record_increment( $site_id, 99999999 );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website, 11, array( 'max_chain_length' => 10 ) ) );

		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'full', $params['type'], 'A broken chain restarts instead of extending unrestorable history.' );
		$this->assertSame( '1', (string) $params['emit_manifest'] );
	}

	/**
	 * The broken-chain rule holds when the length limit is off: a pruned
	 * ancestor makes every further increment unrestorable regardless of
	 * how long the chain may grow.
	 *
	 * @return void
	 */
	public function test_pruned_base_with_zero_limit_restarts_chain_with_full() {
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );
		$this->record_increment( $site_id, $base_id );
		MainWP_Backup_Snapshots::instance()->set_status( $base_id, 'pruned' );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website, 11, array( 'max_chain_length' => 0 ) ) );

		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'full', $params['type'], 'A pruned ancestor breaks the chain whether or not a length limit applies.' );
		$this->assertSame( '1', (string) $params['emit_manifest'] );
	}

	/**
	 * A chain that never reaches a full base is broken under an unlimited
	 * length setting too.
	 *
	 * @return void
	 */
	public function test_broken_chain_with_zero_limit_restarts_full() {
		$site_id = $this->create_test_site();
		// An increment whose parent row is gone: the chain walk stops at it.
		$this->record_increment( $site_id, 99999999 );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website, 11, array( 'max_chain_length' => 0 ) ) );

		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'full', $params['type'], 'A broken chain restarts regardless of the length limit.' );
		$this->assertSame( '1', (string) $params['emit_manifest'] );
	}

	/**
	 * With no snapshot history the first run is a manifest-emitting full -
	 * the chain base.
	 *
	 * @return void
	 */
	public function test_create_runs_manifest_full_when_no_base() {
		$site_id = $this->create_test_site();

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website ) );

		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'full', $params['type'] );
		$this->assertSame( '1', (string) $params['emit_manifest'] );
	}

	/**
	 * A child without the incremental capability gets a full backup - a
	 * scheduled backup that silently never runs would be worse than one
	 * that costs more disk than expected.
	 *
	 * @return void
	 */
	public function test_create_falls_back_to_full_without_capability() {
		$site_id = $this->create_test_site();
		$this->record_base_snapshot( $site_id );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response( array( 'incremental' => 0 ) ),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website ) );

		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'full', $params['type'] );
	}

	/**
	 * A completed incremental run downloads every artifact into a per-run
	 * directory, records the snapshot row with chain parentage, and cleans
	 * the child-side artifacts while leaving the manifest sidecar alone.
	 *
	 * @return void
	 */
	public function test_completion_downloads_artifacts_and_records_snapshot() {
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$base_id = $this->record_base_snapshot( $site_id );

		$artifacts = $this->arrange_remote_artifacts();
		$this->mock_artifact_downloads();

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
				'backup_task_run'    => array(
					'task_id'   => 'child-task-1',
					'phase'     => 'completed',
					'file'      => 'files.list',
					'size'      => 40,
					'artifacts' => $artifacts,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website );

		$this->assertFalse( $this->run_cycle( $site_id, $task )['result'], 'Cycle one creates the child task.' );

		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertNull( $cycle['exception'] );
		$this->assertIsArray( $cycle['result'] );

		// Every artifact landed in the per-run snapshot directory.
		$run_dir = MainWP_System_Utility::get_mainwp_specific_dir( $site_id ) . 'snapshot-child-task-1/';
		foreach ( $artifacts as $artifact ) {
			$this->assertFileExists( $run_dir . $artifact['file'] );
			$this->assertSame( (int) $artifact['size'], (int) filesize( $run_dir . $artifact['file'] ) );
		}

		// The snapshot row carries the chain parentage and artifact list.
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, 11 );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 'incremental', $snapshot->type );
		$this->assertSame( $base_id, (int) $snapshot->parent_id );
		$this->assertSame( $run_dir . 'files.list', $snapshot->manifest_path );
		$this->assertCount( 4, $snapshot->archives );
		$roles = array_column( $snapshot->archives, 'role' );
		$this->assertContains( 'manifest', $roles );
		$this->assertContains( 'part', $roles );

		// Remote cleanup removed everything except the manifest the next
		// incremental diffs against.
		$deleted = array_column( $this->all_call_params( 'delete_backup' ), 'del' );
		$this->assertContains( 'dbBackup-1.zip', $deleted );
		$this->assertContains( 'uploads.part001.zip', $deleted );
		$this->assertContains( 'tombstones.list', $deleted );
		$this->assertNotContains( 'files.list', $deleted );
	}

	/**
	 * A completed forced-full base run (artifacts carry the archive and
	 * the manifest) records a full snapshot with no parent.
	 *
	 * @return void
	 */
	public function test_completion_of_base_full_records_full_snapshot() {
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );

		$artifacts = $this->arrange_remote_artifacts( true );
		$this->mock_artifact_downloads();

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-2',
					'phase'   => 'queued',
				),
				'backup_task_run'    => array(
					'task_id'   => 'child-task-2',
					'phase'     => 'completed',
					'file'      => 'backup-base.zip',
					'size'      => 400,
					'artifacts' => $artifacts,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website );

		$this->run_cycle( $site_id, $task );
		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertNull( $cycle['exception'] );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, 11 );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 'full', $snapshot->type );
		$this->assertNull( $snapshot->parent_id );

		$deleted = array_column( $this->all_call_params( 'delete_backup' ), 'del' );
		$this->assertContains( 'backup-base.zip', $deleted );
		$this->assertNotContains( 'files.list', $deleted );
	}

	/**
	 * A child-reported missing base first retries by pushing the parent
	 * manifest the dashboard holds, then falls back to a manifest-emitting
	 * full, and only then treats the failure as real.
	 *
	 * @return void
	 */
	public function test_base_missing_retries_with_push_then_full_then_fails() {
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => function ( $params ) {
					unset( $params );
					static $n = 0;
					++$n;
					return array(
						'task_id' => 'child-task-' . $n,
						'phase'   => 'queued',
					);
				},
				'backup_task_run'    => array(
					'phase'      => 'failed',
					'error'      => 'The file list from the previous backup is missing.',
					'error_code' => 'mainwp_backup_incremental_base_missing',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website );

		// Cycle 1-2: plain incremental, child reports the base missing - requeued.
		$this->run_cycle( $site_id, $task );
		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertNull( $cycle['exception'], 'The first missing-base report requeues instead of failing.' );
		$this->assertFalse( $cycle['result'] );

		// Cycle 3: the create retries incremental, pushing the manifest.
		$this->run_cycle( $site_id, $task );
		$creates = $this->all_call_params( 'backup_task_create' );
		$this->assertCount( 2, $creates );
		$this->assertSame( 'incremental', $creates[1]['type'] );
		$this->assertSame( (string) $base_id, (string) $creates[1]['parent_snapshot_id'] );
		$this->assertSame( base64_encode( file_get_contents( $this->manifest_fixture ) ), $creates[1]['parent_manifest'] );

		// Cycle 4: the pushed retry also fails - requeued as full.
		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertNull( $cycle['exception'], 'The pushed retry failing requeues as full.' );

		// Cycle 5: the create runs a manifest-emitting full.
		$this->run_cycle( $site_id, $task );
		$creates = $this->all_call_params( 'backup_task_create' );
		$this->assertCount( 3, $creates );
		$this->assertSame( 'full', $creates[2]['type'] );
		$this->assertSame( '1', (string) $creates[2]['emit_manifest'] );

		// Cycle 6: a base-missing failure on the full fallback is real.
		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertNotNull( $cycle['exception'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_INCREMENTAL_BASE_MISSING, $cycle['exception']->get_message_error_code() );
	}

	/**
	 * Make the base manifest larger than the inline push cap.
	 *
	 * @return string Manifest content.
	 */
	protected function oversize_manifest_fixture() {
		$content = str_repeat( "some/very/long/path/file.php\t12345\t1700000000\n", 120000 );
		file_put_contents( $this->manifest_fixture, $content ); // phpcs:ignore -- test fixture.
		return $content;
	}

	/**
	 * A chunk-capable receiver mock for backup_manifest_chunk.
	 *
	 * @param object $state Holder collecting received bytes.
	 *
	 * @return callable Responder.
	 */
	protected function manifest_chunk_responder( $state ) {
		return function ( $params ) use ( $state ) {
			$state->bytes .= base64_decode( $params['chunk_b64'] );
			return array(
				'result'         => 'ok',
				'mtoken'         => 'tok0123456789abc',
				'bytes_received' => strlen( $state->bytes ),
			);
		};
	}

	/**
	 * When the inline push would overflow and the child can stage chunks,
	 * the manifest travels in pieces and the create references the staged
	 * session instead of forcing a full backup.
	 *
	 * @return void
	 */
	public function test_oversized_manifest_chunk_pushes_after_missing_base() {
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );
		$content = $this->oversize_manifest_fixture();

		$state        = new \stdClass();
		$state->bytes = '';

		$this->mock_child_actions(
			array(
				'backup_preflight'      => $this->preflight_response( array( 'manifest_chunk' => 1 ) ),
				'backup_manifest_chunk' => $this->manifest_chunk_responder( $state ),
				'backup_task_create'    => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
				'backup_task_run'       => array(
					'phase'      => 'failed',
					'error'      => 'The file list from the previous backup is missing.',
					'error_code' => 'mainwp_backup_incremental_base_missing',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website );

		// Cycle 1-2: plain incremental, child reports the base missing - requeued.
		$this->run_cycle( $site_id, $task );
		$this->run_cycle( $site_id, $task );

		// Cycle 3: the retry would push the manifest, but it is oversized -
		// it chunks over instead and the create references the session.
		$this->run_cycle( $site_id, $task );

		$this->assertSame( $content, $state->bytes, 'Every manifest byte arrives through the chunk callable.' );

		$creates = $this->all_call_params( 'backup_task_create' );
		$this->assertCount( 2, $creates );
		$this->assertSame( 'incremental', $creates[1]['type'] );
		$this->assertSame( (string) $base_id, (string) $creates[1]['parent_snapshot_id'] );
		$this->assertSame( 'tok0123456789abc', $creates[1]['parent_manifest_ref'] );
		$this->assertSame( hash( 'sha256', $content ), $creates[1]['parent_manifest_sha256'] );
		$this->assertArrayNotHasKey( 'parent_manifest', $creates[1], 'The oversized manifest never travels inline.' );

		// Cycle 4-5: the chunk-pushed retry also reports the base missing -
		// the next escalation is the full fallback.
		$this->run_cycle( $site_id, $task );
		$this->run_cycle( $site_id, $task );

		$creates = $this->all_call_params( 'backup_task_create' );
		$this->assertCount( 3, $creates );
		$this->assertSame( 'full', $creates[2]['type'] );
		$this->assertSame( '1', (string) $creates[2]['emit_manifest'] );
	}

	/**
	 * A child without the staging capability keeps today's behavior: an
	 * oversized manifest forces the full fallback.
	 *
	 * @return void
	 */
	public function test_oversized_manifest_without_capability_runs_full() {
		$site_id = $this->create_test_site();
		$this->record_base_snapshot( $site_id );
		$this->oversize_manifest_fixture();

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
				'backup_task_run'    => array(
					'phase'      => 'failed',
					'error'      => 'The file list from the previous backup is missing.',
					'error_code' => 'mainwp_backup_incremental_base_missing',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website );

		$this->run_cycle( $site_id, $task );
		$this->run_cycle( $site_id, $task );
		$this->run_cycle( $site_id, $task );

		$creates = $this->all_call_params( 'backup_task_create' );
		$this->assertCount( 2, $creates );
		$this->assertSame( 'full', $creates[1]['type'] );
		$this->assertSame( '1', (string) $creates[1]['emit_manifest'] );
		$this->assertSame( array(), $this->all_call_params( 'backup_manifest_chunk' ), 'No chunk is sent to a child that cannot stage it.' );
	}

	/**
	 * A chunk transfer that cannot get through falls the run back to a
	 * full backup in the same cycle - a backup that costs more disk beats
	 * one that silently never runs.
	 *
	 * @return void
	 */
	public function test_chunk_push_failure_falls_back_to_full() {
		$site_id = $this->create_test_site();
		$this->record_base_snapshot( $site_id );
		$this->oversize_manifest_fixture();

		$this->mock_child_actions(
			array(
				'backup_preflight'      => $this->preflight_response( array( 'manifest_chunk' => 1 ) ),
				'backup_manifest_chunk' => function () {
					throw new MainWP_Exception( 'cURL error 52: empty reply' );
				},
				'backup_task_create'    => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
				'backup_task_run'       => array(
					'phase'      => 'failed',
					'error'      => 'The file list from the previous backup is missing.',
					'error_code' => 'mainwp_backup_incremental_base_missing',
				),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website );

		$this->run_cycle( $site_id, $task );
		$this->run_cycle( $site_id, $task );
		$cycle = $this->run_cycle( $site_id, $task );

		$this->assertNull( $cycle['exception'], 'The failed chunk transfer falls back instead of failing the run.' );

		$creates = $this->all_call_params( 'backup_task_create' );
		$this->assertCount( 2, $creates );
		$this->assertSame( 'full', $creates[1]['type'] );
		$this->assertSame( '1', (string) $creates[1]['emit_manifest'] );
		$this->assertArrayNotHasKey( 'parent_manifest_ref', $creates[1] );
	}

	/**
	 * Create a real scheduled incremental task row so the notice recording
	 * against the wp_backup row is observable, unlike the synthetic task
	 * objects the other tests use.
	 *
	 * @param int $site_id Site id the task targets.
	 *
	 * @return object Task row.
	 */
	protected function create_real_task_row( $site_id ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task = MainWP_DB_Backup::instance()->add_backup_task(
			$user,
			'Incremental sender test task',
			'daily',
			'incremental',
			'',
			(string) $site_id,
			'',
			'',
			'',
			0,
			0,
			0,
			0,
			0,
			'zip',
			0,
			1,
			150,
			0,
			0
		);
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'last_run' => time() - 100 ) );
		return MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
	}

	/**
	 * A fallback to full on a child without the incremental capability is a
	 * successful backup with an advisory note: the note lands in
	 * backup_notices and backup_errors stays empty, so the run never reads
	 * as failed and never triggers the failure email.
	 *
	 * @return void
	 */
	public function test_capability_fallback_records_notice_not_error() {
		$site_id = $this->create_test_site();
		$task    = $this->create_real_task_row( $site_id );
		$this->record_base_snapshot( $site_id, (int) $task->id );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response( array( 'incremental' => 0 ) ),
				'backup_task_create' => array(
					'task_id' => 'child-task-1',
					'phase'   => 'queued',
				),
			)
		);

		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertNull( $cycle['exception'] );

		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'full', $params['type'], 'Precondition: the run fell back to a full backup.' );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $task->id );
		$this->assertSame( '', (string) $task->backup_errors, 'An advisory fallback note must not mark the run failed.' );
		$this->assertStringContainsString( 'does not support incremental', (string) $task->backup_notices, 'The fallback reason must reach the notices channel.' );
	}

	/**
	 * The notice recorder itself writes the advisory channel: every
	 * fallback note it takes lands in backup_notices and leaves
	 * backup_errors alone, since anything in backup_errors reads as a
	 * failed run downstream.
	 *
	 * @return void
	 */
	public function test_record_incremental_notice_writes_notices_channel() {
		$site_id = $this->create_test_site();
		$task    = $this->create_real_task_row( $site_id );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$method = new \ReflectionMethod( MainWP_Backup_Executor::class, 'record_incremental_notice' );
		$method->setAccessible( true );
		$method->invoke( null, $website, (int) $task->id, 'A full backup was created because incremental backup was unavailable for this site.' );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $task->id );
		$this->assertSame( '', (string) $task->backup_errors, 'The advisory note must not touch the failure channel.' );
		$this->assertStringContainsString( 'incremental backup was unavailable', (string) $task->backup_notices, 'The advisory note must land in backup_notices.' );
	}
	/**
	 * Create a real incremental task and stub its artifact-producing child.
	 *
	 * @param bool $base Whether a base already exists.
	 * @param bool $empty_tombstones Serve an empty deletion list.
	 * @param bool $empty_part Serve an empty archive part.
	 * @return array Site, task and base ids.
	 */
	private function arrange_manual_task( $base = true, $empty_tombstones = false, $empty_part = false ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$this->give_site_real_privkey( $site_id );
		$task = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Native Run Now', 'manual', 'incremental', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$base_id = $base ? $this->record_base_snapshot( $site_id, $task->id ) : null;
		if ( $base_id ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup_snapshot', array( 'created' => time() - 100 ), array( 'id' => $base_id ) );
		}
		$artifacts = $this->arrange_remote_artifacts( ! $base );
		foreach ( $artifacts as &$artifact ) {
			if ( ( $empty_tombstones && 'tombstones' === $artifact['role'] ) || ( $empty_part && 'part' === $artifact['role'] ) ) {
				$artifact['size']                        = 0;
				$this->remote_files[ $artifact['file'] ] = '';
			}
		}
		unset( $artifact );
		$this->mock_artifact_downloads();
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'manual-' . $task->id,
					'phase'   => 'queued',
				),
				'backup_task_run'    => array(
					'phase'     => 'completed',
					'artifacts' => $artifacts,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
				'backup_task_cancel' => array( 'result' => 'ok' ),
			)
		);
		return array( $site_id, $task, $base_id );
	}

	/**
	 * Run Now extends the chain and announces success after recording it.
	 *
	 * @return void
	 */
	public function test_run_now_records_chained_snapshot_before_success_event() {
		list( $site_id, $task, $base_id ) = $this->arrange_manual_task();
		$events                           = array();
		add_action(
			'mainwp_native_backup_completed',
			function ( $site, $child, $success ) use ( &$events, $task ) {
				$events[] = array( $success, MainWP_Backup_Snapshots::instance()->get_latest_complete( $site, $task->id )->id );
			},
			10,
			3
		);
		$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$this->assertSame( 'running', $start['status'] );
		$this->assertGreaterThan( 0, MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run_manually );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'completed', $status['status'] );
		$snapshot = $status['sites'][0]['snapshot'];
		$this->assertSame( 'incremental', $snapshot['type'] );
		$this->assertSame( $base_id, $snapshot['parent_id'] );
		$this->assertSame( array( array( true, (string) $snapshot['id'] ) ), $events );
		$this->assertSame(
			array(
				'total'    => 0,
				'complete' => 0,
				'failed'   => 0,
				'pending'  => 0,
			),
			$status['sites'][0]['uploads']
		);
	}

	/**
	 * A fresh incremental task records the automatic full base.
	 *
	 * @return void
	 */
	public function test_run_now_without_base_records_full_snapshot() {
		list( $site_id, $task ) = $this->arrange_manual_task( false );
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$this->assertSame( 'full', $this->last_call_params( 'backup_task_create' )['type'] );
		$this->assertSame( '1', $this->last_call_params( 'backup_task_create' )['emit_manifest'] );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 'full', $status['sites'][0]['snapshot']['type'] );
		$this->assertNull( $status['sites'][0]['snapshot']['parent_id'] );
		$this->assertSame( $site_id, $status['sites'][0]['id'] );
	}

	/**
	 * Empty tombstones are valid when advertised as zero bytes.
	 *
	 * @return void
	 */
	public function test_empty_tombstones_download_records_snapshot() {
		list( $site_id, $task ) = $this->arrange_manual_task( true, true );
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$status                 = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'completed', $status['status'] );
		$snapshot   = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$tombstones = array_values(
			array_filter(
				$snapshot->archives,
				static function ( $artifact ) {
					return 'tombstones' === $artifact['role'];
				}
			)
		);
		$this->assertCount( 1, $tombstones );
		$this->assertSame( 0, filesize( $tombstones[0]['path'] ) );
	}

	/**
	 * A zero-byte part remains a failed download.
	 *
	 * @return void
	 */
	public function test_zero_byte_part_download_fails() {
		list( $site_id, $task, $base_id ) = $this->arrange_manual_task( true, false, true );
		$this->run_cycle( $site_id, $task );
		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertInstanceOf( MainWP_Exception::class, $cycle['exception'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DL_FILE_NOT_FOUND, $cycle['exception']->get_message_error_code() );
		$this->assertSame( $base_id, (int) MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task->id )->id );
	}

	/**
	 * A manifest walk failure retains its code and registry message.
	 *
	 * @return void
	 */
	public function test_manifest_walk_failure_stores_registry_message() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$this->mock_child_actions(
			array(
				'backup_task_run' => array(
					'phase'      => 'failed',
					'error'      => 'walk broke',
					'error_code' => MainWP_Backup_Error_Codes::ERR_MANIFEST_WALK_FAILED,
				),
			)
		);
		$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$task  = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_MANIFEST_WALK_FAILED, $cycle['exception']->get_message_error_code() );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false );
		$task    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$message = MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_MANIFEST_WALK_FAILED );
		$this->assertStringContainsString( $message, $task->backup_errors );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'failed', $status['status'] );
		$this->assertSame( $message, $status['sites'][0]['error'] );
		$this->assertNull( $status['sites'][0]['snapshot'] );
	}

	/**
	 * Snapshot insert failure emits failure only.
	 *
	 * @return void
	 */
	public function test_incremental_record_failure_never_emits_success() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$property               = new \ReflectionProperty( MainWP_Backup_Snapshots::class, 'instance' );
		$original               = $property->getValue();
		$property->setValue(
			null,
			new class() extends MainWP_Backup_Snapshots {
				/**
				 * Refuse the snapshot insert.
				 *
				 * @param array $data Snapshot fields.
				 * @return int Failed id.
				 */
				public function record_snapshot( array $data ): int {
					return 0;
				}
			}
		);
		$events = array();
		add_action(
			'mainwp_native_backup_completed',
			function ( $site, $child, $success, $meta ) use ( &$events ) {
				$events[] = array( $success, $meta );
			},
			10,
			4
		);
		try {
			$this->run_cycle( $site_id, $task );
			$cycle = $this->run_cycle( $site_id, $task );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED, $cycle['exception']->get_message_error_code() );
			$this->assertCount( 1, $events );
			$this->assertFalse( $events[0][0] );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED, $events[0][1]['error_code'] );
			$this->assertSame( $cycle['exception']->getMessage(), $events[0][1]['error'] );
		} finally {
			$property->setValue( null, $original );
		}
	}

	/**
	 * Full capacity queues a click without contacting the child, then retries.
	 *
	 * @return void
	 */
	public function test_run_now_queues_at_capacity_then_status_opens_run() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		update_option( 'mainwp_backup_max_concurrent', 1 );
		$token = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		$this->assertIsString( $token );
		try {
			$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'queued', $start['status'] );
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
			$this->assertSame( 'queued', $status['status'] );
			$this->assertSame( 'pending', $status['sites'][0]['status'] );
			$this->assertSame( array(), $this->child_calls );
		} finally {
			MainWP_Backup_Monitor::release_backup_slot( $token );
			delete_option( 'mainwp_backup_max_concurrent' );
		}
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'running', $status['status'] );
		$this->assertNotNull( $this->last_call_params( 'backup_task_create' ) );
	}

	/**
	 * A paused task cannot open a manual run.
	 *
	 * @return void
	 */
	public function test_run_now_refuses_paused_task() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup', array( 'paused' => 1 ), array( 'id' => $task->id ) );

		$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$this->assertSame( 'This task is paused. Resume it before running it.', $start['error'] );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $task->last_run, $after->last_run );
		$this->assertSame( $task->completed, $after->completed );
		$this->assertSame( $task->last_run_manually, $after->last_run_manually );
		$this->assertGreaterThanOrEqual( (int) $after->last_run, (int) $after->completed );
		$this->assertSame( array(), $this->child_calls );
	}

	/**
	 * Pausing a queued task prevents its next poll from opening a run.
	 *
	 * @return void
	 */
	public function test_queued_run_status_refuses_paused_task() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		update_option( 'mainwp_backup_max_concurrent', 1 );
		$token = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		$this->assertIsString( $token );
		try {
			$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'queued', $start['status'] );
		} finally {
			MainWP_Backup_Monitor::release_backup_slot( $token );
			delete_option( 'mainwp_backup_max_concurrent' );
		}
		$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup', array( 'paused' => 1 ), array( 'id' => $task->id ) );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'This task is paused. Resume it before running it.', $status['error'] );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $task->last_run, $after->last_run );
		$this->assertSame( $task->completed, $after->completed );
		$this->assertSame( $task->last_run_manually, $after->last_run_manually );
		$this->assertGreaterThanOrEqual( (int) $after->last_run, (int) $after->completed );
		$this->assertSame( array(), $this->child_calls );
	}

	/**
	 * A duplicate click follows the recently closed run without claiming another.
	 *
	 * @return void
	 */
	public function test_run_status_absorbs_duplicate_initiate_inside_settle_window() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$done                   = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'completed', $done['status'] );
		$closed = time() - 5;
		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp_backup',
			array(
				'last_run'          => $closed,
				'completed'         => $closed,
				'last_run_manually' => $closed,
			),
			array( 'id' => $task->id )
		);
		update_option( 'mainwp_backup_manual_settle_seconds', 60 );
		try {
			$calls     = $this->child_calls;
			$duplicate = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'queued', $duplicate['status'] );
			$this->assertGreaterThan( $closed, $duplicate['started'] );
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $duplicate['started'] );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( $closed, $status['started'] );
			$this->assertSame( $done['sites'][0]['snapshot']['id'], $status['sites'][0]['snapshot']['id'] );

			MainWP_DB_Backup::instance()->update_backup_errors( $task->id, '<ol><li>Upload failed.</li></ol>' );
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $duplicate['started'] );
			$this->assertSame( 'completed_with_errors', $status['status'] );
			$this->assertSame( 'Upload failed.', $status['errors'] );
			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertSame( $closed, (int) $after->last_run );
			$this->assertSame( $closed, (int) $after->completed );
			$this->assertSame( $closed, (int) $after->last_run_manually );
			$this->assertSame( $calls, $this->child_calls );
		} finally {
			delete_option( 'mainwp_backup_manual_settle_seconds' );
		}
	}

	/**
	 * Another user's task cannot be started or polled in multi-user mode.
	 *
	 * @return void
	 */
	public function test_run_now_refuses_another_users_task() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'mainwp_is_single_user', '__return_false' );
		try {
			$this->assertArrayHasKey( 'error', MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id ) );
			$this->assertArrayHasKey( 'error', MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id ) );
			$this->assertSame( array(), $this->child_calls );
		} finally {
			remove_filter( 'mainwp_is_single_user', '__return_false' );
		}
	}

	/**
	 * Task ownership is not site access. The owner of a task whose site the
	 * Team Control capability now denies must not be able to start it, and
	 * must not read that site's name and url back out of the status payload -
	 * the endpoint this lane replaced filtered its site list the same way.
	 *
	 * @return void
	 */
	public function test_run_now_refuses_a_site_the_owner_may_no_longer_access() {
		list( $site_id, $task ) = $this->arrange_manual_task();

		$deny = function ( $allowed, $cap_type, $cap ) use ( $site_id ) {
			return ( 'site' === $cap_type && (int) $cap === (int) $site_id ) ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertArrayHasKey( 'error', $start );
			$this->assertArrayNotHasKey( 'job_id', $start );

			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id );
			$this->assertArrayHasKey( 'error', $status );
			$this->assertArrayNotHasKey( 'sites', $status, 'A refused caller must not receive the site list.' );

			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertSame( (int) $task->last_run, (int) $after->last_run, 'A refused start must not open a run.' );
			$this->assertSame( array(), $this->child_calls );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		// The same caller, with the capability restored, still gets through:
		// the gate must refuse the denial, not the task.
		$allowed = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id );
		$this->assertArrayNotHasKey( 'error', $allowed );
		$this->assertCount( 1, $allowed['sites'] );
	}

	/**
	 * Mid-phase progress is running; a completed child alone is not a snapshot.
	 *
	 * @return void
	 */
	public function test_run_status_reports_mid_phase_label_and_percent() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$this->mock_child_actions(
			array(
				'backup_task_run' => array(
					'phase'    => 'dumping_db',
					'progress' => array(
						'files_processed' => 2,
						'files_total'     => 4,
						'current_file'    => 'table.sql',
					),
				),
			)
		);
		$start  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'running', $status['status'] );
		$this->assertSame( 'running', $status['sites'][0]['status'] );
		$this->assertNotEmpty( $status['sites'][0]['label'] );
		$this->assertSame( 50, $status['sites'][0]['percent'] );
		$this->assertSame( 'table.sql', $status['sites'][0]['current_file'] );
		$this->assertNull( $status['sites'][0]['snapshot'] );
	}

	/**
	 * A snapshot plus a run error reports partial success.
	 *
	 * @return void
	 */
	public function test_run_status_completed_with_errors_keeps_snapshot() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$done                   = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'completed', $done['status'] );
		MainWP_DB_Backup::instance()->update_backup_errors( $task->id, '<ol><li>Upload failed.</li></ol>' );
		MainWP_DB_Backup::instance()->update_backup_notices( $task->id, '<ol><li>Full base used.</li></ol>' );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'completed_with_errors', $status['status'] );
		$this->assertSame( 'Upload failed.', $status['errors'] );
		$this->assertSame( 'Full base used.', $status['notices'] );
		$this->assertSame( $done['sites'][0]['snapshot']['id'], $status['sites'][0]['snapshot']['id'] );
	}
}
