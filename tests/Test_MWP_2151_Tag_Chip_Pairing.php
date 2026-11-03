<?php
/**
 * Tag chips pair each tag's name, link and color by tag ID.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class Test_MWP_2151_Tag_Chip_Pairing
 */
class Test_MWP_2151_Tag_Chip_Pairing extends \WP_UnitTestCase {

	use Site_Fixture;

	/** Sign in as a Dashboard administrator. */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Sign out before the next test. */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Site rows: a comma name stays one chip and same-name tags keep their own IDs and colors. */
	public function test_site_row_chips_pair_by_tag_id(): void {
		$tags    = $this->create_problem_tags();
		$site_id = $this->create_tagged_site( 0, $tags );

		$html = MainWP_System_Utility::get_site_tags( $this->site_row( $site_id ) );

		$this->assertSame( $this->expected_chips( $tags ), $this->parse_chips( $html, 'admin.php?page=managesites&g=' ) );
	}

	/** Manage Sites rows filtered by tag render through get_site_tags_belong(). */
	public function test_belong_row_chips_pair_by_tag_id(): void {
		$tags    = $this->create_problem_tags();
		$site_id = $this->create_tagged_site( 0, $tags );

		$html = MainWP_System_Utility::get_site_tags_belong( $this->belong_row( $site_id ) );

		$this->assertSame( $this->expected_chips( $tags ), $this->parse_chips( $html, 'admin.php?page=managesites&g=' ) );
	}

	/** Manage Clients Tags column: one chip per tag, each with its own ID and color. */
	public function test_client_row_chips_pair_by_tag_id(): void {
		$tags   = $this->create_problem_tags();
		$client = $this->create_client();
		$this->create_tagged_site( $client->client_id, $tags );
		$this->create_tagged_site( $client->client_id, array( $tags[1] ) );

		$html = MainWP_System_Utility::get_site_tags( $this->client_row( $client ), true );

		$this->assertSame( $this->expected_chips( $tags ), $this->parse_chips( $html, 'admin.php?page=ManageClients&tags=' ) );
	}

	/** A client row is paired by ID even when it carries a color list and its names are unique. */
	public function test_client_row_with_colors_still_pairs_by_id(): void {
		$beta  = $this->create_tag( 'Beta', '#1565c0' );
		$alpha = $this->create_tag( 'Alpha', '#c62828' );
		$row   = array(
			'wpgroups'        => 'Alpha,Beta',
			'wpgroupids'      => $beta['id'] . ',' . $alpha['id'],
			'wpgroups_colors' => '#c62828,#1565c0',
		);

		$html = MainWP_System_Utility::get_site_tags( $row, true );

		$this->assertSame( $this->expected_chips( array( $alpha, $beta ) ), $this->parse_chips( $html, 'admin.php?page=ManageClients&tags=' ) );
	}

	/** client_tags_filter() returns one entry per tag, with each name next to its own ID. */
	public function test_client_tags_filter_lines_up_names_and_ids(): void {
		$tags   = $this->create_problem_tags();
		$client = $this->create_client();
		$this->create_tagged_site( $client->client_id, $tags );
		$this->create_tagged_site( $client->client_id, array( $tags[2] ) );

		$filtered = MainWP_System_Utility::client_tags_filter( $this->client_row( $client ) );

		$this->assertSame( array( 'wpgroups', 'wpgroupids' ), array_keys( $filtered ) );
		$this->assertCount( 3, $filtered['wpgroupids'] );
		$pairs = array_combine( $filtered['wpgroupids'], $filtered['wpgroups'] );
		ksort( $pairs );
		$expected = array();
		foreach ( $tags as $tag ) {
			$expected[ (string) $tag['id'] ] = $tag['name'];
		}
		ksort( $expected );
		$this->assertSame( $expected, $pairs );
	}

