<?php
/**
 * REST tag updates validate before writing and hold the site lock.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Common;

class Test_MWP_1885_Site_Group_Update extends \WP_UnitTestCase {
    use Site_Fixture;

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    }

    /** @dataProvider non_array_payloads */
    public function test_non_array_payload_keeps_existing_tags( $data ): void {
        $site = $this->create_test_site();
        $db = MainWP_DB_Common::instance();
        $old = $this->group();
        $db->update_group_site( $old, $site );
        try {
            $db->rest_api_update_website( $site, $data );
        } catch ( \Throwable $error ) {
            $this->fail( 'Non-array REST payload must not throw: ' . $error->getMessage() );
        }
        $this->assertSame( array( $old ), array_values( array_map( 'intval', wp_list_pluck( $db->get_groups_by_website_id( $site ), 'id' ) ) ) );
    }

    public static function non_array_payloads(): array {
        return array( 'null' => array( null ), 'string' => array( 'abc' ) );
    }

    /** @dataProvider invalid_group_ids */
    public function test_invalid_groupids_keep_tags_without_group_writes( $input ): void {
        global $wpdb;
        $site = $this->create_test_site();
        $old = $this->group();
        MainWP_DB_Common::instance()->update_group_site( $old, $site );
        $before = $this->groups_snapshot();
        $queries = array();
        $capture = static function ( $query ) use ( &$queries ) { $queries[] = $query; return $query; };
        add_filter( 'query', $capture );
        try {
            $result = MainWP_DB_Common::instance()->rest_api_update_website( $site, array( 'groupids' => $input ) );
        } finally {
            remove_filter( 'query', $capture );
        }
        $this->assertSame( array( 'group_updated' => false, 'group_message' => 'Failed to update tags or invalid tag IDs.' ), $result );
        $this->assertSame( $before, $this->groups_snapshot() );
        $this->assertSame( array(), $this->group_writes( $queries ) );
    }

    public static function invalid_group_ids(): array {
        return array(
            'comma list inside array' => array( array( '1,2' ) ),
            'nested array' => array( array( array( 1 ) ) ),
            'zero in array' => array( array( 0 ) ),
            'negative in array' => array( array( -1 ) ),
            'word in array' => array( array( 'bad' ) ),
            'malformed string' => array( '1,bad' ),
            'zero string' => array( '0' ),
            'scalar number' => array( 1 ),
            'null' => array( null ),
            'boolean' => array( true ),
            'decimal' => array( 1.5 ),
        );
    }

    /** @dataProvider valid_group_formats */
    public function test_valid_groupids_replace_tags_inside_lock( $format ): void {
        $site = $this->create_test_site();
        $db = MainWP_DB_Common::instance();
        $old = $this->group();
        $new = $this->group();
        $db->update_group_site( $old, $site );
        $queries = array();
        $capture = static function ( $query ) use ( &$queries ) { $queries[] = $query; return $query; };
        add_filter( 'query', $capture );
        try {
            $result = $db->rest_api_update_website( $site, array( 'groupids' => 'array' === $format ? array( $new, (string) $new ) : (string) $new ) );
        } finally {
            remove_filter( 'query', $capture );
        }
        $this->assertTrue( $result['group_updated'] );
        $this->assertSame( array( $new ), array_values( array_map( 'intval', wp_list_pluck( $db->get_groups_by_website_id( $site ), 'id' ) ) ) );
        $events = array();
        foreach ( $queries as $query ) {
            if ( false !== strpos( $query, 'GET_LOCK(' ) ) {
                $events[] = 'lock';
            } elseif ( false !== strpos( $query, 'RELEASE_LOCK(' ) ) {
                $events[] = 'release';
            } elseif ( $this->group_writes( array( $query ) ) ) {
                $events[] = preg_match( '/^\s*INSERT/i', $query ) ? 'insert' : 'delete';
            }
        }
        $this->assertSame( array( 'lock', 'insert', 'delete', 'release' ), $events );
    }

    public static function valid_group_formats(): array {
        return array( 'string' => array( 'string' ), 'array' => array( 'array' ) );
    }

    public function test_lock_failure_keeps_existing_tags_without_group_writes(): void {
        $site = $this->create_test_site();
        $db = MainWP_DB_Common::instance();
        $old = $this->group();
        $new = $this->group();
        $db->update_group_site( $old, $site );
        $before = $this->groups_snapshot();
        $queries = array();
        $refuse_lock = static function ( $query ) use ( &$queries ) {
            $queries[] = $query;
            return false !== strpos( $query, 'GET_LOCK(' ) ? 'SELECT 0' : $query;
        };
        add_filter( 'query', $refuse_lock );
        try {
            $result = $db->rest_api_update_website( $site, array( 'groupids' => (string) $new ) );
        } finally {
            remove_filter( 'query', $refuse_lock );
        }
        $this->assertFalse( $result['group_updated'] );
        $this->assertSame( $before, $this->groups_snapshot() );
        $this->assertSame( array(), $this->group_writes( $queries ) );
    }

    private function group(): int {
        $id = (int) MainWP_DB_Common::instance()->add_group( get_current_user_id(), 'D57 ' . wp_generate_uuid4(), '#cce2ff' );
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    private function groups_snapshot(): array {
        global $wpdb;
        return array(
            $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_group ORDER BY id", ARRAY_A ),
            $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_group ORDER BY wpid, groupid", ARRAY_A ),
        );
    }

    private function group_writes( $queries ): array {
        global $wpdb;
        return array_values( array_filter( $queries, static function ( $query ) use ( $wpdb ) {
            return preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) && preg_match( '/`?' . preg_quote( $wpdb->prefix, '/' ) . 'mainwp_(?:wp_group|wpgroup|group)`?\b/i', $query );
        } ) );
    }
}
