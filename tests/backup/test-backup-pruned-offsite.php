<?php
/**
 * Removed snapshots cannot retain offsite claims through stored or late upload state.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/** Stored state and public serializers must agree after pruning. */
class MainWP_Backup_Pruned_Offsite_Test extends MainWP_Abilities_Test_Case {

	/**
	 * A verified row before removal.
	 *
	 * @return int Snapshot id.
	 */
	private function snapshot(): int {
		$this->set_current_user_as_admin();
		$site = $this->create_test_site();
		$id   = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'site_id' => $site,
				'type'    => 'full',
			)
		);
		MainWP_Backup_Snapshots::instance()->set_offsite_state( $id, 'verified', time() );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		return $id;
	}

	/**
	 * Both schema-ready and deferred-repair writes clear the column atomically.
	 *
	 * @dataProvider repair_states
	 * @param bool $pending Whether the removal-reason column repair is pending.
	 */
	public function test_prune_clears_offsite( bool $pending ) {
		$id     = $this->snapshot();
		$option = MainWP_Install::BACKUP_SNAPSHOT_REMOVAL_REASON_REPAIR_PENDING;
		$old    = get_site_option( $option );
		update_site_option( $option, $pending ? time() : false );
		$updates = array();
		$observe = static function ( $sql ) use ( &$updates ) {
			if ( str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, "`status` = 'pruned'" ) ) {
				$updates[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$this->assertTrue( MainWP_Backup_Snapshots::instance()->mark_pruned( $id, 'retention' ) );
			$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
			$this->assertSame( 'pruned', $row->status );
			$this->assertSame( '', $row->offsite_state );
			$this->assertSame( 0, (int) $row->verified_at );
			$this->assertCount( 1, $updates );
			$this->assertStringContainsString( "`offsite_state` = '', `verified_at` = 0", $updates[0] );
		} finally {
			remove_filter( 'query', $observe );
			update_site_option( $option, $old );
		}
	}

	/**
	 * No DDL is needed to exercise the deferred-repair branch.
	 *
	 * @return array
	 */
	public static function repair_states(): array {
		return array(
			'ready'   => array( false ),
			'pending' => array( true ),
		);
	}

	/**
	 * Old pruned rows and late upload writes must have the same public result.
	 *
	 * @dataProvider snapshot_states
	 * @param string $state Snapshot lifecycle state.
	 */
	public function test_offsite_read_rules( string $state ) {
		global $wpdb;
		$id    = $this->snapshot();
		$store = MainWP_Backup_Snapshots::instance();
		if ( 'old-pruned' === $state ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'status' => 'pruned' ), array( 'id' => $id ) );
		} elseif ( 'late-upload' === $state ) {
			$store->mark_pruned( $id, 'manual' );
			$store->set_offsite_state( $id, 'verified', time() );
		}
		$row = $store->get_snapshot_by_id( $id );
		$this->assertSame( 'verified', $row->offsite_state );
		$expected = 'complete' === $state ? 'verified' : 'none';
		$detail   = MainWP_Backup_Read_Model::snapshot_details( $id );
		$this->assertIsArray( $detail );
		$this->assertSame( $expected, $detail['snapshot']['offsite']['state'] );
		$this->assertSame( 'complete' === $state ? 'remote_size_match' : 'none', $detail['snapshot']['offsite']['evidence'] );
		$summary = new \ReflectionMethod( MainWP_Backup_Read_Model::class, 'snapshot_status_summary' );
		$summary->setAccessible( true );
		$this->assertSame( $expected, $summary->invoke( null, $row )['offsite']['state'] );
		foreach ( array(
			'get-backup-snapshot'   => array( 'snapshot_id' => $id ),
			'list-backup-snapshots' => array(
				'site_id_or_domain' => (int) $row->site_id,
				'include_pruned'    => true,
			),
		) as $name => $input ) {
			$ability = wp_get_ability( 'mainwp/' . $name . '-v1' );
			$this->assertNotNull( $ability );
			$output = $ability->execute( $input );
			$this->assertIsArray( $output );
			$valid = rest_validate_value_from_schema( $output, $ability->get_output_schema() );
			$this->assertTrue( $valid, is_wp_error( $valid ) ? $valid->get_error_message() : '' );
			$item = 'get-backup-snapshot' === $name ? $output['snapshot'] : $output['items'][0]['snapshot'];
			$this->assertSame( $expected, $item['offsite']['state'] );
			if ( 'complete' === $state ) {
				$this->assertNotEmpty( $item['offsite']['verified_at'] );
			} else {
				$this->assertEmpty( $item['offsite']['verified_at'] );
			}
		}
		$page = $this->page_rows( (int) $row->site_id );
		$this->assertSame( 'complete' === $state ? 'verified' : '', $page['snapshots'][0]['offsite_state'] );
	}

	/**
	 * The complete row is the compatibility control.
	 *
	 * @return array
	 */
	public static function snapshot_states(): array {
		return array(
			'old'      => array( 'old-pruned' ),
			'late'     => array( 'late-upload' ),
			'complete' => array( 'complete' ),
		);
	}

	/**
	 * Capture the real page row adapter after its existing nonce check.
	 *
	 * @param int $site Site id.
	 * @return array
	 */
	private function page_rows( int $site ): array {
		$saved    = array( $_POST, $_REQUEST );
		$_POST    = array(
			'site_id'  => $site,
			'security' => wp_create_nonce( 'mainwp_restore_snapshot_list' ),
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'json' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_restore_snapshot_list();
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'json', $error->getMessage() );
		} finally {
			$raw                      = ob_get_clean();
			list( $_POST, $_REQUEST ) = $saved;
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_die_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return json_decode( $raw, true );
	}
}
