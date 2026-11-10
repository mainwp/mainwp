<?php
/**
 * Stored layout metadata across cloud upload and restore staging.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';
require_once __DIR__ . '/test-backup-cloud-pull-manifest.php';

use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Local;
use MainWP\Dashboard\MainWP_Backup_Storage_Provider;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Keys_Manager;

/** Record fetched keys at the actual provider boundary. */
class V2_Cloud_Recording_Provider extends MainWP_Backup_Storage_Local {
	/**
	 * Fetched object keys.
	 *
	 * @var string[]
	 */
	public $fetched = array();
	/**
	 * Record a read before fetching its bytes.
	 *
	 * @param string $key Object key.
	 * @param string $local_path Destination file.
	 * @return bool Download result.
	 */
	public function get_object( string $key, string $local_path ): bool {
		$this->fetched[] = $key;
		return parent::get_object( $key, $local_path );
	}
}

/** Keep provider reads observable while staging through the real driver. */
class V2_Cloud_Recording_Driver extends MainWP_Backup_Restore_Transfer_Driver {
	/**
	 * Pinned provider.
	 *
	 * @var MainWP_Backup_Storage_Provider
	 */
	public $provider;
	/**
	 * Return the pinned provider.
	 *
	 * @param int $destination_id Destination id.
	 * @return ?MainWP_Backup_Storage_Provider Provider.
	 */
	protected function provider_for_destination( int $destination_id ): ?MainWP_Backup_Storage_Provider {
		return $this->provider;
	}
	/**
	 * Return the pinned provider.
	 *
	 * @param int $snapshot_id Snapshot id.
	 * @return ?MainWP_Backup_Storage_Provider Provider.
	 */
	protected function provider_for_snapshot( int $snapshot_id ): ?MainWP_Backup_Storage_Provider {
		return $this->provider;
	}
}

/** Cloud metadata integration tests. */
class MainWP_Backup_V2_Cloud_Metadata_Test extends MainWP_Abilities_Test_Case {
	use Backup_Private_Root_Fixture;

	/**
	 * Fixture directory.
	 *
	 * @var string
	 */
	private $dir;
	/**
	 * Local destination id.
	 *
	 * @var int
	 */
	private $destination_id;
	/**
	 * Source snapshot id.
	 *
	 * @var int
	 */
	private $snapshot_id;
	/**
	 * Archive fixture bytes.
	 *
	 * @var string
	 */
	private $content;
	/**
	 * Canonical v2 metadata.
	 *
	 * @var array
	 */
	private $metadata;

