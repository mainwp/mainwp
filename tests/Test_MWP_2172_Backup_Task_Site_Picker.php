<?php
/**
 * The backup task editor's site picker keeps the saved selection across tabs.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Task_Form;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_UI_Select_Sites;

/**
 * Class Test_MWP_2172_Backup_Task_Site_Picker
 */
class Test_MWP_2172_Backup_Task_Site_Picker extends \WP_UnitTestCase {

	use Site_Fixture;

	/** Sign in as a Dashboard administrator. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Sign out before the next test. */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Other pages keep the picker that clears the other tabs when a tab opens. */
	public function test_picker_keeps_tab_clearing_by_default(): void {
		$html = $this->render_picker( array() );

		$this->assertStringContainsString( '<div id="mainwp-select-sites" class="mainwp_select_sites_wrapper">', $html );
		$this->assertStringNotContainsString( 'data-keep-tab-selection', $html );
	}

	/** The opt-in marks the picker so its tabs keep the selection. */
	public function test_picker_marks_wrapper_when_selection_is_kept(): void {
		$html = $this->render_picker( array( 'keep_selection_on_tab_change' => true ) );

		$this->assertStringContainsString( '<div id="mainwp-select-sites" class="mainwp_select_sites_wrapper" data-keep-tab-selection="1">', $html );
		$this->assertSame( 1, substr_count( $html, 'data-keep-tab-selection' ) );
	}

	/** Editing a task opens its saved sites checked in a picker that keeps them across tabs. */
	public function test_backup_task_editor_keeps_saved_sites_across_tabs(): void {
		$site_ids = array( $this->create_test_site(), $this->create_test_site(), $this->create_test_site() );
		$task     = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'MWP-2172 picker task', 'daily', 'full', '', implode( ',', $site_ids ), '', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0 );
		$this->assertIsObject( $task );

		ob_start();
		MainWP_Backup_Task_Form::render_new_edit( $task );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'class="mainwp_select_sites_wrapper" data-keep-tab-selection="1"', $html );
		$this->assertMatchesRegularExpression( '/<input type="hidden" name="select_by" value="site"/', $html );
		foreach ( $site_ids as $site_id ) {
			$this->assertMatchesRegularExpression( '/<input\s+type="checkbox" name="selected_sites\[\]" siteid="' . $site_id . '"[^>]*checked="true"/', $html );
		}
	}

	/**
	 * Render the Select Sites box.
	 *
	 * @param array $params Picker parameters.
	 * @return string
	 */
	private function render_picker( array $params ): string {
		ob_start();
		MainWP_UI_Select_Sites::select_sites_box( $params );
		return (string) ob_get_clean();
	}
}
