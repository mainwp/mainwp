<?php
/**
 * Cross-site restore admission and immutable source ownership.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;

/** Exercise real catalog, admission and stored jobs through the Child transport seam. */
class MainWP_Backup_V2_Cross_Host_Restore_Test extends MainWP_Abilities_Test_Case {
	use Backup_Private_Root_Fixture;

	/**
	 * Source site.
	 *
	 * @var int
	 */
	private $source_id;
	/**
	 * Destination site.
	 *
	 * @var int
	 */
	private $target_id;
	/**
	 * Local source artifacts.
	 *
	 * @var string
	 */
	private $dir;
	/**
	 * Observed Child requests.
	 *
	 * @var array
	 */
	private $calls = array();

	/** Create two connected sites and isolated source bytes. */
	public function set_up() {
		parent::set_up();
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->source_id = $this->create_test_site();
		$this->target_id = $this->create_test_site();
		$this->dir       = sys_get_temp_dir() . '/mainwp-cross-host-' . uniqid();
		mkdir( $this->dir );
		foreach ( array( $this->source_id, $this->target_id ) as $site_id ) {
			MainWP_DB::instance()->update_website_option( $site_id, 'restore_create_idempotency_support', '1' );
		}
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what, $params ) {
				$this->calls[] = array( (int) $website->id, $what, $params );
				if ( 'backup_preflight' === $what ) {
					return array(
						'backup_layout_v2'           => 1,
						'restore_layout_v2'          => 1,
						'restore_create_idempotency' => 1,
						'layout_id'                  => str_repeat( 'b', 64 ),
						'layout_roles'               => array( 'content' ),
						'core_policy'                => 'managed_preserve',
					);
				}
				if ( 'restore_preflight' === $what ) {
					return array( 'woo_hpos_enabled' => 1 );
				}
				return array( 'task_id' => 'destination-safety' );
			},
			10,
			4
		);
	}

	/** Reset the private switch and remove owned files. */
	public function tear_down() {
		$this->enable( false );
		foreach ( glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * Set the existing private rollout switch.
	 *
	 * @param bool $enabled Switch value.
	 */
	private function enable( $enabled ) {
		( new \ReflectionProperty( MainWP_Backup_Monitor::class, 'layout_v2_enabled' ) )->setValue( null, $enabled );
	}

	/**
	 * Store a source-owned snapshot with explicit format metadata.
	 *
	 * @param bool  $v2 Use v2 metadata.
	 * @param array $extra Snapshot overrides.
	 * @return int Snapshot id.
	 */
	private function snapshot( $v2 = true, $extra = array() ) {
		$descriptor = array(
			'format'        => 2,
			'roots'         => array(
				array(
					'role'     => 'content',
					'coverage' => 'restore',
				),
			),
			'layout_id'     => str_repeat( 'a', 64 ),
			'core_policy'   => 'managed_preserve',
			'list_format'   => 2,
			'path_encoding' => 'role-prefix',
		);
		$archives   = array();
		foreach ( array(
			'full'     => $v2 ? 'backup.mwp2' : 'backup.zip',
			'manifest' => 'files.list',
		) as $role => $file ) {
			$path = $this->dir . '/' . $file;
			file_put_contents( $path, 'source-' . $file );
			$archive = array(
				'role'   => $role,
				'file'   => $file,
				'path'   => $path,
				'size'   => filesize( $path ),
				'sha256' => hash_file( 'sha256', $path ),
			);
			if ( $v2 ) {
				$archive['backup_format']            = 2;
				$archive['layout_descriptor_sha256'] = hash( 'sha256', wp_json_encode( $descriptor, JSON_UNESCAPED_SLASHES ) );
				if ( 'manifest' === $role ) {
					$archive['layout_descriptor'] = $descriptor;
				}
			}
			$archives[] = $archive;
		}
		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array_merge(
				array(
					'site_id'  => $this->source_id,
					'task_id'  => 0,
					'type'     => 'full',
					'archives' => $archives,
				),
				$extra
			)
		);
	}

	/**
	 * Invoke the explicit internal path after checking it exists on this revision.
	 *
	 * @param int   $snapshot_id Source snapshot.
	 * @param array $options Restore options.
	 * @return int Job id.
	 */
	private function start( $snapshot_id, $options = array() ) {
		$orchestrator = new MainWP_Backup_Restore_Orchestrator();
		$this->assertTrue( is_callable( array( $orchestrator, 'initiate_cross_site_restore' ) ), 'The explicit cross-site admission path must exist.' );
		return $orchestrator->initiate_cross_site_restore( $this->source_id, $this->target_id, $snapshot_id, $options );
	}

	/** Source artifacts and ownership survive admission onto a different target. */
	public function test_cross_site_admission_pins_destination_and_preserves_source() {
		$this->enable( true );
		$id    = $this->snapshot();
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$job   = $store->get_job(
			$this->start(
				$id,
				array(
					'confirm_destructive' => true,
					'pre_snapshot'        => true,
				)
			)
		);
		$this->assertSame( $this->target_id, (int) $job->site_id );
		$this->assertSame( $this->source_id, $job->checkpoint['source_site_id'] );
		$this->assertSame( str_repeat( 'b', 64 ), $job->checkpoint['target_layout_id'] );
		$this->assertSame( $this->source_id, (int) MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->site_id );
		$this->assertNull( $store->get_active_job_for_site( $this->source_id ) );
		foreach ( $job->checkpoint['plan'] as $entry ) {
			$this->assertSame( $id, $entry['snapshot_id'] );
			$this->assertSame( $this->dir . '/' . $entry['source_file'], $entry['local_path'] );
			$this->assertFalse( $entry['adopt_only'] );
		}
		$this->assertNotEmpty( $this->calls );
		$this->assertSame( array( $this->target_id ), array_values( array_unique( array_column( $this->calls, 0 ) ) ) );
		$orchestrator = new MainWP_Backup_Restore_Orchestrator();
		$orchestrator->run_job( $job, microtime( true ) + 1 );
		$orchestrator->run_job( $store->get_job( (int) $job->id ), microtime( true ) + 1 );
		$this->assertSame( array( $this->target_id ), array_values( array_unique( array_column( $this->calls, 0 ) ) ) );
		$this->assertNotContains( 'backup_task_create', array_column( $this->calls, 1 ), 'The existing v2 safety producer refusal stays in force.' );
	}

	/** Completed cloud copies are addressed under the source, even for another destination. */
	public function test_cross_site_cloud_keys_keep_source_owner() {
		global $wpdb;
		$this->enable( true );
		$id = $this->snapshot();
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'snapshot_id'    => $id,
				'destination_id' => 7,
				'status'         => 'complete',
				'checkpoint'     => wp_json_encode(
					array(
						'artifacts' => array(
							array(
								'file' => 'backup.mwp2',
								'mode' => 'whole',
							),
							array(
								'file' => 'files.list',
								'mode' => 'whole',
							),
						),
					)
				),
				'created'        => time(),
				'updated'        => time(),
			)
		); // phpcs:ignore WordPress.DB -- completed cloud fixture.
		unlink( $this->dir . '/backup.mwp2' );
		unlink( $this->dir . '/files.list' );
		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->start( $id, array( 'confirm_destructive' => true ) ) );
		foreach ( $job->checkpoint['plan'] as $entry ) {
			$this->assertSame( 'remote', $entry['source'] );
			$this->assertSame( $this->source_id . '/' . $id . '/files/' . $entry['source_file'], $entry['remote_key'] );
		}
	}

	/** Switch-off refusal precedes even a temporary destination reservation. */
	public function test_switch_off_refuses_cross_site_before_any_job_or_child_request() {
		global $wpdb;
		$id    = $this->snapshot();
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore WordPress.DB -- row count assertion.
		try {
			$this->start( $id, array( 'confirm_destructive' => true ) );
			$this->fail( 'Cross-site restore must remain switched off.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_WRONG_SITE, $error->get_message_error_code() );
		}
		$this->assertSame( $count, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ) ); // phpcs:ignore WordPress.DB -- row count assertion.
		$this->assertSame( array(), $this->calls );
	}

	/** Legacy archives cannot be interpreted as cross-site v2 backups. */
	public function test_v1_cross_site_restore_is_refused_without_child_requests() {
		$this->enable( true );
		try {
			$this->start( $this->snapshot( false ), array( 'confirm_destructive' => true ) );
			$this->fail( 'A v1 cross-site restore must be refused.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $error->get_message_error_code() );
		}
		$this->assertSame( array(), $this->calls );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->target_id ) );
	}

	/** Consent uses source snapshot type before any destination work. */
	public function test_cross_site_database_restore_requires_confirmation() {
		$this->enable( true );
		try {
			$this->start( $this->snapshot() );
			$this->fail( 'The source database makes this restore destructive.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( 'mainwp_confirmation_required', $error->get_message_error_code() );
		}
		$this->assertSame( array(), $this->calls );
	}

	/** Existing same-site resolution and wrong-site refusal retain their codes with either switch state. */
	public function test_existing_same_site_controls_with_both_switch_states() {
		$id = $this->snapshot( false );
		foreach ( array( false, true ) as $enabled ) {
			$this->enable( $enabled );
			$catalog = MainWP_Backup_Snapshot_Catalog::instance();
			$this->assertTrue( $catalog->resolve_restorable_chain( $id, $this->source_id )['ok'] );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_WRONG_SITE, $catalog->resolve_restorable_chain( $id, $this->target_id )['code'] );
			try {
				( new MainWP_Backup_Restore_Orchestrator() )->initiate_restore( $this->target_id, $id, array( 'confirm_destructive' => true ) );
				$this->fail( 'The original entry point must keep its wrong-site refusal.' );
			} catch ( MainWP_Exception $error ) {
				$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_WRONG_SITE, $error->get_message_error_code() );
			}
		}
	}
	/** A retained source disappearing after admission cannot reach destination apply. */
	public function test_disappearing_source_artifact_fails_before_destination_apply() {
		$this->enable( true );
		$id           = $this->snapshot();
		$store        = MainWP_Backup_Restore_Job_Store::instance();
		$job_id       = $this->start( $id, array( 'confirm_destructive' => true ) );
		$orchestrator = new MainWP_Backup_Restore_Orchestrator();
		unlink( $this->dir . '/backup.mwp2' );
		for ( $step = 0; $step < 3; ++$step ) {
			$orchestrator->run_job( $store->get_job( $job_id ), microtime( true ) + 1 );
		}
		$this->assertSame( 'failed_permanent', $store->get_job( $job_id )->status );
		$this->assertNotContains( 'restore_task_create', array_column( $this->calls, 1 ) );
		$this->assertNotContains( 'restore_task_run', array_column( $this->calls, 1 ) );
	}

	/** Legacy admission keeps its exact checkpoint shape and serialized values. */
	public function test_same_site_v1_checkpoint_golden_with_both_switch_states() {
		$id    = $this->snapshot( false );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		foreach ( array( false, true ) as $enabled ) {
			$this->enable( $enabled );
			$password = static fn() => 'fixture-restore';
			add_filter( 'random_password', $password );
			try {
				$job_id = ( new MainWP_Backup_Restore_Orchestrator() )->initiate_restore( $this->source_id, $id, array( 'confirm_destructive' => true ) );
			} finally {
				remove_filter( 'random_password', $password );
			}
			$expected = array(
				'restore_id' => 'fixture-restore',
				'sub_type'   => 'full',
				'plan'       => array(
					array(
						'role'              => 'full',
						'file'              => 's' . $id . '-backup.zip',
						'source_file'       => 'backup.zip',
						'size'              => 17,
						'recorded_size'     => 17,
						'sha256'            => '',
						'recorded_sha256'   => hash( 'sha256', 'source-backup.zip' ),
						'damaged'           => false,
						'expected_sha256'   => '',
						'snapshot_id'       => $id,
						'source'            => 'local',
						'local_path'        => $this->dir . '/backup.zip',
						'remote_key'        => '',
						'remote_candidates' => array(),
						'adopt_only'        => false,
					),
				),
				'options'    => array(
					'pre_snapshot'    => false,
					'file_rollback'   => false,
					'preserve_orders' => false,
					'woo_order_since' => 0,
				),
				'transfer'   => array(),
			);
			$this->assertSame( wp_json_encode( $expected ), wp_json_encode( $store->get_job( $job_id )->checkpoint ) );
			$this->assertTrue( $store->fail_permanent( $job_id, 'Fixture finished.' ) );
		}
	}

	/** A parent row owned by another site cannot enter the source chain. */
	public function test_cross_site_chain_refuses_foreign_owned_ancestor() {
		$this->enable( true );
		$base = $this->snapshot( true, array( 'site_id' => $this->target_id ) );
		$id   = $this->snapshot(
			true,
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		try {
			$this->start( $id, array( 'confirm_destructive' => true ) );
			$this->fail( 'Every ancestor must belong to the source.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_UNRESTORABLE_WRONG_SITE, $error->get_message_error_code() );
		}
		$this->assertSame( array(), $this->calls );
	}

	/** Files restore support belongs to the destination, not to the archive owner. */
	public function test_cross_site_files_restore_uses_destination_capability() {
		$this->enable( true );
		MainWP_DB::instance()->update_website_option( $this->source_id, 'restore_files_support', '0' );
		MainWP_DB::instance()->update_website_option( $this->target_id, 'restore_files_support', '1' );
		$id  = $this->snapshot( true, array( 'type' => 'files' ) );
		$job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $this->start( $id ) );
		$this->assertSame( $this->target_id, (int) $job->site_id );
		$this->assertSame( 'files', $job->checkpoint['sub_type'] );
		$this->assertFalse( $job->checkpoint['options']['preserve_orders'] );
	}
}
