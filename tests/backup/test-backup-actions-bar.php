<?php
/**
 * Backup pages actions bar tests: page-level controls sit in the core
 * `.mainwp-actions-bar` above the table, the way core list pages place
 * them, and nowhere else on the page.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Page;
use MainWP\Dashboard\MainWP_Backup_Snapshots_View;
use MainWP\Dashboard\MainWP_Backup_Storage_View;
use MainWP\Dashboard\MainWP_Backup_Task_Form;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_System_View;

/**
 * Class MainWP_Backup_Actions_Bar_Test
 */
class MainWP_Backup_Actions_Bar_Test extends \WP_UnitTestCase {

	/**
	 * Tasks created by a test.
	 *
	 * @var int[]
	 */
	private $task_ids = array();

	/**
	 * Turn native backups on and log in an administrator.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'mainwp_enableNativeBackups', 1 );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Remove created tasks and the option.
	 */
	public function tear_down() {
		foreach ( $this->task_ids as $task_id ) {
			MainWP_DB_Backup::instance()->remove_backup_task( $task_id );
		}
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tear_down();
	}

	/**
	 * Render a callable's output.
	 *
	 * @param callable $render Page renderer.
	 *
	 * @return string
	 */
	private function render( callable $render ) {
		ob_start();
		try {
			$render();
		} finally {
			$output = ob_get_clean();
		}
		return $output;
	}

	/**
	 * Parse page markup. Inline scripts carry HTML fragments in JS strings,
	 * which the HTML parser would read as markup, so they are dropped first.
	 *
	 * @param string $html Page output.
	 *
	 * @return \DOMXPath
	 */
	private function xpath( $html ) {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return new \DOMXPath( $doc );
	}

	/**
	 * XPath test for a class token.
	 *
	 * @param string $token Class name.
	 *
	 * @return string
	 */
	private function has_class( $token ) {
		return "contains(concat(' ', normalize-space(@class), ' '), ' " . $token . " ')";
	}

	/**
	 * Assert the page has one actions bar, copied from the core markup, and
	 * that the element with the id sits once on the page, in the bar's left
	 * or right column.
	 *
	 * @param \DOMXPath $xp   Parsed page.
	 * @param string    $id   Element id.
	 * @param string    $side 'left' or 'right'.
	 */
	private function assert_in_bar( \DOMXPath $xp, $id, $side ) {
		$bars = $xp->query( '//div[' . $this->has_class( 'mainwp-actions-bar' ) . ']' );
		$this->assertSame( 1, $bars->length, 'The page renders one actions bar.' );

		$columns = $xp->query( './div[' . $this->has_class( 'form' ) . ']/div[' . $this->has_class( 'grid' ) . ']/div[' . $this->has_class( 'row' ) . ']/div[' . $this->has_class( 'column' ) . ']', $bars->item( 0 ) );
		$this->assertSame( 2, $columns->length, 'The bar holds a mini form with the core two column row.' );
		$this->assertStringNotContainsString( 'right aligned', $columns->item( 0 )->getAttribute( 'class' ) );
		$this->assertStringContainsString( 'right aligned middle aligned column', $columns->item( 1 )->getAttribute( 'class' ) );

		$this->assertSame( 1, $xp->query( '//*[@id="' . $id . '"]' )->length, $id . ' renders once.' );
		$column = $columns->item( 'left' === $side ? 0 : 1 );
		$this->assertSame( 1, $xp->query( './/*[@id="' . $id . '"]', $column )->length, $id . ' sits in the bar\'s ' . $side . ' column.' );
	}

