<?php
/**
 * MainWP Backup Error Formatting Tests
 *
 * Tests for backup error accumulation formatting (truncation, numbered list,
 * sanitization) and for the removal of vague backup error messages.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Handler;

/**
 * Class MainWP_Backup_Error_Formatting_Test
 */
class MainWP_Backup_Error_Formatting_Test extends \WP_UnitTestCase {

	/**
	 * Errors are capped at the first 10 with a count of the rest.
	 *
	 * @return void
	 */
	public function test_format_backup_errors_truncates_to_first_ten() {
		$entries = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$entries[] = "Backup error number {$i} occurred.";
		}

		$output = MainWP_Backup_Handler::format_backup_errors( $entries );

		$this->assertStringContainsString( 'Backup error number 1 occurred.', $output );
		$this->assertStringContainsString( 'Backup error number 10 occurred.', $output );
		$this->assertStringNotContainsString( 'Backup error number 11', $output );
		$this->assertStringNotContainsString( 'Backup error number 12', $output );
		$this->assertStringContainsString( 'and 2 more errors', $output );
	}

	/**
	 * Ten or fewer errors are kept verbatim with no truncation notice.
	 *
	 * @return void
	 */
	public function test_format_backup_errors_no_truncation_under_limit() {
		$entries = array( 'First error.', 'Second error.' );

		$output = MainWP_Backup_Handler::format_backup_errors( $entries );

		$this->assertStringContainsString( 'First error.', $output );
		$this->assertStringContainsString( 'Second error.', $output );
		$this->assertStringNotContainsString( 'more errors', $output );
	}

	/**
	 * Output is a numbered list, not raw <br /> concatenation.
	 *
	 * @return void
	 */
	public function test_format_backup_errors_uses_numbered_list() {
		$output = MainWP_Backup_Handler::format_backup_errors( array( 'Only error.' ) );

		$this->assertStringContainsString( '<ol>', $output );
		$this->assertStringContainsString( '<li>', $output );
	}

	/**
	 * Entries are sanitized: absolute paths and SQL fragments are stripped.
	 *
	 * @return void
	 */
	public function test_format_backup_errors_sanitizes_entries() {
		$entries = array(
			'Could not open ' . ABSPATH . 'wp-content/backup.zip for writing.',
			'Query failed: SELECT * FROM wp_woocommerce_orders WHERE id > 5',
		);

		$output = MainWP_Backup_Handler::format_backup_errors( $entries );

		$this->assertStringNotContainsString( ABSPATH, $output );
		$this->assertStringNotContainsString( 'wp_woocommerce_orders', $output );
	}

	/**
	 * An empty entry list produces empty output.
	 *
	 * @return void
	 */
	public function test_format_backup_errors_empty_input() {
		$this->assertSame( '', MainWP_Backup_Handler::format_backup_errors( array() ) );
	}

	/**
	 * The backup handler no longer contains vague error messages.
	 *
	 * @return void
	 */
	public function test_backup_handler_source_has_no_vague_messages() {
		$source = file_get_contents( MAINWP_PLUGIN_DIR . 'class/class-mainwp-backup-handler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads plugin source.

		$vague = array(
			"'Undefined error.'",
			"'Backup failed.'",
			"'Backup failed after 5 retries.'",
			"'Database backup failed.'",
			"'Full backup failed.'",
			"'Database backup failed due to an undefined error.'",
			"'Database backup failed due to an undefined error'",
		);

		foreach ( $vague as $literal ) {
			$this->assertStringNotContainsString( $literal, $source, "Vague message {$literal} must be replaced with actionable text." );
		}
	}

	/**
	 * The backup task save paths no longer report an undefined error.
	 *
	 * @return void
	 */
	public function test_backup_task_save_source_has_no_vague_messages() {
		$source = file_get_contents( MAINWP_PLUGIN_DIR . 'pages/page-mainwp-manage-backups-handler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads plugin source.

		$this->assertStringNotContainsString( 'Undefined error occurred. Please try again.', $source, 'Backup task save errors must explain what to check.' );
	}
}
