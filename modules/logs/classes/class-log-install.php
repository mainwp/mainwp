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
	protected $log_db_version = '1.0.1.2';

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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Class constructor
	 */
	public function __construct() { // phpcs:ignore -- overrided.
		parent::__construct();
	}

	/**
	 * Method handle to install module log tables.
	 */
	public function install() {

		global $wpdb;

		// get_site_option is multisite aware!
		$currentVersion = get_site_option( $this->log_db_option_key );

		$rslt = $this->query( "SHOW TABLES LIKE '" . $this->table_name( 'wp_logs' ) . "'" );
		if ( empty( self::num_rows( $rslt ) ) ) {
			$currentVersion = false;
		}

		if ( $currentVersion === $this->log_db_version ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_logs' ) . " (
	log_id bigint(20) NOT NULL auto_increment,
	site_id bigint(20) unsigned NULL,
	item text NOT NULL,
	user_id int(11) unsigned NOT NULL DEFAULT '0',
	action varchar(100) NOT NULL,
	context varchar(100) NOT NULL,
	connector varchar(100) NOT NULL,
	state tinyint(1) unsigned NULL,
	created int(11) NOT NULL DEFAULT 0,
	duration float(11,4) NOT NULL DEFAULT '0',
	KEY site_id (site_id),
	KEY user_id (user_id),
	KEY created (created),
	KEY duration (duration),
	KEY context (context),
	KEY connector (connector),
	KEY action (action),
	KEY state (state)";

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
	KEY meta_key (meta_key(191))';

		if ( empty( $currentVersion ) ) {
			$tbl .= ',
		PRIMARY KEY  (`meta_id`)  ';
		}

		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		if ( MainWP_Utility::instance()->is_disabled_functions( 'error_log' ) || ! function_exists( '\error_log' ) ) {
			error_reporting(0); // phpcs:ignore -- try to disabled the error_log somewhere in WP.
		}

		$this->update_check_modify( $currentVersion );

		$suppress = $wpdb->suppress_errors();
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
		$wpdb->suppress_errors( $suppress );

		MainWP_Utility::update_option( $this->log_db_option_key, $this->log_db_version );
	}

	/**
	 * Method handle update db tables and data.
	 *
	 * @param string $currentVersion current version.
	 */
	public function update_check_modify( $currentVersion ) {

		if ( empty( $currentVersion ) ) {
			return;
		}
	}
}
