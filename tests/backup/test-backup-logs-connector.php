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
}
