<?php
/**
 * MainWP Backup S3 Signer Tests
 *
 * Verifies the SigV4 signer against the worked examples published in the
 * Amazon S3 API reference ("Signature Calculations for the Authorization
 * Header"). All four examples use the documented test credentials, the
 * fixed timestamp 20130524T000000Z, region us-east-1, and the bucket
 * examplebucket, so every expected Authorization header below is ground
 * truth from the service documentation rather than from this codebase.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_S3_Signer;

/**
 * Class MainWP_Backup_S3_Signer_Test
 */
class MainWP_Backup_S3_Signer_Test extends \WP_UnitTestCase {

	const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
	const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
	const REGION     = 'us-east-1';

	/**
	 * Hex sha256 of an empty payload.
	 *
	 * @var string
	 */
	const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * A signer pinned to the documentation timestamp 20130524T000000Z.
	 *
	 * @return MainWP_Backup_S3_Signer
	 */
	private function make_signer() {
		$fixed = gmmktime( 0, 0, 0, 5, 24, 2013 );
		return new MainWP_Backup_S3_Signer(
			function () use ( $fixed ) {
				return $fixed;
			}
		);
	}

	/**
	 * GET object example: GET /test.txt with a Range header.
	 *
	 * @return void
	 */
	public function test_get_object_example() {
		$headers = $this->make_signer()->sign(
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt',
			self::REGION,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			array( 'Range' => 'bytes=0-9' ),
			self::EMPTY_PAYLOAD_HASH
		);

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$headers['Authorization']
		);
		$this->assertSame( '20130524T000000Z', $headers['x-amz-date'] );
		$this->assertSame( self::EMPTY_PAYLOAD_HASH, $headers['x-amz-content-sha256'] );
		$this->assertSame( 'examplebucket.s3.amazonaws.com', $headers['Host'] );
		$this->assertSame( 'bytes=0-9', $headers['Range'], 'Caller headers must pass through.' );
	}

	/**
	 * PUT object example: PUT /test$file.text with a storage class and a
	 * signed Date header.
	 *
	 * @return void
	 */
	public function test_put_object_example() {
		$headers = $this->make_signer()->sign(
			'PUT',
			'https://examplebucket.s3.amazonaws.com/test%24file.text',
			self::REGION,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			array(
				'Date'                => 'Fri, 24 May 2013 00:00:00 GMT',
				'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
			),
			hash( 'sha256', 'Welcome to Amazon S3.' )
		);

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class,Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
			$headers['Authorization']
		);
	}

	/**
	 * GET bucket lifecycle example: a valueless query parameter.
	 *
	 * @return void
	 */
	public function test_get_bucket_lifecycle_example() {
		$headers = $this->make_signer()->sign(
			'GET',
			'https://examplebucket.s3.amazonaws.com/?lifecycle',
			self::REGION,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			array(),
			self::EMPTY_PAYLOAD_HASH
		);

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;x-amz-content-sha256;x-amz-date,Signature=fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543',
			$headers['Authorization']
		);
	}

	/**
	 * List objects example: multiple query parameters, sorted canonically.
	 *
	 * @return void
	 */
	public function test_list_objects_example() {
		$headers = $this->make_signer()->sign(
			'GET',
			'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J',
			self::REGION,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			array(),
			self::EMPTY_PAYLOAD_HASH
		);

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;x-amz-content-sha256;x-amz-date,Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
			$headers['Authorization']
		);
	}

	/**
	 * Query parameters are sorted by key in the canonical request, so
	 * parameter order in the URL must not change the signature.
	 *
	 * @return void
	 */
	public function test_query_parameter_order_is_canonicalized() {
		$signer = $this->make_signer();

		$a = $signer->sign( 'GET', 'https://examplebucket.s3.amazonaws.com/?prefix=J&max-keys=2', self::REGION, self::ACCESS_KEY, self::SECRET_KEY, array(), self::EMPTY_PAYLOAD_HASH );
		$b = $signer->sign( 'GET', 'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J', self::REGION, self::ACCESS_KEY, self::SECRET_KEY, array(), self::EMPTY_PAYLOAD_HASH );

		$this->assertSame( $b['Authorization'], $a['Authorization'] );
	}

	/**
	 * Presigned GET example from "Authenticating Requests: Using Query
	 * Parameters": GET /test.txt, 86400 second lifetime, host-only signed
	 * headers, UNSIGNED-PAYLOAD. The expected signature is ground truth
	 * from the service documentation.
	 *
	 * @return void
	 */
	public function test_presign_get_object_documentation_example() {
		$url = $this->make_signer()->presign(
			'https://examplebucket.s3.amazonaws.com/test.txt',
			self::REGION,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			86400
		);

		$parts = wp_parse_url( $url );
		$this->assertSame( 'examplebucket.s3.amazonaws.com', $parts['host'] );
		$this->assertSame( '/test.txt', $parts['path'] );

		parse_str( $parts['query'], $params );
		$this->assertSame( 'AWS4-HMAC-SHA256', $params['X-Amz-Algorithm'] );
		$this->assertSame( 'AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request', $params['X-Amz-Credential'] );
		$this->assertSame( '20130524T000000Z', $params['X-Amz-Date'] );
		$this->assertSame( '86400', $params['X-Amz-Expires'] );
		$this->assertSame( 'host', $params['X-Amz-SignedHeaders'] );
		$this->assertSame( 'aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $params['X-Amz-Signature'] );
	}

	/**
	 * Presigning is deterministic under a fixed clock and signs only the
	 * host header, never a content hash, so the URL works from any
	 * client.
	 *
	 * @return void
	 */
	public function test_presign_is_deterministic_and_signs_host_only() {
		$signer = $this->make_signer();

		$a = $signer->presign( 'https://examplebucket.s3.amazonaws.com/4/42/files/full.zip', self::REGION, self::ACCESS_KEY, self::SECRET_KEY, 3600 );
		$b = $signer->presign( 'https://examplebucket.s3.amazonaws.com/4/42/files/full.zip', self::REGION, self::ACCESS_KEY, self::SECRET_KEY, 3600 );

		$this->assertSame( $a, $b );
		$this->assertStringContainsString( 'X-Amz-SignedHeaders=host', $a );
		$this->assertStringNotContainsString( 'x-amz-content-sha256', $a );
	}

	/**
	 * Query parameters already on the URL survive presigning and are
	 * part of the signed canonical query.
	 *
	 * @return void
	 */
	public function test_presign_preserves_existing_query_parameters() {
		$url = $this->make_signer()->presign(
			'http://127.0.0.1:9000/bucket/key.bin?versionId=abc123',
			self::REGION,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			3600
		);

		$parts = wp_parse_url( $url );
		parse_str( $parts['query'], $params );

		$this->assertSame( 'abc123', $params['versionId'] );
		$this->assertSame( '127.0.0.1', $parts['host'] );
		$this->assertSame( 9000, $parts['port'], 'Non-standard ports must survive into the presigned URL.' );
		$this->assertArrayHasKey( 'X-Amz-Signature', $params );
	}

	/**
	 * A non-default port must be part of the signed Host header (MinIO and
	 * other S3-compatible endpoints run on explicit ports).
	 *
	 * @return void
	 */
	public function test_host_header_includes_nonstandard_port() {
		$headers = $this->make_signer()->sign(
			'GET',
			'http://127.0.0.1:9000/bucket/key.bin',
			self::REGION,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			array(),
			self::EMPTY_PAYLOAD_HASH
		);

		$this->assertSame( '127.0.0.1:9000', $headers['Host'] );
		$this->assertStringContainsString( 'SignedHeaders=host;x-amz-content-sha256;x-amz-date', $headers['Authorization'] );
	}
}
