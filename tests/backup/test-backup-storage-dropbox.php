<?php
/**
 * MainWP Backup Storage Dropbox Provider Tests
 *
 * Verifies the Dropbox provider through an injected transport: the
 * refresh-token flow mints short-lived access tokens (cached, re-minted
 * once on a 401), uploads and downloads go through the content endpoint
 * with the path carried in the Dropbox-API-Arg header, metadata and
 * delete calls disambiguate Dropbox's catch-all 409 responses by the
 * error tag (missing paths are absence, everything else throws),
 * listing maps the list_folder cursor onto the provider pagination
 * contract, and the connection test proves write access with a probe
 * object.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_Dropbox;
use MainWP\Dashboard\MainWP_Backup_Storage_Provider;

/**
 * Class MainWP_Backup_Storage_Dropbox_Test
 */
class MainWP_Backup_Storage_Dropbox_Test extends \WP_UnitTestCase {

	/**
	 * Requests captured by the injected transport.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Reset captured requests.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->requests = array();
	}

	/**
	 * Default provider settings. The refresh token is unique per test so
	 * the access-token cache never leaks across tests.
	 *
	 * @param array $overrides Setting overrides.
	 *
	 * @return array
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'app_key'       => 'app-key-example',
				'app_secret'    => 'app-secret-example',
				'refresh_token' => 'refresh-' . uniqid(),
				'base_path'     => 'mainwp-backups',
			),
			$overrides
		);
	}

	/**
	 * A WP-HTTP-shaped response.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $body    Response body.
	 * @param array  $headers Response headers.
	 *
	 * @return array
	 */
	private function response( $code, $body = '', array $headers = array() ) {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	/**
	 * A successful token mint response.
	 *
	 * @param string $token Access token value.
	 *
	 * @return array
	 */
	private function token_response( $token = 'access-token-1' ) {
		return $this->response(
			200,
			wp_json_encode(
				array(
					'access_token' => $token,
					'expires_in'   => 14400,
				)
			)
		);
	}

	/**
	 * Build a provider whose transport replays scripted responses and
	 * records every request.
	 *
	 * @param array $responses Responses returned in order (or a single one reused).
	 * @param array $overrides Setting overrides.
	 *
	 * @return MainWP_Backup_Storage_Dropbox
	 */
	private function make_provider( array $responses, array $overrides = array() ) {
		$queue     = $responses;
		$transport = function ( $url, $args ) use ( &$queue ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			return count( $queue ) > 1 ? array_shift( $queue ) : $queue[0];
		};

		return new MainWP_Backup_Storage_Dropbox( $this->settings( $overrides ), $transport );
	}

	/**
	 * The provider satisfies the storage provider interface.
	 *
	 * @return void
	 */
	public function test_implements_storage_provider_interface() {
		$provider = $this->make_provider( array( $this->token_response() ) );
		$this->assertInstanceOf( MainWP_Backup_Storage_Provider::class, $provider );
	}

	/**
	 * The first call mints an access token from the refresh token, then
	 * uploads through the content endpoint with the path in the API-arg
	 * header and the raw bytes as the body.
	 *
	 * @return void
	 */
	public function test_put_object_mints_token_then_uploads() {
		$provider = $this->make_provider(
			array(
				$this->token_response( 'minted-token' ),
				$this->response( 200, '{}' ),
			)
		);
		$body     = 'chunk-bytes';

		$this->assertTrue( $provider->put_object( '4/42/chunks/abc.bin', $body ) );
		$this->assertCount( 2, $this->requests );

		$mint = $this->requests[0];
		$this->assertSame( 'https://api.dropboxapi.com/oauth2/token', $mint['url'] );
		$this->assertSame( 'refresh_token', $mint['args']['body']['grant_type'] );
		$this->assertSame( 'app-key-example', $mint['args']['body']['client_id'] );
		$this->assertSame( 'app-secret-example', $mint['args']['body']['client_secret'] );

		$upload = $this->requests[1];
		$this->assertSame( 'https://content.dropboxapi.com/2/files/upload', $upload['url'] );
		$this->assertSame( 'Bearer minted-token', $upload['args']['headers']['Authorization'] );
		$this->assertSame( $body, $upload['args']['body'] );
		$this->assertSame( 'application/octet-stream', $upload['args']['headers']['Content-Type'] );

		$api_arg = json_decode( $upload['args']['headers']['Dropbox-API-Arg'], true );
		$this->assertSame( '/mainwp-backups/4/42/chunks/abc.bin', $api_arg['path'] );
		$this->assertSame( 'overwrite', $api_arg['mode'] );
	}

	/**
	 * The minted token is cached: a second operation reuses it without a
	 * second mint.
	 *
	 * @return void
	 */
	public function test_access_token_is_cached_between_calls() {
		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 200, '{}' ),
				$this->response( 200, '{}' ),
			)
		);

		$provider->put_object( 'a.bin', 'x' );
		$provider->put_object( 'b.bin', 'y' );

		$mints = 0;
		foreach ( $this->requests as $request ) {
			if ( 'https://api.dropboxapi.com/oauth2/token' === $request['url'] ) {
				++$mints;
			}
		}
		$this->assertSame( 1, $mints, 'The access token must be minted once and cached.' );
	}

	/**
	 * A 401 from the API re-mints the token once and retries the call.
	 *
	 * @return void
	 */
	public function test_expired_token_is_reminted_once_on_401() {
		$provider = $this->make_provider(
			array(
				$this->token_response( 'stale-token' ),
				$this->response( 401, '{"error_summary": "expired_access_token/"}' ),
				$this->token_response( 'fresh-token' ),
				$this->response( 200, '{}' ),
			)
		);

		$this->assertTrue( $provider->put_object( 'a.bin', 'x' ) );
		$this->assertCount( 4, $this->requests );
		$this->assertSame( 'Bearer fresh-token', $this->requests[3]['args']['headers']['Authorization'] );
	}

	/**
	 * A failed token mint throws with the HTTP status so the upload
	 * driver can park the destination as credentials-invalid.
	 *
	 * @return void
	 */
	public function test_failed_token_mint_throws_with_status() {
		$provider = $this->make_provider(
			array( $this->response( 401, '{"error": "invalid_grant"}' ) )
		);

		try {
			$provider->put_object( 'a.bin', 'x' );
			$this->fail( 'A failed mint must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 401, $e->getCode() );
		}
	}

	/**
	 * HEAD maps get_metadata: present returns the size, a not-found 409
	 * is absence, any other 409 throws.
	 *
	 * @return void
	 */
	public function test_head_object_disambiguates_409_responses() {
		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 200, wp_json_encode( array( '.tag' => 'file', 'size' => 4194304 ) ) ),
			)
		);
		$this->assertSame( array( 'size' => 4194304 ), $provider->head_object( 'k.bin' ) );
		$this->assertSame( 'https://api.dropboxapi.com/2/files/get_metadata', $this->requests[1]['url'] );

		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 409, '{"error_summary": "path/not_found/..", "error": {".tag": "path", "path": {".tag": "not_found"}}}' ),
			)
		);
		$this->assertFalse( $provider->head_object( 'k.bin' ) );

		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 409, '{"error_summary": "path/restricted_content/..", "error": {".tag": "path", "path": {".tag": "restricted_content"}}}' ),
			)
		);
		try {
			$provider->head_object( 'k.bin' );
			$this->fail( 'A non-missing 409 must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 409, $e->getCode() );
		}
	}

	/**
	 * Downloads stream to a file through the content endpoint.
	 *
	 * @return void
	 */
	public function test_get_object_streams_to_file() {
		$target = get_temp_dir() . 'mainwp-dropbox-download-' . uniqid() . '.bin';

		$queue     = array( $this->token_response() );
		$transport = function ( $url, $args ) use ( &$queue ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			if ( ! empty( $queue ) ) {
				return array_shift( $queue );
			}
			file_put_contents( $args['filename'], 'streamed-bytes' );
			return $this->response( 200 );
		};
		$provider  = new MainWP_Backup_Storage_Dropbox( $this->settings(), $transport );

		try {
			$this->assertTrue( $provider->get_object( 'k.bin', $target ) );
			$download = $this->requests[1];
			$this->assertSame( 'https://content.dropboxapi.com/2/files/download', $download['url'] );
			$this->assertTrue( $download['args']['stream'] );
			$this->assertSame( $target, $download['args']['filename'] );
			$this->assertSame( '/mainwp-backups/k.bin', json_decode( $download['args']['headers']['Dropbox-API-Arg'], true )['path'] );
			$this->assertSame( 'streamed-bytes', file_get_contents( $target ) );
		} finally {
			if ( file_exists( $target ) ) {
				unlink( $target );
			}
		}
	}

	/**
	 * Deletes succeed on 200 and treat a missing path as already
	 * deleted; other failures throw.
	 *
	 * @return void
	 */
	public function test_delete_object_is_idempotent() {
		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 200, '{}' ),
			)
		);
		$this->assertTrue( $provider->delete_object( 'k.bin' ) );
		$this->assertSame( 'https://api.dropboxapi.com/2/files/delete_v2', $this->requests[1]['url'] );

		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 409, '{"error_summary": "path_lookup/not_found/..", "error": {".tag": "path_lookup", "path_lookup": {".tag": "not_found"}}}' ),
			)
		);
		$this->assertTrue( $provider->delete_object( 'k.bin' ) );

		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 500, '{}' ),
			)
		);
		try {
			$provider->delete_object( 'k.bin' );
			$this->fail( 'A 500 must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 500, $e->getCode() );
		}
	}

	/**
	 * Listing walks list_folder and its continue cursor, returns keys
	 * relative to the base path, skips folder entries, and maps has_more
	 * onto the pagination contract.
	 *
	 * @return void
	 */
	public function test_list_objects_paginates_with_cursor() {
		$page1 = wp_json_encode(
			array(
				'entries'  => array(
					array(
						'.tag'         => 'file',
						'path_display' => '/mainwp-backups/4/42/chunks/aa.bin',
						'size'         => 100,
					),
					array(
						'.tag'         => 'folder',
						'path_display' => '/mainwp-backups/4/42/chunks',
					),
				),
				'cursor'   => 'cursor-2',
				'has_more' => true,
			)
		);
		$page2 = wp_json_encode(
			array(
				'entries'  => array(
					array(
						'.tag'         => 'file',
						'path_display' => '/mainwp-backups/4/42/manifest.json',
						'size'         => 5,
					),
				),
				'cursor'   => 'cursor-3',
				'has_more' => false,
			)
		);

		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 200, $page1 ),
				$this->response( 200, $page2 ),
			)
		);

		$first = $provider->list_objects( '4/42/' );
		$this->assertTrue( $first['truncated'] );
		$this->assertSame( 'cursor-2', $first['next_token'] );
		$this->assertSame(
			array(
				array(
					'key'  => '4/42/chunks/aa.bin',
					'size' => 100,
				),
			),
			$first['objects']
		);
		$list_args = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( '/mainwp-backups/4/42', $list_args['path'] );
		$this->assertTrue( $list_args['recursive'] );

		$second = $provider->list_objects( '4/42/', $first['next_token'] );
		$this->assertFalse( $second['truncated'] );
		$this->assertNull( $second['next_token'] );
		$this->assertSame( 'https://api.dropboxapi.com/2/files/list_folder/continue', $this->requests[2]['url'] );
		$this->assertSame( 'cursor-2', json_decode( $this->requests[2]['args']['body'], true )['cursor'] );
	}

	/**
	 * Listing a folder that does not exist yet is an empty result, not an
	 * error, so cloud cleanup stays idempotent.
	 *
	 * @return void
	 */
	public function test_list_objects_missing_folder_is_empty() {
		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 409, '{"error_summary": "path/not_found/..", "error": {".tag": "path", "path": {".tag": "not_found"}}}' ),
			)
		);

		$result = $provider->list_objects( '4/42/' );

		$this->assertSame( array(), $result['objects'] );
		$this->assertNull( $result['next_token'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * The connection test mints a token and proves write access with a
	 * probe object: upload, metadata check, delete.
	 *
	 * @return void
	 */
	public function test_connection_test_round_trip() {
		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 200, '{}' ),
				$this->response( 200, wp_json_encode( array( '.tag' => 'file', 'size' => 22 ) ) ),
				$this->response( 200, '{}' ),
			)
		);

		$result = $provider->connection_test();

		$this->assertTrue( $result['ok'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( 'https://content.dropboxapi.com/2/files/upload', $this->requests[1]['url'] );
		$this->assertSame( 'https://api.dropboxapi.com/2/files/get_metadata', $this->requests[2]['url'] );
		$this->assertSame( 'https://api.dropboxapi.com/2/files/delete_v2', $this->requests[3]['url'] );
	}

	/**
	 * A failing step surfaces in the connection test result instead of
	 * throwing at the caller.
	 *
	 * @return void
	 */
	public function test_connection_test_reports_failure() {
		$provider = $this->make_provider(
			array( $this->response( 400, '{"error": "invalid_grant"}' ) )
		);

		$result = $provider->connection_test();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '400', $result['error'] );
	}

	/**
	 * The authorization-code exchange posts the code with the app
	 * credentials and the redirect URI, returning the refresh token.
	 *
	 * @return void
	 */
	public function test_exchange_authorization_code_returns_refresh_token() {
		$requests  = array();
		$transport = function ( $url, $args ) use ( &$requests ) {
			$requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			return $this->response(
				200,
				wp_json_encode(
					array(
						'access_token'  => 'short-lived',
						'refresh_token' => 'long-lived-refresh',
						'expires_in'    => 14400,
					)
				)
			);
		};

		$result = MainWP_Backup_Storage_Dropbox::exchange_authorization_code( 'app-key', 'app-secret', 'auth-code-1', 'https://dash.example/wp-admin/admin.php?page=ManageBackupsStorage&mainwp_dropbox_oauth=1', $transport );

		$this->assertSame( 'long-lived-refresh', $result['refresh_token'] );
		$this->assertArrayNotHasKey( 'error', $result );

		$this->assertSame( 'https://api.dropboxapi.com/oauth2/token', $requests[0]['url'] );
		$body = $requests[0]['args']['body'];
		$this->assertSame( 'authorization_code', $body['grant_type'] );
		$this->assertSame( 'auth-code-1', $body['code'] );
		$this->assertSame( 'app-key', $body['client_id'] );
		$this->assertSame( 'app-secret', $body['client_secret'] );
		$this->assertStringContainsString( 'mainwp_dropbox_oauth=1', $body['redirect_uri'] );
	}

	/**
	 * A rejected exchange (bad/expired code) reports an error without a
	 * token and without echoing the response body.
	 *
	 * @return void
	 */
	public function test_exchange_authorization_code_reports_failure() {
		$transport = function () {
			return $this->response( 400, '{"error": "invalid_grant", "error_description": "code has expired"}' );
		};

		$result = MainWP_Backup_Storage_Dropbox::exchange_authorization_code( 'k', 's', 'expired-code', 'https://dash.example/cb', $transport );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( '400', $result['error'] );
		$this->assertArrayNotHasKey( 'refresh_token', $result );

		$no_token = MainWP_Backup_Storage_Dropbox::exchange_authorization_code(
			'k',
			's',
			'c',
			'https://dash.example/cb',
			function () {
				return $this->response( 200, '{"access_token": "only-short-lived"}' );
			}
		);
		$this->assertArrayHasKey( 'error', $no_token, 'A grant without offline access returns no refresh token and must error.' );

		$transport_error = MainWP_Backup_Storage_Dropbox::exchange_authorization_code(
			'k',
			's',
			'c',
			'https://dash.example/cb',
			function () {
				return new \WP_Error( 'http_request_failed', 'timed out' );
			}
		);
		$this->assertArrayHasKey( 'error', $transport_error );
	}

	/**
	 * An empty base path maps keys straight to the Dropbox root.
	 *
	 * @return void
	 */
	public function test_empty_base_path_maps_to_root() {
		$provider = $this->make_provider(
			array(
				$this->token_response(),
				$this->response( 200, '{}' ),
			),
			array( 'base_path' => '' )
		);

		$provider->put_object( 'k.bin', 'x' );

		$this->assertSame( '/k.bin', json_decode( $this->requests[1]['args']['headers']['Dropbox-API-Arg'], true )['path'] );
	}

	/**
	 * A request whose API host resolves to a link-local / cloud-metadata
	 * address is blocked inside send() before the transport runs: this is
	 * the DNS-rebind re-check on the wire path, the same wiring guarantee
	 * the S3 and SFTP providers already prove in their tests. The subclass
	 * only redirects the hardcoded API hosts; the send() path is the real one.
	 *
	 * @return void
	 */
	public function test_send_blocked_when_api_host_is_link_local() {
		$queue     = array( $this->token_response() );
		$transport = function ( $url, $args ) use ( &$queue ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			return count( $queue ) > 1 ? array_shift( $queue ) : $queue[0];
		};

		$provider = new Link_Local_Host_Dropbox( $this->settings(), $transport );

		try {
			$provider->put_object( 'k.bin', 'x' );
			$this->fail( 'A link-local API host must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'link-local or cloud-metadata', $e->getMessage() );
		}

		$this->assertCount( 0, $this->requests, 'The transport must not run for a blocked host.' );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double for the wiring test above.
/**
 * Dropbox provider variant whose hardcoded API hosts point at a link-local
 * address, so the SSRF guard wiring inside send() can be exercised without
 * DNS. Only the host constants change; every request still flows through
 * the real send() path.
 */
class Link_Local_Host_Dropbox extends MainWP_Backup_Storage_Dropbox {
	const API_BASE     = 'http://169.254.169.254';
	const CONTENT_BASE = 'http://169.254.169.254';
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
