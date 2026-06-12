<?php
/**
 * Cloud-pull manifest tests: the gates deciding when a restore may let
 * the child pull artifacts straight from cloud storage, and the
 * presigned manifest assembly with its all-or-nothing rejection rules.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Presignable_Provider;
use MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver;
use MainWP\Dashboard\MainWP_Backup_Storage_Local;
use MainWP\Dashboard\MainWP_Backup_Storage_Provider;
use MainWP\Dashboard\MainWP_DB;

/**
 * A presignable provider stub with a scriptable scheme and support
 * flag.
 */
class Presignable_Stub_Provider extends MainWP_Backup_Storage_Local implements MainWP_Backup_Presignable_Provider { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * URL scheme the stub presigns with.
	 *
	 * @var string
	 */
	public $scheme = 'https';

	/**
	 * Scripted support flag.
	 *
	 * @var bool
	 */
	public $supports = true;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array( 'base_path' => get_temp_dir() ) );
	}

	/**
	 * Scripted capability.
	 *
	 * @return bool
	 */
	public function supports_presigned_get(): bool {
		return $this->supports;
	}

	/**
	 * Deterministic stub URL.
	 *
	 * @param string $key Object key.
	 * @param int    $ttl Lifetime.
	 *
	 * @return string
	 */
	public function presigned_get_url( string $key, int $ttl ): string {
		return $this->scheme . '://bucket.example/' . $key . '?X-Amz-Expires=' . $ttl . '&X-Amz-Signature=stub';
	}
}

/**
 * Transfer driver with the provider and upload records pinned.
 */
class Pull_Manifest_Test_Driver extends MainWP_Backup_Restore_Transfer_Driver { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.

	/**
	 * Pinned provider for every snapshot.
	 *
	 * @var MainWP_Backup_Storage_Provider|null
	 */
	public $provider = null;

	/**
	 * Scripted upload records by snapshot id.
	 *
	 * @var array<int,array>
	 */
	public $upload_records = array();

	/**
	 * Pinned provider.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return MainWP_Backup_Storage_Provider|null
	 */
	protected function provider_for_snapshot( int $snapshot_id ): ?MainWP_Backup_Storage_Provider {
		return $this->provider;
	}

	/**
	 * Scripted records.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return array
	 */
	protected function upload_artifacts_for_snapshot( int $snapshot_id ): array {
		return isset( $this->upload_records[ $snapshot_id ] ) ? $this->upload_records[ $snapshot_id ] : array();
	}

	/**
	 * Pinned prefix.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return string
	 */
	protected function snapshot_prefix( int $snapshot_id ): string {
		return '4/' . $snapshot_id . '/';
	}
}

/**
 * Class MainWP_Backup_Cloud_Pull_Manifest_Test
 */
class MainWP_Backup_Cloud_Pull_Manifest_Test extends MainWP_Abilities_Test_Case { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test doubles above.

	/**
	 * Driver under test.
	 *
	 * @var Pull_Manifest_Test_Driver
	 */
	private $driver;

	/**
	 * Build the driver with a presignable stub.
	 */
	public function set_up() {
		parent::set_up();
		$this->driver           = new Pull_Manifest_Test_Driver();
		$this->driver->provider = new Presignable_Stub_Provider();
	}