	/** Ordinary site tags render the same bytes as before, in name order whatever order they were created in. */
	public function test_ordinary_site_tags_render_unchanged(): void {
		$plain = $this->create_tag( 'Gamma', '' );
		$alpha = $this->create_tag( 'Alpha', '#1b5e20' );
		$beta  = $this->create_tag( 'Beta', '#ffeb3b' );
		$site  = $this->create_tagged_site( 0, array( $plain, $alpha, $beta ) );

		$html = $this->expected_html( array( $alpha, $beta, $plain ), 'admin.php?page=managesites&g=' );
		$this->assertStringStartsWith(
			'<span style="background-color:#1b5e20;color:#ffffff" tag_id="' . $alpha['id'] . '" class="ui tag mini label"><a style="color:#ffffff!important;opacity:1;" href="admin.php?page=managesites&#038;g=' . $alpha['id'] . '">Alpha</a></span>',
			$html
		);

		$this->assertSame( $html, MainWP_System_Utility::get_site_tags( $this->site_row( $site ) ) );
		$this->assertSame( $html, MainWP_System_Utility::get_site_tags( $this->search_row( $site ) ) );
		$this->assertSame( $html, MainWP_System_Utility::get_site_tags_belong( $this->belong_row( $site ) ) );
	}

	/** Ordinary client tags whose IDs already sort like their names render the same bytes as before. */
	public function test_ordinary_client_tags_render_unchanged(): void {
		$alpha  = $this->create_tag( 'Alpha', '#1b5e20' );
		$beta   = $this->create_tag( 'Beta', '#ffeb3b' );
		$plain  = $this->create_tag( 'Gamma', '' );
		$client = $this->create_client();
		$this->create_tagged_site( $client->client_id, array( $alpha, $beta, $plain ) );

		$html = $this->expected_html( array( $alpha, $beta, $plain ), 'admin.php?page=ManageClients&tags=' );

		$this->assertSame( $html, MainWP_System_Utility::get_site_tags( $this->client_row( $client ), true ) );
	}

	/** The Manage Sites and screenshots query drops repeated names and colors with DISTINCT. */
	public function test_distinct_site_row_chips_pair_by_tag_id(): void {
		$tags    = $this->create_problem_tags();
		$site_id = $this->create_tagged_site( 0, $tags );

		$row = $this->search_row( $site_id );
		$this->assertCount( 3, explode( ',', $row['wpgroups'] ), 'DISTINCT names plus the comma split give as many pieces as IDs.' );

		$html = MainWP_System_Utility::get_site_tags( $row );

		$this->assertSame( $this->expected_chips( $tags ), $this->parse_chips( $html, 'admin.php?page=managesites&g=' ) );
	}

	/** A single-tag row pairs from the row itself; a multi-tag row costs one query, not one per chip. */
	public function test_tag_lookup_query_count(): void {
		global $wpdb;
		$single = $this->site_row( $this->create_tagged_site( 0, array( $this->create_tag( 'Alpha', '#1b5e20' ) ) ) );
		$multi  = $this->site_row( $this->create_tagged_site( 0, $this->create_problem_tags() ) );

		$before = $wpdb->num_queries;
		MainWP_System_Utility::get_site_tags( $single );
		$this->assertSame( $before, $wpdb->num_queries );

		$before = $wpdb->num_queries;
		MainWP_System_Utility::get_site_tags( $multi );
		$this->assertSame( $before + 1, $wpdb->num_queries );
	}

	/**
	 * Create "Acme, Inc" and two different tags both named "Staff", each with its own color.
	 *
	 * @return array[]
	 */
	private function create_problem_tags(): array {
		return array(
			$this->create_tag( 'Acme, Inc', '#c62828' ),
			$this->create_tag( 'Staff', '#1565c0' ),
			$this->create_tag( 'Staff', '#ffeb3b' ),
		);
	}

	/**
	 * Create a tag.
	 *
	 * @param string $name  Tag name.
	 * @param string $color Tag color.
	 * @return array{id:int,name:string,color:string}
	 */
	private function create_tag( $name, $color ): array {
		$id = (int) MainWP_DB_Common::instance()->add_group( get_current_user_id(), $name, $color );
		$this->assertGreaterThan( 0, $id );
		return array(
			'id'    => $id,
			'name'  => $name,
			'color' => $color,
		);
	}

