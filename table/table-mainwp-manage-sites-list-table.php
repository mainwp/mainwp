<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}


class MainWP_Manage_Sites_List_Table extends WP_List_Table {
	protected $userExtension = NULL;

	function __construct() {
		parent::__construct( array(
			'singular' => __( 'site', 'mainwp' ), //singular name of the listed records
			'plural'   => __( 'sites', 'mainwp' ), //plural name of the listed records
			'ajax'     => true,//does this table support ajax?

		) );

		//        add_action('admin_head', array(&$this, 'admin_header'));
	}

	//    function admin_header()
	//    {
	//        $page = (isset($_GET['page'])) ? esc_attr($_GET['page']) : false;
	//        if ('my_list_test' != $page)
	//            return;
	//        echo '<style type="text/css">';
	//        echo '.wp-list-table .column-id { width: 5%; }';
	//        echo '.wp-list-table .column-booktitle { width: 40%; }';
	//        echo '.wp-list-table .column-author { width: 35%; }';
	//        echo '.wp-list-table .column-isbn { width: 20%;}';
	//        echo '</style>';
	//    }

	function no_items() {
		$out =  __( 'No sites found.', 'mainwp' );
        if (!isset($_GET['s'])) {
            $out .= '<br/><br/><em>' . __( 'If sites are missing from your display but you know those sites are connected to your dashboard be sure to check the Status drop down filter and adjust it to your needs.', 'mainwp' ) . '</em>';
            if ( MainWP_DB::Instance()->getWebsitesCount() > 0 ) {
                $out .= '<br/><br/>';
                $out .= '<em>' . sprintf(__('If all your child sites are missing from your MainWP Dashboard, please check this %shelp document%s.', 'mainwp'), '<a href="https://mainwp.com/help/docs/all-child-sites-disappeared-from-my-mainwp-dashboard/" target="_blank">', '</a>') . '</em>';
            }
        }
        echo $out;
	}


	protected function get_default_primary_column_name() {
		return 'site';
	}

	function column_default( $item, $column_name ) {

		$item = apply_filters( 'mainwp-sitestable-item', $item, $item );

		switch ( $column_name ) {
			case 'status':
            case 'wpcore_update':
            case 'plugin_update':
            case 'theme_update':
			case 'site':
			case 'url':
			case 'groups':
			case 'backup':
			case 'last_sync':
			case 'last_post':
			case 'seo':
			case 'notes':
            case 'phpversion':
				//case 'site_actions':
				return $item[ $column_name ];
			default:
				return $item[ $column_name ];
			// return print_r($item, true); //Show the whole array for troubleshooting purposes
		}
	}

	function get_sortable_columns() {
		$sortable_columns = array(
			'site'      => array( 'site', false ),
			'url'       => array( 'url', false ),
			'groups'    => array( 'groups', false ),
			'last_sync' => array( 'last_sync', false ),
			'last_post' => array( 'last_post', false ),
            'phpversion' => array( 'phpversion', false ),
            'wpcore_update' => array( 'wpcore_update', false ),
            'plugin_update' => array( 'plugin_update', false ),
            'theme_update' => array( 'theme_update', false ),
		);

		return $sortable_columns;
	}

	function get_columns() {
		$columns = array(
			'cb'           => '<input type="checkbox" />',
			'status'       => __( 'Status', 'mainwp' ),
            'wpcore_update'       => '<i class="fa fa-wordpress" aria-hidden="true"></i>',
            'plugin_update'       => '<i class="fa fa-plug" aria-hidden="true"></i>',
            'theme_update'       => '<i class="fa fa-paint-brush" aria-hidden="true"></i>',
			'site'         => __( 'Site', 'mainwp' ),
			'url'          => __( 'URL', 'mainwp' ),
			'groups'       => __( 'Groups', 'mainwp' ),
			'backup'       => __( 'Backup', 'mainwp' ),
			'last_sync'    => __( 'Last Sync', 'mainwp' ),
			'last_post'    => __( 'Last Post', 'mainwp' ),
			'notes'        => __( 'Notes', 'mainwp' ),
			'site_actions' => __( 'Actions', 'mainwp' ),
            'phpversion'   => __( 'PHP Version', 'mainwp' ),
		);

		$columns = apply_filters( 'mainwp-sitestable-getcolumns', $columns, $columns );

                $disable_backup = false;
                $primaryBackup = get_option('mainwp_primaryBackup');
		$primaryBackupMethods = apply_filters("mainwp-getprimarybackup-methods", array());                
                if (empty($primaryBackup)) {
                        if (!get_option('mainwp_enableLegacyBackupFeature')) {
                            $disable_backup = true;
                        }
                } else if (!is_array($primaryBackupMethods) || empty($primaryBackupMethods)) {
                    $disable_backup = true;                                                   
		}
                
                if ($disable_backup && isset($columns['backup'])) {
                        unset($columns['backup']);
                } 
                
		return $columns;
	}
        
