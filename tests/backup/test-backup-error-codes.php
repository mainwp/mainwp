<?php
/**
 * MainWP Backup Error Codes Tests
 *
 * Tests for the backup error code registry, message map, severity map,
 * and sensitive-information sanitization.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;

/**
 * Class MainWP_Backup_Error_Codes_Test
 */
class MainWP_Backup_Error_Codes_Test extends \WP_UnitTestCase {

	/**
	 * Error codes the child plugin emits; the dashboard registry must know all of them.
	 *
	 * @var string[]
	 */
	private $child_codes = array(
		'mainwp_backup_disk_full',
		'mainwp_backup_memory_limit',
		'mainwp_backup_permissions',
		'mainwp_backup_dir_not_writable',
		'mainwp_backup_missing_extension',
		'mainwp_backup_db_connection_lost',
		'mainwp_backup_db_query_failed',
		'mainwp_backup_db_dump_empty',
		'mainwp_backup_file_corrupt',
		'mainwp_backup_file_too_small',
		'mainwp_backup_zip_failed',
		'mainwp_backup_tar_write_failed',
		'mainwp_backup_file_skipped',
		'mainwp_backup_too_many_skipped',
		'mainwp_backup_stalled',
		'mainwp_backup_already_running',
	);

	/**
	 * Dashboard-side error codes.
	 *
	 * @var string[]
	 */
	private $dashboard_codes = array(
		'mainwp_backup_timeout',
		'mainwp_backup_http_error',
		'mainwp_backup_ssl_failed',
		'mainwp_backup_connect_failed',
		'mainwp_backup_child_unreachable',
		'mainwp_backup_header_invalid',
		'mainwp_backup_retry_exhausted',
		'mainwp_backup_suspended_site',
		'mainwp_backup_no_permission',
		'mainwp_backup_legacy_disabled',
		'mainwp_backup_ftp_failed',
		'mainwp_backup_dropbox_failed',
		'mainwp_backup_s3_failed',
	);

	/**
	 * All child-emitted codes are registered.
	 *
	 * @return void
	 */
	public function test_registers_all_child_error_codes() {
		$registered = MainWP_Backup_Error_Codes::get_registered_codes();
		foreach ( $this->child_codes as $code ) {
			$this->assertContains( $code, $registered, "Child error code {$code} must be registered." );
		}
	}

	/**
	 * All dashboard-side codes are registered.
	 *
	 * @return void
	 */
	public function test_registers_dashboard_error_codes() {
		$registered = MainWP_Backup_Error_Codes::get_registered_codes();
		foreach ( $this->dashboard_codes as $code ) {
			$this->assertContains( $code, $registered, "Dashboard error code {$code} must be registered." );
		}
	}

	/**
	 * Every registered code maps to an actionable message.
	 *
	 * @return void
	 */
	public function test_every_code_has_actionable_message() {
		foreach ( MainWP_Backup_Error_Codes::get_registered_codes() as $code ) {
			$message = MainWP_Backup_Error_Codes::message( $code );
			$this->assertNotEmpty( $message, "Message for {$code} must not be empty." );
			$this->assertGreaterThan( 30, strlen( $message ), "Message for {$code} must explain what/why/how." );
			$this->assertStringNotContainsStringIgnoringCase( 'undefined error', $message );
			$this->assertStringNotContainsStringIgnoringCase( 'unknown error', $message );
		}
	}

	/**
	 * Messages never leak absolute server paths.
	 *
	 * @return void
	 */
	public function test_messages_contain_no_absolute_paths() {
		foreach ( MainWP_Backup_Error_Codes::get_registered_codes() as $code ) {
			$message = MainWP_Backup_Error_Codes::message( $code );
			$this->assertStringNotContainsString( ABSPATH, $message, "Message for {$code} must not contain ABSPATH." );
		}
	}

