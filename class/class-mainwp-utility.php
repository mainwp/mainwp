<?php
/**
 * MainWP Utility Helper
 *
 * Custom curl functions and PHP filesystem functions.
 * 
 */

namespace MainWP\Dashboard;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.
	
/**
 * MainWP Utility
 */
class MainWP_Utility {

	public static $enabled_wp_seo = null;

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method starts_with()
	 *
	 * Start of Stack Trace.
	 *
	 * @param mixed $haystack The full stack.
	 * @param mixed $needle The function that is throwing the error.
	 *
	 * @return mixed Needle in the Haystack.
	 */
	public static function starts_with( $haystack, $needle ) {
		return ! strncmp( $haystack, $needle, strlen( $needle ) );
	}

	/**
	 * Method ends_with()
	 *
	 * End of Stack Trace.
	 *
	 * @param mixed $haystack
	 * @param mixed $needle
	 *
	 * @return mixed True|substr( $haystack, - $length ) === $needle.
	 */
	public static function ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( 0 === $length ) {
			return true;
		}

		return ( substr( $haystack, - $length ) === $needle );
	}

	/**
	 * Method get_nice_url()
	 *
	 * Grab url.
	 *
	 * @param mixed   $pUrl
	 * @param boolean $showHttp
	 *
	 * @return string $url.
	 */
	public static function get_nice_url( $pUrl, $showHttp = false ) {
		$url = $pUrl;

		if ( self::starts_with( $url, 'http://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 7 );
			}
		} elseif ( self::starts_with( $pUrl, 'https://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 8 );
			}
		} else {
			if ( $showHttp ) {
				$url = 'http://' . $url;
			}
		}

		if ( self::ends_with( $url, '/' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 0, strlen( $url ) - 1 );
			}
		} else {
			$url = $url . '/';
		}

		return $url;
	}

	/**
	 * Method is_admin()
	 *
	 * Check if current user is an administrator.
	 *
	 * @return boolean True|False.
	 */
	public static function is_admin() {
		global $current_user;
		if ( 0 === $current_user->ID ) {
			return false;
		}

		if ( 10 == $current_user->wp_user_level || ( isset( $current_user->user_level ) && 10 == $current_user->user_level ) || current_user_can( 'level_10' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Method is_domain_valid()
	 *
	 * Check $url against FILTER_VALIDATE_URL.
	 *
	 * @return boolean True|False.
	 */
	private static function is_domain_valid( $url ) {
		return filter_var( $url, FILTER_VALIDATE_URL );
	}


	/**
	 * Method utf8ize()
	 *
	 * Convert content into utf8 encoding.
	 *
	 * @param mixed $mixed
	 *
	 * @return mixed $mixed
	 */
	public static function utf8ize( $mixed ) {
		if ( is_array( $mixed ) ) {
			foreach ( $mixed as $key => $value ) {
				$mixed[ $key ] = self::utf8ize( $value );
			}
		} elseif ( is_string( $mixed ) ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				return mb_convert_encoding( $mixed, 'UTF-8', 'UTF-8' );
			}
		}
		return $mixed;
	}

	/**
	 * Method safe_json_encode()
	 *
	 * @param mixed   $value String to encode.
	 * @param integer $options Options for encoding.
	 * @param integer $depth Depth to encode to.
	 *
	 * @return mixed $encoded Encoded String.
	 */
	public static function safe_json_encode( $value, $options = 0, $depth = 512 ) {
		$encoded = wp_json_encode( $value, $options, $depth );
		if ( false === $encoded && $value && json_last_error() == JSON_ERROR_UTF8 ) {
			$encoded = wp_json_encode( self::utf8ize( $value ), $options, $depth );
		}
		return $encoded;
	}


	/**
	 * Method get_primary_backup()
	 *
	 * Check if using Legacy Backup Solution.
	 *
	 * @return mixed False|$enable_legacy_backup.
	 */
	public static function get_primary_backup() {
		$enable_legacy_backup = get_option( 'mainwp_enableLegacyBackupFeature' );
		if ( ! $enable_legacy_backup ) {
			return get_option( 'mainwp_primaryBackup', false );
		}
		return false;
	}

	/**
	 * Method get_notification_email()
	 *
	 * Check if user wants to recieve MainWP Notification Emails.
	 *
	 * @param null $user User Email Address.
	 *
	 * @return mixed null|User Email Address.
	 */
	public static function get_notification_email( $user = null ) {
		if ( null == $user ) {
			global $current_user;
			$user = $current_user;
		}

		if ( null == $user ) {
			return null;
		}

		if ( ! ( $user instanceof WP_User ) ) {
			return null;
		}

		$userExt = MainWP_DB::instance()->get_user_extension();
		if ( '' != $userExt->user_email ) {
			return $userExt->user_email;
		}

		return $user->user_email;
	}


	public static function ctype_digit( $str ) {
		return ( is_string( $str ) || is_int( $str ) || is_float( $str ) ) && preg_match( '/^\d+\z/', $str );
	}

	public static function get_base_dir() {
		$upload_dir = wp_upload_dir();

		return $upload_dir['basedir'] . DIRECTORY_SEPARATOR;
	}

	public static function get_icons_dir() {
		$hasWPFileSystem = self::get_wp_file_system();
		global $wp_filesystem;

		$dirs = self::get_mainwp_dir();
		$dir  = $dirs[0] . 'icons' . DIRECTORY_SEPARATOR;
		$url  = $dirs[1] . 'icons/';
		if ( ! $wp_filesystem->exists( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0777, true );
		}
		if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
			$wp_filesystem->touch( $dir . 'index.php' );
		}
		return array( $dir, $url );
	}

	public static function get_mainwp_dir() {
		$hasWPFileSystem = self::get_wp_file_system();
		global $wp_filesystem;

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR;
		$url        = $upload_dir['baseurl'] . '/mainwp/';
		if ( ! $wp_filesystem->exists( $dir ) ) {
			$wp_filesystem->mkdir( $dir, 0777, true );
		}
		if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
			$wp_filesystem->touch( $dir . 'index.php' );
		}

		return array( $dir, $url );
	}

	public static function get_download_url( $what, $filename ) {
		$specificDir = self::get_mainwp_specific_dir( $what );
		$mwpDir      = self::get_mainwp_dir();
		$mwpDir      = $mwpDir[0];
		$fullFile    = $specificDir . $filename;

		return admin_url( '?sig=' . md5( filesize( $fullFile ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $fullFile ) ) );
	}

	public static function get_mainwp_specific_dir( $dir = null ) {
		if ( MainWP_System::instance()->is_single_user() ) {
			$userid = 0;
		} else {
			global $current_user;
			$userid = $current_user->ID;
		}

		$hasWPFileSystem = self::get_wp_file_system();

		global $wp_filesystem;

		$dirs   = self::get_mainwp_dir();
		$newdir = $dirs[0] . $userid . ( null != $dir ? DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR : '' );

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {

			if ( ! $wp_filesystem->is_dir( $newdir ) ) {
				$wp_filesystem->mkdir( $newdir, 0777, true );
			}

			if ( null != $dirs[0] . $userid && ! $wp_filesystem->exists( trailingslashit( $dirs[0] . $userid ) . '.htaccess' ) ) {
				$file_htaccess = trailingslashit( $dirs[0] . $userid ) . '.htaccess';
				$wp_filesystem->put_contents( $file_htaccess, 'deny from all' );
			}
		} else {

			if ( ! file_exists( $newdir ) ) {
				@mkdir( $newdir, 0777, true );
			}

			if ( null != $dirs[0] . $userid && ! file_exists( trailingslashit( $dirs[0] . $userid ) . '.htaccess' ) ) {
				$file = @fopen( trailingslashit( $dirs[0] . $userid ) . '.htaccess', 'w+' );
				@fwrite( $file, 'deny from all' );
				@fclose( $file );
			}
		}

		return $newdir;
	}

	public static function get_mainwp_specific_url( $dir ) {
		if ( MainWP_System::instance()->is_single_user() ) {
			$userid = 0;
		} else {
			global $current_user;
			$userid = $current_user->ID;
		}
		$dirs = self::get_mainwp_dir();

		return $dirs[1] . $userid . '/' . $dir . '/';
	}


	public static function sortmulti( $array, $index, $order, $natsort = false, $case_sensitive = false ) {
		$sorted = array();
		if ( is_array( $array ) && 0 < count( $array ) ) {
			foreach ( array_keys( $array ) as $key ) {
				$temp[ $key ] = $array[ $key ][ $index ];
			}
			if ( ! $natsort ) {
				if ( 'asc' === $order ) {
					asort( $temp );
				} else {
					arsort( $temp );
				}
			} else {
				if ( true === $case_sensitive ) {
					natsort( $temp );
				} else {
					natcasesort( $temp );
				}
				if ( 'asc' !== $order ) {
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

	public static function get_sub_array_having( $array, $index, $value ) {
		$output = array();
		if ( is_array( $array ) && 0 < count( $array ) ) {
			foreach ( $array as $arrvalue ) {
				if ( $arrvalue[ $index ] == $value ) {
					$output[] = $arrvalue;
				}
			}
		}

		return $output;
	}

	public static function trim_slashes( $elem ) {
		return trim( $elem, '/' );
	}

	/**
	 * @return WP_Filesystem_Base
	 */
	public static function get_wp_file_system() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			ob_start();
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/screen.php';
			}
			if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/template.php';
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

	public static function format_email( $to, $body, $title = '', $text_format = false ) {
		$current_year = gmdate( 'Y' );
		if ( $text_format ) {
				$mail_send['header'] = '';

				$mail_send['body']   = $title . "\r\n\r\n" .
									$body . "\r\n\r\n";
				$mail_send['footer'] = 'MainWP: https://mainwp.com' . "\r\n" .
										'Extensions: https://mainwp.com/mainwp-extensions/' . "\r\n" .
										'Documentation: https://mainwp.com/help/' . "\r\n" .
										'Blog: https://mainwp.com/mainwp-blog/' . "\r\n" .
										'Codex: https://mainwp.com/codex/' . "\r\n" .
										'Support: https://mainwp.com/support/' . "\r\n\r\n" .
										'Follow us on Twitter: https://twitter.com/mymainwp' . "\r\n" .
										'Friend us on Facebook: https://www.facebook.com/mainwp' . "\r\n\r\n" .
										"Copyright {$current_year} MainWP, All rights reserved.";
		} else {
			$mail_send['header'] = <<<EOT
			<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
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

			$mail_send['body'] = <<<EOT
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

			$mail_send['footer'] = <<<EOT
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
                                                            <td valign="middle" id="social" style="border-collapse: collapse;background-color: #1d1b1c;border: 0;">
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
                                                            <td valign="top" style="border-collapse: collapse;">
                                                                <div style="color: #b8b8b8;font-family: Arial;font-size: 12px;line-height: 125%;text-align: center;"><div style="text-align: left;color: #b8b8b8;font-family: Arial;font-size: 12px;line-height: 125%;"><em>Copyright &copy; {$current_year} MainWP, All rights reserved.</em><br></div></div>
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
		}
		$mail_send = apply_filters( 'mainwp_format_email', $mail_send );
		return $mail_send['header'] . $mail_send['body'] . $mail_send['footer'];
	}

	public static function end_session() {
		session_write_close();
		if ( 0 < ob_get_length() ) {
			ob_end_flush();
		}
	}

	public static function get_timestamp( $timestamp ) {
		$gmtOffset = get_option( 'gmt_offset' );

		return ( $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp );
	}

	public static function date( $format ) {
		// phpcs:ignore -- use local date function
		return date( $format, self::get_timestamp( time() ) );
	}

	public static function format_timestamp( $timestamp ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	public static function human_filesize( $bytes, $decimals = 2 ) {
		$size   = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
		$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

		return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . @$size[ $factor ];
	}

	public static function map_site( &$website, $keys ) {
		$outputSite = array();
		foreach ( $keys as $key ) {
			$outputSite[ $key ] = $website->$key;
		}

		return (object) $outputSite;
	}

	public static function map_site_array( &$website, $keys ) {
		$outputSite = array();
		foreach ( $keys as $key ) {
			$outputSite[ $key ] = $website->$key;
		}

		return $outputSite;
	}

	public static function sec2hms( $sec, $padHours = false ) {

		$hms     = '';
		$hours   = intval( intval( $sec ) / 3600 );
		$hms    .= ( $padHours ) ? str_pad( $hours, 2, '0', STR_PAD_LEFT ) . ':' : $hours . ':';
		$minutes = intval( ( $sec / 60 ) % 60 );
		$hms    .= str_pad( $minutes, 2, '0', STR_PAD_LEFT ) . ':';
		$seconds = intval( $sec % 60 );
		$hms    .= str_pad( $seconds, 2, '0', STR_PAD_LEFT );

		return $hms;
	}

	public static function can_edit_website( &$website ) {
		if ( null == $website ) {
			return false;
		}

		if ( MainWP_System::instance()->is_single_user() ) {
			return true;
		}

		global $current_user;

		return ( $website->userid == $current_user->ID );
	}

	public static function can_edit_group( &$group ) {
		if ( null == $group ) {
			return false;
		}

		if ( MainWP_System::instance()->is_single_user() ) {
			return true;
		}

		global $current_user;

		return ( $group->userid == $current_user->ID );
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

	public static function update_option( $option_name, $option_value ) {
		$success = add_option( $option_name, $option_value, '', 'no' );

		if ( ! $success ) {
			$success = update_option( $option_name, $option_value );
		}

		return $success;
	}

	public static function get_file_parameter( &$website ) {
		if ( ! isset( $website->version ) || empty( $website->version ) ) {
			return 'file';
		}
		if ( 0 > version_compare( '0.29.13', $website->version ) ) {
			return 'f';
		}

		return 'file';
	}

	public static function remove_preslash_spaces( $text ) {
		while ( stristr( $text, ' /' ) ) {
			$text = str_replace( ' /', '/', $text );
		}

		return $text;
	}

	public static function remove_http_prefix( $pUrl, $pTrimSlashes = false ) {
		return str_replace( array( 'http:' . ( $pTrimSlashes ? '//' : '' ), 'https:' . ( $pTrimSlashes ? '//' : '' ) ), array( '', '' ), $pUrl );
	}

	public static function remove_http_www_prefix( $pUrl ) {
		$pUrl = self::remove_http_prefix( $pUrl, true );
		return str_replace( 'www', '', $pUrl );
	}

	public static function is_archive( $pFileName, $pPrefix = '', $pSuffix = '' ) {
		return preg_match( '/' . $pPrefix . '(.*).(zip|tar|tar.gz|tar.bz2)' . $pSuffix . '$/', $pFileName );
	}

	public static function is_sql_file( $pFileName ) {
		return preg_match( '/(.*).sql$/', $pFileName ) || self::is_sql_archive( $pFileName );
	}

	public static function is_sql_archive( $pFileName ) {
		return preg_match( '/(.*).sql.(zip|tar|tar.gz|tar.bz2)$/', $pFileName );
	}

	public static function get_current_archive_extension( $website = false, $task = false ) {
		$useSite = true;
		if ( false != $task ) {
			if ( 'global' === $task->archiveFormat ) {
				$useGlobal = true;
				$useSite   = false;
			} elseif ( '' == $task->archiveFormat || 'site' == $task->archiveFormat ) {
				$useGlobal = false;
				$useSite   = true;
			} else {
				$archiveFormat = $task->archiveFormat;
				$useGlobal     = false;
				$useSite       = false;
			}
		}

		if ( $useSite ) {
			if ( false === $website ) {
				$useGlobal = true;
			} else {
				$backupSettings = MainWP_DB_Backup::instance()->get_website_backup_settings( $website->id );
				$archiveFormat  = $backupSettings->archiveFormat;
				$useGlobal      = ( 'global' === $archiveFormat );
			}
		}

		if ( $useGlobal ) {
			$archiveFormat = get_option( 'mainwp_archiveFormat' );
			if ( false === $archiveFormat ) {
				$archiveFormat = 'tar.gz';
			}
		}

		return $archiveFormat;
	}

	public static function get_real_extension( $path ) {
		$checks = array( '.sql.zip', '.sql.tar', '.sql.tar.gz', '.sql.tar.bz2', '.tar.gz', '.tar.bz2' );
		foreach ( $checks as $check ) {
			if ( self::ends_with( $path, $check ) ) {
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
		$s = preg_replace( '@\x{00c4}@u', 'A', $s );
		$s = preg_replace( '@\x{00d6}@u', 'O', $s );
		$s = preg_replace( '@\x{00dc}@u', 'U', $s );
		$s = preg_replace( '@\x{00cb}@u', 'E', $s );
		$s = preg_replace( '@\x{00e4}@u', 'a', $s );
		$s = preg_replace( '@\x{00f6}@u', 'o', $s );
		$s = preg_replace( '@\x{00fc}@u', 'u', $s );
		$s = preg_replace( '@\x{00eb}@u', 'e', $s );
		$s = preg_replace( '@\x{00f1}@u', 'n', $s );
		$s = preg_replace( '@\x{00ff}@u', 'y', $s );
		return $s;
	}


	public static function esc_content( $content, $type = 'note' ) {
		if ( 'note' === $type ) {

			$allowed_html = array(
				'a'      => array(
					'href'  => array(),
					'title' => array(),
				),
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'p'      => array(),
				'hr'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'h1'     => array(),
				'h2'     => array(),
			);

			$content = wp_kses( $content, $allowed_html );

		} else {
			$content = wp_kses_post( $content );
		}

		return $content;
	}

	public static function show_mainwp_message( $type, $notice_id ) {
		$status = get_user_option( 'mainwp_notice_saved_status' );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		if ( isset( $status[ $notice_id ] ) ) {
			return false;
		}
		return true;
	}

	public static function reset_user_cookie( $what, $value = '' ) {
		global $current_user;
		$user_id = $current_user->ID;
		if ( $user_id ) {
			$reset_cookies = get_option( 'mainwp_reset_user_cookies' );
			if ( ! is_array( $reset_cookies ) ) {
				$reset_cookies = array();
			}

			if ( ! isset( $reset_cookies[ $user_id ] ) || ! isset( $reset_cookies[ $user_id ][ $what ] ) ) {
				$reset_cookies[ $user_id ][ $what ] = 1;
				self::update_option( 'mainwp_reset_user_cookies', $reset_cookies );
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


	public static function array_sort( &$array, $key, $sort_flag = SORT_STRING ) {
		$sorter = array();
		$ret    = array();
		reset( $array );
		foreach ( $array as $ii => $val ) {
			$sorter[ $ii ] = $val[ $key ];
		}
		asort( $sorter, $sort_flag );
		foreach ( $sorter as $ii => $val ) {
			$ret[ $ii ] = $array[ $ii ];
		}
		$array = $ret;
	}

	public static function enabled_wp_seo() {
		if ( null === self::$enabled_wp_seo ) {
			self::$enabled_wp_seo = is_plugin_active( 'wordpress-seo-extension/wordpress-seo-extension.php' );
		}
		return self::$enabled_wp_seo;
	}


	public static function get_page_id( $screen = null ) {

		if ( empty( $screen ) ) {
			$screen = get_current_screen();
		} elseif ( is_string( $screen ) ) {
			$screen = convert_to_screen( $screen );
		}

		if ( ! isset( $screen->id ) ) {
			return;
		}

		$page = $screen->id;

		return $page;
	}

	public static function generate_random_string( $length = 8 ) {

		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

		$charactersLength = strlen( $characters );

		$randomString = '';

		for ( $i = 0; $i < $length; $i++ ) {

			$randomString .= $characters[ wp_rand( 0, $charactersLength - 1 ) ];
		}

		return $randomString;
	}

	public static function value_to_string( $var ) {
		if ( is_array( $var ) || is_object( $var ) ) {
			//phpcs:ignore -- for debug only
			return print_r( $var, true );  // @codingStandardsIgnoreLine
		} elseif ( is_string( $var ) ) {
			return $var;
		}
		return '';
	}

	public static function get_child_response( $data ) {
		if ( is_serialized( $data ) ) {
			return unserialize( $data, array( 'allowed_classes' => false ) );
		} else {
			return json_decode( $data, true );
		}
	}

	public static function render_mainwp_nonce() {
		wp_nonce_field( 'mainwp-admin-nonce' );
	}

	public static function maybe_unserialyze( $data ) {
		if ( '' == $data || is_array( $data ) ) {
			return $data;
		} elseif ( is_serialized( $data ) ) {
			// phpcs:ignore -- for compatible.
			return maybe_unserialize( $data );
		} else {
			// phpcs:ignore -- for compatible.
			return maybe_unserialize( base64_decode( $data ) );
		}
	}

	/**
	 * Method get_openssl_conf()
	 *
	 * Get dashboard openssl configuration.
	 */
	public static function get_openssl_conf() {

		if ( defined( 'MAINWP_CRYPT_RSA_OPENSSL_CONFIG' ) ) {
			return MAINWP_CRYPT_RSA_OPENSSL_CONFIG;
		}

		$setup_conf_loc = '';
		if ( MainWP_Settings::is_local_window_config() ) {
			$setup_conf_loc = get_option( 'mwp_setup_opensslLibLocation' );
		} elseif ( get_option( 'mainwp_opensslLibLocation' ) != '' ) {
			$setup_conf_loc = get_option( 'mainwp_opensslLibLocation' );
		}
		return $setup_conf_loc;
	}

	/**
	 * Method sync_site_icon()
	 *
	 * Get site's icon.
	 *
	 * @param mixed $siteId site's id.
	 * @return array result error or success
	 */
	public static function sync_site_icon( $siteId = null ) {
		if ( self::ctype_digit( $siteId ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $siteId );
			if ( self::can_edit_website( $website ) ) {
				$error = '';
				try {
					$information = MainWP_Connect::fetch_url_authed( $website, 'get_site_icon' );
				} catch ( MainWP_Exception $e ) {
					$error = $e->getMessage();
				}

				if ( '' != $error ) {
					return array( 'error' => $error );
				} elseif ( isset( $information['faviIconUrl'] ) && ! empty( $information['faviIconUrl'] ) ) {
					MainWP_Logger::instance()->debug( 'Downloading icon :: ' . $information['faviIconUrl'] );
					$content = MainWP_Connect::get_file_content( $information['faviIconUrl'] );
					if ( ! empty( $content ) ) {

						$hasWPFileSystem = self::get_wp_file_system();
						global $wp_filesystem;

						$dirs     = self::get_mainwp_dir();
						$iconsDir = $dirs[0] . 'icons' . DIRECTORY_SEPARATOR;
						if ( $hasWPFileSystem && ! $wp_filesystem->is_dir( $iconsDir ) ) {
							$wp_filesystem->mkdir( $iconsDir, 0777, true );
						}
						if ( $hasWPFileSystem && ! $wp_filesystem->exists( $iconsDir . 'index.php' ) ) {
							$wp_filesystem->touch( $iconsDir . 'index.php' );
						}
						$filename = basename( $information['faviIconUrl'] );
						$filename = strtok( $filename, '?' );
						if ( $filename ) {
							$filename = 'favi-' . $siteId . '-' . $filename;
							$size     = file_put_contents( $iconsDir . $filename, $content );
							if ( $size ) {
								MainWP_Logger::instance()->debug( 'Icon size :: ' . $size );
								MainWP_DB::instance()->update_website_option( $website, 'favi_icon', $filename );
								return array( 'result' => 'success' );
							} else {
								return array( 'error' => 'Save icon file failed.' );
							}
						}
						return array( 'undefined_error' => true );
					} else {
						return array( 'error' => esc_html__( 'Download icon file failed', 'mainwp' ) );
					}
				} else {
					return array( 'undefined_error' => true );
				}
			}
		}
		return array( 'result' => 'NOSITE' );
	}


}