	function column_site_actions( $item ) {
		if ( $item['sync_errors'] != '' ) {
			$reconnect_lnk = '<a class="mainwp_site_reconnect" href="#" siteid="' . $item['id'] . '" style="margin-right: .5em;" title="Reconnect child site"><i class="fa fa-plug fa-lg"></i></a>';
		} else {
			$reconnect_lnk = '';
		}

		if ( ! mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) {
			$dashboard_lnk = '';
		} else {
			$dashboard_lnk = '<a href="admin.php?page=managesites&dashboard=' . $item['id'] . '" style="margin-right: .5em;" title="Open child site dashboard"><i class="fa fa-tachometer fa-lg"></i></a>';
		}

		$sync_lnk = '<a href="#" class="managesites_syncdata" style="margin-right: .5em;" title="Sync child site"><i class="fa fa-refresh fa-lg"></i></a>';

		if ( ! mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) {
			$edit_lnk = '';
		} else {
			$edit_lnk = '<a href="admin.php?page=managesites&id=' . $item['id'] . '" style="margin-right: .5em;" title="Edit Child Site"><i class="fa fa-pencil-square-o fa-lg"></i></a>';
		}

		if ( ! mainwp_current_user_can( 'dashboard', 'test_connection' ) ) {
			$test_lnk = '';
		} else {
			$test_lnk = '<a href="#" class="mainwp_site_testconnection" class="test_connection" style="margin-right: .5em;" title="Test Connection"><i class="fa fa-link fa-lg"></i></a>';
		}
                
                $backup_lnk = '';
		if ( ! mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
			$backup_lnk = '';
		} else {
                    if (get_option('mainwp_enableLegacyBackupFeature')) {
			$backup_lnk = '<a href="admin.php?page=managesites&backupid=' . $item['id'] . '" style="margin-right: .5em;" title="Backup Child Site"><i class="fa fa-hdd-o fa-lg"></i></a>';
                    }
		}

		if ( ! mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) {
			$wp_admin_new_lnk = '';
		} else {
			$wp_admin_new_lnk = '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $item['id'] . '" class="open_newwindow_wpadmin" target="_blank" style="margin-right: .5em;" title="Open Child Site WP Admin"><i class="fa fa-external-link fa-lg"></i></a>';
		}

		$post_lnk = '<a href="admin.php?page=PostBulkAdd&select=' . $item['id'] . '" style="margin-right: .5em;" title="Add New Post"><i class="fa fa-file-text fa-lg"></i></a>';

		$notes_lnk = '<a href="#" class="mainwp_notes_show_all" id="mainwp_notes_' . $item['id'] . '" style="margin-right: .5em;" title="Open Child Site Notes"><i class="fa fa-pencil fa-lg"></i></a>';

		$security_lnk = '<a href="admin.php?page=managesites&scanid=' . $item['id'] . '" style="margin-right: .5em;" title="Show Security Scan Report"><i class="fa fa-shield fa-lg"></i></a>';

		if ( $item['sync_errors'] != '' ) {
			$mainwp_actions = $reconnect_lnk;
		} else {
			$mainwp_actions = $dashboard_lnk . $edit_lnk . $wp_admin_new_lnk . $sync_lnk . $security_lnk . $test_lnk . $backup_lnk . $post_lnk  . $notes_lnk;
		}

		echo $mainwp_actions;

	}

	function column_status( $item ) {

		$hasSyncErrors = ( $item['sync_errors'] != '' );
		$md5Connection = ( ! $hasSyncErrors && ( $item['nossl'] == 1 ) );

		$output = '';
		$cnt    = 0;
		if ( $item['offline_check_result'] == 1 && ! $hasSyncErrors && ! $md5Connection ) {
			$website               = (object) $item;
			$total_wp_upgrades     = 0;
			$total_plugin_upgrades = 0;
			$total_theme_upgrades  = 0;

			$wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );
			if ( $website->is_ignoreCoreUpdates ) {
				$wp_upgrades = array();
			}

			if ( is_array( $wp_upgrades ) && count( $wp_upgrades ) > 0 ) {
				$total_wp_upgrades ++;
			}

			$plugin_upgrades = json_decode( $website->plugin_upgrades, true );
			if ( $website->is_ignorePluginUpdates ) {
				$plugin_upgrades = array();
			}

			$theme_upgrades = json_decode( $website->theme_upgrades, true );
			if ( $website->is_ignoreThemeUpdates ) {
				$theme_upgrades = array();
			}

			$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
			if ( is_array( $decodedPremiumUpgrades ) ) {
				foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
					$premiumUpgrade['premium'] = true;

					if ( $premiumUpgrade['type'] == 'plugin' ) {
						if ( ! is_array( $plugin_upgrades ) ) {
							$plugin_upgrades = array();
						}
						if ( ! $website->is_ignorePluginUpdates ) {
							$plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
						}
					} else if ( $premiumUpgrade['type'] == 'theme' ) {
						if ( ! is_array( $theme_upgrades ) ) {
							$theme_upgrades = array();
						}
						if ( ! $website->is_ignoreThemeUpdates ) {
							$theme_upgrades[ $crrSlug ] = $premiumUpgrade;
						}
					}
				}
			}

