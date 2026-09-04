<?php
/**
 * Tests for the premium update registry and matching (MWP-1660).
 *
 * @package MainWP/Dashboard
 */

use MainWP\Dashboard\MainWP_Premium_Update;
use MainWP\Dashboard\MainWP_Premium_Update_Registry;

/**
 * Class Test_Premium_Update_Registry
 */
class Test_Premium_Update_Registry extends WP_UnitTestCase {

    /**
     * Reset registry-related options between tests.
     */
    public function set_up() {
        parent::set_up();
        delete_option( MainWP_Premium_Update_Registry::OPTION_ENABLED );
        delete_option( MainWP_Premium_Update_Registry::OPTION_CUSTOM );
        remove_all_filters( 'mainwp_detect_premium_plugins_update' );
        remove_all_filters( 'mainwp_detect_premium_themes_update' );
        remove_all_filters( 'mainwp_request_update_premium_plugins' );
        remove_all_filters( 'mainwp_request_update_premium_themes' );
    }

    /**
     * Registry defaults to enabled.
     */
    public function test_registry_enabled_by_default() {
        $this->assertTrue( MainWP_Premium_Update_Registry::is_enabled() );
        update_option( MainWP_Premium_Update_Registry::OPTION_ENABLED, 0 );
        $this->assertFalse( MainWP_Premium_Update_Registry::is_enabled() );
    }

    /**
     * Exact matching is case-insensitive; prefix rules match case-insensitively.
     */
    public function test_slug_matches_exact_and_prefix_case_insensitive() {
        $ids      = array( 'Divi', 'js_composer/js_composer.php' );
        $prefixes = array( 'yith-' );

        $this->assertTrue( MainWP_Premium_Update_Registry::slug_matches( 'Divi', $ids, $prefixes ) );
        $this->assertTrue( MainWP_Premium_Update_Registry::slug_matches( 'divi', $ids, $prefixes ) );
        $this->assertTrue( MainWP_Premium_Update_Registry::slug_matches( 'JS_Composer/js_composer.php', $ids, $prefixes ) );
        $this->assertTrue( MainWP_Premium_Update_Registry::slug_matches( 'yith-woocommerce-wishlist-premium/init.php', $ids, $prefixes ) );
        $this->assertTrue( MainWP_Premium_Update_Registry::slug_matches( 'YITH-woocommerce-wishlist-premium/init.php', $ids, $prefixes ) );
        $this->assertFalse( MainWP_Premium_Update_Registry::slug_matches( 'divi-child', $ids, $prefixes ) );
        $this->assertFalse( MainWP_Premium_Update_Registry::slug_matches( 'akismet/akismet.php', $ids, $prefixes ) );
        $this->assertFalse( MainWP_Premium_Update_Registry::slug_matches( '', $ids, $prefixes ) );
    }

    /**
     * Manifest expectations: shipped identifiers present, removed ones absent.
     */
    public function test_filter_defaults_manifest() {
        $detect_plugins = MainWP_Premium_Update_Registry::get_filter_defaults( 'plugin', 'detect' );
        $detect_themes  = MainWP_Premium_Update_Registry::get_filter_defaults( 'theme', 'detect' );
        $req_plugins    = MainWP_Premium_Update_Registry::get_filter_defaults( 'plugin', 'request' );
        $req_themes     = MainWP_Premium_Update_Registry::get_filter_defaults( 'theme', 'request' );

        $this->assertContains( 'divi-builder/divi-builder.php', $detect_plugins );
        $this->assertContains( 'oxygen/functions.php', $detect_plugins );
        $this->assertContains( 'oxygen/plugin.php', $detect_plugins );
        $this->assertContains( 'updraftplus/updraftplus.php', $detect_plugins );
        $this->assertNotContains( 'elementor-extras/elementor-extras.php', $detect_plugins, 'elementor-extras is discontinued and removed from the registry.' );

        $this->assertContains( 'Divi', $detect_themes );
        $this->assertContains( 'Avada', $detect_themes );
        $this->assertContains( 'yootheme', $detect_themes );

        $this->assertContains( 'js_composer/js_composer.php', $req_plugins );
        $this->assertNotContains( 'wp-rocket/wp-rocket.php', $req_plugins, 'Detect-only entries stay off the request list.' );

        $this->assertContains( 'Divi', $req_themes );
        $this->assertNotContains( 'Avada', $req_themes, 'Avada is detect-only.' );

        $this->assertContains( 'yith-', MainWP_Premium_Update_Registry::get_prefixes( 'plugin', 'detect' ) );
        $this->assertContains( 'yith-', MainWP_Premium_Update_Registry::get_prefixes( 'plugin', 'request' ) );

        // Prefix rules are internal; they never leak into the filtered arrays.
        $this->assertNotContains( 'yith-', $detect_plugins );
        $this->assertNotContains( 'yith-', $req_plugins );
    }

