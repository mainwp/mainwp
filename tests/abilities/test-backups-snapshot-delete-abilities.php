<?php
/**
 * Ability and row deletion share previews, outcomes and consent.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

require_once __DIR__ . '/../backup/trait-snapshot-delete-fixture.php';

/** REST consent and UI parity use actual operations. */
class MainWP_Backups_Snapshot_Delete_Abilities_Test extends MainWP_Abilities_Test_Case {
	use Snapshot_Delete_Fixture;

	/**
	 * Registered deletion.
	 *
	 * @return \WP_Ability
	 */
	private function ability() {
		$this->assertTrue( wp_has_ability( 'mainwp/delete-backup-snapshot-v1' ), 'X2 ability is missing.' );
		return wp_get_ability( 'mainwp/delete-backup-snapshot-v1' );
	}

	/**
	 * Capture the UI transport without ending PHPUnit.
	 *
	 * @param string      $action AJAX action.
	 * @param array       $input Posted fields.
	 * @param string|null $raw_json Captured response body.
	 * @return array
	 */
	private function ajax( $action, $input, &$raw_json = null ) {
		$this->assertTrue( is_callable( array( MainWP_Post_Backup_Handler::instance(), $action ) ) );
		$_POST    = $input + array( 'security' => wp_create_nonce( $action ) );
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'x2-json-end' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->{$action}();
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'x2-json-end', $error->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		$raw_json = $raw;
		return json_decode( $raw, true );
	}

	/**
	 * Both output branches must pass through the declared projection.
	 *
	 * @return array
	 */
	public static function projection_modes() {
		return array( array( true ), array( false ) );
	}

