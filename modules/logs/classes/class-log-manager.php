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
use MainWP\Dashboard\MainWP_Logger;

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
     * Holds the last log created time.
     *
     * @var array
     */
    private static $last_log_created;


    /**
     * Locations.
     *
     * @var array URLs and Paths used by the plugin.
     */
    public $locations = array();

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

        add_action( 'mainwp_delete_site', array( $this, 'hook_delete_site' ), 10, 3 );

        // Load admin area classes.
        if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            $this->admin = new Log_Admin( $this );
        } elseif ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            $this->admin = new Log_Admin( $this, $driver );
        }

        add_filter( 'mainwp_module_log_enable_insert_log_type', array( $this, 'hook_enable_insert_log_type' ), 10, 2 );
        add_filter( 'mainwp_get_cron_jobs_init', array( $this, 'hook_get_cron_jobs_init' ), 10, 2 ); // on/off by change status of use wp cron option.
        add_filter( 'mainwp_module_logs_changes_logs_sync_params', array( $this, 'hook_changes_logs_sync_params' ), 10, 2 ); // on/off by change status of use wp cron option.

        if ( $this->is_enabled_auto_archive_logs() && ! empty( $this->settings->options['records_logs_ttl'] ) ) {
            add_action( 'mainwp_module_log_cron_job_auto_archive', array( $this, 'cron_module_log_auto_archive' ) );
        }
        if ( ! empty( $this->settings->options['enabled'] ) ) {
            add_action( 'mainwp_module_log_render_db_size_notice', array( $this->admin, 'render_logs_db_notice' ), 10, 1 );
        }
        add_action( 'mainwp_module_log_render_db_update_notice', array( $this->admin, 'render_update_db_notice' ), 10, 1 );
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
     * Method get_sync_actions_last_created().
     *
     * Sync site changes logs data.
     *
     * @param int $site_id site id.
     *
     * @return bool
     */
    public function get_sync_actions_last_created( $site_id ) {

        if ( null === static::$last_log_created ) {
            $site_opts = MainWP_DB::instance()->get_website_options_array( $site_id, array( 'non_mainwp_changes_sync_last_created' ) );

            if ( ! is_array( $site_opts ) ) {
                $site_opts = array();
            }

            static::$last_log_created = isset( $site_opts['non_mainwp_changes_sync_last_created'] ) ? $site_opts['non_mainwp_changes_sync_last_created'] : 0;

            // to sure.
            if ( empty( static::$last_log_created ) ) {
                static::$last_log_created = time();
            }
        }

        return static::$last_log_created;
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

        $sync_last_created = $this->get_sync_actions_last_created( $site_id );

        $new_last_created = 0;

        foreach ( $sync_actions as $data ) {
            if ( ! is_array( $data ) || empty( $data['action_user'] ) || empty( $data['created'] ) ) {
                continue;
            }

            if ( (float) $data['created'] <= (float) $sync_last_created ) {
                continue;
            }

            if ( $new_last_created < $data['created'] ) {
                $new_last_created = $data['created'];
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

            $user_login = sanitize_text_field( wp_unslash( $data['action_user'] ) );

            $sum = '';
            if ( false !== $extra_info ) {
                $meta_data['extra_info'] = wp_json_encode( $extra_info );
                $sum                    .= ! empty( $extra_info['name'] ) ? esc_html( $extra_info['name'] ) : 'WP Core';
            } else {
                $sum .= ! empty( $meta_data['name'] ) ? esc_html( $meta_data['name'] ) : 'WP Core';
            }
            $sum .= ' ';
            $sum .= 'wordpress' !== $data['context'] ? esc_html( ucfirst( rtrim( $data['context'], 's' ) ) ) : 'WordPress'; //phpcs:ignore -- wordpress text.

            if ( 'wordpress' === $data['context'] ) {
                $sum = 'WordPress';
            }

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
                'plugins'   => 'plugin',
                'themes'    => 'theme',
                'wordpress' => 'core',
            );

            $action  = isset( $actions_mapping[ $data['action'] ] ) ? $actions_mapping[ $data['action'] ] : $data['action'];
            $context = isset( $contexts_mapping[ $data['context'] ] ) ? $contexts_mapping[ $data['context'] ] : $data['context'];

            $created = isset( $data['created'] ) ? (float) $data['created'] : microtime( true );

            $record_mapping = array(
                'site_id'    => $site_id,
                'user_id'    => $user_id,
                'user_login' => $user_login,
                'created'    => $created,
                'item'       => $sum,
                'context'    => $context,
                'action'     => $action,
                'state'      => 1,
                'duration'   => isset( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : 0, // sanitize_text_field for seconds.
                'meta'       => $meta_data,
            );

            do_action( 'mainwp_sync_site_log_install_actions', $website, $record_mapping );
        }

        if ( $new_last_created ) {
            MainWP_DB::instance()->update_website_option( $site_id, 'non_mainwp_changes_sync_last_created', $new_last_created );
        }

        return true;
    }

    /**
     * Method is_enabled_auto_archive_logs().
     *
     * @return bool True|False Enabled auto archive log or not.
     */
    public function is_enabled_auto_archive_logs() {
        return is_array( $this->settings->options ) && ! empty( $this->settings->options['enabled'] ) && ! empty( $this->settings->options['auto_archive'] ) ? true : false;
    }

    /**
     * Method hook_delete_site()
     *
     * @param mixed $site site object.
     *
     * @return bool result.
     */
    public function hook_delete_site( $site ) {
        if ( empty( $site ) ) {
            return false;
        }
        return Log_DB_Helper::instance()->remove_logs_by( $site->id );
    }

    /**
     * Method hook_enable_insert_log_type()
     *
     * @param bool  $enabled Enable input value.
     * @param array $data Log data.
     *
     * @return bool True: Enable log.
     */
    public function hook_enable_insert_log_type( $enabled, $data ) {
        if ( is_array( $data ) && ! empty( $data['log_type_id'] ) ) {
            return Log_Settings::is_action_log_enabled( $data['log_type_id'], 'changeslogs' );
        } elseif ( is_array( $data ) && isset( $data['connector'] ) && isset( $data['context'] ) && isset( $data['action'] ) ) {
            if ( 'non-mainwp-changes' === $data['connector'] ) {
                return Log_Settings::is_action_log_enabled( $data['context'] . '_' . $data['action'], 'nonmainwpchanges' );
            } elseif ( 'compact' !== $data['connector'] ) {
                return Log_Settings::is_action_log_enabled( $data['context'] . '_' . $data['action'], 'dashboard' );
            }
        }
        return $enabled;
    }

    /**
     * Method hook_get_cron_jobs_init()
     *
     * @param array $init_jobs Jobs to init.
     *
     * @return array Init Jobs.
     */
    public function hook_get_cron_jobs_init( $init_jobs ) {
        if ( $this->is_enabled_auto_archive_logs() ) {
            $init_jobs['mainwp_module_log_cron_job_auto_archive'] = 'daily';
        }
        return $init_jobs;
    }

    /**
     * Method cron_module_log_auto_archive()
     */
    public function cron_module_log_auto_archive() {
        $ttl = 3 * YEAR_IN_SECONDS;
        if ( is_array( $this->settings->options ) && isset( $this->settings->options['records_logs_ttl'] ) ) {
            $ttl = intval( $this->settings->options['records_logs_ttl'] );
        }
        if ( $ttl ) {
            do_action( 'mainwp_log_action', 'Module Log :: Archive logs schedule start.', MainWP_Logger::LOGS_AUTO_PURGE_LOG_PRIORITY );
            $time   = time();
            $before = $time - $ttl;
            Log_DB_Helper::instance()->archive_sites_changes( $before );
            update_option( 'mainwp_module_log_last_time_auto_archive_logs', $time );
            update_option( 'mainwp_module_log_next_time_auto_archive_logs', $time + $ttl );
        }
    }

    /**
     * Method hook_changes_logs_sync_params()
     *
     * @param  string $params Input value.
     * @param  int    $site_id Site id.
     * @param  array  $postdata Post data.
     *
     * @return string Empty or json encoded value.
     *
     * @since 5.5
     */
    public function hook_changes_logs_sync_params( $params, $site_id, $postdata = array() ) {

        // if it is not a manual sync data.
        $sync_logs = isset( $_POST['action'] ) && 'mainwp_syncsites' === $_POST['action'] ? true : false;
        $sync_logs = apply_filters( 'mainwp_module_logs_sync_changes_log', $sync_logs );

        if ( ! $sync_logs ) {
            return $params;
        }

        $last_created = Log_Changes_Logs_Helper::instance()->get_sync_changes_logs_last_created( $site_id );
        $events_count = apply_filters( 'mainwp_module_log_changes_logs_sync_count', 100, $site_id, $postdata );
        return array(
            'newer_than'   => $last_created,
            'events_count' => $events_count,
        );
    }
}
