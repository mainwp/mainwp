<?php
/**
 * MainWP Backup Upload Error Label Tests
 *
 * Upload failures are stored as "[code] message", and the code decides the
 * friendly text users see, so each provider must get its own code and the
 * screens must show that text instead of the raw prefix.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';
require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_List_View;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Upload_Error_Labels_Test
 */
class MainWP_Backup_Upload_Error_Labels_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;
	use Backup_Read_Fixtures;

	const SFTP_TEXT    = 'Could not upload the backup to the SFTP server. Check the SFTP credentials, that the server is reachable, and that the remote folder is writable, then try again.';
	const LOCAL_TEXT   = 'Could not write to the backup folder on the Dashboard server. Check that the folder exists and is writable, then try again.';
	const GENERIC_TEXT = 'Could not upload the backup to the storage destination. Check the destination settings on the Backup Storage page, then try again.';

	/**
	 * Temp dirs created during a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	/**
	 * Private root for the upload staging the driver needs.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_private_root();
	}

	/**
	 * Remove temp fixtures and staging.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->temp_dirs as $dir ) {
			foreach ( glob( $dir . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $dir );
		}
		$this->temp_dirs = array();
		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * Provider slugs and the code their upload failures carry.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_codes() {
		return array(
			's3'      => array( 's3', 'mainwp_backup_s3_failed' ),
			'gcs'     => array( 'gcs', 'mainwp_backup_gcs_failed' ),
			'dropbox' => array( 'dropbox', 'mainwp_backup_dropbox_failed' ),
			'ftp'     => array( 'ftp', 'mainwp_backup_ftp_failed' ),
			'ftps'    => array( 'ftps', 'mainwp_backup_ftp_failed' ),
			'sftp'    => array( 'sftp', 'mainwp_backup_sftp_failed' ),
			'local'   => array( 'local', 'mainwp_backup_local_failed' ),
			'unknown' => array( 'webdav', 'mainwp_backup_destination_upload_failed' ),
			'empty'   => array( '', 'mainwp_backup_destination_upload_failed' ),
		);
	}

	/**
	 * Each provider's upload failure carries its own registered code.
	 *
	 * @dataProvider provider_codes
	 * @param string $provider Destination provider slug.
	 * @param string $expected Expected error code.
	 */
	public function test_provider_maps_to_its_own_error_code( $provider, $expected ) {
		$driver = MainWP_Backup_Upload_Driver::instance();
		$method = new \ReflectionMethod( $driver, 'error_code_for_provider' );
		$method->setAccessible( true );

		$code = $method->invoke( $driver, $provider );

		$this->assertSame( $expected, $code );
		$this->assertContains( $code, MainWP_Backup_Error_Codes::get_registered_codes() );
	}

	/**
	 * The friendly text names the storage actually in use.
	 *
	 * @return void
	 */
	public function test_sftp_local_and_unknown_codes_have_their_own_text() {
		$this->assertSame( 'mainwp_backup_sftp_failed', MainWP_Backup_Error_Codes::ERR_SFTP_FAILED );
		$this->assertSame( 'mainwp_backup_destination_upload_failed', MainWP_Backup_Error_Codes::ERR_UPLOAD_FAILED );
		$this->assertSame( self::SFTP_TEXT, MainWP_Backup_Error_Codes::message( 'mainwp_backup_sftp_failed' ) );
		$this->assertSame( self::LOCAL_TEXT, MainWP_Backup_Error_Codes::message( 'mainwp_backup_local_failed' ) );
		$this->assertSame( self::GENERIC_TEXT, MainWP_Backup_Error_Codes::message( 'mainwp_backup_destination_upload_failed' ) );
		$this->assertStringNotContainsString( 'S3', self::GENERIC_TEXT );
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( 'mainwp_backup_sftp_failed' ) );
		$this->assertSame( 'error', MainWP_Backup_Error_Codes::severity( 'mainwp_backup_destination_upload_failed' ) );
	}

	/**
	 * Stored error strings and the parts shown for them.
	 *
	 * @return array<string,array{0:mixed,1:array{code:string,message:string,detail:string}}>
	 */
	public function stored_errors() {
		$part = static function ( $code, $message, $detail ) {
			return array(
				'code'    => $code,
				'message' => $message,
				'detail'  => $detail,
			);
		};
		return array(
			's3'               => array( '[mainwp_backup_s3_failed] Access denied.', $part( 'mainwp_backup_s3_failed', 'Could not upload the backup to Amazon S3. Check the S3 credentials, bucket name, and permissions, then try again.', 'Access denied.' ) ),
			'gcs'              => array( '[mainwp_backup_gcs_failed] Bucket missing.', $part( 'mainwp_backup_gcs_failed', 'Could not upload the backup to Google Cloud Storage. Check the HMAC key, bucket name, and permissions, then try again.', 'Bucket missing.' ) ),
			'dropbox'          => array( '[mainwp_backup_dropbox_failed] insufficient_space', $part( 'mainwp_backup_dropbox_failed', 'Could not upload the backup to Dropbox. Check the Dropbox connection settings and available storage space, then try again.', 'insufficient_space' ) ),
			'ftp'              => array( '[mainwp_backup_ftp_failed] Login incorrect.', $part( 'mainwp_backup_ftp_failed', 'Could not upload the backup to the FTP server. Check the FTP credentials and that the server is reachable, then try again.', 'Login incorrect.' ) ),
			'sftp'             => array( '[mainwp_backup_sftp_failed] Permission denied', $part( 'mainwp_backup_sftp_failed', self::SFTP_TEXT, 'Permission denied' ) ),
			'local'            => array( '[mainwp_backup_local_failed] Folder is read-only.', $part( 'mainwp_backup_local_failed', self::LOCAL_TEXT, 'Folder is read-only.' ) ),
			'generic'          => array( '[mainwp_backup_destination_upload_failed] No such provider.', $part( 'mainwp_backup_destination_upload_failed', self::GENERIC_TEXT, 'No such provider.' ) ),
			'stored before'    => array( '[mainwp_backup_ftp_failed] SFTP put failed', $part( 'mainwp_backup_ftp_failed', 'Could not upload the backup to the FTP server. Check the FTP credentials and that the server is reachable, then try again.', 'SFTP put failed' ) ),
			'no prefix'        => array( 'Storage destination was deleted.', $part( '', 'Storage destination was deleted.', '' ) ),
			'prefix not first' => array( 'Retry [mainwp_backup_s3_failed] later', $part( '', 'Retry [mainwp_backup_s3_failed] later', '' ) ),
			'unknown code'     => array( '[mainwp_backup_future_failed] Quota reached.', $part( 'mainwp_backup_future_failed', 'Quota reached.', '' ) ),
			'unknown no text'  => array( '[mainwp_backup_future_failed]', $part( 'mainwp_backup_future_failed', 'The backup encountered an unexpected problem and did not complete. Check the dashboard and child site error logs for details and try again.', '' ) ),
			'code only'        => array( '[mainwp_backup_sftp_failed]', $part( 'mainwp_backup_sftp_failed', self::SFTP_TEXT, '' ) ),
			'same as text'     => array( '[mainwp_backup_local_failed] ' . self::LOCAL_TEXT, $part( 'mainwp_backup_local_failed', self::LOCAL_TEXT, '' ) ),
			'apostrophe'       => array( '[mainwp_backup_restore_job_not_found] x', $part( 'mainwp_backup_restore_job_not_found', "The restore job was not found. It may not have started yet, or your account cannot access it. Check the site's backups page for recent restores.", 'x' ) ),
			'escaped registry' => array( '[mainwp_backup_encryption_required] Backup encryption is on, but this backup could not be encrypted, so it was not uploaded. Check the encryption configuration in Settings &gt; Backup Settings, then run the backup again.', $part( 'mainwp_backup_encryption_required', 'Backup encryption is on, but this backup could not be encrypted, so it was not uploaded. Check the encryption configuration in Settings > Backup Settings, then run the backup again.', '' ) ),
			'escaped detail'   => array( '[mainwp_backup_local_failed] Backup directory &quot;x&quot; &amp; more', $part( 'mainwp_backup_local_failed', self::LOCAL_TEXT, 'Backup directory "x" & more' ) ),
			'escaped no code'  => array( 'Site &amp; disk &#039;full&#039;', $part( '', "Site & disk 'full'", '' ) ),
			'empty'            => array( '', $part( '', '', '' ) ),
			'null'             => array( null, $part( '', '', '' ) ),
			'array'            => array( array( '[mainwp_backup_s3_failed] x' ), $part( '', '', '' ) ),
		);
	}

	/**
	 * Stored errors split into friendly text, the provider detail, and the code.
	 *
	 * @dataProvider stored_errors
	 * @param mixed $stored Stored last_error value.
	 * @param array $expected Expected parts.
	 */
	public function test_stored_error_parts( $stored, $expected ) {
		$this->assertSame( $expected, MainWP_Backup_Error_Codes::stored_error_parts( $stored ) );
	}

	/**
	 * The provider detail keeps the sanitizer's path stripping.
	 *
	 * @return void
	 */
	public function test_stored_error_detail_drops_the_site_path() {
		$parts = MainWP_Backup_Error_Codes::stored_error_parts( '[mainwp_backup_local_failed] Backup directory "' . ABSPATH . 'wp-content/x" is not writable.' );
		$this->assertSame( 'Backup directory "wp-content/x" is not writable.', $parts['detail'] );
		$this->assertStringNotContainsString( ABSPATH, implode( ' ', $parts ) );
	}

	/**
	 * The snapshot list sends the display parts beside the unchanged last_error.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function snapshot_list_errors() {
		$long = str_repeat( 'é', 250 );
		return array(
			'sftp'         => array( '[mainwp_backup_sftp_failed] Permission denied', 'mainwp_backup_sftp_failed', self::SFTP_TEXT, 'Permission denied' ),
			'escaped dupe' => array( '[mainwp_backup_encryption_required] Backup encryption is on, but this backup could not be encrypted, so it was not uploaded. Check the encryption configuration in Settings &gt; Backup Settings, then run the backup again.', 'mainwp_backup_encryption_required', 'Backup encryption is on, but this backup could not be encrypted, so it was not uploaded. Check the encryption configuration in Settings > Backup Settings, then run the backup again.', '' ),
			'long no code' => array( $long, '', str_repeat( 'é', 200 ), '' ),
			'long unknown' => array( '[mainwp_backup_future_failed] ' . $long, 'mainwp_backup_future_failed', str_repeat( 'é', 200 ), '' ),
		);
	}

	/**
	 * Snapshot list AJAX carries the friendly text, detail and code for the row.
	 *
	 * @dataProvider snapshot_list_errors
	 * @param string $stored Stored last_error.
	 * @param string $code Expected error_code.
	 * @param string $message Expected error_message.
	 * @param string $detail Expected error_detail.
	 */
	public function test_snapshot_list_sends_display_parts( $stored, $code, $message, $detail ) {
		global $wpdb;
		$this->seed_read_fixtures();
		$this->assertSame( 1, $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'last_error' => $stored ), array( 'id' => 987675 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$_POST    = array(
			'site_id'  => $this->site_id,
			'security' => wp_create_nonce( 'mainwp_restore_snapshot_list' ),
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'labels-json-end' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_restore_snapshot_list();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'labels-json-end', $e->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die );
			$this->clear_read_fixtures();
			$_POST    = array();
			$_REQUEST = array();
		}

		$upload = json_decode( $raw, true )['snapshots'][0]['uploads'][0] ?? null;
		$this->assertIsArray( $upload, $raw );
		$this->assertSame( mb_substr( $stored, 0, 200 ), $upload['last_error'] );
		$this->assertSame( $code, $upload['error_code'] );
		$this->assertSame( $message, $upload['error_message'] );
		$this->assertSame( $detail, $upload['error_detail'] );
	}

	/**
	 * A real Local upload failure is stored with the Local code and reads as Local text.
	 *
	 * @return void
	 */
	public function test_refused_local_upload_stores_local_code_and_reads_as_local_text() {
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Refused Local destination',
				'settings' => array( 'base_path' => WP_CONTENT_DIR . '/mainwp-refused-upload-' . uniqid() ),
			)
		);
		$this->assertGreaterThan( 0, $dest_id );

		$src = get_temp_dir() . 'mainwp-upload-labels-' . uniqid();
		mkdir( $src, 0755, true );
		$this->temp_dirs[] = $src;
		file_put_contents( $src . '/db.sql', str_repeat( 'x', 100 ) );
		$snapshot_id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => 4,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => array(
					array(
						'file' => 'db.sql',
						'size' => 100,
						'role' => 'db',
						'path' => $src . '/db.sql',
					),
				),
				'manifest_path' => '',
			)
		);
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( $snapshot_id ) );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertStringStartsWith( '[mainwp_backup_local_failed] ', $job->last_error );
		$parts = MainWP_Backup_Error_Codes::stored_error_parts( $job->last_error );
		$this->assertSame( 'mainwp_backup_local_failed', $parts['code'] );
		$this->assertSame( self::LOCAL_TEXT, $parts['message'] );
		$this->assertStringContainsString( 'Backup directory "', $parts['detail'] );
		$this->assertStringNotContainsString( 'S3', $parts['message'] . $parts['detail'] );
	}

	/**
	 * The task list tooltip leads with the friendly text and keeps the code last.
	 *
	 * @return void
	 */
	public function test_task_list_tooltip_shows_friendly_text_without_raw_prefix() {
		$method = new \ReflectionMethod( MainWP_Backup_List_View::class, 'upload_failed_label' );
		$method->setAccessible( true );
		$label = $method->invoke(
			new MainWP_Backup_List_View(),
			'Upload failed',
			array(
				(object) array(
					'status'     => 'failed_permanent',
					'last_error' => '[mainwp_backup_sftp_failed] Permission denied "x" <b>now</b>',
				),
			),
			'failed_permanent'
		);

		$this->assertSame( 1, preg_match( '/data-tooltip="([^"]*)"/', $label, $match ) );
		$this->assertSame( esc_attr( self::SFTP_TEXT . ' Permission denied "x" now (mainwp_backup_sftp_failed)' ), $match[1] );
		$this->assertStringNotContainsString( '[mainwp_backup_', $label );
		$this->assertStringNotContainsString( '<b>', $label );
	}

	/**
	 * A tooltip for an error stored without a code is unchanged.
	 *
	 * @return void
	 */
	public function test_task_list_tooltip_keeps_plain_errors() {
		$method = new \ReflectionMethod( MainWP_Backup_List_View::class, 'upload_failed_label' );
		$method->setAccessible( true );
		$label = $method->invoke(
			new MainWP_Backup_List_View(),
			'Upload failed',
			array(
				(object) array(
					'status'     => 'failed_permanent',
					'last_error' => "Storage destination\n was deleted.",
				),
			),
			'failed_permanent'
		);

		$this->assertStringContainsString( 'data-tooltip="Storage destination was deleted."', $label );
	}
}