	/**
	 * Snapshots & Restore: site selector and the removed-backups toggle on
	 * the left, Back Up Now and Clean Up on the right, the table below the
	 * bar in its own segment.
	 */
	public function test_snapshots_controls_sit_in_the_actions_bar() {
		$output = $this->render( array( MainWP_Backup_Snapshots_View::class, 'render' ) );
		$xp     = $this->xpath( $output );

		$this->assert_in_bar( $xp, 'mainwp-restore-site-selector', 'left' );
		$this->assert_in_bar( $xp, 'mainwp-snapshots-show-removed', 'left' );
		$this->assert_in_bar( $xp, 'mainwp-native-backup-now-btn', 'right' );
		$this->assert_in_bar( $xp, 'mainwp-native-backup-cancel-btn', 'right' );
		$this->assert_in_bar( $xp, 'mainwp-snapshots-cleanup-btn', 'right' );

		$this->assertSame( 0, $xp->query( '//div[@id="mainwp-backup-snapshots-toolbar"][not(' . $this->has_class( 'mainwp-actions-bar' ) . ')]' )->length, 'The old toolbar row is gone.' );
		$this->assertSame( 1, $xp->query( '//div[' . $this->has_class( 'mainwp-actions-bar' ) . ']/following-sibling::div[@id="mainwp-backup-snapshots"][' . $this->has_class( 'segment' ) . ']//table[@id="mainwp-restore-snapshots-table"]' )->length, 'The table sits in the segment after the bar.' );
		$this->assertStringNotContainsString( 'mainwp-native-backup-now-row', $output );
	}

	/**
	 * On the per-site Backups tab the bar already sits inside the surface's
	 * own segment, so it drops the theme's side margins and lines up with
	 * the tables below it. The standalone page keeps the core margins.
	 */
	public function test_per_site_bar_shares_the_table_edges() {
		$locked     = $this->render(
			function () {
				MainWP_Backup_Snapshots_View::render_restore_surface( 0, true );
			}
		);
		$standalone = $this->render( array( MainWP_Backup_Snapshots_View::class, 'render' ) );

		$this->assertStringContainsString( '<div class="mainwp-actions-bar" id="mainwp-backup-snapshots-toolbar" style="margin-left: 0 !important; margin-right: 0 !important;">', $locked );
		$this->assertStringContainsString( '<div class="mainwp-actions-bar" id="mainwp-backup-snapshots-toolbar">', $standalone );
	}

	/**
	 * The Created date and time stay on one line.
	 */
	public function test_created_cell_keeps_date_and_time_on_one_line() {
		$output = $this->render( array( MainWP_Backup_Snapshots_View::class, 'render' ) );

		$this->assertStringContainsString( "'<span style=\"white-space: nowrap;\">' + escAttr( snap.created_label || '' ) + '</span>'", $output );
	}

	/**
	 * With no site picked, the bar still holds Back Up Now and Clean Up,
	 * turned off.
	 */
	public function test_snapshots_bar_buttons_start_disabled_without_a_site() {
		$output = $this->render( array( MainWP_Backup_Snapshots_View::class, 'render' ) );

		$this->assertMatchesRegularExpression( '/class="ui mini green button disabled" id="mainwp-native-backup-now-btn"\s+disabled/', $output );
		$this->assertMatchesRegularExpression( '/class="ui mini basic button disabled" id="mainwp-snapshots-cleanup-btn"\s+disabled/', $output );
	}

	/**
	 * Manage Backups: Add New Task on the bar's left when tasks exist.
	 */
	public function test_manage_backups_add_new_task_sits_in_the_actions_bar() {
		$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Actions bar test task', 'daily', 'full', '', '', '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$this->task_ids[] = (int) $task->id;

		$output = $this->render( array( MainWP_Backup_Page::class, 'render_manager' ) );
		$xp     = $this->xpath( $output );

		$this->assert_in_bar( $xp, 'mainwp-backup-add-new-task', 'left' );
		$link = $xp->query( '//*[@id="mainwp-backup-add-new-task"]' )->item( 0 );
		$this->assertStringContainsString( 'page=ManageBackupsAddNew', $link->getAttribute( 'href' ) );
		$this->assertSame( 'ui mini green button', $link->getAttribute( 'class' ) );
		$this->assertSame( 1, $xp->query( '//div[' . $this->has_class( 'mainwp-actions-bar' ) . ']/following-sibling::div[' . $this->has_class( 'padded' ) . ']//table[@id="mainwp-backups-table"]' )->length, 'The task table sits in the segment after the bar.' );
	}

