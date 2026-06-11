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
}
