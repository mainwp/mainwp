<?php
/**
 * MainWP Backup Storage GCS Provider Tests
 *
 * Verifies the Google Cloud Storage provider through an injected
 * transport: the endpoint is pinned to storage.googleapis.com and cannot
 * be overridden by settings, addressing is always path-style so dotted
 * bucket names never break TLS, the signing region defaults to us-east1
 * (GCS accepts it for all buckets) but stays overridable, and the
 * inherited S3 request behavior (SigV4 headers, idempotent deletes,
 * existence checks) holds against the GCS endpoint.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_GCS;
use MainWP\Dashboard\MainWP_Backup_Storage_Provider;

/**
 * Class MainWP_Backup_Storage_GCS_Test
 */
class MainWP_Backup_Storage_GCS_Test extends \WP_UnitTestCase {

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
	 * Default provider settings: a GCS destination only needs the bucket
	 * and an HMAC key pair.
	 *
	 * @param array $overrides Setting overrides.
	 *
	 * @return array
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'bucket'     => 'example-bucket',
				'access_key' => 'GOOG1EXAMPLEHMACKEYID',
				'secret_key' => 'exampleHmacSecretExampleHmacSecretExample',
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
	 * @return MainWP_Backup_Storage_GCS
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

		return new MainWP_Backup_Storage_GCS( $this->settings( $overrides ), $transport );
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
	 * The provider satisfies the storage provider interface.
	 *
	 * @return void
	 */
	public function test_implements_storage_provider_interface() {
		$provider = $this->make_provider( array( $this->response( 200 ) ) );
		$this->assertInstanceOf( MainWP_Backup_Storage_Provider::class, $provider );
	}

	/**
	 * PUT addresses the pinned GCS endpoint path-style and signs with
	 * SigV4 in the default us-east1 region.
	 *
	 * @return void
	 */
	public function test_put_object_uses_gcs_endpoint_path_style() {
		$provider = $this->make_provider( array( $this->response( 200 ) ) );
		$body     = 'chunk-bytes';

		$this->assertTrue( $provider->put_object( '4/42/chunks/abc.bin', $body ) );

		$request = $this->requests[0];
		$this->assertSame( 'https://storage.googleapis.com/example-bucket/4/42/chunks/abc.bin', $request['url'] );
		$this->assertSame( 'PUT', $request['args']['method'] );
		$this->assertSame( $body, $request['args']['body'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=GOOG1EXAMPLEHMACKEYID/', $request['args']['headers']['Authorization'] );
		$this->assertStringContainsString( '/us-east1/s3/aws4_request', $request['args']['headers']['Authorization'] );
		$this->assertSame( hash( 'sha256', $body ), $request['args']['headers']['x-amz-content-sha256'] );
	}

	/**
	 * A submitted endpoint or addressing style cannot redirect the
	 * provider away from GCS.
	 *
	 * @return void
	 */
	public function test_endpoint_and_path_style_cannot_be_overridden() {
		$provider = $this->make_provider(
			array( $this->response( 200 ) ),
			array(
				'endpoint'   => 'https://attacker.example.com',
				'path_style' => 0,
			)
		);

		$provider->put_object( 'k.bin', 'x' );

		$this->assertSame( 'https://storage.googleapis.com/example-bucket/k.bin', $this->requests[0]['url'] );
	}

	/**
	 * Dotted bucket names stay on the path, never in the host.
	 *
	 * @return void
	 */
	public function test_dotted_bucket_names_stay_in_the_path() {
		$provider = $this->make_provider(
			array( $this->response( 200 ) ),
			array( 'bucket' => 'backups.example.com' )
		);

		$provider->put_object( 'k.bin', 'x' );

		$this->assertSame( 'https://storage.googleapis.com/backups.example.com/k.bin', $this->requests[0]['url'] );
		$this->assertSame( 'storage.googleapis.com', $this->requests[0]['args']['headers']['Host'] );
	}

	/**
	 * A region setting overrides the us-east1 signing default.
	 *
	 * @return void
	 */
	public function test_region_setting_overrides_signing_default() {
		$provider = $this->make_provider(
			array( $this->response( 200 ) ),
			array( 'region' => 'europe-west1' )
		);

		$provider->put_object( 'k.bin', 'x' );

		$this->assertStringContainsString( '/europe-west1/s3/aws4_request', $this->requests[0]['args']['headers']['Authorization'] );
	}

	/**
	 * HEAD reports presence with size, absence as false, against the GCS
	 * endpoint.
	 *
	 * @return void
	 */
	public function test_head_object_present_and_absent() {
		$provider = $this->make_provider( array( $this->response( 200, '', array( 'content-length' => '4194304' ) ) ) );
		$this->assertSame( array( 'size' => 4194304 ), $provider->head_object( 'k.bin' ) );
		$this->assertSame( 'https://storage.googleapis.com/example-bucket/k.bin', $this->requests[0]['url'] );

		$provider = $this->make_provider( array( $this->response( 404 ) ) );
		$this->assertFalse( $provider->head_object( 'k.bin' ) );
	}

	/**
	 * Deletes treat 204 and 404 both as success.
	 *
	 * @return void
	 */
	public function test_delete_object_is_idempotent() {
		$provider = $this->make_provider( array( $this->response( 204 ) ) );
		$this->assertTrue( $provider->delete_object( 'k.bin' ) );

		$provider = $this->make_provider( array( $this->response( 404 ) ) );
		$this->assertTrue( $provider->delete_object( 'k.bin' ) );
	}

	/**
	 * The connection test walks HEAD bucket, PUT probe, HEAD probe,
	 * DELETE probe against the pinned endpoint and reports success.
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
		$this->assertStringStartsWith( 'https://storage.googleapis.com/example-bucket', $this->requests[0]['url'] );
	}
}