	/**
	 * Create a client.
	 *
	 * @return object
	 */
	private function create_client() {
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => 'Client ' . wp_generate_uuid4(),
				'client_email' => wp_generate_uuid4() . '@example.com',
				'created'      => time(),
			),
			true
		);
		$this->assertIsObject( $client );
		return $client;
	}

	/**
	 * Create a site carrying the given tags.
	 *
	 * @param int   $client_id Client ID, 0 for none.
	 * @param array $tags      Tags from create_tag().
	 * @return int Site ID.
	 */
	private function create_tagged_site( $client_id, array $tags ): int {
		$site_id = $this->create_test_site( array( 'client_id' => (int) $client_id ) );
		foreach ( $tags as $tag ) {
			MainWP_DB_Common::instance()->update_group_site( $tag['id'], $site_id );
		}
		return $site_id;
	}

	/**
	 * The site row as the site lists read it, with tag columns.
	 *
	 * @param int $site_id Site ID.
	 * @return array
	 */
	private function site_row( $site_id ): array {
		$row = MainWP_DB::instance()->get_website_by_id( $site_id, true, array(), ARRAY_A );
		$this->assertIsArray( $row );
		return $row;
	}

	/**
	 * The site row from the query behind the Manage Sites list and the screenshots view.
	 *
	 * @param int $site_id Site ID.
	 * @return array
	 */
	private function search_row( $site_id ): array {
		global $wpdb;
		$sql  = MainWP_DB::instance()->get_sql_search_websites_for_current_user(
			array(
				'selectgroups'   => true,
				'selected_sites' => array( $site_id ),
			)
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built by the Dashboard.
		$rows = array_values(
			array_filter(
				(array) $rows,
				function ( $row ) use ( $site_id ) {
					return (int) $row['id'] === (int) $site_id;
				}
			)
		);
		$this->assertCount( 1, $rows );
		return $rows[0];
	}

	/**
	 * The site row with the tag columns Manage Sites selects when filtering by tag.
	 *
	 * @param int $site_id Site ID.
	 * @return array
	 */
	private function belong_row( $site_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT wp.id' . MainWP_DB::instance()->get_select_groups_belong() . ' FROM ' . MainWP_DB::instance()->get_table_name( 'wp' ) . ' wp WHERE wp.id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name and fixed SQL fragment.
				$site_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertNotEmpty( $row['wpgroups_belong'] );
		return $row;
	}

	/**
	 * The client's row as Manage Clients reads it.
	 *
	 * @param object $client Client.
	 * @return array
	 */
	private function client_row( $client ): array {
		$rows = MainWP_DB_Client::instance()->get_wp_clients(
			array(
				'client'    => (string) $client->client_id,
				'with_tags' => true,
			)
		);
		$this->assertCount( 1, $rows );
		return $rows[0];
	}

	/**
	 * Expected chips keyed by tag ID.
	 *
	 * @param array $tags Tags from create_tag().
	 * @return array
	 */
	private function expected_chips( array $tags ): array {
		$chips = array();
		foreach ( $tags as $tag ) {
			$chips[ (string) $tag['id'] ] = array(
				'name'  => $tag['name'],
				'color' => $tag['color'],
			);
		}
		ksort( $chips );
		return $chips;
	}

	/**
	 * Read each chip's ID, link ID, name and background color from the rendered HTML.
	 *
	 * @param string $html Rendered chips.
	 * @param string $href Expected link prefix.
	 * @return array Chips keyed by tag ID.
	 */
	private function parse_chips( $html, $href ): array {
		$count = preg_match_all( '/<span (?:style="background-color:(#[0-9a-f]{6});[^"]*")? tag_id="(\d+)" class="ui tag mini label"><a [^>]*href="([^"]*)">([^<]*)<\/a><\/span>/', $html, $matches, PREG_SET_ORDER );
		$this->assertSame( substr_count( $html, '<span ' ), $count, $html );

		$chips = array();
		foreach ( $matches as $match ) {
			$this->assertSame( esc_url( $href . $match[2] ), $match[3] );
			$this->assertArrayNotHasKey( $match[2], $chips, 'One chip per tag.' );
			$chips[ $match[2] ] = array(
				'name'  => html_entity_decode( $match[4], ENT_QUOTES ),
				'color' => $match[1],
			);
		}
		ksort( $chips );
		return $chips;
	}

	/**
	 * The chips main renders for ordinary tags, built with the shared chip builder.
	 *
	 * @param array  $tags Tags from create_tag(), in name order.
	 * @param string $href Link prefix.
	 * @return string
	 */
	private function expected_html( array $tags, $href ): string {
		$label = new \ReflectionMethod( MainWP_System_Utility::class, 'get_site_tag_label' );
		$html = '';
		foreach ( $tags as $tag ) {
			$html .= $label->invoke( null, $tag['name'], (string) $tag['id'], $href, $tag['color'] );
		}
		return $html;
	}
}
