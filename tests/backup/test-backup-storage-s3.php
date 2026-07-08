<?php
/**
 * MainWP Backup Storage S3 Provider Tests
 *
 * Verifies the S3-compatible provider through an injected transport that
 * captures every request: URL construction for virtual-host, path-style,
 * and custom endpoints with ports, SigV4 headers on every call, HEAD
 * based existence checks with size verification, idempotent deletes,
 * paginated prefix listing, the connection test sequence, and streamed
 * downloads. Auth failures throw with the HTTP status so the upload
 * driver can park the destination as credentials_invalid.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_S3;

/**
 * Class MainWP_Backup_Storage_S3_Test
 */
class MainWP_Backup_Storage_S3_Test extends \WP_UnitTestCase {

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
	 * Default provider settings.
	 *
	 * @param array $overrides Setting overrides.
	 *
	 * @return array
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'bucket'     => 'example-bucket',
				'region'     => 'us-east-1',
				'access_key' => 'AKIAIOSFODNN7EXAMPLE',
				'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
			),
			$overrides
		);
	}

	/**
	 * Build a provider whose transport replays scripted responses and
	 * records every request.
	 *
	 * @param array $responses Responses returned in order (or a single one reused).
	 * @param array $overrides Setting overrides.
	 *
	 * @return MainWP_Backup_Storage_S3
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

		return new MainWP_Backup_Storage_S3( $this->settings( $overrides ), $transport );
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
	 * PUT signs the request and addresses the bucket virtual-host style by
	 * default.
	 *
	 * @return void
	 */
	public function test_put_object_signs_and_uses_virtual_host_url() {
		$provider = $this->make_provider( array( $this->response( 200 ) ) );
		$body     = 'chunk-bytes';

		$this->assertTrue( $provider->put_object( '4/42/chunks/abc.bin', $body ) );

		$request = $this->requests[0];
		$this->assertSame( 'https://example-bucket.s3.amazonaws.com/4/42/chunks/abc.bin', $request['url'] );
		$this->assertSame( 'PUT', $request['args']['method'] );
		$this->assertSame( $body, $request['args']['body'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/', $request['args']['headers']['Authorization'] );
		$this->assertSame( hash( 'sha256', $body ), $request['args']['headers']['x-amz-content-sha256'] );
	}

	/**
	 * With a per-destination sse setting, PUT carries the server-side
	 * encryption header and it joins the signed set.
	 *
	 * @return void
	 */
	public function test_put_object_adds_sse_header_when_configured() {
		$provider = $this->make_provider( array( $this->response( 200 ) ), array( 'sse' => 'AES256' ) );

		$provider->put_object( 'k.bin', 'x' );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'AES256', $headers['x-amz-server-side-encryption'] );
		$this->assertStringContainsString( 'x-amz-server-side-encryption', $headers['Authorization'], 'The SSE header must be signed.' );
	}

	/**
	 * Without the sse setting, PUT carries no server-side encryption header,
	 * so endpoints that reject it (MinIO default, GCS over S3) are unaffected.
	 *
	 * @return void
	 */
	public function test_put_object_omits_sse_header_by_default() {
		$provider = $this->make_provider( array( $this->response( 200 ) ) );

		$provider->put_object( 'k.bin', 'x' );

		$this->assertArrayNotHasKey( 'x-amz-server-side-encryption', $this->requests[0]['args']['headers'] );
	}

	/**
	 * The aws:kms value rides the same signed header, so SSE-KMS works
	 * without a custom key id; the account default KMS key is used.
	 *
	 * @return void
	 */
	public function test_put_object_adds_kms_sse_header_when_configured() {
		$provider = $this->make_provider( array( $this->response( 200 ) ), array( 'sse' => 'aws:kms' ) );

		$provider->put_object( 'k.bin', 'x' );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'aws:kms', $headers['x-amz-server-side-encryption'] );
		$this->assertStringContainsString( 'x-amz-server-side-encryption', $headers['Authorization'], 'The SSE header must be signed.' );
	}

	/**
	 * Path-style addressing puts the bucket in the path; custom endpoints
	 * with ports survive intact.
	 *
	 * @return void
	 */
	public function test_path_style_custom_endpoint() {
		$provider = $this->make_provider(
			array( $this->response( 200 ) ),
			array(
				'endpoint'   => 'http://127.0.0.1:9000',
				'path_style' => 1,
			)
		);

		$provider->put_object( 'k.bin', 'x' );

		$this->assertSame( 'http://127.0.0.1:9000/example-bucket/k.bin', $this->requests[0]['url'] );
		$this->assertSame( '127.0.0.1:9000', $this->requests[0]['args']['headers']['Host'] );
	}

	/**
	 * Object keys are encoded per segment; slashes survive.
	 *
	 * @return void
	 */
	public function test_object_keys_are_segment_encoded() {
		$provider = $this->make_provider( array( $this->response( 200 ) ) );

		$provider->put_object( 'snap 1/file$x.bin', 'x' );

		$this->assertSame( 'https://example-bucket.s3.amazonaws.com/snap%201/file%24x.bin', $this->requests[0]['url'] );
	}

	/**
	 * HEAD reports presence with size, absence as false.
	 *
	 * @return void
	 */
	public function test_head_object_present_and_absent() {
		$provider = $this->make_provider( array( $this->response( 200, '', array( 'content-length' => '4194304' ) ) ) );
		$head     = $provider->head_object( 'k.bin' );
		$this->assertSame( array( 'size' => 4194304 ), $head );
		$this->assertSame( 'HEAD', $this->requests[0]['args']['method'] );

		$provider = $this->make_provider( array( $this->response( 404 ) ) );
		$this->assertFalse( $provider->head_object( 'k.bin' ) );
	}

	/**
	 * Auth rejections throw with the HTTP status, so the driver can park
	 * the destination instead of retrying forever.
	 *
	 * @return void
	 */
	public function test_auth_failure_throws_with_status() {
		$provider = $this->make_provider( array( $this->response( 403, 'AccessDenied' ) ) );

		try {
			$provider->head_object( 'k.bin' );
			$this->fail( 'A 403 must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
	}

	/**
	 * Transport failures (WP_Error) throw with code 0 - retryable.
	 *
	 * @return void
	 */
	public function test_transport_error_throws_code_zero() {
		$provider = new MainWP_Backup_Storage_S3(
			$this->settings(),
			function () {
				return new \WP_Error( 'http_request_failed', 'timed out' );
			}
		);

		try {
			$provider->put_object( 'k.bin', 'x' );
			$this->fail( 'A transport error must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 0, $e->getCode() );
			$this->assertStringContainsString( 'timed out', $e->getMessage() );
		}
	}

	/**
	 * Deletes treat 204 and 404 both as success.
	 *
	 * @return void
	 */
	public function test_delete_object_is_idempotent() {
		$provider = $this->make_provider( array( $this->response( 204 ) ) );
		$this->assertTrue( $provider->delete_object( 'k.bin' ) );
		$this->assertSame( 'DELETE', $this->requests[0]['args']['method'] );

		$provider = $this->make_provider( array( $this->response( 404 ) ) );
		$this->assertTrue( $provider->delete_object( 'k.bin' ) );
	}

	/**
	 * Listing parses keys, sizes, and the continuation token, and passes
	 * the token back on the next page.
	 *
	 * @return void
	 */
	public function test_list_objects_paginates() {
		$page1 = '<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>tok-2</NextContinuationToken>
<Contents><Key>4/42/chunks/aa.bin</Key><Size>100</Size></Contents>
<Contents><Key>4/42/chunks/bb.bin</Key><Size>200</Size></Contents>
</ListBucketResult>';
		$page2 = '<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult><IsTruncated>false</IsTruncated>
<Contents><Key>4/42/manifest.json</Key><Size>5</Size></Contents>
</ListBucketResult>';

		$provider = $this->make_provider(
			array(
				$this->response( 200, $page1 ),
				$this->response( 200, $page2 ),
			)
		);

		$first = $provider->list_objects( '4/42/' );
		$this->assertTrue( $first['truncated'] );
		$this->assertSame( 'tok-2', $first['next_token'] );
		$this->assertSame(
			array(
				array(
					'key'  => '4/42/chunks/aa.bin',
					'size' => 100,
				),
				array(
					'key'  => '4/42/chunks/bb.bin',
					'size' => 200,
				),
			),
			$first['objects']
		);
		$this->assertStringContainsString( 'list-type=2', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'prefix=4%2F42%2F', $this->requests[0]['url'] );

		$second = $provider->list_objects( '4/42/', $first['next_token'] );
		$this->assertFalse( $second['truncated'] );
		$this->assertNull( $second['next_token'] );
		$this->assertStringContainsString( 'continuation-token=tok-2', $this->requests[1]['url'] );
	}

	/**
	 * Downloads stream to a file instead of buffering the body.
	 *
	 * @return void
	 */
	public function test_get_object_streams_to_file() {
		$target = get_temp_dir() . 'mainwp-s3-download-' . uniqid() . '.bin';

		$transport = function ( $url, $args ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			file_put_contents( $args['filename'], 'streamed-bytes' );
			return $this->response( 200 );
		};

		$provider = new MainWP_Backup_Storage_S3( $this->settings(), $transport );

		try {
			$this->assertTrue( $provider->get_object( 'k.bin', $target ) );
			$this->assertTrue( $this->requests[0]['args']['stream'] );
			$this->assertSame( $target, $this->requests[0]['args']['filename'] );
			$this->assertSame( 'streamed-bytes', file_get_contents( $target ) );
		} finally {
			if ( file_exists( $target ) ) {
				unlink( $target );
			}
		}
	}

	/**
	 * The provider can presign GET URLs when its credentials are
	 * complete, and reports no support when they are not.
	 *
	 * @return void
	 */
	public function test_supports_presigned_get_requires_credentials() {
		$provider = $this->make_provider( array( $this->response( 200 ) ) );
		$this->assertInstanceOf( \MainWP\Dashboard\MainWP_Backup_Presignable_Provider::class, $provider );
		$this->assertTrue( $provider->supports_presigned_get() );

		$provider = $this->make_provider( array( $this->response( 200 ) ), array( 'secret_key' => '' ) );
		$this->assertFalse( $provider->supports_presigned_get() );

		$provider = $this->make_provider( array( $this->response( 200 ) ), array( 'bucket' => '' ) );
		$this->assertFalse( $provider->supports_presigned_get() );
	}

	/**
	 * Presigned URLs carry the query auth parameters, address the object
	 * like every other call, and are computed offline: the transport is
	 * never invoked.
	 *
	 * @return void
	 */
	public function test_presigned_get_url_is_offline_and_addressed() {
		$provider = $this->make_provider( array( $this->response( 200 ) ) );

		$url = $provider->presigned_get_url( '4/42/files/full.zip', 3600 );

		$this->assertStringStartsWith( 'https://example-bucket.s3.amazonaws.com/4/42/files/full.zip?', $url );
		$this->assertStringContainsString( 'X-Amz-Algorithm=AWS4-HMAC-SHA256', $url );
		$this->assertStringContainsString( 'X-Amz-Expires=3600', $url );
		$this->assertStringContainsString( 'X-Amz-SignedHeaders=host', $url );
		$this->assertStringContainsString( 'X-Amz-Signature=', $url );
		$this->assertSame( array(), $this->requests, 'Presigning must not perform any HTTP request.' );
	}

	/**
	 * Presigned URLs respect path-style addressing and custom endpoints.
	 *
	 * @return void
	 */
	public function test_presigned_get_url_path_style() {
		$provider = $this->make_provider(
			array( $this->response( 200 ) ),
			array(
				'endpoint'   => 'http://127.0.0.1:9000',
				'path_style' => 1,
			)
		);

		$url = $provider->presigned_get_url( 'k.bin', 600 );

		$this->assertStringStartsWith( 'http://127.0.0.1:9000/example-bucket/k.bin?', $url );
	}

	/**
	 * The connection test walks HEAD bucket, PUT probe, HEAD probe,
	 * DELETE probe and reports success.
	 *
	 * @return void
	 */
	public function test_connection_test_round_trip() {
		$provider = $this->make_provider(
			array(
				$this->response( 200 ),
				$this->response( 200 ),
				$this->response( 200, '', array( 'content-length' => '64' ) ),
				$this->response( 204 ),
			)
		);

		$result = $provider->connection_test();

		$this->assertTrue( $result['ok'] );
		$this->assertNull( $result['error'] );
		$this->assertCount( 4, $this->requests );
		$this->assertSame( array( 'HEAD', 'PUT', 'HEAD', 'DELETE' ), array_column( array_column( $this->requests, 'args' ), 'method' ) );
	}

	/**
	 * A failing step surfaces in the connection test result instead of
	 * throwing at the caller.
	 *
	 * @return void
	 */
	public function test_connection_test_reports_failure() {
		$provider = $this->make_provider( array( $this->response( 403, 'AccessDenied' ) ) );

		$result = $provider->connection_test();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '403', $result['error'] );
	}

	/**
	 * A request whose endpoint resolves to a link-local / cloud-metadata
	 * address is blocked before the transport runs: this is the DNS-rebind
	 * re-check. A private/loopback endpoint (self-hosted MinIO) is NOT blocked
	 * (see test_path_style_custom_endpoint).
	 *
	 * @return void
	 */
	public function test_request_blocked_when_endpoint_is_link_local() {
		$provider = $this->make_provider(
			array( $this->response( 200 ) ),
			array(
				'endpoint'   => 'http://169.254.169.254',
				'path_style' => 1,
			)
		);

		try {
			$provider->put_object( 'k.bin', 'x' );
			$this->fail( 'A link-local endpoint must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'link-local or cloud-metadata', $e->getMessage() );
		}

		$this->assertCount( 0, $this->requests, 'The transport must not run for a blocked host.' );
	}

	/**
	 * A single 3xx with a Location header is followed exactly once: the
	 * redirect target is re-guarded and re-signed as its own request.
	 *
	 * @return void
	 */
	public function test_request_follows_one_redirect_to_a_safe_host() {
		$provider = $this->make_provider(
			array(
				$this->response( 301, '', array( 'location' => 'http://127.0.0.1:9000/other-bucket/k.bin' ) ),
				$this->response( 200 ),
			)
		);

		$this->assertTrue( $provider->put_object( 'k.bin', 'x' ) );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'http://127.0.0.1:9000/other-bucket/k.bin', $this->requests[1]['url'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256', $this->requests[1]['args']['headers']['Authorization'] );
	}

	/**
	 * A redirect Location that resolves to a link-local / cloud-metadata
	 * address is refused before the follow-up request is dispatched.
	 *
	 * @return void
	 */
	public function test_request_refuses_redirect_to_unsafe_location() {
		$provider = $this->make_provider(
			array(
				$this->response( 301, '', array( 'location' => 'http://169.254.169.254/latest/meta-data/' ) ),
			)
		);

		try {
			$provider->put_object( 'k.bin', 'x' );
			$this->fail( 'A redirect to a link-local address must throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'link-local or cloud-metadata', $e->getMessage() );
		}

		$this->assertCount( 1, $this->requests, 'The follow-up request must not run for a blocked redirect target.' );
	}

	/**
	 * A second consecutive 3xx is not chased: at most one redirect hop is
	 * followed, and the un-followed 3xx surfaces via expect_status.
	 *
	 * @return void
	 */
	public function test_request_follows_at_most_one_redirect_hop() {
		$provider = $this->make_provider(
			array(
				$this->response( 301, '', array( 'location' => 'http://127.0.0.1:9000/hop-a' ) ),
				$this->response( 301, '', array( 'location' => 'http://127.0.0.1:9000/hop-b' ) ),
			)
		);

		try {
			$provider->put_object( 'k.bin', 'x' );
			$this->fail( 'A second consecutive redirect must not be chased.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'returned HTTP 301', $e->getMessage() );
		}

		$this->assertCount( 2, $this->requests );
	}

	/**
	 * The transport is told never to follow redirects on its own: every
	 * hop is re-guarded and re-signed by the provider, not WP's HTTP layer.
	 *
	 * @return void
	 */
	public function test_request_disables_automatic_redirection() {
		$provider = $this->make_provider( array( $this->response( 200 ) ) );

		$provider->put_object( 'k.bin', 'x' );

		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
	}
}