			if ( is_array( $plugin_upgrades ) ) {
				$ignored_plugins = json_decode( $website->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$ignored_plugins = json_decode( $this->userExtension->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$total_plugin_upgrades += count( $plugin_upgrades );
			}

			if ( is_array( $theme_upgrades ) ) {
				$ignored_themes = json_decode( $website->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$total_theme_upgrades += count( $theme_upgrades );
			}

			$cnt = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

			//            $websiteCore = MainWP_DB::Instance()->getWebsiteOption((object)$item, 'wp_upgrades');
			//            if (is_array($websiteCore) && isset($websiteCore['current'])) $cnt++;
			//
			//            $websitePlugins = json_decode($item['plugin_upgrades'], true);
			//            if (is_array($websitePlugins)) $cnt += count($websitePlugins);
			//
			//            $websiteThemes = json_decode($item['theme_upgrades'], true);
			//            if (is_array($websiteThemes)) $cnt += count($websiteThemes);

			if ( $cnt > 0 ) {
				$output .= '<span class="fa-stack fa-lg" title="'. $cnt . ' ' . _n( 'Available Update', 'Available Updates', $cnt, 'mainwp' ) . '">
                <i class="fa fa-circle fa-stack-2x mainwp-dark-green"></i><strong class="mainwp-white fa-stack-1x">' . $cnt . '</strong></span>';
			}
		}

		$output .= '
       <span title="Site is Offline" ' . ($item['offline_check_result'] == -1 && !$hasSyncErrors && !$md5Connection ? '' : 'style="display:none;"') . '>
            <span class="fa-stack fa-lg">
                <i class="fa fa-exclamation-circle fa-2x mainwp-red"></i>
            </span>
       </span>

       <span title="Site is Online" ' . ($item['offline_check_result'] == 1 && !$hasSyncErrors && !$md5Connection && ($cnt == 0) ? '' : 'style="display:none;"'). '>
            <span class="fa-stack fa-lg">
                <i class="fa fa-check-circle fa-2x mainwp-green"></i>
            </span>
       </span>

       <span title="Site Disconnected" ' . ($hasSyncErrors ? '' : 'style="display:none;"') . '>
            <span class="fa-stack fa-lg">
                <i class="fa fa-circle fa-stack-2x mainwp-red"></i>
                <i class="fa fa-plug fa-stack-1x mainwp-white"></i>
            </span>
       </span>

       <span title="Unsecure connection" ' . ($md5Connection ? '' : 'style="display:none;"') . '>
            <span class="fa-stack fa-lg">
                <i class="fa fa-circle fa-stack-2x mainwp-red"></i>
          <i class="fa fa-chain-broken fa-stack-1x mainwp-white"></i>
            </span>
       </span>
       ';

		return $output;
	}

    function column_wpcore_update( $item ) {
        $hasSyncErrors = ( $item['sync_errors'] != '' );
        $output = '';
        $total_wp_upgrades = 0;
        if ( $item['offline_check_result'] == 1 && ! $hasSyncErrors ) {
		$website               = (object) $item;
		$wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );
		if ( $website->is_ignoreCoreUpdates ) {
			$wp_upgrades = array();
		}

		if ( is_array( $wp_upgrades ) && count( $wp_upgrades ) > 0 ) {
			$total_wp_upgrades ++;
		}
        }

        if ( $total_wp_upgrades == 0 ) {
                $mainwp_tu_color_code = 'mainwp-green';
        } else if ( $total_wp_upgrades > 0 && $total_wp_upgrades < 5 ) {
                $mainwp_tu_color_code = 'mainwp-yellow';
        } else {
                $mainwp_tu_color_code = 'mainwp-red';
        }


        $output .= '<span class="fa-stack fa-lg" title="'. $total_wp_upgrades . ' ' . _n( 'Available WP Core Update', 'Available WP Core Updates', $total_wp_upgrades, 'mainwp' ) . '">
        <i class="fa fa-circle fa-stack-2x ' . $mainwp_tu_color_code . '"></i><strong class="mainwp-white fa-stack-1x">' . $total_wp_upgrades . '</strong></span>';


        return $output;
    }

    function column_plugin_update( $item ) {
        $hasSyncErrors = ( $item['sync_errors'] != '' );
        $output = '';
        $total_plugin_upgrades = 0;
        if ( $item['offline_check_result'] == 1 && ! $hasSyncErrors ) {
		$website               = (object) $item;
		$plugin_upgrades = json_decode( $website->plugin_upgrades, true );
		if ( $website->is_ignorePluginUpdates ) {
			$plugin_upgrades = array();
		}

		$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
		if ( is_array( $decodedPremiumUpgrades ) ) {
			foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
				$premiumUpgrade['premium'] = true;

				if ( $premiumUpgrade['type'] == 'plugin' ) {
					if ( ! is_array( $plugin_upgrades ) ) {
						$plugin_upgrades = array();
					}
					if ( ! $website->is_ignorePluginUpdates ) {
						$plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
					}
				}
			}
		}

		if ( is_array( $plugin_upgrades ) ) {
			$ignored_plugins = json_decode( $website->ignored_plugins, true );
			if ( is_array( $ignored_plugins ) ) {
				$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
			}

			$ignored_plugins = json_decode( $this->userExtension->ignored_plugins, true );
			if ( is_array( $ignored_plugins ) ) {
				$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
			}

			$total_plugin_upgrades += count( $plugin_upgrades );
		}
        }

        if ( $total_plugin_upgrades == 0 ) {
                $mainwp_tu_color_code = 'mainwp-green';
        } else if ( $total_plugin_upgrades > 0 && $total_plugin_upgrades < 5 ) {
                $mainwp_tu_color_code = 'mainwp-yellow';
        } else {
                $mainwp_tu_color_code = 'mainwp-red';
        }


        $output .= '<span class="fa-stack fa-lg" title="'. $total_plugin_upgrades . ' ' . _n( 'Available Plugin Update', 'Available Plugin Updates', $total_plugin_upgrades, 'mainwp' ) . '">
        <i class="fa fa-circle fa-stack-2x ' . $mainwp_tu_color_code . '"></i><strong class="mainwp-white fa-stack-1x">' . $total_plugin_upgrades . '</strong></span>';


