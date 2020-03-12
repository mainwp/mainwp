<?php
/**
 * MainWP Database Controller
 *
 * This file handles all interactions with the DB.
 */
class MainWP_DB {

	// Config
	private $mainwp_db_version = '8.15';
	// Private
	private $table_prefix;
	// Singleton
	/** @var $instance MainWP_DB */
	private static $instance = null;

	/** @var $wpdb wpdb */
	private $wpdb;

	/**
	 * @static
	 * @return MainWP_DB
	 */
	static function Instance() {
		if ( null == self::$instance ) {
			self::$instance = new MainWP_DB();
		}

		self::$instance->test_connection();

		return self::$instance;
	}

	// Constructor
	function __construct() {
		/** @var $this ->wpdb wpdb */
		global $wpdb;

		$this->wpdb         = &$wpdb;
		$this->table_prefix = $wpdb->prefix . 'mainwp_';
	}

	private function test_connection() {
		if ( ! self::ping( $this->wpdb->dbh ) ) {
			MainWP_Logger::Instance()->info( __( 'Trying to reconnect WordPress database connection...', 'mainwp' ) );
			$this->wpdb->db_connect();
		}
	}

	private function tableName( $suffix, $tablePrefix = null ) {
		return ( null == $tablePrefix ? $this->table_prefix : $tablePrefix ) . $suffix;
	}

