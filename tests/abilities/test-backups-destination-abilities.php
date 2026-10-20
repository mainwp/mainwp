<?php
/**
 * Stored destination actions must keep credentials and paths inside the Dashboard.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_Local;
use MainWP\Dashboard\MainWP_Backup_Storage_SFTP;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

require_once __DIR__ . '/../backup/class-mainwp-backup-read-fixtures.php';
require_once __DIR__ . '/test-backups-restore-abilities.php';

/** Consent and stored settings must govern all destination actions. */
class MainWP_Backups_Destination_Abilities_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Purity_Guard;

	/**
	 * Destination allocated by this case.
	 *
	 * @var int
	 */
	private $id;
	/**
	 * Private local destination.
	 *
	 * @var string
	 */
	private $dir;

	/** Real local probes verify the public handler without remote connections. */
	public function set_up() {
		parent::set_up();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->dir = sys_get_temp_dir() . '/mainwp-destination-ability-' . uniqid();
		mkdir( $this->dir );
		$this->id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Stored destination',
				'settings' => array( 'base_path' => $this->dir ),
			)
		);
	}

	/** The case owns its rows and scratch files. */
	public function tear_down() {
		MainWP_Backup_Storage_Destinations::instance()->delete_destination( $this->id );
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * All actions use POST because each confirmed call can write.
	 *
	 * @return array
	 */
	public static function actions() {
		return array(
			array( 'test-backup-destination', false, true ),
			array( 'set-default-backup-destination', false, true ),
			array( 'update-backup-destination', false, false ),
			array( 'delete-backup-destination', true, false ),
		);
	}

	/**
	 * Missing registrations must fail before dispatch.
	 *
	 * @param string $name Action slug.
	 * @return \WP_Ability
	 */
	private function ability( $name ) {
		$this->assertTrue( wp_has_ability( 'mainwp/' . $name . '-v1' ), $name );
		return wp_get_ability( 'mainwp/' . $name . '-v1' );
	}

	/**
	 * A rename is the smallest valid update.
	 *
	 * @param string $name Action slug.
	 * @return array
	 */
	private function input( $name ) {
		return array( 'destination_id' => $this->id ) + ( 'update-backup-destination' === $name ? array( 'name' => 'Renamed destination' ) : array() );
	}

	/**
	 * Preview failures must be pure too.
	 *
	 * @param string $name Action slug.
	 * @param array  $input Caller input.
	 * @param bool   $direct Bypass core permission wrapping.
	 * @return mixed
	 */
	private function execute( $name, array $input, $direct = false ) {
		$ability = $this->ability( $name );
		$call    = static function () use ( $ability, $name, $input, $direct ) {
			$method = 'execute_' . str_replace( '-', '_', $name );
			return $direct ? MainWP_Abilities_Backups::$method( $input ) : $ability->execute( $input );
		};
		return ! empty( $input['dry_run'] ) ? $this->guarded( $call ) : $call();
	}

	/**
	 * REST previews must retain the same purity guard as PHP previews.
	 *
	 * @param string $name Action slug.
	 * @param array  $input Caller input.
	 * @return \WP_REST_Response
	 */
	private function rest( $name, array $input ) {
		global $wp_rest_server;
		$previous       = $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		$request = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
		$call = static function () use ( $request ) {
			return rest_do_request( $request );
		};
		try {
			return ! empty( $input['dry_run'] ) ? $this->guarded( $call ) : $call();
		} finally {
			$wp_rest_server = $previous;
		}
	}

	/**
	 * Both entry points must follow the destination field rules.
	 *
	 * @return array
	 */
	public static function transports() {
		return array(
			'PHP'  => array( false ),
			'REST' => array( true ),
		);
	}

	/**
	 * Projection must exclude both location keys and their values.
	 *
	 * @param string $name Action slug.
	 * @param mixed  $output Result.
	 */
	private function assert_output( $name, $output ) {
		$this->assertIsArray( $output, is_wp_error( $output ) ? $output->get_error_message() : '' );
		$this->assertTrue( rest_validate_value_from_schema( $output, $this->ability( $name )->get_output_schema() ) );
		$text = wp_json_encode( $output, JSON_UNESCAPED_SLASHES );
		$this->assertStringNotContainsString( 'base_path', $text );
		$this->assertStringNotContainsString( $this->dir, $text );
	}

	/**
	 * Discovery must match authority and write semantics.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 * @param bool   $destructive Annotation.
	 * @param bool   $idempotent Annotation.
	 */
	public function test_ability_is_registered( $name, $destructive, $idempotent ) {
		$ability = $this->ability( $name );
		$this->assertSame( 'mainwp-backups', $ability->get_category() );
		$this->assertSame( array( 'edit_backup_tasks' ), MainWP_Abilities_Util::required_rights_for_ability( $ability->get_name() ) );
		$this->assertNotEmpty( $ability->get_label() );
		$this->assertStringContainsString( 'Possible errors:', $ability->get_description() );
		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertSame( $destructive, $meta['annotations']['destructive'] );
		$this->assertSame( $idempotent, $meta['annotations']['idempotent'] );
		$this->assertFalse( wp_has_ability( 'mainwp/create-backup-destination-v1' ) );
	}

	/**
	 * Confirmed actions must persist their documented effects.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 */
	public function test_action_returns_expected_structure( $name ) {
		$output = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		$this->assert_output( $name, $output );
		$this->assertFalse( $output['dry_run'] );
		$row = MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id );
		if ( 'delete-backup-destination' === $name ) {
			$this->assertNull( $row );
			$this->assertTrue( $output['deleted'] );
			$this->assertSame( 0, $output['tasks_detached'] );
		} elseif ( 'update-backup-destination' === $name ) {
			$this->assertSame( 'Renamed destination', $row->name );
			$this->assertSame( array( 'base_path' => $this->dir ), $row->settings );
			$this->assertSame( $this->id, $output['destination']['id'] );
		} elseif ( 'set-default-backup-destination' === $name ) {
			$this->assertTrue( $output['is_default'] );
			$this->assertSame( 1, (int) $row->is_default );
		} else {
			$this->assertTrue( $output['ok'] );
			$this->assertNull( $output['error'] );
			$this->assertFalse( $output['host_key_pinned'] );
			$this->assertSame( array(), glob( $this->dir . '/*' ) );
		}
	}

	/**
	 * Anonymous previews must not read storage.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 */
	public function test_action_requires_authentication( $name ) {
		wp_set_current_user( 0 );
		$error = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ), true );
		$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
	}

	/**
	 * Site ownership does not grant global configuration authority.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 */
	public function test_action_requires_manage_options( $name ) {
		$this->set_current_user_as_subscriber();
		$error = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ), true );
		$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	/**
	 * Global destinations can affect tasks outside the caller's site scope.
	 *
	 * @return array
	 */
	public static function restricted_action_requests() {
		$requests = array();
		foreach ( self::actions() as $action ) {
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				foreach ( array( 'permission', 'callback', 'REST' ) as $transport ) {
					$requests[ $action[0] . '/' . $mode . '/' . $transport ] = array( $action[0], $mode, $transport );
				}
			}
		}
		return $requests;
	}

	/**
	 * Site restrictions must protect global storage before any destination lookup.
	 *
	 * @dataProvider restricted_action_requests
	 * @param string $name Action slug.
	 * @param string $mode Consent path.
	 * @param string $transport Entry point.
	 */
	public function test_restricted_account_is_refused_before_storage( $name, $mode, $transport ) {
		$store         = MainWP_Backup_Storage_Destinations::instance();
		$before        = $store->list_destinations();
		$table         = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$storage_calls = 0;
		$observe       = static function ( $sql ) use ( $table, &$storage_calls ) {
			if ( false !== strpos( $sql, $table ) ) {
				++$storage_calls;
			}
			return $sql;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		add_filter( 'query', $observe );
		try {
			$input = $this->input( $name ) + array( $mode => true );
			if ( 'REST' === $transport ) {
				$response = $this->rest( $name, $input );
				$code     = $response->get_data()['code'] ?? null;
				$status   = $response->get_status();
			} else {
				$output = 'permission' === $transport ? $this->ability( $name )->check_permissions( $input ) : $this->execute( $name, $input, true );
				$code   = is_wp_error( $output ) ? $output->get_error_code() : null;
				$status = is_wp_error( $output ) ? $output->get_error_data()['status'] : null;
			}
		} finally {
			remove_filter( 'query', $observe );
			remove_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		}
		$this->assertSame( 'mainwp_restricted_account', $code );
		$this->assertSame( 403, $status );
		$this->assertSame( 0, $storage_calls, 'A restricted action reached destination storage.' );
		$this->assertEquals( $before, $store->list_destinations(), 'A restricted action changed destination rows.' );
	}

	/**
	 * Delete previews can disclose tasks belonging to sites the caller cannot access.
	 *
	 * @dataProvider transports
	 * @param bool $rest Use the REST run route.
	 */
	public function test_restricted_delete_preview_does_not_disclose_out_of_scope_task( $rest ) {
		$task = MainWP_Backup_Operations::create_task(
			array(
				'name'     => 'Out-of-scope destination task',
				'type'     => 'full',
				'schedule' => 'daily',
				'site_ids' => array( $this->create_test_site() ),
			),
			'ability'
		);
		$this->assertIsArray( $task );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task['task_id'], array( 'destinations' => wp_json_encode( array( $this->id ) ) ) );
		add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		try {
			$input  = $this->input( 'delete-backup-destination' ) + array( 'dry_run' => true );
			$output = $rest ? $this->rest( 'delete-backup-destination', $input )->get_data() : $this->execute( 'delete-backup-destination', $input, true );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		}
		$text = wp_json_encode( $output );
		$this->assertStringNotContainsString( 'Out-of-scope destination task', $text );
		$this->assertDoesNotMatchRegularExpression( '/"task_id":' . $task['task_id'] . '(?:[,}])/', $text );
		$this->assertSame( wp_json_encode( array( $this->id ) ), MainWP_DB_Backup::instance()->get_backup_task_by_id( $task['task_id'] )->destinations );
	}

	/**
	 * Undocumented fields must stop before the adapter.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 */
	public function test_action_validates_input( $name ) {
		$this->assertSame(
			'ability_invalid_input',
			$this->execute(
				$name,
				$this->input( $name ) + array(
					'settings' => array(),
					'confirm'  => true,
				)
			)->get_error_code()
		);
	}

	/**
	 * Pure previews cannot probe a provider or mutate defaults.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 */
	public function test_action_dry_run_returns_preview( $name ) {
		$output = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
		$this->assert_output( $name, $output );
		$this->assertTrue( $output['dry_run'] );
		$this->assertTrue( $output['can_proceed'] );
		$this->assertSame( array(), $output['refusals'] );
		$this->assertSame( 'Stored destination', MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->name );
		$this->assertSame( array(), glob( $this->dir . '/*' ) );
	}

	/**
	 * Consent must be explicit.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 */
	public function test_action_requires_confirmation( $name ) {
		$this->assertSame( 'mainwp_confirmation_required', $this->execute( $name, $this->input( $name ) )->get_error_code() );
	}

	/**
	 * Ambiguous consent cannot write.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 */
	public function test_action_rejects_confirm_with_dry_run( $name ) {
		$this->assertSame(
			'mainwp_invalid_input',
			$this->execute(
				$name,
				$this->input( $name ) + array(
					'confirm' => true,
					'dry_run' => true,
				)
			)->get_error_code()
		);
	}

	/**
	 * Native-off and granular authority apply to previews and confirmation.
	 *
	 * @dataProvider actions
	 * @param string $name Action slug.
	 */
	public function test_native_flag_and_operation_right_are_required( $name ) {
		foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
			add_filter( 'mainwp_currentusercan', '__return_false' );
			try {
				$this->assertSame( 'mainwp_insufficient_right', $this->execute( $name, $this->input( $name ) + array( $mode => true ), true )->get_error_code() );
			} finally {
				remove_filter( 'mainwp_currentusercan', '__return_false' );
			}
			update_option( 'mainwp_enableNativeBackups', 0 );
			$this->assertSame( 'mainwp_backup_legacy_disabled', $this->execute( $name, $this->input( $name ) + array( $mode => true ) )->get_error_code() );
			update_option( 'mainwp_enableNativeBackups', 1 );
		}
	}

	/**
	 * Each ID must keep the same identity through PHP and REST.
	 *
	 * @dataProvider id_shapes
	 * @param mixed $id Candidate identity.
	 * @param mixed $expected Canonical identity or null.
	 */
	public function test_destination_id_contract( $id, $expected ) {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		foreach ( self::actions() as $action ) {
			$name  = $action[0];
			$input = array_replace(
				$this->input( $name ),
				array(
					'destination_id' => $id,
					'dry_run'        => true,
				)
			);
			$code  = null === $expected ? 'mainwp_invalid_input' : 'mainwp_backup_destination_not_found';
			// Allocated rows can legitimately reuse a small canonical identity.
			if ( null !== $expected && MainWP_Backup_Storage_Destinations::instance()->get_destination( $expected ) ) {
				$this->assert_output( $name, $this->execute( $name, $input ) );
			} else {
				$this->assertSame( $code, $this->execute( $name, $input )->get_error_code() );
			}
			$request = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'input' => $input ), JSON_PRESERVE_ZERO_FRACTION ) );
			$response = $this->guarded(
				static function () use ( $wp_rest_server, $request ) {
					return $wp_rest_server->dispatch( $request );
				}
			);
			$this->assertSame( null === $expected ? 400 : ( MainWP_Backup_Storage_Destinations::instance()->get_destination( $expected ) ? 200 : 404 ), $response->get_status(), wp_json_encode( $response->get_data() ) );
		}
		$wp_rest_server = null;
	}

	/**
	 * Shared identity fixtures keep every backup input on one contract.
	 *
	 * @return array
	 */
	public static function id_shapes() {
		return MainWP_Backups_Restore_Abilities_Test::id_shapes();
	}

	/** Canonical string identities must resolve the allocated destination. */
	public function test_canonical_string_identity_succeeds_for_every_action() {
		foreach ( self::actions() as $action ) {
			$name = $action[0];
			$this->assert_output(
				$name,
				$this->execute(
					$name,
					array_replace(
						$this->input( $name ),
						array(
							'destination_id' => (string) $this->id,
							'dry_run'        => true,
						)
					)
				)
			);
		}
	}

	/** Default changes must preserve existing task selections and stored locations. */
	public function test_update_default_flag_and_preview_previous_default() {
		$store    = MainWP_Backup_Storage_Destinations::instance();
		$previous = $store->add_destination(
			array(
				'provider'   => 'local',
				'name'       => 'Previous default',
				'is_default' => true,
				'settings'   => array( 'base_path' => $this->dir ),
			)
		);
		try {
			$preview = $this->execute(
				'set-default-backup-destination',
				array(
					'destination_id' => $this->id,
					'dry_run'        => true,
				)
			);
			$this->assertSame( $previous, $preview['would_affect']['previous_default_id'] );
			foreach ( array( true, false ) as $default ) {
				$input   = array(
					'destination_id' => $this->id,
					'is_default'     => $default,
				);
				$preview = $this->execute( 'update-backup-destination', $input + array( 'dry_run' => true ) );
				$this->assertSame(
					array(
						array(
							'field' => 'is_default',
							'from'  => ! $default,
							'to'    => $default,
						),
					),
					$preview['would_affect']['changes']
				);
				$this->assertTrue( $preview['would_affect']['connection_test_runs'] );
				$output = $this->execute( 'update-backup-destination', $input + array( 'confirm' => true ) );
				$this->assert_output( 'update-backup-destination', $output );
				$this->assertSame( $default, $output['destination']['is_default'] );
				$this->assertSame( 'Stored destination', $output['destination']['name'] );
				$this->assertSame( 0, (int) $store->get_destination( $previous )->is_default );
				$this->assertSame( array( 'base_path' => $this->dir ), $store->get_destination( $this->id )->settings );
			}
		} finally {
			$store->delete_destination( $previous );
		}
	}

	/** Only name and default flag can reach the stored-settings update flow. */
	public function test_update_rejects_every_connection_field_and_empty_changes() {
		foreach ( array( 'provider', 'settings', 'host', 'password', 'base_path', 'destination_ids', 'unknown' ) as $key ) {
			$error = $this->execute(
				'update-backup-destination',
				$this->input( 'update-backup-destination' ) + array(
					$key      => 'private',
					'confirm' => true,
				)
			);
			$this->assertSame( 'ability_invalid_input', $error->get_error_code(), $key );
		}
		foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
			$this->assertSame(
				'mainwp_invalid_input',
				$this->execute(
					'update-backup-destination',
					array(
						'destination_id' => $this->id,
						$mode            => true,
					)
				)->get_error_code()
			);
		}
	}

	/** Unreadable stored settings cannot silently become a credential-free update. */
	public function test_update_refuses_corrupt_stored_secrets() {
		global $wpdb;
		// Teardown finds the destination's key file through the stored envelope, so the envelope must come back.
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$envelope = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $this->id ) ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->update( $table, array( 'settings' => 'unreadable' ), array( 'id' => $this->id ) );
		try {
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				$error = $this->execute( 'update-backup-destination', $this->input( 'update-backup-destination' ) + array( $mode => true ) );
				$this->assertSame( 'mainwp_backup_destination_credentials_required', $error->get_error_code() );
				$this->assertSame( 400, $error->get_error_data()['status'] );
			}
		} finally {
			$wpdb->update( $table, array( 'settings' => $envelope ), array( 'id' => $this->id ) );
		}
	}

	/**
	 * Length limits apply to the stored name after tags and whitespace are removed.
	 *
	 * @return array
	 */
	public static function sanitized_names() {
		$cases = array();
		foreach ( array( false, true ) as $rest ) {
			$cases[] = array( $rest, "Prod <b>S3</b>\nbox", 'Prod S3 box' );
			$cases[] = array( $rest, '<b>' . str_repeat( 'é', 255 ) . '</b>', str_repeat( 'é', 255 ) );
		}
		return $cases;
	}

	/**
	 * Preview consent must describe the exact name that confirmation stores.
	 *
	 * @dataProvider sanitized_names
	 * @param bool   $rest Use the REST run route.
	 * @param string $name Submitted name.
	 * @param string $expected Sanitized name.
	 */
	public function test_update_sanitizes_name_for_preview_and_confirm( $rest, $name, $expected ) {
		$input = array(
			'destination_id' => $this->id,
			'name'           => $name,
		);
		if ( $rest ) {
			$response = $this->rest( 'update-backup-destination', $input + array( 'dry_run' => true ) );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$preview = $response->get_data();
		} else {
			$preview = $this->execute( 'update-backup-destination', $input + array( 'dry_run' => true ) );
		}
		$this->assert_output( 'update-backup-destination', $preview );
		$this->assertSame(
			array(
				array(
					'field' => 'name',
					'from'  => 'Stored destination',
					'to'    => $expected,
				),
			),
			$preview['would_affect']['changes']
		);
		$this->assertSame( 'Stored destination', MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->name );
		if ( $rest ) {
			$response = $this->rest( 'update-backup-destination', $input + array( 'confirm' => true ) );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$output = $response->get_data();
		} else {
			$output = $this->execute( 'update-backup-destination', $input + array( 'confirm' => true ) );
		}
		$this->assert_output( 'update-backup-destination', $output );
		$this->assertSame( $expected, $output['destination']['name'] );
		$this->assertSame( $expected, MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->name );
	}

	/**
	 * Sanitized names must be nonempty and fit the stored field.
	 *
	 * @return array
	 */
	public static function invalid_sanitized_names() {
		return array(
			array( false, '<b></b>' ),
			array( true, '<b></b>' ),
			array( false, str_repeat( 'é', 256 ) ),
			array( true, str_repeat( 'é', 256 ) ),
		);
	}

	/**
	 * Invalid sanitized names must fail before a probe or any row change.
	 *
	 * @dataProvider invalid_sanitized_names
	 * @param bool   $rest Use the REST run route.
	 * @param string $name Submitted name.
	 */
	public function test_update_rejects_invalid_sanitized_name( $rest, $name ) {
		$before = MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id );
		foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
			$input = array(
				'destination_id' => $this->id,
				'name'           => $name,
				$mode            => true,
			);
			if ( $rest ) {
				$response = $this->rest( 'update-backup-destination', $input );
				$this->assertSame( 400, $response->get_status() );
				$this->assertSame( 'mainwp_invalid_input', $response->get_data()['code'] );
			} else {
				$error = $this->execute( 'update-backup-destination', $input );
				$this->assertWPError( $error );
				$this->assertSame( 'mainwp_invalid_input', $error->get_error_code() );
				$this->assertSame( 400, $error->get_error_data()['status'] );
			}
			$this->assertEquals( $before, MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id ) );
		}
	}

	/**
	 * Update consent must disclose the same first-contact pin as a connection test.
	 *
	 * @dataProvider transports
	 * @param bool $rest Use the REST run route.
	 */
	public function test_update_preview_discloses_host_key_pinning( $rest ) {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$this->assertArrayNotHasKey( $this->id, get_option( MainWP_Backup_Storage_SFTP::HOSTKEYS_OPTION, array() ) );
		foreach ( array( 'local', 'sftp' ) as $provider ) {
			if ( 'sftp' === $provider ) {
				$this->assertTrue(
					$store->update_destination(
						$this->id,
						array(
							'provider' => 'sftp',
							'settings' => array(
								'password'  => 'stored-password',
								'base_path' => $this->dir,
							),
						)
					)
				);
			}
			$input = $this->input( 'update-backup-destination' ) + array( 'dry_run' => true );
			if ( $rest ) {
				$response = $this->rest( 'update-backup-destination', $input );
				$this->assertSame( 200, $response->get_status() );
				$output = $response->get_data();
			} else {
				$output = $this->execute( 'update-backup-destination', $input );
			}
			$this->assert_output( 'update-backup-destination', $output );
			$this->assertSame( 'sftp' === $provider, $output['would_affect']['may_pin_host_key'] );
			$this->assertTrue( $output['would_affect']['connection_test_runs'] );
			$this->assertArrayNotHasKey( $this->id, get_option( MainWP_Backup_Storage_SFTP::HOSTKEYS_OPTION, array() ) );
		}
	}

	/** A row whose settings cannot be read fails before any handshake, so no preview may promise a pin. */
	public function test_unreadable_sftp_settings_never_promise_a_pin() {
		global $wpdb;
		$this->assertTrue(
			MainWP_Backup_Storage_Destinations::instance()->update_destination(
				$this->id,
				array(
					'provider' => 'sftp',
					'settings' => array(
						'password'  => 'stored-password',
						'base_path' => $this->dir,
					),
				)
			)
		);
		$output = $this->execute( 'test-backup-destination', $this->input( 'test-backup-destination' ) + array( 'dry_run' => true ) );
		$this->assertTrue( $output['would_affect']['may_pin_host_key'] );
		// Teardown finds the destination's key file through the stored envelope, so the envelope must come back.
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$envelope = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM {$table} WHERE id = %d", $this->id ) ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->update( $table, array( 'settings' => 'unreadable' ), array( 'id' => $this->id ) );
		try {
			$output = $this->execute( 'test-backup-destination', $this->input( 'test-backup-destination' ) + array( 'dry_run' => true ) );
		} finally {
			$wpdb->update( $table, array( 'settings' => $envelope ), array( 'id' => $this->id ) );
		}
		$this->assert_output( 'test-backup-destination', $output );
		$this->assertFalse( $output['would_affect']['may_pin_host_key'] );
	}

	/** Connection testing must happen before a rename is persisted. */
	public function test_update_does_not_save_when_connection_test_fails() {
		MainWP_Backup_Storage_Destinations::instance()->update_destination( $this->id, array( 'settings' => array( 'base_path' => $this->dir . '/missing' ) ) );
		$error = $this->execute( 'update-backup-destination', $this->input( 'update-backup-destination' ) + array( 'confirm' => true ) );
		$this->assertWPError( $error );
		$this->assertSame( 'mainwp_backup_local_failed', $error->get_error_code() );
		$this->assertSame( 502, $error->get_error_data()['status'] );
		$this->assertStringNotContainsString( $this->dir, wp_json_encode( $error, JSON_UNESCAPED_SLASHES ) );
		$this->assertSame( 'Stored destination', MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id )->name );
	}

	/**
	 * Failed local probes must describe the Dashboard folder without disclosing its path.
	 *
	 * @dataProvider transports
	 * @param bool $rest Use the REST run route.
	 */
	public function test_local_connection_failure_uses_local_code_without_path( $rest ) {
		MainWP_Backup_Storage_Destinations::instance()->update_destination( $this->id, array( 'settings' => array( 'base_path' => $this->dir . '/missing' ) ) );
		$input = array(
			'destination_id' => $this->id,
			'confirm'        => true,
		);
		if ( $rest ) {
			$response = $this->rest( 'test-backup-destination', $input );
			$this->assertSame( 200, $response->get_status() );
			$output = $response->get_data();
		} else {
			$output = $this->execute( 'test-backup-destination', $input );
		}
		$this->assert_output( 'test-backup-destination', $output );
		$this->assertFalse( $output['ok'] );
		$this->assertSame( MainWP_Backup_Operations::safe_error( 'mainwp_backup_local_failed' ), $output['error'] );
		$this->assertFalse( $output['host_key_pinned'] );
	}

	/**
	 * A refused Dashboard delete must not report a Child response failure.
	 *
	 * @dataProvider transports
	 * @param bool $rest Use the REST run route.
	 */
	public function test_delete_storage_failure_is_internal_error( $rest ) {
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$hits  = 0;
		$fail  = function ( $sql ) use ( $table, &$hits ) {
			if ( ( 'DELETE FROM `' . $table . '` WHERE `id` = ' . $this->id ) === $sql ) {
				++$hits;
				return $sql . ' AND 1 = 0';
			}
			return $sql;
		};
		add_filter( 'query', $fail );
		try {
			$input = array(
				'destination_id' => $this->id,
				'confirm'        => true,
			);
			if ( $rest ) {
				$response = $this->rest( 'delete-backup-destination', $input );
				$this->assertSame( 500, $response->get_status() );
				$this->assertSame( 'mainwp_internal_error', $response->get_data()['code'] );
				$this->assertSame( MainWP_Backup_Operations::safe_error( 'mainwp_internal_error' )['message'], $response->get_data()['message'] );
			} else {
				$error = $this->execute( 'delete-backup-destination', $input );
				$this->assertWPError( $error );
				$this->assertSame( 'mainwp_internal_error', $error->get_error_code() );
				$this->assertSame( 500, $error->get_error_data()['status'] );
				$this->assertSame( MainWP_Backup_Operations::safe_error( 'mainwp_internal_error' )['message'], $error->get_error_message() );
			}
		} finally {
			remove_filter( 'query', $fail );
		}
		$this->assertSame( 1, $hits );
		$this->assertNotNull( MainWP_Backup_Storage_Destinations::instance()->get_destination( $this->id ) );
	}

	/** Destination and settings writes must all use the shared consent wording. */
	public function test_destination_and_settings_confirm_copy_is_shared() {
		foreach ( array_merge( array_column( self::actions(), 0 ), array( 'update-backup-settings' ) ) as $name ) {
			$this->assertSame(
				'Must be true to perform the action. Call with dry_run first and show the preview to the user.',
				$this->ability( $name )->get_input_schema()['properties']['confirm']['description']
			);
		}
	}

	/** Delete previews must count the same nonterminal jobs the handler retires. */
	public function test_delete_detaches_tasks_fails_pending_jobs_and_keeps_objects() {
		$task = MainWP_Backup_Operations::create_task(
			array(
				'name'     => 'Destination task',
				'type'     => 'full',
				'schedule' => 'daily',
				'site_ids' => array( $this->create_test_site() ),
			),
			'ability'
		);
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task['task_id'], array( 'destinations' => wp_json_encode( array( $this->id ) ) ) );
		global $wpdb;
		$jobs = array();
		foreach ( array( array( 'upload', 'pending' ), array( 'delete', 'failed_retryable' ), array( 'upload', 'complete' ), array( 'delete', 'credentials_invalid' ) ) as $fixture ) {
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
				array(
					'destination_id' => $this->id,
					'kind'           => $fixture[0],
					'status'         => $fixture[1],
					'snapshot_id'    => $task['task_id'] + count( $jobs ),
					'created'        => time(),
					'updated'        => time(),
				)
			);
			$jobs[] = (int) $wpdb->insert_id;
		}
		file_put_contents( $this->dir . '/remote-object', 'preserve' );
		$preview = $this->execute(
			'delete-backup-destination',
			array(
				'destination_id' => $this->id,
				'dry_run'        => true,
			)
		);
		$this->assert_output( 'delete-backup-destination', $preview );
		$this->assertSame(
			array(
				array(
					'task_id' => $task['task_id'],
					'name'    => 'Destination task',
				),
			),
			$preview['would_affect']['tasks_detached']
		);
		$this->assertSame(
			array(
				'upload' => 1,
				'delete' => 1,
			),
			$preview['would_affect']['pending_jobs_failed']
		);
		$this->assertTrue( $preview['would_affect']['remote_objects_left_in_place'] );
		$output = $this->execute(
			'delete-backup-destination',
			array(
				'destination_id' => $this->id,
				'confirm'        => true,
			)
		);
		$this->assertSame( 1, $output['tasks_detached'] );
		$this->assertSame( array(), json_decode( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task['task_id'] )->destinations, true ) );
		$this->assertSame( 'preserve', file_get_contents( $this->dir . '/remote-object' ) );
		$this->assertSame(
			array( 'failed_permanent', 'failed_permanent', 'complete', 'credentials_invalid' ),
			array_map(
				static function ( $id ) use ( $wpdb ) {
					return $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ) . ' WHERE id = %d', $id ) );
				},
				$jobs
			)
		);
	}

	/**
	 * Provider failures and first-contact pins must not disclose connection material.
	 *
	 * @dataProvider sftp_results
	 * @param bool $ok Probe result.
	 * @param bool $pins Whether first contact stored a key.
	 */
	public function test_sftp_probe_redacts_fingerprints_and_reports_first_pin( $ok, $pins ) {
		$store = MainWP_Backup_Storage_Destinations::instance();
		$store->update_destination(
			$this->id,
			array(
				'provider' => 'sftp',
				'settings' => array(
					'password'  => 'stored-password',
					'base_path' => $this->dir,
				),
			)
		);
		$reflection = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$reflection->setAccessible( true );
		$mock     = $this->getMockBuilder( MainWP_Backup_Storage_Destinations::class )->onlyMethods( array( 'make_provider' ) )->getMock();
		$provider = $this->getMockBuilder( MainWP_Backup_Storage_Local::class )->setConstructorArgs( array( array() ) )->onlyMethods( array( 'connection_test' ) )->getMock();
		$provider->expects( $this->once() )->method( 'connection_test' )->willReturnCallback(
			function () use ( $ok, $pins ) {
				if ( $pins ) {
					update_option( MainWP_Backup_Storage_SFTP::HOSTKEYS_OPTION, array( $this->id => 'SHA256:offered-fingerprint' ) );
				}
				return array(
					'ok'    => $ok,
					'error' => $ok ? null : 'Pinned: SHA256:pinned-fingerprint offered: SHA256:offered-fingerprint ' . $this->dir,
				);
			}
		);
		$mock->method( 'make_provider' )->willReturn( $provider );
		$reflection->setValue( null, $mock );
		try {
			$preview = $this->execute(
				'test-backup-destination',
				array(
					'destination_id' => $this->id,
					'dry_run'        => true,
				)
			);
			$this->assertTrue( $preview['would_affect']['may_pin_host_key'] );
			$output = $this->execute(
				'test-backup-destination',
				array(
					'destination_id' => $this->id,
					'confirm'        => true,
				)
			);
			$this->assert_output( 'test-backup-destination', $output );
			$this->assertSame( $ok, $output['ok'] );
			$this->assertSame( $pins, $output['host_key_pinned'] );
			$this->assertStringNotContainsString( 'fingerprint', wp_json_encode( $output ) );
			if ( ! $ok ) {
				$this->assertSame( 'mainwp_backup_ftp_failed', $output['error']['code'] );
			}
		} finally {
			$reflection->setValue( null, $store );
		}
	}

	/**
	 * Both probe outcomes use the same privacy boundary.
	 *
	 * @return array
	 */
	public static function sftp_results() {
		return array( array( true, true ), array( false, false ), array( false, true ) );
	}
}
