<?php
/**
 * Daily Digest mail formats and version destinations.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Notification;

class Test_MWP_1901_Daily_Digest_Format extends \WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        reset_phpmailer_instance();
    }

    public function tear_down() {
        reset_phpmailer_instance();
        parent::tear_down();
    }

    private function send_digest( $plain_text = false, $updates = true ) {
        $item = array( 'id' => 1, 'name' => 'Example site', 'new' => true, 'current' => '1.0', 'new_version' => '2.0', 'trusted' => true );
        MainWP_Notification::send_daily_digest_notification(
            array( 'disable' => false, 'recipients' => 'digest@example.org', 'heading' => 'Daily Digest', 'subject' => 'Digest' ),
            $updates,
            $updates ? array( array_merge( $item, array( 'new_version' => '6.9' ) ) ) : array(),
            $updates ? array(
                array_merge( $item, array( 'plugin' => 'Linked plugin', 'change_log' => 'https://example.org/changelog?a=1&b=2' ) ),
                array_merge( $item, array( 'plugin' => 'Unlinked plugin', 'change_log' => '' ) ),
                array_merge( $item, array( 'plugin' => 'Placeholder plugin', 'change_log' => '#' ) ),
                array_merge( $item, array( 'plugin' => 'Relative plugin', 'change_log' => '/wordpress.org/plugins/relative' ) ),
                array_merge( $item, array( 'plugin' => 'Protocol-relative plugin', 'change_log' => '//example.org/changelog' ) ),
            ) : array(),
            $updates ? array( array_merge( $item, array( 'theme' => 'Example theme', 'new_version' => '3.0' ) ) ) : array(),
            array( array( 'name' => 'Disconnected site', 'url' => 'https://offline.example.org/' ) ),
            array( 'plain_text' => $plain_text )
        );
        return tests_retrieve_phpmailer_instance();
    }

    public function test_plain_text_has_status_rows_and_useful_urls_without_html() {
        $mailer = $this->send_digest( true );
        $this->assertSame( 'text/plain', $mailer->ContentType );
        $this->assertSame( strip_tags( $mailer->Body ), $mailer->Body );
        foreach ( array( 'WordPress Core Updates', 'Plugin Updates', 'Theme Updates', 'Detected', 'Latest', 'Trusted', 'Example site', '6.9', 'Linked plugin', 'Example theme', 'Disconnected Sites', 'Disconnected site', 'https://offline.example.org/', 'https://example.org/changelog?a=1&b=2', admin_url( 'admin.php?page=managesites&dashboard=1' ) ) as $value ) {
            $this->assertStringContainsString( $value, $mailer->Body );
        }
        $this->assertStringContainsString( 'NEW Example site', $mailer->Body );
        $this->assertStringContainsString( '| 1.0 | 6.9 | Yes', $mailer->Body );
        $this->assertStringNotContainsString( '&amp;', $mailer->Body );
        $this->assertSame( '', $mailer->AltBody );
        $this->assertNotFalse( $mailer->get_sent() );
    }

    public function test_html_mail_has_a_matching_mime_text_alternative_and_no_leak_to_next_mail() {
        $mailer = $this->send_digest();
        $mail   = $mailer->get_sent();
        $this->assertNotFalse( $mail );
        $this->assertStringContainsString( 'multipart/alternative', $mail->header );
        $this->assertStringContainsString( 'Content-Type: text/plain;', $mail->body );
        $this->assertStringContainsString( 'Content-Type: text/html;', $mail->body );
        $this->assertStringContainsString( 'Disconnected site', $mail->body );
        $this->assertSame( '', $mailer->AltBody, 'Digest alternative must not remain on the shared mailer.' );
        MainWP_Notification::send_websites_health_status_notification( 'digest@example.org', 'Health', '<p>Health unchanged</p>', false );
        $next = $mailer->get_sent( 1 );
        $this->assertNotFalse( $next );
        $this->assertStringNotContainsString( 'multipart/alternative', $next->header );
        $this->assertStringContainsString( '<p>Health unchanged</p>', $next->body );
        $this->assertStringNotContainsString( 'Disconnected site', $next->body );
    }

    public function test_versions_only_link_to_real_changelogs() {
        $html = $this->send_digest()->Body;
        $this->assertStringNotContainsString( 'href="#"', $html );
        $this->assertStringNotContainsString( 'href=""', $html );
        $document = new \DOMDocument();
        @$document->loadHTML( $html );
        $xpath = new \DOMXPath( $document );
        $this->assertSame( 0, $xpath->query( '//a[text()="6.9" or text()="3.0"]' )->length );
        $this->assertSame( 1, $xpath->query( '//a[text()="2.0"]' )->length );
        $this->assertSame( 'https://example.org/changelog?a=1&b=2', $xpath->query( '//a[text()="2.0"]' )->item( 0 )->getAttribute( 'href' ) );
    }

    public function test_no_updates_status_is_readable_in_plain_text() {
        $text = $this->send_digest( true, false )->Body;
        $this->assertStringContainsString( 'All your child sites are up to date!', $text );
        $this->assertSame( strip_tags( $text ), $text );
    }

    public function test_template_overrides_and_email_hooks_are_preserved() {
        $file = tempnam( sys_get_temp_dir(), 'digest-template-' );
        file_put_contents( $file, '<h1>Custom digest</h1><p>Custom status</p><a href="https://custom.example.org/status" data-href="https://preview.example.org/status">Details</a>' );
        $locate = static function ( $path, $name ) use ( $file ) {
            return 'emails/mainwp-daily-digest-email.php' === $name ? $file : $path;
        };
        $calls = 0;
        $filter = static function ( $value, $ids, $plain ) use ( &$calls ) {
            ++$calls;
            return $value;
        };
        add_filter( 'mainwp_locate_template', $locate, 10, 2 );
        add_filter( 'mainwp_daily_digest_content', $filter, 10, 3 );
        try {
            $text = $this->send_digest( true )->Body;
            $this->assertStringContainsString( 'Custom digest', $text );
            $this->assertStringContainsString( 'Custom status', $text );
            $this->assertStringContainsString( 'https://custom.example.org/status', $text );
            $this->assertStringNotContainsString( 'https://preview.example.org/status', $text );
            $this->assertSame( strip_tags( $text ), $text );
            $this->assertSame( 1, $calls );
        } finally {
            remove_filter( 'mainwp_locate_template', $locate );
            remove_filter( 'mainwp_daily_digest_content', $filter );
            unlink( $file );
        }
    }

    public function test_plain_text_template_override_keeps_its_status_lines() {
        $text = "Daily Digest\n\nSite A: WordPress 6.8 -> 6.9\nSite B: Disconnected\nhttps://offline.example.org/";
        $file = tempnam( sys_get_temp_dir(), 'digest-plain-template-' );
        file_put_contents( $file, $text );
        $locate = static function ( $path, $name ) use ( $file ) {
            return 'emails/mainwp-daily-digest-email.php' === $name ? $file : $path;
        };
        add_filter( 'mainwp_locate_template', $locate, 10, 2 );
        try {
            $this->assertSame( $text, $this->send_digest( true )->Body );
        } finally {
            remove_filter( 'mainwp_locate_template', $locate );
            unlink( $file );
        }
    }

    public function test_alternative_matches_plain_mode_and_includes_mail_filter_changes() {
        $expected = $this->send_digest( true )->Body;
        reset_phpmailer_instance();
        $alternative = null;
        $capture = static function () use ( &$alternative ) {
            $alternative = tests_retrieve_phpmailer_instance()->AltBody;
        };
        add_action( 'wp_mail_succeeded', $capture );
        try {
            $this->send_digest();
            $this->assertSame( $expected, $alternative );
            $modify = static function ( $args ) {
                $args['message'] .= '<p>Mail filter addition</p>';
                return $args;
            };
            add_filter( 'wp_mail', $modify );
            try {
                $this->send_digest();
                $this->assertStringContainsString( 'Mail filter addition', $alternative );
                $this->assertSame( strip_tags( $alternative ), $alternative );
            } finally {
                remove_filter( 'wp_mail', $modify );
            }
        } finally {
            remove_action( 'wp_mail_succeeded', $capture );
        }
    }

    public function test_send_exception_removes_the_temporary_hook_and_alternative() {
        $fail = static function () {
            throw new \RuntimeException( 'Fixture mail failure' );
        };
        add_action( 'wp_mail_succeeded', $fail, PHP_INT_MAX );
        try {
            try {
                $this->send_digest();
                $this->fail( 'Expected the mail integration to throw.' );
            } catch ( \RuntimeException $error ) {
                $this->assertSame( 'Fixture mail failure', $error->getMessage() );
            }
        } finally {
            remove_action( 'wp_mail_succeeded', $fail, PHP_INT_MAX );
        }
        $this->assertSame( '', tests_retrieve_phpmailer_instance()->AltBody );
        MainWP_Notification::send_websites_health_status_notification( 'digest@example.org', 'Health', '<p>Health after failure</p>', false );
        $mail = tests_retrieve_phpmailer_instance()->get_sent( 1 );
        $this->assertStringContainsString( '<p>Health after failure</p>', $mail->body );
        $this->assertStringNotContainsString( 'multipart/alternative', $mail->header );
    }

    public function test_mailer_integration_can_supply_its_own_alternative() {
        $callback = static function ( $mailer ) {
            $mailer->AltBody = 'Integration alternative';
        };
        add_action( 'phpmailer_init', $callback, 20 );
        try {
            $mail = $this->send_digest()->get_sent();
            $this->assertStringContainsString( 'Integration alternative', $mail->body );
        } finally {
            remove_action( 'phpmailer_init', $callback, 20 );
        }
    }
}
