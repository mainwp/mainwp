<?php
/**
 * MainWP DeleteSite Ability Tests
 *
 * Tests for the mainwp/delete-site-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/delete-site-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_DeleteSite_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/delete-site-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/delete-site-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_delete_site_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-delete-site.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/delete-site-v1', [
            'site_id_or_domain' => $site_id,
            'confirm'           => true,
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'dry_run', $result );
        $this->assertFalse( $result['dry_run'], 'dry_run should be false for actual deletion.' );
        $this->assertArrayHasKey( 'deleted', $result );
        $this->assertTrue( $result['deleted'], 'deleted should be true.' );
        $this->assertArrayHasKey( 'site', $result );
        $this->assertIsArray( $result['site'] );
        $this->assertEquals( $site_id, $result['site']['id'], 'site.id should match created site.' );
        $this->assertEquals( 'Test Site', $result['site']['name'], 'site.name should match.' );
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_delete_site_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/delete-site-v1', [
            'site_id_or_domain' => 1,
        ] );

        $this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
        $this->assertEquals(
            'ability_invalid_permissions',
            $result->get_error_code(),
            'Should return ability_invalid_permissions error code.'
        );
    }

    /**
     * Test that users without manage_options capability are denied.
     *
     * @return void
     */
    public function test_delete_site_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/delete-site-v1', [
            'site_id_or_domain' => 1,
        ] );

        $this->assertWPError( $result, 'Subscriber should be denied.' );
        $this->assertEquals(
            'ability_invalid_permissions',
            $result->get_error_code(),
            'Should return ability_invalid_permissions error code.'
        );
    }

    /**
     * Test input validation rejects invalid values.
     *
     * @return void
     */
    public function test_delete_site_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/delete-site-v1', [
            'site_id_or_domain' => '', // Empty required field
        ] );

        $this->assertWPError( $result, 'Invalid input should return WP_Error.' );
    }

    /**
     * Test that delete-site requires confirmation.
     *
     * @return void
     */
    public function test_delete_site_requires_confirmation() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site Without Confirm',
        ] );

        $input = [
            'site_id_or_domain' => $site_id,
        ];

        $result = $this->execute_ability( 'mainwp/delete-site-v1', $input );

        $this->assertWPError( $result );
        $this->assertEquals( 'mainwp_confirmation_required', $result->get_error_code() );
    }

    /**
     * Test that delete-site dry_run returns preview.
     *
     * @return void
     */
    public function test_delete_site_dry_run_returns_preview() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site Dry Run',
        ] );

        $input = [
            'site_id_or_domain' => $site_id,
            'dry_run'           => true,
        ];

        $result = $this->execute_ability( 'mainwp/delete-site-v1', $input );

        $this->assertNotWPError( $result );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'dry_run', $result );
        $this->assertTrue( $result['dry_run'] );
    }

    /**
     * Test that delete-site rejects both dry_run and confirm together.
     *
     * @return void
     */
    public function test_delete_site_rejects_dry_run_and_confirm_together() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site();

        $input = [
            'site_id_or_domain' => $site_id,
            'confirm'           => true,
        ];
        $input['dry_run'] = true;

        $result = $this->execute_ability( 'mainwp/delete-site-v1', $input );

        $this->assertWPError( $result );
        $this->assertEquals( 'mainwp_invalid_input', $result->get_error_code() );
    }

    /**
     * Test that a confirmed delete fires the site-deletion hooks once.
     *
     * MainWP_Manage_Sites_Handler::remove_website() fires mainwp_delete_site
     * and then mainwp_site_deleted, each with the website object.
     *
     * @return void
     */
    public function test_delete_site_fires_deletion_hooks() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_url = 'https://test-delete-site-hooks.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Site Hooks',
                'url'  => $site_url,
            ]
        );

        $fired = [];

        $on_delete_site  = static function () use ( &$fired ) {
            $fired[] = [
                'hook' => 'mainwp_delete_site',
                'args' => func_get_args(),
            ];
        };
        $on_site_deleted = static function () use ( &$fired ) {
            $fired[] = [
                'hook' => 'mainwp_site_deleted',
                'args' => func_get_args(),
            ];
        };

        add_action( 'mainwp_delete_site', $on_delete_site, 10, 10 );
        add_action( 'mainwp_site_deleted', $on_site_deleted, 10, 10 );

        try {
            $result = $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $site_id,
                    'confirm'           => true,
                ]
            );
        } finally {
            remove_action( 'mainwp_delete_site', $on_delete_site, 10 );
            remove_action( 'mainwp_site_deleted', $on_site_deleted, 10 );
        }

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertTrue( $result['deleted'], 'deleted should be true.' );
        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The site row should be removed.'
        );

        $this->assertCount( 2, $fired, 'Both deletion hooks should fire exactly once.' );
        $this->assertSame( 'mainwp_delete_site', $fired[0]['hook'], 'mainwp_delete_site fires first.' );
        $this->assertSame( 'mainwp_site_deleted', $fired[1]['hook'], 'mainwp_site_deleted fires second.' );

        foreach ( $fired as $call ) {
            $this->assertCount( 1, $call['args'], 'The handler passes only the website object.' );
            $this->assertIsObject( $call['args'][0] );
            $this->assertSame( $site_id, (int) $call['args'][0]->id, 'Hook argument should be the deleted site.' );
            $this->assertSame( $site_url, $call['args'][0]->url, 'Hook argument should be the site object, not an id.' );
        }
    }

    /** Both post-delete hooks run and success survives throwing listeners. */
    public function test_review_round_one_delete_site_contains_hook_failures(): void {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $site_id = $this->create_test_site();
        $fired = array();
        $on_delete_site = static function ( $site ) use ( &$fired ) {
            $fired['mainwp_delete_site'] = (int) $site->id;
            throw new \Error( 'First delete listener failed' );
        };
        $on_site_deleted = static function ( $site ) use ( &$fired ) {
            $fired['mainwp_site_deleted'] = (int) $site->id;
            throw new \RuntimeException( 'Second delete listener failed' );
        };
        add_action( 'mainwp_delete_site', $on_delete_site );
        add_action( 'mainwp_site_deleted', $on_site_deleted );

        try {
            $result = $this->execute_ability( 'mainwp/delete-site-v1', array( 'site_id_or_domain' => $site_id, 'confirm' => true ) );
        } finally {
            remove_action( 'mainwp_delete_site', $on_delete_site );
            remove_action( 'mainwp_site_deleted', $on_site_deleted );
        }

        $this->assertNotWPError( $result );
        $this->assertTrue( $result['deleted'] );
        $this->assertFalse( $result['dry_run'] );
        $this->assertSame( array( 'mainwp_delete_site' => $site_id, 'mainwp_site_deleted' => $site_id ), $fired );
        $this->assertNull( \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ) );
    }

    /**
     * Test that a dry-run does not fire deletion hooks or remove the site.
     *
     * @return void
     */
    public function test_delete_site_dry_run_does_not_fire_deletion_hooks() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Site Dry Run Hooks',
                'url'  => 'https://test-delete-site-dry-run-hooks.example.com/',
            ]
        );

        $this->record_deletion_hooks();

        try {
            $result = $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $site_id,
                    'dry_run'           => true,
                ]
            );
        } finally {
            $this->remove_deletion_hook_recorders();
        }

        $this->assertNotWPError( $result );
        $this->assertTrue( $result['dry_run'] );
        $this->assertSame( [], $this->deletion_hook_fired, 'A dry-run must not fire mainwp_delete_site or mainwp_site_deleted.' );

        $site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
        $this->assertNotNull( $site, 'A dry-run must leave the site row in place.' );
        $this->assertSame( $site_id, (int) $site->id );
    }

    /**
     * Test that a failed database delete of a live site fires no deletion hooks.
     *
     * @return void
     */
    public function test_delete_live_site_failed_delete_does_not_fire_deletion_hooks() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Site Failed Delete',
                'url'  => 'https://test-delete-site-failed.example.com/',
            ]
        );

        $result = $this->execute_confirmed_delete_with_failing_site_row( $site_id );

        $this->assert_failed_delete_fires_no_hooks( $result, $site_id );
    }

    /**
     * Test that a failed database delete of a demo site fires no deletion hooks.
     *
     * @return void
     */
    public function test_delete_demo_site_failed_delete_does_not_fire_deletion_hooks() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Demo Site Failed Delete',
                'url'  => 'https://test-delete-demo-site-failed.example.com/',
            ]
        );

        $property = new \ReflectionProperty( \MainWP\Dashboard\MainWP_Demo_Handle::class, 'demo_website_ids' );
        $property->setAccessible( true );
        $previous = $property->getValue( null );
        $demo_ids = is_array( $previous ) ? $previous : [];
        $demo_ids[] = $site_id;

        try {
            $property->setValue( null, $demo_ids );

            $site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
            $this->assertTrue(
                \MainWP\Dashboard\MainWP_Demo_Handle::get_instance()->is_demo_website( $site ),
                'The fixture site should be treated as a demo site.'
            );

            $result = $this->execute_confirmed_delete_with_failing_site_row( $site_id );
        } finally {
            $property->setValue( null, $previous );
        }

        $this->assert_failed_delete_fires_no_hooks( $result, $site_id );
    }

    /**
     * Confirm a delete while the site-row DELETE fails.
     *
     * @param int $site_id Site id.
     * @return mixed Ability result.
     */
    private function execute_confirmed_delete_with_failing_site_row( $site_id ) {
        $this->record_deletion_hooks();
        add_filter( 'query', [ $this, 'fail_site_row_delete' ] );

        try {
            return $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $site_id,
                    'confirm'           => true,
                ]
            );
        } finally {
            remove_filter( 'query', [ $this, 'fail_site_row_delete' ] );
            $this->remove_deletion_hook_recorders();
        }
    }

    /**
     * Rewrite the child-site DELETE into SQL that $wpdb->delete() rejects.
     *
     * An empty query returns false before MySQL runs, which is what
     * MainWP_DB::remove_website() treats as a failed delete. Other deletes
     * are left alone. Removed by the caller so later cleanup can run.
     *
     * @param string $query SQL.
     * @return string
     */
    public function fail_site_row_delete( $query ) {
        global $wpdb;

        $site_table = '`' . $wpdb->prefix . 'mainwp_wp`';
        if ( is_string( $query ) && false !== stripos( $query, 'DELETE FROM ' . $site_table ) ) {
            ++$this->site_row_deletes_rewritten;
            return '';
        }

        return $query;
    }

    /**
     * Make the child-site DELETE match no row.
     *
     * $wpdb->delete() then returns 0, which is not a database error.
     * Other deletes are left alone. Removed by the caller.
     *
     * @param string $query SQL.
     * @return string
     */
    public function zero_site_row_delete( $query ) {
        global $wpdb;

        $site_table = '`' . $wpdb->prefix . 'mainwp_wp`';
        if ( is_string( $query ) && false !== stripos( $query, 'DELETE FROM ' . $site_table ) ) {
            ++$this->site_row_deletes_rewritten;
            return $query . ' AND 1=0';
        }

        return $query;
    }

    /**
     * Assert a confirmed delete failed and neither deletion hook fired.
     *
     * @param mixed $result  Ability result.
     * @param int   $site_id Site id that must still exist.
     * @return void
     */
    private function assert_failed_delete_fires_no_hooks( $result, $site_id ) {
        $this->assertWPError( $result );
        $this->assertSame( 'mainwp_deletion_failed', $result->get_error_code() );
        $this->assertSame( 500, $result->get_error_data()['status'] );
        $this->assertSame( [], $this->deletion_hook_fired, 'A failed delete must not fire mainwp_delete_site or mainwp_site_deleted.' );

        global $wpdb;
        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp WHERE id = %d",
                $site_id
            )
        );
        $this->assertSame( 1, $remaining, 'A failed delete must leave the site row in place.' );
    }

    /**
     * Test that deleting a demo site fires both deletion hooks.
     *
     * Demo sites skip the remote child deactivate and still fire
     * mainwp_delete_site then mainwp_site_deleted with the site object.
     *
     * @return void
     */
    public function test_delete_demo_site_fires_deletion_hooks() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_url = 'https://test-delete-demo-site-hooks.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Demo Site Hooks',
                'url'  => $site_url,
            ]
        );

        $demo     = \MainWP\Dashboard\MainWP_Demo_Handle::get_instance();
        $property = new \ReflectionProperty( \MainWP\Dashboard\MainWP_Demo_Handle::class, 'demo_website_ids' );
        $property->setAccessible( true );
        $previous = $property->getValue( null );
        $demo_ids = is_array( $previous ) ? $previous : [];
        $demo_ids[] = $site_id;

        $this->record_deletion_hooks();

        try {
            $property->setValue( null, $demo_ids );

            $site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
            $this->assertTrue( $demo->is_demo_website( $site ), 'The fixture site should be treated as a demo site.' );

            $result = $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $site_id,
                    'confirm'           => true,
                ]
            );
        } finally {
            $property->setValue( null, $previous );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertTrue( $result['deleted'], 'deleted should be true.' );
        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The demo site row should still be removed.'
        );
        $this->assert_demo_deletion_hooks_fired( $site_id, $site_url );
    }

    /**
     * Test that the manage-sites handler fires both hooks for a demo site.
     *
     * The ability path does not call remove_website(). This covers that
     * handler directly: no remote deactivate, then the same two hooks.
     *
     * @return void
     */
    public function test_remove_website_demo_site_fires_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_url = 'https://test-handler-delete-demo-site-hooks.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Handler Demo Site Hooks',
                'url'  => $site_url,
            ]
        );

        $property = new \ReflectionProperty( \MainWP\Dashboard\MainWP_Demo_Handle::class, 'demo_website_ids' );
        $property->setAccessible( true );
        $previous = $property->getValue( null );
        $demo_ids = is_array( $previous ) ? $previous : [];
        $demo_ids[] = $site_id;

        $this->record_deletion_hooks();

        try {
            $property->setValue( null, $demo_ids );

            $site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
            $this->assertTrue(
                \MainWP\Dashboard\MainWP_Demo_Handle::get_instance()->is_demo_website( $site ),
                'The fixture site should be treated as a demo site.'
            );

            $information = \MainWP\Dashboard\MainWP_Manage_Sites_Handler::remove_website( $site );
        } finally {
            $property->setValue( null, $previous );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertIsArray( $information );
        $this->assertTrue( $information['removed'], 'A demo delete skips the remote deactivate and marks the site removed locally.' );
        $this->assertArrayNotHasKey( 'error', $information, 'A demo delete must not attempt the remote deactivate call.' );
        $this->assertTrue( $information['removed_site'], 'The demo site row should be removed.' );
        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The demo site row should be removed.'
        );
        $this->assert_demo_deletion_hooks_fired( $site_id, $site_url );
    }

    /**
     * Test that the manage-sites handler fires both hooks for a live site.
     *
     * A live site calls fetch_url_authed() for deactivate. The testing filter
     * stubs that call so this is not a staging site, which would skip it.
     * Both hooks then fire once, in order, with that same website object.
     *
     * @return void
     */
    public function test_remove_website_live_site_fires_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_url = 'https://test-handler-delete-live-site-hooks.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Handler Live Site Hooks',
                'url'  => $site_url,
            ]
        );
        $site     = $this->assert_handler_live_site( $site_id );

        $remote_calls = [];
        $this->record_deletion_hooks();
        $stub = $this->stub_live_site_deactivate( $remote_calls );

        try {
            $information = \MainWP\Dashboard\MainWP_Manage_Sites_Handler::remove_website( $site );
        } finally {
            remove_filter( 'mainwp_fetch_url_authed_pre', $stub, 10 );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertSame(
            [
                [
                    'what' => 'deactivate',
                    'id'   => $site_id,
                ],
            ],
            $remote_calls,
            'A live delete deactivates the child through fetch_url_authed().'
        );
        $this->assertIsArray( $information );
        $this->assertTrue( $information['removed_site'], 'The live site row should be removed.' );
        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The live site row should be removed.'
        );
        $this->assert_demo_deletion_hooks_fired( $site_id, $site_url );
        $this->assert_deletion_hook_argument_is( $site );
    }

    /**
     * Test that a failed database delete of a live site fires no deletion hooks.
     *
     * @return void
     */
    public function test_remove_website_live_site_failed_delete_does_not_fire_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Handler Live Site Failed Delete',
                'url'  => 'https://test-handler-delete-live-site-failed.example.com/',
            ]
        );
        $site    = $this->assert_handler_live_site( $site_id );

        $remote_calls                     = [];
        $this->site_row_deletes_rewritten = 0;
        $this->record_deletion_hooks();
        $stub = $this->stub_live_site_deactivate( $remote_calls );
        add_filter( 'query', [ $this, 'fail_site_row_delete' ] );

        try {
            $information = \MainWP\Dashboard\MainWP_Manage_Sites_Handler::remove_website( $site );
        } finally {
            remove_filter( 'query', [ $this, 'fail_site_row_delete' ] );
            remove_filter( 'mainwp_fetch_url_authed_pre', $stub, 10 );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The failure filter blanks exactly one site-row DELETE.' );
        $this->assertSame(
            [
                [
                    'what' => 'deactivate',
                    'id'   => $site_id,
                ],
            ],
            $remote_calls,
            'The live deactivate still runs; only the database delete fails.'
        );
        $this->assert_handler_failed_delete_fires_no_hooks( $information, $site_id );
    }

    /**
     * Test that a failed database delete of a demo site fires no deletion hooks.
     *
     * @return void
     */
    public function test_remove_website_demo_site_failed_delete_does_not_fire_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Handler Demo Site Failed Delete',
                'url'  => 'https://test-handler-delete-demo-site-failed.example.com/',
            ]
        );

        $property = new \ReflectionProperty( \MainWP\Dashboard\MainWP_Demo_Handle::class, 'demo_website_ids' );
        $property->setAccessible( true );
        $previous = $property->getValue( null );
        $demo_ids = is_array( $previous ) ? $previous : [];
        $demo_ids[] = $site_id;

        $this->site_row_deletes_rewritten = 0;
        $this->record_deletion_hooks();
        add_filter( 'query', [ $this, 'fail_site_row_delete' ] );

        try {
            $property->setValue( null, $demo_ids );

            $site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
            $this->assertTrue(
                \MainWP\Dashboard\MainWP_Demo_Handle::get_instance()->is_demo_website( $site ),
                'The fixture site should be treated as a demo site.'
            );

            $information = \MainWP\Dashboard\MainWP_Manage_Sites_Handler::remove_website( $site );
        } finally {
            $property->setValue( null, $previous );
            remove_filter( 'query', [ $this, 'fail_site_row_delete' ] );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The failure filter blanks exactly one site-row DELETE.' );
        $this->assert_handler_failed_delete_fires_no_hooks( $information, $site_id );
    }

    /**
     * Test that a delete matching no row still fires both hooks.
     *
     * Zero rows is not a database error. The ability path treats only false
     * as failure, and removed_site stays unset because that flag is truthy-only.
     *
     * @return void
     */
    public function test_remove_website_zero_row_delete_still_fires_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_url = 'https://test-handler-delete-zero-rows.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Handler Zero Row Delete',
                'url'  => $site_url,
            ]
        );
        $site     = $this->assert_handler_live_site( $site_id );

        $remote_calls                     = [];
        $this->site_row_deletes_rewritten = 0;
        $this->record_deletion_hooks();
        $stub = $this->stub_live_site_deactivate( $remote_calls );
        add_filter( 'query', [ $this, 'zero_site_row_delete' ] );

        try {
            $information = \MainWP\Dashboard\MainWP_Manage_Sites_Handler::remove_website( $site );
        } finally {
            remove_filter( 'query', [ $this, 'zero_site_row_delete' ] );
            remove_filter( 'mainwp_fetch_url_authed_pre', $stub, 10 );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The zero-row filter rewrites exactly one site-row DELETE.' );
        $this->assertIsArray( $information );
        $this->assertArrayNotHasKey( 'removed_site', $information, 'Zero rows does not set removed_site.' );
        $this->assertSame( 1, $this->count_site_rows( $site_id ), 'A zero-row delete must leave the site row in place.' );
        $this->assert_demo_deletion_hooks_fired( $site_id, $site_url );
        $this->assert_deletion_hook_argument_is( $site );
    }

    /**
     * Test that the extension delete-site filter fires both hooks on success.
     *
     * mainwp_deletesite is the extension entry. It must fire mainwp_delete_site
     * then mainwp_site_deleted with the website object, return SUCCESS, and
     * remove a normal cached favicon.
     *
     * @return void
     */
    public function test_hook_delete_site_fires_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_url = 'https://test-hook-delete-site-success.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Hook Delete Site',
                'url'  => $site_url,
            ]
        );
        $favicon  = $this->write_site_favicon( $site_id, 'favi-' . $site_id . '-favicon.ico' );

        $this->record_deletion_hooks();

        try {
            $result = apply_filters( 'mainwp_deletesite', $site_id );

            $this->assertSame( [ 'result' => 'SUCCESS' ], $result );
            $this->assertNull(
                \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
                'A successful delete removes the site row.'
            );
            $this->assert_demo_deletion_hooks_fired( $site_id, $site_url );
            $this->assertFileDoesNotExist( $favicon, 'A normal cached favicon is removed.' );
        } finally {
            $this->remove_deletion_hook_recorders();
            $this->delete_file_if_present( $favicon );
        }
    }

    /**
     * Test that a failed extension delete fires nothing and returns an error.
     *
     * @return void
     */
    public function test_hook_delete_site_failed_delete_fires_nothing() {
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Hook Delete Site Failed',
                'url'  => 'https://test-hook-delete-site-failed.example.com/',
            ]
        );

        $this->site_row_deletes_rewritten = 0;
        $this->record_deletion_hooks();
        add_filter( 'query', [ $this, 'fail_site_row_delete' ] );

        try {
            $result = apply_filters( 'mainwp_deletesite', $site_id );
        } finally {
            remove_filter( 'query', [ $this, 'fail_site_row_delete' ] );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The failure filter blanks exactly one site-row DELETE.' );
        $this->assert_extension_delete_failed( $result, $site_id, 'Failed to delete the website' );
    }

    /**
     * Test that zero deleted rows still counts as success for the extension filter.
     *
     * Only false is a failed delete. A statement that matches no row fires both
     * hooks and returns SUCCESS, matching the handler and the ability.
     *
     * @return void
     */
    public function test_hook_delete_site_zero_row_delete_still_fires_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_url = 'https://test-hook-delete-site-zero-rows.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Hook Delete Site Zero Rows',
                'url'  => $site_url,
            ]
        );

        $this->site_row_deletes_rewritten = 0;
        $this->record_deletion_hooks();
        add_filter( 'query', [ $this, 'zero_site_row_delete' ] );

        try {
            $result = apply_filters( 'mainwp_deletesite', $site_id );
        } finally {
            remove_filter( 'query', [ $this, 'zero_site_row_delete' ] );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The zero-row filter rewrites exactly one site-row DELETE.' );
        $this->assertSame( [ 'result' => 'SUCCESS' ], $result );
        $this->assertSame( 1, $this->count_site_rows( $site_id ), 'A zero-row delete must leave the site row in place.' );
        $this->assert_demo_deletion_hooks_fired( $site_id, $site_url );
    }

    /**
     * Test that deleting a clone site fires both hooks and returns SUCCESS.
     *
     * The clone path only removes a staging site. The extension filter is
     * mainwp_delete_clonesite. A normal cached favicon is removed.
     *
     * @return void
     */
    public function test_hook_delete_clone_site_fires_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_url = 'https://test-hook-delete-clone-success.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name'       => 'Test Hook Delete Clone',
                'url'        => $site_url,
                'is_staging' => 1,
            ]
        );
        $favicon  = $this->write_site_favicon( $site_id, 'favi-' . $site_id . '-favicon.ico' );

        $this->record_deletion_hooks();

        try {
            $result = $this->apply_delete_clone_site( '', $site_id );

            $this->assertSame( [ 'result' => 'SUCCESS' ], $result );
            $this->assertNull(
                \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
                'A successful clone delete removes the site row.'
            );
            $this->assert_demo_deletion_hooks_fired( $site_id, $site_url );
            $this->assertFileDoesNotExist( $favicon, 'A normal cached favicon is removed.' );
        } finally {
            $this->remove_deletion_hook_recorders();
            $this->delete_file_if_present( $favicon );
        }
    }

    /**
     * Test that a failed clone-site delete fires nothing and returns an error.
     *
     * @return void
     */
    public function test_hook_delete_clone_site_failed_delete_fires_nothing() {
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name'       => 'Test Hook Delete Clone Failed',
                'url'        => 'https://test-hook-delete-clone-failed.example.com/',
                'is_staging' => 1,
            ]
        );

        $this->site_row_deletes_rewritten = 0;
        $this->record_deletion_hooks();
        add_filter( 'query', [ $this, 'fail_site_row_delete' ] );

        try {
            $result = $this->apply_delete_clone_site( '', $site_id );
        } finally {
            remove_filter( 'query', [ $this, 'fail_site_row_delete' ] );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The failure filter blanks exactly one site-row DELETE.' );
        $this->assert_extension_delete_failed( $result, $site_id, 'Failed to delete the clone website' );
    }

    /**
     * Test that a clone delete matching no row still fires both hooks.
     *
     * @return void
     */
    public function test_hook_delete_clone_site_zero_row_delete_still_fires_deletion_hooks() {
        $this->set_current_user_as_admin();

        $site_url = 'https://test-hook-delete-clone-zero-rows.example.com/';
        $site_id  = $this->create_test_site(
            [
                'name'       => 'Test Hook Delete Clone Zero Rows',
                'url'        => $site_url,
                'is_staging' => 1,
            ]
        );

        $this->site_row_deletes_rewritten = 0;
        $this->record_deletion_hooks();
        add_filter( 'query', [ $this, 'zero_site_row_delete' ] );

        try {
            $result = $this->apply_delete_clone_site( $site_url, false );
        } finally {
            remove_filter( 'query', [ $this, 'zero_site_row_delete' ] );
            $this->remove_deletion_hook_recorders();
        }

        $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The zero-row filter rewrites exactly one site-row DELETE.' );
        $this->assertSame( [ 'result' => 'SUCCESS' ], $result );
        $this->assertSame( 1, $this->count_site_rows( $site_id ), 'A zero-row delete must leave the site row in place.' );
        $this->assert_demo_deletion_hooks_fired( $site_id, $site_url );
    }

    /**
     * Test that the extension delete-site filter does not follow a favicon path out of the icons directory.
     *
     * The stored name still contains favi-{id}-, which the old strpos() check accepted.
     *
     * @return void
     */
    public function test_hook_delete_site_keeps_favicon_outside_icons_directory() {
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Hook Delete Site Favicon Traversal',
                'url'  => 'https://test-hook-delete-site-favicon-traversal.example.com/',
            ]
        );
        $outside = $this->plant_traversal_favicon( $site_id );

        try {
            $result = apply_filters( 'mainwp_deletesite', $site_id );

            $this->assertSame( [ 'result' => 'SUCCESS' ], $result );
            $this->assertFileExists( $outside, 'A favicon name that leaves the icons directory must not be deleted.' );
        } finally {
            $this->delete_file_if_present( $outside );
        }

        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The site row should be removed.'
        );
    }

    /**
     * Test that the clone-site delete does not follow a favicon path out of the icons directory.
     *
     * @return void
     */
    public function test_hook_delete_clone_site_keeps_favicon_outside_icons_directory() {
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name'       => 'Test Hook Delete Clone Favicon Traversal',
                'url'        => 'https://test-hook-delete-clone-favicon-traversal.example.com/',
                'is_staging' => 1,
            ]
        );
        $outside = $this->plant_traversal_favicon( $site_id );

        try {
            $result = $this->apply_delete_clone_site( '', $site_id );

            $this->assertSame( [ 'result' => 'SUCCESS' ], $result );
            $this->assertFileExists( $outside, 'A favicon name that leaves the icons directory must not be deleted.' );
        } finally {
            $this->delete_file_if_present( $outside );
        }

        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The site row should be removed.'
        );
    }

    /**
     * Assert the fixture is a live site: not staging and not demo.
     *
     * @param int $site_id Site id.
     * @return object Website object.
     */
    private function assert_handler_live_site( $site_id ) {
        $site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
        $this->assertIsObject( $site );
        $this->assertSame( 0, (int) $site->is_staging, 'The fixture is a live site, not a staging site.' );
        $this->assertFalse(
            \MainWP\Dashboard\MainWP_Demo_Handle::get_instance()->is_demo_website( $site ),
            'The fixture is not a demo site.'
        );
        return $site;
    }

    /**
     * Stub the live-site deactivate request.
     *
     * mainwp_fetch_url_authed_pre runs only in the test harness, before any
     * HTTP call. The handler still takes the live branch.
     *
     * @param array $calls Collected deactivate calls, by reference.
     * @return callable Filter callback, so the caller can remove it.
     */
    private function stub_live_site_deactivate( array &$calls ) {
        $calls = [];
        $stub  = static function ( $result, $website, $what ) use ( &$calls ) {
            if ( false !== $result ) {
                return $result;
            }
            $calls[] = [
                'what' => $what,
                'id'   => is_object( $website ) ? (int) $website->id : 0,
            ];
            return [ 'deactivated' => true ];
        };
        add_filter( 'mainwp_fetch_url_authed_pre', $stub, 10, 3 );
        return $stub;
    }

    /**
     * Assert a handler delete failed and neither deletion hook fired.
     *
     * @param mixed $information Handler return value.
     * @param int   $site_id     Site id that must still exist.
     * @return void
     */
    private function assert_handler_failed_delete_fires_no_hooks( $information, $site_id ) {
        $this->assertIsArray( $information );
        $this->assertArrayNotHasKey( 'removed_site', $information, 'A failed delete must not set removed_site.' );
        $this->assertSame( [], $this->deletion_hook_fired, 'A failed delete must not fire mainwp_delete_site or mainwp_site_deleted.' );
        $this->assertSame( 1, $this->count_site_rows( $site_id ), 'A failed delete must leave the site row in place.' );
    }

    /**
     * Assert each recorded hook received this website object.
     *
     * @param object $website Website passed into remove_website().
     * @return void
     */
    private function assert_deletion_hook_argument_is( $website ) {
        $this->assertNotEmpty( $this->deletion_hook_fired );
        foreach ( $this->deletion_hook_fired as $call ) {
            $this->assertSame( $website, $call['args'][0], 'The hook receives the website object fetched before the delete.' );
        }
    }

    /**
     * Count mainwp_wp rows for a site id.
     *
     * @param int $site_id Site id.
     * @return int
     */
    private function count_site_rows( $site_id ) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp WHERE id = %d",
                $site_id
            )
        );
    }

    /**
     * Assert both deletion hooks fired once, in order, with the site object.
     *
     * @param int    $site_id  Deleted site id.
     * @param string $site_url Deleted site url.
     * @return void
     */
    private function assert_demo_deletion_hooks_fired( $site_id, $site_url ) {
        $this->assertCount( 2, $this->deletion_hook_fired, 'Both deletion hooks should fire exactly once.' );
        $this->assertSame( 'mainwp_delete_site', $this->deletion_hook_fired[0]['hook'], 'mainwp_delete_site fires first.' );
        $this->assertSame( 'mainwp_site_deleted', $this->deletion_hook_fired[1]['hook'], 'mainwp_site_deleted fires second.' );

        foreach ( $this->deletion_hook_fired as $call ) {
            $this->assertCount( 1, $call['args'], 'The handler passes only the website object.' );
            $this->assertIsObject( $call['args'][0] );
            $this->assertSame( $site_id, (int) $call['args'][0]->id, 'Hook argument should be the deleted site.' );
            $this->assertSame( $site_url, $call['args'][0]->url, 'Hook argument should be the site object, not an id.' );
        }
    }

    /**
     * Test that a confirmed delete removes the site's cached favicon file.
     *
     * @return void
     */
    public function test_delete_site_removes_cached_favicon_file() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Site Favicon',
                'url'  => 'https://test-delete-site-favicon.example.com/',
            ]
        );
        $filename = 'favi-' . $site_id . '-favicon.ico';
        $path     = $this->write_site_favicon( $site_id, $filename );
        $outside  = $this->write_outside_icons_file( 'mwp-1809-keep-' . $site_id . '.txt' );

        try {
            $result = $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $site_id,
                    'confirm'           => true,
                ]
            );

            $this->assertNotWPError( $result, 'Should return successful result.' );
            $this->assertTrue( $result['deleted'], 'deleted should be true.' );
            $this->assertSame( $this->confirmed_delete_response_keys(), array_keys( $result ) );
            $this->assertFileDoesNotExist( $path, 'The cached favicon file should be removed.' );
            $this->assertFileExists( $outside, 'A file outside the icons directory must be left in place.' );
        } finally {
            $this->delete_file_if_present( $path );
            $this->delete_file_if_present( $outside );
        }

        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The site row should be removed.'
        );
    }

    /**
     * Test that deleting a site with no favicon still succeeds.
     *
     * @return void
     */
    public function test_delete_site_without_favicon_still_succeeds() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Site No Favicon',
                'url'  => 'https://test-delete-site-no-favicon.example.com/',
            ]
        );

        $result = $this->execute_ability(
            'mainwp/delete-site-v1',
            [
                'site_id_or_domain' => $site_id,
                'confirm'           => true,
            ]
        );

        $this->assertNotWPError( $result, 'A site with no favicon should still be deleted.' );
        $this->assertTrue( $result['deleted'], 'deleted should be true.' );
        $this->assertFalse( $result['dry_run'] );
        $this->assertSame( $this->confirmed_delete_response_keys(), array_keys( $result ) );
        $this->assertSame( $site_id, $result['site']['id'] );
        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The site row should be removed.'
        );
    }

    /**
     * Test that a stored favicon name with no file on disk does not fail the delete.
     *
     * @return void
     */
    public function test_delete_site_with_missing_favicon_file_still_succeeds() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Site Missing Favicon File',
                'url'  => 'https://test-delete-site-missing-favicon.example.com/',
            ]
        );
        $filename = 'favi-' . $site_id . '-missing.ico';
        $this->set_site_option( $site_id, 'favi_icon', $filename );
        $path = $this->site_icons_directory() . $filename;
        $this->assertFileDoesNotExist( $path );

        $result = $this->execute_ability(
            'mainwp/delete-site-v1',
            [
                'site_id_or_domain' => $site_id,
                'confirm'           => true,
            ]
        );

        $this->assertNotWPError( $result, 'A missing favicon file should not fail the delete.' );
        $this->assertTrue( $result['deleted'], 'deleted should be true.' );
        $this->assertSame( $this->confirmed_delete_response_keys(), array_keys( $result ) );
        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The site row should be removed.'
        );
    }

    /**
     * Test that a directory named like the favicon file is left in place.
     *
     * @return void
     */
    public function test_delete_site_leaves_a_favicon_named_directory() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $empty_id   = $this->create_test_site(
            [
                'name' => 'Test Site Empty Favicon Directory',
                'url'  => 'https://test-delete-site-empty-favicon-directory.example.com/',
            ]
        );
        $empty_name = 'favi-' . $empty_id . '-favicon.ico';
        $this->set_site_option( $empty_id, 'favi_icon', $empty_name );
        $empty_path = $this->site_icons_directory() . $empty_name;
        $this->assertTrue( mkdir( $empty_path ) );

        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Site Favicon Directory',
                'url'  => 'https://test-delete-site-favicon-directory.example.com/',
            ]
        );
        $filename = 'favi-' . $site_id . '-favicon.ico';
        $this->set_site_option( $site_id, 'favi_icon', $filename );
        $path = $this->site_icons_directory() . $filename;
        $this->assertTrue( mkdir( $path ) );
        $marker = $path . '/keep.txt';
        $this->assertNotFalse( file_put_contents( $marker, 'keep' ) );

        try {
            $empty_result = $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $empty_id,
                    'confirm'           => true,
                ]
            );

            $this->assertNotWPError( $empty_result, 'An empty favicon-named directory must not fail the delete.' );
            $this->assertTrue( $empty_result['deleted'], 'deleted should be true.' );
            $this->assertDirectoryExists( $empty_path, 'An empty directory with the favicon name must be left alone.' );

            $result = $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $site_id,
                    'confirm'           => true,
                ]
            );

            $this->assertNotWPError( $result, 'A favicon-named directory must not fail the delete.' );
            $this->assertTrue( $result['deleted'], 'deleted should be true.' );
            $this->assertDirectoryExists( $path, 'A directory with the favicon name must be left alone.' );
            $this->assertFileExists( $marker, 'Files inside that directory must be left alone.' );
        } finally {
            if ( is_dir( $empty_path ) ) {
                rmdir( $empty_path );
            }
            $this->delete_file_if_present( $marker );
            if ( is_dir( $path ) ) {
                rmdir( $path );
            }
        }

        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $empty_id ),
            'The site row for the empty directory should be removed.'
        );
        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The site row should be removed.'
        );
    }

    /**
     * Test that a dry-run does not remove the cached favicon file.
     *
     * @return void
     */
    public function test_delete_site_dry_run_keeps_favicon_file() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id  = $this->create_test_site(
            [
                'name' => 'Test Site Favicon Dry Run',
                'url'  => 'https://test-delete-site-favicon-dry-run.example.com/',
            ]
        );
        $filename = 'favi-' . $site_id . '-favicon.ico';
        $path     = $this->write_site_favicon( $site_id, $filename );

        try {
            $result = $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $site_id,
                    'dry_run'           => true,
                ]
            );

            $this->assertNotWPError( $result );
            $this->assertTrue( $result['dry_run'] );
            $this->assertFileExists( $path, 'A dry-run must not delete the favicon file.' );
        } finally {
            $this->delete_file_if_present( $path );
        }

        $site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
        $this->assertNotNull( $site, 'A dry-run must leave the site row in place.' );
    }

    /**
     * Test that a stored favicon name that leaves the icons directory is not deleted.
     *
     * The name still contains the historical favi-{id}- marker, which is what
     * the UI path used to check. The file outside the icons directory must
     * remain, and the site delete must still succeed.
     *
     * @return void
     */
    public function test_delete_site_does_not_follow_favicon_path_traversal() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Site Favicon Traversal',
                'url'  => 'https://test-delete-site-favicon-traversal.example.com/',
            ]
        );

        $icons         = $this->site_icons_directory();
        $sentinel_name = 'favi-' . $site_id . '-escaped.txt';
        $sentinel      = dirname( untrailingslashit( $icons ) ) . '/' . $sentinel_name;
        $this->assertNotFalse( file_put_contents( $sentinel, 'keep' ) );
        $this->assertFileExists( $sentinel );

        // Same basename inside the icons directory. A basename() trim of the
        // stored traversal name would delete this decoy.
        $decoy = $icons . $sentinel_name;
        $this->assertNotFalse( file_put_contents( $decoy, 'decoy' ) );

        // ../favi-{id}-escaped.txt resolves to the parent of the icons directory
        // and still contains the marker the old strpos() check accepted.
        $stored = '../' . $sentinel_name;
        $this->set_site_option( $site_id, 'favi_icon', $stored );
        $resolved = realpath( $icons . $stored );
        $this->assertNotFalse( $resolved, 'The fixture path should resolve.' );
        $this->assertSame( realpath( $sentinel ), $resolved, 'The fixture should point at the sentinel outside the icons directory.' );

        try {
            $result = $this->execute_ability(
                'mainwp/delete-site-v1',
                [
                    'site_id_or_domain' => $site_id,
                    'confirm'           => true,
                ]
            );

            $this->assertNotWPError( $result, 'A rejected favicon path must not fail the delete.' );
            $this->assertTrue( $result['deleted'], 'deleted should be true.' );
            $this->assertSame( $this->confirmed_delete_response_keys(), array_keys( $result ) );
            $this->assertFileExists( $sentinel, 'A favicon name that leaves the icons directory must not be deleted.' );
            $this->assertFileExists( $decoy, 'A different file in the icons directory must not be deleted.' );
        } finally {
            $this->delete_file_if_present( $sentinel );
            $this->delete_file_if_present( $decoy );
        }

        $this->assertNull(
            \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
            'The site row should be removed.'
        );
    }

    /**
     * Keys returned by a confirmed delete-site-v1 call.
     *
     * @return array<int, string>
     */
    private function confirmed_delete_response_keys(): array {
        return array( 'dry_run', 'deleted', 'site', 'would_affect', 'warnings' );
    }

    /**
     * Icons directory used for cached site favicons.
     *
     * @return string
     */
    private function site_icons_directory(): string {
        $dirs = \MainWP\Dashboard\MainWP_System_Utility::get_icons_dir();
        $this->assertIsArray( $dirs );
        $this->assertNotEmpty( $dirs[0] );
        $this->assertDirectoryExists( $dirs[0] );
        return $dirs[0];
    }

    /**
     * Store a favicon name and write that file in the icons directory.
     *
     * @param int    $site_id  Site ID.
     * @param string $filename Favicon file name.
     * @return string Absolute path.
     */
    private function write_site_favicon( int $site_id, string $filename ): string {
        $this->assertSame( $filename, basename( $filename ), 'Test favicon names must be a single path segment.' );
        $this->set_site_option( $site_id, 'favi_icon', $filename );
        $path = $this->site_icons_directory() . $filename;
        $this->assertNotFalse( file_put_contents( $path, 'favicon' ) );
        $this->assertFileExists( $path );
        return $path;
    }

    /**
     * Store ../probe-keep-favi-{id}-x.txt and write that file above the icons directory.
     *
     * The name contains the favi-{id}- marker and points at the planted file.
     *
     * @param int $site_id Site id.
     * @return string Absolute path of the file outside the icons directory.
     */
    private function plant_traversal_favicon( $site_id ) {
        $filename = 'probe-keep-favi-' . $site_id . '-x.txt';
        $outside  = $this->write_outside_icons_file( $filename );
        $stored   = '../' . $filename;
        $this->set_site_option( $site_id, 'favi_icon', $stored );

        $icons    = $this->site_icons_directory();
        $resolved = realpath( $icons . $stored );
        $this->assertNotFalse( $resolved, 'The fixture path should resolve.' );
        $this->assertSame( realpath( $outside ), $resolved, 'The stored name should point at the file outside the icons directory.' );

        return $outside;
    }

    /**
     * Write a file next to the icons directory.
     *
     * @param string $filename File name.
     * @return string Absolute path.
     */
    private function write_outside_icons_file( string $filename ): string {
        $path = dirname( untrailingslashit( $this->site_icons_directory() ) ) . '/' . $filename;
        $this->assertNotFalse( file_put_contents( $path, 'keep' ) );
        $this->assertFileExists( $path );
        return $path;
    }

    /**
     * Remove a fixture file when it is still present.
     *
     * @param string $path File path.
     * @return void
     */
    private function delete_file_if_present( string $path ): void {
        if ( is_file( $path ) ) {
            unlink( $path );
        }
    }

    /**
     * Calls recorded by record_deletion_hooks().
     *
     * @var array<int, array{hook: string, args: array}>
     */
    private $deletion_hook_fired = [];

    /**
     * Site-row DELETE statements rewritten by the query filters.
     *
     * @var int
     */
    private $site_row_deletes_rewritten = 0;

    /**
     * Listeners registered by record_deletion_hooks().
     *
     * @var array<int, callable>
     */
    private $deletion_hook_recorders = [];

    /**
     * Record mainwp_delete_site and mainwp_site_deleted calls.
     *
     * Results are appended to $this->deletion_hook_fired.
     *
     * @return void
     */
    private function record_deletion_hooks(): void {
        $this->deletion_hook_fired = [];

        $on_delete_site = function () {
            $this->deletion_hook_fired[] = [
                'hook' => 'mainwp_delete_site',
                'args' => func_get_args(),
            ];
        };
        $on_site_deleted = function () {
            $this->deletion_hook_fired[] = [
                'hook' => 'mainwp_site_deleted',
                'args' => func_get_args(),
            ];
        };

        add_action( 'mainwp_delete_site', $on_delete_site, 10, 10 );
        add_action( 'mainwp_site_deleted', $on_site_deleted, 10, 10 );

        $this->deletion_hook_recorders = [ $on_delete_site, $on_site_deleted ];
    }

    /**
     * Call the clone-site delete through its extension filter.
     *
     * The key is the same check hook_verify() uses. Pass a URL or a site id,
     * matching the two extension callers.
     *
     * @param string   $clone_url     Clone URL, or an empty string to select by id.
     * @param int|bool $clone_site_id Clone site id, or false when selecting by URL.
     * @return mixed Filter result.
     */
    private function apply_delete_clone_site( $clone_url, $clone_site_id ) {
        $plugin_file = 'mainwp-staging-extension/mainwp-staging-extension.php';
        $key         = md5( $plugin_file . '-SNNonceAdder' );

        return apply_filters( 'mainwp_delete_clonesite', $plugin_file, $key, $clone_url, $clone_site_id );
    }

    /**
     * Assert an extension delete failed, fired no hooks, and kept the row.
     *
     * @param mixed  $result  Filter result.
     * @param int    $site_id Site id that must still exist.
     * @param string $error   Expected error text.
     * @return void
     */
    private function assert_extension_delete_failed( $result, $site_id, $error ) {
        $this->assertSame( [ 'error' => $error ], $result );
        $this->assertSame( [], $this->deletion_hook_fired, 'A failed delete must not fire mainwp_delete_site or mainwp_site_deleted.' );
        $this->assertSame( 1, $this->count_site_rows( $site_id ), 'A failed delete must leave the site row in place.' );
    }

    /**
     * Remove listeners registered by record_deletion_hooks().
     *
     * @return void
     */
    private function remove_deletion_hook_recorders(): void {
        if ( isset( $this->deletion_hook_recorders[0] ) ) {
            remove_action( 'mainwp_delete_site', $this->deletion_hook_recorders[0], 10 );
        }
        if ( isset( $this->deletion_hook_recorders[1] ) ) {
            remove_action( 'mainwp_site_deleted', $this->deletion_hook_recorders[1], 10 );
        }
        $this->deletion_hook_recorders = [];
    }
}
