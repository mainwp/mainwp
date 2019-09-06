<?php

class MainWP_Manage_Sites_List_Table {

	protected $userExtension = NULL;
    public $items;
    public $_total_items;
    protected $_pagination_args = array();

    protected $_column_headers;

	function __construct() {
        add_action( 'mainwp_managesites_tabletop' , array( &$this, 'generate_tabletop' ) );
	}

	protected function get_default_primary_column_name() {
		return 'site';
	}

    function column_backup( $item ) {

		$lastBackup	 = MainWP_DB::Instance()->getWebsiteOption( $item, 'primary_lasttime_backup' );

		$backupnow_lnk = apply_filters( 'mainwp-managesites-getbackuplink', '', $item[ 'id' ], $lastBackup );

		if ( !empty( $backupnow_lnk ) ) {
			return $backupnow_lnk;
		}

		$dir = MainWP_Utility::getMainWPSpecificDir( $item[ 'id' ] );
		$lastbackup = 0;
		if ( file_exists( $dir ) && ( $dh			 = opendir( $dir ) ) ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( $file != '.' && $file != '..' ) {
					$theFile = $dir . $file;
					if ( MainWP_Utility::isArchive( $file ) && !MainWP_Utility::isSQLArchive( $file ) ) {
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
			$output = '<div class="ui red label">Never</div><br/>';
		}

		return $output;
	}


	function column_default( $item, $column_name ) {

		$item = apply_filters( 'mainwp-sitestable-item', $item, $item );

		switch ( $column_name ) {
			case 'status':
			case 'site':
            case 'login':
			case 'url':
			case 'update':
            case 'wpcore_update':
			case 'plugin_update':
			case 'theme_update':
			case 'backup':
			case 'last_sync':
			case 'last_post':
			case 'notes':
			case 'phpversion':
            case 'site_actions':
				return ''; // core columns, displayed in single_row_columns()
			default:
				return isset($item[ $column_name ]) ? $item[ $column_name ] : '';
		}
	}

	function get_sortable_columns() {
		$sortable_columns = array(
			'site'			 		=> array( 'site', false ),
			'url'			 			=> array( 'url', false ),
			'groups'		 		=> array( 'groups', false ),
			'last_sync'		  => array( 'last_sync', false ),
			'last_post'		  => array( 'last_post', false ),
			'phpversion'	  => array( 'phpversion', false ),
			'update'		 		=> array( 'update', false ),
		);

		return $sortable_columns;
	}

    function get_default_columns() {
        return array(
			'cb'			 			 => '<input type="checkbox" />',
			'status'				 => '',
			'site'					 => __( 'Site', 'mainwp' ),
            'login'       	 => '<i class="sign in alternate icon"></i>',
			'url'			 			 => __( 'URL', 'mainwp' ),
			'update'		 		 => __( 'Updates', 'mainwp' ),
            'wpcore_update'	 => '<i class="wordpress icon"></i>',
			'plugin_update'	 => '<i class="plug icon"></i>',
			'theme_update'	 => '<i class="paint brush icon"></i>',
			'last_sync'		 	 => __( 'Last Sync', 'mainwp' ),
			'backup'				 => __( 'Last Backup', 'mainwp' ),
			'phpversion'	 	 => __( 'PHP', 'mainwp' ),
			'last_post'		 	 => __( 'Last Post', 'mainwp' ),
			'notes'			 		 => __( 'Notes', 'mainwp' ),
		);
    }

	function get_columns() {

		$columns = $this->get_default_columns();
		$columns = apply_filters( 'mainwp-sitestable-getcolumns', $columns, $columns );
        $columns['site_actions'] = '';

		$disable_backup			 	 = false;
		$primaryBackup			 	 = get_option( 'mainwp_primaryBackup' );
		$primaryBackupMethods	 = apply_filters( "mainwp-getprimarybackup-methods", array() );
		if ( empty( $primaryBackup ) ) {
			if ( !get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
				$disable_backup = true;
			}
		} else if ( !is_array( $primaryBackupMethods ) || empty( $primaryBackupMethods ) ) {
			$disable_backup = true;
		}

		if ( $disable_backup && isset( $columns[ 'backup' ] ) ) {
			unset( $columns[ 'backup' ] );
		}

		return $columns;
	}

    function get_columns_init() {
        $cols = $this->get_columns();
        $init_cols = array();
        foreach($cols as $key => $val) {
      $init_cols[] = array( 'data' => esc_html( $key ) );
        }
        return $init_cols;
    }

    function get_columns_defines() {
        $defines = array();
        $defines[] = array( 'targets' => 'no-sort', 'orderable' => false );
        $defines[] = array( 'targets' => 'manage-cb-column', 'className' => 'check-column collapsing' );
        $defines[] = array( 'targets' => 'manage-site-column', 'className' => 'column-site-bulk' );
        $defines[] = array( 'targets' => array( 'manage-login-column', 'manage-wpcore_update-column', 'manage-plugin_update-column', 'manage-theme_update-column', 'manage-last_sync-column', 'manage-last_post-column', 'manage-site_actions-column', 'extra-column' ), 'className' => 'collapsing' );
        $defines[] = array( 'targets' => array( 'manage-notes-column', 'manage-phpversion-column', 'manage-status-column'), 'className' => 'collapsing' );
        return $defines;
    }

	function generate_tabletop(){
        $this->renderManageSitesTableTop();
	}

	function get_bulk_actions() {

		$actions = array(
			'sync'					 			=> __( 'Sync Data', 'mainwp' ),
			'reconnect'				 		=> __( 'Reconnect', 'mainwp' ),
			'refresh_favico'    => __( 'Refresh Favicon', 'mainwp' ),
			'delete'				 			=> __( 'Remove', 'mainwp' ),
			'seperator_1' 				=> '',
			'open_wpadmin'			  => __( 'Jump to WP Admin', 'mainwp' ),
			'open_frontpage'		  => __( 'Jump to Front Page', 'mainwp' ),
			'seperator_2' 				=> '',
			'update_plugins'		  => __( 'Update Plugins', 'mainwp' ),
			'update_themes'			  => __( 'Update Themes', 'mainwp' ),
			'update_wpcore'			  => __( 'Update WordPress', 'mainwp' ),
			'update_translations'	=> __( 'Update Translations', 'mainwp' ),

		);

		return apply_filters( 'mainwp_managesites_bulk_actions', $actions );
	}

    public function renderManageSitesTableTop() {
        $items_bulk = $this->get_bulk_actions();

        $selected_status = isset( $_REQUEST[ 'status' ] ) ? $_REQUEST[ 'status' ] : '';
        $selected_group = isset( $_REQUEST[ 'g' ] ) ? $_REQUEST[ 'g' ] : '';

        if ( empty( $selected_status ) && empty( $selected_group ) ) {
            $selected_status = get_option( 'mainwp_managesites_filter_status' );
            $selected_group = get_option( 'mainwp_managesites_filter_group' );
        }

		?>
		<div class="ui grid">
			<div class="equal width row">
		    <div class="middle aligned column">
					<?php esc_html_e( 'Bulk actions: ', 'mainwp' ); ?>
					<div id="mainwp-sites-bulk-actions-menu" class="ui dropdown">
					  <div class="text"><?php esc_html_e( 'Select action', 'mainwp' ); ?></div>
					  <i class="dropdown icon"></i>
					  <div class="menu">
                          <?php
                          foreach($items_bulk as $value => $title  ) {
                              if ( substr($value, 0, 10) == 'seperator_' ) {
                                  ?>
                                    <div class="ui divider"></div>
                                <?php
                              } else {
                                  ?>
                                  <div class="item" data-value="<?php echo esc_attr($value); ?>"><?php esc_html_e( $title ); ?></div>
                                <?php
                              }
                          }
                          ?>
					  </div>
					</div>
					<button class="ui tiny basic button" id="mainwp-do-sites-bulk-actions"><?php _e('Apply', 'mainwp'); ?></button>
				</div>
                <div class="right aligned middle aligned column">
                        <?php esc_html_e( 'Filter sites: ', 'mainwp' ); ?>
                        <div class="ui dropdown" id="mainwp-filter-sites-group">
                          <div class="text"><?php esc_html_e( 'All groups', 'mainwp' ); ?></div>
                          <i class="dropdown icon"></i>
                          <div class="menu">
                                <div class="item" data-value="-1" ><?php esc_html_e( 'All groups', 'mainwp' ); ?></div>
                                <?php
                                $groups = MainWP_DB::Instance()->getGroupsForManageSites();
                                foreach ( $groups as $group ) { ?>
                                    <div class="item" data-value="<?php echo $group->id; ?>" ><?php echo stripslashes( $group->name ); ?></div>
                                    <?php
                                }
                                ?>
                          </div>
                        </div>
                        <div class="ui dropdown" id="mainwp-filter-sites-status">
                          <div class="text"><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                          <i class="dropdown icon"></i>
                          <div class="menu">
                                <div class="item" data-value="all" ><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                                <div class="item" data-value="connected"><?php _e( 'Connected', 'mainwp' ); ?></div>
                                <div class="item" data-value="disconnected"><?php _e( 'Disconnected', 'mainwp' ); ?></div>
                                <div class="item" data-value="update"><?php _e( 'Available update', 'mainwp' ); ?></div>
                          </div>
                        </div>
                        <button onclick="mainwp_manage_sites_filter()" class="ui tiny basic button"><?php _e('Filter Sites', 'mainwp'); ?></button>
				</div>
		  </div>
		</div>

        <script type="text/javascript">
                jQuery( document ).ready( function () {
                    <?php if ( $selected_group != '' ) { ?>
                    jQuery( '#mainwp-filter-sites-group' ).dropdown( "set selected", "<?php echo esc_js( $selected_group ); ?>" ); ; // default value
                    <?php } ?>
                    <?php if ( $selected_status != '' ) { ?>
                    jQuery( '#mainwp-filter-sites-status' ).dropdown( "set selected", "<?php echo esc_js( $selected_status ); ?>" ); ; // default value
                    <?php } ?>
                } );
          </script>
		<?php
	}

  function no_items() {
		?>
		<div class="ui center aligned segment">
		<?php if ( MainWP_DB::Instance()->getWebsitesCount( null, true ) == 0 ) : ?>
			<i class="globe massive icon"></i>
			<div class="ui header">
				<?php echo __( 'No websites connected to the MainWP Dashboard yet.', 'mainwp' ); ?>
			</div>
			<a href="<?php echo admin_url( 'admin.php?page=managesites&do=new' ); ?>" class="ui big green button"><?php echo __( 'Connect Your WordPress Sites', 'mainwp' ); ?></a>

			<div class="ui sub header">
				<?php printf( __( 'If all your child sites are missing from your MainWP Dashboard, please check this %shelp document%s.', 'mainwp' ), '<a href="https://mainwp.com/help/docs/all-child-sites-disappeared-from-my-mainwp-dashboard/" target="_blank">', '</a>' ); ?>
			</div>
		<?php else : ?>
		<?php echo __( 'No websites found.', 'mainwp' ); ?>
		<?php endif; ?>
		</div>
		<?php
	}

    public function has_items() {
		return !empty( $this->items );
	}

  function prepare_items( $optimize = true ) {

		if ( NULL === $this->userExtension ) {
			$this->userExtension = MainWP_DB::Instance()->getUserExtension();
		}

        $orderby = 'wp.url';

        if ( $optimize ) {

                if (isset($_REQUEST['order'])) {
                    $columns = $_REQUEST['columns'];
                    $ord_col =  $_REQUEST['order'][0]['column'];
                    if (isset($columns[$ord_col])) {
                        $_GET[ 'orderby' ] = $columns[$ord_col]['data'];
                        $_GET[ 'order' ] = $_REQUEST['order'][0]['dir'];
                    }
                }


//                if ( !isset( $_GET[ 'orderby' ] ) ) {
//                    $_order_by	 = get_option( 'mainwp_managesites_orderby' );
//                    $_order		 = get_option( 'mainwp_managesites_order' );
//                    if ( !empty( $_order_by ) ) {
//                        $_GET[ 'orderby' ]	 = $_order_by;
//                        $_GET[ 'order' ]	 = $_order;
//                    }
//                } else {
//                    MainWP_Utility::update_option( 'mainwp_managesites_orderby', $_GET[ 'orderby' ] );
//                    MainWP_Utility::update_option( 'mainwp_managesites_order', $_GET[ 'order' ] );
//                }


                if ( isset( $_GET[ 'orderby' ] ) ) {
                    if ( ( $_GET[ 'orderby' ] == 'site' ) ) {
                        $orderby = 'wp.name ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
                    } else if ( ( $_GET[ 'orderby' ] == 'url' ) ) {
                        $orderby = 'wp.url ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
                    } else if ( ( $_GET[ 'orderby' ] == 'groups' ) ) {
                        $orderby = 'GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
                    } else if ( ( $_GET[ 'orderby' ] == 'update' ) ) {
                        $orderby = 'CASE true
                                        WHEN (offline_check_result = -1)
                                            THEN 2
                                        WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
                                            THEN 3
                                        ELSE 4
                                            + (CASE wp_upgrades WHEN "[]" THEN 0 ELSE 1 END)
                                            + (CASE plugin_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(plugin_upgrades) - LENGTH(REPLACE(plugin_upgrades, "\"Name\":", "\"Name\"")) END)
                                            + (CASE theme_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(theme_upgrades) - LENGTH(REPLACE(theme_upgrades, "\"Name\":", "\"Name\"")) END)
                                    END ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
                    }
        //			else if ( ( $_GET[ 'orderby' ] == 'wpcore_update' ) ) {
        //				$orderby = 'CASE true
        //                                WHEN (offline_check_result = -1)
        //                                    THEN 2
        //                                WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
        //                                    THEN 3
        //                                ELSE 4
        //                                    + (CASE wp_upgrades WHEN "[]" THEN 0 ELSE 1 END)
        //                            END ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
        //			} else if ( ( $_GET[ 'orderby' ] == 'plugin_update' ) ) {
        //				$orderby = 'CASE true
        //                                WHEN (offline_check_result = -1)
        //                                    THEN 2
        //                                WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
        //                                    THEN 3
        //                                ELSE 4
        //                                    + (CASE plugin_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(plugin_upgrades) - LENGTH(REPLACE(plugin_upgrades, "\"Name\":", "\"Name\"")) END)
        //                            END ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
        //			} else if ( ( $_GET[ 'orderby' ] == 'theme_update' ) ) {
        //				$orderby = 'CASE true
        //                                WHEN (offline_check_result = -1)
        //                                    THEN 2
        //                                WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
        //                                    THEN 3
        //                                ELSE 4
        //                                    + (CASE theme_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(theme_upgrades) - LENGTH(REPLACE(theme_upgrades, "\"Name\":", "\"Name\"")) END)
        //                            END ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
        //			}
                    else if ( ( $_GET[ 'orderby' ] == 'phpversion' ) ) {
                        $orderby = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
                    } else if ( ( $_GET[ 'orderby' ] == 'status' ) ) {
                        $orderby = 'CASE true
                                        WHEN (offline_check_result = -1)
                                            THEN 2
                                        WHEN (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "")
                                            THEN 3
                                        ELSE 4
                                            + (CASE plugin_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(plugin_upgrades) - LENGTH(REPLACE(plugin_upgrades, "\"Name\":", "\"Name\"")) END)
                                            + (CASE theme_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(theme_upgrades) - LENGTH(REPLACE(theme_upgrades, "\"Name\":", "\"Name\"")) END)
                                            + (CASE wp_upgrades WHEN "[]" THEN 0 ELSE 1 END)
                                    END ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
                    } else if ( ( isset( $_GET[ 'orderby' ] ) && ( $_GET[ 'orderby' ] == 'last_post' ) ) ) {
                        $orderby = 'wp_sync.last_post_gmt ' . ( $_GET[ 'order' ] == 'asc' ? 'asc' : 'desc' );
                    }
                }
        }
		
        if ( ! $optimize ) {
           $perPage	 = 9999; // paging by datatable js
           $start = 0;
        } else {
           $perPage	 = $_REQUEST['length'];
		   if ($perPage == -1) // to fix
               $perPage = 9999;
           $start = isset($_REQUEST['start']) ? intval($_REQUEST['start']) : 0;
        }

        $search = isset( $_REQUEST[ 'search' ]['value'] ) ? trim($_REQUEST[ 'search' ]['value']) : '';

//        if ($search != '') {
//            $_REQUEST[ 's' ] = $search;
//        }

//        $no_request = (!isset( $_REQUEST[ 's' ] ) && !isset( $_REQUEST[ 'g' ] ) && !isset( $_REQUEST[ 'status' ] ) );
//		$all_request = ($search == '') && (( isset( $_REQUEST[ 'status' ] ) && $_REQUEST[ 'status' ] == 'all') || (isset( $_REQUEST[ 'g' ] ) && $_REQUEST[ 'g' ] == -1 )) ? true : false;

        $get_saved_state = empty( $search ) && !isset( $_REQUEST[ 'g' ] ) && !isset( $_REQUEST[ 'status' ] );
		$get_all = ($search == '') && ( isset( $_REQUEST[ 'status' ] ) && $_REQUEST[ 'status' ] == 'all') && (isset( $_REQUEST[ 'g' ] ) && $_REQUEST[ 'g' ] == -1 ) ? true : false;

        $group_id = false;
        $site_status = '';

        if ( !isset( $_REQUEST[ 'status' ] ) ) {
            if ( $get_saved_state ) {
//                $_status = get_option( 'mainwp_managesites_filter_status' );
//                if ( !empty( $_status ) ) {
//                    $_REQUEST[ 'status' ] = $_status; // this also remember filter status when not optimize
//                }
                $site_status = get_option( 'mainwp_managesites_filter_status' );
            } else {
                MainWP_Utility::update_option( 'mainwp_managesites_filter_status', '' ); // clear saved status
            }
        } else {
            MainWP_Utility::update_option( 'mainwp_managesites_filter_status', $_REQUEST[ 'status' ] ); // saving status
            $site_status = $_REQUEST[ 'status' ];
        }

        if ($get_all) {
            MainWP_Utility::update_option( 'mainwp_managesites_filter_group', '' );
        } else if ( !isset( $_REQUEST[ 'g' ] ) ) {
            if ( $get_saved_state ) {
//                $_g = get_option( 'mainwp_managesites_filter_group' );
//                if ( !empty( $_g ) ) {
//                    $_REQUEST[ 'g' ] = $_g; // this also remember filter groups when not optimize
//                }
                $group_id = get_option( 'mainwp_managesites_filter_group' );
            } else {
                MainWP_Utility::update_option( 'mainwp_managesites_filter_group', '' ); // clear saved status
            }
        } else {
                MainWP_Utility::update_option( 'mainwp_managesites_filter_group', $_REQUEST[ 'g' ] ); // saving status
                $group_id = $_REQUEST[ 'g' ];
        }

		$where = null;

//        if ( isset( $_REQUEST[ 'status' ] ) && ( $_REQUEST[ 'status' ] != '' ) && ( $_REQUEST[ 'status' ] != 'all' )) {
		if (  $site_status != '' &&  $site_status != 'all' ) {
            if ( $site_status == 'connected' ) {
                $where = 'wp_sync.sync_errors = ""';
			}
//            else if ( $site_status == 'connected' ) {
//				$where = 'wp.offline_check_result = 1';
//			}
//            else if ( $site_status == 'offline' ) {
//				$where = 'wp.offline_check_result = -1';
//			}
            else if ( $site_status == 'disconnected' ) {
				$where = 'wp_sync.sync_errors != ""';
			} else if ( $site_status == 'update' ) {
				$available_update_ids = $this->get_available_update_siteids();
				if ( empty( $available_update_ids ) ) {
					$where = 'wp.id = -1';
				} else {
					$where = 'wp.id IN (' . implode( ',', $available_update_ids ) . ') ';
				}
			}
		}

		if ( $get_all ) {

            $total_params =array(
            );

            $params =array(
                'selectgroups' => true,
                'orderby' => $orderby,
                'offset' => $start,
                'rowcount' => $perPage,
            );

//			$websites		 = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, null, $orderby ) );
//
//			$totalRecords	 = ( $websites ? MainWP_DB::num_rows( $websites ) : 0 );
//
//			if ( $websites ) {
//				@MainWP_DB::free_result( $websites );
//			}

			//$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, null, $orderby, $start, $perPage ) );
		}
//        else if ( isset( $_REQUEST[ 'g' ] ) && ( $_REQUEST[ 'g' ] != '' ) && ( $_REQUEST[ 'g' ] != -1 )) {
//            $websites		 = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $_REQUEST[ 'g' ], true ) );
//			$totalRecords	 = ( $websites ? MainWP_DB::num_rows( $websites ) : 0 );
//			if ( $websites ) {
//				@MainWP_DB::free_result( $websites );
//			}
            //$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $_REQUEST[ 'g' ], true, $orderby, $start, $perPage, $where, $search ) );

//		}
//        else if ( isset( $_REQUEST[ 'status' ] ) && ( $_REQUEST[ 'status' ] != '' ) && ( $_REQUEST[ 'status' ] != 'all' )) {
//			$websites		 = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, null, $orderby, false, false, $where ) );
//			$totalRecords	 = ( $websites ? MainWP_DB::num_rows( $websites ) : 0 );
//
//			if ( $websites ) {
//				@MainWP_DB::free_result( $websites );
//			}
//			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, null, $orderby, $start, $perPage, $where ) );
//		}
        else
        {


            $total_params = array(
                'search' => $search
            );

            $params =array(
                'selectgroups' => true,
                'orderby' => $orderby,
                'offset' => $start,
                'rowcount' => $perPage,
                'search' => $search
            );

            if ( $group_id > 0 ) {
                $total_params['group_id'] = $group_id;
                $params['group_id'] = $group_id;
            }

            if ( !empty( $where ) ) {
                $total_params['extra_where'] = $where;
                $params['extra_where'] = $where;
            }


//			$websites		 = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, ( isset( $_REQUEST[ 's' ] ) && ( $_REQUEST[ 's' ] != '' ) ? $_REQUEST[ 's' ] : null ), $orderby ) );
//			$totalRecords	 = ( $websites ? MainWP_DB::num_rows( $websites ) : 0 );
//
//			if ( $websites ) {
//				@MainWP_DB::free_result( $websites );
//			}
			//$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( true, ( isset( $_REQUEST[ 's' ] ) && ( $_REQUEST[ 's' ] != '' ) ? $_REQUEST[ 's' ] : null ), $orderby, $start, $perPage ) );

		}



        $total_websites		 = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLSearchWebsitesForCurrentUser( $total_params ) );
        $totalRecords	 = ( $total_websites ? MainWP_DB::num_rows( $total_websites ) : 0 );
        if ( $total_websites ) {
            @MainWP_DB::free_result( $total_websites );
        }

        $websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLSearchWebsitesForCurrentUser( $params ) );


        $site_ids = array();
        while ( $websites && ( $site = @MainWP_DB::fetch_object( $websites ) ) ) {
            $site_ids[] = $site->id;
        }
        do_action('mainwp-sitestable-prepared-items', $websites, $site_ids);

        @MainWP_DB::data_seek( $websites, 0 );

		$this->items = $websites;
        $this->_total_items = $totalRecords;
	}

