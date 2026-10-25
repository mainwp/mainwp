<?php
/**
 * Notification core token HTML regression tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Notification;
use MainWP\Dashboard\MainWP_Notification_Settings;
use MainWP\Dashboard\MainWP_Notification_Template;
use MainWP\Dashboard\MainWP_System_Utility;

class Test_Notification_Core_Token_HTML extends \WP_UnitTestCase {

    use Site_Fixture;

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
        add_filter( 'pre_wp_mail', '__return_true' );
    }

    public function tearDown(): void {
        remove_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
        remove_filter( 'pre_wp_mail', '__return_true' );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function block_http() {
        return new \WP_Error( 'unexpected_http', 'Outbound HTTP blocked.' );
    }

    private function site( string $theme, string $name = 'R&D &amp; Partners <Ops>', array $info = array() ) {
        $site_id = $this->create_test_site( array( 'name' => $name ) );
        $this->set_site_option( $site_id, 'site_info', wp_json_encode( array_merge( array( 'themeactivated' => $theme, 'wpversion' => '6.8', 'phpversion' => '8.3', 'mysql_version' => '8.0' ), $info ) ) );
        return MainWP_DB::instance()->get_website_by_id( $site_id );
    }

    private function digest( $site, string $plugin = 'Plugin [client.site.theme]' ): string {
        return MainWP_Notification_Template::instance()->get_template_html(
            'emails/mainwp-daily-digest-email.php',
            array(
                'heading'            => 'Updates for [site.name]',
                'current_email_site' => $site,
                'available_updates'  => true,
                'wp_updates'         => array(),
                'theme_updates'      => array(),
                'sites_disconnected' => array(),
                'plugin_updates'     => array(
                    array( 'id' => $site->id, 'name' => $site->name, 'plugin' => $plugin, 'new' => false, 'current' => '1.0', 'new_version' => '1.1', 'trusted' => false, 'change_log' => '' ),
                ),
            )
        );
    }

    public function test_stored_child_theme_expanded_from_plugin_name_is_visible_text(): void {
        $theme = '<a data-injected="theme" href="https://invalid.example/">Theme & Co</a>';
        $site  = $this->site( $theme );
        $this->assertSame( $theme, MainWP_System_Utility::get_tokens_site_values( $site )['[client.site.theme]'] );
        $html = $this->digest( $site );
        $this->assertStringContainsString( 'Plugin ' . esc_html( $theme ), $html );
        $this->assertStringNotContainsString( '<a data-injected=', $html );
        $this->assertStringNotContainsString( '[client.site.theme]', $html );
        $this->assertStringContainsString( '<table', $html );
        $this->assertStringContainsString( '<h1', $html );
    }

    public function test_normal_theme_and_site_entities_render_without_double_encoding(): void {
        $site = $this->site( 'Twenty Twenty-Five & Friends &amp; Co' );
        $html = $this->digest( $site );
        $this->assertStringContainsString( 'Plugin Twenty Twenty-Five &amp; Friends &amp; Co', $html );
        $this->assertStringContainsString( 'Updates for R&amp;D &amp; Partners &lt;Ops&gt;', $html );
        $this->assertStringNotContainsString( '&amp;amp;', $html );
    }

    public function test_plain_text_digest_keeps_normal_token_text(): void {
        $mail = $this->sent_digest( $this->site( 'R&D Theme', 'Normal site' ), true );
        $this->assertStringContainsString( 'Content-Type: text/plain', implode( '\n', $mail['headers'] ) );
        $this->assertStringContainsString( 'Plugin R&D Theme', $mail['message'] );
        $this->assertStringNotContainsString( 'Plugin R&amp;D Theme', $mail['message'] );
    }

    public function test_html_digest_renders_child_markup_as_text(): void {
        $theme = '<a data-injected="theme" href="https://invalid.example/">Theme & Co</a>';
        $mail = $this->sent_digest( $this->site( $theme, 'Normal site' ), false );
        $this->assertStringContainsString( 'Content-Type: text/html', implode( '\n', $mail['headers'] ) );
        $this->assertStringContainsString( 'Plugin ' . esc_html( $theme ), $mail['message'] );
        $this->assertStringNotContainsString( '<a data-injected=', $mail['message'] );
    }

    private function sent_digest( $site, bool $plain_text ): array {
        $mail = null;
        $capture = static function ( $sent, $args ) use ( &$mail ) {
            $mail = $args;
            return true;
        };
        add_filter( 'pre_wp_mail', $capture, 20, 2 );
        try {
            MainWP_Notification::send_daily_digest_notification(
                array( 'disable' => false, 'recipients' => 'fixture@example.org', 'heading' => 'Updates', 'subject' => 'Digest' ),
                true, array(),
                array( array( 'id' => $site->id, 'name' => $site->name, 'plugin' => 'Plugin [client.site.theme]', 'new' => false, 'current' => '1.0', 'new_version' => '1.1', 'trusted' => false, 'change_log' => '' ) ),
                array(), array(), array( 'plain_text' => $plain_text, 'email_site' => $site )
            );
            $this->assertIsArray( $mail );
            return $mail;
        } finally {
            remove_filter( 'pre_wp_mail', $capture, 20 );
        }
    }

    public function test_all_core_values_are_escaped_only_in_the_html_body_path(): void {
        $site = $this->site( '<b>Theme</b>', '<b>Site</b>', array( 'wpversion' => '<i>WP</i>', 'phpversion' => '<em>PHP</em>', 'mysql_version' => '<strong>SQL</strong>' ) );
        $site->url = 'https://example.org/?a=1&b=2';
        $values = MainWP_System_Utility::get_tokens_site_values( $site, true );
        $tokens = implode( ' | ', array_keys( $values ) );
        $plain  = implode( ' | ', $values );
        $this->assertSame( $plain, MainWP_System_Utility::replace_tokens_values( $tokens, $values ) );
        $this->assertSame( implode( ' | ', array_map( 'esc_html', $values ) ), MainWP_Notification_Settings::replace_tokens_for_content( $tokens, $site ) );
        $this->assertSame( '<b>Theme</b>', MainWP_System_Utility::get_tokens_site_values( $site )['[client.site.theme]'] );
    }

    public function test_extension_token_values_remain_html_in_the_real_digest(): void {
        $filters = array(
            'mainwp_boilerplate_get_tokens'      => '[extension.boilerplate]',
            'mainwp_pro_reports_get_site_tokens' => '[extension.reports]',
            'mainwp_client_report_get_site_tokens' => '[extension.client]',
        );
        $callbacks = array();
        foreach ( $filters as $filter => $token ) {
            $callbacks[ $filter ] = static function () use ( $token ) {
                return array( $token => '<strong data-extension="' . $token . '">Extension HTML</strong>' );
            };
            add_filter( $filter, $callbacks[ $filter ] );
        }
        try {
            $html = $this->digest( $this->site( 'Normal theme', 'Normal site' ), implode( ' ', $filters ) );
            foreach ( $filters as $token ) {
                $this->assertStringContainsString( '<strong data-extension="' . $token . '">Extension HTML</strong>', $html );
            }
        } finally {
            foreach ( $callbacks as $filter => $callback ) {
                remove_filter( $filter, $callback );
            }
        }
    }
}
