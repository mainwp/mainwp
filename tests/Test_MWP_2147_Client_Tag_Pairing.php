<?php
/**
 * Client tag IDs keep their own names.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class Test_MWP_2147_Client_Tag_Pairing
 */
class Test_MWP_2147_Client_Tag_Pairing extends \WP_UnitTestCase {

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

	/** The tag created first sorts last by name, so ID order and name order differ. */
	public function test_tag_names_follow_their_ids_when_name_order_differs(): void {
		$zeta   = $this->create_tag( 'Zeta' );
		$alpha  = $this->create_tag( 'Alpha' );
		$client = $this->create_client();
		$this->create_tagged_site( $client, array( $zeta, $alpha ) );

		$this->assertLessThan( $alpha['id'], $zeta['id'] );
		$this->assertSame( $this->expected_tags( $zeta, $alpha ), $this->client_tags( $client ) );
	}

	/** A tag on two of the client's sites appears once and does not shift the others. */
	public function test_tag_shared_by_two_sites_keeps_every_pairing(): void {
		$zeta   = $this->create_tag( 'Zeta' );
		$shared = $this->create_tag( 'Mid' );
		$alpha  = $this->create_tag( 'Alpha' );
		$client = $this->create_client();
		$this->create_tagged_site( $client, array( $zeta, $shared ) );
		$this->create_tagged_site( $client, array( $shared, $alpha ) );

		$this->assertSame( $this->expected_tags( $zeta, $shared, $alpha ), $this->client_tags( $client ) );
	}

	/** A comma inside a tag name stays in that name and does not shift the later tags. */
	public function test_comma_in_tag_name_does_not_shift_later_names(): void {
		$comma  = $this->create_tag( 'Acme, Inc' );
		$zeta   = $this->create_tag( 'Zeta' );
		$client = $this->create_client();
		$this->create_tagged_site( $client, array( $comma, $zeta ) );

		$this->assertSame( $this->expected_tags( $comma, $zeta ), $this->client_tags( $client ) );
	}

	/** Clients without tagged sites still get no tags key. */
	public function test_client_without_tags_has_no_tags_key(): void {
		$untagged = $this->create_client();
		$this->create_tagged_site( $untagged, array() );
		$no_sites = $this->create_client();

		foreach ( array( $untagged, $no_sites ) as $client ) {
			$row = $this->client_row( $client );
			$this->assertArrayNotHasKey( 'tags', $row );
			$this->assertEmpty( $row['wpgroups'] );
			$this->assertEmpty( $row['wpgroupids'] );
		}
	}

	/** One tag on one site keeps the existing output. */
	public function test_single_tag_client_is_unchanged(): void {
		$solo   = $this->create_tag( 'Solo' );
		$client = $this->create_client();
		$this->create_tagged_site( $client, array( $solo ) );

		$row = $this->client_row( $client );
		$this->assertSame( array( $solo['id'] => $solo['name'] ), $row['tags'] );
		$this->assertSame( $solo['name'], $row['wpgroups'] );
		$this->assertSame( (string) $solo['id'], $row['wpgroupids'] );
	}

