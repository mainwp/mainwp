<?php
/**
 * Base test case for MainWP Dashboard.
 *
 * @package Mainwp
 * @author  MainWP
 */

abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Emulate deactivating, then subsequently reactivating the plugin.
	 */
	protected static function reactivate_plugin() {
		$plugin = basename( dirname( __DIR__ ) ) . '/mainwp.php';

		do_action( 'deactivate_' . $plugin, false );
		do_action( 'activate_' . $plugin, false );
	}
}
