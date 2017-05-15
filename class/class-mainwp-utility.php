<?php

class MainWP_Utility {
	public static function startsWith( $haystack, $needle ) {
		return ! strncmp( $haystack, $needle, strlen( $needle ) );
	}

	public static function endsWith( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( $length == 0 ) {
			return true;
		}

		return ( substr( $haystack, - $length ) === $needle );
	}

	public static function getNiceURL( $pUrl, $showHttp = false ) {
		$url = $pUrl;

		if ( self::startsWith( $url, 'http://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 7 );
			}
		} else if ( self::startsWith( $pUrl, 'https://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 8 );
			}
		} else {
			if ( $showHttp ) {
				$url = 'http://' . $url;
			}
		}

		if ( self::endsWith( $url, '/' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 0, strlen( $url ) - 1 );
			}
		} else {
			$url = $url . '/';
		}

		return $url;
	}

	public static function limitString( $pInput, $pMax = 500 ) {
		$output = strip_tags( $pInput );
		if ( strlen( $output ) > $pMax ) {
			// truncate string
			$outputCut = substr( $output, 0, $pMax );
			// make sure it ends in a word so assassinate doesn't become ass...
			$output = substr( $outputCut, 0, strrpos( $outputCut, ' ' ) ) . '...';
		}
		echo $output;
	}

	public static function isAdmin() {
		global $current_user;
		if ( $current_user->ID == 0 ) {
			return false;
		}

		if ( $current_user->wp_user_level == 10 || ( isset( $current_user->user_level ) && $current_user->user_level == 10 ) || current_user_can( 'level_10' ) ) {
			return true;
		}

		return false;
	}

	public static function isWebsiteAvailable( $website ) {
		$http_user = null;
		$http_pass = null;
		$sslVersion = null;
		$verifyCertificate = null;
		if ( is_object( $website ) && isset( $website->url ) ) {
			$url               = $website->url;
			$verifyCertificate = isset( $website->verify_certificate ) ? $website->verify_certificate : null;
			$http_user         = $website->http_user;
			$http_pass         = $website->http_pass;
			$sslVersion        = $website->ssl_version;
		} else {
			$url               = $website;
		}

		if ( ! self::isDomainValid( $url ) ) {
			return false;
		}

		return MainWP_Utility::tryVisit( $url, $verifyCertificate, $http_user, $http_pass, $sslVersion );
	}

	private static function isDomainValid( $url ) {
		//check, if a valid url is provided
		return filter_var( $url, FILTER_VALIDATE_URL );
	}


	public static function tryVisit( $url, $verifyCertificate = null, $http_user = null, $http_pass = null, $sslVersion = 0 ) {
		//$agent    = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		$postdata = array( 'test' => 'yes' );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
			curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
		}

		$ssl_verifyhost = false;
		if ( $verifyCertificate !== null ) {
			if ( $verifyCertificate == 1 ) {
				$ssl_verifyhost = true;
			} else if ( $verifyCertificate == 2 ) { // use global setting
				if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
					$ssl_verifyhost = true;
				}
			}
		} else {
			if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
				$ssl_verifyhost = true;
			}
		}

		if ( $ssl_verifyhost ) {
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		} else {
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		}

		@curl_setopt( $ch, CURLOPT_SSLVERSION, $sslVersion );

		$disabled_functions = ini_get( 'disable_functions' );
		if ( empty( $disabled_functions ) || ( stristr( $disabled_functions, 'curl_multi_exec' ) === false ) ) {
			$mh = curl_multi_init();
			@curl_multi_add_handle( $mh, $ch );

			do {
				curl_multi_exec( $mh, $running ); //Execute handlers
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

		MainWP_Logger::Instance()->debug( ' :: tryVisit :: [url=' . $url . '] [http_status=' . $http_status . '] [error=' . $err . '] [data=' . $data . ']' );

		$host   = parse_url( ( empty( $realurl ) ? $url : $realurl ), PHP_URL_HOST );
		$ip     = false;
		$target = false;

		$dnsRecord = @dns_get_record( $host );
		MainWP_Logger::Instance()->debug( ' :: tryVisit :: [dnsRecord=' . print_r( $dnsRecord, 1 ) . ']' );
		if ( $dnsRecord === false ) {
			$data = false;
		} else if ( is_array( $dnsRecord ) ) {
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
						if ( $dnsRec['type'] == 'CNAME' ) {
							$target = $dnsRec['target'];
						}
						$found = true;
						break;
					}
				}
			} else {
				$found = ( $dnsRecord['host'] == $host );
				if ( $dnsRecord['type'] == 'CNAME' ) {
					$target = $dnsRecord['target'];
				}
			}

			if ( ! $found ) {
				$data = false;
				//return array( 'error' => ( $err == '' ? 'Invalid host.' : $err ) ); // Got redirected to: ' . $dnsRecord['host'])));
			}
		}

		if ( $ip === false ) {
			$ip = gethostbynamel( $host );
		}
		if ( ( $target !== false ) && ( $target != $host ) ) {
			$host .= ' (CNAME: ' . $target . ')';
		}

		$out = array(
			'host'           => $host,
			'httpCode'       => $http_status,
			'error'          => ( $err == '' && $data === false ? 'Invalid host.' : $err ),
			'httpCodeString' => self::getHttpStatusErrorString( $http_status ),
		);
		if ( $ip !== false ) {
			$out['ip'] = $ip;
		}

		return $out;
	}


	protected static function getHttpStatusErrorString( $httpCode ) {
		if ( $httpCode == 100 ) {
			return 'Continue';
		}
		if ( $httpCode == 101 ) {
			return 'Switching Protocols';
		}
		if ( $httpCode == 200 ) {
			return 'OK';
		}
		if ( $httpCode == 201 ) {
			return 'Created';
		}
		if ( $httpCode == 202 ) {
			return 'Accepted';
		}
		if ( $httpCode == 203 ) {
			return 'Non-Authoritative Information';
		}
		if ( $httpCode == 204 ) {
			return 'No Content';
		}
		if ( $httpCode == 205 ) {
			return 'Reset Content';
		}
		if ( $httpCode == 206 ) {
			return 'Partial Content';
		}
		if ( $httpCode == 300 ) {
			return 'Multiple Choices';
		}
		if ( $httpCode == 301 ) {
			return 'Moved Permanently';
		}
		if ( $httpCode == 302 ) {
			return 'Found';
		}
		if ( $httpCode == 303 ) {
			return 'See Other';
		}
		if ( $httpCode == 304 ) {
			return 'Not Modified';
		}
		if ( $httpCode == 305 ) {
			return 'Use Proxy';
		}
		if ( $httpCode == 306 ) {
			return '(Unused)';
		}
		if ( $httpCode == 307 ) {
			return 'Temporary Redirect';
		}
		if ( $httpCode == 400 ) {
			return 'Bad Request';
		}
		if ( $httpCode == 401 ) {
			return 'Unauthorized';
		}
		if ( $httpCode == 402 ) {
			return 'Payment Required';
		}
		if ( $httpCode == 403 ) {
			return 'Forbidden';
		}
		if ( $httpCode == 404 ) {
			return 'Not Found';
		}
		if ( $httpCode == 405 ) {
			return 'Method Not Allowed';
		}
		if ( $httpCode == 406 ) {
			return 'Not Acceptable';
		}
		if ( $httpCode == 407 ) {
			return 'Proxy Authentication Required';
		}
		if ( $httpCode == 408 ) {
			return 'Request Timeout';
		}
		if ( $httpCode == 409 ) {
			return 'Conflict';
		}
		if ( $httpCode == 410 ) {
			return 'Gone';
		}
		if ( $httpCode == 411 ) {
			return 'Length Required';
		}
		if ( $httpCode == 412 ) {
			return 'Precondition Failed';
		}
		if ( $httpCode == 413 ) {
			return 'Request Entity Too Large';
		}
		if ( $httpCode == 414 ) {
			return 'Request-URI Too Long';
		}
		if ( $httpCode == 415 ) {
			return 'Unsupported Media Type';
		}
		if ( $httpCode == 416 ) {
			return 'Requested Range Not Satisfiable';
		}
		if ( $httpCode == 417 ) {
			return 'Expectation Failed';
		}
		if ( $httpCode == 500 ) {
			return 'Internal Server Error';
		}
		if ( $httpCode == 501 ) {
			return 'Not Implemented';
		}
		if ( $httpCode == 502 ) {
			return 'Bad Gateway';
		}
		if ( $httpCode == 503 ) {
			return 'Service Unavailable';
		}
		if ( $httpCode == 504 ) {
			return 'Gateway Timeout';
		}
		if ( $httpCode == 505 ) {
			return 'HTTP Version Not Supported';
		}

		return null;
	}

    static function check_ignored_http_code( $value ) {
        if ( $value == 200 )
            return true;

        $ignored_code = get_option( 'mainwp_ignore_HTTP_response_status', '' );
        $ignored_code = trim($ignored_code);
        if ( !empty( $ignored_code ) ) {
            $ignored_code = explode( ',', $ignored_code );
            foreach( $ignored_code as $code ) {
                $code = trim( $code );
                if ( $value == $code ) {
                    return true;
                }
            }
        }
        return false;
    }

    static function activated_primary_backup_plugin( $what, $website ) {
        $plugins = json_decode( $website->plugins, 1 );
        if ( !is_array( $plugins ) || count( $plugins ) == 0 ) {
            return false;
        }

        $installed = false;
        switch( $what ) {
            case 'backupbuddy':
                foreach ( $plugins as $plugin ) {
                    if ( ('backupbuddy/backupbuddy.php' == strtolower($plugin['slug'])) ) {
                        if ( $plugin['active'] ) {
                            $installed = true;
                        }
                        break;
                    }
                }
                break;
            case 'backupwp':
                foreach ( $plugins as $plugin ) {
                    if ( ('backupwordpress/backupwordpress.php' == $plugin['slug']) ) {
                        if ( $plugin['active'] ) {
                            $installed = true;
                        }
                        break;
                    }
                }
                break;
            case 'backwpup':
                foreach ( $plugins as $plugin ) {
                    if ( ( strcmp( $plugin['slug'], "backwpup/backwpup.php" ) === 0 ) || strcmp( $plugin['slug'], "backwpup-pro/backwpup.php" ) === 0 ) {
                        if ( $plugin['active'] ) {
                            $installed = true;
                        }
                        break;
                    }
                }
                break;
            case 'updraftplus':
                foreach ( $plugins as $plugin ) {
                    if ( ('updraftplus/updraftplus.php' == $plugin['slug']) ) {
                        if ( $plugin['active'] ) {
                            $installed = true;
                        }
                        break;
                    }
                }
                break;
        }
        return $installed;
    }

     public static function get_primary_backup() {
        $enable_legacy_backup = get_option( 'mainwp_enableLegacyBackupFeature' );
        if ( !$enable_legacy_backup ) {
            return get_option( 'mainwp_primaryBackup', false );
        }
        return false;
    }

	static function getNotificationEmail( $user = null ) {
		if ( $user == null ) {
			global $current_user;
			$user = $current_user;
		}

		if ( $user == null ) {
			return null;
		}

		if ( ! ( $user instanceof WP_User ) ) {
			return null;
		}

		$userExt = MainWP_DB::Instance()->getUserExtension();
		if ( $userExt->user_email != '' ) {
			return $userExt->user_email;
		}

		return $user->user_email;
	}

	/*
     * $website: Expected object ($website->id, $website->url, ... returned by MainWP_DB)
     * $what: What function needs to be called - defined in the mainwpplugin
     * $params: (Optional) array(key => value, key => value);
     */

	static function getPostDataAuthed( &$website, $what, $params = null ) {
		if ( $website && $what != '' ) {
			$data             = array();
			$data['user']     = $website->adminname;
			$data['function'] = $what;
			$data['nonce']    = rand( 0, 9999 );
			if ( $params != null ) {
				$data = array_merge( $data, $params );
			}

			if ( ( $website->nossl == 0 ) && function_exists( 'openssl_verify' ) ) {
				$data['nossl'] = 0;
				@openssl_sign( $what . $data['nonce'], $signature, base64_decode( $website->privkey ) );
			} else {
				$data['nossl'] = 1;
				$signature     = md5( $what . $data['nonce'] . $website->nosslkey );
			}
			$data['mainwpsignature'] = base64_encode( $signature );

			return http_build_query( $data, '', '&' );
		}

		return null;
	}

	static function getGetDataAuthed( $website, $paramValue, $paramName = 'where', $asArray = false ) {
		$params = array();
		if ( $website && $paramValue != '' ) {
			$nonce = rand( 0, 9999 );
			if ( ( $website->nossl == 0 ) && function_exists( 'openssl_verify' ) ) {
				$nossl = 0;
				openssl_sign( $paramValue . $nonce, $signature, base64_decode( $website->privkey ) );
			} else {
				$nossl     = 1;
				$signature = md5( $paramValue . $nonce . $website->nosslkey );
			}
			$signature = base64_encode( $signature );

			$params = array(
				'login_required'  => 1,
				'user'            => rawurlencode( $website->adminname ),
				'mainwpsignature' => rawurlencode( $signature ),
				'nonce'           => $nonce,
				'nossl'           => $nossl,
				$paramName        => rawurlencode( $paramValue ),
			);
		}

		if ( $asArray ) {
			return $params;
		}

		$url = ( isset( $website->url ) && $website->url != '' ? $website->url : $website->siteurl );
		$url .= ( substr( $url, - 1 ) != '/' ? '/' : '' );
		$url .= '?';

		foreach ( $params as $key => $value ) {
			$url .= $key . '=' . $value . '&';
		}

		return rtrim( $url, '&' );
	}

	/*
     * $url: String
     * $admin: admin username
     * $what: What function needs to be called - defined in the mainwpplugin
     * $params: (Optional) array(key => value, key => value);
     */

	static function getPostDataNotAuthed( $url, $admin, $what, $params = null ) {
		if ( $url != '' && $admin != '' && $what != '' ) {
			$data             = array();
			$data['user']     = $admin;
			$data['function'] = $what;
			if ( $params != null ) {
				$data = array_merge( $data, $params );
			}

			return http_build_query( $data, '', '&' );
		}

		return null;
	}

	/*
     * $websites: Expected array of objects ($website->id, $website->url, ... returned by MainWP_DB) indexed by the object->id
     * $what: What function needs to be called - defined in the mainwpplugin
     * $params: (Optional) array(key => value, key => value);
     * $handler: Name of a function to be called:
     *      function handler($data, $website, &$output) {}
     *          the $data = data returned by the request, $website = website object returned by MainWP_DB
     *          $output has to be filled in by the handler-function - it is used as an output variable!
     */

	static function fetchUrlsAuthed( &$websites, $what, $params = null, $handler, &$output, $whatPage = null ) {
		if ( ! is_array( $websites ) || empty( $websites ) ) {
			return false;
		}

		$chunkSize = 10;
		if ( count( $websites ) > $chunkSize ) {
			$total = count( $websites );
			$loops = ceil( $total / $chunkSize );
			for ( $i = 0; $i < $loops; $i ++ ) {
				$newSites = array_slice( $websites, $i * $chunkSize, $chunkSize, true );
				self::fetchUrlsAuthed( $newSites, $what, $params, $handler, $output, $whatPage );
				sleep( 5 );
			}

			return false;
		}

		$debug = false;
		if ($debug)
		{
			$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';

			$timeout = 20 * 60 * 60; //20 minutes

			$handleToWebsite    = array();
			$requestUrls        = array();
			$requestHandles     = array();

			$dirs      = self::getMainWPDir();
			$cookieDir = $dirs[0] . 'cookies';
			if ( ! @is_dir( $cookieDir ) ) {
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

			foreach ( $websites as $website ) {
				$url = $website->url;
				if ( substr( $url, - 1 ) != '/' ) {
					$url .= '/';
				}

				if ( strpos( $url, 'wp-admin' ) === false ) {
					$url .= 'wp-admin/';
				}

				if ( $whatPage != null ) {
					$url .= $whatPage;
				} else {
					$url .= 'admin-ajax.php';
				}

				if ( property_exists( $website, 'http_user' ) ) $http_user = $website->http_user;
				if ( property_exists( $website, 'http_pass' ) ) $http_pass = $website->http_pass;

				$_new_post = null;
				if ( isset( $params ) && isset( $params['new_post'] ) ) {
					$_new_post = $params['new_post'];
					$params    = apply_filters( 'mainwp-pre-posting-posts', ( is_array( $params ) ? $params : array() ), (object) array(
						'id'   => $website->id,
						'url'  => $website->url,
						'name' => $website->name,
					) );
				}

				$ch = curl_init();

				if ( $website != null ) {
					$cookieFile = $cookieDir . '/' . sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' );
					if ( ! file_exists( $cookieFile ) ) {
						@file_put_contents( $cookieFile, '' );
					}

					if ( file_exists( $cookieFile ) ) {
						@chmod( $cookieFile, 0777 );
						@curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
						@curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
					}
				}

				@curl_setopt( $ch, CURLOPT_URL, $url );
				@curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				@curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
				@curl_setopt( $ch, CURLOPT_POST, true );
				$postdata = MainWP_Utility::getPostDataAuthed( $website, $what, $params );
				@curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
				@curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
				@curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
				if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
					curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
				}

				$ssl_verifyhost    = false;
				$verifyCertificate = isset( $website->verify_certificate ) ? $website->verify_certificate : null;
				if ( $verifyCertificate !== null ) {
					if ( $verifyCertificate == 1 ) {
						$ssl_verifyhost = true;
					} else if ( $verifyCertificate == 2 ) { // use global setting
						if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
							$ssl_verifyhost = true;
						}
					}
				} else {
					if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
						$ssl_verifyhost = true;
					}
				}

				if ( $ssl_verifyhost ) {
					@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
					@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
				} else {
					@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
					@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
				}

				@curl_setopt( $ch, CURLOPT_SSLVERSION, $website->ssl_version );

				@curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout ); //20minutes
				if ( version_compare(phpversion(), '5.3.0') >= 0 || ! ini_get( 'safe_mode' ) ) {
					@set_time_limit( $timeout );
				} //20minutes
				@ini_set( 'max_execution_time', $timeout );

				$handleToWebsite[ self::get_resource_id( $ch ) ] = $website;
				$requestUrls[ self::get_resource_id( $ch ) ]     = $website->url;
				$requestHandles[ self::get_resource_id( $ch ) ]  = $ch;

				if ( $_new_post != null ) {
					$params['new_post'] = $_new_post;
				} // reassign new_post
			}

			foreach ( $requestHandles as $id => $ch ) {
				$website = &$handleToWebsite[ self::get_resource_id( $ch ) ];

				$identifier = null;
				$semLock = '103218'; //SNSyncLock
				//Lock
				$identifier = MainWP_Utility::getLockIdentifier( $semLock );

				//Check the delays
				//In MS
				$minimumDelay = ( ( get_option( 'mainwp_minimumDelay' ) === false ) ? 200 : get_option( 'mainwp_minimumDelay' ) );
				if ( $minimumDelay > 0 ) {
					$minimumDelay = $minimumDelay / 1000;
				}
				$minimumIPDelay = ( ( get_option( 'mainwp_minimumIPDelay' ) === false ) ? 400 : get_option( 'mainwp_minimumIPDelay' ) );
				if ( $minimumIPDelay > 0 ) {
					$minimumIPDelay = $minimumIPDelay / 1000;
				}

				MainWP_Utility::endSession();
				$delay = true;
				while ( $delay ) {
					MainWP_Utility::lock( $identifier );

					if ( $minimumDelay > 0 ) {
						//Check last request overall
						$lastRequest = MainWP_DB::Instance()->getLastRequestTimestamp();
						if ( $lastRequest > ( ( microtime( true ) ) - $minimumDelay ) ) {
							//Delay!
							MainWP_Utility::release( $identifier );
							usleep( ( $minimumDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000 );
							continue;
						}
					}

					if ( $minimumIPDelay > 0 && $website != null ) {
						//Get ip of this site url
						$ip = MainWP_DB::Instance()->getWPIp( $website->id );

						if ( $ip != null && $ip != '' ) {
							//Check last request for this site
							$lastRequest = MainWP_DB::Instance()->getLastRequestTimestamp( $ip );

							//Check last request for this subnet?
							if ( $lastRequest > ( ( microtime( true ) ) - $minimumIPDelay ) ) {
								//Delay!
								MainWP_Utility::release( $identifier );
								usleep( ( $minimumIPDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000 );
								continue;
							}
						}
					}

					$delay = false;
				}

				//Check the simultaneous requests
				$maximumRequests   = ( ( get_option( 'mainwp_maximumRequests' ) === false ) ? 4 : get_option( 'mainwp_maximumRequests' ) );
				$maximumIPRequests = ( ( get_option( 'mainwp_maximumIPRequests' ) === false ) ? 1 : get_option( 'mainwp_maximumIPRequests' ) );

				$first = true;
				$delay = true;
				while ( $delay ) {
					if ( ! $first ) {
						MainWP_Utility::lock( $identifier );
					} else {
						$first = false;
					}

					//Clean old open requests (may have timed out or something..)
					MainWP_DB::Instance()->closeOpenRequests();

					if ( $maximumRequests > 0 ) {
						$nrOfOpenRequests = MainWP_DB::Instance()->getNrOfOpenRequests();
						if ( $nrOfOpenRequests >= $maximumRequests ) {
							//Delay!
							MainWP_Utility::release( $identifier );
							//Wait 200ms
							usleep( 200000 );
							continue;
						}
					}

					if ( $maximumIPRequests > 0 && $website != null ) {
						//Get ip of this site url
						$ip = MainWP_DB::Instance()->getWPIp( $website->id );

						if ( $ip != null && $ip != '' ) {
							$nrOfOpenRequests = MainWP_DB::Instance()->getNrOfOpenRequests( $ip );
							if ( $nrOfOpenRequests >= $maximumIPRequests ) {
								//Delay!
								MainWP_Utility::release( $identifier );
								//Wait 200ms
								usleep( 200000 );
								continue;
							}
						}
					}

					$delay = false;
				}


				if ( $website != null ) {
					//Log the start of this request!
					MainWP_DB::Instance()->insertOrUpdateRequestLog( $website->id, null, microtime( true ), null );
				}

				if ( $identifier != null ) {
					//Unlock
					MainWP_Utility::release( $identifier );
				}

				$data = curl_exec( $ch );

				if ( $website != null ) {
					MainWP_DB::Instance()->insertOrUpdateRequestLog( $website->id, $ip, null, microtime( true ) );
				}

				if ( $handler != null ) {
					call_user_func_array( $handler, array($data, $website, &$output) );
				}
			}

			return true;
		}

		//$agent = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		$mh    = curl_multi_init();

		$timeout = 20 * 60 * 60; //20 minutes

		$disabled_functions = ini_get( 'disable_functions' );
		$handleToWebsite    = array();
		$requestUrls        = array();
		$requestHandles     = array();

		$dirs      = self::getMainWPDir();
		$cookieDir = $dirs[0] . 'cookies';
		if ( ! @is_dir( $cookieDir ) ) {
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

		foreach ( $websites as $website ) {
			$url = $website->url;
			if ( substr( $url, - 1 ) != '/' ) {
				$url .= '/';
			}

			if ( strpos( $url, 'wp-admin' ) === false ) {
				$url .= 'wp-admin/';
			}

			if ( $whatPage != null ) {
				$url .= $whatPage;
			} else {
				$url .= 'admin-ajax.php';
			}

			if ( property_exists( $website, 'http_user' ) ) $http_user = $website->http_user;
			if ( property_exists( $website, 'http_pass' ) ) $http_pass = $website->http_pass;

			$_new_post = null;
			if ( isset( $params ) && isset( $params['new_post'] ) ) {
				$_new_post = $params['new_post'];
				$params    = apply_filters( 'mainwp-pre-posting-posts', ( is_array( $params ) ? $params : array() ), (object) array(
					'id'   => $website->id,
					'url'  => $website->url,
					'name' => $website->name,
				) );
			}

			$ch = curl_init();

			if ( $website != null ) {
				$cookieFile = $cookieDir . '/' . sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' );
				if ( ! file_exists( $cookieFile ) ) {
					@file_put_contents( $cookieFile, '' );
				}

				if ( file_exists( $cookieFile ) ) {
					@chmod( $cookieFile, 0777 );
					@curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
					@curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
				}
			}

			@curl_setopt( $ch, CURLOPT_URL, $url );
			@curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			@curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			@curl_setopt( $ch, CURLOPT_POST, true );
			$postdata = MainWP_Utility::getPostDataAuthed( $website, $what, $params );
			@curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
			@curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
			@curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
			if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
				curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
			}

			$ssl_verifyhost    = false;
			$verifyCertificate = isset( $website->verify_certificate ) ? $website->verify_certificate : null;
			if ( $verifyCertificate !== null ) {
				if ( $verifyCertificate == 1 ) {
					$ssl_verifyhost = true;
				} else if ( $verifyCertificate == 2 ) { // use global setting
					if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
						$ssl_verifyhost = true;
					}
				}
			} else {
				if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
					$ssl_verifyhost = true;
				}
			}

			if ( $ssl_verifyhost ) {
				@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
				@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
			} else {
				@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
				@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			}

			@curl_setopt( $ch, CURLOPT_SSLVERSION, $website->ssl_version );

			@curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout ); //20minutes
			if ( version_compare(phpversion(), '5.3.0') >= 0 || ! ini_get( 'safe_mode' ) ) {
				@set_time_limit( $timeout );
			} //20minutes
			@ini_set( 'max_execution_time', $timeout );

			if ( empty( $disabled_functions ) || ( stristr( $disabled_functions, 'curl_multi_exec' ) === false ) ) {
				@curl_multi_add_handle( $mh, $ch );
			}

			$handleToWebsite[ self::get_resource_id( $ch ) ] = $website;
			$requestUrls[ self::get_resource_id( $ch ) ]     = $website->url;
			$requestHandles[ self::get_resource_id( $ch ) ]  = $ch;

			if ( $_new_post != null ) {
				$params['new_post'] = $_new_post;
			} // reassign new_post
		}

		if ( empty( $disabled_functions ) || ( stristr( $disabled_functions, 'curl_multi_exec' ) === false ) ) {
			$lastRun = 0;
			do {
				if ( time() - $lastRun > 20 ) {
					@set_time_limit( $timeout ); //reset timer..
					$lastRun = time();
				}

				curl_multi_exec( $mh, $running ); //Execute handlers
				while ( $info = curl_multi_info_read( $mh ) ) {
					$data     = curl_multi_getcontent( $info['handle'] );
					$contains = ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 );
					curl_multi_remove_handle( $mh, $info['handle'] );

					if ( ! $contains && isset( $requestUrls[ self::get_resource_id( $info['handle'] ) ] ) ) {
						curl_setopt( $info['handle'], CURLOPT_URL, $requestUrls[ self::get_resource_id( $info['handle'] ) ] );
						curl_multi_add_handle( $mh, $info['handle'] );
						unset( $requestUrls[ self::get_resource_id( $info['handle'] ) ] );
						$running ++;
						continue;
					}

					if ( $handler != null ) {
						$site = &$handleToWebsite[ self::get_resource_id( $info['handle'] ) ];
						call_user_func_array( $handler, array($data, $site, &$output) );
					}

					unset( $handleToWebsite[ self::get_resource_id( $info['handle'] ) ] );
					if ( gettype( $info['handle'] ) == 'resource' ) {
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

				if ( $handler != null ) {
					$site = &$handleToWebsite[ self::get_resource_id( $ch ) ];
					call_user_func_array( $handler, array($data, $site, &$output) );
				}
			}
		}

		return true;
	}

	static function fetchUrlAuthed( &$website, $what, $params = null, $checkConstraints = false, $pForceFetch = false, $pRetryFailed = true ) {
		if ( $params == null ) {
			$params = array();
		}

		if ( $what == 'stats' || ( $what == 'upgradeplugintheme' && isset( $params['type'] ) && 'plugin' == $params['type'] ) ) {
			// to fix bug: update upgrade plugin information
			$try_tounch_plugins_page = get_option( 'mainwp_request_plugins_page_site_' . $website->id );
			if ('yes' == $try_tounch_plugins_page) {
				$page_plugins_url = MainWP_Utility::getGetDataAuthed( $website, 'plugins.php' );
				wp_remote_get( $page_plugins_url, array( 'timeout' => 25, 'httpversion' => '1.1' ) );
			}
		}

		$params['optimize'] = ( ( get_option( 'mainwp_optimize' ) == 1 ) ? 1 : 0 );

		$postdata    = MainWP_Utility::getPostDataAuthed( $website, $what, $params );
		$information = MainWP_Utility::fetchUrl( $website, $website->url, $postdata, $checkConstraints, $pForceFetch, $website->verify_certificate, $pRetryFailed, $website->http_user, $website->http_pass, $website->ssl_version );

		if ( is_array( $information ) && isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
			MainWP_Sync::syncInformationArray( $website, $information['sync'] );
			unset( $information['sync'] );
		}

        if ( $what == 'upgradeplugintheme' || $what == 'upgrade' || $what == 'upgradetranslation' ) {

            if ( $what == 'upgradeplugintheme' || $what == 'upgradetranslation' ) {
                $type = $params['type'];
                $list = $params['list'];
            } else {
                $type = 'wp';
                $list = '';
            }

            do_action( 'mainwp_website_updated', $website, $type, $list, $information );

            if ( 1 == get_option( 'mainwp_check_http_response', 0 ) ) {
                $result = MainWP_Utility::isWebsiteAvailable( $website );
                $http_code = ( is_array($result) && isset( $result['httpCode'] ) ) ? $result['httpCode'] : 0;
                $online_detected = MainWP_Utility::check_ignored_http_code( $http_code );
                MainWP_DB::Instance()->updateWebsiteValues( $website->id, array(
                    'offline_check_result' => $online_detected ? 1 : -1,
                    'offline_checks_last'  => time(),
                    'http_response_code' => $http_code
                ) );

                if ( defined( 'DOING_CRON' ) && DOING_CRON && !$online_detected) {
                    $sitesHttpChecks = get_option( 'mainwp_automaticUpdate_httpChecks' );
                    if ( !is_array( $sitesHttpChecks ) ) {
                        $sitesHttpChecks = array();
                    }

                    if ( !in_array( $website->id, $sitesHttpChecks ) ) {
                        $sitesHttpChecks[] = $website->id;
                        MainWP_Utility::update_option( 'mainwp_automaticUpdate_httpChecks', $sitesHttpChecks );
                    }
                }
            }
        }

		return $information;
	}

	static function fetchUrlNotAuthed( $url, $admin, $what, $params = null, $pForceFetch = false, $verifyCertificate = null, $http_user = null, $http_pass = null, $sslVersion = 0 ) {
		$postdata = MainWP_Utility::getPostDataNotAuthed( $url, $admin, $what, $params );
		$website  = null;

		return MainWP_Utility::fetchUrl( $website, $url, $postdata, false, $pForceFetch, $verifyCertificate, true, $http_user, $http_pass,  $sslVersion);
	}

	static function fetchUrlClean( $url, $postdata ) {
		//$agent = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );

		if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		} else {
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		}

		$data = curl_exec( $ch );
		curl_close( $ch );
		if ( ! $data ) {
			throw new Exception( 'HTTPERROR' );
		} else {
			return $data;
		}
	}

	static function fetchUrl( &$website, $url, $postdata, $checkConstraints = false, $pForceFetch = false, $verifyCertificate = null, $pRetryFailed = true, $http_user = null, $http_pass = null, $sslVersion = 0 ) {
		$start = time();

		try {
			$tmpUrl = $url;
			if ( substr( $tmpUrl, - 1 ) != '/' ) {
				$tmpUrl .= '/';
			}

			if ( strpos( $url, 'wp-admin' ) === false ) {
				$tmpUrl .= 'wp-admin/admin-ajax.php';
			}

			return self::_fetchUrl( $website, $tmpUrl, $postdata, $checkConstraints, $pForceFetch, $verifyCertificate, $http_user, $http_pass, $sslVersion );
		} catch ( Exception $e ) {
			if ( ! $pRetryFailed || ( ( time() - $start ) > 30 ) ) {
				//If more then 30secs past since the initial request, do not retry this!
				throw $e;
			}

			try {
				return self::_fetchUrl( $website, $url, $postdata, $checkConstraints, $pForceFetch, $verifyCertificate, $http_user, $http_pass, $sslVersion );
			} catch ( Exception $ex ) {
				throw $e;
			}
		}
	}

	static function _fetchUrl( &$website, $url, $postdata, $checkConstraints = false, $pForceFetch = false, $verifyCertificate = null, $http_user = null, $http_pass = null, $sslVersion = 0 ) {
		//$agent = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';

		MainWP_Logger::Instance()->debugForWebsite( $website, '_fetchUrl', 'Request to ['. $url . '] [' . print_r( $postdata, 1 ) . ']' );

		if ( ! $pForceFetch ) {
			//todo: RS:
			//check if offline
		}

		$identifier = null;
		if ( $checkConstraints ) {
			$semLock = '103218'; //SNSyncLock
			//Lock
			$identifier = MainWP_Utility::getLockIdentifier( $semLock );

			//Check the delays
			//In MS
			$minimumDelay = ( ( get_option( 'mainwp_minimumDelay' ) === false ) ? 200 : get_option( 'mainwp_minimumDelay' ) );
			if ( $minimumDelay > 0 ) {
				$minimumDelay = $minimumDelay / 1000;
			}
			$minimumIPDelay = ( ( get_option( 'mainwp_minimumIPDelay' ) === false ) ? 1000 : get_option( 'mainwp_minimumIPDelay' ) );
			if ( $minimumIPDelay > 0 ) {
				$minimumIPDelay = $minimumIPDelay / 1000;
			}

			MainWP_Utility::endSession();
			$delay = true;
			while ( $delay ) {
				MainWP_Utility::lock( $identifier );

				if ( $minimumDelay > 0 ) {
					//Check last request overall
					$lastRequest = MainWP_DB::Instance()->getLastRequestTimestamp();
					if ( $lastRequest > ( ( microtime( true ) ) - $minimumDelay ) ) {
						//Delay!
						MainWP_Utility::release( $identifier );
						usleep( ( $minimumDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000 );
						continue;
					}
				}

				if ( $minimumIPDelay > 0 && $website != null ) {
					//Get ip of this site url
					$ip = MainWP_DB::Instance()->getWPIp( $website->id );

					if ( $ip != null && $ip != '' ) {
						//Check last request for this site
						$lastRequest = MainWP_DB::Instance()->getLastRequestTimestamp( $ip );

						//Check last request for this subnet?
						if ( $lastRequest > ( ( microtime( true ) ) - $minimumIPDelay ) ) {
							//Delay!
							MainWP_Utility::release( $identifier );
							usleep( ( $minimumIPDelay - ( ( microtime( true ) ) - $lastRequest ) ) * 1000 * 1000 );
							continue;
						}
					}
				}

				$delay = false;
			}

			//Check the simultaneous requests
			$maximumRequests   = ( ( get_option( 'mainwp_maximumRequests' ) === false ) ? 4 : get_option( 'mainwp_maximumRequests' ) );
			$maximumIPRequests = ( ( get_option( 'mainwp_maximumIPRequests' ) === false ) ? 1 : get_option( 'mainwp_maximumIPRequests' ) );

			$first = true;
			$delay = true;
			while ( $delay ) {
				if ( ! $first ) {
					MainWP_Utility::lock( $identifier );
				} else {
					$first = false;
				}

				//Clean old open requests (may have timed out or something..)
				MainWP_DB::Instance()->closeOpenRequests();

				if ( $maximumRequests > 0 ) {
					$nrOfOpenRequests = MainWP_DB::Instance()->getNrOfOpenRequests();
					if ( $nrOfOpenRequests >= $maximumRequests ) {
						//Delay!
						MainWP_Utility::release( $identifier );
						//Wait 200ms
						usleep( 200000 );
						continue;
					}
				}

				if ( $maximumIPRequests > 0 && $website != null ) {
					//Get ip of this site url
					$ip = MainWP_DB::Instance()->getWPIp( $website->id );

					if ( $ip != null && $ip != '' ) {
						$nrOfOpenRequests = MainWP_DB::Instance()->getNrOfOpenRequests( $ip );
						if ( $nrOfOpenRequests >= $maximumIPRequests ) {
							//Delay!
							MainWP_Utility::release( $identifier );
							//Wait 200ms
							usleep( 200000 );
							continue;
						}
					}
				}

				$delay = false;
			}
		}

		if ( $website != null ) {
			//Log the start of this request!
			MainWP_DB::Instance()->insertOrUpdateRequestLog( $website->id, null, microtime( true ), null );
		}

		if ( $identifier != null ) {
			//Unlock
			MainWP_Utility::release( $identifier );
		}

		$dirs      = self::getMainWPDir();
		$cookieDir = $dirs[0] . 'cookies';
		if ( ! @is_dir( $cookieDir ) ) {
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

		$ch = curl_init();

		if ( $website != null ) {
			$cookieFile = $cookieDir . '/' . sha1( sha1( 'mainwp' . LOGGED_IN_SALT . $website->id ) . NONCE_SALT . 'WP_Cookie' );
			if ( ! file_exists( $cookieFile ) ) {
				@file_put_contents( $cookieFile, '' );
			}

			if ( file_exists( $cookieFile ) ) {
				@chmod( $cookieFile, 0777 );
				@curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
				@curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
			}
		}

		@curl_setopt( $ch, CURLOPT_URL, $url );
		@curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		@curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		@curl_setopt( $ch, CURLOPT_POST, true );
		@curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		@curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		@curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
			@curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
		}

		$ssl_verifyhost = false;
		if ( $verifyCertificate !== null ) {
			if ( $verifyCertificate == 1 ) {
				$ssl_verifyhost = true;
			} else if ( $verifyCertificate == 2 ) { // use global setting
				if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
					$ssl_verifyhost = true;
				}
			}
		} else {
			if ( ( ( get_option( 'mainwp_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_sslVerifyCertificate' ) == 1 ) ) ) {
				$ssl_verifyhost = true;
			}
		}

		if ( $ssl_verifyhost ) {
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		} else {
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
			@curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		}

		@curl_setopt( $ch, CURLOPT_SSLVERSION, $sslVersion );

		$timeout = 20 * 60 * 60; //20 minutes
		@curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
		if ( version_compare(phpversion(), '5.3.0') >= 0 || ! ini_get( 'safe_mode' ) ) {
			@set_time_limit( $timeout );
		}
		@ini_set( 'max_execution_time', $timeout );
		MainWP_Utility::endSession();

		MainWP_Logger::Instance()->debugForWebsite( $website, '_fetchUrl', 'Executing handlers' );

		$disabled_functions = ini_get( 'disable_functions' );
		if ( empty( $disabled_functions ) || ( stristr( $disabled_functions, 'curl_multi_exec' ) === false ) ) {
			$mh = @curl_multi_init();
			@curl_multi_add_handle( $mh, $ch );

			$lastRun = 0;
			do {
				if ( time() - $lastRun > 20 ) {
					@set_time_limit( $timeout ); //reset timer..
					$lastRun = time();
				}
				@curl_multi_exec( $mh, $running ); //Execute handlers

				//$ready = curl_multi_select($mh);
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

		$host = parse_url( $real_url, PHP_URL_HOST );
		$ip   = gethostbyname( $host );

		if ( $website != null ) {
			MainWP_DB::Instance()->insertOrUpdateRequestLog( $website->id, $ip, null, microtime( true ) );
		}


		MainWP_Logger::Instance()->debugForWebsite( $website, '_fetchUrl', 'http status: [' . $http_status . '] err: [' . $err . '] data: [' . $data . ']' );
		if ($http_status == '400') MainWP_Logger::Instance()->debugForWebsite( $website, '_fetchUrl', 'post data: [' . print_r($postdata , 1). ']' );

		if ( ( $data === false ) && ( $http_status == 0 ) ) {
			MainWP_Logger::Instance()->debugForWebsite( $website, 'fetchUrl', '[' . $url . '] HTTP Error: [status=0][' . $err . ']' );
			throw new MainWP_Exception( 'HTTPERROR', $err );
		} else if ( empty( $data ) && ! empty( $err ) ) {
			MainWP_Logger::Instance()->debugForWebsite( $website, 'fetchUrl', '[' . $url . '] HTTP Error: [status=' . $http_status . '][' . $err . ']' );
			throw new MainWP_Exception( 'HTTPERROR', $err );
		} else if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$information = unserialize( base64_decode( $result ) );
			MainWP_Logger::Instance()->debugForWebsite( $website, '_fetchUrl', 'information: [OK]' );
			return $information;
		} else {
			MainWP_Logger::Instance()->debugForWebsite( $website, 'fetchUrl', '[' . $url . '] Result was: [' . $data . ']' );
			throw new MainWP_Exception( 'NOMAINWP', $url );
		}
	}

	static function ctype_digit( $str ) {
		return ( is_string( $str ) || is_int( $str ) || is_float( $str ) ) && preg_match( '/^\d+\z/', $str );
	}

	static function log( $text ) {

	}

	public static function downloadToFile( $url, $file, $size = false, $http_user = null, $http_pass = null ) {
		if ( @file_exists( $file ) && ( ( $size === false ) || ( @filesize( $file ) > $size ) ) ) {
			@unlink( $file );
		}

		if ( ! @file_exists( @dirname( $file ) ) ) {
			@mkdir( @dirname( $file ), 0777, true );
		}

		if ( ! @file_exists( @dirname( $file ) ) ) {
			throw new MainWP_Exception( __( 'MainWP plugin could not create directory in order to download the file.', 'mainwp' ) );
		}

		if ( ! @is_writable( @dirname( $file ) ) ) {
			throw new MainWP_Exception( __( 'MainWP upload directory is not writable.', 'mainwp' ) );
		}

		$fp    = fopen( $file, 'a' );
		//$agent = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		if ( $size !== false ) {
			if ( @file_exists( $file ) ) {
				$size = @filesize( $file );
				$url .= '&foffset=' . $size;
			}
		}
		$ch = curl_init( str_replace( ' ', '%20', $url ) );
		curl_setopt( $ch, CURLOPT_FILE, $fp );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		if ( ! empty( $http_user ) && ! empty( $http_pass ) ) {
			curl_setopt( $ch, CURLOPT_USERPWD, "$http_user:$http_pass" );
		}
		curl_exec( $ch );
		curl_close( $ch );
		fclose( $fp );
	}

	static function uploadImage( $img_url, $img_data = array()  ) {
		if (!is_array($img_data))
			$img_data = array();
		include_once( ABSPATH . 'wp-admin/includes/file.php' ); //Contains download_url
		//Download $img_url
		$temporary_file = download_url( $img_url );

		if ( is_wp_error( $temporary_file ) ) {
			throw new Exception( 'Error: ' . $temporary_file->get_error_message() );
		} else {
			$upload_dir     = wp_upload_dir();
			$local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename( $img_url ); //Local name
			$local_img_url  = $upload_dir['url'] . '/' . basename( $img_url );
			$moved          = @rename( $temporary_file, $local_img_path );
			if ( $moved ) {
				$wp_filetype = wp_check_filetype( basename( $img_url ), null ); //Get the filetype to set the mimetype
				$attachment  = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title'     => isset( $img_data['title'] ) && !empty( $img_data['title'] ) ? $img_data['title'] : preg_replace( '/\.[^.]+$/', '', basename( $img_url ) ),
					'post_content'   => isset( $img_data['description'] ) && !empty( $img_data['description'] ) ? $img_data['description'] : '',
					'post_excerpt' => isset( $img_data['caption'] ) && !empty( $img_data['caption'] ) ? $img_data['caption'] : '',
					'post_status'    => 'inherit',
				);
				$attach_id   = wp_insert_attachment( $attachment, $local_img_path ); //Insert the image in the database
				require_once( ABSPATH . 'wp-admin/includes/image.php' );
				$attach_data = wp_generate_attachment_metadata( $attach_id, $local_img_path );
				wp_update_attachment_metadata( $attach_id, $attach_data ); //Update generated metadata
				if ( isset( $img_data['alt'] ) && !empty( $img_data['alt'] ) )
					update_post_meta( $attach_id, '_wp_attachment_image_alt', $img_data['alt'] );
				return array( 'id' => $attach_id, 'url' => $local_img_url );
			}
		}
		if ( file_exists( $temporary_file ) ) {
			unlink( $temporary_file );
		}

		return null;
	}

	static function getBaseDir() {
		$upload_dir = wp_upload_dir();

		return $upload_dir['basedir'] . DIRECTORY_SEPARATOR;
	}

    public static function getIconsDir() {
        $dirs = self::getMainWPDir();
        $dir        = $dirs[0] . 'icons' . DIRECTORY_SEPARATOR;
        $url        = $dirs[1] . 'icons/';
        if ( ! file_exists( $dir ) ) {
                @mkdir( $dir, 0777, true );
        }
        if ( ! file_exists( $dir . 'index.php' ) ) {
                @touch( $dir . 'index.php' );
        }
        return array( $dir, $url );
    }

	public static function getMainWPDir() {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR;
		$url        = $upload_dir['baseurl'] . '/mainwp/';
		if ( ! file_exists( $dir ) ) {
			@mkdir( $dir, 0777, true );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			@touch( $dir . 'index.php' );
		}

		return array( $dir, $url );
	}

	public static function getDownloadUrl( $what, $filename ) {
		$specificDir = MainWP_Utility::getMainWPSpecificDir( $what );
		$mwpDir      = MainWP_Utility::getMainWPDir();
		$mwpDir      = $mwpDir[0];
		$fullFile    = $specificDir . $filename;

		return admin_url( '?sig=' . md5( filesize( $fullFile ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $fullFile ) ) );
	}

	public static function getMainWPSpecificDir( $dir = null ) {
		if ( MainWP_System::Instance()->isSingleUser() ) {
			$userid = 0;
		} else {
			global $current_user;
			$userid = $current_user->ID;
		}

		$dirs   = self::getMainWPDir();
		$newdir = $dirs[0] . $userid . ( $dir != null ? DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR : '' );

		if ( ! file_exists( $newdir ) ) {
			@mkdir( $newdir, 0777, true );
		}

		if ( $dirs[0] . $userid != null && ! file_exists( trailingslashit( $dirs[0] . $userid ) . '.htaccess' ) ) {
			$file = @fopen( trailingslashit( $dirs[0] . $userid ) . '.htaccess', 'w+' );
			@fwrite( $file, 'deny from all' );
			@fclose( $file );
		}

		return $newdir;
	}

	public static function getMainWPSpecificUrl( $dir ) {
		if ( MainWP_System::Instance()->isSingleUser() ) {
			$userid = 0;
		} else {
			global $current_user;
			$userid = $current_user->ID;
		}
		$dirs = self::getMainWPDir();

		return $dirs[1] . $userid . '/' . $dir . '/';
	}

	public static function getAlexaRank( $domain ) {
		$remote_url = 'http://data.alexa.com/data?cli=10&dat=snbamz&url=' . trim( $domain );
		$search_for = '<POPULARITY URL';
		$part       = '';
		if ( $handle = @fopen( $remote_url, 'r' ) ) {
			while ( ! feof( $handle ) ) {
				$part .= fread( $handle, 100 );
				$pos = strpos( $part, $search_for );
				if ( $pos === false ) {
					continue;
				} else {
					break;
				}
			}
			$part .= fread( $handle, 100 );
			fclose( $handle );
		}
		if ( ! stristr( $part, '<ALEXA') ) return NULL;
		if ( ! stristr( $part, $search_for ) ) {
			return 0;
		}

		$str = explode( $search_for, $part );
		$str = explode( '"/>', $str[1] );
		$str = array_shift( $str );
		$str = explode( 'TEXT="', $str );

		return $str[1];
	}


	protected static function StrToNum( $Str, $Check, $Magic ) {
		$Int32Unit = 4294967296; // 2^32

		$length = strlen( $Str );
		for ( $i = 0; $i < $length; $i ++ ) {
			$Check *= $Magic;
			//If the float is beyond the boundaries of integer (usually +/- 2.15e+9 = 2^31),
			//  the result of converting to integer is undefined
			//  refer to http://www.php.net/manual/en/language.types.integer.php
			if ( $Check >= $Int32Unit ) {
				$Check = ( $Check - $Int32Unit * (int) ( $Check / $Int32Unit ) );
				//if the check less than -2^31
				$Check = ( $Check < - 2147483648 ) ? ( $Check + $Int32Unit ) : $Check;
			}
			$Check += ord( $Str{$i} );
		}

		return $Check;
	}

	//--> for google pagerank
	/*
	* Genearate a hash for a url
	*/
	protected static function HashURL( $String ) {
		$Check1 = MainWP_Utility::StrToNum( $String, 0x1505, 0x21 );
		$Check2 = MainWP_Utility::StrToNum( $String, 0, 0x1003F );

		$Check1 >>= 2;
		$Check1 = ( ( $Check1 >> 4 ) & 0x3FFFFC0 ) | ( $Check1 & 0x3F );
		$Check1 = ( ( $Check1 >> 4 ) & 0x3FFC00 ) | ( $Check1 & 0x3FF );
		$Check1 = ( ( $Check1 >> 4 ) & 0x3C000 ) | ( $Check1 & 0x3FFF );

		$T1 = ( ( ( ( $Check1 & 0x3C0 ) << 4 ) | ( $Check1 & 0x3C ) ) << 2 ) | ( $Check2 & 0xF0F );
		$T2 = ( ( ( ( $Check1 & 0xFFFFC000 ) << 4 ) | ( $Check1 & 0x3C00 ) ) << 0xA ) | ( $Check2 & 0xF0F0000 );

		return ( $T1 | $T2 );
	}

	//--> for google pagerank
	/*
	* genearate a checksum for the hash string
	*/
	protected static function CheckHash( $Hashnum ) {
		$CheckByte = 0;
		$Flag      = 0;

		$HashStr = sprintf( '%u', $Hashnum );
		$length  = strlen( $HashStr );

		for ( $i = $length - 1; $i >= 0; $i -- ) {
			$Re = $HashStr{$i};
			if ( 1 === ( $Flag % 2 ) ) {
				$Re += $Re;
				$Re = (int) ( $Re / 10 ) + ( $Re % 10 );
			}
			$CheckByte += $Re;
			$Flag ++;
		}

		$CheckByte %= 10;
		if ( 0 !== $CheckByte ) {
			$CheckByte = 10 - $CheckByte;
			if ( 1 === ( $Flag % 2 ) ) {
				if ( 1 === ( $CheckByte % 2 ) ) {
					$CheckByte += 9;
				}
				$CheckByte >>= 1;
			}
		}

		return '7' . $CheckByte . $HashStr;
	}

	//get google pagerank
	public static function getpagerank( $url ) {
		$query = 'http://toolbarqueries.google.com/tbr?client=navclient-auto&ch=' . MainWP_Utility::CheckHash( MainWP_Utility::HashURL( $url ) ) . '&features=Rank&q=info:' . $url . '&num=100&filter=0';
		$data  = MainWP_Utility::file_get_contents_curl( $query );
		$pos   = strpos( $data, 'Rank_' );
		if ( $pos === false ) {
			return false;
		} else {
			$pagerank = substr( $data, $pos + 9 );

			return $pagerank;
		}
	}

    public static function get_file_content( $url ) {
		$data = self::file_get_contents_curl( $url );
        if ( empty( $data ) ) return false;
		return $data;
	}

	protected static function file_get_contents_curl( $url ) {
		//$agent = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
		$agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
		$ch    = curl_init();
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 ); //Set curl to return the data instead of printing it to the browser.
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		$data = curl_exec( $ch );
		curl_close( $ch );

		return $data;
	}

	public static function getGoogleCount( $domain ) {
		$content = file_get_contents( 'https://ajax.googleapis.com/ajax/services/' .
		                              'search/web?v=1.0&filter=0&q=site:' . urlencode( $domain ) );
		$data    = json_decode( $content );

		if ( empty( $data ) ) {
			return NULL;
		}
		if ( ! property_exists( $data, 'responseData' ) ) {
			return NULL;
		}
		if ( ! is_object( $data->responseData ) || ! property_exists( $data->responseData, 'cursor' ) ) {
			return 0;
		}
		if ( ! is_object( $data->responseData->cursor ) || ! property_exists( $data->responseData->cursor, 'estimatedResultCount' ) ) {
			return 0;
		}

		return intval( $data->responseData->cursor->estimatedResultCount );
	}

	public static function countRecursive( $array, $levels ) {
		if ( $levels == 0 ) {
			return count( $array );
		}
		$levels --;

		$count = 0;
		foreach ( $array as $value ) {
			if ( is_array( $value ) && ( $levels > 0 ) ) {
				$count += MainWP_Utility::countRecursive( $value, $levels - 1 );
			} else {
				$count += count( $value );
			}
		}

		return $count;
	}

	public static function sortmulti( $array, $index, $order, $natsort = false, $case_sensitive = false ) {
		$sorted = array();
		if ( is_array( $array ) && count( $array ) > 0 ) {
			foreach ( array_keys( $array ) as $key ) {
				$temp[ $key ] = $array[ $key ][ $index ];
			}
			if ( ! $natsort ) {
				if ( $order == 'asc' ) {
					asort( $temp );
				} else {
					arsort( $temp );
				}
			} else {
				if ( $case_sensitive === true ) {
					natsort( $temp );
				} else {
					natcasesort( $temp );
				}
				if ( $order != 'asc' ) {
					$temp = array_reverse( $temp, true );
				}
			}
			foreach ( array_keys( $temp ) as $key ) {
				if ( is_numeric( $key ) ) {
					$sorted[] = $array[ $key ];
				} else {
					$sorted[ $key ] = $array[ $key ];
				}
			}

			return $sorted;
		}

		return $sorted;
	}

	public static function getSubArrayHaving( $array, $index, $value ) {
		$output = array();
		if ( is_array( $array ) && count( $array ) > 0 ) {
			foreach ( $array as $arrvalue ) {
				if ( $arrvalue[ $index ] == $value ) {
					$output[] = $arrvalue;
				}
			}
		}

		return $output;
	}

	public static function http_post( $request, $http_host, $path, $port = 80, $pApplication = 'main', $throwException = false ) {

		$connect_timeout = get_option( 'mainwp_versioncontrol_timeout' );
		if ( $connect_timeout !== false && ( time() - $connect_timeout ) < 60 * 60 * 12 ) { //12 hrs..
			return false;
		}

		if ( $pApplication == 'main' ) {
			$pApplication = 'MainWP/1.1';
		} //.MainWP_System::Instance()->getVersion();
		else {
			$pApplication = 'MainWPExtension/' . $pApplication . '/v';
		}

		// use the WP HTTP class if it is available
		//        if ( function_exists( 'wp_remote_post' ) ) {
		$http_args  = array(
			'body'        => $request,
			'headers'     => array(
				'Content-Type' => 'application/x-www-form-urlencoded; ' .
				                  'charset=' . get_option( 'blog_charset' ),
				'Host'         => $http_host,
				'User-Agent'   => $pApplication,
			),
			'httpversion' => '1.0',
			'timeout'     => 15,
		);
		$mainwp_url = "http://{$http_host}{$path}";

		$response = wp_remote_post( $mainwp_url, $http_args );

		if ( empty( $response ) || is_wp_error( $response ) ) {
			MainWP_Utility::update_option( 'mainwp_versioncontrol_timeout', time() );
		} else if ( $connect_timeout !== false ) {
			delete_option( 'mainwp_versioncontrol_timeout' );
		}

		if ( is_wp_error( $response ) ) {
			if ( $throwException ) {
				throw new Exception( $response->get_error_message() );
			}

			return '';
		}

		return array( $response['headers'], $response['body'] );
	}

	static function trimSlashes( $elem ) {
		return trim( $elem, '/' );
	}

	public static function renderToolTip( $pText, $pUrl = null, $pImage = 'images/info.png', $style = null ) {
		$output = '<span class="tooltipcontainer">';
		if ( $pUrl != null ) {
			$output .= '<a href="' . $pUrl . '" target="_blank">';
		}
		$output .= '<span style="color: #0074a2; font-size: 14px;" class="tooltip"><i class="fa fa-question-circle"></i></span>';
		if ( $pUrl != null ) {
			$output .= '</a>';
		}
		$output .= '<span class="tooltipcontent" style="display: none;">' . $pText;
		if ( $pUrl != null ) {
			$output .= ' (Click to read more)';
		}
		$output .= '</span></span>';
		echo $output;
	}

    public static function renderNoteTooltip( $pText, $pImage = '<i class="fa fa-pencil-square-o"></i>') {
		$output = '<span class="tooltipcontainer">';
		$output .= '<span style="font-size: 14px;" class="tooltip">' . $pImage . '</span>';
		$output .= '<span class="tooltipcontent" style="display: none;">' . $pText;
		$output .= '</span></span>';
		return $output;
	}


	public static function encrypt( $str, $pass ) {
		$pass = str_split( str_pad( '', strlen( $str ), $pass, STR_PAD_RIGHT ) );
		$stra = str_split( $str );
		foreach ( $stra as $k => $v ) {
			$tmp        = ord( $v ) + ord( $pass[ $k ] );
			$stra[ $k ] = chr( $tmp > 255 ? ( $tmp - 256 ) : $tmp );
		}

		return base64_encode( join( '', $stra ) );
	}

	public static function decrypt( $str, $pass ) {
		$str  = base64_decode( $str );
		$pass = str_split( str_pad( '', strlen( $str ), $pass, STR_PAD_RIGHT ) );
		$stra = str_split( $str );
		foreach ( $stra as $k => $v ) {
			$tmp        = ord( $v ) - ord( $pass[ $k ] );
			$stra[ $k ] = chr( $tmp < 0 ? ( $tmp + 256 ) : $tmp );
		}

		return join( '', $stra );
	}

	public static function encrypt_legacy( $string, $key ) {
		if ( function_exists( 'mcrypt_encrypt' ) ) {
			return base64_encode( mcrypt_encrypt( MCRYPT_RIJNDAEL_256, md5( $key ), $string, MCRYPT_MODE_CBC, md5( md5( $key ) ) ) );
		} else {
			return base64_encode( $string );
		}
	}

	public static function decrypt_legacy( $encrypted, $key ) {
		if ( function_exists( 'mcrypt_encrypt' ) ) {
			return rtrim( mcrypt_decrypt( MCRYPT_RIJNDAEL_256, md5( $key ), base64_decode( $encrypted ), MCRYPT_MODE_CBC, md5( md5( $key ) ) ), "\0" );
		} else {
			return base64_decode( $encrypted );
		}
	}

	/**
	 * @return WP_Filesystem_Base
	 */
	public static function getWPFilesystem() {
		/** @global WP_Filesystem_Base $wp_filesystem */
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			ob_start();
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/screen.php' );
			}
			if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/template.php' );
			}
			$creds = request_filesystem_credentials( 'test' );
			ob_end_clean();
			if ( empty( $creds ) ) {
				define( 'FS_METHOD', 'direct' );
			}
			$init = WP_Filesystem( $creds );
		} else {
			$init = true;
		}

		return $init;
	}

	public static function sanitize( $str ) {
		return preg_replace( '/[\\\\\/\:"\*\?\<\>\|]+/', '', $str );
	}

	public static function formatEmail( $to, $body, $title = '' ) {
		$current_year = date("Y");
		$mail_send['header']  = <<<EOT
            <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=U=TF-8">
        <title> {$title} </title>
        <style type="text/css">
        outlook a{padding:0;}
        body{width:100% !important;}
        .ReadMsgBody{width:100%;}
        .ExternalClass{width:100%;}
        body{-webkit-text-size-adjust:none;}
        body{margin:0;padding:0;}
        img{
        border:0;
        height:auto;
        line-height:100%;
        outline:none;
        text-decoration:none;
        }
        table td{
        border-collapse:collapse;
        }
        #backgroundTable{
        height:100% !important;
        margin:0;
        padding:0;
        width:100% !important;
        }
        body,#backgroundTable{
        background-color:#FAFAFA;
        }
        #templateContainer{
        border:1px solid #DDDDDD;
        }
        h1,.h1{
        color:#202020;
        display:block;
        font-family:Arial;
        font-size:34px;
        font-weight:bold;
        line-height:100%;
        margin-top:0;
        margin-right:0;
        margin-bottom:10px;
        margin-left:0;
        text-align:left;
        }
        h2,.h2{
        color:#202020;
        display:block;
        font-family:Arial;
        font-size:30px;
        font-weight:bold;
        line-height:100%;
        margin-top:0;
        margin-right:0;
        margin-bottom:10px;
        margin-left:0;
        text-align:left;
        }
        h3,.h3{
        color:#202020;
        display:block;
        font-family:Arial;
        font-size:26px;
        font-weight:bold;
        line-height:100%;
        margin-top:0;
        margin-right:0;
        margin-bottom:10px;
        margin-left:0;
        text-align:left;
        }
        h4,.h4{
        color:#202020;
        display:block;
        font-family:Arial;
        font-size:22px;
        font-weight:bold;
        line-height:100%;
        margin-top:0;
        margin-right:0;
        margin-bottom:10px;
        margin-left:0;
        text-align:left;
        }
        #templatePreheader{
        background-color:#FAFAFA;
        }
        .preheaderContent div{
        color:#505050;
        font-family:Arial;
        font-size:10px;
        line-height:100%;
        text-align:left;
        }
        .preheaderContent div a:link,.preheaderContent div a:visited,.prehead=
        erContent div a .yshortcuts {
        color:#446200;
        font-weight:normal;
        text-decoration:underline;
        }
        #templateHeader{
        background-color:#FFFFFF;
        border-bottom:0;
        }
        .headerContent{
        color:#202020;
        font-family:Arial;
        font-size:34px;
        font-weight:bold;
        line-height:100%;
        padding:0;
        text-align:center;
        vertical-align:middle;
        }
        .headerContent a:link,.headerContent a:visited,.headerContent a .ysho=
        rtcuts {
        color:#446200;
        font-weight:normal;
        text-decoration:underline;
        }
        #headerImage{
        height:auto;
        max-width:600px !important;
        }
        #templateContainer,.bodyContent{
        background-color:#FFFFFF;
        }
        .bodyContent div{
        color:#505050;
        font-family:Arial;
        font-size:14px;
        line-height:150%;
        text-align:left;
        }
        .bodyContent div a:link,.bodyContent div a:visited,.bodyContent div a=
         .yshortcuts {
        color:#446200;
        font-weight:bold;
        text-decoration:underline;
        }
        .bodyContent img{
        display:inline;
        height:auto;
        }
        #templateFooter{
        background-color:#1d1b1c;
        border-top:4px solid #7fb100;
        }
        .footerContent div{
        color:#b8b8b8;
        font-family:Arial;
        font-size:12px;
        line-height:125%;
        text-align:center;
        }
        .footerContent div a:link,.footerContent div a:visited,.footerContent=
         div a .yshortcuts {
        color:#336699;
        font-weight:normal;
        text-decoration:underline;
        }
        .footerContent img{
        display:inline;
        }
        #social{
        background-color:#1d1b1c;
        border:0;
        }
        #social div{
        text-align:center;
        }
        #utility{
        background-color:#1d1b1c;
        border:0;
        }
        #utility div{
        text-align:center;
        }
        #monkeyRewards img{
        max-width:190px;
        }
        </style>
    </head>
    <body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" style="-webkit-text-size-adjust: none;margin: 0;padding: 0;background-color: #FAFAFA;width: 100% !important;">
    <center>
        <table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" id="backgroundTable" style="margin: 0;padding:0;background-color: #FAFAFA;height: 100% !important;width: 100% !important;">
            <tr>
                <td align="center" valign="top" style="border-collapse: collapse;">

                        <!-- // Begin: Template Pre-header \\ -->

                        <table border="0" cellpadding="10" cellspacing="0" width="600" id="templatePreheader" style="background-color: #FAFAFA;">
                            <tr>
                                <td valign="top" class="preheaderContent" style="border-collapse: collapse;">
                                
                                <!-- // Begin: Standard Preheader \ -->

                                    <table border="0" cellpadding="10" cellspacing="0" width="100%">
                                        <tr>
                                            <td valign="top" style="border-collapse: collapse;">
                                                <div style="color: #505050;font-family: Arial;font-size: 10px;line-height: 100%;text-align: left;"></div>
                                            </td>
                                            <td valign="top" width="190" style="border-collapse: collapse;">
                                                <div style="color: #505050;font-family: Arial;font-size: 10px;line-height: 100%;text-align: left;"></div>
                                            </td>
                                        </tr>
                                    </table>

                                <!-- // End: Standard Preheader \ -->
                                
                                </td>
                            </tr>
                        </table>

                        <!-- // End: Template Preheader \\ -->

                        <table border="0" cellpadding="0" cellspacing="0" width="600" id="templateContainer" style="border: 1px solid #DDDDDD;background-color: #FFFFFF;">
                            <tr>
                                <td align="center" valign="top" style="border-collapse: collapse;">

                                        <!-- // Begin: Template Header \\ -->

                                        <table border="0" cellpadding="0" cellspacing="0" width="600" id="templateHeader" style="background-color: #FFFFFF;border-bottom: 0;">
                                            <tr>
                                                <td class="headerContent" style="border-collapse: collapse;color: #202020;font-family: Arial;font-size: 34px;font-weight: bold;line-height: 100%;padding: 0;text-align: center;vertical-align: middle;">
                                                
                                                <!-- // Begin: Standard Header Image \\ -->

                                                <a href="https://mainwp.com" target="_blank" style="color: #446200;font-weight: normal;text-decoration: underline;"><img src="https://gallery.mailchimp.com/f3ac05fd307648a9c6bbe320a/images/header.png" alt="MainWP" border="0" style="border: px none;border-color: ;border-style: none;border-width: px;height: 130px;width: 600px;margin: 0;padding: 0;line-height: 100%;outline: none;text-decoration: none;" width="600" height="130"></a>
                                                
                                                <!-- // End: Standard Header Image \\ -->
                                                
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- // End: Template Header \\ -->

                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" valign="top" style="border-collapse: collapse;">

                                        <!-- // Begin: Template Body \\ -->

                                        <table border="0" cellpadding="0" cellspacing="0" width="600" id="templateBody">
                                            <tr>
                                                <td valign="top" class="bodyContent" style="border-collapse: collapse;background-color: #FFFFFF;">
                                    
                                                    <!-- // Begin: Standard Content \\ -->
