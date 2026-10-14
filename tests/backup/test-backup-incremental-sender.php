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

namespace MainWP\Dashboard;

/**
 * A fixed second makes same-second initiation coverage independent of database speed.
 *
 * @return int
 */
function time() {
	return Tests\MainWP_Backup_Incremental_Sender_Test::$fixed_time ?? \time();
}

namespace MainWP\Dashboard\Tests; // phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- The production-namespace filesystem stub belongs with its tests.

require_once __DIR__ . '/trait-backup-private-root-fixture.php'; // phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- the filesystem or clock stub must share the production namespace.

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Incremental_Sender_Test
 */
class MainWP_Backup_Incremental_Sender_Test extends MainWP_Abilities_Test_Case { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- the switchable stub belongs with its regression tests.

	use Backup_Cancel_Transport_Guard;

	use Backup_Private_Root_Fixture;

	/**
	 * Keep initiation timestamps equal even when a test crosses a wall-clock second.
	 *
	 * @var int|null
	 */
	public static $fixed_time = null;

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
	 * Site ids created by this test, so their run directories can be swept up
	 * in tearDown(). The DB rolls back per test, but the run directories the
	 * executor writes under get_mainwp_specific_dir() do not; because site
	 * ids are reused across runs, a later test can find a same-size
	 * files.list left over from an earlier run and skip a download it
	 * expected to perform.
	 *
	 * @var int[]
	 */
	protected $site_ids = array();

	/**
	 * A failed clock-dependent test must not pin the next test's clock.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_private_root();
		self::$fixed_time = null;
	}

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
		$this->site_ids     = array();

		$this->manifest_fixture = tempnam( sys_get_temp_dir(), 'mainwp-manifest-' );
		file_put_contents( $this->manifest_fixture, "a.php\t10\t100\nb.php\t20\t200\n" ); // phpcs:ignore -- test fixture.
	}

	/**
	 * Create a test site and track its id for run-directory cleanup.
	 *
	 * @param array $args Optional. Site properties to override defaults.
	 *
	 * @return int Site id.
	 */
	protected function create_test_site( array $args = array() ): int {
		$site_id          = parent::create_test_site( $args );
		$this->site_ids[] = $site_id;
		return $site_id;
	}

	/**
	 * Clean up progress and snapshot rows, and any run directories the
	 * executor left behind for this test's sites.
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
		foreach ( $this->site_ids as $site_id ) {
			$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
			if ( is_dir( $dir ) && false !== strpos( $dir, '/mainwp/' ) ) {
				self::rrmdir( $dir );
			}
		}
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $dir Directory path.
	 *
	 * @return void
	 */
	private static function rrmdir( $dir ) {
		if ( is_link( $dir ) ) {
			unlink( $dir );
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $leftover ) {
			if ( $leftover->isLink() ) {
				unlink( $leftover->getPathname() );
			} elseif ( $leftover->isDir() ) {
				rmdir( $leftover->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			} else {
				unlink( $leftover->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
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
				'disk_free'                => 10 * GB_IN_BYTES,
				'dir_writable'             => true,
				'db_connected'             => true,
				'zip_support'              => true,
				'time_limit_ok'            => true,
				'backup_dir_hash'          => 'abc123def456gh78',
				'task_spine'               => 1,
				'incremental'              => 1,
				'manifest_writer_identity' => 1,
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
				'file'   => 'files.list',
				'size'   => 40,
				'role'   => 'manifest',
				'sha256' => hash( 'sha256', $this->remote_files['files.list'] ),
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
	 * @param int  $site_id Site id.
	 * @param int  $task_id Task id.
	 * @param bool $record_manifest Whether the child-local manifest belongs to this base.
	 *
	 * @return int Snapshot id.
	 */
	protected function record_base_snapshot( $site_id, $task_id = 11, $record_manifest = true ) {
		$snapshot_id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => $task_id,
				'site_id'       => $site_id,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => array(),
				'manifest_path' => $this->manifest_fixture,
				'run_key'       => $site_id . ':' . $task_id . ':c:fixture-' . wp_generate_password( 12, false ),
			)
		);
		if ( $record_manifest ) {
			MainWP_DB::instance()->update_website_option( $site_id, 'native_manifest_snapshot', (string) $snapshot_id );
		}
		return $snapshot_id;
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
				'run_key'       => $site_id . ':' . $task_id . ':c:fixture-' . wp_generate_password( 12, false ),
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
	 * Plain requires both a negotiated proof and a stable base identity.
	 *
	 * @dataProvider manifest_identity_inputs
	 * @param bool   $capable Child capability.
	 * @param string $identity Base run identity suffix.
	 * @param bool   $plain Expected plain transport.
	 */
	public function test_plain_requires_manifest_writer_identity( $capable, $identity, $plain ) {
		global $wpdb;
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup_snapshot', array( 'run_key' => $site_id . ':11:' . $identity ), array( 'id' => $base_id ) );
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response( array( 'manifest_writer_identity' => $capable ? 1 : 0 ) ),
				'backup_task_create' => array(
					'task_id' => 'identity-next',
					'phase'   => 'queued',
				),
			)
		);
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertNull( $this->run_cycle( $site_id, $this->make_task( $website ) )['exception'] );
		$params = $this->last_call_params( 'backup_task_create' );
		$this->assertSame( 'incremental', $params['type'] );
		$this->assertSame( $plain, isset( $params['parent_child_task_id'] ) );
		$this->assertSame( ! $plain, isset( $params['parent_manifest'] ) );
		if ( $plain ) {
			$this->assertSame( 'task-base', $params['parent_child_task_id'] );
		}
		$this->assertSame( $capable ? '1' : '0', MainWP_DB::instance()->get_website_option( $site_id, 'manifest_writer_identity_support' ) );
	}

	/**
	 * Capability and identity cases.
	 *
	 * @return array
	 */
	public static function manifest_identity_inputs() {
		return array(
			'new child'            => array( true, 'c:task-base', true ),
			'old child'            => array( false, 'c:task-base', false ),
			'legacy identity'      => array( true, 'l:legacy', false ),
			'empty child identity' => array( true, 'c:', false ),
		);
	}

	/**
	 * Every ordering preserves the most recent pending owner, including failed records.
	 *
	 * @dataProvider manifest_record_interleavings
	 * @param array  $events Ordered create/record events.
	 * @param string $owner Expected final owner.
	 */
	public function test_manifest_pending_token_interleavings( $events, $owner ) {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$claim   = new \ReflectionMethod( MainWP_Backup_Executor::class, 'claim_manifest_writer' );
		$claim->setAccessible( true );
		$record = new \ReflectionMethod( MainWP_Backup_Executor::class, 'record_manifest_writer' );
		$record->setAccessible( true );
		$rows = array();
		foreach ( $events as $event ) {
			list( $op, $name ) = explode( ':', $event );
			$id                = 'I' === $name ? 11 : 12;
			if ( 'create' === $op ) {
				MainWP_DB_Backup::instance()->add_backup_task_progress( $id, $site_id, array() );
				MainWP_DB_Backup::instance()->update_backup_task_progress(
					$id,
					$site_id,
					array(
						'child_backup_task_id' => 'creating-' . $name,
						'last_error'           => wp_json_encode(
							array(
								'incremental_sent' => 'push',
								'run'              => 123,
							)
						),
					)
				);
				$claim->invoke( null, $website, $id, 'creating-' . $name );
				$rows[ $name ] = MainWP_DB_Backup::instance()->get_backup_task_progress( $id, $site_id );
				$state         = json_decode( $rows[ $name ]->last_error, true );
				$this->assertSame( 'push', $state['incremental_sent'] );
				$this->assertSame( 123, $state['run'] );
				$this->assertSame( $state['manifest_pending'], MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
			} elseif ( 'record' === $op ) {
				$record->invoke( null, $website, $rows[ $name ], $id );
			}
		}
		$expected = 'pending:F' === $owner ? json_decode( $rows['F']->last_error, true )['manifest_pending'] : $owner;
		$this->assertSame( $expected, MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
		if ( 'pending:F' === $owner ) {
			$this->record_base_snapshot( $site_id, 11, false );
			MainWP_DB::instance()->update_website_option( $site_id, 'incremental_support', '1' );
			MainWP_DB::instance()->update_website_option( $site_id, 'manifest_writer_identity_support', '1' );
			$resolve = new \ReflectionMethod( MainWP_Backup_Executor::class, 'resolve_incremental_send' );
			$resolve->setAccessible( true );
			$send = $resolve->invoke( null, $website, $this->make_task( $website ), null );
			$this->assertSame( 'push', $send['mode'], 'F completed but failed to record; I must send its own base.' );
		}
	}

	/**
	 * All create/record orders and an unrecorded completion.
	 *
	 * @return array
	 */
	public static function manifest_record_interleavings() {
		return array(
			'I R F R'                      => array( array( 'create:I', 'record:I', 'create:F', 'record:F' ), '12' ),
			'I F RI RF'                    => array( array( 'create:I', 'create:F', 'record:I', 'record:F' ), '12' ),
			'I F RF RI'                    => array( array( 'create:I', 'create:F', 'record:F', 'record:I' ), '12' ),
			'F R I R'                      => array( array( 'create:F', 'record:F', 'create:I', 'record:I' ), '11' ),
			'F I RF RI'                    => array( array( 'create:F', 'create:I', 'record:F', 'record:I' ), '11' ),
			'F I RI RF'                    => array( array( 'create:F', 'create:I', 'record:I', 'record:F' ), '11' ),
			'I F RI F record fails next I' => array( array( 'create:I', 'create:F', 'record:I', 'failed:F' ), 'pending:F' ),
		);
	}

	/** The option CAS compares token bytes, not the database collation. */
	public function test_manifest_record_rejects_case_variant_token() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $site_id, 'native_manifest_snapshot', 'pending:11:ABC' );
		$record = new \ReflectionMethod( MainWP_Backup_Executor::class, 'record_manifest_writer' );
		$record->setAccessible( true );
		$record->invoke( null, $website, (object) array( 'last_error' => wp_json_encode( array( 'manifest_pending' => 'pending:11:abc' ) ) ), 99 );
		$this->assertSame( 'pending:11:ABC', MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
	}

	/** A database error cannot turn a pending token into recorded ownership. */
	public function test_manifest_record_db_failure_preserves_pending() {
		global $wpdb;
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $site_id, 'native_manifest_snapshot', 'pending:11:token' );
		$record = new \ReflectionMethod( MainWP_Backup_Executor::class, 'record_manifest_writer' );
		$record->setAccessible( true );
		$fail = static function ( $query ) {
			return 0 === strpos( $query, 'UPDATE ' ) && false !== strpos( $query, 'BINARY value' ) ? 'INVALID SQL FOR MANIFEST CAS TEST' : $query;
		};
		$old  = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$record->invoke( null, $website, (object) array( 'last_error' => wp_json_encode( array( 'manifest_pending' => 'pending:11:token' ) ) ), 99 );
			$this->assertSame( 'pending:11:token', MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $old );
		}
	}

	/**
	 * A writer failure must not hide a recorded snapshot or duplicate its success event.
	 *
	 * @dataProvider manifest_record_completion_cases
	 * @param bool $fail_write Fail the first writer update.
	 * @param bool $legacy_identity Adopt through the run-directory fallback.
	 */
	public function test_manifest_record_db_failure_retry_announces_success_once( $fail_write, $legacy_identity ) {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task( false );
		$events                 = array();
		$capture                = static function ( $site, $child, $success, $meta ) use ( &$events ) {
			$events[] = array( $success, $meta );
		};
		$fail                   = static function ( $query ) {
			return 0 === strpos( $query, 'UPDATE ' ) && false !== strpos( $query, 'BINARY value' ) ? 'INVALID SQL FOR MANIFEST CAS TEST' : $query;
		};
		$old                    = $wpdb->suppress_errors( true );
		add_action( 'mainwp_native_backup_completed', $capture, 10, 4 );
		try {
			$create = $this->run_cycle( $site_id, $task );
			$this->assertNull( $create['exception'] );
			$this->assertFalse( $create['result'] );
			if ( $fail_write ) {
				add_filter( 'query', $fail );
			}
			$first = $this->run_cycle( $site_id, $task );
			remove_filter( 'query', $fail );
			$snapshots = MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id );
			$this->assertCount( 1, $snapshots );
			$snapshot_id = (int) $snapshots[0]->id;
			$this->assertCount( 1, $events );
			$this->assertTrue( $events[0][0] );
			$this->assertSame( $snapshot_id, $events[0][1]['snapshot_id'] );
			$this->assertNull( $first['exception'] );
			$this->assertSame( $snapshot_id, $first['result']['snapshot_id'] );
			$owner = MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' );
			if ( $fail_write ) {
				$this->assertStringStartsWith( 'pending:', $owner );
				$resolve = new \ReflectionMethod( MainWP_Backup_Executor::class, 'resolve_incremental_send' );
				$resolve->setAccessible( true );
				$send = $resolve->invoke( null, MainWP_DB::instance()->get_website_by_id( $site_id ), $task, null );
				$this->assertSame( 'push', $send['mode'] );
			} else {
				$this->assertSame( (string) $snapshot_id, $owner );
			}
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( $snapshot_id, $progress->fetchResult['announced_snapshot_id'] );
			if ( $legacy_identity ) {
				$this->assertSame( 1, $wpdb->update( $wpdb->prefix . 'mainwp_wp_backup_snapshot', array( 'run_key' => null ), array( 'id' => $snapshot_id ) ) );
			}
			$retry = $this->run_cycle( $site_id, $task );
			$this->assertNull( $retry['exception'] );
			$this->assertSame( $snapshot_id, $retry['result']['snapshot_id'] );
			$this->assertCount( 1, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
			$this->assertSame( array( true ), array_column( $events, 0 ) );
			$this->assertSame( $snapshot_id, $events[ count( $events ) - 1 ][1]['snapshot_id'] );
			$this->assertSame( $owner, MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
			$again = $this->run_cycle( $site_id, $task );
			$this->assertNull( $again['exception'] );
			$this->assertSame( $snapshot_id, $again['result']['snapshot_id'] );
			$this->assertCount( 1, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
			$this->assertCount( 1, $events );
		} finally {
			remove_filter( 'query', $fail );
			remove_action( 'mainwp_native_backup_completed', $capture, 10 );
			$wpdb->suppress_errors( $old );
		}
	}

	/**
	 * Both snapshot identity paths must recover without duplicating a successful announcement.
	 *
	 * @return array
	 */
	public static function manifest_record_completion_cases() {
		return array(
			'writer failure'               => array( true, false ),
			'writer failure run directory' => array( true, true ),
			'happy path'                   => array( false, false ),
			'happy path run directory'     => array( false, true ),
		);
	}

	/**
	 * Failed pending writes or readbacks must stop before the remote create.
	 *
	 * @dataProvider manifest_write_failures
	 * @param string $target Failed table suffix or readback.
	 */
	public function test_manifest_db_failure_prevents_create( $target ) {
		global $wpdb;
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array( 'task_id' => 'should-not-create' ),
			)
		);
		$pending_written = false;
		$fail            = static function ( $query ) use ( $target, &$pending_written ) {
			if ( 'readback' === $target && false !== strpos( $query, 'native_manifest_snapshot' ) ) {
				if ( 0 === strpos( $query, 'UPDATE ' ) && false !== strpos( $query, 'pending:' ) ) {
					$pending_written = true;
				} elseif ( $pending_written && 0 === strpos( $query, 'SELECT value ' ) ) {
					return 'INVALID SQL FOR MANIFEST READBACK TEST';
				}
			}
			if ( false !== strpos( $query, $target ) && ( 'manifest_writer_identity_support' === $target || false !== strpos( $query, 'pending:' ) ) && preg_match( '/^(UPDATE|INSERT)/i', $query ) ) {
				return 'INVALID SQL FOR MANIFEST WRITE TEST';
			}
			return $query;
		};
		$old             = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$website = MainWP_DB::instance()->get_website_by_id( $site_id );
			$cycle   = $this->run_cycle( $site_id, $this->make_task( $website ) );
			$this->assertInstanceOf( MainWP_Exception::class, $cycle['exception'] );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED, $cycle['exception']->get_message_error_code() );
			$this->assertSame( array(), $this->all_call_params( 'backup_task_create' ) );
			remove_filter( 'query', $fail );
			if ( 'readback' === $target ) {
				$this->assertStringStartsWith( 'pending:11:', MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
			} else {
				$this->assertSame( (string) $base_id, MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
			}
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $old );
		}
	}

	/**
	 * Pending token writes and the option readback may fail independently.
	 *
	 * @return array
	 */
	public static function manifest_write_failures() {
		return array(
			'progress' => array( 'mainwp_wp_backup_progress' ),
			'option'   => array( 'mainwp_wp_options' ),
			'readback' => array( 'readback' ),
		);
	}

	/** Losing the creating marker during preflight yields without a create. */
	public function test_manifest_claim_lost_before_write_yields() {
		$site_id = $this->create_test_site();
		$base_id = $this->record_base_snapshot( $site_id );
		$this->mock_child_actions(
			array(
				'backup_preflight' => function () use ( $site_id ) {
					MainWP_DB_Backup::instance()->update_backup_task_progress( 11, $site_id, array( 'child_backup_task_id' => 'creating-replacement' ) );
					return $this->preflight_response();
				},
			)
		);
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$cycle   = $this->run_cycle( $site_id, $this->make_task( $website ) );
		$this->assertNull( $cycle['exception'] );
		$this->assertSame( array(), $this->all_call_params( 'backup_task_create' ) );
		$this->assertSame( 'creating-replacement', MainWP_DB_Backup::instance()->get_backup_task_progress( 11, $site_id )->child_backup_task_id );
		$this->assertSame( (string) $base_id, MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
	}

	/** Another task replacing the option before readback yields without sending a create. */
	public function test_manifest_claim_lost_on_readback_yields() {
		global $wpdb;
		$site_id = $this->create_test_site();
		$this->record_base_snapshot( $site_id );
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array( 'task_id' => 'should-not-create' ),
			)
		);
		$pending_written = false;
		$interleaved     = false;
		$interleave      = static function ( $query ) use ( &$interleave, &$pending_written, &$interleaved, $site_id, $wpdb ) {
			if ( false !== strpos( $query, 'native_manifest_snapshot' ) ) {
				if ( 0 === strpos( $query, 'UPDATE ' ) && false !== strpos( $query, 'pending:' ) ) {
					$pending_written = true;
				} elseif ( $pending_written && 0 === strpos( $query, 'SELECT value ' ) ) {
					remove_filter( 'query', $interleave );
					MainWP_DB::instance()->update_website_option( $site_id, 'native_manifest_snapshot', 'pending:12:other-task' );
					$interleaved = '' === $wpdb->last_error;
				}
			}
			return $query;
		};
		add_filter( 'query', $interleave );
		try {
			$website = MainWP_DB::instance()->get_website_by_id( $site_id );
			$cycle   = $this->run_cycle( $site_id, $this->make_task( $website ) );
			$this->assertTrue( $interleaved, 'The competing option write must succeed before readback.' );
			$this->assertNull( $cycle['exception'] );
			$this->assertFalse( $cycle['result'] );
			$this->assertSame( array(), $this->all_call_params( 'backup_task_create' ) );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( 11, $site_id );
			$this->assertSame( 0, (int) $progress->dtsFetched );
			$this->assertSame( '', $progress->child_backup_task_id );
			$state = json_decode( $progress->last_error, true );
			$this->assertArrayNotHasKey( 'incremental_sent', $state );
			$this->assertArrayNotHasKey( 'run', $state );
			$this->assertStringStartsWith( 'pending:11:', $state['manifest_pending'] );
			$this->assertSame( 'pending:12:other-task', MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
		} finally {
			remove_filter( 'query', $interleave );
		}
	}

