<?php

class MainWP_Sync {
	public static function syncSite( &$pWebsite = null, $pForceFetch = false, $pAllowDisconnect = true ) {
		if ( $pWebsite == null ) {
			return false;
		}
		$userExtension = MainWP_DB::Instance()->getUserExtensionByUserId( $pWebsite->userid );
		if ( $userExtension == null ) {
			return false;
		}

		MainWP_Utility::endSession();

		try {
			$pluginDir = $pWebsite->pluginDir;
			if ( $pluginDir == '' ) {
				$pluginDir = $userExtension->pluginDir;
			}

			$cloneEnabled = apply_filters( 'mainwp_clone_enabled', false );
			$cloneSites   = array();
			if ( $cloneEnabled ) {
				$disallowedCloneSites = get_option( 'mainwp_clone_disallowedsites' );
				if ( $disallowedCloneSites === false ) {
					$disallowedCloneSites = array();
				}
				$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
				if ( $websites ) {
					while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
						if ( in_array( $website->id, $disallowedCloneSites ) ) {
							continue;
						}
						if ( $website->id == $pWebsite->id ) {
							continue;
						}

						$cloneSites[ $website->id ] = array(
							'name'    => $website->name,
							'url'     => $website->url,
							'extauth' => $website->extauth,
							'size'    => $website->totalsize,
						);
					}
					@MainWP_DB::free_result( $websites );
				}
			}

            $primaryBackup = MainWP_Utility::get_primary_backup();

			$othersData  = apply_filters( 'mainwp-sync-others-data', array(), $pWebsite );
			$information = MainWP_Utility::fetchUrlAuthed( $pWebsite, 'stats',
				array(
					'optimize'                     => ( ( get_option( 'mainwp_optimize' ) == 1 ) ? 1 : 0 ),
					'heatMap'                      => ( MainWP_Extensions::isExtensionAvailable( 'mainwp-heatmap-extension' ) ? $userExtension->heatMap : 0 ),
					'pluginDir'                    => $pluginDir,
					'cloneSites'                   => ( ! $cloneEnabled ? 0 : urlencode( json_encode( $cloneSites ) ) ),
					'othersData'                   => json_encode( $othersData ),
					'server'                       => get_admin_url(),
					'numberdaysOutdatePluginTheme' => get_option( 'mainwp_numberdays_Outdate_Plugin_Theme', 365 ),
                    'primaryBackup' => $primaryBackup
				),
				true, $pForceFetch
			);
            MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'primary_lasttime_backup', isset( $information['primaryLasttimeBackup'] ) ? $information['primaryLasttimeBackup'] : 0 );
			$return = self::syncInformationArray( $pWebsite, $information, '', 1, false, $pAllowDisconnect );