	/**
	 * A site with the cloud-pull capability stamped (or cleared).
	 *
	 * @param bool $supported Whether the child advertised the capability.
	 *
	 * @return object Website.
	 */
	private function website( $supported = true ) {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'cloud_restore_pull_support', $supported ? '1' : '' );
		return MainWP_DB::instance()->get_website_by_id( $site_id );
	}

	/**
	 * A remote-sourced plan entry.
	 *
	 * @param array $over Overrides.
	 *
	 * @return array
	 */
	private function plan_entry( array $over = array() ) {
		return array_merge(
			array(
				'role'        => 'full',
				'file'        => 's42-full.zip',
				'source_file' => 'full.zip',
				'size'        => 0,
				'sha256'      => '',
				'snapshot_id' => 42,
				'source'      => 'remote',
				'local_path'  => '',
				'remote_key'  => '4/42/files/full.zip',
				'adopt_only'  => false,
			),
			$over
		);
	}

	/**
	 * The whole-file upload record for snapshot 42.
	 *
	 * @param array $over Overrides.
	 *
	 * @return array
	 */
	private function upload_record( array $over = array() ) {
		return array(
			42 => array(
				'full.zip' => array_merge(
					array(
						'file'   => 'full.zip',
						'mode'   => 'whole',
						'size'   => 1000,
						'sha256' => str_repeat( 'a', 64 ),
					),
					$over
				),
			),
		);
	}

	/**
	 * Every gate must hold for cloud pull: not yet tried, child
	 * capability stamped, all artifacts remote, provider presignable.
	 */
	public function test_can_use_cloud_pull_gates() {
		$this->driver->upload_records = $this->upload_record();
		$plan                         = array( $this->plan_entry() );

		$this->assertTrue( $this->driver->can_use_cloud_pull( $this->website(), $plan, array() ) );

		$this->assertFalse(
			$this->driver->can_use_cloud_pull( $this->website(), $plan, array( 'cloud_pull_tried' => true ) ),
			'A job that already tried cloud pull must stage instead.'
		);

		$this->assertFalse(
			$this->driver->can_use_cloud_pull( $this->website( false ), $plan, array() ),
			'A child without the capability must stage instead.'
		);

		$local = array(
			$this->plan_entry(),
			$this->plan_entry(
				array(
					'file'        => 's42-part.zip',
					'source_file' => 'part.zip',
					'source'      => 'local',
					'local_path'  => '/tmp/part.zip',
				)
			),
		);
		$this->assertFalse(
			$this->driver->can_use_cloud_pull( $this->website(), $local, array() ),
			'A plan with any non-remote artifact must stage instead.'
		);

		$adopt_only = array( $this->plan_entry( array( 'adopt_only' => true, 'source' => 'local' ) ) );
		$this->assertFalse(
			$this->driver->can_use_cloud_pull( $this->website(), $adopt_only, array() ),
			'A plan with nothing to pull must stage instead.'
		);

		$this->driver->provider->supports = false;
		$this->assertFalse(
			$this->driver->can_use_cloud_pull( $this->website(), $plan, array() ),
			'A provider without presign support must stage instead.'
		);

		$this->driver->provider = new MainWP_Backup_Storage_Local( array( 'base_path' => get_temp_dir() ) );
		$this->assertFalse(
			$this->driver->can_use_cloud_pull( $this->website(), $plan, array() ),
			'A non-presignable provider must stage instead.'
		);
	}

	/**
	 * The manifest carries a presigned URL per whole artifact and per
	 * chunk, fills each plan entry's expected hash from the upload
	 * record, and skips adopt-only entries.
	 */
	public function test_generate_pull_manifest_whole_and_chunked() {
		$chunk_hash                   = hash( 'xxh128', 'piece' );
		$this->driver->upload_records = array(
			42 => array(
				'full.zip' => array(
					'file'   => 'full.zip',
					'mode'   => 'whole',
					'size'   => 1000,
					'sha256' => str_repeat( 'a', 64 ),
				),
				'big.zip'  => array(
					'file'   => 'big.zip',
					'mode'   => 'chunked',
					'size'   => 5000,
					'sha256' => str_repeat( 'b', 64 ),
					'chunks' => array(
						array(
							'hash' => $chunk_hash,
							'algo' => 'xxh128',
						),
					),
				),
			),
		);

		$plan = array(
			$this->plan_entry(),
			$this->plan_entry(
				array(
					'file'        => 's42-big.zip',
					'source_file' => 'big.zip',
					'source'      => 'remote_chunked',
					'remote_key'  => '',
				)
			),
			$this->plan_entry(
				array(
					'file'       => 's42-old.zip',
					'adopt_only' => true,
					'source'     => 'local',
				)
			),
		);

		$manifest = $this->driver->generate_pull_manifest( $plan );

		$this->assertNotNull( $manifest );
		$this->assertCount( 2, $manifest['artifacts'] );

		$whole = $manifest['artifacts'][0];
		$this->assertSame( 's42-full.zip', $whole['file'] );
		$this->assertSame( str_repeat( 'a', 64 ), $whole['sha256'] );
		$this->assertSame( 1000, $whole['size'] );
		$this->assertStringStartsWith( 'https://bucket.example/4/42/files/full.zip?', $whole['url'] );
		$this->assertStringContainsString( 'X-Amz-Expires=' . MainWP_Backup_Restore_Transfer_Driver::PRESIGN_TTL_SECONDS, $whole['url'] );

		$chunked = $manifest['artifacts'][1];
		$this->assertSame( 's42-big.zip', $chunked['file'] );
		$this->assertCount( 1, $chunked['chunks'] );
		$this->assertSame( $chunk_hash, $chunked['chunks'][0]['hash'] );
		$this->assertSame( 'xxh128', $chunked['chunks'][0]['algo'] );
		$this->assertStringStartsWith( 'https://bucket.example/4/42/chunks/' . $chunk_hash . '.bin?', $chunked['chunks'][0]['url'] );

		$this->assertSame( str_repeat( 'a', 64 ), $plan[0]['sha256'], 'The plan entry must learn its expected hash.' );
		$this->assertSame( str_repeat( 'b', 64 ), $plan[1]['sha256'] );
		$this->assertSame( '', $plan[2]['sha256'], 'Adopt-only entries are untouched.' );
	}

	/**
	 * An upload recorded before artifact hashes existed rejects the
	 * whole manifest, never a partial one.
	 */
	public function test_generate_pull_manifest_null_without_recorded_hash() {
		$this->driver->upload_records = $this->upload_record( array( 'sha256' => '' ) );

		$plan = array( $this->plan_entry() );

		$this->assertNull( $this->driver->generate_pull_manifest( $plan ) );
		$this->assertSame( '', $plan[0]['sha256'] );
	}

	/**
	 * Presigned URLs that are not https reject the manifest, matching
	 * the child's own validation.
	 */
	public function test_generate_pull_manifest_null_on_http_url() {
		$this->driver->upload_records   = $this->upload_record();
		$this->driver->provider->scheme = 'http';

		$plan = array( $this->plan_entry() );

		$this->assertNull( $this->driver->generate_pull_manifest( $plan ) );
	}
}
