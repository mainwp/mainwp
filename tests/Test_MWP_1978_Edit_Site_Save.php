<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit direct-file discovery matches the class name.
/**
 * Edit Site persistence failures and save notices.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Credential_Render;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_Manage_Sites_View;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Settings;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test class naming convention.
// phpcs:disable WordPress.Security.NonceVerification -- Request fixtures include valid nonces checked by the handler.

/** Exercise the production handler, option writer and rendered notices. */
class Test_MWP_1978_Edit_Site_Save extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Original module callbacks.
	 *
	 * @var array
	 */
	private $saved_hooks = array();

	/**
	 * Original module singleton.
	 *
	 * @var Api_Backups_Settings
	 */
	private $saved_settings;

	/**
	 * SQL failure filter.
	 *
	 * @var callable|null
	 */
	private $query_filter;

	/**
	 * Failed fixture writes.
	 *
	 * @var int
	 */
	private $failed_queries = 0;

	/**
	 * Original database error visibility.
	 *
	 * @var bool
	 */
	private $saved_suppression;

	/**
	 * Original request arrays.
	 *
	 * @var array
	 */
	private $saved_request = array();

	/**
	 * Key files created by this test.
	 *
	 * @var array
	 */
	private $key_files = array();

	/** Prepare an authorized editor and fresh module notice state. */
	public function setUp(): void {
		parent::setUp();
		global $wp_filter, $wpdb;
		$this->saved_request = array( $_GET, $_POST, $_REQUEST );
		$_GET                = array();
		$_POST               = array();
		$_REQUEST            = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		set_current_screen( 'dashboard' );
		$this->saved_settings = Api_Backups_Settings::get_instance();
		foreach ( array( 'admin_init', 'mainwp_manage_sites_edit', 'mainwp_update_site', 'mainwp_after_header' ) as $hook ) {
			$this->saved_hooks[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
		}
		remove_action( 'mainwp_manage_sites_edit', array( $this->saved_settings, 'hook_render_mainwp_manage_sites_edit' ), 10 );
		remove_action( 'mainwp_update_site', array( $this->saved_settings, 'hook_mainwp_update_site' ), 10 );
		remove_action( 'mainwp_after_header', array( $this->saved_settings, 'hook_render_credential_save_error' ), 10 );
		Api_Backups_Settings::$instance = new Api_Backups_Settings();
		$this->saved_suppression        = $wpdb->suppress_errors();
	}

	/** Restore hooks, request state and only the fixture's key files. */
	public function tearDown(): void {
		global $wp_filter, $wpdb;
		$this->stop_failing_writes();
		foreach ( $this->key_files as $file_key ) {
			do_action( 'mainwp_delete_key_file', $file_key );
		}
		Api_Backups_Settings::$instance = $this->saved_settings;
		foreach ( $this->saved_hooks as $hook => $callbacks ) {
			if ( null === $callbacks ) {
				unset( $wp_filter[ $hook ] );
			} else {
				$wp_filter[ $hook ] = $callbacks;
			}
		}
		list( $_GET, $_POST, $_REQUEST ) = $this->saved_request;
		$wpdb->suppress_errors( $this->saved_suppression );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** New, changed and unchanged option saves retain their successful return contract. */
	public function test_option_writer_preserves_null_on_success(): void {
		$site = $this->create_test_site();
		$db   = MainWP_DB::instance();
		$this->assertNull( $db->update_website_option( $site, 'mwp1978_option', 'first' ) );
		$this->assertSame( 'first', $db->get_website_option( $site, 'mwp1978_option' ) );
		$this->assertNull( $db->update_website_option( $site, 'mwp1978_option', 'second' ) );
		$this->assertSame( 'second', $db->get_website_option( $site, 'mwp1978_option' ) );
		$this->assertNull( $db->update_website_option( $site, 'mwp1978_option', 'second' ) );
		$this->assertSame( 'second', $db->get_website_option( $site, 'mwp1978_option' ) );
	}

	/**
	 * Failed option writes expose failure without replacing stored values.
	 *
	 * @dataProvider option_write_operations
	 * @param string $operation SQL write to fail.
	 */
	public function test_option_writer_reports_failed_sql( string $operation ): void {
		$site = $this->create_test_site();
		$db   = MainWP_DB::instance();
		if ( 'UPDATE' === $operation ) {
			$this->set_site_option( $site, 'mwp1978_option', 'previous' );
		}
		$this->fail_writes( 'mainwp_wp_options', "'mwp1978_option'", $operation );
		$result = $db->update_website_option( $site, 'mwp1978_option', 'replacement' );
		$this->stop_failing_writes();
		$this->assertSame( 1, $this->failed_queries, 'The intended SQL write must reach the database failure.' );
		$this->assertSame( 'UPDATE' === $operation ? 'previous' : '__missing__', $db->get_website_option( $site, 'mwp1978_option', '__missing__' ) );
		$this->assertFalse( $result, 'A failed SQL write must return false.' );
	}

	/**
	 * Cover both option persistence branches.
	 *
	 * @return array SQL write operations.
	 */
	public static function option_write_operations(): array {
		return array(
			'select' => array( 'SELECT' ),
			'insert' => array( 'INSERT' ),
			'update' => array( 'UPDATE' ),
		);
	}

	/**
	 * The form must report a failed option write even when later writes succeed.
	 *
	 * @dataProvider edit_site_option_failures
	 * @param string $option Stored option to retain.
	 * @param string $previous Stored value before submission.
	 * @param string $field Posted field to change.
	 * @param string $submitted Replacement value.
	 */
	public function test_site_edit_reports_option_failure( string $option, string $previous, string $field, string $submitted ): void {
		$site = $this->create_test_site();
		MainWP_DB::instance()->update_website_option( $site, $option, $previous );
		$this->post_site_edit( $site, array( $field => $submitted ) );
		$this->fail_writes( 'mainwp_wp_options', "'" . $option . "'" );
		$result = $this->save_site( $site );
		$this->stop_failing_writes();
		$html = $this->render_site( $site, $result );
		$this->assertSame( 1, $this->failed_queries, 'The selected option write must fail.' );
		$this->assertSame( $previous, MainWP_DB::instance()->get_website_option( $site, $option ) );
		$this->assert_failed_save( $result, $html );
	}

	/**
	 * Core and API-module options share the form save result.
	 *
	 * @return array Option and posted-field fixtures.
	 */
	public static function edit_site_option_failures(): array {
		return array(
			'translation-ignore'  => array( 'ignored_trans_updates', '0', 'mainwp_is_ignore_trans_updates', '1' ),
			'monitoring-emails'   => array( 'monitoring_notification_emails', 'before@example.com', 'mainwp_managesites_edit_monitoringNotificationEmails', 'after@example.com' ),
			'added-date'          => array( 'added_timestamp', '946684800', 'mainwp_managesites_edit_dt_added', '2020-01-02' ),
			'signature-algorithm' => array( 'signature_algo', '9999', 'mainwp_managesites_edit_openssl_alg', '7' ),
			'verification-method' => array( 'verify_method', '1', 'mainwp_managesites_edit_verify_connection_method', '2' ),
			'custom-icon'         => array( 'cust_site_icon_info', 'uploaded:;selected:globe;color:#123456', 'mainwp_managesites_edit_site_selected_icon_hidden', 'wordpress' ),
			'url-lock'            => array( 'url_correct_lock', '0', 'mainwp_managesites_edit_url_lock', '1' ),
			'url-pending'         => array( 'url_correct_pending', 'pending-correction', 'mainwp_managesites_edit_url_lock', '1' ),
			'backup-provider'     => array( 'mainwp_3rd_party_api', 'Plesk', 'mainwp_managesites_edit_module_api_backups_provider', '4' ),
		);
	}

	/**
	 * A failed site-row update must suppress the saved-settings notice.
	 *
	 * @dataProvider site_row_failures
	 * @param string $column Column identifying the write to reject.
	 * @param string $previous Persisted value that must survive.
	 */
	public function test_site_edit_reports_site_row_failure( string $column, string $previous ): void {
		$site = $this->create_test_site(
			array(
				'name'             => 'Original site name',
				'automatic_update' => 0,
			)
		);
		$this->post_site_edit( $site, array( 'mainwp_automaticDailyUpdate' => '1' ) );
		$this->fail_writes( 'mainwp_wp', '`' . $column . '`' );
		$result = $this->save_site( $site );
		$this->stop_failing_writes();
		$html = $this->render_site( $site, $result );
		$this->assertSame( 1, $this->failed_queries, 'The selected site-row write must fail.' );
		$this->assertSame( $previous, MainWP_DB::instance()->get_website_by_id( $site )->{$column} );
		$this->assert_failed_save( $result, $html );
	}

	/**
	 * Reject each of the two site-row update calls independently.
	 *
	 * @return array Column and stored-value fixtures.
	 */
	public static function site_row_failures(): array {
		return array(
			'primary-row'         => array( 'name', 'Original site name' ),
			'additional-settings' => array( 'automatic_update', '0' ),
		);
	}

	/** A rejected credential clear must retain both its envelope and its decryption key. */
	public function test_site_edit_failed_credential_clear_keeps_previous_key(): void {
		$site    = $this->create_test_site();
		$utility = Api_Backups_Utility::get_instance();
		$this->assertTrue( $utility->update_child_api_key( $site, 'cpanel', 'fixture-existing-credential' ) );
		$option   = 'mainwp_api_backups_cpanel_api_key';
		$previous = MainWP_DB::instance()->get_website_option( $site, $option );
		$envelope = json_decode( $previous, true );
		$this->assertIsArray( $envelope );
		$this->key_files[] = $envelope['file_key'];
		$key_path          = MainWP_Keys_Manager::get_keys_dir() . $envelope['file_key'];
		$this->assertFileExists( $key_path );
		$this->post_site_edit(
			$site,
			array(
				'mainwp_managesites_edit_module_api_backups_provider' => '4',
				'mainwp_cpanel_account_password' => '',
				'mainwp_plesk_api_key'           => MainWP_Credential_Render::SENTINEL,
				'mainwp_kinsta_api_key'          => MainWP_Credential_Render::SENTINEL,
			)
		);
		$this->fail_writes( 'mainwp_wp_options', "'" . $option . "'" );
		$result = $this->save_site( $site );
		$this->stop_failing_writes();
		$html = $this->render_site( $site, $result );
		$this->assertSame( 1, $this->failed_queries, 'The credential clear must reach the failed UPDATE.' );
		$this->assertSame( $previous, MainWP_DB::instance()->get_website_option( $site, $option ) );
		clearstatcache( true, $key_path );
		$this->assertFileExists( $key_path, 'The stored credential still needs its previous key file.' );
		$this->assertSame( 'fixture-existing-credential', $utility->get_child_api_key( $site, 'cpanel' ) );
		$this->assert_failed_save( $result, $html );
	}

	/** A rejected icon option must not delete the file still referenced by the site. */
	public function test_failed_icon_save_preserves_uploaded_file(): void {
		$site     = $this->create_test_site();
		$filename = 'mwp1978-icon-' . wp_generate_uuid4() . '.png';
		$dirs     = MainWP_System_Utility::get_mainwp_dir( 'site-icons', true );
		$path     = $dirs[0] . $filename;
		$previous = 'uploaded:' . $filename . ';selected:;color:';
		$this->assertNotFalse( file_put_contents( $path, 'fixture-icon' ) );
		try {
			MainWP_DB::instance()->update_website_option( $site, 'cust_site_icon_info', $previous );
			$this->post_site_edit( $site, array( 'mainwp_managesites_edit_site_selected_icon_hidden' => 'wordpress' ) );
			$this->fail_writes( 'mainwp_wp_options', "'cust_site_icon_info'" );
			$result = $this->save_site( $site );
			$this->stop_failing_writes();
			$html = $this->render_site( $site, $result );
			$this->assertSame( 1, $this->failed_queries );
			$this->assertSame( $previous, MainWP_DB::instance()->get_website_option( $site, 'cust_site_icon_info' ) );
			clearstatcache( true, $path );
			$this->assertFileExists( $path, 'The unchanged icon setting still references the uploaded file.' );
			$this->assert_failed_save( $result, $html );
		} finally {
			wp_delete_file( $path );
		}
	}

	/** A successful submission still persists settings and shows the existing success notice. */
	public function test_successful_site_edit_keeps_success_notice(): void {
		$site = $this->create_test_site();
		$this->post_site_edit( $site );
		$result = $this->save_site( $site );
		$html   = $this->render_site( $site, $result );
		$this->assertTrue( $result );
		$this->assertSame( 'Changed site name', MainWP_DB::instance()->get_website_by_id( $site )->name );
		$this->assertStringContainsString( 'Child site settings saved successfully.', $html );
		$this->assertSame( array(), $this->error_notices( $html ) );
		$result = $this->save_site( $site );
		$this->assertTrue( $result, 'Saving unchanged settings must still succeed.' );
		$this->assertStringContainsString( 'Child site settings saved successfully.', $this->render_site( $site, $result ) );
	}

	/**
	 * Submit a nonce-valid form with unchanged credential placeholders.
	 *
	 * @param int   $site Child site ID.
	 * @param array $changes Posted field overrides.
	 */
	private function post_site_edit( int $site, array $changes = array() ): void {
		$_POST    = array_merge(
			array(
				'submit'                                   => 'Save Settings',
				'wp_nonce'                                 => wp_create_nonce( 'UpdateWebsite' . $site ),
				'mainwp_managesites_edit_siteadmin'        => 'admin',
				'mainwp_managesites_edit_sitename'         => 'Changed site name',
				'mainwp_managesites_edit_siteurl_protocol' => 'https',
				'mainwp_managesites_edit_monitoringNotificationEmails' => 'after@example.com',
				'mainwp_cpanel_account_password'           => MainWP_Credential_Render::SENTINEL,
				'mainwp_plesk_api_key'                     => MainWP_Credential_Render::SENTINEL,
				'mainwp_kinsta_api_key'                    => MainWP_Credential_Render::SENTINEL,
			),
			$changes
		);
		$_REQUEST = array( 'id' => $site );
	}

	/**
	 * Invoke the same handler used by the Edit Site request.
	 *
	 * @param int $site Child site ID.
	 * @return bool Handler result.
	 */
	private function save_site( int $site ): bool {
		$method = new \ReflectionMethod( MainWP_Manage_Sites::class, 'update_site_handle' );
		$method->setAccessible( true );
		return $method->invoke( null, MainWP_DB::instance()->get_website_by_id( $site ) );
	}

	/**
	 * Render the saved form and its registered page notices.
	 *
	 * @param int  $site Child site ID.
	 * @param bool $updated Handler result.
	 * @return string Rendered form and notices.
	 */
	private function render_site( int $site, bool $updated ): string {
		ob_start();
		try {
			do_action( 'mainwp_after_header', array() );
			MainWP_Manage_Sites_View::render_edit_site( $site, $updated );
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Replace only the intended write with real SQL against a missing fixture table.
	 *
	 * @param string $table MainWP table suffix.
	 * @param string $marker SQL fragment identifying the write.
	 * @param string $operation SQL operation.
	 */
	private function fail_writes( string $table, string $marker, string $operation = 'UPDATE' ): void {
		global $wpdb;
		$prefix               = 'SELECT' === $operation ? 'SELECT name FROM ' . $wpdb->prefix . $table : ( 'INSERT' === $operation ? 'INSERT INTO' : $operation ) . ' `' . $wpdb->prefix . $table . '`';
		$this->failed_queries = 0;
		$this->query_filter   = function ( $query ) use ( $prefix, $marker ) {
			if ( 0 === strpos( $query, $prefix ) && false !== strpos( $query, $marker ) ) {
				++$this->failed_queries;
				return 'UPDATE mainwp_missing_edit_site_fixture SET value = 1';
			}
			return $query;
		};
		add_filter( 'query', $this->query_filter, 999 );
	}

	/** Remove SQL failure injection before rendering and reading persisted values. */
	private function stop_failing_writes(): void {
		if ( $this->query_filter ) {
			remove_filter( 'query', $this->query_filter, 999 );
			$this->query_filter = null;
		}
	}

	/**
	 * Assert the failure reaches both the handler caller and the user.
	 *
	 * @param bool   $result Handler result.
	 * @param string $html Rendered form.
	 */
	private function assert_failed_save( bool $result, string $html ): void {
		$this->assertStringContainsString( 'id="mainwp-edit-site"', $html );
		$this->assertFalse( $result, 'An incomplete settings save must not report success.' );
		$this->assertStringNotContainsString( 'Child site settings saved successfully.', $html );
		$this->assertNotEmpty( $this->error_notices( $html ), 'The failed save needs a visible error notice.' );
	}

	/**
	 * Read populated error notices without binding tests to their wording.
	 *
	 * @param string $html Rendered form.
	 * @return array Visible error messages.
	 */
	private function error_notices( string $html ): array {
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( $html );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$xpath   = new \DOMXPath( $document );
		$nodes   = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " message ") and contains(concat(" ", normalize-space(@class), " "), " red ") and not(contains(@style, "display:none")) and not(contains(@style, "display: none"))]' );
		$notices = array();
		foreach ( $nodes as $node ) {
			if ( '' !== trim( $node->textContent ) ) {
				$notices[] = trim( $node->textContent );
			}
		}
		return $notices;
	}
}
