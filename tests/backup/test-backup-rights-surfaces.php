<?php
/**
 * Backup download rights and page visibility through browser authentication.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Download;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Page;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Snapshots_View;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Sites_Backup_View;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_System_Handler;
use MainWP\Dashboard\MainWP_System_Utility;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/** Real credentials resolve through core before each browser surface is invoked. */
class MainWP_Backup_Rights_Surfaces_Test extends MainWP_Abilities_Test_Case {
	use Backup_Private_Root_Fixture;

	/**
	 * Request state restored after each case.
	 *
	 * @var array
	 */
	private $saved = array();
	/**
	 * Fixture user, site, snapshot, archive and application password.
	 *
	 * @var array
	 */
	private $fixture = array();
	/**
	 * Token records to remove.
	 *
	 * @var array
	 */
	private $tokens = array();

	/** Credentials and archives must be valid before a denial is tested. */
	public function setUp(): void {
		parent::setUp();
		$this->skip_when_capabilities_bypassed();
		foreach ( array( '_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', 'current_user', 'current_screen', 'wp_rest_auth_cookie', 'wp_rest_application_password_status', 'wp_rest_application_password_uuid', 'submenu', 'mainwp_leftmenu', 'mainwp_sub_leftmenu', '_registered_pages' ) as $key ) {
			$this->saved[ $key ] = array( array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null );
		}
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core must resolve the browser cookie outside a simulated admin screen.
		$this->fixture['user']     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$GLOBALS['current_user']   = new \WP_User( $this->fixture['user'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- fixture setup precedes request authentication.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$password = \WP_Application_Passwords::create_new_application_password( $this->fixture['user'], array( 'name' => 'Browser backup fixture' ) );
		$this->assertNotWPError( $password );
		$this->fixture['password'] = $password[0];
		$this->fixture['cookie']   = wp_generate_auth_cookie( $this->fixture['user'], time() + HOUR_IN_SECONDS, 'logged_in' );
		$this->saved['query_vars'] = $GLOBALS['wp']->query_vars;
		$hidden                    = new \ReflectionProperty( MainWP_Backup_Page::class, 'hideSubmenuBackups' );
		$hidden->setAccessible( true );
		$this->fixture['hidden']   = $hidden->getValue();
		$this->fixture['subpages'] = MainWP_Backup_Page::$subPages;
		$hidden->setValue( null, false );
		MainWP_Backup_Page::$subPages = null;
		$this->set_up_private_root();
		$this->fixture['site'] = $this->create_test_site();
		$this->fixture['path'] = MainWP_Backup_Private_Root::site_dir( $this->fixture['site'] ) . 'full.zip';
		file_put_contents( $this->fixture['path'], 'archive bytes' );
		$this->fixture['snapshot'] = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'site_id'  => $this->fixture['site'],
				'type'     => 'full',
				'archives' => array(
					array(
						'file' => 'full.zip',
						'path' => $this->fixture['path'],
						'size' => 13,
						'role' => 'full',
					),
				),
			)
		);
		update_option( 'mainwp_enableNativeBackups', 1 );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
	}

