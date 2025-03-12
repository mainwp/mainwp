<?php
/**
 * Includes files.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Logs class.
 */
class MainWP_Includes { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Public static variable to hold the plugin dir.
     *
     * @static
     *
     * @var string Default MainWP dashboard plugin dir.
     */
    public static $plugin_basedir = MAINWP_PLUGIN_DIR;

    /**
     * Load required files and hooks to make the CLI work.
     */
    public function __construct() {
        // constructor.
    }

    /**
     * Load files.
     */
    public function includes() {
        require_once static::$plugin_basedir . 'includes/class-mainwp-setup.php'; // NOSONAR - WP compatible.
        require_once static::$plugin_basedir . 'includes/class-mainwp-datetime.php'; // NOSONAR - WP compatible.
        if ( file_exists( static::$plugin_basedir . 'modules/common/class-module-log.php' ) ) {
            require_once static::$plugin_basedir . 'modules/common/class-module-log.php'; // NOSONAR - WP compatible.
        }
        if ( file_exists( static::$plugin_basedir . 'modules/common/class-module-cost-tracker.php' ) ) {
            require_once static::$plugin_basedir . 'modules/common/class-module-cost-tracker.php'; // NOSONAR - WP compatible.
        }
        if ( file_exists( static::$plugin_basedir . 'modules/common/class-module-api-backups.php' ) ) {
            require_once static::$plugin_basedir . 'modules/common/class-module-api-backups.php'; // NOSONAR - WP compatible.
        }
    }
}
