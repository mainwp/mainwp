<?php
/**
 * Failed post and page image imports.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Page_Handler;

/**
 * Keep failed image references while importing other images.
 *
 * @group mainwp-image-upload-failure
 */
class Test_Post_Page_Image_Upload_Failure extends \WP_UnitTestCase {

	/**
	 * Filesystem restored after each test.
	 *
	 * @var object|null
	 */
	private $original_filesystem;
	/**
	 * Separate uploads directory.
	 *
	 * @var array
	 */
	private $upload_dir;
	/**
	 * Downloaded temporary paths.
	 *
	 * @var array
	 */
	private $temporary_files = array();
	/**
	 * Requests per source URL.
	 *
	 * @var array
	 */
	private $downloads = array();
	/**
	 * PHP warnings and deprecations during import.
	 *
	 * @var array
	 */
	private $warnings = array();
	/**
	 * Child uploads URL.
	 *
	 * @var string
	 */
	private $source_url = 'https://child.example.org/uploads/';

	/**
	 * Prepare real image downloads and fail one filesystem move.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$this->original_filesystem = $GLOBALS['wp_filesystem'] ?? null;
		WP_Filesystem();
		$filesystem        = $GLOBALS['wp_filesystem'];
		$failed_filesystem = $this->getMockBuilder( \WP_Filesystem_Direct::class )->setConstructorArgs( array( null ) )->onlyMethods( array( 'move' ) )->getMock();
		$failed_filesystem->method( 'move' )->willReturnCallback(
			static function ( $source, $destination, $overwrite ) use ( $filesystem ) {
				if ( 'failed.png' === basename( $destination ) ) {
					return false;
				}
				return $filesystem->move( $source, $destination, $overwrite );
			}
		);
		$GLOBALS['wp_filesystem'] = $failed_filesystem;
		$base                     = wp_upload_dir();
		$name                     = '/mainwp-failed-import-' . wp_generate_uuid4();
		$this->upload_dir         = array_merge(
			$base,
			array(
				'path'   => $base['basedir'] . $name,
				'url'    => $base['baseurl'] . $name,
				'subdir' => $name,
			)
		);
		wp_mkdir_p( $this->upload_dir['path'] );
		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		add_filter( 'pre_http_request', array( $this, 'download_fixture' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	/**
	 * Remove imported files and restore filters and the filesystem.
	 */
	public function tearDown(): void {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		foreach ( $attachments as $attachment_id ) {
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

	/**
	 * Use a separate uploads directory.
	 *
	 * @return array Upload directory details.
	 */
	public function filter_upload_dir(): array {
		return $this->upload_dir;
	}

	/**
	 * Stream a valid image through the WordPress HTTP download path.
	 *
	 * @param mixed  $preempt Preempted HTTP response.
	 * @param array  $args HTTP request arguments.
	 * @param string $url Requested URL.
	 * @return array|\WP_Error HTTP response.
	 */
	public function download_fixture( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, $this->source_url ) ) {
			return new \WP_Error( 'unexpected_http', 'Outbound HTTP blocked.' );
		}
		$this->assertTrue( $args['stream'] );
		$this->assertNotEmpty( $args['filename'] );
		$this->temporary_files[] = $args['filename'];
		$this->downloads[ $url ] = ( $this->downloads[ $url ] ?? 0 ) + 1;
		$this->assertTrue( copy( DIR_TESTDATA . '/images/codeispoetry.png', $args['filename'] ) );
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Provide the supported importer entry points.
	 *
	 * @return array Importer cases.
	 */
	public static function importers(): array {
		return array(
			'embedded'                       => array( 'embedded' ),
			'advanced'                       => array( 'advanced' ),
			'escaped advanced'               => array( 'escaped' ),
			'combined embedded and advanced' => array( 'combined' ),
		);
	}

	/**
	 * Build linked, sized and repeated references to the failed image.
	 *
	 * @return string Source content.
	 */
	private function failed_content(): string {
		return '<a href="' . $this->source_url . 'failed.png"><img src="' . $this->source_url . 'failed-150x150.png" srcset="' . $this->source_url . 'failed-600x600.png 600w, ' . $this->source_url . 'failed-1024x768.png 1024w, ' . $this->source_url . 'failed-1536x1536.png 1536w, ' . $this->source_url . 'failed.png 600w" /></a><img src="' . $this->source_url . 'failed.png" /><img src="' . $this->source_url . 'failed-300x300.png" />';
	}

	/**
	 * Import content while collecting PHP warnings.
	 *
	 * @param string $caller Importer entry point.
	 * @param string $content Source content.
	 * @return string Imported content.
	 */
	private function import( string $caller, string $content ): string {
		$website = (object) array( 'url' => 'https://child.example.org/' );
		$uploads = array( 'baseurl' => $this->source_url );
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capture the regression warnings.
			function ( $severity, $message ) {
				$this->warnings[] = $message;
				return true;
			},
			E_WARNING | E_NOTICE | E_DEPRECATED
		);
		try {
			if ( 'embedded' === $caller || 'combined' === $caller ) {
				$result = MainWP_Post_Page_Handler::create_post(
					array(
						'edit_id'      => 123,
						'post_title'   => 'Failed image import',
						'post_name'    => 'failed-image-import',
						'post_type'    => 'post',
						'post_status'  => 'draft',
						'post_content' => $content,
					),
					array(),
					'',
					'',
					$uploads,
					'',
					array(),
					'combined' === $caller,
					$website
				);
				$this->assertTrue( $result['success'] );
				return get_post( $result['id'] )->post_content;
			}
			return MainWP_Post_Page_Handler::replace_advanced_image( $content, $uploads, $website, 'escaped' === $caller );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Include escaped references where the importer supports them.
	 *
	 * @param string $caller Importer entry point.
	 * @param string $content Source content.
	 * @return string Content for this importer.
	 */
	private function content_for( string $caller, string $content ): string {
		if ( 'escaped' === $caller ) {
			return str_replace( '/', '\/', $content );
		}
		if ( 'combined' === $caller ) {
			return $content . '<!-- wp:image {"url":"' . str_replace( '/', '\/', $this->source_url . 'failed.png' ) . '"} -->';
		}
		return $content;
	}

	/**
	 * Preserve failed image URLs, including links and srcset.
	 *
	 * @dataProvider importers
	 * @param string $caller Importer entry point.
	 */
	public function test_failed_move_preserves_image_links_and_srcset( string $caller ): void {
		$content  = $this->content_for( $caller, $this->failed_content() );
		$expected = 'combined' === $caller ? wp_unslash( $content ) : $content;
		$this->assertSame( $expected, $this->import( $caller, $content ) );
		$this->assertFileDoesNotExist( $this->upload_dir['path'] . '/failed.png' );
		foreach ( $this->temporary_files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
	}

	/**
	 * Cache failure within an import and retry on a later import.
	 *
	 * @dataProvider importers
	 * @param string $caller Importer entry point.
	 */
	public function test_repeated_failed_source_downloads_once_and_later_import_retries( string $caller ): void {
		$content = $this->content_for( $caller, $this->failed_content() );
		$this->import( $caller, $content );
		$this->assertSame( array( $this->source_url . 'failed.png' => 1 ), $this->downloads );
		$this->import( $caller, $content );
		$this->assertSame( array( $this->source_url . 'failed.png' => 2 ), $this->downloads );
	}

	/**
	 * Avoid PHP warnings when the filesystem move fails.
	 *
	 * @dataProvider importers
	 * @param string $caller Importer entry point.
	 */
	public function test_failed_move_emits_no_php_warnings( string $caller ): void {
		$this->import( $caller, $this->content_for( $caller, $this->failed_content() ) );
		$this->assertSame( array(), $this->warnings );
	}

	/**
	 * Rewrite successful images in the same directory as failed images.
	 *
	 * @dataProvider importers
	 * @param string $caller Importer entry point.
	 */
	public function test_successful_image_in_same_directory_is_replaced_without_changing_failed_urls( string $caller ): void {
		$success  = '<a href="' . $this->source_url . 'success.png"><img src="' . $this->source_url . 'success-150x150.png" /></a>';
		$content  = $this->content_for( $caller, $this->failed_content() . $success );
		$result   = $this->import( $caller, $content );
		$expected = $this->content_for( $caller, $this->failed_content() . $success );
		foreach ( array( 'success-150x150.png', 'success.png' ) as $filename ) {
			$source   = $this->source_url . $filename;
			$expected = str_replace( array( $source, str_replace( '/', '\/', $source ) ), $this->upload_dir['url'] . '/success.png', $expected );
		}
		$expected = 'combined' === $caller ? wp_unslash( $expected ) : $expected;
		$this->assertSame( $expected, $result );
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 1, $attachments );
		$this->assertSame( $this->upload_dir['url'] . '/success.png', wp_get_attachment_url( $attachments[0] ) );
		$this->assertSame( file_get_contents( DIR_TESTDATA . '/images/codeispoetry.png' ), file_get_contents( $this->upload_dir['path'] . '/success.png' ) );
		$this->assertSame(
			array(
				$this->source_url . 'failed.png'  => 1,
				$this->source_url . 'success.png' => 1,
			),
			$this->downloads
		);
		$this->assertSame( array(), $this->warnings );
	}
}
