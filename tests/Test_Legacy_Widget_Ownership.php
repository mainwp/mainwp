<?php
/**
 * Ownership of global legacy widget output.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Recent_Pages;
use MainWP\Dashboard\MainWP_Recent_Posts;
use MainWP\Dashboard\MainWP_Security_Issues_Widget;
use MainWP\Dashboard\MainWP_Site_Actions;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\Module\Log\Log_Graph_Plugins_Widget;

/** Exercise persisted sites through the widget listing renderers. */
class Test_Legacy_Widget_Ownership extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Saved request state.
	 *
	 * @var array
	 */
	private $request_state;

	/**
	 * Original user and site context.
	 *
	 * @var \WP_User
	 */
	private $original_user;

	/**
	 * Fixture owners.
	 *
	 * @var array
	 */
	private $owners;

	/**
	 * Persisted fixture sites.
	 *
	 * @var array
	 */
	private $sites = array();

	/**
	 * Whether legacy ownership is enabled.
	 *
	 * @var bool
	 */
	private $multiuser = true;

	/**
	 * Team Control grants.
	 *
	 * @var array|string
	 */
	private $allowed = 'all';

	/** Persist distinct content and counts for both administrators. */
	public function setUp(): void {
		parent::setUp();
		$this->request_state = array( $_GET, $_POST, $_REQUEST );
		$this->original_user = wp_get_current_user();
		$_GET                = array();
		$_POST               = array();
		$_REQUEST            = array();
		$this->owners        = array(
			self::factory()->user->create( array( 'role' => 'administrator' ) ),
			self::factory()->user->create( array( 'role' => 'administrator' ) ),
		);
		wp_set_current_user( $this->owners[0] );
		MainWP_System_Utility::set_current_wpid( 0 );
		foreach ( array(
			'Alpha'       => array( 0, 2, 1, 2, 0 ),
			'AlphaDenied' => array( 0, 3, 2, 1, 0 ),
			'Beta'        => array( 1, 7, 3, 1, 0 ),
			'Staging'     => array( 0, 11, 4, 2, 1 ),
		) as $name => $values ) {
			$plugins = array();
			foreach ( array(
				1 => $values[2],
				0 => $values[3],
			) as $active => $count ) {
				for ( $i = 0; $i < $count; ++$i ) {
					$plugins[] = array( 'active' => $active );
				}
			}
			$id                   = $this->create_test_site(
				array(
					'userid'         => $this->owners[ $values[0] ],
					'name'           => 'Ownership' . $name,
					'securityIssues' => $values[1],
					'plugins'        => wp_json_encode( $plugins ),
					'is_staging'     => $values[4],
				)
			);
			$this->sites[ $name ] = array(
				'id'       => $id,
				'issues'   => $values[1],
				'active'   => $values[2],
				'inactive' => $values[3],
			);
			$this->assertGreaterThan( 0, $id, 'Site fixture must persist.' );
			foreach ( array( 'posts', 'pages' ) as $kind ) {
				$this->set_site_option(
					$id,
					'recent_' . $kind,
					wp_json_encode(
						array(
							array(
								'id'     => $id,
								'title'  => 'Ownership' . $name . '-' . $kind,
								'status' => 'publish',
								'dts'    => '2026-10-25 12:00:00',
							),
						)
					)
				);
			}
		}
		add_filter( 'mainwp_is_single_user', array( $this, 'single_user' ), 999 );
		add_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'allowed_sites' ), 999 );
	}

	/** Restore only fixture state and callbacks owned by this test. */
	public function tearDown(): void {
		global $wpdb;
		remove_filter( 'mainwp_is_single_user', array( $this, 'single_user' ), 999 );
		remove_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'allowed_sites' ), 999 );
		foreach ( $this->sites as $site ) {
			foreach ( array(
				'mainwp_wp_options' => 'wpid',
				'mainwp_wp_sync'    => 'wpid',
				'mainwp_wp'         => 'id',
			) as $table => $column ) {
				$wpdb->delete( $wpdb->prefix . $table, array( $column => $site['id'] ), array( '%d' ) );
			}
		}
		list( $_GET, $_POST, $_REQUEST ) = $this->request_state;
		$GLOBALS['current_user']         = $this->original_user;
		parent::tearDown();
	}

	/**
	 * Return the configured single-user mode.
	 *
	 * @return bool
	 */
	public function single_user() {
		return ! $this->multiuser;
	}

	/**
	 * Return the configured Team Control grants.
	 *
	 * @return array|string
	 */
	public function allowed_sites() {
		return $this->allowed;
	}

	/**
	 * Render all five widgets under ownership and access restrictions.
	 *
	 * @dataProvider scenarios
	 * @param string $widget Widget name.
	 * @param string $scenario Access scenario.
	 */
	public function test_global_widget_output( $widget, $scenario ): void {
		$expected = array( 'Alpha', 'AlphaDenied' );
		if ( 'owner-b' === $scenario ) {
			wp_set_current_user( $this->owners[1] );
			$expected = array( 'Beta' );
		} elseif ( 'single-user' === $scenario ) {
			$this->multiuser = false;
			$expected        = array( 'Alpha', 'AlphaDenied', 'Beta' );
		} elseif ( 'foreign-grant' === $scenario ) {
			$this->allowed = array( $this->sites['Beta']['id'] );
			$expected      = array();
		} elseif ( 'own-grant' === $scenario ) {
			$this->allowed = array( $this->sites['Alpha']['id'], $this->sites['Beta']['id'] );
			$expected      = array( 'Alpha' );
		}
		MainWP_System_Utility::set_current_wpid( 0 );
		$this->assertArrayNotHasKey( 'client_id', $_GET );
		$this->assertSame( 0, MainWP_System_Utility::get_current_wpid() );
		ob_start();
		try {
			switch ( $widget ) {
				case 'posts':
					MainWP_Recent_Posts::render();
					break;
				case 'pages':
					MainWP_Recent_Pages::render();
					break;
				case 'security':
					MainWP_Security_Issues_Widget::render_widget();
					break;
				case 'actions':
					MainWP_Site_Actions::render_logs_overview_top( array(), true, null, null, 'operations' );
					break;
				case 'plugins':
					Log_Graph_Plugins_Widget::instance()->render_widget_content();
					break;
			}
			$body = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		foreach ( $this->sites as $name => $site ) {
			if ( in_array( $name, $expected, true ) ) {
				$this->assertStringContainsString( 'Ownership' . $name, $body );
			} else {
				$this->assertStringNotContainsString( 'Ownership' . $name, $body );
			}
		}
		if ( 'security' === $widget ) {
			preg_match_all( '/count-issues="(\d+)"[^>]*siteid="(\d+)"/', $body, $rows );
			$this->assertCount( count( $expected ), $rows[0] );
			$total = array_sum(
				array_map(
					function ( $name ) {
						return $this->sites[ $name ]['issues'];
					},
					$expected
				)
			);
			$this->assertSame( $total, array_sum( $rows[1] ) );
			$this->assertMatchesRegularExpression( '/shield alternate icon"><\/i>\s*' . $total . '<\/span>/', $body );
		} elseif ( 'plugins' === $widget ) {
			preg_match_all( '/x:\s*\x27Ownership(\w+)"\x27,\s*y:\s*(\d+)/', $body, $points, PREG_SET_ORDER );
			$this->assertCount( 2 * count( $expected ), $points );
			foreach ( $expected as $name ) {
				$counts = array();
				foreach ( $points as $point ) {
					if ( $name === $point[1] ) {
						$counts[] = (int) $point[2];
					}
				}
				$this->assertSame( array( $this->sites[ $name ]['active'], $this->sites[ $name ]['inactive'] ), $counts );
			}
		} elseif ( 'actions' === $widget ) {
			preg_match_all( '/<div class="item" data-value="(\d+)">Ownership(\w+)\/<\/div>/', $body, $rows );
			$this->assertCount( count( $expected ), $rows[0] );
		} else {
			preg_match_all( '/class="websiteId"[^>]*value="(\d+)"/', $body, $rows );
			$this->assertCount( count( $expected ), $rows[0] );
			foreach ( $expected as $name ) {
				$this->assertStringContainsString( 'Ownership' . $name . '-' . $widget, $body );
			}
		}
	}

	/**
	 * Provide widget and access combinations.
	 *
	 * @return array
	 */
	public static function scenarios(): array {
		$cases = array();
		foreach ( array( 'posts', 'pages', 'security', 'actions', 'plugins' ) as $widget ) {
			foreach ( array( 'owner-a', 'owner-b', 'single-user', 'foreign-grant', 'own-grant' ) as $scenario ) {
				$cases[ $widget . '-' . $scenario ] = array( $widget, $scenario );
			}
		}
		return $cases;
	}
}
