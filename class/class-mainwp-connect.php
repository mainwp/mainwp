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
 * Class MainWP_Connect
 *
 * @package MainWP\Dashboard
 */
class MainWP_Connect {

	// phpcs:disable WordPress.DB.RestrictedFunctions, Generic.Metrics.CyclomaticComplexity, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.

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
	 * Method try visit.
	 *
	 * Try connecting to Child Site via cURL.
	 *
	 * @param string $url Child Site URL.
	 * @param bool   $ssl_verifyhost Option to check SSL Certificate. Default = null.
	 * @param string $http_user HTTPAuth Username. Default = null.
	 * @param string $http_pass HTTPAuth Password. Default = null.
	 * @param int    $sslVersion        Child Site SSL Version.
	 * @param bool   $forceUseIPv4      Option to force IP4. Default = null.
	 * @param bool   $no_body           Option to set CURLOPT_NOBODY option. Default = false.
	 *
	 * @return array $out. 'host IP, Returned HTTP Code, Error Message, http Status error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 * @uses \MainWP\Dashboard\MainWP_System::$version
	 * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
	 * @uses \MainWP\Dashboard\MainWP_Utility::get_http_codes()
	 */
	public static function try_visit( $url, $ssl_verifyhost = null, $http_user = null, $http_pass = null, $sslVersion = 0, $forceUseIPv4 = null, $no_body = false ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

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
		if ( $no_body ) {
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'HEAD' ); // HTTP request is 'HEAD', but sometime return 4xx - error code.
		}
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

		if ( $ssl_verifyhost ) {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		} else {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		}

		curl_setopt( $ch, CURLOPT_SSLVERSION, $sslVersion );

		$http_version = apply_filters( 'mainwp_curl_http_version', false, false, $url );
		if ( false !== $http_version ) {
			curl_setopt( $ch, CURLOPT_HTTP_VERSION, $http_version );
		}

		$curlopt_resolve = apply_filters( 'mainwp_curl_curlopt_resolve', false, false, $url );
		if ( is_array( $curlopt_resolve ) && ! empty( $curlopt_resolve ) ) {
			curl_setopt( $ch, CURLOPT_RESOLVE, $curlopt_resolve );
			curl_setopt( $ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false );
		}

