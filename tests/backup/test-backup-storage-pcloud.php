<?php
/**
 * pCloud provider transport tests.
 *
 * Response shapes follow docs.pcloud.com method examples and rclone's
 * backend/pcloud/api/types.go. No pCloud account is used.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_PCloud;
use MainWP\Dashboard\MainWP_Backup_Storage_Provider;

require_once __DIR__ . '/class-backup-pcloud-transport-fixture.php';

/** Test the provider through the same transport seam as Dropbox. */
class MainWP_Backup_Storage_PCloud_Test extends \WP_UnitTestCase {
	/**
	 * Captured requests.
	 *
	 * @var array
	 */
	private $requests = array();

	/** Reset request capture. */
	public function set_up() {
		parent::set_up();
		$this->requests = array();
		add_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
	}

	/** Remove the DNS override. */
	public function tear_down() {
		remove_filter( 'mainwp_backup_skip_ssrf_check', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Build a scripted transport.
	 *
	 * @param array $responses Response queue.
	 * @param array $settings Settings overrides.
	 * @return MainWP_Backup_Storage_PCloud
	 */
	private function make_provider( array $responses, array $settings = array() ) {
		return new MainWP_Backup_Storage_PCloud(
			array_merge(
				array(
					'locationid'   => 1,
					'access_token' => 'token',
					'base_path'    => 'MainWP Backups',
				),
				$settings
			),
			function ( $url, $args ) use ( &$responses ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				$this->assertNotEmpty( $responses, 'Unexpected HTTP request: ' . $url );
				$response = array_shift( $responses );
				if ( ! is_wp_error( $response ) && ! empty( $args['stream'] ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
					file_put_contents( $args['filename'], $response['body'] );
				}
				return $response;
			}
		);
	}

	/**
	 * WP HTTP response using the documented result envelope.
	 *
	 * @param array $data JSON response.
	 * @param int   $status HTTP status.
	 * @return array
	 */
	private function response( array $data, int $status = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $data ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
		);
	}

	/**
	 * Folder metadata from createfolderifnotexists's documented example.
	 *
	 * @param string $id Folder ID.
	 * @return array
	 */
	private function folder( string $id = '230807' ) {
		return $this->response(
			array(
				'result'   => 0,
				'created'  => true,
				'metadata' => array(
					'isfolder' => true,
					'folderid' => $id,
				),
			)
		);
	}

	/** Upload, cache and 64-bit identity behavior. */
	public function test_pcloud_put_folder_cache_string_ids_and_timeouts() {
		// pCloud probe P2: Authorization Bearer is accepted.
		// pCloud probe P5: raw PUT with nopartial and explicit length overwrites by filename.
		$upload = $this->response(
			array(
				'result'   => 0,
				'metadata' => array( array( 'size' => 3 ) ),
			)
		);
		$p      = $this->make_provider( array( $this->folder( '9223372036854775808' ), $this->folder( '9223372036854775809' ), $upload, $upload ), array( 'locationid' => 2 ) );
		$this->assertInstanceOf( MainWP_Backup_Storage_Provider::class, $p );
		$this->assertTrue( $p->put_object( 'chunks/a.bin', 'abc' ) );
		$this->assertTrue( $p->put_object( 'chunks/b.bin', 'abc' ) );
		$this->assertCount( 4, $this->requests );
		$this->assertSame( '9223372036854775808', $p->base_folder_id() );
		parse_str( wp_parse_url( $this->requests[2]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( '9223372036854775809', $query['folderid'] );
		$this->assertSame( '1', $query['nopartial'] );
		$this->assertStringStartsWith( 'https://eapi.pcloud.com/', $this->requests[2]['url'] );
		$this->assertSame( 30, $this->requests[0]['args']['timeout'] );
		$this->assertSame( 120, $this->requests[2]['args']['timeout'] );
		$this->assertSame( 'Bearer token', $this->requests[2]['args']['headers']['Authorization'] );
		$this->assertSame( '3', (string) $this->requests[2]['args']['headers']['Content-Length'] );
		$this->assertSame( 'PUT', $this->requests[2]['args']['method'] );
		$this->assertSame( 'abc', $this->requests[2]['args']['body'] );
		$this->assertSame( 0, $this->requests[2]['args']['redirection'] );
	}

	/** Empty files need multipart rather than an empty PUT. */
	public function test_pcloud_zero_byte_upload() {
		// pCloud probe P5: zero-byte files are uploaded with multipart POST.
		$p = $this->make_provider(
			array(
				$this->folder(),
				$this->response(
					array(
						'result'   => 0,
						'metadata' => array( array( 'size' => 0 ) ),
					)
				),
			)
		);
		$this->assertTrue( $p->put_object( 'empty.bin', '' ) );
		$args = $this->requests[1]['args'];
		$this->assertSame( 'POST', $args['method'] );
		$this->assertStringContainsString( 'multipart/form-data; boundary=', $args['headers']['Content-Type'] );
		$this->assertStringContainsString( 'filename="empty.bin"', $args['body'] );
	}

	/** An incomplete upload must not be acknowledged. */
	public function test_pcloud_put_rejects_size_mismatch() {
		// pCloud probe P5: uploadfile reports the final stored size.
		$p = $this->make_provider(
			array(
				$this->folder(),
				$this->response(
					array(
						'result'   => 0,
						'metadata' => array( array( 'size' => 1 ) ),
					)
				),
			)
		);
		$this->expectException( \RuntimeException::class );
		$p->put_object( 'a', 'abc' );
	}

	/** Head and delete share documented absent-path semantics. */
	public function test_pcloud_head_delete_and_missing_list() {
		// The absence probe confirmed distinct results for missing files and parents.
		foreach ( array( 2009, 2005, 2002, 2055 ) as $code ) {
			$missing = $this->response( array( 'result' => $code ) );
			$p       = $this->make_provider( array( $missing, $missing, $missing ) );
			$this->assertFalse( $p->head_object( 'missing' ) );
			$this->assertTrue( $p->delete_object( 'missing' ) );
			$this->assertSame(
				array(
					'objects'    => array(),
					'next_token' => null,
					'truncated'  => false,
				),
				$p->list_objects( 'missing/' )
			);
		}
		$p = $this->make_provider(
			array(
				$this->response(
					array(
						'result'   => 0,
						'metadata' => array(
							'isfolder' => false,
							'size'     => 73269,
						),
					)
				),
				$this->response( array( 'result' => 0 ) ),
			)
		);
		$this->assertSame( array( 'size' => 73269 ), $p->head_object( 'a' ) );
		$this->assertTrue( $p->delete_object( 'a' ) );
	}

	/** The fixture distinguishes missing files from missing parent folders. */
	public function test_pcloud_fixture_stat_absence(): void {
		foreach ( array( 2055, 2002 ) as $expected ) {
			$state     = array(
				'folders' => 2055 === $expected ? array(
					''                => '0',
					'/MainWP Backups' => '230807',
				) : array( '' => '0' ),
			);
			$transport = MainWP_PCloud_Transport_Fixture::transport( $state );
			$p         = new MainWP_Backup_Storage_PCloud(
				array(
					'locationid'   => 1,
					'access_token' => 'token',
					'base_path'    => 'MainWP Backups',
				),
				function ( $url, $args ) use ( $transport, $expected ) {
					$response = $transport( false, $args, $url );
					$this->assertSame( $expected, json_decode( $response['body'], true )['result'] );
					return $response;
				}
			);
			$this->assertFalse( $p->head_object( 'missing' ) );
		}
	}

	/** API errors are parsed even when HTTP succeeds or fails. */
	public function test_pcloud_result_code_table() {
		// pCloud probe P3: a nonzero result may accompany HTTP 200.
		// pCloud probe P4: auth codes map to 401, access denied 2003 to 403 and quota 2008 to 507.
		// pCloud probe P9: 4xxx results throttle and 5xxx results retry.
		foreach ( array(
			1000 => 401,
			2000 => 401,
			2094 => 401,
			2095 => 401,
			2003 => 403,
			2008 => 507,
			4000 => 429,
			4999 => 429,
			5000 => 500,
			5001 => 500,
		) as $result => $expected ) {
			foreach ( array( 200, 503 ) as $http ) {
				$p = $this->make_provider(
					array(
						$this->response(
							array(
								'result' => $result,
								'error'  => 'SECRET-RESPONSE',
							),
							$http
						),
					)
				);
				try {
					$p->head_object( 'a' );
					$this->fail( 'Service error must throw.' );
				} catch ( \RuntimeException $e ) {
					$this->assertSame( $expected, $e->getCode() );
					$this->assertStringNotContainsString( 'SECRET', $e->getMessage() );
					if ( 401 === $expected ) {
						$this->assertSame( 'pCloud refused the saved access. Click Connect pCloud and paste the authorization code to reconnect this destination.', $e->getMessage() );
					}
				}
			}
		}
	}

	/** Invalid locations never silently select the US host. */
	public function test_pcloud_locationid_validation() {
		foreach ( array( 0, 3, '', '2evil', null, true ) as $location ) {
			try {
				$this->make_provider( array(), array( 'locationid' => $location ) );
				$this->fail( 'Invalid region must throw.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'region', $e->getMessage() );
			}
		}
		$this->assertCount( 0, $this->requests );
	}

	/** Nested list metadata is flattened and filtered by the whole prefix. */
	public function test_pcloud_list_prefix_filtering() {
		// pCloud probe P6: recursive listfolder returns the complete tree without pagination.
		$p = $this->make_provider(
			array(
				$this->response(
					array(
						'result'   => 0,
						'metadata' => array(
							'contents' => array(
								array(
									'name'     => 'manifest.json',
									'size'     => 5,
									'isfolder' => false,
								),
								array(
									'name'     => 'chunks',
									'isfolder' => true,
									'contents' => array(
										array(
											'name'     => 'a.bin',
											'size'     => 8,
											'isfolder' => false,
										),
									),
								),
							),
						),
					)
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'key'  => '4/42/chunks/a.bin',
					'size' => 8,
				),
			),
			$p->list_objects( '4/42/ch' )['objects']
		);
		parse_str( wp_parse_url( $this->requests[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( '/MainWP Backups/4/42', $query['path'] );
		$this->assertSame( '1', $query['recursive'] );
	}

	/** Only an anchored pCloud content host may receive a streamed GET. */
	public function test_pcloud_download_host_validation_redirect_and_cleanup() {
		// pCloud probe P7: download hosts match the anchored pcloud.com subdomain pattern and do not redirect.
		foreach ( array( 'evil.com', 'pcloud.com.evil.com', 'pcloud.com', 'c1.pcloud.com:443', 'c1.pcloud.com@evil.com' ) as $host ) {
			$p = $this->make_provider(
				array(
					$this->response(
						array(
							'result' => 0,
							'hosts'  => array( $host ),
							'path'   => '/hash/file',
						)
					),
				)
			);
			try {
				$p->get_object( 'a', get_temp_dir() . 'never-pcloud' );
				$this->fail( 'Invalid content host must be refused.' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'link', $e->getMessage() );
			}
		}
		$file = tempnam( get_temp_dir(), 'pcloud-' );
		try {
			$link = $this->response(
				array(
					'result' => 0,
					'hosts'  => array( 'c63.pcloud.com' ),
					'path'   => '/hash/My%20picture.jpg',
				)
			);
			$p    = $this->make_provider( array( $link, $this->response( array(), 302 ) ) );
			try {
				$p->get_object( 'a', $file );
				$this->fail( 'Redirect must be refused.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 302, $e->getCode() );
			}
			$this->assertFileDoesNotExist( $file );
			$p = $this->make_provider( array( $link, $this->response( array() ) ) );
			$this->assertTrue( $p->get_object( 'a', $file ) );
			$this->assertSame( '[]', file_get_contents( $file ) );
			$args = end( $this->requests )['args'];
			$this->assertSame( 120, $args['timeout'] );
			$this->assertSame( 0, $args['redirection'] );
			$this->assertSame( $file, $args['filename'] );
			$this->assertTrue( $args['stream'] );
			$this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? array() );
		} finally {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/** Connection failures never expose transport or server text. */
	public function test_pcloud_connection_test_and_safe_errors() {
		// pCloud probe P5: a probe upload returns its stored byte length.
		$p = $this->make_provider(
			array(
				$this->folder(),
				$this->response(
					array(
						'result'   => 0,
						'metadata' => array( array( 'size' => 22 ) ),
					)
				),
				$this->response(
					array(
						'result'   => 0,
						'metadata' => array(
							'size'     => 22,
							'isfolder' => false,
						),
					)
				),
				$this->response( array( 'result' => 0 ) ),
			)
		);
		$this->assertSame(
			array(
				'ok'    => true,
				'error' => null,
			),
			$p->connection_test()
		);
		$p      = $this->make_provider( array( new \WP_Error( 'failed', 'SECRET token in network error' ) ) );
		$result = $p->connection_test();
		$this->assertFalse( $result['ok'] );
		$this->assertStringNotContainsString( 'SECRET', $result['error'] );
		// pCloud probe P4: quota result 2008 keeps a safe, actionable message.
		$p      = $this->make_provider( array( $this->response( array( 'result' => 2008 ) ) ) );
		$result = $p->connection_test();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'pCloud storage quota exceeded. (HTTP 507)', $result['error'] );
	}

	/** IDs remain strings, including values beyond PHP_INT_MAX. */
	public function test_pcloud_account_and_base_folder_ids_are_strings() {
		$p = $this->make_provider(
			array(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"result":0,"userid":9223372036854775808}',
				),
				$this->folder( '9223372036854775809' ),
			)
		);
		$this->assertSame( '9223372036854775808', $p->account_userid() );
		$this->assertSame( '9223372036854775809', $p->base_folder_id() );
	}
	/** Enumeration points expose safe pCloud failure messages. */
	public function test_pcloud_factory_and_error_enumerations() {
		$store = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$this->assertInstanceOf(
			MainWP_Backup_Storage_PCloud::class,
			$store->make_provider(
				(object) array(
					'provider' => 'pcloud',
					'settings' => array( 'locationid' => 1 ),
				)
			)
		);
		foreach ( array( \MainWP\Dashboard\MainWP_Backup_Upload_Driver::instance(), \MainWP\Dashboard\MainWP_Backup_Cloud_Delete_Driver::instance() ) as $driver ) {
			$method = new \ReflectionMethod( $driver, 'error_code_for_provider' );
			$this->assertSame( 'mainwp_backup_pcloud_failed', $method->invoke( $driver, 'pcloud' ) );
		}
		$result = new \ReflectionMethod( \MainWP\Dashboard\MainWP_Backup_Operations::class, 'storage_result' );
		$error  = $result->invoke( null, array( 'error' => 'SECRET' ), 'pcloud' );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'mainwp_backup_pcloud_failed', $error->get_error_code() );
		$this->assertStringContainsString( 'pCloud', $error->get_error_message() );
		$this->assertStringNotContainsString( 'SECRET', $error->get_error_message() );
	}
	/** Network failures and malformed success bodies must not masquerade as absence. */
	public function test_pcloud_transport_and_malformed_response_fail_closed() {
		foreach ( array( new \WP_Error( 'failed', 'SECRET' ), $this->response( array() ), $this->response( array( 'result' => 0 ) ) ) as $response ) {
			$p = $this->make_provider( array( $response ) );
			try {
				$p->head_object( 'a' );
				$this->fail( 'Failed metadata must not be treated as absence.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( is_wp_error( $response ) ? 0 : 500, $e->getCode() );
				$this->assertStringNotContainsString( 'SECRET', $e->getMessage() );
			}
		}
	}
	/** Token metadata uses the field names confirmed by the live exchange. */
	public function test_oauth_exchange_in_both_regions(): void {
		foreach ( array( 1, 2 ) as $region ) {
			foreach ( array( 23419480, '23419480', '9223372036854775808' ) as $userid ) {
				$transport = function ( $url, $args ) use ( $region, $userid ) {
					$this->assertSame( MainWP_Backup_Storage_PCloud::API_BASES[ $region ] . '/oauth2_token', $url );
					$this->assertSame(
						array(
							'client_id'     => 'client',
							'client_secret' => 'secret',
							'code'          => 'code',
						),
						$args['body']
					);
					$this->assertSame( 'POST', $args['method'] );
					$this->assertSame( 30, $args['timeout'] );
					$this->assertSame( 0, $args['redirection'] );
					return $this->response(
						array(
							'result'       => 0,
							'userid'       => $userid,
							'locationid'   => is_int( $userid ) ? $region : (string) $region,
							'token_type'   => 'bearer',
							'access_token' => str_repeat( 't', 52 ),
						)
					);
				};
				$result    = MainWP_Backup_Storage_PCloud::exchange_authorization_code( 'client', 'secret', 'code', $region, $transport );
				$this->assertSame(
					array(
						'access_token' => str_repeat( 't', 52 ),
						'userid'       => (string) $userid,
						'locationid'   => $region,
					),
					$result
				);
				$this->assertArrayNotHasKey( 'uid', $result );
			}
		}
	}

	/** Header control characters and malformed tokens must never reach an authenticated request. */
	public function test_oauth_exchange_rejects_invalid_access_tokens(): void {
		foreach ( array( str_repeat( 't', 26 ) . "\r\nInjected: value", str_repeat( 't', 52 ) . "\n", 'short', str_repeat( 't', 513 ), ' ' . str_repeat( 't', 52 ) ) as $token ) {
			$result = MainWP_Backup_Storage_PCloud::exchange_authorization_code(
				'client',
				'secret',
				'code',
				1,
				function () use ( $token ) {
					return $this->response( array( 'access_token' => $token ) );
				}
			);
			$this->assertSame( array( 'error' => 'pCloud did not issue an access token. Click Connect pCloud and paste the new code.' ), $result );
			$this->assertStringNotContainsString( $token, wp_json_encode( $result ) );
		}
	}

	/** A normal pCloud token survives validation unchanged. */
	public function test_oauth_exchange_accepts_normal_52_character_token(): void {
		$token  = str_repeat( 'Ab3_', 13 );
		$result = MainWP_Backup_Storage_PCloud::exchange_authorization_code(
			'client',
			'secret',
			'code',
			1,
			function () use ( $token ) {
				return $this->response( array( 'access_token' => $token ) );
			}
		);
		$this->assertSame( array( 'access_token' => $token ), $result );
	}

	/** An absent or invalid region hint does not invalidate an issued token. */
	public function test_oauth_exchange_ignores_invalid_locationid(): void {
		foreach ( array( array(), array( 'locationid' => 0 ), array( 'locationid' => 3 ), array( 'locationid' => 'x' ), array( 'locationid' => true ), array( 'locationid' => 2.5 ) ) as $optional ) {
			$result = MainWP_Backup_Storage_PCloud::exchange_authorization_code(
				'client',
				'secret',
				'code',
				1,
				function () use ( $optional ) {
					return $this->response(
						array_merge(
							array(
								'result'       => 0,
								'access_token' => str_repeat( 't', 52 ),
							),
							$optional
						)
					);
				}
			);
			$this->assertSame( array( 'access_token' => str_repeat( 't', 52 ) ), $result );
		}
	}

	/** JSON integers beyond PHP's range retain every digit of the account ID. */
	public function test_oauth_exchange_preserves_large_numeric_userid(): void {
		$result = MainWP_Backup_Storage_PCloud::exchange_authorization_code(
			'client',
			'secret',
			'code',
			1,
			function () {
				$response         = $this->response(
					array(
						'result'       => 0,
						'access_token' => str_repeat( 't', 52 ),
						'userid'       => '9223372036854775808',
					)
				);
				$response['body'] = str_replace( '"9223372036854775808"', '9223372036854775808', $response['body'] );
				return $response;
			}
		);
		$this->assertSame(
			array(
				'access_token' => str_repeat( 't', 52 ),
				'userid'       => '9223372036854775808',
			),
			$result
		);
	}

	/** Wrong-region and spent codes retain pCloud's refusal result. */
	public function test_oauth_exchange_preserves_refusal_codes(): void {
		foreach ( array( 2012, 2092 ) as $code ) {
			$result = MainWP_Backup_Storage_PCloud::exchange_authorization_code(
				'client',
				'secret',
				'code',
				1,
				function () use ( $code ) {
					return $this->response(
						array(
							'result' => $code,
							'error'  => 'private-response',
						)
					);
				}
			);
			$this->assertSame(
				array(
					'error'  => 'pCloud did not issue an access token. Click Connect pCloud and paste the new code.',
					'result' => $code,
				),
				$result
			);
		}
	}

	/** Malformed responses and service failures return fixed errors without response text. */
	public function test_oauth_exchange_failures_are_safe(): void {
		// pCloud probe P1: token field and result envelope validation.
		$responses = array(
			new \WP_Error( 'transport', 'private-response' ),
			$this->response( array( 'access_token' => 'private-response' ), 302 ),
			$this->response( array( 'access_token' => 'private-response' ), 500 ),
			$this->response(
				array(
					'result'       => 2000,
					'access_token' => 'private-response',
				)
			),
			$this->response( array( 'error' => 'private-response' ) ),
			$this->response( array( 'access_token' => array( 'private-response' ) ) ),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'private-response',
			),
		);
		foreach ( $responses as $response ) {
			$result = MainWP_Backup_Storage_PCloud::exchange_authorization_code(
				'client',
				'secret',
				'code',
				1,
				static function () use ( $response ) {
					return $response;
				}
			);
			$this->assertArrayHasKey( 'error', $result );
			$this->assertStringNotContainsString( 'private-response', $result['error'] );
		}
		$calls  = 0;
		$result = MainWP_Backup_Storage_PCloud::exchange_authorization_code(
			'client',
			'secret',
			'code',
			3,
			static function () use ( &$calls ) {
				++$calls;
			}
		);
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 0, $calls );
	}

	/**
	 * Route requests by host and API method so each region decision is scripted.
	 *
	 * @param array $routes "host/method" => response or WP_Error.
	 * @return \Closure
	 */
	private function region_transport( array $routes ): \Closure {
		return function ( $url, $args ) use ( $routes ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			$route            = wp_parse_url( $url, PHP_URL_HOST ) . wp_parse_url( $url, PHP_URL_PATH );
			$this->assertArrayHasKey( $route, $routes, 'Unexpected HTTP request: ' . $url );
			return $routes[ $route ];
		};
	}

	/** A token response from the code exchange. */
	private function token(): array {
		return $this->response(
			array(
				'result'       => 0,
				'access_token' => str_repeat( 't', 52 ),
				'userid'       => 101,
			)
		);
	}

	/** A userinfo response for the issued token. */
	private function userinfo(): array {
		return $this->response(
			array(
				'result' => 0,
				'userid' => 101,
			)
		);
	}

	/**
	 * Region decision table for a pasted code.
	 *
	 * When both hosts refuse the code, the US error is returned because the two refusals read the same.
	 * When the US host refuses and the EU host cannot be reached, the EU transport error is returned:
	 * the code may be valid for the EU region, so a new code would not help.
	 *
	 * @return array Routes, expected result and expected request sequence.
	 */
	public static function region_cases(): array {
		$refused   = array( 'result' => 2012 );
		$no_token  = 'pCloud did not issue an access token. Click Connect pCloud and paste the new code.';
		$no_send   = 'The pCloud code exchange could not be sent. Check the dashboard&#039;s outbound connectivity and try again.';
		$no_region = 'pCloud did not confirm the account region. Click Connect pCloud and paste the new code.';
		return array(
			'US code'                          => array(
				array(
					'api.pcloud.com/oauth2_token' => 'token',
					'api.pcloud.com/userinfo'     => 'userinfo',
				),
				array(
					'access_token' => str_repeat( 't', 52 ),
					'locationid'   => 1,
					'userid'       => '101',
				),
				array( 'api.pcloud.com/oauth2_token', 'api.pcloud.com/userinfo' ),
			),
			'EU code after a US refusal'       => array(
				array(
					'api.pcloud.com/oauth2_token'  => $refused,
					'eapi.pcloud.com/oauth2_token' => 'token',
					'eapi.pcloud.com/userinfo'     => 'userinfo',
				),
				array(
					'access_token' => str_repeat( 't', 52 ),
					'locationid'   => 2,
					'userid'       => '101',
				),
				array( 'api.pcloud.com/oauth2_token', 'eapi.pcloud.com/oauth2_token', 'eapi.pcloud.com/userinfo' ),
			),
			'US exchange, EU userinfo'         => array(
				array(
					'api.pcloud.com/oauth2_token' => 'token',
					'api.pcloud.com/userinfo'     => array( 'result' => 2000 ),
					'eapi.pcloud.com/userinfo'    => 'userinfo',
				),
				array(
					'access_token' => str_repeat( 't', 52 ),
					'locationid'   => 2,
					'userid'       => '101',
				),
				array( 'api.pcloud.com/oauth2_token', 'api.pcloud.com/userinfo', 'eapi.pcloud.com/userinfo' ),
			),
			'US exchange hints EU region'      => array(
				array(
					'api.pcloud.com/oauth2_token' => array(
						'result'       => 0,
						'access_token' => str_repeat( 't', 52 ),
						'userid'       => 101,
						'locationid'   => 2,
					),
					'eapi.pcloud.com/userinfo'    => 'userinfo',
				),
				array(
					'access_token' => str_repeat( 't', 52 ),
					'locationid'   => 2,
					'userid'       => '101',
				),
				array( 'api.pcloud.com/oauth2_token', 'eapi.pcloud.com/userinfo' ),
			),
			'EU hint, US userinfo confirms'    => array(
				array(
					'api.pcloud.com/oauth2_token' => array(
						'result'       => 0,
						'access_token' => str_repeat( 't', 52 ),
						'userid'       => 101,
						'locationid'   => '2',
					),
					'eapi.pcloud.com/userinfo'    => array( 'result' => 2094 ),
					'api.pcloud.com/userinfo'     => 'userinfo',
				),
				array(
					'access_token' => str_repeat( 't', 52 ),
					'locationid'   => 1,
					'userid'       => '101',
				),
				array( 'api.pcloud.com/oauth2_token', 'eapi.pcloud.com/userinfo', 'api.pcloud.com/userinfo' ),
			),
			'both exchanges refused'           => array(
				array(
					'api.pcloud.com/oauth2_token'  => $refused,
					'eapi.pcloud.com/oauth2_token' => array( 'result' => 2092 ),
				),
				array( 'error' => $no_token ),
				array( 'api.pcloud.com/oauth2_token', 'eapi.pcloud.com/oauth2_token' ),
			),
			'US transport failure'             => array(
				array( 'api.pcloud.com/oauth2_token' => 'transport' ),
				array( 'error' => $no_send ),
				array( 'api.pcloud.com/oauth2_token' ),
			),
			'US refusal, EU transport failure' => array(
				array(
					'api.pcloud.com/oauth2_token'  => $refused,
					'eapi.pcloud.com/oauth2_token' => 'transport',
				),
				array( 'error' => $no_send ),
				array( 'api.pcloud.com/oauth2_token', 'eapi.pcloud.com/oauth2_token' ),
			),
			'US answer without a result code'  => array(
				array( 'api.pcloud.com/oauth2_token' => 'malformed' ),
				array( 'error' => $no_token ),
				array( 'api.pcloud.com/oauth2_token' ),
			),
			'userinfo fails on both hosts'     => array(
				array(
					'api.pcloud.com/oauth2_token' => 'token',
					'api.pcloud.com/userinfo'     => array( 'result' => 2000 ),
					'eapi.pcloud.com/userinfo'    => 'transport',
				),
				array( 'error' => $no_region ),
				array( 'api.pcloud.com/oauth2_token', 'api.pcloud.com/userinfo', 'eapi.pcloud.com/userinfo' ),
			),
		);
	}

	/** Only the userinfo host that confirms a valid account supplies the email. */
	public function test_code_exchange_returns_confirming_account_email(): void {
		foreach ( array( 1, 2 ) as $region ) {
			$this->requests = array();
			$routes         = array(
				'api.pcloud.com/oauth2_token' => $this->response(
					array(
						'access_token' => str_repeat( 't', 52 ),
						'email'        => 'exchange@example.com',
					)
				),
				'api.pcloud.com/userinfo'     => $this->response(
					array(
						'result' => 0,
						'userid' => 1 === $region ? 101 : 'invalid',
						'email'  => 1 === $region ? 'us-owner@example.com' : 'unconfirmed@example.com',
					)
				),
				'eapi.pcloud.com/userinfo'    => $this->response(
					array(
						'result' => 0,
						'userid' => 202,
						'email'  => 'eu-owner@example.com',
					)
				),
			);
			$result         = MainWP_Backup_Storage_PCloud::exchange_code_for_region( 'client', 'secret', 'code', $this->region_transport( $routes ) );
			$this->assertSame(
				array(
					'access_token'  => str_repeat( 't', 52 ),
					'locationid'    => $region,
					'userid'        => (string) ( 1 === $region ? 101 : 202 ),
					'account_email' => sanitize_email( 1 === $region ? 'us-owner@example.com' : 'eu-owner@example.com' ),
				),
				$result
			);
			$counts = array_count_values( array_column( $this->requests, 'url' ) );
			$this->assertCount( 1 + $region, $this->requests );
			$this->assertSame( 1, $counts['https://api.pcloud.com/userinfo'] );
			$this->assertSame( 2 === $region ? 1 : 0, $counts['https://eapi.pcloud.com/userinfo'] ?? 0 );
		}
	}

	/** Missing and invalid email values do not prevent a successful connection. */
	public function test_code_exchange_omits_missing_or_invalid_account_email(): void {
		foreach ( array( array(), array( 'email' => null ), array( 'email' => '' ), array( 'email' => 'invalid' ), array( 'email' => 123 ), array( 'email' => array( 'owner@example.com' ) ) ) as $email ) {
			$this->requests = array();
			$routes         = array(
				'api.pcloud.com/oauth2_token' => $this->token(),
				'api.pcloud.com/userinfo'     => $this->response(
					$email + array(
						'result' => 0,
						'userid' => 101,
					)
				),
			);
			$result         = MainWP_Backup_Storage_PCloud::exchange_code_for_region( 'client', 'secret', 'code', $this->region_transport( $routes ) );
			$this->assertSame(
				array(
					'access_token' => str_repeat( 't', 52 ),
					'locationid'   => 1,
					'userid'       => '101',
				),
				$result
			);
			$this->assertCount( 2, $this->requests );
			$this->assertSame( 'https://api.pcloud.com/userinfo', $this->requests[1]['url'] );
		}
	}

	/**
	 * The code is exchanged at most once per host and never sent again after a token is issued.
	 *
	 * @dataProvider region_cases
	 * @param array $routes Scripted responses by host and method.
	 * @param array $expected Expected result.
	 * @param array $sequence Expected request routes in order.
	 */
	public function test_code_exchange_detects_region( array $routes, array $expected, array $sequence ): void {
		foreach ( $routes as $route => $response ) {
			if ( 'token' === $response ) {
				$routes[ $route ] = $this->token();
			} elseif ( 'userinfo' === $response ) {
				$routes[ $route ] = $this->userinfo();
			} elseif ( 'transport' === $response ) {
				$routes[ $route ] = new \WP_Error( 'http_request_failed', 'private-response' );
			} elseif ( 'malformed' === $response ) {
				$routes[ $route ] = $this->response( array( 'error' => 'private-response' ), 502 );
			} else {
				$routes[ $route ] = $this->response( $response + array( 'error' => 'private-response' ) );
			}
		}
		$result = MainWP_Backup_Storage_PCloud::exchange_code_for_region( 'client', 'secret', 'pasted-code', $this->region_transport( $routes ) );
		$this->assertSame( $expected, $result );
		$this->assertStringNotContainsString( 'private-response', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'pasted-code', wp_json_encode( $result ) );
		$this->assertSame(
			$sequence,
			array_map(
				static function ( $request ) {
					return wp_parse_url( $request['url'], PHP_URL_HOST ) . wp_parse_url( $request['url'], PHP_URL_PATH );
				},
				$this->requests
			)
		);
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'pasted-code', $request['url'] );
			$this->assertSame( 0, $request['args']['redirection'] );
			if ( str_ends_with( $request['url'], '/oauth2_token' ) ) {
				$this->assertSame(
					array(
						'client_id'     => 'client',
						'client_secret' => 'secret',
						'code'          => 'pasted-code',
					),
					$request['args']['body']
				);
				$this->assertSame( 'POST', $request['args']['method'] );
				$this->assertSame( 30, $request['args']['timeout'] );
			} else {
				$this->assertStringNotContainsString( 'pasted-code', wp_json_encode( $request['args'] ) );
				$this->assertSame( 'Bearer ' . str_repeat( 't', 52 ), $request['args']['headers']['Authorization'] );
			}
		}
	}

	/** An empty code or missing app credentials never reach pCloud. */
	public function test_code_exchange_requires_code_and_credentials(): void {
		$transport = $this->region_transport( array() );
		foreach ( array( array( 'client', 'secret', '  ' ), array( '', 'secret', 'code' ), array( 'client', '', 'code' ) ) as $case ) {
			$this->assertArrayHasKey( 'error', MainWP_Backup_Storage_PCloud::exchange_code_for_region( $case[0], $case[1], $case[2], $transport ) );
		}
		$this->assertCount( 0, $this->requests );
	}

	/** Without wp-config overrides the built-in app is used, and its authorize link carries only the client ID and response type. */
	public function test_app_credentials_default_to_built_in_app(): void {
		$this->assertFalse( defined( 'MAINWP_PCLOUD_CLIENT_ID' ) );
		$this->assertSame(
			array(
				'client_id'     => MainWP_Backup_Storage_PCloud::CLIENT_ID,
				'client_secret' => MainWP_Backup_Storage_PCloud::CLIENT_SECRET,
			),
			MainWP_Backup_Storage_PCloud::app_credentials()
		);
		$this->assertNotSame( '', MainWP_Backup_Storage_PCloud::CLIENT_ID );
		$this->assertNotSame( '', MainWP_Backup_Storage_PCloud::CLIENT_SECRET );
		$this->assertSame(
			'https://my.pcloud.com/oauth2/authorize?client_id=' . rawurlencode( MainWP_Backup_Storage_PCloud::CLIENT_ID ) . '&response_type=code',
			MainWP_Backup_Storage_PCloud::authorize_url()
		);
	}
}
