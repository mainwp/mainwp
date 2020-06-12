<?php
/**
 * MainWP Connect
 *
 * MainWP Connect functions.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Utility
 */
class MainWP_Connect {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method try_visit()
	 *
	 * Try connecting to Child Site via cURL.
	 *
	 * @param string            $url Child Site URL.
	 * @param boolean true|null $verifyCertificate Option to check SSL Certificate. Default = null.
	 * @param string            $http_user HTTPAuth Username. Default = null.
	 * @param string            $http_pass HTTPAuth Password. Default = null.
	 * @param integer           $sslVersion Child Site SSL Version.
	 * @param boolean true|null $forceUseIPv4 Option to fource IP4. Default = null.
	 *
	 * @return array $out. 'host IP, Returned HTTP Code, Error Message, http Status error message.
	 */
	public static function try_visit( $url, $verifyCertificate = null, $http_user = null, $http_pass = null, $sslVersion = 0, $forceUseIPv4 = null ) { // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$agent    = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		$postdata = array( 'test' => 'yes' );

		$ch = curl_init();

		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
			curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
			curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

			if ( $proxy->use_authentication() ) {
				curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
				curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
			}
		}

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		curl_setopt( $ch, CURLOPT_ENCODING, 'none' );

		if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
			$http_pass = stripslashes( $http_pass );
			curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
		}

		$ssl_verifyhost = false;
		if ( null !== $verifyCertificate ) {
			if ( 1 === $verifyCertificate ) {
				$ssl_verifyhost = true;
			} elseif ( 2 === $verifyCertificate ) {
				if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 == get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
					$ssl_verifyhost = true;
				}
			}
		} else {
			if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 == get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
				$ssl_verifyhost = true;
			}
		}

		if ( $ssl_verifyhost ) {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		} else {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		}

		curl_setopt( $ch, CURLOPT_SSLVERSION, $sslVersion );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'X-Requested-With: XMLHttpRequest' ) );
		curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );

		$force_use_ipv4 = false;
		if ( null !== $forceUseIPv4 ) {
			if ( 1 === $forceUseIPv4 ) {
				$force_use_ipv4 = true;
			} elseif ( 2 === $forceUseIPv4 ) {
				if ( 1 === get_option( 'mainwp_forceUseIPv4' ) ) {
					$force_use_ipv4 = true;
				}
			}
		} else {
			if ( 1 === get_option( 'mainwp_forceUseIPv4' ) ) {
				$force_use_ipv4 = true;
			}
		}

		if ( $force_use_ipv4 ) {
			if ( defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
				curl_setopt( $ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
			}
		}

		$disabled_functions = ini_get( 'disable_functions' );
		if ( empty( $disabled_functions ) || ( stristr( $disabled_functions, 'curl_multi_exec' ) === false ) ) {
			$mh = curl_multi_init();
			@curl_multi_add_handle( $mh, $ch );

			do {
				curl_multi_exec( $mh, $running );
				while ( $info = curl_multi_info_read( $mh ) ) {
					$data        = curl_multi_getcontent( $info['handle'] );
					$err         = curl_error( $info['handle'] );
					$http_status = curl_getinfo( $info['handle'], CURLINFO_HTTP_CODE );
					$errno       = curl_errno( $info['handle'] );
					$realurl     = curl_getinfo( $info['handle'], CURLINFO_EFFECTIVE_URL );

					curl_multi_remove_handle( $mh, $info['handle'] );
				}
				usleep( 10000 );
			} while ( $running > 0 );

			curl_multi_close( $mh );
		} else {
			$data        = curl_exec( $ch );
			$err         = curl_error( $ch );
			$http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$errno       = curl_errno( $ch );
			$realurl     = curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
			curl_close( $ch );
		}

		MainWP_Logger::instance()->debug( ' :: tryVisit :: [url=' . $url . '] [http_status=' . $http_status . '] [error=' . $err . '] [data=' . $data . ']' );

		$host   = wp_parse_url( ( empty( $realurl ) ? $url : $realurl ), PHP_URL_HOST );
		$ip     = false;
		$target = false;

		$dnsRecord = @dns_get_record( $host );
		MainWP_Logger::instance()->debug( ' :: tryVisit :: [dnsRecord=' . MainWP_Utility::value_to_string( $dnsRecord, 1 ) . ']' );
		if ( false === $dnsRecord ) {
			$data = false;
		} elseif ( is_array( $dnsRecord ) ) {
			if ( ! isset( $dnsRecord['ip'] ) ) {
				foreach ( $dnsRecord as $dnsRec ) {
					if ( isset( $dnsRec['ip'] ) ) {
						$ip = $dnsRec['ip'];
						break;
					}
				}
			} else {
				$ip = $dnsRecord['ip'];
			}

			$found = false;
			if ( ! isset( $dnsRecord['host'] ) ) {
				foreach ( $dnsRecord as $dnsRec ) {
					if ( $dnsRec['host'] == $host ) {
						if ( 'CNAME' === $dnsRec['type'] ) {
							$target = $dnsRec['target'];
						}
						$found = true;
						break;
					}
				}
			} else {
				$found = ( $dnsRecord['host'] == $host );
				if ( 'CNAME' === $dnsRecord['type'] ) {
					$target = $dnsRecord['target'];
				}
			}

			if ( ! $found ) {
				$data = false;
			}
		}

		if ( false === $ip ) {
			$ip = gethostbynamel( $host );
		}
		if ( ( false !== $target ) && ( $target != $host ) ) {
			$host .= ' (CNAME: ' . $target . ')';
		}

		$out = array(
			'host'           => $host,
			'httpCode'       => $http_status,
			'error'          => ( '' == $err && false === $data ? 'Invalid host.' : $err ),
			'httpCodeString' => self::get_http_status_error_string( $http_status ),
		);
		if ( false !== $ip ) {
			$out['ip'] = $ip;
		}

		return $out;
	}

	/**
	 * Method get_http_status_error_string()
	 *
	 * Grab HTTP Error code 100 - 505 & convert to String representation of error.
	 *
	 * @param int $httpCode Returned HTTP Code from cURL.
	 *
	 * @return mixed null|Error String.
	 */
	protected static function get_http_status_error_string( $httpCode ) {

		$codeString = array(
			100 => 'Continue',
			101 => 'Switching Protocols',
			200 => 'OK',
			201 => 'Created',
			202 => 'Accepted',
			203 => 'Non-Authoritative Information',
			204 => 'No Content',
			205 => 'Reset Content',
			206 => 'Partial Content',

			300 => 'Multiple Choices',
			301 => 'Moved Permanently',
			302 => 'Found',
			303 => 'See Other',
			304 => 'Not Modified',
			305 => 'Use Proxy',
			306 => '(Unused)',
			307 => 'Temporary Redirect',

			400 => 'Bad Request',
			401 => 'Unauthorized',
			402 => 'Payment Required',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			406 => 'Not Acceptable',
			407 => 'Proxy Authentication Required',
			408 => 'Request Timeout',
			409 => 'Conflict',
			410 => 'Gone',
			411 => 'Length Required',
			412 => 'Precondition Failed',
			413 => 'Request Entity Too Large',
			414 => 'Request-URI Too Long',
			415 => 'Unsupported Media Type',
			416 => 'Requested Range Not Satisfiable',
			417 => 'Expectation Failed',

			500 => 'Internal Server Error',
			501 => 'Not Implemented',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
			505 => 'HTTP Version Not Supported',
		);

		return isset( $codeString[ $httpCode ] ) ? $codeString[ $httpCode ] : null;
	}

	/**
	 * Method check_ignored_http_code()
	 *
	 * Check if http error code is being ignored.
	 *
	 * @param mixed $value http error code.
	 *
	 * @return bolean True|False.
	 */
	public static function check_ignored_http_code( $value ) {
		if ( 200 === $value ) {
			return true;
		}

		$ignored_code = get_option( 'mainwp_ignore_HTTP_response_status', '' );
		$ignored_code = trim( $ignored_code );
		if ( ! empty( $ignored_code ) ) {
			$ignored_code = explode( ',', $ignored_code );
			foreach ( $ignored_code as $code ) {
				$code = trim( $code );
				if ( $value == $code ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Method is_website_available()
	 *
	 * Check if the Website returns and http errors.
	 *
	 * @param array $website Child Site information.
	 *
	 * @return mixed False|try_visit().
	 */
	public static function is_website_available( $website ) {
		$http_user         = null;
		$http_pass         = null;
		$sslVersion        = null;
		$verifyCertificate = null;
		$forceUseIPv4      = null;
		if ( is_object( $website ) && isset( $website->url ) ) {
			$url               = $website->url;
			$verifyCertificate = isset( $website->verify_certificate ) ? $website->verify_certificate : null;
			$forceUseIPv4      = $website->force_use_ipv4;
			$http_user         = $website->http_user;
			$http_pass         = $website->http_pass;
			$sslVersion        = $website->ssl_version;
		} else {
			$url = $website;
		}

		if ( ! MainWP_Utility::is_domain_valid( $url ) ) {
			return false;
		}

		return self::try_visit( $url, $verifyCertificate, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );
	}

	/**
	 * Method get_post_data_authed()
	 *
	 * Get authorized $_POST data & build query.
	 *
	 * @param mixed $website Array of Child Site Info.
	 * @param mixed $what What we are posting.
	 * @param null  $params Post parameters.
	 *
	 * @return mixed null|http_build_query()
	 */
	public static function get_post_data_authed( &$website, $what, $params = null ) {
		if ( $website && '' != $what ) {
			$data             = array();
			$data['user']     = $website->adminname;
			$data['function'] = $what;
			$data['nonce']    = wp_rand( 0, 9999 );
			if ( null != $params ) {
				$data = array_merge( $data, $params );
			}

			if ( ( 0 == $website->nossl ) && function_exists( 'openssl_verify' ) ) {
				$data['nossl'] = 0;
				openssl_sign( $what . $data['nonce'], $signature, base64_decode( $website->privkey ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			} else {
				$data['nossl'] = 1;
				$signature     = md5( $what . $data['nonce'] . $website->nosslkey );
			}
			$data['mainwpsignature'] = base64_encode( $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			$recent_number = apply_filters( 'mainwp_recent_posts_pages_number', 5 );
			if ( 5 !== $recent_number ) {
				$data['recent_number'] = $recent_number;
			}

			global $current_user;

			if ( ( ! defined( 'DOING_CRON' ) || false === DOING_CRON ) && ( ! defined( 'WP_CLI' ) || false === WP_CLI ) ) {
				if ( is_object( $current_user ) && property_exists( $current_user, 'ID' ) && $current_user->ID ) {
					$alter_user = apply_filters( 'mainwp_alter_login_user', false, $website->id, $current_user->ID );
					if ( ! empty( $alter_user ) ) {
						$data['alt_user'] = rawurlencode( $alter_user );
					}
				}
			}

			return http_build_query( $data, '', '&' );
		}

		return null;
	}

	/**
	 * Method get_get_data_authed()
	 *
	 * Get authorized $_GET data & build query.
	 *
	 * @param mixed   $website Child Site data.
	 * @param mixed   $paramValue OpenSSL parameter.
	 * @param string  $paramName Parameter name.
	 * @param boolean $asArray true|false Default is false.
	 *
	 * @return mixed $url
	 */
	public static function get_get_data_authed( $website, $paramValue, $paramName = 'where', $asArray = false ) {
		$params = array();
		if ( $website && '' != $paramValue ) {
			$nonce = wp_rand( 0, 9999 );
			if ( ( 0 == $website->nossl ) && function_exists( 'openssl_verify' ) ) {
				$nossl = 0;
				openssl_sign( $paramValue . $nonce, $signature, base64_decode( $website->privkey ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			} else {
				$nossl     = 1;
				$signature = md5( $paramValue . $nonce . $website->nosslkey );
			}
			$signature = base64_encode( $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			$params = array(
				'login_required'     => 1,
				'user'               => rawurlencode( $website->adminname ),
				'mainwpsignature'    => rawurlencode( $signature ),
				'nonce'              => $nonce,
				'nossl'              => $nossl,
				$paramName           => rawurlencode( $paramValue ),
			);

			global $current_user;
			if ( ( ! defined( 'DOING_CRON' ) || false === DOING_CRON ) && ( ! defined( 'WP_CLI' ) || false === WP_CLI ) ) {
				if ( $current_user && $current_user->ID ) {
					$alter_user = apply_filters( 'mainwp_alter_login_user', false, $website->id, $current_user->ID );
					if ( ! empty( $alter_user ) ) {
						$params['alt_user'] = rawurlencode( $alter_user );
					}
				}
			}
		}

		if ( $asArray ) {
			return $params;
		}

		$url  = ( isset( $website->url ) && '' != $website->url ? $website->url : $website->siteurl );
		$url .= ( substr( $url, - 1 ) != '/' ? '/' : '' );
		$url .= '?';

		foreach ( $params as $key => $value ) {
			$url .= $key . '=' . $value . '&';
		}

		return rtrim( $url, '&' );
	}

	/**
	 * Method get_post_data_not_authed()
	 *
	 * Get not authorized $_POST data.
	 *
	 * @param mixed $url Child site URL.
	 * @param mixed $admin Admin Username.
	 * @param mixed $what What function to perform.
	 * @param null  $params Function parameters.
	 *
	 * @return mixed null|http_build_query()
	 */
	public static function get_post_data_not_authed( $url, $admin, $what, $params = null ) {
		if ( '' != $url && '' != $admin && '' != $what ) {
			$data             = array();
			$data['user']     = $admin;
			$data['function'] = $what;
			if ( null != $params ) {
				$data = array_merge( $data, $params );
			}

			return http_build_query( $data, '', '&' );
		}

		return null;
	}

	/**
	 * Method fetch_urls_authed()
	 *
	 * Fethech authorized URLs.
	 *
	 * @param object $websites Websites information.
	 * @param string $what Action to perorm.
	 * @param array  $params Request parameters.
	 * @param mixed  $handler Request handler.
	 * @param mixed  $output Request output.
	 * @param mixed  $whatPage Request URL. Default /admin-ajax.php.
	 * @param array  $others Request additional information.
	 * @param bool   $is_external_hook Check if external hook is used.
	 *
	 * @return bool true|false
	 */
	public static function fetch_urls_authed( &$websites, $what, $params = null, $handler, &$output, $whatPage = null, $others = array(), $is_external_hook = false ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		if ( ! is_array( $websites ) || empty( $websites ) ) {
			return false;
		}

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$chunkSize = 10;
		if ( count( $websites ) > $chunkSize ) {
			$total = count( $websites );
			$loops = ceil( $total / $chunkSize );
			for ( $i = 0; $i < $loops; $i ++ ) {
				$newSites = array_slice( $websites, $i * $chunkSize, $chunkSize, true );
				self::fetch_urls_authed( $newSites, $what, $params, $handler, $output, $whatPage, $others, $is_external_hook );
				sleep( 5 );
			}

			return false;
		}

		if ( $is_external_hook ) {
			$json_format = apply_filters( 'mainwp_response_json_format', false );
		} else {
			$json_format = true;
		}

		$debug = false;
		if ( $debug ) {
			self::debug_fetch_urls_authed( $websites, $what, $params, $handler, $output, $whatPage, $json_format, $others );
			return;
		}

		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		$mh    = curl_multi_init();

		$timeout = 20 * 60 * 60;

		$disabled_functions = ini_get( 'disable_functions' );
		$handleToWebsite    = array();
		$requestUrls        = array();
		$requestHandles     = array();

		$dirs      = MainWP_System_Utility::get_mainwp_dir();
		$cookieDir = $dirs[0] . 'cookies';

		self::init_cookiesdir( $cookieDir );

		foreach ( $websites as $website ) {
			$url = $website->url;
			if ( '/' != substr( $url, - 1 ) ) {
				$url .= '/';
			}

			if ( false === strpos( $url, 'wp-admin' ) ) {
				$url .= 'wp-admin/';
			}

			if ( null != $whatPage ) {
				$url .= $whatPage;
			} else {
				$url .= 'admin-ajax.php';
			}

			if ( property_exists( $website, 'http_user' ) ) {
				$http_user = $website->http_user;
			}
			if ( property_exists( $website, 'http_pass' ) ) {
				$http_pass = $website->http_pass;
			}

			$_new_post = null;
			if ( isset( $params ) && isset( $params['new_post'] ) ) {
				$_new_post = $params['new_post'];

				/**
				 * Filter is being replaced with mainwp_pre_posting_posts.
				 *
				 * @deprecated
				 */
				$params = apply_filters_deprecated(
					'mainwp-pre-posting-posts',
					array(
						( is_array( $params ) ? $params : array() ),
						(object) array(
							'id'     => $website->id,
							'url'    => $website->url,
							'name'   => $website->name,
						),
					),
					'4.0.7.2',
					'mainwp_pre_posting_posts'
				);

				$params = apply_filters(
					'mainwp_pre_posting_posts',
					( is_array( $params ) ? $params : array() ),
					(object) array(
						'id'     => $website->id,
						'url'    => $website->url,
						'name'   => $website->name,
					)
				);
			}

			$ch = curl_init();

			$proxy = new \WP_HTTP_Proxy();
			if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
				curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
				curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
				curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

				if ( $proxy->use_authentication() ) {
					curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
					curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
				}
			}

			if ( ( null != $website ) && ( ( property_exists( $website, 'wpe' ) && 1 !== $website->wpe ) || ( isset( $others['upgrade'] ) && ( true === $others['upgrade'] ) ) ) ) {
				$cookieFile = $cookieDir . '/' . sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' );
				if ( ! file_exists( $cookieFile ) ) {
					@file_put_contents( $cookieFile, '' );
				}

				if ( file_exists( $cookieFile ) ) {
					@chmod( $cookieFile, 0644 );
					curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
					curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
				}
			}

			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_POST, true );

			if ( is_array( $params ) ) {
				$params['json_result'] = $json_format;
			}

			$postdata = self::get_post_data_authed( $website, $what, $params );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
			curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
			curl_setopt( $ch, CURLOPT_ENCODING, 'none' );
			if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
				$http_pass = stripslashes( $http_pass );
				curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
			}

			$ssl_verifyhost    = false;
			$verifyCertificate = isset( $website->verify_certificate ) ? $website->verify_certificate : null;
			if ( null !== $verifyCertificate ) {
				if ( 1 === $verifyCertificate ) {
					$ssl_verifyhost = true;
				} elseif ( 2 == $verifyCertificate ) {
					if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 == get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
						$ssl_verifyhost = true;
					}
				}
			} else {
				if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 == get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
					$ssl_verifyhost = true;
				}
			}

			if ( $ssl_verifyhost ) {
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
			} else {
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			}

			curl_setopt( $ch, CURLOPT_SSLVERSION, $website->ssl_version );

			curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
			set_time_limit( $timeout );

			if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
				@curl_multi_add_handle( $mh, $ch );
			}

			$handleToWebsite[ self::get_resource_id( $ch ) ] = $website;
			$requestUrls[ self::get_resource_id( $ch ) ]     = $website->url;
			$requestHandles[ self::get_resource_id( $ch ) ]  = $ch;

			if ( null != $_new_post ) {
				$params['new_post'] = $_new_post;
			}
		}

		if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
			$lastRun = 0;
			do {
				if ( 20 < time() - $lastRun ) {
					@set_time_limit( $timeout );
					$lastRun = time();
				}

				curl_multi_exec( $mh, $running );
				while ( $info = curl_multi_info_read( $mh ) ) {
					$data     = curl_multi_getcontent( $info['handle'] );
					$contains = ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) );
					curl_multi_remove_handle( $mh, $info['handle'] );

					if ( ! $contains && isset( $requestUrls[ self::get_resource_id( $info['handle'] ) ] ) ) {
						curl_setopt( $info['handle'], CURLOPT_URL, $requestUrls[ self::get_resource_id( $info['handle'] ) ] );
						curl_multi_add_handle( $mh, $info['handle'] );
						unset( $requestUrls[ self::get_resource_id( $info['handle'] ) ] );
						$running ++;
						continue;
					}

					if ( null != $handler ) {
						$site = &$handleToWebsite[ self::get_resource_id( $info['handle'] ) ];
						call_user_func_array( $handler, array( $data, $site, &$output ) );
					}

					unset( $handleToWebsite[ self::get_resource_id( $info['handle'] ) ] );
					if ( 'resource' === gettype( $info['handle'] ) ) {
						curl_close( $info['handle'] );
					}
					unset( $info['handle'] );
				}
				usleep( 10000 );
			} while ( $running > 0 );

			curl_multi_close( $mh );
		} else {
			foreach ( $requestHandles as $id => $ch ) {
				$data = curl_exec( $ch );

				if ( null != $handler ) {
					$site = &$handleToWebsite[ self::get_resource_id( $ch ) ];
					call_user_func_array( $handler, array( $data, $site, &$output ) );
				}
			}
		}

		return true;
	}

	/**
	 * Method debug_fetch_urls_authed()
	 *
	 * To debug fetch authorized URLs.
	 *
	 * @param object $websites Websites information.
	 * @param string $what Action to perorm.
	 * @param array  $params Request parameters.
	 * @param mixed  $handler Request handler.
	 * @param mixed  $output Request output.
	 * @param mixed  $whatPage Request URL. Default /admin-ajax.php.
	 * @param bool   $json_format Use JSON format.
	 * @param array  $others Request additional information.
	 */
	private static function debug_fetch_urls_authed( $websites, $what, $params, $handler, $output, $whatPage, $json_format, $others ) { // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';

		$timeout = 20 * 60 * 60;

		$handleToWebsite = array();
		$requestUrls     = array();
		$requestHandles  = array();

		$dirs      = MainWP_System_Utility::get_mainwp_dir();
		$cookieDir = $dirs[0] . 'cookies';

		self::init_cookiesdir( $cookieDir );

		foreach ( $websites as $website ) {
			$url = $website->url;
			if ( '/' != substr( $url, - 1 ) ) {
				$url .= '/';
			}

			if ( false === strpos( $url, 'wp-admin' ) ) {
				$url .= 'wp-admin/';
			}

			if ( null != $whatPage ) {
				$url .= $whatPage;
			} else {
				$url .= 'admin-ajax.php';
			}

			if ( property_exists( $website, 'http_user' ) ) {
				$http_user = $website->http_user;
			}
			if ( property_exists( $website, 'http_pass' ) ) {
				$http_pass = $website->http_pass;
			}

			$_new_post = null;
			if ( isset( $params ) && isset( $params['new_post'] ) ) {
				$_new_post = $params['new_post'];

				/**
				 * Filter is being replaced with mainwp_pre_posting_posts.
				 *
				 * @deprecated
				 */
				$params = apply_filters_deprecated(
					'mainwp-pre-posting-posts',
					array(
						( is_array( $params ) ? $params : array() ),
						(object) array(
							'id'     => $website->id,
							'url'    => $website->url,
							'name'   => $website->name,
						),
					),
					'4.0.7.2',
					'mainwp_pre_posting_posts'
				);

				$params = apply_filters(
					'mainwp_pre_posting_posts',
					( is_array( $params ) ? $params : array() ),
					(object) array(
						'id'     => $website->id,
						'url'    => $website->url,
						'name'   => $website->name,
					)
				);
			}

			$ch = curl_init();

			$proxy = new \WP_HTTP_Proxy();
			if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
				curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
				curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
				curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

				if ( $proxy->use_authentication() ) {
					curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
					curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
				}
			}

			if ( ( null != $website ) && ( ( property_exists( $website, 'wpe' ) && 1 !== $website->wpe ) || ( isset( $others['upgrade'] ) && ( true === $others['upgrade'] ) ) ) ) {
				$cookieFile = $cookieDir . '/' . sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' );
				if ( ! file_exists( $cookieFile ) ) {
					@file_put_contents( $cookieFile, '' );
				}

				if ( file_exists( $cookieFile ) ) {
					@chmod( $cookieFile, 0644 );
					curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
					curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
				}
			}

			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_POST, true );

			$params['json_result'] = $json_format;

			$postdata = self::get_post_data_authed( $website, $what, $params );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
			curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
			curl_setopt( $ch, CURLOPT_ENCODING, 'none' );
			if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
				$http_pass = stripslashes( $http_pass );
				curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
			}

			$ssl_verifyhost    = false;
			$verifyCertificate = isset( $website->verify_certificate ) ? $website->verify_certificate : null;
			if ( null !== $verifyCertificate ) {
				if ( 1 == $verifyCertificate ) {
					$ssl_verifyhost = true;
				} elseif ( 2 == $verifyCertificate ) {
					if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 == get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
						$ssl_verifyhost = true;
					}
				}
			} else {
				if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 == get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
					$ssl_verifyhost = true;
				}
			}

			if ( $ssl_verifyhost ) {
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
			} else {
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			}

			curl_setopt( $ch, CURLOPT_SSLVERSION, $website->ssl_version );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'X-Requested-With: XMLHttpRequest' ) );
			curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );

			$force_use_ipv4 = false;
			$forceUseIPv4   = isset( $website->force_use_ipv4 ) ? $website->force_use_ipv4 : null;
			if ( null !== $forceUseIPv4 ) {
				if ( 1 === $forceUseIPv4 ) {
					$force_use_ipv4 = true;
				} elseif ( 2 === $forceUseIPv4 ) {
					if ( 1 === get_option( 'mainwp_forceUseIPv4' ) ) {
						$force_use_ipv4 = true;
					}
				}
			} else {
				if ( 1 === get_option( 'mainwp_forceUseIPv4' ) ) {
					$force_use_ipv4 = true;
				}
			}

			if ( $force_use_ipv4 ) {
				if ( defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
					curl_setopt( $ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
				}
			}

			curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
			set_time_limit( $timeout );

			$handleToWebsite[ self::get_resource_id( $ch ) ] = $website;
			$requestUrls[ self::get_resource_id( $ch ) ]     = $website->url;
			$requestHandles[ self::get_resource_id( $ch ) ]  = $ch;

			if ( null != $_new_post ) {
				$params['new_post'] = $_new_post;
			}
		}

		foreach ( $requestHandles as $id => $ch ) {
			$website = &$handleToWebsite[ self::get_resource_id( $ch ) ];

			$identifier   = null;
			$semLock      = '103218';
			$identifier   = self::get_lock_identifier( $semLock );
			$minimumDelay = ( ( false === get_option( 'mainwp_minimumDelay' ) ) ? 200 : get_option( 'mainwp_minimumDelay' ) );
			if ( 0 < $minimumDelay ) {
				$minimumDelay = $minimumDelay / 1000;
			}
			$minimumIPDelay = ( ( false === get_option( 'mainwp_minimumIPDelay' ) ) ? 400 : get_option( 'mainwp_minimumIPDelay' ) );
			if ( 0 < $minimumIPDelay ) {
				$minimumIPDelay = $minimumIPDelay / 1000;
			}

			MainWP_Utility::end_session();
			$delay = true;
			while ( $delay ) {
				self::lock( $identifier );

				if ( 0 < $minimumDelay ) {
					$lastRequest = MainWP_DB_Common::instance()->get_last_request_timestamp();
					if ( $lastRequest > ( ( microtime( true ) ) - $minimumDelay ) ) {
						self::release( $identifier );
						usleep( ( $minimumDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000 );
						continue;
					}
				}

				if ( 0 < $minimumIPDelay && null != $website ) {
					$ip = MainWP_DB::instance()->get_wp_ip( $website->id );

					if ( null != $ip && '' != $ip ) {
						$lastRequest = MainWP_DB_Common::instance()->get_last_request_timestamp( $ip );

						if ( $lastRequest > ( ( microtime( true ) ) - $minimumIPDelay ) ) {
							self::release( $identifier );
							usleep( ( $minimumIPDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000 );
							continue;
						}
					}
				}

				$delay = false;
			}

			$maximumRequests   = ( ( false === get_option( 'mainwp_maximumRequests' ) ) ? 4 : get_option( 'mainwp_maximumRequests' ) );
			$maximumIPRequests = ( ( false === get_option( 'mainwp_maximumIPRequests' ) ) ? 1 : get_option( 'mainwp_maximumIPRequests' ) );

			$first = true;
			$delay = true;
			while ( $delay ) {
				if ( ! $first ) {
					self::lock( $identifier );
				} else {
					$first = false;
				}

				MainWP_DB_Common::instance()->close_open_requests();

				if ( 0 < $maximumRequests ) {
					$nrOfOpenRequests = MainWP_DB_Common::instance()->get_nrof_open_requests();
					if ( $nrOfOpenRequests >= $maximumRequests ) {
						self::release( $identifier );
						usleep( 200000 );
						continue;
					}
				}

				if ( 0 < $maximumIPRequests && null != $website ) {
					$ip = MainWP_DB::instance()->get_wp_ip( $website->id );

					if ( null != $ip && '' != $ip ) {
						$nrOfOpenRequests = MainWP_DB_Common::instance()->get_nrof_open_requests( $ip );
						if ( $nrOfOpenRequests >= $maximumIPRequests ) {
							self::release( $identifier );
							usleep( 200000 );
							continue;
						}
					}
				}

				$delay = false;
			}

			if ( null != $website ) {
				MainWP_DB_Common::instance()->insert_or_update_request_log( $website->id, null, microtime( true ), null );
			}

			if ( null != $identifier ) {
				self::release( $identifier );
			}

			$data = curl_exec( $ch );

			if ( null != $website ) {
				MainWP_DB_Common::instance()->insert_or_update_request_log( $website->id, $ip, null, microtime( true ) );
			}

			if ( null != $handler ) {
				call_user_func_array( $handler, array( $data, $website, &$output ) );
			}
		}
	}

	/**
	 * Method get_resource_id()
	 *
	 * Get resource id.
	 *
	 * @param mixed $resource The given resource.
	 *
	 * @return $result Resource ID only.
	 */
	public static function get_resource_id( $resource ) {
		if ( ! is_resource( $resource ) ) {
			return false;
		}

		$resourceString = (string) $resource;
		$exploded       = explode( '#', $resourceString );
		$result         = array_pop( $exploded );

		return $result;
	}

	/**
	 * Method get_lock_identifier(
	 *
	 * Get lock identifier.
	 *
	 * @param mixed $pLockName Provided Lock Name.
	 *
	 * @return mixed false|sem_get()|@fopen
	 */
	public static function get_lock_identifier( $pLockName ) {
		if ( ( null == $pLockName ) || ( false == $pLockName ) ) {
			return false;
		}

		if ( function_exists( 'sem_get' ) ) {
			return sem_get( $pLockName );
		} else {
			$fh = @fopen( sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lock' . $pLockName . '.txt', 'w+' );
			if ( ! $fh ) {
				return false;
			}

			return $fh;
		}

		return false;
	}

	/**
	 * Method lock()
	 *
	 * Use sem_acquire or @flock to lock the $identifier.
	 *
	 * @param mixed $identifier Identifier.
	 *
	 * @return mixed false|sem_acquire()|@flock
	 */
	public static function lock( $identifier ) {
		if ( ( null == $identifier ) || ( false == $identifier ) ) {
			return false;
		}

		if ( function_exists( 'sem_acquire' ) ) {
			return sem_acquire( $identifier );
		} else {
			for ( $i = 0; $i < 3; $i ++ ) {
				if ( @flock( $identifier, LOCK_EX ) ) {
					return $identifier;
				} else {
					sleep( 1 );
				}
			}

			return false;
		}

		return false;
	}

	/**
	 * Method release()
	 *
	 * Use sem_release or @flock, @fclose to unlock $identifier.
	 *
	 * @param mixed $identifier Identifier.
	 *
	 * @return mixed false|sem_release()|@flock
	 */
	public static function release( $identifier ) {
		if ( ( null == $identifier ) || ( false == $identifier ) ) {
			return false;
		}

		if ( function_exists( 'sem_release' ) ) {
			return sem_release( $identifier );
		} else {
			@flock( $identifier, LOCK_UN );
			@fclose( $identifier );
		}

		return false;
	}

	/**
	 * Method fetch_url_authed()
	 *
	 * @param object  $website Website information.
	 * @param string  $what Function to perform.
	 * @param null    $params Function paramerters.
	 * @param boolean $checkConstraints true|false Whether or not to check contraints.
	 * @param boolean $pForceFetch true|false Whether or not to force the fetch.
	 * @param boolean $pRetryFailed true|false Whether or not to retry the fetch process.
	 * @param null    $rawResponse Raw response.
	 *
	 * @return mixed $information
	 */
	public static function fetch_url_authed(
		&$website,
		$what,
		$params = null,
		$checkConstraints = false,
		$pForceFetch = false,
		$pRetryFailed = true,
		$rawResponse = null ) {

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$others = array(
			'force_use_ipv4' => $website->force_use_ipv4,
			'upgrade'        => ( 'upgradeplugintheme' === $what || 'upgrade' === $what || 'upgradetranslation' === $what ),
		);

		$request_update = MainWP_Premium_Update::maybe_request_premium_updates( $website, $what, $params );

		if ( isset( $rawResponse ) && $rawResponse ) {
			$others['raw_response'] = 'yes';
		}

		$params['optimize'] = ( ( 1 == get_option( 'mainwp_optimize' ) ) ? 1 : 0 );

		$updating_website = false;
		$type             = '';
		$list             = '';
		if ( 'upgradeplugintheme' === $what || 'upgrade' === $what || 'upgradetranslation' === $what ) {
			$updating_website = true;
			if ( 'upgradeplugintheme' === $what || 'upgradetranslation' === $what ) {
				$type = $params['type'];
				$list = $params['list'];
			} else {
				$type = 'wp';
				$list = '';
			}
		}

		if ( $updating_website ) {
			do_action( 'mainwp_website_before_updated', $website, $type, $list );
		}

		$params['json_result'] = true;
		$postdata              = self::get_post_data_authed( $website, $what, $params );
		$others['function']    = $what;

		$information = array();

		if ( ! $request_update ) {
			$information = self::fetch_url( $website, $website->url, $postdata, $checkConstraints, $website->verify_certificate, $pRetryFailed, $website->http_user, $website->http_pass, $website->ssl_version, $others );
		} else {
			$slug                    = $params['list'];
			$information['upgrades'] = array( $slug => 1 );
		}

		if ( is_array( $information ) && isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
			MainWP_Sync::sync_information_array( $website, $information['sync'] );
			unset( $information['sync'] );
		}

		if ( $updating_website ) {
			do_action( 'mainwp_website_updated', $website, $type, $list, $information );
			if ( 1 === get_option( 'mainwp_check_http_response', 0 ) ) {
				$result          = self::is_website_available( $website );
				$http_code       = ( is_array( $result ) && isset( $result['httpCode'] ) ) ? $result['httpCode'] : 0;
				$online_detected = self::check_ignored_http_code( $http_code );
				MainWP_DB::instance()->update_website_values(
					$website->id,
					array(
						'offline_check_result' => $online_detected ? 1 : -1,
						'offline_checks_last'  => time(),
						'http_response_code'   => $http_code,
					)
				);

				if ( defined( 'DOING_CRON' ) && DOING_CRON && ! $online_detected ) {
					$sitesHttpChecks = get_option( 'mainwp_automaticUpdate_httpChecks' );
					if ( ! is_array( $sitesHttpChecks ) ) {
						$sitesHttpChecks = array();
					}

					if ( ! in_array( $website->id, $sitesHttpChecks ) ) {
						$sitesHttpChecks[] = $website->id;
						MainWP_Utility::update_option( 'mainwp_automaticUpdate_httpChecks', $sitesHttpChecks );
					}
				}
			}
		}

		return $information;
	}

	/**
	 * Method fetch_url_not_authed()
	 *
	 * Fetch not authorized URL.
	 *
	 * @param string  $url URL to fetch from.
	 * @param string  $admin Admin name.
	 * @param string  $what Function to perform.
	 * @param null    $params Function paramerters.
	 * @param boolean $pForceFetch true|false Whether or not to force the fetch.
	 * @param null    $verifyCertificate Verify the SSL Certificate.
	 * @param null    $http_user htaccess username.
	 * @param null    $http_pass htaccess password.
	 * @param integer $sslVersion SSL version to check for.
	 * @param array   $others Other functions to perform.
	 *
	 * @return mixed self::fetch_url() Fetch URL.
	 */
	public static function fetch_url_not_authed(
		$url,
		$admin,
		$what,
		$params = null,
		$pForceFetch = false,
		$verifyCertificate = null,
		$http_user = null,
		$http_pass = null,
		$sslVersion = 0,
		$others = array() ) {

		if ( empty( $params ) ) {
			$params = array();
		}

		if ( is_array( $params ) ) {
			$params['json_result'] = true;
		}

		$postdata = self::get_post_data_not_authed( $url, $admin, $what, $params );
		$website  = null;

		$others['function'] = $what;
		return self::fetch_url( $website, $url, $postdata, false, $verifyCertificate, true, $http_user, $http_pass, $sslVersion, $others );
	}

	/**
	 * Method fetch_url()
	 *
	 * Fetch URL.
	 *
	 * @param object  $website Child Site info.
	 * @param string  $url URL to fetch from.
	 * @param mixed   $postdata Post data to fetch.
	 * @param boolean $checkConstraints true|false Whether or not to check contraints.
	 * @param null    $verifyCertificate Verify SSL Certificate.
	 * @param boolean $pRetryFailed ture|false Whether or not the Retry has failed.
	 * @param null    $http_user htaccess username.
	 * @param null    $http_pass htaccess password.
	 * @param integer $sslVersion SSL version.
	 * @param array   $others Other functions to perform.
	 *
	 * @throws \Exception Excetpion message.
	 *
	 * @return mixed self::m_fetch_url()
	 */
	public static function fetch_url(
		&$website,
		$url,
		$postdata,
		$checkConstraints = false,
		$verifyCertificate = null,
		$pRetryFailed = true,
		$http_user = null,
		$http_pass = null,
		$sslVersion = 0,
		$others = array() ) {

		$start = time();

		try {
			$tmpUrl = $url;
			if ( '/' != substr( $tmpUrl, - 1 ) ) {
				$tmpUrl .= '/';
			}

			if ( false === strpos( $url, 'wp-admin' ) ) {
				$tmpUrl .= 'wp-admin/admin-ajax.php';
			}

			return self::m_fetch_url( $website, $tmpUrl, $postdata, $checkConstraints, $verifyCertificate, $http_user, $http_pass, $sslVersion, $others );
		} catch ( \Exception $e ) {
			if ( ! $pRetryFailed || ( 30 < ( time() - $start ) ) ) {
				throw $e;
			}

			try {
				return self::m_fetch_url( $website, $url, $postdata, $checkConstraints, $verifyCertificate, $http_user, $http_pass, $sslVersion, $others );
			} catch ( \Exception $ex ) {
				throw $e;
			}
		}
	}

	/**
	 * Method m_fetch_url()
	 *
	 * M Fetch URL.
	 *
	 * @param object  $website Child Site info.
	 * @param string  $url URL to fetch from.
	 * @param mixed   $postdata Post data to fetch.
	 * @param boolean $checkConstraints true|false Whether or not to check contraints.
	 * @param null    $verifyCertificate Verify SSL Certificate.
	 * @param null    $http_user htaccess username.
	 * @param null    $http_pass htaccess password.
	 * @param integer $sslVersion SSL version.
	 * @param array   $others Other functions to perform.
	 *
	 * @throws MainWP_Exception Exception message.
	 *
	 * @return mixed $data, $information.
	 */
	public static function m_fetch_url( // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		&$website,
		$url,
		$postdata,
		$checkConstraints = false,
		$verifyCertificate = null,
		$http_user = null,
		$http_pass = null,
		$sslVersion = 0,
		$others = array() ) {

		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';

		MainWP_Logger::instance()->debug_for_website( $website, 'm_fetch_url', 'Request to [' . $url . '] [' . MainWP_Utility::value_to_string( $postdata, 1 ) . ']' );

		$identifier = null;
		if ( $checkConstraints ) {
			self::check_constraints( $identifier, $website );
		}

		if ( null != $website ) {
			MainWP_DB_Common::instance()->insert_or_update_request_log( $website->id, null, microtime( true ), null );
		}

		if ( null != $identifier ) {
			self::release( $identifier );
		}

		$dirs      = MainWP_System_Utility::get_mainwp_dir();
		$cookieDir = $dirs[0] . 'cookies';

		self::init_cookiesdir( $cookieDir );

		$ch = curl_init();

		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
			curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
			curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

			if ( $proxy->use_authentication() ) {
				curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
				curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
			}
		}

		if ( ( null != $website ) && ( ( property_exists( $website, 'wpe' ) && 1 !== $website->wpe ) || ( isset( $others['upgrade'] ) && ( true === $others['upgrade'] ) ) ) ) {
			$cookieFile = $cookieDir . '/' . sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' );
			if ( ! file_exists( $cookieFile ) ) {
				@file_put_contents( $cookieFile, '' );
			}

			if ( file_exists( $cookieFile ) ) {
				@chmod( $cookieFile, 0644 );
				curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
				curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
			}
		}

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		curl_setopt( $ch, CURLOPT_ENCODING, 'none' );

		if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
			$http_pass = stripslashes( $http_pass );
			curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
		}

		$ssl_verifyhost = false;
		if ( null !== $verifyCertificate ) {
			if ( 1 === $verifyCertificate ) {
				$ssl_verifyhost = true;
			} elseif ( 2 === $verifyCertificate ) {
				if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
					$ssl_verifyhost = true;
				}
			}
		} else {
			if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
				$ssl_verifyhost = true;
			}
		}

		if ( $ssl_verifyhost ) {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		} else {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		}

		curl_setopt( $ch, CURLOPT_SSLVERSION, $sslVersion );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'X-Requested-With: XMLHttpRequest' ) );
		curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );

		$force_use_ipv4 = false;
		$forceUseIPv4   = isset( $others['force_use_ipv4'] ) ? $others['force_use_ipv4'] : null;
		if ( null !== $forceUseIPv4 ) {
			if ( 1 === $forceUseIPv4 ) {
				$force_use_ipv4 = true;
			} elseif ( 2 === $forceUseIPv4 ) {
				if ( 1 === get_option( 'mainwp_forceUseIPv4' ) ) {
					$force_use_ipv4 = true;
				}
			}
		} else {
			if ( 1 === get_option( 'mainwp_forceUseIPv4' ) ) {
				$force_use_ipv4 = true;
			}
		}

		if ( $force_use_ipv4 ) {
			if ( defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
				curl_setopt( $ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
			}
		}

		$timeout = 20 * 60 * 60;
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
		set_time_limit( $timeout );

		MainWP_Utility::end_session();

		MainWP_Logger::instance()->debug_for_website( $website, 'm_fetch_url', 'Executing handlers' );

		$disabled_functions = ini_get( 'disable_functions' );
		if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
			$mh = @curl_multi_init();
			@curl_multi_add_handle( $mh, $ch );

			$lastRun = 0;
			do {
				if ( 20 < time() - $lastRun ) {
					@set_time_limit( $timeout );
					$lastRun = time();
				}
				@curl_multi_exec( $mh, $running );
				while ( $info = @curl_multi_info_read( $mh ) ) {
					$data = @curl_multi_getcontent( $info['handle'] );

					$http_status = @curl_getinfo( $info['handle'], CURLINFO_HTTP_CODE );
					$err         = @curl_error( $info['handle'] );
					$real_url    = @curl_getinfo( $info['handle'], CURLINFO_EFFECTIVE_URL );

					@curl_multi_remove_handle( $mh, $info['handle'] );
				}
				usleep( 10000 );
			} while ( $running > 0 );

			@curl_multi_close( $mh );
		} else {
			$data        = @curl_exec( $ch );
			$http_status = @curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$err         = @curl_error( $ch );
			$real_url    = @curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
		}

		$host = wp_parse_url( $real_url, PHP_URL_HOST );
		$ip   = gethostbyname( $host );

		if ( null != $website ) {
			MainWP_DB_Common::instance()->insert_or_update_request_log( $website->id, $ip, null, microtime( true ) );
		}

		$raw_response = isset( $others['raw_response'] ) && 'yes' === $others['raw_response'] ? true : false;

		MainWP_Logger::instance()->debug_for_website( $website, 'm_fetch_url', 'http status: [' . $http_status . '] err: [' . $err . '] data: [' . $data . ']' );
		if ( '400' === $http_status ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'm_fetch_url', 'post data: [' . MainWP_Utility::value_to_string( $postdata, 1 ) . ']' );
		}

		if ( ( false === $data ) && ( 0 === $http_status ) ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $url . '] HTTP Error: [status=0][' . $err . ']' );
			throw new MainWP_Exception( 'HTTPERROR', $err );
		} elseif ( empty( $data ) && ! empty( $err ) ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $url . '] HTTP Error: [status=' . $http_status . '][' . $err . ']' );
			throw new MainWP_Exception( 'HTTPERROR', $err );
		} elseif ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result      = $results[1];
			$information = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			MainWP_Logger::instance()->debug_for_website( $website, 'm_fetch_url', 'information: [OK]' );
			return $information;
		} elseif ( 200 === $http_status && ! empty( $err ) ) {
			throw new MainWP_Exception( 'HTTPERROR', $err );
		} elseif ( $raw_response ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'm_fetch_url', 'Response: [RAW]' );
			return $data;
		} else {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $url . '] Result was: [' . $data . ']' );
			throw new MainWP_Exception( 'NOMAINWP', $url );
		}
	}

	/**
	 * Method check_constraints()
	 *
	 * Check connection delay constraints.
	 *
	 * @param mixed $identifier Lock identifier.
	 * @param mixed $website Object child site.
	 */
	private static function check_constraints( &$identifier, $website ) {
		$semLock      = '103218';
		$identifier   = self::get_lock_identifier( $semLock );
		$minimumDelay = ( ( false === get_option( 'mainwp_minimumDelay' ) ) ? 200 : get_option( 'mainwp_minimumDelay' ) );
		if ( 0 < $minimumDelay ) {
			$minimumDelay = $minimumDelay / 1000;
		}
		$minimumIPDelay = ( ( false === get_option( 'mainwp_minimumIPDelay' ) ) ? 1000 : get_option( 'mainwp_minimumIPDelay' ) );
		if ( 0 < $minimumIPDelay ) {
			$minimumIPDelay = $minimumIPDelay / 1000;
		}

		MainWP_Utility::end_session();
		$delay = true;
		while ( $delay ) {
			self::lock( $identifier );
			if ( 0 < $minimumDelay && self::check_constraints_last_request( $identifier, $minimumDelay ) ) {
				continue;
			}

			if ( 0 < $minimumIPDelay && null != $website ) {
				$ip = MainWP_DB::instance()->get_wp_ip( $website->id );
				if ( null != $ip && '' !== $ip ) {
					if ( self::check_constraints_last_request( $identifier, $minimumIPDelay, $ip ) ) {
						continue;
					}
				}
			}
			$delay = false;
		}

		$maximumRequests   = ( ( false === get_option( 'mainwp_maximumRequests' ) ) ? 4 : get_option( 'mainwp_maximumRequests' ) );
		$maximumIPRequests = ( ( false === get_option( 'mainwp_maximumIPRequests' ) ) ? 1 : get_option( 'mainwp_maximumIPRequests' ) );

		$first = true;
		$delay = true;
		while ( $delay ) {
			if ( ! $first ) {
				self::lock( $identifier );
			} else {
				$first = false;
			}

			MainWP_DB_Common::instance()->close_open_requests();

			if ( 0 < $maximumRequests && self::check_constraints_open_requests( $identifier, $maximumRequests ) ) {
				continue;
			}

			if ( 0 < $maximumIPRequests && null != $website ) {
				$ip = MainWP_DB::instance()->get_wp_ip( $website->id );
				if ( null != $ip && '' != $ip ) {
					if ( self::check_constraints_open_requests( $identifier, $maximumIPRequests, $ip ) ) {
						continue;
					}
				}
			}
			$delay = false;
		}
	}

	/**
	 * Method check_constraints_last_request().
	 *
	 * Check constraints for last requests.
	 *
	 * @param mixed       $identifier connect identifier.
	 * @param int         $minimumDelay minimum delay.
	 * @param string|null $ip ip address.
	 */
	private static function check_constraints_last_request( $identifier, $minimumDelay, $ip = null ) {
		$lastRequest = MainWP_DB_Common::instance()->get_last_request_timestamp( $ip );
		if ( $lastRequest > ( ( microtime( true ) ) - $minimumDelay ) ) {
			self::release( $identifier );
			usleep( ( $minimumDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000 );
			return true;
		}
		return false;
	}

	/**
	 * Method check_constraints_open_requests().
	 *
	 * Check constraints for open requests.
	 *
	 * @param mixed       $identifier connect identifier.
	 * @param int         $maximumRequests maximum requests.
	 * @param string|null $ip ip address.
	 */
	private static function check_constraints_open_requests( $identifier, $maximumRequests, $ip = null ) {
		$nrOfOpenRequests = MainWP_DB_Common::instance()->get_nrof_open_requests( $ip );
		if ( $nrOfOpenRequests >= $maximumRequests ) {
			self::release( $identifier );
			usleep( 200000 );
			return true;
		}
		return false;
	}

	/**
	 * Method download_to_file()
	 *
	 * Download to file.
	 *
	 * @param mixed   $url Download URL.
	 * @param mixed   $file File to download to.
	 * @param boolean $size Size of file.
	 * @param null    $http_user htaccess username.
	 * @param null    $http_pass htaccess password.
	 *
	 * @throws MainWP_Exception Exception message.
	 */
	public static function download_to_file( $url, $file, $size = false, $http_user = null, $http_pass = null ) {

		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();
		global $wp_filesystem;

		if ( $wp_filesystem->exists( $file ) && ( ( false === $size ) || ( $wp_filesystem->size( $file ) > $size ) ) ) {
			$wp_filesystem->delete( $file );
		}

		if ( ! $wp_filesystem->exists( dirname( $file ) ) ) {
			$wp_filesystem->mkdir( dirname( $file ), 0777, true );
		}

		if ( ! $wp_filesystem->exists( dirname( $file ) ) ) {
			throw new MainWP_Exception( __( 'MainWP plugin could not create directory in order to download the file.', 'mainwp' ) );
		}

		if ( ! is_writable( @dirname( $file ) ) ) {
			throw new MainWP_Exception( __( 'MainWP upload directory is not writable.', 'mainwp' ) );
		}

		$fp    = fopen( $file, 'a' );
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		if ( false !== $size ) {
			if ( $wp_filesystem->exists( $file ) ) {
				$size = $wp_filesystem->size( $file );
				$url .= '&foffset=' . $size;
			}
		}
		$ch = curl_init( str_replace( ' ', '%20', $url ) );

		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
			curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
			curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

			if ( $proxy->use_authentication() ) {
				curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
				curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
			}
		}

		curl_setopt( $ch, CURLOPT_FILE, $fp );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		curl_setopt( $ch, CURLOPT_ENCODING, 'none' );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
			$http_pass = stripslashes( $http_pass );
			curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
		}
		curl_exec( $ch );
		curl_close( $ch );
		fclose( $fp );
	}

	/**
	 * Method init_coockiesdir()
	 *
	 * Check for cookies directory and crate it if it doesn't already exist,
	 * set the file permissions and update htaccess.
	 *
	 * @param mixed $cookieDir Cookies directory.
	 *
	 * @return void
	 */
	public static function init_cookiesdir( $cookieDir ) {

			$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

			global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {

			if ( ! $wp_filesystem->is_dir( $cookieDir ) ) {
				$wp_filesystem->mkdir( $cookieDir, 0777, true );
			}

			if ( ! file_exists( $cookieDir . '/.htaccess' ) ) {
				$file_htaccess = $cookieDir . '/.htaccess';
				$wp_filesystem->put_contents( $file_htaccess, 'deny from all' );
			}

			if ( ! file_exists( $cookieDir . '/index.php' ) ) {
				$file_index = $cookieDir . '/index.php';
				$wp_filesystem->touch( $file_index );
			}
		} else {

			if ( ! file_exists( $cookieDir ) ) {
				@mkdir( $cookieDir, 0777, true );
			}

			if ( ! file_exists( $cookieDir . '/.htaccess' ) ) {
				$file_htaccess = @fopen( $cookieDir . '/.htaccess', 'w+' );
				@fwrite( $file_htaccess, 'deny from all' );
				@fclose( $file_htaccess );
			}

			if ( ! file_exists( $cookieDir . '/index.php' ) ) {
				$file_index = @fopen( $cookieDir . '/index.php', 'w+' );
				@fclose( $file_index );
			}
		}
	}

	/**
	 * Method get_file_content()
	 *
	 * Get contents of file.
	 *
	 * @param mixed $url File Location.
	 *
	 * @return mixed false|$data
	 */
	protected static function get_file_content( $url ) {
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		$ch    = curl_init();

		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
			curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
			curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

			if ( $proxy->use_authentication() ) {
				curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
				curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
			}
		}

		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		curl_setopt( $ch, CURLOPT_ENCODING, 'none' );

		$data     = @curl_exec( $ch );
		$httpCode = @curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		curl_close( $ch );

		if ( 200 === $httpCode ) {
			return $data;
		} else {
			return false;
		}
	}

	/**
	 * Method get_favico_url()
	 *
	 * Get Child Site favicon URL.
	 *
	 * @param mixed $website Child Site info.
	 *
	 * @return mixed $faviurl Favicon URL.
	 */
	public static function get_favico_url( $website ) {
		$favi    = MainWP_DB::instance()->get_website_option( $website, 'favi_icon', '' );
		$faviurl = '';

		if ( ! empty( $favi ) ) {
			if ( false !== strpos( $favi, 'favi-' . intval( $website->id ) . '-' ) ) {
				$dirs = MainWP_System_Utility::get_icons_dir();
				if ( file_exists( $dirs[0] . $favi ) ) {
					$faviurl = $dirs[1] . $favi;
				} else {
					$faviurl = '';
				}
			} elseif ( ( 0 === strpos( $favi, '//' ) ) || ( 0 === strpos( $favi, 'http' ) ) ) {
				$faviurl = $favi;
			} else {
				$faviurl = $website->url . $favi;
				$faviurl = MainWP_Utility::remove_http_prefix( $faviurl );
			}
		}
		if ( empty( $faviurl ) ) {
			$faviurl = MAINWP_PLUGIN_URL . 'assets/images/sitefavi.png';
		}

		return $faviurl;
	}
}
