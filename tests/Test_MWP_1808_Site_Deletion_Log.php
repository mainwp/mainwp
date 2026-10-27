<?php
/**
 * Deleted sites retain an audit identity without changing public log records.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Hooks;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Abilities_Sites;
use MainWP\Dashboard\MainWP_Manage_Sites_Handler;
use MainWP\Dashboard\MainWP_Demo_Handle;
use MainWP\Dashboard\Module\Log\Log_Manager;
use MainWP\Dashboard\Module\Log\Log_Query;
use MainWP\Dashboard\Module\Log\Log_Events_List_Table;
use MainWP\Dashboard\Module\Log\Log_DB_Archive;

/** Exercise deletion writers, audit queries, rendering and public compatibility. */
class Test_MWP_1808_Site_Deletion_Log extends \WP_UnitTestCase {
	use Site_Fixture;

	/**
	 * Actor ID.
	 *
	 * @var int
	 */
	private $actor;

	/** Set an unrestricted actor and block every HTTP request. */
	public function setUp(): void {
		parent::setUp();
		$this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->actor );
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'block_transport' ) );
		wp_cache_flush();
	}

	/** Restore request guards. */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'block_transport' ) );
		parent::tearDown();
	}

	/**
	 * Block WordPress HTTP.
	 *
	 * @return \WP_Error Offline fixture response.
	 */
	public function block_http() {
		return new \WP_Error( 'offline_fixture', 'HTTP disabled for deletion tests.' );
	}

	/**
	 * Intercept MainWP transport.
	 *
	 * @return array Offline Child response.
	 */
	public function block_transport() {
		return array( 'removed' => true );
	}

	/**
	 * Query through the production SQL builder.
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
					'order'            => 'DESC',
				),
				$args
			)
		);
	}

	/**
	 * Seed an enabled event with its production metadata writer.
	 *
	 * @param int    $site Site ID.
	 * @param string $action Event action.
	 * @return int Log ID.
	 */
	private function event( $site, $action = 'updated' ) {
		return Log_Manager::instance()->connectors->connectors['site']->log( '%1$s', array( 'item' => 'Fixture' ), $site, 'sites', $action, 1 );
	}

	/**
	 * Remove a real site through the public hook implementation.
	 *
	 * @return array Site and surviving log IDs.
	 */
	private function deleted() {
		global $wpdb;
		$site = $this->create_test_site(
			array(
				'name' => 'Beta <b>Audit</b>',
				'url'  => 'https://deleted.example/?a=1&b=2',
			)
		);
		MainWP_Hooks::get_instance()->hook_delete_site( $site );
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT log_id FROM %i WHERE site_id = %d', $wpdb->mainwp_tbl_logs, $site ) );
		return array( $site, (int) $id );
	}

	/**
	 * Reachable deletion paths.
	 *
	 * @return array
	 */
	public static function deletion_paths() {
		return array(
			'hook'        => array( 'hook' ),
			'ability'     => array( 'ability' ),
			'manage demo' => array( 'demo' ),
		);
	}

	/**
	 * Each writer purges old events and captures the deleted site's identity.
	 *
	 * @dataProvider deletion_paths
	 * @param string $path Deletion entry point.
	 */
	public function test_deletion_paths_capture_identity( $path ) {
		global $wpdb;
		$site = $this->create_test_site(
			array(
				'name' => 'Deleted <em>site</em>',
				'url'  => 'https://deleted.example/',
			)
		);
		foreach ( array( 'added', 'updated', 'reconnect' ) as $action ) {
			$this->assertGreaterThan( 0, $this->event( $site, $action ) );
		}
		$before = time();
		if ( 'ability' === $path ) {
			$result = MainWP_Abilities_Sites::execute_delete_site(
				array(
					'site_id_or_domain' => $site,
					'confirm'           => true,
				)
			);
			$this->assertIsArray( $result );
			$this->assertTrue( $result['deleted'] );
		} elseif ( 'demo' === $path ) {
			$property = new \ReflectionProperty( MainWP_Demo_Handle::class, 'demo_website_ids' );
			$property->setAccessible( true );
			$original = $property->getValue();
			$property->setValue( null, array( $site ) );
			try {
				$this->assertTrue( MainWP_Manage_Sites_Handler::remove_website( $site )['removed_site'] );
			} finally {
				$property->setValue( null, $original );
			}
		} else {
			$this->assertSame( array( 'result' => 'SUCCESS' ), MainWP_Hooks::get_instance()->hook_delete_site( $site ) );
		}
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $wpdb->mainwp_tbl_wp, $site ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE site_id = %d', $wpdb->mainwp_tbl_logs, $site ) );
		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( array( 'site', 'sites', 'deleted', 'Deleted' ), array( $row->connector, $row->context, $row->action, $row->item ) );
		$this->assertSame( $this->actor, (int) $row->user_id );
		$this->assertGreaterThanOrEqual( $before, (int) ( $row->created / 1000000 ) );
		$meta = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE meta_log_id = %d', $wpdb->mainwp_tbl_logs_meta, $row->log_id ), OBJECT_K );
		$this->assertArrayHasKey( 'deleted_site_name', $meta );
		$this->assertSame( 'Deleted <em>site</em>', $meta['deleted_site_name']->meta_value );
		$this->assertSame( 'https://deleted.example/', $meta['deleted_site_url']->meta_value );
	}

	/**
	 * Audit view and optimization combinations.
	 *
	 * @return array
	 */
	public static function audit_views() {
		return array( array( 'events_list', false ), array( '', false ), array( 'widget', false ), array( 'events_list', true ), array( 'widget', true ) );
	}

	/**
	 * Audit views expose captured identity and matching counts.
	 *
	 * @dataProvider audit_views
	 * @param string $view Audit view.
	 * @param bool   $optimize Optimized query.
	 */
	public function test_audit_views_search_and_count( $view, $optimize ) {
		list( $site ) = $this->deleted();
		$args         = array(
			'wpid'     => $site,
			'view'     => $view,
			'optimize' => $optimize,
			'search'   => 'Beta',
		);
		$result       = $this->records( $args );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'Beta <b>Audit</b>', $result['items'][0]->log_site_name );
		$this->assertSame( 'https://deleted.example/?a=1&b=2', $result['items'][0]->url );
		$this->assertSame( array( 'count' => 1 ), $this->records( $args + array( 'count_only' => true ) ) );
		$this->assertSame( 1, $this->records( $args + array( 'recent_number' => 5 ) )['count'] );
	}

	/** Deleted-site names are text, including when legacy rows have no identity. */
	public function test_deleted_render_has_no_links_and_escapes_identity() {
		list( $site ) = $this->deleted();
		$item         = $this->records( array( 'wpid' => $site ) )['items'][0];
		$html         = ( new Log_Events_List_Table( Log_Manager::instance() ) )->column_default( $item, 'name' );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'Beta &lt;b&gt;Audit&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'deleted.example/', $html );
		$this->assertStringContainsString( 'https://deleted.example/?a=1&amp;b=2', $html );
		$item->log_site_name = 'Agency \\ Branch';
		$html                = ( new Log_Events_List_Table( Log_Manager::instance() ) )->column_default( $item, 'name' );
		$this->assertStringContainsString( 'Agency  Branch', $html );
		$this->assertStringNotContainsString( 'Agency \\ Branch', $html );
		$item->log_site_name = null;
		$this->assertSame( 'N/A', ( new Log_Events_List_Table( Log_Manager::instance() ) )->column_default( $item, 'name' ) );
	}

	/** Stored identity stays exact while display follows the existing slash convention. */
	public function test_deleted_identity_round_trip() {
		$name = 'Agency \\ Branch & "Reports"';
		$site = $this->create_test_site(
			array(
				'name' => $name,
				'url'  => 'https://identity.example/?a=1&b=2',
			)
		);
		$this->assertSame( array( 'result' => 'SUCCESS' ), MainWP_Hooks::get_instance()->hook_delete_site( $site ) );
		$rows = $this->records( array( 'wpid' => $site ) )['items'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $name, $rows[0]->log_site_name );
		$html = ( new Log_Events_List_Table( Log_Manager::instance() ) )->column_default( $rows[0], 'name' );
		$this->assertStringContainsString( 'Agency  Branch &amp; &quot;Reports&quot;', $html );
		$this->assertStringNotContainsString( esc_html( $name ), $html );
		$this->assertStringContainsString( 'https://identity.example/?a=1&amp;b=2', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/** The deletion hook captures staging state from the removed site. */
	public function test_deletion_hook_captures_staging_state() {
		global $wpdb;
		foreach ( array( 0, 1, 2 ) as $staging ) {
			$site = $this->create_test_site( array( 'is_staging' => $staging ) );
			$this->assertSame( array( 'result' => 'SUCCESS' ), MainWP_Hooks::get_instance()->hook_delete_site( $site ) );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $wpdb->mainwp_tbl_wp, $site ) ) );
			$meta = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT meta_value FROM %i WHERE meta_log_id = (SELECT log_id FROM %i WHERE site_id = %d) AND meta_key = %s',
					$wpdb->mainwp_tbl_logs_meta,
					$wpdb->mainwp_tbl_logs,
					$site,
					'deleted_site_is_staging'
				)
			);
			$this->assertSame( (string) $staging, $meta );
		}
	}

	/**
	 * Newly deleted staging sites stay hidden in every audit query mode.
	 *
	 * @dataProvider audit_views
	 * @param string $view Audit view.
	 * @param bool   $optimize Optimized query.
	 */
	public function test_deleted_staging_is_hidden_from_items_counts_and_recent( $view, $optimize ) {
		global $wpdb;
		$site = $this->create_test_site( array( 'is_staging' => 1 ) );
		$this->assertGreaterThan( 0, $this->event( $site ) );
		$this->assertSame( array( 'result' => 'SUCCESS' ), MainWP_Hooks::get_instance()->hook_delete_site( $site ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $wpdb->mainwp_tbl_wp, $site ) ) );
		$this->assertSame( 'deleted', $wpdb->get_var( $wpdb->prepare( 'SELECT action FROM %i WHERE site_id = %d', $wpdb->mainwp_tbl_logs, $site ) ) );
		$args = array(
			'wpid'     => $site,
			'view'     => $view,
			'optimize' => $optimize,
		);
		foreach ( array( true, false ) as $check ) {
			$query_args = $args + array( 'check_access' => $check );
			$this->assertSame( array( 'count' => 0 ), $this->records( $query_args + array( 'count_only' => true ) ) );
			$result = $this->records( $query_args );
			$this->assertCount( 0, $result['items'] );
			$this->assertSame( 0, $result['count'] );
			$recent = $this->records( $query_args + array( 'recent_number' => 5 ) );
			$this->assertCount( 0, $recent['items'] );
			$this->assertSame( 0, $recent['count'] );
		}
	}

	/** Nonzero staging values retain their raw identity and stay hidden after deletion. */
	public function test_deleted_nonbinary_staging_is_hidden() {
		global $wpdb;
		$site = $this->create_test_site( array( 'is_staging' => 2 ) );
		$this->assertSame( array( 'result' => 'SUCCESS' ), MainWP_Hooks::get_instance()->hook_delete_site( $site ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $wpdb->mainwp_tbl_wp, $site ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE site_id = %d', $wpdb->mainwp_tbl_logs, $site ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'site', 'sites', 'deleted' ), array( $rows[0]->connector, $rows[0]->context, $rows[0]->action ) );
		$this->assertSame(
			'2',
			$wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE meta_log_id = %d AND meta_key = %s', $wpdb->mainwp_tbl_logs_meta, $rows[0]->log_id, 'deleted_site_is_staging' ) )
		);
		$args = array(
			'wpid'             => $site,
			'start'            => 0,
			'records_per_page' => 20,
			'order'            => 'DESC',
			'orderby'          => 'created',
		);
		$this->assertSame( array(), $this->records( $args )['items'], 'A deleted site with staging value2 must stay hidden.' );
		$this->assertSame( 0, $this->records( $args )['count'] );
		$this->assertSame( array( 'count' => 0 ), $this->records( $args + array( 'count_only' => true ) ) );
		$recent = $this->records( $args + array( 'recent_number' => 5 ) );
		$this->assertSame( array(), $recent['items'] );
		$this->assertSame( 0, $recent['count'] );
		foreach ( array(
			array( 'with_all_logs_meta' => true ),
			array(
				'optimize'           => true,
				'optimize_with_meta' => true,
			),
		) as $mode ) {
			$api_args = $args + $mode + array( 'view' => 'api-view' );
			$direct   = $this->records( $api_args );
			$this->assertCount( 1, $direct['items'] );
			$this->assertSame( $rows[0]->log_id, $direct['items'][0]->log_id );
			$this->assertStringNotContainsString( 'deleted_site_', wp_json_encode( $direct['items'] ) );
			$this->assertEquals( $direct['items'], apply_filters( 'mainwp_module_logs_get_log_records', false, $api_args ) );
			$this->assertSame( array(), apply_filters( 'mainwp_module_logs_get_log_records', false, $args + $mode + array( 'view' => 'events_list' ) ) );
		}
	}

	/** Actual table rendering must not look up each deleted site's existence. */
	public function test_rendering_multiple_deleted_rows_has_no_per_row_site_queries() {
		global $wpdb;
		$sites = array();
		for ( $index = 0; $index < 3; ++$index ) {
			list( $sites[] ) = $this->deleted();
		}
		$result       = $this->records( array( 'wpid' => $sites ) );
		$table        = new Log_Events_List_Table( Log_Manager::instance() );
		$table->items = $result['items'];
		$this->assertCount( 3, $table->items );
		$queries = array();
		$observe = static function ( $sql ) use ( &$queries, $wpdb ) {
			if ( preg_match( '/^\s*SELECT\s+id\s+FROM\s+' . preg_quote( $wpdb->mainwp_tbl_wp, '/' ) . '\s+WHERE\s+id\s*=/i', str_replace( '`', '', $sql ) ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $wpdb->mainwp_tbl_wp, $sites[0] ) );
			$this->assertCount( 1, $queries, 'The observer must detect a real site-existence query.' );
			$queries = array();
			$rows    = $table->ajax_get_datatable_rows();
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertCount( 3, $rows['data'] );
		foreach ( $rows['data'] as $row ) {
			$this->assertStringContainsString( 'Beta &lt;b&gt;Audit&lt;/b&gt;', $row['name'] );
			$this->assertStringNotContainsString( '<a ', $row['name'] );
		}
		$this->assertCount( 0, $queries, 'Rendering query-returned rows must not issue per-site existence SELECTs: ' . wp_json_encode( $queries ) );
	}

	/** A live site's deletion-like event still renders its existing links. */
	public function test_live_deleted_action_retains_site_links() {
		$site   = $this->create_test_site( array( 'name' => 'Live deletion-like event' ) );
		$id     = $this->event( $site, 'deleted' );
		$result = $this->records( array( 'wpid' => $site ) );
		$this->assertSame( $id, (int) $result['items'][0]->log_id );
		$html = ( new Log_Events_List_Table( Log_Manager::instance() ) )->column_default( $result['items'][0], 'name' );
		$this->assertStringContainsString( 'admin.php?page=managesites&dashboard=' . $site, $html );
		$this->assertStringContainsString( 'Live deletion-like event', $html );
	}

	/** The More button counts and pages only the newly visible audit records. */
	public function test_optimized_pagination_with_deleted_and_excluded_rows() {
		global $wpdb;
		$live                   = $this->create_test_site();
		$first                  = $this->event( $live );
		list( $site, $deleted ) = $this->deleted();
		$last                   = $this->event( $live );
		$staging                = $this->create_test_site( array( 'is_staging' => 1 ) );
		$excluded               = $this->event( $staging, 'deleted' );
		$orphan                 = $this->event( 987655 );
		foreach ( array( $first, $deleted, $last, $excluded, $orphan ) as $index => $id ) {
			$wpdb->update( $wpdb->mainwp_tbl_logs, array( 'created' => ( $index + 1 ) * 1000000 ), array( 'log_id' => $id ) );
		}
		foreach ( array( $first, $deleted, $last ) as $page => $expected ) {
			$result = $this->records(
				array(
					'order'             => 'ASC',
					'start'             => $page,
					'records_per_page'  => 1,
					'optimize'          => true,
					'optimize_has_more' => true,
				)
			);
			$this->assertSame( array( $expected ), array_map( 'intval', wp_list_pluck( $result['items'], 'log_id' ) ) );
			$this->assertSame( $page < 2, $result['extra_data']['has_more'] );
			$this->assertSame( min( 3, $page + 2 ), $result['count'] );
		}
	}

	/** Scoped or anonymous audit callers cannot acquire orphan visibility. */
	public function test_scoped_and_anonymous_callers_are_hidden_even_without_access_check() {
		list( $site ) = $this->deleted();
		$scope        = static function () {
			return array( 987654 );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		try {
			foreach ( array( true, false ) as $check ) {
				$this->assertSame(
					0,
					$this->records(
						array(
							'wpid'         => $site,
							'check_access' => $check,
						)
					)['count']
				);
			}
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 0, $this->records( array( 'wpid' => $site ) )['count'] );
	}

	/** Only exact deletion events gain visibility; live and staging behavior stays stable. */
	public function test_sort_count_staging_and_other_orphans() {
		global $wpdb;
		$alpha = $this->create_test_site( array( 'name' => 'Alpha' ) );
		$zulu  = $this->create_test_site( array( 'name' => 'Zulu' ) );
		$stage = $this->create_test_site(
			array(
				'name'       => 'Staging',
				'is_staging' => 1,
			)
		);
		$a     = $this->event( $alpha );
		$z     = $this->event( $zulu );
		$this->event( $stage, 'deleted' );
		$this->event( 987651 );
		$wrong = $this->event( 987652, 'deleted' );
		$wpdb->update( $wpdb->mainwp_tbl_logs, array( 'connector' => 'other' ), array( 'log_id' => $wrong ) );
		$wrong_context = $this->event( 987653, 'deleted' );
		$wpdb->update( $wpdb->mainwp_tbl_logs, array( 'context' => 'other' ), array( 'log_id' => $wrong_context ) );
		list( $site, $deleted ) = $this->deleted();
		foreach ( array( 'fixture_site_name', 'fixture_site_url' ) as $key ) {
			$this->assertSame(
				1,
				$wpdb->insert(
					$wpdb->mainwp_tbl_logs_meta,
					array(
						'meta_log_id' => $deleted,
						'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single-row test fixture.
					'meta_value'      => 'fixture_site_name' === $key ? 'Beta <b>Audit</b>' : 'https://deleted.example/?a=1&b=2', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single-row test fixture.
					)
				)
			);
		}
		$result = $this->records(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$this->assertSame( array( $a, $deleted, $z ), array_map( 'intval', wp_list_pluck( $result['items'], 'log_id' ) ) );
		$this->assertSame( 3, $result['count'] );
		$searched = $this->records( array( 'search' => 'Beta' ) );
		$this->assertCount( 1, $searched['items'] );
		$this->assertSame( 1, $searched['count'] );
		$this->assertSame( 4, $this->records( array( 'check_access' => false ) )['count'] );
		$this->assertSame(
			array( $a, $z ),
			array_map(
				'intval',
				wp_list_pluck(
					$this->records(
						array(
							'sites_ids' => array( $alpha, $zulu ),
							'orderby'   => 'name',
							'order'     => 'ASC',
						)
					)['items'],
					'log_id'
				)
			)
		);
		$this->assertSame(
			0,
			$this->records(
				array(
					'wpid'          => $stage,
					'recent_number' => 5,
				)
			)['count']
		);
	}

	/**
	 * Public and API query combinations.
	 *
	 * @return array
	 */
	public static function public_views() {
		$cases = array();
		foreach ( array( false, true ) as $public_hook ) {
			foreach ( array( 'api-view', 'events_list', '' ) as $view ) {
				if ( ! $public_hook && 'api-view' !== $view ) {
					continue;
				}
				foreach ( array(
					array(),
					array( 'with_all_logs_meta' => true ),
					array(
						'optimize'           => true,
						'optimize_with_meta' => true,
					),
				) as $mode ) {
					$cases[] = array( $public_hook, $view, $mode );
				}
			}
		}
		return $cases;
	}

	/**
	 * Private deletion metadata cannot change any public record field or value.
	 *
	 * @dataProvider public_views
	 * @param bool   $public_hook Public hook context.
	 * @param string $view Query view.
	 * @param array  $mode Metadata mode.
	 */
	public function test_public_and_api_output_parity( $public_hook, $view, $mode ) {
		global $wpdb;
		list( $site, $id ) = $this->deleted();
		$live              = $this->create_test_site( array( 'name' => 'Live' ) );
		$live_log          = $this->event( $live, 'deleted' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s,%s,%s)', $wpdb->mainwp_tbl_logs_meta, 'deleted_site_name', 'deleted_site_url', 'deleted_site_is_staging' ) );
		$args  = $mode + array(
			'view'             => $view,
			'start'            => 0,
			'records_per_page' => 20,
			'order'            => 'DESC',
			'orderby'          => 'created',
		);
		$query = function () use ( $args, $public_hook ) {
			return $public_hook ? apply_filters( 'mainwp_module_logs_get_log_records', false, $args ) : $this->records( $args )['items'];
		};
		$this->records( $args );
		$before = $query();
		$this->assertCount( 'api-view' === $view ? 2 : 1, $before );
		foreach ( array( $id, $live_log ) as $log ) {
			foreach ( array(
				'deleted_site_name'       => 'Private identity',
				'deleted_site_url'        => 'https://private.example/',
				'deleted_site_is_staging' => 1,
			) as $key => $value ) {
				$wpdb->insert(
					$wpdb->mainwp_tbl_logs_meta,
					array(
						'meta_log_id' => $log,
						'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single-row test fixture.
						'meta_value'  => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single-row test fixture.
					)
				);
			}
		}
		$this->assertEquals( $before, $query(), 'Every public property and value must match the metadata-free baseline.' );
		$this->assertStringNotContainsString( 'deleted_site_', wp_json_encode( $query() ) );
		foreach ( $query() as $item ) {
			$this->assertArrayNotHasKey( 'deleted_site_orphan', get_object_vars( $item ), 'Internal rendering state must not become a public property.' );
		}
	}

	/** Deletion events remain subject to the existing age archive policy. */
	public function test_age_archive_still_moves_deleted_site_events() {
		global $wpdb;
		list( $site, $id ) = $this->deleted();
		Log_DB_Archive::instance()->archive_sites_changes( time() - DAY_IN_SECONDS );
		$this->assertSame( $id, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT log_id FROM %i WHERE log_id = %d', $wpdb->mainwp_tbl_logs, $id ) ) );
		$wpdb->update( $wpdb->mainwp_tbl_logs, array( 'created' => ( time() - 2 * DAY_IN_SECONDS ) * 1000000 ), array( 'log_id' => $id ) );
		Log_DB_Archive::instance()->archive_sites_changes( time() - DAY_IN_SECONDS );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT log_id FROM %i WHERE log_id = %d', $wpdb->mainwp_tbl_logs, $id ) ) );
		$this->assertSame( $id, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT log_id FROM %i WHERE log_id = %d', MainWP_DB::instance()->get_table_name( 'wp_logs_archive' ), $id ) ) );
	}

	/** Existing-site names, counts, staging and links retain their behavior. */
	public function test_existing_site_baseline() {
		$alpha = $this->create_test_site(
			array(
				'name' => 'Alpha',
				'url'  => 'https://alpha.example/',
			)
		);
		$zulu  = $this->create_test_site( array( 'name' => 'Zulu' ) );
		$stage = $this->create_test_site(
			array(
				'name'       => 'Staging',
				'is_staging' => 1,
			)
		);
		$a     = $this->event( $alpha );
		$z     = $this->event( $zulu );
		$this->event( $stage, 'deleted' );
		$result = $this->records(
			array(
				'orderby' => 'name',
				'order'   => 'ASC',
			)
		);
		$this->assertSame( array( $a, $z ), array_map( 'intval', wp_list_pluck( $result['items'], 'log_id' ) ) );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 3, $this->records( array( 'check_access' => false ) )['count'] );
		$html = ( new Log_Events_List_Table( Log_Manager::instance() ) )->column_default( $result['items'][0], 'name' );
		$this->assertStringContainsString( 'admin.php?page=managesites&amp;dashboard=', str_replace( '&dashboard=', '&amp;dashboard=', $html ) );
		$this->assertStringContainsString( 'https://alpha.example/', $html );
	}

	/** Age compaction removes old deletion events while leaving fresh ones alone. */
	public function test_age_compaction_is_unchanged() {
		global $wpdb;
		list( $site, $id ) = $this->deleted();
		$cutoff            = ( time() - DAY_IN_SECONDS ) * 1000000;
		$db                = Log_Manager::instance()->db;
		$db->create_compact_and_erase_records( 0, $cutoff );
		$this->assertSame( $id, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT log_id FROM %i WHERE log_id = %d', $wpdb->mainwp_tbl_logs, $id ) ) );
		$wpdb->update( $wpdb->mainwp_tbl_logs, array( 'created' => $cutoff - DAY_IN_SECONDS * 1000000 ), array( 'log_id' => $id ) );
		$db->create_compact_and_erase_records( 0, $cutoff );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT log_id FROM %i WHERE log_id = %d', $wpdb->mainwp_tbl_logs, $id ) ) );
	}

	/** Historical deletion rows remain visible without synthesizing identity. */
	public function test_legacy_deletion_without_identity() {
		$id     = $this->event( 987659, 'deleted' );
		$result = $this->records( array( 'wpid' => 987659 ) );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( $id, (int) $result['items'][0]->log_id );
		$this->assertNull( $result['items'][0]->log_site_name );
		$this->assertSame(
			array( 'count' => 1 ),
			$this->records(
				array(
					'wpid'       => 987659,
					'count_only' => true,
				)
			)
		);
		$this->assertSame(
			1,
			$this->records(
				array(
					'wpid'          => 987659,
					'recent_number' => 5,
				)
			)['count']
		);
		$this->assertSame( 'N/A', ( new Log_Events_List_Table( Log_Manager::instance() ) )->column_default( $result['items'][0], 'name' ) );
	}
}
