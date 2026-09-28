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

    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

    /**
     * Private variable to hold the database version info.
     *
     * @var string DB version info.
     */
    protected $mainwp_db_version = '9.0.2.5'; // NOSONAR - no IP. 9.0.2.4 drops the stray unique index on backup progress task_id. Bumped for MWP-1566 sibling-dir chmod migration (MWP-1558 follow-up). Original 9.0.2.0 bump for MWP-1557/1558.

    /**
     * Protected variable to hold the database option name.
     *
     * @var string DB version info.
     */
    protected $option_db_key = 'mainwp_db_version';

    /**
     * Network-scoped like mainwp_db_version, so every blog on a multisite sees the pending repair.
     */
    const BACKUP_PROGRESS_INDEX_REPAIR_PENDING = 'mainwp_backup_progress_index_repair_pending';

    /**
     * Network-scoped like mainwp_db_version, so every blog on a multisite sees the pending repair.
     */
    const WP_GROUP_MISSING_PRIMARY_KEY_REPAIR_PENDING = 'mainwp_wp_group_missing_primary_key_repair_pending';

    /**
     * Autoloaded option. "complete" means the site-note slash repair finished.
     *
     * Read on admin_init. Any other value means the repair still has to run.
     * This is not a database version, so it does not collide with other migrations.
     */
    const SITE_NOTE_SLASH_REPAIR_OPTION = 'mainwp_site_note_slash_repair';

    /**
     * Last site id that was fully handled. Autoload is off. Saved after each row, not each batch.
     */
    const SITE_NOTE_SLASH_REPAIR_LAST_ID = 'mainwp_site_note_slash_repair_last_id';

    /**
     * Highest site id that existed when the repair started. Newer rows are left alone.
     */
    const SITE_NOTE_SLASH_REPAIR_MAX_ID = 'mainwp_site_note_slash_repair_max_id';

    /**
     * Prefix for one skip row per site. Autoload is off. A save during the repair inserts its own row.
     */
    const SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX = 'mainwp_site_note_slash_repair_skip_';

    /**
     * Row being repaired. Holds the id and the note value read before the update.
     */
    const SITE_NOTE_SLASH_REPAIR_INFLIGHT = 'mainwp_site_note_slash_repair_inflight';

    /**
     * Transient that keeps a failed repair from running again for an hour.
     */
    const SITE_NOTE_SLASH_REPAIR_RETRY = 'mainwp_site_note_slash_repair_retry';

    /**
     * Lock row so two admin requests cannot repair the same notes at once. Autoload is off.
     */
    const SITE_NOTE_SLASH_REPAIR_LOCK = 'mainwp_site_note_slash_repair_lock';

    /**
     * Seconds a crashed request may hold the repair lock.
     */
    const SITE_NOTE_SLASH_REPAIR_LOCK_TTL = 300;

    /**
     * Site rows read per batch. Tests may lower this through a filter.
     */
    const SITE_NOTE_SLASH_REPAIR_BATCH = 100;

    /**
     * Seconds an admin page load may spend in the repair before the next request resumes.
     */
    const SITE_NOTE_SLASH_REPAIR_TIME_BUDGET = 3;

    /**
     * Characters mysqli_real_escape_string may place after a backslash.
     */
    const SITE_NOTE_SLASH_REPAIR_ESCAPED = "\\'\"nr0Z";

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * When the current repair request started, used for the time budget.
     *
     * @var float
     */
    private $site_note_repair_started_at = 0.0;

    /**
     * True when this request stopped because the time budget ran out.
     *
     * @var bool
     */
    private $site_note_repair_paused = false;

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
        // An empty version is the new-install path. Mark the note repair done at the end without scanning.
        $fresh_install = empty( $currentVersion );

        if ( empty( $currentVersion ) ) {
            update_option( 'mainwp_run_quick_setup', 'yes' );
            MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 0 );
        } elseif ( false === get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 1 );
        }

        if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.8', '<' ) ) {
            MainWP_Utility::update_option( 'mainwp_selected_theme', 'default' );
        }

        $wp_table = esc_sql( $this->table_name( 'wp' ) );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql.
        $rslt = static::instance()->query( "SHOW TABLES LIKE '{$wp_table}'" );
        if ( empty( static::num_rows( $rslt ) ) ) {
            $currentVersion = false;
            $fresh_install  = true;
        }

        if ( $currentVersion === $this->mainwp_db_version ) {
            // The index repair keeps its own marker so a failed DROP is retried at
            // most hourly, without holding the DB version back and re-running every
            // older migration on each load.
            $this->maybe_retry_backup_progress_index_repair();

            $this->maybe_retry_repair_wp_group_primary_key();
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
  KEY idx_groupid (groupid),
  PRIMARY KEY (wp_group_id)
  ) ' . $charset_collate;

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

        $tbl   = 'CREATE TABLE ' . $this->table_name( 'wp_backup_progress' ) . " (
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

        // No legacy notes exist on a new install. Skip the row scan.
        if ( $fresh_install ) {
            $this->mark_site_note_slash_repair_complete();
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
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
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
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_table} DROP COLUMN {$rankColumn}" );
            }

            $syncColumns   = array( 'uptodate' );
            $wp_sync_table = esc_sql( $this->table_name( 'wp_sync' ) );
            foreach ( $syncColumns as $column ) {
                $column = esc_sql( $column );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_sync_table} DROP COLUMN {$column}" );
            }
        }

        // delete old columns.
        if ( version_compare( $currentVersion, '8.35', '<' ) ) {
            $delColumns = array( 'offline_checks' );
            foreach ( $delColumns as $column ) {
                $column = esc_sql( $column );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_table} DROP COLUMN {$column}" );
            }
            $delColumns  = array( 'heatMap' );
            $users_table = esc_sql( $this->table_name( 'users' ) );
            foreach ( $delColumns as $column ) {
                $column = esc_sql( $column );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$users_table} DROP COLUMN {$column}" );
            }
        }

        // delete columns.
        if ( version_compare( $currentVersion, '8.42', '<' ) ) {
            $delColumns = array( 'offlineChecksOnlineNotification' );
            foreach ( $delColumns as $column ) {
                $column = esc_sql( $column );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$users_table} DROP COLUMN {$column}" );
            }
        }

        // fix missing PRIMARY keys.
        if ( version_compare( $currentVersion, '8.53', '<=' ) ) {
            $wp_options_table         = esc_sql( $this->table_name( 'wp_options' ) );
            $wp_settings_backup_table = esc_sql( $this->table_name( 'wp_settings_backup' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql.
            $this->wpdb->query( "ALTER TABLE {$wp_options_table} ADD opt_id int NOT NULL AUTO_INCREMENT PRIMARY KEY" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql.
            $this->wpdb->query( "ALTER TABLE {$wp_settings_backup_table} ADD set_id int NOT NULL AUTO_INCREMENT PRIMARY KEY" );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql.
            $this->wpdb->query( "ALTER TABLE {$wp_sync_table} ADD sync_id int NOT NULL AUTO_INCREMENT PRIMARY KEY" );
        }

        $this->update_optimize_indexes_55( $currentVersion );

        // dbDelta never drops an index, so the stray unique key needs an explicit migration.
        if ( version_compare( $currentVersion, '9.0.2.4', '<' ) ) { // NOSONAR - no ip.
            $this->repair_backup_progress_index();
        }

        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '9.0.2.5', '<' ) ) { // NOSONAR - no ip.
            $this->repair_wp_group_primary_key();
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
     * Retry a pending backup progress index repair, no more than once an hour.
     *
     * A DB user without ALTER never clears the marker, so an unthrottled retry
     * would run two SHOW INDEX plus a failing ALTER on every single request.
     *
     * @return bool True when a repair attempt was made.
     */
    public function maybe_retry_repair_wp_group_primary_key() {
        $pending = (int) get_site_option( self::WP_GROUP_MISSING_PRIMARY_KEY_REPAIR_PENDING );
        if ( empty( $pending ) || time() - $pending < HOUR_IN_SECONDS ) {
            return false;
        }

        $suppress = $this->wpdb->suppress_errors();
        $this->repair_wp_group_primary_key();
        $this->wpdb->suppress_errors( $suppress );

        return true;
    }

    /**
     * Repair the wp_group table primary key for legacy installations.
     *
     * To fix MWP-1794.
     *
     * @return void
     */
    private function repair_wp_group_primary_key() {
        $wp_group_table = esc_sql( $this->table_name( 'wp_group' ) );

        $column_exists = $this->wpdb->get_var(
            "SHOW COLUMNS FROM {$wp_group_table} LIKE 'wp_group_id'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped and query contains no user input.
        );

        if ( empty( $column_exists ) ) {
            $this->wpdb->query(
                "ALTER TABLE {$wp_group_table}
                ADD COLUMN wp_group_id int(11) NOT NULL auto_increment PRIMARY KEY FIRST" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped and query contains no user input.
            );
        } else {
            $primary_key = $this->wpdb->get_var(
                "SHOW INDEX FROM {$wp_group_table}
                WHERE Key_name = 'PRIMARY'
                AND Column_name = 'wp_group_id'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped and query contains no user input.
            );

            if ( empty( $primary_key ) ) {
                $this->wpdb->query(
                    "ALTER TABLE {$wp_group_table}
                    ADD PRIMARY KEY (wp_group_id)" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped and query contains no user input.
                );
            }
        }

        $primary_key = $this->wpdb->get_var(
            "SHOW INDEX FROM {$wp_group_table}
            WHERE Key_name = 'PRIMARY'
            AND Column_name = 'wp_group_id'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped and query contains no user input.
        );

        if ( ! empty( $primary_key ) ) {
            delete_site_option( self::WP_GROUP_MISSING_PRIMARY_KEY_REPAIR_PENDING );
        } else {
            // A timestamp, not a formatted date: the retry throttle compares it against time().
            update_site_option( self::WP_GROUP_MISSING_PRIMARY_KEY_REPAIR_PENDING, time() );
        }
    }

    /**
     * Run the site-note slash repair unless it is already complete.
     *
     * The option is autoloaded, so the usual admin request only reads memory and returns.
     *
     * @return void
     */
    public function maybe_repair_site_note_slashes() {
        if ( 'complete' === get_option( self::SITE_NOTE_SLASH_REPAIR_OPTION ) ) {
            return;
        }

        // Heartbeat is an admin-ajax request. Only a normal admin page load should repair.
        if ( $this->site_note_slash_repair_request_is_background() ) {
            return;
        }

        // admin-ajax.php fires admin_init for logged-out callers. Do not repair for them.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( false !== get_transient( self::SITE_NOTE_SLASH_REPAIR_RETRY ) ) {
            return;
        }

        $this->repair_site_note_slashes();
    }

    /**
     * Remember a site whose note was saved while the repair is still open.
     *
     * A quote-free value such as C:\new matches the old escape alphabet, so the
     * repair must not treat that new write as a legacy row.
     *
     * @param int $website_id Site id.
     *
     * @return bool True when the note write may proceed.
     */
    public function note_saved_during_site_note_slash_repair( $website_id ) {
        if ( 'complete' === get_option( self::SITE_NOTE_SLASH_REPAIR_OPTION ) ) {
            return true;
        }

        $website_id = (int) $website_id;
        if ( $website_id < 1 ) {
            return true;
        }

        // One row per site. A second save of another site cannot overwrite this insert.
        $option   = self::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX . $website_id;
        $suppress = $this->wpdb->suppress_errors( true );
        $inserted = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- INSERT IGNORE is the per-site skip marker.
            $this->wpdb->prepare(
                'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
                $this->wpdb->options,
                $option,
                '1',
                'no'
            )
        );
        $failed   = ( false === $inserted );
        $this->wpdb->suppress_errors( $suppress );
        $this->wpdb->last_error = '';
        if ( $failed ) {
            return false;
        }

        $this->forget_site_note_repair_option_cache( $option );
        $present = $this->site_note_slash_repair_skips_id( $website_id );
        if ( true === $present ) {
            // The row was inserted after the flag was already complete. Drop this id only.
            if ( 'complete' === $this->read_site_note_slash_repair_flag() ) {
                $this->delete_one_site_note_slash_repair_skip_row( $website_id );
            }
            return true;
        }

        if ( null === $present ) {
            return false;
        }

        // The completion DELETE can land between INSERT IGNORE and the read-back.
        // The insert succeeded, or the row already existed. Write the note when the
        // repair is finished, and do not drop the save when the insert itself worked.
        if ( 'complete' === $this->read_site_note_slash_repair_flag() ) {
            return true;
        }

        return false !== $inserted;
    }

    /**
     * Reverse the extra slash layer that update_note() used to store.
     *
     * The old write passed esc_sql() into prepare(), and MySQL kept one escape layer.
     * This walks that layer back. It does not follow the database version, because other
     * open migrations already claim the next version numbers.
     *
     * @return void
     */
    public function repair_site_note_slashes() {
        $previous_started                  = $this->site_note_repair_started_at;
        $previous_paused                   = $this->site_note_repair_paused;
        $this->site_note_repair_started_at = microtime( true );
        $this->site_note_repair_paused     = false;

        try {
            $this->run_site_note_slash_repair();
        } finally {
            $this->site_note_repair_started_at = $previous_started;
            $this->site_note_repair_paused     = $previous_paused;
        }
    }

    /**
     * Hold the lock, repair each batch, and release it.
     *
     * A nested call saves and restores the time-budget clock so it cannot clear a pause
     * the outer request still has to honor.
     *
     * @return void
     */
    private function run_site_note_slash_repair() {
        $token = $this->acquire_site_note_slash_repair_lock();
        if ( false === $token ) {
            return;
        }

        // Read past the alloptions cache. The other request may have just set the flag.
        if ( 'complete' === $this->read_site_note_slash_repair_flag() ) {
            $this->release_site_note_slash_repair_lock( $token );
            return;
        }

        $finished = $this->repair_site_note_slash_batches( $token );

        // The lock was taken over. Leave that holder's row and do not throttle the next request.
        if ( ! $this->site_note_slash_repair_lock_is_held( $token ) ) {
            return;
        }

        // The time budget ran out. The last id stays, and the next eligible request resumes.
        if ( $this->site_note_repair_paused ) {
            $this->release_site_note_slash_repair_lock( $token );
            return;
        }

        if ( $finished && $this->mark_site_note_slash_repair_complete() ) {
            $this->release_site_note_slash_repair_lock( $token );
            return;
        }

        // The flag is still incomplete and the last id is left where the last good row stopped.
        set_transient( self::SITE_NOTE_SLASH_REPAIR_RETRY, time(), HOUR_IN_SECONDS );
        $this->release_site_note_slash_repair_lock( $token );
    }

    /**
     * Repair notes up to the id recorded at the start, checkpointing every finished row.
     *
     * A quote-free note such as C:\new still matches the old escape alphabet after one
     * pass. Saving the last id before the next row is read keeps a retry from undoing it.
     *
     * @param string $token Lock token owned by this request.
     *
     * @return bool True when every row was read and every needed update succeeded.
     */
    private function repair_site_note_slash_batches( $token ) {
        $table  = $this->table_name( 'wp' );
        $batch  = (int) apply_filters( 'mainwp_site_note_slash_repair_batch_size', self::SITE_NOTE_SLASH_REPAIR_BATCH );
        $max_id = $this->ensure_site_note_slash_repair_max_id( $table );

        if ( false === $max_id ) {
            return false;
        }

        if ( $batch < 1 ) {
            $batch = self::SITE_NOTE_SLASH_REPAIR_BATCH;
        }

        // Finish a row whose update landed and whose finished id did not.
        if ( ! $this->refresh_site_note_slash_repair_lock( $token ) ) {
            return false;
        }
        if ( ! $this->resume_site_note_slash_repair_inflight( $table, $token ) ) {
            return false;
        }

        // Read the checkpoint from the database. A cached 0 would scan finished rows again.
        $last_id = $this->read_site_note_slash_repair_last_id();
        if ( false === $last_id ) {
            return false;
        }

        while ( true ) {
            if ( $this->site_note_slash_repair_time_exceeded() ) {
                return false;
            }
            if ( ! $this->refresh_site_note_slash_repair_lock( $token ) ) {
                return false;
            }

            $sql     = $this->wpdb->prepare(
                'SELECT id, note FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d',
                $table,
                $last_id,
                $max_id,
                $batch
            );
            $queried = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Batched repair read; failure must stay visible to the caller.
            if ( false === $queried || '' !== (string) $this->wpdb->last_error ) {
                return false;
            }

            $rows = $this->wpdb->last_result;
            if ( ! is_array( $rows ) || empty( $rows ) ) {
                return true;
            }

            foreach ( $rows as $row ) {
                if ( $this->site_note_slash_repair_time_exceeded() ) {
                    return false;
                }
                if ( ! $this->repair_one_site_note_row( $table, $row, $token ) ) {
                    return false;
                }
                $last_id = (int) $row->id;
            }

            // Unchanged rows share one checkpoint at the end of the batch.
            if ( ! $this->save_site_note_slash_repair_last_id( $last_id ) ) {
                return false;
            }
        }
    }

    /**
     * Repair one note. The in-progress marker is stored before the update.
     *
     * A crash after the update and before the finished id leaves that marker.
     * The next run updates the row only while its note is still the value that was read.
     *
     * @param string $table Raw site table name. The %i placeholder quotes it.
     * @param object $row   Row with id and note.
     * @param string $token Lock token owned by this request.
     *
     * @return bool False when the update or the checkpoint failed.
     */
    private function repair_one_site_note_row( $table, $row, $token ) {
        $id      = (int) $row->id;
        $note    = (string) $row->note;
        $armed   = false;
        $changed = false;

        if ( $this->site_note_is_legacy_escaped( $note ) ) {
            $skipped = $this->site_note_slash_repair_skips_id( $id );
            if ( null === $skipped ) {
                return false;
            }
            if ( ! $skipped ) {
                // Another request may have taken the lock after this batch was read.
                if ( ! $this->site_note_slash_repair_lock_is_held( $token ) ) {
                    return false;
                }
                if ( ! $this->mark_site_note_slash_repair_inflight( $id, $note ) ) {
                    return false;
                }
                $armed = true;
                $fixed = $this->unescape_legacy_site_note( $note );
                do_action( 'mainwp_site_note_slash_repair_before_update', $id, $note, $fixed );

                // The batch was already read. A save since then has its own skip row in the database.
                $skipped = $this->site_note_slash_repair_skips_id( $id );
                if ( null === $skipped ) {
                    return false;
                }
                if ( ! $skipped ) {
                    if ( ! $this->site_note_slash_repair_lock_is_held( $token ) ) {
                        return false;
                    }
                    if ( ! $this->update_legacy_site_note( $table, $id, $note, $fixed ) ) {
                        return false;
                    }
                    $changed = true;
                }
            }
        }

        // A row that did not change waits for the batch checkpoint.
        if ( $changed && ! $this->save_site_note_slash_repair_last_id( $id ) ) {
            return false;
        }

        if ( $armed && ! $this->clear_site_note_slash_repair_inflight( $id, $note ) ) {
            return false;
        }

        return true;
    }

    /**
     * Write one legacy note back only while the column still holds the value that was read.
     *
     * @param string $table Raw site table name. The %i placeholder quotes it.
     * @param int    $id    Site id.
     * @param string $note  Note value read before the update.
     * @param string $fixed Note with one escape layer removed.
     *
     * @return bool False when the query failed. Zero matched rows is success.
     */
    private function update_legacy_site_note( $table, $id, $note, $fixed ) {
        $updated = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare-and-swap write; zero matched rows is success.
            $this->wpdb->prepare(
                'UPDATE %i SET note=%s WHERE id=%d AND note=%s',
                $table,
                $fixed,
                $id,
                $note
            )
        );

        return false !== $updated && '' === (string) $this->wpdb->last_error;
    }

    /**
     * Finish a row that was updated and then interrupted before its finished id was stored.
     *
     * @param string $table Raw site table name. The %i placeholder quotes it.
     * @param string $token Lock token owned by this request.
     *
     * @return bool False when the re-check or the checkpoint failed.
     */
    private function resume_site_note_slash_repair_inflight( $table, $token ) {
        $inflight = $this->read_site_note_slash_repair_inflight();
        if ( false === $inflight ) {
            return false;
        }
        if ( null === $inflight ) {
            return true;
        }

        $id      = (int) $inflight['id'];
        $note    = (string) $inflight['note'];
        $skipped = $this->site_note_slash_repair_skips_id( $id );
        if ( null === $skipped ) {
            return false;
        }

        if ( ! $skipped && $this->site_note_is_legacy_escaped( $note ) ) {
            if ( ! $this->site_note_slash_repair_lock_is_held( $token ) ) {
                return false;
            }
            $fixed = $this->unescape_legacy_site_note( $note );
            if ( ! $this->update_legacy_site_note( $table, $id, $note, $fixed ) ) {
                return false;
            }
        }

        if ( ! $this->save_site_note_slash_repair_last_id( $id ) ) {
            return false;
        }

        return $this->clear_site_note_slash_repair_inflight( $id, $note );
    }

    /**
     * Whether this column value can only have been written by the old esc_sql() path.
     *
     * That escape never left a bare quote, and it only wrote a backslash before
     * backslash, quote, double quote, n, r, 0, or Z. Anything else was stored later.
     *
     * @param string $note Raw note column.
     *
     * @return bool True when the note still has a reversible escape sequence.
     */
    private function site_note_is_legacy_escaped( $note ) {
        $length     = strlen( $note );
        $saw_escape = false;

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $note[ $i ];
            if ( "'" === $char || '"' === $char ) {
                return false;
            }
            if ( '\\' !== $char ) {
                continue;
            }
            if ( $i + 1 >= $length || false === strpos( self::SITE_NOTE_SLASH_REPAIR_ESCAPED, $note[ $i + 1 ] ) ) {
                return false;
            }
            $saw_escape = true;
            ++$i;
        }

        return $saw_escape;
    }

    /**
     * Reverse one mysqli_real_escape_string layer, including real line breaks.
     *
     * The stripslashes() function turns a stored newline marker into the letter n. This walks
     * the same pairs the old writer produced and puts the original character back.
     *
     * @param string $note Legacy note column.
     *
     * @return string Note with that one escape layer removed.
     */
    private function unescape_legacy_site_note( $note ) {
        $out    = '';
        $length = strlen( $note );
        $map    = array(
            '\\' => '\\',
            "'"  => "'",
            '"'  => '"',
            'n'  => "\n",
            'r'  => "\r",
            '0'  => "\0",
            'Z'  => "\x1A",
        );

        for ( $i = 0; $i < $length; $i++ ) {
            if ( '\\' === $note[ $i ] && $i + 1 < $length && isset( $map[ $note[ $i + 1 ] ] ) ) {
                $out .= $map[ $note[ $i + 1 ] ];
                ++$i;
                continue;
            }
            $out .= $note[ $i ];
        }

        return $out;
    }

    /**
     * Take the repair lock before any note row is read.
     *
     * The add_option() function on current WordPress updates the row when the name exists, so it
     * cannot keep a second request out. A plain INSERT fails on that duplicate. An expired lock
     * is replaced only when the row still holds the value this request read.
     *
     * @return string|false Lock token, or false when another request holds it.
     */
    private function acquire_site_note_slash_repair_lock() {
        $option = self::SITE_NOTE_SLASH_REPAIR_LOCK;
        $now    = time();
        $this->forget_site_note_repair_option_cache( $option );
        $existing = get_option( $option, false );

        $token = wp_generate_password( 12, false );
        $value = $token . '|' . ( $now + self::SITE_NOTE_SLASH_REPAIR_LOCK_TTL );

        if ( ! is_string( $existing ) ) {
            if ( false !== $existing ) {
                return false;
            }
            return $this->insert_site_note_slash_repair_lock( $option, $value, $token );
        }

        $parts   = explode( '|', $existing, 2 );
        $expires = isset( $parts[1] ) ? (int) $parts[1] : 0;
        if ( $expires > $now ) {
            return false;
        }

        $taken = $this->compare_and_swap_site_note_option( $option, $existing, $value );
        if ( 1 !== $taken ) {
            return false;
        }

        $this->forget_site_note_repair_option_cache( $option );
        if ( get_option( $option ) !== $value ) {
            return false;
        }

        return $token;
    }

    /**
     * Insert the lock row. A duplicate key means another request already owns it.
     *
     * The duplicate is expected, so the database error is suppressed and cleared.
     * Otherwise WP_DEBUG_DISPLAY would print it into an admin-ajax response.
     *
     * @param string $option Option name.
     * @param string $value  Lock token and expiry.
     * @param string $token  Token to return when this request owns the row.
     *
     * @return string|false
     */
    private function insert_site_note_slash_repair_lock( $option, $value, $token ) {
        $suppress = $this->wpdb->suppress_errors( true );
        $added    = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The insert must fail when the lock row already exists.
            $this->wpdb->options,
            array(
                'option_name'  => $option,
                'option_value' => $value,
                'autoload'     => 'no',
            ),
            array( '%s', '%s', '%s' )
        );
        $this->wpdb->suppress_errors( $suppress );
        $this->wpdb->last_error = '';

        if ( false === $added ) {
            return false;
        }

        $this->forget_site_note_repair_option_cache( $option );
        if ( get_option( $option ) !== $value ) {
            return false;
        }

        return $token;
    }

    /**
     * Extend this request's lock at the start of each batch.
     *
     * @param string $token Lock token owned by this request.
     *
     * @return bool True when the row still belongs to this token.
     */
    private function refresh_site_note_slash_repair_lock( $token ) {
        $option = self::SITE_NOTE_SLASH_REPAIR_LOCK;
        $this->forget_site_note_repair_option_cache( $option );
        $existing = get_option( $option, false );
        if ( ! is_string( $existing ) ) {
            return false;
        }

        $parts = explode( '|', $existing, 2 );
        if ( ! isset( $parts[0] ) || ! hash_equals( (string) $parts[0], (string) $token ) ) {
            return false;
        }

        // MySQL reports zero affected rows when the stored value does not change.
        // A refresh in the same second must still move the expiry, or the batch stops.
        $expires = isset( $parts[1] ) ? (int) $parts[1] : 0;
        $until   = time() + self::SITE_NOTE_SLASH_REPAIR_LOCK_TTL;
        if ( $until <= $expires ) {
            $until = $expires + 1;
        }

        $value = $token . '|' . $until;
        if ( 1 !== $this->compare_and_swap_site_note_option( $option, $existing, $value ) ) {
            return false;
        }

        $this->forget_site_note_repair_option_cache( $option );
        return true;
    }

    /**
     * Replace an option only when it still holds the value that was read.
     *
     * @param string $option      Option name.
     * @param string $old         Value read before the write.
     * @param string $replacement Value to store.
     *
     * @return int Rows updated, or -1 when the query failed.
     */
    private function compare_and_swap_site_note_option( $option, $old, $replacement ) {
        $updated = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Conditional lock update.
            $this->wpdb->prepare(
                'UPDATE %i SET option_value=%s WHERE option_name=%s AND option_value=%s',
                $this->wpdb->options,
                $replacement,
                $option,
                $old
            )
        );

        if ( false === $updated || '' !== (string) $this->wpdb->last_error ) {
            return -1;
        }

        return (int) $updated;
    }

    /**
     * Release the repair lock when this request still owns it.
     *
     * @param string $token Token returned by the acquire call.
     *
     * @return void
     */
    private function release_site_note_slash_repair_lock( $token ) {
        if ( ! $this->site_note_slash_repair_lock_is_held( $token ) ) {
            return;
        }

        delete_option( self::SITE_NOTE_SLASH_REPAIR_LOCK );
    }

    /**
     * Whether the lock row still stores this request's token.
     *
     * The read is a direct query. A cached copy of our own token would delete the row
     * a later request took over.
     *
     * @param string $token Lock token owned by this request.
     *
     * @return bool True when the stored token matches.
     */
    private function site_note_slash_repair_lock_is_held( $token ) {
        $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Must not trust a cached lock token.
            $this->wpdb->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
                $this->wpdb->options,
                self::SITE_NOTE_SLASH_REPAIR_LOCK
            )
        );

        if ( '' !== (string) $this->wpdb->last_error || ! is_string( $value ) ) {
            return false;
        }

        $parts = explode( '|', $value, 2 );
        return isset( $parts[0] ) && hash_equals( (string) $parts[0], (string) $token );
    }

    /**
     * Read the last finished site id from the database.
     *
     * @return int|false Site id, 0 when the option is missing, or false when the read failed.
     */
    private function read_site_note_slash_repair_last_id() {
        $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A cached 0 would scan finished rows again.
            $this->wpdb->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
                $this->wpdb->options,
                self::SITE_NOTE_SLASH_REPAIR_LAST_ID
            )
        );

        if ( '' !== (string) $this->wpdb->last_error ) {
            return false;
        }

        if ( null === $value ) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * Whether this request has used its repair time.
     *
     * A budget of zero stops before the first row. The cap also stays inside max_execution_time
     * when PHP has set one, so a large install resumes on the next admin page load.
     *
     * @return bool True when the repair should stop without treating the stop as a failure.
     */
    private function site_note_slash_repair_time_exceeded() {
        $budget = (int) apply_filters( 'mainwp_site_note_slash_repair_time_budget', self::SITE_NOTE_SLASH_REPAIR_TIME_BUDGET );
        if ( $budget < 0 ) {
            $budget = self::SITE_NOTE_SLASH_REPAIR_TIME_BUDGET;
        }

        $limit = (int) ini_get( 'max_execution_time' );
        if ( $budget > 0 && $limit > 1 ) {
            $budget = min( $budget, $limit - 1 );
        }

        if ( ( microtime( true ) - $this->site_note_repair_started_at ) < $budget ) {
            return false;
        }

        $this->site_note_repair_paused = true;
        return true;
    }

    /**
     * Move the checkpoint forward, or insert it when the row is missing.
     *
     * A stalled request must not write an older id over the request that took over.
     *
     * @param int $last_id Site id.
     *
     * @return bool True when the stored id is this id or a later one.
     */
    private function save_site_note_slash_repair_last_id( $last_id ) {
        $option  = self::SITE_NOTE_SLASH_REPAIR_LAST_ID;
        $last_id = (int) $last_id;
        $updated = $this->forward_site_note_slash_repair_last_id( $option, $last_id );
        if ( false === $updated ) {
            return false;
        }
        if ( $updated > 0 ) {
            $this->forget_site_note_repair_option_cache( $option );
            return true;
        }

        $current = $this->read_site_note_slash_repair_last_id_row();
        if ( false === $current ) {
            return false;
        }
        if ( null !== $current ) {
            return (int) $current >= $last_id;
        }

        return $this->insert_site_note_slash_repair_last_id( $option, $last_id );
    }

    /**
     * Advance the checkpoint only while the stored id is lower.
     *
     * @param string $option  Option name.
     * @param int    $last_id Site id to store.
     *
     * @return int|false Rows updated, or false when the query failed.
     */
    private function forward_site_note_slash_repair_last_id( $option, $last_id ) {
        $updated = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Forward-only checkpoint. A lower id must not replace a higher one.
            $this->wpdb->prepare(
                'UPDATE %i SET option_value=%s WHERE option_name=%s AND CAST(option_value AS UNSIGNED) < %d',
                $this->wpdb->options,
                (string) $last_id,
                $option,
                $last_id
            )
        );

        if ( false === $updated || '' !== (string) $this->wpdb->last_error ) {
            return false;
        }

        return (int) $updated;
    }

    /**
     * Insert the checkpoint when the option row does not exist yet.
     *
     * @param string $option  Option name.
     * @param int    $last_id Site id to store.
     *
     * @return bool True when the stored id is this id or a later one.
     */
    private function insert_site_note_slash_repair_last_id( $option, $last_id ) {
        $suppress = $this->wpdb->suppress_errors( true );
        $added    = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserts the checkpoint only when the row is missing.
            $this->wpdb->options,
            array(
                'option_name'  => $option,
                'option_value' => (string) $last_id,
                'autoload'     => 'no',
            ),
            array( '%s', '%s', '%s' )
        );
        $this->wpdb->suppress_errors( $suppress );
        $this->wpdb->last_error = '';

        if ( false !== $added ) {
            $this->forget_site_note_repair_option_cache( $option );
            return true;
        }

        // Another request inserted the row first. Move it forward only.
        $updated = $this->forward_site_note_slash_repair_last_id( $option, $last_id );
        if ( false === $updated ) {
            return false;
        }
        if ( $updated > 0 ) {
            $this->forget_site_note_repair_option_cache( $option );
            return true;
        }

        $current = $this->read_site_note_slash_repair_last_id_row();
        return null !== $current && false !== $current && (int) $current >= $last_id;
    }

    /**
     * Read the checkpoint column, distinguishing a missing row from id 0.
     *
     * @return string|null|false Stored value, null when the row is missing, false on error.
     */
    private function read_site_note_slash_repair_last_id_row() {
        $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The forward-only update must see the stored id.
            $this->wpdb->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
                $this->wpdb->options,
                self::SITE_NOTE_SLASH_REPAIR_LAST_ID
            )
        );

        if ( '' !== (string) $this->wpdb->last_error ) {
            return false;
        }

        return $value;
    }

    /**
     * Whether this admin_init belongs to ajax, heartbeat, cron, or REST.
     *
     * Those requests must not spend the page-load budget. The next admin screen resumes.
     *
     * @return bool True when the repair should wait for a normal admin page load.
     */
    private function site_note_slash_repair_request_is_background() {
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return true;
        }
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return true;
        }

        return defined( 'REST_REQUEST' ) && REST_REQUEST;
    }

    /**
     * Mark the repair complete and drop the resume marker.
     *
     * The update_option() function returns false when the value is already complete, so the
     * stored value is what decides success. The option is autoloaded.
     *
     * @return bool True when the flag reads back as complete.
     */
    private function mark_site_note_slash_repair_complete() {
        $option = self::SITE_NOTE_SLASH_REPAIR_OPTION;

        if ( false === get_option( $option, false ) ) {
            add_option( $option, 'complete', '', 'yes' );
        } else {
            update_option( $option, 'complete' );
        }

        if ( 'complete' !== get_option( $option ) ) {
            return false;
        }

        // The resume marker is removed only after the flag is stored, so a second
        // request cannot start again at id 0 while this one still holds the lock.
        delete_option( self::SITE_NOTE_SLASH_REPAIR_LAST_ID );
        delete_option( self::SITE_NOTE_SLASH_REPAIR_MAX_ID );
        delete_option( self::SITE_NOTE_SLASH_REPAIR_INFLIGHT );
        if ( ! $this->delete_site_note_slash_repair_skip_rows() ) {
            return false;
        }
        delete_transient( self::SITE_NOTE_SLASH_REPAIR_RETRY );
        return true;
    }

    /**
     * Read the repair flag from the database, not from the autoload cache.
     *
     * @return mixed Stored flag, or false when the option is missing.
     */
    private function read_site_note_slash_repair_flag() {
        wp_cache_delete( self::SITE_NOTE_SLASH_REPAIR_OPTION, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Must not trust a cached "not complete" flag.
            $this->wpdb->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
                $this->wpdb->options,
                self::SITE_NOTE_SLASH_REPAIR_OPTION
            )
        );

        if ( '' !== (string) $this->wpdb->last_error ) {
            return false;
        }

        if ( null === $value ) {
            return false;
        }

        return maybe_unserialize( $value );
    }

    /**
     * Store the highest site id present when the repair first runs.
     *
     * Later resumes keep that ceiling, so a site created after the start is not scanned.
     *
     * @param string $table Site table name. The %i placeholder quotes it.
     *
     * @return int|false Highest id, or false when the read or the save failed.
     */
    private function ensure_site_note_slash_repair_max_id( $table ) {
        $option = self::SITE_NOTE_SLASH_REPAIR_MAX_ID;
        $saved  = get_option( $option, false );
        if ( false !== $saved && '' !== $saved ) {
            return (int) $saved;
        }

        // Requires WordPress 6.2, which this plugin already requires, for the %i identifier placeholder.
        $max = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Failure must stay visible to the caller.
        if ( '' !== (string) $this->wpdb->last_error ) {
            return false;
        }

        $max = (int) $max;
        if ( false === get_option( $option, false ) ) {
            add_option( $option, (string) $max, '', 'no' );
        } else {
            update_option( $option, (string) $max );
        }

        $stored = get_option( $option, false );
        if ( false === $stored || (int) $stored !== $max ) {
            return false;
        }

        return $max;
    }

    /**
     * Whether update_note() saved this site while the repair was still open.
     *
     * The read is a direct query. The per-request option cache can still say the row is missing
     * after another request inserted it.
     *
     * @param int $id Site id.
     *
     * @return bool|null True when the skip row exists, false when it does not, null when the read failed.
     */
    private function site_note_slash_repair_skips_id( $id ) {
        $option = self::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX . (int) $id;
        $value  = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Must not trust the per-request option cache.
            $this->wpdb->prepare(
                'SELECT option_id FROM %i WHERE option_name = %s LIMIT 1',
                $this->wpdb->options,
                $option
            )
        );

        if ( '' !== (string) $this->wpdb->last_error ) {
            return null;
        }

        return null !== $value;
    }

    /**
     * Delete every per-site skip row.
     *
     * @return bool False when the delete query failed.
     */
    private function delete_site_note_slash_repair_skip_rows() {
        $like    = $this->wpdb->esc_like( self::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX ) . '%';
        $deleted = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One delete for every per-site skip row.
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE option_name LIKE %s',
                $this->wpdb->options,
                $like
            )
        );

        return false !== $deleted && '' === (string) $this->wpdb->last_error;
    }

    /**
     * Delete the skip row for one site.
     *
     * Used when a save inserts its row after the repair flag is already complete.
     *
     * @param int $id Site id.
     *
     * @return bool False when the delete query failed.
     */
    private function delete_one_site_note_slash_repair_skip_row( $id ) {
        $option  = self::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX . (int) $id;
        $deleted = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Removes the one skip row this save inserted.
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE option_name = %s',
                $this->wpdb->options,
                $option
            )
        );
        $this->forget_site_note_repair_option_cache( $option );

        return false !== $deleted && '' === (string) $this->wpdb->last_error;
    }

    /**
     * Remember the note value that the in-progress update is allowed to replace.
     *
     * The insert does not replace a marker another request already stored.
     *
     * @param int    $id   Site id.
     * @param string $note Note value read before the update.
     *
     * @return bool True when the database stores that pair.
     */
    private function mark_site_note_slash_repair_inflight( $id, $note ) {
        $option  = self::SITE_NOTE_SLASH_REPAIR_INFLIGHT;
        $payload = array(
            'id'   => (int) $id,
            'note' => (string) $note,
        );

        $suppress = $this->wpdb->suppress_errors( true );
        $added    = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert-only marker. A second request must not overwrite it.
            $this->wpdb->options,
            array(
                'option_name'  => $option,
                'option_value' => maybe_serialize( $payload ),
                'autoload'     => 'no',
            ),
            array( '%s', '%s', '%s' )
        );
        $this->wpdb->suppress_errors( $suppress );
        $this->wpdb->last_error = '';

        if ( false !== $added ) {
            $this->forget_site_note_repair_option_cache( $option );
        }

        $stored = $this->read_site_note_slash_repair_inflight();
        return is_array( $stored ) && (int) $stored['id'] === (int) $id && (string) $stored['note'] === (string) $note;
    }

    /**
     * Read the in-progress row from the database.
     *
     * @return array|null|false Payload, null when absent, false when the read failed.
     */
    private function read_site_note_slash_repair_inflight() {
        $value = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Resume must see the marker written before a crash.
            $this->wpdb->prepare(
                'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
                $this->wpdb->options,
                self::SITE_NOTE_SLASH_REPAIR_INFLIGHT
            )
        );

        if ( '' !== (string) $this->wpdb->last_error ) {
            return false;
        }

        if ( null === $value ) {
            return null;
        }

        $payload = maybe_unserialize( $value );
        if ( ! is_array( $payload ) || ! isset( $payload['id'], $payload['note'] ) ) {
            return false;
        }

        return $payload;
    }

    /**
     * Drop the in-progress marker when it still holds this run's value.
     *
     * @param int    $id   Site id stored in the marker.
     * @param string $note Note value stored in the marker.
     *
     * @return bool True when this run's marker is gone. Another run's marker is left in place.
     */
    private function clear_site_note_slash_repair_inflight( $id, $note ) {
        $option  = self::SITE_NOTE_SLASH_REPAIR_INFLIGHT;
        $payload = array(
            'id'   => (int) $id,
            'note' => (string) $note,
        );
        $deleted = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare-and-swap delete. A newer marker must stay.
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
                $this->wpdb->options,
                $option,
                maybe_serialize( $payload )
            )
        );
        if ( false === $deleted || '' !== (string) $this->wpdb->last_error ) {
            return false;
        }

        $this->forget_site_note_repair_option_cache( $option );
        $stored = $this->read_site_note_slash_repair_inflight();
        if ( false === $stored ) {
            return false;
        }
        if ( null === $stored ) {
            return true;
        }

        return ! ( (int) $stored['id'] === (int) $id && (string) $stored['note'] === (string) $note );
    }

    /**
     * Drop a cached "missing option" entry after a direct insert.
     *
     * @param string $option Option name.
     *
     * @return void
     */
    private function forget_site_note_repair_option_cache( $option ) {
        $notoptions = wp_cache_get( 'notoptions', 'options' );
        if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
            unset( $notoptions[ $option ] );
            wp_cache_set( 'notoptions', $notoptions, 'options' );
        }
        wp_cache_delete( $option, 'options' );
    }

    /**
     * Retry a pending backup progress index repair, no more than once an hour.
     *
     * A DB user without ALTER never clears the marker, so an unthrottled retry
     * would run two SHOW INDEX plus a failing ALTER on every single request.
     *
     * @return bool True when a repair attempt was made.
     */
    public function maybe_retry_backup_progress_index_repair() {
        $pending = (int) get_site_option( self::BACKUP_PROGRESS_INDEX_REPAIR_PENDING );
        if ( empty( $pending ) || time() - $pending < HOUR_IN_SECONDS ) {
            return false;
        }

        $this->repair_backup_progress_index();

        return true;
    }

    /**
     * Run the backup progress index repair and remember whether it still needs a retry.
     *
     * @return bool True when the repair is confirmed complete.
     */
    public function repair_backup_progress_index() {
        // The retry from install() runs outside post_update()'s suppression; a
        // persistently failing ALTER must not log or print on every request.
        $suppress = $this->wpdb->suppress_errors();
        $repaired = $this->drop_backup_progress_unique_index();
        $this->wpdb->suppress_errors( $suppress );
        if ( $repaired ) {
            delete_site_option( self::BACKUP_PROGRESS_INDEX_REPAIR_PENDING );
        } else {
            // A timestamp, not a formatted date: the retry throttle compares it against time().
            update_site_option( self::BACKUP_PROGRESS_INDEX_REPAIR_PENDING, time() );
        }
        return $repaired;
    }

    /**
     * Drop a stray unique index on the backup progress task_id column.
     *
     * Fresh installs made between DB 8.53 and the schema fix created
     * wp_backup_progress with UNIQUE (task_id). Progress rows are per task and
     * per site, so a task covering two or more sites could never insert its
     * second row. dbDelta never drops an index, so the stray key has to go here.
     *
     * @return bool True when no such key remains, false when one survived a failed DROP.
     */
    public function drop_backup_progress_unique_index() {
        $table = $this->table_name( 'wp_backup_progress' );
        $keys  = $this->find_backup_progress_task_id_unique_keys();
        if ( null === $keys ) {
            return false;
        }

        foreach ( $keys as $key_name ) {
            $this->wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$key_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table name is a hardcoded internal identifier, key name comes from SHOW INDEX and is allowlisted below.
        }

        // suppress_errors() hides a failed DROP, so the table is the only source of truth.
        return array() === $this->find_backup_progress_task_id_unique_keys();
    }

    /**
     * Names of the unique keys on the backup progress table that cover task_id alone.
     *
     * A composite unique key over task_id and wp_id is legitimate and is left out.
     *
     * @return array|null Key names, or null when the lookup itself failed and nothing can be concluded.
     */
    protected function find_backup_progress_task_id_unique_keys() {
        $table = $this->table_name( 'wp_backup_progress' );

        // suppress_errors() is on in post_update(), so a failed SHOW INDEX looks like an empty index list; last_error is the only tell.
        $this->wpdb->last_error = '';
        $indexes                = $this->wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Non_unique = 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name is a hardcoded internal identifier, no user input involved.
        if ( '' !== $this->wpdb->last_error ) {
            return null;
        }

        $key_columns = array();
        foreach ( (array) $indexes as $index ) {
            if ( 'PRIMARY' === $index['Key_name'] ) {
                continue;
            }
            $key_columns[ $index['Key_name'] ][] = $index['Column_name'];
        }

        $keys = array();
        foreach ( $key_columns as $key_name => $columns ) {
            if ( array( 'task_id' ) === $columns && preg_match( '/^[A-Za-z0-9_]+$/', $key_name ) ) {
                $keys[] = $key_name;
            }
        }

        return $keys;
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
            $existing_columns = $this->wpdb->get_col( "SHOW COLUMNS FROM {$wp_table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name is a hardcoded internal identifier escaped via esc_sql(), no user input involved.
            foreach ( array( 'backups', 'note_lastupdate', 'pages' ) as $column ) {
                if ( in_array( $column, $existing_columns, true ) ) {
                    $this->wpdb->query( 'ALTER TABLE ' . $wp_table . ' DROP COLUMN ' . esc_sql( $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table and column names are hardcoded internal identifiers escaped via esc_sql(), no user input involved.
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
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $rslts = $this->wpdb->get_results( "SELECT id,{$wpSyncColumn} FROM {$wp_table}", ARRAY_A );
                if ( empty( $rslts ) ) {
                    continue;
                }

                foreach ( $rslts as $rslt ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name escaped via esc_sql and query uses proper prepare.
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
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
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
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $rslts = $this->wpdb->get_results( "SELECT id,{$optionColumn} FROM {$wp_table}", ARRAY_A );
                if ( empty( $rslts ) ) {
                    continue;
                }

                foreach ( $rslts as $rslt ) {
                    static::update_website_option( (object) $rslt, $optionColumn, $rslt[ $optionColumn ] );
                }

                $suppress = $this->wpdb->suppress_errors();
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column name escaped via esc_sql, table name escaped separately.
                $this->wpdb->query( "ALTER TABLE {$wp_table} DROP COLUMN {$optionColumn}" );
                $this->wpdb->suppress_errors( $suppress );
            }
        }
    }
}
