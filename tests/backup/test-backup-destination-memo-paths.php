<?php
/**
 * Destination reuse through snapshot-list and cloud-delete entry points.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Cloud_Delete_Driver;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Retention_Protection;
use MainWP\Dashboard\MainWP_Backup_Retention_Query;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

/** Class MainWP_Backup_Destination_Memo_Paths_Test. */
class MainWP_Backup_Destination_Memo_Paths_Test extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Created destination identities.
	 *
	 * @var int[]
	 */
	private $destinations = array();

	/** Retire fixture keys before the database transaction rolls back. */
	public function tear_down() {
		foreach ( $this->destinations as $id ) {
			MainWP_Backup_Storage_Destinations::instance()->delete_destination( $id );
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tear_down();
	}

	/**
	 * Seed completed, bound uploads for three destinations on each snapshot.
	 *
	 * @param int $count Snapshot count.
	 * @return array Site, task, snapshots and destinations.
	 */
	protected function seed_history( int $count ): array {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		$site = $this->create_test_site();
		$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Destination memo history', 'daily', 'full', '', (string) $site, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'manual_site_id'  => $site,
				'template'        => 1,
				'retention_count' => 5,
			)
		);
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$bindings = array();
		for ( $index = 0; $index < 3; ++$index ) {
			$id = $store->add_destination(
				array(
					'provider' => 's3',
					'name'     => 'Destination memo ' . $index,
					'settings' => array(
						'bucket'                        => 'memo-' . $index,
						'region'                        => 'us-east-1',
						'access_key'                    => 'fixture',
						'secret_key'                    => 'fixture',
						'minimum_remote_retention_days' => 0,
					),
				)
			);
			$this->assertGreaterThan( 0, $id );
			$this->destinations[] = $id;
			$bindings[ $id ]      = MainWP_Backup_Retention_Protection::destination_binding( $store->get_destination( $id ) );
		}
		$ids     = array();
		$archive = array(
			'file'                       => 'full.tar',
			'role'                       => 'full',
			'size'                       => 100,
			'sha256'                     => hash( 'sha256', str_repeat( 'x', 100 ) ),
			'retention_requires_uploads' => true,
		);
		for ( $index = 0; $index < $count; ++$index ) {
			$this->assertNotFalse(
				$wpdb->insert(
					MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
					array(
						'site_id'       => $site,
						'task_id'       => $task->id,
						'type'          => 'full',
						'created'       => time() - ( $count - $index ) * DAY_IN_SECONDS,
						'archives'      => wp_json_encode( array( $archive ) ),
						'manifest_path' => '',
						'status'        => 'complete',
					)
				)
			);
			$snapshot = (int) $wpdb->insert_id;
			$ids[]    = $snapshot;
			$this->assertTrue( MainWP_Backup_Retention_Protection::capture_required_destinations( $snapshot, $this->destinations ) );
			foreach ( $this->destinations as $destination ) {
				$checkpoint = array(
					'uploaded_at'         => time() - DAY_IN_SECONDS,
					'manifest_submitted'  => true,
					'destination_binding' => $bindings[ $destination ],
					'artifacts'           => array(
						array_merge(
							$archive,
							array(
								'mode' => 'whole',
								'done' => true,
							)
						),
					),
				);
				$this->assertNotFalse(
					$wpdb->insert(
						MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
						array(
							'snapshot_id'    => $snapshot,
							'destination_id' => $destination,
							'provider'       => 's3',
							'kind'           => 'upload',
							'status'         => 'complete',
							'checkpoint'     => wp_json_encode( $checkpoint ),
							'last_error'     => '',
							'created'        => time(),
							'updated'        => time(),
						)
					)
				);
			}
		}
		return array( $site, (int) $task->id, $ids, $this->destinations );
	}

	/**
	 * A fresh store models the next PHP request; counters omit fixture setup.
	 *
	 * @param callable $operation Real entry point.
	 * @return array Measured queries, decrypts and seconds.
	 */
	protected function measure_request( callable $operation ): array {
		global $wpdb;
		$instance = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$instance->setValue( null, null );
		wp_cache_flush();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		$decrypts      = 0;
		$reads         = 0;
		$count_decrypt = static function ( $value ) use ( &$decrypts ) {
			++$decrypts;
			return $value;
		};
		$count_read    = static function ( $sql ) use ( &$reads ) {
			if ( str_starts_with( $sql, 'SELECT * FROM ' ) && str_contains( $sql, 'mainwp_wp_backup_storage' ) ) {
				++$reads;
			}
			return $sql;
		};
		add_filter( 'mainwp_decrypt_key_value', $count_decrypt, PHP_INT_MAX );
		add_filter( 'query', $count_read );
		$queries = $wpdb->num_queries;
		$start   = microtime( true );
		try {
			$result  = $operation();
			$seconds = microtime( true ) - $start;
			$queries = $wpdb->num_queries - $queries;
		} finally {
			remove_filter( 'mainwp_decrypt_key_value', $count_decrypt, PHP_INT_MAX );
			remove_filter( 'query', $count_read );
		}
		return array(
			'queries'           => $queries,
			'decrypts'          => $decrypts,
			'destination_reads' => $reads,
			'seconds'           => $seconds,
			'result'            => $result,
		);
	}

	/**
	 * Preview follows the same unit decoration as the snapshot-list AJAX load.
	 *
	 * @param int $count History size.
	 * @return array Measurements.
	 */
	protected function measure_list( int $count ): array {
		list( $site, $task, $ids ) = $this->seed_history( $count );
		$metrics                   = $this->measure_request(
			static function () use ( $site, $task ) {
				return array( MainWP_Backup_Operations::preview_site_cleanup( $site, 5, 'ui' ), MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $site, $task ) );
			}
		);
		$this->assertNotWPError( $metrics['result'][0] );
		$this->assertCount( $count, $metrics['result'][1] );
		foreach ( $metrics['result'][1] as $unit ) {
			$this->assertFalse( $unit['required_uploads_pending'] );
		}
		$this->assertNotEmpty( $metrics['result'][0]['would_affect']['on_demand_units'] );
		unset( $metrics['result'] );
		return $metrics;
	}

	/**
	 * The driver walks known keys and sweeps uncheckpointed objects via real S3 methods.
	 *
	 * @param int $count History size and sweep object count per destination.
	 * @return array Measurements and HTTP operation counts.
	 */
	protected function measure_sweep( int $count ): array {
		list( $site, $task, $ids, $destinations ) = $this->seed_history( $count );
		$store                                    = MainWP_Backup_Upload_Job_Store::instance();
		foreach ( $destinations as $id ) {
			$this->assertTrue(
				$store->enqueue_delete_job(
					$ids[0],
					$id,
					array(
						'prefix' => $site . '/' . $ids[0] . '/',
						'keys'   => array( array( 'key' => $site . '/' . $ids[0] . '/known.tar' ) ),
					)
				)
			);
		}
		$lists   = 0;
		$deletes = 0;
		$http    = static function ( $pre, $args, $url ) use ( $count, &$lists, &$deletes ) {
			if ( 'DELETE' === $args['method'] ) {
				++$deletes;
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 204,
						'message' => 'No Content',
					),
				);
			}
			if ( 'GET' !== $args['method'] ) {
				return new \WP_Error( 'unexpected_http', 'Unexpected provider HTTP method.' );
			}
			++$lists;
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$body = '<ListBucketResult><IsTruncated>false</IsTruncated>';
			for ( $index = 0; $index < $count; ++$index ) {
				$body .= '<Contents><Key>' . esc_html( $query['prefix'] . 'sweep-' . $index ) . '</Key><Size>100</Size></Contents>';
			}
			return array(
				'headers'  => array(),
				'body'     => $body . '</ListBucketResult>',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};
		add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
		try {
			$metrics = $this->measure_request(
				static function () {
					MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 120 );
				}
			);
		} finally {
			remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
		}
		$this->assertSame( 3, $lists );
		$this->assertSame( 3 * ( $count + 1 ), $deletes );
		$jobs = $store->get_jobs_for_snapshot( $ids[0], 'delete' );
		$this->assertCount( 3, $jobs );
		foreach ( $jobs as $job ) {
			$this->assertSame( 'complete', $job->status );
			$this->assertTrue( $job->checkpoint['swept'] );
		}
		unset( $metrics['result'] );
		return array_merge(
			$metrics,
			array(
				'http_lists'   => $lists,
				'http_deletes' => $deletes,
			)
		);
	}

	/** Each destination is read and decrypted once on list loads. */
	public function test_list_reuses_destinations() {
		$metrics = $this->measure_list( 6 );
		$this->assertSame( 3, $metrics['decrypts'] );
		$this->assertSame( 3, $metrics['destination_reads'] );
	}

	/** Key walks and prefix sweeps reuse each destination. */
	public function test_delete_sweep_reuses_destinations() {
		$metrics = $this->measure_sweep( 6 );
		$this->assertSame( 3, $metrics['decrypts'] );
		$this->assertSame( 3, $metrics['destination_reads'] );
	}
}