	/**
	 * Schema projection gives every write the same field order and closed shape.
	 *
	 * @dataProvider projection_modes
	 * @param bool $dry_run Preview mode.
	 */
	public function test_delete_results_use_schema_projection( $dry_run ) {
		$ability = $this->ability();
		$id      = $this->snapshot();
		$result  = $ability->execute(
			array(
				'site_id_or_domain'              => $this->site_id,
				'snapshot_id'                    => $id,
				'expected_snapshot_ids'          => array( $id ),
				$dry_run ? 'dry_run' : 'confirm' => true,
			)
		);
		$this->assertIsArray( $result );
		$project = new \ReflectionMethod( MainWP_Abilities_Backups::class, 'serialize' );
		$schema  = $ability->get_output_schema()['oneOf'][ $dry_run ? 0 : 1 ];
		$this->assertSame( $project->invoke( null, $result, $schema ), $result );
		$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema() ) );
	}

	/** Existing confirmations warn only for copies whose minimum still applies. */
	public function test_minimum_retention_warnings_cover_snapshot_task_and_cleanup(): void {
		global $wpdb;
		$this->use_scheduled_task();
		$id    = $this->snapshot();
		$store = MainWP_Backup_Storage_Destinations::instance();
		$dest  = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Protected warning fixture',
				'settings' => array( 'minimum_remote_retention_days' => 90 ),
			)
		);
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$wpdb->insert(
			$table,
			array(
				'snapshot_id'    => $id,
				'destination_id' => $dest,
				'provider'       => 's3',
				'status'         => 'complete',
				'kind'           => 'upload',
				'checkpoint'     => wp_json_encode( array( 'uploaded_at' => time() ) ),
			)
		);
		$job = (int) $wpdb->insert_id;
		try {
			$preview = $this->preview( $id, true );
			$this->assertStringContainsString( 'minimum retention period', implode( ' ', $preview['warnings'] ) );
			$local_only = $this->preview( $id, false );
			$this->assertStringNotContainsString( 'minimum retention period', implode( ' ', $local_only['warnings'] ) );
			$task_preview = MainWP_Backup_Operations::preview_task_delete( (int) $this->task->id );
			$this->assertStringContainsString( 'minimum retention period', implode( ' ', $task_preview['warnings'] ) );
			$kept = MainWP_Backup_Operations::preview_task_delete( (int) $this->task->id, false );
			$this->assertSame( array(), $kept['warnings'] );
			$run = MainWP_Backup_Operations::preview_task_update( (int) $this->task->id, array( 'retention' => array( 'count' => 1 ) ) );
			$this->assertStringContainsString( 'Count or storage limits may be exceeded', implode( ' ', $run['warnings'] ) );
			$wpdb->update( $table, array( 'checkpoint' => wp_json_encode( array( 'uploaded_at' => time() - 91 * DAY_IN_SECONDS ) ) ), array( 'id' => $job ) );
			$this->assertSame( array(), $this->preview( $id, true )['warnings'] );
			$wpdb->update(
				$table,
				array(
					'status'     => 'pending',
					'checkpoint' => '{}',
				),
				array( 'id' => $job )
			);
			$this->assertStringContainsString( 'MainWP cannot confirm that it has expired', implode( ' ', $this->preview( $id, true )['warnings'] ) );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $this->task->id ) );
			$cleanup = MainWP_Backup_Operations::preview_site_cleanup( $this->site_id, null );
			$this->assertContains( $id, $cleanup['would_affect']['orphaned_task_snapshot_ids'] );
			$this->assertStringContainsString( 'minimum retention period', implode( ' ', $cleanup['warnings'] ) );
			$list = $this->ajax( 'mainwp_restore_snapshot_list', array( 'site_id' => $this->site_id ) );
			$this->assertSame( $cleanup['warnings'], $list['cleanup_warnings'] );
			$store->update_destination( $dest, array( 'settings' => array( 'minimum_remote_retention_days' => 0 ) ) );
			$this->assertSame( array(), MainWP_Backup_Operations::preview_site_cleanup( $this->site_id, null )['warnings'] );
		} finally {
			$store->delete_destination( $dest );
		}
	}


	/** Preview refusal stays unavailable while successful warning arrays survive.
	 *
	 * @dataProvider cleanup_warning_states
	 * @param string $state Preview state.
	 */
	public function test_snapshot_list_cleanup_warning_contract( string $state ): void {
		global $wpdb;
		$this->use_scheduled_task();
		$id          = $this->snapshot();
		$store       = MainWP_Backup_Storage_Destinations::instance();
		$destination = 0;
		if ( 'empty' !== $state ) {
			$destination = $store->add_destination(
				array(
					'provider' => 's3',
					'name'     => 'Producer warning fixture',
					'settings' => array( 'minimum_remote_retention_days' => 90 ),
				)
			);
			$this->assertGreaterThan( 0, $destination );
			$this->assertNotFalse(
				$wpdb->insert(
					MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
					array(
						'snapshot_id'    => $id,
						'destination_id' => $destination,
						'provider'       => 's3',
						'status'         => 'complete',
						'kind'           => 'upload',
						'checkpoint'     => wp_json_encode( array( 'uploaded_at' => time() ) ),
					)
				)
			);
		}
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $this->task->id ) );
		$outbound       = array(
			'http'   => 0,
			'mainwp' => 0,
		);
		$deny_http      = static function () use ( &$outbound ) {
			++$outbound['http'];
			return new \WP_Error( 'producer_test_http_refused', 'HTTP is disabled for this read-only test.' );
		};
		$observe_mainwp = static function ( $reply ) use ( &$outbound ) {
			++$outbound['mainwp'];
			return $reply;
		};
		add_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
		add_filter( 'mainwp_fetch_url_authed_pre', $observe_mainwp, 0 );
		try {
			if ( 'disabled' === $state ) {
				$enabled = MainWP_Backup_Operations::preview_site_cleanup( $this->site_id, null, 'ui' );
				$this->assertIsArray( $enabled );
				$this->assertNotEmpty( $enabled['warnings'], 'The disabled-state fixture must have a protected copy to warn about.' );
				update_option( 'mainwp_enableNativeBackups', 0 );
			}
			$before = $this->cleanup_producer_fixture_state( $id );
			$this->assertFalse( $before['required_proof_present'], 'This ordinary fixture has no captured upload requirements.' );
			$preview = MainWP_Backup_Operations::preview_site_cleanup( $this->site_id, null, 'ui' );
			if ( 'disabled' === $state ) {
				$this->assertWPError( $preview );
				$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, $preview->get_error_code() );
			} else {
				$this->assertIsArray( $preview );
				$this->assertContains( $id, $preview['would_affect']['orphaned_task_snapshot_ids'] );
				if ( 'empty' === $state ) {
					$this->assertSame( array(), $preview['warnings'] );
				} else {
					$this->assertNotEmpty( $preview['warnings'] );
					$this->assertStringContainsString( 'minimum retention period', implode( ' ', $preview['warnings'] ) );
				}
			}
			$raw      = null;
			$response = $this->ajax( 'mainwp_restore_snapshot_list', array( 'site_id' => $this->site_id ), $raw );
			$evidence = getenv( 'MWP_PRODUCER_CONTRACT_EVIDENCE' );
			if ( is_string( $evidence ) && '' !== $evidence ) {
				$this->assertNotFalse( file_put_contents( $evidence . '/response-' . $state . '.json', $raw ) );
			}
			$this->assertSame( $before, $this->cleanup_producer_fixture_state( $id ), 'Preview and list reads must preserve fixture rows, options, files and directories.' );
			$this->assertSame(
				array(
					'http'   => 0,
					'mainwp' => 0,
				),
				$outbound,
				'Preview and list reads must not request a provider or Child.'
			);
			$this->assertIsArray( $response );
			$this->assertArrayNotHasKey( 'error', $response, 'This is an authorized list response, not an AJAX refusal.' );
			$this->assertContains( $id, array_column( $response['snapshots'], 'id' ) );
			$this->assertArrayHasKey( 'cleanup_warnings', $response );
			$this->assertSame( 'disabled' === $state ? null : $preview['warnings'], $response['cleanup_warnings'] );
		} finally {
			remove_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
			remove_filter( 'mainwp_fetch_url_authed_pre', $observe_mainwp, 0 );
			if ( $destination > 0 ) {
				$store->delete_destination( $destination );
			}
		}
	}

	/**
	 * Actual producer states.
	 *
	 * @return array Cases.
	 */
	public static function cleanup_warning_states(): array {
		return array(
			'disabled'  => array( 'disabled' ),
			'empty'     => array( 'empty' ),
			'protected' => array( 'protected' ),
		);
	}

	/**
	 * Hash raw fixture data and list every owned file and directory.
	 *
	 * @param int $snapshot_id Fixture snapshot identity.
	 * @return array Read-only comparison state.
	 */
	private function cleanup_producer_fixture_state( int $snapshot_id ): array {
		global $wpdb;
		$state = array();
		foreach ( array( 'wp_backup', 'wp_backup_snapshot', 'wp_backup_upload_job', 'wp_backup_restore_job', 'wp_backup_storage' ) as $table ) {
			$rows            = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', MainWP_DB::instance()->get_table_name( $table ) ), ARRAY_A );
			$state[ $table ] = hash( 'sha256', serialize( $rows ) );
		}
		foreach ( array(
			'wp'      => 'id',
			'wp_sync' => 'wpid',
		) as $table => $key ) {
			$row             = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %d', MainWP_DB::instance()->get_table_name( $table ), $key, $this->site_id ), ARRAY_A );
			$state[ $table ] = hash( 'sha256', serialize( $row ) );
		}
		$proof                           = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, \MainWP\Dashboard\MainWP_Backup_Retention_Protection::REQUIRED_OPTION_PREFIX . $snapshot_id ), ARRAY_A );
		$state['required_proof_present'] = null !== $proof;
		$state['required_proof']         = hash( 'sha256', serialize( $proof ) );
		$options                         = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name IN (%s, %s) ORDER BY option_name', $wpdb->options, 'mainwp_enableNativeBackups', 'mainwp_enableLegacyBackupFeature' ), ARRAY_A );
		$state['native_options']         = hash( 'sha256', serialize( $options ) );
		$files                           = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->private_root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $files as $file ) {
			$state['files'][ $file->getPathname() ] = $file->isDir() ? 'directory' : hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $state['files'] );
		return $state;
	}

	/** Only the deleted snapshot's destinations may be named in either transport. */
	public function test_cloud_destination_names_match_ui_and_schema() {
		global $wpdb;
		$store = MainWP_Backup_Storage_Destinations::instance();
		$name  = 'Cost $& Co';
		$dest  = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => $name,
				'settings' => array(),
			)
		);
		$other = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Unrelated destination',
				'settings' => array(),
			)
		);
		$id    = $this->snapshot();
		$job   = array(
			'snapshot_id'    => $id,
			'destination_id' => $dest,
			'provider'       => 's3',
			'status'         => 'complete',
			'kind'           => 'upload',
			'created'        => time(),
			'updated'        => time(),
		);
		$wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), $job );
		$input = array(
			'site_id'               => $this->site_id,
			'snapshot_id'           => $id,
			'expected_snapshot_ids' => array( $id ),
			'delete_cloud_copies'   => '1',
		);
		try {
			$ui = $this->ajax( 'mainwp_backup_snapshot_delete', $input );
			$this->assertSame(
				array(
					array(
						'snapshot_id'      => $id,
						'destination_id'   => $dest,
						'destination_name' => $name,
					),
				),
				$ui['cloud_deletes_queued']
			);
			$this->assertStringNotContainsString( 'Unrelated destination', wp_json_encode( $ui ) );
			$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
			file_put_contents( $row->archives[0]['path'], 'backup' );
			MainWP_Backup_Snapshots::instance()->set_status( $id, 'complete' );
			$wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), $job );
			$ability = $this->ability();
			$result  = $ability->execute(
				array(
					'site_id_or_domain'     => $this->site_id,
					'snapshot_id'           => $id,
					'expected_snapshot_ids' => array( $id ),
					'delete_cloud_copies'   => true,
					'confirm'               => true,
				)
			);
			$this->assertSame( $ui, $result );
			$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema() ) );
		} finally {
			$store->delete_destination( $dest );
			$store->delete_destination( $other );
		}
	}

	/** A preview refusal keeps the operations message after schema projection. */
	public function test_busy_preview_message_matches_ui() {
		$id = $this->snapshot();
		\MainWP\Dashboard\MainWP_Backup_Restore_Job_Store::instance()->reserve_site( $this->site_id );
		$ui     = $this->ajax(
			'mainwp_backup_snapshot_delete_preview',
			array(
				'site_id'     => $this->site_id,
				'snapshot_id' => $id,
			)
		);
		$result = $this->ability()->execute(
			array(
				'site_id_or_domain' => $this->site_id,
				'snapshot_id'       => $id,
				'dry_run'           => true,
			)
		);
		$this->assertSame( 'A restore or another backup action is running on this site. Try again when it finishes.', $result['refusals'][0]['message'] );
		$this->assertSame( $ui, $result );
	}

	/** Preview and confirmation must agree byte-for-byte with the UI payload. */
	public function test_ability_ui_preview_and_confirm_match() {
		$ability = $this->ability();
		$base    = $this->snapshot();
		$leaf    = $this->snapshot( $base );
		$input   = array(
			'site_id_or_domain'   => $this->site_id,
			'snapshot_id'         => $base,
			'delete_cloud_copies' => false,
		);
		$preview = $ability->execute( $input + array( 'dry_run' => true ) );
		$this->assertIsArray( $preview );
		$this->assertTrue( rest_validate_value_from_schema( $preview, $ability->get_output_schema() ) );
		$ui = $this->ajax(
			'mainwp_backup_snapshot_delete_preview',
			array(
				'site_id'             => $this->site_id,
				'snapshot_id'         => $base,
				'delete_cloud_copies' => '0',
			)
		);
		$this->assertSame( $preview, $ui );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$expected = $preview['would_affect']['snapshot_ids'];
		$ui       = $this->ajax(
			'mainwp_backup_snapshot_delete',
			array(
				'site_id'               => $this->site_id,
				'snapshot_id'           => $base,
				'expected_snapshot_ids' => $expected,
				'delete_cloud_copies'   => '0',
			)
		);
		$this->assertSame( 'complete', $ui['status'] );
		// Recreate the same persisted inputs to compare the second transport's outcome.
		foreach ( array( $base, $leaf ) as $id ) {
			$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
			file_put_contents( $row->archives[0]['path'], 'backup' );
			MainWP_Backup_Snapshots::instance()->set_status( $id, 'complete' );
		}
		$result = $ability->execute(
			$input + array(
				'confirm'               => true,
				'expected_snapshot_ids' => $expected,
			)
		);
		$this->assertSame( $ui, $result );
		$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema() ) );
	}

	/** A changed set returns the same fresh preview through both transports. */
	public function test_ability_ui_changed_set_match() {
		$ability = $this->ability();
		$base    = $this->snapshot();
		$input   = array(
			'site_id_or_domain' => $this->site_id,
			'snapshot_id'       => $base,
		);
		$preview = $ability->execute( $input + array( 'dry_run' => true ) );
		$this->snapshot( $base );
		$ids    = $preview['would_affect']['snapshot_ids'];
		$result = $ability->execute(
			$input + array(
				'confirm'               => true,
				'expected_snapshot_ids' => $ids,
			)
		);
		$this->assertSame( 'changed', $result['status'] );
		// cleanup_error() passes this refusal through unchanged rather than replacing it with a generic error.
		$this->assertSame( 'This site\'s backups changed since the preview. Check the new list, then delete again.', $result['errors'][0]['message'] );
		$this->assertSame(
			$result,
			$this->ajax(
				'mainwp_backup_snapshot_delete',
				array(
					'site_id'               => $this->site_id,
					'snapshot_id'           => $base,
					'expected_snapshot_ids' => $ids,
				)
			)
		);
		$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema() ) );
	}

	/** The new action follows the house consent gate and requires the preview set. */
	public function test_consent_and_expected_ids_required() {
		$ability = $this->ability();
		$id      = $this->snapshot();
		$input   = array(
			'site_id_or_domain' => $this->site_id,
			'snapshot_id'       => $id,
		);
		$this->assertSame( 'mainwp_confirmation_required', $ability->execute( $input )->get_error_code() );
		$this->assertSame(
			'mainwp_invalid_input',
			$ability->execute(
				$input + array(
					'dry_run' => true,
					'confirm' => true,
				)
			)->get_error_code()
		);
		$this->assertSame( 'mainwp_invalid_input', $ability->execute( $input + array( 'confirm' => true ) )->get_error_code() );
	}

	/**
	 * Noncanonical IDs must never be coerced.
	 *
	 * @return array
	 */
	public static function bad_ids() {
		return array( array( 1.0 ), array( '01' ), array( '1e0' ), array( '+1' ), array( 9007199254740992 ), array( '9007199254740992' ) );
	}

	/**
	 * Strict selection identities apply to direct callbacks and REST bodies.
	 *
	 * @dataProvider bad_ids
	 * @param mixed $bad Invalid id.
	 */
	public function test_expected_ids_strict( $bad ) {
		$this->ability();
		$id     = $this->snapshot();
		$input  = array(
			'site_id_or_domain'     => $this->site_id,
			'snapshot_id'           => $id,
			'confirm'               => true,
			'expected_snapshot_ids' => array( $bad ),
		);
		$result = MainWP_Abilities_Backups::execute_delete_backup_snapshot( $input );
		$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
		$request = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/mainwp/delete-backup-snapshot-v1/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ), JSON_PRESERVE_ZERO_FRACTION ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	/** The AJAX transport must apply the same ID rule before integer casts. */
	public function test_ajax_rejects_bad_ids_and_nonce() {
		foreach ( array( 'mainwp_backup_snapshot_delete_preview', 'mainwp_backup_snapshot_delete' ) as $action ) {
			$_REQUEST = array( 'security' => 'wrong-nonce' );
			$this->assertFalse( MainWP_Post_Backup_Handler::instance()->check_security( $action, 'security', false ) );
			$_REQUEST = array( 'security' => wp_create_nonce( $action ) );
			$this->assertTrue( MainWP_Post_Backup_Handler::instance()->check_security( $action, 'security', false ) );
		}
		$this->ability();
		$id     = $this->snapshot();
		$result = $this->ajax(
			'mainwp_backup_snapshot_delete',
			array(
				'site_id'               => $this->site_id,
				'snapshot_id'           => $id,
				'expected_snapshot_ids' => array( '01' ),
			)
		);
		$this->assertArrayHasKey( 'error', $result );
		$result = $this->ajax(
			'mainwp_backup_snapshot_delete_preview',
			array(
				'site_id'     => $this->site_id,
				'snapshot_id' => '01',
			)
		);
		$this->assertArrayHasKey( 'error', $result );
	}
}
