<?php
/**
 * MainWP Backup Logs Connector Tests
 *
 * Tests for the logs-module connector that records backup artifact
 * downloads fired through the mainwp_backup_downloaded action.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class MainWP_Backup_Logs_Connector_Test
 */
class MainWP_Backup_Logs_Connector_Test extends \WP_UnitTestCase {

	use Backup_Cancel_Transport_Guard;

	/**
	 * Load the connector class files.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once MAINWP_PLUGIN_DIR . 'modules/logs/classes/class-log-connector.php';
		require_once MAINWP_PLUGIN_DIR . 'modules/logs/connectors/class-connector-backup.php';
	}

	/**
	 * Build a connector that captures log() calls instead of writing.
	 *
	 * @param array $captured By-ref capture list.
	 *
	 * @return \MainWP\Dashboard\Module\Log\Connector_Backup Test connector.
	 */
	private function capturing_connector( &$captured ) {
		return new class( $captured ) extends \MainWP\Dashboard\Module\Log\Connector_Backup {
			/**
			 * Capture list.
			 *
			 * @var array
			 */
			private $captured;

			/**
			 * Constructor.
			 *
			 * @param array $captured By-ref capture list.
			 */
			public function __construct( &$captured ) {
				$this->captured = &$captured;
			}

			/**
			 * Capture instead of logging.
			 *
			 * @param string   $message Message.
			 * @param array    $args    Args.
			 * @param int      $site_id Site ID.
			 * @param string   $context Context.
			 * @param string   $action  Action.
			 * @param int|null $state   State.
			 * @param int      $user_id User ID.
			 *
			 * @return bool
			 */
			public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
				$this->captured[] = compact( 'message', 'args', 'site_id', 'context', 'action', 'state', 'user_id' );
				return true;
			}
		};
	}

	/**
	 * The connector observes the download action.
	 *
	 * @return void
	 */
	public function test_connector_observes_downloaded_action() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$this->assertSame( 'backup', $connector->name );
		$this->assertContains( 'mainwp_backup_downloaded', $connector->actions );
		$this->assertNotEmpty( $connector->get_label() );
		$this->assertArrayHasKey( 'downloaded', $connector->get_action_labels() );
		$this->assertArrayHasKey( 'backup', $connector->get_context_labels() );
	}

	/**
	 * A download event becomes a log entry carrying the site, file, size,
	 * and verification state.
	 *
	 * @return void
	 */
	public function test_download_event_is_logged() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_downloaded(
			array(
				'site_id'       => 42,
				'file'          => 'backup-site.tar.gz',
				'size'          => 4096,
				'hash_verified' => true,
			)
		);

		$this->assertCount( 1, $captured );
		$this->assertSame( 42, $captured[0]['site_id'] );
		$this->assertSame( 'backup', $captured[0]['context'] );
		$this->assertSame( 'downloaded', $captured[0]['action'] );
		$this->assertSame( 1, $captured[0]['state'] );
		$this->assertSame( 'backup-site.tar.gz', $captured[0]['args']['file'] );
		$this->assertSame( 4096, $captured[0]['args']['size'] );
		$this->assertSame( 1, $captured[0]['args']['hash_verified'] );
	}

	/**
	 * A malformed event is ignored.
	 *
	 * @return void
	 */
	public function test_malformed_event_is_ignored() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_downloaded( 'not-an-array' );
		$connector->callback_mainwp_backup_downloaded( array() );

		$this->assertCount( 0, $captured );
	}

	/**
	 * The connector observes the upload action.
	 *
	 * @return void
	 */
	public function test_connector_observes_uploaded_action() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$this->assertContains( 'mainwp_backup_uploaded', $connector->actions );
		$this->assertArrayHasKey( 'uploaded', $connector->get_action_labels() );
	}

	/**
	 * An upload event becomes a log entry carrying the site, destination,
	 * and snapshot.
	 *
	 * @return void
	 */
	public function test_upload_event_is_logged() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_uploaded(
			array(
				'site_id'          => 42,
				'snapshot_id'      => 7,
				'destination_id'   => 3,
				'destination_name' => 'Primary S3',
				'provider'         => 's3',
				'objects'          => 12,
				'bytes'            => 4096,
			)
		);

		$this->assertCount( 1, $captured );
		$this->assertSame( 42, $captured[0]['site_id'] );
		$this->assertSame( 'backup', $captured[0]['context'] );
		$this->assertSame( 'uploaded', $captured[0]['action'] );
		$this->assertSame( 'Primary S3', $captured[0]['args']['destination'] );
		$this->assertSame( 7, $captured[0]['args']['snapshot_id'] );
		$this->assertSame( 's3', $captured[0]['args']['provider'] );
	}

	/**
	 * A malformed upload event is ignored.
	 *
	 * @return void
	 */
	public function test_malformed_upload_event_is_ignored() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_uploaded( 'not-an-array' );
		$connector->callback_mainwp_backup_uploaded( array() );

		$this->assertCount( 0, $captured );
	}

	/**
	 * The connector observes the pruned action.
	 *
	 * @return void
	 */
	public function test_connector_observes_pruned_action() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$this->assertContains( 'mainwp_backup_pruned', $connector->actions );
		$this->assertArrayHasKey( 'pruned', $connector->get_action_labels() );
	}

	/**
	 * A prune event becomes a log entry carrying the site, snapshots and
	 * reason.
	 *
	 * @return void
	 */
	public function test_pruned_event_is_logged() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_pruned(
			array(
				'site_id'      => 42,
				'task_id'      => 9,
				'snapshot_ids' => array( 3, 4, 5 ),
				'reason'       => 'count',
				'size_bytes'   => 2048,
			)
		);

		$this->assertCount( 1, $captured );
		$this->assertSame( 42, $captured[0]['site_id'] );
		$this->assertSame( 'backup', $captured[0]['context'] );
		$this->assertSame( 'pruned', $captured[0]['action'] );
		$this->assertSame( 9, $captured[0]['args']['task_id'] );
		$this->assertSame( '3,4,5', $captured[0]['args']['snapshot_ids'] );
		$this->assertSame( 'count', $captured[0]['args']['reason'] );
		$this->assertSame( 2048, $captured[0]['args']['size_bytes'] );
	}

	/**
	 * A malformed prune event is ignored.
	 *
	 * @return void
	 */
	public function test_malformed_pruned_event_is_ignored() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_pruned( 'not-an-array' );
		$connector->callback_mainwp_backup_pruned( array() );

		$this->assertCount( 0, $captured );
	}

	/**
	 * The connector observes the backup-run-failed action.
	 *
	 * @return void
	 */
	public function test_connector_observes_backup_failed_action() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$this->assertContains( 'mainwp_backup_failed', $connector->actions );
		$this->assertArrayHasKey( 'failed', $connector->get_action_labels() );
	}

	/**
	 * A backup run failure becomes a log entry with state 0 and the
	 * error detail carried in extra_info.
	 *
	 * @return void
	 */
	public function test_backup_failed_event_is_logged() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_failed(
			array(
				'site_id'    => 42,
				'task_id'    => 'task-abc',
				'error'      => 'The child site did not respond.',
				'error_code' => 'mainwp_backup_timeout',
			)
		);

		$this->assertCount( 1, $captured );
		$this->assertSame( 42, $captured[0]['site_id'] );
		$this->assertSame( 'backup', $captured[0]['context'] );
		$this->assertSame( 'failed', $captured[0]['action'] );
		$this->assertSame( 0, $captured[0]['state'] );
		$this->assertArrayHasKey( 'extra_info', $captured[0]['args'] );
		$this->assertStringContainsString( 'The child site did not respond.', $captured[0]['args']['extra_info'] );
	}

	/**
	 * A malformed backup-failed event is ignored.
	 *
	 * @return void
	 */
	public function test_malformed_backup_failed_event_is_ignored() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_failed( 'not-an-array' );
		$connector->callback_mainwp_backup_failed( array() );
		$connector->callback_mainwp_backup_failed( array( 'site_id' => 42 ) );

		$this->assertCount( 0, $captured );
	}

	/**
	 * The connector observes the upload-failed action.
	 *
	 * @return void
	 */
	public function test_connector_observes_upload_failed_action() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$this->assertContains( 'mainwp_backup_upload_failed', $connector->actions );
		$this->assertArrayHasKey( 'upload_failed', $connector->get_action_labels() );
	}

	/**
	 * An upload failure becomes a log entry with state 0 and the error
	 * detail carried in extra_info.
	 *
	 * @return void
	 */
	public function test_upload_failed_event_is_logged() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_upload_failed(
			array(
				'site_id'          => 42,
				'task_id'          => 9,
				'snapshot_id'      => 7,
				'destination_id'   => 3,
				'destination_name' => 'Primary S3',
				'provider'         => 's3',
				'error'            => '[mainwp_backup_s3_failed] Access denied.',
			)
		);

		$this->assertCount( 1, $captured );
		$this->assertSame( 42, $captured[0]['site_id'] );
		$this->assertSame( 'backup', $captured[0]['context'] );
		$this->assertSame( 'upload_failed', $captured[0]['action'] );
		$this->assertSame( 0, $captured[0]['state'] );
		$this->assertSame( 'Primary S3', $captured[0]['args']['destination'] );
		$this->assertSame( 7, $captured[0]['args']['snapshot_id'] );
		$this->assertArrayHasKey( 'extra_info', $captured[0]['args'] );
		$this->assertStringContainsString( 'Access denied.', $captured[0]['args']['extra_info'] );
	}

	/**
	 * An upload failure with no site attribution (the snapshot record was
	 * already gone) still logs as a dashboard-level entry with site 0.
	 *
	 * @return void
	 */
	public function test_upload_failed_event_without_site_still_logs() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_upload_failed(
			array(
				'site_id'          => 0,
				'task_id'          => 0,
				'snapshot_id'      => 7,
				'destination_id'   => 3,
				'destination_name' => '',
				'provider'         => 'local',
				'error'            => 'Snapshot record is gone.',
			)
		);

		$this->assertCount( 1, $captured );
		$this->assertSame( 0, $captured[0]['site_id'] );
		$this->assertSame( 'upload_failed', $captured[0]['action'] );
		$this->assertSame( 0, $captured[0]['state'] );
		$this->assertArrayHasKey( 'extra_info', $captured[0]['args'] );
		$this->assertStringContainsString( 'Snapshot record is gone.', $captured[0]['args']['extra_info'] );
	}

	/**
	 * A malformed upload-failed event is ignored.
	 *
	 * @return void
	 */
	public function test_malformed_upload_failed_event_is_ignored() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_upload_failed( 'not-an-array' );
		$connector->callback_mainwp_backup_upload_failed( array() );

		$this->assertCount( 0, $captured );
	}

	/**
	 * The connector observes the restore-failed action.
	 *
	 * @return void
	 */
	public function test_connector_observes_restore_failed_action() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$this->assertContains( 'mainwp_backup_restore_failed', $connector->actions );
		$this->assertArrayHasKey( 'restore_failed', $connector->get_action_labels() );
	}

	/**
	 * A restore failure becomes a log entry with state 0 and the error
	 * detail carried in extra_info.
	 *
	 * @return void
	 */
	public function test_restore_failed_event_is_logged() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_restore_failed(
			array(
				'site_id'     => 42,
				'snapshot_id' => 7,
				'job_id'      => 5,
				'error'       => 'The site did not respond after the restore.',
				'error_code'  => 'mainwp_backup_child_unreachable',
			)
		);

		$this->assertCount( 1, $captured );
		$this->assertSame( 42, $captured[0]['site_id'] );
		$this->assertSame( 'backup', $captured[0]['context'] );
		$this->assertSame( 'restore_failed', $captured[0]['action'] );
		$this->assertSame( 0, $captured[0]['state'] );
		$this->assertSame( 7, $captured[0]['args']['snapshot_id'] );
		$this->assertSame( 5, $captured[0]['args']['job_id'] );
		$this->assertArrayHasKey( 'extra_info', $captured[0]['args'] );
		$this->assertStringContainsString( 'The site did not respond after the restore.', $captured[0]['args']['extra_info'] );
	}

	/**
	 * A malformed restore-failed event is ignored.
	 *
	 * @return void
	 */
	public function test_malformed_restore_failed_event_is_ignored() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );

		$connector->callback_mainwp_backup_restore_failed( 'not-an-array' );
		$connector->callback_mainwp_backup_restore_failed( array() );

		$this->assertCount( 0, $captured );
	}
	/**
	 * Failure metadata removes paths and SQL before storage.
	 *
	 * @return void
	 */
	public function test_failure_extra_info_sanitizes_diagnostics() {
		$captured  = array();
		$connector = $this->capturing_connector( $captured );
		$event     = array(
			'site_id'        => 42,
			'destination_id' => 7,
			'error'          => ABSPATH . 'private/file.sql: SELECT password FROM private_users WHERE id = 1;',
		);
		$connector->callback_mainwp_backup_failed( $event );
		$connector->callback_mainwp_backup_upload_failed( $event );
		$connector->callback_mainwp_backup_restore_failed( $event );
		$this->assertCount( 3, $captured );
		foreach ( $captured as $entry ) {
			$info = json_decode( $entry['args']['extra_info'], true );
			$this->assertSame( 'private/file.sql: [SQL removed];', $info['error'], $entry['action'] );
		}
		$this->assertSame( 'private/file.sql: [SQL removed];', $captured[0]['args']['error'] );
	}
}
