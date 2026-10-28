<?php
/**
 * Fresh layout negotiation and permanent backup refusals.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;

/** Exercise negotiation through the authenticated request boundary. */
class MainWP_Backup_V2_Negotiation_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Connected fixture site.
	 *
	 * @var object
	 */
	private $website;

	/**
	 * Preflight response or exception.
	 *
	 * @var mixed
	 */
	private $report;

	/**
	 * Requests emitted by production consumers.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Task response or exception.
	 *
	 * @var mixed
	 */
	private $task_response;

	/** Observe requests before signing or network access. */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->website       = MainWP_DB::instance()->get_website_by_id( $this->create_test_site() );
		$this->report        = $this->capabilities();
		$this->calls         = array();
		$this->task_response = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what, $params ) {
				if ( (int) $website->id !== (int) $this->website->id ) {
					return $pre;
				}
				$this->calls[] = array( $what, $params );
				$response      = 'backup_preflight' === $what ? $this->report : $this->task_response;
				if ( $response instanceof \Throwable ) {
					throw $response; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixture exception.
				}
				return $response;
			},
			10,
			4
		);
	}

	/** Restore the private rollout seam and remove fixture storage. */
	public function tearDown(): void {
		if ( property_exists( MainWP_Backup_Monitor::class, 'layout_v2_enabled' ) ) {
			$this->enable( false );
		}
		delete_option( 'mainwp_enableNativeBackups' );
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Set the private rollout switch for this fixture.
	 *
	 * @param bool $enabled Test switch value.
	 */
	private function enable( $enabled ) {
		$property = new \ReflectionProperty( MainWP_Backup_Monitor::class, 'layout_v2_enabled' );
		$property->setValue( null, $enabled );
	}

	/**
	 * Canonical source descriptor.
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
					'role'     => 'site',
					'coverage' => 'preserved',
				),
				array(
					'role'     => 'config',
					'coverage' => 'recovery_only',
				),
			),
			'layout_id'     => str_repeat( 'a', 64 ),
			'core_policy'   => 'managed_preserve',
			'list_format'   => 2,
			'path_encoding' => 'role-prefix',
		);
	}

	/**
	 * Fresh destination capabilities.
	 *
	 * @return array
	 */
	private function capabilities() {
		return array(
			'task_spine'        => 1,
			'backup_layout_v2'  => 1,
			'restore_layout_v2' => 1,
			'layout_id'         => str_repeat( 'a', 64 ),
			'layout_roles'      => array( 'content', 'site', 'config' ),
			'core_policy'       => 'managed_preserve',
		);
	}

	/**
	 * Source operations as assembled by the catalog.
	 *
	 * @return array
	 */
	private function plan() {
		$metadata = array(
			'backup_format'            => 2,
			'layout_descriptor'        => $this->descriptor(),
			'layout_descriptor_sha256' => hash( 'sha256', wp_json_encode( $this->descriptor() ) ),
		);
		return array(
			array_merge(
				array(
					'role' => 'full',
					'file' => 'backup.mwp2',
				),
				$metadata
			),
			array_merge(
				array(
					'role' => 'manifest',
					'file' => 'files.list',
				),
				$metadata
			),
			array(
				'role' => 'db',
				'file' => 'backup.sql',
			),
		);
	}

	/** Legacy plans pass through without probing even when rollout is enabled. */
	public function test_v1_plan_is_unchanged_and_sends_nothing() {
		$this->enable( true );
		$plan   = array(
			array(
				'role' => 'full',
				'file' => 'backup.zip',
			),
			array(
				'role'          => 'db',
				'backup_format' => '1',
			),
		);
		$before = wp_json_encode( $plan );
		$this->assertSame( array(), MainWP_Backup_Monitor::restore_plan_metadata( $plan ) );
		$this->assertSame( array(), MainWP_Backup_Monitor::admit_restore_plan( $this->website, $plan, str_repeat( 'a', 64 ) ) );
		$this->assertSame( $before, wp_json_encode( $plan ) );
		$this->assertSame( array(), $this->calls );
	}

	/** The default-off switch refuses v2 before a capability request. */
	public function test_v2_is_off_by_default() {
		$result = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/** Validation must not reorder the stored source descriptor. */
	public function test_admission_preserves_original_descriptor_after_validation() {
		$this->enable( true );
		$plan = $this->plan();
		foreach ( array( 0, 1 ) as $index ) {
			$plan[ $index ]['layout_descriptor']             = array_reverse( $plan[ $index ]['layout_descriptor'], true );
			$plan[ $index ]['layout_descriptor']['roots'][0] = array(
				'coverage' => 'restore',
				'role'     => 'content',
			);
		}
		$before = wp_json_encode( $plan );
		$result = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $plan, str_repeat( 'a', 64 ) );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['backup_format'] );
		$this->assertSame( $plan[0]['layout_descriptor'], $result['layout_descriptor'] );
		$this->assertSame( $plan[0]['layout_descriptor_sha256'], $result['layout_descriptor_sha256'] );
		$this->assertSame( $before, wp_json_encode( $plan ) );
		$this->assertSame( array( array( 'backup_preflight', array( 'capabilities_only' => 1 ) ) ), $this->calls );
		$this->assertNotSame( '1', MainWP_DB::instance()->get_website_option( $this->website, 'restore_layout_v2_support' ), 'Admission must not persist fresh proof.' );
	}

	/**
	 * Child flag values and their stored representation.
	 *
	 * @return array
	 */
	public static function flag_values() {
		return array(
			'integer one' => array( 1, '1' ),
			'string one'  => array( '1', '1' ),
			'true'        => array( true, '0' ),
			'false text'  => array( 'false', '0' ),
			'float one'   => array( 1.0, '0' ),
			'zero'        => array( 0, '0' ),
		);
	}

	/**
	 * Record strict new flags without changing existing truthy capability behavior.
	 *
	 * @dataProvider flag_values
	 * @param mixed  $value Reported flag.
	 * @param string $stored Expected stored value.
	 */
	public function test_monitor_records_only_explicit_v2_support( $value, $stored ) {
		$this->report['backup_layout_v2']  = $value;
		$this->report['restore_layout_v2'] = $value;
		$this->report['task_spine']        = true;
		$extension                         = 'tar.gz';
		MainWP_Backup_Monitor::negotiate_backup_preflight( $this->website, $extension );
		$this->assertSame( $stored, MainWP_DB::instance()->get_website_option( $this->website, 'backup_layout_v2_support' ) );
		$this->assertSame( $stored, MainWP_DB::instance()->get_website_option( $this->website, 'restore_layout_v2_support' ) );
		$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $this->website, 'task_spine_support' ) );
	}

	/** Missing new flags clear earlier diagnostic support after a downgrade. */
	public function test_old_child_records_missing_v2_flags_as_zero() {
		MainWP_DB::instance()->update_website_option( $this->website, 'backup_layout_v2_support', '1' );
		MainWP_DB::instance()->update_website_option( $this->website, 'restore_layout_v2_support', '1' );
		$this->report = array( 'task_spine' => 1 );
		$extension    = 'tar.gz';
		MainWP_Backup_Monitor::negotiate_backup_preflight( $this->website, $extension );
		$this->assertSame( '0', MainWP_DB::instance()->get_website_option( $this->website, 'backup_layout_v2_support' ) );
		$this->assertSame( '0', MainWP_DB::instance()->get_website_option( $this->website, 'restore_layout_v2_support' ) );
	}

	/** An unknown extra role must not hide behind the required valid roles. */
	public function test_preflight_rejects_an_unknown_additional_role() {
		$this->enable( true );
		$this->report['layout_roles'][] = 'unexpected';
		$result                         = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
	}

	/**
	 * Invalid fresh reports and the required refusal.
	 *
	 * @return array
	 */
	public static function invalid_reports() {
		$unsupported = MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT;
		return array(
			'old child'        => array(
				array(
					'backup_layout_v2'  => null,
					'restore_layout_v2' => null,
				),
				$unsupported,
			),
			'backup only'      => array( array( 'restore_layout_v2' => 0 ), $unsupported ),
			'restore only'     => array( array( 'backup_layout_v2' => 0 ), $unsupported ),
			'truthy backup'    => array( array( 'backup_layout_v2' => 'false' ), $unsupported ),
			'truthy restore'   => array( array( 'restore_layout_v2' => true ), $unsupported ),
			'missing layout'   => array( array( 'layout_id' => null ), $unsupported ),
			'uppercase layout' => array( array( 'layout_id' => str_repeat( 'A', 64 ) ), $unsupported ),
			'changed layout'   => array( array( 'layout_id' => str_repeat( 'b', 64 ) ), MainWP_Backup_Error_Codes::ERR_LAYOUT_CHANGED ),
			'missing role'     => array( array( 'layout_roles' => array( 'site', 'config' ) ), $unsupported ),
			'path as role'     => array( array( 'layout_roles' => array( '/srv/content', 'site', 'config' ) ), $unsupported ),
			'malformed roles'  => array( array( 'layout_roles' => 'content' ), $unsupported ),
			'missing policy'   => array( array( 'core_policy' => null ), $unsupported ),
			'write core'       => array( array( 'core_policy' => 'overwrite' ), $unsupported ),
		);
	}

	/**
	 * Cached flags never authorize a malformed or incompatible fresh response.
	 *
	 * @dataProvider invalid_reports
	 * @param array  $changes Fresh response fields.
	 * @param string $code Refusal code.
	 */
	public function test_v2_requires_complete_fresh_capabilities( $changes, $code ) {
		$this->enable( true );
		MainWP_DB::instance()->update_website_option( $this->website, 'backup_layout_v2_support', '1' );
		MainWP_DB::instance()->update_website_option( $this->website, 'restore_layout_v2_support', '1' );
		$this->report = array_replace( $this->capabilities(), $changes );
		$result       = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertCount( 1, $this->calls );
	}

	/** String flags are accepted, but their loss is checked again on the next call. */
	public function test_every_call_requires_a_new_dual_capability_report() {
		$this->enable( true );
		$this->report['backup_layout_v2']  = '1';
		$this->report['restore_layout_v2'] = '1';
		$this->assertIsArray( MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) ) );
		$this->report['backup_layout_v2'] = 0;
		$result                           = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		$this->assertSame( array( 'backup_preflight', 'backup_preflight' ), array_column( $this->calls, 0 ) );
	}

	/** An explicit pin changes the destination comparison, never the source metadata. */
	public function test_explicit_destination_layout_pin() {
		$this->enable( true );
		$this->report['layout_id'] = str_repeat( 'b', 64 );
		$result                    = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'b', 64 ) );
		$this->assertIsArray( $result );
		$this->assertSame( str_repeat( 'a', 64 ), $result['layout_descriptor']['layout_id'] );
	}

	/** A new job captures the destination binding independently of its source. */
	public function test_new_job_pins_fresh_destination_without_reusing_source_layout() {
		$this->enable( true );
		$this->report['layout_id'] = str_repeat( 'b', 64 );
		$result                    = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), '', true );
		$this->assertIsArray( $result );
		$this->assertSame( str_repeat( 'b', 64 ), $result['expected_layout_id'] );
		$this->assertSame( str_repeat( 'a', 64 ), $result['layout_descriptor']['layout_id'] );
	}

	/** A failed fresh request cannot borrow persisted flags or make a second request. */
	public function test_failed_fresh_probe_is_permanent_without_retry() {
		$this->enable( true );
		MainWP_DB::instance()->update_website_option( $this->website, 'backup_layout_v2_support', '1' );
		MainWP_DB::instance()->update_website_option( $this->website, 'restore_layout_v2_support', '1' );
		$this->report = new \RuntimeException( 'Fixture transport unavailable.' );
		$result       = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		$this->assertCount( 1, $this->calls );
	}

	/** An empty fresh answer is not evidence even after a successful admission. */
	public function test_missing_fresh_response_never_reuses_previous_report() {
		$this->enable( true );
		$this->assertIsArray( MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) ) );
		$this->report = array();
		$result       = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		$this->assertCount( 2, $this->calls );
	}

	/**
	 * Permanent remote refusals.
	 *
	 * @return array
	 */
	public static function permanent_codes() {
		return array(
			'format' => array( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT ),
			'layout' => array( MainWP_Backup_Error_Codes::ERR_LAYOUT_CHANGED ),
		);
	}

	/**
	 * Refusals retain their codes before cached task-spine fallback.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Child error code.
	 */
	public function test_preflight_preserves_permanent_code_with_cached_task_spine( $code ) {
		MainWP_DB::instance()->update_website_option( $this->website, 'task_spine_support', '1' );
		$this->report = array(
			'error'      => 'Fixture refusal.',
			'error_code' => $code,
		);
		$extension    = 'tar.gz';
		try {
			MainWP_Backup_Monitor::negotiate_backup_preflight( $this->website, $extension );
			$this->fail( 'A permanent refusal was ignored.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( $code, $error->get_message_error_code() );
		}
		$this->assertCount( 1, $this->calls );
	}

	/**
	 * Fresh admission preserves permanent remote exceptions and response codes.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Child error code.
	 */
	public function test_admission_preserves_remote_refusals( $code ) {
		$this->enable( true );
		$this->report = array(
			'error'      => 'Fixture refusal.',
			'error_code' => $code,
		);
		$result       = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->report = MainWP_Backup_Error_Codes::exception( $code );
		$result       = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $this->plan(), str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertCount( 2, $this->calls );
	}

	/**
	 * A thrown readiness refusal must not enter the legacy fallback.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Permanent refusal.
	 */
	public function test_thrown_preflight_refusal_keeps_its_code( $code ) {
		$this->report = new MainWP_Exception( 'Fixture refusal.', null, $code );
		MainWP_DB::instance()->update_website_option( $this->website, 'task_spine_support', '1' );
		$extension = 'zip';
		try {
			MainWP_Backup_Monitor::negotiate_backup_preflight( $this->website, $extension );
			$this->fail( 'A permanent exception was swallowed.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( $code, $error->get_message_error_code() );
		}
		$this->assertCount( 1, $this->calls );
	}

	/**
	 * A reconciliation refresh must propagate permanent refusals without fallback.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Permanent refusal.
	 */
	public function test_refresh_propagates_permanent_response_and_exception( $code ) {
		foreach ( array(
			array(
				'error'      => 'Fixture refusal.',
				'error_code' => $code,
			),
			new MainWP_Exception( 'Fixture refusal.', null, $code ),
		) as $report ) {
			$this->calls  = array();
			$this->report = $report;
			try {
				MainWP_Backup_Monitor::refresh_negotiated_capabilities( $this->website );
				$this->fail( 'A permanent refresh refusal was discarded.' );
			} catch ( MainWP_Exception $error ) {
				$this->assertSame( $code, $error->get_message_error_code() );
			}
			$this->assertCount( 1, $this->calls );
		}
	}

	/** Unknown formats fail before even the disabled-switch check can hide them. */
	public function test_unknown_source_format_is_refused_without_requests() {
		$plan   = array(
			array(
				'role'          => 'full',
				'backup_format' => 3,
			),
		);
		$result = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $plan, str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * File roles that cannot mix v1 into v2.
	 *
	 * @return array
	 */
	public static function file_roles() {
		return array( array( 'full' ), array( 'part' ), array( 'tombstones' ), array( 'manifest' ) );
	}

	/**
	 * A mixed chain is corrupt even when every supplied v2 descriptor is valid.
	 *
	 * @dataProvider file_roles
	 * @param string $role Legacy file operation.
	 */
	public function test_v1_file_roles_cannot_join_a_v2_plan( $role ) {
		$plan   = $this->plan();
		$plan[] = array(
			'role' => $role,
			'file' => 'legacy.zip',
		);
		$result = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $plan, str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/** Individually valid descriptors must also agree across the chain. */
	public function test_different_valid_source_descriptors_are_corrupt() {
		$plan                                      = $this->plan();
		$plan[1]['layout_descriptor']['layout_id'] = str_repeat( 'b', 64 );
		$plan[1]['layout_descriptor_sha256']       = hash( 'sha256', wp_json_encode( $plan[1]['layout_descriptor'] ) );
		$result                                    = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $plan, str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/** Each entry's digest is validated, not merely compared with its neighbor. */
	public function test_corrupt_source_digest_is_refused() {
		$plan = $this->plan();
		foreach ( array( 0, 1 ) as $index ) {
			$plan[ $index ]['layout_descriptor_sha256'] = str_repeat( 'c', 64 );
		}
		$result = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $plan, str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/** DB-only selection retains its containing v2 snapshot's admission requirement. */
	public function test_v1_database_bytes_from_v2_source_cannot_bypass_switch() {
		$plan                         = $this->plan();
		$plan[1]['layout_descriptor'] = array_reverse( $plan[1]['layout_descriptor'], true );
		$id                           = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'site_id'  => (int) $this->website->id,
				'archives' => $plan,
				'type'     => 'full',
			)
		);
		$this->assertGreaterThan( 0, $id );
		$db_plan = array(
			array(
				'role'        => 'db',
				'snapshot_id' => $id,
				'file'        => 'backup.sql',
			),
		);
		$before  = wp_json_encode( $db_plan );
		$result  = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $db_plan, str_repeat( 'a', 64 ) );
		$this->assertWPError( $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNSUPPORTED_BACKUP_FORMAT, $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
		$this->enable( true );
		$result = MainWP_Backup_Monitor::admit_restore_plan( $this->website, $db_plan, str_repeat( 'a', 64 ) );
		$this->assertIsArray( $result );
		$this->assertSame( $plan[1]['layout_descriptor'], $result['layout_descriptor'] );
		$this->assertSame( $before, wp_json_encode( $db_plan ) );
		$this->assertCount( 1, $this->calls );
	}

	/**
	 * Scheduled slices must not turn permanent exceptions into another attempt.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Child error code.
	 */
	public function test_scheduled_run_does_not_retry_permanent_exception( $code ) {
		$task  = (object) array(
			'id'                             => 771,
			'userid'                         => $this->website->userid,
			'type'                           => 'full',
			'exclude'                        => '',
			'excludebackup'                  => 0,
			'excludecache'                   => 0,
			'excludenonwp'                   => 0,
			'excludezip'                     => 0,
			'filename'                       => '',
			'archiveFormat'                  => 'zip',
			'loadFilesBeforeZip'             => 1,
			'maximumFileDescriptorsOverride' => 0,
			'last_run'                       => time() - 100,
		);
		$store = MainWP_DB_Backup::instance();
		$store->add_backup_task_progress( $task->id, $this->website->id, array() );
		$store->update_backup_task_progress(
			$task->id,
			$this->website->id,
			array(
				'dtsFetched'           => time(),
				'child_backup_task_id' => 'fixture-task',
				'attempts'             => 0,
			)
		);
		$this->task_response = MainWP_Backup_Error_Codes::exception( $code );
		try {
			MainWP_Backup_Executor::backup_site( $this->website->id, $task, 'backups' );
			$this->fail( 'The permanent exception was swallowed.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( $code, $error->get_message_error_code() );
		}
		$this->assertSame( 0, (int) $store->get_backup_task_progress( $task->id, $this->website->id )->attempts );
		$this->assertSame( array( 'backup_task_run' ), array_column( $this->calls, 0 ) );
	}

	/**
	 * Ad-hoc reconciliation must preserve permanent errors before its attempt cap.
	 *
	 * @dataProvider permanent_codes
	 * @param string $code Child error code.
	 */
	public function test_adhoc_reconciliation_does_not_count_permanent_exception_as_transport_failure( $code ) {
		MainWP_DB::instance()->update_website_option(
			$this->website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id'           => 'fixture-task',
					'type'              => 'full',
					'started'           => time(),
					'reconcile_pending' => array(
						'attempts'       => 0,
						'last_status_at' => microtime( true ) - 60,
					),
				)
			)
		);
		$this->task_response = MainWP_Backup_Error_Codes::exception( $code );
		try {
			MainWP_Backup_Executor::get_backup_task_status( (int) $this->website->id, 'fixture-task', 1 );
			$this->fail( 'The permanent exception was swallowed.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( $code, $error->get_message_error_code() );
		}
		$stored = json_decode( MainWP_DB::instance()->get_website_option( $this->website, 'adhoc_backup_task' ), true );
		$this->assertSame( 0, $stored['reconcile_pending']['attempts'] );
		$this->assertSame( array( 'backup_task_status' ), array_column( $this->calls, 0 ) );
	}
}
