<?php
/**
 * Remote MCP "no Team Control limits" check tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Team_Control;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Team_Control
 */
class Test_MainWP_Remote_MCP_Team_Control extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
	}

	public function test_user_has_no_limits_false_with_site_subset_or_missing_right() {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $other );

		// Team Control inactive: every manage_options user passes, and only those.
		$this->assertTrue( Remote_MCP_Team_Control::user_has_no_limits( $admin ) );
		$this->assertFalse( Remote_MCP_Team_Control::user_has_no_limits( $editor ) );
		$this->assertFalse( Remote_MCP_Team_Control::user_has_no_limits( 0 ) );
		$this->assertFalse( Remote_MCP_Team_Control::user_has_no_limits( 999999 ) );
		$this->assertSame( $other, get_current_user_id(), 'the current user is restored' );

		// The filters are evaluated with that user as the current user.
		$limit_sites = function ( $allowed ) use ( $admin ) {
			return get_current_user_id() === $admin ? array( 1, 2 ) : $allowed;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $limit_sites );
		$this->assertFalse( Remote_MCP_Team_Control::user_has_no_limits( $admin ), 'a site subset is a limit' );
		$this->assertTrue( Remote_MCP_Team_Control::user_has_no_limits( $other ) );
		remove_filter( 'mainwp_currentuserallowedaccesssites', $limit_sites );

		$rights = array_merge( array_values( MainWP_Abilities_Util::ABILITY_OPERATION_RIGHTS ), array( 'update_wordpress', 'update_plugins', 'update_themes', 'update_translations' ) );
		foreach ( array_unique( $rights ) as $missing ) {
			$deny = function ( $can, $context, $right ) use ( $admin, $missing ) {
				return ( get_current_user_id() === $admin && 'dashboard' === $context && $missing === $right ) ? false : $can;
			};
			add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
			$this->assertFalse( Remote_MCP_Team_Control::user_has_no_limits( $admin ), 'missing ' . $missing );
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}
		$this->assertTrue( Remote_MCP_Team_Control::user_has_no_limits( $admin ) );
		$this->assertSame( $other, get_current_user_id() );
	}
}
