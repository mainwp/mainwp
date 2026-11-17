<?php
/**
 * Bulk deletion uses the single-delete guards on every request.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Snapshots_View;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

require_once __DIR__ . '/trait-snapshot-delete-fixture.php';

/** Replace only the validator's process exit so PHPUnit can observe denial. */
class Bulk_Delete_Request_Handler extends MainWP_Post_Backup_Handler {

	/**
	 * Validate through the real nonce check and make denial catchable.
	 *
	 * @param string $action Action nonce.
	 * @param string $query_arg Request field.
	 * @param bool   $out_die Whether to end the request.
	 * @return bool
	 * @throws \RuntimeException When the nonce is invalid.
	 */
	public function check_security( $action = -1, $query_arg = 'security', $out_die = true ) {
		if ( ! parent::check_security( $action, $query_arg, false ) ) {
			throw new \RuntimeException( 'nonce-denied' );
		}
		return true;
	}
}

/** Stored files and the real AJAX callback expose per-request guard failures. */
class MainWP_Backup_Bulk_Delete_Requests_Test extends MainWP_Abilities_Test_Case {
	use Snapshot_Delete_Fixture;

	/**
	 * Invoke an endpoint while capturing its JSON termination.
	 *
	 * @param int    $id Selected snapshot.
	 * @param bool   $valid_nonce Whether the nonce is valid.
	 * @param string $action Endpoint.
	 * @return array
	 */
	private function request( $id, $valid_nonce = true, $action = 'mainwp_backup_snapshot_delete' ) {
		$_POST    = array(
			'site_id'               => $this->site_id,
			'snapshot_id'           => $id,
			'expected_snapshot_ids' => array( $id ),
			'security'              => $valid_nonce ? wp_create_nonce( $action ) : 'invalid',
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'json-end' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		$handler = ( new \ReflectionClass( Bulk_Delete_Request_Handler::class ) )->newInstanceWithoutConstructor();
		ob_start();
		try {
			$handler->$action();
		} catch ( \RuntimeException $error ) {
			if ( 'nonce-denied' === $error->getMessage() ) {
				return array( 'error' => 'nonce-denied' );
			}
			$this->assertSame( 'json-end', $error->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return json_decode( $raw, true );
	}

	/** Each selected item needs its own valid nonce, including preview. */
	public function test_nonce_is_enforced_per_item() {
		$first  = $this->snapshot();
		$second = $this->snapshot();
		$this->assertSame( 'complete', $this->request( $first )['status'] );
		foreach ( array( 'mainwp_backup_snapshot_delete_preview', 'mainwp_backup_snapshot_delete' ) as $action ) {
			$result = $this->request( $second, false, $action );
			$this->assertArrayHasKey( 'error', $result, 'Each endpoint must reject an invalid nonce before deleting files.' );
			$this->assertSame( 'nonce-denied', $result['error'] );
		}
		$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $second );
		$this->assertSame( 'complete', $row->status );
		$this->assertFileExists( $row->archives[0]['path'] );
	}

	/** Rights are evaluated anew after a successful item. */
	public function test_capability_is_enforced_per_item() {
		$first  = $this->snapshot();
		$second = $this->snapshot();
		$this->assertSame( 'complete', $this->request( $first )['status'] );
		$deny = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'execute_backups' === $right ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			$this->assertArrayHasKey( 'error', $this->request( $second ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}
		$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $second );
		$this->assertSame( 'complete', $row->status );
		$this->assertFileExists( $row->archives[0]['path'] );
	}

	/** Changed chain membership refuses the exact expected set, preserving files. */
	public function test_unselected_descendant_stays_refused_after_another_success() {
		$first  = $this->snapshot();
		$parent = $this->snapshot();
		$child  = $this->snapshot( $parent );
		$this->assertSame( array( $first ), $this->request( $first )['deleted_ids'] );
		$result = $this->request( $parent );
		$this->assertSame( 'changed', $result['status'] );
		$this->assertSame( array(), $result['deleted_ids'] );
		foreach ( array( $parent, $child ) as $id ) {
			$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
			$this->assertSame( 'complete', $row->status );
			$this->assertFileExists( $row->archives[0]['path'] );
		}
	}

	/** A restore admitted between items preserves the next backup and its files. */
	public function test_busy_item_stays_refused_after_another_success() {
		$first  = $this->snapshot();
		$second = $this->snapshot();
		$this->assertSame( array( $first ), $this->request( $first )['deleted_ids'] );
		$store       = MainWP_Backup_Restore_Job_Store::instance();
		$reservation = $store->reserve_site( $this->site_id );
		try {
			$result = $this->request( $second );
			$this->assertSame( 'refused', $result['status'] );
			$this->assertSame( array(), $result['deleted_ids'] );
			$this->assertNotEmpty( $result['errors'] );
		} finally {
			$store->release_reservation( $reservation );
		}
		$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $second );
		$this->assertSame( 'complete', $row->status );
		$this->assertFileExists( $row->archives[0]['path'] );
	}

	/** Independent previews must not hide the combined last-backup update hold. */
	public function test_combined_selection_has_update_gate_warning_before_confirmation() {
		update_option( 'mainwp_backup_before_upgrade', 1 );
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 0 );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'backup_before_upgrade' => 1 ), array( 'id' => $this->site_id ) );
		$first  = $this->snapshot();
		$second = $this->snapshot();
		foreach ( array( $first, $second ) as $id ) {
			$this->assertFalse( $this->preview( $id )['would_affect']['update_gate_warning'], 'Each individual preview still sees the other qualifying backup.' );
		}
		ob_start();
		MainWP_Backup_Snapshots_View::render_restore_surface( $this->site_id, true );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'id="mainwp-backup-bulk-update-warning"', $output );
		$this->assertStringContainsString( 'updates will be held until a new qualifying backup is available', $output );
		update_option( 'mainwp_backup_before_upgrade', 0 );
		ob_start();
		MainWP_Backup_Snapshots_View::render_restore_surface( $this->site_id, true );
		$this->assertStringNotContainsString( 'id="mainwp-backup-bulk-update-warning"', ob_get_clean() );
	}
}