        return $output;
    }

    function column_theme_update( $item ) {
        $hasSyncErrors = ( $item['sync_errors'] != '' );
        $output = '';
        $total_theme_upgrades = 0;
        if ( $item['offline_check_result'] == 1 && ! $hasSyncErrors ) {
		$website               = (object) $item;
        $theme_upgrades = json_decode( $website->theme_upgrades, true );
		if ( $website->is_ignoreThemeUpdates ) {
			$theme_upgrades = array();
		}

		$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
		if ( is_array( $decodedPremiumUpgrades ) ) {
			foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
				$premiumUpgrade['premium'] = true;
                                    if ( $premiumUpgrade['type'] == 'theme' ) {
					if ( ! is_array( $theme_upgrades ) ) {
						$theme_upgrades = array();
					}
					if ( ! $website->is_ignoreThemeUpdates ) {
						$theme_upgrades[ $crrSlug ] = $premiumUpgrade;
					}
				}
			}
		}

		if ( is_array( $theme_upgrades ) ) {
			$ignored_themes = json_decode( $website->ignored_themes, true );
			if ( is_array( $ignored_themes ) ) {
				$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
			}

			$ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
			if ( is_array( $ignored_themes ) ) {
				$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
			}

			$total_theme_upgrades += count( $theme_upgrades );
		}
        }

        if ( $total_theme_upgrades == 0 ) {
                $mainwp_tu_color_code = 'mainwp-green';
        } else if ( $total_theme_upgrades > 0 && $total_theme_upgrades < 5 ) {
                $mainwp_tu_color_code = 'mainwp-yellow';
        } else {
                $mainwp_tu_color_code = 'mainwp-red';
        }


        $output .= '<span class="fa-stack fa-lg" title="'. $total_theme_upgrades . ' ' . _n( 'Available Theme Update', 'Available Theme Updates', $total_theme_upgrades, 'mainwp' ) . '">
        <i class="fa fa-circle fa-stack-2x ' . $mainwp_tu_color_code . '"></i><strong class="mainwp-white fa-stack-1x">' . $total_theme_upgrades . '</strong></span>';

        return $output;
    }

	function column_site( $item ) {
		$actions = array(
			'dashboard' => sprintf( '<a href="admin.php?page=managesites&dashboard=%s">' . __( 'Overview', 'mainwp' ) . '</a>', $item['id'] ),
			'edit'      => sprintf( '<a href="admin.php?page=managesites&id=%s">' . __( 'Edit', 'mainwp' ) . '</a>', $item['id'] ),
			'delete'    => sprintf( '<a class="submitdelete" href="#" onClick="return managesites_remove(' . "'" . '%s' . "'" . ');">' . __( 'Delete', 'mainwp' ) . '</a>', $item['id'] ),
		);

		if ( ! mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) {
			unset( $actions['dashboard'] );
		}

		if ( ! mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) {
			unset( $actions['edit'] );
		}

		if ( ! mainwp_current_user_can( 'dashboard', 'delete_sites' ) ) {
			unset( $actions['delete'] );
		}

		if ( $item['sync_errors'] != '' ) {
			$actions['reconnect'] = sprintf( '<a class="mainwp_site_reconnect" href="#" siteid="%s">' . __( 'Reconnect', 'mainwp' ) . '</a>', $item['id'] );
		}

		$imgfavi = '';
		if ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) {
			$siteObj  = (object) $item;
			$favi_url = MainWP_Utility::get_favico_url( $siteObj );
			$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
		}

		$loader = '<span class="bulk_running"><i class="fa fa-spinner fa-pulse" style="display:none"></i><span class="status hidden"></span></span>';

		return $imgfavi . sprintf( '<a href="admin.php?page=managesites&dashboard=%s" id="mainwp_notes_%s_url">%s</a>%s' . $loader, $item['id'], $item['id'], stripslashes( $item['name'] ), $this->row_actions( $actions ) );
	}

	function column_url( $item ) {
		$actions = array(
			'open' => sprintf( '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=%1$s" class="open_newwindow_wpadmin" target="_blank">' . __( 'Open WP Admin', 'mainwp' ) . '</a>', $item['id'] ),
			'updates' => sprintf( '<a href="admin.php?page=managesites&updateid=%1$s" class="open_updates">' . __( 'Updates', 'mainwp' ) . '</a>', $item['id'] ),
			'scan' => '<a href="admin.php?page=managesites&scanid=' . $item['id'] . '">' . __( 'Security scan', 'mainwp' ) . '</a>',
		);

		if ( ! mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) {
			unset( $actions['open'] );
		}

		if ( ! mainwp_current_user_can( 'dashboard', 'test_connection' ) ) {
			unset( $actions['test'] );
		}

		$actions = apply_filters( 'mainwp_managesites_column_url', $actions, $item['id'] );

		return sprintf( '<strong><a target="_blank" href="%1$s" class="site_url">%1$s</a></strong>%2$s', $item['url'], $this->row_actions( $actions ) );
	}

	function column_backup( $item ) {
        $siteObj = new stdClass();
        $siteObj->id = $item['id'];
        $lastBackup = MainWP_DB::Instance()->getWebsiteOption( $siteObj, 'primary_lasttime_backup' );

		$backupnow_lnk = apply_filters( 'mainwp-managesites-getbackuplink', '', $item['id'], $lastBackup );
		if ( ! empty( $backupnow_lnk ) ) {
			return $backupnow_lnk;
		}

		$dir        = MainWP_Utility::getMainWPSpecificDir( $item['id'] );
		$lastbackup = 0;
		if ( file_exists( $dir ) && ( $dh = opendir( $dir ) ) ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( $file != '.' && $file != '..' ) {
					$theFile = $dir . $file;
					if ( MainWP_Utility::isArchive( $file ) && ! MainWP_Utility::isSQLArchive( $file ) ) {
						if ( filemtime( $theFile ) > $lastbackup ) {
							$lastbackup = filemtime( $theFile );
						}
					}
				}
			}
			closedir( $dh );
		}

		$output = '';
		if ( $lastbackup > 0 ) {
			$output = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $lastbackup ) ) . '<br />';
		} else {
			$output = '<span class="mainwp-red">Never</span><br/>';
		}

		if ( mainwp_current_user_can( 'dashboard', 'execute_backups' ) && get_option('mainwp_enableLegacyBackupFeature')) {
			$output .= sprintf( '<a href="admin.php?page=managesites&backupid=%s">' . '<i class="fa fa-hdd-o"></i> ' . __( 'Backup now', 'mainwp' ) . '</a>', $item['id'] );
		}

		return $output;
	}

	function column_last_sync( $item ) {
		$output = '';
		if ( $item['dtsSync'] != 0 ) {
			$output = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $item['dtsSync'] ) ) . '<br />';
		}
		$output .= sprintf( '<a href="#" class="managesites_syncdata">' . '<i class="fa fa-refresh"></i> ' . __( 'Sync data', 'mainwp' ) . '</a>', $item['id'] );

		return $output;
	}

	function column_last_post( $item ) {
		$output = '';
		if ( $item['last_post_gmt'] != 0 ) {
			$output .= MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $item['last_post_gmt'] ) ) . '<br />';
		}
		$output .= sprintf( '<a href="admin.php?page=PostBulkAdd&select=%s">' . '<i class="fa fa-plus"></i> ' . __( 'Add new', 'mainwp' ) . '</a>', $item['id'] );

		return $output;
	}

	function column_seo( $item ) {
		return sprintf( '<a href="admin.php?page=managesites&seowebsiteid=%s">' . '<i class="fa fa-search"></i> ' . __( 'SEO', 'mainwp' ) . '</a>', $item['id'] );
	}

	function column_notes( $item ) {
		//$note = strip_tags( $item['note'], '<p><strong><em><br/><hr/><a></p></strong></em></a>' );
        //$note = wp_kses_post( $item['note'] );
        $note = html_entity_decode($item['note']); // to fix
        $lastupdate = $item['note_lastupdate'];

        $txt_lastupdate = '';
        if ( $lastupdate ) {
	        $txt_lastupdate = '<br/>' . __( 'Last update:', 'mainwp' ) . ' ' . MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $lastupdate ) );
        }

		if ( $item['note'] == '' ) {
			return sprintf( '<a href="#" class="mainwp_notes_show_all" id="mainwp_notes_%1$s">' . '<i class="fa fa-pencil-square-o"></i> ' . __( 'Notes', 'mainwp' ) . '</a>' . $txt_lastupdate . '<span style="display: none" id="mainwp_notes_%1$s_note">%3$s</span>', $item['id'], ( $item['note'] == '' ? 'display: none;' : '' ), $note );
		} else {
            $raw_note = esc_html($note);
			return sprintf( '<a href="#" class="mainwp_notes_show_all mainwp-green" id="mainwp_notes_%1$s">' . MainWP_Utility::renderNoteTooltip( $raw_note, '<i class="fa fa-pencil-square-o"></i> ' . __( 'Notes', 'mainwp' ) ) . '</a>' . $txt_lastupdate . '<span style="display: none" id="mainwp_notes_%1$s_note">%3$s</span>', $item['id'], ( $item['note'] == '' ? 'display: none;' : '' ), $note );
		}
	}

    function column_phpversion( $item ) {
		return $item['phpversion'];
	}

        // to fix data-placeholder
    protected function bulk_actions( $which = '' ) {
		if ( is_null( $this->_actions ) ) {
			$no_new_actions = $this->_actions = $this->get_bulk_actions();
			/**
			 * Filter the list table Bulk Actions drop-down.
			 *
			 * The dynamic portion of the hook name, `$this->screen->id`, refers
			 * to the ID of the current screen, usually a string.
			 *
			 * This filter can currently only be used to remove bulk actions.
			 *
			 * @since 3.5.0
			 *
			 * @param array $actions An array of the available bulk actions.
			 */
			$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions );
			$this->_actions = array_intersect_assoc( $this->_actions, $no_new_actions );
			$two = '';
		} else {
			$two = '2';
		}

		if ( empty( $this->_actions ) )
			return;

		echo '<label for="bulk-action-selector-' . esc_attr( $which ) . '" class="screen-reader-text">' . __( 'Select bulk action' ) . '</label>';
		echo '<select class="mainwp-select2" name="action' . $two . '" id="bulk-action-selector-' . esc_attr( $which ) . "\">\n";
		echo '<option value="-1">' . __( 'Bulk Actions' ) . "</option>\n";

		foreach ( $this->_actions as $name => $title ) {
			$class = 'edit' === $name ? ' class="hide-if-no-js"' : '';

			echo "\t" . '<option value="' . $name . '"' . $class . '>' . $title . "</option>\n";
		}

		echo "</select>\n";

		submit_button( __( 'Apply' ), 'action', '', false, array( 'id' => "doaction$two" ) );
		echo "\n";
	}

	function get_bulk_actions() {
		$actions = array(
			'sync'            => __( 'Sync', 'mainwp' ),
			'delete'          => __( 'Delete', 'mainwp' ),
			'test_connection' => __( 'Test connection', 'mainwp' ),
			'reconnect'       => __( 'Reconnect', 'mainwp' ),
			'open_wpadmin'    => __( 'Open WP Admin', 'mainwp' ),
			'open_frontpage'  => __( 'Open Front Page', 'mainwp' ),
			'update_plugins'  => __( 'Update plugins', 'mainwp' ),
			'update_themes'   => __( 'Update themes', 'mainwp' ),
			'update_wpcore'   => __('Update WordPress', 'mainwp'),
			'update_translations'   => __('Update translations', 'mainwp'),
		);

		return apply_filters( 'mainwp_managesites_bulk_actions', $actions );
	}

	function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox"  status="queue" value="%s" />', $item['id']
		);
	}

	function prepare_items() {
		if ( NULL === $this->userExtension ) {
			$this->userExtension = MainWP_DB::Instance()->getUserExtension();
		}

		$orderby = 'wp.url';

		if ( ! isset( $_GET['orderby'] ) ) {
			$_order_by = get_option( 'mainwp_managesites_orderby' );
			$_order    = get_option( 'mainwp_managesites_order' );
			if ( ! empty( $_order_by ) ) {
				$_GET['orderby'] = $_order_by;
				$_GET['order']   = $_order;
			}
		} else {
			MainWP_Utility::update_option( 'mainwp_managesites_orderby', $_GET['orderby'] );
			MainWP_Utility::update_option( 'mainwp_managesites_order', $_GET['order'] );
		}

		if ( isset( $_GET['orderby'] ) ) {
			if ( ( $_GET['orderby'] == 'site' ) ) {
				$orderby = 'wp.name ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );
			} else if ( ( $_GET['orderby'] == 'url' ) ) {
				$orderby = 'wp.url ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );
			} else if ( ( $_GET['orderby'] == 'groups' ) ) {
				$orderby = 'GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );
			} else if ( ( $_GET['orderby'] == 'wpcore_update' ) ) {
				$orderby = 'CASE true
                                WHEN (offline_check_result = -1)
                                    THEN 2
                                WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
                                    THEN 3
                                ELSE 4
                                    + (CASE wp_upgrades WHEN "[]" THEN 0 ELSE 1 END)
                            END ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );
			} else if ( ( $_GET['orderby'] == 'plugin_update' ) ) {
				$orderby = 'CASE true
                                WHEN (offline_check_result = -1)
                                    THEN 2
                                WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
                                    THEN 3
                                ELSE 4
                                    + (CASE plugin_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(plugin_upgrades) - LENGTH(REPLACE(plugin_upgrades, "\"Name\":", "\"Name\"")) END)
                            END ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );
			} else if ( ( $_GET['orderby'] == 'theme_update' ) ) {
				$orderby = 'CASE true
                                WHEN (offline_check_result = -1)
                                    THEN 2
                                WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
                                    THEN 3
                                ELSE 4
                                    + (CASE theme_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(theme_upgrades) - LENGTH(REPLACE(theme_upgrades, "\"Name\":", "\"Name\"")) END)
                            END ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );
			} else if ( ( $_GET['orderby'] == 'phpversion' ) ) {
				$orderby = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );



			} else if ( ( $_GET['orderby'] == 'status' ) ) {
				$orderby = 'CASE true                                
                                WHEN (offline_check_result = -1)
                                    THEN 2
                                WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
                                    THEN 3
                                ELSE 4
                                    + (CASE plugin_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(plugin_upgrades) - LENGTH(REPLACE(plugin_upgrades, "\"Name\":", "\"Name\"")) END)
                                    + (CASE theme_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(theme_upgrades) - LENGTH(REPLACE(theme_upgrades, "\"Name\":", "\"Name\"")) END)
                                    + (CASE wp_upgrades WHEN "[]" THEN 0 ELSE 1 END)
                            END ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );
			} else if ( ( isset( $_REQUEST['orderby'] ) && ( $_REQUEST['orderby'] == 'last_post' ) ) ) {
				$orderby = 'wp_sync.last_post_gmt ' . ( $_GET['order'] == 'asc' ? 'asc' : 'desc' );
			}
		}

		$perPage     = $this->get_items_per_page( 'mainwp_managesites_per_page' );
		$currentPage = $this->get_pagenum();

		$no_request = ( ! isset( $_REQUEST['s'] ) && ! isset( $_REQUEST['g'] ) && ! isset( $_REQUEST['status'] ) );

		if ( ! isset( $_REQUEST['status'] ) ) {
			if ( $no_request ) {
				$_status = get_option( 'mainwp_managesites_filter_status' );
				if ( ! empty( $_status ) ) {
					$_REQUEST['status'] = $_status;
				}
			} else {
				MainWP_Utility::update_option( 'mainwp_managesites_filter_status', '' );
			}
		} else {
			MainWP_Utility::update_option( 'mainwp_managesites_filter_status', $_REQUEST['status'] );
		}

		if ( ! isset( $_REQUEST['g'] ) ) {
			if ( $no_request ) {
				$_g = get_option( 'mainwp_managesites_filter_group' );
				if ( ! empty( $_g ) ) {
					$_REQUEST['g'] = $_g;
				}
			} else {
				MainWP_Utility::update_option( 'mainwp_managesites_filter_group', '' );
			}
		} else {
			MainWP_Utility::update_option( 'mainwp_managesites_filter_group', $_REQUEST['g'] );
		}

		$where = null;
		if ( isset( $_REQUEST['status'] ) && ( $_REQUEST['status'] != '' ) ) {
			if ( $_REQUEST['status'] == 'online' ) {
				$where = 'wp.offline_check_result = 1';
			} else if ( $_REQUEST['status'] == 'offline' ) {
				$where = 'wp.offline_check_result = -1';
			} else if ( $_REQUEST['status'] == 'disconnected' ) {
				$where = 'wp_sync.sync_errors != ""';
			} else if ( $_REQUEST['status'] == 'update' ) {
				$available_update_ids = $this->get_available_update_siteids();
				if ( empty( $available_update_ids ) ) {
					$where = 'wp.id = -1';
				} else {
					$where = 'wp.id IN (' . implode( ',', $available_update_ids ) . ') ';
				}
			}
		}

		if ( isset( $_REQUEST['g'] ) && ( $_REQUEST['g'] != '' ) ) {
			$websites     = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $_REQUEST['g'], true ) );
			$totalRecords = ( $websites ? MainWP_DB::num_rows( $websites ) : 0 );

			if ( $websites ) {
				@MainWP_DB::free_result( $websites );
			}
			if ( isset( $_GET['orderby'] ) && ( $_GET['orderby'] == 'groups' ) ) {
				$orderby = 'wp.url';
			}
			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $_REQUEST['g'], true, $orderby, ( ( $currentPage - 1 ) * $perPage ), $perPage, $where ) );
		} else if ( isset( $_REQUEST['status'] ) && ( $_REQUEST['status'] != '' ) ) {
			$websites     = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, null, $orderby, false, false, $where ) );
			$totalRecords = ( $websites ? MainWP_DB::num_rows( $websites ) : 0 );

			if ( $websites ) {
				@MainWP_DB::free_result( $websites );
			}
			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, null, $orderby, ( ( $currentPage - 1 ) * $perPage ), $perPage, $where ) );
		} else {
			$websites     = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, ( isset( $_REQUEST['s'] ) && ( $_REQUEST['s'] != '' ) ? $_REQUEST['s'] : null ), $orderby ) );
			$totalRecords = ( $websites ? MainWP_DB::num_rows( $websites ) : 0 );

			if ( $websites ) {
				@MainWP_DB::free_result( $websites );
			}
			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, ( isset( $_REQUEST['s'] ) && ( $_REQUEST['s'] != '' ) ? $_REQUEST['s'] : null ), $orderby, ( ( $currentPage - 1 ) * $perPage ), $perPage ) );
		}

		$this->set_pagination_args( array(
			'total_items' => $totalRecords, //WE have to calculate the total number of items
			'per_page'    => $perPage,//WE have to determine how many items to show on a page
		) );
		$this->items = $websites;
	}

	function get_available_update_siteids() {
		$site_ids      = array();
		$websites      = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );

		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			$hasSyncErrors = ( $website->sync_errors != '' );
			$cnt           = 0;
			if ( $website->offline_check_result == 1 && ! $hasSyncErrors ) {
				$total_wp_upgrades     = 0;
				$total_plugin_upgrades = 0;
				$total_theme_upgrades  = 0;

				$wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );
				if ( $website->is_ignoreCoreUpdates ) {
					$wp_upgrades = array();
				}

				if ( is_array( $wp_upgrades ) && count( $wp_upgrades ) > 0 ) {
					$total_wp_upgrades ++;
				}

				$plugin_upgrades = json_decode( $website->plugin_upgrades, true );
				if ( $website->is_ignorePluginUpdates ) {
					$plugin_upgrades = array();
				}

				$theme_upgrades = json_decode( $website->theme_upgrades, true );
				if ( $website->is_ignoreThemeUpdates ) {
					$theme_upgrades = array();
				}

				$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
				if ( is_array( $decodedPremiumUpgrades ) ) {
					foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
						$premiumUpgrade['premium'] = true;

						if ( $premiumUpgrade['type'] == 'plugin' ) {
							if ( ! is_array( $plugin_upgrades ) ) {
								$plugin_upgrades = array();
							}
							if ( ! $website->is_ignorePluginUpdates ) {
								$plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
							}
						} else if ( $premiumUpgrade['type'] == 'theme' ) {
							if ( ! is_array( $theme_upgrades ) ) {
								$theme_upgrades = array();
							}
							if ( ! $website->is_ignoreThemeUpdates ) {
								$theme_upgrades[ $crrSlug ] = $premiumUpgrade;
							}
						}
					}
				}

				if ( is_array( $plugin_upgrades ) ) {
					$ignored_plugins = json_decode( $website->ignored_plugins, true );
					if ( is_array( $ignored_plugins ) ) {
						$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
					}

					$ignored_plugins = json_decode( $this->userExtension->ignored_plugins, true );
					if ( is_array( $ignored_plugins ) ) {
						$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
					}

					$total_plugin_upgrades += count( $plugin_upgrades );
				}

				if ( is_array( $theme_upgrades ) ) {
					$ignored_themes = json_decode( $website->ignored_themes, true );
					if ( is_array( $ignored_themes ) ) {
						$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
					}

					$ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
					if ( is_array( $ignored_themes ) ) {
						$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
					}

					$total_theme_upgrades += count( $theme_upgrades );
				}

				$cnt = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

				if ( $cnt > 0 ) {
					$site_ids[] =  $website->id;
				}
			}
		}

		return $site_ids;
	}

	function clear_items() {
		if ( MainWP_DB::is_result( $this->items ) ) {
			@MainWP_DB::free_result( $this->items );
		}
	}

	function display_rows() {
		if ( MainWP_DB::is_result( $this->items ) ) {
			while ( $this->items && ( $item = @MainWP_DB::fetch_array( $this->items ) ) ) {
				$this->single_row( $item );
			}
		}
	}

	function single_row( $item ) {
		static $row_class = '';
		$row_class = ( $row_class == '' ? ' class="alternate"' : '' );

		echo '<tr' . $row_class . ' siteid="' . $item['id'] . '" site-url="' . $item['url'] . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}
        
        public function print_column_headers( $with_id = true ) {
		list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

		$current_url = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
		$current_url = remove_query_arg( 'paged', $current_url );

		if ( isset( $_GET['orderby'] ) ) {
			$current_orderby = $_GET['orderby'];
		} else {
			$current_orderby = '';
		}

		if ( isset( $_GET['order'] ) && 'desc' === $_GET['order'] ) {
			$current_order = 'desc';
		} else {
			$current_order = 'asc';
		}

		if ( ! empty( $columns['cb'] ) ) {
			static $cb_counter = 1;
			$columns['cb'] = '<label class="screen-reader-text" for="cb-select-all-' . $cb_counter . '">' . __( 'Select All' ) . '</label>'
				. '<input id="cb-select-all-' . $cb_counter . '" type="checkbox" />';
			$cb_counter++;
		}

		foreach ( $columns as $column_key => $column_display_name ) {
			$class = array( 'manage-column', "column-$column_key" );

			if ( in_array( $column_key, $hidden ) ) {
				$class[] = 'hidden';
			}

			if ( 'cb' === $column_key )
				$class[] = 'check-column';
			elseif ( in_array( $column_key, array( 'posts', 'comments', 'links' ) ) )
				$class[] = 'num';

			if ( $column_key === $primary ) {
				$class[] = 'column-primary';
			}

			if ( isset( $sortable[$column_key] ) ) {
				list( $orderby, $desc_first ) = $sortable[$column_key];

				if ( $current_orderby === $orderby ) {
					$order = 'asc' === $current_order ? 'desc' : 'asc';
					$class[] = 'sorted';
					$class[] = $current_order;
				} else {
					$order = $desc_first ? 'desc' : 'asc';
					$class[] = 'sortable';
					$class[] = $desc_first ? 'asc' : 'desc';
				}

				$column_display_name = '<a href="' . esc_url( add_query_arg( compact( 'orderby', 'order' ), $current_url ) ) . '"><span>' . $column_display_name . '</span><span class="sorting-indicator"></span></a>';
			}

			$tag = ( 'cb' === $column_key ) ? 'th' : 'th'; // to fix layout
			$scope = ( 'th' === $tag ) ? 'scope="col"' : '';
			$id = $with_id ? "id='$column_key'" : '';
                        
                        // support draggable column
                        if ($column_key !== 'cb') {
                            $class[] = 'drag-enable';
                        }
                        
			if ( !empty( $class ) )
				$class = "class='" . join( ' ', $class ) . "'";
                        
                        // to fix layout
                        $column_display_name ='<div class="header-wrap">' . $column_display_name . '</div>';
                        
                        // drag col handle
                        if ($column_key !== 'cb' ) {
                            $column_display_name = '<div class="table-handle"></div>' . $column_display_name;
                        }
                        
			echo "<$tag $scope $id $class>$column_display_name</$tag>";
		}
	}
        

	function extra_tablenav( $which ) {
		?>


		<div class="alignleft actions">
			<form method="GET" action="">
				<input type="hidden" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>" name="page"/>
				<select name="g" class="mainwp-select2 allowclear" data-placeholder="<?php _e( 'All Groups', 'mainwp' ); ?>">
					<option value=""></option>
					<?php
					$groups = MainWP_DB::Instance()->getGroupsForCurrentUser();
					foreach ( $groups as $group ) {
						echo '<option value="' . $group->id . '" ' . ( isset( $_REQUEST['g'] ) && $_REQUEST['g'] == $group->id ? 'selected' : '' ) . '>' . stripslashes( $group->name ) . '</option>';
					}
					?>
				</select>

				<input type="hidden" value="<?php echo $_REQUEST['page']; ?>" name="page"/>
				<select name="status"  class="mainwp-select2 allowclear" data-placeholder="<?php _e( 'All Statuses', 'mainwp' ); ?>">
					<option value=""></option>
					<option value="online" <?php echo( isset( $_REQUEST['status'] ) && $_REQUEST['status'] == 'online' ? 'selected' : '' ); ?>><?php _e( 'Online', 'mainwp' ); ?></option>
					<option value="offline" <?php echo( isset( $_REQUEST['status'] ) && $_REQUEST['status'] == 'offline' ? 'selected' : '' ); ?>><?php _e( 'Offline', 'mainwp' ); ?></option>
					<option value="disconnected" <?php echo( isset( $_REQUEST['status'] ) && $_REQUEST['status'] == 'disconnected' ? 'selected' : '' ); ?>><?php _e( 'Disconnected', 'mainwp' ); ?></option>
					<option value="update" <?php echo( isset( $_REQUEST['status'] ) && $_REQUEST['status'] == 'update' ? 'selected' : '' ); ?>><?php _e( 'Available update', 'mainwp' ); ?></option>
				</select>
				<input type="submit" value="<?php _e( 'Display', 'mainwp' ); ?>" class="button" name="">
			</form>
		</div>

		<div class="alignleft actions">
			<form method="GET" action="">
				<input type="hidden" value="<?php echo $_REQUEST['page']; ?>" name="page"/>
				<input type="text" value="<?php echo( isset( $_REQUEST['s'] ) ? esc_attr( $_REQUEST['s'] ) : '' ); ?>"
				       autocompletelist="sites" name="s" class="mainwp_autocomplete"/>
				<datalist id="sites">
					<?php
					if ( MainWP_DB::is_result( $this->items ) ) {
						while ( $this->items && ( $item = @MainWP_DB::fetch_array( $this->items ) ) ) {
							echo '<option>' . stripslashes( $item['name'] ) . '</option>';
						}

						MainWP_DB::data_seek( $this->items, 0 );
					}
					?>
				</datalist>
				<input type="submit" value="<?php _e( 'Search sites', 'mainwp' ); ?>" class="button" name=""/>
			</form>
		</div>
		<?php
	}
} //class
