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

	/**
	 * Existing subclasses can keep the public four-argument override.
	 */
	public function test_four_argument_subclass_override_loads_and_imports(): void {
		$method = new \ReflectionMethod( MainWP_Post_Page_Handler::class, 'replace_advanced_image' );
		$this->assertSame( 4, $method->getNumberOfParameters() );
		$handler = new class() extends MainWP_Post_Page_Handler {
			/**
			 * Delegate through the existing public signature.
			 *
			 * @param string $content Post content.
			 * @param array  $upload_dir Child uploads directory.
			 * @param object $website Child site.
			 * @param bool   $withslashes Whether URLs have escaped slashes.
			 * @return string Imported content.
			 */
			public static function replace_advanced_image( $content, $upload_dir, $website, $withslashes = false ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Exercise the legacy subclass declaration.
				return parent::replace_advanced_image( $content, $upload_dir, $website, $withslashes );
			}
		};
		$content = $this->failed_content();
		$this->assertSame( $content, $handler::replace_advanced_image( $content, array( 'baseurl' => $this->source_url ), (object) array( 'url' => 'https://child.example.org/' ), false ) );
		$this->assertSame( array( $this->source_url . 'failed.png' => 1 ), $this->downloads );
	}

	/**
	 * Cover successful dispatch and cleanup after an override throws.
	 *
	 * @return array Dispatch outcomes.
	 */
	public static function advanced_dispatch_outcomes(): array {
		return array(
			'normal'    => array( false ),
			'exception' => array( true ),
		);
	}

	/**
	 * Inherited imports call the public override and release their cache.
	 *
	 * @dataProvider advanced_dispatch_outcomes
	 * @param bool $throws Whether the override throws.
	 */
	public function test_create_post_dispatches_advanced_override_and_releases_cache( bool $throws ): void {
		$handler          = new class() extends MainWP_Post_Page_Handler {
			/**
			 * Observed advanced passes.
			 *
			 * @var array
			 */
			public static $passes = array();
			/**
			 * Whether to interrupt the import.
			 *
			 * @var bool
			 */
			public static $throws = false;
			/**
			 * Observe dispatch before delegating to the public importer.
			 *
			 * @param string $content Post content.
			 * @param array  $upload_dir Child uploads directory.
			 * @param object $website Child site.
			 * @param bool   $withslashes Whether URLs have escaped slashes.
			 * @return string Imported content.
			 * @throws \RuntimeException When the fixture interrupts the import.
			 */
			public static function replace_advanced_image( $content, $upload_dir, $website, $withslashes = false ) {
				self::$passes[] = $withslashes;
				if ( self::$throws ) {
					throw new \RuntimeException( 'Interrupted advanced image import.' );
				}
				return parent::replace_advanced_image( $content, $upload_dir, $website, $withslashes );
			}
		};
		$handler::$passes = array();
		$handler::$throws = $throws;
		$failed           = $this->source_url . 'failed.png';
		$success          = $this->source_url . 'success.png';
		$content          = '<img src="' . $failed . '" /><img src="' . $success . '" />';
		$result           = null;
		try {
			$result = $handler::create_post(
				array(
					'edit_id'      => 123,
					'post_title'   => 'Subclass image import',
					'post_name'    => 'subclass-image-import',
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_content' => $content,
				),
				array(),
				'',
				'',
				array( 'baseurl' => $this->source_url ),
				'',
				array(),
				true,
				(object) array( 'url' => 'https://child.example.org/' )
			);
		} catch ( \RuntimeException $exception ) {
			$this->assertTrue( $throws );
			$this->assertSame( 'Interrupted advanced image import.', $exception->getMessage() );
		}
		$this->assertSame( $throws ? array( false ) : array( false, true ), $handler::$passes );
		if ( $throws ) {
			$this->assertNull( $result );
		} else {
			$this->assertTrue( $result['success'] );
			$this->assertSame( str_replace( $success, $this->upload_dir['url'] . '/success.png', $content ), get_post( $result['id'] )->post_content );
		}
		$this->assertSame(
			array(
				$failed  => 1,
				$success => 1,
			),
			$this->downloads
		);
		$this->assertSame( $failed, MainWP_Post_Page_Handler::replace_advanced_image( $failed, array( 'baseurl' => $this->source_url ), (object) array( 'url' => 'https://child.example.org/' ) ) );
		$this->assertSame( 2, $this->downloads[ $failed ] );
	}

	/**
	 * Cover both advanced passes after the embedded import.
	 *
	 * @return array URL forms.
	 */
	public static function fallback_url_forms(): array {
		return array(
			'plain'   => array( false ),
			'escaped' => array( true ),
		);
	}

	/**
	 * Cached failures survive a fallback whose matcher skips dotted folders.
	 *
	 * @dataProvider fallback_url_forms
	 * @param bool $escaped Whether advanced URLs have escaped slashes.
	 */
	public function test_advanced_fallback_preserves_cached_dotted_folder_failure( bool $escaped ): void {
		$failed    = $this->source_url . '2026.10/failed.png';
		$success   = $this->source_url . '2026.10/success.png';
		$advanced  = $this->source_url . 'advanced.png';
		$reference = $escaped ? str_replace( '/', '\\/', $failed ) : $failed;
		$trigger   = $escaped ? str_replace( '/', '\\/', $advanced ) : $advanced;
		$content   = '<img src="' . $failed . '" /><img src="' . $success . '" /><!-- wp:image {"url":"' . $reference . '"} --><div style="background-image:url(' . $trigger . ')"></div>';
		$imported  = null;
		$capture   = static function ( $data, $postarr, $unsanitized_postarr ) use ( &$imported ) {
			if ( 'bulkpost' === $data['post_type'] && null === $imported ) {
				$imported = $unsanitized_postarr['post_content'];
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $capture, 10, 3 );
		try {
			$result = $this->import( 'combined', $content );
		} finally {
			remove_filter( 'wp_insert_post_data', $capture );
		}
		$expected = str_replace( $success, $this->upload_dir['url'] . '/success.png', $content );
		$expected = str_replace( $trigger, $this->upload_dir['url'] . '/advanced.png', $expected );
		$this->assertSame( $expected, $imported );
		$this->assertSame( wp_unslash( $expected ), $result );
		$this->assertSame(
			array(
				$failed   => 1,
				$success  => 1,
				$advanced => 1,
			),
			$this->downloads
		);
		$this->assertFileDoesNotExist( $this->upload_dir['path'] . '/failed.png' );
		$this->assertFileExists( $this->upload_dir['path'] . '/success.png' );
		$this->assertFileExists( $this->upload_dir['path'] . '/advanced.png' );
		$this->assertSame( array(), $this->warnings );
	}
}