EOT;

		$mail_send['body']  = <<<EOT
                                                    <table border="0" cellpadding="20" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td valign="top" style="border-collapse: collapse;">
                                                                <div style="color: #505050;font-family: Arial;font-size: 14px;line-height: 150%;text-align: left;"> Hi MainWP user, <br><br>
                                                                <b style="color: rgb(127, 177, 0); font-family: Helvetica, Sans; font-size: medium; line-height: normal;"> {$title} </b><br>
                                                                <br>{$body}<br>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    </table>
EOT;

		$mail_send['footer']  = <<<EOT
                                                    <!-- // End: Standard Content \\ -->

                                                </td>
                                            </tr>
                                        </table>

                                        <!-- // End: Template Body \\ -->

                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" valign="top" style="border-collapse: collapse;">

                                        <!-- // Begin: Template Footer \\ -->

                                        <table border="0" cellpadding="10" cellspacing="0" width="600" id="templateFooter" style="background-color: #1d1b1c;border-top: 4px solid #7fb100;">
                                            <tr>
                                                <td valign="top" class="footerContent" style="border-collapse: collapse;">
                                                    
                                                    <!-- // Begin: Standard Footer \\ -->

                                                    <table border="0" cellpadding="10" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td colspan="2" valign="middle" id="social" style="border-collapse: collapse;background-color: #1d1b1c;border: 0;">
                                                                <div style="color: #b8b8b8;font-family: Arial;font-size: 12px;line-height: 125%;text-align: center;">
                                                                    <style type="text/css">
                                                                        #mainwp-links a {
                                                                          text-transform: uppercase;
                                                                          text-decoration: none;
                                                                          color: #7fb100 ;
                                                                        }
                                                                    </style>
                                                                    <div class="tpl-content-highlight" id="mainwp-links" style="color: #b8b8b8;font-family: Arial;font-size: 12px;line-height: 125%;text-align: center;">
                                                                    <a href="https://mainwp.com" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">MainWP</a> | <a href="https://mainwp.com/mainwp-extensions/" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">Extensions</a> | <a href="https://mainwp.com/help/" target="_self" style="color: #7fb100;font-weight: normal;text-decoration:none;text-transform: uppercase;">Documentation</a> | <a href="https://mainwp.com/mainwp-blog/" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">Blog</a> | <a href="http://codex.mainwp.com" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">Codex</a> | <a href="https://mainwp.com/support/" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">Support</a></div>

                                                                    <hr><br>
                                                                    <a href="https://twitter.com/mymainwp" target="_blank" style="color: #336699;font-weight: normal;text-decoration: underline;">Follow us on Twitter</a> | <a href="https://www.facebook.com/mainwp" style="color:#336699;font-weight: normal;text-decoration: underline;">Friend us on Facebook</a>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td valign="top" width="350" style="border-collapse: collapse;">
                                                                <div style="color: #b8b8b8;font-family: Arial;font-size: 12px;line-height: 125%;text-align: center;"><div style="text-align: left;color: #b8b8b8;font-family: Arial;font-size: 12px;line-height: 125%;"><em>Copyright &copy; {$current_year} MainWP, All rights reserved.</em><br></div></div>
                                                            </td>
                                                            <td valign="top" width="190" id="monkeyRewards" style="border-collapse: collapse;">
                                                                <div style="color: #b8b8b8;font-family: Arial;font-size: 12px;line-height: 125%;text-align: center;"><a href="https://mainwp.com" target="_blank" style="color: #336699;font-weight: normal;text-decoration: underline;"><img align="none" alt="MainWP" height="60" src="http://mainwp.com/wp-content/uploads/2013/07/MainWP-Icon-300.png" style="width: 60px;height: 60px;float: right;border: 0;line-height: 100%;outline: none;text-decoration: none;display:inline;max-width: 190px;" width="60"></a></div>
                                                            </td>
                                                        </tr>
                                                    </table>

                                                    <!-- // End: Standard Footer \\ -->
                                                    
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- // End: Template Footer \\ -->

                                    </td>
                                </tr>
                            </table>
                        <br>
                    </td>
                </tr>
            </table>
        </center>
    </body>