	function get_available_update_siteids() {
		$site_ids	 = array();
		$websites	 = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );

		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			$hasSyncErrors	 = ( $website->sync_errors != '' );
			$cnt			 = 0;
			if ( $website->offline_check_result == 1 && !$hasSyncErrors ) {
				$total_wp_upgrades		 = 0;
				$total_plugin_upgrades	 = 0;
				$total_theme_upgrades	 = 0;

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
						$premiumUpgrade[ 'premium' ] = true;

						if ( $premiumUpgrade[ 'type' ] == 'plugin' ) {
							if ( !is_array( $plugin_upgrades ) ) {
								$plugin_upgrades = array();
							}
							if ( !$website->is_ignorePluginUpdates ) {
								$plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
							}
						} else if ( $premiumUpgrade[ 'type' ] == 'theme' ) {
							if ( !is_array( $theme_upgrades ) ) {
								$theme_upgrades = array();
							}
							if ( !$website->is_ignoreThemeUpdates ) {
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
					$site_ids[] = $website->id;
				}
			}
		}

		return $site_ids;
	}


	/**
	 * Display the table
	 *
	 */
public function display( $optimize = true ) {
	
	$sites_per_page = get_option( 'mainwp_default_sites_per_page', 25 );

    $sites_per_page = intval($sites_per_page);

    $pages_length = array(
        25 => '25',
        10 => '10',
        50 => '50',
        100 => '100',
        300 => '300',
    );

    $pages_length = $pages_length + array( $sites_per_page => $sites_per_page); // array indexed by number
    ksort($pages_length);

    // that for All value
    if (isset($pages_length[-1]))
        unset($pages_length[-1]);

    $pagelength_val = implode(",", array_keys($pages_length));
    $pagelength_title = implode(",", array_values($pages_length));
	
	?>
	<table id="mainwp-manage-sites-table" style="width:100%" class="ui single line selectable stackable table">
		<thead>
		<tr>
			<?php $this->print_column_headers( $optimize, true ); ?>
		</tr>
		</thead>
		<?php if ( ! $optimize ) { ?>
		<tbody id="mainwp-manage-sites-body-table">
			<?php $this->display_rows_or_placeholder(); ?>
		</tbody>
		<?php } ?>
		<tfoot>
			<tr>
				<?php $this->print_column_headers( $optimize, false ); ?>
			</tr>
	  </tfoot>
	</table>
	<div id="mainwp-loading-sites" style="display: none;">
    <div class="ui active inverted dimmer">
      <div class="ui indeterminate large text loader"><?php _e( 'Loading ...', 'mainwp' ); ?></div>
    </div>
	</div>

    <script type="text/javascript">
			
        mainwp_manage_sites_screen_options = function () {
			
			jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( { onHide: function (){
				var val = jQuery('#mainwp_default_sites_per_page').val();
				var saved = jQuery('#mainwp_default_sites_per_page').attr('saved-value');
				if (saved != val) {
					jQuery('#mainwp-manage-sites-table').DataTable().page.len(val);
					jQuery('#mainwp-manage-sites-table').DataTable().state.save();
					var data = mainwp_secure_data({
						action: 'mainwp_save_settings',
						name: 'default_sites_per_page',
						value: val
					});
					jQuery.post( ajaxurl, data, function ( res ) {
						window.location = 'admin.php?page=managesites';
					});
				}
            }}).modal( 'show' );
		
            $manage_sites_table.columns().iterator('column', function (settings, idx) {
                var col = settings.aoColumns[idx];
                var col_id = jQuery(settings.aoColumns[idx].nTh).attr('id');
                jQuery('#mainwp-manage-sites-screen-options-modal #mainwp_hide_column_' + col_id).attr('checked', (col.bVisible ? false : true));
            });
            return false;
        };

        jQuery( document ).ready( function ($) {
        <?php if ( !$optimize ) { ?>
            $manage_sites_table = jQuery('#mainwp-manage-sites-table').DataTable( {
                "colReorder" : {
                    fixedColumnsLeft: 1,
                    fixedColumnsRight: 1
                },
				"lengthMenu" : [ [<?php echo $pagelength_val; ?>, -1 ], [<?php echo $pagelength_title; ?>, "All" ] ],
                "stateSave":  true,
				"scrollX": true,
                "pagingType": "full_numbers",
                "order": [],
                "columnDefs": [ { "targets": 'no-sort', "orderable": false } ],
                "pageLength": <?php echo intval($sites_per_page); ?>
            } );

        <?php } else { ?>
                $manage_sites_table = jQuery('#mainwp-manage-sites-table').on( 'processing.dt', function ( e, settings, processing ) {
                    jQuery('#mainwp-loading-sites').css( 'display', processing ? 'block' : 'none' );
                    if (!processing) {
                        var tb = jQuery('#mainwp-manage-sites-table');
                        tb.find('th[cell-cls]').each(function(){
                            var ceIdx = this.cellIndex;
                            var cls = jQuery(this).attr('cell-cls');
                            jQuery('#mainwp-manage-sites-table tr').each(function(){
                                jQuery(this).find('td:eq(' + ceIdx + ')').addClass(cls);
                            });
                        });
                        $('#mainwp-manage-sites-table .ui.dropdown').dropdown(); // to fix
                        $('#mainwp-manage-sites-table .ui.checkbox').checkbox(); // to fix
                    }

                } ).on( 'column-reorder.dt', function ( e, settings, details ) {
                    $('#mainwp-manage-sites-table .ui.dropdown').dropdown(); // to fix
                    $('#mainwp-manage-sites-table .ui.checkbox').checkbox(); // to fix
                }).DataTable( {
                    "ajax": {
                        "url": ajaxurl,
                        "type": "GET",
                        "data":  function ( d ) {
                            return $.extend( {}, d, mainwp_secure_data({
                                action: 'mainwp_manage_display_rows',
                                status: jQuery("#mainwp-filter-sites-status").dropdown("get value"),
                                g: jQuery("#mainwp-filter-sites-group").dropdown("get value")
                            }));
                        },
                        "dataSrc": function ( json ) {
                            for ( var i=0, ien=json.data.length ; i<ien ; i++ ) {
                              // prepare extra data for rows
                              json.data[i].syncError = json.rowsInfo[i].syncError ? json.rowsInfo[i].syncError : false;
                              json.data[i].rowClass = json.rowsInfo[i].rowClass;
                              json.data[i].siteID = json.rowsInfo[i].siteID;
                              json.data[i].siteUrl = json.rowsInfo[i].siteUrl;
                            }
                            return json.data;
                        }
                      },
                    "pagingType": "full_numbers",
                    "colReorder" : {
                        fixedColumnsLeft: 1,
                        fixedColumnsRight: 1
                    },
					"lengthMenu" : [ [<?php echo $pagelength_val; ?>, -1 ], [<?php echo $pagelength_title; ?>, "All"] ],
                    "stateSave":  true,
					"scrollX": true,
                    serverSide: true,
					"pageLength": <?php echo intval($sites_per_page); ?>,                    
                    "columnDefs": <?php echo json_encode($this->get_columns_defines()); ?>,
                    "columns": <?php echo json_encode($this->get_columns_init()) ; ?>,
                    "drawCallback": function( settings ) {
                        this.api().tables().body().to$().attr('id', 'mainwp-manage-sites-body-table');
                    },
                    rowCallback: function (row, data) {
                        jQuery(row).addClass(data.rowClass);
                        jQuery(row).attr('site-url', data.siteUrl);
                        jQuery(row).attr('siteid', data.siteID);
                        jQuery(row).attr('id', "child-site-" + data.siteID); // to fix
                        if (data.syncError){
                          jQuery(row).find('td.column-site-bulk').addClass('site-sync-error');
                        };
                  }
                } );
    <?php  } ?>
            _init_manage_sites_screen = function(){
                jQuery('#mainwp-manage-sites-screen-options-modal input[type=checkbox][id^="mainwp_hide_column_"]').change(function(){
                    var reg = new RegExp( 'mainwp_hide_column_(.*)' );
                    var matches = reg.exec( jQuery(this).attr('id'));
                    if ( matches ) {
                        var col_id = matches[1];
                        $manage_sites_table.column('#' + col_id).visible( !jQuery(this).is(':checked') );
                    }
                });
            }(); // run it!
    } );

            mainwp_manage_sites_filter = function() {
                <?php if ( !$optimize ) { ?>
                    var group = jQuery("#mainwp-filter-sites-group").dropdown("get value");
                    var status = jQuery("#mainwp-filter-sites-status").dropdown("get value");

                    var params = '';
                    if (group != '') {
                        params += '&g=' + group;
                    }
                    if (status != '')
                        params += '&status=' + status;

                    window.location = 'admin.php?page=managesites' + params;
                    return false;
                <?php } else { ?>
                    $manage_sites_table.ajax.reload();
                <?php } ?>
            };

   </script>
    <?php

	}