	/**
	 * Unknown codes fall back to a generic but useful message.
	 *
	 * @return void
	 */
	public function test_unknown_code_returns_generic_fallback() {
		$message = MainWP_Backup_Error_Codes::message( 'mainwp_backup_nonexistent_code' );
		$this->assertNotEmpty( $message );
		$this->assertStringNotContainsStringIgnoringCase( 'undefined error', $message );
	}

	/**
	 * Warning-severity codes map to 'warning'.
	 *
	 * @return void
	 */
	public function test_warning_codes_have_warning_severity() {
		$warnings = array(
			'mainwp_backup_memory_limit',
			'mainwp_backup_file_skipped',
			'mainwp_backup_already_running',
		);
		foreach ( $warnings as $code ) {
			$this->assertSame( 'warning', MainWP_Backup_Error_Codes::severity( $code ), "{$code} must be a warning." );
		}
	}

	/**
	 * Error-severity codes (and unknown codes) map to 'error'.
	 *
	 * @return void
	 */
	public function test_error_codes_have_error_severity() {
		$errors = array(
			'mainwp_backup_timeout',
			'mainwp_backup_disk_full',
			'mainwp_backup_file_corrupt',
		);
		foreach ( $errors as $code ) {
			$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( $code ), "{$code} must be an error." );
		}
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( 'mainwp_backup_nonexistent_code' ), 'Unknown codes default to error severity.' );
	}

	/**
	 * Sanitization strips the site root path.
	 *
	 * @return void
	 */
	public function test_sanitize_strips_site_root_path() {
		$raw       = 'Could not open ' . ABSPATH . 'wp-content/uploads/mainwp/backup.zip for writing.';
		$sanitized = MainWP_Backup_Error_Codes::sanitize_message( $raw );
		$this->assertStringNotContainsString( ABSPATH, $sanitized );
		$this->assertStringContainsString( 'wp-content/uploads/mainwp/backup.zip', $sanitized );
	}

	/**
	 * Sanitization strips SQL statement fragments.
	 *
	 * @return void
	 */
	public function test_sanitize_strips_sql_statements() {
		$cases = array(
			'Query failed: SELECT * FROM wp_woocommerce_orders WHERE id > 5',
			'Could not run INSERT INTO wp_custom_crm_leads (name) VALUES ("x")',
			'Error in DROP TABLE IF EXISTS wp_users',
			'Failure: CREATE TABLE wp_secret_data (id INT)',
		);
		foreach ( $cases as $raw ) {
			$sanitized = MainWP_Backup_Error_Codes::sanitize_message( $raw );
			$this->assertStringNotContainsString( 'wp_woocommerce_orders', $sanitized );
			$this->assertStringNotContainsString( 'wp_custom_crm_leads', $sanitized );
			$this->assertStringNotContainsString( 'wp_users', $sanitized );
			$this->assertStringNotContainsString( 'wp_secret_data', $sanitized );
		}
	}

	/**
	 * Sanitization redacts backticked identifiers and MySQL Table '...' references.
	 *
	 * @return void
	 */
	public function test_sanitize_redacts_table_identifiers() {
		$sanitized = MainWP_Backup_Error_Codes::sanitize_message( 'Duplicate entry in `wp_woocommerce_orders` detected.' );
		$this->assertStringNotContainsString( 'wp_woocommerce_orders', $sanitized );

		$sanitized = MainWP_Backup_Error_Codes::sanitize_message( "Table 'mydb.wp_custom_crm_leads' doesn't exist" );
		$this->assertStringNotContainsString( 'wp_custom_crm_leads', $sanitized );
	}

	/**
	 * Sanitization tolerates non-string and empty input.
	 *
	 * @return void
	 */
	public function test_sanitize_handles_non_string_input() {
		$this->assertSame( '', MainWP_Backup_Error_Codes::sanitize_message( null ) );
		$this->assertSame( '', MainWP_Backup_Error_Codes::sanitize_message( '' ) );
		$this->assertSame( '', MainWP_Backup_Error_Codes::sanitize_message( array( 'not', 'a', 'string' ) ) );
	}
}