	/**
	 * An empty task list keeps its own first-step buttons and gets no bar,
	 * so Add New Task is not offered twice.
	 */
	public function test_manage_backups_empty_list_has_no_actions_bar() {
		$output = $this->render( array( MainWP_Backup_Page::class, 'render_manager' ) );

		$this->assertStringContainsString( 'id="mainwp-backup-tasks-empty"', $output );
		$this->assertStringNotContainsString( 'mainwp-actions-bar', $output );
		$this->assertStringNotContainsString( 'mainwp-backup-add-new-task', $output );
	}

	/**
	 * Storage: Add Destination on the bar's left, switching the form to
	 * add mode.
	 */
	public function test_storage_add_destination_sits_in_the_actions_bar() {
		$output = $this->render( array( MainWP_Backup_Storage_View::class, 'render' ) );
		$xp     = $this->xpath( $output );

		$this->assert_in_bar( $xp, 'mainwp-storage-add-new', 'left' );
		$this->assertSame( 'ui mini green button', $xp->query( '//*[@id="mainwp-storage-add-new"]' )->item( 0 )->getAttribute( 'class' ) );
		$this->assertSame( 1, $xp->query( '//div[' . $this->has_class( 'mainwp-actions-bar' ) . ']/following-sibling::div[@id="mainwp-backup-storage"][' . $this->has_class( 'padded' ) . ']//table[@id="mainwp-storage-destinations-table"]' )->length, 'The destinations table sits in the segment after the bar.' );
		$this->assertMatchesRegularExpression( "/#mainwp-storage-add-new' \\)\\.on\\( 'click'[^}]*resetForm\\(\\)/s", $output );
	}

	/**
	 * Clean Up's confirmation says it also removes the snapshots a deleted
	 * task left behind, remote copies included, and the result reports
	 * them with the space freed.
	 */
	public function test_cleanup_confirmation_mentions_deleted_task_snapshots() {
		$output = $this->render( array( MainWP_Backup_Snapshots_View::class, 'render' ) );

		$this->assertStringContainsString( 'Clean Up also deletes snapshots whose backup task was deleted, including their copies in remote storage.', $output );
		$this->assertStringContainsString( 'response.orphaned_snapshots_pruned', $output );
		$this->assertStringContainsString( '%s of them belonged to deleted backup tasks.', $output );
		$this->assertStringContainsString( "'Space freed: %s.'.replace( '%s', String( response.bytes_pruned_label ) )", $output );
	}

	/**
	 * The delete-task confirmation strings reach the browser translated.
	 */
	public function test_delete_task_confirmation_strings_are_registered() {
		$translations = MainWP_System_View::get_mainwp_translations();

		$this->assertSame( 'Are you sure you want to delete this backup task?', $translations['Are_you_sure_you_want_to_delete_this_backup_task_'] );
		$this->assertSame( 'Also delete this task&#039;s snapshots', $translations['Also_delete_this_task_s_snapshots'] );
		$this->assertSame( 'Unticked, the snapshots stay in Snapshots &amp; Restore until you delete them with Clean Up.', $translations['Unticked__the_snapshots_stay_in_Snapshots___Restore_until_you_delete_them_with_Clean_Up_'] );
	}

	/**
	 * The Keep Zip help sits on its own line under the toggle.
	 */
	public function test_keep_zip_help_sits_under_its_toggle() {
		$output = $this->render( array( MainWP_Backup_Task_Form::class, 'render_new' ) );

		$this->assertMatchesRegularExpression( '#<div><em>A large site on Zip is switched to Tar GZip#', $output );
	}

	/**
	 * The Test Restore label and date never break inside, and the table
	 * scrolls inside the page when its columns cannot fit.
	 */
	public function test_test_restore_cell_keeps_label_and_date_unbroken() {
		$output = $this->render( array( MainWP_Backup_Snapshots_View::class, 'render' ) );

		$this->assertStringContainsString( "' <span style=\"white-space: nowrap;\">' + escAttr( snap.test_restore_label ) + '</span>'", $output );
		$this->assertStringContainsString( '<span class="ui mini green label" style="white-space: nowrap;"><i class="check icon"></i>', $output );
		$this->assertStringContainsString( '"scrollX": true,', $output );
	}
}
