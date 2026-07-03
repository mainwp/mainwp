<?php
/**
 * MainWP Install
 *
 * This file handles install MainWP DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Class MainWP_Install
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_DB_Base
 */
class MainWP_Install extends MainWP_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- unprepared SQL ok, table and column identifiers cannot be prepared and are escaped via esc_sql.

    /**
     * Private variable to hold the database version info.
     *
     * @var string DB version info.
     */
    protected $mainwp_db_version = '9.0.2.18'; // NOSONAR - no IP. Bumped so dbDelta adds wp_backup_snapshot.woo_meta, the Woo order-storage facts a database-bearing backup captured at dump time, read by the restore initiate handler's preserve-orders gate. Previous 9.0.2.17 bump added wp_backup.backup_notices, the informational-notes channel that keeps run notes (like the large-site ZIP auto-switch) out of backup_errors; 9.0.2.16 added wp_backup.zip_force_override for the large-site ZIP auto-switch override; 9.0.2.15 added wp_backup_snapshot.test_restore_state/test_restore_at for the verified-restore (sandbox test-restore) result; 9.0.2.14 added wp_backup_snapshot.encryption for the per-snapshot archive-encryption metadata.

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
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        static::$instance->test_connection();

        return static::$instance;
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
    public function install() { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        // get_site_option is multisite aware!
        $currentVersion = get_site_option( $this->option_db_key );

        if ( empty( $currentVersion ) ) {
            update_option( 'mainwp_run_quick_setup', 'yes' );
            MainWP_Utility::update_option( 'mainwp_enableNativeBackups', 0 );
        } elseif ( false === get_option( 'mainwp_enableNativeBackups' ) && false === get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            // An existing install that never wrote either flag predates the option entirely; keep the feature on for it.
            MainWP_Utility::update_option( 'mainwp_enableNativeBackups', 1 );
        }

        if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.8', '<' ) ) {
            MainWP_Utility::update_option( 'mainwp_selected_theme', 'default' );
        }

        $wp_table = esc_sql( $this->table_name( 'wp' ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql.
        $rslt = static::instance()->query( "SHOW TABLES LIKE '{$wp_table}'" );
        if ( empty( static::num_rows( $rslt ) ) ) {
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
  disable_health_check tinyint(1) NOT NULL DEFAULT 0,
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
  primary_backup_method varchar(64) NOT NULL DEFAULT '',
  http_user text NOT NULL DEFAULT '',
  http_pass text NOT NULL DEFAULT '',
  wpe tinyint(1) NOT NULL,
  is_staging tinyint(1) NOT NULL DEFAULT 0,
  client_id int(11) NOT NULL DEFAULT 0,
  `suspended` tinyint(1) NOT NULL DEFAULT 0,
  KEY idx_wp_staging_name_id (is_staging, name(191), id),
  KEY idx_userid (userid),
  KEY idx_client_id (client_id),
  KEY idx_url (url(191))";

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
  KEY idx_options_wpid_name (wpid, name(191)),
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
  ignored_wp_upgrades longtext NOT NULL DEFAULT '',
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

        $tbl = 'CREATE TABLE ' . $this->table_name( 'lookup_item_objects' ) . ' (
    lookup_id bigint(20) unsigned NOT NULL auto_increment,
    item_id bigint(20) unsigned NOT NULL,
    item_name varchar(32) NOT NULL,
    object_id bigint(20) unsigned NOT NULL,
    object_name varchar(32) NOT NULL,
    KEY item_id (item_id),
    KEY object_id (object_id)';

        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.0.5', '<' ) ) { // NOSONAR - none IP.
            $tbl .= ',
    PRIMARY KEY  (lookup_id)  ';
        }
        $tbl  .= ') ' . $charset_collate . ';';
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
  busy_since int(11) NOT NULL DEFAULT 0,
  child_backup_task_id varchar(64) NOT NULL DEFAULT '',
  child_phase varchar(20) NOT NULL DEFAULT '',
  bytes_downloaded bigint(20) NOT NULL DEFAULT 0,
  bytes_expected bigint(20) NOT NULL DEFAULT 0,
  current_file varchar(255) NOT NULL DEFAULT '',
  files_processed int(11) NOT NULL DEFAULT 0,
  files_total int(11) NOT NULL DEFAULT 0,
  KEY idx_task_id (task_id)";
        if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.53', '<=' ) ) {
            $tbl .= ',
            UNIQUE (task_id)';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        $tbl  = 'CREATE TABLE ' . $this->table_name( 'wp_backup' ) . ' (
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
        $tbl .= ",
  destinations text NOT NULL DEFAULT '',
  interval_hours smallint(5) unsigned NOT NULL DEFAULT 0,
  time_of_day varchar(5) NOT NULL DEFAULT '',
  day_of_week tinyint(1) unsigned NOT NULL DEFAULT 0,
  day_of_month tinyint(2) unsigned NOT NULL DEFAULT 1,
  next_run int(11) NOT NULL DEFAULT 0,
  retention_count int(11) NOT NULL DEFAULT 0,
  retention_days int(11) NOT NULL DEFAULT 0,
  retention_storage_mb int(11) NOT NULL DEFAULT 0,
  delete_cloud_copies tinyint(1) NOT NULL DEFAULT 1,
  max_chain_length int(11) NOT NULL DEFAULT 0,
  zip_force_override tinyint(1) NOT NULL DEFAULT 0,
  backup_notices text NOT NULL DEFAULT '',
  KEY idx_next_run (next_run)";
        if ( empty( $currentVersion ) ) {
            $tbl .= ',
  PRIMARY KEY  (id)  ';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->table_name( 'wp_backup_snapshot' ) . " (
  id bigint(20) unsigned NOT NULL auto_increment,
  task_id int(11) NOT NULL,
  site_id int(11) NOT NULL,
  type varchar(20) NOT NULL,
  parent_id bigint(20) unsigned NULL DEFAULT NULL,
  created int(11) NOT NULL DEFAULT 0,
  archives text NOT NULL DEFAULT '',
  manifest_path text NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'complete',
  offsite_state varchar(20) NOT NULL DEFAULT '',
  verified_at int(11) NOT NULL DEFAULT 0,
  encryption text NOT NULL DEFAULT '',
  woo_meta text NOT NULL DEFAULT '',
  test_restore_state varchar(20) NOT NULL DEFAULT '',
  test_restore_at int(11) NOT NULL DEFAULT 0,
  KEY idx_site_task (site_id, task_id),
  KEY idx_parent (parent_id),
  KEY idx_offsite_state (offsite_state),
  KEY idx_test_restore_state (test_restore_state)";
        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.2.8', '<' ) ) { // NOSONAR - none IP.
            $tbl .= ',
  PRIMARY KEY  (id)  ';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->table_name( 'wp_backup_storage' ) . " (
  id int(11) NOT NULL auto_increment,
  provider varchar(32) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  settings longtext NOT NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created int(11) NOT NULL DEFAULT 0,
  updated int(11) NOT NULL DEFAULT 0,
  KEY idx_provider (provider)";
        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.2.9', '<' ) ) { // NOSONAR - none IP.
            $tbl .= ',
  PRIMARY KEY  (id)  ';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->table_name( 'wp_backup_upload_job' ) . " (
  id bigint(20) unsigned NOT NULL auto_increment,
  snapshot_id bigint(20) unsigned NOT NULL,
  destination_id int(11) NOT NULL,
  kind varchar(10) NOT NULL DEFAULT 'upload',
  provider varchar(20) NOT NULL DEFAULT '',
  status varchar(32) NOT NULL DEFAULT 'pending',
  attempt_count int(11) NOT NULL DEFAULT 0,
  next_retry_at int(11) NOT NULL DEFAULT 0,
  locked_until int(11) NOT NULL DEFAULT 0,
  locked_by varchar(64) NOT NULL DEFAULT '',
  checkpoint longtext NOT NULL,
  last_error text NOT NULL DEFAULT '',
  created int(11) NOT NULL DEFAULT 0,
  updated int(11) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_snap_dest_kind (snapshot_id, destination_id, kind),
  KEY idx_status_retry (status, next_retry_at)";
        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.2.9', '<' ) ) { // NOSONAR - none IP.
            $tbl .= ',
  PRIMARY KEY  (id)  ';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->table_name( 'wp_backup_restore_job' ) . " (
  id bigint(20) unsigned NOT NULL auto_increment,
  site_id int(11) NOT NULL,
  snapshot_id bigint(20) unsigned NOT NULL,
  status varchar(32) NOT NULL DEFAULT 'pending',
  child_task_id varchar(64) NOT NULL DEFAULT '',
  pre_snapshot_task_id varchar(64) NOT NULL DEFAULT '',
  checkpoint longtext NOT NULL,
  last_error text NOT NULL DEFAULT '',
  attempt_count int(11) NOT NULL DEFAULT 0,
  next_retry_at int(11) NOT NULL DEFAULT 0,
  locked_until int(11) NOT NULL DEFAULT 0,
  locked_by varchar(64) NOT NULL DEFAULT '',
  created int(11) NOT NULL DEFAULT 0,
  updated int(11) NOT NULL DEFAULT 0,
  KEY idx_site (site_id),
  KEY idx_status_retry (status, next_retry_at)";
        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.2.11', '<' ) ) { // NOSONAR - none IP.
            $tbl .= ',
  PRIMARY KEY  (id)  ';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->table_name( 'api_keys' ) . ' (
    key_id bigint(20) unsigned NOT NULL auto_increment,
    user_id bigint(20) unsigned NOT NULL,
    description varchar(200) NULL,
    permissions varchar(10) NOT NULL,
    consumer_key char(64) NOT NULL,
    consumer_secret varchar(255) NOT NULL,
    nonces longtext NULL,
    truncated_key char(7) NOT NULL,
    key_pass char(64) NOT NULL DEFAULT "",
    key_type tinyint(1) NOT NULL DEFAULT 0,
    `enabled` tinyint(1) DEFAULT 0,
    last_access datetime NULL default null,
    KEY consumer_key (consumer_key)';
        if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.0.9', '<=' ) ) { // NOSONAR - none IP.
            $tbl .= ',
    PRIMARY KEY  (key_id)  ';
        }
        $tbl  .= ') ' . $charset_collate . ';';
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

        $tbl = 'CREATE TABLE ' . $this->table_name( 'schedule_processes' ) . " (
    process_id int(11) NOT NULL auto_increment,
    item_id int(11) NOT NULL,
    `type` varchar(32) NOT NULL,
    `process_slug` varchar(64) NOT NULL,
    `status` varchar(32) NOT NULL DEFAULT '',
    dts_process_start int(11) NOT NULL DEFAULT 0,
    dts_process_init_time int(11) NOT NULL DEFAULT 0,
    dts_process_stop int(11) NOT NULL DEFAULT 0";

    if ( empty( $currentVersion ) || version_compare( $currentVersion, '9.0.0.45', '<' ) ) { //phpcs:ignore -- NOSONAR - no ip.
            $tbl .= ',
    PRIMARY KEY  (process_id)  ';
        }

        $tbl  .= ') ' . $charset_collate . ';';
        $sql[] = $tbl;

        // End of tables.

        $sql = apply_filters( 'mainwp_db_install_tables', $sql, $currentVersion, $charset_collate );

        MainWP_DB_Uptime_Monitoring::instance()->get_db_schema( $sql, $currentVersion );

        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WP compatible.

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

        do_action( 'mainwp_db_after_update', $currentVersion, $this->mainwp_db_version ); // new version: $this->mainwp_db_version.

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
    public function post_update() { // phpcs:ignore -- NOSONAR - complex.

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
            $wp_table   = esc_sql( $this->table_name( 'wp' ) );
            foreach ( $sslColumns as $col ) {
                $col = esc_sql( $col );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_table} DROP COLUMN {$col}" );
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
                $rankColumn = esc_sql( $rankColumn );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_table} DROP COLUMN {$rankColumn}" );
            }

            $syncColumns   = array( 'uptodate' );
            $wp_sync_table = esc_sql( $this->table_name( 'wp_sync' ) );
            foreach ( $syncColumns as $column ) {
                $column = esc_sql( $column );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_sync_table} DROP COLUMN {$column}" );
            }
        }

        // delete old columns.
        if ( version_compare( $currentVersion, '8.35', '<' ) ) {
            $delColumns = array( 'offline_checks' );
            foreach ( $delColumns as $column ) {
                $column = esc_sql( $column );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_table} DROP COLUMN {$column}" );
            }
            $delColumns  = array( 'heatMap' );
            $users_table = esc_sql( $this->table_name( 'users' ) );
            foreach ( $delColumns as $column ) {
                $column = esc_sql( $column );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$users_table} DROP COLUMN {$column}" );
            }
        }

        // delete columns.
        if ( version_compare( $currentVersion, '8.42', '<' ) ) {
            $delColumns = array( 'offlineChecksOnlineNotification' );
            foreach ( $delColumns as $column ) {
                $column = esc_sql( $column );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$users_table} DROP COLUMN {$column}" );
            }
        }

        // fix missing PRIMARY keys.
        if ( version_compare( $currentVersion, '8.53', '<=' ) ) {
            $wp_options_table         = esc_sql( $this->table_name( 'wp_options' ) );
            $wp_settings_backup_table = esc_sql( $this->table_name( 'wp_settings_backup' ) );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql.
            $this->wpdb->query( "ALTER TABLE {$wp_options_table} ADD opt_id int NOT NULL AUTO_INCREMENT PRIMARY KEY" );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql.
            $this->wpdb->query( "ALTER TABLE {$wp_settings_backup_table} ADD set_id int NOT NULL AUTO_INCREMENT PRIMARY KEY" );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql.
            $this->wpdb->query( "ALTER TABLE {$wp_sync_table} ADD sync_id int NOT NULL AUTO_INCREMENT PRIMARY KEY" );
        }

        $this->update_optimize_indexes_55( $currentVersion );

        // dbDelta index additions on existing tables are unreliable, so the
        // next_run index and the widened upload job unique key are added
        // explicitly (same approach as update_optimize_indexes_55). dbDelta has
        // already added the columns these indexes cover by the time this runs.
        if ( version_compare( $currentVersion, '9.0.2.10', '<' ) ) { // NOSONAR - none IP.
            $backup_table = esc_sql( $this->table_name( 'wp_backup' ) );
            $has_index    = $this->wpdb->get_results( "SHOW INDEX FROM {$backup_table} WHERE Key_name = 'idx_next_run'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
            if ( empty( $has_index ) ) {
                $this->wpdb->query( "ALTER TABLE {$backup_table} ADD INDEX idx_next_run (next_run)" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table name escaped via esc_sql.
            }

            $upload_job_table = esc_sql( $this->table_name( 'wp_backup_upload_job' ) );
            $has_index        = $this->wpdb->get_results( "SHOW INDEX FROM {$upload_job_table} WHERE Key_name = 'uniq_snap_dest_kind'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
            if ( empty( $has_index ) ) {
                $this->wpdb->query( "ALTER TABLE {$upload_job_table} ADD UNIQUE KEY uniq_snap_dest_kind (snapshot_id, destination_id, kind)" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table name escaped via esc_sql.
            }
        }

        // Rename the backups feature flag to mainwp_enableNativeBackups; reads fall back to the
        // old name until this has run (see MainWP_Backup_Settings::is_native_backups_enabled()).
        if ( version_compare( $currentVersion, '9.0.2.6', '<' ) ) { // NOSONAR - no ip.
            $old_flag = get_option( 'mainwp_enableLegacyBackupFeature', false );
            if ( false !== $old_flag ) {
                if ( false === get_option( 'mainwp_enableNativeBackups', false ) ) {
                    MainWP_Utility::update_option( 'mainwp_enableNativeBackups', (int) $old_flag );
                }
                delete_option( 'mainwp_enableLegacyBackupFeature' );
            }
        }

        $this->wpdb->suppress_errors( $suppress );
        MainWP_DB_Client::instance()->check_to_updates_reports_data_861( $currentVersion );
    }

    /**
     * Handle optimize tables indexes.
     *
     * @param string $current_ver Current DB version.
     *
     * @return void
     */
    public function update_optimize_indexes_55( $current_ver ) {
        if ( ! empty( $current_ver ) && version_compare( $current_ver, '9.0.1.1', '<' ) ) { // NOSONAR - no ip.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' ADD INDEX idx_wp_staging_name_id (is_staging, name(191), id)' ); //phpcs:ignore -- ok.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_options' ) . ' ADD INDEX KEY idx_options_wpid_name (wpid, name(191))' ); //phpcs:ignore -- ok.
        }
        if ( ! empty( $current_ver ) && version_compare( $current_ver, '9.0.1.3', '<' ) ) { // NOSONAR - no ip.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' ADD INDEX idx_userid (userid)' ); //phpcs:ignore -- ok.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' ADD INDEX idx_client_id (client_id)' ); //phpcs:ignore -- ok.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp' ) . ' ADD INDEX idx_url (url(191))' ); //phpcs:ignore -- ok.
        }
        // MWP-1540: widen consumer_secret so wp_hash_password output (variable length,
        // typically 34 chars for $P$ but up to ~150 for argon2) fits without truncation,
        // and drop the unused KEY consumer_secret index (lookups are by HMAC'd consumer_key,
        // never by consumer_secret). dbDelta does not reliably MODIFY existing column types
        // or DROP indexes, so both changes are applied explicitly here.
        if ( ! empty( $current_ver ) && version_compare( $current_ver, '9.0.1.5', '<' ) ) { // NOSONAR - no ip.
            $api_keys_table = $this->table_name( 'api_keys' );
            // Drop the unused index FIRST so the column type change is unambiguous.
            $existing_indexes = $this->wpdb->get_col( "SHOW INDEX FROM {$api_keys_table} WHERE Key_name = 'consumer_secret'", 2 ); // phpcs:ignore -- table name is internal.
            if ( ! empty( $existing_indexes ) ) {
                $this->wpdb->query( "ALTER TABLE {$api_keys_table} DROP INDEX consumer_secret" ); // phpcs:ignore -- table name is internal.
            }
            $this->wpdb->query( "ALTER TABLE {$api_keys_table} MODIFY COLUMN consumer_secret varchar(255) NOT NULL" ); // phpcs:ignore -- table name is internal.

            // Confirm the MODIFY actually took effect before we trust this migration.
            // WP 6.5+ produces bcrypt hashes around 60 characters, so a silent failure
            // would leave the column at char(43) and truncate every freshly hashed
            // secret on insert, quietly breaking auth on any newly created key. On a
            // width mismatch we set a flag option that the dashboard surfaces as an
            // admin notice; mainwp_notice_wp_mail_failed in class-mainwp-system.php
            // uses the same shape.
            $col_def = $this->wpdb->get_row( "SHOW COLUMNS FROM {$api_keys_table} LIKE 'consumer_secret'" ); // phpcs:ignore -- table name is internal.
            if ( empty( $col_def ) || false === stripos( (string) $col_def->Type, 'varchar(255)' ) ) {
                update_option( 'mainwp_notice_consumer_secret_migration_failed', current_time( 'mysql' ) );
            } else {
                delete_option( 'mainwp_notice_consumer_secret_migration_failed' );
            }
        }
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
            $wp_table         = esc_sql( $this->table_name( 'wp' ) );
            $existing_columns = $this->wpdb->get_col( "SHOW COLUMNS FROM {$wp_table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name is a hardcoded internal identifier escaped via esc_sql(), no user input involved.
            foreach ( array( 'backups', 'note_lastupdate', 'pages' ) as $column ) {
                if ( in_array( $column, $existing_columns, true ) ) {
                    $this->wpdb->query( 'ALTER TABLE ' . $wp_table . ' DROP COLUMN ' . esc_sql( $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table and column names are hardcoded internal identifiers escaped via esc_sql(), no user input involved.
                }
            }
        }

        // The upload job unique key widens to (snapshot_id, destination_id, kind)
        // so a cloud delete job can coexist with the original upload row. dbDelta
        // cannot DROP an index, so the narrow key goes here before dbDelta adds
        // the widened one.
        if ( version_compare( $currentVersion, '9.0.2.10', '<' ) ) { // NOSONAR - none IP.
            $upload_job_table = esc_sql( $this->table_name( 'wp_backup_upload_job' ) );
            $table_found      = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $upload_job_table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table probe.
            if ( ! empty( $table_found ) ) {
                $old_key = $this->wpdb->get_results( "SHOW INDEX FROM {$upload_job_table} WHERE Key_name = 'uniq_snap_dest'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
                if ( ! empty( $old_key ) ) {
                    $this->wpdb->query( "ALTER TABLE {$upload_job_table} DROP INDEX uniq_snap_dest" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table name escaped via esc_sql.
                }
            }
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
    public function post_update_81( $current_version ) { //phpcs:ignore -- NOSONAR - complex.

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
            $wp_table      = esc_sql( $this->table_name( 'wp' ) );
            $wp_sync_table = esc_sql( $this->table_name( 'wp_sync' ) );
            foreach ( $wpSyncColumns as $wpSyncColumn ) {
                $wpSyncColumn = esc_sql( $wpSyncColumn );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $rslts = $this->wpdb->get_results( "SELECT id,{$wpSyncColumn} FROM {$wp_table}", ARRAY_A );
                if ( empty( $rslts ) ) {
                    continue;
                }

                foreach ( $rslts as $rslt ) {
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql and query uses proper prepare.
                    $exists = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT wpid FROM {$wp_sync_table} WHERE wpid = %d", $rslt['id'] ), ARRAY_A );
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
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_table} DROP COLUMN {$wpSyncColumn}" );
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
                $optionColumn = esc_sql( $optionColumn );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $rslts = $this->wpdb->get_results( "SELECT id,{$optionColumn} FROM {$wp_table}", ARRAY_A );
                if ( empty( $rslts ) ) {
                    continue;
                }

                foreach ( $rslts as $rslt ) {
                    static::update_website_option( (object) $rslt, $optionColumn, $rslt[ $optionColumn ] );
                }

                $suppress = $this->wpdb->suppress_errors();
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_table} DROP COLUMN {$optionColumn}" );
                $this->wpdb->suppress_errors( $suppress );
            }
        }
    }
}
