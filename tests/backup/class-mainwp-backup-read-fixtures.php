<?php
/**
 * Fixed identities keep recorded JSON independent of database sequences.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

/** Shared rows for serializer and read-only checks. */
trait Backup_Read_Fixtures {
	/**
	 * Fixed identity for recorded responses.
	 *
	 * @var int
	 */
	protected $site_id = 987670;

	/**
	 * Invalid fixture inserts must fail before readers run.
	 *
	 * @param string $table Table key.
	 * @param array  $data Row values.
	 * @return void
	 */
	protected function insert_read_fixture( $table, array $data ) {
		global $wpdb;
		$this->assertNotFalse( $wpdb->insert( MainWP_DB::instance()->get_table_name( $table ), $data ), $wpdb->last_error );
	}

	/**
	 * Fixed identities and timestamps make full JSON comparisons repeatable.
	 *
	 * @return void
	 */
	protected function seed_read_fixtures() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->create_test_site(
			array(
				'id'   => $this->site_id,
				'name' => 'Parity Site',
				'url'  => 'https://parity.example.org/',
			)
		);
		foreach ( array( 987671, 987672 ) as $id ) {
			$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Parity task', 'daily', 'full', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
			global $wpdb;
			$this->assertNotFalse( $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $id ), array( 'id' => $task->id ) ) );
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$id,
				array(
					'last_run'       => 1700000000,
					'completed'      => 1700000001,
					'backup_errors'  => '[mainwp_backup_disk_full] Disk &amp; space <b>error</b>.',
					'backup_notices' => 'ZIP &amp; archive <b>notice</b>.',
					'manual_site_id' => 987672 === $id ? $this->site_id : null,
					'template'       => 987672 === $id ? 1 : 0,
				)
			);
			$this->insert_read_fixture(
				'wp_backup_progress',
				array(
					'task_id'              => $id,
					'wp_id'                => $this->site_id,
					'dtsFetched'           => 1700000000,
					'child_backup_task_id' => 'parity-child',
					'child_phase'          => 'failed',
					'last_error'           => '[mainwp_backup_disk_full] Site &amp; disk <b>error</b>.',
				)
			);
		}
		$this->insert_read_fixture(
			'wp_backup_snapshot',
			array(
				'id'       => 987673,
				'site_id'  => $this->site_id,
				'task_id'  => 987671,
				'type'     => 'full',
				'created'  => 2000000000,
				'archives' => wp_json_encode(
					array(
						array(
							'file' => 'full.zip',
							'path' => __FILE__,
							'role' => 'full',
							'size' => 1024,
						),
					)
				),
			)
		);
		$this->insert_read_fixture(
			'wp_backup_restore_job',
			array(
				'id'          => 987674,
				'site_id'     => $this->site_id,
				'snapshot_id' => 987673,
				'status'      => 'failed_permanent',
				'last_error'  => 'Restore & disk error.',
				'created'     => 1700000000,
				'checkpoint'  => wp_json_encode(
					array(
						'last_error_code' => 'mainwp_restore_disk_full',
						'sub_type'        => 'full',
						'plan'            => array( array( 'size' => 1024 ) ),
						'transfer'        => array(
							'artifact_index' => 0,
							'offset'         => 512,
						),
					)
				),
			)
		);
		$this->insert_read_fixture(
			'wp_backup_upload_job',
			array(
				'id'             => 987675,
				'snapshot_id'    => 987673,
				'destination_id' => 987676,
				'provider'       => 's3',
				'status'         => 'failed_permanent',
				'checkpoint'     => '{}',
				'last_error'     => '[mainwp_backup_s3_failed] Upload & disk error.',
				'updated'        => 1700000000,
			)
		);
	}

	/**
	 * Only records owned by these fixtures are removed.
	 *
	 * @return void
	 */
	protected function clear_read_fixtures() {
		global $wpdb;
		foreach ( array(
			'wp_backup_progress'    => 'wp_id',
			'wp_backup'             => 'id',
			'wp_backup_snapshot'    => 'site_id',
			'wp_backup_restore_job' => 'site_id',
			'wp_backup_upload_job'  => 'snapshot_id',
		) as $table => $column ) {
			$values = 'wp_backup' === $table ? array( 987671, 987672 ) : array( 'wp_backup_upload_job' === $table ? 987673 : $this->site_id );
			foreach ( $values as $value ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( $table ), array( $column => $value ) );
			}
		}
		delete_option( 'mainwp_enableNativeBackups' );
	}
}

/** Shared purity assertions keep cleanup previews read-only too. */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- shared read guard.
trait Backup_Read_Purity_Guard {
	/**
	 * Read calls fail as soon as they attempt externally visible work.
	 *
	 * @param callable $call Reader invocation.
	 * @return mixed Reader result.
	 */
	protected function guarded( callable $call ) {
		$deny  = function () {
			$this->fail( 'A read attempted a write, HTTP request, Child request, or scheduled event.' );
		};
		$query = function ( $sql ) {
			$this->assertDoesNotMatchRegularExpression( '/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql, $sql );
			return $sql;
		};
		$hooks = array( 'pre_http_request', 'mainwp_fetch_url_authed_pre', 'pre_schedule_event', 'pre_update_option', 'setted_transient' );
		add_filter( 'query', $query );
		foreach ( $hooks as $hook ) {
			add_filter( $hook, $deny );
		}
		try {
			return $call();
		} finally {
			remove_filter( 'query', $query );
			foreach ( $hooks as $hook ) {
				remove_filter( $hook, $deny );
			}
		}
	}
}
