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
     * Test that deleting a demo site skips both deletion hooks.
     *
     * MainWP_Manage_Sites_Handler::remove_website() does not fire
     * mainwp_delete_site or mainwp_site_deleted for demo sites.
     *
     * @return void
     */
    public function test_delete_demo_site_skips_deletion_hooks() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site(
            [
                'name' => 'Test Demo Site Hooks',
                'url'  => 'https://test-delete-demo-site-hooks.example.com/',
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
        $this->assertSame( [], $this->deletion_hook_fired, 'Demo site deletion must not fire mainwp_delete_site or mainwp_site_deleted.' );
    }

    /**
     * Calls recorded by record_deletion_hooks().
     *
     * @var array<int, array{hook: string, args: array}>
     */
    private $deletion_hook_fired = [];

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
