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
}
