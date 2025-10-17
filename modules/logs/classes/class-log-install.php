<?php
/**
 *
 * This file handles all interactions with the Log DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Log_Install
 *
 * @package MainWP\Dashboard
 */
class Log_Install extends MainWP_Install {

    /**
     * Protected variable to hold the database version info.
     *
     * @var string DB version info.
     */
    public $log_db_version = '1.0.1.50'; // NOSONAR - no IP.

    /**
     * Protected variable to hold the database option name.
     *
     * @var string DB version info.
     */
    protected $log_db_option_key = 'mainwp_module_log_db_version';

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
     * @return instance of class.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method handle to install module log tables.
     */
    public function install() {

        global $wpdb;

        // get_site_option is multisite aware!
        $currentVersion = get_option( $this->log_db_option_key );

        $rslt = $this->query( "SHOW TABLES LIKE '" . $this->table_name( 'wp_logs' ) . "'" );
        if ( empty( static::num_rows( $rslt ) ) ) {
            $currentVersion = false;
        }

        if ( $currentVersion === $this->log_db_version ) {
            return;
        }

        $this->update_log_db_60_before_dbDelta( $currentVersion );

        $charset_collate = $wpdb->get_charset_collate();

        $tbl = 'CREATE TABLE ' . $this->table_name( 'wp_logs' ) . " (
    log_id bigint(20) NOT NULL auto_increment,
    site_id bigint(20) unsigned NULL,
    log_type_id bigint NOT NULL DEFAULT 0,
    item varchar(256) NOT NULL DEFAULT '',
    user_id int(11) unsigned NOT NULL DEFAULT '0',
    user_login varchar(100) NOT NULL,
    action varchar(100) NOT NULL,
    context varchar(100) NOT NULL,
    connector varchar(100) NOT NULL,
    state tinyint(1) unsigned NULL,
    created created BIGINT(20) UNSIGNED NOT NULL,
    duration float(11,4) NOT NULL DEFAULT '0',
    dismiss tinyint(1) NOT NULL DEFAULT 0,
    KEY site_id (site_id),
    KEY user_id (user_id),
    KEY user_login (user_login),
    KEY created (created),
    KEY duration (duration),
    KEY context (context),
    KEY connector (connector),
    KEY action (action),
    KEY state (state),
    KEY idx_site_created(site_id, created),
    KEY item (item(191))";

        if ( empty( $currentVersion ) ) {
            $tbl .= ',
        PRIMARY KEY (log_id)';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        $tbl = 'CREATE TABLE ' . $this->table_name( 'wp_logs_meta' ) . ' (
    meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    meta_log_id bigint(20) unsigned NOT NULL,
    meta_key varchar(200) NOT NULL,
    meta_value mediumtext NOT NULL,
    KEY meta_log_id (meta_log_id),
    UNIQUE KEY meta_unique (meta_log_id, meta_key(191))';

        if ( empty( $currentVersion ) ) {
            $tbl .= ',
        PRIMARY KEY  (`meta_id`)  ';
        }

        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WP compatible.

        global $wpdb;

        if ( MainWP_Utility::instance()->is_disabled_functions( 'error_log' ) || ! function_exists( '\error_log' ) ) {
            error_reporting(0); // phpcs:ignore -- try to disabled the error_log somewhere in WP.
        }

        $suppress = $this->wpdb->suppress_errors();
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
        $this->update_log_db( $currentVersion );
        $this->update_log_db_60( $currentVersion );

        $this->wpdb->suppress_errors( $suppress );

        if ( empty( $currentVersion ) ) {
            $this->create_archive_tables();
        }
        MainWP_Utility::update_option( $this->log_db_option_key, $this->log_db_version );
        $wpdb->suppress_errors( $suppress );
    }

    /**
     * Method update module log tables.
     *
     * @param string $currentVersion Current db version.
     */
    public function update_log_db( $currentVersion ) {

        $is_db_ver_with_archive = version_compare( $currentVersion, '1.0.1.8', '>=' );

        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '1.0.1.9', '<' ) ) { // NOSONAR - non-ip.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs' ) . ' MODIFY COLUMN item varchar(256) NOT NULL DEFAULT ""' ); //phpcs:ignore -- ok.
        }

        if ( ! empty( $currentVersion ) && $is_db_ver_with_archive && version_compare( $currentVersion, '1.0.1.10', '<' ) ) { // NOSONAR - non-ip.
            $this->create_archive_tables();
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' ADD COLUMN archived_at int(11) NOT NULL DEFAULT 0' ); //phpcs:ignore -- ok.
        }

        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '1.0.1.11', '<' ) ) { // NOSONAR - non-ip.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs' ) . ' ADD INDEX item (item(191))' ); //phpcs:ignore -- ok.
        }

        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '1.0.1.16', '<' ) ) { // NOSONAR - non-ip.

            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs' ) . ' DROP COLUMN object_id' ); //phpcs:ignore -- ok.
            if ( $is_db_ver_with_archive ) {
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' DROP INDEX created' ); //phpcs:ignore -- ok.
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' DROP INDEX index_site_object_id' ); //phpcs:ignore -- ok.
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' DROP COLUMN object_id' ); //phpcs:ignore -- ok.

                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' MODIFY COLUMN created double NOT NULL' ); //phpcs:ignore -- ok.
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' ADD INDEX created ( created )' ); //phpcs:ignore -- ok.
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' ADD INDEX idx_site_created(site_id, created)' ); //phpcs:ignore -- ok.
            }
        }

        if ( ! empty( $currentVersion ) && $is_db_ver_with_archive && version_compare( $currentVersion, '1.0.1.20', '<' ) ) { // NOSONAR - non-ip.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' ADD COLUMN user_login varchar(100) NOT NULL' ); //phpcs:ignore -- ok.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' ADD INDEX user_login ( user_login )' ); //phpcs:ignore -- ok.
        }

        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '1.0.1.26', '<' ) ) { // NOSONAR - non-ip.
            $count = Log_DB_Helper::instance()->count_legacy_dismissed();
            if ( ! empty( $count ) ) {
                update_option( 'mainwp_module_logs_updates_dismissed_db_process_status', 'require_update' );
            }
        }

        if ( $is_db_ver_with_archive && version_compare( $currentVersion, '1.0.1.29', '<' ) ) { // NOSONAR - non-ip.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' MODIFY log_id bigint(20) NOT NULL' ); //phpcs:ignore -- ok.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_meta_archive' ) . ' MODIFY meta_id bigint(20) unsigned' ); //phpcs:ignore -- ok.
        }
        if ( version_compare( $currentVersion, '1.0.1.7', '>=' ) && version_compare( $currentVersion, '1.0.1.9', '<' ) ) { // NOSONAR - non-ip.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs' ) . ' DROP INDEX state' ); //phpcs:ignore -- ok.
        }

        if ( ! empty( $currentVersion ) && $is_db_ver_with_archive && version_compare( $currentVersion, '1.0.1.35', '<' ) ) { // NOSONAR - non-ip.
            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' ADD COLUMN log_type_id bigint NOT NULL DEFAULT 0' ); //phpcs:ignore -- ok.
        }
    }

    /**
     * Method update module log tables.
     *
     * @param string $currentVersion Current db version.
     */
    private function update_log_db_60_before_dbDelta( $currentVersion ) {

        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '1.0.1.48', '<' ) ) { // NOSONAR - non-ip.
            global $wpdb;

            $meta_table = $this->table_name( 'wp_logs_meta' );

            $wpdb->query("ALTER TABLE {$meta_table} DROP INDEX meta_log_id_key, DROP INDEX meta_key"); // phpcs:ignore -- ok.

            // Check if duplicates exist.
            //phpcs:ignore -- ok.
            $has_duplicates = $wpdb->get_var( "
                SELECT COUNT(*) FROM (
                    SELECT 1
                    FROM {$meta_table}
                    GROUP BY meta_log_id, meta_key
                    HAVING COUNT(*) > 1
                ) dup
            "
            );

            if ( $has_duplicates > 0 ) {
                // Backup duplicates first.
                //phpcs:ignore -- ok.
                $wpdb->query( "CREATE TABLE IF NOT EXISTS {$meta_table}_dup_backup LIKE {$meta_table}" );

                $wpdb->query(
                    "
                    INSERT IGNORE INTO {$meta_table}_dup_backup
                    SELECT *
                    FROM {$meta_table}
                    WHERE (meta_log_id, meta_key) IN (
                        SELECT meta_log_id, meta_key
                        FROM {$meta_table}
                        GROUP BY meta_log_id, meta_key
                        HAVING COUNT(*) > 1
                    )
                "
                );

                // Delete duplicates, keep newest (MAX meta_id).
                //phpcs:ignore -- ok.
                $wpdb->query( "
                    DELETE t
                    FROM {$meta_table} t
                    JOIN (
                        SELECT meta_log_id, meta_key, MAX(meta_id) AS keep_id
                        FROM {$meta_table}
                        GROUP BY meta_log_id, meta_key
                        HAVING COUNT(*) > 1
                    ) dup
                    ON t.meta_log_id = dup.meta_log_id
                    AND t.meta_key    = dup.meta_key
                    WHERE t.meta_id <> dup.keep_id
                "
                );
            }
        }
    }

    /**
     * Method update module log tables.
     *
     * @param string $currentVersion Current db version.
     */
    private function update_log_db_60( $currentVersion ) {
        $is_db_ver_with_archive = version_compare( $currentVersion, '1.0.1.8', '>=' );
        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '1.0.1.40', '<' ) ) { // NOSONAR - non-ip.

            // to save microsecords.
            if ( $is_db_ver_with_archive ) {
                $this->wpdb->query( 'UPDATE ' . $this->table_name( 'wp_logs' ) . ' SET created = ROUND(created * 1000000)' ); //phpcs:ignore -- ok.
            }

            $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs' ) . ' MODIFY COLUMN created BIGINT(20) UNSIGNED NOT NULL' ); //phpcs:ignore -- ok.

            if ( $is_db_ver_with_archive ) {
                $this->wpdb->query( 'UPDATE ' . $this->table_name( 'wp_logs_archive' ) . ' SET created = ROUND(created * 1000000)' ); //phpcs:ignore -- ok.
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'wp_logs_archive' ) . ' MODIFY COLUMN created BIGINT(20) UNSIGNED NOT NULL' ); //phpcs:ignore -- ok.
            }
        }

        $this->update_changes_logs_meta_60( $currentVersion );
    }

    /**
     * Method update_changes_logs_meta_60().
     *
     * @param  mixed $currentVersion
     * @return void
     */
    private function update_changes_logs_meta_60( $currentVersion ) {

        if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '1.0.1.46', '<' ) ) {

            $log_table  = $this->table_name( 'wp_logs' );
            $meta_table = $this->table_name( 'wp_logs_meta' );

            $meta_items = $this->wpdb->get_results(
                "
                    SELECT m.meta_log_id, m.meta_value
                    FROM {$log_table} l
                    JOIN {$meta_table} m
                    ON l.log_id = m.meta_log_id
                    WHERE m.meta_key = 'extra_info'
                    AND (l.context = 'plugin' OR l.context = 'theme')
                ",
                ARRAY_A
            );

            foreach ( $meta_items as $row ) {

                $extra_info = json_decode( $row['meta_value'], true );
                if ( ! is_array( $extra_info ) ) {
                    continue;
                }

                $key = '';
                $val = '';

                if ( ! empty( $extra_info['slug'] ) ) {
                    $key = 'slug';
                    $val = $extra_info['slug'];
                } elseif ( ! empty( $extra_info['name'] ) ) {
                    $key = 'name';
                    $val = $extra_info['name'];
                }

                if ( empty( $key ) || empty( $val ) ) {
                    continue;
                }

                $this->wpdb->replace(
                    $meta_table,
                    array(
                        'meta_log_id' => (int) $row['meta_log_id'],
                        'meta_key'    => $key, //phpcs:ignore -- ok.
                        'meta_value'  => $val, //phpcs:ignore -- ok.
                    ),
                    array( '%d', '%s', '%s' )
                );
            }
        }
    }

    /**
     * Method get_current_logs_db_ver().
     */
    public function get_current_logs_db_ver() {
        return get_option( $this->log_db_option_key );
    }

    /**
     * Create archive_ logs tables.
     */
    public function create_archive_tables() {
        $this->wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . $this->table_name( 'wp_logs_archive' ) . ' LIKE ' . $this->table_name( 'wp_logs' ) ); //phpcs:ignore -- ok.
        $this->wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . $this->table_name( 'wp_logs_meta_archive' ) . ' LIKE ' . $this->table_name( 'wp_logs_meta' ) ); //phpcs:ignore -- ok.
    }
}