			return $return;
		} catch ( MainWP_Exception $e ) {
			$sync_errors          = '';
			$offline_check_result = 1;

			if ( $e->getMessage() == 'HTTPERROR' ) {
				$sync_errors          = __( 'HTTP error', 'mainwp' ) . ( $e->getMessageExtra() != null ? ' - ' . $e->getMessageExtra() : '' );
				$offline_check_result = - 1;
			} else if ( $e->getMessage() == 'NOMAINWP' ) {
				$sync_errors          = __( 'MainWP Child plugin not detected', 'mainwp' );
				$offline_check_result = 1;
			}

			return self::syncInformationArray( $pWebsite, $information, $sync_errors, $offline_check_result, true, $pAllowDisconnect );
		}
	}

	public static function syncInformationArray( &$pWebsite, &$information, $sync_errors = '', $offline_check_result = 1, $error = false, $pAllowDisconnect = true ) {
		$emptyArray        = json_encode( array() );
		$websiteValues     = array(
			'directories'          => $emptyArray,
			'plugin_upgrades'      => $emptyArray,
			'theme_upgrades'       => $emptyArray,
			'translation_upgrades' => $emptyArray,
			'securityIssues'       => $emptyArray,
			'themes'               => $emptyArray,
			'plugins'              => $emptyArray,
			'users'                => $emptyArray,
			'categories'           => $emptyArray,
			'offline_check_result' => $offline_check_result,
		);
		$websiteSyncValues = array(
			'uptodate'    => 0,
			'sync_errors' => $sync_errors,
			'version'     => 0,
		);

		$done = false;

        $information = apply_filters('mainwp_before_save_sync_result', $information, $pWebsite);

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
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'site_info', @json_encode( $information['site_info'] ) );
			$done = true;
		} else {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'site_info', $emptyArray );
		}
        MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'phpversion', $phpversion );


		if ( isset( $information['directories'] ) && is_array( $information['directories'] ) ) {
			$websiteValues['directories'] = @json_encode( $information['directories'] );
			$done                         = true;
		} else if ( isset( $information['directories'] ) ) {
			$websiteValues['directories'] = $information['directories'];
			$done                         = true;
		}

		if ( isset( $information['wp_updates'] ) && $information['wp_updates'] != null ) {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'wp_upgrades', @json_encode( array(
				'current' => $information['wpversion'],
				'new'     => $information['wp_updates'],
			) ) );
			$done = true;
		} else {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'wp_upgrades', $emptyArray );
		}

		if ( isset( $information['plugin_updates'] ) ) {
			$websiteValues['plugin_upgrades'] = @json_encode( $information['plugin_updates'] );
			$done                             = true;
		}

		if ( isset( $information['theme_updates'] ) ) {
			$websiteValues['theme_upgrades'] = @json_encode( $information['theme_updates'] );
			$done                            = true;
		}

		if ( isset( $information['translation_updates'] ) ) {
			$websiteValues['translation_upgrades'] = @json_encode( $information['translation_updates'] );
			$done                            = true;
		}

		if ( isset( $information['premium_updates'] ) ) {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'premium_upgrades', @json_encode( $information['premium_updates'] ) );
			$done = true;
		} else {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'premium_upgrades', $emptyArray );
		}

		if ( isset( $information['securityIssues'] ) && MainWP_Utility::ctype_digit( $information['securityIssues'] ) && $information['securityIssues'] >= 0 ) {
			$websiteValues['securityIssues'] = $information['securityIssues'];
			$done                            = true;
		}

		if ( isset( $information['recent_comments'] ) ) {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'recent_comments', @json_encode( $information['recent_comments'] ) );
			$done = true;
		} else {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'recent_comments', $emptyArray );
		}

		if ( isset( $information['recent_posts'] ) ) {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'recent_posts', @json_encode( $information['recent_posts'] ) );
			$done = true;
		} else {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'recent_posts', $emptyArray );
		}

		if ( isset( $information['recent_pages'] ) ) {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'recent_pages', @json_encode( $information['recent_pages'] ) );
			$done = true;
		} else {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'recent_pages', $emptyArray );
		}

		if ( isset( $information['themes'] ) ) {
			$websiteValues['themes'] = @json_encode( $information['themes'] );
			$done                    = true;
		}


		$to_fix = false;
		if ( isset( $information['plugins'] ) ) {
			foreach( $information['plugins'] as $info ) {
				if ( isset($info['slug']) && in_array( $info['slug'], array( 'ithemes-security-pro/ithemes-security-pro.php', 'monarch/monarch.php', 'cornerstone/cornerstone.php', 'updraftplus/updraftplus.php') ) ) {
					$to_fix = true;
				}
			}
			$websiteValues['plugins'] = @json_encode( $information['plugins'] );
			$done                     = true;
		}

		if ( $to_fix ) {
			$request = get_option( 'mainwp_request_plugins_page_site_' . $pWebsite->id );
			if ( empty( $request ) )
				update_option( 'mainwp_request_plugins_page_site_' . $pWebsite->id, 'yes' );
		} else {
			if ( 'yes' == get_option( 'mainwp_request_plugins_page_site_' . $pWebsite->id ) )
				delete_option( 'mainwp_request_plugins_page_site_' . $pWebsite->id );
		}

		if ( isset( $information['users'] ) ) {
			$websiteValues['users'] = @json_encode( $information['users'] );
			$done                   = true;
		}

		if ( isset( $information['categories'] ) ) {
			$websiteValues['categories'] = @json_encode( $information['categories'] );
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

//		if ( isset( $information['faviIcon'] ) ) {
//			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'favi_icon', trim( $information['faviIcon'] ) );
//			$done = true;
//		} else {
//			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'favi_icon', '' );
//		}

		if ( isset( $information['plugins_outdate_info'] ) ) {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'plugins_outdate_info', @json_encode( $information['plugins_outdate_info'] ) );
			$done = true;
		} else {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'plugins_outdate_info', $emptyArray );
		}

		if ( isset( $information['themes_outdate_info'] ) ) {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'themes_outdate_info', @json_encode( $information['themes_outdate_info'] ) );
			$done = true;
		} else {
			MainWP_DB::Instance()->updateWebsiteOption( $pWebsite, 'themes_outdate_info', $emptyArray );
		}

		if ( ! $done ) {
			if ( isset( $information['wpversion'] ) ) {
				$websiteSyncValues['uptodate'] = 1;
				$done                          = true;
			} else if ( isset( $information['error'] ) ) {
				MainWP_Logger::Instance()->warningForWebsite( $pWebsite, 'SYNC ERROR', '[' . $information['error'] . ']' );
				$error                            = true;
				$done                             = true;
				$websiteSyncValues['sync_errors'] = __( 'ERROR: ', 'mainwp' ) . $information['error'];
			} else if ( ! empty( $sync_errors ) ) {
				MainWP_Logger::Instance()->warningForWebsite( $pWebsite, 'SYNC ERROR', '[' . $sync_errors . ']' );

				$error = true;
				if ( ! $pAllowDisconnect ) {
					$sync_errors = '';
				}

				$websiteSyncValues['sync_errors'] = $sync_errors;
			} else {
				MainWP_Logger::Instance()->warningForWebsite( $pWebsite, 'SYNC ERROR', '[Undefined error]' );
				$error = true;
				if ( $pAllowDisconnect ) {
					$websiteSyncValues['sync_errors'] = __( 'Undefined error! Please, reinstall the MainWP Child plugin on the child site.', 'mainwp' );
				}
			}
		}

		if ( $done ) {
			$websiteSyncValues['dtsSync'] = time();
		}
		MainWP_DB::Instance()->updateWebsiteSyncValues( $pWebsite->id, $websiteSyncValues );
		MainWP_DB::Instance()->updateWebsiteValues( $pWebsite->id, $websiteValues );

		//Sync action
		if ( ! $error ) {
			do_action( 'mainwp-site-synced', $pWebsite, $information );
		}

		return ( ! $error );
	}

	public static function statsUpdate( $pSite = null ) {
		//todo: implement
	}

	public static function offlineCheck( $pSite = null ) {
		//todo: implement

	}
}
