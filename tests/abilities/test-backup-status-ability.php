<?php
/**
 * Tests for mainwp/get-backup-status-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/get-backup-status-v1.
 *
 * @group abilities
 * @group abilities-backups
 */
class Test_Backup_Status_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Ability is registered and discoverable.
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();
        $abilities = wp_get_abilities();
        $this->assertArrayHasKey( 'mainwp/get-backup-status-v1', $abilities );
    }

    /**
     * Happy path: stale vs fresh backups classified by threshold, items-level.
     *
     * Asserts deltas rather than absolute totals: this ability queries the whole
     * network unscoped, so a shared test DB that already has rows would make
     * absolute-count assertions flaky. Capturing a baseline before seeding
     * fixtures keeps the assertions correct regardless of what else is in the DB.
     */
    public function test_backup_status_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $baseline = $this->read_status( [ 'threshold_days' => 7, 'per_page' => 100 ] );
        $this->assertNotWPError( $baseline );

        $fresh_id = $this->create_test_site( [ 'name' => 'Fresh', 'url' => 'https://fresh.example/' ] );
        $stale_id = $this->create_test_site( [ 'name' => 'Stale', 'url' => 'https://stale.example/' ] );
        $never_id = $this->create_test_site( [ 'name' => 'Never', 'url' => 'https://never.example/' ] );
        $this->set_site_option( $fresh_id, 'primary_lasttime_backup', time() - DAY_IN_SECONDS );
        $this->set_site_option( $stale_id, 'primary_lasttime_backup', time() - 30 * DAY_IN_SECONDS );

        $result = $this->read_status( [ 'threshold_days' => 7, 'per_page' => 100 ] );

        $this->assertNotWPError( $result );
        $this->assertSame( 3, $result['totals']['sites'] - $baseline['totals']['sites'] );
        $this->assertSame( 2, $result['totals']['stale'] - $baseline['totals']['stale'] );
        $this->assertSame( 1, $result['totals']['never'] - $baseline['totals']['never'] );
        $by_id = array_column( $result['items'], null, 'site_id' );
        $this->assertFalse( $by_id[ $fresh_id ]['is_stale'] );
        $this->assertSame( 'child_reported', $by_id[ $fresh_id ]['source'] );
        $this->assertTrue( $by_id[ $stale_id ]['is_stale'] );
        $this->assertTrue( $by_id[ $never_id ]['is_stale'] );
        $this->assertSame( 'none', $by_id[ $never_id ]['source'] );
        $this->assertSame( 7, $result['filters']['threshold_days'] );
    }

    /**
     * only_stale narrows items but totals still describe the whole fleet.
     *
     * Fleet-dependent counts (item count, totals.sites absolute value) aren't
     * asserted; instead the seeded fresh site is confirmed absent from items
     * and the seeded never-backed-up site is confirmed present and stale.
     */
    public function test_backup_status_only_stale_filters_items() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $baseline = $this->read_status( [ 'threshold_days' => 7, 'only_stale' => true, 'per_page' => 100 ] );
        $this->assertNotWPError( $baseline );

        $fresh_id = $this->create_test_site( [ 'name' => 'Fresh2', 'url' => 'https://fresh2.example/' ] );
        $this->set_site_option( $fresh_id, 'primary_lasttime_backup', time() - HOUR_IN_SECONDS );
        $never_id = $this->create_test_site( [ 'name' => 'Never2', 'url' => 'https://never2.example/' ] );

        $result = $this->read_status( [ 'threshold_days' => 7, 'only_stale' => true, 'per_page' => 100 ] );

        $this->assertNotWPError( $result );
        $this->assertSame( 2, $result['totals']['sites'] - $baseline['totals']['sites'] );
        $by_id = array_column( $result['items'], null, 'site_id' );
        $this->assertArrayNotHasKey( $fresh_id, $by_id );
        $this->assertArrayHasKey( $never_id, $by_id );
        $this->assertTrue( $by_id[ $never_id ]['is_stale'] );
    }

    /**
     * A group-targeted task counts for the group's sites when the run has
     * per-site completion evidence (M16: groups were never expanded).
     */
    public function test_backup_status_expands_task_groups_with_completion_evidence() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [ 'name' => 'Grouped', 'url' => 'https://grouped.example/' ] );
        $tag_id  = $this->create_test_tag( [ 'name' => 'BackupGroup' ] );
        $this->assign_site_to_tag( $site_id, $tag_id );

        $last_run = time() - DAY_IN_SECONDS;
        $this->seed_backup_task(
            [
                'groups'          => (string) $tag_id,
                'sites'           => '',
                'last_run'        => $last_run,
                'completed_sites' => wp_json_encode( [ $site_id => true ] ),
            ]
        );

        $root = untrailingslashit( get_temp_dir() ) . '/assistant-backup-status-' . wp_generate_uuid4();
        $uploads = static function ( $upload ) use ( $root ) {
            $upload['basedir'] = $root;
            return $upload;
        };
        add_filter( 'mainwp_get_wp_upload_dir', $uploads );
        $file = $root . '/mainwp/0/' . $site_id . '/full.zip';
        try {
            $without_archive = $this->read_status( [ 'per_page' => 100 ] );
            $items = array_column( $without_archive['items'], null, 'site_id' );
            $this->assertSame( 'none', $items[ $site_id ]['source'] );
            wp_mkdir_p( dirname( $file ) );
            file_put_contents( $file, 'fixture archive' );
            touch( $file, $last_run );
            $result = $this->read_status( [ 'threshold_days' => 7, 'per_page' => 100 ] );

            $this->assertNotWPError( $result );
            $by_id = array_column( $result['items'], null, 'site_id' );
            $this->assertArrayHasKey( $site_id, $by_id );
            $this->assertSame( 'legacy_task', $by_id[ $site_id ]['source'] );
            $this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $last_run ), $by_id[ $site_id ]['last_backup'] );
            $this->assertFalse( $by_id[ $site_id ]['is_stale'] );
        } finally {
            remove_filter( 'mainwp_get_wp_upload_dir', $uploads );
            if ( file_exists( $file ) ) {
                unlink( $file );
            }
            if ( is_dir( dirname( $file ) ) ) {
                rmdir( dirname( $file ) );
                rmdir( $root . '/mainwp/0' );
                rmdir( $root . '/mainwp' );
                rmdir( $root );
            }
        }
    }

    /**
     * A task run without per-site completion evidence reports last_attempted
     * honestly instead of implying a successful backup (M16: last_run is
     * stamped at task START).
     */
    public function test_backup_status_reports_last_attempted_without_completion_evidence() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id  = $this->create_test_site( [ 'name' => 'Attempted', 'url' => 'https://attempted.example/' ] );
        $last_run = time() - HOUR_IN_SECONDS;
        $this->seed_backup_task(
            [
                'sites'           => (string) $site_id,
                'last_run'        => $last_run,
                'completed_sites' => '',
            ]
        );

        $result = $this->read_status( [ 'threshold_days' => 7, 'per_page' => 100 ] );

        $this->assertNotWPError( $result );
        $by_id = array_column( $result['items'], null, 'site_id' );
        $this->assertArrayHasKey( $site_id, $by_id );
        $this->assertNull( $by_id[ $site_id ]['last_backup'], 'A start-stamped run without completion evidence must not count as a backup.' );
        $this->assertSame( 'none', $by_id[ $site_id ]['source'] );
        $this->assertTrue( $by_id[ $site_id ]['is_stale'] );
        $this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $last_run ), $by_id[ $site_id ]['last_attempted'] );
    }

    /** Each ability call represents a fresh request after fixture changes. */
    private function read_status( array $input ) {
        \MainWP\Dashboard\MainWP_Backup_Operations::reset_authorized_site_ids_memo();
        return $this->execute_ability( 'mainwp/get-backup-status-v1', $input );
    }

    /** Backup status requires the native execute_backups right. */
    public function test_backup_status_requires_execute_backups() {
        $this->set_current_user_as_admin();
        $deny = static function ( $allowed, $type, $right ) {
            return 'execute_backups' === $right ? false : $allowed;
        };
        add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
        try {
            $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
            $this->assertWPError( $this->read_status( array() ) );
        } finally {
            remove_filter( 'mainwp_currentusercan', $deny, 10 );
        }
    }

    /**
     * Insert a legacy backup task row directly.
     *
     * @param array $args Column overrides for wp_backup.
     * @return int Task id.
     */
    private function seed_backup_task( array $args = [] ): int {
        global $wpdb;
        $table    = \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_table_name( 'wp_backup' );
        $defaults = [
            'userid'            => get_current_user_id(),
            'name'              => 'Seeded Task',
            'schedule'          => 'daily',
            'type'              => 'full',
            'exclude'           => '',
            'sites'             => '',
            'groups'            => '',
            'last'              => 0,
            'last_run'          => 0,
            'last_run_manually' => 0,
            'completed_sites'   => '',
            'completed'         => 0,
            'backup_errors'     => '',
            'subfolder'         => '',
            'filename'          => '',
            'paused'            => 0,
            'template'          => 0,
        ];
        $wpdb->insert( $table, array_merge( $defaults, $args ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * Unauthenticated users are denied.
     */
    public function test_backup_status_requires_authentication() {
        $this->skip_if_no_abilities_api();
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
        wp_set_current_user( 0 );

        $result = $this->read_status( [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
    }

    /**
     * Users without manage_options are denied.
     */
    public function test_backup_status_requires_manage_options() {
        $this->skip_if_no_abilities_api();
        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
        $subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber_id );

        $result = $this->read_status( [] );

        $this->assertWPError( $result );
        $this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
    }

    /**
     * Schema rejects threshold_days below the minimum.
     */
    public function test_backup_status_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->read_status( [ 'threshold_days' => 0 ] );

        $this->assertWPError( $result, 'threshold_days of 0 must be rejected.' );
    }
}
