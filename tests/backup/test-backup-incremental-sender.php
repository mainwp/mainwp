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
			$this->remote_files['dbBackup-1.zip']       = $zip_bytes( 120 );
			$this->remote_files['uploads.part001.zip']  = $zip_bytes( 200 );
			$artifacts[]                                = array(
				'file' => 'dbBackup-1.zip',
				'size' => 120,
				'role' => 'db',
			);
			$artifacts[]                                = array(
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
}
