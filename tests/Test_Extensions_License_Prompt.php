<?php
/**
 * Install add-ons modal purchase prompt by license state (MWP-1584).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Isolated key fixtures need local file operations.

use MainWP\Dashboard\MainWP_Api_Manager_Key;
use MainWP\Dashboard\MainWP_Extensions_View;
use MainWP\Dashboard\MainWP_Keys_Manager;

/** Pro members get a refresh path instead of Upgrade to Pro. */
class Test_Extensions_License_Prompt extends \WP_UnitTestCase {

    private const DOC_URL = 'https://docs.mainwp.com/troubleshooting/troubleshoot-extension-licensing-issues';

    private const BUTTON = 'class="ui basic button mainwp-extensions-refresh-license"';

    /**
     * Original options.
     *
     * @var array
     */
    private $options = array();

    /**
     * Isolated key directory.
     *
     * @var string
     */
    private $fixture_root = '';

    /**
     * Upload directory filter.
     *
     * @var callable
     */
    private $upload_filter;

    /** Isolate license options and key files. */
    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        foreach ( array( 'mainwp_extensions_master_api_key', 'mainwp_extensions_api_save_login', 'mainwp_extensions_plan_info' ) as $name ) {
            $this->options[ $name ] = get_option( $name );
            delete_option( $name );
        }
        $this->fixture_root  = get_temp_dir() . 'mainwp-license-prompt-keys-' . wp_generate_uuid4();
        $this->upload_filter = function ( $upload ) {
            $upload['basedir'] = $this->fixture_root;
            return $upload;
        };
        mkdir( $this->fixture_root, 0700, true );
        add_filter( 'mainwp_get_wp_upload_dir', $this->upload_filter, PHP_INT_MAX );
        new MainWP_Api_Manager_Key();
        MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_extensions_master_api_key', false );
    }

    /** Restore options and remove key files. */
    public function tearDown(): void {
        foreach ( $this->options as $name => $value ) {
            false === $value ? delete_option( $name ) : update_option( $name, $value );
        }
        remove_filter( 'mainwp_get_wp_upload_dir', $this->upload_filter, PHP_INT_MAX );
        if ( is_dir( $this->fixture_root ) ) {
            $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->fixture_root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
            foreach ( $files as $file ) {
                $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
            }
            rmdir( $this->fixture_root );
        }
        parent::tearDown();
    }

    /**
     * Store a remembered license key.
     *
     * @param bool $remember Value of the Remember Key option.
     */
    private function store_key( bool $remember = true ): void {
        $this->assertTrue( MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_extensions_master_api_key', 'fixture-license-key' ) );
        update_option( 'mainwp_extensions_api_save_login', $remember );
    }

    /**
     * Cache plan details the way the license check stores them.
     *
     * @param string $purchased Plan term.
     * @param string $status    Plan status.
     */
    private function store_plan( string $purchased, string $status ): void {
        update_option(
            'mainwp_extensions_plan_info',
            wp_json_encode(
                array(
                    'plan_purchased' => $purchased,
                    'plan_status'    => $status,
                )
            )
        );
    }

    /** @return string Install add-ons modal markup. */
    private function modal(): string {
        ob_start();
        MainWP_Extensions_View::render_purchase_notice();
        return (string) ob_get_clean();
    }

    /** An active Pro plan never sees Upgrade to Pro and gets the refresh path. */
    public function test_pro_plan_gets_refresh_instead_of_upgrade(): void {
        foreach ( array( 'monthly', 'yearly', 'lifetime' ) as $term ) {
            $this->store_key();
            $this->store_plan( $term, 'active' );
            $html = $this->modal();
            $this->assertStringNotContainsString( 'Upgrade to Pro', $html, $term );
            $this->assertStringNotContainsString( 'mainwp.com/signup', $html, $term );
            $this->assertStringContainsString( self::BUTTON, $html, $term );
            $this->assertStringContainsString( 'includes all MainWP add-ons', $html, $term );
            $this->assertStringContainsString( self::DOC_URL, $html, $term );
        }
    }

    /** Without a license key the modal keeps today's Upgrade to Pro button only. */
    public function test_no_key_keeps_upgrade_only(): void {
        $html = $this->modal();
        $this->assertStringContainsString( 'Upgrade to Pro', $html );
        $this->assertStringContainsString( 'mainwp.com/signup', $html );
        $this->assertStringNotContainsString( 'mainwp-extensions-refresh-license', $html );
    }

    /** A key whose cached plan is missing or not active keeps Upgrade to Pro and offers a refresh. */
    public function test_key_without_active_plan_offers_refresh_beside_upgrade(): void {
        $this->store_key();
        foreach ( array( 'missing', 'expired', 'free' ) as $case ) {
            if ( 'expired' === $case ) {
                $this->store_plan( 'lifetime', 'expired' );
            } elseif ( 'free' === $case ) {
                $this->store_plan( 'free', 'active' );
            }
            $html = $this->modal();
            $this->assertStringContainsString( 'Upgrade to Pro', $html, $case );
            $this->assertStringContainsString( self::BUTTON, $html, $case );
            $this->assertStringContainsString( self::DOC_URL, $html, $case );
        }
    }

    /** A key that is not remembered is treated as no key, matching the Add-ons page. */
    public function test_unremembered_key_counts_as_no_key(): void {
        $this->store_key( false );
        $html = $this->modal();
        $this->assertStringContainsString( 'Upgrade to Pro', $html );
        $this->assertStringNotContainsString( 'mainwp-extensions-refresh-license', $html );
    }

    /** Without the Add-ons right the refresh action would be refused, so only the note and help link render. */
    public function test_refresh_button_needs_manage_extensions_right(): void {
        $this->store_key();
        $this->store_plan( 'lifetime', 'active' );
        $deny = static function ( $can, $type, $cap ) {
            return ( 'dashboard' === $type && 'manage_extensions' === $cap ) ? false : $can;
        };
        add_filter( 'mainwp_currentusercan', $deny, PHP_INT_MAX, 3 );
        try {
            $html = $this->modal();
        } finally {
            remove_filter( 'mainwp_currentusercan', $deny, PHP_INT_MAX );
        }
        $this->assertStringNotContainsString( 'Upgrade to Pro', $html );
        $this->assertStringContainsString( 'includes all MainWP add-ons', $html );
        $this->assertStringContainsString( self::DOC_URL, $html );
        $this->assertStringNotContainsString( self::BUTTON, $html );
    }

    /** The refresh action refuses non-administrators even with the Add-ons right, so they get no button. */
    public function test_refresh_button_needs_administrator(): void {
        $this->store_key();
        $this->store_plan( 'lifetime', 'active' );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
        $allow = static function ( $can, $type, $cap ) {
            return ( 'dashboard' === $type && 'manage_extensions' === $cap ) ? true : $can;
        };
        add_filter( 'mainwp_currentusercan', $allow, PHP_INT_MAX, 3 );
        try {
            $html = $this->modal();
        } finally {
            remove_filter( 'mainwp_currentusercan', $allow, PHP_INT_MAX );
        }
        $this->assertStringNotContainsString( 'Upgrade to Pro', $html );
        $this->assertStringContainsString( 'includes all MainWP add-ons', $html );
        $this->assertStringContainsString( self::DOC_URL, $html );
        $this->assertStringNotContainsString( self::BUTTON, $html );
    }
}
