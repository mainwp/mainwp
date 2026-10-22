<?php
/**
 * REST site name input tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;

/**
 * Covers site name writes shared by REST v1 and WP-CLI.
 */
class Test_REST_Update_Website_Name extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Tagged names are stored as plain text.
	 */
	public function test_strips_tags_from_site_name(): void {
		$site_id = $this->create_test_site();
		MainWP_DB_Common::instance()->rest_api_update_website( $site_id, array( 'name' => 'a <img src=x onerror=alert(1)> b' ) );
		$name = MainWP_DB::instance()->get_website_by_id( $site_id )->name;
		$this->assertStringNotContainsString( '<', $name );
		$this->assertSame( 'a  b', $name );
	}

	/**
	 * Plain names retain their punctuation and spacing.
	 */
	public function test_preserves_plain_site_name(): void {
		$site_id = $this->create_test_site();
		$name    = 'Agency & Co "East"';
		MainWP_DB_Common::instance()->rest_api_update_website( $site_id, array( 'name' => $name ) );

		$this->assertSame( $name, MainWP_DB::instance()->get_website_by_id( $site_id )->name );
	}

	/**
	 * Invalid names do not block other mapped fields.
	 */
	public function test_skips_non_scalar_names(): void {
		$site_id = $this->create_test_site();
		foreach ( array( array( 'tagged' ), (object) array( 'name' => 'tagged' ) ) as $name ) {
			MainWP_DB_Common::instance()->rest_api_update_website(
				$site_id,
				array(
					'name'  => $name,
					'admin' => 'next-admin',
				)
			);
			$site = MainWP_DB::instance()->get_website_by_id( $site_id );
			$this->assertSame( 'Test Site', $site->name );
			$this->assertSame( 'next-admin', $site->adminname );
		}
	}

	/**
	 * Empty names, including names that are only markup, keep the existing stored value.
	 */
	public function test_ignores_empty_names(): void {
		$site_id = $this->create_test_site();
		foreach ( array( '', '0', 0, false, null, array(), '<b></b>' ) as $name ) {
			MainWP_DB_Common::instance()->rest_api_update_website( $site_id, array( 'name' => $name ) );
			$this->assertSame( 'Test Site', MainWP_DB::instance()->get_website_by_id( $site_id )->name );
		}
	}
}
