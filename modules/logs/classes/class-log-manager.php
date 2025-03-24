<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Execution_Helper;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Log_Manager
 *
 * @package MainWP\Dashboard
 */
class Log_Manager {

    /**
     * Version
     *
     * @const string Plugin version number.
     * */
    const VERSION = '5.0.0';

    /**
     * Log_Admin
     *
     * @var \MainWP\Dashboard\Module\Log\Log_Admin Admin class.
     * */
    public $admin;

    /**
     * MainWP_Execution_Helper
     *
     * @var \MainWP\Dashboard\MainWP_Execution_Helper class.
     * */
    public $executor;

    /**
     * Holds Instance of settings object
     *
     * @var Log_Settings
     */
    public $settings;

    /**
     * Log_Connectors
     *
     * @var \MainWP\Dashboard\Module\Log\Log_Connectors Connectors class.
     * */
    public $connectors;

    /**
     * Log_DB
     *
     * @var \MainWP\Dashboard\Module\Log\Log_DB DB Class.
     * */
    public $db;

    /**
     * Log
     *
     * @var \MainWP\Dashboard\Module\Log\Log Log Class.
     * */
    public $log;

    /**
     * Log_Install class.
     *
     * @var \MainWP\Dashboard\Module\Log\Log_Install Install class.
     * */
    public $install;

    /**
     * Locations.
     *
     * @var array URLs and Paths used by the plugin.
     */
    public $locations = array();

