<?php
/**
 * Recorded responses preserve the types and text consumed by the Backups pages.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Backup_Handler;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

/** Class MainWP_Backup_Ajax_Serializer_Parity_Test. */
class MainWP_Backup_Ajax_Serializer_Parity_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;
	use Backup_Read_Fixtures;

	/**
	 * Fixed records make handler responses repeatable.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->seed_read_fixtures();
	}

	/**
	 * Fixture records and request state must not escape the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->clear_read_fixtures();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Each response is recorded from a fixed handler fixture.
	 *
	 * @return array Handler fixtures.
	 */
	public function handlers() {
		return array(
			'task-run'  => array(
				'mainwp_backuptask_run_status',
				array(
					'task_id' => 987671,
					'started' => 1700000000,
				),
			),
			'on-demand' => array(
				'mainwp_native_backup_progress',
				array(
					'site_id' => 987670,
					'since'   => 1700000000,
				),
			),
			'restore'   => array( 'mainwp_restore_progress', array( 'job_id' => 987674 ) ),
			'snapshots' => array( 'mainwp_restore_snapshot_list', array( 'site_id' => 987670 ) ),
			'cancel'    => array( 'mainwp_native_backup_cancel', array( 'site_id' => 987670 ) ),
			'cleanup'   => array(
				'mainwp_snapshots_cleanup',
				array(
					'site_id' => 987670,
					'keep'    => 5,
				),
			),
		);
	}

	/**
	 * Decoded snapshots lock down keys, scalar types, and stored text.
	 *
	 * @dataProvider handlers
	 * @param string $handler Handler name.
	 * @param array  $input Request fields.
	 * @return void
	 */
	public function test_recorded_response( $handler, $input ) {
		$nonce    = wp_create_nonce( $handler );
		$_POST    = array_merge( $input, array( 'security' => $nonce ) );
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'parity-json-end' );
			};
		};
		$child    = static function ( $value, $website, $what ) {
			return 'backup_task_cancel' === $what ? array( 'result' => 'ok' ) : $value;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $child, 10, 3 );
		$budget = static function () {
			return -1;
		};
		add_filter( 'mainwp_restore_foreground_budget', $budget );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->$handler();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'parity-json-end', $e->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'mainwp_fetch_url_authed_pre', $child, 10 );
			remove_filter( 'mainwp_restore_foreground_budget', $budget );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die );
		}
		$actual = json_decode( $raw, true );
		$this->assertIsArray( $actual, $raw );
		$path = __DIR__ . '/fixtures/ajax-' . $handler . '.json';
		$this->assertFileExists( $path );
		$this->assertSame( json_decode( file_get_contents( $path ), true ), $actual );
	}
}
