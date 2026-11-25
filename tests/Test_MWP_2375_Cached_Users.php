<?php
/**
 * Cached Manage Users search and rendering regressions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Cache;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_User;

/** Exercise real site selection, cached filtering, and HTML rendering together. */
class Test_MWP_2375_Cached_Users extends \WP_UnitTestCase {

	use Site_Fixture;

	/** @var mixed Original session state. */
	private $previous_session;

	/** @var int Original current user. */
	private $previous_user;

	/** Use an administrator and isolated search-cache state for each test. */
	public function set_up(): void {
		parent::set_up();
		$this->previous_user    = get_current_user_id();
		$this->previous_session = $_SESSION ?? null;
		$_SESSION              = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_optimize', 1 );
	}

	/** Restore the surrounding request and session. */
	public function tear_down(): void {
		if ( null === $this->previous_session ) {
			unset( $_SESSION );
		} else {
			$_SESSION = $this->previous_session;
		}
		wp_set_current_user( $this->previous_user );
		parent::tear_down();
	}

	/**
	 * A normal cached Child user, including nullable password tracking.
	 *
	 * @return array
	 */
	private static function healthy_user(): array {
		return array(
			'id'                   => 23,
			'login'                => 'bob-healthy',
			'display_name'         => 'Bob Healthy',
			'email'                => 'bob@example.test',
			'role'                 => 'subscriber',
			'post_count'           => 0,
			'last_password_change' => null,
			'password_status'      => 'OK',
			'password_due_time'    => null,
		);
	}

	/** @return array Invalid or empty whole-cache values. */
	public static function invalid_caches(): array {
		$cases = array();
		foreach ( array( '', 'null', 'false', '42', '"text"', '{broken', '[null,true,42,"bad"]', '{"id":2,"login":"broken"}' ) as $cache ) {
			$cases[] = array( $cache );
		}
		return $cases;
	}

	/**
	 * A bad site's cache must not stop another site's healthy matches.
	 *
	 * @dataProvider invalid_caches
	 * @param string $cache Invalid cache.
	 */
	public function test_bad_site_cache_does_not_hide_another_sites_users( string $cache ): void {
		$bad  = $this->create_test_site( array( 'users' => $cache ) );
		$good = $this->site_with_users( array( self::healthy_user() ) );
		foreach ( array( '', 'subscriber' ) as $role ) {
			list( $html, $count ) = $this->search( array( $bad, $good ), $role, 'bob' );
			$this->assertSame( 1, $count );
			$this->assertStringContainsString( 'bob-healthy', $html );
		}
	}

	/** All empty sites produce an empty result without notices or exceptions. */
	public function test_all_empty_sites_return_no_users(): void {
		$first  = $this->create_test_site( array( 'users' => '' ) );
		$second = $this->create_test_site( array( 'users' => 'null' ) );
		list( $html, $count ) = $this->search( array( $first, $second ), 'subscriber', 'bob' );
		$this->assertSame( 0, $count );
		$this->assertSame( '', $html );
	}

	/** @return array Malformed fields that used to bypass matching or crash rendering. */
	public static function invalid_records(): array {
		$cases = array();
		$bad   = array_merge( self::healthy_user(), array( 'id' => 2, 'login' => 'broken', 'display_name' => 'Bob Broken' ) );
		foreach ( array(
			'id'                   => array( array( 2 ), true, 0, -1, 2.5, 'not-an-id' ),
			'login'                => array( array(), null, '', '   ', false ),
			'display_name'         => array( array( 'Bob' ) ),
			'email'                => array( array( 'bob@example.test' ) ),
			'role'                 => array( false, array( array( 'subscriber' ) ), array( 'subscriber', 7 ) ),
			'post_count'           => array( array(), -1, 'many' ),
			'last_password_change' => array( array( 123 ) ),
			'password_due_time'    => array( array( 123 ) ),
			'password_status'      => array( array( 'OK' ) ),
		) as $field => $values ) {
			foreach ( $values as $index => $value ) {
				$cases[ $field . '-' . $index ] = array( array_merge( $bad, array( $field => $value ) ) );
			}
		}
		$missing_id = $bad;
		unset( $missing_id['id'] );
		$cases['missing-id'] = array( $missing_id );
		$missing_login = $bad;
		unset( $missing_login['login'] );
		$cases['missing-login'] = array( $missing_login );
		$cases['scalar-record'] = array( 'not a user' );
		return $cases;
	}