	/**
	 * Create isolated local and cloud fixtures.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_private_root();
		$this->dir = get_temp_dir() . 'mainwp-v2-cloud-' . uniqid();
		mkdir( $this->dir . '/source', 0755, true );
		mkdir( $this->dir . '/remote', 0755, true );
		remove_all_actions( 'mainwp_backup_snapshot_recorded' );
		remove_all_actions( 'mainwp_backup_upload_job_settled' );
		remove_all_actions( 'mainwp_backup_snapshot_offsite_verified' );
		add_filter(
			'mainwp_backup_upload_chunk_size',
			static function () {
				return 1024;
			}
		);
		add_filter(
			'mainwp_backup_upload_whole_file_threshold',
			static function () {
				return 2000;
			}
		);
		$this->destination_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Layout cloud fixture',
				'settings' => array( 'base_path' => $this->dir . '/remote' ),
			)
		);
		$descriptor           = array(
			'format'        => 2,
			'roots'         => array(
				array(
					'role'     => 'content',
					'coverage' => 'restore',
				),
			),
			'layout_id'     => hash( 'sha256', 'layout fixture' ),
			'core_policy'   => 'managed_preserve',
			'list_format'   => 2,
			'path_encoding' => 'role-prefix',
		);
		$this->metadata       = array(
			'backup_format'            => 2,
			'layout_descriptor'        => $descriptor,
			'layout_descriptor_sha256' => hash( 'sha256', wp_json_encode( $descriptor, JSON_UNESCAPED_SLASHES ) ),
		);
	}

	/**
	 * Remove this test’s files and encryption state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );
		$this->remove_tree( $this->dir );
		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * Remove a fixture directory without following links.
	 *
	 * @param string $path Fixture directory.
	 * @return void
	 */
	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = $path . '/' . $name;
			if ( is_dir( $child ) && ! is_link( $child ) ) {
				$this->remove_tree( $child );
			} else {
				unlink( $child );
			}
		}
		rmdir( $path );
	}

	/**
	 * Record and upload a snapshot through the real driver.
	 *
	 * @param bool $chunked Use chunked archive bytes.
	 * @param bool $encrypted Enable archive encryption.
	 * @param bool $v2 Supply v2 file metadata.
	 * @param bool $with_db Include an unchanged database artifact.
	 * @param bool $string_format Supply a digit-string version.
	 * @return object|null Completed upload job, or null for a refused snapshot.
	 */
	private function upload_fixture( $chunked, $encrypted, $v2 = true, $with_db = false, $string_format = false ) {
		if ( $encrypted ) {
			update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '1' );
			MainWP_Backup_Encryption::instance()->set_recovery_passphrase( 'fixture recovery passphrase' );
		}
		$this->create_test_site(
			array(
				'id'  => 9901,
				'url' => 'https://layout-fixture.example.com/',
			)
		);
		$this->content = str_repeat( 'stored-layout-content-', $chunked ? 170 : 10 );
		$archives      = array();
		$files         = array(
			'full'     => $v2 ? 'full.mwp2' : 'legacy.tar',
			'manifest' => 'manifest.json',
		);
		if ( $with_db ) {
			$files['db'] = 'database.sql';
		}
		foreach ( $files as $role => $file ) {
			$bytes = 'manifest' === $role ? '{"fixture":true}' : $this->content;
			$path  = $this->dir . '/source/' . $file;
			file_put_contents( $path, $bytes );
			$entry = array(
				'file' => $file,
				'role' => $role,
				'path' => $path,
				'size' => strlen( $bytes ),
			);
			if ( $v2 && 'db' !== $role ) {
				$entry['backup_format']            = $string_format ? '2' : 2;
				$entry['layout_descriptor_sha256'] = $this->metadata['layout_descriptor_sha256'];
				if ( 'manifest' === $role ) {
					$entry['layout_descriptor'] = $this->metadata['layout_descriptor'];
				}
			}
			$archives[] = $entry;
		}
		$this->snapshot_id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'site_id'       => 9901,
				'task_id'       => 0,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => $archives,
				'manifest_path' => $this->dir . '/source/manifest.json',
			)
		);
		if ( $string_format ) {
			$this->assertSame( 0, $this->snapshot_id );
			return null;
		}
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $this->snapshot_id, array( $this->destination_id ) );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 60 );
		$job = $store->get_jobs_for_snapshot( $this->snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $job->status, $job->last_error );
		return $job;
	}

	/**
	 * Locate the uploaded cloud manifest.
	 *
	 * @return string Manifest path.
	 */
	private function manifest_path() {
		return $this->dir . '/remote/9901-test-site/' . $this->snapshot_id . '/manifest.json';
	}

	/**
	 * Resolve a cloud restore after removing local artifacts.
	 *
	 * @return array Restore plan.
	 */
	private function remote_plan() {
		unlink( $this->dir . '/source/full.mwp2' );
		unlink( $this->dir . '/source/manifest.json' );
		if ( is_file( $this->dir . '/source/database.sql' ) ) {
			unlink( $this->dir . '/source/database.sql' );
		}
		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $this->snapshot_id, 9901 );
		$this->assertTrue( $resolved['ok'], (string) $resolved['error'] );
		return $resolved['plan'];
	}

	/**
	 * Cover whole files and chunks with and without encryption.
	 *
	 * @return array Cloud variants.
	 */
	public static function cloud_variants() {
		return array(
			'whole plain'      => array( false, false ),
			'chunks plain'     => array( true, false ),
			'whole encrypted'  => array( false, true ),
			'chunks encrypted' => array( true, true ),
		);
	}

	/**
	 * Keep the descriptor through upload, catalog and staging.
	 *
	 * @dataProvider cloud_variants
	 * @param bool $chunked Use chunks.
	 * @param bool $encrypted Encrypt uploaded bytes.
	 * @return void
	 */
	public function test_upload_catalog_stage_preserves_v2_metadata( $chunked, $encrypted ) {
		$job      = $this->upload_fixture( $chunked, $encrypted );
		$manifest = json_decode( file_get_contents( $this->manifest_path() ), true );
		$this->assertSame( 2, $manifest['format'] );
		foreach ( $this->metadata as $key => $value ) {
			$this->assertSame( $value, $manifest[ $key ] );
		}
		foreach ( array( $manifest['artifacts'], $job->checkpoint['artifacts'] ) as $artifacts ) {
			foreach ( $artifacts as $artifact ) {
				$this->assertSame( 2, $artifact['backup_format'] );
				$this->assertSame( $this->metadata['layout_descriptor_sha256'], $artifact['layout_descriptor_sha256'] );
				if ( 'manifest' === $artifact['role'] ) {
					$this->assertSame( $this->metadata['layout_descriptor'], $artifact['layout_descriptor'] );
				}
			}
		}
		if ( $encrypted ) {
			$raw  = file_get_contents( $this->manifest_path() );
			$hmac = file_get_contents( $this->manifest_path() . '.hmac' );
			$this->assertSame( MainWP_Backup_Encryption::instance()->manifest_hmac( $raw, MainWP_Backup_Encryption::instance()->load_kek() ), $hmac );
			$this->assertNotEmpty( $manifest['recovery'] );
		}
		$plan = $this->remote_plan();
		$this->assertSame( 2, $plan[0]['backup_format'] );
		$driver  = new MainWP_Backup_Restore_Transfer_Driver();
		$outcome = $driver->stage_artifacts( 7771884, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'], (string) ( $outcome['error'] ?? '' ) );
		$this->assertSame( $this->content, file_get_contents( $plan[0]['staged_path'] ) );
		$this->assertSame( $this->metadata['layout_descriptor_sha256'], $plan[0]['layout_descriptor_sha256'] );
	}

	/**
	 * Stage the unchanged database beside v2 file artifacts.
	 *
	 * @dataProvider cloud_variants
	 * @param bool $chunked Use chunks.
	 * @param bool $encrypted Encrypt uploaded bytes.
	 * @return void
	 */
	public function test_mixed_v2_files_and_v1_database_stage_roundtrip( $chunked, $encrypted ) {
		$job      = $this->upload_fixture( $chunked, $encrypted, true, true );
		$manifest = json_decode( file_get_contents( $this->manifest_path() ), true );
		$this->assertArrayNotHasKey( 'backup_format', $manifest['artifacts'][2] );
		$this->assertArrayNotHasKey( 'backup_format', $job->checkpoint['artifacts'][2] );
		$plan = $this->remote_plan();
		$this->assertCount( 3, $plan );
		$this->assertArrayNotHasKey( 'backup_format', $plan[2] );
		$driver  = new MainWP_Backup_Restore_Transfer_Driver();
		$outcome = $driver->stage_artifacts( 7771884, $plan, microtime( true ) + 60 );
		$this->assertTrue( $outcome['done'], (string) ( $outcome['error'] ?? '' ) );
		$this->assertSame( $this->content, file_get_contents( $plan[2]['staged_path'] ) );
		$this->assertSame( 2, $manifest['format'] );
	}

	/**
	 * Refuse a corrupt resumed checkpoint before uploading any objects.
	 *
	 * @return void
	 */
	public function test_resumed_upload_refuses_corrupt_checkpoint_before_writes() {
		$job        = $this->upload_fixture( false, false );
		$store      = MainWP_Backup_Upload_Job_Store::instance();
		$checkpoint = $job->checkpoint;
		$checkpoint['artifacts'][0]['layout_descriptor_sha256'] = str_repeat( 'f', 64 );
		foreach ( $checkpoint['artifacts'] as &$artifact ) {
			$artifact['done'] = false;
		}
		unset( $artifact );
		$checkpoint['manifest_submitted'] = false;
		$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
		$this->assertTrue( $store->set_status( (int) $job->id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING ) );
		$prefix = $this->dir . '/remote/9901-test-site/' . $this->snapshot_id;
		$this->remove_tree( $prefix );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 60 );
		$after = $store->get_jobs_for_snapshot( $this->snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $after->status );
		$this->assertStringContainsString( 'Full site backup did not complete.', $after->last_error );
		$this->assertDirectoryDoesNotExist( $prefix );
	}

	/**
	 * Refuse a digit-string format before storing or uploading the snapshot.
	 *
	 * @return void
	 */
	public function test_supplied_string_format_keeps_metadata_on_upload() {
		$this->assertNull( $this->upload_fixture( false, false, true, false, true ) );
		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $this->snapshot_id ) );
		$this->assertFileDoesNotExist( $this->manifest_path() );
	}

	/**
	 * Cover plans and checkpoints that cannot use legacy pull.
	 *
	 * @return array Version cases.
	 */
	public static function direct_pull_versions() {
		return array(
			'v2'                        => array( 2, null ),
			'unknown'                   => array( 99, null ),
			'unknown upload checkpoint' => array( 1, 99 ),
		);
	}

	/**
	 * Refuse direct pull without filling or sending a payload.
	 *
	 * @dataProvider direct_pull_versions
	 * @param int      $version Stored plan version.
	 * @param int|null $upload_version Optional upload checkpoint version.
	 * @return void
	 */
	public function test_v2_and_unknown_resumed_plans_never_generate_direct_pull( $version, $upload_version ) {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'cloud_restore_pull_support', '1' );
		$website                = MainWP_DB::instance()->get_website_by_id( $site_id );
		$driver                 = new Pull_Manifest_Test_Driver();
		$driver->provider       = new Presignable_Stub_Provider();
		$driver->upload_records = array(
			42 => array(
				'full.mwp2' => array(
					'file'   => 'full.mwp2',
					'mode'   => 'whole',
					'size'   => 100,
					'sha256' => str_repeat( 'a', 64 ),
				),
			),
		);
		if ( null !== $upload_version ) {
			$driver->upload_records[42]['full.mwp2']['backup_format'] = $upload_version;
		}
		$entry                  = array_merge(
			array(
				'role'        => 'full',
				'file'        => 's42-full.mwp2',
				'source_file' => 'full.mwp2',
				'source'      => 'remote',
				'snapshot_id' => 42,
				'size'        => 100,
				'sha256'      => '',
				'adopt_only'  => false,
			),
			$this->metadata
		);
		$entry['backup_format'] = $version;
		$plan                   = array( $entry );
		$this->assertNull( $driver->generate_pull_manifest( $plan ) );
		$this->assertFalse( $driver->can_use_cloud_pull( $website, $plan, array() ) );
		$this->assertSame( '', $plan[0]['sha256'] );
	}

	/**
	 * Reject unsupported plans before staging and transfer.
	 *
	 * @return void
	 */
	public function test_unknown_plan_format_refused_before_stage_or_transfer() {
		$path = $this->dir . '/source/unknown.mwp2';
		file_put_contents( $path, 'fixture archive' );
		$plan    = array(
			array(
				'backup_format' => 99,
				'file'          => 'unknown.mwp2',
				'role'          => 'full',
				'source'        => 'local',
				'local_path'    => $path,
				'snapshot_id'   => 0,
				'size'          => 15,
				'staged_path'   => $path,
			),
		);
		$driver  = new MainWP_Backup_Restore_Transfer_Driver();
		$outcome = $driver->stage_artifacts( 7771884, $plan, microtime( true ) + 60 );
		$this->assertSame( 'mainwp_backup_format_unsupported', $outcome['error_code'] );
		$cursor  = array();
		$outcome = $driver->transfer_artifacts( (object) array( 'id' => 9901 ), 'fixture-restore', $plan, $cursor, microtime( true ) - 1 );
		$this->assertSame( 'mainwp_backup_format_unsupported', $outcome['error_code'] );
	}

	/**
	 * Cover invalid cloud metadata for whole files and chunks.
	 *
	 * @return array Corruption cases.
	 */
	public static function invalid_cloud_metadata() {
		$rows = array();
		foreach ( array( false, true ) as $chunked ) {
			foreach ( array( 'unknown', 'missing', 'digest', 'descriptor', 'artifact', 'artifact_format' ) as $fault ) {
				$rows[ ( $chunked ? 'chunked ' : 'whole ' ) . $fault ] = array( $chunked, $fault );
			}
		}
		return $rows;
	}

	/**
	 * Reject unbound cloud metadata before downloading bytes.
	 *
	 * @dataProvider invalid_cloud_metadata
	 * @param bool   $chunked Use chunks.
	 * @param string $fault Cloud metadata mutation.
	 * @return void
	 */
	public function test_bad_cloud_metadata_refused_before_artifact_download( $chunked, $fault ) {
		$this->upload_fixture( $chunked, false );
		$manifest = json_decode( file_get_contents( $this->manifest_path() ), true );
		if ( 'unknown' === $fault ) {
			$manifest['format'] = 99;
		} elseif ( 'missing' === $fault ) {
			unset( $manifest['layout_descriptor'] );
		} elseif ( 'digest' === $fault ) {
			$manifest['layout_descriptor_sha256'] = str_repeat( 'f', 64 );
		} elseif ( 'descriptor' === $fault ) {
			$manifest['layout_descriptor']['layout_id'] = hash( 'sha256', 'other layout' );
			$manifest['layout_descriptor_sha256']       = hash( 'sha256', wp_json_encode( $manifest['layout_descriptor'], JSON_UNESCAPED_SLASHES ) );
			foreach ( $manifest['artifacts'] as &$artifact ) {
				$artifact['layout_descriptor_sha256'] = $manifest['layout_descriptor_sha256'];
				if ( 'manifest' === $artifact['role'] ) {
					$artifact['layout_descriptor'] = $manifest['layout_descriptor'];
				}
			}
			unset( $artifact );
		} elseif ( 'artifact_format' === $fault ) {
			unset( $manifest['artifacts'][0]['backup_format'] );
		} else {
			$manifest['artifacts'][0]['layout_descriptor_sha256'] = str_repeat( 'f', 64 );
		}
		file_put_contents( $this->manifest_path(), wp_json_encode( $manifest ) );
		$plan             = $this->remote_plan();
		$driver           = new V2_Cloud_Recording_Driver();
		$driver->provider = new V2_Cloud_Recording_Provider( array( 'base_path' => $this->dir . '/remote' ) );
		$outcome          = $driver->stage_artifacts( 7771884, $plan, microtime( true ) + 60 );
		$this->assertFalse( $outcome['done'] );
		foreach ( $driver->provider->fetched as $key ) {
			$this->assertStringEndsWith( '/manifest.json', $key );
		}
		$this->assertSame( 'unknown' === $fault ? 'mainwp_backup_format_unsupported' : 'mainwp_backup_file_corrupt', $outcome['error_code'] );
		$this->assertFalse( $outcome['retryable'] );
		$this->assertFileDoesNotExist( $driver->staging_dir( 7771884 ) . $plan[0]['file'] );
	}

	/**
	 * Compare legacy cloud JSON with its existing byte layout.
	 *
	 * @dataProvider cloud_variants
	 * @param bool $chunked Use chunks.
	 * @param bool $encrypted Encrypt uploaded bytes.
	 * @return void
	 */
	public function test_v1_cloud_json_keeps_existing_bytes( $chunked, $encrypted ) {
		$job      = $this->upload_fixture( $chunked, $encrypted, false );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $this->snapshot_id );
		$expected = array(
			'format'      => 1,
			'snapshot_id' => $this->snapshot_id,
			'task_id'     => 0,
			'site_id'     => 9901,
			'type'        => 'full',
			'created'     => (int) $snapshot->created,
			'parent_id'   => null,
			'artifacts'   => array(),
		);
		foreach ( $snapshot->archives as $entry ) {
			$expected['artifacts'][] = array(
				'file'   => $entry['file'],
				'role'   => $entry['role'],
				'size'   => $entry['size'],
				'mode'   => $entry['size'] >= 2000 ? 'chunked' : 'whole',
				'sha256' => hash_file( 'sha256', $entry['path'] ),
			);
			$index                   = count( $expected['artifacts'] ) - 1;
			if ( $entry['size'] >= 2000 ) {
				$expected['artifacts'][ $index ]['chunks'] = array_map(
					static function ( $chunk ) {
						return array(
							'hash'   => $chunk['hash'],
							'algo'   => $chunk['algo'],
							'offset' => $chunk['offset'],
							'length' => $chunk['length'],
						);
					},
					$job->checkpoint['artifacts'][ $index ]['chunks']
				);
			} else {
				$expected['artifacts'][ $index ]['key'] = 'files/' . $entry['file'];
			}
		}
		if ( $encrypted ) {
			$expected['encryption'] = array(
				'enc'            => 1,
				'cipher'         => 'aes-256-gcm',
				'overhead'       => 28,
				'wrapped_dek'    => $snapshot->encryption['wrapped_dek'],
				'kek_version_id' => $snapshot->encryption['kek_version_id'],
			);
			$expected['recovery']   = MainWP_Backup_Encryption::instance()->get_recovery_blob();
			$expected['site_url']   = MainWP_DB::instance()->get_website_by_id( 9901 )->url;
		}
		$actual = file_get_contents( $this->manifest_path() );
		$this->assertSame( wp_json_encode( $expected ), $actual );
		if ( getenv( 'MWP1884_GOLDEN_FILE' ) ) {
			$normalized                = json_decode( $actual, true );
			$normalized['snapshot_id'] = 1;
			$normalized['created']     = 1234567890;
			if ( $encrypted ) {
				$normalized['encryption']['wrapped_dek']    = 'fixture wrapped dek';
				$normalized['encryption']['kek_version_id'] = 'fixture key version';
				foreach ( $normalized['recovery'] as $key => $value ) {
					if ( is_string( $value ) ) {
						$normalized['recovery'][ $key ] = 'fixture ' . $key;
					}
				}
			}
			file_put_contents( getenv( 'MWP1884_GOLDEN_FILE' ) . '-' . (int) $chunked . '-' . (int) $encrypted . '.json', wp_json_encode( $normalized ) );
		}
	}
}
