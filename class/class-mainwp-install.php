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
     * Network-scoped option holding the time() of the last failed attempt to
     * install the wp_backup_progress (task_id, wp_id) unique key. Absent once
     * the key is there.
     *
     * @var string Option name.
     */
    const BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING = 'mainwp_backup_progress_uniq_key_repair_pending';

    /**
     * Retry failed snapshot identity migrations after the version is stamped.
     *
     * @var string
     */
    const BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING = 'mainwp_backup_snapshot_run_key_repair_pending';

    /**
     * Retry a failed continue-lane column migration after the version is stamped.
     *
     * @var string
     */
    const BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING = 'mainwp_backup_task_last_continued_repair_pending';

    /**
     * Result writes need this column even after a failed migration stamps the version.
     *
     * @var string
     */
    const BACKUP_SNAPSHOT_TEST_RESTORE_RESULT_AT_REPAIR_PENDING = 'mainwp_backup_snapshot_test_restore_result_at_repair_pending';

    /**
     * Retry failed removal-cause migrations after the version is stamped.
     *
     * @var string
     */
    const BACKUP_SNAPSHOT_REMOVAL_REASON_REPAIR_PENDING = 'mainwp_backup_snapshot_removal_reason_repair_pending';

    /**
     * Child cleanup must wait for both columns after a deferred migration.
     *
     * @var string
     */
    const BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING = 'mainwp_backup_snapshot_child_prune_repair_pending';

    /**
     * Restore checkpoint writes compare this column even after a failed migration stamps the version.
     *
     * @var string
     */
    const BACKUP_RESTORE_JOB_CHECKPOINT_REV_REPAIR_PENDING = 'mainwp_backup_restore_job_checkpoint_rev_repair_pending';

    /**
     * Retry failed per-site identity migrations after the version is stamped.
     *
     * @var string
     */
    const BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING = 'mainwp_backup_site_uniq_keys_repair_pending';

    /**
     * Latest site-identity repair blockers, keyed by table, for support diagnostics.
     */
    const BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS = 'mainwp_backup_site_uniq_keys_repair_reasons';

    /**
     * Network-scoped time() of the last check for missing native backup
     * tables on a current install, so the check runs at most hourly.
     *
     * @var string
     */
    const NATIVE_BACKUP_TABLES_CHECKED = 'mainwp_native_backup_tables_checked';

    /**
     * The native backup tables, in creation order.
     *
     * @var string[]
     */
    const NATIVE_BACKUP_TABLES = array( 'wp_backup_snapshot', 'wp_backup_storage', 'wp_backup_upload_job', 'wp_backup_restore_job', 'wp_backup_run_history' );

    /**
     * MySQL advisory lock name serializing the wp_backup_progress duplicate
     * collapse.
     *
     * @var string Lock name.
     */
    const BACKUP_PROGRESS_DEDUPE_LOCK = 'mainwp_backup_progress_dedupe';

    /**
     * Private variable to hold the database version info.
     *
     * @var string DB version info.
     */
    protected $mainwp_db_version = '9.0.2.33'; // NOSONAR - no IP. The native backups line numbers its migrations past main's 9.0.2.x line; where the two lines reused a number, the step checks table state instead of the version.

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
     * Network-scoped like mainwp_db_version. Once set, the per-request schema check stops querying.
     */
    const KNOWLEDGE_SCHEMA_CONFIRMED = 'mainwp_knowledge_schema_confirmed';

    /**
     * A description-only confirmation cannot vouch for agency support or required rules.
     */
    const KNOWLEDGE_SCHEMA_REVISION = 'agency-required-description-1';

    /**
     * Network-scoped like mainwp_db_version, so every blog on a multisite sees the pending repair.
     */
    const KNOWLEDGE_SCHEMA_REPAIR_PENDING = 'mainwp_knowledge_schema_repair_pending';

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
            // Route the default-off write through the settings helper so it also
            // mirrors mainwp_enableLegacyBackupFeature=0. A bare native write
            // would leave the legacy flag absent, and a rollback to a pre-rename
            // dashboard recreates a MISSING flag as enabled, silently starting
            // legacy backups the user never turned on.
            MainWP_Backup_Settings::update_native_backups_option( 0 );
        } elseif ( false === get_option( 'mainwp_enableNativeBackups' ) && false === get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            // An existing install that never wrote either flag never used backups; default it off,
            // matching a fresh install. A prior legacy-flag value still carries forward via the
            // <9.0.2.28 migration below, which runs before this option is read again by callers.
            // Mirror the legacy flag here too (via the helper), for the same
            // rollback-window reason as the fresh-install branch above.
            MainWP_Backup_Settings::update_native_backups_option( 0 );
            // Mark the cohort so a one-time dismissible notice can say the
            // feature exists and where to turn it on.
            MainWP_Utility::update_option( 'mainwp_native_backups_defaulted_off_on_upgrade', 1 );
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
            // The index repair keeps its own marker so a failed DROP is retried at
            // most hourly, without holding the DB version back and re-running every
            // older migration on each load.
            $this->maybe_retry_backup_progress_index_repair();
            $this->maybe_retry_backup_progress_unique_key();
            $this->maybe_retry_backup_snapshot_run_key();
            $this->maybe_retry_backup_task_last_continued();
            $this->maybe_retry_native_backup_tables();
            $this->maybe_retry_backup_snapshot_test_restore_result_at();
            $this->maybe_retry_backup_snapshot_removal_reason();
            $this->maybe_retry_backup_snapshot_child_prune();
            $this->maybe_retry_backup_restore_job_checkpoint_rev();
            $pending = (int) get_site_option( self::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING );
            if ( $pending && time() - $pending >= HOUR_IN_SECONDS ) {
                $this->repair_backup_site_unique_keys();
            }
            $this->maybe_retry_repair_wp_group_primary_key();
            $this->maybe_create_knowledge_table( $currentVersion );
            $this->maybe_repair_knowledge_table();
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
  busy_since int(11) NOT NULL DEFAULT 0,
  child_backup_task_id varchar(64) NOT NULL DEFAULT '',
  child_phase varchar(20) NOT NULL DEFAULT '',
  bytes_downloaded bigint(20) NOT NULL DEFAULT 0,
  bytes_expected bigint(20) NOT NULL DEFAULT 0,
  current_file varchar(255) NOT NULL DEFAULT '',
  files_processed int(11) NOT NULL DEFAULT 0,
  files_total int(11) NOT NULL DEFAULT 0,
  foreign_refused_since int(11) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_task_wp (task_id, wp_id),
  KEY idx_task_id (task_id)";
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
  last_continued int(11) NOT NULL DEFAULT 0,
  retention_count int(11) NOT NULL DEFAULT 0,
  retention_days int(11) NOT NULL DEFAULT 0,
  retention_storage_mb int(11) NOT NULL DEFAULT 0,
  delete_cloud_copies tinyint(1) NOT NULL DEFAULT 1,
  max_chain_length int(11) NOT NULL DEFAULT 0,
  zip_force_override tinyint(1) NOT NULL DEFAULT 0,
  backup_notices text NOT NULL DEFAULT '',
  authorized_sites text NULL,
  manual_site_id bigint unsigned NULL,
  UNIQUE KEY uniq_manual_site (manual_site_id),
  KEY idx_next_run (next_run)";
        if ( empty( $currentVersion ) ) {
            $tbl .= ',
  PRIMARY KEY  (id)  ';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        foreach ( self::NATIVE_BACKUP_TABLES as $table_key ) {
            $sql[] = $this->native_backup_table_sql( $table_key, $currentVersion, $charset_collate );
        }

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

        $this->maybe_repair_knowledge_table();
        $this->post_update();
        $this->ensure_backup_snapshot_test_restore_result_at();
        $this->ensure_backup_snapshot_removal_reason();
        $this->ensure_backup_snapshot_child_prune();
        $this->ensure_backup_restore_job_checkpoint_rev();

        do_action( 'mainwp_db_after_update', $currentVersion, $this->mainwp_db_version ); // new version: $this->mainwp_db_version.

        if ( ! is_multisite() ) {
            MainWP_Utility::update_option( $this->option_db_key, $this->mainwp_db_version );
        } else {
            update_site_option( $this->option_db_key, $this->mainwp_db_version );
        }
    }

    /**
     * Whether a table still has to be created.
     *
     * The native backup tables decide on table state, not the stored version:
     * main numbers its migrations independently, so a site on main can be past
     * a version gate without having the table, and creating an auto_increment
     * id without its primary key fails the whole CREATE.
     *
     * @param string $table_key Table key for table_name().
     *
     * @return bool
     */
    private function is_table_missing( $table_key ) {
        $table = $this->table_name( $table_key );
        return null === $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $table ) ) );
    }

    /**
     * Re-create a native backup table dbDelta failed to create.
     *
     * The version is stamped even when a CREATE fails, so without this a
     * missing table would never be created again. The current-version path
     * runs on every request, so the check itself runs at most hourly.
     *
     * @return bool Whether a missing table was found and creation was retried.
     */
    public function maybe_retry_native_backup_tables() {
        $checked = (int) get_site_option( self::NATIVE_BACKUP_TABLES_CHECKED );
        if ( $checked && time() - $checked < HOUR_IN_SECONDS ) {
            return false;
        }
        update_site_option( self::NATIVE_BACKUP_TABLES_CHECKED, time() );

        $missing = array_filter( self::NATIVE_BACKUP_TABLES, array( $this, 'is_table_missing' ) );
        if ( empty( $missing ) ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WP compatible.
        $charset_collate = $this->wpdb->get_charset_collate();
        $current_version = get_site_option( $this->option_db_key );
        $suppress        = $this->wpdb->suppress_errors();
        foreach ( $missing as $table_key ) {
            dbDelta( $this->native_backup_table_sql( $table_key, $current_version, $charset_collate ) );
            if ( $this->is_table_missing( $table_key ) ) {
                // Install runs before the mainwp_log_warning listeners are registered; use the logger directly.
                MainWP_Logger::instance()->warning( sprintf( 'Native backup table %s could not be created: %s', $this->table_name( $table_key ), $this->wpdb->last_error ) );
            }
        }
        $this->wpdb->suppress_errors( $suppress );

        return true;
    }

    /**
     * CREATE TABLE statement for one native backup table.
     *
     * Shared by install() and the current-version retry, because dbDelta
     * failures do not hold back the version stamp.
     *
     * @param string       $table_key       One of NATIVE_BACKUP_TABLES.
     * @param string|false $current_version Stored DB version, false on a fresh install.
     * @param string       $charset_collate Charset and collation clause.
     *
     * @return string
     */
    private function native_backup_table_sql( $table_key, $current_version, $charset_collate ) {
        $columns = array(
            'wp_backup_run_history' => "  id bigint(20) unsigned NOT NULL auto_increment,
  task_id int(11) NOT NULL DEFAULT 0,
  site_id int(11) NOT NULL,
  started int(11) NOT NULL DEFAULT 0,
  finished int(11) NOT NULL DEFAULT 0,
  trigger_source varchar(20) NOT NULL DEFAULT '',
  initiator_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  outcome varchar(20) NOT NULL DEFAULT 'running',
  error_code varchar(64) NOT NULL DEFAULT '',
  message text NOT NULL,
  snapshot_id bigint(20) unsigned NULL DEFAULT NULL,
  restore_job_id bigint(20) unsigned NULL DEFAULT NULL,
  ref varchar(191) NULL DEFAULT NULL,
  KEY idx_task_site_started (task_id, site_id, started),
  KEY idx_site_finished (site_id, finished),
  KEY idx_finished (finished)",
            'wp_backup_snapshot'    => "  id bigint(20) unsigned NOT NULL auto_increment,
  task_id int(11) NOT NULL,
  site_id int(11) NOT NULL,
  type varchar(20) NOT NULL,
  parent_id bigint(20) unsigned NULL DEFAULT NULL,
  run_key varchar(191) NULL DEFAULT NULL,
  created int(11) NOT NULL DEFAULT 0,
  archives text NOT NULL DEFAULT '',
  manifest_path text NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'complete',
  removal_reason varchar(20) NOT NULL DEFAULT '',
  child_prune_confirmed_at int(11) NOT NULL DEFAULT 0,
  child_prune_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  offsite_state varchar(20) NOT NULL DEFAULT '',
  verified_at int(11) NOT NULL DEFAULT 0,
  encryption text NOT NULL DEFAULT '',
  woo_meta text NOT NULL DEFAULT '',
  test_restore_state varchar(20) NOT NULL DEFAULT '',
  test_restore_at int(11) NOT NULL DEFAULT 0,
  test_restore_result_at int(11) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_run_key (run_key),
  KEY idx_site_task (site_id, task_id),
  KEY idx_parent (parent_id),
  KEY idx_offsite_state (offsite_state),
  KEY idx_test_restore_state (test_restore_state)",
            'wp_backup_storage'     => "  id int(11) NOT NULL auto_increment,
  provider varchar(32) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  settings longtext NOT NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created int(11) NOT NULL DEFAULT 0,
  updated int(11) NOT NULL DEFAULT 0,
  KEY idx_provider (provider)",
            'wp_backup_upload_job'  => "  id bigint(20) unsigned NOT NULL auto_increment,
  snapshot_id bigint(20) unsigned NOT NULL,
  destination_id int(11) NOT NULL,
  kind varchar(10) NOT NULL DEFAULT 'upload',
  provider varchar(20) NOT NULL DEFAULT '',
  status varchar(32) NOT NULL DEFAULT 'pending',
  attempt_count int(11) NOT NULL DEFAULT 0,
  reclaim_count int(11) NOT NULL DEFAULT 0,
  next_retry_at int(11) NOT NULL DEFAULT 0,
  locked_until int(11) NOT NULL DEFAULT 0,
  locked_by varchar(64) NOT NULL DEFAULT '',
  checkpoint longtext NOT NULL,
  last_error text NOT NULL DEFAULT '',
  created int(11) NOT NULL DEFAULT 0,
  updated int(11) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_snap_dest_kind (snapshot_id, destination_id, kind),
  KEY idx_status_retry (status, next_retry_at)",
            'wp_backup_restore_job' => "  id bigint(20) unsigned NOT NULL auto_increment,
  site_id int(11) NOT NULL,
  active_site_id bigint unsigned NULL,
  snapshot_id bigint(20) unsigned NOT NULL,
  status varchar(32) NOT NULL DEFAULT 'pending',
  child_task_id varchar(64) NOT NULL DEFAULT '',
  pre_snapshot_task_id varchar(64) NOT NULL DEFAULT '',
  checkpoint longtext NOT NULL,
  checkpoint_rev int(11) unsigned NOT NULL DEFAULT 0,
  last_error text NOT NULL DEFAULT '',
  attempt_count int(11) NOT NULL DEFAULT 0,
  reclaim_count int(11) NOT NULL DEFAULT 0,
  next_retry_at int(11) NOT NULL DEFAULT 0,
  locked_until int(11) NOT NULL DEFAULT 0,
  locked_by varchar(64) NOT NULL DEFAULT '',
  created int(11) NOT NULL DEFAULT 0,
  updated int(11) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_active_site (active_site_id),
  KEY idx_site (site_id),
  KEY idx_status_retry (status, next_retry_at)",
        );

        $tbl = 'CREATE TABLE ' . $this->table_name( $table_key ) . ' (
' . $columns[ $table_key ];
        if ( empty( $current_version ) || $this->is_table_missing( $table_key ) ) {
            $tbl .= ',
  PRIMARY KEY  (id)  ';
        }
        return $tbl . ') ' . $charset_collate;
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

        // The 9.0.2.20 drop ran without a marker, so failed drops were never
        // retried. This repair leaves a marker when the task-only key survives.
        if ( version_compare( $currentVersion, '9.0.2.25', '<' ) ) { // NOSONAR - none IP.
            $this->repair_backup_progress_index();
            $this->repair_backup_site_unique_keys();
        }

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

        // Same reason: dbDelta cannot be relied on to add the progress
        // table's (task_id, wp_id) unique key to an existing install.
        if ( version_compare( $currentVersion, '9.0.2.24', '<' ) ) { // NOSONAR - none IP.
            $this->ensure_backup_progress_unique_key();
        }

        if ( version_compare( $currentVersion, '9.0.2.26', '<' ) ) { // NOSONAR - none IP.
            $this->ensure_backup_snapshot_run_key();
        }

        if ( version_compare( $currentVersion, '9.0.2.27', '<' ) ) { // NOSONAR - none IP.
            $this->ensure_backup_task_last_continued();
        }

        // Rename the backups feature flag to mainwp_enableNativeBackups; reads fall back to the
        // old name until this has run (see MainWP_Backup_Settings::is_native_backups_enabled()).
        // The old option is kept, mirroring the effective value, not deleted: a rollback to a
        // pre-rename dashboard recreates a MISSING flag as enabled on install(), silently
        // re-enabling legacy backups, while a present flag is preserved.
        if ( version_compare( $currentVersion, '9.0.2.28', '<' ) ) { // NOSONAR - no ip.
            $old_flag = get_option( 'mainwp_enableLegacyBackupFeature', false );
            if ( false !== $old_flag ) {
                if ( false === get_option( 'mainwp_enableNativeBackups', false ) ) {
                    MainWP_Utility::update_option( 'mainwp_enableNativeBackups', (int) $old_flag );
                }
                MainWP_Backup_Settings::mirror_native_flag_for_rollback();
            }
        }

        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '9.0.2.28', '<' ) ) { // NOSONAR - no ip.
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
     * Create the knowledge table when the stored DB version already covers it.
     *
     * Errors from dbDelta are suppressed and the version is written regardless,
     * so a failed CREATE TABLE would otherwise leave every knowledge read and
     * write against a missing table until the next schema bump.
     *
     * @param string|false $currentVersion Stored DB version.
     *
     * @return bool True when the table had to be created.
     */
    public function maybe_create_knowledge_table( $currentVersion ) {
        $table  = $this->table_name( 'knowledge' );
        $exists = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $table ) ) );
        if ( null !== $exists ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql      = MainWP_DB_Knowledge::instance()->hook_db_install_tables( array(), $currentVersion, $this->wpdb->get_charset_collate() );
        $suppress = $this->wpdb->suppress_errors();
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
        $this->wpdb->suppress_errors( $suppress );

        return true;
    }

    /**
     * Retry knowledge schema changes that may have failed before the version was saved.
     *
     * The current-version path of install() runs on every request, so a confirmed
     * schema is remembered and a failing ALTER (a DB user without ALTER) is retried at
     * most hourly instead of logging an error on each request.
     *
     * @return bool Whether a missing schema component was repaired.
     */
    public function maybe_repair_knowledge_table() {
        if ( self::KNOWLEDGE_SCHEMA_REVISION === get_site_option( self::KNOWLEDGE_SCHEMA_CONFIRMED ) ) {
            return false;
        }
        $pending = (int) get_site_option( self::KNOWLEDGE_SCHEMA_REPAIR_PENDING );
        if ( ! empty( $pending ) && time() - $pending < HOUR_IN_SECONDS ) {
            return false;
        }

        $table    = $this->table_name( 'knowledge' );
        $suppress = $this->wpdb->suppress_errors();
        if ( null === $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $table ) ) ) ) {
            $this->wpdb->suppress_errors( $suppress );
            return false;
        }
        $columns = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), OBJECT_K );
        $repairs = array();
        if ( ! empty( $columns ) ) {
            // dbDelta can add columns but does not reliably expand an enum, including on beta installs with a higher version.
            if ( isset( $columns['scope_type'] ) && false === strpos( $columns['scope_type']->Type, "'agency'" ) ) {
                $repairs[] = "ALTER TABLE %i MODIFY scope_type enum('agency','site','client') NOT NULL";
            }
            if ( ! isset( $columns['required'] ) ) {
                $repairs[] = 'ALTER TABLE %i ADD COLUMN required tinyint(1) NOT NULL DEFAULT 0 AFTER verified';
            }
            if ( ! isset( $columns['description'] ) ) {
                $repairs[] = "ALTER TABLE %i ADD COLUMN description varchar(1024) NOT NULL DEFAULT '' AFTER title";
            }
        }
        $confirmed = ! empty( $columns ) && isset( $columns['scope_type'] );
        $repaired  = false;
        foreach ( $repairs as $sql ) {
            $success   = false !== $this->wpdb->query( $this->wpdb->prepare( $sql, $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed schema statements above, with the table identifier bound.
            $confirmed = $confirmed && $success;
            $repaired  = $repaired || $success;
        }
        $this->wpdb->suppress_errors( $suppress );

        if ( $confirmed ) {
            update_site_option( self::KNOWLEDGE_SCHEMA_CONFIRMED, self::KNOWLEDGE_SCHEMA_REVISION );
            delete_site_option( 'mainwp_knowledge_description_column_confirmed' );
            delete_site_option( 'mainwp_knowledge_description_column_repair_pending' );
            delete_site_option( self::KNOWLEDGE_SCHEMA_REPAIR_PENDING );
        } else {
            // A timestamp, not a formatted date: the retry throttle compares it against time().
            update_site_option( self::KNOWLEDGE_SCHEMA_REPAIR_PENDING, time() );
        }
        return $repaired;
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
            "SHOW COLUMNS FROM {$wp_group_table} LIKE 'wp_group_id'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and query contains no user input.
        );

        if ( empty( $column_exists ) ) {
            $this->wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and query contains no user input.
                "ALTER TABLE {$wp_group_table}
                ADD COLUMN wp_group_id int(11) NOT NULL auto_increment PRIMARY KEY FIRST" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped and query contains no user input.
            );
        } else {
            $primary_key = $this->wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and query contains no user input.
                "SHOW INDEX FROM {$wp_group_table}
                WHERE Key_name = 'PRIMARY'
                AND Column_name = 'wp_group_id'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped and query contains no user input.
            );

            if ( empty( $primary_key ) ) {
                $this->wpdb->query(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and query contains no user input.
                    "ALTER TABLE {$wp_group_table}
                    ADD PRIMARY KEY (wp_group_id)" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is escaped and query contains no user input.
                );
            }
        }

        $primary_key = $this->wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and query contains no user input.
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
     * Keep historical task ids while assigning one owner for each site.
     *
     * @return bool Whether both backfills and unique keys are complete.
     */
    public function repair_backup_site_unique_keys(): bool {
        $previous_reasons = get_site_option( self::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS, array() );
        $suppress         = $this->wpdb->suppress_errors();
        $repaired         = true;
        $reasons          = array();
        $schemas          = array(
            'wp_backup'             => array( 'manual_site_id', 'uniq_manual_site' ),
            'wp_backup_restore_job' => array( 'active_site_id', 'uniq_active_site' ),
        );

        try {
            foreach ( $schemas as $suffix => $schema ) {
                $table  = esc_sql( $this->table_name( $suffix ) );
                $column = $schema[0];
                $key    = $schema[1];
                $exists = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
                if ( empty( $exists ) && false === $this->wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} bigint unsigned NULL" ) ) { // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table name escaped via esc_sql.
                    $error                          = $this->wpdb->last_error;
                    $reasons[ $table ]['reasons'][] = array(
                        'code'  => 'add_column_failed',
                        'error' => $error,
                    );
                    MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: failed to add column %s: %s', $table, $column, $error ) );
                    $repaired = false;
                    continue;
                }

                // Row repair can defer independently of a foreign same-name index.
                $has_index   = $this->wpdb->get_results( $this->wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
                $valid_index = 1 === count( (array) $has_index ) && 0 === (int) $has_index[0]->Non_unique && 1 === (int) $has_index[0]->Seq_in_index && $column === $has_index[0]->Column_name;
                if ( ! $valid_index && ! empty( $has_index ) ) {
                    $reasons[ $table ]['reasons'][] = array( 'code' => 'malformed_index' );
                    MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: malformed same-name index %s.', $table, $key ) );
                    $repaired = false;
                }

                if ( 'wp_backup' === $suffix ) {
                    // Clear duplicate identities first so a retry also works with the key installed.
                    $cleared = $this->wpdb->query( "UPDATE {$table} task INNER JOIN {$table} older ON older.template = 1 AND older.schedule = 'manual' AND older.sites REGEXP '^[0-9]+$' AND CAST(older.sites AS UNSIGNED) = CAST(task.sites AS UNSIGNED) AND older.id < task.id SET task.manual_site_id = NULL WHERE task.template = 1 AND task.schedule = 'manual' AND task.sites REGEXP '^[0-9]+$'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time dedupe; table name escaped via esc_sql.
                    if ( false === $cleared ) {
                        $error                          = $this->wpdb->last_error;
                        $reasons[ $table ]['reasons'][] = array(
                            'code'  => 'manual_dedupe_failed',
                            'error' => $error,
                        );
                        MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: failed to deduplicate manual rows: %s', $table, $error ) );
                    }
                    $filled = false;
                    if ( false !== $cleared ) {
                        $filled = $this->wpdb->query( "UPDATE {$table} task LEFT JOIN {$table} older ON older.template = 1 AND older.schedule = 'manual' AND older.sites REGEXP '^[0-9]+$' AND CAST(older.sites AS UNSIGNED) = CAST(task.sites AS UNSIGNED) AND older.id < task.id SET task.manual_site_id = CAST(task.sites AS UNSIGNED) WHERE task.template = 1 AND task.schedule = 'manual' AND task.sites REGEXP '^[0-9]+$' AND older.id IS NULL" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time dedupe; table name escaped via esc_sql.
                        if ( false === $filled ) {
                            $error                          = $this->wpdb->last_error;
                            $reasons[ $table ]['reasons'][] = array(
                                'code'  => 'manual_backfill_failed',
                                'error' => $error,
                            );
                            MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: failed to backfill manual identities: %s', $table, $error ) );
                        }
                    }
                } else {
                    $statuses     = MainWP_Backup_Restore_Job_Store::active_statuses();
                    $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
                    // Restore repair matrix, per site:
                    // - Zero or one active row: keep it.
                    // - All duplicates pristine: keep the highest id.
                    // - Exactly one progressed row: keep it; cancel only pristine siblings.
                    // - Two or more progressed rows: leave the site untouched and defer this table.
                    // Pristine means pending, no handles or owner, no positive lease deadline,
                    // and a decoded array containing only initial checkpoint keys with empty transfer.
                    // Any extra key (including resume_status or last_failure_transfer), non-empty
                    // transfer, or undecodable/non-array checkpoint counts as progress.
                    // The unique key is hardening, not worth terminalizing a live restore for.
                    $rows = $this->wpdb->get_results(
                        $this->wpdb->prepare(
                            "SELECT * FROM {$table} WHERE status IN ( {$placeholders} ) ORDER BY id DESC", // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- trusted table and counted status placeholders.
                            $statuses
                        )
                    );
                    if ( null === $rows || '' !== $this->wpdb->last_error ) {
                        $error                          = $this->wpdb->last_error;
                        $reasons[ $table ]['reasons'][] = array(
                            'code'  => 'active_rows_select_failed',
                            'error' => $error,
                        );
                        MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: failed to select active restore rows: %s', $table, $error ) );
                        $repaired = false;
                        continue;
                    }
                    $sites = array();
                    foreach ( $rows as $row ) {
                        $sites[ $row->site_id ][] = $row;
                    }
                    $cleared         = true;
                    $cancel_failures = array();
                    foreach ( $sites as $jobs ) {
                        if ( count( $jobs ) < 2 ) {
                            continue;
                        }
                        $progressed = array();
                        foreach ( $jobs as $job ) {
                            $checkpoint   = json_decode( $job->checkpoint, true );
                            $initial_keys = array_fill_keys( array( 'restore_id', 'sub_type', 'plan', 'options', 'transfer' ), true );
                            if ( 'pending' !== $job->status || '' !== (string) $job->child_task_id || '' !== (string) $job->pre_snapshot_task_id || '' !== (string) $job->locked_by || 0 < (int) $job->locked_until || ! is_array( $checkpoint ) || ! empty( $checkpoint['transfer'] ) || ! empty( array_diff_key( $checkpoint, $initial_keys ) ) ) {
                                $progressed[] = $job;
                            }
                        }
                        if ( count( $progressed ) > 1 ) {
                            if ( ! isset( $reasons[ $table ]['site_count'] ) ) {
                                $reasons[ $table ]['reasons'][]  = array( 'code' => 'progressed_duplicates' );
                                $reasons[ $table ]['site_count'] = 0;
                                $reasons[ $table ]['sites']      = array();
                            }
                            ++$reasons[ $table ]['site_count'];
                            if ( count( $reasons[ $table ]['sites'] ) < 25 ) {
                                $reasons[ $table ]['sites'][] = array(
                                    'site_id'   => (int) $jobs[0]->site_id,
                                    'job_ids'   => array_map( 'intval', wp_list_pluck( array_slice( $progressed, 0, 20 ), 'id' ) ),
                                    'job_count' => count( $progressed ),
                                );
                            }
                            MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: blocked by progressed duplicate jobs; site_id=%d; job_ids=%s.', $table, $jobs[0]->site_id, implode( ',', wp_list_pluck( $progressed, 'id' ) ) ) );
                            $cleared = false;
                            continue;
                        }
                        $keep = empty( $progressed ) ? $jobs[0] : $progressed[0];
                        foreach ( $jobs as $job ) {
                            if ( $job->id === $keep->id ) {
                                continue;
                            }
                            $changed = $this->wpdb->query(
                                $this->wpdb->prepare(
                                    "UPDATE {$table} SET status = 'cancelled', active_site_id = NULL, locked_until = 0, locked_by = '', last_error = %s WHERE id = %d AND status = 'pending' AND child_task_id = '' AND pre_snapshot_task_id = '' AND locked_by = '' AND locked_until <= 0 AND checkpoint = %s", // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- trusted table; recheck pristine state so a worker that progressed after the SELECT is not cancelled.
                                    'Superseded by the active restore uniqueness repair.',
                                    $job->id,
                                    $job->checkpoint
                                )
                            );
                            if ( 1 !== $changed ) {
                                $error = $this->wpdb->last_error;
                                if ( empty( $cancel_failures ) ) {
                                    $cancel_failures = array(
                                        'code'      => 'pristine_cancel_failed',
                                        'job_count' => 0,
                                        'job_ids'   => array(),
                                    );
                                }
                                ++$cancel_failures['job_count'];
                                if ( count( $cancel_failures['job_ids'] ) < 20 ) {
                                    $cancel_failures['job_ids'][] = (int) $job->id;
                                }
                                if ( '' !== $error && ! isset( $cancel_failures['error'] ) ) {
                                    $cancel_failures['error'] = $error;
                                }
                                MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: failed to cancel pristine restore job %d: %s', $table, $job->id, '' !== $error ? $error : 'row no longer matched the pristine state.' ) );
                                $cleared = false;
                            }
                        }
                    }
                    if ( ! empty( $cancel_failures ) ) {
                        $reasons[ $table ]['reasons'][] = $cancel_failures;
                    }
                    $filled = false;
                    if ( false !== $cleared ) {
                        $filled = $this->wpdb->query(
                            $this->wpdb->prepare(
                                "UPDATE {$table} SET active_site_id = CASE WHEN status IN ( {$placeholders} ) THEN site_id ELSE NULL END", // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one-time dedupe; table name escaped via esc_sql.
                                $statuses
                            )
                        );
                        if ( false === $filled ) {
                            $error                          = $this->wpdb->last_error;
                            $reasons[ $table ]['reasons'][] = array(
                                'code'  => 'restore_backfill_failed',
                                'error' => $error,
                            );
                            MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: failed to backfill restore identities: %s', $table, $error ) );
                        }
                    }
                }
                if ( false === $cleared || false === $filled ) {
                    $repaired = false;
                    continue;
                }

                // dbDelta reports planned changes even when an ALTER fails; verify before clearing the retry marker.
                if ( empty( $has_index ) ) {
                    $this->wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY {$key} ({$column})" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table name escaped via esc_sql.
                    $alter_error = $this->wpdb->last_error;
                    $has_index   = $this->wpdb->get_results( $this->wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
                    $valid_index = 1 === count( (array) $has_index ) && 0 === (int) $has_index[0]->Non_unique && 1 === (int) $has_index[0]->Seq_in_index && $column === $has_index[0]->Column_name;
                    if ( ! $valid_index ) {
                        $reason = array( 'code' => 'add_unique_failed' );
                        if ( '' !== $alter_error ) {
                            $reason['error'] = $alter_error;
                        }
                        $reasons[ $table ]['reasons'][] = $reason;
                        MainWP_Logger::instance()->warning( sprintf( 'Backup identity repair deferred for %s: failed to add unique key %s: %s', $table, $key, $alter_error ) );
                    }
                }
                $repaired = empty( $reasons[ $table ] ) && $repaired;
            }
        } finally {
            $this->wpdb->suppress_errors( $suppress );
        }

        if ( $repaired ) {
            delete_site_option( self::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING );
            delete_site_option( self::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
        } else {
            $now = time();
            foreach ( $reasons as $table => &$entry ) {
                $previous            = is_array( $previous_reasons ) && isset( $previous_reasons[ $table ] ) && is_array( $previous_reasons[ $table ] ) ? $previous_reasons[ $table ] : array();
                $entry['table']      = $table;
                $entry['first_seen'] = $previous['first_seen'] ?? $now;
                $entry['attempts']   = (int) ( $previous['attempts'] ?? 0 ) + 1;
            }
            unset( $entry );
            update_site_option( self::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING, $now );
            update_site_option( self::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS, $reasons );
        }
        return $repaired;
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

        // Make wp_backup.authorized_sites nullable so NULL is the
        // never-stamped sentinel. A dev install that added the column under
        // 9.0.2.21 (NOT NULL DEFAULT '') is converted here, and its existing
        // '' rows are reset to NULL so they are treated as never-stamped and
        // backfill from their live expansion on the next run rather than
        // reading as "authorized to zero sites". dbDelta does not reliably
        // MODIFY an existing column, so it is done explicitly.
        if ( version_compare( $currentVersion, '9.0.2.22', '<' ) ) { // NOSONAR - none IP.
            $backup_table = esc_sql( $this->table_name( 'wp_backup' ) );
            $has_column   = $this->wpdb->get_results( "SHOW COLUMNS FROM {$backup_table} LIKE 'authorized_sites'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
            if ( ! empty( $has_column ) ) {
                $this->wpdb->query( "ALTER TABLE {$backup_table} MODIFY COLUMN authorized_sites text NULL" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table name escaped via esc_sql.
                $this->wpdb->query( "UPDATE {$backup_table} SET authorized_sites = NULL WHERE authorized_sites = ''" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- one-time reset of the pre-nullable default.
            }
        }

        // (task_id, wp_id) becomes UNIQUE on the progress table, and ADD
        // UNIQUE refuses while duplicates are present, so an install that
        // already raced two rows onto one site is collapsed here first - one
        // pass before dbDelta and post_update() add the key.
        if ( version_compare( $currentVersion, '9.0.2.24', '<' ) ) { // NOSONAR - none IP.
            $progress_table = esc_sql( $this->table_name( 'wp_backup_progress' ) );
            $table_found    = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $progress_table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table probe.
            if ( ! empty( $table_found ) ) {
                $this->dedupe_backup_progress_rows( $progress_table );
            }
        }

        $this->wpdb->suppress_errors( $suppress );
    }

    /**
     * Method dedupe_backup_progress_rows()
     *
     * Collapse the progress table to one row per (task_id, wp_id).
     *
     * @param string $progress_table Progress table name, already escaped via esc_sql.
     *
     * @return bool True when the collapse ran, false when another request owns it.
     */
    private function dedupe_backup_progress_rows( $progress_table ) {
        // The count-based cull below deletes by a COUNT taken in an earlier
        // statement, so two requests racing through the migration or the
        // hourly retry would each read row_count = 2 and each delete one row,
        // emptying the pair. The whole collapse is serialized instead, and
        // whoever loses the race skips it rather than waiting: the work is
        // idempotent and the winner is already doing it. GET_LOCK is
        // connection-scoped, so a request that dies mid-collapse releases it
        // on disconnect instead of wedging every later attempt.
        $locked = $this->wpdb->get_var( "SELECT GET_LOCK('" . static::BACKUP_PROGRESS_DEDUPE_LOCK . "', 0)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- advisory lock, no table involved.

        if ( '1' !== (string) $locked ) {
            return false;
        }

        try {
            $this->run_backup_progress_dedupe_passes( $progress_table );
        } finally {
            $this->wpdb->get_var( "SELECT RELEASE_LOCK('" . static::BACKUP_PROGRESS_DEDUPE_LOCK . "')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- advisory lock, no table involved.
        }

        return true;
    }

    /**
     * Method run_backup_progress_dedupe_passes()
     *
     * The three survivor passes of the progress-table collapse. Only ever
     * called while the dedupe advisory lock is held.
     *
     * @param string $progress_table Progress table name, already escaped via esc_sql.
     *
     * @return void
     */
    private function run_backup_progress_dedupe_passes( $progress_table ) {
        // A row naming the child's backup task outranks one that does not:
        // that id is the only handle left on a run already queued on the
        // child, and the executor stamps it without advancing dtsFetched, so
        // a dead driver's later reset row can look fresher than the row that
        // can still poll or cancel the run.
        // nosemgrep: AIK_tainted-sql-string -- This dedupe query uses MainWP's internal progress table and fixed clauses.
        $this->wpdb->query( "DELETE idless FROM {$progress_table} idless INNER JOIN {$progress_table} named ON idless.task_id = named.task_id AND idless.wp_id = named.wp_id AND idless.child_backup_task_id = '' AND named.child_backup_task_id <> ''" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- one-time dedupe; table name escaped via esc_sql.

        // Rows that survive the first pass are alike on that test, so the
        // freshest is the one every read path would have been meant to return.
        // nosemgrep: AIK_tainted-sql-string -- This dedupe query uses MainWP's internal progress table and fixed clauses.
        $this->wpdb->query( "DELETE stale FROM {$progress_table} stale INNER JOIN {$progress_table} fresh ON stale.task_id = fresh.task_id AND stale.wp_id = fresh.wp_id AND stale.dtsFetched < fresh.dtsFetched" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- one-time dedupe; table name escaped via esc_sql.

        // The table carries no primary key or row id to tie-break with, so
        // rows still indistinguishable on both tests are culled by count:
        // they are same-second race inserts of the same payload and carry
        // nothing to choose between.
        // nosemgrep: AIK_tainted-sql-string -- This dedupe query uses MainWP's internal progress table and fixed clauses.
        $tied = $this->wpdb->get_results( "SELECT task_id, wp_id, COUNT(*) AS row_count FROM {$progress_table} GROUP BY task_id, wp_id HAVING row_count > 1" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- one-time dedupe; table name escaped via esc_sql.
        foreach ( $tied as $pair ) {
            $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time dedupe.
                $this->wpdb->prepare(
                    // nosemgrep: AIK_tainted-sql-string -- The progress table is internal, and task_id, wp_id and LIMIT bind via %d.
                    "DELETE FROM {$progress_table} WHERE task_id = %d AND wp_id = %d LIMIT %d", // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name escaped via esc_sql.
                    $pair->task_id,
                    $pair->wp_id,
                    (int) $pair->row_count - 1
                )
            );
        }
    }

    /**
     * Method ensure_backup_progress_unique_key()
     *
     * Install the progress table's (task_id, wp_id) unique key when it is
     * missing, and record whether it is there afterwards. A dbDelta or ALTER
     * that never took would otherwise be stamped as done by the version bump
     * and never retried, silently reverting add_backup_task_progress() to
     * plain-insert semantics.
     *
     * @return bool True when the key is present, false when it could not be installed.
     */
    public function ensure_backup_progress_unique_key() {
        // The retry path runs outside post_update()'s suppression, and a DB
        // user without ALTER would otherwise log a failure on every request.
        $suppress = $this->wpdb->suppress_errors();

        $progress_table = esc_sql( $this->table_name( 'wp_backup_progress' ) );
        $table_found    = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $progress_table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table probe.
        $has_index      = array();

        if ( ! empty( $table_found ) ) {
            $has_index = $this->wpdb->get_results( "SHOW INDEX FROM {$progress_table} WHERE Key_name = 'uniq_task_wp'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
            if ( empty( $has_index ) ) {
                // A duplicate landed between the migration's dedupe and a
                // failed ALTER would refuse every later retry, so the collapse
                // runs again whenever the key is still missing. ADD UNIQUE
                // would only fail on the duplicates a skipped collapse leaves
                // behind, so a lost race waits for the hourly retry.
                if ( $this->dedupe_backup_progress_rows( $progress_table ) ) {
                    $this->wpdb->query( "ALTER TABLE {$progress_table} ADD UNIQUE KEY uniq_task_wp (task_id, wp_id)" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement; table name escaped via esc_sql.
                }
                // The ALTER runs error-suppressed, and the request that won
                // the collapse may have added the key meanwhile, so the index
                // itself is the only trustworthy report of what happened.
                $has_index = $this->wpdb->get_results( "SHOW INDEX FROM {$progress_table} WHERE Key_name = 'uniq_task_wp'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table name escaped via esc_sql.
            }
        }

        $this->wpdb->suppress_errors( $suppress );

        if ( empty( $has_index ) ) {
            update_site_option( static::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING, time() );
            return false;
        }

        delete_site_option( static::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING );
        return true;
    }

    /**
     * Method maybe_retry_backup_progress_unique_key()
     *
     * Retry a pending progress-table unique key install, at most hourly. The
     * throttle keeps an install whose DB user cannot ALTER from paying two
     * SHOW INDEX and a failing ALTER on every request.
     *
     * @return void
     */
    public function maybe_retry_backup_progress_unique_key() {
        $pending = (int) get_site_option( static::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING );

        if ( empty( $pending ) || ( time() - $pending ) < HOUR_IN_SECONDS ) {
            return;
        }

        $this->ensure_backup_progress_unique_key();
    }

    /**
     * Retry both schema changes because dbDelta failures do not hold back the version stamp.
     *
     * @return bool Whether the column and unique key exist.
     */
    public function ensure_backup_snapshot_run_key() {
        $suppress = $this->wpdb->suppress_errors();
        $table    = esc_sql( $this->table_name( 'wp_backup_snapshot' ) );
        $found    = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table probe.
        $column   = null;
        $index    = array();
        $error    = 'Snapshot table or run_key schema is missing.';

        if ( ! empty( $found ) ) {
            $column = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'run_key'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table escaped via esc_sql.
            if ( empty( $column ) ) {
                $this->wpdb->query( "ALTER TABLE {$table} ADD COLUMN run_key varchar(191) NULL DEFAULT NULL" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL; table escaped via esc_sql.
                $error  = 'Failed to add run_key column: ' . $this->wpdb->last_error;
                $column = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'run_key'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table escaped via esc_sql.
            }
            if ( ! empty( $column ) ) {
                $index = $this->wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_run_key'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table escaped via esc_sql.
                if ( empty( $index ) ) {
                    $this->wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_run_key (run_key)" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL; table escaped via esc_sql.
                    $error = 'Failed to add uniq_run_key: ' . $this->wpdb->last_error;
                    $index = $this->wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_run_key'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table escaped via esc_sql.
                }
            }
        }
        $this->wpdb->suppress_errors( $suppress );

        $valid_index = ! empty( $index ) && 1 === count( $index ) && 0 === (int) $index[0]->Non_unique && 'run_key' === $index[0]->Column_name && empty( $index[0]->Sub_part );
        if ( empty( $column ) || ! $valid_index ) {
            if ( ! empty( $index ) && ! $valid_index ) {
                $error = 'Malformed uniq_run_key index; expected a single-column unique index on run_key without a prefix.';
            }
            // Install runs before the mainwp_log_warning listeners are registered; use the logger directly.
            MainWP_Logger::instance()->warning( sprintf( 'Backup snapshot run key repair deferred for %s: %s', $table, $error ) );
            update_site_option( static::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING, time() );
            return false;
        }
        delete_site_option( static::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING );
        return true;
    }

    /**
     * Whether snapshot inserts can use the run key schema.
     *
     * @return bool Whether no schema repair is pending.
     */
    public static function backup_snapshot_run_key_ready(): bool {
        return ! get_site_option( static::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING );
    }

    /**
     * Avoid retrying denied ALTER statements on every request.
     *
     * @return void
     */
    public function maybe_retry_backup_snapshot_run_key() {
        $pending = (int) get_site_option( static::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING );
        if ( $pending && time() - $pending >= HOUR_IN_SECONDS ) {
            $this->ensure_backup_snapshot_run_key();
        }
    }

    /**
     * Retry the column addition because dbDelta failures do not hold back the version stamp.
     *
     * @return bool Whether the column exists.
     */
    public function ensure_backup_task_last_continued() {
        $suppress = $this->wpdb->suppress_errors();
        $table    = esc_sql( $this->table_name( 'wp_backup' ) );
        $column   = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'last_continued'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table escaped via esc_sql.
        $error    = '';

        if ( empty( $column ) ) {
            $this->wpdb->query( "ALTER TABLE {$table} ADD COLUMN last_continued int(11) NOT NULL DEFAULT 0" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL; table escaped via esc_sql.
            $error  = $this->wpdb->last_error;
            $column = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'last_continued'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL introspection; table escaped via esc_sql.
        }
        $this->wpdb->suppress_errors( $suppress );

        if ( empty( $column ) ) {
            // Install runs before the mainwp_log_warning listeners are registered; use the logger directly.
            MainWP_Logger::instance()->warning( sprintf( 'Backup task last_continued repair deferred for %s: %s', $table, $error ) );
            update_site_option( static::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING, time() );
            return false;
        }
        delete_site_option( static::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING );
        return true;
    }

    /**
     * Whether the continue lane can use the rotation column.
     *
     * @return bool Whether no schema repair is pending.
     */
    public static function backup_task_last_continued_ready(): bool {
        return ! get_site_option( static::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING );
    }

    /**
     * Whether restore job writes can use the checkpoint revision column.
     *
     * @return bool Whether no schema repair is pending.
     */
    public static function backup_restore_job_checkpoint_rev_ready(): bool {
        return ! get_site_option( static::BACKUP_RESTORE_JOB_CHECKPOINT_REV_REPAIR_PENDING );
    }

    /**
     * Avoid retrying denied ALTER statements on every request.
     *
     * @return void
     */
    public function maybe_retry_backup_task_last_continued() {
        $pending = (int) get_site_option( static::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING );
        if ( $pending && time() - $pending >= HOUR_IN_SECONDS ) {
            $this->ensure_backup_task_last_continued();
        }
    }

    /**
     * A failed dbDelta must not leave every test-restore result write unusable.
     *
     * @return bool Whether the column exists.
     */
    public function ensure_backup_snapshot_test_restore_result_at(): bool {
        $suppress = $this->wpdb->suppress_errors();
        $table    = esc_sql( $this->table_name( 'wp_backup_snapshot' ) );
        $column   = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'test_restore_result_at'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
        $error    = '';
        if ( empty( $column ) ) {
            $this->wpdb->query( "ALTER TABLE {$table} ADD COLUMN test_restore_result_at int(11) NOT NULL DEFAULT 0" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
            $error  = $this->wpdb->last_error;
            $column = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'test_restore_result_at'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
        }
        $this->wpdb->suppress_errors( $suppress );
        if ( empty( $column ) ) {
            // Install precedes the warning listeners, so the logger must receive this directly.
            MainWP_Logger::instance()->warning( sprintf( 'Backup snapshot test_restore_result_at repair deferred for %s: %s', $table, $error ) );
            update_site_option( static::BACKUP_SNAPSHOT_TEST_RESTORE_RESULT_AT_REPAIR_PENDING, time() );
            return false;
        }
        delete_site_option( static::BACKUP_SNAPSHOT_TEST_RESTORE_RESULT_AT_REPAIR_PENDING );
        return true;
    }

    /**
     * Denied ALTER statements must not run on every request.
     *
     * @return void
     */
    public function maybe_retry_backup_snapshot_test_restore_result_at(): void {
        $pending = (int) get_site_option( static::BACKUP_SNAPSHOT_TEST_RESTORE_RESULT_AT_REPAIR_PENDING );
        if ( $pending && time() - $pending >= HOUR_IN_SECONDS ) {
            $this->ensure_backup_snapshot_test_restore_result_at();
        }
    }

    /**
     * Pruning must omit the cause column until its deferred repair succeeds.
     *
     * @return bool Whether no schema repair is pending.
     */
    public static function backup_snapshot_removal_reason_ready(): bool {
        return ! get_site_option( static::BACKUP_SNAPSHOT_REMOVAL_REASON_REPAIR_PENDING );
    }

    /**
     * Pruning must keep working when recording its cause needs a later repair.
     *
     * @return bool Whether the column exists.
     */
    public function ensure_backup_snapshot_removal_reason(): bool {
        $suppress = $this->wpdb->suppress_errors();
        $table    = esc_sql( $this->table_name( 'wp_backup_snapshot' ) );
        $column   = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'removal_reason'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
        $error    = '';
        if ( empty( $column ) ) {
            $this->wpdb->query( "ALTER TABLE {$table} ADD COLUMN removal_reason varchar(20) NOT NULL DEFAULT ''" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
            $error  = $this->wpdb->last_error;
            $column = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'removal_reason'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
        }
        $this->wpdb->suppress_errors( $suppress );
        if ( empty( $column ) ) {
            // Install precedes the warning listeners, so the logger must receive this directly.
            MainWP_Logger::instance()->warning( sprintf( 'Backup snapshot removal_reason repair deferred for %s: %s', $table, $error ) );
            update_site_option( static::BACKUP_SNAPSHOT_REMOVAL_REASON_REPAIR_PENDING, time() );
            return false;
        }
        delete_site_option( static::BACKUP_SNAPSHOT_REMOVAL_REASON_REPAIR_PENDING );
        return true;
    }

    /**
     * Denied ALTER statements must not run on every request.
     *
     * @return void
     */
    public function maybe_retry_backup_snapshot_removal_reason(): void {
        $pending = (int) get_site_option( static::BACKUP_SNAPSHOT_REMOVAL_REASON_REPAIR_PENDING );
        if ( $pending && time() - $pending >= HOUR_IN_SECONDS ) {
            $this->ensure_backup_snapshot_removal_reason();
        }
    }

    /**
     * Retry writes must wait until both cleanup columns are available.
     *
     * @return bool Whether no schema repair is pending.
     */
    public static function backup_snapshot_child_prune_ready(): bool {
        return ! get_site_option( static::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING );
    }

    /**
     * Pruning must keep working when its cleanup bookkeeping needs a later repair.
     *
     * @return bool Whether both columns exist.
     */
    public function ensure_backup_snapshot_child_prune(): bool {
        $suppress = $this->wpdb->suppress_errors();
        $table    = esc_sql( $this->table_name( 'wp_backup_snapshot' ) );
        $missing  = array();
        $columns  = array(
            'child_prune_confirmed_at' => 'int(11)',
            'child_prune_attempts'     => 'smallint(5) unsigned',
        );
        foreach ( $columns as $name => $definition ) {
            $column = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
            $error  = '';
            if ( empty( $column ) ) {
                $this->wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition} NOT NULL DEFAULT 0" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped; columns and definitions are fixed above.
                $error  = $this->wpdb->last_error;
                $column = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
            }
            if ( empty( $column ) ) {
                $missing[] = $name . ': ' . $error;
            }
        }
        $this->wpdb->suppress_errors( $suppress );
        if ( ! empty( $missing ) ) {
            // Install precedes the warning listeners, so the logger must receive this directly.
            MainWP_Logger::instance()->warning( sprintf( 'Backup snapshot child prune repair deferred for %s: %s', $table, implode( '; ', $missing ) ) );
            update_site_option( static::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING, time() );
            return false;
        }
        delete_site_option( static::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING );
        return true;
    }

    /**
     * Denied ALTER statements must not run on every request.
     *
     * @return void
     */
    public function maybe_retry_backup_snapshot_child_prune(): void {
        $pending = (int) get_site_option( static::BACKUP_SNAPSHOT_CHILD_PRUNE_REPAIR_PENDING );
        if ( $pending && time() - $pending >= HOUR_IN_SECONDS ) {
            $this->ensure_backup_snapshot_child_prune();
        }
    }

    /**
     * A failed dbDelta must not leave every restore checkpoint write refused.
     *
     * @return bool Whether the column exists.
     */
    public function ensure_backup_restore_job_checkpoint_rev(): bool {
        $suppress = $this->wpdb->suppress_errors();
        $table    = esc_sql( $this->table_name( 'wp_backup_restore_job' ) );
        $column   = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'checkpoint_rev'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
        $error    = '';
        if ( empty( $column ) ) {
            $this->wpdb->query( "ALTER TABLE {$table} ADD COLUMN checkpoint_rev int(11) unsigned NOT NULL DEFAULT 0" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
            $error  = $this->wpdb->last_error;
            $column = $this->wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'checkpoint_rev'" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table escaped via esc_sql.
        }
        $this->wpdb->suppress_errors( $suppress );
        if ( empty( $column ) ) {
            // Install precedes the warning listeners, so the logger must receive this directly.
            MainWP_Logger::instance()->warning( sprintf( 'Restore job checkpoint_rev repair deferred for %s: %s', $table, $error ) );
            update_site_option( static::BACKUP_RESTORE_JOB_CHECKPOINT_REV_REPAIR_PENDING, time() );
            return false;
        }
        delete_site_option( static::BACKUP_RESTORE_JOB_CHECKPOINT_REV_REPAIR_PENDING );
        return true;
    }

    /**
     * Denied ALTER statements must not run on every request.
     *
     * @return void
     */
    public function maybe_retry_backup_restore_job_checkpoint_rev(): void {
        $pending = (int) get_site_option( static::BACKUP_RESTORE_JOB_CHECKPOINT_REV_REPAIR_PENDING );
        if ( $pending && time() - $pending >= HOUR_IN_SECONDS ) {
            $this->ensure_backup_restore_job_checkpoint_rev();
        }
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
