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
	 * An encryption refusal must reach the UI with its dedicated placeholder.
	 */
	public function test_b151_encryption_refusal_is_registered() {
		$code = 'mainwp_backup_encryption_required';
		$this->assertContains( $code, MainWP_Backup_Error_Codes::get_registered_codes() );
		$this->assertSame( 'Placeholder: this backup was created with encryption on, but it could not be encrypted for upload, so it was not uploaded.', MainWP_Backup_Error_Codes::message( $code ) );
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( $code ) );
	}

	/**
	 * Child dump failures need distinct messages instead of the fallback.
	 *
	 * @dataProvider database_dump_failure_codes
	 * @param string $code Child error code.
	 * @param string $message Child failure message.
	 */
	public function test_b312_database_dump_failure_message( $code, $message ) {
		$actual = MainWP_Backup_Error_Codes::message( $code );
		$this->assertSame( $message, $actual );
		$this->assertNotSame( MainWP_Backup_Error_Codes::message( 'unknown_b312_code' ), $actual );
		$this->assertContains( $code, MainWP_Backup_Error_Codes::get_registered_codes() );
	}

	/**
	 * Use the Child's text until the Dashboard copy is finalized.
	 *
	 * @return array Dump failure codes and messages.
	 */
	public static function database_dump_failure_codes() {
		return array(
			'write'  => array( 'mainwp_backup_db_write_failed', 'The database dump could not be written. Check the child site error log and try again.' ),
			'rename' => array( 'mainwp_backup_db_rename_failed', 'The database dump could not be finalized. Check the child site error log and try again.' ),
		);
	}

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
		'mainwp_backup_db_write_failed',
		'mainwp_backup_db_rename_failed',
		'mainwp_backup_file_corrupt',
		'mainwp_backup_file_too_small',
		'mainwp_backup_zip_failed',
		'mainwp_backup_tar_write_failed',
		'mainwp_backup_file_skipped',
		'mainwp_backup_too_many_skipped',
		'mainwp_backup_stalled',
		'mainwp_backup_already_running',
		'mainwp_backup_mutex_write_failed',
		'mainwp_backup_dl_file_not_found',
		'mainwp_backup_dl_file_changed',
		'mainwp_backup_dl_token_invalid',
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
		'mainwp_backup_hash_mismatch',
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
	 * The hash mismatch constant exists and is error severity.
	 *
	 * @return void
	 */
	public function test_hash_mismatch_code_is_error_severity() {
		$this->assertSame( 'mainwp_backup_hash_mismatch', MainWP_Backup_Error_Codes::ERR_HASH_MISMATCH );
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( MainWP_Backup_Error_Codes::ERR_HASH_MISMATCH ) );
	}

	/**
	 * A mutex write failure has its own message and error severity.
	 *
	 * @return void
	 */
	public function test_mutex_write_failed_code_is_registered_with_message() {
		$this->assertSame( 'mainwp_backup_mutex_write_failed', MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED );
		$this->assertContains( MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED, MainWP_Backup_Error_Codes::get_registered_codes() );
		$this->assertSame(
			'The site could not write its backup lock to the database. The database may be busy or unreachable. Try again in a few minutes.',
			MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED )
		);
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED ) );
	}

	/**
	 * The child-update gate has a registered terminal code and its signed-off copy.
	 *
	 * @return void
	 */
	public function test_child_update_required_code_is_registered_with_signed_off_message() {
		$this->assertSame( 'mainwp_backup_child_update_required', MainWP_Backup_Error_Codes::ERR_CHILD_UPDATE_REQUIRED );
		$this->assertContains( MainWP_Backup_Error_Codes::ERR_CHILD_UPDATE_REQUIRED, MainWP_Backup_Error_Codes::get_registered_codes() );
		$this->assertSame(
			'The MainWP Child plugin on this site is too old to run backups. Update MainWP Child on the site, then sync it to run backups again.',
			MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_CHILD_UPDATE_REQUIRED )
		);
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( MainWP_Backup_Error_Codes::ERR_CHILD_UPDATE_REQUIRED ) );
	}

	/** Child readiness failures have a registered message and error severity. */
	public function test_child_preflight_failed_code_is_registered_with_message() {
		$this->assertSame( 'mainwp_backup_child_preflight_failed', MainWP_Backup_Error_Codes::ERR_CHILD_PREFLIGHT_FAILED );
		$this->assertContains( MainWP_Backup_Error_Codes::ERR_CHILD_PREFLIGHT_FAILED, MainWP_Backup_Error_Codes::get_registered_codes() );
		$this->assertSame(
			'The child site could not get ready for a backup. Fix the problem it reported on the site, then try again.',
			MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_CHILD_PREFLIGHT_FAILED )
		);
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( MainWP_Backup_Error_Codes::ERR_CHILD_PREFLIGHT_FAILED ) );
	}

	/**
	 * The incremental backup codes are registered: the child reports a
	 * missing or unusable parent manifest (the dashboard reruns the site as
	 * a full backup) and an oversized manifest push.
	 *
	 * @return void
	 */
	public function test_registers_incremental_error_codes() {
		$registered = MainWP_Backup_Error_Codes::get_registered_codes();
		$this->assertContains( 'mainwp_backup_incremental_base_missing', $registered );
		$this->assertContains( 'mainwp_backup_manifest_too_large', $registered );
		$this->assertSame( 'mainwp_backup_incremental_base_missing', MainWP_Backup_Error_Codes::ERR_INCREMENTAL_BASE_MISSING );
		$this->assertSame( 'mainwp_backup_manifest_too_large', MainWP_Backup_Error_Codes::ERR_MANIFEST_TOO_LARGE );
	}

	/**
	 * The manifest chunk transfer codes are registered: the two the child
	 * reports during staging and the dashboard-side send failure that
	 * falls the run back to a full backup.
	 *
	 * @return void
	 */
	public function test_registers_manifest_chunk_error_codes() {
		$registered = MainWP_Backup_Error_Codes::get_registered_codes();
		$this->assertContains( 'mainwp_backup_manifest_chunk_wrong_phase', $registered );
		$this->assertContains( 'mainwp_backup_manifest_chunk_corrupt', $registered );
		$this->assertContains( 'mainwp_backup_manifest_chunk_send_failed', $registered );
		$this->assertSame( 'mainwp_backup_manifest_chunk_send_failed', MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED );
		$this->assertSame( 'warning', MainWP_Backup_Error_Codes::severity( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED ), 'The send failure falls back to a full backup; the run still succeeds.' );
	}

	/**
	 * The wall-clock timeout and unknown-phase codes are registered and are
	 * error severity. The child emits both on failures that previously
	 * reached the dashboard as a bare "failed" with no reason or code.
	 *
	 * @return void
	 */
	public function test_registers_silent_failure_codes() {
		$registered = MainWP_Backup_Error_Codes::get_registered_codes();
		$this->assertContains( 'mainwp_backup_wall_clock_exceeded', $registered );
		$this->assertContains( 'mainwp_backup_unknown_phase', $registered );
		$this->assertSame( 'mainwp_backup_wall_clock_exceeded', MainWP_Backup_Error_Codes::ERR_WALL_CLOCK );
		$this->assertSame( 'mainwp_backup_unknown_phase', MainWP_Backup_Error_Codes::ERR_UNKNOWN_PHASE );
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( MainWP_Backup_Error_Codes::ERR_WALL_CLOCK ) );
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( MainWP_Backup_Error_Codes::ERR_UNKNOWN_PHASE ) );
	}

	/**
	 * The Google Cloud Storage upload code is registered and is error
	 * severity, alongside the other provider upload codes.
	 *
	 * @return void
	 */
	public function test_registers_gcs_error_code() {
		$registered = MainWP_Backup_Error_Codes::get_registered_codes();
		$this->assertContains( 'mainwp_backup_gcs_failed', $registered );
		$this->assertSame( 'mainwp_backup_gcs_failed', MainWP_Backup_Error_Codes::ERR_GCS_FAILED );
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( MainWP_Backup_Error_Codes::ERR_GCS_FAILED ) );
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
			'mainwp_backup_incremental_base_missing',
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
	 * The exception factory builds a MainWP_Exception carrying the code and message.
	 *
	 * @return void
	 */
	public function test_exception_factory_builds_coded_exception() {
		$exception = MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RETRY_EXHAUSTED );

		$this->assertInstanceOf( \MainWP\Dashboard\MainWP_Exception::class, $exception );
		$this->assertSame( 'mainwp_backup_retry_exhausted', $exception->get_message_error_code() );
		$this->assertSame( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_RETRY_EXHAUSTED ), $exception->getMessage() );
	}

	/**
	 * Sanitization redacts the per-site backup directory hash.
	 *
	 * The Child names its backup directory backup-<16 alphanumerics>; on hosts
	 * without Apache that name is the only thing keeping archives off a
	 * predictable URL, and check_dir() puts it into its error text.
	 *
	 * @return void
	 */
	public function test_sanitize_redacts_backup_dir_hash() {
		$cases = array(
			'check_dir message, trailing slash' => array(
				'Unable to create directory wp-content/uploads/mainwp/backup-tnQjEHx99TA2ZYTh/. Is its parent directory writable by the server?',
				'Unable to create directory wp-content/uploads/mainwp/backup-[redacted]/. Is its parent directory writable by the server?',
			),
			'hash at the end of the message'    => array(
				'Missing directory wp-content/uploads/mainwp/backup-AbCdEfGhIjKlMnOp',
				'Missing directory wp-content/uploads/mainwp/backup-[redacted]',
			),
			'absolute path with the hash'       => array(
				'Could not open ' . ABSPATH . 'wp-content/uploads/mainwp/backup-0123456789abcdef/x.zip',
				'Could not open wp-content/uploads/mainwp/backup-[redacted]/x.zip',
			),
			'two hashes'                        => array(
				'backup-tnQjEHx99TA2ZYTh and backup-AbCdEfGhIjKlMnOp',
				'backup-[redacted] and backup-[redacted]',
			),
			'short token stays'                 => array(
				'Unable to create directory wp-content/uploads/mainwp/backup-x/.',
				'Unable to create directory wp-content/uploads/mainwp/backup-x/.',
			),
			'15 characters stays'               => array(
				'backup-tnQjEHx99TA2ZYT/',
				'backup-tnQjEHx99TA2ZYT/',
			),
			'17 characters stays'               => array(
				'backup-tnQjEHx99TA2ZYThZ/',
				'backup-tnQjEHx99TA2ZYThZ/',
			),
			'archive name stays'                => array(
				'Deleted backup-example.com-20260927-1200.tar.gz',
				'Deleted backup-example.com-20260927-1200.tar.gz',
			),
			'word prefix does not count'        => array(
				'prebackup-tnQjEHx99TA2ZYTh/',
				'prebackup-tnQjEHx99TA2ZYTh/',
			),
		);
		foreach ( $cases as $label => $case ) {
			$this->assertSame( $case[1], MainWP_Backup_Error_Codes::sanitize_message( $case[0] ), $label );
		}
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