	// Installs new DB
	function install() {
		// get_site_option is multisite aware!
		$currentVersion = get_site_option( 'mainwp_db_version' );

		if ( empty( $currentVersion ) ) {
			update_option( 'mainwp_run_quick_setup', 'yes' );
			MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 0 );
		} elseif ( false === get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 1 );
		}

		$rslt = self::Instance()->query( "SHOW TABLES LIKE '" . $this->tableName( 'wp' ) . "'" );
		if ( 0 === self::num_rows( $rslt ) ) {
			$currentVersion = false;
		}

		if ( $currentVersion == $this->mainwp_db_version ) {
			return;
		}

		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = array();
		$tbl = 'CREATE TABLE ' . $this->tableName( 'wp' ) . ' (
   id int(11) NOT NULL auto_increment,
   userid int(11) NOT NULL,
   adminname text NOT NULL,
  name text NOT NULL,
  url text NOT NULL,
  pubkey text NOT NULL,
  privkey text NOT NULL,
  nossl tinyint(1) NOT NULL,
  nosslkey text NOT NULL,
  siteurl text NOT NULL,
  ga_id text NOT NULL,
  gas_id int(11) NOT NULL,
  offline_checks text NOT NULL,
  offline_checks_last int(11) NOT NULL,
  offline_check_result int(11) NOT NULL,
  http_response_code int(11) NOT NULL DEFAULT 0,
  note text NOT NULL,
  note_lastupdate int(11) NOT NULL DEFAULT 0,
  statsUpdate int(11) NOT NULL,
  pagerank int(11) NOT NULL,
  indexed int(11) NOT NULL,
  alexia int(11) NOT NULL,
  pagerank_old int(11) DEFAULT NULL,
  indexed_old int(11) DEFAULT NULL,
  alexia_old int(11) DEFAULT NULL,
  directories longtext NOT NULL,
  plugin_upgrades longtext NOT NULL,
  theme_upgrades longtext NOT NULL,
  translation_upgrades longtext NOT NULL,
  premium_upgrades longtext NOT NULL,
  securityIssues longtext NOT NULL,
  themes longtext NOT NULL,
  ignored_themes longtext NOT NULL,
  plugins longtext NOT NULL,
  ignored_plugins longtext NOT NULL,
  pages longtext NOT NULL,
  users longtext NOT NULL,
  categories longtext NOT NULL,
  pluginDir text NOT NULL,
  automatic_update tinyint(1) NOT NULL,
  backup_before_upgrade tinyint(1) NOT NULL DEFAULT 2,
  last_db_backup_size int(11) NOT NULL,
  backups text NOT NULL,
  mainwpdir int(11) NOT NULL,
  loadFilesBeforeZip tinyint(1) NOT NULL DEFAULT 1,
  is_ignoreCoreUpdates tinyint(1) NOT NULL DEFAULT 0,
  is_ignorePluginUpdates tinyint(1) NOT NULL DEFAULT 0,
  is_ignoreThemeUpdates tinyint(1) NOT NULL DEFAULT 0,
  verify_certificate tinyint(1) NOT NULL DEFAULT 1,
  force_use_ipv4 tinyint(1) NOT NULL DEFAULT 0,
  ssl_version tinyint(1) NOT NULL DEFAULT 0,
  ip text NOT NULL DEFAULT "",
  uniqueId text NOT NULL,
  maximumFileDescriptorsOverride tinyint(1) NOT NULL DEFAULT 0,
  maximumFileDescriptorsAuto tinyint(1) NOT NULL DEFAULT 1,
  maximumFileDescriptors int(11) NOT NULL DEFAULT 150,
  http_user text NOT NULL DEFAULT "",
  http_pass text NOT NULL DEFAULT "",
  wpe tinyint(1) NOT NULL,
  is_staging tinyint(1) NOT NULL DEFAULT 0,
  KEY idx_userid (userid)';
		if ( '' === $currentVersion ) {
			$tbl .= ',
  PRIMARY KEY  (id)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl   = 'CREATE TABLE ' . $this->tableName( 'wp_sync' ) . ' (
   wpid int(11) NOT NULL,
  version text NOT NULL DEFAULT "",
  sync_errors longtext NOT NULL DEFAULT "",
  uptodate longtext NOT NULL DEFAULT "",
  dtsAutomaticSync int(11) NOT NULL DEFAULT 0,
  dtsAutomaticSyncStart int(11) NOT NULL DEFAULT 0,
  dtsSync int(11) NOT NULL DEFAULT 0,
  dtsSyncStart int(11) NOT NULL DEFAULT 0,
  totalsize int(11) NOT NULL DEFAULT 0,
  dbsize int(11) NOT NULL DEFAULT 0,
  extauth text NOT NULL DEFAULT "",
  last_post_gmt int(11) NOT NULL DEFAULT 0,
  KEY idx_wpid (wpid)) ' . $charset_collate;
		$sql[] = $tbl;

		$tbl   = 'CREATE TABLE ' . $this->tableName( 'wp_options' ) . ' (
  wpid int(11) NOT NULL,
  name text NOT NULL DEFAULT "",
  value longtext NOT NULL DEFAULT "",
  KEY idx_wpid (wpid)) ' . $charset_collate;
		$sql[] = $tbl;

		$tbl   = 'CREATE TABLE ' . $this->tableName( 'wp_settings_backup' ) . ' (
  wpid int(11) NOT NULL,
  archiveFormat text NOT NULL,
  KEY idx_wpid (wpid)) ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->tableName( 'users' ) . " (
  userid int(11) NOT NULL,
  user_email text NOT NULL DEFAULT '',  
  offlineChecksOnlineNotification tinyint(1) NOT NULL DEFAULT '0',
  heatMap tinyint(1) NOT NULL DEFAULT '0',
  ignored_plugins longtext NOT NULL DEFAULT '',
  trusted_plugins longtext NOT NULL DEFAULT '',
  trusted_plugins_notes longtext NOT NULL DEFAULT '',
  ignored_themes longtext NOT NULL DEFAULT '',
  trusted_themes longtext NOT NULL DEFAULT '',
  trusted_themes_notes longtext NOT NULL DEFAULT '',
  site_view tinyint(1) NOT NULL DEFAULT '0',
  pluginDir text NOT NULL DEFAULT '',
  dismissed_plugins longtext NOT NULL DEFAULT '',
  dismissed_themes longtext NOT NULL DEFAULT ''";
		if ( '' === $currentVersion ) {
			$tbl .= ',
  PRIMARY KEY  (userid)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->tableName( 'group' ) . ' (
  id int(11) NOT NULL auto_increment,
  userid int(11) NOT NULL,
  name text NOT NULL';
		if ( '' === $currentVersion ) {
			$tbl .= ',
  PRIMARY KEY  (id)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$sql[] = 'CREATE TABLE ' . $this->tableName( 'wp_group' ) . ' (
  wpid int(11) NOT NULL,
  groupid int(11) NOT NULL,
  KEY idx_wpid (wpid),
  KEY idx_groupid (groupid)
        ) ' . $charset_collate;

		$tbl   = 'CREATE TABLE ' . $this->tableName( 'wp_backup_progress' ) . ' (
  task_id int(11) NOT NULL,
  wp_id int(11) NOT NULL,
  dtsFetched int(11) NOT NULL DEFAULT 0,
  fetchResult text NOT NULL DEFAULT "",
  downloadedDB text NOT NULL DEFAULT "",
  downloadedFULL text NOT NULL DEFAULT "",
  downloadedDBComplete tinyint(1) NOT NULL DEFAULT 0,
  downloadedFULLComplete tinyint(1) NOT NULL DEFAULT 0,
  removedFiles tinyint(1) NOT NULL DEFAULT 0,
  attempts int(11) NOT NULL DEFAULT 0,
  last_error text NOT NULL DEFAULT "",
  pid int(11) NOT NULL DEFAULT 0,
  KEY idx_task_id (task_id)
         ) ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->tableName( 'wp_backup' ) . ' (
  id int(11) NOT NULL auto_increment,
  userid int(11) NOT NULL,
  name text NOT NULL,
  schedule text NOT NULL,
  type text NOT NULL,
  exclude text NOT NULL,
  sites text NOT NULL,
  `groups` text NOT NULL,
  last int(11) NOT NULL,
  last_run int(11) NOT NULL,
  lastStartNotificationSent int(11) NOT NULL DEFAULT 0,
  last_run_manually int(11) NOT NULL,
  completed_sites text NOT NULL,
  completed int(11) NOT NULL,
  backup_errors text NOT NULL,
  subfolder text NOT NULL,
  filename text NOT NULL,
  paused tinyint(1) NOT NULL,
  template tinyint(1) DEFAULT 0,
  excludebackup tinyint(1) DEFAULT 0,
  excludecache tinyint(1) DEFAULT 0,
  excludenonwp tinyint(1) DEFAULT 0,
  excludezip tinyint(1) DEFAULT 0,
  archiveFormat text NOT NULL,
  loadFilesBeforeZip tinyint(1) NOT NULL DEFAULT 1,
  maximumFileDescriptorsOverride tinyint(1) NOT NULL DEFAULT 0,
  maximumFileDescriptorsAuto tinyint(1) NOT NULL DEFAULT 1,
  maximumFileDescriptors int(11) NOT NULL DEFAULT 150';
		if ( '' === $currentVersion ) {
			$tbl .= ',
  PRIMARY KEY  (id)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->tableName( 'request_log' ) . ' (
  id int(11) NOT NULL auto_increment,
  wpid int(11) NOT NULL,
  ip text NOT NULL DEFAULT "",
  subnet text NOT NULL DEFAULT "",
  micro_timestamp_stop DECIMAL( 12, 2 ) NOT NULL DEFAULT  0,
  micro_timestamp_start DECIMAL( 12, 2 ) NOT NULL DEFAULT  0';
		if ( '' === $currentVersion || version_compare( $currentVersion, '5.7', '<=' ) ) {
			$tbl .= ',
  PRIMARY KEY  (id)  ';
		}
		$tbl  .= ') ' . $charset_collate . ';';
		$sql[] = $tbl;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		$this->post_update();

		if ( ! is_multisite() ) {
			MainWP_Utility::update_option( 'mainwp_db_version', $this->mainwp_db_version );
		} else {
			update_site_option( 'mainwp_db_version', $this->mainwp_db_version );
		}
	}

	// Check for update - if required, update..
	function update() {
	}

	function getOptionView( $extra = array() ) {
		$view = '(SELECT intwp.id AS wpid,
                         (SELECT recent_comments.value FROM ' . $this->tableName( 'wp_options' ) . ' recent_comments WHERE  recent_comments.wpid = intwp.id AND recent_comments.name = "recent_comments" LIMIT 1) AS recent_comments,
                         (SELECT recent_posts.value FROM ' . $this->tableName( 'wp_options' ) . ' recent_posts WHERE  recent_posts.wpid = intwp.id AND recent_posts.name = "recent_posts" LIMIT 1) AS recent_posts,
                         (SELECT recent_pages.value FROM ' . $this->tableName( 'wp_options' ) . ' recent_pages WHERE  recent_pages.wpid = intwp.id AND recent_pages.name = "recent_pages" LIMIT 1) AS recent_pages,
                         (SELECT phpversion.value FROM ' . $this->tableName( 'wp_options' ) . ' phpversion WHERE  phpversion.wpid = intwp.id AND phpversion.name = "phpversion" LIMIT 1) AS phpversion,
                         (SELECT wp_upgrades.value FROM ' . $this->tableName( 'wp_options' ) . ' wp_upgrades WHERE  wp_upgrades.wpid = intwp.id AND wp_upgrades.name = "wp_upgrades" LIMIT 1) AS wp_upgrades ';

		if ( is_array($extra) ) {
			foreach ( $extra as $field ) {
				if ( empty($field) ) {
					continue;
				}
				$view .= ', ';
				$view .= '(SELECT ' . $this->escape($field) . '.value FROM ' . $this->tableName( 'wp_options' ) . ' ' . $this->escape($field) . ' WHERE  ' . $this->escape($field) . '.wpid = intwp.id AND ' . $this->escape($field) . '.name = "' . $this->escape($field) . '" LIMIT 1) AS ' . $this->escape($field);
			}
		}

		$view .= ' FROM ' . $this->tableName( 'wp' ) . ' intwp)';

		return $view;
	}

	function post_update() {
		// get_site_option is multisite aware!
		$currentVersion = get_site_option( 'mainwp_db_version' );
		if ( false === $currentVersion ) {
			return;
		}

		if ( version_compare( $currentVersion, '8.1', '<' ) ) {
			// We can't split up here!
			$wpSyncColumns = array(
				'version',
				'totalsize',
				'dbsize',
				'extauth',
				'last_post_gmt',
				'uptodate',
				'sync_errors',
				'dtsSync',
				'dtsSyncStart',
				'dtsAutomaticSync',
				'dtsAutomaticSyncStart',
			);
			foreach ( $wpSyncColumns as $wpSyncColumn ) {
				$rslts = $this->wpdb->get_results( 'SELECT id,' . $wpSyncColumn . ' FROM ' . $this->tableName( 'wp' ), ARRAY_A );
				if ( empty( $rslts ) ) {
					continue;
				}

				foreach ( $rslts as $rslt ) {
					$exists = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT wpid FROM ' . $this->tableName( 'wp_sync' ) . ' WHERE wpid = %d', $rslt['id'] ), ARRAY_A );
					if ( empty( $exists ) ) {
						$this->wpdb->insert( $this->tableName( 'wp_sync' ), array(
							'wpid'           => $rslt['id'],
							$wpSyncColumn    => $rslt[ $wpSyncColumn ],
						) );
					} else {
						$this->wpdb->update( $this->tableName( 'wp_sync' ), array( $wpSyncColumn => $rslt[ $wpSyncColumn ] ), array( 'wpid' => $rslt['id'] ) );
					}
				}

				$suppress = $this->wpdb->suppress_errors();
				$this->wpdb->query( 'ALTER TABLE ' . $this->tableName( 'wp' ) . ' DROP COLUMN ' . $wpSyncColumn );
				$this->wpdb->suppress_errors( $suppress );
			}

			$optionColumns = array(
				'last_wp_upgrades',
				'last_plugin_upgrades',
				'last_theme_upgrades',
				'wp_upgrades',
				'recent_comments',
				'recent_posts',
				'recent_pages',
			);
			foreach ( $optionColumns as $optionColumn ) {
				$rslts = $this->wpdb->get_results( 'SELECT id,' . $optionColumn . ' FROM ' . $this->tableName( 'wp' ), ARRAY_A );
				if ( empty( $rslts ) ) {
					continue;
				}

				foreach ( $rslts as $rslt ) {
					self::updateWebsiteOption( (object) $rslt, $optionColumn, $rslt[ $optionColumn ] );
				}

				$suppress = $this->wpdb->suppress_errors();
				$this->wpdb->query( 'ALTER TABLE ' . $this->tableName( 'wp' ) . ' DROP COLUMN ' . $optionColumn );
				$this->wpdb->suppress_errors( $suppress );
			}
		}
	}

	public function getFirstSyncedSite( $userId = null ) {
		if ( ( null == $userId ) && MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$userId = $current_user->ID;
		}
		$where  = ( null != $userId ) ? ' userid = ' . $userId : '';
		$where .= $this->getWhereAllowAccessSites( 'wp' );
		$qry    = 'SELECT wp_sync.dtsSync FROM ' . $this->tableName( 'wp' ) . ' wp JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE wp_sync.sync_errors = "" ' . $where . ' ORDER BY wp_sync.dtsSync ASC LIMIT 1';

		return $this->wpdb->get_var( $qry );
	}

	public function getLastSyncStatus( $userId = null ) {
		$sql      = self::Instance()->getSQLWebsitesForCurrentUser();
		$websites = self::Instance()->query( $sql );

		$return = array(
			'sync_status'    => false,
			'last_sync'      => 0,
		);

		if ( ! $websites ) {
			$return['sync_status'] = 'all_synced';
			return $return;
		}

		$total_sites  = 0;
		$synced_sites = 0;
		$last_sync    = 0;
		self::data_seek( $websites, 0 );
		while ( $websites && ( $website      = self::fetch_object( $websites ) ) ) {
			if ( empty( $website ) || '' !== $website->sync_errors ) {
				continue;
			}
			$total_sites++;
			if ( 60 * 60 * 24 > time() - $website->dtsSync ) {
				$synced_sites++;
			}
			if ( $last_sync < $website->dtsSync ) {
				$last_sync = $website->dtsSync;
			}
		}

		if ( $total_sites == $synced_sites ) {
			$return['sync_status'] = 'all_synced';
		} elseif ( 0 === $synced_sites ) {
			$return['sync_status'] = 'not_synced';
		}
		$return['last_sync'] = $last_sync;
		return $return;
	}

	public function getDisconnectedWebsites() {
		$sql      = self::Instance()->getSQLWebsitesForCurrentUser();
		$websites = self::Instance()->query( $sql );

		if ( ! $websites ) {
			return array();
		}

		$disc_sites = array();

		self::data_seek( $websites, 0 );
		while ( $websites && ( $website      = self::fetch_object( $websites ) ) ) {
			if ( empty( $website ) ) {
				continue;
			}
			if ( '' !== $website->sync_errors ) {
				$disc_sites[ $website->id ] = $website->url;
			}
		}

		return $disc_sites;
	}

	public function getRequestsSince( $pSeconds ) {
		$where = $this->getWhereAllowAccessSites( 'wp' );
		$qry   = $this->wpdb->prepare( 'SELECT count(*) FROM ' . $this->tableName( 'wp' ) . ' wp JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE wp_sync.dtsSyncStart > %d' . $where, time() - $pSeconds );

		return $this->wpdb->get_var( $qry );
	}

	// Database actions
	public function getWebsitesCount( $userId = null, $all_access = false ) {
		if ( ( null == $userId ) && MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$userId = $current_user->ID;
		}
		$where = ( null == $userId ? '' : ' wp.userid = ' . $userId );
		if ( ! $all_access ) {
			$where .= $this->getWhereAllowAccessSites( 'wp' );
		}
		$qry = 'SELECT COUNT(wp.id) FROM ' . $this->tableName( 'wp' ) . ' wp WHERE 1 ' . $where;

		return $this->wpdb->get_var( $qry );
	}

	public function getWebsiteOption( $website, $option ) {

		if ( is_array( $website ) ) {
			if ( isset( $website[ $option ] ) ) {
				return $website[ $option ];
			}
			$site_id = $website['id'];
		} elseif ( is_object( $website ) ) {
			if ( property_exists( $website, $option ) ) {
				return $website->{$option};
			}
			$site_id = $website->id;
		}

		return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT value FROM ' . $this->tableName( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $site_id ) );
	}

	public function updateWebsiteOption( $website, $option, $value ) {
		$rslt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name FROM ' . $this->tableName( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $website->id ) );
		if ( 0 < count( $rslt ) ) {
			$this->wpdb->delete( $this->tableName( 'wp_options' ), array(
				'wpid'   => $website->id,
				'name'   => $this->escape( $option ),
			) );
			$rslt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name FROM ' . $this->tableName( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $website->id ) );
		}

		if ( 0 === count( $rslt ) ) {
			$this->wpdb->insert( $this->tableName( 'wp_options' ), array(
				'wpid'   => $website->id,
				'name'   => $option,
				'value'  => $value,
			) );
		} else {
			$this->wpdb->update( $this->tableName( 'wp_options' ), array( 'value' => $value ), array(
				'wpid'   => $website->id,
				'name'   => $option,
			) );
		}
	}

	public function getWebsitesByUserId( $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url' ) {
		return $this->getResultsResult( $this->getSQLWebsitesByUserId( $userid, $selectgroups, $search_site, $orderBy ) );
	}

	public function getSQLWebsites() {
		$where = $this->getWhereAllowAccessSites( 'wp' );

		return 'SELECT wp.*,wp_sync.*,wp_optionview.*
                FROM ' . $this->tableName( 'wp' ) . ' wp
                JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->getOptionView() . ' wp_optionview ON wp.id = wp_optionview.wpid
                ' . $where;
	}

	public function getSQLWebsitesByUserId( $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url',
										 $offset = false, $rowcount = false ) {
		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			$where = '';
			if ( null !== $search_site ) {
				$search_site = trim( $search_site );
				$where       = ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
			}

			$where .= $this->getWhereAllowAccessSites( 'wp' );

			if ( $selectgroups ) {
				$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
                FROM ' . $this->tableName( 'wp' ) . ' wp
                LEFT JOIN ' . $this->tableName( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->tableName( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->getOptionView() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.userid = ' . $userid . "
                $where
                GROUP BY wp.id
                ORDER BY " . $orderBy;
			} else {
				$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*
                FROM ' . $this->tableName( 'wp' ) . ' wp
                JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->getOptionView() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.userid = ' . $userid . "
                $where
                ORDER BY " . $orderBy;
			}

			if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
				$qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
			}

			return $qry;
		}

		return null;
	}

	public function getSQLWebsitesForCurrentUser( $selectgroups = false, $search_site = null, $orderBy = 'wp.url',
											   $offset = false, $rowcount = false, $extraWhere = null, $for_manager = false,
											   $extra_view = array( 'favi_icon' ), $is_staging = 'no' ) {
		$where = '';
		if ( MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$where .= ' AND wp.userid = ' . $current_user->ID . ' ';
		}

		if ( null !== $search_site ) {
			$search_site = trim( $search_site );
			$where      .= ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
		}

		if ( null !== $extraWhere ) {
			$where .= ' AND ' . $extraWhere;
		}

		if ( ! $for_manager ) {
			$where .= $this->getWhereAllowAccessSites( 'wp', $is_staging );
		}

		if ( 'wp.url' === $orderBy ) {
			$orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www', '')";
		}

		// wpgroups to fix issue for mysql 8.0, as groups will generate error syntax
		if ( $selectgroups ) {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
            FROM ' . $this->tableName( 'wp' ) . ' wp
            LEFT JOIN ' . $this->tableName( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->tableName( 'group' ) . ' gr ON wpgr.groupid = gr.id
            JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->getOptionView( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . '
            GROUP BY wp.id
            ORDER BY ' . $orderBy;
		} else {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*
            FROM ' . $this->tableName( 'wp' ) . ' wp
            JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->getOptionView( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . '
            ORDER BY ' . $orderBy;
		}

		if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
			$qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
		}

		return $qry;
	}

	public function getSQLSearchWebsitesForCurrentUser( $params ) {

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$selectgroups = isset( $params['selectgroups'] ) && $params['selectgroups'] ? true : false;
		$search_site  = isset( $params['search'] ) ? $this->escape( trim( $params['search'] ) ) : null;
		$orderBy      = isset( $params['orderby'] ) ? $params['orderby'] : 'wp.url';
		$offset       = isset( $params['offset'] ) ? intval( $params['offset'] ) : false;
		$rowcount     = isset( $params['rowcount'] ) ? intval( $params['rowcount'] ) : false;
		$extraWhere   = isset( $params['extra_where'] ) ? $params['extra_where'] : null;
		$for_manager  = isset( $params['for_manager'] ) && $params['for_manager'] ? true : false;
		$extra_view   = isset( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
		$is_staging   = isset( $params['is_staging'] ) && 'yes' == $params['is_staging'] ? 'yes' : 'no';

		$group_id = isset( $params['group_id'] ) && $params['group_id'] ? intval( $params['group_id'] ) : false;

		if ( $selectgroups ) {
			if ( $staging_group = get_option( 'mainwp_stagingsites_group_id' ) ) {
				if ( $group_id == $staging_group ) {
					$is_staging = 'yes'; // will list staging sites
				}
			}
		}

		$where = '';
		if ( MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$where .= ' AND wp.userid = ' . $current_user->ID . ' ';
		}

		// for searching
		if ( null !== $search_site && '' !== $search_site ) {
			$where .= ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
		}

		if ( null !== $extraWhere ) {
			$where .= ' AND ' . $extraWhere;
		}

		if ( ! $for_manager ) {
			$where .= $this->getWhereAllowAccessSites( 'wp', $is_staging );
		}

		if ( 'wp.url' === $orderBy ) {
			$orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www', '')";
		}

		$join_group  = '';
		$where_group = '';

		if ( MainWP_Utility::ctype_digit( $group_id ) && $group_id > 0 ) {
			$join_group  = ' JOIN ' . $this->tableName( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
			$where_group = ' AND wpgroup.groupid = ' . $group_id;
		}

		// wpgroups to fix issue for mysql 8.0, as groups will generate error syntax
		if ( $selectgroups ) {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
            FROM ' . $this->tableName( 'wp' ) . ' wp ' .
			$join_group . '
            LEFT JOIN ' . $this->tableName( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->tableName( 'group' ) . ' gr ON wpgr.groupid = gr.id
            JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->getOptionView( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $where_group . '
            GROUP BY wp.id
            ORDER BY ' . $orderBy;
		} else {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*
            FROM ' . $this->tableName( 'wp' ) . ' wp ' .
			$join_group . '
            JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->getOptionView( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $where_group . '
            ORDER BY ' . $orderBy;
		}

		if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
			$qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
		}

		return $qry;
	}


	public function getWhereAllowAccessSites( $site_table_alias = '', $is_staging = 'no' ) {

		if ( empty( $site_table_alias ) ) {
			$site_table_alias = $this->tableName( 'wp' );
		}

		// check to filter the staging sites
		$where_staging = ' AND ' . $site_table_alias . '.is_staging = 0 ';
		if ( 'no' === $is_staging ) {
			$where_staging = ' AND ' . $site_table_alias . '.is_staging = 0 ';
		} elseif ( 'yes' === $is_staging ) {
			$where_staging = ' AND ' . $site_table_alias . '.is_staging = 1 ';
		} elseif ( 'nocheckstaging' === $is_staging ) {
			$where_staging = '';
		}
		// end staging filter

		$_where = $where_staging;
		// To fix bug run from cron job
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return $_where;
		}

		// To fix bug run from wp cli
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $_where;
		}

		$allowed_sites = apply_filters( 'mainwp_currentuserallowedaccesssites', 'all' );

		if ( 'all' === $allowed_sites ) {
			return $_where; // allow access all sites
		}

		if ( is_array( $allowed_sites ) && 0 < count( $allowed_sites ) ) {
			$_where .= ' AND ' . $site_table_alias . '.id IN (' . implode( ',', $allowed_sites ) . ') ';
		} else {
			$_where .= ' AND 0 '; // denied access
		}

		return $_where;
	}

	public function getWhereAllowGroups( $group_table_alias = '', $with_staging = 'no' ) {
		// To fix bug run from cron job
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return '';
		}

		if ( empty( $group_table_alias ) ) {
			$group_table_alias = $this->tableName( 'group' );
		}

		// check to filter the staging group
		$where_staging_group = '';
		$staging_group       = get_option( 'mainwp_stagingsites_group_id' );
		if ( $staging_group ) {
			$where_staging_group = ' AND ' . $group_table_alias . '.id <> ' . $staging_group . ' '; // filter the staging group
			if ( 'yes' === $with_staging ) {
				$where_staging_group = ''; // will do not the filter staging group
			}
		}
		// end staging filter

		$_where = $where_staging_group;

		$allowed_groups = apply_filters( 'mainwp_currentuserallowedaccessgroups', 'all' );

		if ( 'all' === $allowed_groups ) {
			return $_where;
		} // allow all groups

		if ( is_array( $allowed_groups ) && 0 < count( $allowed_groups ) ) {
			return ' AND ' . $group_table_alias . '.id IN (' . implode( ',', $allowed_groups ) . ') ' . $_where;
		} else {
			return ' AND 0 '; // not allow access groups
		}
	}

	public function getGroupByNameForUser( $name, $userid = null ) {
		if ( ( null == $userid ) && MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$userid = $current_user->ID;
		}
		$where  = ( null != $userid ) ? ' AND userid=' . $userid : '';
		$where .= $this->getWhereAllowGroups();

		return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'group' ) . ' WHERE 1 ' . $where . ' AND name= %s', $this->escape( $name ) ) );
	}

	public function getGroupById( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'group' ) . ' WHERE id= %d', $id ) );
		}

		return null;
	}

	public function getGroupsByUserId( $userid ) {
		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			$where = $this->getWhereAllowGroups();

			return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'group' ) . ' WHERE userid = %d ' . $where . ' ORDER BY name', $userid ), OBJECT_K );
		}

		return null;
	}

	public function getGroupsForManageSites() {
		$where = ' 1 ';
		if ( MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$where = ' userid = ' . $current_user->ID . ' ';
		}
		$with_staging    = 'yes';
		$staging_enabled = apply_filters( 'mainwp-extension-available-check', 'mainwp-staging-extension' ) || apply_filters( 'mainwp-extension-available-check', 'mainwp-timecapsule-extension' );

		if ( ! $staging_enabled ) {
			$with_staging = 'no';
		}

		$where .= $this->getWhereAllowGroups('', $with_staging );
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'group' ) . ' WHERE ' . $where . ' ORDER BY name', OBJECT_K );
	}

	public function getGroupsForCurrentUser() {
		$where = ' 1 ';
		if ( MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$where = ' userid = ' . $current_user->ID . ' ';
		}
		$where .= $this->getWhereAllowGroups();

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'group' ) . ' WHERE ' . $where . ' ORDER BY name', OBJECT_K );
	}

	public function getGroupsByWebsiteId( $websiteid ) {
		if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
			return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'group' ) . ' gr
                JOIN ' . $this->tableName( 'wp_group' ) . ' wpgr ON gr.id = wpgr.groupid
                WHERE wpgr.wpid = %d ORDER BY name', $websiteid ), OBJECT_K );
		}

		return null;
	}

	public function getGroupsAndCount( $userid = null, $for_manager = false ) {
		if ( ( null == $userid ) && MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$userid = $current_user->ID;
		}

		$where = '';

		if ( null != $userid ) {
			$where = ' AND gr.userid = ' . $userid;
		}

		if ( ! $for_manager ) {
			$where .= $this->getWhereAllowGroups( 'gr' );
		}

		return $this->wpdb->get_results( 'SELECT gr.*, COUNT(DISTINCT(wpgr.wpid)) as nrsites
                FROM ' . $this->tableName( 'group' ) . ' gr
                LEFT JOIN ' . $this->tableName( 'wp_group' ) . ' wpgr ON gr.id = wpgr.groupid
                WHERE 1 ' . $where . '
                GROUP BY gr.id
                ORDER BY gr.name', OBJECT_K );
	}

	public function getGroupsByName( $name ) {
		return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT gr.*
            FROM ' . $this->tableName( 'group' ) . ' gr
            WHERE gr.name = %s', $this->escape( $name ) ),
			 OBJECT_K );
	}

	public function getNotEmptyGroups( $userid = null, $enableOfflineSites = true ) {
		if ( ( null == $userid ) && MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$userid = $current_user->ID;
		}

		$where  = ' WHERE 1 ';
		$where .= $this->getWhereAllowGroups( 'g' );

		if ( null != $userid ) {
			$where .= ' AND g.userid = ' . $userid;
		}
		if ( ! $enableOfflineSites ) {
			$where .= ' AND wp_sync.sync_errors = ""';
		}

		return $this->wpdb->get_results( 'SELECT DISTINCT(g.id), g.name, count(wp.wpid)
              FROM ' . $this->tableName( 'group' ) . ' g
              JOIN ' . $this->tableName( 'wp_group' ) . ' wp ON g.id = wp.groupid
              JOIN ' . $this->tableName( 'wp' ) . ' wpsite ON wp.wpid = wpsite.id
              JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.wpid = wp_sync.wpid
              ' . $where . '
              GROUP BY g.id
              HAVING count(wp.wpid) > 0
              ORDER BY g.name', OBJECT_K );
	}

	public function getWebsitesByUrl( $url ) {
		if ( '/' != substr( $url, - 1 ) ) {
			$url .= '/';
		}
		$where   = '';
		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'wp' ) . ' WHERE url = %s ' . $where, $this->escape( $url ) ), OBJECT );
		if ( $results ) {
			return $results;
		}

		if ( stristr( $url, '/www.' ) ) {
			// remove www if it's there!
			$url = str_replace( '/www.', '/', $url );
		} else {
			// add www if it's not there!
			$url = str_replace( 'https://', 'https://www.', $url );
			$url = str_replace( 'http://', 'http://www.', $url );
		}

		return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'wp' ) . ' WHERE url = %s ' . $where, $this->escape( $url ) ), OBJECT );
	}

	public function getWebsiteBackupSettings( $websiteid ) {
		if ( ! MainWP_Utility::ctype_digit( $websiteid ) ) {
			return null;
		}

		return $this->getRowResult( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'wp_settings_backup' ) . ' WHERE wpid = %d ', $websiteid ) );
	}

	public function getWebsiteById( $id, $selectGroups = false ) {
		return $this->getRowResult( $this->getSQLWebsiteById( $id, $selectGroups ) );
	}

	public function getSQLWebsiteById( $id, $selectGroups = false, $extra_view = array( 'favi_icon' ) ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			$where = $this->getWhereAllowAccessSites( 'wp', 'nocheckstaging' ); // no check staging
			if ( $selectGroups ) {
				return 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
                FROM ' . $this->tableName( 'wp' ) . ' wp
                LEFT JOIN ' . $this->tableName( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->tableName( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->getOptionView( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.id = ' . $id . $where . '
                GROUP BY wp.id';
			}

			return 'SELECT wp.*,wp_sync.*,wp_optionview.*
                    FROM ' . $this->tableName( 'wp' ) . ' wp
                    JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                    JOIN ' . $this->getOptionView( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                    WHERE id = ' . $id . $where;
		}

		return null;
	}

	public function getWebsitesByIds( $ids, $userId = null ) {
		if ( ( null == $userId ) && MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$userId = $current_user->ID;
		}
		$where = $this->getWhereAllowAccessSites();

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'wp' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' . ( null != $userId ? ' AND userid = ' . $userId : '' ) . $where, OBJECT );
	}

	public function getWebsitesByGroupIds( $ids, $userId = null ) {
		if ( empty( $ids ) ) {
			return array();
		}
		if ( ( null == $userId ) && MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$userId = $current_user->ID;
		}

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'wp' ) . ' wp JOIN ' . $this->tableName( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . implode( ',', $ids ) . ') ' . ( null != $userId ? ' AND wp.userid = ' . $userId : '' ), OBJECT );
	}

	public function getWebsitesByGroupId( $id ) {
		return $this->getResultsResult( $this->getSQLWebsitesByGroupId( $id ) );
	}

	public function getSQLWebsitesByGroupId( $id, $selectgroups = false, $orderBy = 'wp.url', $offset = false,
										  $rowcount = false, $where = null, $search_site = null ) {

		$is_staging = 'no';
		if ( $selectgroups ) {
			if ( $staging_group = get_option( 'mainwp_stagingsites_group_id' ) ) {
				if ( $id == $staging_group ) {
					$is_staging = 'yes'; // will list staging sites
				}
			}
		}

		$where_search = '';
		if ( ! empty( $search_site ) ) {
			$search_site   = trim( $search_site );
			$where_search .= ' AND (wp.name LIKE "%' . $this->escape( $search_site ) . '%" OR wp.url LIKE  "%' . $this->escape( $search_site ) . '%") ';
		}

		if ( MainWP_Utility::ctype_digit( $id ) ) {
			$where_allowed = $this->getWhereAllowAccessSites( 'wp', $is_staging );
			if ( $selectgroups ) {
				$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
                 FROM ' . $this->tableName( 'wp' ) . ' wp
                 JOIN ' . $this->tableName( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                 LEFT JOIN ' . $this->tableName( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                 LEFT JOIN ' . $this->tableName( 'group' ) . ' gr ON wpgr.groupid = gr.id
                 JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                 JOIN ' . $this->getOptionView() . ' wp_optionview ON wp.id = wp_optionview.wpid
                 WHERE wpgroup.groupid = ' . $id . ' ' .
				( null == $where ? '' : ' AND ' . $where ) . $where_allowed . $where_search . '
                 GROUP BY wp.id
                 ORDER BY ' . $orderBy;
			} else {
				$qry = 'SELECT wp.*,wp_sync.* FROM ' . $this->tableName( 'wp' ) . ' wp
                        JOIN ' . $this->tableName( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                        JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                        WHERE wpgroup.groupid = ' . $id . ' ' . $where_allowed . $where_search .
				( null == $where ? '' : ' AND ' . $where ) . ' ORDER BY ' . $orderBy;
			}
			if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
				$qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
			}

			return $qry;
		}

		return null;
	}

	public function getWebsitesByGroupName( $userid, $groupname ) {
		return $this->getResultsResult( $this->getSQLWebsitesByGroupName( $groupname, $userid ) );
	}

	public function getSQLWebsitesByGroupName( $groupname, $userid = null ) {
		if ( ( null == $userid ) && MainWP_System::Instance()->isMultiUser() ) {
			global $current_user;
			$userid = $current_user->ID;
		}

		$sql = 'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->tableName( 'wp' ) . ' wp
                INNER JOIN ' . $this->tableName( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                JOIN ' . $this->tableName( 'group' ) . ' g ON wpgroup.groupid = g.id
                JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->getOptionView() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE g.name="' . $this->escape( $groupname ) . '"';
		if ( null != $userid ) {
			$sql .= ' AND g.userid = "' . $userid . '"';
		}

		return $sql;
	}

	public function getWPIp( $wpid ) {
		return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ip FROM ' . $this->tableName( 'request_log' ) . ' WHERE wpid = %d', $wpid ) );
	}

	public function insertOrUpdateRequestLog( $wpid, $ip, $start, $stop ) {
		$updateValues = array();
		if ( null != $ip ) {
			$updateValues['ip'] = $ip;
		}
		if ( null != $start ) {
			$updateValues['micro_timestamp_start'] = $start;
		}
		if ( null != $stop ) {
			$updateValues['micro_timestamp_stop'] = $stop;
		}

		$var = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->tableName( 'request_log' ) . ' WHERE wpid = %d ', $wpid ) );
		if ( null !== $var ) {
			$this->wpdb->update( $this->tableName( 'request_log' ), $updateValues, array( 'wpid' => $wpid ) );
		} else {
			$updateValues['wpid'] = $wpid;
			$this->wpdb->insert( $this->tableName( 'request_log' ), $updateValues );
		}
	}

	public function closeOpenRequests() {
		// Close requests open longer then 7 seconds.. something is wrong here..
		$this->wpdb->query( 'UPDATE ' . $this->tableName( 'request_log' ) . ' SET micro_timestamp_stop = micro_timestamp_start WHERE micro_timestamp_stop < micro_timestamp_start and ' . microtime( true ) . ' - micro_timestamp_start > 7' );
	}

	public function getNrOfOpenRequests( $ip = null ) {
		if ( null == $ip ) {
			return $this->wpdb->get_var( 'select count(id) from ' . $this->tableName( 'request_log' ) . ' where micro_timestamp_stop < micro_timestamp_start' );
		}

		return $this->wpdb->get_var( 'select count(id) from ' . $this->tableName( 'request_log' ) . ' where micro_timestamp_stop < micro_timestamp_start and ip = "' . esc_sql( $ip ) . '"' );
	}

	public function getLastRequestTimestamp( $ip = null ) {
		if ( null == $ip ) {
			return $this->wpdb->get_var( 'select micro_timestamp_start from ' . $this->tableName( 'request_log' ) . ' order by micro_timestamp_start desc limit 1' );
		}

		return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT micro_timestamp_start FROM ' . $this->tableName( 'request_log' ) . ' WHERE ip = %s order by micro_timestamp_start desc limit 1', esc_sql( $ip ) ) );
	}

	public function addWebsite( $userid, $name, $url, $admin, $pubkey, $privkey, $nossl, $nosslkey, $groupids, $groupnames,
							 $verifyCertificate = 1, $uniqueId = '', $http_user, $http_pass, $sslVersion = 0, $wpe = 0, $isStaging = 0 ) {
		if ( MainWP_Utility::ctype_digit( $userid ) && ( 0 === $nossl || 1 === $nossl ) ) {
			$values = array(
				'userid'                 => $userid,
				'adminname'              => $this->escape( $admin ),
				'name'                   => $this->escape( strip_tags( $name ) ), // escape by insert
				'url'                    => $this->escape( $url ),
				'pubkey'                 => $this->escape( $pubkey ),
				'privkey'                => $this->escape( $privkey ),
				'nossl'                  => $nossl,
				'nosslkey'               => ( null == $nosslkey ? '' : $this->escape( $nosslkey ) ),
				'siteurl'                => '',
				'ga_id'                  => '',
				'gas_id'                 => 0,
				'offline_checks'         => '',
				'offline_checks_last'    => 0,
				'offline_check_result'   => 0,
				'note'                   => '',
				'statsUpdate'            => 0,
				'pagerank'               => 0,
				'indexed'                => 0,
				'alexia'                 => 0,
				'pagerank_old'           => 0,
				'indexed_old'            => 0,
				'alexia_old'             => 0,
				'directories'            => '',
				'plugin_upgrades'        => '',
				'theme_upgrades'         => '',
				'translation_upgrades'   => '',
				'securityIssues'         => '',
				'themes'                 => '',
				'ignored_themes'         => '',
				'plugins'                => '',
				'ignored_plugins'        => '',
				'pages'                  => '',
				'users'                  => '',
				'categories'             => '',
				'pluginDir'              => '',
				'automatic_update'       => 0,
				'backup_before_upgrade'  => 2,
				'verify_certificate'     => intval( $verifyCertificate ),
				'ssl_version'            => $sslVersion,
				'uniqueId'               => $uniqueId,
				'mainwpdir'              => 0,
				'http_user'              => $http_user,
				'http_pass'              => $http_pass,
				'wpe'                    => $wpe,
				'is_staging'             => $isStaging,
			);

			$syncValues = array(
				'dtsSync'                => 0,
				'dtsSyncStart'           => 0,
				'dtsAutomaticSync'       => 0,
				'dtsAutomaticSyncStart'  => 0,
				'totalsize'              => 0,
				'extauth'                => '',
				'sync_errors'            => '',
			);
			if ( $this->wpdb->insert( $this->tableName( 'wp' ), $values ) ) {
				$websiteid          = $this->wpdb->insert_id;
				$syncValues['wpid'] = $websiteid;
				$this->wpdb->insert( $this->tableName( 'wp_sync' ), $syncValues );
				$this->wpdb->insert( $this->tableName( 'wp_settings_backup' ), array(
					'wpid'           => $websiteid,
					'archiveFormat'  => 'global',
				) );

				foreach ( $groupnames as $groupname ) {
					if ( $this->wpdb->insert( $this->tableName( 'group' ), array(
						'userid' => $userid,
						'name'   => $this->escape( htmlspecialchars( $groupname ) ),
					) )
					) {
						$groupids[] = $this->wpdb->insert_id;
					}
				}
				// add groupids
				foreach ( $groupids as $groupid ) {
					$this->wpdb->insert( $this->tableName( 'wp_group' ), array(
						'wpid'       => $websiteid,
						'groupid'    => $groupid,
					) );
				}

				return $websiteid;
			}
		}

		return false;
	}

	public function updateGroupSite( $groupId, $websiteId ) {
		$this->wpdb->insert( $this->tableName( 'wp_group' ), array(
			'wpid'    => $websiteId,
			'groupid' => $groupId,
		) );
	}

	public function clearGroup( $groupId ) {
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'wp_group' ) . ' WHERE groupid= %d', $groupId ) );
	}

	public function addGroup( $userid, $name ) {
		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			if ( $this->wpdb->insert( $this->tableName( 'group' ), array(
				'userid' => $userid,
				'name'   => $this->escape( $name ),
			) )
			) {
				return $this->wpdb->insert_id;
			}
		}

		return false;
	}

	public function removeWebsite( $websiteid ) {
		if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
			$nr = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'wp' ) . ' WHERE id=%d', $websiteid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'wp_group' ) . ' WHERE wpid=%d', $websiteid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'wp_sync' ) . ' WHERE wpid=%d', $websiteid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'wp_options' ) . ' WHERE wpid=%d', $websiteid ) );

			return $nr;
		}

		return false;
	}

	public function removeGroup( $groupid ) {
		if ( MainWP_Utility::ctype_digit( $groupid ) ) {
			$nr = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'group' ) . ' WHERE id=%d', $groupid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'wp_group' ) . ' WHERE groupid=%d', $groupid ) );

			return $nr;
		}

		return false;
	}

	public function updateNote( $websiteid, $note ) {
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->tableName( 'wp' ) . ' SET note= %s, note_lastupdate = %d WHERE id=%d', $this->escape( $note ), time(), $websiteid ) );
	}

	public function updateWebsiteValues( $websiteid, $fields ) {
		if ( 0 < count( $fields ) ) {
			return $this->wpdb->update( $this->tableName( 'wp' ), $fields, array( 'id' => $websiteid ) );
		}

		return false;
	}

	public function updateWebsiteSyncValues( $websiteid, $fields ) {
		if ( 0 < count( $fields ) ) {
			return $this->wpdb->update( $this->tableName( 'wp_sync' ), $fields, array( 'wpid' => $websiteid ) );
		}

		return false;
	}

	public function updateWebsite( $websiteid, $url, $userid, $name, $siteadmin, $groupids, $groupnames, $offlineChecks,
								$pluginDir, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors,
								$verifyCertificate = 1, $archiveFormat, $uniqueId = '', $http_user = null, $http_pass = null, $sslVersion = 0,
								$wpe = 0 ) {
		if ( MainWP_Utility::ctype_digit( $websiteid ) && MainWP_Utility::ctype_digit( $userid ) ) {
			$website = self::Instance()->getWebsiteById( $websiteid );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				// update admin
				$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->tableName( 'wp' ) . ' SET url="' . $this->escape( $url ) . '", name="' . $this->escape( strip_tags($name) ) . '", adminname="' . $this->escape( $siteadmin ) . '",offline_checks="' . $this->escape( $offlineChecks ) . '",pluginDir="' . $this->escape( $pluginDir ) . '",maximumFileDescriptorsOverride = ' . ( $maximumFileDescriptorsOverride ? 1 : 0 ) . ',maximumFileDescriptorsAuto= ' . ( $maximumFileDescriptorsAuto ? 1 : 0 ) . ',maximumFileDescriptors = ' . $maximumFileDescriptors . ', verify_certificate="' . intval( $verifyCertificate ) . '", ssl_version="' . intval( $sslVersion ) . '", wpe="' . intval( $wpe ) . '", uniqueId="' . $this->escape( $uniqueId ) . '", http_user="' . $this->escape( $http_user ) . '", http_pass="' . $this->escape( $http_pass ) . '"  WHERE id=%d', $websiteid ) );
				$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->tableName( 'wp_settings_backup' ) . ' SET archiveFormat = "' . $this->escape( $archiveFormat ) . '" WHERE wpid=%d', $websiteid ) );
				// remove groups
				$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'wp_group' ) . ' WHERE wpid=%d', $websiteid ) );
				// Remove GA stats
				$showErrors = $this->wpdb->hide_errors();
				do_action( 'mainwp_ga_delete_site', $websiteid );
				if ( $showErrors ) {
					$this->wpdb->show_errors();
				}
				// add groups with groupnames
				foreach ( $groupnames as $groupname ) {
					if ( $this->wpdb->insert( $this->tableName( 'group' ), array(
						'userid' => $userid,
						'name'   => $this->escape( $groupname ),
					) )
					) {
						$groupids[] = $this->wpdb->insert_id;
					}
				}
				// add groupids
				foreach ( $groupids as $groupid ) {
					$this->wpdb->insert( $this->tableName( 'wp_group' ), array(
						'wpid'       => $websiteid,
						'groupid'    => $groupid,
					) );
				}

				return true;
			}
		}

		return false;
	}

	public function updateGroup( $groupid, $groupname ) {
		if ( MainWP_Utility::ctype_digit( $groupid ) ) {
			// update groupname
			$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->tableName( 'group' ) . ' SET name=%s WHERE id=%d', $this->escape( $groupname ), $groupid ) );

			return true;
		}

		return false;
	}

	public function updateBackupTaskProgress( $task_id, $wp_id, $values ) {
		$this->wpdb->update( $this->tableName( 'wp_backup_progress' ), $values, array(
			'task_id'    => $task_id,
			'wp_id'      => $wp_id,
		) );

		return $this->getBackupTaskProgress( $task_id, $wp_id );
	}

	public function addBackupTaskProgress( $task_id, $wp_id, $information ) {
		$values = array(
			'task_id'        => $task_id,
			'wp_id'          => $wp_id,
			'dtsFetched'     => time(),
			'fetchResult'    => wp_json_encode( $information ),
			'removedFiles'   => 0,
			'downloadedDB'   => '',
			'downloadedFULL' => '',
		);

		if ( $this->wpdb->insert( $this->tableName( 'wp_backup_progress' ), $values ) ) {
			return $this->getBackupTaskProgress( $task_id, $wp_id );
		}

		return null;
	}

	public function getBackupTaskProgress( $task_id, $wp_id ) {
		$progress = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'wp_backup_progress' ) . ' WHERE task_id= %d AND wp_id = %d ', $task_id, $wp_id )  );

		if ( '' !== $progress->fetchResult ) {
			$progress->fetchResult = json_decode( $progress->fetchResult, true );
		}

		return $progress;
	}

	public function backupFullTaskRunning( $wp_id ) {

		if ( ! get_option('mainwp_enableLegacyBackupFeature') ) {
			return false;
		}

		$progresses = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'wp_backup_progress' ) . ' WHERE wp_id = %d AND dtsFetched > %d ', $wp_id, time() - ( 30 * 60 ) ) );
		if ( is_array( $progresses ) ) {
			foreach ( $progresses as $progress ) {
				if ( ( 0 == $progress->downloadedDBComplete ) && ( 0 == $progress->downloadedFULLComplete ) ) {
					if ( $task = $this->getBackupTaskById( $progress->task_id ) ) {
						if ( ( 'full' == $task->type ) && ! $task->paused ) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	public function removeBackupTask( $id ) {
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tableName( 'wp_backup' ) . ' WHERE id = %d', $id ) );
	}

	public function getBackupTaskById( $id ) {
		return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tableName( 'wp_backup' ) . ' WHERE id= %d ', $id ) );
	}

	public function getBackupTasksForUser( $orderBy = 'name' ) {
		if ( MainWP_System::Instance()->isSingleUser() ) {
			return $this->getBackupTasks( null, $orderBy );
		}

		global $current_user;

		return $this->getBackupTasks( $current_user->ID, $orderBy );
	}

	public function getBackupTasks( $userid = null, $orderBy = null ) {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'wp_backup' ) . ' WHERE ' . ( null == $userid ? '' : 'userid= ' . $userid . ' AND ' ) . ' template = 0 ' . ( null != $orderBy ? 'ORDER BY ' . $orderBy : '' ), OBJECT );
	}

	public function addBackupTask( $userid, $name, $schedule, $type, $exclude, $sites, $groups, $subfolder, $filename,
								$template, $excludebackup, $excludecache, $excludenonwp, $excludezip, $archiveFormat,
								$maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip ) {
		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			$values = array(
				'userid'                         => $userid,
				'name'                           => $name,
				'schedule'                       => $schedule,
				'type'                           => $type,
				'exclude'                        => $exclude,
				'sites'                          => $sites,
				'groups'                         => $groups,
				'last'                           => 0,
				'last_run'                       => 0,
				'last_run_manually'              => 0,
				'completed_sites'                => '',
				'completed'                      => 0,
				'backup_errors'                  => '',
				'subfolder'                      => MainWP_Utility::removePreSlashSpaces( $subfolder ),
				'filename'                       => $filename,
				'paused'                         => 0,
				'template'                       => $template,
				'excludebackup'                  => $excludebackup,
				'excludecache'                   => $excludecache,
				'excludenonwp'                   => $excludenonwp,
				'excludezip'                     => $excludezip,
				'archiveFormat'                  => $archiveFormat,
				'loadFilesBeforeZip'             => $loadFilesBeforeZip,
				'maximumFileDescriptorsOverride' => $maximumFileDescriptorsOverride,
				'maximumFileDescriptorsAuto'     => $maximumFileDescriptorsAuto,
				'maximumFileDescriptors'         => $maximumFileDescriptors,
			);

			if ( $this->wpdb->insert( $this->tableName( 'wp_backup' ), $values ) ) {
				return $this->getBackupTaskById( $this->wpdb->insert_id );
			}
		}

		return false;
	}

	public function updateBackupTask( $id, $userid, $name, $schedule, $type, $exclude, $sites, $groups, $subfolder,
								   $filename, $excludebackup, $excludecache, $excludenonwp, $excludezip, $archiveFormat,
								   $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $loadFilesBeforeZip ) {
		if ( MainWP_Utility::ctype_digit( $userid ) && MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update( $this->tableName( 'wp_backup' ), array(
				'userid'                         => $userid,
				'name'                           => $name,
				'schedule'                       => $schedule,
				'type'                           => $type,
				'exclude'                        => $exclude,
				'sites'                          => $sites,
				'groups'                         => $groups,
				'subfolder'                      => MainWP_Utility::removePreSlashSpaces( $subfolder ),
				'filename'                       => $filename,
				'excludebackup'                  => $excludebackup,
				'excludecache'                   => $excludecache,
				'excludenonwp'                   => $excludenonwp,
				'excludezip'                     => $excludezip,
				'archiveFormat'                  => $archiveFormat,
				'loadFilesBeforeZip'             => $loadFilesBeforeZip,
				'maximumFileDescriptorsOverride' => $maximumFileDescriptorsOverride,
				'maximumFileDescriptorsAuto'     => $maximumFileDescriptorsAuto,
				'maximumFileDescriptors'         => $maximumFileDescriptors,
			), array( 'id' => $id ) );
		}

		return false;
	}

	public function updateBackupTaskWithValues( $id, $values ) {
		if ( ! is_array( $values ) ) {
			return false;
		}

		return $this->wpdb->update( $this->tableName( 'wp_backup' ), $values, array( 'id' => $id ) );
	}

	public function updateBackupRun( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update( $this->tableName( 'wp_backup' ), array(
				'last_run'           => time(),
				'last'               => time(),
				'completed_sites'    => wp_json_encode( array() ),
			), array( 'id' => $id ) );
		}

		return false;
	}

	public function updateBackupRunManually( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update( $this->tableName( 'wp_backup' ), array( 'last_run_manually' => time() ), array( 'id' => $id ) );
		}

		return false;
	}

	public function updateBackupCompleted( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update( $this->tableName( 'wp_backup' ), array( 'completed' => time() ), array( 'id' => $id ) );
		}

		return false;
	}

	public function updateBackupErrors( $id, $errors ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			if ( '' === $errors ) {
				return $this->wpdb->update( $this->tableName( 'wp_backup' ), array( 'backup_errors' => '' ), array( 'id' => $id ) );
			} else {
				$task = $this->getBackupTaskById( $id );

				return $this->wpdb->update( $this->tableName( 'wp_backup' ), array( 'backup_errors' => $task->backup_errors . $errors ), array( 'id' => $id ) );
			}
		}

		return false;
	}

	public function updateCompletedSites( $id, $completedSites ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->update( $this->tableName( 'wp_backup' ), array( 'completed_sites' => wp_json_encode( $completedSites ) ), array( 'id' => $id ) );
		}

		return false;
	}

	public function getOfflineChecks() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'wp' ) . ' WHERE (offline_checks="hourly" AND ' . time() . ' - offline_checks_last >= ' . ( 60 * 60 * 1 ) . ') OR (offline_checks="2xday" AND ' . time() . ' - offline_checks_last >= ' . ( 60 * 60 * 12 * 1 ) . ') OR (offline_checks="daily" AND ' . time() . ' - offline_checks_last >= ' . ( 60 * 60 * 24 * 1 ) . ') OR (offline_checks="weekly" AND ' . time() . ' - offline_checks_last >= ' . ( 60 * 60 * 24 * 7 ) . ')', OBJECT );
	}

	public function getWebsitesCheckUpdatesCount() {
		$where = $this->getWhereAllowAccessSites( 'wp' );

		return $this->wpdb->get_var( 'SELECT count(wp.id) FROM ' . $this->tableName( 'wp' ) . ' wp
                                            JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                                            WHERE (wp_sync.dtsAutomaticSyncStart = 0 OR DATE(FROM_UNIXTIME(wp_sync.dtsAutomaticSyncStart)) <> DATE(NOW())) ' . $where );
	}

	public function getWebsitesCountWhereDtsAutomaticSyncSmallerThenStart() {
		$where = $this->getWhereAllowAccessSites( 'wp' );

		// once a day
		return $this->wpdb->get_var( 'SELECT count(wp.id) FROM ' . $this->tableName( 'wp' ) . ' wp
                                            JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                                            WHERE ((wp_sync.dtsAutomaticSync < wp_sync.dtsAutomaticSyncStart) OR (wp_sync.dtsAutomaticSyncStart = 0) OR (DATE(FROM_UNIXTIME(wp_sync.dtsAutomaticSyncStart)) <> DATE(NOW()))) ' . $where );
	}

	public function getWebsitesLastAutomaticSync() {
		// once a day
		return $this->wpdb->get_var( 'SELECT MAX(wp_sync.dtsAutomaticSync) FROM ' . $this->tableName( 'wp' ) . ' wp
                                            JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid' );
	}

	public function getWebsitesCheckUpdates( $limit ) {
		$where = $this->getWhereAllowAccessSites( 'wp' );

		// once a day
		return $this->wpdb->get_results( 'SELECT wp.*,wp_sync.*,wp_optionview.*
                                    FROM ' . $this->tableName( 'wp' ) . ' wp
                                    JOIN ' . $this->tableName( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                                    JOIN ' . $this->getOptionView() . ' wp_optionview ON wp.id = wp_optionview.wpid
                                    WHERE (wp_sync.dtsAutomaticSyncStart = 0 OR DATE(FROM_UNIXTIME(wp_sync.dtsAutomaticSyncStart)) <> DATE(NOW())) ' . $where . ' LIMIT 0,' . $limit, OBJECT );
	}

	public function getWebsitesStatsUpdateSQL() {
		$where = $this->getWhereAllowAccessSites();

		// once a week
		return 'SELECT * FROM ' . $this->tableName( 'wp' ) . ' WHERE (statsUpdate = 0 OR ' . time() . ' - statsUpdate >= ' . ( 60 * 60 * 24 * 7 ) . ')' . $where . ' ORDER BY statsUpdate ASC';
	}

	public function updateWebsiteStats( $websiteid, $statsUpdated ) {
		return $this->wpdb->update( $this->tableName( 'wp' ), array(
			'statsUpdate' => $statsUpdated,
		), array( 'id' => $websiteid ) );
	}

	public function getBackupTasksToComplete() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'wp_backup' ) . ' WHERE paused = 0 AND completed < last_run', // AND '. time() . ' - last_run >= 120 AND ' . time() . ' - last >= 120'
		 OBJECT );
	}

	public function getBackupTasksTodoDaily() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'wp_backup' ) . ' WHERE paused = 0 AND schedule="daily" AND ' . time() . ' - last_run >= ' . ( 60 * 60 * 24 ), OBJECT );
	}

	public function getBackupTasksTodoWeekly() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'wp_backup' ) . ' WHERE paused = 0 AND schedule="weekly" AND ' . time() . ' - last_run >= ' . ( 60 * 60 * 24 * 7 ), OBJECT );
	}

	public function getBackupTasksTodoMonthly() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->tableName( 'wp_backup' ) . ' WHERE paused = 0 AND schedule="monthly" AND ' . time() . ' - last_run >= ' . ( 60 * 60 * 24 * 30 ), OBJECT );
	}

	public function getUserNotificationEmail( $userid ) {
		$theUserId = $userid;
		if ( MainWP_System::Instance()->isSingleUser() ) {
			$theUserId = 0;
		}
		$user_email = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT user_email FROM ' . $this->tableName( 'users' ) . ' WHERE userid = %d', $theUserId ) );

		if ( null == $user_email || '' == $user_email ) {
			$user_email = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT user_email FROM ' . $this->wpdb->prefix . 'users WHERE id = %d', $userid ) );
		}

		return $user_email;
	}

	public function getUserExtension() {
		global $current_user;

		if ( empty( $current_user ) ) {
			if ( MainWP_System::Instance()->isSingleUser() ) {
				$userid = 0;
			} else {
				return false;
			}
		} else {
			$userid = $current_user->ID;
		}

		return $this->getUserExtensionByUserId( $userid );
	}

	public function getUserExtensionByUserId( $userid ) {
		if ( MainWP_System::Instance()->isSingleUser() ) {
			$userid = 0;
		}

		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->tableName( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		if ( null == $row ) {
			$this->createUserExtension( $userid );
			$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->tableName( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		}

		return $row;
	}

	protected function createUserExtension( $userId ) {
		$fields = array(
			'userid'                 => $userId,
			'user_email'             => '',
			'ignored_plugins'        => '',
			'trusted_plugins'        => '',
			'trusted_plugins_notes'  => '',
			'ignored_themes'         => '',
			'trusted_themes'         => '',
			'trusted_themes_notes'   => '',
			'pluginDir'              => '',
		);

		$this->wpdb->insert( $this->tableName( 'users' ), $fields );
	}

	public function updateUserExtension( $userExtension ) {

		if ( is_object($userExtension) ) {
			$userid = $userExtension->userid;
		} elseif ( is_array($userExtension) ) {
			$userid = $userExtension['userid']; // fix for some
		} else {
			$userid = null;
		}

		if ( null == $userid ) {
			if ( MainWP_System::Instance()->isSingleUser() ) {
				$userid = '0';
			} else {
				global $current_user;
				$userid = $current_user->ID;
			}
		}
		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->tableName( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		if ( null == $row ) {
			$this->createUserExtension( $userid );
		}

		$fields = array();
		foreach ( $userExtension as $field => $value ) {
			if ( $value != $row->$field ) {
				$fields[ $field ] = $value;
			}
		}

		if ( 0 < count( $fields ) ) {
			$this->wpdb->update( $this->tableName( 'users' ), $fields, array( 'userid' => $userid ) );
		}

		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->tableName( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );

		return $row;
	}

	public function getMySQLVersion() {
		return $this->wpdb->get_var( 'SHOW VARIABLES LIKE "version"', 1 );
	}

	public function getRowResult( $sql ) {
		if ( null == $sql ) {
			return null;
		}

		return $this->wpdb->get_row( $sql, OBJECT );
	}

	public function getResultsResult( $sql ) {
		if ( null == $sql ) {
			return null;
		}

		return $this->wpdb->get_results( $sql, OBJECT_K );
	}

	public function query( $sql ) {
		if ( null == $sql ) {
			return false;
		}

		$result = self::_query( $sql, $this->wpdb->dbh );

		if ( ! $result || ( 0 == self::num_rows( $result ) ) ) {
			return false;
		}

		return $result;
	}

	protected function escape( $data ) {
		if ( function_exists( 'esc_sql' ) ) {
			return esc_sql( $data );
		} else {
			return $this->wpdb->escape( $data );
		}
	}

	// Support old & new versions of WordPress (3.9+)
	public static function use_mysqli() {
		/** @var $this ->wpdb wpdb */
		if ( ! function_exists( 'mysqli_connect' ) ) {
			return false;
		}

		return ( self::$instance->wpdb->dbh instanceof mysqli );
	}

	public static function ping( $link ) {
		if ( self::use_mysqli() ) {
			return mysqli_ping( $link );
		} else {
			return mysql_ping( $link );
		}
	}

	public static function _query( $query, $link ) {
		if ( self::use_mysqli() ) {
			return mysqli_query( $link, $query );
		} else {
			return mysql_query( $query, $link );
		}
	}

	public static function fetch_object( $result ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return mysqli_fetch_object( $result );
		} else {
			return mysql_fetch_object( $result );
		}
	}

	public static function free_result( $result ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return mysqli_free_result( $result );
		} else {
			return mysql_free_result( $result );
		}
	}

	public static function data_seek( $result, $offset ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return mysqli_data_seek( $result, $offset );
		} else {
			return mysql_data_seek( $result, $offset );
		}
	}

	public static function fetch_array( $result, $result_type = null ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return mysqli_fetch_array( $result, ( null == $result_type ? MYSQLI_BOTH : $result_type ) );
		} else {
			return mysql_fetch_array( $result, ( null == $result_type ? MYSQL_BOTH : $result_type ) );
		}
	}

	public static function num_rows( $result ) {
		if ( false === $result ) {
			return 0;
		}

		if ( self::use_mysqli() ) {
			return mysqli_num_rows( $result );
		} else {
			return mysql_num_rows( $result );
		}
	}

	public static function is_result( $result ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return ( $result instanceof mysqli_result );
		} else {
			return is_resource( $result );
		}
	}

}
