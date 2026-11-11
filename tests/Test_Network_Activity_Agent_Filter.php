<?php
/**
 * Network Activity agent filtering.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Module\Log\Log_Query;
use MainWP\Dashboard\Module\Log\Log_Manage_Insights_Events_Page;
use MainWP\Dashboard\Module\Log\Log_Events_Filter_Segment;
use MainWP\Dashboard\MainWP_Manage_Sites_Filter_Segment;

/** Exercise the production SQL and persisted page filters. */
class Test_Network_Activity_Agent_Filter extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Fixture log IDs.
	 *
	 * @var array
	 */
	private $ids = array();

	/**
	 * Visible site.
	 *
	 * @var int
	 */
	private $site;

	/**
	 * Hidden site.
	 *
	 * @var int
	 */
	private $hidden;

	/**
	 * Dashboard actor.
	 *
	 * @var int
	 */
	private $actor;

	/**
	 * Original request.
	 *
	 * @var array
	 */
	private $request;

	/** Build mixed origins, including missing and malformed legacy metadata. */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->request = $_REQUEST;
		$this->actor   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->actor );
		$this->site   = $this->create_test_site();
		$this->hidden = $this->create_test_site();
		foreach ( array( 'mainwp_mcp', 'wp_cli', 'wp_cron', 'wp_rest_api', '', 'legacy', 'invalid', 'nested', 'MAINWP_MCP' ) as $offset => $agent ) {
			$this->ids[ $agent ] = $this->event( $this->site, $this->actor, $offset );
			if ( 'legacy' === $agent ) {
				continue;
			}
			$meta = wp_json_encode( array( 'agent' => $agent ) );
			if ( 'invalid' === $agent ) {
				$meta = '{broken';
			} elseif ( 'nested' === $agent ) {
				$meta = wp_json_encode( array( 'other' => array( 'agent' => 'mainwp_mcp' ) ) );
			}
			$this->assertNotFalse(
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (meta_log_id, meta_key, meta_value) VALUES (%d, %s, %s)',
						$wpdb->mainwp_tbl_logs_meta,
						$this->ids[ $agent ],
						'user_meta_json',
						$meta
					)
				)
			);
		}
		$this->ids['second'] = $this->event( $this->site, $this->actor, 9, 'mainwp_mcp' );
		$this->ids['hidden'] = $this->event( $this->hidden, $this->actor, 10, 'mainwp_mcp' );
		$this->ids['other']  = $this->event( $this->site, $this->actor + 100, 11, 'mainwp_mcp' );
		wp_cache_flush();
	}

	/** Restore request and scope filters. */
	public function tear_down() {
		$_REQUEST = $this->request;
		remove_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'scope' ) );
		parent::tear_down();
	}

	/** The SQL filter applies before counts and LIMIT. */
	public function test_mcp_count_and_paging() {
		$all = $this->records();
		$this->assertSame( 12, $all['count'] );
		$first = $this->records(
			array(
				'agent'            => 'mainwp_mcp',
				'records_per_page' => 2,
			)
		);
		$this->assertSame( 4, $first['count'] );
		$this->assertSame( array( $this->ids['mainwp_mcp'], $this->ids['second'] ), array_map( 'intval', wp_list_pluck( $first['items'], 'log_id' ) ) );
		$second = $this->records(
			array(
				'agent'            => 'mainwp_mcp',
				'records_per_page' => 2,
				'start'            => 2,
			)
		);
		$this->assertSame( 4, $second['count'] );
		$this->assertSame( array( $this->ids['hidden'], $this->ids['other'] ), array_map( 'intval', wp_list_pluck( $second['items'], 'log_id' ) ) );
		$this->assertSame(
			array( 'count' => 4 ),
			$this->records(
				array(
					'agent'      => 'mainwp_mcp',
					'count_only' => true,
				)
			)
		);
	}

	/** The logger's encoded user names do not interfere with agent matching. */
	public function test_canonical_metadata_with_escaped_user_login() {
		global $wpdb;
		foreach ( array( 'reviewer', 'reviewer"\\name', 'reviewer café' ) as $login ) {
			$meta = wp_json_encode(
				array(
					'user_login' => $login,
					'agent'      => 'mainwp_mcp',
				)
			);
			$this->assertNotFalse(
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET meta_value = %s WHERE meta_log_id = %d AND meta_key = %s',
						$wpdb->mainwp_tbl_logs_meta,
						$meta,
						$this->ids['mainwp_mcp'],
						'user_meta_json'
					)
				)
			);
			wp_cache_flush();
			$result = $this->records( array( 'agent' => 'mainwp_mcp' ) );
			$this->assertSame( 4, $result['count'], $login );
			$this->assertContains( $this->ids['mainwp_mcp'], array_map( 'intval', wp_list_pluck( $result['items'], 'log_id' ) ), $login );
		}
	}

	/** Site, actor, date and event restrictions remain conjunctive. */
	public function test_combined_filters_preserve_staff_scope() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'scope' ) );
		$args   = array(
			'agent'     => 'mainwp_mcp',
			'sites_ids' => array( $this->site, $this->hidden ),
			'user_ids'  => array( $this->actor ),
			'events'    => array( 'updated' ),
		);
		$result = $this->records( $args );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( array( $this->ids['mainwp_mcp'], $this->ids['second'] ), array_map( 'intval', wp_list_pluck( $result['items'], 'log_id' ) ) );
		$args['sites_ids'] = array( $this->hidden );
		$this->assertSame( 0, $this->records( $args )['count'] );
	}

	/** Unknown and absent agents have the same rows and counts. */
	public function test_unknown_agent_is_all() {
		$default = $this->records();
		foreach ( array( '', 'all', 'wp_cli', "mainwp_mcp' OR 1=1 --", array( 'mainwp_mcp' ) ) as $agent ) {
			$this->assertEquals( $default, $this->records( array( 'agent' => $agent ) ) );
		}
	}

	/** Persist and reload the current selection; old saved filters default to all. */
	public function test_page_filter_round_trip() {
		$page     = new Log_Manage_Insights_Events_Page();
		$_REQUEST = array(
			'agent'               => 'mainwp_mcp',
			'range'               => 'thismonth',
			'_insights_opennonce' => wp_create_nonce( 'mainwp-admin-nonce' ),
		);
		$this->assertSame( 'mainwp_mcp', $page->get_insights_filters( true )['filter_agent'] );
		$_REQUEST = array();
		$this->assertSame( 'mainwp_mcp', $page->get_insights_filters()['filter_agent'] );
		update_user_option( get_current_user_id(), 'mainwp_module_logs_manage_filters_saved', array( 'ranges' => 'thismonth' ) );
		$this->assertSame( '', $page->get_insights_filters()['filter_agent'] );
		$_REQUEST = array( 'agent' => 'unknown' );
		$this->assertSame( '', $page->get_insights_filters()['filter_agent'] );
	}

	/** Save actual segment POST fields and read back the persisted values. */
	public function test_segment_save_round_trip() {
		global $wpdb;
		$post = $_POST;
		$stop = static function ( $meta_id, $user_id, $key ) use ( $wpdb ) {
			if ( $wpdb->prefix . 'mainwp_module_log_manage_filter_saved_segments' === $key ) {
				throw new \RuntimeException( 'segment persisted' );
			}
		};
		add_action( 'added_user_meta', $stop, 10, 3 );
		add_action( 'updated_user_meta', $stop, 10, 3 );
		try {
			foreach ( array( 'mainwp_mcp', '', 'unknown' ) as $index => $agent ) {
				$_POST    = array(
					'security'        => wp_create_nonce( 'mainwp_module_log_filter_save_segment' ),
					'filter_opt_name' => 'module_log_manage',
					'seg_id'          => (string) ( $index + 1 ),
					'name'            => 'Agent fixture',
					'seg_agent'       => $agent,
				);
				$_REQUEST = $_POST;
				try {
					( new Log_Events_Filter_Segment() )->ajax_log_filter_save_segment();
					$this->fail( 'The segment persistence hook was not reached.' );
				} catch ( \RuntimeException $exception ) {
					$this->assertSame( 'segment persisted', $exception->getMessage() );
				}
				$segments = MainWP_Manage_Sites_Filter_Segment::set_get_manage_sites_filter_segments( false, array(), 'module_log_manage' );
				$this->assertSame( 'mainwp_mcp' === $agent ? 'mainwp_mcp' : '', $segments[ $index + 1 ]['seg_agent'] );
			}
		} finally {
			$_POST = $post;
			remove_action( 'added_user_meta', $stop );
			remove_action( 'updated_user_meta', $stop );
		}
	}

	/**
	 * Restrict the staff fixture to its assigned site.
	 *
	 * @return array Allowed site IDs.
	 */
	public function scope() {
		return array( $this->site );
	}

	/**
	 * Insert audit fixtures without changing agent detection.
	 *
	 * @param int    $site Site ID.
	 * @param int    $user Actor ID.
	 * @param int    $offset Timestamp offset.
	 * @param string $agent Optional agent metadata.
	 * @return int Log ID.
	 */
	private function event( $site, $user, $offset, $agent = '' ) {
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				$wpdb->mainwp_tbl_logs,
				array(
					'site_id'   => $site,
					'user_id'   => $user,
					'connector' => 'site',
					'context'   => 'sites',
					'action'    => 'updated',
					'created'   => ( strtotime( '2026-11-11 12:00:00' ) + $offset ) * 1000000,
					'dismiss'   => 0,
				)
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $agent ) {
			$this->assertNotFalse(
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (meta_log_id, meta_key, meta_value) VALUES (%d, %s, %s)',
						$wpdb->mainwp_tbl_logs_meta,
						$id,
						'user_meta_json',
						wp_json_encode( array( 'agent' => $agent ) )
					)
				)
			);
		}
		return $id;
	}

	/**
	 * Run the production list and count queries over the same date range.
	 *
	 * @param array $args Query overrides.
	 * @return array Query result.
	 */
	private function records( $args = array() ) {
		return ( new Log_Query() )->query(
			array_merge(
				array(
					'view'             => 'events_list',
					'start'            => 0,
					'records_per_page' => 20,
					'orderby'          => 'created',
					'order'            => 'ASC',
					'timestart'        => strtotime( '2026-11-11' ),
					'timestop'         => strtotime( '2026-11-12' ),
				),
				$args
			)
		);
	}
}
