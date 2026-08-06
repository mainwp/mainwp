<?php
/**
 * MWP-1656 site-removal regression tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MWP_1656_Site_Removal
 */
class Test_MWP_1656_Site_Removal extends \WP_UnitTestCase {

	/**
	 * Ensure the PHP registry uses the same keys as MainWP.I18n.t().
	 */
	public function test_removal_messages_use_javascript_compatible_translation_keys(): void {
		$translations = \MainWP\Dashboard\MainWP_System_View::get_mainwp_translations();

		$messages = array(
			'Removing_the_site__Please_wait___' => 'Removing the site. Please wait...',
			'Site_could_not_be_removed__Please_reload_the_page_and_try_again_' => 'Site could not be removed. Please reload the page and try again.',
			'Details_' => 'Details:',
			'The_site_has_been_removed__Please_make_sure_that_the_MainWP_Child_plugin_has_been_deactivated_properly__You_will_be_redirected_to_the_Sites_page_right_away_' => 'The site has been removed. Please make sure that the MainWP Child plugin has been deactivated properly. You will be redirected to the Sites page right away.',
			'The_site_has_been_removed_and_the_MainWP_Child_plugin_has_been_disabled__You_will_be_redirected_to_the_Sites_page_right_away_' => 'The site has been removed and the MainWP Child plugin has been disabled. You will be redirected to the Sites page right away.',
			'The_site_has_been_removed__You_will_be_redirected_to_the_Sites_page_right_away_' => 'The site has been removed. You will be redirected to the Sites page right away.',
			'The_request_timed_out__Please_make_sure_that_the_MainWP_Child_plugin_has_been_deactivated_properly_' => 'The request timed out. Please make sure that the MainWP Child plugin has been deactivated properly.',
			'An_unexpected_error_occurred__Please_reload_the_page_and_try_again_' => 'An unexpected error occurred. Please reload the page and try again.',
		);

		foreach ( $messages as $key => $message ) {
			$this->assertArrayHasKey( $key, $translations, 'Missing JavaScript-compatible translation key for: ' . $message );
			$this->assertSame( $message, $translations[ $key ], 'Incorrect English source registered for: ' . $message );
		}
	}
}
