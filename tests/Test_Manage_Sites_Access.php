<?php
/**
 * Manage Sites access-guard tests.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Manage_Sites_Handler;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Verify reconnect and monitor flows reject inaccessible site objects before use.
 */
class Test_Manage_Sites_Access extends \WP_UnitTestCase {

    /**
     * Whether the test access filter should allow the requested site.
     *
     * @var bool
     */
    private $allow_site_access = true;

    /**
     * Site capability IDs observed by the test filter.
     *
     * @var array
     */
    private $site_access_checks = array();

    /**
     * Set up the per-site access-control test filter.
     */
    public function setUp(): void {
        parent::setUp();

        $this->allow_site_access  = true;
        $this->site_access_checks = array();
        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );
        add_filter( 'mainwp_currentusercan', array( $this, 'filter_site_access' ), PHP_INT_MAX, 3 );
    }

    /**
     * Remove the per-site access-control test filter.
     */
    public function tearDown(): void {
        remove_filter( 'mainwp_currentusercan', array( $this, 'filter_site_access' ), PHP_INT_MAX );
        parent::tearDown();
    }

    /**
     * Control and record per-site capability decisions.
     *
     * @param bool   $allowed  Existing capability decision.
     * @param string $cap_type Capability type.
     * @param mixed  $cap      Requested capability or site ID.
     *
     * @return bool Filtered capability decision.
     */
    public function filter_site_access( $allowed, $cap_type, $cap ) {
        if ( 'site' !== $cap_type ) {
            return $allowed;
        }

        $this->site_access_checks[] = (int) $cap;
        return $this->allow_site_access;
    }

    /** Missing sites must be rejected without consulting a site capability. */
    public function test_missing_site_is_rejected() {
        $this->assertFalse( MainWP_Manage_Sites_Handler::can_access_site( null, 7 ) );
        $this->assertSame( array(), $this->site_access_checks );
    }

    /** A resolved object for a different site must be rejected. */
    public function test_mismatched_site_object_is_rejected() {
        $this->assertFalse( MainWP_Manage_Sites_Handler::can_access_site( (object) array( 'id' => 8 ), 7 ) );
        $this->assertSame( array(), $this->site_access_checks );
    }

    /** A matching object must still be rejected when the site capability is denied. */
    public function test_unauthorized_site_is_rejected() {
        $this->allow_site_access = false;

        $this->assertFalse( MainWP_Manage_Sites_Handler::can_access_site( (object) array( 'id' => 7 ), 7 ) );
        $this->assertSame( array( 7 ), $this->site_access_checks );
    }

    /** A matching, authorized site object must be accepted. */
    public function test_authorized_site_is_accepted() {
        $this->assertTrue( MainWP_Manage_Sites_Handler::can_access_site( (object) array( 'id' => 7 ), 7 ) );
        $this->assertSame( array( 7 ), $this->site_access_checks );
    }

    /** Reconnect must check access before credentials or connection work. */
    public function test_reconnect_checks_access_before_credentials_and_requests() {
        $source = file_get_contents( MAINWP_PLUGIN_DIR . 'pages/page-mainwp-manage-sites-handler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

        $entry_guard      = strpos( $source, 'if ( ! static::can_access_site( $website, $siteId ) )' );
        $credential_input = strpos( $source, '$params[\'wpadmin\']', $entry_guard );
        $reconnect_call   = strpos( $source, 'MainWP_Manage_Sites_View::m_reconnect_site( $website, $sync_first, $params );', $entry_guard );
        $fallback_guard   = strpos( $source, 'empty( $connection_diag ) && static::can_access_site( $website, $siteId )', $reconnect_call );
        $decrypt_call     = strpos( $source, 'MainWP_Credential_Storage::decrypt_credential', $fallback_guard );

        $this->assertNotFalse( $entry_guard );
        $this->assertNotFalse( $credential_input );
        $this->assertNotFalse( $reconnect_call );
        $this->assertNotFalse( $fallback_guard );
        $this->assertNotFalse( $decrypt_call );
        $this->assertTrue( $entry_guard < $credential_input );
        $this->assertTrue( $credential_input < $reconnect_call );
        $this->assertTrue( $reconnect_call < $fallback_guard );
        $this->assertTrue( $fallback_guard < $decrypt_call );
    }

    /** Monitor settings must check access before rendering the site. */
    public function test_monitor_checks_access_before_rendering() {
        $source = file_get_contents( MAINWP_PLUGIN_DIR . 'pages/page-mainwp-manage-sites.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

        $guard  = strpos( $source, 'if ( MainWP_Manage_Sites_Handler::can_access_site( $website, $websiteid ) )' );
        $render = strpos( $source, 'static::render_monitor_site( $website );', $guard );

        $this->assertNotFalse( $guard );
        $this->assertNotFalse( $render );
        $this->assertTrue( $guard < $render );
    }
}
