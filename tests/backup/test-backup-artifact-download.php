<?php
/**
 * MainWP Backup Artifact Download Tests
 *
 * Tests for the token-authorized artifact download: capability gating,
 * one-time token request and refresh, sha256 integrity verification, and
 * the token-less legacy path for older child sites.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class MainWP_Backup_Artifact_Download_Test
 */
class MainWP_Backup_Artifact_Download_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Local files created during a test.
	 *
	 * @var string[]
	 */
	private $local_files = array();

	/**
	 * Clean up local files.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->local_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->local_files = array();
		parent::tearDown();
	}

	/**
	 * Build a local download target path.
	 *
	 * @return string Path (file does not exist yet).
	 */
	private function local_file() {
		$upload = wp_upload_dir();
		$path   = $upload['basedir'] . '/mainwp-test-artifact-' . wp_rand( 1000, 999999 ) . '.bin';
		$this->local_files[] = $path;
		return $path;
	}

	/**
	 * Create a site, optionally advertising download token support.
	 *
	 * @param bool $supports_token Whether the child supports tokens.
	 *
	 * @return object Website.
	 */
	private function make_site( $supports_token ) {
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		if ( $supports_token ) {
			MainWP_DB::instance()->update_website_option( $website, 'download_token_support', '1' );
		}
		return MainWP_DB::instance()->get_website_by_id( $site_id );
	}

	/**
	 * Give a test site a real RSA private key so download URL signing works.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	private function give_site_real_privkey( $site_id ) {
		global $wpdb;
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $privkey_pem );
		// The connect layer base64-decodes the stored key before signing.
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'privkey' => base64_encode( $privkey_pem ) ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions -- test fixture.
	}

	/**
	 * Mock the backup_download_token callable.
	 *
	 * @param mixed $responses Single response array or list of per-call responses.
	 * @param array $calls     By-ref call log: each entry is the request params.
	 *
	 * @return void
	 */
	private function mock_token_callable( $responses, &$calls ) {
		if ( isset( $responses['token'] ) || isset( $responses['error'] ) ) {
			$responses = array( $responses );
		}
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $responses, &$calls ) {
				unset( $website );
				if ( 'backup_download_token' !== $what ) {
					return $result;
				}
				$index   = count( $calls );
				$calls[] = $params;
				return isset( $responses[ $index ] ) ? $responses[ $index ] : end( $responses );
			},
			10,
			4
		);
	}

	/**
	 * Mock download_to_file with per-call outcomes.
	 *
	 * @param array $outcomes Per-call: a string writes that content to the
	 *                        target file, a MainWP_Exception is thrown by
	 *                        the transfer layer. The last outcome repeats.
	 * @param array $urls     By-ref list of requested URLs.
	 *
	 * @return void
	 */
	private function mock_download( $outcomes, &$urls ) {
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $outcomes, &$urls ) {
				unset( $pre );
				$index   = count( $urls );
				$urls[]  = $url;
				$outcome = isset( $outcomes[ $index ] ) ? $outcomes[ $index ] : end( $outcomes );
				if ( $outcome instanceof MainWP_Exception ) {
					return $outcome;
				}
				file_put_contents( $file, $outcome ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture write.
				return true;
			},
			10,
			3
		);
	}

	/**
	 * A child without the capability downloads over the token-less path:
	 * no token callable, no dltoken param, no hash verification, and the
	 * download action reports hash_verified false.
	 *
	 * @return void
	 */
	public function test_legacy_child_downloads_without_token() {
		$website = $this->make_site( false );
		$local   = $this->local_file();
		$calls   = array();
		$urls    = array();
		$this->mock_token_callable( array( 'error' => 'must never be called' ), $calls );
		$this->mock_download( array( 'legacy-bytes-with-no-hash-check' ), $urls );

		$events = array();
		add_action(
			'mainwp_backup_downloaded',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		MainWP_Backup_File_Manager::download_backup_artifact( $website, 'backup-legacy.zip', $local, false, null, null );

		$this->assertCount( 0, $calls, 'The token callable must not be invoked for a legacy child.' );
		$this->assertCount( 1, $urls );
		$this->assertStringNotContainsString( 'dltoken=', $urls[0] );
		$this->assertStringContainsString( 'fdl=', $urls[0] );
		$this->assertCount( 1, $events );
		$this->assertFalse( $events[0]['hash_verified'] );
		$this->assertSame( (int) $website->id, $events[0]['site_id'] );
	}

	/**
	 * A token-capable child gets a token request, the download URL carries
	 * the token, and a matching hash fires the action as verified.
	 *
	 * @return void
	 */
	public function test_token_download_verifies_hash_and_fires_action() {
		$website = $this->make_site( true );
		$local   = $this->local_file();
		$content = 'verified-artifact-bytes';
		$calls   = array();
		$urls    = array();
		$this->mock_token_callable(
			array(
				'token'      => str_repeat( 'a', 32 ),
				'hash'       => hash( 'sha256', $content ),
				'size'       => strlen( $content ),
				'expires_in' => HOUR_IN_SECONDS,
			),
			$calls
		);
		$this->mock_download( array( $content ), $urls );

		$events = array();
		add_action(
			'mainwp_backup_downloaded',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		MainWP_Backup_File_Manager::download_backup_artifact( $website, 'backup-token.zip', $local, false, null, null );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'backup-token.zip', $calls[0]['file'] );
		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( 'dltoken=' . str_repeat( 'a', 32 ), $urls[0] );
		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]['hash_verified'] );
	}

	/**
	 * A hash mismatch removes the local file and throws the coded exception.
	 *
	 * @return void
	 */
	public function test_hash_mismatch_deletes_local_file_and_throws() {
		$website = $this->make_site( true );
		$local   = $this->local_file();
		$calls   = array();
		$urls    = array();
		$this->mock_token_callable(
			array(
				'token' => str_repeat( 'b', 32 ),
				'hash'  => hash( 'sha256', 'what-the-child-hashed' ),
				'size'  => 100,
			),
			$calls
		);
		$this->mock_download( array( 'tampered-bytes' ), $urls );

		try {
			MainWP_Backup_File_Manager::download_backup_artifact( $website, 'backup-tampered.zip', $local, false, null, null );
			$this->fail( 'Expected a hash mismatch exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_HASH_MISMATCH, $e->get_message_error_code() );
		}
		$this->assertFileDoesNotExist( $local );
	}

	/**
	 * A token-issue failure on a capability-advertising child throws and
	 * never falls back to a token-less download.
	 *
	 * @return void
	 */
	public function test_token_issue_failure_throws_without_fallback() {
		$website = $this->make_site( true );
		$local   = $this->local_file();
		$calls   = array();
		$urls    = array();
		$this->mock_token_callable(
			array(
				'error'      => 'The requested backup file was not found on the child site.',
				'error_code' => 'mainwp_backup_dl_file_not_found',
			),
			$calls
		);
		$this->mock_download( array( 'never-downloaded' ), $urls );

		try {
			MainWP_Backup_File_Manager::download_backup_artifact( $website, 'backup-missing.zip', $local, false, null, null );
			$this->fail( 'Expected the child error to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_dl_file_not_found', $e->get_message_error_code() );
		}
		$this->assertCount( 0, $urls, 'A failed token request must not fall back to a token-less download.' );
	}

	/**
	 * A token response missing the token or hash is rejected.
	 *
	 * @return void
	 */
	public function test_invalid_token_response_throws() {
		$website = $this->make_site( true );
		$local   = $this->local_file();
		$calls   = array();
		$urls    = array();
		$this->mock_token_callable( array( 'token' => '', 'hash' => '' ), $calls );
		$this->mock_download( array( 'never-downloaded' ), $urls );

		$this->expectException( MainWP_Exception::class );
		MainWP_Backup_File_Manager::download_backup_artifact( $website, 'backup-bad-response.zip', $local, false, null, null );
	}

	/**
	 * A 403 from the child (consumed token) triggers a fresh token request
	 * and the retried download succeeds.
	 *
	 * @return void
	 */
	public function test_token_rejection_triggers_fresh_token_retry() {
		$website = $this->make_site( true );
		$local   = $this->local_file();
		$content = 'retried-artifact-bytes';
		$calls   = array();
		$urls    = array();
		$this->mock_token_callable(
			array(
				array(
					'token' => str_repeat( 'c', 32 ),
					'hash'  => hash( 'sha256', $content ),
					'size'  => strlen( $content ),
				),
				array(
					'token' => str_repeat( 'd', 32 ),
					'hash'  => hash( 'sha256', $content ),
					'size'  => strlen( $content ),
				),
			),
			$calls
		);
		$this->mock_download(
			array(
				new MainWP_Exception( 'unexpected response', 'HTTP 403', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR ),
				$content,
			),
			$urls
		);

		MainWP_Backup_File_Manager::download_backup_artifact( $website, 'backup-retry.zip', $local, false, null, null );

		$this->assertCount( 2, $calls, 'A rejected token must trigger one fresh token request.' );
		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'dltoken=' . str_repeat( 'c', 32 ), $urls[0] );
		$this->assertStringContainsString( 'dltoken=' . str_repeat( 'd', 32 ), $urls[1] );
	}

	/**
	 * Persistent token rejection stops after the retry bound and the last
	 * exception propagates.
	 *
	 * @return void
	 */
	public function test_persistent_token_rejection_exhausts_retries() {
		$website = $this->make_site( true );
		$local   = $this->local_file();
		$calls   = array();
		$urls    = array();
		$this->mock_token_callable(
			array(
				'token' => str_repeat( 'e', 32 ),
				'hash'  => hash( 'sha256', 'never-arrives' ),
				'size'  => 13,
			),
			$calls
		);
		$this->mock_download(
			array( new MainWP_Exception( 'unexpected response', 'HTTP 401', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR ) ),
			$urls
		);

		try {
			MainWP_Backup_File_Manager::download_backup_artifact( $website, 'backup-always-401.zip', $local, false, null, null );
			$this->fail( 'Expected the exhausted retry to propagate the exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_HTTP_ERROR, $e->get_message_error_code() );
		}
		$this->assertCount( 3, $calls, 'Initial token plus two refreshes.' );
		$this->assertCount( 3, $urls, 'Three download attempts in total.' );
	}

	/**
	 * backup_download_file() routes through the token flow for a capable
	 * child.
	 *
	 * @return void
	 */
	public function test_backup_download_file_uses_token_flow() {
		$website = $this->make_site( true );
		$upload  = wp_upload_dir();
		$local   = $upload['basedir'] . '/mainwp-test-dl-' . wp_rand( 1000, 999999 ) . '/backup-file.tar.gz';
		$this->local_files[] = $local;
		$content             = "\x1f\x8b\x08" . str_repeat( 'z', 64 );
		$calls               = array();
		$urls                = array();
		$this->mock_token_callable(
			array(
				'token' => str_repeat( 'h', 32 ),
				'hash'  => hash( 'sha256', $content ),
				'size'  => strlen( $content ),
			),
			$calls
		);
		$this->mock_download( array( $content ), $urls );

		MainWP_Backup_File_Manager::backup_download_file( (int) $website->id, 'full', 'backup-remote.tar.gz', $local );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'backup-remote.tar.gz', $calls[0]['file'] );
		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( 'dltoken=' . str_repeat( 'h', 32 ), $urls[0] );
	}

	/**
	 * A non-token transfer failure propagates immediately without a token
	 * refresh.
	 *
	 * @return void
	 */
	public function test_non_token_failure_propagates_without_refresh() {
		$website = $this->make_site( true );
		$local   = $this->local_file();
		$calls   = array();
		$urls    = array();
		$this->mock_token_callable(
			array(
				'token' => str_repeat( 'f', 32 ),
				'hash'  => hash( 'sha256', 'irrelevant' ),
				'size'  => 10,
			),
			$calls
		);
		$this->mock_download(
			array( new MainWP_Exception( 'unexpected response', 'HTTP 500', MainWP_Backup_Error_Codes::ERR_HTTP_ERROR ) ),
			$urls
		);

		try {
			MainWP_Backup_File_Manager::download_backup_artifact( $website, 'backup-500.zip', $local, false, null, null );
			$this->fail( 'Expected the transfer failure to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_HTTP_ERROR, $e->get_message_error_code() );
		}
		$this->assertCount( 1, $calls, 'A non-token failure must not request a fresh token.' );
		$this->assertCount( 1, $urls );
	}
}
