<?php
/**
 * Setup requires the WordPress Abilities API.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard;

/**
 * Simulate older WordPress without changing the installed core.
 *
 * @param string $name Function name.
 * @return bool
 */
function function_exists( $name ) {
    if ( 'wp_register_ability' === $name && null !== Test_Setup_Abilities_Requirement::$abilities_available ) {
        return Test_Setup_Abilities_Requirement::$abilities_available;
    }
    return \function_exists( $name );
}

class Test_Setup_Abilities_Requirement extends \WP_UnitTestCase {
    public static $abilities_available = null;

    public function set_up() {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        set_current_screen( 'dashboard' );
    }

    public function tear_down() {
        self::$abilities_available = null;
        set_current_screen( 'front' );
        parent::tear_down();
    }

    public static function setup_pages() {
        return array(
            'MCP'     => array( MainWP_MCP::class, 'mainwp-mcp-steps', 'mainwp-mcp-config' ),
            'Control' => array( MainWP_Control::class, 'mainwp-control-steps', 'mainwp-control-install-commands' ),
        );
    }

    /** @dataProvider setup_pages */
    public function test_old_wordpress_replaces_setup_with_notice( $page, $steps, $config ) {
        self::$abilities_available = false;
        update_option( 'mainwp_onboarding_fresh_install', 1 );
        delete_option( 'mainwp_onboarding_mcp_visited' );
        $passwords = \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() );
        $html = $this->render_page( $page );
        $this->assertStringContainsString( 'ui blue message', $html );
        $this->assertStringContainsString( 'MainWP MCP and MainWP Control require WordPress 6.9 or later. Update WordPress on this Dashboard to set up a connection.', $html );
        $this->assertStringNotContainsString( 'id="' . $steps . '"', $html );
        $this->assertStringNotContainsString( 'id="' . $config . '"', $html );
        $this->assertStringNotContainsString( 'mainwp-inline-application-password-creator', $html );
        $this->assertSame( $passwords, \WP_Application_Passwords::get_user_application_passwords( get_current_user_id() ) );
        $this->assertFalse( get_option( 'mainwp_onboarding_mcp_visited' ) );
    }

    /** @dataProvider setup_pages */
    public function test_supported_wordpress_keeps_setup( $page, $steps, $config ) {
        self::$abilities_available = true;
        $html = $this->render_page( $page );
        $this->assertStringContainsString( 'id="' . $steps . '"', $html );
        $this->assertStringContainsString( 'id="' . $config . '"', $html );
        $this->assertStringNotContainsString( 'require WordPress 6.9 or later', $html );
        $this->assertStringContainsString( 'mainwp-inline-application-password-creator', $html );
    }

    /** @dataProvider setup_pages */
    public function test_version_notice_does_not_replace_permission_denial( $page ) {
        self::$abilities_available = false;
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
        $html = $this->render_page( $page );
        $this->assertStringContainsString( 'You do not have permission to access this page.', $html );
        $this->assertStringNotContainsString( 'require WordPress 6.9 or later', $html );
    }

    /** @dataProvider setup_pages */
    public function test_notice_is_translated_and_escaped( $page ) {
        self::$abilities_available = false;
        $translate = static function ( $translated, $text, $domain ) {
            if ( 'mainwp' === $domain && str_starts_with( $text, 'MainWP MCP and MainWP Control require' ) ) {
                return 'Update <WordPress> & continue';
            }
            return $translated;
        };
        add_filter( 'gettext', $translate, 10, 3 );
        try {
            $html = $this->render_page( $page );
            $this->assertStringContainsString( 'Update &lt;WordPress&gt; &amp; continue', $html );
            $this->assertStringNotContainsString( 'Update <WordPress>', $html );
        } finally {
            remove_filter( 'gettext', $translate, 10 );
        }
    }

    private function render_page( $page ) {
        ob_start();
        try {
            $page::render();
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
