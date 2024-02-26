<?php
/**
 * MainWP Install
 *
 * This file handles install MainWP DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Install
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_DB_Base
 */
class MainWP_Install extends MainWP_DB_Base {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

	/**
	 * Private variable to hold the database version info.
	 *
	 * @var string DB version info.
	 */
	protected $mainwp_db_version = '9.0.0.4';

	/**
	 * Protected variable to hold the database option name.
	 *
	 * @var string DB version info.
	 */
	protected $option_db_key = 'mainwp_db_version';

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Return public static instance.
	 *
	 * @static
	 * @return MainWP_DB
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		self::$instance->test_connection();

		return self::$instance;
	}

	/**
	 * Method install()
	 *
	 * Installs the new DB.
	 *
	 * @return void
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function install() { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		// get_site_option is multisite aware!
		$currentVersion = get_site_option( $this->option_db_key );

		if ( empty( $currentVersion ) ) {
			update_option( 'mainwp_run_quick_setup', 'yes' );
			MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 0 );
		} elseif ( false === get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
				MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 1 );
		}

		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.8', '<' ) ) {
			MainWP_Utility::update_option( 'mainwp_selected_theme', 'default' );
		}

		$rslt = self::instance()->query( "SHOW TABLES LIKE '" . $this->table_name( 'wp' ) . "'" );
		if ( empty( self::num_rows( $rslt ) ) ) {
			$currentVersion = false;
		}

		if ( $currentVersion === $this->mainwp_db_version ) {
			return;
		}

		$this->pre_update_tables();

		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = array();
		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp' ) . " (
   id int(11) NOT NULL auto_increment,
   userid int(11) NOT NULL,
   adminname text NOT NULL,
  name text NOT NULL,
  url text NOT NULL,
  pubkey text NOT NULL,
  privkey text NOT NULL,
  siteurl text NOT NULL,
  ga_id text NOT NULL,
  gas_id int(11) NOT NULL,
  offline_checks_last int(11) NOT NULL,
  offline_check_result int(11) NOT NULL,  
  http_response_code int(11) NOT NULL DEFAULT 0,
  http_code_noticed tinyint(1) NOT NULL DEFAULT 1,
  disable_status_check tinyint(1) NOT NULL DEFAULT 0,
  disable_health_check tinyint(1) NOT NULL DEFAULT 0,
  status_check_interval tinyint(1) NOT NULL DEFAULT 0,
  health_threshold int(11) NOT NULL DEFAULT 0,  
  note text NOT NULL,
  statsUpdate int(11) NOT NULL,
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
  users longtext NOT NULL,
  categories longtext NOT NULL,
  pluginDir text NOT NULL,
  automatic_update tinyint(1) NOT NULL,
  backup_before_upgrade tinyint(1) NOT NULL DEFAULT 2,
  mainwpdir tinyint(1) NOT NULL,
  loadFilesBeforeZip tinyint(1) NOT NULL DEFAULT 1,
  is_ignoreCoreUpdates tinyint(1) NOT NULL DEFAULT 0,
  is_ignorePluginUpdates tinyint(1) NOT NULL DEFAULT 0,
  is_ignoreThemeUpdates tinyint(1) NOT NULL DEFAULT 0,
  verify_certificate tinyint(1) NOT NULL DEFAULT 1,
  force_use_ipv4 tinyint(1) NOT NULL DEFAULT 0,
  ssl_version tinyint(1) NOT NULL DEFAULT 0,
  ip text NOT NULL DEFAULT '',
  uniqueId text NOT NULL,
  maximumFileDescriptorsOverride tinyint(1) NOT NULL DEFAULT 0,
  maximumFileDescriptorsAuto tinyint(1) NOT NULL DEFAULT 1,
  maximumFileDescriptors int(11) NOT NULL DEFAULT 150,
  http_user text NOT NULL DEFAULT '',
  http_pass text NOT NULL DEFAULT '',
  wpe tinyint(1) NOT NULL,
  is_staging tinyint(1) NOT NULL DEFAULT 0,
  client_id int(11) NOT NULL DEFAULT 0,
  `suspended` tinyint(1) NOT NULL DEFAULT 0,
  KEY idx_userid (userid)";
		if ( empty( $currentVersion ) ) {
			$tbl .= ',
  PRIMARY KEY  (id)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_sync' ) . " (
  sync_id int(11) NOT NULL auto_increment,
  wpid int(11) NOT NULL,
  version text NOT NULL DEFAULT '',
  sync_errors longtext NOT NULL DEFAULT '',
  uptodate longtext NOT NULL DEFAULT '',
  dtsAutomaticSync int(11) NOT NULL DEFAULT 0,
  dtsAutomaticSyncStart int(11) NOT NULL DEFAULT 0,
  dtsSync int(11) NOT NULL DEFAULT 0,
  dtsSyncStart int(11) NOT NULL DEFAULT 0,
  totalsize int(11) NOT NULL DEFAULT 0,
  dbsize int(11) NOT NULL DEFAULT 0,
  extauth text NOT NULL DEFAULT '',
  last_post_gmt int(11) NOT NULL DEFAULT 0,
  health_value int(11) NOT NULL DEFAULT 0,
  health_status tinyint(1) NOT NULL DEFAULT 0,
  health_site_noticed tinyint(1) NOT NULL DEFAULT 1,
  KEY idx_wpid (wpid)";

		if ( empty( $currentVersion ) ) {
			$tbl .= ',
	PRIMARY KEY  (sync_id)';
		}

		$tbl .= ') ' . $charset_collate;

		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_options' ) . " (
  opt_id int(11) NOT NULL auto_increment,
  wpid int(11) NOT NULL,
  name text NOT NULL DEFAULT '',
  value longtext NOT NULL DEFAULT '',
  KEY idx_wpid (wpid)";

		if ( empty( $currentVersion ) ) {
			$tbl .= ',
	PRIMARY KEY  (opt_id)';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_settings_backup' ) . ' (
  set_id int(11) NOT NULL auto_increment,
  wpid int(11) NOT NULL,
  archiveFormat text NOT NULL,
  KEY idx_wpid (wpid)';

		if ( empty( $currentVersion ) ) {
			$tbl .= ',
	PRIMARY KEY  (set_id)';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'users' ) . " (
  userid int(11) NOT NULL,
  user_email text NOT NULL DEFAULT '',
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
		if ( empty( $currentVersion ) ) {
			$tbl .= ',
  PRIMARY KEY  (userid)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_status' ) . ' (
	statusid bigint(20) unsigned NOT NULL auto_increment,
	wpid int(11) NOT NULL,
	http_code smallint NOT NULL DEFAULT 0,
	status tinyint(1) NOT NULL DEFAULT 0,
	event_timestamp int(11) NOT NULL,
	duration int(11) NOT NULL DEFAULT 0';
		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.31', '<=' ) ) {
			$tbl .= ',
			PRIMARY KEY  (statusid)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'group' ) . ' (
  id int(11) NOT NULL auto_increment,
  userid int(11) NOT NULL,
  name text NOT NULL,
  color varchar(32) NOT NULL DEFAULT ""';
		if ( empty( $currentVersion ) ) {
			$tbl .= ',
  PRIMARY KEY  (id)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_group' ) . ' (
  wp_group_id int(11) NOT NULL auto_increment,
  wpid int(11) NOT NULL,
  groupid int(11) NOT NULL,
  KEY idx_wpid (wpid),
  KEY idx_groupid (groupid)';
		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.57', '<=' ) ) {
			$tbl .= ',
  PRIMARY KEY  (wp_group_id)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_backup_progress' ) . " (
  task_id int(11) NOT NULL,
  wp_id int(11) NOT NULL,
  dtsFetched int(11) NOT NULL DEFAULT 0,
  fetchResult text NOT NULL DEFAULT '',
  downloadedDB text NOT NULL DEFAULT '',
  downloadedFULL text NOT NULL DEFAULT '',
  downloadedDBComplete tinyint(1) NOT NULL DEFAULT 0,
  downloadedFULLComplete tinyint(1) NOT NULL DEFAULT 0,
  removedFiles tinyint(1) NOT NULL DEFAULT 0,
  attempts int(11) NOT NULL DEFAULT 0,
  last_error text NOT NULL DEFAULT '',
  pid int(11) NOT NULL DEFAULT 0,
  KEY idx_task_id (task_id)";
		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.53', '<=' ) ) {
			$tbl .= ',
			UNIQUE (task_id)';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_backup' ) . ' (
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
		if ( empty( $currentVersion ) ) {
			$tbl .= ',
  PRIMARY KEY  (id)  ';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'action_log' ) . " (
	id int(11) NOT NULL auto_increment,
	log_content mediumtext NOT NULL DEFAULT '',	
	log_type tinyint(1) DEFAULT 0,
	log_color tinyint(1) DEFAULT 0,	
	log_user varchar(128) NOT NULL DEFAULT '',
	log_timestamp int(11) NOT NULL DEFAULT 0";
		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.50', '<=' ) ) {
			$tbl .= ',
	PRIMARY KEY  (id)  ';
		}
			$tbl  .= ') ' . $charset_collate . ';';
			$sql[] = $tbl;

		$tbl = 'CREATE TABLE ' . $this->table_name( 'request_log' ) . " (
  id int(11) NOT NULL auto_increment,
  wpid int(11) NOT NULL,
  ip text NOT NULL DEFAULT '',
  subnet text NOT NULL DEFAULT '',
  micro_timestamp_stop DECIMAL( 12, 2 ) NOT NULL DEFAULT  0,
  micro_timestamp_start DECIMAL( 12, 2 ) NOT NULL DEFAULT  0";
		if ( empty( $currentVersion ) || version_compare( $currentVersion, '5.7', '<=' ) ) {
			$tbl .= ',
  PRIMARY KEY  (id)  ';
		}
		$tbl  .= ') ' . $charset_collate . ';';
		$sql[] = $tbl;

		$sql = apply_filters( 'mainwp_db_install_tables', $sql, $currentVersion, $charset_collate );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		if ( MainWP_Utility::instance()->is_disabled_functions( 'error_log' ) || ! function_exists( '\error_log' ) ) {
			error_reporting(0); // phpcs:ignore -- try to disabled the error_log somewhere in WP.
		}

		$suppress = $wpdb->suppress_errors();
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
		$wpdb->suppress_errors( $suppress );

		$this->post_update();

		if ( ! is_multisite() ) {
			MainWP_Utility::update_option( $this->option_db_key, $this->mainwp_db_version );
		} else {
			update_site_option( $this->option_db_key, $this->mainwp_db_version );
		}
	}

	/**
	 * Returns the database version.
	 *
	 * @return string
	 */
	public function get_db_version() {
		return get_site_option( $this->option_db_key );
	}

	/**
	 * Method post_update()
	 *
	 * Update MainWP DB.
	 *
	 * @return void
	 */
	public function post_update() {

		// get_site_option is multisite aware!
		$currentVersion = get_site_option( $this->option_db_key );

		if ( false === $currentVersion ) {
			return;
		}

		$suppress = $this->wpdb->suppress_errors();

		$this->post_update_81( $currentVersion );

		if ( version_compare( $currentVersion, '8.984', '<' ) ) {
			$sslColumns = array(
				'nossl',
				'nosslkey',
			);
			foreach ( $sslColumns as $col ) {
				$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN ' . $col );
			}
		}

		// delete old columns.
		if ( version_compare( $currentVersion, '8.17', '<' ) ) {
			$rankColumns = array(
				'pagerank',
				'indexed',
				'alexia',
				'pagerank_old',
				'indexed_old',
				'alexia_old',
				'last_db_backup_size',
			);

			foreach ( $rankColumns as $rankColumn ) {
				$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN ' . $rankColumn );
			}

			$syncColumns = array( 'uptodate' );
			foreach ( $syncColumns as $column ) {
				$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_sync' ) . ' DROP COLUMN ' . $column );
			}
		}

		// delete old columns.
		if ( version_compare( $currentVersion, '8.35', '<' ) ) {
			$delColumns = array( 'offline_checks' );
			foreach ( $delColumns as $column ) {
				$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN ' . $column );
			}
			$delColumns = array( 'heatMap' );
			foreach ( $delColumns as $column ) {
				$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'users' ) . ' DROP COLUMN ' . $column );
			}
		}

		// change columns.
		if ( version_compare( $currentVersion, '8.40', '<' ) && version_compare( $currentVersion, '8.30', '>' ) ) {
			$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_status' ) . ' CHANGE COLUMN `timestamp_status` `event_timestamp` int(11) NOT NULL' );
		}

		// delete columns.
		if ( version_compare( $currentVersion, '8.42', '<' ) ) {
			$delColumns = array( 'offlineChecksOnlineNotification' );
			foreach ( $delColumns as $column ) {
				$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'users' ) . ' DROP COLUMN ' . $column );
			}
		}

		// fix missing PRIMARY keys.
		if ( version_compare( $currentVersion, '8.53', '<=' ) ) {
			$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_options' ) . ' ADD opt_id int NOT NULL AUTO_INCREMENT PRIMARY KEY' );
			$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_settings_backup' ) . ' ADD set_id int NOT NULL AUTO_INCREMENT PRIMARY KEY' );
			$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_sync' ) . ' ADD sync_id int NOT NULL AUTO_INCREMENT PRIMARY KEY' );
		}

		$this->wpdb->suppress_errors( $suppress );
		MainWP_DB_Client::instance()->check_to_updates_reports_data_861( $currentVersion );
	}

	/**
	 * Method pre_update_tables()
	 *
	 * Handle pre update tables.
	 *
	 * @return void
	 */
	public function pre_update_tables() {
		// get_site_option is multisite aware!
		$currentVersion = get_site_option( $this->option_db_key );

		if ( false === $currentVersion ) {
			return;
		}

		$suppress = $this->wpdb->suppress_errors();

		if ( version_compare( $currentVersion, '8.98', '<=' ) ) {
			$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN backups' );
			$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN note_lastupdate' );
			$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN pages' );
		}

		$this->wpdb->suppress_errors( $suppress );
	}

	/**
	 * Method post_update_81()
	 *
	 * Update MainWP DB for version 8.1.
	 *
	 * @param string $current_version Current version DB.
	 *
	 * @return void
	 */
	public function post_update_81( $current_version ) {

		if ( version_compare( $current_version, '8.1', '<' ) ) {

			// We can't split up here!
			$wpSyncColumns = array(
				'version',
				'totalsize',
				'dbsize',
				'extauth',
				'last_post_gmt',
				'sync_errors',
				'dtsSync',
				'dtsSyncStart',
				'dtsAutomaticSync',
				'dtsAutomaticSyncStart',
			);
			foreach ( $wpSyncColumns as $wpSyncColumn ) {
				$rslts = $this->wpdb->get_results( 'SELECT id,' . $wpSyncColumn . ' FROM ' . $this->table_name( 'wp' ), ARRAY_A );
				if ( empty( $rslts ) ) {
					continue;
				}

				foreach ( $rslts as $rslt ) {
					$exists = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT wpid FROM ' . $this->table_name( 'wp_sync' ) . ' WHERE wpid = %d', $rslt['id'] ), ARRAY_A );
					if ( empty( $exists ) ) {
						$this->wpdb->insert(
							$this->table_name( 'wp_sync' ),
							array(
								'wpid'        => $rslt['id'],
								$wpSyncColumn => $rslt[ $wpSyncColumn ],
							)
						);
					} else {
						$this->wpdb->update( $this->table_name( 'wp_sync' ), array( $wpSyncColumn => $rslt[ $wpSyncColumn ] ), array( 'wpid' => $rslt['id'] ) );
					}
				}

				$suppress = $this->wpdb->suppress_errors();
				$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN ' . $wpSyncColumn );
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
				$rslts = $this->wpdb->get_results( 'SELECT id,' . $optionColumn . ' FROM ' . $this->table_name( 'wp' ), ARRAY_A );
				if ( empty( $rslts ) ) {
					continue;
				}

				foreach ( $rslts as $rslt ) {
					self::update_website_option( (object) $rslt, $optionColumn, $rslt[ $optionColumn ] );
				}

				$suppress = $this->wpdb->suppress_errors();
				$this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' DROP COLUMN ' . $optionColumn );
				$this->wpdb->suppress_errors( $suppress );
			}
		}
	}
}