    /**
     * Last log created.
     *
     * @var int last time.
     * */
    public static $last_non_mainwp_action_last_object_id = null;

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Return the single instance of the class.
     *
     * @return mixed $instance The single instance of the class.
     */
    public static function instance() {
        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Plugin constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {

        $mod_log_dir     = MAINWP_MODULES_DIR . 'logs/';
        $this->locations = array(
            'dir'       => $mod_log_dir,
            'url'       => MAINWP_MODULES_URL . 'logs/',
            'inc_dir'   => $mod_log_dir . 'includes/',
            'class_dir' => $mod_log_dir . 'classes/',
        );

        spl_autoload_register( array( $this, 'autoload' ) );

        // Load helper functions.
        require_once $this->locations['inc_dir'] . 'functions.php'; // NOSONAR - WP compatible.

        $driver         = new Log_DB_Driver_WPDB();
        $this->db       = new Log_DB( $driver );
        $this->executor = MainWP_Execution_Helper::instance();
        $this->settings = new Log_Settings( $this );

        // Load logger class.
        $this->log = new Log( $this );

        // Load settings and connectors after widgets_init and before the default init priority.
        add_action( 'init', array( $this, 'init' ), 9 );

        // Change DB driver after plugin loaded if any add-ons want to replace.
        add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 20 );

        // Load admin area classes.
        if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            $this->admin = new Log_Admin( $this );
        } elseif ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            $this->admin = new Log_Admin( $this, $driver );
        }
    }

    /**
     * Autoloader for classes.
     *
     * @param string $class_name class name.
     */
    public function autoload( $class_name ) {

        if ( ! preg_match( '/^(?P<namespace>.+)\\\\(?P<autoload>[^\\\\]+)$/', $class_name, $matches ) ) {
            return;
        }

        static $reflection;

        if ( empty( $reflection ) ) {
            $reflection = new \ReflectionObject( $this );
        }

        if ( $reflection->getNamespaceName() !== $matches['namespace'] ) {
            return;
        }

        $autoload_name = $matches['autoload'];
        $autoload_dir  = \trailingslashit( $this->locations['dir'] );
        $load_dirs     = array(
            'classes' => 'class',
            'pages'   => 'page',
            'widgets' => 'widget',
        );
        foreach ( $load_dirs as $dir => $prefix ) {
            $dir           = $dir . '/';
            $autoload_path = sprintf( '%s%s%s-%s.php', $autoload_dir, $dir, $prefix, strtolower( str_replace( '_', '-', $autoload_name ) ) );
            if ( is_readable( $autoload_path ) ) {
                require_once $autoload_path; // NOSONAR - WP compatible.
                return;
            }
        }
    }


    /**
     * Get internal connectors.
     */
    public function get_internal_connectors() {
        return array(
            'compact',
        );
    }

    /**
     * Load Log_Connectors.
     *
     * @action init
     *
     * @uses \MainWP\Dashboard\Module\Log\Log_Connectors
     */
    public function init() {
        $this->connectors = new Log_Connectors( $this );
    }

    /**
     * Getter for the version number.
     *
     * @return string
     */
    public function get_version() {
        return static::VERSION;
    }

    /**
     * Change plugin database driver in case driver plugin loaded after logs.
     *
     * @uses \MainWP\Dashboard\Module\Log\Log_DB
     * @uses \MainWP\Dashboard\Module\Log\Log_DB_Driver_WPDB
     */
    public function plugins_loaded() {
        // Load DB helper interface/class.
        $driver_class = '\MainWP\Dashboard\Module\Log\Log_DB_Driver_WPDB';

        if ( class_exists( $driver_class ) ) {
            $driver   = new $driver_class();
            $this->db = new Log_DB( $driver );
        }
    }


    /**
     * Method sync_log_site_actions().
     *
     * Sync site actions data.
     *
     * @param int    $site_id site id.
     * @param array  $sync_actions action data.
     * @param object $website website data.
     *
     * @return bool
     */
    public function sync_log_site_actions( $site_id, $sync_actions, $website ) { // phpcs:ignore -- NOSONAR - complex.

        if ( empty( $sync_actions ) || ! is_array( $sync_actions ) ) {
            return false;
        }

        MainWP_Utility::array_sort_existed_keys( $sync_actions, 'created', SORT_NUMERIC );

        foreach ( $sync_actions as $index => $data ) {
            $object_id = sanitize_text_field( $index );

            if ( ! is_array( $data ) || empty( $data['action_user'] ) || empty( $data['created'] ) || empty( $object_id ) ) {
                continue;
            }

            if ( null === static::$last_non_mainwp_action_last_object_id ) {
                static::$last_non_mainwp_action_last_object_id = (int) MainWP_DB::instance()->get_website_option( $website, 'non_mainwp_action_last_object_id', 0 );
            }

            if ( (int) $data['created'] <= static::$last_non_mainwp_action_last_object_id ) {
                continue;
            }

            if ( $this->db->is_site_action_log_existed( $website->id, $object_id ) ) {
                continue;
            }

            $user_meta  = array();
            $meta_data  = array();
            $extra_info = false;

            if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
                $meta_data = $data['meta_data'];
                if ( isset( $meta_data['user_meta'] ) && is_array( $meta_data['user_meta'] ) ) {
                    $user_meta = $meta_data['user_meta']; // to compatible old user_meta site changes actions data.
                    unset( $meta_data['user_meta'] );
                } elseif ( isset( $meta_data['meta_data'] ) && ! empty( $meta_data['meta_data'] ) && is_array( $meta_data['meta_data'] ) ) {
                    $user_meta = $meta_data['meta_data']; // new meta_data site changes actions.
                    unset( $meta_data['meta_data'] );
                }
                $meta_data['user_meta_json'] = wp_json_encode( $user_meta );
                if ( isset( $meta_data['extra_info'] ) && is_array( $meta_data['extra_info'] ) ) {
                    $extra_info = $meta_data['extra_info'];
                }
            }

            $sum = '';
            if ( false !== $extra_info ) {
                $meta_data['extra_info'] = wp_json_encode( $extra_info );
                $sum                    .= ! empty( $extra_info['name'] ) ? esc_html( $extra_info['name'] ) : 'WP Core';
            } else {
                $sum .= ! empty( $meta_data['name'] ) ? esc_html( $meta_data['name'] ) : 'WP Core';
            }
            $sum .= ' ';
            $sum .= 'wordpress' !== $data['context'] ? esc_html( ucfirst( rtrim( $data['context'], 's' ) ) ) : 'WordPress'; //phpcs:ignore -- wordpress text.

            if ( isset( $user_meta['wp_user_id'] ) ) {
                $user_id = ! empty( $user_meta['wp_user_id'] ) ? sanitize_text_field( $user_meta['wp_user_id'] ) : 0;
            } elseif ( ! empty( $user_meta['user_id'] ) ) { // to compatible with old child actions data.
                $user_id = sanitize_text_field( $user_meta['user_id'] );
            }

            $actions_mapping = array(
                'installed'   => 'install',
                'deleted'     => 'delete',
                'activated'   => 'activate',
                'deactivated' => 'deactivate',
            );

            $contexts_mapping = array(
                'wordpress' => 'core',
            );

            $action  = isset( $actions_mapping[ $data['action'] ] ) ? $actions_mapping[ $data['action'] ] : $data['action'];
            $context = isset( $contexts_mapping[ $data['context'] ] ) ? $contexts_mapping[ $data['context'] ] : $data['context'];

            $record_mapping = array(
                'site_id'   => $site_id,
                'object_id' => $object_id,
                'user_id'   => $user_id,
                'created'   => $data['created'],
                'item'      => $sum,
                'context'   => $context,
                'action'    => $action,
                'state'     => 1,
                'duration'  => isset( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : 0, // sanitize_text_field for seconds.
                'meta'      => $meta_data,
            );

            do_action( 'mainwp_sync_site_log_install_actions', $website, $record_mapping, $object_id );
        }

        return true;
    }
}
