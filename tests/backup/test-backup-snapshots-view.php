<?php
/**
 * Snapshots view tests: the cancel window surfaced to the user matches
 * the orchestrator's, including cancel while the restore runs on the
 * site.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots_View;

/**
 * Class MainWP_Backup_Snapshots_View_Test
 */
class MainWP_Backup_Snapshots_View_Test extends \WP_UnitTestCase {

	/**
	 * Render the page as an administrator.
	 *
	 * @return string Page output.
	 */
	private function render_page() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		MainWP_Backup_Snapshots_View::render();
		return ob_get_clean();
	}

	/**
	 * The cancel button stays available while the restore runs on the
	 * site - the child-side cancel makes that window safe to offer.
	 *
	 * @return void
	 */
	public function test_cancel_button_offered_while_running() {
		$output = $this->render_page();

		$this->assertMatchesRegularExpression(
			"/#mainwp-restore-cancel-btn'\s*\)\.toggle\([^;]*'running'/s",
			$output,
			'The cancel button must be offered during the running status.'
		);
	}

	/**
	 * Cancelling mid-restore needs a warning that the site may be left
	 * partially modified.
	 *
	 * @return void
	 */
	public function test_running_cancel_shows_warning() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-restore-cancel-warning', $output );
	}

	/**
	 * A cancel deferred to the cron lane (busy child) is surfaced: the
	 * progress poll carries cancel_requested and the page reacts to it
	 * instead of leaving the user staring at a running status.
	 *
	 * @return void
	 */
	public function test_pending_cancel_state_surfaces() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'cancel_requested', $output );
	}

	/**
	 * The cancel warning promises a rollback stays available, so a
	 * cancelled restore that left a safety backup must offer the
	 * rollback button - not only a failed one.
	 *
	 * @return void
	 */
	public function test_rollback_offered_after_cancel() {
		$output = $this->render_page();

		$this->assertMatchesRegularExpression(
			"/#mainwp-restore-rollback-btn'\s*\)\.toggle\([^;]*'cancelled'/s",
			$output,
			'The rollback button must be offered for cancelled restores with a safety backup.'
		);
	}

	/**
	 * The Restore button carries the snapshot type, so the confirm pane
	 * can speak honestly about what a files-only restore replaces.
	 *
	 * @return void
	 */
	public function test_restore_button_carries_snapshot_type() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'data-snapshot-type', $output );
	}

	/**
	 * A files snapshot's confirm pane says the database stays untouched;
	 * the page carries the files-only warning copy for the swap.
	 *
	 * @return void
	 */
	public function test_files_only_consent_copy_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'The database will not be changed', $output );
	}

	/**
	 * Disabled rows explain themselves: the list renderer puts the
	 * server's restore_reason into the row's title attribute, escaped.
	 *
	 * @return void
	 */
	public function test_disabled_rows_carry_restore_reason_tooltip() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'snap.restore_reason', $output );
		$this->assertMatchesRegularExpression(
			'/title="[^"]*escAttr\( snap\.restore_reason \)/s',
			$output,
			'The restore_reason tooltip must pass through the attribute escaper.'
		);
	}

	/**
	 * A multisite child's confirm pane warns the restore is
	 * network-wide, driven by the multisite flag in the list payload.
	 *
	 * @return void
	 */
	public function test_multisite_confirm_carries_network_warning() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'every site in the network', $output );
		$this->assertStringContainsString( 'response.multisite', $output );
	}
}
