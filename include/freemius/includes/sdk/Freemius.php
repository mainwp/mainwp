<?php
	/**
	 * Copyright 2014 Freemius, Inc.
	 *
	 * Licensed under the GPL v2 (the "License"); you may
	 * not use this file except in compliance with the License. You may obtain
	 * a copy of the License at
	 *
	 *     http://choosealicense.com/licenses/gpl-v2/
	 *
	 * Unless required by applicable law or agreed to in writing, software
	 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
	 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
	 * License for the specific language governing permissions and limitations
	 * under the License.
	 */

	require_once dirname( __FILE__ ) . '/FreemiusBase.php';

	if ( ! defined( 'FS_SDK__USER_AGENT' ) ) {
		define( 'FS_SDK__USER_AGENT', 'fs-php-' . Freemius_Api_Base::VERSION );
	}

	if ( ! defined( 'FS_SDK__SIMULATE_NO_CURL' ) ) {
		define( 'FS_SDK__SIMULATE_NO_CURL', false );
	}

	if ( ! defined( 'FS_SDK__SIMULATE_NO_API_CONNECTIVITY_CLOUDFLARE' ) ) {
		define( 'FS_SDK__SIMULATE_NO_API_CONNECTIVITY_CLOUDFLARE', false );
	}

	if ( ! defined( 'FS_SDK__SIMULATE_NO_API_CONNECTIVITY_SQUID_ACL' ) ) {
		define( 'FS_SDK__SIMULATE_NO_API_CONNECTIVITY_SQUID_ACL', false );
	}

	if ( ! defined( 'FS_SDK__HAS_CURL' ) ) {
		define( 'FS_SDK__HAS_CURL', ! FS_SDK__SIMULATE_NO_CURL && function_exists( 'curl_version' ) );
	}

	if ( ! FS_SDK__HAS_CURL ) {
		$curl_version = array( 'version' => '7.0.0' );
	} else {
		$curl_version = curl_version();
	}

	if ( ! defined( 'FS_API__PROTOCOL' ) ) {
		define( 'FS_API__PROTOCOL', version_compare( $curl_version['version'], '7.37', '>=' ) ? 'https' : 'http' );
	}

	if ( ! defined( 'FS_API__LOGGER_ON' ) ) {
		define( 'FS_API__LOGGER_ON', false );
	}

	if ( ! defined( 'FS_API__ADDRESS' ) ) {
		define( 'FS_API__ADDRESS', '://api.freemius.com' );
	}
	if ( ! defined( 'FS_API__SANDBOX_ADDRESS' ) ) {
		define( 'FS_API__SANDBOX_ADDRESS', '://sandbox-api.freemius.com' );
	}

	if ( class_exists( 'Freemius_Api' ) ) {
		return;
	}

	class Freemius_Api extends Freemius_Api_Base {
		private static $_logger = array();

		/**
		 * @param string      $pScope   'app', 'developer', 'user' or 'install'.
		 * @param number      $pID      Element's id.
		 * @param string      $pPublic  Public key.
		 * @param string|bool $pSecret  Element's secret key.
		 * @param bool        $pSandbox Whether or not to run API in sandbox mode.
		 */
		public function __construct( $pScope, $pID, $pPublic, $pSecret = false, $pSandbox = false ) {
			// If secret key not provided, use public key encryption.
			if ( is_bool( $pSecret ) ) {
				$pSecret = $pPublic;
			}

			parent::Init( $pScope, $pID, $pPublic, $pSecret, $pSandbox );
		}

		public static function GetUrl( $pCanonizedPath = '', $pIsSandbox = false ) {
			$address = ( $pIsSandbox ? FS_API__SANDBOX_ADDRESS : FS_API__ADDRESS );

			if ( ':' === $address[0] ) {
				$address = self::$_protocol . $address;
			}

			return $address . $pCanonizedPath;
		}

		#region Servers Clock Diff ------------------------------------------------------

		/**
		 * @var int Clock diff in seconds between current server to API server.
		 */
		private static $_clock_diff = 0;

		/**
		 * Set clock diff for all API calls.
		 *
		 * @since 1.0.3
		 *
		 * @param $pSeconds
		 */
		public static function SetClockDiff( $pSeconds ) {
			self::$_clock_diff = $pSeconds;
		}

		/**
		 * Find clock diff between current server to API server.
		 *
		 * @since 1.0.2
		 * @return int Clock diff in seconds.
		 */
		public static function FindClockDiff() {
			$time = time();
			$pong = self::Ping();

			return ( $time - strtotime( $pong->timestamp ) );
		}

		#endregion Servers Clock Diff ------------------------------------------------------

		/**
		 * @var string http or https
		 */
		private static $_protocol = FS_API__PROTOCOL;

		/**
		 * Set API connection protocol.
		 *
		 * @since 1.0.4
		 */
		public static function SetHttp() {
			self::$_protocol = 'http';
		}

		/**
		 * @since 1.0.4
		 *
		 * @return bool
		 */
		public static function IsHttps() {
			return ( 'https' === self::$_protocol );
		}

		/**
		 * Sign request with the following HTTP headers:
		 *      Content-MD5: MD5(HTTP Request body)
		 *      Date: Current date (i.e Sat, 14 Feb 2015 20:24:46 +0000)
		 *      Authorization: FS {scope_entity_id}:{scope_entity_public_key}:base64encode(sha256(string_to_sign,
		 *      {scope_entity_secret_key}))
		 *
		 * @param string $pResourceUrl
		 * @param array  $pCurlOptions
		 *
		 * @return array
		 */
		function SignRequest( $pResourceUrl, $pCurlOptions ) {
			$eol          = "\n";
			$content_md5  = '';
			$now          = ( time() - self::$_clock_diff );
			$date         = date( 'r', $now );
			$content_type = '';

			if ( isset( $pCurlOptions[ CURLOPT_POST ] ) && 0 < $pCurlOptions[ CURLOPT_POST ] ) {
				$content_md5                          = md5( $pCurlOptions[ CURLOPT_POSTFIELDS ] );
				$pCurlOptions[ CURLOPT_HTTPHEADER ][] = 'Content-MD5: ' . $content_md5;
				$content_type                         = 'application/json';
			}

			$pCurlOptions[ CURLOPT_HTTPHEADER ][] = 'Date: ' . $date;

			$string_to_sign = implode( $eol, array(
				$pCurlOptions[ CURLOPT_CUSTOMREQUEST ],
				$content_md5,
				$content_type,
				$date,
				$pResourceUrl
			) );

			// If secret and public keys are identical, it means that
			// the signature uses public key hash encoding.
			$auth_type = ( $this->_secret !== $this->_public ) ? 'FS' : 'FSP';

			// Add authorization header.
			$pCurlOptions[ CURLOPT_HTTPHEADER ][] = 'Authorization: ' .
			                                        $auth_type . ' ' .
			                                        $this->_id . ':' .
			                                        $this->_public . ':' .
			                                        self::Base64UrlEncode(
				                                        hash_hmac( 'sha256', $string_to_sign, $this->_secret )
			                                        );

			return $pCurlOptions;
		}

		/**
		 * Get API request URL signed via query string.
		 *
		 * @param string $pPath
		 *
		 * @throws Freemius_Exception
		 *
		 * @return string
		 */
		function GetSignedUrl( $pPath ) {
			$resource     = explode( '?', $this->CanonizePath( $pPath ) );
			$pResourceUrl = $resource[0];

			$eol          = "\n";
			$content_md5  = '';
			$content_type = '';
			$now          = ( time() - self::$_clock_diff );
			$date         = date( 'r', $now );

			$string_to_sign = implode( $eol, array(
				'GET',
				$content_md5,
				$content_type,
				$date,
				$pResourceUrl
			) );

			// If secret and public keys are identical, it means that
			// the signature uses public key hash encoding.
			$auth_type = ( $this->_secret !== $this->_public ) ? 'FS' : 'FSP';

			return Freemius_Api::GetUrl(
				$pResourceUrl . '?' .
				( 1 < count( $resource ) && ! empty( $resource[1] ) ? $resource[1] . '&' : '' ) .
				http_build_query( array(
					'auth_date'     => $date,
					'authorization' => $auth_type . ' ' . $this->_id . ':' .
					                   $this->_public . ':' .
					                   self::Base64UrlEncode( hash_hmac(
						                   'sha256', $string_to_sign, $this->_secret
					                   ) )
				) ), $this->_isSandbox );
		}

		/**
		 * @param resource $pCurlHandler
		 * @param array    $pCurlOptions
		 *
		 * @return mixed
		 */
		private static function ExecuteRequest( &$pCurlHandler, &$pCurlOptions ) {
			$start = microtime( true );

			$result = curl_exec( $pCurlHandler );

			if ( FS_API__LOGGER_ON ) {
				$end = microtime( true );

				$has_body = ( isset( $pCurlOptions[ CURLOPT_POST ] ) && 0 < $pCurlOptions[ CURLOPT_POST ] );

				self::$_logger[] = array(
					'id'        => count( self::$_logger ),
					'start'     => $start,
					'end'       => $end,
					'total'     => ( $end - $start ),
					'method'    => $pCurlOptions[ CURLOPT_CUSTOMREQUEST ],
					'path'      => $pCurlOptions[ CURLOPT_URL ],
					'body'      => $has_body ? $pCurlOptions[ CURLOPT_POSTFIELDS ] : null,
					'result'    => $result,
					'code'      => curl_getinfo( $pCurlHandler, CURLINFO_HTTP_CODE ),
					'backtrace' => debug_backtrace(),
				);
			}

			return $result;
		}

		/**
		 * @return array
		 */
		static function GetLogger() {
			return self::$_logger;
		}

		/**
		 * @param string        $pCanonizedPath
		 * @param string        $pMethod
		 * @param array         $pParams
		 * @param null|resource $pCurlHandler
		 * @param bool          $pIsSandbox
		 * @param null|callable $pBeforeExecutionFunction
		 *
		 * @return object[]|object|null
		 *
		 * @throws \Freemius_Exception
		 */
		private static function MakeStaticRequest(
			$pCanonizedPath,
			$pMethod = 'GET',
			$pParams = array(),
			$pCurlHandler = null,
			$pIsSandbox = false,
			$pBeforeExecutionFunction = null
		) {
			if ( ! FS_SDK__HAS_CURL ) {
				self::ThrowNoCurlException();
			}

			// Connectivity errors simulation.
			if ( FS_SDK__SIMULATE_NO_API_CONNECTIVITY_CLOUDFLARE ) {
				self::ThrowCloudFlareDDoSException();
			} else if ( FS_SDK__SIMULATE_NO_API_CONNECTIVITY_SQUID_ACL ) {
				self::ThrowSquidAclException();
			}

			if ( ! $pCurlHandler ) {
				$pCurlHandler = curl_init();
			}

			$opts = array(
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_USERAGENT      => FS_SDK__USER_AGENT,
				CURLOPT_HTTPHEADER     => array(),
			);

			if ( 'POST' === $pMethod || 'PUT' === $pMethod ) {
				if ( is_array( $pParams ) && 0 < count( $pParams ) ) {
					$opts[ CURLOPT_HTTPHEADER ][] = 'Content-Type: application/json';
					$opts[ CURLOPT_POST ]         = count( $pParams );
					$opts[ CURLOPT_POSTFIELDS ]   = json_encode( $pParams );
				}

				$opts[ CURLOPT_RETURNTRANSFER ] = true;
			}

			$request_url = self::GetUrl( $pCanonizedPath, $pIsSandbox );

			$opts[ CURLOPT_URL ]           = $request_url;
			$opts[ CURLOPT_CUSTOMREQUEST ] = $pMethod;

			$resource = explode( '?', $pCanonizedPath );

			// disable the 'Expect: 100-continue' behaviour. This causes CURL to wait
			// for 2 seconds if the server does not support this header.
			$opts[ CURLOPT_HTTPHEADER ][] = 'Expect:';

			if ( 'https' === substr( strtolower( $request_url ), 0, 5 ) ) {
				$opts[ CURLOPT_SSL_VERIFYHOST ] = false;
				$opts[ CURLOPT_SSL_VERIFYPEER ] = false;
			}

			if ( false !== $pBeforeExecutionFunction &&
			     is_callable( $pBeforeExecutionFunction )
			) {
				$opts = call_user_func( $pBeforeExecutionFunction, $resource[0], $opts );
			}

			curl_setopt_array( $pCurlHandler, $opts );
			$result = self::ExecuteRequest( $pCurlHandler, $opts );

			/*if (curl_errno($ch) == 60) // CURLE_SSL_CACERT
			{
				self::errorLog('Invalid or no certificate authority found, using bundled information');
				curl_setopt($ch, CURLOPT_CAINFO,
				dirname(__FILE__) . '/fb_ca_chain_bundle.crt');
				$result = curl_exec($ch);
			}*/

			// With dual stacked DNS responses, it's possible for a server to
			// have IPv6 enabled but not have IPv6 connectivity.  If this is
			// the case, curl will try IPv4 first and if that fails, then it will
			// fall back to IPv6 and the error EHOSTUNREACH is returned by the
			// operating system.
			if ( false === $result && empty( $opts[ CURLOPT_IPRESOLVE ] ) ) {
				$matches = array();
				$regex   = '/Failed to connect to ([^:].*): Network is unreachable/';
				if ( preg_match( $regex, curl_error( $pCurlHandler ), $matches ) ) {
					if ( strlen( @inet_pton( $matches[1] ) ) === 16 ) {
//						self::errorLog('Invalid IPv6 configuration on server, Please disable or get native IPv6 on your server.');
						$opts[ CURLOPT_IPRESOLVE ] = CURL_IPRESOLVE_V4;
						curl_setopt( $pCurlHandler, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
						$result = self::ExecuteRequest( $pCurlHandler, $opts );
					}
				}
			}

			if ( $result === false ) {
				self::ThrowCurlException( $pCurlHandler );
			}

			curl_close( $pCurlHandler );

			if ( empty( $result ) ) {
				return null;
			}

			$decoded = json_decode( $result );

			if ( is_null( $decoded ) ) {
				if ( preg_match( '/Please turn JavaScript on/i', $result ) &&
				     preg_match( '/text\/javascript/', $result )
				) {
					self::ThrowCloudFlareDDoSException( $result );
				} else if ( preg_match( '/Access control configuration prevents your request from being allowed at this time. Please contact your service provider if you feel this is incorrect./', $result ) &&
				            preg_match( '/squid/', $result )
				) {
					self::ThrowSquidAclException( $result );
				} else {
					$decoded = (object) array(
						'error' => (object) array(
							'type'    => 'Unknown',
							'message' => $result,
							'code'    => 'unknown',
							'http'    => 402
						)
					);
				}
			}

			return $decoded;
		}


		/**
		 * Makes an HTTP request. This method can be overridden by subclasses if
		 * developers want to do fancier things or use something other than curl to
		 * make the request.
		 *
		 * @param string        $pCanonizedPath The URL to make the request to
		 * @param string        $pMethod        HTTP method
		 * @param array         $pParams        The parameters to use for the POST body
		 * @param null|resource $pCurlHandler   Initialized curl handle
		 *
		 * @return object[]|object|null
		 *
		 * @throws Freemius_Exception
		 */
		public function MakeRequest(
			$pCanonizedPath,
			$pMethod = 'GET',
			$pParams = array(),
			$pCurlHandler = null
		) {
			$resource = explode( '?', $pCanonizedPath );

			// Only sign request if not ping.json connectivity test.
			$sign_request = ( '/v1/ping.json' !== strtolower( substr( $resource[0], - strlen( '/v1/ping.json' ) ) ) );

			return self::MakeStaticRequest(
				$pCanonizedPath,
				$pMethod,
				$pParams,
				$pCurlHandler,
				$this->_isSandbox,
				$sign_request ? array( &$this, 'SignRequest' ) : null
			);
		}

		#region Connectivity Test ------------------------------------------------------

		/**
		 * If successful connectivity to the API endpoint using ping.json endpoint.
		 *
		 *      - OR -
		 *
		 * Validate if ping result object is valid.
		 *
		 * @param mixed $pPong
		 *
		 * @return bool
		 */
		public static function Test( $pPong = null ) {
			$pong = is_null( $pPong ) ?
				self::Ping() :
				$pPong;

			return (
				is_object( $pong ) &&
				isset( $pong->api ) &&
				'pong' === $pong->api
			);
		}

		/**
		 * Ping API to test connectivity.
		 *
		 * @return object
		 */
		public static function Ping() {
			try {
				$result = self::MakeStaticRequest( '/v' . FS_API__VERSION . '/ping.json' );
			} catch ( Freemius_Exception $e ) {
				// Map to error object.
				$result = (object) $e->getResult();
			} catch ( Exception $e ) {
				// Map to error object.
				$result = (object) array(
					'error' => array(
						'type'    => 'Unknown',
						'message' => $e->getMessage() . ' (' . $e->getFile() . ': ' . $e->getLine() . ')',
						'code'    => 'unknown',
						'http'    => 402
					)
				);
			}

			return $result;
		}

		#endregion Connectivity Test ------------------------------------------------------

		#region Connectivity Exceptions ------------------------------------------------------

		/**
		 * @param resource $pCurlHandler
		 *
		 * @throws Freemius_Exception
		 */
		private static function ThrowCurlException( $pCurlHandler ) {
			$e = new Freemius_Exception( array(
				'error' => array(
					'code'    => curl_errno( $pCurlHandler ),
					'message' => curl_error( $pCurlHandler ),
					'type'    => 'CurlException',
				),
			) );

			curl_close( $pCurlHandler );
			throw $e;
		}

		/**
		 * @param string $pResult
		 *
		 * @throws Freemius_Exception
		 */
		private static function ThrowNoCurlException( $pResult = '' ) {
			throw new Freemius_Exception( array(
				'error' => (object) array(
					'type'    => 'cUrlMissing',
					'message' => $pResult,
					'code'    => 'curl_missing',
					'http'    => 402
				)
			) );
		}

		/**
		 * @param string $pResult
		 *
		 * @throws Freemius_Exception
		 */
		private static function ThrowCloudFlareDDoSException( $pResult = '' ) {
			throw new Freemius_Exception( array(
				'error' => (object) array(
					'type'    => 'CloudFlareDDoSProtection',
					'message' => $pResult,
					'code'    => 'cloudflare_ddos_protection',
					'http'    => 402
				)
			) );
		}

		/**
		 * @param string $pResult
		 *
		 * @throws Freemius_Exception
		 */
		private static function ThrowSquidAclException( $pResult = '' ) {
			throw new Freemius_Exception( array(
				'error' => (object) array(
					'type'    => 'SquidCacheBlock',
					'message' => $pResult,
					'code'    => 'squid_cache_block',
					'http'    => 402
				)
			) );
		}

		#endregion Connectivity Exceptions ------------------------------------------------------
	}