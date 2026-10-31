<?php
/**
 * Manual updates must enforce the same backup policy as their preflight.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Backup_Gate_Post_Handler;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Updates_Overview;

/**
 * Class MainWP_Backup_Manual_Update_Gate_Test
 */
class MainWP_Backup_Manual_Update_Gate_Test extends MainWP_Abilities_Test_Case {

	/**
	 * $_POST, $_REQUEST and the current user saved before each test.
	 *
	 * @var array
	 */
	private $request_state;

	/**
	 * Child requests the handler tried to send, as [ site id, action ].
	 *
	 * @var array
	 */
	private $child_calls;

	/**
	 * Load a copy of the handler whose exits throw, so it can run in this process.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// Legacy exits must be intercepted so the real handler can run in this process.
		$code = '';
		foreach ( token_get_all( file_get_contents( dirname( __DIR__, 2 ) . '/class/class-mainwp-post-plugin-theme-handler.php' ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the handler without modifying production termination.
			if ( is_array( $token ) ) {
				$code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && 'MainWP_Post_Plugin_Theme_Handler' === $token[1] ? 'Backup_Gate_Post_Handler' : $token[1] );
			} else {
				$code .= $token;
			}
		}
		$code = str_replace( '\\wp_die;', '\\wp_die();', $code );
		eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Replace only exits and the class name; retain real nonce checks and update code.
	}

	/**
	 * Turn on the backup gate, log in an administrator and stub Child requests.
	 */
	public function set_up() {
		parent::set_up();
		$this->request_state = array( $_POST, $_REQUEST, get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve fixture globals.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_backup_before_upgrade', 1 );
		$this->child_calls = array();
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'stop_child_request' ), 10, 3 );
	}

	/**
	 * Restore request globals, the user and the gate options.
	 */
	public function tear_down() {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'stop_child_request' ) );
		list( $_POST, $_REQUEST, $user_id ) = $this->request_state;
		wp_set_current_user( $user_id );
		foreach ( array( 'mainwp_enableNativeBackups', 'mainwp_backup_before_upgrade', 'mainwp_backup_before_upgrade_days', 'mainwp_backup_before_upgrade_require_offsite', 'mainwp_primaryBackup' ) as $option ) {
			delete_option( $option );
		}
		parent::tear_down();
	}

	/**
	 * Make wp_die() in AJAX context throw its message.
	 *
	 * @return callable
	 */
	public function ajax_die_handler(): callable {
		return static function ( $message ) {
			throw new \WPDieException( (string) $message );
		};
	}

	/**
	 * Record the Child request and stop before it is sent.
	 *
	 * @param mixed  $pre    Short-circuit value.
	 * @param object $site   Site the request targets.
	 * @param string $action Child action.
	 *
	 * @throws \Error Always, to stop the handler at the Child request.
	 */
	public function stop_child_request( $pre, $site, $action ) {
		$this->child_calls[] = array( (int) $site->id, $action );
		throw new \Error( 'backup-gate-child-request' );
	}

	/**
	 * Every update type crossed with every gate scenario.
	 *
	 * @return array
	 */
	public static function manual_update_cases(): array {
		$cases = array();
		foreach ( array( 'core', 'plugin', 'theme', 'translation' ) as $type ) {
			foreach ( array( 'missing', 'stale', 'pending_offsite', 'ignore', 'invalid_ignore', 'setting_off', 'native_off', 'site_off', 'fresh', 'fresh_ignore', 'third_party', 'third_party_stale', 'third_party_stale_ignore', 'global_third_party', 'empty_global_method' ) as $scenario ) {
				$cases[ "$type $scenario" ] = array( $type, $scenario );
			}
		}
		return $cases;
	}

	/**
	 * Each manual update handler refuses or allows the update the way the preflight does.
	 *
	 * @dataProvider manual_update_cases
	 *
	 * @param string $type     Update type.
	 * @param string $scenario Gate scenario.
	 */
	public function test_manual_update_gate( $type, $scenario ) {
		global $wpdb;
		$site_id = $this->create_test_site(
			array(
				'userid'                => get_current_user_id(),
				'backup_before_upgrade' => 'site_off' === $scenario ? 0 : 1,
				'primary_backup_method' => 0 === strpos( $scenario, 'third_party' ) ? 'backupbuddy' : ( 'global_third_party' === $scenario ? 'global' : '' ),
			)
		);
		if ( in_array( $scenario, array( 'fresh', 'fresh_ignore', 'stale', 'pending_offsite' ), true ) ) {
			$snapshot = MainWP_Backup_Snapshots::instance()->record_snapshot(
				array(
					'task_id'       => 1,
					'site_id'       => $site_id,
					'type'          => 'full',
					'archives'      => array(),
					'manifest_path' => '',
				)
			);
			if ( 'stale' === $scenario ) {
				$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'created' => time() - 10 * DAY_IN_SECONDS ), array( 'id' => $snapshot ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Age fixture evidence without changing the snapshot writer.
			}
		}
		if ( 'pending_offsite' === $scenario ) {
			update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );
		}
		if ( 'setting_off' === $scenario ) {
			update_option( 'mainwp_backup_before_upgrade', 0 );
		}
		if ( in_array( $scenario, array( 'native_off', 'global_third_party' ), true ) ) {
			update_option( 'mainwp_enableNativeBackups', 0 );
		}
		if ( in_array( $scenario, array( 'global_third_party', 'empty_global_method' ), true ) ) {
			update_option( 'mainwp_primaryBackup', 'backupbuddy' );
		}
		if ( in_array( $scenario, array( 'third_party', 'third_party_stale', 'third_party_stale_ignore', 'global_third_party' ), true ) ) {
			$stale = 0 === strpos( $scenario, 'third_party_stale' );
			MainWP_DB::instance()->update_website_option( $site_id, 'primary_lasttime_backup', $stale ? time() - 10 * DAY_IN_SECONDS : time() );
			$_POST['sites'] = array( (string) $site_id );
			$preflight      = MainWP_Updates_Overview::check_backups();
			$this->assertSame( ! $stale, $preflight['sites'][ $site_id ], 'The handler must agree with the preflight for this site.' );
		}

		$core   = 'core' === $type;
		$action = $core ? 'mainwp_upgradewp' : 'mainwp_upgradeplugintheme';
		$method = $core ? 'mainwp_upgradewp' : 'mainwp_upgrade_plugintheme';
		$_POST  = array( 'security' => wp_create_nonce( $action ) );
		$_POST += $core ? array( 'id' => $site_id ) : array(
			'websiteId' => $site_id,
			'type'      => $type,
			'slug'      => 'example/example.php',
		);
		if ( in_array( $scenario, array( 'ignore', 'fresh_ignore', 'invalid_ignore', 'third_party_stale_ignore' ), true ) ) {
			$_POST['ignore_backup_gate'] = 'invalid_ignore' === $scenario ? '0' : '1';
		}
		$_REQUEST = $_POST;
		$response = null;
		ob_start();
		try {
			Backup_Gate_Post_Handler::instance()->$method();
			$this->fail( 'The handler must end with JSON or reach the stubbed Child request.' );
		} catch ( \WPDieException $error ) {
			$response = json_decode( $error->getMessage(), true );
			$this->assertIsArray( $response, 'The handler must preserve its JSON response.' );
		} catch ( \Error $error ) {
			$this->assertSame( 'backup-gate-child-request', $error->getMessage() );
		} finally {
			$output = ob_get_clean();
		}
		$this->assertSame( '', $output );
		$blocked = in_array( $scenario, array( 'missing', 'stale', 'pending_offsite', 'invalid_ignore', 'third_party_stale', 'empty_global_method' ), true );
		if ( $blocked ) {
			$this->assertSame( array(), $this->child_calls, 'A refused update must not contact the Child.' );
			$this->assertIsArray( $response );
			$this->assertSame( MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_GATE_BLOCKED )['message'], $core ? $response['error']['message'] : $response['error'] );
			if ( $core ) {
				$this->assertArrayHasKey( 'extra', $response['error'] );
				$this->assertSame( MainWP_Backup_Error_Codes::ERR_GATE_BLOCKED, $response['error']['errorCode'] );
			}
		} else {
			$this->assertCount( 1, $this->child_calls, 'An allowed update must reach the stubbed Child request.' );
			$this->assertSame( $site_id, $this->child_calls[0][0] );
			$this->assertNull( $response );
		}
		$history = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ) . ' WHERE site_id = %d AND outcome = %s', $site_id, 'gate_overridden' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Query only this fixture's durable override evidence.
		$this->assertCount( 'ignore' === $scenario ? 1 : 0, $history, 'Only an explicit override of a failed gate creates history.' );
		if ( 'ignore' === $scenario ) {
			$this->assertSame( get_current_user_id(), (int) $history[0]->initiator_user_id );
		}
	}

	/**
	 * The Updates, Plugins and Themes pages only show the backup modal when this flag follows the setting.
	 */
	public function test_backup_preflight_flag_follows_setting() {
		set_current_screen( 'dashboard' );
		// wp_localize_script() stringifies scalars, so true is "1" and false is "".
		foreach ( array(
			1 => false,
			0 => true,
		) as $setting => $disabled ) {
			update_option( 'mainwp_backup_before_upgrade', $setting );
			wp_deregister_script( 'mainwp' );
			\MainWP\Dashboard\MainWP_System::instance()->admin_init();
			$this->assertMatchesRegularExpression( '/"disable_checkBackupBeforeUpgrade":"' . ( $disabled ? '1' : '' ) . '"/', (string) wp_scripts()->get_data( 'mainwp', 'data' ) );
		}
		wp_dequeue_script( 'mainwp' );
		set_current_screen( 'front' );
	}
}
