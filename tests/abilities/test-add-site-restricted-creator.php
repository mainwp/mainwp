<?php
/**
 * Regression coverage for access granted by the site-creation hook.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Exercise relation assignment after a restricted creator receives access.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_Add_Site_Restricted_Creator extends MainWP_Abilities_Test_Case {

	/**
	 * Keep unrelated callbacks on shared hooks intact during cleanup.
	 *
	 * @var array<string, callable>
	 */
	private $scope_filters = array();

	/**
	 * Retain the callback so cleanup removes only this test's action.
	 *
	 * @var callable|null
	 */
	private $site_added_action;

	/**
	 * The creator must receive access before the ability attaches relations.
	 *
	 * @return void
	 */
	public function test_restricted_creator_receives_site_and_relations() {
		global $wpdb;

		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$tag           = $this->create_test_tag();
		$allowed_sites = array();
		$hook_calls    = 0;
		$url           = 'https://test-restricted-creator.example.com/';

		$this->scope_filters = array(
			'mainwp_currentuserallowedaccesssites'  => static function () use ( &$allowed_sites ) {
				return $allowed_sites;
			},
			'mainwp_currentuserallowedaccessgroups' => static function () use ( $tag ) {
				return array( $tag );
			},
			'mainwp_fetch_url_not_authed_pre'       => static function () {
				return array( 'register' => 'OK' );
			},
			'mainwp_add_site_skip_key_generation'   => static function () {
				return true;
			},
			// Initial favicon fetching must not contact a real Child site.
			'mainwp_fetch_url_authed_pre'           => static function () {
				return array();
			},
		);
		foreach ( $this->scope_filters as $hook => $callback ) {
			add_filter( $hook, $callback );
		}

		$this->site_added_action = static function ( $site_id ) use ( &$allowed_sites, &$hook_calls ) {
			$allowed_sites[] = (int) $site_id;
			++$hook_calls;
		};
		add_action( 'mainwp_added_new_site', $this->site_added_action, 10, 1 );

		$result = $this->execute_ability(
			'mainwp/add-site-v1',
			array(
				'url'            => $url,
				'name'           => 'Test Restricted Creator',
				'admin_username' => 'admin',
				'tag_ids'        => array( $tag ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $hook_calls, 'The creation hook must fire exactly once.' );

		$inserted_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE url = %s",
				$url
			)
		);
		$this->assertGreaterThan( 0, $inserted_id );
		$this->assertSame( $inserted_id, $result['id'] );
		$this->assertSame( array( $inserted_id ), $allowed_sites );

		$relation_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_group WHERE wpid = %d AND groupid = %d",
				$inserted_id,
				$tag
			)
		);
		$this->assertSame( 1, $relation_count, 'The ability must attach the requested tag after access is granted.' );
	}

	/**
	 * Keep shared hooks available to other tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->scope_filters as $hook => $callback ) {
			remove_filter( $hook, $callback );
		}
		$this->scope_filters = array();
		if ( null !== $this->site_added_action ) {
			remove_action( 'mainwp_added_new_site', $this->site_added_action, 10 );
			$this->site_added_action = null;
		}
		parent::tearDown();
	}
}