	/**
	 * Replaced shared bytes permit completion; retryable failures must preserve the cycle.
	 *
	 * @dataProvider manifest_artifact_failures
	 * @param int    $actual_size Downloaded byte count.
	 * @param mixed  $digest Advertised digest.
	 * @param string $download_code Injected download failure, or empty for success.
	 * @param string $expected_code Surfaced failure, or empty for manifestless completion.
	 * @param bool   $lock_refused Whether another driver owns the download lock.
	 */
	public function test_manifest_artifact_failure_matrix( $actual_size, $digest, $download_code, $expected_code, $lock_refused = false ) {
		list( $site_id, $task )           = $this->arrange_manual_task( false );
		$this->remote_files['files.list'] = str_repeat( 'x', $actual_size );
		$local                            = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-manual-' . $task->id . '/files.list';
		$failure                          = '' === $download_code ? null : ( 'runtime' === $download_code ? new \RuntimeException( 'Download interrupted.' ) : MainWP_Backup_Error_Codes::exception( $download_code ) );
		$deleted_under_lock               = array();
		$rewrite                          = static function ( $result, $website, $what ) use ( $digest ) {
			if ( 'backup_task_run' === $what && isset( $result['artifacts'] ) ) {
				$result['artifacts'][0]['size'] = 1000;
				unset( $result['artifacts'][0]['sha256'] );
				if ( null !== $digest ) {
					$result['artifacts'][0]['sha256'] = $digest;
				}
			}
			return $result;
		};
		$download_failure                 = static function ( $pre, $url, $file ) use ( $failure ) {
			if ( 'files.list' === basename( $file ) && null !== $failure ) {
				throw $failure;
			}
			return $pre;
		};
		$observe_delete                   = static function ( $file ) use ( $local, &$deleted_under_lock ) {
			if ( $local === $file ) {
				$deleted_under_lock[] = is_file( $local . '.downloading' );
			}
			return $file;
		};
		add_filter( 'mainwp_download_to_file_pre', $download_failure, 20, 3 );
		add_filter( 'mainwp_fetch_url_authed_pre', $rewrite, 20, 3 );
		add_filter( 'wp_delete_file', $observe_delete );
		try {
			$this->run_cycle( $site_id, $task );
			if ( $lock_refused ) {
				wp_mkdir_p( dirname( $local ) );
				file_put_contents( $local . '.downloading', wp_json_encode( array( 'started' => time(), 'site_id' => $site_id, 'task_id' => $task->id, 'token' => 'other-driver' ) ) ); // phpcs:ignore -- competing driver fixture.
			}
			try {
				$finish = $this->run_cycle( $site_id, $task );
			} catch ( \RuntimeException $e ) {
				$finish = array(
					'exception' => $e,
					'result'    => null,
				);
			}
			if ( '' !== $expected_code ) {
				$this->assertCount( 0, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
				if ( null !== $failure ) {
					$this->assertSame( $failure, $finish['exception'] );
				}
				if ( 'runtime' !== $expected_code ) {
					$this->assertInstanceOf( MainWP_Exception::class, $finish['exception'] );
					$this->assertSame( $expected_code, $finish['exception']->get_message_error_code() );
				}
				$this->assertSame( array(), $deleted_under_lock );
			} else {
				$this->assertNull( $finish['exception'], 'Manifest degradation must not surface ERR_FILE_CORRUPT.' );
				$this->assertIsArray( $finish['result'] );
				$this->assertCount( 1, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
				$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $finish['result']['snapshot_id'] );
				$this->assertSame( '', $snapshot->manifest_path );
				$this->assertNotContains( 'manifest', array_column( $snapshot->archives, 'role' ) );
				$this->assertNotContains( 'files.list', array_column( $snapshot->archives, 'file' ) );
				$this->assertFileDoesNotExist( $local );
				$this->assertSame( array( true ), $deleted_under_lock, 'Deletion must precede download lock release.' );
				$send = new \ReflectionMethod( MainWP_Backup_Executor::class, 'resolve_incremental_send' );
				$send->setAccessible( true );
				$next = $send->invoke( null, MainWP_DB::instance()->get_website_by_id( $site_id ), $task, null );
				$this->assertSame( 'full', $next['mode'] );
			}
			$this->assertSame( $lock_refused, is_file( $local . '.downloading' ), 'Release only the lock acquired by this driver.' );
		} finally {
			remove_filter( 'mainwp_download_to_file_pre', $download_failure, 20 );
			remove_filter( 'mainwp_fetch_url_authed_pre', $rewrite, 20 );
			remove_filter( 'wp_delete_file', $observe_delete );
		}
	}

	/**
	 * Only content failures justify abandoning the shared manifest.
	 *
	 * @return array
	 */
	public static function manifest_artifact_failures() {
		$digest = hash( 'sha256', str_repeat( 'm', 1000 ) );
		return array(
			'lock refused'             => array( 1000, $digest, '', MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, true ),
			'too-small size error'     => array( 980, $digest, MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL, '' ),
			'oversize size error'      => array( 1020, $digest, MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, '' ),
			'smaller within tolerance' => array( 995, $digest, MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL, '' ),
			'post-download mismatch'   => array( 1005, $digest, '', '' ),
			'digest absent'            => array( 1000, null, '', '' ),
			'digest empty'             => array( 1000, '', '', '' ),
			'same-size replacement'    => array( 1000, $digest, '', '' ),
			'digest malformed'         => array( 1000, 'not-a-digest', '', '' ),
			'digest non-string'        => array( 1000, 123, '', '' ),
			'transport error'          => array( 1000, $digest, MainWP_Backup_Error_Codes::ERR_HTTP_ERROR, MainWP_Backup_Error_Codes::ERR_HTTP_ERROR ),
			'slot lost'                => array( 1000, $digest, MainWP_Backup_Error_Codes::ERR_SLOT_LOST, MainWP_Backup_Error_Codes::ERR_SLOT_LOST ),
			'non-MainWP throwable'     => array( 1000, $digest, 'runtime', 'runtime' ),
		);
	}

	/**
	 * Digest compatibility cases.
	 *
	 * @return array
	 */
	public static function unverified_manifest_digests() {
		return array(
			'old child absent'      => array( null ),
			'mid-upgrade empty'     => array( '' ),
			'same-size replacement' => array( hash( 'sha256', str_repeat( 'm', 40 ) ) ),
			'malformed'             => array( 'not-a-digest' ),
			'non-string'            => array( 123 ),
		);
	}

	/**
	 * Legacy full downloads keep the archive but discard unverified reconciliation metadata.
	 *
	 * @dataProvider unverified_manifest_digests
	 * @param mixed $digest Missing, empty, malformed or mismatching digest.
	 */
	public function test_legacy_full_unverified_manifest_degrades( $digest ) {
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$artifacts = $this->arrange_remote_artifacts( true );
		$artifacts = array( $artifacts[2], $artifacts[0] );
		unset( $artifacts[1]['sha256'] );
		if ( null !== $digest ) {
			$artifacts[1]['sha256'] = $digest;
		}
		$this->remote_files['files.list'] = str_repeat( 'x', 40 );
		$this->mock_artifact_downloads();
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response( array( 'manifest_writer_identity' => 0 ) ),
				'backup_task_create' => array(
					'task_id' => 'unverified-full',
					'phase'   => 'queued',
				),
				'backup_task_run'    => array(
					'task_id'   => 'unverified-full',
					'phase'     => 'completed',
					'file'      => 'backup-base.zip',
					'size'      => 400,
					'artifacts' => $artifacts,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
			)
		);
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website, 11, array( 'type' => 'full' ) );
		$this->run_cycle( $site_id, $task );
		$finish = $this->run_cycle( $site_id, $task );
		$this->assertNull( $finish['exception'] );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, 11 );
		$this->assertNotNull( $snapshot );
		$this->assertSame( '', $snapshot->manifest_path );
		$this->assertSame( array( 'full' ), array_column( $snapshot->archives, 'role' ) );
		$this->assertFileExists( $snapshot->archives[0]['path'] );
		$this->assertFileDoesNotExist( \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-unverified-full/files.list' );
	}

	/** Archive size failures cannot be treated as optional metadata failures. */
	public function test_incremental_archive_size_failure_rejects_run() {
		list( $site_id, $task )               = $this->arrange_manual_task();
		$this->remote_files['dbBackup-1.zip'] = "PK\x03\x04" . str_repeat( 'z', 200 );
		$this->run_cycle( $site_id, $task );
		$before = count( MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
		$finish = $this->run_cycle( $site_id, $task );
		$this->assertInstanceOf( MainWP_Exception::class, $finish['exception'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $finish['exception']->get_message_error_code() );
		$this->assertCount( $before, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
	}

	/** A listener cannot undo a durable snapshot and its announcement marker. */
	public function test_incremental_success_listener_failure_keeps_completion() {
		list( $site_id, $task ) = $this->arrange_manual_task( false );
		$events                 = array();
		$listener               = static function ( $site, $child, $success ) use ( &$events ) {
			$events[] = $success;
			if ( $success ) {
				throw new \RuntimeException( 'Completion listener failed.' );
			}
		};
		add_action( 'mainwp_native_backup_completed', $listener, 10, 3 );
		try {
			$this->run_cycle( $site_id, $task );
			$finish = $this->run_cycle( $site_id, $task );
			$this->assertNull( $finish['exception'] );
			$this->assertIsArray( $finish['result'] );
			$this->assertCount( 1, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
			$this->assertSame( array( true ), $events );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( $finish['result']['snapshot_id'], $progress->fetchResult['announced_snapshot_id'] );
			$retry = $this->run_cycle( $site_id, $task );
			$this->assertNull( $retry['exception'] );
			$this->assertSame( $finish['result']['snapshot_id'], $retry['result']['snapshot_id'] );
			$this->assertSame( array( true ), $events );
		} finally {
			remove_action( 'mainwp_native_backup_completed', $listener, 10 );
		}
	}

	/**
	 * Only a recorded manifest identifies the child's shared files.list.
	 *
	 * @dataProvider manifest_snapshot_events
	 * @param string $type Intervening task type.
	 * @param string $event Intervening run outcome.
	 * @param int    $next_task Task whose next incremental is checked.
	 */
	public function test_manifest_snapshot_event_matrix( $type, $event, $next_task ) {
		global $wpdb;
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		self::$fixed_time = \time() - 200;
		$base_b           = $this->record_base_snapshot( $site_id, 12, false );
		$base_a           = $this->record_base_snapshot( $site_id, 11 );
		$base_b_manifest  = $this->manifest_fixture . '-task-b';
		file_put_contents( $base_b_manifest, "task-b.php\t30\t300\n" );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup_snapshot', array( 'manifest_path' => $base_b_manifest ), array( 'id' => $base_b ) );
		self::$fixed_time += 200;
		$website           = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task              = $this->make_task( $website, 12, array( 'type' => $type ) );
		$artifacts         = $this->arrange_remote_artifacts( 'incremental' !== $type );
		$this->mock_artifact_downloads();
		$creates = 0;
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => function () use ( $site_id, $event, &$creates ) {
					++$creates;
					$this->assertStringStartsWith( 'pending:', (string) MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
					if ( 1 === $creates && 'create fails' === $event ) {
						throw new MainWP_Exception( 'Create failed.' );
					}
					if ( 1 === $creates && 'refused' === $event ) {
						return array(
							'error'      => 'Busy.',
							'error_code' => MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING,
						);
					}
					return array(
						'task_id' => 'matrix-' . $creates,
						'phase'   => 'queued',
					);
				},
				'backup_task_run'    => array(
					'task_id'   => 'matrix-1',
					'phase'     => 'child fails' === $event ? 'failed' : 'completed',
					'file'      => 'incremental' === $type ? 'files.list' : 'backup-base.zip',
					'size'      => 'incremental' === $type ? 40 : 400,
					'artifacts' => $artifacts,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
				'backup_task_cancel' => array( 'result' => 'ok' ),
			)
		);
		$property = new \ReflectionProperty( MainWP_Backup_Snapshots::class, 'instance' );
		$original = $property->getValue();
		try {
			if ( 'old option only' === $event ) {
				MainWP_DB::instance()->update_website_option( $site_id, 'native_manifest_snapshot', '' );
				MainWP_DB::instance()->update_website_option( $site_id, 'native_manifest_writer_task', '12' );
			} else {
				$create = $this->run_cycle( $site_id, $task );
				if ( 'create fails' === $event ) {
					$this->assertInstanceOf( MainWP_Exception::class, $create['exception'] );
				} else {
					$this->assertNull( $create['exception'] );
					$this->assertFalse( $create['result'] );
				}
				$this->assertFalse( ctype_digit( (string) MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) ) );
				if ( 'record fails' === $event ) {
					$property->setValue(
						null,
						new class() extends MainWP_Backup_Snapshots {
							/**
							 * Refuse the insert after the completed child's artifacts arrive.
							 *
							 * @param array $data Snapshot fields.
							 * @return int Failed id.
							 */
							public function record_snapshot( array $data ): int {
								return 0;
							}
						}
					);
				}
				if ( 'degraded' === $event ) {
					add_filter(
						'mainwp_download_to_file_pre',
						static function ( $pre, $url, $file ) {
							if ( 'files.list' === basename( $file ) ) {
								throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL );
							}
							return $pre;
						},
						5,
						3
					);
				}
				if ( 'cancelled' === $event ) {
					MainWP_Manage_Backups_Handler::close_child_task_after_terminal_mark( 12, $site_id );
					$this->assertSame( array( array( 'task_id' => 'matrix-1' ) ), $this->all_call_params( 'backup_task_cancel' ) );
				}
				if ( ! in_array( $event, array( 'create fails', 'refused', 'cancelled' ), true ) ) {
					$finish = $this->run_cycle( $site_id, $task );
					if ( 'record fails' === $event ) {
						$this->assertInstanceOf( MainWP_Exception::class, $finish['exception'] );
						$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED, $finish['exception']->get_message_error_code() );
					} elseif ( 'child fails' === $event ) {
						$this->assertInstanceOf( MainWP_Exception::class, $finish['exception'] );
					} else {
						$this->assertNull( $finish['exception'] );
						$this->assertIsArray( $finish['result'] );
					}
				}
			}
			$property->setValue( null, $original );
			$latest = $original->get_latest_complete( $site_id, 12 );
			if ( in_array( $event, array( 'recorded', 'degraded' ), true ) ) {
				$this->assertNotSame( $base_b, (int) $latest->id, 'The completion must insert a new snapshot.' );
				if ( 'degraded' !== $event ) {
					$this->assertSame( (string) $latest->id, (string) MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
				}
				$this->assertSame( 'degraded' === $event, '' === $latest->manifest_path );
			} else {
				$this->assertSame( $base_b, (int) $latest->id, 'A failed or cancelled run must not record a snapshot.' );
				$this->assertFalse( ctype_digit( (string) MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) ) );
			}

			// Start a fresh run after the outcome, without carrying its retry marker.
			$wpdb->delete(
				$wpdb->prefix . 'mainwp_wp_backup_progress',
				array(
					'task_id' => $next_task,
					'wp_id'   => $site_id,
				)
			); // phpcs:ignore WordPress.DB -- test fixture.
			++self::$fixed_time;
			$next = $this->make_task( $website, $next_task, array( 'last_run' => self::$fixed_time ) );
			$this->assertNull( $this->run_cycle( $site_id, $next )['exception'] );
			$params = $this->last_call_params( 'backup_task_create' );
			if ( 12 === $next_task && ( 'degraded' === $event || ( 'files' === $type && 'recorded' === $event ) ) ) {
				$this->assertSame( 'full', $params['type'], 'A missing manifest or files-only base requires the existing full escalation.' );
				$this->assertSame( '1', $params['emit_manifest'] );
			} else {
				$this->assertSame( 'incremental', $params['type'] );
				$this->assertSame( (string) ( 11 === $next_task ? $base_a : $latest->id ), $params['parent_snapshot_id'] );
				if ( 'recorded' === $event && 12 === $next_task ) {
					$this->assertArrayNotHasKey( 'parent_manifest', $params );
					$this->assertArrayNotHasKey( 'parent_manifest_ref', $params );
				} else {
					$this->assertSame( base64_encode( file_get_contents( 11 === $next_task ? $this->manifest_fixture : $base_b_manifest ) ), $params['parent_manifest'] );
				}
			}
		} finally {
			$property->setValue( null, $original );
			self::$fixed_time = null;
			unlink( $base_b_manifest );
		}
	}

	/**
	 * Successful records and every interruption before a record.
	 *
	 * @return array
	 */
	public static function manifest_snapshot_events() {
		$cases = array( 'old task option is ignored' => array( 'incremental', 'old option only', 12 ) );
		foreach ( array( 'incremental', 'full', 'files' ) as $type ) {
			foreach ( array( 'create fails', 'child fails', 'cancelled', 'refused', 'record fails', 'recorded' ) as $event ) {
				$cases[ $type . '/' . $event ] = array( $type, $event, 12 );
			}
			$cases[ $type . '/another task recorded in between' ] = array( $type, 'recorded', 11 );
			if ( 'incremental' !== $type ) {
				$cases[ $type . '/degraded' ] = array( $type, 'degraded', 12 );
			}
		}
		return $cases;
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
		$run_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-child-task-1/';
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
	 * A native download sweeps orphan mutexes left by earlier runs in the site directory.
	 */
	public function test_native_completion_sweeps_aged_orphan_download_mutex() {
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$this->record_base_snapshot( $site_id );
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
		$dir     = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		wp_mkdir_p( $dir );
		$orphan = $dir . 'previous.zip.downloading.reclaim';
		file_put_contents( $orphan, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		touch( $orphan, time() - 2 * HOUR_IN_SECONDS );
		try {
			$this->assertFileExists( $orphan );
			$this->assertFalse( $this->run_cycle( $site_id, $task )['result'], 'Cycle one creates the child task.' );
			$cycle = $this->run_cycle( $site_id, $task );
			$this->assertNull( $cycle['exception'] );
			$this->assertIsArray( $cycle['result'] );
			$this->assertFileDoesNotExist( $orphan );
		} finally {
			wp_delete_file( $orphan );
		}
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
	 * Reconciliation needs the full/files run's own walker parameters and manifest.
	 *
	 * @dataProvider native_file_backup_types
	 * @param string $type Scheduled backup type.
	 */
	public function test_full_task_finish_records_manifest_and_walk( $type ) {
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$walk = array(
			'excludes'     => array( 'private', 'wp-content/cache' ),
			'excludenonwp' => 1,
			'excludezip'   => 0,
		);

		$artifacts            = $this->arrange_remote_artifacts( true );
		$artifacts            = array( $artifacts[2], $artifacts[0] );
		$artifacts[1]['walk'] = $walk;
		$this->mock_artifact_downloads();
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'child-full-manifest',
					'phase'   => 'queued',
				),
				'backup_task_run'    => array(
					'task_id'   => 'child-full-manifest',
					'phase'     => 'completed',
					'file'      => 'backup-base.zip',
					'size'      => 400,
					'artifacts' => $artifacts,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
			)
		);
		$postprocessed = array();
		$feedback_runs = array();
		add_action(
			'mainwp_postprocess_backup_site',
			function ( ...$args ) use ( &$postprocessed ) {
				$postprocessed[] = $args;
			},
			10,
			7
		);
		add_filter(
			'mainwp_postprocess_backup_sites_feedback',
			function ( $feedback, $unique ) use ( &$feedback_runs ) {
				$feedback_runs[]       = $unique;
				$feedback['delivered'] = true;
				return $feedback;
			},
			10,
			2
		);
		$events = array();
		add_action(
			'mainwp_native_backup_completed',
			function ( $site, $task_id, $success, $details ) use ( &$events ) {
				$events[] = array( $success, $details );
			},
			10,
			4
		);
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website, 11, array( 'type' => $type ) );
		$create  = $this->run_cycle( $site_id, $task );
		$this->assertNull( $create['exception'] );
		$this->assertSame( '1', $this->last_call_params( 'backup_task_create' )['emit_manifest'] );
		$old_backup = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'full-obsolete.zip';
		wp_mkdir_p( dirname( $old_backup ) );
		file_put_contents( $old_backup, $this->remote_files['backup-base.zip'] );
		update_option( 'mainwp_backupsOnServer', 1 );
		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertNull( $cycle['exception'] );
		$this->assertIsArray( $cycle['result'] );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, 11 );
		$this->assertNotNull( $snapshot );
		$this->assertSame( $type, $snapshot->type );
		$this->assertNull( $snapshot->parent_id );
		$run_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-child-full-manifest/';
		$this->assertSame( $run_dir . 'files.list', $snapshot->manifest_path );
		$this->assertCount( 2, $snapshot->archives );
		$this->assertSame(
			array(
				'file' => 'files.list',
				'size' => 40,
				'role' => 'manifest',
				'path' => $run_dir . 'files.list',
				'walk' => $walk,
			),
			$snapshot->archives[1]
		);
		$this->assertSame( $this->remote_files['files.list'], file_get_contents( $snapshot->manifest_path ) );
		$this->assertSame( array( 'backup-base.zip' ), array_column( $this->all_call_params( 'delete_backup' ), 'del' ) );
		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0][0] );
		$this->assertSame( 'backup-base.zip', $events[0][1]['file'] );
		$this->assertCount( 1, $postprocessed );
		$this->assertNotEmpty( $postprocessed[0][0] );
		$this->assertSame( $snapshot->archives[0]['path'], $postprocessed[0][0] );
		$this->assertFileExists( $postprocessed[0][0] );
		$this->assertSame( 'full', $postprocessed[0][1] );
		$this->assertSame( 'backups', $postprocessed[0][2] );
		$this->assertStringStartsWith( 'full-', basename( $postprocessed[0][0] ) );
		$this->assertNotEmpty( $postprocessed[0][3] );
		$this->assertSame( $site_id, (int) $postprocessed[0][4]->id );
		$this->assertSame( 11, (int) $postprocessed[0][5] );
		$this->assertSame( $task->last_run, $postprocessed[0][6] );
		$this->assertSame( array( $task->last_run ), $feedback_runs );
		$this->assertTrue( $cycle['result']['delivered'] );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( 11, $site_id );
		$this->assertSame( 1, (int) $progress->removedFiles );
		$this->assertFileDoesNotExist( $old_backup );
		$this->assertSame( $postprocessed[0][0], $progress->downloadedFULL );
	}

	/**
	 * Both file-producing task types need reconciliation metadata.
	 *
	 * @return array
	 */
	public static function native_file_backup_types() {
		return array(
			'full'  => array( 'full' ),
			'files' => array( 'files' ),
		);
	}

	/**
	 * Refused creates retry conservatively without escalating to a full backup.
	 *
	 * @dataProvider incremental_create_refusals
	 * @param string $error_code Child refusal code.
	 */
	public function test_refused_creates_retry_plain_push_push( $error_code ) {
		$site_id          = $this->create_test_site();
		$task             = $this->create_real_task_row( $site_id );
		$base_id          = $this->record_base_snapshot( $site_id, (int) $task->id );
		$creates          = 0;
		$refused_state    = array();
		self::$fixed_time = \time();
		$started          = self::$fixed_time;
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => function () use ( $site_id, $task, $error_code, &$creates, &$refused_state ) {
					++$creates;
					if ( 2 < $creates ) {
						return array( 'task_id' => 'accepted-after-refusals' );
					}
					$progress              = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
					$refused_state          = json_decode( $progress->last_error, true );
					$refused_state['other'] = 'preserved';
					MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'last_error' => wp_json_encode( $refused_state ) ) );
					return array(
						'error'      => 'Child refused the create.',
						'error_code' => $error_code,
						'extra'      => MainWP_Backup_Executor::EXTRA_FOREIGN_ALREADY_RUNNING,
					);
				},
			)
		);
		try {
			for ( $cycle_number = 1; $cycle_number <= 3; ++$cycle_number ) {
				$cycle = $this->run_cycle( $site_id, $task );
				$this->assertNull( $cycle['exception'] );
				$this->assertFalse( $cycle['result'] );
				$params = $this->last_call_params( 'backup_task_create' );
				$this->assertSame( 'incremental', $params['type'] );
				$this->assertSame( (string) $base_id, $params['parent_snapshot_id'] );
				if ( 1 === $cycle_number ) {
					$this->assertArrayNotHasKey( 'parent_manifest', $params );
					$this->assertArrayHasKey( 'parent_child_task_id', $params );
				} else {
					$this->assertSame( base64_encode( file_get_contents( $this->manifest_fixture ) ), $params['parent_manifest'] );
				}
				if ( 3 > $cycle_number ) {
					$this->assertSame( 1 === $cycle_number ? 'plain' : 'push', $refused_state['incremental_sent'] );
					unset( $refused_state['incremental_sent'], $refused_state['run'] );
					$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
					$this->assertSame( 0, (int) $progress->dtsFetched );
					$this->assertSame( '', $progress->child_backup_task_id );
					$this->assertSame( $started, (int) $progress->foreign_refused_since );
					$this->assertSame( $refused_state, json_decode( $progress->last_error, true ) );
					$this->assertStringStartsWith( 'pending:', $refused_state['manifest_pending'] );
					$this->assertSame( $refused_state['manifest_pending'], MainWP_DB::instance()->get_website_option( $site_id, 'native_manifest_snapshot' ) );
				}
				$fresh_task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
				$this->assertSame( '', (string) $fresh_task->backup_notices, 'Refusals must not record a base-missing notice.' );
				self::$fixed_time += 10;
			}
			$this->assertCount( 3, $this->all_call_params( 'backup_task_create' ) );
			$this->assertSame( 'accepted-after-refusals', MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
		} finally {
			self::$fixed_time = null;
		}
	}

	/**
	 * Both refusal codes mean that the child did not accept the create.
	 *
	 * @return array
	 */
	public static function incremental_create_refusals() {
		return array(
			'foreign child task' => array( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ),
			'mutex write failed' => array( MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED ),
		);
	}

	/** A losing driver's reset cannot change a replacement driver's state. */
	public function test_refusal_reset_preserves_replacement_driver() {
		$site_id = $this->create_test_site();
		$state   = array(
			'incremental_sent' => 'push',
			'run'              => 123,
			'manifest_pending' => 'pending:11:replacement',
			'other'            => 'preserved',
		);
		MainWP_DB_Backup::instance()->add_backup_task_progress( 11, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			11,
			$site_id,
			array(
				'dtsFetched'           => 123,
				'child_backup_task_id' => '__creating_replacement',
				'last_error'           => wp_json_encode( $state ),
			)
		);
		$reset = new \ReflectionMethod( MainWP_Backup_Executor::class, 'reset_create_window_if_mine' );
		$reset->invoke( null, 11, $site_id, '__creating_loser' );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( 11, $site_id );
		$this->assertSame( 123, (int) $progress->dtsFetched );
		$this->assertSame( '__creating_replacement', $progress->child_backup_task_id );
		$this->assertSame( $state, json_decode( $progress->last_error, true ) );
	}

	/** Run refusals retain the accepted task and its send marker for missing-base recovery. */
	public function test_run_refusal_preserves_incremental_send_marker() {
		$site_id = $this->create_test_site();
		$this->record_base_snapshot( $site_id );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website );
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array( 'task_id' => 'accepted-task' ),
				'backup_task_run'    => array(
					'error'      => 'Another backup holds the child lock.',
					'error_code' => MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING,
				),
			)
		);
		self::$fixed_time = \time();
		try {
			$cycle = $this->run_cycle( $site_id, $task );
			$this->assertNull( $cycle['exception'] );
			$accepted = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( 'plain', json_decode( $accepted->last_error, true )['incremental_sent'] );
			for ( $refusal = 0; $refusal < 2; ++$refusal ) {
				self::$fixed_time += 10;
				$cycle             = $this->run_cycle( $site_id, $task );
				$this->assertNull( $cycle['exception'] );
				$this->assertFalse( $cycle['result'] );
				$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
				$this->assertSame( 'accepted-task', $progress->child_backup_task_id );
				$this->assertSame( $accepted->dtsFetched, $progress->dtsFetched );
				$this->assertSame( $accepted->last_error, $progress->last_error );
			}
			$this->assertCount( 1, $this->all_call_params( 'backup_task_create' ) );
			$this->assertCount( 2, $this->all_call_params( 'backup_task_run' ) );
		} finally {
			self::$fixed_time = null;
		}
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
				'mtoken'         => 'tok0123456789abc', // gitleaks:allow -- fixed manifest-transfer token for the mock child, never a credential.
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
	 * Real sizes include every remaining role and preserve the fixed reserve.
	 *
	 * @dataProvider download_disk_cases
	 * @param string $type Backup type.
	 * @param mixed  $free Measured free bytes.
	 * @param mixed  $size Reported archive size, or null to omit it.
	 * @param int    $partial Existing archive bytes.
	 * @param bool   $refused Whether the transfer must be refused.
	 * @param bool   $manifest Whether the full run also downloads a manifest.
	 */
	public function test_download_disk_guard( $type, $free, $size, $partial, $refused, $manifest = false ) {
		list( $site_id, $task ) = $this->arrange_manual_task( 'incremental' === $type );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'type' => $type ) );
		$task                           = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$body                           = "PK\x03\x04" . str_repeat( 'z', 396 );
		$this->remote_files['real.zip'] = $body;
		$payload                        = array(
			'phase' => 'completed',
			'file'  => 'real.zip',
		);
		if ( null !== $size ) {
			$payload['size'] = $size;
		}
		if ( 'incremental' === $type ) {
			$payload['artifacts'] = $this->arrange_remote_artifacts();
			if ( null === $size ) {
				unset( $payload['artifacts'][0]['size'] );
			}
		} elseif ( $manifest ) {
			$payload['artifacts'] = array(
				array(
					'file'   => 'files.list',
					'role'   => 'manifest',
					'size'   => 40,
					'sha256' => hash( 'sha256', $this->remote_files['files.list'] ),
				),
			);
		}
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $result, $website, $what ) use ( $payload ) {
				return 'backup_task_run' === $what ? $payload : $result;
			},
			20,
			3
		);
		$this->assertNull( $this->run_cycle( $site_id, $task )['exception'] );
		$dir   = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		$local = 'incremental' === $type ? $dir . 'snapshot-manual-' . $task->id . '/uploads.part001.zip' : $dir . 'resume.zip';
		if ( $partial > 0 ) {
			wp_mkdir_p( dirname( $local ) );
			file_put_contents( $local, substr( $body, 0, $partial ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- partial fixture.
			if ( 'incremental' !== $type ) {
				MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'downloadedFULL' => $local ) );
			}
		}
		$downloads = array();
		$capture   = static function ( $pre, $url, $file ) use ( &$downloads ) {
			$downloads[] = $file;
			return $pre;
		};
		add_filter( 'mainwp_download_to_file_pre', $capture, 1, 3 );
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = $free;
		try {
			$finish = $this->run_cycle( $site_id, $task );
		} finally {
			MainWP_Backup_TestRestore_Sandbox_Test::$free_space = null;
			remove_filter( 'mainwp_download_to_file_pre', $capture, 1 );
		}
		if ( $refused ) {
			$this->assertInstanceOf( MainWP_Exception::class, $finish['exception'] );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $finish['exception']->get_message_error_code() );
			$this->assertStringContainsString( 'Dashboard server', $finish['exception']->getMessage() );
			$this->assertSame( array(), $downloads, 'Refuse the entire set before its first transfer.' );
			$this->assertFileDoesNotExist( $local );
		} else {
			$this->assertNull( $finish['exception'] );
			$this->assertIsArray( $finish['result'] );
			$this->assertNotEmpty( $downloads );
		}
	}

	/**
	 * Capacity boundaries and unavailable readings.
	 *
	 * @return array
	 */
	public static function download_disk_cases() {
		$margin = 100 * MB_IN_BYTES;
		return array(
			'full refused'          => array( 'full', $margin + 399, 400, 0, true ),
			'files refused'         => array( 'files', $margin + 399, 400, 0, true ),
			'db refused'            => array( 'db', $margin + 399, 400, 0, true ),
			'full fits exactly'     => array( 'full', $margin + 400, 400, 0, false ),
			'roles sum refused'     => array( 'incremental', $margin + 300, 372, 0, true ),
			'roles size missing'    => array( 'incremental', 1, null, 0, true ),
			'roles resume fits'     => array( 'incremental', $margin + 272, 372, 100, false ),
			'roles resume cleans'   => array( 'incremental', $margin + 271, 372, 100, true ),
			'roles sum fits'        => array( 'incremental', $margin + 372, 372, 0, false ),
			'full plus manifest'    => array( 'full', $margin + 420, 400, 0, true, true ),
			'free unavailable'      => array( 'full', false, 400, 0, false ),
			'size missing'          => array( 'full', 1, null, 0, false ),
			'resume remaining fits' => array( 'full', $margin + 200, 400, 200, false ),
			'resume refused cleans' => array( 'full', $margin + 199, 400, 200, true ),
		);
	}

	/**
	 * Re-entry may revisit archives already owned by a snapshot.
	 *
	 * @dataProvider refusal_cleanup_cases
	 * @param bool   $recorded Whether the archives belong to a recorded snapshot.
	 * @param string $condition Part validity.
	 */
	public function test_download_disk_refusal_preserves_credited_and_protected_archives( $recorded, $condition ) {
		list( $site_id, $task, $base_id ) = $this->arrange_manual_task();
		$this->assertNull( $this->run_cycle( $site_id, $task )['exception'] );
		$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-manual-' . $task->id . '/';
		wp_mkdir_p( $dir );
		$db      = $dir . 'dbBackup-1.zip';
		$part    = $dir . 'uploads.part001.zip';
		$partial = $dir . 'files.list';
		$body    = $this->remote_files['uploads.part001.zip'];
		if ( 'invalid' === $condition ) {
			$body = str_repeat( 'x', 200 );
		} elseif ( 'oversized' === $condition ) {
			$body .= 'x';
		}
		file_put_contents( $db, $this->remote_files['dbBackup-1.zip'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- completed archive fixture.
		file_put_contents( $part, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- archive validity fixture.
		file_put_contents( $partial, str_repeat( 'm', 10 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- partial fixture.
		if ( $recorded ) {
			$this->assertGreaterThan(
				0,
				MainWP_Backup_Snapshots::instance()->record_snapshot(
					array(
						'task_id'   => $task->id,
						'site_id'   => $site_id,
						'type'      => 'incremental',
						'parent_id' => $base_id,
						'archives'  => array(
							array(
								'path' => $db,
								'role' => 'db',
								'size' => 120,
							),
							array(
								'path' => $part,
								'role' => 'part',
								'size' => 200,
							),
						),
					)
				)
			);
		}
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = 100 * MB_IN_BYTES + 41;
		try {
			$finish = $this->run_cycle( $site_id, $task );
		} finally {
			MainWP_Backup_TestRestore_Sandbox_Test::$free_space = null;
		}
		$this->assertInstanceOf( MainWP_Exception::class, $finish['exception'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $finish['exception']->get_message_error_code() );
		$this->assertFileExists( $db, 'A validated complete archive must survive refusal cleanup.' );
		$this->assertSame( $this->remote_files['dbBackup-1.zip'], file_get_contents( $db ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture assertion.
		if ( $recorded || 'valid' === $condition ) {
			$this->assertFileExists( $part );
			$this->assertSame( $body, file_get_contents( $part ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture assertion.
		} else {
			$this->assertFileDoesNotExist( $part );
		}
		$this->assertFileDoesNotExist( $partial, 'A genuine partial must still be removed.' );
	}

	/**
	 * Snapshot ownership protects even damaged files from refusal cleanup.
	 *
	 * @return array
	 */
	public static function refusal_cleanup_cases() {
		return array(
			'intact unrecorded'    => array( false, 'valid' ),
			'intact recorded'      => array( true, 'valid' ),
			'invalid recorded'     => array( true, 'invalid' ),
			'oversized recorded'   => array( true, 'oversized' ),
			'invalid unrecorded'   => array( false, 'invalid' ),
			'oversized unrecorded' => array( false, 'oversized' ),
		);
	}

	/**
	 * A missing best-effort manifest must not prevent recording intact archives.
	 *
	 * @dataProvider manifest_only_types
	 * @param string $type Backup type.
	 */
	public function test_download_disk_guard_allows_manifest_only_reentry( $type ) {
		list( $site_id, $task ) = $this->arrange_manual_task( 'incremental' === $type );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'type' => $type ) );
		$task      = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$artifacts = $this->arrange_remote_artifacts( 'incremental' !== $type );
		$payload   = array(
			'phase'     => 'completed',
			'artifacts' => $artifacts,
		);
		if ( 'incremental' !== $type ) {
			$payload['file'] = 'backup-base.zip';
			$payload['size'] = 400;
		}
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $result, $website, $what ) use ( $payload ) {
				return 'backup_task_run' === $what ? $payload : $result;
			},
			20,
			3
		);
		$this->assertNull( $this->run_cycle( $site_id, $task )['exception'] );
		$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-manual-' . $task->id . '/';
		wp_mkdir_p( $dir );
		foreach ( $artifacts as $artifact ) {
			if ( 'manifest' !== $artifact['role'] ) {
				file_put_contents( $dir . $artifact['file'], $this->remote_files[ $artifact['file'] ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- completed artifact fixture.
			}
		}
		if ( 'incremental' !== $type ) {
			MainWP_DB_Backup::instance()->update_backup_task_progress(
				$task->id,
				$site_id,
				array(
					'downloadedFULL'         => $dir . 'backup-base.zip',
					'downloadedFULLComplete' => 1,
				)
			);
		}
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = 1;
		try {
			$finish = $this->run_cycle( $site_id, $task );
		} finally {
			MainWP_Backup_TestRestore_Sandbox_Test::$free_space = null;
		}
		$this->assertNull( $finish['exception'] );
		$this->assertIsArray( $finish['result'] );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task->id );
		$this->assertNotNull( $snapshot );
		$this->assertSame( $type, $snapshot->type );
		foreach ( $artifacts as $artifact ) {
			$this->assertFileExists( $dir . $artifact['file'] );
		}
	}

	/**
	 * Each snapshot consumer can complete without a manifest.
	 *
	 * @return array
	 */
	public static function manifest_only_types() {
		return array(
			'full'        => array( 'full' ),
			'files'       => array( 'files' ),
			'incremental' => array( 'incremental' ),
		);
	}

	/**
	 * An advisory size cannot exempt a different artifact from its capacity check.
	 *
	 * @dataProvider unknown_artifact_sizes
	 * @param mixed $size Unusable size reading.
	 */
	public function test_download_disk_guard_checks_known_sizes_and_preserves_unknown_paths( $size ) {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$dir     = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		$unknown = $dir . 'unknown.zip';
		$known   = $dir . 'known.zip';
		file_put_contents( $unknown, str_repeat( 'u', 20 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- unknown-size fixture.
		file_put_contents( $known, 'PK' . str_repeat( 'k', 8 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- partial fixture.
		$guard = new \ReflectionMethod( MainWP_Backup_Executor::class, 'validate_download_disk_space' );
		$guard->setAccessible( true );
		$error = null;
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = 100 * MB_IN_BYTES + 89;
		try {
			$guard->invoke(
				null,
				$website,
				$dir,
				array(
					array(
						'size' => $size,
						'path' => $unknown,
						'role' => 'part',
					),
					array(
						'size' => 100,
						'path' => $known,
						'role' => 'part',
					),
				)
			);
		} catch ( MainWP_Exception $e ) {
			$error = $e;
		} finally {
			MainWP_Backup_TestRestore_Sandbox_Test::$free_space = null;
		}
		$this->assertInstanceOf( MainWP_Exception::class, $error );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $error->get_message_error_code() );
		$this->assertFileExists( $unknown );
		$this->assertSame( str_repeat( 'u', 20 ), file_get_contents( $unknown ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture assertion.
		$this->assertFileDoesNotExist( $known );
	}

	/**
	 * Advisory readings must leave known artifact sizes enforceable.
	 *
	 * @return array
	 */
	public static function unknown_artifact_sizes() {
		return array(
			'missing'      => array( false ),
			'null'         => array( null ),
			'invalid'      => array( 'unknown' ),
			'infinite'     => array( INF ),
			'not a number' => array( NAN ),
			'negative'     => array( -1 ),
		);
	}

	/** Terminal refusals use the failed-download history, diagnostics and Child prune. */
	public function test_download_disk_refusal_records_failure_and_prunes_child() {
		list( $site_id, $task ) = $this->arrange_manual_task( false );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'type' => 'full' ) );
		$responses = array(
			'backup_task_run'   => array(
				'phase' => 'completed',
				'file'  => 'backup-base.zip',
				'size'  => 400,
			),
			'backup_task_prune' => array(
				'result'  => 'ok',
				'pruned'  => 1,
				'active'  => 0,
				'missing' => 0,
			),
		);
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $result, $website, $what ) use ( $responses ) {
				return $responses[ $what ] ?? $result;
			},
			20,
			3
		);
		$polls = array();
		$marks = array();
		add_action(
			'mainwp_backup_diag_poll',
			static function ( $payload, $elapsed, $context ) use ( &$polls ) {
				$polls[] = array( $payload, $context );
			},
			10,
			3
		);
		add_action(
			'mainwp_backup_diag_site_mark',
			static function ( $task_id, $site, $outcome, $detail ) use ( &$marks ) {
				$marks[] = array( $outcome, $detail );
			},
			10,
			4
		);
		$downloads = array();
		add_filter(
			'mainwp_download_to_file_pre',
			static function ( $pre, $url, $file ) use ( &$downloads ) {
				$downloads[] = $file;
				return $pre;
			},
			1,
			3
		);
		$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = 100 * MB_IN_BYTES + 399;
		try {
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
		} finally {
			MainWP_Backup_TestRestore_Sandbox_Test::$free_space = null;
		}
		$this->assertSame( 'failed', $status['status'] );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $progress->fetchResult['error_code'] ?? '' );
		$this->assertStringContainsString( 'Free up space on the Dashboard server and run the backup again.', $progress->last_error );
		$this->assertSame( array(), $downloads );
		$this->assertSame(
			array(
				array(
					'task_ids' => wp_json_encode( array( 'manual-' . $task->id ) ),
					'sweep'    => 0,
				),
			),
			$this->all_call_params( 'backup_task_prune' )
		);
		$this->assertSame( 'error', end( $marks )[0] );
		$this->assertStringContainsString( MainWP_Backup_Error_Codes::ERR_DISK_FULL, end( $marks )[1] );
		$failed_polls = array_values(
			array_filter(
				$polls,
				static function ( $poll ) {
					return 'download' === ( $poll[1]['op'] ?? '' ) && MainWP_Backup_Error_Codes::ERR_DISK_FULL === ( $poll[0]['error_code'] ?? '' );
				}
			)
		);
		$this->assertCount( 1, $failed_polls );
		$this->assertFalse( $failed_polls[0][0]['sent'] );
		global $wpdb;
		$history = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ) . ' WHERE task_id = %d AND site_id = %d ORDER BY id DESC LIMIT 1', $task->id, $site_id ) ); // phpcs:ignore WordPress.DB -- history evidence.
		$this->assertSame( 'failed', $history->outcome );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $history->error_code );
		$this->assertSame( array(), glob( \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . '*.zip' ) );
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
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
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
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
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
		$status                 = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
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
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
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
	 * Exercise both manual entry points after a successful claim is superseded.
	 *
	 * @return array Request modes.
	 */
	public static function superseded_claim_requests() {
		return array(
			'initiate'    => array( true ),
			'queued poll' => array( false ),
		);
	}

	/**
	 * A zero-grace claim after our UPDATE must not become our run identity.
	 *
	 * @dataProvider superseded_claim_requests
	 * @param bool $initiate Whether this is the first request.
	 * @return void
	 */
	public function test_successful_manual_claim_does_not_adopt_foreign_run( $initiate ) {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$owned                  = 0;
		$foreign                = 0;
		$interleave             = function ( $query ) use ( &$interleave, &$owned, &$foreign, $wpdb, $task ) {
			if ( ! str_starts_with( $query, 'UPDATE ' . $wpdb->prefix . 'mainwp_wp_backup SET last_run = GREATEST(' ) ) {
				return $query;
			}
			remove_filter( 'query', $interleave );
			$this->assertSame( 1, $wpdb->query( $query ), 'The manual claim must succeed before the competing claim.' );
			$owned = (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run;
			// Cross the timestamp boundary so zero-grace recovery advances the still-open run.
			sleep( 1 );
			$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( $task->id, 0, $foreign ) );
			$this->assertGreaterThan( $owned, $foreign );
			// Return the successful UPDATE's affected-row count after the interleaving.
			return 'SELECT 1';
		};
		add_filter( 'query', $interleave );
		try {
			$result = $initiate
				? MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id )
				: MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, 0, time() );
			$this->assertGreaterThan( 0, $owned );
			$this->assertSame( 'queued', $result['status'], 'A superseded claim must leave either request queued.' );
			$this->assertSame( 0, $result['started'] );
			$this->assertGreaterThan( 0, $result['click'] );
			$this->assertArrayNotHasKey( 'error', $result );
			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertSame( $foreign, (int) $after->last_run );
			$this->assertSame( 0, (int) $after->last_run_manually, 'The foreign run must not be stamped as manual.' );
			$this->assertSame( array(), $this->child_calls, 'The superseded caller must not drive the foreign run.' );
		} finally {
			remove_filter( 'query', $interleave );
		}
	}

	/**
	 * Deletion between the claim and row refresh returns a controlled error.
	 *
	 * @return void
	 */
	public function test_manual_claim_handles_task_deleted_after_update() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$interleave             = function ( $query ) use ( &$interleave, $wpdb, $task ) {
			if ( str_starts_with( $query, 'UPDATE ' . $wpdb->prefix . 'mainwp_wp_backup SET last_run = GREATEST(' ) ) {
				remove_filter( 'query', $interleave );
				$this->assertSame( 1, $wpdb->query( $query ) );
				$wpdb->delete( $wpdb->prefix . 'mainwp_wp_backup', array( 'id' => $task->id ) );
				return 'SELECT 1';
			}
			return $query;
		};
		add_filter( 'query', $interleave );
		try {
			$result = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertArrayHasKey( 'error', $result );
			$this->assertSame( array(), $this->child_calls );
		} finally {
			remove_filter( 'query', $interleave );
		}
	}

	/**
	 * Zero settle time must not let a stale manual caller recover an open run.
	 *
	 * @return void
	 */
	public function test_zero_settle_manual_claim_refuses_foreign_open_run() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'        => time() - 10,
				'completed'       => 0,
				'completed_sites' => '[123]',
			)
		);
		update_option( 'mainwp_backup_manual_settle_seconds', 0 );
		try {
			$claim  = new \ReflectionMethod( MainWP_Manage_Backups_Handler::class, 'claim_manual_task_run' );
			$result = $claim->invokeArgs( null, array( &$task, time(), (int) $task->last_run ) );
			$this->assertSame( 'queued', $result['status'] );
			$this->assertSame( 0, $result['started'] );
			$this->assertSame( '[123]', $task->completed_sites );
		} finally {
			delete_option( 'mainwp_backup_manual_settle_seconds' );
		}
	}

	/**
	 * Cleanup Errors cannot turn a recorded snapshot into a failure event.
	 *
	 * @return void
	 */
	public function test_incremental_cleanup_error_emits_success_once() {
		list( $site_id, $task, $base_id ) = $this->arrange_manual_task();
		$events                           = array();
		$cleanup_snapshot                 = 0;
		$fail                             = function ( $result, $website, $what ) use ( &$cleanup_snapshot, $task ) {
			if ( 'delete_backup' === $what ) {
				$latest           = MainWP_Backup_Snapshots::instance()->get_latest_complete( (int) $website->id, (int) $task->id );
				$cleanup_snapshot = (int) $latest->id;
				throw new \Error( 'Remote cleanup broke.' );
			}
			return $result;
		};
		$capture                          = static function ( $site, $child, $success ) use ( &$events ) {
			$events[] = $success;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $fail, 20, 3 );
		add_action( 'mainwp_native_backup_completed', $capture, 10, 3 );
		try {
			$this->run_cycle( $site_id, $task );
			$cycle = $this->run_cycle( $site_id, $task );
			$this->assertNull( $cycle['exception'] );
			$this->assertGreaterThan( $base_id, $cleanup_snapshot, 'Cleanup must run after the new snapshot is recorded.' );
			$this->assertSame( array( true ), $events );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $fail, 20 );
			remove_action( 'mainwp_native_backup_completed', $capture, 10 );
		}
	}

	/**
	 * A fully deleted selection refuses Run Now and records a scheduled failure.
	 *
	 * @return void
	 */
	public function test_fully_stale_task_cannot_close_without_errors() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$stale_id               = 999999;
		$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $stale_id ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'sites'            => (string) $stale_id,
				'authorized_sites' => (string) $stale_id,
			)
		);
		$this->assertArrayHasKey( 'error', MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id ) );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertFalse( MainWP_Manage_Backups_Handler::execute_backup_task( $task ) );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( 'No existing sites remain', $after->backup_errors );
		$this->assertStringContainsString( 'Site ' . $stale_id . ' no longer exists', $after->backup_notices );
		$this->assertSame( array(), $this->child_calls );
	}

	/**
	 * Scheduled runs keep a deduplicated notice for each deleted selection.
	 *
	 * @return void
	 */
	public function test_scheduled_partial_stale_task_records_notice() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$stale_id               = 999999;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'sites'            => $site_id . ',' . $stale_id,
				'authorized_sites' => $site_id . ',' . $stale_id,
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false );
		MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, substr_count( $after->backup_notices, 'Site ' . $stale_id . ' no longer exists' ) );
		$this->assertSame( '', $after->backup_errors );
		$this->assertGreaterThanOrEqual( (int) $after->last_run, (int) $after->completed );
	}

	/**
	 * Distinguish the previous run's ownership from the replacing claim's lane.
	 *
	 * @return array Previous run types.
	 */
	public static function previous_run_types() {
		return array(
			'manual'    => array( true ),
			'scheduled' => array( false ),
		);
	}

	/**
	 * Pollers may own either run type when a scheduled claim replaces it.
	 *
	 * @dataProvider previous_run_types
	 * @param bool $manual Whether the previous run was manual.
	 * @return void
	 */
	public function test_scheduled_claim_preserves_previous_closed_run( $manual ) {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$previous               = time() - 200;
		$state                  = array(
			'last_run'          => $previous,
			'completed'         => $previous + 10,
			'last_run_manually' => $manual ? $previous : $previous - 100,
		);
		$key                    = 'mainwp_backup_run_' . $task->id . '_' . $previous;
		delete_transient( $key );
		try {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, $state );
			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			MainWP_Manage_Backups_Handler::execute_backup_task( $task );
			$saved = get_transient( $key );
			$this->assertIsArray( $saved, 'Cron must preserve every closed run for its poller.' );
			$this->assertSame( $previous, (int) $saved['task']->last_run );
		} finally {
			delete_transient( $key );
		}
	}

	/**
	 * A late error is carried by the closed-run snapshot, not dropped with it.
	 *
	 * @return void
	 */
	public function test_closed_run_snapshot_carries_late_errors() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$closed_last_run        = time() - 200;
		$key                    = 'mainwp_backup_run_' . $task->id . '_' . $closed_last_run;
		foreach ( array( false, true ) as $late_write ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$task->id,
				array(
					'last_run'      => $closed_last_run,
					'completed'     => $closed_last_run + 10,
					'backup_errors' => '',
				)
			);
			$task   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$select = 'SELECT * FROM ' . $wpdb->prefix . 'mainwp_wp_backup WHERE id= ' . $task->id;
			$reads  = 0;
			$spy    = function ( $query ) use ( &$spy, &$reads, $select, $late_write, $task ) {
				if ( trim( $query ) === $select ) {
					++$reads;
					// The second task read follows the per-site progress and snapshot loop.
					if ( 2 === $reads ) {
						remove_filter( 'query', $spy );
						if ( $late_write ) {
							MainWP_DB_Backup::instance()->update_backup_task_with_values(
								$task->id,
								array( 'backup_errors' => 'Lease-lost driver flushed a late error.' )
							);
						}
					}
				}
				return $query;
			};
			delete_transient( $key );
			add_filter( 'query', $spy );
			try {
				MainWP_Manage_Backups_Handler::execute_backup_task( $task );
				$this->assertSame( 2, $reads, 'The spy must reach the closed-run snapshot re-read.' );
				$saved = get_transient( $key );
				$this->assertIsArray( $saved, 'The closed run must be saved whether or not a late error landed.' );
				$this->assertSame( $closed_last_run, (int) $saved['task']->last_run );
				if ( $late_write ) {
					// The re-read row is what gets saved, so the poller reads the
					// error instead of run_not_found.
					$this->assertSame( 'Lease-lost driver flushed a late error.', $saved['task']->backup_errors );
				} else {
					$this->assertSame( $task->backup_errors, $saved['task']->backup_errors );
				}
			} finally {
				remove_filter( 'query', $spy );
				delete_transient( $key );
			}
		}
	}

	/**
	 * A manual run's result survives a scheduled claim before its next poll.
	 *
	 * @return void
	 */
	public function test_closed_manual_run_remains_pollable_after_scheduled_claim() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$this->assertSame( 'running', $start['status'] );

		// The initiate only opens the run; the poll's continuation tick is what drives it
		// to completion, so the manual run has to be closed before a claim can replace it.
		$poll = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
		$this->assertSame( 'completed', $poll['status'] );

		$task     = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task->id );
		$this->assertGreaterThanOrEqual( $start['started'], (int) $task->completed );
		$this->assertNotNull( $snapshot );
		$key = 'mainwp_backup_run_' . $task->id . '_' . $start['started'];
		try {
			MainWP_Manage_Backups_Handler::execute_backup_task( $task );
			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertGreaterThan( $start['started'], (int) $after->last_run );
			$result = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
			$this->assertArrayNotHasKey( 'error', $result );
			$this->assertSame( 'completed', $result['status'] );
			$this->assertSame( 'closed', $result['run_state'] );
			$this->assertSame( $start['started'], $result['started'] );
			$this->assertSame( (int) $snapshot->id, $result['sites'][0]['snapshot']['id'] );
		} finally {
			delete_transient( $key );
		}
	}

	/**
	 * An open run with progress must get a new identity even within one second.
	 *
	 * @return void
	 */
	public function test_same_second_scheduled_claim_advances_open_run_identity() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		// Keep the logical run stamp ahead of the clock to avoid timing-dependent assertions.
		$previous = time() + 10;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'        => $previous,
				'completed'       => $previous - 1,
				'completed_sites' => wp_json_encode( array( $site_id => true ) ),
			)
		);
		$started = 0;
		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( $task->id, 0, $started ) );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $previous + 1, $started );
		$this->assertSame( $started, (int) $after->last_run );
		$this->assertSame( '[]', $after->completed_sites );
		MainWP_DB_Backup::instance()->update_backup_run_manually( $task->id, $started );
		$this->assertSame( $started, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run_manually );
	}

	/**
	 * Poll resolution covers live, saved, missing and never-stamped results.
	 *
	 * @return array Poll modes.
	 */
	public static function read_only_poll_modes() {
		return array(
			'live'    => array( 'live' ),
			'saved'   => array( 'saved' ),
			'missing' => array( 'missing' ),
			'legacy'  => array( 'legacy' ),
		);
	}

	/**
	 * Poll guards and result rendering must not write notices or snapshots.
	 *
	 * @dataProvider read_only_poll_modes
	 * @param string $mode Poll mode.
	 * @return void
	 */
	public function test_status_poll_resolution_does_not_write_task_row( $mode ) {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$drifted                = $this->create_test_site( array( 'userid' => $task->userid ) );
		$started                = time() - 100;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'         => $started,
				'completed'        => $started + 10,
				'sites'            => $site_id . ',' . $drifted . ',999999',
				'authorized_sites' => 'legacy' === $mode ? null : (string) $site_id,
				'backup_notices'   => '',
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$key  = 'mainwp_backup_run_' . $task->id . '_' . ( $started - 100 );
		delete_transient( $key );
		if ( 'saved' === $mode ) {
			$saved_task           = clone $task;
			$saved_task->last_run = $started - 100;
			$saved_task->sites    = $site_id . ',' . $drifted . ',999998';
			set_transient(
				$key,
				array(
					'task'      => $saved_task,
					'progress'  => array(),
					'snapshots' => array(),
				),
				DAY_IN_SECONDS
			);
		}
		$writes = array();
		$spy    = static function ( $query ) use ( &$writes, $wpdb ) {
			if ( preg_match( '/^UPDATE\s+`?' . preg_quote( $wpdb->prefix . 'mainwp_wp_backup', '/' ) . '`?\s/i', $query ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		try {
			$poll_started = in_array( $mode, array( 'saved', 'missing' ), true ) ? $started - 100 : $started;
			$result       = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $poll_started );
			$this->assertSame( array(), $writes, 'Status resolution must not update the live task, including before saved-run lookup.' );
			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertSame( '', $after->backup_notices );
			$this->assertSame( $task->authorized_sites, $after->authorized_sites );
			if ( 'missing' === $mode ) {
				$this->assertSame( 'run_not_found', $result['code'] );
			} else {
				$this->assertArrayNotHasKey( 'error', $result );
				$this->assertSame( array( 'saved' === $mode ? 999998 : 999999 ), $result['stale_sites'] );
			}
		} finally {
			remove_filter( 'query', $spy );
			delete_transient( $key );
		}
	}

	/**
	 * Incomplete transient payloads return run_not_found instead of a PHP error.
	 *
	 * @return void
	 */
	public function test_malformed_saved_run_returns_run_not_found() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$started                = time() - 100;
		$key                    = 'mainwp_backup_run_' . $task->id . '_' . $started;
		$invalid                = array(
			array(),
			array(
				'task'      => 'invalid',
				'progress'  => array(),
				'snapshots' => array(),
			),
			array(
				'task'      => $task,
				'snapshots' => array(),
			),
			array(
				'task'     => $task,
				'progress' => array(),
			),
		);
		try {
			foreach ( $invalid as $saved ) {
				set_transient( $key, $saved, DAY_IN_SECONDS );
				$result = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $started );
				$this->assertSame( 'run_not_found', $result['code'] );
				$this->assertArrayNotHasKey( 'sites', $result );
			}
		} finally {
			delete_transient( $key );
		}
	}

	/**
	 * A deleted selection must not block the remaining authorized sites.
	 *
	 * @return void
	 */
	public function test_run_now_skips_deleted_site_and_reports_its_id() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$stale_id               = 999999;
		$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $stale_id ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'sites'            => $site_id . ',' . $stale_id,
				'authorized_sites' => $site_id . ',' . $stale_id,
			)
		);
		$allowed = static function () use ( $site_id ) {
			return array( $site_id );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		try {
			$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'running', $start['status'] );
			$this->assertSame( array( $stale_id ), $start['stale_sites'] );
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( array( $stale_id ), $status['stale_sites'] );
			$this->assertSame( array( $site_id ), array_column( $status['sites'], 'id' ) );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		}
	}

	/**
	 * A site the owner can no longer access still exists, so it is a refusal
	 * for the per-site gate, never a stale id the run may skip.
	 *
	 * @return void
	 */
	public function test_run_now_refuses_restricted_site_instead_of_skipping_it() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$restricted             = $this->create_test_site( array( 'userid' => get_current_user_id() ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'sites'            => $site_id . ',' . $restricted,
				'authorized_sites' => $site_id . ',' . $restricted,
			)
		);
		$allowed = static function () use ( $site_id ) {
			return array( $site_id );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		try {
			$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertArrayHasKey( 'error', $start );
			$this->assertArrayNotHasKey( 'stale_sites', $start );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		}
	}

	/**
	 * A stale caller must close the run that opened since its read.
	 *
	 * @return void
	 */
	public function test_rescope_pause_rereads_run_before_closing() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$restricted             = $this->create_test_site( array( 'userid' => get_current_user_id() ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'sites'            => $site_id . ',' . $restricted,
				'authorized_sites' => $site_id . ',' . $restricted,
			)
		);
		$allowed = static function () use ( $site_id ) {
			return array( $site_id );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		try {
			$reloaded = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertSame( 'narrowed', MainWP_Manage_Backups_Handler::resolve_task_scope_pause( $reloaded ) );
			MainWP_Manage_Backups_Handler::pause_task_for_rescope( $task );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		}
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $start['started'], (int) $after->completed );
	}

	/**
	 * Engine errors must announce failure before propagating to the caller.
	 *
	 * @return void
	 */
	public function test_incremental_finish_error_emits_failure() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$property               = new \ReflectionProperty( MainWP_Backup_Snapshots::class, 'instance' );
		$original               = $property->getValue();
		$property->setValue(
			null,
			new class() extends MainWP_Backup_Snapshots {
				/**
				 * Model a programming error in the finish path.
				 *
				 * @param array $data Snapshot fields.
				 * @return never
				 * @throws \Error The injected failure.
				 */
				public function record_snapshot( array $data ): int {
					throw new \Error( 'Snapshot finish broke.' );
				}
			}
		);
		$events  = array();
		$capture = static function ( $site, $child, $success, $meta ) use ( &$events ) {
			$events[] = array( $success, $meta['error'] ?? '' );
		};
		add_action( 'mainwp_native_backup_completed', $capture, 10, 4 );
		try {
			$this->run_cycle( $site_id, $task );
			try {
				$this->run_cycle( $site_id, $task );
				$this->fail( 'The finish Error must propagate.' );
			} catch ( \Error $error ) {
				$this->assertSame( 'Snapshot finish broke.', $error->getMessage() );
			}
			$this->assertSame( array( array( false, 'Snapshot finish broke.' ) ), $events );
		} finally {
			$property->setValue( null, $original );
			remove_action( 'mainwp_native_backup_completed', $capture, 10 );
		}
	}

	/**
	 * Downloading cached artifacts on resume reports advancement; re-adopting a recorded snapshot does not.
	 *
	 * @return void
	 */
	public function test_incremental_resume_cached_fetch_result_reports_advanced() {
		list( $site_id, $task ) = $this->arrange_manual_task();

		$failure          = new MainWP_Exception( 'Download interrupted.' );
		$fail_download    = true;
		$download_failure = static function ( $pre ) use ( $failure, &$fail_download ) {
			if ( $fail_download ) {
				throw $failure;
			}
			return $pre;
		};
		add_filter( 'mainwp_download_to_file_pre', $download_failure, 5 );
		try {
			$this->run_cycle( $site_id, $task );
			$failed = $this->run_cycle( $site_id, $task );
			$this->assertSame( $failure, $failed['exception'] );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertGreaterThan( 0, (int) $progress->dtsFetched );
			$this->assertNotEmpty( $progress->fetchResult['artifacts'] );
			$local = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-manual-' . $task->id . '/files.list';
			$this->assertFileDoesNotExist( $local );

			$run_call_count = count( $this->all_call_params( 'backup_task_run' ) );

			$fail_download = false;
			$advanced      = false;
			$result        = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced );
			$this->assertTrue( $advanced );
			$this->assertIsArray( $result );
			$this->assertGreaterThan( 0, $result['snapshot_id'] );
			$this->assertFileExists( $local );
			$this->assertCount( $run_call_count, $this->all_call_params( 'backup_task_run' ) );

			$advanced = false;
			$resume   = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced );
			$this->assertSame( $result['snapshot_id'], $resume['snapshot_id'] );
			$this->assertFalse( $advanced );
			$this->assertCount( $run_call_count, $this->all_call_params( 'backup_task_run' ) );
		} finally {
			remove_filter( 'mainwp_download_to_file_pre', $download_failure, 5 );
		}
	}

	/**
	 * Reusing a recorded snapshot must not announce another successful backup.
	 *
	 * @dataProvider completion_identity_cases
	 * @param bool   $manifest Whether the child returns a manifest.
	 * @param bool   $newer Whether another run is recorded before the retry.
	 * @param string $schema Schema state.
	 * @param bool   $repair Whether schema repair succeeds before retry.
	 * @return void
	 */
	public function test_incremental_resume_recorded_snapshot_emits_success_once( $manifest, $newer, $schema = 'ready', $repair = false ) {
		list( $site_id, $task ) = $this->arrange_manual_task();
		global $wpdb;
		$table          = $wpdb->prefix . 'mainwp_wp_backup_snapshot';
		$pending        = get_site_option( 'mainwp_backup_snapshot_run_key_repair_pending' );
		$dropped_index  = false;
		$dropped_column = false;
		$events         = array();
		$omit_manifest  = static function ( $result, $website, $what ) use ( $manifest ) {
			if ( ! $manifest && 'backup_task_run' === $what && is_array( $result ) && isset( $result['artifacts'] ) ) {
				$result['artifacts'] = array_values(
					array_filter(
						$result['artifacts'],
						static function ( $artifact ) {
							return 'manifest' !== $artifact['role'];
						}
					)
				);
			}
			return $result;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $omit_manifest, 20, 3 );
		$capture = static function ( $site, $child, $success ) use ( &$events ) {
			if ( $success ) {
				$events[] = $child;
			}
		};
		add_action( 'mainwp_native_backup_completed', $capture, 10, 3 );
		try {
			if ( 'ready' !== $schema ) {
				update_site_option( 'mainwp_backup_snapshot_run_key_repair_pending', time() );
				$dropped_index = false !== $wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_run_key" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema fixture restored in finally.
				$this->assertTrue( $dropped_index );
				if ( 'missing-column' === $schema ) {
					$dropped_column = false !== $wpdb->query( "ALTER TABLE {$table} DROP COLUMN run_key" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema fixture restored in finally.
					$this->assertTrue( $dropped_column );
				}
			}
			$this->run_cycle( $site_id, $task );
			$first = $this->run_cycle( $site_id, $task );
			$this->assertNull( $first['exception'] );
			$this->assertCount( 1, $events );
			$saved = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $first['result']['snapshot_id'] );
			$this->assertSame( $manifest, '' !== $saved->manifest_path );
			if ( 'ready' === $schema ) {
				$this->assertNotEmpty( $saved->run_key );
			}
			if ( $repair ) {
				$this->assertNull( $saved->run_key ?? null );
				if ( $dropped_column ) {
					$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} ADD COLUMN run_key varchar(191) NULL DEFAULT NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- installer-equivalent schema repair fixture.
					$dropped_column = false;
				}
				$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_run_key (run_key)" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- installer-equivalent schema repair fixture.
				$dropped_index = false;
				delete_site_option( 'mainwp_backup_snapshot_run_key_repair_pending' );
				$this->assertTrue( \MainWP\Dashboard\MainWP_Install::backup_snapshot_run_key_ready() );
				$repaired = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $first['result']['snapshot_id'] );
				$this->assertNull( $repaired->run_key );
			}
			if ( $newer ) {
				$this->record_base_snapshot( $site_id, (int) $task->id, false );
			}
			$before = count( MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
			$resume = $this->run_cycle( $site_id, $task );
			$this->assertCount( $before, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
			$this->assertNull( $resume['exception'] );
			$this->assertSame( $first['result']['snapshot_id'], $resume['result']['snapshot_id'] );
			$this->assertCount( 1, $events );
		} finally {
			remove_action( 'mainwp_native_backup_completed', $capture, 10 );
			remove_filter( 'mainwp_fetch_url_authed_pre', $omit_manifest, 20 );
			if ( $dropped_column ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN run_key varchar(191) NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema fixture restored in finally.
			}
			if ( $dropped_index ) {
				$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_run_key (run_key)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema fixture restored in finally.
			}
			if ( false === $pending ) {
				delete_site_option( 'mainwp_backup_snapshot_run_key_repair_pending' );
			} else {
				update_site_option( 'mainwp_backup_snapshot_run_key_repair_pending', $pending );
			}
			if ( 'ready' !== $schema ) {
				// ALTER commits the fixtures; commit their removal before parent teardown rolls back.
				$wpdb->delete(
					$table,
					array(
						'site_id' => $site_id,
						'task_id' => $task->id,
					)
				);
				$wpdb->delete(
					$wpdb->prefix . 'mainwp_wp_backup_progress',
					array(
						'wp_id'   => $site_id,
						'task_id' => $task->id,
					)
				);
				$wpdb->delete( $wpdb->prefix . 'mainwp_wp_backup', array( 'id' => $task->id ) );
				$wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $site_id ) );
				$wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ) );
				$wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ) );
				$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $task->userid ) );
				$wpdb->delete( $wpdb->users, array( 'ID' => $task->userid ) );
				delete_option( 'mainwp_enableNativeBackups' );
				self::commit_transaction();
			}
		}
	}

	/**
	 * Completion identity cases independent of manifest and recency.
	 *
	 * @return array
	 */
	public static function completion_identity_cases() {
		return array(
			'with manifest'                         => array( true, false ),
			'without manifest'                      => array( false, false ),
			'without manifest and newer snapshot'   => array( false, true ),
			'missing column manifestless'           => array( false, false, 'missing-column' ),
			'missing column intervening completion' => array( false, true, 'missing-column' ),
			'missing index manifestless'            => array( false, false, 'missing-index' ),
			'missing index intervening completion'  => array( false, true, 'missing-index' ),
			'missing column repaired before retry'  => array( false, true, 'missing-column', true ),
			'missing index repaired before retry'   => array( false, true, 'missing-index', true ),
		);
	}

	/**
	 * The second site's terminal error must not reuse the first site's result.
	 *
	 * @return void
	 */
	public function test_terminal_site_error_has_its_own_schedule_hook_payload() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$second                 = $this->create_test_site( array( 'userid' => (int) $task->userid ) );
		$this->give_site_real_privkey( $second );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'sites'            => $site_id . ',' . $second,
				'authorized_sites' => $site_id . ',' . $second,
				'last_run'         => time() - 10,
				'completed'        => 0,
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->run_cycle( $site_id, $task );
		$fail    = static function ( $result, $website, $what ) use ( $second ) {
			if ( (int) $website->id === $second && 'backup_task_create' === $what ) {
				return array(
					'error'      => 'walk broke',
					'error_code' => MainWP_Backup_Error_Codes::ERR_MANIFEST_WALK_FAILED,
				);
			}
			return $result;
		};
		$events  = array();
		$capture = static function ( $website, $options, $result ) use ( &$events ) {
			$events[ (int) $website->id ] = $result;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $fail, 20, 3 );
		add_action( 'mainwp_managesite_schedule_backup', $capture, 10, 3 );
		try {
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false );
			$this->assertArrayHasKey( 'snapshot_id', $events[ $site_id ] );
			$this->assertSame( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_MANIFEST_WALK_FAILED ), $events[ $second ] );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $fail, 20 );
			remove_action( 'mainwp_managesite_schedule_backup', $capture, 10 );
		}
	}

	/**
	 * Manual ownership cases. Timestamps are offsets from the test's clock;
	 * null means the zero sentinel. Claims count the actual SQL attempts.
	 *
	 * @return array Matrix rows.
	 */
	public static function manual_run_ownership_cases() {
		$defaults  = array(
			'last_run'    => -10,
			'completed'   => -5,
			'started'     => null,
			'click'       => 0,
			'errors'      => '',
			'paused'      => 0,
			'held'        => false,
			'initiate'    => false,
			'snapshot'    => false,
			'claims'      => 0,
			'advanced'    => false,
			'status'      => 'failed',
			'race'        => false,
			'replaced'    => false,
			'denied'      => false,
			'task_denied' => false,
			'baseline'    => 'same',
			'guard'       => '',
			'fast_close'  => false,
			'manual'      => false,
		);
		$scenarios = array(
			'1 never run'                                 => array(
				'last_run'  => null,
				'completed' => null,
				'click'     => null,
			),
			'2 open owned run'                            => array(
				'completed' => -20,
				'started'   => -10,
				'click'     => null,
				'status'    => 'running',
				'advanced'  => true,
			),
			'3 open other run'                            => array(
				'status'    => 'missing',
				'completed' => -20,
				'started'   => -30,
				'click'     => null,
				'snapshot'  => true,
			),
			'4 owned clean close'                         => array(
				'started'  => -10,
				'click'    => null,
				'snapshot' => true,
				'status'   => 'completed',
			),
			'5 scheduler close beyond baseline'           => array(
				'baseline' => -400,
				'last_run' => -300,
				'click'    => -900,
				'snapshot' => true,
				'claims'   => 0,
				'status'   => 'completed',
				'adopted'  => true,
			),
			'6 old clean close'                           => array(
				'last_run'  => -200,
				'completed' => -100,
				'claims'    => 1,
				'advanced'  => true,
				'status'    => 'running',
			),
			'7 failed close'                              => array(
				'errors'   => 'Upload failed.',
				'claims'   => 1,
				'advanced' => true,
				'status'   => 'running',
			),
			'8 held slot'                                 => array(
				'held'     => true,
				'initiate' => true,
				'snapshot' => true,
				'status'   => 'queued',
			),
			'9 paused'                                    => array(
				'paused' => 1,
				'status' => 'refused',
			),
			'10 same second scheduled close before click' => array(
				'last_run'  => 0,
				'completed' => 0,
				'initiate'  => true,
				'snapshot'  => true,
				'held'      => true,
				'status'    => 'queued',
			),
			'11 queued then scheduler opens'              => array(
				'last_run'     => -200,
				'completed'    => -300,
				'click'        => -230,
				'baseline'     => -250,
				'snapshot'     => true,
				'status'       => 'queued',
				'close_errors' => '',
			),
			'12 failed claim with foreign advance'        => array(
				'last_run'  => -200,
				'completed' => -100,
				'initiate'  => true,
				'race'      => true,
				'claims'    => 1,
				'status'    => 'queued',
			),
			'13 A closes successfully then B opens'       => array(
				'started'  => -10,
				'click'    => null,
				'snapshot' => true,
				'replaced' => true,
				'status'   => 'completed',
			),
			'14 A closes with errors then B opens'        => array(
				'started'  => -10,
				'click'    => null,
				'errors'   => 'Upload failed.',
				'replaced' => true,
			),
			'15 recent manual close at initiate'          => array(
				'initiate' => true,
				'manual'   => true,
				'snapshot' => true,
				'claims'   => 1,
				'adopted'  => true,
				'status'   => 'completed',
			),
			'16 unauthorized site'                        => array(
				'denied' => true,
				'status' => 'refused',
			),
			'17 initiate follows open run'                => array(
				'completed' => -20,
				'initiate'  => true,
				'advanced'  => true,
				'status'    => 'running',
			),
			'18 initiate opens new run'                   => array(
				'last_run'  => null,
				'completed' => null,
				'initiate'  => true,
				'claims'    => 1,
				'advanced'  => true,
				'status'    => 'completed',
			),

			'19 queued click with recent manual close'    => array(
				'manual'   => true,
				'snapshot' => true,
				'adopted'  => true,
				'status'   => 'completed',
			),
			'20 unauthorized task'                        => array(
				'task_denied' => true,
				'status'      => 'refused',
			),
			'21 queued then foreign run closes with errors' => array(
				'last_run'     => -200,
				'completed'    => -300,
				'click'        => -230,
				'baseline'     => -250,
				'status'       => 'queued',
				'close_errors' => 'Upload failed.',
			),
			'22 scheduler close then replacing claim'     => array(
				'started'   => -10,
				'click'     => null,
				'snapshot'  => true,
				'replaced'  => true,
				'scheduler' => true,
				'status'    => 'completed',
			),
			'23 missing task'                             => array(
				'guard'  => 'missing',
				'status' => 'refused',
			),
			'24 native backups disabled'                  => array(
				'guard'  => 'disabled',
				'status' => 'refused',
			),
			'25 empty selection with stale ids'           => array(
				'guard'  => 'stale',
				'status' => 'refused',
			),
			'26 empty group'                              => array(
				'guard'  => 'empty_group',
				'status' => 'refused',
			),
			'27 active restore'                           => array(
				'guard'  => 'restore',
				'status' => 'refused',
			),
			'28 legacy handle without baseline'           => array(
				'baseline'  => null,
				'last_run'  => -200,
				'completed' => -100,
				'click'     => -300,
				'snapshot'  => true,
				'claims'    => 1,
				'advanced'  => true,
				'status'    => 'running',
			),
			'29 initiate adopts interleaved clean close'  => array(
				'last_run'  => -200,
				'completed' => -100,
				'initiate'  => true,
				'race'      => 'closed',
				'claims'    => 1,
				'adopted'   => true,
				'status'    => 'completed',
			),
			'30 clean close without current snapshot'     => array(
				'baseline' => -20,
				'claims'   => 1,
				'status'   => 'queued',
			),
			'31 manual stamp precedes synchronous close'  => array(
				'last_run'   => null,
				'completed'  => null,
				'initiate'   => true,
				'fast_close' => true,
				'claims'     => 1,
				'advanced'   => true,
				'status'     => 'completed',
			),
			'32 continuation closes before error flush'   => array(
				'guard' => 'error_flush',
			),
			'33 stale snapshot cannot satisfy adoption'   => array(
				'baseline' => -20,
				'snapshot' => true,
				'claims'   => 1,
				'status'   => 'queued',
			),
			'34 recent scheduled close at baseline'       => array(
				'snapshot' => true,
				'claims'   => 1,
				'status'   => 'queued',
			),
			'35 old manual close at baseline'             => array(
				'last_run'  => -200,
				'completed' => -100,
				'manual'    => true,
				'snapshot'  => true,
				'claims'    => 1,
				'advanced'  => true,
				'status'    => 'running',
			),
		);
		foreach ( $scenarios as $name => $scenario ) {
			$scenarios[ $name ] = array( array_merge( $defaults, array( 'row' => (int) $name ), $scenario ) );
		}
		return $scenarios;
	}

	/**
	 * Ownership must survive slot refusals and another driver's writes.
	 *
	 * @dataProvider manual_run_ownership_cases
	 * @param array $scenario Task state, request identity and expected effects.
	 * @return void
	 */
	public function test_manual_run_ownership_matrix( array $scenario ) {
		if ( 'error_flush' === $scenario['guard'] ) {
			$this->assert_error_flush_gap_is_not_adopted();
			return;
		}
		global $wpdb;
		list( $site_id, $task, $snapshot_id ) = $this->arrange_manual_task( $scenario['snapshot'] );
		$now                                  = time();
		$last_run                             = null === $scenario['last_run'] ? 0 : $now + $scenario['last_run'];
		$completed                            = null === $scenario['completed'] ? 0 : $now + $scenario['completed'];
		$started                              = null === $scenario['started'] ? 0 : $now + $scenario['started'];
		$click                                = null === $scenario['click'] ? 0 : $now + $scenario['click'];
		$baseline                             = 'same' === $scenario['baseline'] ? $last_run : ( null === $scenario['baseline'] ? null : $now + $scenario['baseline'] );
		$request_id                           = (int) $task->id;
		$group_id                             = 0;
		$restore_id                           = 0;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'          => $last_run,
				'completed'         => $completed,
				'backup_errors'     => $scenario['errors'],
				'paused'            => $scenario['paused'],
				'last_run_manually' => ! empty( $scenario['scheduler'] ) ? $last_run - 100 : ( $scenario['replaced'] || $scenario['manual'] ? $last_run : 0 ),
			)
		);
		if ( $snapshot_id ) {
			$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup_snapshot', array( 'created' => 33 === $scenario['row'] ? $last_run - 1 : $last_run ), array( 'id' => $snapshot_id ) );
		}
		if ( 'missing' === $scenario['guard'] ) {
			$request_id = 999999;
			$this->assertEmpty( MainWP_DB_Backup::instance()->get_backup_task_by_id( $request_id ) );
		} elseif ( 'disabled' === $scenario['guard'] ) {
			update_option( 'mainwp_enableNativeBackups', 0 );
		} elseif ( in_array( $scenario['guard'], array( 'stale', 'empty_group' ), true ) ) {
			if ( 'empty_group' === $scenario['guard'] ) {
				$group_id = MainWP_DB_Common::instance()->add_group( (int) $task->userid, 'Empty backup group' );
				$this->assertGreaterThan( 0, $group_id );
			}
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$task->id,
				array(
					'sites'            => $group_id ? '' : '999999',
					'groups'           => $group_id ? (string) $group_id : '',
					'authorized_sites' => $group_id ? '' : '999999',
				)
			);
		} elseif ( 'restore' === $scenario['guard'] ) {
			$restore_id = MainWP_Backup_Restore_Job_Store::instance()->create_job( $site_id, 1, array() );
			$this->assertGreaterThan( 0, $restore_id );
		}
		if ( $scenario['fast_close'] ) {
			$cycle = $this->run_cycle( $site_id, $task );
			$this->assertNull( $cycle['exception'] );
			$this->assertFalse( $cycle['result'] );
			$this->child_calls = array();
		}
		if ( $scenario['replaced'] ) {
			$claimed = null;
			$owned   = 0;
			MainWP_Manage_Backups_Handler::execute_backup_task( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ), 5, true, 0, $claimed, $owned, true );
			if ( ! empty( $scenario['scheduler'] ) ) {
				$saved = get_transient( 'mainwp_backup_run_' . $task->id . '_' . $last_run );
				$this->assertIsArray( $saved );
				$this->assertSame( $last_run, (int) $saved['task']->last_run );
				$this->assertLessThan( $last_run, (int) $saved['task']->last_run_manually );
			}
			$this->child_calls = array();
		}
		$claims         = array();
		$foreign        = 0;
		$claimed_start  = 0;
		$stamp_observed = false;
		$spy            = function ( $query ) use ( &$spy, &$claims, &$foreign, &$claimed_start, &$stamp_observed, $wpdb, $scenario, $task, $site_id, $now ) {
			if ( str_starts_with( $query, 'UPDATE ' . $wpdb->prefix . 'mainwp_wp_backup SET last_run = GREATEST(' ) ) {
				$claims[] = $query;
				if ( 'closed' === $scenario['race'] ) {
					remove_filter( 'query', $spy );
					$other = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
					MainWP_Manage_Backups_Handler::execute_backup_task( $other );
					$other = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
					MainWP_Manage_Backups_Handler::execute_backup_task( $other, 5, false );
					$other   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
					$foreign = (int) $other->last_run;
					$this->assertGreaterThanOrEqual( $foreign, (int) $other->completed );
					$this->assertSame( '', $other->backup_errors );
					$this->child_calls = array();
					add_filter( 'query', $spy );
				} elseif ( $scenario['race'] ) {
					$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup', array( 'last_run' => $now + 1 ), array( 'id' => $task->id ) );
				} elseif ( $scenario['fast_close'] ) {
					remove_filter( 'query', $spy );
					$this->assertSame( 1, $wpdb->query( $query ) );
					$claimed_start = (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run;
					MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'dtsFetched' => $claimed_start ) );
					add_filter( 'query', $spy );
					return 'SELECT 1';
				}
			}
			if ( $claimed_start > 0 && ! $stamp_observed && str_starts_with( $query, 'SELECT' ) && str_contains( $query, $wpdb->prefix . 'mainwp_wp_backup_progress' ) ) {
				$row = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
				$this->assertSame( $claimed_start, (int) $row->last_run_manually, 'The manual stamp must precede the first site call.' );
				$this->assertSame( array(), $this->child_calls );
				$stamp_observed = true;
			}
			return $query;
		};
		$token          = false;
		update_option( 'mainwp_backup_manual_settle_seconds', 60 );
		update_option( 'mainwp_backup_max_concurrent', 'closed' === $scenario['race'] ? 2 : 1 );
		add_filter( 'query', $spy );
		$deny = static function ( $allowed, $type ) use ( $scenario ) {
			return $scenario['denied'] && 'site' === $type ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 2 );
		if ( $scenario['task_denied'] ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			add_filter( 'mainwp_is_single_user', '__return_false' );
		}
		try {
			if ( 'refused' === $scenario['status'] ) {
				$this->assertArrayHasKey( 'error', MainWP_Manage_Backups_Handler::run_backup_task_now( $request_id ) );
			}
			if ( $scenario['held'] ) {
				$token = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
				$this->assertIsString( $token );
			}
			if ( $scenario['initiate'] ) {
				$start = MainWP_Manage_Backups_Handler::run_backup_task_now( $request_id );
				$this->assertArrayNotHasKey( 'error', $start );
				if ( 'queued' === $start['status'] ) {
					$this->assertArrayHasKey( 'baseline', $start );
					$this->assertSame( $last_run, $start['baseline'], 'A queued handle must retain the identity read before claiming.' );
				}
				$this->assertSame( $scenario['claims'] && $scenario['advanced'] ? 'running' : $scenario['status'], $start['status'] );
				$started  = $start['started'];
				$click    = $start['click'];
				$baseline = $start['baseline'] ?? $baseline;
				if ( $scenario['fast_close'] ) {
					$row = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
					$this->assertTrue( $stamp_observed );
					$this->assertGreaterThanOrEqual( $started, (int) $row->completed, 'This run must close inside initiate, before polling.' );
				}
				if ( 17 === (int) $scenario['row'] ) {
					$this->assertSame( $last_run, $started );
					$this->assertSame( 0, $click );
					$this->assertArrayNotHasKey( 'baseline', $start );
				}
				if ( ! $scenario['advanced'] ) {
					$this->assertSame( 'queued' === $scenario['status'] ? 0 : ( $foreign ? $foreign : $last_run ), $started );
					$this->assertGreaterThan( 0, $click );
				}
			}
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( $request_id, $started, $click, false, $baseline );
			if ( 'refused' === $scenario['status'] ) {
				$this->assertArrayHasKey( 'error', $status );
				$this->assertArrayNotHasKey( 'sites', $status );
			} elseif ( 'missing' === $scenario['status'] ) {
				$this->assertSame( 'run_not_found', $status['code'] );
				$this->assertArrayHasKey( 'error', $status );
				$this->assertArrayNotHasKey( 'sites', $status );
			} else {
				$this->assertSame( $scenario['status'], $status['status'] );
				if ( ! empty( $scenario['adopted'] ) ) {
					$this->assertSame( $foreign ? $foreign : $last_run, $status['started'] );
					$this->assertSame( $click, $status['click'] );
					$this->assertGreaterThan( 0, $status['click'] );
					$this->assertSame( 'closed', $status['run_state'] );
					$this->assertNotNull( $status['sites'][0]['snapshot'], 'Adoption must have a recorded snapshot.' );
				}
				if ( $scenario['replaced'] ) {
					$this->assertSame( 'closed', $status['run_state'] );
					$this->assertSame( $scenario['errors'], $status['errors'] );
				}
				if ( 'completed' === $scenario['status'] ) {
					if ( $snapshot_id ) {
						$this->assertSame( (int) $snapshot_id, $status['sites'][0]['snapshot']['id'] );
					} else {
						$this->assertNotNull( $status['sites'][0]['snapshot'] );
					}
				} else {
					$this->assertNull( $status['sites'][0]['snapshot'] );
				}
				if ( 'queued' === $scenario['status'] ) {
					$this->assertSame( 0, $status['started'] );
					$this->assertSame( $baseline, $status['baseline'] );
					$this->assertSame( $click, $status['click'] );
					$this->assertSame( 'pending', $status['sites'][0]['status'] );
					$this->assertSame( '', $status['errors'] );
				}
			}
			$this->assertCount( $scenario['claims'], $claims, 'The claim SQL must only run in claim rows.' );
			$this->assertSame( $scenario['advanced'], ! empty( $this->child_calls ), 'Only an owned continuation or a new claim may contact the child.' );
			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			if ( $scenario['claims'] > 0 && $scenario['advanced'] ) {
				$this->assertGreaterThan( $last_run, (int) $after->last_run );
				$this->assertGreaterThan( 0, (int) $after->last_run_manually );
			} elseif ( $scenario['replaced'] ) {
				$this->assertGreaterThan( $last_run, (int) $after->last_run );
			} else {
				$this->assertSame( $foreign ? $foreign : ( $scenario['race'] ? $now + 1 : $last_run ), (int) $after->last_run );
				$this->assertSame( $scenario['manual'] ? $last_run : 0, (int) $after->last_run_manually, 'A refused claim or adoption must preserve the manual stamp.' );
			}
			if ( in_array( $scenario['guard'], array( 'stale', 'empty_group' ), true ) ) {
				$reason = 'stale' === $scenario['guard'] ? 'No existing sites remain' : 'Edit the task and select sites';
				$this->assertStringContainsString( $reason, $status['error'] );
				$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
				$this->assertFalse( MainWP_Manage_Backups_Handler::execute_backup_task( $task ) );
				$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
				$this->assertGreaterThanOrEqual( (int) $after->last_run, (int) $after->completed );
				$this->assertStringContainsString( $reason, $after->backup_errors );
				$poll = MainWP_Manage_Backups_Handler::get_backup_task_run_status( $request_id, (int) $after->last_run );
				$this->assertStringContainsString( $reason, $poll['error'] );
			}
			if ( isset( $scenario['close_errors'] ) ) {
				MainWP_DB_Backup::instance()->update_backup_task_with_values(
					$task->id,
					array(
						'completed'     => $last_run + 1,
						'backup_errors' => $scenario['close_errors'],
					)
				);
				$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( $request_id, 0, $click, false, $baseline );
				$after  = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
				if ( '' === $scenario['close_errors'] ) {
					$this->assertSame( 'completed', $status['status'] );
					$this->assertSame( 'closed', $status['run_state'] );
					$this->assertSame( $foreign ? $foreign : $last_run, $status['started'] );
					$this->assertSame( $click, $status['click'] );
					$this->assertGreaterThan( 0, $status['click'] );
					$this->assertSame( (int) $snapshot_id, $status['sites'][0]['snapshot']['id'] );
					$this->assertSame( $last_run, (int) $after->last_run );
					$this->assertSame( 0, (int) $after->last_run_manually );
					$this->assertCount( 0, $claims );
					$this->assertSame( array(), $this->child_calls );
				} else {
					$this->assertSame( 'running', $status['status'] );
					$this->assertGreaterThan( $last_run, $status['started'] );
					$this->assertSame( $status['started'], (int) $after->last_run );
					$this->assertSame( $status['started'], (int) $after->last_run_manually );
					$this->assertSame( 0, $status['click'] );
					$this->assertCount( 1, $claims );
					$this->assertNotEmpty( $this->child_calls );
				}
			}
		} finally {
			remove_filter( 'query', $spy );
			if ( $group_id ) {
				$wpdb->delete( $wpdb->prefix . 'mainwp_group', array( 'id' => $group_id ) );
			}
			if ( $restore_id ) {
				MainWP_Backup_Restore_Job_Store::instance()->complete_job( $restore_id );
			}
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			if ( $scenario['task_denied'] ) {
				remove_filter( 'mainwp_is_single_user', '__return_false' );
				wp_set_current_user( (int) $task->userid );
			}
			if ( is_string( $token ) ) {
				MainWP_Backup_Monitor::release_backup_slot( $token );
			}
			delete_option( 'mainwp_backup_max_concurrent' );
			delete_option( 'mainwp_backup_manual_settle_seconds' );
		}
	}

	/**
	 * A concurrent closer can observe terminal site marks before their errors land.
	 *
	 * @return void
	 */
	private function assert_error_flush_gap_is_not_adopted() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task( false );
		$started                = time() - 10;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'  => $started,
				'completed' => 0,
			)
		);
		$this->mock_child_actions(
			array(
				'backup_task_create' => array(
					'error'      => 'walk broke',
					'error_code' => MainWP_Backup_Error_Codes::ERR_MANIFEST_WALK_FAILED,
				),
			)
		);
		$observed   = false;
		$interleave = function ( $query ) use ( &$interleave, &$observed, $wpdb, $task, $site_id, $started ) {
			if ( ! str_starts_with( $query, 'UPDATE' ) || ! str_contains( $query, $wpdb->prefix . 'mainwp_wp_backup`' ) || ! str_contains( $query, '`completed_sites`' ) ) {
				return $query;
			}
			remove_filter( 'query', $interleave );
			$this->assertSame( 1, $wpdb->query( $query ) );
			$marked = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertTrue( json_decode( $marked->completed_sites, true )[ $site_id ] );
			$this->assertSame( '', $marked->backup_errors );
			$this->assertNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task->id ) );
			MainWP_Manage_Backups_Handler::execute_backup_task( $marked, 5, false );
			$closed = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertGreaterThanOrEqual( $started, (int) $closed->completed );
			$this->assertSame( '', $closed->backup_errors );
			$poll = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, 0, time(), false, $started - 1 );
			$this->assertSame( 'queued', $poll['status'], 'A terminal mark without a snapshot must not consume the click.' );
			$this->assertSame( 0, $poll['started'] );
			$this->assertNull( $poll['sites'][0]['snapshot'] );
			$observed = true;
			return 'SELECT 1';
		};
		update_option( 'mainwp_backup_max_concurrent', 3 );
		update_option( 'mainwp_backup_manual_settle_seconds', 60 );
		add_filter( 'query', $interleave );
		try {
			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false );
			$this->assertTrue( $observed, 'The competing continuation must run after the terminal mark and before the error flush.' );
			$this->assertNotEmpty( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->backup_errors );
		} finally {
			remove_filter( 'query', $interleave );
			delete_option( 'mainwp_backup_max_concurrent' );
			delete_option( 'mainwp_backup_manual_settle_seconds' );
		}
	}

	/**
	 * Listing legacy modal sites must not confer manual ownership on a scheduled run.
	 *
	 * @return void
	 */
	public function test_legacy_task_site_list_does_not_stamp_a_run() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$sites                  = MainWP_Manage_Backups_Handler::get_backup_task_sites( $task->id );
		$this->assertSame( $site_id, (int) $sites['sites'][0]['id'] );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( (int) $task->last_run_manually, (int) $after->last_run_manually );
		$this->assertSame( array(), $this->child_calls );
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
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
			$this->assertSame( 'queued', $status['status'] );
			$this->assertSame( 'pending', $status['sites'][0]['status'] );
			$this->assertSame( array(), $this->child_calls );
		} finally {
			MainWP_Backup_Monitor::release_backup_slot( $token );
			delete_option( 'mainwp_backup_max_concurrent' );
		}
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
		$this->assertSame( 'running', $status['status'] );
		$this->assertNotNull( $this->last_call_params( 'backup_task_create' ) );
	}

	/**
	 * A poll must project site rows even while another process drives the run.
	 *
	 * @return void
	 */
	public function test_status_poll_reports_lane_state() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$started                = $start['started'];
		$this->child_calls      = array();
		$row                    = wp_json_encode(
			array(
				'token'     => 'other-owner',
				'started'   => time(),
				'heartbeat' => time(),
				'pid'       => 2,
				'lease'     => 600,
			)
		);
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", MainWP_Backup_Monitor::LANE_LEASE_OPTION, $row ) ) );
		try {
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $started );
			$this->assertArrayHasKey( 'lane', $status );
			$this->assertSame( 'busy', $status['lane'] );
			$this->assertTrue( $status['run_open'] );
			$this->assertSame( array(), $this->child_calls );
			$this->assertCount( 1, $status['sites'] );
			$this->assertSame( $site_id, $status['sites'][0]['id'] );
			$this->assertSame( 'running', $status['sites'][0]['status'] );
			$this->assertSame( '', $status['sites'][0]['phase'] );
			$this->assertSame( $row, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ) );
			delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $started );
			$this->assertSame( 'driver', $status['lane'] );
			$this->assertNotNull( $this->last_call_params( 'backup_task_run' ) );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertFalse( $status['run_open'] );
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", MainWP_Backup_Monitor::LANE_LEASE_OPTION, $row ) ) );
			$calls  = $this->child_calls;
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $started );
			$this->assertSame( 'idle', $status['lane'] );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( $calls, $this->child_calls );
		} finally {
			delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
		}
	}

	/**
	 * Both status execution paths must clamp a nonpositive budget to zero.
	 *
	 * @return array Run state and filter budget.
	 */
	public static function status_poll_zero_budget_cases() {
		return array(
			'open zero'       => array( true, 0 ),
			'queued zero'     => array( false, 0 ),
			'open negative'   => array( true, -10 ),
			'queued negative' => array( false, -10 ),
		);
	}

	/**
	 * An exhausted poll must not claim a lane or contact the Child.
	 *
	 * @dataProvider status_poll_zero_budget_cases
	 * @param bool $open Whether the run is already open.
	 * @param int  $seconds Filtered budget.
	 * @return void
	 */
	public function test_status_poll_zero_budget( bool $open, int $seconds ) {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$db                     = MainWP_DB_Backup::instance();
		$started                = $open ? time() - 10 : 0;
		if ( $open ) {
			$db->update_backup_task_with_values(
				$task->id,
				array(
					'last_run'  => $started,
					'completed' => 0,
				)
			);
		}
		$before  = $db->get_backup_task_by_id( $task->id );
		$budgets = array();
		$events  = array();
		$budget  = static function ( $default_seconds ) use ( &$budgets, $seconds ) {
			$budgets[] = $default_seconds;
			return $seconds;
		};
		$lane    = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		add_filter( 'mainwp_backup_status_poll_budget', $budget );
		add_action( 'mainwp_backup_diag_lane', $lane );
		try {
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $started, $open ? 0 : time() );
			$this->assertSame( array(), $this->child_calls );
			$this->assertSame( array(), $events );
			$this->assertSame( array( 25 ), $budgets );
			$this->assertArrayHasKey( 'lane', $status );
			$this->assertSame( 'idle', $status['lane'] );
			$this->assertSame( $open ? 'running' : 'queued', $status['status'] );
			$this->assertCount( 1, $status['sites'] );
			$this->assertSame( $site_id, $status['sites'][0]['id'] );
			$after = $db->get_backup_task_by_id( $task->id );
			$this->assertSame( $before->last_run, $after->last_run );
			$this->assertSame( $before->completed, $after->completed );
			$this->assertSame( $before->backup_errors, $after->backup_errors );
		} finally {
			remove_filter( 'mainwp_backup_status_poll_budget', $budget );
			remove_action( 'mainwp_backup_diag_lane', $lane );
		}
	}

	/**
	 * Adopting a closed run must retain the initial poll's budget.
	 *
	 * @return void
	 */
	public function test_status_poll_adoption_reuses_budget() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$done                   = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'completed', $done['status'] );
		$calls  = $this->child_calls;
		$reads  = 0;
		$budget = static function () use ( &$reads ) {
			++$reads;
			return 0;
		};
		add_filter( 'mainwp_backup_status_poll_budget', $budget );
		try {
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, 0, time(), false, $start['started'] );
			$this->assertSame( 1, $reads );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( 'idle', $status['lane'] );
			$this->assertSame( $calls, $this->child_calls );
		} finally {
			remove_filter( 'mainwp_backup_status_poll_budget', $budget );
		}
	}

	/**
	 * Opening a queued run is also work driven by the status request.
	 *
	 * @return void
	 */
	public function test_status_poll_manual_claim_reports_driver() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$status                 = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, 0, time() );
		$this->assertArrayHasKey( 'lane', $status );
		$this->assertSame( 'driver', $status['lane'] );
		$this->assertSame( 'running', $status['status'] );
		$this->assertNotNull( $this->last_call_params( 'backup_task_create' ) );
	}

	/**
	 * A snapshot landing during a refused claim must not restart the poll budget.
	 *
	 * @return void
	 */
	public function test_status_poll_claim_adoption_reuses_budget() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$done                   = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
		$this->assertSame( 'completed', $done['status'] );
		$calls  = $this->child_calls;
		$reads  = 0;
		$hidden = false;
		$budget = static function () use ( &$reads ) {
			++$reads;
			return 25;
		};
		$hide   = static function ( $query ) use ( &$hide, &$hidden, $wpdb ) {
			if ( str_starts_with( $query, 'SELECT * FROM ' . $wpdb->prefix . 'mainwp_wp_backup_snapshot' ) && str_contains( $query, "status = 'complete'" ) ) {
				remove_filter( 'query', $hide );
				$hidden = true;
				return 'SELECT * FROM ' . $wpdb->prefix . 'mainwp_wp_backup_snapshot WHERE 1 = 0';
			}
			return $query;
		};
		add_filter( 'mainwp_backup_status_poll_budget', $budget );
		add_filter( 'query', $hide );
		try {
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, 0, time(), false, $start['started'] );
			$this->assertTrue( $hidden );
			$this->assertSame( 1, $reads );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( 'idle', $status['lane'] );
			$this->assertSame( $calls, $this->child_calls );
		} finally {
			remove_filter( 'query', $hide );
			remove_filter( 'mainwp_backup_status_poll_budget', $budget );
		}
	}

	/**
	 * A response re-entry must remember the site this poll drove before a new run opened.
	 *
	 * @return void
	 */
	public function test_status_poll_reentry_preserves_budget_and_driver() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$armed                  = false;
		$replaced               = false;
		$reads                  = 0;
		$budget                 = static function () use ( &$reads ) {
			++$reads;
			return 25;
		};
		$released               = static function ( $event ) use ( &$armed ) {
			$armed = $armed || 'released' === $event;
		};
		$replace                = static function ( $query ) use ( &$replace, &$armed, &$replaced, $wpdb, $task, $site_id, $start ) {
			if ( $armed && str_starts_with( $query, 'SELECT * FROM ' . $wpdb->prefix . 'mainwp_wp_backup_snapshot' ) && str_contains( $query, "status = 'complete'" ) ) {
				remove_filter( 'query', $replace );
				$db       = MainWP_DB_Backup::instance();
				$previous = $db->get_backup_task_by_id( $task->id );
				set_transient(
					'mainwp_backup_run_' . $task->id . '_' . $start['started'],
					array(
						'task'      => $previous,
						'progress'  => array( $site_id => $db->get_backup_task_progress( $task->id, $site_id ) ),
						'snapshots' => array( $site_id => MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task->id ) ),
					),
					DAY_IN_SECONDS
				);
				$db->update_backup_task_with_values( $task->id, array( 'last_run' => $start['started'] + 1 ) );
				$replaced = true;
			}
			return $query;
		};
		add_filter( 'mainwp_backup_status_poll_budget', $budget );
		add_action( 'mainwp_backup_diag_lane', $released );
		add_filter( 'query', $replace );
		try {
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'] );
			$this->assertTrue( $replaced );
			$this->assertSame( 1, $reads );
			$this->assertSame( 'driver', $status['lane'] );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( 'closed', $status['run_state'] );
		} finally {
			remove_filter( 'query', $replace );
			remove_action( 'mainwp_backup_diag_lane', $released );
			remove_filter( 'mainwp_backup_status_poll_budget', $budget );
			delete_transient( 'mainwp_backup_run_' . $task->id . '_' . $start['started'] );
		}
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
	 * Back Up Now's hidden task cannot be reopened through Run Now, which treats
	 * it as no task at all, or through a status poll, even when disconnected.
	 *
	 * @return void
	 */
	public function test_hidden_on_demand_task_refuses_disconnected_site() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup', array( 'template' => 1 ), array( 'id' => $task->id ) );
		\MainWP\Dashboard\MainWP_DB::instance()->update_website_sync_values( $site_id, array( 'sync_errors' => 'Connection failed' ) );

		$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$this->assertSame( 'Insufficient permissions. Is this task set by you?', $start['error'] );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, (int) $task->last_run, time() );
		$this->assertSame( 'Insufficient permissions. Is this task set by you?', $status['error'] );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $task->last_run, $after->last_run );
		$this->assertSame( array(), $this->child_calls );
	}

	/** A hidden task's poll must refuse before it can claim a connected site. */
	public function test_hidden_connected_task_poll_cannot_open_run(): void {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup', array( 'template' => 1 ), array( 'id' => $task->id ) );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, 0, time() );
		$this->assertSame( array( 'error' => 'Insufficient permissions. Is this task set by you?' ), $status );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $task->last_run, $after->last_run );
		$this->assertSame( array(), $this->child_calls );
	}

	/**
	 * A scheduled task keeps running for its connected sites; one disconnected
	 * site fails on its own during the run instead of blocking the whole task.
	 *
	 * @return void
	 */
	public function test_visible_task_start_guard_ignores_disconnected_site() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		\MainWP\Dashboard\MainWP_DB::instance()->update_website_sync_values( $site_id, array( 'sync_errors' => 'Connection failed' ) );

		$guard = new \ReflectionMethod( MainWP_Manage_Backups_Handler::class, 'manual_task_start_error' );
		$guard->setAccessible( true );
		$this->assertSame( '', $guard->invoke( null, MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) ) );
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
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
		$this->assertSame( 'This task is paused. Resume it before running it.', $status['error'] );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $task->last_run, $after->last_run );
		$this->assertSame( $task->completed, $after->completed );
		$this->assertSame( $task->last_run_manually, $after->last_run_manually );
		$this->assertGreaterThanOrEqual( (int) $after->last_run, (int) $after->completed );
		$this->assertSame( array(), $this->child_calls );
	}

	/**
	 * A duplicate click must reuse the recent manual close instead of starting another run.
	 *
	 * @return void
	 */
	public function test_run_status_absorbs_duplicate_initiate_inside_settle_window() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$done                   = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
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
		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'dtsFetched' => $closed ) );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp_backup_snapshot', array( 'created' => $closed ), array( 'id' => $done['sites'][0]['snapshot']['id'] ) );
		update_option( 'mainwp_backup_manual_settle_seconds', 60 );
		try {
			$calls     = $this->child_calls;
			$duplicate = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'completed', $duplicate['status'] );
			$this->assertSame( $closed, $duplicate['started'] );
			$this->assertSame( $closed, $duplicate['baseline'] );
			$this->assertGreaterThan( 0, $duplicate['click'] );
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $duplicate['started'], $duplicate['click'], false, $duplicate['baseline'] );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( $closed, $status['started'] );
			$this->assertSame( $done['sites'][0]['snapshot']['id'], $status['sites'][0]['snapshot']['id'] );

			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertSame( $closed, (int) $after->last_run );
			$this->assertSame( $closed, (int) $after->completed );
			$this->assertSame( $closed, (int) $after->last_run_manually );
			$this->assertSame( $calls, $this->child_calls );

			MainWP_DB_Backup::instance()->update_backup_errors( $task->id, '<ol><li>Upload failed.</li></ol>' );
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, 0, time() );
			$this->assertSame( 'running', $status['status'] );
			$this->assertGreaterThan( $closed, $status['started'] );
			$this->assertSame( 0, $status['click'] );
			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertGreaterThan( $closed, (int) $after->last_run_manually );
			$this->assertGreaterThan( count( $calls ), count( $this->child_calls ) );
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
	 * The gate checks group members in the saved scope, not ignored selections.
	 *
	 * @return void
	 */
	public function test_run_now_ignores_inaccessible_sites_outside_group_run_scope() {
		global $wpdb;
		list( $site_id, $task ) = $this->arrange_manual_task();
		$ignored_site           = $this->create_test_site( array( 'userid' => get_current_user_id() ) );
		$new_member             = $this->create_test_site( array( 'userid' => get_current_user_id() ) );
		$group_id               = 939393;
		$deny                   = function ( $allowed, $cap_type, $cap ) use ( $ignored_site, $new_member ) {
			return 'site' === $cap_type && in_array( (int) $cap, array( (int) $ignored_site, (int) $new_member ), true ) ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			foreach ( array( $site_id, $new_member ) as $member ) {
				$wpdb->insert(
					$wpdb->prefix . 'mainwp_wp_group',
					array(
						'wpid'    => $member,
						'groupid' => $group_id,
					)
				);
			}
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$task->id,
				array(
					'sites'            => (string) $ignored_site,
					'groups'           => (string) $group_id,
					'authorized_sites' => (string) $site_id,
				)
			);
			$this->assertFalse( \mainwp_current_user_can( 'site', $ignored_site ) );
			$this->assertFalse( \mainwp_current_user_can( 'site', $new_member ) );
			$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'running', $start['status'] );
			$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( array( (int) $site_id ), array_column( $status['sites'], 'id' ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_group', array( 'groupid' => $group_id ) );
		}
	}

	/**
	 * A continuation closes an open run when the owner's scope was revoked.
	 *
	 * @return void
	 */
	public function test_rescope_pause_closes_an_open_run() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		$start                  = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
		$task                   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed );
		$calls = $this->child_calls;
		$deny  = function () {
			return array();
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $deny );
		try {
			$this->assertSame( 'narrowed', MainWP_Manage_Backups_Handler::resolve_task_scope_pause( $task ) );
			$this->assertFalse( MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false ) );
			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertSame( 1, (int) $after->paused );
			$this->assertSame( (int) $task->last_run, (int) $after->completed );
			$this->assertSame( $start['started'], (int) $after->last_run );
			$this->assertSame( $calls, $this->child_calls );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $deny );
		}
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
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
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
		$done                   = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
		$this->assertSame( 'completed', $done['status'] );
		MainWP_DB_Backup::instance()->update_backup_errors( $task->id, '<ol><li>Upload failed.</li></ol>' );
		MainWP_DB_Backup::instance()->update_backup_notices( $task->id, '<ol><li>Full base used.</li></ol>' );
		$status = MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, $start['started'], $start['click'] );
		$this->assertSame( 'completed_with_errors', $status['status'] );
		$this->assertSame( 'Upload failed.', $status['errors'] );
		$this->assertSame( 'Full base used.', $status['notices'] );
		$this->assertSame( $done['sites'][0]['snapshot']['id'], $status['sites'][0]['snapshot']['id'] );
	}
	/**
	 * A second tab must not erase the first click's evidence for adopting a closed run.
	 */
	public function test_two_clicks_keep_separate_baselines_for_adoption() {
		list( $site_id, $task ) = $this->arrange_manual_task( true );
		$closed                 = time() - 120;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'          => $closed,
				'completed'         => $closed + 1,
				'last_run_manually' => 0,
				'backup_errors'     => '',
			)
		);
		update_option( 'mainwp_backup_max_concurrent', 1 );
		$token = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		$this->assertIsString( $token );
		$keys             = array();
		self::$fixed_time = time();
		try {
			$first = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'queued', $first['status'] );
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$task->id,
				array(
					'last_run'  => $closed + 2,
					'completed' => $closed + 3,
				)
			);
			$second = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'queued', $second['status'] );
			$this->assertSame( $first['click'], $second['click'] );
			$this->assertNotSame( $first['initiation_id'], $second['initiation_id'] );
			$this->assertSame( $closed, $first['baseline'] );
			$this->assertSame( $closed + 2, $second['baseline'] );
			foreach ( array( $first, $second ) as $handle ) {
				$key    = 'mainwp_backup_click_' . $task->id . '_' . get_current_user_id() . '_' . $handle['initiation_id'];
				$keys[] = $key;
				$this->assertSame(
					array(
						'baseline' => $handle['baseline'],
						'click'    => $handle['click'],
					),
					get_transient( $key )
				);
				$expires = (int) get_option( '_transient_timeout_' . $key );
				$this->assertGreaterThanOrEqual( $handle['click'] + 30 * MINUTE_IN_SECONDS, $expires );
				$this->assertLessThanOrEqual( time() + 30 * MINUTE_IN_SECONDS, $expires );
			}
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$task->id,
				array(
					'last_run'  => $closed + 2,
					'completed' => $closed + 3,
				)
			);
			$status = $this->poll_manual_task_endpoint(
				array(
					'task_id'       => $task->id,
					'started'       => 0,
					'click'         => $first['click'],
					'initiation_id' => $first['initiation_id'],
				)
			);
			$this->assertSame( 'completed', $status['status'] );
			$this->assertSame( $closed + 2, $status['started'] );
			$this->assertSame( $closed, get_transient( $keys[0] )['baseline'] );
			$this->assertSame( $closed + 2, get_transient( $keys[1] )['baseline'] );
			$this->assertSame( array(), $this->child_calls );
		} finally {
			self::$fixed_time = null;
			MainWP_Backup_Monitor::release_backup_slot( $token );
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}
	}

	/**
	 * A lost first non-queued status response must not cause a second run.
	 *
	 * @return void
	 */
	public function test_lost_first_non_queued_status_response_retains_baseline_until_acknowledged() {
		list( $site_id, $task ) = $this->arrange_manual_task();
		update_option( 'mainwp_backup_max_concurrent', 1 );
		update_option( 'mainwp_backup_manual_settle_seconds', 60 );
		$token = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		$this->assertIsString( $token );
		$key = '';
		try {
			$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'queued', $start['status'] );
			$this->assertSame( 0, $start['started'] );
			$key    = 'mainwp_backup_click_' . $task->id . '_' . get_current_user_id() . '_' . $start['initiation_id'];
			$stored = array(
				'baseline' => $start['baseline'],
				'click'    => $start['click'],
			);
			$this->assertSame( $stored, get_transient( $key ) );
			MainWP_Backup_Monitor::release_backup_slot( $token );
			$token   = null;
			$request = array(
				'task_id'       => $task->id,
				'started'       => 0,
				'click'         => $start['click'],
				'initiation_id' => $start['initiation_id'],
			);
			$lost    = $this->poll_manual_task_endpoint( $request );
			$this->assertSame( 'running', $lost['status'] );
			$this->assertGreaterThan( $stored['baseline'], $lost['started'] );
			$this->assertSame( $stored, get_transient( $key ), 'The client has not received the run identity yet.' );
			$this->assertCount( 1, $this->all_call_params( 'backup_task_create' ) );

			// Finish independently of the client, which still has the queued handle.
			$running = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			MainWP_Manage_Backups_Handler::execute_backup_task( $running, 5, false );
			$closed = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertGreaterThanOrEqual( $lost['started'], (int) $closed->completed );
			$calls            = $this->child_calls;
			self::$fixed_time = (int) $closed->completed + 61;

			$retry = $this->poll_manual_task_endpoint( $request );
			$this->assertSame( 'completed', $retry['status'] );
			$this->assertSame( $lost['started'], $retry['started'], 'The retry must adopt the original run after the settle window.' );
			$this->assertSame( $stored, get_transient( $key ) );
			$this->assertSame( $lost['started'], (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run );
			$this->assertSame( $calls, $this->child_calls, 'The retry must not start another child task.' );

			$request['started'] = $retry['started'];
			$acknowledged       = $this->poll_manual_task_endpoint( $request );
			$this->assertSame( 'completed', $acknowledged['status'] );
			$this->assertSame( $lost['started'], $acknowledged['started'] );
			$this->assertFalse( get_transient( $key ), 'Receipt of the run identity releases the baseline.' );
			$this->assertSame( $calls, $this->child_calls );
		} finally {
			self::$fixed_time = null;
			if ( null !== $token ) {
				MainWP_Backup_Monitor::release_backup_slot( $token );
			}
			delete_transient( $key );
			delete_option( 'mainwp_backup_max_concurrent' );
			delete_option( 'mainwp_backup_manual_settle_seconds' );
		}
	}

	/**
	 * A posted identity cannot substitute for this user's pending click.
	 */
	public function test_poll_rejects_absent_or_stale_stored_baseline() {
		list( $site_id, $task ) = $this->arrange_manual_task( true );
		$closed                 = time() - 120;
		$click                  = time();
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'          => $closed,
				'completed'         => $closed + 1,
				'last_run_manually' => 0,
				'backup_errors'     => '',
			)
		);
		update_option( 'mainwp_backup_max_concurrent', 1 );
		$token = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		$this->assertIsString( $token );
		try {
			$start = MainWP_Manage_Backups_Handler::run_backup_task_now( (int) $task->id );
			$this->assertSame( 'queued', $start['status'] );
			$key = 'mainwp_backup_click_' . $task->id . '_' . get_current_user_id() . '_' . $start['initiation_id'];
			$this->assertSame( $closed, get_transient( $key )['baseline'] );
			$this->assertSame( $start['click'], get_transient( $key )['click'] );
			$click = $start['click'];
			foreach ( array( 'absent', 'expired', 'other_click', 'other_user', 'stored_baseline', 'malformed_id', 'array_id', 'missing_id', 'valid' ) as $case ) {
				delete_transient( $key );
				if ( 'absent' !== $case ) {
					$stored_key = 'other_user' === $case ? 'mainwp_backup_click_' . $task->id . '_' . ( get_current_user_id() + 1 ) . '_' . $start['initiation_id'] : $key;
					set_transient(
						$stored_key,
						array(
							'baseline' => 'stored_baseline' === $case ? $closed : 1,
							'click'    => 'other_click' === $case ? $click - 1 : $click,
						),
						30 * MINUTE_IN_SECONDS
					);
					if ( 'expired' === $case ) {
						update_option( '_transient_timeout_' . $key, time() - 1 );
					}
				}
				$status = $this->poll_manual_task_endpoint(
					array(
						'task_id'       => $task->id,
						'started'       => 0,
						'click'         => $click,
						'baseline'      => 1,
						'initiation_id' => 'malformed_id' === $case ? '../invalid' : ( 'array_id' === $case ? array( $start['initiation_id'] ) : ( 'missing_id' === $case ? '' : $start['initiation_id'] ) ),
					)
				);
				$this->assertSame( 'valid' === $case ? 'completed' : 'queued', $status['status'], $case );
				$this->assertSame( 'valid' === $case ? $closed : 0, $status['started'], $case );
				if ( 'valid' === $case ) {
					$this->assertSame( 1, get_transient( $key )['baseline'], 'An unacknowledged response must retain its baseline.' );
				}
			}
			$this->assertSame( array(), $this->child_calls );
		} finally {
			MainWP_Backup_Monitor::release_backup_slot( $token );
			delete_transient( $key );
			delete_transient( 'mainwp_backup_click_' . $task->id . '_' . ( get_current_user_id() + 1 ) . '_' . $start['initiation_id'] );
		}
	}

	/**
	 * Exercise the request boundary because client baselines are untrusted there.
	 *
	 * @param array $post Request fields.
	 * @return array Decoded response.
	 */
	private function poll_manual_task_endpoint( array $post ): array {
		$original_post        = $_POST;
		$original_request     = $_REQUEST;
		$_POST                = $post;
		$nonce                = wp_create_nonce( 'mainwp_backuptask_run_status' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$throw                = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Post_Backup_Handler::instance()->mainwp_backuptask_run_status();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$_POST    = $original_post;
			$_REQUEST = $original_request;
		}
		return json_decode( $raw, true );
	}

	/**
	 * Continuation must not append the opening error on every tick.
	 */
	public function test_empty_site_error_is_written_only_on_opening_tick() {
		list( $site_id, $task ) = $this->arrange_manual_task( false );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'sites'  => '',
				'groups' => '',
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, substr_count( $task->backup_errors, 'No sites are selected' ) );
		$opening_errors = $task->backup_errors;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'completed'       => 0,
				'completed_sites' => wp_json_encode( array( $site_id => true ) ),
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $opening_errors, $task->backup_errors );
	}

	/**
	 * Parent selection holds this site until its run-keyed snapshot exists.
	 *
	 * @dataProvider x1_parent_interleavings
	 * @param bool $replace_parent Simulate a concurrent completed snapshot instead of prune.
	 */
	public function test_x1_incremental_parent_selection_interleaves_with_prune( bool $replace_parent ) {
		global $wpdb;
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$row = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'X1 incremental', 'daily', 'incremental', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0 );
		$run = time() - 100;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$row->id,
			array(
				'last_run'  => $run,
				'completed' => 0,
			)
		);
		$task = $this->make_task( MainWP_DB::instance()->get_website_by_id( $site_id ), $row->id, array( 'last_run' => $run ) );
		$dir  = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		wp_mkdir_p( $dir );
		copy( $this->manifest_fixture, $dir . 'parent.list' );
		$base = $this->record_base_snapshot( $site_id, $row->id );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'manifest_path' => $dir . 'parent.list' ), array( 'id' => $base ) );
		$prune     = null;
		$artifacts = $this->arrange_remote_artifacts();
		$this->mock_artifact_downloads();
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => function ( $params ) use ( $site_id, $task, $base, $replace_parent, &$prune ) {
					if ( 'incremental' === $params['type'] ) {
						if ( $replace_parent ) {
							$this->record_base_snapshot( $site_id, $task->id );
						} else {
							$prune = \MainWP\Dashboard\MainWP_Backup_Retention_Executor::instance()->run_prune(
								array(
									array(
										'snapshot_ids' => array( $base ),
										'reason'       => 'count',
									),
								),
								$task
							);
						}
					}
					return array(
						'task_id' => 'x1-delta',
						'phase'   => 'queued',
					);
				},
				'backup_task_run'    => array(
					'task_id'   => 'x1-delta',
					'phase'     => 'completed',
					'artifacts' => $artifacts,
					'file'      => 'files.list',
					'size'      => 40,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
				'backup_task_prune'  => array( 'result' => 'ok' ),
			)
		);
		$this->assertFalse( $this->run_cycle( $site_id, $task )['result'] );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$marker   = json_decode( $progress->last_error, true );
		if ( ! $replace_parent ) {
			$this->assertSame( 0, $prune['snapshots_pruned'] );
			$this->assertArrayHasKey( 'parent_snapshot_id', $marker );
			$this->assertSame( $base, $marker['parent_snapshot_id'], 'Admission persists the parent actually sent.' );
			$this->assertSame( array( $base ), $prune['remaining_ids'] );
			$this->assertFileExists( $dir . 'parent.list' );
		}
		$cycle = $this->run_cycle( $site_id, $task );
		$this->assertNull( $cycle['exception'] );
		if ( $replace_parent ) {
			$this->assertFalse( $cycle['result'], 'A delta cannot be recorded against a different parent.' );
			$this->assertSame( 'full', MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task->id )->type );
			$this->assertFalse( $this->run_cycle( $site_id, $task )['result'] );
			$this->assertSame( 'full', $this->last_call_params( 'backup_task_create' )['type'], 'The backstop goes straight to full.' );
		} else {
			$this->assertIsArray( $cycle['result'] );
			$delta = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, $task->id );
			$this->assertSame( $base, (int) $delta->parent_id );
			$after = \MainWP\Dashboard\MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $base, (int) $delta->id ),
						'reason'       => 'count',
					),
				),
				$task
			);
			$this->assertSame( 2, $after['snapshots_pruned'], 'A finished site in an open task is eligible again.' );
		}
	}

	/**
	 * Exercise the interleaving seam.
	 *
	 * @return array Parent held and parent changed interleavings.
	 */
	public static function x1_parent_interleavings(): array {
		return array(
			'prune'          => array( false ),
			'parent_changed' => array( true ),
		);
	}

	/**
	 * Only a live child handle or creating marker can hold incremental prune.
	 *
	 * @dataProvider s90_r1_terminal_progress
	 * @param string $child_id Child handle after failure or reset.
	 * @param string $phase Child phase.
	 */
	public function test_s90_r1_terminal_incremental_progress_does_not_hold_prune( string $child_id, string $phase ) {
		$site = $this->create_test_site();
		$db   = MainWP_DB_Backup::instance();
		$task = $db->add_backup_task( get_current_user_id(), 'Prune terminal progress', 'daily', 'incremental', '', (string) $site, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0 );
		$run  = time() - 100;
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'  => $run,
				'completed' => 0,
			)
		);
		$db->add_backup_task_progress( $task->id, $site, array() );
		$db->update_backup_task_progress(
			$task->id,
			$site,
			array(
				'dtsFetched'           => $run,
				'child_backup_task_id' => $child_id,
				'child_phase'          => $phase,
			)
		);
		$this->assertFalse( $db->has_inflight_incremental_for_site( $site ) );
	}

	/** A pending run_key repair cannot turn the in-flight read into a hold on every prune. */
	public function test_s90_r4_inflight_predicate_skips_run_key_while_repair_pending() {
		global $wpdb;
		$site = $this->create_test_site();
		$db   = MainWP_DB_Backup::instance();
		$task = $db->add_backup_task( get_current_user_id(), 'Prune run_key repair pending', 'daily', 'incremental', '', (string) $site, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0 );
		$run  = time() - 100;
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'  => $run,
				'completed' => 0,
			)
		);
		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		// Stands in for a snapshot table whose run_key column the repair has not added yet.
		$missing = static function ( $sql ) use ( $snapshot_table ) {
			return str_contains( $sql, $snapshot_table . ' s ' ) ? str_replace( 's.run_key', 's.run_key_missing', $sql ) : $sql;
		};
		update_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING, time() );
		add_filter( 'query', $missing );
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$idle = $db->has_inflight_incremental_for_site( $site );
			$db->add_backup_task_progress( $task->id, $site, array() );
			$db->update_backup_task_progress(
				$task->id,
				$site,
				array(
					'dtsFetched'           => $run,
					'child_backup_task_id' => MainWP_Backup_Executor::CREATING_MARKER_PREFIX . 's90-r4',
					'child_phase'          => '',
				)
			);
			$creating = $db->has_inflight_incremental_for_site( $site );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_filter( 'query', $missing );
			delete_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING );
		}
		$this->assertFalse( $idle, 'A site with nothing in flight must not read as held.' );
		$this->assertTrue( $creating, 'A mid-create site is still held.' );
	}

	/**
	 * Progress after failure and reset paths.
	 *
	 * @return array Terminal progress fixtures.
	 */
	public static function s90_r1_terminal_progress(): array {
		return array(
			'cleared handle' => array( '', 'queued' ),
			'failed phase'   => array( 'failed-child', 'failed' ),
			'terminal close' => array( '', 'failed' ),
		);
	}

	/** A paused incremental fleet holds only its mid-create site, including before the child returns its id. */
	public function test_s90_r1_paused_incremental_fleet_prunes_finished_unstarted_and_failed_sites() {
		global $wpdb;
		$sites = array();
		foreach ( array( 'a', 'b', 'c', 'd' ) as $name ) {
			$sites[ $name ] = $this->create_test_site();
			$this->give_site_real_privkey( $sites[ $name ] );
		}
		$db  = MainWP_DB_Backup::instance();
		$row = $db->add_backup_task( get_current_user_id(), 'Incremental fleet prune', 'daily', 'incremental', '', implode( ',', $sites ), '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0 );
		$run = time() - 100;
		$db->update_backup_task_with_values(
			$row->id,
			array(
				'last_run'  => $run,
				'completed' => 0,
			)
		);
		$task  = $this->make_task( MainWP_DB::instance()->get_website_by_id( $sites['a'] ), $row->id, array( 'last_run' => $run ) );
		$bases = array();
		foreach ( $sites as $name => $site ) {
			$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site );
			wp_mkdir_p( $dir );
			copy( $this->manifest_fixture, $dir . 'parent.list' );
			$bases[ $name ] = $this->record_base_snapshot( $site, $row->id );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'manifest_path' => $dir . 'parent.list' ), array( 'id' => $bases[ $name ] ) );
		}
		$db->add_backup_task_progress( $task->id, $sites['b'], array() );
		$db->update_backup_task_progress( $task->id, $sites['b'], array( 'dtsFetched' => $run - 1 ) );
		$artifacts = $this->arrange_remote_artifacts();
		$this->mock_artifact_downloads();
		$creating_hold = null;
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => function () use ( $db, $task, $sites, $bases, &$creating_hold ) {
					$progress = $db->get_backup_task_progress( $task->id, $sites['c'] );
					if ( $progress && MainWP_Backup_Executor::is_creating_marker( $progress->child_backup_task_id ) ) {
						$creating_hold = \MainWP\Dashboard\MainWP_Backup_Retention_Executor::instance()->run_prune(
							array(
								array(
									'snapshot_ids' => array( $bases['c'] ),
									'reason'       => 'count',
								),
							),
							$task
						);
					}
					return array(
						'task_id' => 's90-fleet-child',
						'phase'   => 'queued',
					);
				},
				'backup_task_run'    => array(
					'task_id'   => 's90-fleet-child',
					'phase'     => 'completed',
					'artifacts' => $artifacts,
					'file'      => 'files.list',
					'size'      => 40,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
				'backup_task_cancel' => array( 'result' => 'ok' ),
				'backup_task_prune'  => array( 'result' => 'ok' ),
			)
		);
		$this->assertFalse( $this->run_cycle( $sites['a'], $task )['result'] );
		$finished = $this->run_cycle( $sites['a'], $task );
		$this->assertNull( $finished['exception'] );
		$this->assertIsArray( $finished['result'] );
		$delta = MainWP_Backup_Snapshots::instance()->get_latest_complete( $sites['a'], $task->id );
		$this->assertSame( $sites['a'] . ':' . $task->id . ':c:s90-fleet-child', $delta->run_key );
		$this->assertFalse( $this->run_cycle( $sites['c'], $task )['result'] );
		$this->assertSame( array( $bases['c'] ), $creating_hold['remaining_ids'] );
		$this->assertStringContainsString( 'an incremental backup is running', implode( ' ', $creating_hold['errors'] ) );
		$this->assertFalse( $this->run_cycle( $sites['d'], $task )['result'] );
		MainWP_Manage_Backups_Handler::close_child_task_after_terminal_mark( $task->id, $sites['d'] );
		$failed = $db->get_backup_task_progress( $task->id, $sites['d'] );
		$this->assertSame( '', $failed->child_backup_task_id );
		$this->assertSame( 'failed', $failed->child_phase );
		$this->assertGreaterThanOrEqual( $run, (int) $failed->dtsFetched );
		$db->update_backup_task_with_values( $task->id, array( 'paused' => 1 ) );
		$candidates = array();
		foreach ( $bases as $name => $id ) {
			$candidates[] = array(
				'snapshot_ids' => 'a' === $name ? array( $id, (int) $delta->id ) : array( $id ),
				'reason'       => 'count',
			);
		}
		$result = \MainWP\Dashboard\MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $bases['d'] )->status, 'D failed terminally; it must no longer be held as running.' );
		$this->assertSame( array( $bases['c'] ), $result['remaining_ids'] );
		$this->assertSame( 4, $result['snapshots_pruned'] );
		$this->assertSame( 3, $result['pruned'] );
		$this->assertSame( array( (int) $delta->id, $bases['a'], $bases['b'], $bases['d'] ), $result['deleted_ids'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'an incremental backup is running', $result['errors'][0] );
		$after = $db->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $after->last_run, (int) $after->completed );
		$this->assertSame( 1, (int) $after->paused );
	}

	/** A paused full fleet run permits prune on finished and never-started sites. */
	public function test_x1_paused_multisite_full_run_does_not_hold_finished_or_unstarted_sites() {
		global $wpdb;
		$a = $this->create_test_site();
		$b = $this->create_test_site();
		$this->give_site_real_privkey( $a );
		$row = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'X1 fleet', 'daily', 'full', '', $a . ',' . $b, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0 );
		$run = time() - 100;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$row->id,
			array(
				'last_run'  => $run,
				'completed' => 0,
			)
		);
		$task = $this->make_task(
			MainWP_DB::instance()->get_website_by_id( $a ),
			$row->id,
			array(
				'type'     => 'full',
				'last_run' => $run,
			)
		);
		$dir  = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $b );
		wp_mkdir_p( $dir );
		copy( $this->manifest_fixture, $dir . 'unstarted.list' );
		$base_b = $this->record_base_snapshot( $b, $row->id );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'manifest_path' => $dir . 'unstarted.list' ), array( 'id' => $base_b ) );
		$artifacts = $this->arrange_remote_artifacts( true );
		$this->mock_artifact_downloads();
		$this->mock_child_actions(
			array(
				'backup_preflight'   => $this->preflight_response(),
				'backup_task_create' => array(
					'task_id' => 'x1-full',
					'phase'   => 'queued',
				),
				'backup_task_run'    => array(
					'task_id'   => 'x1-full',
					'phase'     => 'completed',
					'artifacts' => array( $artifacts[2], $artifacts[0] ),
					'file'      => 'backup-base.zip',
					'size'      => 400,
				),
				'delete_backup'      => array( 'result' => 'ok' ),
				'backup_task_prune'  => array( 'result' => 'ok' ),
			)
		);
		$reports = array();
		$pause   = function () use ( $task, $a, $base_b, &$reports ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'paused' => 1 ) );
			$latest    = MainWP_Backup_Snapshots::instance()->get_latest_complete( $a, $task->id );
			$reports[] = \MainWP\Dashboard\MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( (int) $latest->id ),
						'reason'       => 'count',
					),
					array(
						'snapshot_ids' => array( $base_b ),
						'reason'       => 'count',
					),
				),
				$task
			);
		};
		add_action( 'mainwp_backup_snapshot_recorded', $pause );
		try {
			$this->assertFalse( $this->run_cycle( $a, $task )['result'] );
			$cycle = $this->run_cycle( $a, $task );
		} finally {
			remove_action( 'mainwp_backup_snapshot_recorded', $pause );
		}
		$this->assertNull( $cycle['exception'] );
		$this->assertCount( 1, $reports );
		$this->assertSame( 2, $reports[0]['snapshots_pruned'] );
		$this->assertArrayHasKey( 'remaining_ids', $reports[0] );
		$this->assertSame( array(), $reports[0]['remaining_ids'] );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $after->last_run, (int) $after->completed );
	}
}