</html>
EOT;
        $mail_send = apply_filters( 'mainwp_format_email', $mail_send );
		return $mail_send['header'] . $mail_send['body'] . $mail_send['footer'];
	}

	public static function endSession() {
		session_write_close();
		if ( ob_get_length() > 0 ) {
			ob_end_flush();
		}
	}

	public static function getLockIdentifier( $pLockName ) {
		if ( ( $pLockName == null ) || ( $pLockName == false ) ) {
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

	public static function lock( $pIdentifier ) {
		if ( ( $pIdentifier == null ) || ( $pIdentifier == false ) ) {
			return false;
		}

		if ( function_exists( 'sem_acquire' ) ) {
			return sem_acquire( $pIdentifier );
		} else {
			//Retry lock 3 times
			for ( $i = 0; $i < 3; $i ++ ) {
				if ( @flock( $pIdentifier, LOCK_EX ) ) {
					// acquire an exclusive lock
					return $pIdentifier;
				} else {
					//Sleep before lock retry
					sleep( 1 );
				}
			}

			return false;
		}

		return false;
	}

	public static function release( $pIdentifier ) {
		if ( ( $pIdentifier == null ) || ( $pIdentifier == false ) ) {
			return false;
		}

		if ( function_exists( 'sem_release' ) ) {
			return sem_release( $pIdentifier );
		} else {
			@flock( $pIdentifier, LOCK_UN ); // release the lock
			@fclose( $pIdentifier );
		}

		return false;
	}

	public static function getTimestamp( $timestamp ) {
		$gmtOffset = get_option( 'gmt_offset' );

		return ( $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp );
	}

	public static function date( $format ) {
		return date( $format, self::getTimestamp( time() ) );
	}

	public static function formatTimestamp( $timestamp ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	public static function human_filesize( $bytes, $decimals = 2 ) {
		$size   = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
		$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

		return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . @$size[ $factor ];
	}

	public static function mapSite( &$website, $keys ) {
		$outputSite = array();
		foreach ( $keys as $key ) {
			$outputSite[ $key ] = $website->$key;
		}

		return (object) $outputSite;
	}

	public static function can_edit_website( &$website ) {
		if ( $website == null ) {
			return false;
		}

		//Everyone may change this website
		if ( MainWP_System::Instance()->isSingleUser() ) {
			return true;
		}

		global $current_user;

		return ( $website->userid == $current_user->ID );
	}

	public static function can_edit_group( &$group ) {
		if ( $group == null ) {
			return false;
		}

		//Everyone may change this website
		if ( MainWP_System::Instance()->isSingleUser() ) {
			return true;
		}

		global $current_user;

		return ( $group->userid == $current_user->ID );
	}

	public static function can_edit_backuptask( &$task ) {
		if ( $task == null ) {
			return false;
		}

		if ( MainWP_System::Instance()->isSingleUser() ) {
			return true;
		}

		global $current_user;

		return ( $task->userid == $current_user->ID );
	}

	public static function get_current_wpid() {
		global $current_user;

		return $current_user->current_site_id;
	}

	public static function set_current_wpid( $wpid ) {
		global $current_user;
		$current_user->current_site_id = $wpid;
	}

	public static function array_merge( $arr1, $arr2 ) {
		if ( ! is_array( $arr1 ) && ! is_array( $arr2 ) ) {
			return array();
		}
		if ( ! is_array( $arr1 ) ) {
			return $arr2;
		}
		if ( ! is_array( $arr2 ) ) {
			return $arr1;
		}

		$output = array();
		foreach ( $arr1 as $el ) {
			$output[] = $el;
		}
		foreach ( $arr2 as $el ) {
			$output[] = $el;
		}

		return $output;
	}

	public static function getCronSchedules( $schedules ) {
		$schedules['5minutely'] = array(
			'interval' => 5 * 60, // 5minutes in seconds
			'display'  => __( 'Once every 5 minutes', 'mainwp' ),
		);
		$schedules['minutely']  = array(
			'interval' => 1 * 60, // 1minute in seconds
			'display'  => __( 'Once every minute', 'mainwp' ),
		);

		return $schedules;
	}

	public static function getWebsitesAutomaticUpdateTime() {
		$lastAutomaticUpdate    = MainWP_DB::Instance()->getWebsitesLastAutomaticSync();

		if ( $lastAutomaticUpdate == 0 ) {
			$nextAutomaticUpdate = 'Any minute';
		} else if ( MainWP_DB::Instance()->getWebsitesCountWhereDtsAutomaticSyncSmallerThenStart() > 0 || MainWP_DB::Instance()->getWebsitesCheckUpdatesCount() > 0 ) {
			$nextAutomaticUpdate = 'Processing your websites.';
		} else {
			$nextAutomaticUpdate = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( mktime( 0, 0, 0, date( 'n' ), date( 'j' ) + 1 ) ) );
		}

		if ( $lastAutomaticUpdate == 0 ) {
			$lastAutomaticUpdate = 'Never';
		} else {
			$lastAutomaticUpdate = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $lastAutomaticUpdate ) );
		}

		return array(
			'last' => $lastAutomaticUpdate,
			'next' => $nextAutomaticUpdate
		);
	}

	public static function mime_content_type( $filename ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo    = finfo_open( FILEINFO_MIME );
			$mimetype = finfo_file( $finfo, $filename );
			finfo_close( $finfo );

			return $mimetype;
		}

		if ( function_exists( 'mime_content_type' ) ) {
			return mime_content_type( $filename );
		}

		$mime_types = array(

			'txt'  => 'text/plain',
			'htm'  => 'text/html',
			'html' => 'text/html',
			'php'  => 'text/html',
			'css'  => 'text/css',
			'js'   => 'application/javascript',
			'json' => 'application/json',
			'xml'  => 'application/xml',
			'swf'  => 'application/x-shockwave-flash',
			'flv'  => 'video/x-flv',
			// images
			'png'  => 'image/png',
			'jpe'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpg'  => 'image/jpeg',
			'gif'  => 'image/gif',
			'bmp'  => 'image/bmp',
			'ico'  => 'image/vnd.microsoft.icon',
			'tiff' => 'image/tiff',
			'tif'  => 'image/tiff',
			'svg'  => 'image/svg+xml',
			'svgz' => 'image/svg+xml',
			// archives
			'zip'  => 'application/zip',
			'rar'  => 'application/x-rar-compressed',
			'exe'  => 'application/x-msdownload',
			'msi'  => 'application/x-msdownload',
			'cab'  => 'application/vnd.ms-cab-compressed',
			// audio/video
			'mp3'  => 'audio/mpeg',
			'qt'   => 'video/quicktime',
			'mov'  => 'video/quicktime',
			// adobe
			'pdf'  => 'application/pdf',
			'psd'  => 'image/vnd.adobe.photoshop',
			'ai'   => 'application/postscript',
			'eps'  => 'application/postscript',
			'ps'   => 'application/postscript',
			// ms office
			'doc'  => 'application/msword',
			'rtf'  => 'application/rtf',
			'xls'  => 'application/vnd.ms-excel',
			'ppt'  => 'application/vnd.ms-powerpoint',
			// open office
			'odt'  => 'application/vnd.oasis.opendocument.text',
			'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		);

		$ext = strtolower( array_pop( explode( '.', $filename ) ) );
		if ( array_key_exists( $ext, $mime_types ) ) {
			return $mime_types[ $ext ];
		}

		return 'application/octet-stream';
	}

	static function update_option( $option_name, $option_value ) {
		$success = add_option( $option_name, $option_value, '', 'no' );

		if ( ! $success ) {
			$success = update_option( $option_name, $option_value );
		}

		return $success;
	}

	static function fix_option( $option_name ) {
		global $wpdb;

		if ( 'yes' == $wpdb->get_var( "SELECT autoload FROM $wpdb->options WHERE option_name = '" . $option_name . "'" ) ) {
			$option_value = get_option( $option_name );
			delete_option( $option_name );
			add_option( $option_name, $option_value, null, 'no' );
		}
	}

	static function get_resource_id( $resource ) {
		if ( ! is_resource( $resource ) ) {
			return false;
		}

		$resourceString = (string) $resource;
		$exploded       = explode( '#', $resourceString );
		$result         = array_pop( $exploded );

		return $result;
	}

	public static function getFileParameter( &$website ) {
		if ( ! isset( $website->version ) || empty( $website->version ) ) {
			return 'file';
		}
		if ( version_compare( '0.29.13', $website->version ) < 0 ) {
			return 'f';
		}

		return 'file';
	}

	public static function removePreSlashSpaces( $text ) {
		while ( stristr( $text, ' /' ) ) {
			$text = str_replace( ' /', '/', $text );
		}

		return $text;
	}

	public static function removeHttpPrefix( $pUrl, $pTrimSlashes = false ) {
		return str_replace( array( 'http:' . ( $pTrimSlashes ? '//' : '' ), 'https:' . ( $pTrimSlashes ? '//' : '' ) ), array( '', '' ), $pUrl );
	}

	public static function isArchive( $pFileName, $pPrefix = '', $pSuffix = '' ) {
		return preg_match( '/' . $pPrefix . '(.*).(zip|tar|tar.gz|tar.bz2)' . $pSuffix . '$/', $pFileName );
	}

	public static function isSQLFile( $pFileName ) {
		return preg_match( '/(.*).sql$/', $pFileName ) || self::isSQLArchive( $pFileName );
	}

	public static function isSQLArchive( $pFileName ) {
		return preg_match( '/(.*).sql.(zip|tar|tar.gz|tar.bz2)$/', $pFileName );
	}

	public static function getCurrentArchiveExtension( $website = false, $task = false ) {
		$useSite = true;
		if ( $task != false ) {
			if ( $task->archiveFormat == 'global' ) {
				$useGlobal = true;
				$useSite   = false;
			} else if ( $task->archiveFormat == '' || $task->archiveFormat == 'site' ) {
				$useGlobal = false;
				$useSite   = true;
			} else {
				$archiveFormat = $task->archiveFormat;
				$useGlobal     = false;
				$useSite       = false;
			}
		}

		if ( $useSite ) {
			if ( $website == false ) {
				$useGlobal = true;
			} else {
				$backupSettings = MainWP_DB::Instance()->getWebsiteBackupSettings( $website->id );
				$archiveFormat  = $backupSettings->archiveFormat;
				$useGlobal      = ( $archiveFormat == 'global' );
			}
		}

		if ( $useGlobal ) {
			$archiveFormat = get_option( 'mainwp_archiveFormat' );
			if ( $archiveFormat === false ) {
				$archiveFormat = 'tar.gz';
			}
		}

		return $archiveFormat;
	}

	public static function getRealExtension( $path ) {
		$checks = array( '.sql.zip', '.sql.tar', '.sql.tar.gz', '.sql.tar.bz2', '.tar.gz', '.tar.bz2' );
		foreach ( $checks as $check ) {
			if ( self::endsWith( $path, $check ) ) {
				return $check;
			}
		}

		return '.' . pathinfo( $path, PATHINFO_EXTENSION );
	}

	public static function sanitize_file_name( $filename ) {
		$filename = str_replace( array( '|', '/', '\\', ' ', ':' ), array( '-', '-', '-', '-', '-' ), $filename );

		return sanitize_file_name( $filename );
	}

	public static function normalize_filename( $s ) {
		// maps German (umlauts) and other European characters onto two characters before just removing diacritics
		$s = preg_replace( '@\x{00c4}@u', 'A', $s );    // umlaut Ä => A
		$s = preg_replace( '@\x{00d6}@u', 'O', $s );    // umlaut Ö => O
		$s = preg_replace( '@\x{00dc}@u', 'U', $s );    // umlaut Ü => U
		$s = preg_replace( '@\x{00cb}@u', 'E', $s );    // umlaut Ë => E
		$s = preg_replace( '@\x{00e4}@u', 'a', $s );    // umlaut ä => a
		$s = preg_replace( '@\x{00f6}@u', 'o', $s );    // umlaut ö => o
		$s = preg_replace( '@\x{00fc}@u', 'u', $s );    // umlaut ü => u
		$s = preg_replace( '@\x{00eb}@u', 'e', $s );    // umlaut ë => e
		$s = preg_replace( '@\x{00f1}@u', 'n', $s );    // ñ => n
		$s = preg_replace( '@\x{00ff}@u', 'y', $s );    // ÿ => y
		return $s;
	}

	public static function showUserTip( $tip_id ) {
		global $current_user;

		if ( get_option( 'mainwp_hide_tips', 1 ) ) {
			return false;
		}

		if ( $user_id = $current_user->ID ) {
			$reset_tips = get_option( 'mainwp_reset_user_tips' );
			if ( ! is_array( $reset_tips ) ) {
				$reset_tips = array();
			}
			if ( ! isset( $reset_tips[ $user_id ] ) ) {
				$reset_tips[ $user_id ] = 1;
				update_option( 'mainwp_reset_user_tips', $reset_tips );
				update_user_option( $user_id, 'mainwp_hide_user_tips', array() );

				return true;
			}

			$hide_usertips = get_user_option( 'mainwp_hide_user_tips' );
			if ( ! is_array( $hide_usertips ) ) {
				$hide_usertips = array();
			}
			if ( isset( $hide_usertips[ $tip_id ] ) ) {
				return false;
			}
		}

		return true;
	}

    public static function showMainWPMessage( $type, $notice_id ) {
        if ( 'tour' == $type ) {
            $status = get_user_option( 'mainwp_tours_status' );
        }  else {
            $status = get_user_option( 'mainwp_notice_saved_status' );
        }
        if ( ! is_array( $status ) ) {
                $status = array();
        }
        if ( isset( $status[ $notice_id ] ) ) {
            return false;
        }
		return true;
	}

	public static function resetUserCookie( $what, $value = '' ) {
		global $current_user;
		if ( $user_id = $current_user->ID ) {
			$reset_cookies = get_option( 'mainwp_reset_user_cookies' );
			if ( ! is_array( $reset_cookies ) ) {
				$reset_cookies = array();
			}

			if ( ! isset( $reset_cookies[ $user_id ] ) || ! isset( $reset_cookies[ $user_id ][ $what ] ) ) {
				$reset_cookies[ $user_id ][ $what ] = 1;
				MainWP_Utility::update_option( 'mainwp_reset_user_cookies', $reset_cookies );
				update_user_option( $user_id, 'mainwp_saved_user_cookies', array() );

				return false;
			}

			$user_cookies = get_user_option( 'mainwp_saved_user_cookies' );
			if ( ! is_array( $user_cookies ) ) {
				$user_cookies = array();
			}
			if ( ! isset( $user_cookies[ $what ] ) ) {
				return false;
			}
		}

		return true;
	}

	public static function get_favico_url( $website ) {
        $favi     = MainWP_DB::Instance()->getWebsiteOption( $website, 'favi_icon', '' );
        $faviurl = '';

		if ( ! empty( $favi ) ) {
            if ( false !== strpos( $favi, 'favi-' . $website->id . '-' ) ) {
                $dirs      = self::getIconsDir();
                if ( file_exists( $dirs[0] . $favi ) ) {
                    $faviurl = $dirs[1] . $favi;
                } else {
                    $faviurl = '';
                }
            } else if ( ( strpos( $favi, '//' ) === 0 ) || ( strpos( $favi, 'http' ) === 0 ) ) {
				$faviurl = $favi;
			} else {
				$faviurl = $website->url . $favi;
                $faviurl = MainWP_Utility::removeHttpPrefix( $faviurl );
			}
		}
        if ( empty( $faviurl ) ){
			$faviurl = plugins_url( 'images/sitefavi.png', dirname( __FILE__ ) );
		}

		return $faviurl;
	}

	public static function getCURLSSLVersion( $sslVersion )
	{
		switch ($sslVersion)
		{
			case '1.x':
				return 1; //CURL_SSLVERSION_TLSv1;
			case '2':
				return 2; //CURL_SSLVERSION_SSLv2;
			case '3':
				return 3; //CURL_SSLVERSION_SSLv3;
			case '1.0':
				return 4; //CURL_SSLVERSION_TLSv1_0;
			case '1.1':
				return 5; //CURL_SSLVERSION_TLSv1_1;
			case '1.2':
				return 6; //CURL_SSLVERSION_TLSv1_2;
			default:
				return 0; //CURL_SSLVERSION_DEFAULT;
		}
	}

	public static function array_sort ( &$array, $key, $sort_flag = SORT_STRING ) {
		$sorter = array();
		$ret = array();
		reset( $array );
		foreach ( $array as $ii => $val ) {
			$sorter[$ii] = $val[$key];
		}
		asort( $sorter, $sort_flag );
		foreach ( $sorter as $ii => $val ) {
			$ret[$ii] = $array[$ii];
		}
		$array = $ret;
	}
}