<?php
/**
 * Child version persistence and ability compatibility checks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Sync;

/**
 * Preserve known versions and allow unknown versions through the compatibility gate.
 *
 * @group abilities
 * @group sync
 */
class Test_Child_Version_Sync extends MainWP_Abilities_Test_Case {

	/**
	 * Persist supplied versions while keeping stored values for absent versions.
	 *
	 * @dataProvider sync_version_provider
	 *
	 * @param array  $information Child response.
	 * @param string $sync_error  Transport error.
	 * @param string $expected    Persisted version.
	 * @param bool   $success     Expected sync result.
	 */
	public function test_sync_persists_only_reported_child_versions( array $information, string $sync_error, string $expected, bool $success ): void {
		$site_id = $this->create_test_site( array( 'version' => '6.3' ) );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$this->assertSame( $success, MainWP_Sync::sync_information_array( $website, $information, $sync_error ) );

		$stored = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( $expected, $stored->version );
		if ( '' !== $sync_error ) {
			$this->assertSame( $sync_error, $stored->sync_errors );
		}
	}

	/**
	 * A response without a version cannot overwrite a newer database value.
	 */
	public function test_missing_version_keeps_newer_database_value(): void {
		$site_id = $this->create_test_site( array( 'version' => '6.3' ) );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_sync_values( $site_id, array( 'version' => '6.4' ) );
		$information = array( 'wpversion' => '6.8' );

		$this->assertSame( '6.3', $website->version );
		$this->assertTrue( MainWP_Sync::sync_information_array( $website, $information ) );
		$this->assertSame( '6.4', MainWP_DB::instance()->get_website_by_id( $site_id )->version );
	}

	/**
	 * Sync responses with missing, empty, and reported versions.
	 *
	 * @return array
	 */
	public static function sync_version_provider(): array {
		return array(
			'missing version on success' => array( array( 'wpversion' => '6.8' ), '', '6.3', true ),
			'failed child response'      => array( array( 'error' => 'Child unavailable' ), '', '6.3', false ),
			'failed transport'           => array( array(), 'Connection timed out', '6.3', false ),
			'integer zero'               => array(
				array(
					'wpversion' => '6.8',
					'version'   => 0,
				),
				'',
				'6.3',
				true,
			),
			'string zero'                => array(
				array(
					'wpversion' => '6.8',
					'version'   => '0',
				),
				'',
				'6.3',
				true,
			),
			'empty string'               => array(
				array(
					'wpversion' => '6.8',
					'version'   => '',
				),
				'',
				'6.3',
				true,
			),
			'null'                       => array(
				array(
					'wpversion' => '6.8',
					'version'   => null,
				),
				'',
				'6.3',
				true,
			),
			'release version'            => array( array( 'version' => '6.4' ), '', '6.4', true ),
			'prerelease version'         => array( array( 'version' => '6.4-rc.2' ), '', '6.4-rc.2', true ),
		);
	}

	/**
	 * Reject known old versions while allowing sites without a known version.
	 *
	 * @dataProvider child_version_provider
	 *
	 * @param array $properties Site properties.
	 * @param bool  $outdated   Whether the known version is below the minimum.
	 */
	public function test_child_version_gate_allows_unknown_versions( array $properties, bool $outdated ): void {
		$site   = (object) array_merge( array( 'id' => 42 ), $properties );
		$result = MainWP_Abilities_Util::check_child_version( $site );

		if ( $outdated ) {
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_child_outdated', $result->get_error_code() );
			$this->assertSame( 'Child plugin version 4.0.0 or higher required.', $result->get_error_message() );
			$this->assertSame(
				array(
					'status'  => 400,
					'site_id' => 42,
				),
				$result->get_error_data()
			);
		} else {
			$this->assertTrue( $result );
		}
	}

	/**
	 * Unknown and known versions presented to the compatibility gate.
	 *
	 * @return array
	 */
	public static function child_version_provider(): array {
		return array(
			'integer zero'  => array( array( 'version' => 0 ), false ),
			'string zero'   => array( array( 'version' => '0' ), false ),
			'empty string'  => array( array( 'version' => '' ), false ),
			'null'          => array( array( 'version' => null ), false ),
			'missing'       => array( array(), false ),
			'known old'     => array( array( 'version' => '3.9' ), true ),
			'known current' => array( array( 'version' => '6.3' ), false ),
		);
	}

	/**
	 * Unknown versions still require a valid site object.
	 */
	public function test_child_version_gate_rejects_invalid_sites(): void {
		foreach ( array( null, array( 'id' => 42 ), (object) array( 'version' => 0 ) ) as $site ) {
			$result = MainWP_Abilities_Util::check_child_version( $site );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_internal_error', $result->get_error_code() );
			$this->assertSame( array( 'status' => 500 ), $result->get_error_data() );
		}
	}

	/**
	 * Explicit minimum versions continue to reject known older versions.
	 */
	public function test_child_version_gate_honors_explicit_minimum(): void {
		$result = MainWP_Abilities_Util::check_child_version(
			(object) array(
				'id'      => 42,
				'version' => '6.3',
			),
			'7.0'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_child_outdated', $result->get_error_code() );
		$this->assertSame( 'Child plugin version 7.0 or higher required.', $result->get_error_message() );
	}
}
