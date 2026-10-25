<?php
/**
 * Imported image filename regression tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Page_Handler;

class Test_Post_Image_Import extends \WP_UnitTestCase {

    private $original_filesystem;
    private $upload_dir;
    private $attachments = array();
    private $temporary_files = array();
    private $fixture;
    private $source_url = 'https://child.example.org/uploads/';

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $this->original_filesystem = $GLOBALS['wp_filesystem'] ?? null;
        WP_Filesystem();
        $this->fixture = DIR_TESTDATA . '/images/codeispoetry.png';
        $base = wp_upload_dir();
        $name = '/mainwp-import-' . wp_generate_uuid4();
        $this->upload_dir = array_merge( $base, array( 'path' => $base['basedir'] . $name, 'url' => $base['baseurl'] . $name, 'subdir' => $name ) );
        wp_mkdir_p( $this->upload_dir['path'] );
        add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
        add_filter( 'pre_http_request', array( $this, 'download_fixture' ), PHP_INT_MAX, 3 );
        add_filter( 'pre_wp_mail', '__return_true' );
    }

    public function tearDown(): void {
        foreach ( $this->attachments as $attachment_id ) {
            wp_delete_attachment( $attachment_id, true );
        }
        foreach ( array_merge( $this->temporary_files, glob( $this->upload_dir['path'] . '/*' ) ) as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file );
            }
        }
        rmdir( $this->upload_dir['path'] );
        remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
        remove_filter( 'pre_http_request', array( $this, 'download_fixture' ), PHP_INT_MAX );
        remove_filter( 'pre_wp_mail', '__return_true' );
        $GLOBALS['wp_filesystem'] = $this->original_filesystem;
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function filter_upload_dir(): array {
        return $this->upload_dir;
    }

    public function download_fixture( $preempt, $args, $url ) {
        if ( 0 !== strpos( $url, $this->source_url ) ) {
            return new \WP_Error( 'unexpected_http', 'Outbound HTTP blocked.' );
        }
        $this->assertTrue( $args['stream'] );
        $this->assertNotEmpty( $args['filename'] );
        $this->temporary_files[] = $args['filename'];
        $this->assertTrue( copy( $this->fixture, $args['filename'] ) );
        return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
    }

    private function upload( string $filename, array $data = array() ): array {
        $result = MainWP_Post_Page_Handler::upload_image( $this->source_url . $filename, $data );
        if ( is_array( $result ) ) {
            $this->attachments[] = $result['id'];
        }
        $this->assertIsArray( $result );
        return $result;
    }

    private function assert_attachment( array $result, string $filename, string $title ): void {
        $path = $this->upload_dir['path'] . '/' . $filename;
        $url  = $this->upload_dir['url'] . '/' . $filename;
        $this->assertSame( $path, get_attached_file( $result['id'] ) );
        $this->assertSame( $url, $result['url'] );
        $this->assertSame( $url, wp_get_attachment_url( $result['id'] ) );
        $this->assertSame( file_get_contents( $this->fixture ), file_get_contents( $path ) );
        $this->assertSame( 'image/png', get_post_mime_type( $result['id'] ) );
        $this->assertSame( $title, get_post( $result['id'] )->post_title );
        $metadata = wp_get_attachment_metadata( $result['id'] );
        $size     = getimagesize( $this->fixture );
        $this->assertSame( $size[0], $metadata['width'] );
        $this->assertSame( $size[1], $metadata['height'] );
        $this->assertSame( ltrim( $this->upload_dir['subdir'], '/' ) . '/' . $filename, $metadata['file'] );
        foreach ( $this->temporary_files as $temporary_file ) {
            $this->assertFileDoesNotExist( $temporary_file );
        }
    }

    public function test_collision_preserves_existing_bytes_and_uses_unique_attachment_name(): void {
        $path = $this->upload_dir['path'] . '/logo.png';
        file_put_contents( $path, 'existing dashboard image bytes' );
        $result = $this->upload( 'logo.png' );
        $this->assertSame( hash( 'sha256', 'existing dashboard image bytes' ), hash_file( 'sha256', $path ) );
        $this->assert_attachment( $result, 'logo-1.png', 'logo-1' );
    }

    /**
     * @dataProvider no_collision_filenames
     */
    public function test_no_collision_keeps_original_filename_and_default_title( string $filename, string $title ): void {
        $this->assert_attachment( $this->upload( $filename ), $filename, $title );
    }

    public static function no_collision_filenames(): array {
        return array(
            'ordinary'       => array( 'original.png', 'original' ),
            'size suffix'    => array( 'original-150x150.png', 'original-150x150' ),
            'uppercase type' => array( 'original.PNG', 'original' ),
        );
    }

    public function test_supplied_metadata_is_preserved_with_a_collision(): void {
        file_put_contents( $this->upload_dir['path'] . '/metadata.png', 'existing' );
        $result = $this->upload( 'metadata.png', array( 'title' => 'Supplied title', 'description' => 'Supplied description', 'caption' => 'Supplied caption', 'alt' => 'Supplied alt' ) );
        $this->assert_attachment( $result, 'metadata-1.png', 'Supplied title' );
        $attachment = get_post( $result['id'] );
        $this->assertSame( 'Supplied description', $attachment->post_content );
        $this->assertSame( 'Supplied caption', $attachment->post_excerpt );
        $this->assertSame( 'Supplied alt', get_post_meta( $result['id'], '_wp_attachment_image_alt', true ) );
    }

    public function test_invalid_extension_returns_null_and_removes_downloaded_temp_file(): void {
        $this->assertNull( MainWP_Post_Page_Handler::upload_image( $this->source_url . 'blocked.php' ) );
        $this->assertFileDoesNotExist( $this->upload_dir['path'] . '/blocked.php' );
        $this->assertCount( 1, $this->temporary_files );
        $this->assertFileDoesNotExist( $this->temporary_files[0] );
    }

    public function test_embedded_content_retains_the_allocated_url(): void {
        $this->assert_content_caller( 'embedded' );
    }

    public function test_advanced_content_retains_the_allocated_url(): void {
        $this->assert_content_caller( 'advanced' );
    }

    /**
     * @dataProvider content_callers
     */
    public function test_multiple_images_keep_unique_urls_and_download_each_source_once( string $caller ): void {
        $path = $this->upload_dir['path'] . '/shared.png';
        file_put_contents( $path, 'existing shared image' );
        $content = '';
        foreach ( array( 'first.png', 'shared-150x150.png', 'shared.png', 'shared-150x150.png' ) as $filename ) {
            $content .= '<img src="' . $this->source_url . $filename . '" />';
        }
        $website = (object) array( 'url' => 'https://child.example.org/' );
        $uploads = array( 'baseurl' => $this->source_url );
        if ( 'embedded' === $caller ) {
            $result = MainWP_Post_Page_Handler::create_post(
                array( 'edit_id' => 123, 'post_title' => 'Imported images', 'post_name' => 'imported-images', 'post_type' => 'post', 'post_status' => 'draft', 'post_content' => $content ),
                array(), '', '', $uploads, '', array(), false, $website
            );
            $this->assertTrue( $result['success'] );
            $content = get_post( $result['id'] )->post_content;
        } else {
            $content = MainWP_Post_Page_Handler::replace_advanced_image( $content, $uploads, $website );
        }
        $this->attachments = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => -1 ) );
        $this->assertSame( 'existing shared image', file_get_contents( $path ) );
        $this->assertSame( 1, substr_count( $content, 'src="' . $this->upload_dir['url'] . '/first.png"' ), $caller );
        $this->assertSame( 3, substr_count( $content, 'src="' . $this->upload_dir['url'] . '/shared-1.png"' ), $caller );
        $this->assertStringNotContainsString( $this->source_url, $content );
        $this->assertCount( 2, $this->temporary_files, 'Each original source should be downloaded once.' );
        $this->assertCount( 2, $this->attachments, 'Repeated references should share one attachment.' );
        $this->assertFileDoesNotExist( $this->upload_dir['path'] . '/shared-2.png' );
    }

    public static function content_callers(): array {
        return array( 'embedded' => array( 'embedded' ), 'advanced' => array( 'advanced' ) );
    }

    public function test_embedded_linked_thumbnail_retains_the_allocated_full_image_url(): void {
        $this->assert_content_caller( 'embedded', true );
    }

    public function test_advanced_linked_thumbnail_retains_the_allocated_full_image_url(): void {
        $this->assert_content_caller( 'advanced', true );
    }

    public function test_advanced_import_reuses_an_image_in_an_escaped_reference(): void {
        $source = $this->source_url . 'shared.png';
        $content = '<img src="' . $source . '" /><!-- wp:image {"url":"' . str_replace( '/', '\/', $source ) . '"} -->';
        $result = MainWP_Post_Page_Handler::create_post(
            array( 'edit_id' => 123, 'post_title' => 'Mixed image references', 'post_name' => 'mixed-images', 'post_type' => 'post', 'post_status' => 'draft', 'post_content' => $content ),
            array(), '', '', array( 'baseurl' => $this->source_url ), '', array(), true, (object) array( 'url' => 'https://child.example.org/' )
        );
        $this->attachments = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => -1 ) );
        $this->assertTrue( $result['success'] );
        $this->assertCount( 1, $this->temporary_files, 'Raw and escaped references should share one download.' );
        $this->assertCount( 1, $this->attachments );
        $this->assertFileDoesNotExist( $this->upload_dir['path'] . '/shared-1.png' );
        $saved = str_replace( '\/', '/', get_post( $result['id'] )->post_content );
        $this->assertStringNotContainsString( $this->source_url, $saved );
        $this->assertSame( 2, substr_count( $saved, $this->upload_dir['url'] . '/shared.png' ) );
    }

    private function assert_content_caller( string $caller, bool $linked_thumbnail = false ): void {
        $filename = $caller . '.png';
        $path     = $this->upload_dir['path'] . '/' . $filename;
        file_put_contents( $path, 'existing ' . $caller );
        $source_filename = $linked_thumbnail ? $caller . '-150x150.png' : $filename;
        $content = '<img src="' . $this->source_url . $source_filename . '" />';
        if ( $linked_thumbnail ) {
            $content = '<a href="' . $this->source_url . $filename . '">' . $content . '</a>';
        }
        $website = (object) array( 'url' => 'https://child.example.org/' );
        $uploads = array( 'baseurl' => $this->source_url );
        if ( 'embedded' === $caller ) {
            $result = MainWP_Post_Page_Handler::create_post(
                array( 'edit_id' => 123, 'post_title' => 'Imported post', 'post_name' => 'imported-post', 'post_type' => 'post', 'post_status' => 'draft', 'post_content' => $content ),
                array(), '', '', $uploads, '', array(), false, $website
            );
            $this->assertTrue( $result['success'] );
            $content = get_post( $result['id'] )->post_content;
        } else {
            $content = MainWP_Post_Page_Handler::replace_advanced_image( $content, $uploads, $website );
        }
        $attachment_ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => -1 ) );
        $this->attachments = array_unique( array_merge( $this->attachments, $attachment_ids ) );
        $this->assertStringContainsString( 'src="' . $this->upload_dir['url'] . '/' . $caller . '-1.png"', $content, $caller );
        $this->assertStringNotContainsString( $this->source_url, $content, $caller );
        $this->assertSame( 'existing ' . $caller, file_get_contents( $path ) );
        if ( $linked_thumbnail ) {
            $this->assertStringContainsString( 'href="' . $this->upload_dir['url'] . '/' . $caller . '-1.png"', $content, $caller );
        }
    }
}