	/** Leaving out with_tags adds neither the tags map nor the raw columns. */
	public function test_without_with_tags_no_tag_data_is_returned(): void {
		$client = $this->create_client();
		$this->create_tagged_site( $client, array( $this->create_tag( 'Solo' ) ) );

		$rows = MainWP_DB_Client::instance()->get_wp_clients( array( 'client' => (string) $client->client_id ) );
		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'tags', $rows[0] );
		$this->assertArrayNotHasKey( 'wpgroups', $rows[0] );
	}

	/** The raw columns line up by position, so index-pairing readers get the right ID. */
	public function test_raw_tag_columns_pair_by_position(): void {
		$zeta   = $this->create_tag( 'Zeta' );
		$shared = $this->create_tag( 'Mid' );
		$alpha  = $this->create_tag( 'Alpha' );
		$client = $this->create_client();
		$this->create_tagged_site( $client, array( $zeta, $shared ) );
		$this->create_tagged_site( $client, array( $shared, $alpha ) );

		$expected = array(
			$alpha['name']  => (string) $alpha['id'],
			$shared['name'] => (string) $shared['id'],
			$zeta['name']   => (string) $zeta['id'],
		);
		$this->assertSame( $expected, $this->pair_by_position( $this->client_row( $client ) ) );

		$by_row = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id, ARRAY_A, array( 'with_tags' => true ) );
		$this->assertSame( $expected, $this->pair_by_position( $by_row ) );
	}

	/** The Manage Clients Tags column links each tag name to its own ID. */
	public function test_client_list_tag_labels_link_to_their_own_ids(): void {
		$zeta   = $this->create_tag( 'Zeta' );
		$shared = $this->create_tag( 'Mid' );
		$alpha  = $this->create_tag( 'Alpha' );
		$client = $this->create_client();
		$this->create_tagged_site( $client, array( $zeta, $shared ) );
		$this->create_tagged_site( $client, array( $shared, $alpha ) );

		$rows = MainWP_DB_Client::instance()->get_wp_client_by( 'all', null, ARRAY_A, array( 'with_tags' => true ) );
		$rows = array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $client ) {
					return (int) $row['client_id'] === (int) $client->client_id;
				}
			)
		);
		$this->assertCount( 1, $rows );

		$html = MainWP_System_Utility::get_site_tags( $rows[0], true );
		foreach ( array( $zeta, $shared, $alpha ) as $tag ) {
			$this->assertMatchesRegularExpression(
				'/tag_id="' . $tag['id'] . '"[^>]*>\s*<a[^>]*href="[^"]*tags=' . $tag['id'] . '"[^>]*>' . preg_quote( $tag['name'], '/' ) . '<\/a>/',
				$html
			);
		}
		$this->assertSame( 3, substr_count( $html, 'tag_id="' ) );
	}

	/**
	 * Create a tag with a unique name that keeps the given prefix for sorting.
	 *
	 * @param string $prefix Sort prefix.
	 * @return array{id:int,name:string}
	 */
	private function create_tag( $prefix ): array {
		$name = $prefix . ' ' . wp_generate_password( 8, false );
		$id   = (int) MainWP_DB_Common::instance()->add_group( get_current_user_id(), $name, '' );
		$this->assertGreaterThan( 0, $id );
		return array(
			'id'   => $id,
			'name' => $name,
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
	 * Create a site for the client carrying the given tags.
	 *
	 * @param object $client Client.
	 * @param array  $tags   Tags from create_tag().
	 * @return int Site ID.
	 */
	private function create_tagged_site( $client, array $tags ): int {
		$site_id = $this->create_test_site( array( 'client_id' => $client->client_id ) );
		foreach ( $tags as $tag ) {
			MainWP_DB_Common::instance()->update_group_site( $tag['id'], $site_id );
		}
		return $site_id;
	}

	/**
	 * Fetch the client's row from get_wp_clients() with tags.
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
	 * The client's tags map.
	 *
	 * @param object $client Client.
	 * @return array
	 */
	private function client_tags( $client ): array {
		$row = $this->client_row( $client );
		$this->assertArrayHasKey( 'tags', $row );
		return $row['tags'];
	}

	/**
	 * Build the expected id => name map in ID order.
	 *
	 * @param array ...$tags Tags from create_tag().
	 * @return array
	 */
	private function expected_tags( ...$tags ): array {
		$map = array();
		foreach ( $tags as $tag ) {
			$map[ $tag['id'] ] = $tag['name'];
		}
		ksort( $map );
		return $map;
	}

	/**
	 * Pair the raw wpgroups and wpgroupids columns by position, keeping the first ID per name.
	 *
	 * @param array $row Client row.
	 * @return array name => id
	 */
	private function pair_by_position( array $row ): array {
		$names = explode( ',', $row['wpgroups'] );
		$ids   = explode( ',', $row['wpgroupids'] );
		$this->assertCount( count( $names ), $ids );
		$pairs = array();
		foreach ( $names as $idx => $name ) {
			if ( ! isset( $pairs[ $name ] ) ) {
				$pairs[ $name ] = $ids[ $idx ];
			}
		}
		return $pairs;
	}
}
