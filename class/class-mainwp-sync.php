<?php
/**
 * MainWP Sync Handler
 * 
 * Handle all syncing between MainWP & Child Site Network.
 */
namespace MainWP\Dashboard;

/**
 * MainWP Sync Handler
 */
class MainWP_Sync {

	/**
	 * Method sync_site()
	 * 
	 * @param mixed $pWebsite Null|userid.
	 * @param boolean $pForceFetch Check if a fourced Sync.
	 * @param boolean $pAllowDisconnect Check if allowed to disconect.
	 * 
	 * @return array sync_information_array
	 */
	public static function sync_site( &$pWebsite = null, $pForceFetch = false, $pAllowDisconnect = true ) {
		if ( $pWebsite == null ) {
			return false;
		}
		$userExtension = MainWP_DB::instance()->get_user_extension_by_user_id( $pWebsite->userid );
		if ( $userExtension == null ) {
			return false;
		}

		MainWP_Utility::end_session();

		try {

			$cloneEnabled = apply_filters( 'mainwp_clone_enabled', false );
			$cloneSites   = array();
			if ( $cloneEnabled ) {
				$disallowedCloneSites = get_option( 'mainwp_clone_disallowedsites' );
				if ( $disallowedCloneSites === false ) {
					$disallowedCloneSites = array();
				}
				$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
				if ( $websites ) {
					while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
						if ( in_array( $website->id, $disallowedCloneSites ) ) {
							continue;
						}
						if ( $website->id == $pWebsite->id ) {
							continue;
						}

						$cloneSites[ $website->id ] = array(
							'name'       => $website->name,
							'url'        => $website->url,
							'extauth'    => $website->extauth,
							'size'       => $website->totalsize,
						);
					}
					MainWP_DB::free_result( $websites );
				}
			}

			$primaryBackup = MainWP_Utility::get_primary_backup();

			$othersData  = apply_filters( 'mainwp-sync-others-data', array(), $pWebsite );
			$information = MainWP_Utility::fetch_url_authed( $pWebsite, 'stats', array(
				'optimize'                       => ( ( get_option( 'mainwp_optimize' ) == 1 ) ? 1 : 0 ),
				'heatMap'                        => 0,
				'cloneSites'                     => ( ! $cloneEnabled ? 0 : urlencode( wp_json_encode( $cloneSites ) ) ),
				'othersData'                     => wp_json_encode( $othersData ),
				'server'                         => get_admin_url(),
				'numberdaysOutdatePluginTheme'   => get_option( 'mainwp_numberdays_Outdate_Plugin_Theme', 365 ),
				'primaryBackup'                  => $primaryBackup,
				'siteId'                         => $pWebsite->id,
			), true, $pForceFetch
			);
			MainWP_DB::instance()->update_website_option( $pWebsite, 'primary_lasttime_backup', isset( $information['primaryLasttimeBackup'] ) ? $information['primaryLasttimeBackup'] : 0  );
			$return = self::sync_information_array( $pWebsite, $information, '', 1, false, $pAllowDisconnect );

			return $return;
		} catch ( MainWP_Exception $e ) {
			$sync_errors          = '';
			$offline_check_result = 1;

			if ( $e->getMessage() == 'HTTPERROR' ) {
				$sync_errors          = __( 'HTTP error', 'mainwp' ) . ( $e->get_message_extra() != null ? ' - ' . $e->get_message_extra() : '' );
				$offline_check_result = - 1;
			} elseif ( $e->getMessage() == 'NOMAINWP' ) {
				$sync_errors          = __( 'MainWP Child plugin not detected', 'mainwp' );
				$offline_check_result = 1;
			}

			return self::sync_information_array( $pWebsite, $information, $sync_errors, $offline_check_result, true, $pAllowDisconnect );
		}
	}

	/**
	 * Method sync_information_array()
	 * 
	 * Grab all Child Site Information.
	 * 
	 * @param mixed $pWebsite 
	 * @param mixed $information Filter mainwp_before_save_sync_result
	 * @param string $sync_errors Check for Sync Errors.
	 * @param integer $offline_check_result Check if offline.
	 * @param boolean $error True|False.
	 * @param boolean $pAllowDisconnect True|False.
	 * 
	 * @return mixed do_action( 'mainwp-site-synced', $pWebsite, $information ).
	 */
	public static function sync_information_array( &$pWebsite, &$information, $sync_errors = '', $offline_check_result = 1, $error = false, $pAllowDisconnect = true ) {
		$emptyArray        = wp_json_encode( array() );
		$websiteValues     = array(
			'directories'            => $emptyArray,
			'plugin_upgrades'        => $emptyArray,
			'theme_upgrades'         => $emptyArray,
			'translation_upgrades'   => $emptyArray,
			'securityIssues'         => $emptyArray,
			'themes'                 => $emptyArray,
			'plugins'                => $emptyArray,
			'users'                  => $emptyArray,
			'categories'             => $emptyArray,
			'offline_check_result'   => $offline_check_result,
		);
		$websiteSyncValues = array(
			'uptodate'       => 0,
			'sync_errors'    => $sync_errors,
			'version'        => 0,
		);

		$done = false;

		$information = apply_filters( 'mainwp_before_save_sync_result', $information, $pWebsite );

		if ( isset( $information['siteurl'] ) ) {
			$websiteValues['siteurl'] = $information['siteurl'];
			$done                     = true;
		}

		if ( isset( $information['version'] ) ) {
			$websiteSyncValues['version'] = $information['version'];
			$done                         = true;
		}

		$phpversion = '';
		if ( isset( $information['site_info'] ) && $information['site_info'] != null ) {
			if ( is_array( $information['site_info'] ) && isset( $information['site_info']['phpversion'] ) ) {
				$phpversion = $information['site_info']['phpversion'];
			}
			MainWP_DB::instance()->update_website_option( $pWebsite, 'site_info', wp_json_encode( $information['site_info'] ) );
			$done = true;
		} else {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'site_info', $emptyArray );
		}
		MainWP_DB::instance()->update_website_option( $pWebsite, 'phpversion', $phpversion );

		if ( isset( $information['directories'] ) && is_array( $information['directories'] ) ) {
			$websiteValues['directories'] = wp_json_encode( $information['directories'] );
			$done                         = true;
		} elseif ( isset( $information['directories'] ) ) {
			$websiteValues['directories'] = $information['directories'];
			$done                         = true;
		}

		if ( isset( $information['wp_updates'] ) && $information['wp_updates'] != null ) {
			MainWP_DB::instance()->update_website_option(
				$pWebsite, 'wp_upgrades', wp_json_encode(
					array(
						'current'    => $information['wpversion'],
						'new'        => $information['wp_updates'],
					)
				)
			);
			$done = true;
		} else {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'wp_upgrades', $emptyArray );
		}

		if ( isset( $information['plugin_updates'] ) ) {
			$websiteValues['plugin_upgrades'] = wp_json_encode( $information['plugin_updates'] );
			$done                             = true;
		}

		if ( isset( $information['theme_updates'] ) ) {
			$websiteValues['theme_upgrades'] = wp_json_encode( $information['theme_updates'] );
			$done                            = true;
		}

		if ( isset( $information['translation_updates'] ) ) {
			$websiteValues['translation_upgrades'] = wp_json_encode( $information['translation_updates'] );
			$done                                  = true;
		}

		if ( isset( $information['premium_updates'] ) ) {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'premium_upgrades', wp_json_encode( $information['premium_updates'] ) );
			$done = true;
		} else {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'premium_upgrades', $emptyArray );
		}

		if ( isset( $information['securityIssues'] ) && MainWP_Utility::ctype_digit( $information['securityIssues'] ) && $information['securityIssues'] >= 0 ) {
			$websiteValues['securityIssues'] = $information['securityIssues'];
			$done                            = true;
		}

		if ( isset( $information['recent_comments'] ) ) {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_comments', wp_json_encode( $information['recent_comments'] ) );
			$done = true;
		} else {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_comments', $emptyArray );
		}

		if ( isset( $information['recent_posts'] ) ) {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_posts', wp_json_encode( $information['recent_posts'] ) );
			$done = true;
		} else {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_posts', $emptyArray );
		}

		if ( isset( $information['recent_pages'] ) ) {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_pages', wp_json_encode( $information['recent_pages'] ) );
			$done = true;
		} else {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_pages', $emptyArray );
		}

		if ( isset( $information['themes'] ) ) {
			$websiteValues['themes'] = wp_json_encode( $information['themes'] );
			$done                    = true;
		}

		if ( isset( $information['plugins'] ) ) {
			$websiteValues['plugins'] = MainWP_Utility::safe_json_encode($information['plugins']);
			$done                     = true;
		}

		if ( isset( $information['users'] ) ) {
			$websiteValues['users'] = wp_json_encode( $information['users'] );
			$done                   = true;
		}

		if ( isset( $information['categories'] ) ) {
			$websiteValues['categories'] = MainWP_Utility::safe_json_encode( $information['categories'] );
			$done                        = true;
		}

		if ( isset( $information['totalsize'] ) ) {
			$websiteSyncValues['totalsize'] = $information['totalsize'];
			$done                           = true;
		}

		if ( isset( $information['dbsize'] ) ) {
			$websiteSyncValues['dbsize'] = $information['dbsize'];
			$done                        = true;
		}

		if ( isset( $information['extauth'] ) ) {
			$websiteSyncValues['extauth'] = $information['extauth'];
			$done                         = true;
		}

		if ( isset( $information['nossl'] ) ) {
			$websiteValues['nossl'] = $information['nossl'];
			$done                   = true;
		}

		if ( isset( $information['wpe'] ) ) {
			$websiteValues['wpe'] = $information['wpe'];
			$done                 = true;
		}

		if ( isset( $information['last_post_gmt'] ) ) {
			$websiteSyncValues['last_post_gmt'] = $information['last_post_gmt'];
			$done                               = true;
		}

		if ( isset( $information['mainwpdir'] ) ) {
			$websiteValues['mainwpdir'] = $information['mainwpdir'];
			$done                       = true;
		}

		if ( isset( $information['uniqueId'] ) ) {
			$websiteValues['uniqueId'] = $information['uniqueId'];
			$done                      = true;
		}

		if ( isset( $information['admin_nicename'] ) ) {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'admin_nicename', trim( $information['admin_nicename'] ) );
			$done = true;
		}

		if ( isset( $information['admin_useremail'] ) ) {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'admin_useremail', trim( $information['admin_useremail'] ) );
			$done = true;
		}

		if ( isset( $information['plugins_outdate_info'] ) ) {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'plugins_outdate_info', wp_json_encode( $information['plugins_outdate_info'] ) );
			$done = true;
		} else {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'plugins_outdate_info', $emptyArray );
		}

		if ( isset( $information['themes_outdate_info'] ) ) {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'themes_outdate_info', wp_json_encode( $information['themes_outdate_info'] ) );
			$done = true;
		} else {
			MainWP_DB::instance()->update_website_option( $pWebsite, 'themes_outdate_info', $emptyArray );
		}

		if ( ! $done ) {
			if ( isset( $information['wpversion'] ) ) {
				$websiteSyncValues['uptodate'] = 1;
				$done                          = true;
			} elseif ( isset( $information['error'] ) ) {
				MainWP_Logger::instance()->warning_for_website( $pWebsite, 'SYNC ERROR', '[' . $information['error'] . ']' );
				$error                            = true;
				$done                             = true;
				$websiteSyncValues['sync_errors'] = __( 'ERROR: ', 'mainwp' ) . $information['error'];
			} elseif ( ! empty( $sync_errors ) ) {
				MainWP_Logger::instance()->warning_for_website( $pWebsite, 'SYNC ERROR', '[' . $sync_errors . ']' );

				$error = true;
				if ( ! $pAllowDisconnect ) {
					$sync_errors = '';
				}

				$websiteSyncValues['sync_errors'] = $sync_errors;
			} else {
				MainWP_Logger::instance()->warning_for_website( $pWebsite, 'SYNC ERROR', '[Undefined error]' );
				$error = true;
				if ( $pAllowDisconnect ) {
					$websiteSyncValues['sync_errors'] = __( 'Undefined error! Please, reinstall the MainWP Child plugin on the child site.', 'mainwp' );
				}
			}
		}

		if ( $done ) {
			$websiteSyncValues['dtsSync'] = time();
		}
		MainWP_DB::instance()->update_website_sync_values( $pWebsite->id, $websiteSyncValues );
		MainWP_DB::instance()->update_website_values( $pWebsite->id, $websiteValues );

		// Sync action
		if ( ! $error ) {
			do_action( 'mainwp-site-synced', $pWebsite, $information );
		}

		return ( ! $error );
	}
}