	/** Request identities and transient tokens must not leak between cases. */
	public function tearDown(): void {
		// A setUp() skip runs before any fixture or saved global exists.
		if ( ! array_key_exists( 'query_vars', $this->saved ) ) {
			parent::tearDown();
			return;
		}
		foreach ( $this->tokens as $key ) {
			delete_site_transient( $key );
		}
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_role( 'mainwp_download_staff' );
		$this->tear_down_private_root();
		$hidden = new \ReflectionProperty( MainWP_Backup_Page::class, 'hideSubmenuBackups' );
		$hidden->setAccessible( true );
		$hidden->setValue( null, $this->fixture['hidden'] );
		MainWP_Backup_Page::$subPages = $this->fixture['subpages'];
		$GLOBALS['wp']->query_vars    = $this->saved['query_vars'];
		unset( $this->saved['query_vars'] );
		foreach ( $this->saved as $key => list( $exists, $value ) ) {
			if ( $exists ) {
				$GLOBALS[ $key ] = $value;
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tearDown();
	}

	/**
	 * Application passwords are not API credentials on browser endpoints.
	 *
	 * @param string $state Authentication state.
	 */
	private function authenticate( string $state ): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$GLOBALS['wp_rest_auth_cookie']                 = null;
		$GLOBALS['wp_rest_application_password_status'] = null;
		$GLOBALS['wp_rest_application_password_uuid']   = null;
		if ( 'application-password' !== $state ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $this->fixture['cookie'];
		}
		if ( str_starts_with( $state, 'application-password' ) ) {
			$_SERVER['PHP_AUTH_USER'] = get_userdata( $this->fixture['user'] )->user_login;
			$_SERVER['PHP_AUTH_PW']   = $this->fixture['password'];
		}
		unset( $GLOBALS['current_user'] );
		$id = (int) apply_filters( 'determine_current_user', false );
		$this->assertSame( 'application-password' === $state ? 0 : $this->fixture['user'], $id );
		$GLOBALS['current_user'] = new \WP_User( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- retain the identity core resolved without calling wp_set_current_user().
		$this->assertFalse( $GLOBALS['wp_rest_application_password_status'] instanceof \WP_User );
		$this->assertNotFalse( has_filter( 'determine_current_user', 'wp_validate_application_password' ) );
		$this->assertNotFalse( has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ) );
	}

	/**
	 * Every browser state is crossed with granted, denied and administrator rights.
	 *
	 * @return array
	 */
	public static function browser_rights(): array {
		$cases = array();
		foreach ( array( 'application-password', 'application-password-cookie', 'cookie-nonce', 'cookie-only' ) as $state ) {
			foreach ( array( 'denied', 'default', 'team-admin' ) as $right ) {
				$cases[ $state . '/' . $right ] = array( $state, $right );
			}
		}
		return $cases;
	}

	/**
	 * Only the selected Team Control right changes.
	 *
	 * @param string $mode Rights mode.
	 * @param string $right Operation right.
	 * @return \Closure Filter for cleanup.
	 */
	private function rights( string $mode, string $right = 'download_backups' ): \Closure {
		$filter = static function ( $allowed, $type, $cap ) use ( $mode, $right ) {
			return 'team-admin' === $mode ? true : ( 'denied' === $mode && 'dashboard' === $type && $right === $cap ? false : $allowed );
		};
		if ( 'default' !== $mode ) {
			add_filter( 'mainwp_currentusercan', $filter, 10, 3 );
		}
		return $filter;
	}

	/**
	 * Literal die calls need a returning seam so PHPUnit can observe refusals.
	 *
	 * @return MainWP_Post_Backup_Handler
	 */
	private function ajax_handler(): MainWP_Post_Backup_Handler {
		return new class() extends MainWP_Post_Backup_Handler {
			/**
			 * Preserve the base guard before its literal die.
			 *
			 * @param string $action Nonce action.
			 * @param string $query_arg Nonce field.
			 * @throws \RuntimeException When no browser user is authorized.
			 */
			public function secure_request( $action = '', $query_arg = 'security' ) {
				if ( ! MainWP_System_Utility::is_admin() ) {
					throw new \RuntimeException( 'no user' );
				}
				parent::secure_request( $action, $query_arg );
			}

			/**
			 * Retain the real nonce check while replacing its literal die.
			 *
			 * @param string $action Nonce action.
			 * @param string $query_arg Nonce field.
			 * @param bool   $out_die Whether refusal terminates.
			 * @return bool
			 * @throws \RuntimeException When the nonce is absent or invalid.
			 */
			public function check_security( $action = -1, $query_arg = 'security', $out_die = true ) {
				if ( ! parent::check_security( $action, $query_arg, false ) ) {
					throw new \RuntimeException( 'nonce' );
				}
				return true;
			}
		};
	}

	/**
	 * Capture JSON without replacing authentication or operation rights.
	 *
	 * @param callable $callback Handler invocation.
	 * @return array
	 */
	private function ajax( callable $callback ): array {
		$die = static function () {
			return static function () {
				throw new \RuntimeException( 'json' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			$callback();
		} catch ( \RuntimeException $error ) {
			$this->assertContains( $error->getMessage(), array( 'json', 'no user', 'nonce' ) );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_die_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return json_decode( $raw, true ) ?? array();
	}

	/**
	 * Only a cookie with a nonce and both rights can mint a download URL.
	 *
	 * @dataProvider browser_rights
	 * @param string $state Authentication state.
	 * @param string $mode Team Control mode.
	 */
	public function test_ajax_rights( string $state, string $mode ) {
		if ( 'denied' === $mode ) {
			add_role( 'mainwp_download_staff', 'Backup staff', get_role( 'administrator' )->capabilities );
			$user = new \WP_User( $this->fixture['user'] );
			$user->set_role( 'mainwp_download_staff' );
		}
		$this->authenticate( $state );
		$filter               = $this->rights( $mode );
		$_POST['snapshot_id'] = $this->fixture['snapshot'];
		if ( 'cookie-nonce' === $state ) {
			$_POST['security']    = wp_create_nonce( 'mainwp_backup_snapshot_download_url' );
			$_REQUEST['security'] = wp_create_nonce( 'mainwp_backup_snapshot_download_url' );
		}
		try {
			$handler  = $this->ajax_handler();
			$response = $this->ajax( array( $handler, 'mainwp_backup_snapshot_download_url' ) );
			if ( 'cookie-nonce' === $state && 'denied' !== $mode ) {
				$this->assertArrayHasKey( 'url', $response );
				$this->track_link( $response['url'] );
			} else {
				$this->assertArrayNotHasKey( 'url', $response );
				if ( 'cookie-nonce' === $state ) {
					$this->assertSame( 'You do not have permission to download this backup.', $response['error'] );
				}
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
	}

	/**
	 * Cancel run admits only a cookie with its nonce, then applies the task right.
	 *
	 * @dataProvider browser_rights
	 * @param string $state Authentication state.
	 * @param string $mode Team Control mode.
	 */
	public function test_cancel_run_rights( string $state, string $mode ) {
		$task = MainWP_DB_Backup::instance()->add_backup_task( $this->fixture['user'], 'Cancel run rights', 'daily', 'full', '', (string) $this->fixture['site'], '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 0, 150, 0 );
		$this->assertIsObject( $task );
		$this->authenticate( $state );
		$filter           = $this->rights( $mode, 'pause_resume_backup_tasks' );
		$_POST['task_id'] = (string) $task->id;
		$_POST['run']     = (string) $task->last_run;
		if ( 'cookie-nonce' === $state ) {
			$_POST['security']    = wp_create_nonce( 'mainwp_native_backup_task_cancel_run' );
			$_REQUEST['security'] = wp_create_nonce( 'mainwp_native_backup_task_cancel_run' );
		}
		$before = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		try {
			$handler  = $this->ajax_handler();
			$response = $this->ajax( array( $handler, 'mainwp_native_backup_task_cancel_run' ) );
			if ( 'cookie-nonce' !== $state ) {
				$this->assertSame( array(), $response );
			} elseif ( 'denied' === $mode ) {
				$this->assertSame( array( 'error' => 'Your role does not permit this operation.' ), $response );
			} else {
				// Past both gates, the idle fixture task is refused on its run state.
				$this->assertSame( array( 'error' => 'This task is not currently running.' ), $response );
			}
			$this->assertEquals( $before, MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
	}

	/**
	 * Token expiry and bindings stay real across the next simulated request.
	 *
	 * @param string $url Issued URL.
	 * @return array
	 */
	private function track_link( string $url ): array {
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->tokens[] = ( new \ReflectionClassConstant( MainWP_Backup_Download::class, 'TRANSIENT_PREFIX' ) )->getValue() . hash( 'sha256', $query['token'] );
		return $query;
	}

	/**
	 * Revocation after minting must refuse every browser authentication state.
	 *
	 * @dataProvider browser_rights
	 * @param string $state Authentication state.
	 * @param string $mode Team Control mode.
	 */
	public function test_redeem_rights( string $state, string $mode ) {
		$this->authenticate( 'cookie-nonce' );
		$url = MainWP_Backup_Download::create_download_url( $this->fixture['snapshot'] );
		$this->assertIsString( $url );
		$query = $this->track_link( $url );
		$this->authenticate( $state );
		$filter = $this->rights( $mode );
		if ( 'cookie-nonce' !== $state ) {
			unset( $query['_wpnonce'] );
		}
		try {
			$result = MainWP_Backup_Download::redeem_download( $query );
			if ( 'cookie-nonce' === $state && 'denied' !== $mode ) {
				$this->assertSame( realpath( $this->fixture['path'] ), $result );
			} else {
				$this->assertWPError( $result );
				if ( 'cookie-nonce' === $state ) {
					$this->assertSame( 'mainwp_backup_download_permission', $result->get_error_code() );
					$this->assertNotFalse( get_site_transient( end( $this->tokens ) ) );
				}
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
	}

	/**
	 * The signed private reader has no nonce requirement but honors both rights.
	 *
	 * @dataProvider browser_rights
	 * @param string $state Authentication state.
	 * @param string $mode Team Control mode.
	 */
	public function test_private_rights( string $state, string $mode ) {
		$this->authenticate( $state );
		$filter   = $this->rights( $mode );
		$streamer = new class() extends MainWP_System_Handler {
			/**
			 * Capture successful selection before parse_init exits.
			 *
			 * @param string $file Selected archive.
			 * @throws \RuntimeException On a served archive.
			 */
			public function upload_file( $file ) {
				throw new \RuntimeException( file_get_contents( $file ) );
			}
		};
		$property = new \ReflectionProperty( MainWP_System_Handler::class, 'instance' );
		$original = $property->getValue();
		$_GET     = array(
			'root'  => 'private',
			'mwpdl' => 'sites/' . $this->fixture['site'] . '/full.zip',
			'sig'   => MainWP_System_Utility::get_download_sig( $this->fixture['path'] ),
		);
		if ( 'cookie-nonce' === $state ) {
			$_GET['_wpnonce'] = wp_create_nonce( 'mainwp-admin-nonce' );
		}
		$_REQUEST = $_GET;
		$bytes    = null;
		try {
			$property->setValue( null, $streamer );
			try {
				MainWP_System::instance()->parse_init();
			} catch ( \RuntimeException $error ) {
				$bytes = $error->getMessage();
			}
			$this->assertSame( 'application-password' !== $state && 'denied' !== $mode ? 'archive bytes' : null, $bytes );
		} finally {
			$property->setValue( null, $original );
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
	}

	/** The same right hides full and DB archive anchors in either storage root. */
	public function test_legacy_links() {
		$this->authenticate( 'cookie-only' );
		$legacy = MainWP_System_Utility::get_mainwp_specific_dir( $this->fixture['site'] ) . 'legacy.zip';
		file_put_contents( $legacy, 'legacy bytes' );
		$site   = MainWP_DB::instance()->get_website_by_id( $this->fixture['site'] );
		$native = get_option( 'mainwp_enableNativeBackups' );
		try {
			foreach ( array( 'denied/native', 'default/native', 'denied/legacy', 'default/legacy' ) as $case ) {
				list( $mode, $toggle ) = explode( '/', $case );
				update_option( 'mainwp_enableNativeBackups', 'native' === $toggle ? 1 : 0 );
				$filter = $this->rights( $mode );
				ob_start();
				try {
					MainWP_Manage_Sites_Backup_View::show_backups( $site, array( $legacy, $this->fixture['path'] ), array( $legacy, $this->fixture['path'] ) );
				} finally {
					$output = ob_get_clean();
					remove_filter( 'mainwp_currentusercan', $filter, 10 );
				}
				$this->assertSame( 4, substr_count( $output, '<label ' ) );
				$this->assertSame( 'denied' === $mode ? 0 : 4, substr_count( $output, '>Download</a>' ) );
				$this->assertSame( 'default/legacy' === $case ? 2 : 0, substr_count( $output, 'page=SiteRestore' ) );
				if ( 'denied' === $mode ) {
					$this->assertStringNotContainsString( 'sig=', $output );
					$this->assertStringNotContainsString( 'SiteRestore', $output );
					preg_match_all( '/[?&;]f=([A-Za-z0-9+\/=%]+)/', $output, $encoded );
					foreach ( $encoded[1] as $value ) {
						$this->assertStringNotContainsString( 'sig=', (string) base64_decode( rawurldecode( $value ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the legacy restore link base64-encodes the signed URL.
					}
				}
			}
		} finally {
			update_option( 'mainwp_enableNativeBackups', $native );
			unlink( $legacy );
		}
	}

	/** The script receives the same download right enforced at issuance. */
	public function test_download_script() {
		$this->authenticate( 'cookie-only' );
		$_GET['site_id'] = $this->fixture['site'];
		foreach ( array( 'denied', 'default' ) as $mode ) {
			$filter = $this->rights( $mode );
			ob_start();
			try {
				MainWP_Backup_Snapshots_View::render();
			} finally {
				$output = ob_get_clean();
				remove_filter( 'mainwp_currentusercan', $filter, 10 );
			}
			$this->assertStringContainsString( 'var canDownload = ' . ( 'denied' === $mode ? 'false' : 'true' ) . ';', $output );
		}
	}

	/**
	 * Page GETs use the cookie in both nonce states.
	 *
	 * @dataProvider page_states
	 * @param string $state Authentication state.
	 * @param string $mode Team Control mode.
	 */
	public function test_page_rights( string $state, string $mode ) {

		$this->authenticate( $state );
		$filter = $this->rights( $mode, 'execute_backups' );
		if ( 'cookie-nonce' === $state ) {
			$_GET['_wpnonce'] = wp_create_nonce( 'mainwp-admin-nonce' );
		}
		$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Visible schedule', 'daily', 'full', '', (string) $this->fixture['site'], '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		ob_start();
		try {
			MainWP_Backup_Page::render_manager();
		} finally {
			$output = ob_get_clean();
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
		if ( 'denied' === $mode ) {
			$this->assertStringContainsString( '(Manage Backups)', $output );
			foreach ( array( 'mainwp-backups-table', 'mainwp-actions-bar', 'managebackups-task-status-box', 'Visible schedule' ) as $marker ) {
				$this->assertStringNotContainsString( $marker, $output );
			}
		} else {
			$this->assertStringContainsString( 'Visible schedule', $output );
		}
		$this->assertGreaterThan( 0, (int) $task->id );
	}

	/**
	 * Page GETs do not need a nonce to retain the cookie identity.
	 *
	 * @return array
	 */
	public static function page_states(): array {
		return array_filter( self::browser_rights(), static fn( $row ) => in_array( $row[0], array( 'cookie-nonce', 'cookie-only' ), true ) );
	}

	/** The native-off pointer and edit form retain their separate access rules. */
	public function test_page_exceptions() {
		$this->authenticate( 'cookie-only' );
		$filter = $this->rights( 'denied', 'execute_backups' );
		try {
			update_option( 'mainwp_enableNativeBackups', 0 );
			ob_start();
			MainWP_Backup_Page::render_manager();
			$output = ob_get_clean();
			$this->assertStringContainsString( 'Native backups are turned off.', $output );
			update_option( 'mainwp_enableNativeBackups', 1 );
			$task            = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Editable schedule', 'daily', 'full', '', (string) $this->fixture['site'], '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
			$_GET['task_id'] = $task->id;
			ob_start();
			MainWP_Backup_Page::render_manager();
			$output = ob_get_clean();
			$this->assertStringContainsString( 'id="mainwp_managebackups_edit_id" value="' . $task->id . '"', $output );
			$this->assertStringNotContainsString( '(Manage Backups)', $output );
		} finally {
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
	}

	/** Hidden WordPress labels preserve direct access to the standard notice. */
	public function test_menu_rights() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->authenticate( 'cookie-only' );
		$filter                           = $this->rights( 'denied', 'execute_backups' );
		$GLOBALS['submenu']['mainwp_tab'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolate menu registrations.
		$GLOBALS['mainwp_sub_leftmenu']   = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolate menu registrations.
		try {
			MainWP_Backup_Page::init_menu();
			$matches = array_values( array_filter( $GLOBALS['submenu']['mainwp_tab'], static fn( $item ) => 'ManageBackups' === $item[2] ) );
			$this->assertCount( 1, $matches );
			$this->assertSame( 'read', $matches[0][1] );
			$this->assertStringContainsString( '<div class="mainwp-hidden">', $matches[0][0] );
			$left = array_values( array_filter( $GLOBALS['mainwp_sub_leftmenu']['ManageBackups'], static fn( $item ) => 'ManageBackups' === $item[4] ) );
			$this->assertSame( 'execute_backups', $left[0][2] );
			ob_start();
			MainWP_Backup_Page::init_subpages_menu();
			$output = ob_get_clean();
			$this->assertStringNotContainsString( '>Manage Backups</a>', $output );
		} finally {
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
	}
}
