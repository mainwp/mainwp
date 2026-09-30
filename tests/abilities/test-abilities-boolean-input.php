<?php
/**
 * Boolean input handling for MainWP abilities.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_DB_Common;

/**
 * Covers boolean strings that schema validation passes through unchanged.
 *
 * @group abilities
 */
class MainWP_Abilities_Boolean_Input extends MainWP_Abilities_Test_Case {

	/**
	 * All twelve input shapes from the boolean input specification.
	 *
	 * @return array
	 */
	public static function boolean_input_provider(): array {
		return [
			'boolean true'  => [ [ 'flag' => true ], true ],
			'boolean false' => [ [ 'flag' => false ], false ],
			'string true'   => [ [ 'flag' => 'true' ], true ],
			'string false'  => [ [ 'flag' => 'false' ], false ],
			'uppercase'     => [ [ 'flag' => 'FALSE' ], false ],
			'string one'    => [ [ 'flag' => '1' ], true ],
			'string zero'   => [ [ 'flag' => '0' ], false ],
			'integer one'   => [ [ 'flag' => 1 ], true ],
			'integer zero'  => [ [ 'flag' => 0 ], false ],
			'empty string'  => [ [ 'flag' => '' ], false ],
			'missing'       => [ [], false ],
			'null'          => [ [ 'flag' => null ], false ],
		];
	}

	/**
	 * Empty strings and null bypass schema validation only in this helper test.
	 *
	 * @dataProvider boolean_input_provider
	 * @param array $input    Input containing the optional flag.
	 * @param bool  $expected Expected boolean value.
	 * @return void
	 */
	public function test_get_boolean_input( array $input, bool $expected ): void {
		$this->assertSame( $expected, MainWP_Abilities_Util::get_boolean_input( $input, 'flag' ) );
	}

	/**
	 * Schema-valid confirmation shapes and the combined flag rules.
	 *
	 * @return array
	 */
	public static function destructive_input_provider(): array {
		$matrix = self::boolean_input_provider();
		unset( $matrix['empty string'], $matrix['null'] );
		$rows = [];
		foreach ( $matrix as $name => [ $input, $expected ] ) {
			$flags         = isset( $input['flag'] ) ? [ 'confirm' => $input['flag'] ] : [];
			$rows[ $name ] = [ $flags, $expected ? 'execute' : 'refuse' ];
		}
		$rows['confirm true, dry_run string false']  = [ [ 'confirm' => true, 'dry_run' => 'false' ], 'execute' ];
		$rows['confirm false, dry_run string false'] = [ [ 'confirm' => 'false', 'dry_run' => 'false' ], 'refuse' ];
		$rows['confirm string false, preview']       = [ [ 'confirm' => 'false', 'dry_run' => true ], 'preview' ];
		$rows['confirm true, dry_run string true']   = [ [ 'confirm' => true, 'dry_run' => 'true' ], 'invalid' ];
		return $rows;
	}

	/**
	 * Client deletion requires a true confirmation after sanitization.
	 *
	 * @dataProvider destructive_input_provider
	 * @param array  $flags   Boolean inputs.
	 * @param string $outcome Expected action.
	 * @return void
	 */
	public function test_delete_client( array $flags, string $outcome ): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$before    = $this->get_test_client( $client_id );
		$result    = $this->execute_ability( 'mainwp/delete-client-v1', [ 'client_id_or_email' => $client_id ] + $flags );

