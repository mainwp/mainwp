<?php
/**
 * Update dispatch must respect the site's backup evidence and explicit overrides.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Cron;
use MainWP\Dashboard\MainWP_Backup_Run_History;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;

/** Gate behavior must agree across previews, immediate updates and queued jobs. */
class MainWP_Updates_Backup_Gate_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Child requests observed during this test.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Child transport replacement.
	 *
	 * @var callable
	 */
	private $transport;

	/** Each case starts with the gate enabled and no qualifying backup. */
	public function set_up() {
		parent::set_up();
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_backup_before_upgrade', 1 );
		update_option( 'mainwp_backup_before_upgrade_days', 7 );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 0 );
		$this->calls     = array();
		$this->transport = function ( $result, $site, $what, $params ) {
			$this->calls[] = (int) $site->id;
			return 'upgrade' === $what ? array( 'upgrade' => 'SUCCESS' ) : array( 'upgrades' => array_fill_keys( explode( ',', $params['list'] ?? '' ), true ) );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $this->transport, 10, 4 );
	}

	/** Filters must not influence later update tests in the same process. */
	public function tear_down() {
		remove_filter( 'mainwp_fetch_url_authed_pre', $this->transport, 10 );
		foreach ( array( 'mainwp_enableNativeBackups', 'mainwp_backup_before_upgrade', 'mainwp_backup_before_upgrade_days', 'mainwp_backup_before_upgrade_require_offsite' ) as $option ) {
			delete_option( $option );
		}
		parent::tear_down();
	}

	/**
	 * Dispatch abilities and their single-site type, if any.
	 *
	 * @return array
	 */
	public static function abilities(): array {
		return array(
			'run'          => array( 'run-updates', '' ),
			'all'          => array( 'update-all', '' ),
			'core'         => array( 'update-site-core', 'core' ),
			'plugins'      => array( 'update-site-plugins', 'plugins' ),
			'themes'       => array( 'update-site-themes', 'themes' ),
			'translations' => array( 'update-site-translations', 'translations' ),
		);
	}

	/**
	 * Every ability exercises each evidence and applicability case.
	 *
	 * @return array
	 */
	public static function preview_cases(): array {
		$fixtures = array();
		foreach ( self::abilities() as $label => $ability ) {
			foreach ( array( 'missing', 'pending_offsite', 'pass', 'stale', 'gate_off', 'native_off', 'site_off', 'override' ) as $state ) {
				$fixtures[ $label . '-' . $state ] = array_merge( $ability, array( $state ) );
			}
		}
		return $fixtures;
	}

	/**
	 * Pending updates of every type exercise one decision per site.
	 *
	 * @param string $type Single-site update type.
	 * @param int    $flag Per-site gate applicability.
	 * @return int Site with pending updates of every supported type.
	 */
	private function site( string $type = '', int $flag = 1 ): int {
		$id = $this->create_test_site( array( 'backup_before_upgrade' => $flag ) );
		$this->set_site_option(
			$id,
			'wp_upgrades',
			wp_json_encode(
				array(
					'current' => '6.8.3',
					'new'     => '6.9',
				)
			)
		);
		$this->set_site_plugin_upgrades(
			$id,
			array(
				'sample/plugin.php' => array(
					'Name'    => 'Sample Plugin',
					'Version' => '1.0',
					'update'  => array( 'new_version' => '2.0' ),
				),
			)
		);
		$this->set_site_theme_upgrades(
			$id,
			array(
				'sample-theme' => array(
					'Name'    => 'Sample Theme',
					'Version' => '1.0',
					'update'  => array( 'new_version' => '1.1' ),
				),
			)
		);
		$this->set_site_translation_upgrades(
			$id,
			array(
				array(
					'slug'        => 'sample-translation',
					'version'     => '1.0',
					'new_version' => '2.0',
				),
			)
		);
		return $id;
	}

	/**
	 * Recorded snapshots exercise the real evidence evaluator.
	 *
	 * @param int    $site_id Site whose backup evidence is seeded.
	 * @param string $state Evidence fixture.
	 */
	private function snapshot( int $site_id, string $state ): void {
		$id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => $site_id,
				'type'          => 'full',
				'archives'      => array(),
				'manifest_path' => '',
			)
		);
		$this->assertGreaterThan( 0, $id );
		if ( 'stale' === $state ) {
			global $wpdb;
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'created' => time() - 8 * DAY_IN_SECONDS ), array( 'id' => $id ) );
		}
		if ( 'pending_offsite' === $state ) {
			update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );
		}
	}

	/**
	 * Keep existing batch and single-site target shapes in every case.
	 *
	 * @param string $type Single-site type, empty for a batch ability.
	 * @param int    $id Resolved site.
	 * @return array Existing target input shape.
	 */
	private function input( string $type, int $id ): array {
		return '' === $type ? array( 'site_ids_or_domains' => array( $id ) ) : array( 'site_id_or_domain' => $id );
	}

	/**
	 * Required branch keys must keep response shapes exclusive.
	 *
	 * @param string $name Ability slug.
	 * @param array  $result Returned response.
	 */
	private function assert_output( string $name, array $result ): void {
		$schema = wp_get_ability( 'mainwp/' . $name . '-v1' )->get_output_schema();
		$this->assertTrue( rest_validate_value_from_schema( $result, $schema ) );
		$matches = 0;
		foreach ( $schema['oneOf'] as $branch ) {
			$matches += true === rest_validate_value_from_schema( $result, $branch ) ? 1 : 0;
		}
		$this->assertSame( 1, $matches, 'Each response must validate against exactly one branch.' );
	}

	/**
	 * Preview must report evidence without creating update or history side effects.
	 *
	 * @dataProvider preview_cases
	 * @param string $name Ability slug.
	 * @param string $type Single-site type.
	 * @param string $state Evidence fixture.
	 */
	public function test_preview_reports_gate_without_dispatch_or_history( $name, $type, $state ) {
		$id = $this->site( $type, 'site_off' === $state ? 0 : 1 );
		if ( in_array( $state, array( 'pass', 'pending_offsite', 'stale' ), true ) ) {
			$this->snapshot( $id, $state );
		}
		if ( 'gate_off' === $state || 'native_off' === $state ) {
			update_option( 'gate_off' === $state ? 'mainwp_backup_before_upgrade' : 'mainwp_enableNativeBackups', 0 );
		}
		$override = 'override' === $state;
		$blocked  = in_array( $state, array( 'missing', 'pending_offsite', 'stale' ), true );
		$verdict  = 'override' === $state || 'stale' === $state ? 'missing' : ( str_ends_with( $state, '_off' ) ? 'not_applicable' : $state );
		$before   = MainWP_Backup_Run_History::query( array(), 1, 100, array( $id ) );
		$result   = $this->execute_ability(
			'mainwp/' . $name . '-v1',
			$this->input( $type, $id ) + array(
				'dry_run'            => true,
				'ignore_backup_gate' => $override,
			)
		);
		$this->assertNotWPError( $result );
		$this->assertSame( array(), $this->calls );
		$this->assertSame( $before, MainWP_Backup_Run_History::query( array(), 1, 100, array( $id ) ) );
		$entry = $result['would_affect']['sites'][0];
		$this->assertSame(
			array(
				'applies'    => 'not_applicable' !== $verdict,
				'verdict'    => $verdict,
				'overridden' => $override,
			),
			$entry['backup_gate']
		);
		$this->assertCount( $blocked ? 0 : ( $type ? 1 : 4 ), $entry['items'] );
		$this->assertCount( $blocked ? ( $type ? 1 : 4 ) : 0, $entry['skipped'] );
		if ( $blocked ) {
			$this->assertSame( array( 'pending_offsite' === $state ? 'backup_pending_offsite' : 'backup_missing' ), array_values( array_unique( array_column( $entry['skipped'], 'reason' ) ) ) );
			$this->assertStringContainsString( 'ignore_backup_gate: true', implode( ' ', $result['warnings'] ) );
			$this->assertStringStartsWith( '1 site has no backup', $result['warnings'][0] );
		}
		$this->assertSame( $blocked ? 1 : 0, $result['would_affect']['summary']['backup_gate_blocked_sites'] );
		$this->assertSame( $override ? 1 : 0, $result['would_affect']['summary']['backup_gate_overridden_sites'] );
		$this->assert_output( $name, $result );
	}

	/**
	 * Every ability exercises both refusals and overrides.
	 *
	 * @return array
	 */
	public static function execution_cases(): array {
		$fixtures = array();
		foreach ( self::abilities() as $label => $ability ) {
			foreach ( array( 'missing', 'pending_offsite' ) as $state ) {
				foreach ( array( false, true ) as $override ) {
					$fixtures[ $label . '-' . $state . '-' . (int) $override ] = array_merge( $ability, array( $state, $override ) );
				}
			}
		}
		return $fixtures;
	}

	/**
	 * An admitted override must keep one durable record for each site.
	 *
	 * @dataProvider execution_cases
	 * @param string $name Ability slug.
	 * @param string $type Single-site type.
	 * @param string $state Evidence fixture.
	 * @param bool   $override Explicit override.
	 */
	public function test_confirm_refuses_or_dispatches_and_records_one_override( $name, $type, $state, $override ) {
		$id = $this->site( $type );
		if ( 'pending_offsite' === $state ) {
			$this->snapshot( $id, $state );
		}
		$result = $this->execute_ability(
			'mainwp/' . $name . '-v1',
			$this->input( $type, $id ) + array(
				'confirm'            => true,
				'ignore_backup_gate' => $override,
			)
		);
		if ( $type && ! $override ) {
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_backup_gate_blocked', $result->get_error_code() );
			$this->assertSame(
				array(
					'status'  => 409,
					'site_id' => $id,
					'verdict' => $state,
				),
				$result->get_error_data()
			);
		} else {
			$this->assertNotWPError( $result );
			$this->assert_output( $name, $result );
			$gate = $type ? $result['backup_gate'] : $result['backup_gate'][0];
			$this->assertSame( $override, $gate['overridden'] );
			if ( ! $override ) {
				$this->assertSame( array( 'pending_offsite' === $state ? 'backup_pending_offsite' : 'backup_missing' ), array_values( array_unique( array_column( $result['skipped'], 'reason' ) ) ) );
			}
		}
		$this->assertCount( $override ? ( $type ? 1 : 4 ) : 0, $this->calls );
		$history = MainWP_Backup_Run_History::query( array(), 1, 100, array( $id ) );
		$this->assertSame( $override ? 1 : 0, $history['total'] );
		if ( $override ) {
			$row = $history['items'][0];
			$this->assertSame( 'gate_overridden', $row['outcome'] );
			$this->assertSame( 'ability', $row['trigger_source'] );
			$this->assertSame( get_current_user_id(), (int) $row['initiator_user_id'] );
			$this->assertSame( 0, (int) $row['task_id'] );
			$this->assertSame( $row['started'], $row['finished'] );
			$this->assertSame( '', $row['error_code'] );
			$this->assertSame( 'pending_offsite' === $state ? 'backup_pending_offsite' : 'backup_missing', $row['message'] );
			$this->assertNull( $row['ref'] );
		}
	}

	/**
	 * The backup override must preserve existing hold precedence.
	 *
	 * @dataProvider abilities
	 * @param string $name Ability slug.
	 * @param string $type Single-site type.
	 */
	public function test_hold_reason_wins_and_override_does_not_remove_holds( $name, $type ) {
		$id = $this->site( $type );
		$this->set_site_ignored_plugins( $id, array( 'sample/plugin.php' => 'Held' ) );
		$this->set_site_ignored_themes( $id, array( 'sample-theme' => 'Held' ) );
		$this->set_site_option( $id, 'ignored_wp_upgrades', wp_json_encode( array( 'ignored_versions' => array( 'all_versions' ) ) ) );
		foreach ( array( false, true ) as $override ) {
			$result = $this->execute_ability(
				'mainwp/' . $name . '-v1',
				$this->input( $type, $id ) + array(
					'dry_run'            => true,
					'ignore_backup_gate' => $override,
				)
			);
			$this->assertNotWPError( $result );
			foreach ( $result['would_affect']['sites'][0]['skipped'] as $item ) {
				$this->assertSame( 'translation' === $item['type'] ? 'backup_missing' : 'ignored_site', $item['reason'] );
			}
			if ( $type && 'translations' !== $type ) {
				$this->assertSame( 0, $result['would_affect']['summary']['backup_gate_blocked_sites'] );
				$this->assertSame( array(), $result['warnings'] );
			}
		}
		if ( ! $type ) {
			return;
		}
		$result = $this->execute_ability( 'mainwp/' . $name . '-v1', $this->input( $type, $id ) + array( 'confirm' => true ) );
		if ( 'core' === $type ) {
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_no_updates', $result->get_error_code() );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		} elseif ( 'translations' === $type ) {
			// Translations have no hold layer, so this fixture still needs the backup refusal.
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_backup_gate_blocked', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
		} else {
			$this->assertNotWPError( $result );
			$this->assert_output( $name, $result );
			$this->assertSame( array(), $result['updated'] );
			$this->assertSame( array(), $result['errors'] );
			$this->assertSame( array( 'ignored_site' ), array_column( $result['skipped'], 'reason' ) );
			$this->assertSame( 1, $result['summary']['total_skipped'] );
			$this->assertSame( 0, $result['summary']['total_updated'] );
		}
		$this->assertSame( array(), $this->calls );
		$this->assertSame( 0, MainWP_Backup_Run_History::query( array(), 1, 100, array( $id ) )['total'] );
	}

	/**
	 * Empty selections must preserve the answer regardless of the blocking verdict.
	 *
	 * @return array
	 */
	public static function nothing_pending_cases(): array {
		$fixtures = array();
		foreach ( self::abilities() as $label => $ability ) {
			if ( ! $ability[1] ) {
				continue;
			}
			foreach ( array( 'missing', 'pending_offsite' ) as $state ) {
				$fixtures[ $label . '-' . $state . '-empty' ] = array_merge( $ability, array( $state, false ) );
				if ( 'core' !== $ability[1] ) {
					$fixtures[ $label . '-' . $state . '-unmatched-slug' ] = array_merge( $ability, array( $state, true ) );
				}
			}
		}
		return $fixtures;
	}

	/**
	 * A backup refusal must not replace an existing empty-selection result.
	 *
	 * @dataProvider nothing_pending_cases
	 * @param string $name Ability slug.
	 * @param string $type Single-site type.
	 * @param string $state Blocking evidence fixture.
	 * @param bool   $unmatched_slug Whether other slugs remain pending.
	 */
	public function test_gate_blocked_site_with_nothing_pending_keeps_existing_answer( $name, $type, $state, $unmatched_slug ) {
		$id    = $this->site( $type );
		$input = $this->input( $type, $id );
		if ( 'pending_offsite' === $state ) {
			$this->snapshot( $id, $state );
		}
		if ( $unmatched_slug ) {
			$input['slugs'] = array( 'not-pending' );
		} elseif ( 'core' === $type ) {
			$this->set_site_option( $id, 'wp_upgrades', wp_json_encode( array() ) );
		} elseif ( 'plugins' === $type ) {
			$this->set_site_plugin_upgrades( $id, array() );
		} elseif ( 'themes' === $type ) {
			$this->set_site_theme_upgrades( $id, array() );
		} else {
			$this->set_site_translation_upgrades( $id, array() );
		}
		$preview = $this->execute_ability( 'mainwp/' . $name . '-v1', $input + array( 'dry_run' => true ) );
		$this->assertNotWPError( $preview );
		$this->assert_output( $name, $preview );
		$this->assertSame( 0, $preview['would_affect']['summary']['item_count'] );
		$this->assertSame( 0, $preview['would_affect']['summary']['backup_gate_blocked_sites'] );
		$result = $this->execute_ability( 'mainwp/' . $name . '-v1', $input + array( 'confirm' => true ) );
		if ( 'core' === $type ) {
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_no_updates', $result->get_error_code() );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		} else {
			$this->assertNotWPError( $result );
			$this->assert_output( $name, $result );
			$this->assertSame( array(), $result['updated'] );
			$this->assertSame( array(), $result['errors'] );
			$this->assertSame( array(), $result['skipped'] );
			$this->assertSame( $state, $result['backup_gate']['verdict'] );
			$this->assertTrue( $result['backup_gate']['applies'] );
			$this->assertFalse( $result['backup_gate']['overridden'] );
		}
		$this->assertSame( array(), $this->calls );
		$this->assertSame( 0, MainWP_Backup_Run_History::query( array(), 1, 100, array( $id ) )['total'] );
	}

	/**
	 * Applicability and passing evidence also permit confirmation.
	 *
	 * @return array
	 */
	public static function unblocked_cases(): array {
		return array_filter(
			self::preview_cases(),
			static function ( $fixture ) {
				return in_array( $fixture[2], array( 'pass', 'gate_off', 'native_off', 'site_off' ), true );
			}
		);
	}

	/**
	 * Earlier site refusals must remain visible when the backup gate also fails.
	 *
	 * @return array
	 */
	public static function prerequisite_cases(): array {
		$fixtures = array();
		foreach ( self::abilities() as $label => $ability ) {
			foreach ( array( 'offline', 'old_child' ) as $state ) {
				$fixtures[ $label . '-' . $state ] = array_merge( $ability, array( $state ) );
			}
		}
		return $fixtures;
	}

	/**
	 * A failed backup check must not hide an existing site error.
	 *
	 * @dataProvider prerequisite_cases
	 * @param string $name Ability slug.
	 * @param string $type Single-site type.
	 * @param string $state Existing refusal.
	 */
	public function test_gate_preserves_offline_and_child_version_errors( $name, $type, $state ) {
		global $wpdb;
		$id = $this->site( $type );
		if ( 'offline' === $state ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'offline_check_result' => -1 ), array( 'id' => $id ) );
		} else {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'version' => '3.0' ), array( 'wpid' => $id ) );
		}
		$result = $this->execute_ability( 'mainwp/' . $name . '-v1', $this->input( $type, $id ) + array( 'confirm' => true ) );
		$code   = 'offline' === $state ? 'mainwp_site_offline' : 'mainwp_child_outdated';
		if ( $type ) {
			$this->assertWPError( $result );
			$this->assertSame( $code, $result->get_error_code() );
		} else {
			$this->assertNotWPError( $result );
			$this->assertNotEmpty( $result['errors'] );
			$this->assertSame( array( $code ), array_values( array_unique( array_column( $result['errors'], 'code' ) ) ) );
		}
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * An unnecessary override must leave backup history unchanged.
	 *
	 * @dataProvider unblocked_cases
	 * @param string $name Ability slug.
	 * @param string $type Single-site type.
	 * @param string $state Evidence fixture.
	 */
	public function test_confirm_dispatches_unblocked_sites_without_override_history( $name, $type, $state ) {
		$id = $this->site( $type, 'site_off' === $state ? 0 : 1 );
		if ( 'pass' === $state ) {
			$this->snapshot( $id, $state );
		} elseif ( 'gate_off' === $state || 'native_off' === $state ) {
			update_option( 'gate_off' === $state ? 'mainwp_backup_before_upgrade' : 'mainwp_enableNativeBackups', 0 );
		}
		$result = $this->execute_ability(
			'mainwp/' . $name . '-v1',
			$this->input( $type, $id ) + array(
				'confirm'            => true,
				'ignore_backup_gate' => true,
			)
		);
		$this->assertNotWPError( $result );
		$this->assert_output( $name, $result );
		$gate = $type ? $result['backup_gate'] : $result['backup_gate'][0];
		$this->assertSame( 'pass' === $state ? 'pass' : 'not_applicable', $gate['verdict'] );
		$this->assertFalse( $gate['overridden'] );
		$this->assertCount( $type ? 1 : 4, $this->calls );
		$this->assertSame( array(), $result['skipped'] );
		$this->assertSame( 0, MainWP_Backup_Run_History::query( array(), 1, 100, array( $id ) )['total'] );
	}

	/**
	 * Only the multi-site abilities queue.
	 *
	 * @return array
	 */
	public static function queued_cases(): array {
		$fixtures = array();
		foreach ( array( 'run-updates', 'update-all' ) as $name ) {
			foreach ( array( 'missing', 'pending_offsite' ) as $state ) {
				foreach ( array( false, true ) as $override ) {
					$fixtures[ $name . '-' . $state . '-' . (int) $override ] = array( $name, $state, $override );
				}
			}
		}
		return $fixtures;
	}

	/**
	 * Queued dispatch must restore the owner and use current gate evidence.
	 *
	 * @dataProvider queued_cases
	 * @param string $name Ability slug.
	 * @param string $state Dispatch-time evidence fixture.
	 * @param bool   $override Explicit override.
	 */
	public function test_queued_gate_rechecks_evidence_and_reports_skips( $name, $state, $override ) {
		$ids       = array( $this->site(), $this->site() );
		$threshold = static function () {
			return 1;
		};
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$result = $this->execute_ability(
				'mainwp/' . $name . '-v1',
				array(
					'site_ids_or_domains' => $ids,
					'confirm'             => true,
					'ignore_backup_gate'  => $override,
				)
			);
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
		$this->assertNotWPError( $result );
		$this->assert_output( $name, $result );
		$this->track_update_job( $result['job_id'] );
		$job = get_transient( 'mainwp_update_job_' . $result['job_id'] );
		$this->assertSame( $override, $job['ignore_backup_gate'] );
		$this->assertSame( array(), $this->calls );
		if ( 'pending_offsite' === $state ) {
			foreach ( $ids as $id ) {
				$this->snapshot( $id, $state );
			}
		}
		$owner = get_current_user_id();
		wp_set_current_user( 0 );
		MainWP_Abilities_Cron::instance()->process_update_job( $result['job_id'] );
		$this->assertSame( 0, get_current_user_id() );
		wp_set_current_user( $owner );
		$status = $this->execute_ability( 'mainwp/get-batch-job-status-v1', array( 'job_id' => $result['job_id'] ) );
		$this->assertNotWPError( $status );
		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 2, $status['processed'] );
		$this->assertSame( $override ? 0 : 8, $status['skipped']['count'] );
		$this->assertSame( $override ? 2 : 0, $status['succeeded'] );
		$this->assertSame( $override ? 0 : 2, $status['unchanged'] );
		$this->assertCount( $override ? 8 : 0, $this->calls );
		$this->assertTrue( rest_validate_value_from_schema( $status, wp_get_ability( 'mainwp/get-batch-job-status-v1' )->get_output_schema() ) );
		$this->assertSame( array( $override, $override ), array_column( $status['backup_gate'], 'overridden' ) );
		$this->assertSame( array( $state, $state ), array_column( $status['backup_gate'], 'verdict' ) );
		if ( ! $override ) {
			$this->assertSame( array( 'pending_offsite' === $state ? 'backup_pending_offsite' : 'backup_missing' ), array_values( array_unique( array_column( $status['skipped']['items'], 'reason' ) ) ) );
		}
		foreach ( $ids as $id ) {
			$history = MainWP_Backup_Run_History::query( array(), 1, 100, array( $id ) );
			$this->assertSame( $override ? 1 : 0, $history['total'] );
			if ( $override ) {
				$this->assertSame( $result['job_id'], $history['items'][0]['ref'] );
				$this->assertSame( $owner, (int) $history['items'][0]['initiator_user_id'] );
			}
		}
		MainWP_Abilities_Cron::instance()->process_update_job( $result['job_id'] );
		$this->assertCount( $override ? 8 : 0, $this->calls, 'A completed job must not redispatch a site.' );
	}

	/** Old jobs have no override flag and must use current evidence and holds. */
	public function test_legacy_queued_job_rechecks_each_site_at_dispatch() {
		$blocked = $this->site();
		$passing = $this->site();
		$held    = $this->site();
		$this->snapshot( $passing, 'pass' );
		$threshold = static function () {
			return 1;
		};
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		try {
			$result = $this->execute_ability(
				'mainwp/run-updates-v1',
				array(
					'site_ids_or_domains' => array( $blocked, $passing, $held ),
					'types'               => array( 'plugins' ),
					'confirm'             => true,
				)
			);
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
		}
		$this->assertNotWPError( $result );
		$this->track_update_job( $result['job_id'] );
		$job = get_transient( 'mainwp_update_job_' . $result['job_id'] );
		unset( $job['ignore_backup_gate'] );
		set_transient( 'mainwp_update_job_' . $result['job_id'], $job, DAY_IN_SECONDS );
		$this->set_site_ignored_plugins( $held, array( 'sample/plugin.php' => 'Held' ) );
		MainWP_Abilities_Cron::instance()->process_update_job( $result['job_id'] );
		$this->assertSame( array( $passing ), $this->calls );
		$job = get_transient( 'mainwp_update_job_' . $result['job_id'] );
		$this->assertSame( array( 'backup_missing', 'ignored_site' ), array_column( $job['skipped'], 'reason' ) );
		$this->assertSame( array( $blocked, $held ), $job['unchanged'] );
	}

	/**
	 * A missing history table must not interrupt an admitted update.
	 *
	 * @dataProvider abilities
	 * @param string $name Ability slug.
	 * @param string $type Single-site type.
	 */
	public function test_history_insert_failure_does_not_prevent_override_dispatch( $name, $type ) {
		$id     = $this->site( $type );
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		$refuse = static function ( $sql ) use ( $table ) {
			return str_starts_with( $sql, 'INSERT INTO `' . $table . '`' ) ? 'INSERT INTO mainwp_missing_history_table (site_id) VALUES (1)' : $sql;
		};
		add_filter( 'query', $refuse );
		try {
			$result = $this->execute_ability(
				'mainwp/' . $name . '-v1',
				$this->input( $type, $id ) + array(
					'confirm'            => true,
					'ignore_backup_gate' => true,
				)
			);
		} finally {
			remove_filter( 'query', $refuse );
		}
		$this->assertNotWPError( $result );
		$this->assertCount( $type ? 1 : 4, $this->calls );
		$this->assertSame( 0, MainWP_Backup_Run_History::query( array(), 1, 100, array( $id ) )['total'] );
	}
}
