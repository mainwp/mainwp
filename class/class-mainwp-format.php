<?php
/**
 * MainWP Format Utility
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Format
 *
 * @package MainWP\Dashboard
 */
class MainWP_Format {

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
	 * Method get_update_plugins_items().
	 *
	 * Get plugins update.
	 *
	 * @return array $update_items Plugins update items.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::array_merge()
	 */
	public static function get_update_plugins_items() {

		$pluginsNewUpdate = get_option( 'mainwp_updatescheck_mail_update_plugins_new' );
		if ( ! is_array( $pluginsNewUpdate ) ) {
			$pluginsNewUpdate = array();
		}
		$pluginsToUpdate = get_option( 'mainwp_updatescheck_mail_update_plugins' );
		if ( ! is_array( $pluginsToUpdate ) ) {
			$pluginsToUpdate = array();
		}
		$notTrustedPluginsNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_plugins_new' );
		if ( ! is_array( $notTrustedPluginsNewUpdate ) ) {
			$notTrustedPluginsNewUpdate = array();
		}
		$notTrustedPluginsToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_plugins' );
		if ( ! is_array( $notTrustedPluginsToUpdate ) ) {
			$notTrustedPluginsToUpdate = array();
		}

		$update_items = array();
		$update_items = MainWP_Utility::array_merge( $pluginsNewUpdate, $pluginsToUpdate );
		$update_items = MainWP_Utility::array_merge( $update_items, $notTrustedPluginsNewUpdate );
		$update_items = MainWP_Utility::array_merge( $update_items, $notTrustedPluginsToUpdate );

		return $update_items;
	}

	/**
	 * Method get_update_themes_items().
	 *
	 * Get themes update items to email.
	 *
	 * @return array $update_items Update themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::array_merge()
	 */
	public static function get_update_themes_items() {

		$themesNewUpdate = get_option( 'mainwp_updatescheck_mail_update_themes_new' );
		if ( ! is_array( $themesNewUpdate ) ) {
			$themesNewUpdate = array();
		}
		$themesToUpdate = get_option( 'mainwp_updatescheck_mail_update_themes' );
		if ( ! is_array( $themesToUpdate ) ) {
			$themesToUpdate = array();
		}
		$notTrustedThemesNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_themes_new' );
		if ( ! is_array( $notTrustedThemesNewUpdate ) ) {
			$notTrustedThemesNewUpdate = array();
		}
		$notTrustedThemesToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_themes' );
		if ( ! is_array( $notTrustedThemesToUpdate ) ) {
			$notTrustedThemesToUpdate = array();
		}

		$update_items = array();

		$update_items = MainWP_Utility::array_merge( $themesNewUpdate, $themesToUpdate );
		$update_items = MainWP_Utility::array_merge( $update_items, $notTrustedThemesNewUpdate );
		$update_items = MainWP_Utility::array_merge( $update_items, $notTrustedThemesToUpdate );

		return $update_items;
	}

	/**
	 * Method get_update_wp_items().
	 *
	 * Get WP update to email.
	 *
	 * @return array $update_items WP update items.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::array_merge()
	 */
	public static function get_update_wp_items() {

		$coreNewUpdate = get_option( 'mainwp_updatescheck_mail_update_core_new' );
		if ( ! is_array( $coreNewUpdate ) ) {
			$coreNewUpdate = array();
		}
		$coreToUpdate = get_option( 'mainwp_updatescheck_mail_update_core' );
		if ( ! is_array( $coreToUpdate ) ) {
			$coreToUpdate = array();
		}
		$ignoredCoreNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_core_new' );
		if ( ! is_array( $ignoredCoreNewUpdate ) ) {
			$ignoredCoreNewUpdate = array();
		}
		$ignoredCoreToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_core' );
		if ( ! is_array( $ignoredCoreToUpdate ) ) {
			$ignoredCoreToUpdate = array();
		}

		$update_items = array();

		$update_items = MainWP_Utility::array_merge( $coreNewUpdate, $coreToUpdate );
		$update_items = MainWP_Utility::array_merge( $update_items, $ignoredCoreNewUpdate );
		$update_items = MainWP_Utility::array_merge( $update_items, $ignoredCoreToUpdate );

		return $update_items;
	}

	/**
	 * Method get_site_updates_items().
	 *
	 * Get Updates items of websites.
	 *
	 * @param string $what values: plugin, theme, wpcore.
	 * @param array  $sites_ids Websites ids filter (option).
	 *
	 * @return array $update_items WP update items.
	 */
	public static function get_site_updates_items( $what, $sites_ids = false ) {

		$items = array();
		if ( 'plugin' === $what ) {
			$items = self::get_update_plugins_items();
		} elseif ( 'theme' === $what ) {
			$items = self::get_update_themes_items();
		} elseif ( 'wpcore' === $what ) {
			$items = self::get_update_wp_items();
		}

		$filters = array();
		foreach ( $items as $item ) {
			if ( isset( $item['id'] ) ) { // to valid and compatible data.
				if ( ! empty( $sites_ids ) ) { // if filter by sites ids.
					if ( ! in_array( $item['id'], $sites_ids ) ) {
						continue;
					}
				}
				$filters[] = $item;
			}
		}
		return $filters;
	}

	/**
	 * Method format_email()
	 *
	 * Format email.
	 *
	 * @param string $to_email Send to emails.
	 * @param string $body Email's body.
	 * @param string $title Email's title.
	 * @param bool   $plain_text text format.
	 *
	 * @return string Formatted content
	 */
	public static function format_email( $to_email = null, $body = '', $title = '', $plain_text = false ) {

		$current_year = gmdate( 'Y' );
		if ( $plain_text ) {
				$mail_send['header'] = '';
				$mail_send['body']   = 'Hi,' . "\r\n\r\n" .
										( ( ! empty( $title ) ) ? $title . "\r\n\r\n" : '' ) .
										$body . "\r\n\r\n";
				$mail_send['footer'] = 'MainWP: https://mainwp.com' . "\r\n" .
										'Extensions: https://mainwp.com/mainwp-extensions/' . "\r\n" .
										'Documentation: https://kb.mainwp.com/' . "\r\n" .
										'Blog: https://mainwp.com/mainwp-blog/' . "\r\n" .
										'Codex: https://mainwp.dev/' . "\r\n" .
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

                                                <a href="https://mainwp.com" target="_blank" style="color: #446200;font-size:45px;font-weight: normal;text-decoration: underline;">MainWP</a>

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

			$title_content = ! empty( $title ) ? '<b style="color: rgb(127, 177, 0); font-family: Helvetica, Sans; font-size: medium; line-height: normal;"> ' . $title . ' </b><br>' : '';

			$mail_send['body'] = <<<EOT
                                                    <table border="0" cellpadding="20" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td valign="top" style="border-collapse: collapse;">
                                                                <div style="color: #505050;font-family: Arial;font-size: 14px;line-height: 150%;text-align: left;"> Hi, <br><br>
                                                                {$title_content}
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
                                                                    <a href="https://mainwp.com" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">MainWP</a> | <a href="https://mainwp.com/mainwp-extensions/" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">Extensions</a> | <a href="https://kb.mainwp.com/" target="_self" style="color: #7fb100;font-weight: normal;text-decoration:none;text-transform: uppercase;">Documentation</a> | <a href="https://mainwp.com/mainwp-blog/" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">Blog</a> | <a href="http://codex.mainwp.com" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">Codex</a> | <a href="https://mainwp.com/support/" target="_self" style="color: #7fb100;font-weight: normal;text-decoration: none;text-transform: uppercase;">Support</a></div>

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
}