    /**
     * check_premium_updates(): registry matching for plugins.
     */
    public function test_check_premium_updates_plugins() {
        $installed = array(
            array( 'slug' => 'akismet/akismet.php' ),
            array( 'slug' => 'divi-builder/divi-builder.php' ),
        );
        $this->assertTrue( MainWP_Premium_Update::check_premium_updates( $installed, 'plugin' ) );

        $installed = array(
            array( 'slug' => 'yith-woocommerce-gift-cards-premium/init.php' ),
        );
        $this->assertTrue( MainWP_Premium_Update::check_premium_updates( $installed, 'plugin' ) );

        $installed = array(
            array( 'slug' => 'akismet/akismet.php' ),
        );
        $this->assertFalse( MainWP_Premium_Update::check_premium_updates( $installed, 'plugin' ) );

        $this->assertFalse( MainWP_Premium_Update::check_premium_updates( array(), 'plugin' ) );
    }

    /**
     * check_premium_updates(): themes are gated on active theme (+ parent),
     * with graceful fallback for older children that report no activity flags.
     */
    public function test_check_premium_updates_theme_active_gating() {
        // Active premium theme matches.
        $themes = array(
            array(
                'slug'          => 'Divi',
                'active'        => 1,
                'parent_active' => 0,
            ),
        );
        $this->assertTrue( MainWP_Premium_Update::check_premium_updates( $themes, 'theme' ) );

        // Parent of the active child theme matches.
        $themes = array(
            array(
                'slug'          => 'Divi',
                'active'        => 0,
                'parent_active' => 1,
            ),
            array(
                'slug'          => 'divi-child',
                'active'        => 1,
                'parent_active' => 0,
            ),
        );
        $this->assertTrue( MainWP_Premium_Update::check_premium_updates( $themes, 'theme' ) );

        // Installed-but-inactive premium theme does not trigger the checks.
        $themes = array(
            array(
                'slug'          => 'Divi',
                'active'        => 0,
                'parent_active' => 0,
            ),
            array(
                'slug'          => 'twentytwentyfive',
                'active'        => 1,
                'parent_active' => 0,
            ),
        );
        $this->assertFalse( MainWP_Premium_Update::check_premium_updates( $themes, 'theme' ) );

        // Older child payloads without activity flags keep matching.
        $themes = array(
            array( 'slug' => 'Divi' ),
        );
        $this->assertTrue( MainWP_Premium_Update::check_premium_updates( $themes, 'theme' ) );

        // Case-insensitive comparison absorbs hand-typed or mangled case.
        $themes = array(
            array(
                'slug'   => 'divi',
                'active' => 1,
            ),
        );
        $this->assertTrue( MainWP_Premium_Update::check_premium_updates( $themes, 'theme' ) );
    }

    /**
     * Filters stay additive: appended identifiers match, and filters receive
     * the registry defaults as input.
     */
    public function test_filters_stay_additive() {
        $received = null;
        add_filter(
            'mainwp_detect_premium_plugins_update',
            function ( $premiums ) use ( &$received ) {
                $received   = $premiums;
                $premiums[] = 'my-custom-premium/my-custom-premium.php';
                return $premiums;
            }
        );

        $installed = array(
            array( 'slug' => 'my-custom-premium/my-custom-premium.php' ),
        );
        $this->assertTrue( MainWP_Premium_Update::check_premium_updates( $installed, 'plugin' ) );
        $this->assertIsArray( $received );
        $this->assertContains( 'divi-builder/divi-builder.php', $received, 'Filters receive registry defaults as input.' );
    }

    /**
     * Replace-style snippets wipe the exact defaults (documented behavior),
     * while internal prefix rules keep working.
     */
    public function test_replace_style_filter_wipes_exact_defaults() {
        add_filter(
            'mainwp_detect_premium_plugins_update',
            function () {
                return array( 'only-mine/only-mine.php' );
            }
        );

        $this->assertFalse(
            MainWP_Premium_Update::check_premium_updates(
                array( array( 'slug' => 'divi-builder/divi-builder.php' ) ),
                'plugin'
            )
        );
        $this->assertTrue(
            MainWP_Premium_Update::check_premium_updates(
                array( array( 'slug' => 'only-mine/only-mine.php' ) ),
                'plugin'
            )
        );
        $this->assertTrue(
            MainWP_Premium_Update::check_premium_updates(
                array( array( 'slug' => 'yith-woocommerce-wishlist-premium/init.php' ) ),
                'plugin'
            ),
            'Prefix rules are internal and unaffected by replace-style snippets, matching the historical strpos behavior.'
        );
    }

