<?php
/**
 * Restore destructive-warning tests: WooCommerce detection from the synced
 * plugin list, and the server-side confirmation gate that a direct AJAX
 * call cannot skip.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class MainWP_Backup_Restore_Warning_Test
 */
class MainWP_Backup_Restore_Warning_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Create a child site row with a given synced plugin list JSON.
	 *
	 * @param string $plugins_json Raw plugins column JSON.
	 *
	 * @return int Site id.
	 */
	private function make_site_with_plugins( $plugins_json ) {
		return $this->create_test_site( array( 'plugins' => $plugins_json ) );
	}

	// ---- site_has_woocommerce() ----

	/**
	 * Active WooCommerce in the synced list reads as present.
	 *
	 * @return void
	 */
	public function test_site_has_woocommerce_true_when_active() {
		$site_id = $this->make_site_with_plugins( '{"woocommerce/woocommerce.php":{"active":1,"Name":"WooCommerce"}}' );
		$this->assertTrue( MainWP_Utility::site_has_woocommerce( $site_id ) );
	}

	/**
	 * Inactive WooCommerce does not count.
	 *
	 * @return void
	 */
	public function test_site_has_woocommerce_false_when_inactive() {
		$site_id = $this->make_site_with_plugins( '{"woocommerce/woocommerce.php":{"active":0,"Name":"WooCommerce"}}' );
		$this->assertFalse( MainWP_Utility::site_has_woocommerce( $site_id ) );
	}

	/**
	 * A store without WooCommerce in its list reads as absent.
	 *
	 * @return void
	 */
	public function test_site_has_woocommerce_false_when_absent() {
		$site_id = $this->make_site_with_plugins( '{"akismet/akismet.php":{"active":1}}' );
		$this->assertFalse( MainWP_Utility::site_has_woocommerce( $site_id ) );
	}

	/**
	 * An empty or unsynced plugin list reads as absent, not a warning.
	 *
	 * @return void
	 */
	public function test_site_has_woocommerce_false_for_empty_plugins() {
		$site_id = $this->make_site_with_plugins( '' );
		$this->assertFalse( MainWP_Utility::site_has_woocommerce( $site_id ) );
	}

	/**
	 * An unknown site id is safe and reads as absent.
	 *
	 * @return void
	 */
	public function test_site_has_woocommerce_false_for_unknown_site() {
		$this->assertFalse( MainWP_Utility::site_has_woocommerce( 99999999 ) );
	}

	// ---- restore_needs_confirmation() ----

	/**
	 * A database-bearing restore without consent must be confirmed.
	 *
	 * @return void
	 */
	public function test_restore_needs_confirmation_for_full_without_consent() {
		$this->assertTrue( MainWP_Post_Backup_Handler::restore_needs_confirmation( (object) array( 'type' => 'full' ), false ) );
		$this->assertTrue( MainWP_Post_Backup_Handler::restore_needs_confirmation( (object) array( 'type' => 'incremental' ), false ) );
	}

	/**
	 * With consent, a database-bearing restore proceeds.
	 *
	 * @return void
	 */
	public function test_restore_no_confirmation_for_full_with_consent() {
		$this->assertFalse( MainWP_Post_Backup_Handler::restore_needs_confirmation( (object) array( 'type' => 'full' ), true ) );
	}

	/**
	 * A files-only restore leaves the database untouched and never gates.
	 *
	 * @return void
	 */
	public function test_restore_no_confirmation_for_files_only() {
		$this->assertFalse( MainWP_Post_Backup_Handler::restore_needs_confirmation( (object) array( 'type' => 'files' ), false ) );
	}

	/**
	 * A missing snapshot does not gate here - initiate_restore reports the
	 * not-found error on its own.
	 *
	 * @return void
	 */
	public function test_restore_no_confirmation_for_unknown_snapshot() {
		$this->assertFalse( MainWP_Post_Backup_Handler::restore_needs_confirmation( null, false ) );
	}
}