	/**
	 * Reject bad rows before both loops, even if another keyword field matches.
	 *
	 * @dataProvider invalid_records
	 * @param mixed $bad Malformed user record.
	 */
	public function test_bad_record_never_blocks_later_valid_matches( $bad ): void {
		$site = $this->site_with_users( array( $bad, self::healthy_user() ) );
		foreach ( array( '', 'subscriber' ) as $role ) {
			foreach ( array( '', 'bob' ) as $keyword ) {
				list( $html, $count ) = $this->search( array( $site ), $role, $keyword );
				$this->assertSame( 1, $count );
				$this->assertStringContainsString( 'bob-healthy', $html );
				$this->assertStringNotContainsString( 'Bob Broken', $html );
				$this->assertStringNotContainsString( '>Array<', $html );
			}
		}
	}

	/** @return array Supported legacy role representations and filters. */
	public static function role_formats(): array {
		return array(
			'string'       => array( 'subscriber', 'subscriber', 1 ),
			'list'         => array( array( 'subscriber' ), 'subscriber', 1 ),
			'multiple'     => array( array( 'editor', 'subscriber' ), 'editor', 1 ),
			'custom'       => array( 'customer_support', 'customer_support', 1 ),
			'no roles'     => array( array(), 'subscriber', 0 ),
			'nonmatching'  => array( 'editor', 'subscriber', 0 ),
			'comma string' => array( 'editor,subscriber', 'subscriber', 1 ),
			'multi filter' => array( array( 'editor', 'subscriber' ), 'author,subscriber', 1 ),
		);
	}

	/**
	 * Preserve scalar and array roles without sending arrays to stristr().
	 *
	 * @dataProvider role_formats
	 * @param mixed  $role Cached role value.
	 * @param string $filter Requested role filter.
	 * @param int    $expected Expected matches.
	 */
	public function test_supported_role_formats_still_filter_and_render( $role, string $filter, int $expected ): void {
		$user         = self::healthy_user();
		$user['role'] = $role;
		$site         = $this->site_with_users( array( $user ) );
		list( $html, $count ) = $this->search( array( $site ), $filter, 'bob' );
		$this->assertSame( $expected, $count );
		if ( $expected ) {
			$this->assertStringContainsString( 'bob-healthy', $html );
		}
	}

	/** Sparse caches and optional fields from old Child versions remain usable. */
	public function test_sparse_cache_and_minimal_legacy_user_are_supported_without_rewriting_cache(): void {
		$users = array( 5 => null, 20 => array( 'id' => '23', 'login' => 'bob-legacy' ) );
		$site  = $this->site_with_users( $users );
		$before = MainWP_DB::instance()->get_website_by_id( $site )->users;
		list( $html, $count ) = $this->search( array( $site ), '', 'bob' );
		$this->assertSame( 1, $count );
		$this->assertStringContainsString( 'bob-legacy', $html );
		$this->assertSame( $before, MainWP_DB::instance()->get_website_by_id( $site )->users );
	}

	/** @return array Each searchable text field must still work independently. */
	public static function keyword_fields(): array {
		return array( array( 'login' ), array( 'display_name' ), array( 'email' ) );
	}

	/**
	 * Keep case-insensitive, trimmed matching in every supported text field.
	 *
	 * @dataProvider keyword_fields
	 * @param string $field Searchable field.
	 */
	public function test_keyword_matches_each_field( string $field ): void {
		$user           = array_merge( self::healthy_user(), array( 'login' => 'plain', 'display_name' => 'Plain', 'email' => 'plain@example.test' ) );
		$user[ $field ] = 'BoB';
		$site           = $this->site_with_users( array( $user ) );
		list( , $count ) = $this->search( array( $site ), 'subscriber', ' bob ' );
		$this->assertSame( 1, $count );
	}

	/**
	 * Store records in the same cache read by the real selected-site loader.
	 *
	 * @param array $users Cached records.
	 * @return int Site ID.
	 */
	private function site_with_users( array $users ): int {
		return $this->create_test_site( array( 'users' => wp_json_encode( $users, JSON_PRESERVE_ZERO_FRACTION ) ) );
	}

	/**
	 * Render through the real handler, including filtering, escaping and caching.
	 *
	 * @param array  $sites Selected site IDs.
	 * @param string $role Role filter.
	 * @param string $keyword Search term.
	 * @return array HTML and recorded match count.
	 */
	private function search( array $sites, string $role, string $keyword ): array {
		MainWP_Cache::init_cache( 'Users' );
		ob_start();
		try {
			MainWP_User::render_table_body( $role, '', $sites, $keyword );
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$context = MainWP_Cache::get_cached_context( 'Users' );
		return array( $html, $context['count'] );
	}
}