    /**
     * check_request_update_premium(): single-item constraint and prefix support.
     */
    public function test_check_request_update_premium() {
        $this->assertTrue( MainWP_Premium_Update::check_request_update_premium( 'yith-woocommerce-gift-cards-premium/init.php', 'plugin' ) );
        $this->assertTrue( MainWP_Premium_Update::check_request_update_premium( 'Divi', 'theme' ) );
        $this->assertTrue( MainWP_Premium_Update::check_request_update_premium( 'divi', 'theme' ) );
        $this->assertFalse( MainWP_Premium_Update::check_request_update_premium( 'Avada', 'theme' ), 'Avada is detect-only.' );
        $this->assertFalse( MainWP_Premium_Update::check_request_update_premium( 'akismet/akismet.php', 'plugin' ) );

        // The premium request route only ever handles a single item.
        $this->assertFalse( MainWP_Premium_Update::check_request_update_premium( 'Divi,Extra', 'theme' ) );
        $this->assertFalse( MainWP_Premium_Update::check_request_update_premium( 'yith-a-premium/init.php,yith-b-premium/init.php', 'plugin' ) );
    }

    /**
     * Kill switch reverts to the exact pre-registry behavior.
     */
    public function test_kill_switch_reverts_to_legacy_behavior() {
        update_option( MainWP_Premium_Update_Registry::OPTION_ENABLED, 0 );

        // Registry-added theme entries are inert.
        $this->assertFalse(
            MainWP_Premium_Update::check_premium_updates(
                array(
                    array(
                        'slug'   => 'Divi',
                        'active' => 1,
                    ),
                ),
                'theme'
            )
        );

        // The historical substring hack still detects YITH plugins.
        $this->assertTrue(
            MainWP_Premium_Update::check_premium_updates(
                array( array( 'slug' => 'yith-woocommerce-wishlist-premium/init.php' ) ),
                'plugin'
            )
        );

        // The legacy hardcoded list applies verbatim, including entries the registry removed.
        $this->assertTrue(
            MainWP_Premium_Update::check_premium_updates(
                array( array( 'slug' => 'elementor-extras/elementor-extras.php' ) ),
                'plugin'
            )
        );

        // Legacy request list still contains only the single YITH entry.
        $this->assertTrue( MainWP_Premium_Update::check_request_update_premium( 'yith-woocommerce-request-a-quote-premium/init.php', 'plugin' ) );
        $this->assertFalse( MainWP_Premium_Update::check_request_update_premium( 'yith-woocommerce-gift-cards-premium/init.php', 'plugin' ) );
        $this->assertFalse( MainWP_Premium_Update::check_request_update_premium( 'Divi', 'theme' ) );
    }

    /**
     * Custom identifiers: sanitization, dedup, and matching for both purposes.
     */
    public function test_custom_entries_save_and_match() {
        MainWP_Premium_Update_Registry::save_custom_entries(
            array(
                array(
                    'type' => 'theme',
                    'id'   => 'betheme2',
                ),
                array(
                    'type' => 'theme',
                    'id'   => 'BeTheme2',
                ),
                array(
                    'type' => 'plugin',
                    'id'   => 'my-premium/my-premium.php',
                ),
                array(
                    'type' => 'plugin',
                    'id'   => 'bad id with spaces',
                ),
                array(
                    'type' => 'plugin',
                    'id'   => '<script>alert(1)</script>',
                ),
            )
        );

        $custom = MainWP_Premium_Update_Registry::get_custom_entries();
        $this->assertCount( 2, $custom, 'Duplicates (case-insensitive) and invalid identifiers are dropped.' );

        // Custom entries participate in detection and in the request route.
        $this->assertTrue(
            MainWP_Premium_Update::check_premium_updates(
                array(
                    array(
                        'slug'   => 'betheme2',
                        'active' => 1,
                    ),
                ),
                'theme'
            )
        );
        $this->assertTrue( MainWP_Premium_Update::check_request_update_premium( 'my-premium/my-premium.php', 'plugin' ) );
    }

    /**
     * Envelope parsing: valid envelope decodes; garbage and empty bodies yield null.
     */
    public function test_parse_mainwp_envelope() {
        $information = array(
            'upgrades'   => array( 'divi-builder/divi-builder.php' => 1 ),
            'other_data' => array(),
        );
        $body        = '<html><body>admin page noise <mainwp>' . base64_encode( wp_json_encode( $information ) ) . '</mainwp></body></html>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

        $parsed = MainWP_Premium_Update::parse_mainwp_envelope( $body );
        $this->assertIsArray( $parsed );
        $this->assertSame( $information['upgrades'], $parsed['upgrades'] );

        $this->assertNull( MainWP_Premium_Update::parse_mainwp_envelope( '' ) );
        $this->assertNull( MainWP_Premium_Update::parse_mainwp_envelope( '<html>timeout page with no envelope</html>' ) );
        $this->assertNull( MainWP_Premium_Update::parse_mainwp_envelope( '<mainwp>not-base64-json!!</mainwp>' ) );
    }
}