		$this->assert_destructive_result( $result, $outcome );
		if ( 'execute' === $outcome ) {
			$this->assertEquals( $client_id, $result['id'] );
			$this->assertNull( $this->get_test_client( $client_id ) );
		} else {
			$this->assertNotNull( $this->get_test_client( $client_id ) );
			$this->assertEquals( $before, $this->get_test_client( $client_id ) );
		}
	}

	/**
	 * Tag deletion requires a true confirmation after sanitization.
	 *
	 * @dataProvider destructive_input_provider
	 * @param array  $flags   Boolean inputs.
	 * @param string $outcome Expected action.
	 * @return void
	 */
	public function test_delete_tag( array $flags, string $outcome ): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$tag_id = $this->create_test_tag();
		$before = MainWP_DB_Common::instance()->get_group_by_id( $tag_id );
		$result = $this->execute_ability( 'mainwp/delete-tag-v1', [ 'tag_id' => $tag_id ] + $flags );

		$this->assert_destructive_result( $result, $outcome );
		$after = MainWP_DB_Common::instance()->get_group_by_id( $tag_id );
		if ( 'execute' === $outcome ) {
			$this->assertTrue( $result['deleted'] );
			$this->assertEmpty( $after );
		} else {
			$this->assertNotEmpty( $after );
			$this->assertEquals( $before, $after );
		}
	}

	/**
	 * Site deletion requires a true confirmation after sanitization.
	 *
	 * @dataProvider destructive_input_provider
	 * @param array  $flags   Boolean inputs.
	 * @param string $outcome Expected action.
	 * @return void
	 */
	public function test_delete_site( array $flags, string $outcome ): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$before  = $this->get_test_site( $site_id );
		$result  = $this->execute_ability( 'mainwp/delete-site-v1', [ 'site_id_or_domain' => $site_id ] + $flags );

		$this->assert_destructive_result( $result, $outcome );
		if ( 'execute' === $outcome ) {
			$this->assertTrue( $result['deleted'] );
			$this->assertNull( $this->get_test_site( $site_id ) );
		} else {
			$this->assertNotNull( $this->get_test_site( $site_id ) );
			$this->assertEquals( $before, $this->get_test_site( $site_id ) );
		}
	}

	/**
	 * Apply every destructive input row to both remote deletion abilities.
	 *
	 * @return array
	 */
	public static function remote_delete_input_provider(): array {
		$rows = [];
		foreach ( [ 'plugins', 'themes' ] as $type ) {
			foreach ( self::destructive_input_provider() as $name => $row ) {
				$rows[ $type . ': ' . $name ] = array_merge( [ $type ], $row );
			}
		}
		return $rows;
	}

	/**
	 * Refusals and previews leave installed items untouched and send no remote calls.
	 *
	 * @dataProvider remote_delete_input_provider
	 * @param string $type    Plugins or themes.
	 * @param array  $flags   Boolean inputs.
	 * @param string $outcome Expected action.
	 * @return void
	 */
	public function test_delete_site_plugins_and_themes( string $type, array $flags, string $outcome ): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$slug    = 'plugins' === $type ? 'hello-dolly/hello.php' : 'twentytwentyfour';
		$items   = [ $slug => [ 'slug' => $slug, 'Name' => 'Test Item', 'Version' => '1.0', 'active' => 0 ] ];
		if ( 'plugins' === $type ) {
			$this->set_site_plugins( $site_id, $items );
		} else {
			$this->set_site_themes( $site_id, $items );
		}
		$before = $this->get_test_site( $site_id );
		$calls  = [];
		$this->mock_child_site_response( $site_id, [ 'success' => true ] );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $response, $website, $what, $params ) use ( &$calls ) {
				$calls[] = [ (int) $website->id, $what, $params ];
				return $response;
			},
			20,
			4
		);

		$result = $this->execute_ability(
			'mainwp/delete-site-' . $type . '-v1',
			[ 'site_id_or_domain' => $site_id, $type => [ $slug ] ] + $flags
		);

		$this->assert_destructive_result( $result, $outcome );
		if ( 'execute' === $outcome ) {
			$remote_type = 'plugins' === $type ? 'plugin' : 'theme';
			$this->assertSame( [ [ $site_id, $remote_type . '_action', [ 'action' => 'delete', $remote_type => $slug ] ] ], $calls );
			$this->assertSame( [ $slug ], array_column( $result['deleted'], 'slug' ) );
			$this->assertSame( [], $result['errors'] );
			$this->assertFalse( $result['dry_run'] );
		} else {
			$this->assertSame( [], $calls );
			$this->assertNotNull( $this->get_test_site( $site_id ) );
			$this->assertEquals( $before, $this->get_test_site( $site_id ) );
		}
	}

	/**
	 * Schema-valid shapes for all three read flags.
	 *
	 * @return array
	 */
	public static function read_input_provider(): array {
		$matrix = self::boolean_input_provider();
		unset( $matrix['empty string'], $matrix['null'] );
		$rows = [];
		foreach ( [ 'site', 'plugins', 'themes' ] as $type ) {
			$key = 'site' === $type ? 'include_stats' : 'has_update';
			foreach ( $matrix as $name => [ $input, $expected ] ) {
				$flags                      = isset( $input['flag'] ) ? [ $key => $input['flag'] ] : [];
				$rows[ $type . ': ' . $name ] = [ $type, $flags, $expected ];
			}
		}
		return $rows;
	}

	/**
	 * Stats and update filtering apply only when their flags read true.
	 *
	 * @dataProvider read_input_provider
	 * @param string $type     Site, plugins or themes.
	 * @param array  $flags    Boolean inputs.
	 * @param bool   $expected Expected boolean value.
	 * @return void
	 */
	public function test_read_flags( string $type, array $flags, bool $expected ): void {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$updated = 'plugins' === $type ? 'hello-dolly/hello.php' : 'twentytwentyfour';
		$current = 'plugins' === $type ? 'akismet/akismet.php' : 'twentytwentythree';
		$items   = [
			$updated => [ 'slug' => $updated, 'Name' => 'Needs Update', 'Version' => '1.0', 'active' => 0 ],
			$current => [ 'slug' => $current, 'Name' => 'Current', 'Version' => '1.0', 'active' => 0 ],
		];
		$updates = [ $updated => [ 'new_version' => '2.0' ] ];
		if ( 'plugins' === $type ) {
			$this->set_site_plugins( $site_id, $items );
			$this->set_site_plugin_upgrades( $site_id, $updates );
		} elseif ( 'themes' === $type ) {
			$this->set_site_themes( $site_id, $items );
			$this->set_site_theme_upgrades( $site_id, $updates );
		}
		$ability = 'site' === $type ? 'mainwp/get-site-v1' : 'mainwp/get-site-' . $type . '-v1';
		$result  = $this->execute_ability( $ability, [ 'site_id_or_domain' => $site_id ] + $flags );

		$this->assertNotWPError( $result );
		if ( 'site' === $type ) {
			if ( $expected ) {
				$this->assertArrayHasKey( 'stats', $result );
				$this->assertIsArray( $result['stats'] );
			} else {
				$this->assertArrayNotHasKey( 'stats', $result );
			}
		} else {
			$this->assertSame( $expected ? [ $updated ] : [ $updated, $current ], array_column( $result[ $type ], 'slug' ) );
			$this->assertSame( $expected ? 1 : 2, $result['total'] );
		}
	}

	/**
	 * Assert gate errors by code and status, or a successful execution or preview.
	 *
	 * @param mixed  $result  Ability result.
	 * @param string $outcome Expected action.
	 * @return void
	 */
	private function assert_destructive_result( $result, string $outcome ): void {
		if ( 'refuse' === $outcome || 'invalid' === $outcome ) {
			$this->assertWPError( $result );
			$this->assertSame( 'refuse' === $outcome ? 'mainwp_confirmation_required' : 'mainwp_invalid_input', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		} else {
			$this->assertNotWPError( $result );
			$this->assertIsArray( $result );
			if ( 'preview' === $outcome ) {
				$this->assertTrue( $result['dry_run'] );
				$this->assertArrayHasKey( 'would_affect', $result );
			}
		}
	}
}