		$headers           = array( 'X-Requested-With' => 'XMLHttpRequest' );
		$headers['Expect'] = self::get_expect_header( $postdata );

		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			$headers = \WpOrg\Requests\Requests::flatten( $headers );
		} else {
			$headers = \Requests::flatten( $headers );
		}

		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'X-Requested-With: XMLHttpRequest' ) );
		curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );

		$force_use_ipv4 = false;
		if ( null !== $forceUseIPv4 ) {
			if ( 1 === $forceUseIPv4 ) {
				$force_use_ipv4 = true;
			} elseif ( 2 === $forceUseIPv4 ) {
				if ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) {
					$force_use_ipv4 = true;
				}
			}
		} elseif ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) {
				$force_use_ipv4 = true;
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

			if ( 'resource' === gettype( $mh ) ) {
				curl_multi_close( $mh );
			}
		} else {
			$data        = curl_exec( $ch );
			$err         = curl_error( $ch );
			$http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$errno       = curl_errno( $ch );
			$realurl     = curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
			if ( 'resource' === gettype( $ch ) ) {
				curl_close( $ch );
			}
		}

		MainWP_Logger::instance()->debug( ' :: tryVisit :: [url=' . $url . '] [http_status=' . $http_status . '] [error=' . $err . '] [data-start]' . $data . '[data-end]' );
		MainWP_Logger::instance()->log_execution_time( 'tryVisit :: [url=' . $url . '] [http_status=' . $http_status . ']' );

		$host   = wp_parse_url( ( empty( $realurl ) ? $url : $realurl ), PHP_URL_HOST );
		$ip     = false;
		$target = false;

		$found     = false;
		$dnsRecord = @dns_get_record( $host );
		MainWP_Logger::instance()->debug( ' :: tryVisit :: [dnsRecord=' . MainWP_Utility::value_to_string( $dnsRecord, 1 ) . ']' );

		if ( false !== $dnsRecord && is_array( $dnsRecord ) ) {
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

			if ( ! isset( $dnsRecord['host'] ) ) {
				foreach ( $dnsRecord as $dnsRec ) {
					if ( $dnsRec['host'] === $host ) {
						if ( 'CNAME' === $dnsRec['type'] ) {
							$target = $dnsRec['target'];
						}
						$found = true;
						break;
					}
				}
			} else {
				$found = ( $dnsRecord['host'] === $host );
				if ( 'CNAME' === $dnsRecord['type'] ) {
					$target = $dnsRecord['target'];
				}
			}
		}

		if ( false === $ip ) {
			$ip = gethostbynamel( $host );
		}
		if ( ( false !== $target ) && ( $target !== $host ) ) {
			$host .= ' (CNAME: ' . $target . ')';
		}

		$out = array(
			'host'           => $host,
			'httpCode'       => $http_status,
			'httpCodeString' => MainWP_Utility::get_http_codes( $http_status ),
		);

		if ( false !== $ip ) {
			$out['ip'] = $ip;
			$found     = true;
		}

		$out['error'] = ( '' === $err && false === $found ? 'Invalid host.' : $err );

		return $out;
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
		$value = (int) $value;
		if ( 200 === $value ) {
			return true;
		}
		$ignored_code = get_option( 'mainwp_ignore_HTTP_response_status', '' );
		$ignored_code = trim( $ignored_code );
		if ( ! empty( $ignored_code ) ) {
			$ignored_code = explode( ',', $ignored_code );
			foreach ( $ignored_code as $code ) {
				$code = trim( $code );
				if ( (int) $value === (int) $code ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Method check_website_status()
	 *
	 * Check if the Website returns and http errors.
	 *
	 * @param array $website Child Site information.
	 *
	 * @return mixed False|try visit result.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::is_domain_valid()
	 */
	public static function check_website_status( $website ) {
		$http_user         = null;
		$http_pass         = null;
		$sslVersion        = null;
		$verifyCertificate = null;
		$forceUseIPv4      = null;
		if ( is_object( $website ) && isset( $website->url ) ) {
			$url               = $website->url;
			$verifyCertificate = isset( $website->verify_certificate ) ? (int) $website->verify_certificate : null;
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

		$ssl_verifyhost = false;

		if ( 1 === $verifyCertificate ) {
			$ssl_verifyhost = true;
		} elseif ( 2 === $verifyCertificate || null === $verifyCertificate ) {
			if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
				$ssl_verifyhost = true;
			}
		}

		$noBody = false;
		return self::try_visit( $url, $ssl_verifyhost, $http_user, $http_pass, $sslVersion, $forceUseIPv4, $noBody );
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
	public static function get_post_data_authed( &$website, $what, $params = null ) {  //phpcs:ignore -- complex method.
		if ( $website && '' !== $what ) {
			$data             = array();
			$data['user']     = $website->adminname;
			$data['function'] = $what;
			$data['nonce']    = wp_rand( 0, 9999 );

			$params_filter = apply_filters( 'mainwp_pre_fetch_authed_data', false, $params, $what, $website );
			if ( is_array( $params_filter ) && ! empty( $params_filter ) ) {
				$data = array_merge( $data, $params_filter );
			}

			if ( null !== $params ) {
				$data = array_merge( $data, $params );
			}

			$alg          = false;
			$sign_success = null;
			$use_seclib   = false;

			$data = apply_filters( 'mainwp_get_post_data_authed', $data, $website, $what, $params );
			if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
				$sign_success = MainWP_Connect_Lib::connect_sign( $what . $data['nonce'], $signature, base64_decode( $website->privkey ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				$use_seclib   = true;
			} elseif ( function_exists( 'openssl_verify' ) ) {
				$alg          = MainWP_System_Utility::get_connect_sign_algorithm( $website );
				$sign_success = self::connect_sign( $what . $data['nonce'], $signature, base64_decode( $website->privkey ), $alg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				if ( false !== $alg ) {
					$data['sign_algo'] = $alg;
				}
			}

			if ( $use_seclib ) {
				$data['verifylib'] = 1;
			}

			if ( null !== $sign_success && empty( $sign_success ) ) {
				$sign_error = '';
				while ( $msg = openssl_error_string() ) {
					if ( is_string( $msg ) ) {
						$sign_error .= $msg;
					}
				}
				MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT SIGN', 'FAILED :: [what=' . ( is_string( $what ) ? $what : '' ) . '] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
			}

			$data['mainwpsignature'] = ! empty( $signature ) ? base64_encode( $signature ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			/** This filter is documented in ../widgets/widget-mainwp-recent-posts.php */
			$recent_number = apply_filters( 'mainwp_recent_posts_pages_number', 5 );
			if ( 5 !== $recent_number ) {
				$data['recent_number'] = $recent_number;
			}

			$scan_dir = apply_filters( 'mainwp_stats_scan_dir', false, $website );
			if ( ! empty( $scan_dir ) ) {
				$data['scan_dir'] = 1;
			}

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			if ( ( ! defined( 'DOING_CRON' ) || false === DOING_CRON ) && ( ! defined( 'WP_CLI' ) || false === WP_CLI ) ) {
				if ( is_object( $current_user ) && property_exists( $current_user, 'ID' ) && $current_user->ID ) {

					/**
					 * Filter: mainwp_alter_login_user
					 *
					 * Filters users accounts so it allows you user to jump to child site under alternative administrator account.
					 *
					 * @param int $website->id Child site ID.
					 * @param int $current_user->ID User ID.
					 *
					 * @since Unknown
					 */
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
	 * Method get_renew_post_data_authed()
	 *
	 * Get authorized $_POST data & build query for renew connection action only.
	 *
	 * @param mixed $website Array of Child Site Info.
	 * @param mixed $what What we are posting.
	 *
	 * @return mixed null|http_build_query()
	 */
	private static function get_renew_post_data_authed( &$website, $what ) {

		if ( $website && '' !== $what ) {
			$compat_what      = 'disconnect'; // to compatible, renew will call disconnect.
			$data             = array();
			$data['user']     = $website->adminname;
			$data['function'] = $compat_what;
			$data['nonce']    = wp_rand( 0, 9999 );

			$alg          = false;
			$sign_success = null;
			$use_seclib   = false;

			if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
				// to disconnect.
				$sign_success = MainWP_Connect_Lib::connect_sign( $compat_what . $data['nonce'], $signature, base64_decode( $website->privkey ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				$use_seclib   = true;
			} elseif ( function_exists( 'openssl_verify' ) ) {
				$alg          = MainWP_System_Utility::get_connect_sign_algorithm( $website );
				$sign_success = self::connect_sign( $compat_what . $data['nonce'], $signature, base64_decode( $website->privkey ), $alg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for keys encoding.
				if ( empty( $sign_success ) ) { // error from openssl, openssl_sign().
					$alg = defined( 'OPENSSL_ALGO_SHA1' ) ? OPENSSL_ALGO_SHA1 : false; // to set default SHA1, to disconnect.
					MainWP_Logger::instance()->debug_for_website( $website, 'get_renew_post_data_authed', '[' . $website->url . '] :: [openssl_sign:failed] :: Set sign_algo=SHA1' );
					$sign_success = self::connect_sign( $compat_what . $data['nonce'], $signature, base64_decode( $website->privkey ), $alg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for keys encoding.
				}

				if ( false !== $alg ) {
					$data['sign_algo'] = $alg;
				}
			}

			if ( $use_seclib ) {
				$data['verifylib'] = 1;
			}

			if ( null !== $sign_success && empty( $sign_success ) ) {
				$sign_error = '';
				while ( $msg = openssl_error_string() ) {
					if ( is_string( $msg ) ) {
						$sign_error .= $msg;
					}
				}
				MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT SIGN', 'FAILED :: [what=' . ( is_string( $what ) ? $what : '' ) . '] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
			}

			$data['mainwpsignature'] = ! empty( $signature ) ? base64_encode( $signature ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			return http_build_query( $data, '', '&' );
		}
		return null;
	}


	/**
	 * Method get_get_data_authed()
	 *
	 * Get authorized $_GET data & build query.
	 *
	 * @param mixed  $website Child Site data.
	 * @param mixed  $paramValue OpenSSL parameter.
	 * @param string $paramName Parameter name.
	 * @param bool   $asArray true|false Default is false.
	 * @param array  $other_params other params.
	 *
	 * @return string $url
	 */
	public static function get_get_data_authed( $website, $paramValue, $paramName = 'where', $asArray = false, $other_params = array() ) { //phpcs:ignore -- complex method.
		$params = array();
		if ( $website && '' !== $paramValue ) {

			$sign_success = null;
			$alg          = false;
			$use_seclib   = false;
			$nonce        = wp_rand( 0, 9999 );
			if ( MainWP_Connect_Lib::is_use_fallback_sec_lib( $website ) ) {
				$sign_success = MainWP_Connect_Lib::connect_sign( $paramValue . $nonce, $signature, base64_decode( $website->privkey ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				$use_seclib   = true;
			} elseif ( function_exists( 'openssl_verify' ) ) {
				$alg          = MainWP_System_Utility::get_connect_sign_algorithm( $website );
				$sign_success = self::connect_sign( $paramValue . $nonce, $signature, base64_decode( $website->privkey ), $alg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			}

			$signature = ! empty( $signature ) ? base64_encode( $signature ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			if ( null !== $sign_success && empty( $sign_success ) ) {
				$sign_error = '';
				while ( $msg = openssl_error_string() ) {
					if ( is_string( $msg ) ) {
						$sign_error .= $msg;
					}
				}
				MainWP_Logger::instance()->warning_for_website( $website, 'CONNECT SIGN', 'FAILED :: [login_required=1] :: [seclib=' . intval( $use_seclib ) . '] :: [algorithm=' . $alg . '] :: [openssl_sign error =' . $sign_error . ']', false );
			}

			$params = array(
				'login_required'  => 1,
				'user'            => rawurlencode( $website->adminname ),
				'mainwpsignature' => rawurlencode( $signature ),
				'nonce'           => $nonce,
				$paramName        => rawurlencode( $paramValue ),
			);

			if ( is_array( $other_params ) ) {
				foreach ( $other_params as $name => $value ) {
					if ( is_string( $name ) && ! empty( $name ) && is_scalar( $value ) ) {
						$params[ sanitize_text_field( wp_unslash( $name ) ) ] = rawurlencode( sanitize_text_field( wp_unslash( $value ) ) );
					}
				}
			}

			if ( false !== $alg ) {
				$params['sign_algo'] = $alg;
			}

			if ( ! empty( $use_seclib ) ) {
				$params['verifylib'] = 1;
			}

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			if ( ( ! defined( 'DOING_CRON' ) || false === DOING_CRON ) && ( ! defined( 'WP_CLI' ) || false === WP_CLI ) ) {
				if ( $current_user && $current_user->ID ) {
					/** This filter is documented in ../class/class-mainwp-connect.php */
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

		$url  = ( isset( $website->url ) && '' !== $website->url ? $website->url : $website->siteurl );
		$url .= ( substr( $url, - 1 ) !== '/' ? '/' : '' );
		$url .= '?';

		foreach ( $params as $key => $value ) {
			$url .= $key . '=' . $value . '&';
		}

		return rtrim( $url, '&' );
	}

	/**
	 * Method connect_sign()
	 *
	 * Sign connect.
	 *
	 * @param string $data Data sign.
	 * @param string $signature signature.
	 * @param string $privkey Private key.
	 * @param mixed  $algorithm signature algorithm.
	 *
	 * @return bool Success or not.
	 */
	public static function connect_sign( $data, &$signature, $privkey, $algorithm ) {
		if ( false === $algorithm ) {
			return openssl_sign( $data, $signature, $privkey ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		} else {
			return openssl_sign( $data, $signature, $privkey, $algorithm ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		}
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
		if ( '' !== $url && '' !== $admin && '' !== $what ) {
			$data             = array();
			$data['user']     = $admin;
			$data['function'] = $what;
			if ( null !== $params ) {
				$data = array_merge( $data, $params );
			}

			return http_build_query( $data, '', '&' );
		}

		return null;
	}

	/**
	 * Method fetch_urls_authed()
	 *
	 * Fetch authorized URLs.
	 *
	 * @param object $websites Websites information.
	 * @param string $what Action to perform.
	 * @param array  $params Request parameters.
	 * @param mixed  $handler Request handler.
	 * @param mixed  $output Request output.
	 * @param mixed  $whatPage Request URL. Default /admin-ajax.php.
	 * @param array  $others Request additional information.
	 *
	 * @return bool true|false
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::$version
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
	 */
	public static function fetch_urls_authed( &$websites, $what, $params, $handler, &$output, $whatPage = null, $others = array() ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		if ( ! is_array( $websites ) || empty( $websites ) ) {
			return false;
		}

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$chunkSize = apply_filters( 'mainwp_fetch_urls_chunk_size', 10 );
		if ( count( $websites ) > $chunkSize ) {
			$total = count( $websites );
			$loops = ceil( $total / $chunkSize );
			for ( $i = 0; $i < $loops; $i++ ) {
				$newSites = array_slice( $websites, $i * $chunkSize, $chunkSize, true );
				self::fetch_urls_authed( $newSites, $what, $params, $handler, $output, $whatPage, $others );
				sleep( 5 );
			}

			return false;
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

			if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
				MainWP_Demo_Handle::get_instance()->handle_fetch_urls_demo( $data, $website, $output, $what, $params );
				continue;
			}

			$url = $website->url;
			if ( '/' !== substr( $url, - 1 ) ) {
				$url .= '/';
			}

			if ( false === strpos( $url, 'wp-admin' ) ) {
				$url .= 'wp-admin/';
			}

			if ( null !== $whatPage ) {
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
							'id'   => $website->id,
							'url'  => $website->url,
							'name' => $website->name,
						),
					),
					'4.0.7.2',
					'mainwp_pre_posting_posts'
				);

				/**
				 * Filter: mainwp_pre_posting_posts
				 *
				 * Prepares parameters for the authenticated cURL post.
				 *
				 * @since 4.1
				 */
				$params = apply_filters(
					'mainwp_pre_posting_posts',
					( is_array( $params ) ? $params : array() ),
					(object) array(
						'id'   => $website->id,
						'url'  => $website->url,
						'name' => $website->name,
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

			if ( ( null !== $website ) && ( ( property_exists( $website, 'wpe' ) && 1 !== $website->wpe ) || ( isset( $others['upgrade'] ) && ( true === $others['upgrade'] ) ) ) ) {
				// to fix.
				if ( defined( 'LOGGED_IN_SALT' ) && defined( 'NONCE_SALT' ) ) {
					$cookie_salt = sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' );
				} else {
					$cookie_salt = sha1( sha1( 'mainwp' . $website->id ) . 'WP_Cookie' );
				}
				$cookieFile = $cookieDir . '/' . $cookie_salt;
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
			$verifyCertificate = isset( $website->verify_certificate ) ? (int) $website->verify_certificate : null;
			if ( null !== $verifyCertificate ) {
				if ( 1 === $verifyCertificate ) {
					$ssl_verifyhost = true;
				} elseif ( 2 === $verifyCertificate ) {
					if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
						$ssl_verifyhost = true;
					}
				}
			} elseif ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
				$ssl_verifyhost = true;
			}

			if ( $ssl_verifyhost ) {
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
			} else {
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			}

			curl_setopt( $ch, CURLOPT_SSLVERSION, $website->ssl_version );

			if ( is_object( $website ) && property_exists( $website, 'id' ) ) {
				$http_version = apply_filters( 'mainwp_curl_http_version', false, $website->id );
				if ( false !== $http_version ) {
					curl_setopt( $ch, CURLOPT_HTTP_VERSION, $http_version );
				}

				$curlopt_resolve = apply_filters( 'mainwp_curl_curlopt_resolve', false, $website->id, $website->url );
				if ( is_array( $curlopt_resolve ) && ! empty( $curlopt_resolve ) ) {
					curl_setopt( $ch, CURLOPT_RESOLVE, $curlopt_resolve );
					curl_setopt( $ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false );
				}
			}

			curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
			MainWP_System_Utility::set_time_limit( $timeout );

			if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
				@curl_multi_add_handle( $mh, $ch );
			}

			$handleToWebsite[ self::get_resource_id( $ch ) ] = $website;
			$requestUrls[ self::get_resource_id( $ch ) ]     = $website->url;
			$requestHandles[ self::get_resource_id( $ch ) ]  = $ch;

			if ( null !== $_new_post ) {
				$params['new_post'] = $_new_post;
			}
		}

		if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
			$lastRun = 0;
			do {
				if ( 20 < time() - $lastRun ) {
					MainWP_System_Utility::set_time_limit( $timeout );
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
						++$running;
						continue;
					}

					if ( null !== $handler ) {
						$site = &$handleToWebsite[ self::get_resource_id( $info['handle'] ) ];
						call_user_func_array( $handler, array( $data, $site, &$output, $params ) );
					}

					unset( $handleToWebsite[ self::get_resource_id( $info['handle'] ) ] );
					if ( 'resource' === gettype( $info['handle'] ) ) {
						curl_close( $info['handle'] );
					}
					unset( $info['handle'] );
				}
				usleep( 10000 );
			} while ( $running > 0 );

			if ( 'resource' === gettype( $mh ) ) {
				curl_multi_close( $mh );
			}
		} else {
			foreach ( $requestHandles as $id => $ch ) {
				$data = curl_exec( $ch );

				if ( null !== $handler ) {
					$site = &$handleToWebsite[ self::get_resource_id( $ch ) ];
					call_user_func_array( $handler, array( $data, $site, &$output, $params ) );
				}
			}
		}

		return true;
	}

	/**
	 * Credits WordPress org.
	 *
	 * Get the correct "Expect" header for the given request data.
	 *
	 * @param string|array $data Data to send either as the POST body, or as parameters in the URL for a GET/HEAD.
	 * @return string The "Expect" header.
	 */
	protected static function get_expect_header( $data ) {
		if ( ! is_array( $data ) ) {
			return strlen( (string) $data ) >= 1048576 ? '100-Continue' : '';
		}

		$bytesize = 0;
		$iterator = new \RecursiveIteratorIterator( new \RecursiveArrayIterator( $data ) );

		foreach ( $iterator as $datum ) {
			$bytesize += strlen( (string) $datum );

			if ( $bytesize >= 1048576 ) {
				return '100-Continue';
			}
		}

		return '';
	}

	/**
	 * Method get_resource_id()
	 *
	 * Get resource id.
	 *
	 * @param mixed $res The given resource.
	 *
	 * @return $result Resource ID only.
	 */
	public static function get_resource_id( $res ) {
		$result = false;
		if ( is_a( $res, 'CurlHandle' ) ) {
			$result = spl_object_hash( $res );
		} elseif ( is_resource( $res ) ) {
			$resourceString = (string) $res;
			$exploded       = explode( '#', $resourceString );
			$result         = array_pop( $exploded );
		}
		return $result;
	}

	/**
	 * Method get_lock_identifier().
	 *
	 * Get lock identifier.
	 *
	 * @param mixed $pLockName Provided Lock Name.
	 *
	 * @return mixed false|sem_get()|@fopen
	 */
	public static function get_lock_identifier( $pLockName ) {
		if ( ( null === $pLockName ) || ( false === $pLockName ) ) {
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
		if ( ( null === $identifier ) || ( false === $identifier ) ) {
			return false;
		}

		if ( function_exists( 'sem_acquire' ) ) {
			return sem_acquire( $identifier );
		} else {
			if ( ! is_resource( $identifier ) ) {
				return false; // to fix.
			}
			for ( $i = 0; $i < 3; $i++ ) {
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
		if ( ( null === $identifier ) || ( false === $identifier ) ) {
			return false;
		}

		if ( function_exists( 'sem_release' ) ) {
			return sem_release( $identifier );
		} else {
			if ( ! is_resource( $identifier ) ) {
				return false; // to fix.
			}
			@flock( $identifier, LOCK_UN );
			@fclose( $identifier );
		}

		return false;
	}

	/**
	 * Method fetch_url_authed()
	 *
	 * Updates the child site via authenticated request.
	 *
	 * @param object $website          Website information.
	 * @param string $what             Function to perform.
	 * @param null   $params           Function parameters.
	 * @param bool   $checkConstraints Whether or not to check constraints.
	 * @param bool   $pForceFetch      Whether or not to force the fetch.
	 * @param bool   $pRetryFailed     Whether or not to retry the fetch process.
	 * @param null   $rawResponse      Raw response.
	 *
	 * @return mixed $information
	 *
	 * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::handle_check_website()
	 * @uses \MainWP\Dashboard\MainWP_Premium_Update::maybe_request_premium_updates()
	 * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
	 */
	public static function fetch_url_authed(
		&$website,
		$what,
		$params = null,
		$checkConstraints = false,
		$pForceFetch = false,
		$pRetryFailed = true,
		$rawResponse = null
	) {

		// to support demo data.
		if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
			return MainWP_Demo_Handle::get_instance()->handle_action_demo( $website, $what );
		}

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

		$params['optimize'] = ( ( 1 === (int) get_option( 'mainwp_optimize', 1 ) ) ? 1 : 0 );

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
			/**
			 * Action: mainwp_website_before_updated
			 *
			 * Fires before the child site update process.
			 *
			 * @param object $website Object containing child site info.
			 * @param string $type    Type parameter.
			 * @param string $list    List parameter.
			 *
			 * @since Unknown
			 */
			do_action( 'mainwp_website_before_updated', $website, $type, $list );
		}

		if ( 'renew' === $what ) {
			$postdata = self::get_renew_post_data_authed( $website, $what );
		} else {
			$postdata = self::get_post_data_authed( $website, $what, $params );

		}
		$others['function'] = $what;

		$information = array();

		if ( ! $request_update ) {
			$information = self::fetch_url( $website, $website->url, $postdata, $checkConstraints, $website->verify_certificate, $pRetryFailed, $website->http_user, $website->http_pass, $website->ssl_version, $others );
			/**
			 * Fires immediately after fetch url action.
			 *
			 * @param object $website  website.
			 * @param array $information information result data.
			 * @param string $what action.
			 * @param array $params params input array.
			 * @param array $others others input array.
			 *
			 * @since 4.5.1.1
			 */
			do_action( 'mainwp_fetch_url_authed', $website, $information, $what, $params, $others );
		} else {
			$slug                    = $params['list'];
			$information['upgrades'] = array( $slug => 1 );
		}

		if ( is_array( $information ) && isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
			MainWP_Sync::sync_information_array( $website, $information['sync'] );
			unset( $information['sync'] );
		}

		if ( $updating_website ) {
			/**
			 * Action: mainwp_website_updated
			 *
			 * Fires after the child site update process.
			 *
			 * @param object $website     Object containing child site info.
			 * @param string $type        Type parameter.
			 * @param string $list        List parameter.
			 * @param array  $information Array containing the information fetched from the child site.
			 *
			 * @since Unknown
			 */
			do_action( 'mainwp_website_updated', $website, $type, $list, $information );
			if ( 1 === (int) get_option( 'mainwp_check_http_response', 0 ) ) {
				MainWP_Monitoring_Handler::handle_check_website( $website );
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
	 * @param null    $params Function parameters.
	 * @param bool    $pForceFetch true|false Whether or not to force the fetch.
	 * @param null    $verifyCertificate Verify the SSL Certificate.
	 * @param null    $http_user htaccess username.
	 * @param null    $http_pass htaccess password.
	 * @param integer $sslVersion SSL version to check for.
	 * @param array   $others Other functions to perform.
	 * @param array   $output Output values.
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
		$others = array(),
		&$output = array()
	) {

		if ( empty( $params ) ) {
			$params = array();
		}

		$postdata = self::get_post_data_not_authed( $url, $admin, $what, $params );
		$website  = null;

		$others['function'] = $what;
		return self::fetch_url( $website, $url, $postdata, false, $verifyCertificate, true, $http_user, $http_pass, $sslVersion, $others, $output );
	}

	/**
	 * Method fetch_url()
	 *
	 * Fetch URL.
	 *
	 * @param object  $website Child Site info.
	 * @param string  $url URL to fetch from.
	 * @param mixed   $postdata Post data to fetch.
	 * @param bool    $checkConstraints true|false Whether or not to check constraints.
	 * @param null    $verifyCertificate Verify SSL Certificate.
	 * @param bool    $pRetryFailed ture|false Whether or not the Retry has failed.
	 * @param null    $http_user htaccess username.
	 * @param null    $http_pass htaccess password.
	 * @param integer $sslVersion SSL version.
	 * @param array   $others Other functions to perform.
	 * @param array   $output Output values.
	 *
	 * @throws \Exception Exception message.
	 *
	 * @return mixed self::fetch_url_site()
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
		$others = array(),
		&$output = array()
	) {

		$start = time();

		try {
			$tmpUrl = $url;
			if ( '/' !== substr( $tmpUrl, - 1 ) ) {
				$tmpUrl .= '/';
			}

			if ( false === strpos( $url, 'wp-admin' ) ) {
				$tmpUrl .= 'wp-admin/admin-ajax.php';
			}

			return self::fetch_url_site( $website, $tmpUrl, $postdata, $checkConstraints, $verifyCertificate, $http_user, $http_pass, $sslVersion, $others, $output );
		} catch ( \Exception $e ) {
			if ( ! $pRetryFailed || ( 30 < ( time() - $start ) ) ) {
				throw $e;
			}

			try {
				return self::fetch_url_site( $website, $url, $postdata, $checkConstraints, $verifyCertificate, $http_user, $http_pass, $sslVersion, $others, $output );
			} catch ( \Exception $ex ) {
				throw $e;
			}
		}
	}

	/**
	 * Method fetch_url_site()
	 *
	 * M Fetch URL.
	 *
	 * @param object  $website Child Site info.
	 * @param string  $url URL to fetch from.
	 * @param mixed   $postdata Post data to fetch.
	 * @param bool    $checkConstraints true|false Whether or not to check constraints.
	 * @param null    $verifyCertificate Verify SSL Certificate.
	 * @param null    $http_user htaccess username.
	 * @param null    $http_pass htaccess password.
	 * @param integer $sslVersion SSL version.
	 * @param array   $others Other functions to perform.
	 * @param array   $output Output values.
	 *
	 * @return mixed $data, $information.
	 * @throws MainWP_Exception Exception message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::insert_or_update_request_log()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug_for_website()
	 * @uses \MainWP\Dashboard\MainWP_System::$version
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
	 * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
	 * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
	 */
	public static function fetch_url_site( // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		&$website,
		$url,
		$postdata,
		$checkConstraints = false,
		$verifyCertificate = null,
		$http_user = null,
		$http_pass = null,
		$sslVersion = 0,
		$others = array(),
		&$output = array()
	) {

		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';

		if ( ! empty( $website ) ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'Request to [' . $url . '] [' . MainWP_Utility::value_to_string( $postdata, 1 ) . ']' );
		}

		$identifier = null;
		if ( $checkConstraints ) {
			self::check_constraints( $identifier, $website );
		}

		if ( null !== $website ) {
			MainWP_DB_Common::instance()->insert_or_update_request_log( $website->id, null, microtime( true ), null );
		}

		if ( null !== $identifier ) {
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

		if ( ( null !== $website ) && ( ( property_exists( $website, 'wpe' ) && 1 !== $website->wpe ) || ( isset( $others['upgrade'] ) && ( true === $others['upgrade'] ) ) ) ) {
			// to fix.
			if ( defined( 'LOGGED_IN_SALT' ) && defined( 'NONCE_SALT' ) ) {
				$cookie_salt = sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' );
			} else {
				$cookie_salt = sha1( sha1( 'mainwp' . $website->id ) . 'WP_Cookie' );
			}
			$cookieFile = $cookieDir . '/' . $cookie_salt;
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
			if ( 1 === (int) $verifyCertificate ) {
				$ssl_verifyhost = true;
			} elseif ( 2 === (int) $verifyCertificate ) {
				if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
					$ssl_verifyhost = true;
				}
			}
		} elseif ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
				$ssl_verifyhost = true;
		}

		if ( $ssl_verifyhost ) {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		} else {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		}

		curl_setopt( $ch, CURLOPT_SSLVERSION, $sslVersion );

		if ( is_object( $website ) && property_exists( $website, 'id' ) ) {
			$http_version = apply_filters( 'mainwp_curl_http_version', false, $website->id );
			if ( false !== $http_version ) {
				curl_setopt( $ch, CURLOPT_HTTP_VERSION, $http_version );
			}
			$curlopt_resolve = apply_filters( 'mainwp_curl_curlopt_resolve', false, $website->id, $website->url );
			if ( is_array( $curlopt_resolve ) && ! empty( $curlopt_resolve ) ) {
				curl_setopt( $ch, CURLOPT_RESOLVE, $curlopt_resolve );
				curl_setopt( $ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false );
			}
		}

		$headers           = array( 'X-Requested-With' => 'XMLHttpRequest' );
		$headers['Expect'] = self::get_expect_header( $postdata );

		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			$headers = \WpOrg\Requests\Requests::flatten( $headers );
		} else {
			$headers = \Requests::flatten( $headers );
		}

		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_REFERER, get_option( 'siteurl' ) );

		$force_use_ipv4 = false;
		$forceUseIPv4   = isset( $others['force_use_ipv4'] ) ? (int) $others['force_use_ipv4'] : null;
		if ( null !== $forceUseIPv4 ) {
			if ( 1 === $forceUseIPv4 ) {
				$force_use_ipv4 = true;
			} elseif ( 2 === $forceUseIPv4 ) {
				if ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) {
					$force_use_ipv4 = true;
				}
			}
		} elseif ( 1 === (int) get_option( 'mainwp_forceUseIPv4' ) ) {
				$force_use_ipv4 = true;
		}

		if ( $force_use_ipv4 ) {
			if ( defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
				curl_setopt( $ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
			}
		}

		$timeout = 20 * 60 * 60;
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
		MainWP_System_Utility::set_time_limit( $timeout );

		MainWP_Utility::end_session();

		MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'Executing handlers' );

		$disabled_functions = ini_get( 'disable_functions' );
		if ( empty( $disabled_functions ) || ( false === stristr( $disabled_functions, 'curl_multi_exec' ) ) ) {
			$mh = @curl_multi_init();
			@curl_multi_add_handle( $mh, $ch );

			$lastRun = 0;
			do {
				if ( 20 < time() - $lastRun ) {
					MainWP_System_Utility::set_time_limit( $timeout );
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
			if ( 'resource' === gettype( $mh ) ) {
				@curl_multi_close( $mh );
			}
		} else {
			$data        = @curl_exec( $ch );
			$http_status = @curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$err         = @curl_error( $ch );
			$real_url    = @curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
		}

		$host = wp_parse_url( $real_url, PHP_URL_HOST );
		$ip   = gethostbyname( $host );

		if ( null !== $website ) {
			MainWP_DB_Common::instance()->insert_or_update_request_log( $website->id, $ip, null, microtime( true ) );
		}

		$raw_response = isset( $others['raw_response'] ) && 'yes' === $others['raw_response'] ? true : false;

		$output['fetch_data']  = $data;
		$output['http_status'] = (int) $http_status;

		MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'http status: [' . $http_status . '] err: [' . $err . ']' );
		if ( '400' === $http_status ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'post data: [' . MainWP_Utility::value_to_string( $postdata, 1 ) . ']' );
		}

		MainWP_Logger::instance()->log_execution_time( 'fetch_url_site :: [url=' . $url . ']' );

		$thr_error = null;

		if ( ( false === $data ) && empty( $http_status ) ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $url . '] HTTP Error: [status=0][' . $err . ']' );
			$thr_error = new MainWP_Exception( 'HTTPERROR', $err ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		} elseif ( empty( $data ) && ! empty( $err ) ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $url . '] HTTP Error: [status=' . $http_status . '][' . $err . ']' );
			$thr_error = new MainWP_Exception( 'HTTPERROR', $err ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		} elseif ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result      = $results[1];
			$information = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			unset( $output['fetch_data'] ); // hide the data.
			$data_log = is_array( $postdata ) ? print_r( $postdata, true ) : ( is_string( $postdata ) ? $postdata : '' );  //phpcs:ignore -- good.
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', '[' . $url . '] postdata [' . $data_log . '] information: [OK]' ); //phpcs:ignore -- ok.
			return $information;
		} elseif ( 200 === (int) $http_status && ! empty( $err ) ) {
			$thr_error = new MainWP_Exception( 'HTTPERROR', $err ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		} elseif ( $raw_response ) {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url_site', 'Response: [RAW]' );
			return $data;
		} else {
			MainWP_Logger::instance()->debug_for_website( $website, 'fetch_url', '[' . $url . '] Error: NOMAINWP' );
			$detect_wsidchk = is_string( $data ) ? strpos( $data, 'wsidchk' ) : false;
			if ( false !== $detect_wsidchk ) {
				$thr_error = new MainWP_Exception( 'ERROR:Connection Failed. We suspect that Imunify360, a security layer added by your host, is causing this problem. Please contact your host to whitelist your Dashboard IP in their system. If you need help determining your MainWP Dashboard site IP address, check with your hosting provider.', $url );
			} else {
				$thr_error = new MainWP_Exception( 'NOMAINWP', $url ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		if ( null !== $thr_error ) {
			$thr_error->set_data( $data );
			throw $thr_error;
		}
	}

	/**
	 * Method check_constraints()
	 *
	 * Check connection delay constraints.
	 *
	 * @param mixed $identifier Lock identifier.
	 * @param mixed $website Object child site.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::close_open_requests()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_wp_ip()
	 * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
	 */
	private static function check_constraints( &$identifier, $website ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
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

			if ( 0 < $minimumIPDelay && null !== $website ) {
				$ip = MainWP_DB::instance()->get_wp_ip( $website->id );
				if ( null !== $ip && '' !== $ip ) {
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

			if ( 0 < $maximumIPRequests && null !== $website ) {
				$ip = MainWP_DB::instance()->get_wp_ip( $website->id );
				if ( null !== $ip && '' !== $ip ) {
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_last_request_timestamp()
	 */
	private static function check_constraints_last_request( $identifier, $minimumDelay, $ip = null ) {
		$lastRequest = MainWP_DB_Common::instance()->get_last_request_timestamp( $ip );
		if ( $lastRequest > ( ( microtime( true ) ) - $minimumDelay ) ) {
			self::release( $identifier );
			$sleep = ( $minimumDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000;
			$sleep = intval( $sleep );
			usleep( $sleep );
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_nrof_open_requests()
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
	 * @param mixed $url Download URL.
	 * @param mixed $file File to download to.
	 * @param bool  $size Size of file.
	 * @param null  $http_user htaccess username.
	 * @param null  $http_pass htaccess password.
	 *
	 * @throws MainWP_Exception Exception message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_System::$version
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 */
	public static function download_to_file( $url, $file, $size = false, $http_user = null, $http_pass = null ) {

		$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

		/**
		 * WordPress files system object.
		 *
		 * @global object
		 */
		global $wp_filesystem;

		if ( $wp_filesystem->exists( $file ) && ( ( false === $size ) || ( $wp_filesystem->size( $file ) > $size ) ) ) {
			$wp_filesystem->delete( $file );
		}

		if ( ! $wp_filesystem->exists( dirname( $file ) ) ) {
			$wp_filesystem->mkdir( dirname( $file ), 0777 );
		}

		if ( ! $wp_filesystem->exists( dirname( $file ) ) ) {
			throw new MainWP_Exception( esc_html__( 'MainWP plugin could not create directory in order to download the file.', 'mainwp' ) );
		}

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! $wp_filesystem->is_writable( @dirname( $file ) ) ) {
				throw new MainWP_Exception( esc_html__( 'MainWP upload directory is not writable.', 'mainwp' ) );
			}
		} elseif ( ! is_writable( @dirname( $file ) ) ) { //phpcs:ignore -- ok.
			throw new MainWP_Exception( esc_html__( 'MainWP upload directory is not writable.', 'mainwp' ) );
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
		if ( 'resource' === gettype( $ch ) ) {
			curl_close( $ch );
		}
		fclose( $fp );
	}

	/**
	 * Method init_coockiesdir()
	 *
	 * Check for cookies directory and create it if it doesn't already exist,
	 * set the file permissions and update htaccess.
	 *
	 * @param mixed $cookieDir Cookies directory.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 */
	public static function init_cookiesdir( $cookieDir ) {

			$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

			/**
			 * WordPress files system object.
			 *
			 * @global object
			 */
			global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {

			if ( ! $wp_filesystem->is_dir( $cookieDir ) ) {
				$wp_filesystem->mkdir( $cookieDir, 0777 );
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::$version
	 */
	public static function get_file_content( $url ) {
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
		if ( 'resource' === gettype( $ch ) ) {
			curl_close( $ch );
		}
		if ( 200 === (int) $httpCode ) {
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_icons_dir()
	 * @uses \MainWP\Dashboard\MainWP_Utility::remove_http_prefix()
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
			$faviurl = false;
		}

		return $faviurl;
	}
}
