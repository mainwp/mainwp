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
use MainWP\Dashboard\MainWP_Backup_TestRestore_Scheduler;

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
	 * The confirm modal carries the keep-orders opt-in, its helper text, and
	 * posts the preserve_orders field.
	 */
	public function test_preserve_orders_checkbox_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-restore-preserve-orders-box', $output );
		$this->assertStringContainsString( 'Preserve orders placed since the backup', $output );
		$this->assertStringContainsString( 'preserve_orders:', $output );
	}

	/**
	 * The opt-in is hidden until the snapshot click handler shows it, so a
	 * non-WooCommerce or files-only restore never sees it.
	 */
	public function test_preserve_orders_box_hidden_by_default() {
		$output = $this->render_page();

		$this->assertMatchesRegularExpression( '/id="mainwp-restore-preserve-orders-box"[^>]*style="display:none;"/', $output );
	}

	/**
	 * The opt-in is shown only for a WooCommerce store restoring a
	 * database-bearing snapshot.
	 */
	public function test_preserve_orders_toggle_gated_on_woocommerce() {
		$output = $this->render_page();

		$this->assertStringContainsString( "siteHasWooCommerce && 'files' !== snapType", $output );
		$this->assertStringContainsString( "#mainwp-restore-preserve-orders-box' ).toggle( showPreserve )", $output );
	}

	/**
	 * A completed preserve restore reports its outcome: the note element and
	 * the function that fills it from the verification payload are present.
	 */
	public function test_preserve_outcome_note_rendered() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-restore-preserve-note', $output );
		$this->assertStringContainsString( 'function showPreserveNote(', $output );
		$this->assertStringContainsString( 'verification.woo_preserve', $output );
		$this->assertStringContainsString( 'orphan_customer_orders', $output );
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

	/**
	 * A WooCommerce store restoring an aged backup gets a strong order-loss
	 * warning, driven by the flag and age in the list payload.
	 *
	 * @return void
	 */
	public function test_woocommerce_age_warning_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'response.site_has_woocommerce', $output );
		$this->assertStringContainsString( 'removes any orders and customers', $output );
		$this->assertStringContainsString( 'data-snapshot-age', $output );
	}

	/**
	 * The destructive restore sends the consent flag the server now
	 * requires, and the page recovers when the server asks for it.
	 *
	 * @return void
	 */
	public function test_restore_sends_and_handles_confirm_destructive() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'confirm_destructive', $output );
		$this->assertStringContainsString( 'response.needs_confirmation', $output );
	}

	/**
	 * A database-only restore gets accurate copy: it replaces the database
	 * and leaves files untouched, rather than falling through to the
	 * files-and-database default (M3).
	 *
	 * @return void
	 */
	public function test_db_restore_warning_copy_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'replaces the site', $output );
		$this->assertStringContainsString( 'Files are not changed', $output, 'A db restore must say files are not changed.' );
		$this->assertMatchesRegularExpression( "/restoreWarnings\s*=\s*\{[^}]*'db'\s*:/s", $output, 'The db warning key must exist.' );
		$this->assertMatchesRegularExpression( "/'incremental'\s*:/s", $output, 'The incremental warning key must exist.' );
	}

	/**
	 * The preflight status has a friendly label instead of the raw token (M4).
	 *
	 * @return void
	 */
	public function test_preflight_status_label_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'Checking the site is ready', $output );
		$this->assertStringContainsString( '"preflight"', $output, 'The preflight key must be in the serialised status map.' );
	}

	/**
	 * The snapshot list carries a Size column header and renders the
	 * server-formatted size per row (M2).
	 *
	 * @return void
	 */
	public function test_size_column_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( '>Size</th>', $output, 'The snapshot table must have a Size header.' );
		$this->assertStringContainsString( 'snap.size_label', $output, 'The list must render the per-snapshot size.' );
	}

	/**
	 * The modal is initialised once and shown behind an is-active guard, so
	 * a re-open does not collide with an in-flight transition (M5).
	 *
	 * @return void
	 */
	public function test_modal_show_is_guarded() {
		$output = $this->render_page();

		$this->assertStringContainsString( "modal( 'is active' )", $output, 'The show must be guarded by an is-active check.' );
		$this->assertStringNotContainsString( ".modal( { closable: false } ).modal( 'show' )", $output, 'The re-init-on-every-open chain must be gone.' );
	}

	/**
	 * The restore modal shows a phase progress bar across the known
	 * sequence (L3).
	 *
	 * @return void
	 */
	public function test_progress_bar_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-restore-progress-bar', $output );
		$this->assertStringContainsString( 'phaseOrder', $output );
	}

	/**
	 * The safety backup is surfaced when one exists for the run (L4).
	 *
	 * @return void
	 */
	public function test_safety_backup_note_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-restore-safety-note', $output );
		$this->assertStringContainsString( 'A safety backup was taken', $output );
	}

	/**
	 * The completed state offers a Visit-site link, driven by the site URL
	 * in the list payload (L5).
	 *
	 * @return void
	 */
	public function test_visit_site_link_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-restore-visit-site', $output );
		$this->assertStringContainsString( 'response.site_url', $output );
	}

	/**
	 * With native backups enabled, the restore surface offers an on-demand
	 * "Back up now" button wired to the native backup endpoints, and the
	 * completion path refreshes the snapshot list rather than reloading.
	 *
	 * @return void
	 */
	public function test_back_up_now_button_and_poll_present() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-native-backup-now-btn', $output, 'The Back up now button must render under native backups.' );
		$this->assertStringContainsString( 'mainwp_native_backup_initiate', $output );
		$this->assertStringContainsString( 'mainwp_native_backup_progress', $output );
		$this->assertStringContainsString( 'function pollBackup', $output, 'The poll loop must be present.' );
		$this->assertMatchesRegularExpression(
			"/'completed' === data\.status[^}]*loadSnapshots\( currentSite \)/s",
			$output,
			'A completed backup must refresh the list in place, not reload.'
		);

		delete_option( 'mainwp_enableNativeBackups' );
	}

	/**
	 * With native backups enabled, the restore surface offers a "Cancel backup"
	 * affordance wired to the native cancel endpoint, so a stuck backup that no
	 * longer self-heals can be cleared from the dashboard.
	 *
	 * @return void
	 */
	public function test_cancel_backup_button_and_endpoint_present() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-native-backup-cancel-btn', $output, 'The Cancel backup button must render under native backups.' );
		$this->assertStringContainsString( 'mainwp_native_backup_cancel', $output, 'The cancel endpoint must be wired.' );
		$this->assertStringContainsString( 'nativeBackupCancel', $output, 'The cancel nonce must be passed to the page.' );
		$this->assertStringContainsString( 'function showCancelBackup', $output, 'The cancel toggle helper must be present.' );

		delete_option( 'mainwp_enableNativeBackups' );
	}

	/**
	 * A restore that failed but rolled the database back reassures the user
	 * their data is safe, keyed off the child's rolled-back error code rather
	 * than the raw error text.
	 *
	 * @return void
	 */
	public function test_rolled_back_outcome_message_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'restoreOutcomeMessages', $output, 'The outcome message map must be present.' );
		$this->assertStringContainsString( 'mainwp_restore_rolled_back', $output, 'The rolled-back code must be handled.' );
		$this->assertMatchesRegularExpression(
			"/data\.error_code/s",
			$output,
			'The terminal branch must read the error code.'
		);
	}

	/**
	 * A rollback that could not finish surfaces a maintenance-mode notice and
	 * branches on the rollback-failed code.
	 *
	 * @return void
	 */
	public function test_rollback_failed_maintenance_notice_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'mainwp-restore-maintenance-note', $output, 'The maintenance notice element must render.' );
		$this->assertStringContainsString( 'mainwp_restore_rollback_failed', $output, 'The rollback-failed code must be handled.' );
	}

	/**
	 * The snapshot list carries a Test Restore column.
	 *
	 * @return void
	 */
	public function test_test_restore_column_header_present() {
		$output = $this->render_page();

		$this->assertStringContainsString( '>Test Restore</th>', $output, 'The snapshot table must have a Test Restore header.' );
	}

	/**
	 * The list renders each test-restore state distinctly: a green pass with a
	 * date, a red fail, a neutral couldn't-test and artifacts-unavailable, and a
	 * dash when never tested.
	 *
	 * @return void
	 */
	public function test_test_restore_states_render_distinctly() {
		$output = $this->render_page();

		$this->assertStringContainsString( 'snap.test_restore_state', $output, 'The list must read the per-snapshot test-restore state.' );
		$this->assertMatchesRegularExpression( "/'pass' === snap\.test_restore_state/s", $output, 'A pass must be handled.' );
		$this->assertMatchesRegularExpression( "/'fail' === snap\.test_restore_state/s", $output, 'A fail must be handled.' );
		$this->assertMatchesRegularExpression( "/'unsupported' === snap\.test_restore_state/s", $output, 'An unsupported host must be handled.' );
		$this->assertMatchesRegularExpression( "/'unavailable' === snap\.test_restore_state/s", $output, 'An unavailable snapshot must be handled.' );
		$this->assertMatchesRegularExpression( "/ui mini green label[^']*check icon/s", $output, 'A pass must render a green checked label.' );
		$this->assertStringContainsString( 'ui mini red label', $output, 'A fail must render a red label.' );
		$this->assertMatchesRegularExpression( "/snap\.test_restore_at \* 1000/s", $output, 'A pass must show the test-restore date.' );
	}

	/**
	 * When the host cannot host a sandbox, the standalone page shows the
	 * operator notice; when it can, it does not.
	 *
	 * @return void
	 */
	public function test_capability_notice_rendered_only_when_incapable() {
		update_option(
			MainWP_Backup_TestRestore_Scheduler::CAPABILITY_OPTION,
			array(
				'capable'    => false,
				'reason'     => 'Sandbox needs the CREATE privilege here',
				'checked_at' => time(),
			),
			false
		);
		$incapable = $this->render_page();
		$this->assertStringContainsString( 'Sandbox needs the CREATE privilege here', $incapable, 'An incapable host surfaces the operator notice.' );

		update_option(
			MainWP_Backup_TestRestore_Scheduler::CAPABILITY_OPTION,
			array(
				'capable'    => true,
				'reason'     => '',
				'checked_at' => time(),
			),
			false
		);
		$capable = $this->render_page();
		$this->assertStringNotContainsString( 'Sandbox needs the CREATE privilege here', $capable, 'A capable host shows no notice.' );

		delete_option( MainWP_Backup_TestRestore_Scheduler::CAPABILITY_OPTION );
	}
}
