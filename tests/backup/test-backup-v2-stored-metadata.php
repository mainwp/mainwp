<?php
/**
 * Backup format metadata storage and restore catalog tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;

/** Tests the stored format through the snapshot and catalog paths. */
class MainWP_Backup_V2_Stored_Metadata_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Fixture directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Connected source site.
	 *
	 * @var int
	 */
	private $site_id;

	/** Create a connected site and opaque local artifacts. */
	public function set_up() {
		parent::set_up();
		$this->site_id = $this->create_test_site();
		$this->dir     = sys_get_temp_dir() . '/mainwp-v2-stored-' . uniqid();
		mkdir( $this->dir );
	}

	/** Remove fixture bytes. */
	public function tear_down() {
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Build the reviewed descriptor in canonical field order.
	 *
	 * @return array
	 */
	private function descriptor() {
		return array(
			'format'        => 2,
			'roots'         => array(
				array(
					'role'     => 'content',
					'coverage' => 'restore',
				),
				array(
					'role'     => 'config',
					'coverage' => 'recovery_only',
				),
				array(
					'role'     => 'site',
					'coverage' => 'preserved',
				),
				array(
					'role'     => 'uploads',
					'coverage' => 'restore',
				),
				array(
					'role'     => 'plugins',
					'coverage' => 'restore',
				),
				array(
					'role'     => 'mu_plugins',
					'coverage' => 'restore',
				),
			),
			'layout_id'     => str_repeat( 'b', 64 ),
			'core_policy'   => 'managed_preserve',
			'list_format'   => 2,
			'path_encoding' => 'role-prefix',
		);
	}

	/**
	 * Build file payload and manifest metadata supplied by a Child.
	 *
	 * @return array
	 */
	private function archives() {
		$descriptor = $this->descriptor();
		$digest     = hash( 'sha256', wp_json_encode( $descriptor ) );
		return array(
			array(
				'file'                     => 'backup.mwp2',
				'role'                     => 'full',
				'backup_format'            => 2,
				'layout_descriptor_sha256' => $digest,
			),
			array(
				'file'                     => 'files.list',
				'role'                     => 'manifest',
				'backup_format'            => 2,
				'layout_descriptor'        => $descriptor,
				'layout_descriptor_sha256' => $digest,
			),
		);
	}

	/**
	 * Store actual snapshot rows, optionally bypassing new-writer validation.
	 *
	 * @param array $archives Archive metadata.
	 * @param array $extra Snapshot overrides.
	 * @param bool  $raw Whether to simulate an already stored row.
	 * @return int Snapshot id.
	 */
	private function record( array $archives, array $extra = array(), bool $raw = false ) {
		global $wpdb;
		foreach ( $archives as &$archive ) {
			$archive['path'] = $this->dir . '/' . $archive['file'];
			file_put_contents( $archive['path'], 'fixture-' . $archive['file'] );
			$archive['size'] = filesize( $archive['path'] );
		}
		unset( $archive );
		$data = array_merge(
			array(
				'site_id'       => $this->site_id,
				'task_id'       => 0,
				'type'          => 'full',
				'archives'      => $archives,
				'manifest_path' => $this->dir . '/files.list',
			),
			$extra
		);
		if ( ! $raw ) {
			return MainWP_Backup_Snapshots::instance()->record_snapshot( $data );
		}
		$data['archives'] = wp_json_encode( $archives );
		$data['created']  = time();
		$data['status']   = 'complete';
		$wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), $data ); // phpcs:ignore WordPress.DB -- stored fixture.
		return (int) $wpdb->insert_id;
	}

	/** A descriptor survives a real snapshot row and catalog reconstruction without walk metadata. */
	public function test_v2_snapshot_catalog_round_trip() {
		$id       = $this->record( $this->archives() );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( $this->descriptor(), $snapshot->archives[1]['layout_descriptor'] );
		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id, $this->site_id );
		$this->assertTrue( $resolved['ok'], $resolved['error'] ?? '' );
		$this->assertCount( 2, $resolved['plan'] );
		foreach ( $resolved['plan'] as $entry ) {
			$this->assertSame( 2, $entry['backup_format'] );
			$this->assertSame( $this->descriptor(), $entry['layout_descriptor'] );
			$this->assertSame( hash( 'sha256', wp_json_encode( $this->descriptor() ) ), $entry['layout_descriptor_sha256'] );
		}
	}

	/** Ancestor payloads retain their own snapshot descriptor. */
	public function test_v2_ancestor_descriptor_survives_catalog() {
		$base            = $this->record( $this->archives() );
		$next            = $this->archives();
		$next[0]['file'] = 'part001.mwp2';
		$next[0]['role'] = 'part';
		$id              = $this->record(
			$next,
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		$resolved        = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id, $this->site_id );
		$this->assertTrue( $resolved['ok'], $resolved['error'] ?? '' );
		$this->assertCount( 3, $resolved['plan'] );
		$this->assertSame( $base, $resolved['plan'][0]['snapshot_id'] );
		$this->assertSame( $this->descriptor(), $resolved['plan'][0]['layout_descriptor'] );
	}

	/** Unknown stored formats fail admission before staging. */
	public function test_unknown_stored_format_is_refused() {
		$archives                     = $this->archives();
		$archives[0]['backup_format'] = 9;
		$archives[1]['backup_format'] = 9;
		$id                           = $this->record( $archives, array(), true );
		$resolved                     = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id, $this->site_id );
		$this->assertFalse( $resolved['ok'] );
		$this->assertSame( 'mainwp_backup_format_unsupported', $resolved['code'] );
	}

	/**
	 * Missing or inconsistent descriptors cannot become restorable operations.
	 *
	 * @dataProvider invalid_metadata_cases
	 * @param string $corruption Corruption to plant in stored metadata.
	 */
	public function test_invalid_stored_descriptor_is_refused( $corruption ) {
		$archives = $this->archives();
		if ( 'missing' === $corruption ) {
			unset( $archives[1]['layout_descriptor'] );
		} elseif ( 'multiple_manifests' === $corruption ) {
			$archives[] = $archives[1];
		} elseif ( 'digest' === $corruption ) {
			$archives[1]['layout_descriptor_sha256'] = 'invalid-digest';
		} elseif ( 'artifact' === $corruption ) {
			$archives[0]['layout_descriptor_sha256'] = str_repeat( 'c', 64 );
		} elseif ( 'forged' === $corruption ) {
			$archives[1]['layout_descriptor']['layout_id'] = str_repeat( 'd', 64 );
		} elseif ( 'role' === $corruption ) {
			$archives[1]['layout_descriptor']['roots'][0]['role'] = 'outside';
			$archives[1]['layout_descriptor_sha256']              = hash( 'sha256', wp_json_encode( $archives[1]['layout_descriptor'] ) );
			$archives[0]['layout_descriptor_sha256']              = $archives[1]['layout_descriptor_sha256'];
		} elseif ( 'duplicate' === $corruption ) {
			$archives[1]['layout_descriptor']['roots'][] = $archives[1]['layout_descriptor']['roots'][0];
			$archives[1]['layout_descriptor_sha256']     = hash( 'sha256', wp_json_encode( $archives[1]['layout_descriptor'] ) );
			$archives[0]['layout_descriptor_sha256']     = $archives[1]['layout_descriptor_sha256'];
		} else {
			$archives[1]['layout_descriptor']['roots'][0]['coverage'] = 'all';
			$archives[1]['layout_descriptor_sha256']                  = hash( 'sha256', wp_json_encode( $archives[1]['layout_descriptor'] ) );
			$archives[0]['layout_descriptor_sha256']                  = $archives[1]['layout_descriptor_sha256'];
		}
		$id       = $this->record( $archives, array(), true );
		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id, $this->site_id );
		$this->assertFalse( $resolved['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $resolved['code'] );
	}

	/**
	 * List stored corruption cases.
	 *
	 * @return array
	 */
	public static function invalid_metadata_cases() {
		return array( array( 'missing' ), array( 'artifact' ), array( 'forged' ), array( 'role' ), array( 'coverage' ), array( 'duplicate' ), array( 'multiple_manifests' ), array( 'digest' ) );
	}

	/** New rows refuse v2 metadata that cannot identify its descriptor. */
	public function test_snapshot_writer_refuses_missing_descriptor() {
		$archives = $this->archives();
		unset( $archives[1]['layout_descriptor'] );
		$this->assertSame( 0, $this->record( $archives ) );
	}

	/** Unknown top-level metadata versions cannot be validated as v1 or v2. */
	public function test_unknown_metadata_format_is_refused() {
		$result = MainWP_Backup_Snapshots::validate_format_metadata( array( 'backup_format' => 9 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_backup_format_unsupported', $result->get_error_code() );
	}

	/** The supplied descriptor is projected in the reviewed canonical field order. */
	public function test_descriptor_validation_uses_contract_field_order() {
		$metadata                      = $this->archives()[1];
		$metadata['layout_descriptor'] = array_reverse( $metadata['layout_descriptor'], true );
		$validated                     = MainWP_Backup_Snapshots::validate_format_metadata( $metadata );
		$this->assertIsArray( $validated );
		$this->assertSame( $this->descriptor(), $validated['layout_descriptor'] );
	}

	/** The same-site rejection remains authoritative even for new-format rows. */
	public function test_v2_wrong_site_rejection_is_unchanged() {
		$id       = $this->record( $this->archives() );
		$resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $id, $this->site_id + 1 );
		$this->assertFalse( $resolved['ok'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_WRONG_SITE, $resolved['code'] );
	}

	/** v1 snapshot JSON retains field order, omitted fields and literal path bytes. */
	public function test_v1_archives_json_is_byte_identical() {
		global $wpdb;
		$id  = $this->record(
			array(
				array(
					'file' => 'legacy.tar',
					'role' => 'full',
				),
			)
		);
		$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT archives FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB -- golden stored bytes.
		$this->assertSame( wp_json_encode( $row->archives ), $raw );
		$this->assertSame( array( 'file', 'role', 'path', 'size' ), array_keys( $row->archives[0] ) );
		$this->assertArrayNotHasKey( 'backup_format', $row->archives[0] );
		if ( getenv( 'MWP1884_GOLDEN_FILE' ) ) {
			file_put_contents( getenv( 'MWP1884_GOLDEN_FILE' ), wp_json_encode( array( 'archives' => str_replace( str_replace( '/', '\\/', $this->dir ), '<fixture>', $raw ) ) ) . "\n", FILE_APPEND );
		}
	}

	/** Optional future checkpoint fields already round-trip through generic JSON on base. */
	public function test_optional_checkpoint_fields_round_trip_without_defaults() {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$state = array(
			'restore_id'       => 'stored-metadata-fixture',
			'source_site_id'   => 33001,
			'target_layout_id' => str_repeat( 'e', 64 ),
		);
		$id    = $store->create_job( $this->site_id, 42, $state );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $this->site_id, (int) $store->get_job( $id )->site_id );
		$this->assertSame( $state, $store->get_job( $id )->checkpoint );
		$state['cursor'] = 1;
		$this->assertTrue( $store->save_checkpoint( $id, $state ) );
		$this->assertSame( $state, $store->get_job( $id )->checkpoint );
		if ( getenv( 'MWP1884_GOLDEN_FILE' ) ) {
			global $wpdb;
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT checkpoint FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB -- golden stored bytes.
			file_put_contents( getenv( 'MWP1884_GOLDEN_FILE' ), wp_json_encode( array( 'checkpoint' => $raw ) ) . "\n", FILE_APPEND );
		}
	}

	/** Old checkpoints keep their bytes and omit the optional cross-site fields. */
	public function test_v1_checkpoint_bytes_are_unchanged() {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$state = array(
			'restore_id' => 'legacy-checkpoint-fixture',
			'transfer'   => array(),
		);
		$id    = $store->create_job( $this->site_id, 42, $state );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $state, $store->get_job( $id )->checkpoint );
		$state['transfer']['offset'] = 17;
		$this->assertTrue( $store->save_checkpoint( $id, $state ) );
		$this->assertSame( $state, $store->get_job( $id )->checkpoint );
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT checkpoint FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB -- golden stored bytes.
		$this->assertSame( wp_json_encode( $state ), $raw );
		$this->assertArrayNotHasKey( 'source_site_id', $store->get_job( $id )->checkpoint );
		$this->assertArrayNotHasKey( 'target_layout_id', $store->get_job( $id )->checkpoint );
		if ( getenv( 'MWP1884_GOLDEN_FILE' ) ) {
			file_put_contents( getenv( 'MWP1884_GOLDEN_FILE' ), wp_json_encode( array( 'checkpoint_v1' => $raw ) ) . "\n", FILE_APPEND );
		}
	}

	/** New format errors retain registry response classification. */
	public function test_format_errors_are_registered() {
		$this->assertContains( 'mainwp_backup_format_unsupported', MainWP_Backup_Error_Codes::get_registered_codes() );
		$this->assertContains( 'mainwp_backup_layout_changed', MainWP_Backup_Error_Codes::get_registered_codes() );
	}
}