public function display_rows_or_placeholder() {
	if ( $this->has_items() ) {
		$this->display_rows();
	} else {
		echo '<tr class="no-items"><td class="colspanchange" colspan="' . $this->get_column_count() . '">';
		$this->no_items();
		echo '</td></tr>';
	}
}

    public function get_column_count() {
		list ( $columns ) = $this->get_column_info();
		return count( $columns );
	}

    public function print_column_headers( $optimize, $top = true) {
		list( $columns, $sortable, $primary ) = $this->get_column_info();

		$current_url = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
		$current_url = remove_query_arg( 'paged', $current_url );

		if ( ! empty( $columns['cb'] ) ) {
      $columns['cb'] = '<div class="ui checkbox"><input id="' . ( $top ? 'cb-select-all-top': 'cb-select-all-bottom') . '" type="checkbox" /></div>';
		}

        $def_columns = $this->get_default_columns();
        $def_columns['site_actions'] = ''; // this is default column too

		foreach ( $columns as $column_key => $column_display_name ) {

            $class = array( 'manage-' . $column_key . '-column' ); // don't change this class
            $attr = '';
            if ( !isset($def_columns[$column_key]) ) {
                $class[] = 'extra-column';
                if ( $optimize ) {
                    $attr = 'cell-cls="' . esc_html("collapsing $column_key column-$column_key") . '"';
                }
            }

			if ( 'cb' === $column_key ) {
				$class[] = 'check-column';
                $class[] = 'collapsing';
			}

      if ( !isset( $sortable[$column_key] ) ) {
                $class[] = 'no-sort';
            }


			//$tag = ( 'cb' === $column_key ) ? 'td' : 'th';
            $tag = 'th';
			$id = "id='$column_key'";

			if ( !empty( $class ) )
				$class = "class='" . join( ' ', $class ) . "'"; // classes compatible with get_columns_defines();

			echo "<$tag $id $class $attr>$column_display_name</$tag>";
		}
	}

    protected function get_column_info() {

		// $_column_headers is already set / cached
		if ( isset( $this->_column_headers ) && is_array( $this->_column_headers ) ) {
			// Back-compat for list tables that have been manually setting $_column_headers for horse reasons.
			// In 4.3, we added a fourth argument for primary column.
			$column_headers = array( array(), array(), array(), $this->get_default_primary_column_name() );
			foreach ( $this->_column_headers as $key => $value ) {
				$column_headers[ $key ] = $value;
			}

			return $column_headers;
		}

        $columns = $this->get_columns();

		$sortable_columns = $this->get_sortable_columns();

		$_sortable = $sortable_columns;

		$sortable = array();
		foreach ( $_sortable as $id => $data ) {
			if ( empty( $data ) )
				continue;

			$data = (array) $data;
			if ( !isset( $data[1] ) )
				$data[1] = false;

			$sortable[$id] = $data;
		}

		$primary = $this->get_default_primary_column_name();
		$this->_column_headers = array( $columns, $sortable, $primary );

		return $this->_column_headers;
	}


	function clear_items() {
		if ( MainWP_DB::is_result( $this->items ) ) {
			@MainWP_DB::free_result( $this->items );
		}
	}

    public function get_datatable_rows() {
        $all_rows = array();
        $info_rows = array();
		$use_favi = get_option( 'mainwp_use_favicon', 1) ;
        if ( $this->items ) {
            foreach ( $this->items as $website ) {
                    $rw_classes = '';
                    if ( isset( $website[ 'groups' ] ) && !empty( $website[ 'groups' ] ) ) {
                        $group_class = $website[ 'groups' ];
                        $group_class = explode( ',', $group_class );
                        if ( is_array( $group_class ) ) {
                            foreach ( $group_class as $_class ) {
                                $_class	 = trim( $_class );
                                $_class	 = MainWP_Utility::sanitize_file_name( $_class );
                                $rw_classes .= " " . strtolower( $_class );
                            }
                        } else {
                            $_class	 = MainWP_Utility::sanitize_file_name( $group_class );
                            $rw_classes .= " " . strtolower( $_class );
                        }
                    }

                    $hasSyncErrors = ( $website['sync_errors'] != '' );
                    $md5Connection = ( !$hasSyncErrors && ( $website['nossl'] == 1 ) );

                    $rw_classes = trim( $rw_classes );
                    $rw_classes = 'child-site mainwp-child-site-' . $website['id'] . ' ' . ( $hasSyncErrors ? 'error' : '') . ' ' . ( $md5Connection ? 'warning' : '') . ' ' . $rw_classes ;

                    $info_item = array(
                        'rowClass' => esc_html($rw_classes),
                        'siteID' => $website['id'],
                        'siteUrl' => $website['url'],
                        'syncError' => ($website['sync_errors'] != '' ? true : false )
                    );


                 // Updates
                    $total_updates 					 = 0;
                    $total_wp_upgrades		 	 = 0;
                    $total_plugin_upgrades	 = 0;
                    $total_theme_upgrades	 	 = 0;

                    $wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );

                    if ( $website['is_ignoreCoreUpdates'] ) {
                        $wp_upgrades = array();
                    }

                    if ( is_array( $wp_upgrades ) && count( $wp_upgrades ) > 0 ) {
                        $total_wp_upgrades ++;
                    }

                    $plugin_upgrades = json_decode( $website['plugin_upgrades'], true );

                    if ( $website['is_ignorePluginUpdates'] ) {
                        $plugin_upgrades = array();
                    }

                    $theme_upgrades = json_decode( $website['theme_upgrades'], true );

                    if ( $website['is_ignoreThemeUpdates'] ) {
                        $theme_upgrades = array();
                    }

                    $decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );

                    if ( is_array( $decodedPremiumUpgrades ) ) {
                        foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                            $premiumUpgrade[ 'premium' ] = true;
                            if ( $premiumUpgrade[ 'type' ] == 'plugin' ) {
                                if ( !is_array( $plugin_upgrades ) ) {
                                    $plugin_upgrades = array();
                                }
                                if ( !$website['is_ignorePluginUpdates'] ) {
                                    $plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
                                }
                        } else if ( $premiumUpgrade[ 'type' ] == 'theme' ) {
                            if ( !is_array( $theme_upgrades ) ) {
                                $theme_upgrades = array();
                            }
                            if ( !$website['is_ignoreThemeUpdates'] ) {
                                $theme_upgrades[ $crrSlug ] = $premiumUpgrade;
                            }
                            }
                        }
                    }

                    if ( is_array( $plugin_upgrades ) ) {
                        $ignored_plugins = json_decode( $website['ignored_plugins'], true );
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
                        $ignored_themes = json_decode( $website['ignored_themes'], true );
                        if ( is_array( $ignored_themes ) ) {
                            $theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
                        }
                        $ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
                        if ( is_array( $ignored_themes ) ) {
                            $theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
                        }
                        $total_theme_upgrades += count( $theme_upgrades );
                    }

                    $total_updates = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

                    // Color codding

                    if ( $total_updates > 5 ) {
                        $a_color = 'red';
                    } else if ( $total_updates > 0 &&  $total_updates <= 5 ) {
                        $a_color = 'yellow';
                    } else {
                        $a_color = 'green';
                    }

                    if ( $total_wp_upgrades > 5 ) {
                        $w_color = 'red';
                    } else if ( $total_wp_upgrades > 0 &&  $total_wp_upgrades <= 5 ) {
                        $w_color = 'yellow';
                    } else {
                        $w_color = 'green';
                    }

                    if ( $total_plugin_upgrades > 5 ) {
                        $p_color = 'red';
                    } else if ( $total_plugin_upgrades > 0 &&  $total_plugin_upgrades <= 5 ) {
                        $p_color = 'yellow';
                    } else {
                        $p_color = 'green';
                    }

                    if ( $total_theme_upgrades > 5 ) {
                        $t_color = 'red';
                    } else if ( $total_theme_upgrades > 0 &&  $total_theme_upgrades <= 5 ) {
                        $t_color = 'yellow';
                    } else {
                        $t_color = 'green';
                    }

                    $note = html_entity_decode( $website['note'] ); // to compatible
                    $esc_note = MainWP_Utility::esc_content( $note );
                    $strip_note = strip_tags( $esc_note );

                    $columns = $this->get_columns();

                    $cols_data = array();

                    foreach ( $columns as $column_name => $column_display_name ) {
                        $default_classes = esc_html("collapsing $column_name column-$column_name");
                        ob_start();
                        ?>
                            <?php if ( 'cb' === $column_name ) { ?>
                            <!-- Checkbox column -->
                            <div class="ui checkbox"><input type="checkbox" value="<?php echo $website['id']; ?>" /></div>
                            <?php } else if ('status' === $column_name ) { ?>
                            <!-- Status column -->
                            <?php if ( $hasSyncErrors ) : ?>
                              <span data-tooltip="<?php esc_attr_e( 'The site appears to be disconnected. Click here to reconnect.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><a class="mainwp_site_reconnect" href="#"><i class="circular inverted red unlink icon"></i></a></span>
                            <?php elseif ( $md5Connection ) : ?>
                              <span data-tooltip="<?php esc_attr_e( 'The site appears to be connected over the insecure MD5 connection.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><i class="circular inverted orange shield icon"></i></span>
                            <?php else : ?>
                              <span data-tooltip="<?php esc_attr_e( 'The site appears to be connected properly. Click here to sync the site.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><a class="managesites_syncdata" href="#"><i class="circular inverted green check icon"></i></a></span>
                            <?php endif; ?>
                            <?php } else if ('site' === $column_name ) { ?>
                                <!-- Site Name column -->
                                <a href="<?php echo 'admin.php?page=managesites&dashboard=' . $website['id']; ?>" data-tooltip="<?php esc_attr_e( 'Open the site overview', 'mainwp' ); ?>" data-position="right center"  data-inverted=""><?php echo stripslashes( $website[ 'name' ] ); ?></a><i class="ui active inline loader tiny" style="display:none"></i><span id="site-status-<?php esc_attr_e($website['id']); ?>" class="status hidden"></span>
                            <?php } else if ('login' === $column_name ) { ?>
                                <!-- Login to WP Admin column -->
                                <?php if ( !mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
                                    <i class="sign in icon"></i>
                                <?php else : ?>
                                    <a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website['id']; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="right center"  data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
                                <?php endif; ?>
                            <?php } else if ('url' === $column_name ) { 
								$imgfavi = '';
								if ( $use_favi ) {
									$siteObj  = (object) $website;
									$favi_url = MainWP_Utility::get_favico_url( $siteObj );
									$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
								}
								echo $imgfavi;
								?>
                                <!-- URL column -->
                                <a href="<?php echo esc_url($website['url']); ?>" class="mainwp-may-hide-referrer open_site_url" target="_blank"><?php echo esc_html($website['url']); ?></a>
                            <?php } else if ('update' === $column_name ) { ?>
                                <!-- Total updates column -->
                                <span><a class="ui mini compact button <?php echo $a_color; ?>" href="admin.php?page=managesites&updateid=<?php echo intval($website['id']); ?>"><?php echo $total_updates; ?></a></span>
                            <?php } else if ('wpcore_update' === $column_name ) { ?>
                                <span><a class="ui mini compact basic button <?php echo $w_color; ?>" href="admin.php?page=managesites&updateid=<?php echo intval($website['id']); ?>&tab=wordpress-updates"><?php echo $total_wp_upgrades; ?></a></span>
                            <?php } else if ('plugin_update' === $column_name ) { ?>
                                <span><a class="ui mini compact basic button <?php echo $p_color; ?>" href="admin.php?page=managesites&updateid=<?php echo intval($website['id']); ?>"><?php echo $total_plugin_upgrades; ?></a></span>
                            <?php } else if ('theme_update' === $column_name ) { ?>
                                <span><a class="ui mini compact basic button <?php echo $t_color; ?>" href="admin.php?page=managesites&updateid=<?php echo intval($website['id']); ?>&tab=themes-updates"><?php echo $total_theme_upgrades; ?></a></span>
                            <?php } else if ('last_sync' === $column_name ) { ?>
                                <!-- Last Sycn column -->
                                <?php echo $website['dtsSync'] != 0 ? MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $website['dtsSync'] ) ) : '' ; ?>
                            <?php } else if ('last_post' === $column_name ) { ?>
                                <!-- Last Post column -->
                                <?php echo $website['last_post_gmt'] != 0 ? MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $website['last_post_gmt'] ) ) : '' ; ?>
                            <?php } else if ('notes' === $column_name ) {
                                    $col_class = 'collapsing center aligned';
                                ?>
                                <!-- Notes column -->
                                    <?php if ( $website['note'] == '' ) : ?>
                                        <a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo $website['id']; ?>"><i class="sticky note outline icon"></i></a>
                                    <?php else : ?>
                                        <a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo $website['id']; ?>" data-tooltip="<?php echo substr( $strip_note, 0, 100 ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
                                    <?php endif ;  ?>
                                        <span style="display: none" id="mainwp-notes-<?php echo $website['id']; ?>-note"><?php echo $esc_note; ?></span>
          <?php } else if ( 'phpversion' === $column_name ) { ?>
                                <!-- PHP Version column -->
          	<?php echo esc_html( substr( $website['phpversion'], 0, 6) ); ?>
          <?php } else if ( 'site_actions' === $column_name ) { ?>
                                 <!-- Actions column -->
                                    <div class="ui left pointing dropdown icon mini basic green button" style="z-index: 999;">
                                      <i class="ellipsis horizontal icon"></i>
                                      <div class="menu">
                                        <?php if ( $website['sync_errors'] != '' ) : ?>
                                        <a class="mainwp_site_reconnect item" href="#"><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
                                        <?php else : ?>
                                        <a class="managesites_syncdata item" href="#"><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
                                        <?php endif ; ?>
                                        <?php if ( mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) : ?>
                                        <a class="item" href="admin.php?page=managesites&dashboard=<?php echo $website['id']; ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
                                        <?php endif ; ?>
                                        <?php if ( mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) : ?>
                                        <a class="item" href="admin.php?page=managesites&id=<?php echo $website['id']; ?>"><?php esc_html_e( 'Edit Site', 'mainwp' ); ?></a>
                                        <?php endif ; ?>
																				<?php if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) : ?>
																				<a class="item" href="admin.php?page=managesites&scanid=<?php echo $website['id']; ?>"><?php esc_html_e( 'Security Scan', 'mainwp' ); ?></a>
																				<?php endif ; ?>
                                        <a class="item" onclick="return managesites_remove('<?php echo $website['id']; ?>')"><?php esc_html_e( 'Remove Site', 'mainwp' ); ?></a>
                                      </div>
                                    </div>
                                <?php
                            } elseif ( method_exists( $this, 'column_' . $column_name ) ) {
                                echo call_user_func( array( $this, 'column_' . $column_name ), $website );
                            } else {
                                echo $this->column_default( $website, $column_name );
                            }
                            $cols_data[$column_name] = ob_get_clean();
                        }
                        $all_rows[] = $cols_data;
                        $info_rows[] = $info_item;
            }
        }
        return array( 'data' => $all_rows, 'recordsTotal' => $this->_total_items, 'recordsFiltered' => $this->_total_items ,'rowsInfo' => $info_rows );
	}

	function display_rows() {
		if ( MainWP_DB::is_result( $this->items ) ) {
			while ( $this->items && ( $item = @MainWP_DB::fetch_array( $this->items ) ) ) {
				$this->single_row( $item );
			}
		}
	}

	function single_row( $website ) {

		$classes = '';
		if ( isset( $website[ 'groups' ] ) && !empty( $website[ 'groups' ] ) ) {
			$group_class = $website[ 'groups' ];
			$group_class = explode( ',', $group_class );
			if ( is_array( $group_class ) ) {
				foreach ( $group_class as $_class ) {
					$_class	 = trim( $_class );
					$_class	 = MainWP_Utility::sanitize_file_name( $_class );
					$classes .= " " . strtolower( $_class );
				}
			} else {
				$_class	 = MainWP_Utility::sanitize_file_name( $group_class );
				$classes .= " " . strtolower( $_class );
			}
		}


        $hasSyncErrors = ( $website['sync_errors'] != '' );
        $md5Connection = ( !$hasSyncErrors && ( $website['nossl'] == 1 ) );


		$classes = trim( $classes );
        $classes = ' class="child-site mainwp-child-site-' . $website['id'] . ' ' . ( $hasSyncErrors ? 'error' : '') . ' ' . ( $md5Connection ? 'warning' : '') . ' ' . $classes . '"';

		echo '<tr id="child-site-' . $website[ 'id' ] . '"' . $classes . ' siteid="' . $website[ 'id' ] . '" site-url="' . $website[ 'url' ] . '">';
        $this->single_row_columns( $website );
		echo '</tr>';
	}

    protected function single_row_columns( $website ) {

        // Updates
        $total_updates 					 = 0;
        $total_wp_upgrades		 	 = 0;
        $total_plugin_upgrades	 = 0;
        $total_theme_upgrades	 	 = 0;

        $wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );

        if ( $website['is_ignoreCoreUpdates'] ) {
            $wp_upgrades = array();
        }

        if ( is_array( $wp_upgrades ) && count( $wp_upgrades ) > 0 ) {
            $total_wp_upgrades ++;
        }

        $plugin_upgrades = json_decode( $website['plugin_upgrades'], true );

        if ( $website['is_ignorePluginUpdates'] ) {
            $plugin_upgrades = array();
        }

        $theme_upgrades = json_decode( $website['theme_upgrades'], true );

        if ( $website['is_ignoreThemeUpdates'] ) {
            $theme_upgrades = array();
        }

        $decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );

        if ( is_array( $decodedPremiumUpgrades ) ) {
            foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                $premiumUpgrade[ 'premium' ] = true;

                if ( $premiumUpgrade[ 'type' ] == 'plugin' ) {
                    if ( !is_array( $plugin_upgrades ) ) {
                        $plugin_upgrades = array();
                    }
                    if ( !$website['is_ignorePluginUpdates'] ) {
                        $plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
                    }
            } else if ( $premiumUpgrade[ 'type' ] == 'theme' ) {
                if ( !is_array( $theme_upgrades ) ) {
                    $theme_upgrades = array();
                }
                if ( !$website['is_ignoreThemeUpdates'] ) {
                    $theme_upgrades[ $crrSlug ] = $premiumUpgrade;
                }
                }
            }
        }

        if ( is_array( $plugin_upgrades ) ) {

            $ignored_plugins = json_decode( $website['ignored_plugins'], true );
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

            $ignored_themes = json_decode( $website['ignored_themes'], true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
            }

            $ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
            }

            $total_theme_upgrades += count( $theme_upgrades );
        }

        $total_updates = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

        // Color codding

        if ( $total_updates > 5 ) {
            $a_color = 'red';
        } else if ( $total_updates > 0 &&  $total_updates <= 5 ) {
            $a_color = 'yellow';
        } else {
            $a_color = 'green';
        }

        if ( $total_wp_upgrades > 5 ) {
            $w_color = 'red';
        } else if ( $total_wp_upgrades > 0 &&  $total_wp_upgrades <= 5 ) {
            $w_color = 'yellow';
        } else {
            $w_color = 'green';
        }

        if ( $total_plugin_upgrades > 5 ) {
            $p_color = 'red';
        } else if ( $total_plugin_upgrades > 0 &&  $total_plugin_upgrades <= 5 ) {
            $p_color = 'yellow';
        } else {
            $p_color = 'green';
        }

        if ( $total_theme_upgrades > 5 ) {
            $t_color = 'red';
        } else if ( $total_theme_upgrades > 0 &&  $total_theme_upgrades <= 5 ) {
            $t_color = 'yellow';
        } else {
            $t_color = 'green';
        }

        $hasSyncErrors = ( $website['sync_errors'] != '' );
        $md5Connection = ( !$hasSyncErrors && ( $website['nossl'] == 1 ) );

        $note = html_entity_decode( $website['note'] ); // to compatible
        $esc_note = MainWP_Utility::esc_content( $note );
        $strip_note = strip_tags( $esc_note );

		list( $columns ) = $this->get_column_info();
		
		$use_favi = get_option( 'mainwp_use_favicon', 1) ;
		
		foreach ( $columns as $column_name => $column_display_name ) {

            $classes = "collapsing center aligned $column_name column-$column_name";
            $attributes = "class='$classes'";

        ?>
            <?php if ( 'cb' === $column_name ) { ?>
                <!-- Checkbox column -->
                <td class="check-column">
                  <div class="ui checkbox">
										<input type="checkbox" value="<?php echo $website['id']; ?>" name=""/>
									</div>
                </td>
            <?php } else if ('status' === $column_name ) { ?>
                <!-- Status column -->
                <td class="center aligned collapsing">
                    <?php if ( $hasSyncErrors ) : ?>
                        <span data-tooltip="<?php esc_attr_e( 'Site appears to be disconnected. Click here to reconnect.', 'mainwp' ); ?>"  data-position="right center"  data-inverted=""><a class="mainwp_site_reconnect" href="#"><i class="circular inverted red unlink icon"></i></a></span>
                    <?php elseif ( $md5Connection ) : ?>
                        <span data-tooltip="<?php esc_attr_e( 'Site appears to be connected over the insecure MD5 connection.', 'mainwp' ); ?>"  data-position="right center" data-inverted=""><i class="circular inverted orange shield icon"></i></span>
                    <?php else : ?>
                        <span data-tooltip="<?php esc_attr_e( 'Site appears to be connected properly. Click here to sync the site.', 'mainwp' ); ?>"  data-position="right center" data-inverted=""><a class="managesites_syncdata" href="#"><i class="circular inverted green check icon"></i></a></span>
                    <?php endif; ?>
                </td>
            <?php } else if ('site' === $column_name ) {
                    $cls_site = '';
                    if ( $website['sync_errors'] != '' ) {
                        $cls_site = 'site-sync-error';
                    }
                ?>
                <!-- Site Name column -->
                <td class="column-site-bulk <?php echo $cls_site; ?>"><a href="<?php echo 'admin.php?page=managesites&dashboard=' . $website['id']; ?>" data-tooltip="<?php esc_attr_e( 'Open the site overview', 'mainwp' ); ?>"  data-position="right center" data-inverted=""><?php echo stripslashes( $website[ 'name' ] ); ?></a><i class="ui active inline loader tiny" style="display:none"></i><span id="site-status-<?php esc_attr_e($website['id']); ?>" class="status hidden"></span></td>
            <?php } else if ('login' === $column_name ) { ?>
                <!-- Login to WP Admin column -->
                <td class="collapsing">
                <?php if ( !mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) : ?>
                    <i class="sign in icon"></i>
                <?php else : ?>
                    <a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website['id']; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>" data-position="right center" data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>
                <?php endif; ?>
                </td>
            <?php } else if ('url' === $column_name ) { 
				
				$imgfavi = '';
				if ( $use_favi ) {
					$siteObj  = (object) $website;
					$favi_url = MainWP_Utility::get_favico_url( $siteObj );
					$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
				}

				?>
                <!-- URL column -->
                <td><?php echo $imgfavi; ?><a href="<?php echo esc_url($website['url']); ?>" class="mainwp-may-hide-referrer open_site_url" target="_blank"><?php echo esc_html($website['url']); ?></a></td>
            <?php } else if ('update' === $column_name ) { ?>
                <!-- Total updates column -->
                <td class="collapsing center aligned"><span><a class="ui mini compact button <?php echo $a_color; ?>" href="admin.php?page=managesites&updateid=<?php echo intval($website['id']); ?>"><?php echo $total_updates; ?></a></span></td>
            <?php } else if ('wpcore_update' === $column_name ) { ?>
                <td class="collapsing"><span><a class="ui basic mini compact button <?php echo $w_color; ?>" href="admin.php?page=managesites&updateid=<?php echo intval($website['id']); ?>&tab=wordpress-updates"><?php echo $total_wp_upgrades; ?></a></span></td>
            <?php } else if ('plugin_update' === $column_name ) { ?>
                <td class="collapsing"><span><a class="ui basic mini compact button <?php echo $p_color; ?>" href="admin.php?page=managesites&updateid=<?php echo intval($website['id']); ?>"><?php echo $total_plugin_upgrades; ?></a></span></td>
            <?php } else if ('theme_update' === $column_name ) { ?>
                <td class="collapsing"><span><a class="ui basic mini compact button <?php echo $t_color; ?>" href="admin.php?page=managesites&updateid=<?php echo intval($website['id']); ?>&tab=themes-updates"><?php echo $total_theme_upgrades; ?></a></span></td>
            <?php } else if ('last_sync' === $column_name ) { ?>
                <!-- Last Sycn column -->
                <td class="collapsing"><?php echo $website['dtsSync'] != 0 ? MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $website['dtsSync'] ) ) : '' ; ?></td>
            <?php } else if ('last_post' === $column_name ) { ?>
                <!-- Last Post column -->
                <td class="collapsing"><?php echo $website['last_post_gmt'] != 0 ? MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $website['last_post_gmt'] ) ) : '' ; ?></td>
            <?php } else if ('notes' === $column_name ) { ?>
                <!-- Notes column -->
                <td class="collapsing center aligned">
                    <?php if ( $website['note'] == '' ) : ?>
                        <a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo $website['id']; ?>"><i class="sticky note outline icon"></i></a>
                    <?php else : ?>
                        <a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-<?php echo $website['id']; ?>" data-tooltip="<?php echo substr( $strip_note, 0, 100 ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
                    <?php endif ;  ?>
                        <span style="display: none" id="mainwp-notes-<?php echo $website['id']; ?>-note"><?php echo $esc_note; ?></span>
                </td>
            <?php } else if ('phpversion' === $column_name ) { ?>
                <!-- PHP Version column -->
                <td class="collapsing center aligned"><?php echo esc_html( substr( $website['phpversion'], 0, 6 ) ); ?></td>
            <?php } else if ('site_actions' === $column_name ) {
                ?>
                 <!-- Actions column -->
                <td class="collapsing">
                    <div class="ui left pointing dropdown icon mini basic green button" style="z-index: 999;">
                      <i class="ellipsis horizontal icon"></i>
                      <div class="menu">
                        <?php if ( $website['sync_errors'] != '' ) : ?>
                        <a class="mainwp_site_reconnect item" href="#"><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
                        <?php else : ?>
                        <a class="managesites_syncdata item" href="#"><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
                        <?php endif ; ?>
                        <?php if ( mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) : ?>
                        <a class="item" href="admin.php?page=managesites&dashboard=<?php echo $website['id']; ?>"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a>
                        <?php endif ; ?>
                        <?php if ( mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) : ?>
                        <a class="item" href="admin.php?page=managesites&id=<?php echo $website['id']; ?>"><?php esc_html_e( 'Edit Site', 'mainwp' ); ?></a>
                        <?php endif ; ?>
												<?php if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) : ?>
												<a class="item" href="admin.php?page=managesites&scanid=<?php echo $website['id']; ?>"><?php esc_html_e( 'Security Scan', 'mainwp' ); ?></a>
												<?php endif ; ?>
                        <a class="item" onclick="return managesites_remove('<?php echo $website['id']; ?>')"><?php esc_html_e( 'Remove Site', 'mainwp' ); ?></a>
                      </div>
                    </div>
                </td>
                <?php
            } elseif ( method_exists( $this, 'column_' . $column_name ) ) {
                echo "<td $attributes>";
                echo call_user_func( array( $this, 'column_' . $column_name ), $website );
                echo "</td>";
            } else {
                echo "<td $attributes>";
                echo $this->column_default( $website, $column_name );
                echo "</td>";
            }
		}
	}


}

//class
