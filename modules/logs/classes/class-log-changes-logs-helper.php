<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Logger;

/**
 * Class Log_Changes_Logs_Helper
 *
 * @package MainWP\Dashboard
 */
class Log_Changes_Logs_Helper {


    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Holds the last log created time.
     *
     * @var array
     */
    private static $last_log_created;

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
    }

    /**
     * Method get_sync_changes_logs_last_created().
     *
     * Sync site changes logs data.
     *
     * @param int $site_id site id.
     *
     * @return bool
     */
    public function get_sync_changes_logs_last_created( $site_id ) {

        if ( null === static::$last_log_created ) {
            $site_opts = MainWP_DB::instance()->get_website_options_array( $site_id, array( 'changes_logs_sync_last_created' ) );

            if ( ! is_array( $site_opts ) ) {
                $site_opts = array();
            }

            static::$last_log_created = isset( $site_opts['changes_logs_sync_last_created'] ) ? $site_opts['changes_logs_sync_last_created'] : 0;

            // to sure.
            if ( empty( static::$last_log_created ) ) {
                static::$last_log_created = time() - DAY_IN_SECONDS; // to prevent "strange" issue.
                MainWP_DB::instance()->update_website_option( $site_id, 'changes_logs_sync_last_created', static::$last_log_created );
            }
        }

        return static::$last_log_created;
    }


    /**
     * Method sync_changes_logs().
     *
     * Sync site changes logs data.
     *
     * @param int    $site_id site id.
     * @param array  $sync_changes action data.
     * @param object $website website data.
     *
     * @return bool
     */
    public function sync_changes_logs( $site_id, $sync_changes, $website ) { // phpcs:ignore -- NOSONAR - complex.

        MainWP_Logger::instance()->log_events( 'sites-changes', 'Sync changes logs :: [site_id=' . $site_id . '] :: [count=' . ( is_array( $sync_changes ) ? count( $sync_changes ) : 0 ) . ']' );

        if ( empty( $sync_changes ) || ! is_array( $sync_changes ) ) {
            return false;
        }

        $sync_last_created          = $this->get_sync_changes_logs_last_created( $site_id );
        $new_last_created           = 0;
        $disabled_type_last_created = 0;

        $created_success = false;

        $ignored_meta_fields = apply_filters( 'mainwp_module_log_sync_ignored_meta_fields', array( 'userdata', 'user_meta' ) );

        if ( ! is_array( $ignored_meta_fields ) ) {
            $ignored_meta_fields = array();
        }

        foreach ( $sync_changes as $data ) {

            if ( ! is_array( $data ) || empty( $data['created_on'] ) || empty( $data['log_type_id'] ) ) {
                continue;
            }

            $item_created = round( (float) $data['created_on'], 4 ); // to fix float comparing issue.

            if ( $item_created <= $sync_last_created ) {
                continue;
            }

            // to fix issue of last sync value.
            if ( $new_last_created < $item_created ) {
                $new_last_created = $item_created;
            }

            $enable_log_type = apply_filters( 'mainwp_module_log_enable_insert_log_type', true, $data );
            if ( ! $enable_log_type ) {
                // to fix issue of last sync value.
                if ( $disabled_type_last_created < $item_created ) {
                    $disabled_type_last_created = $item_created;
                }
                continue;
            }

            $type_id    = intval( $data['log_type_id'] );
            $user_id    = ! empty( $data['user_id'] ) ? sanitize_text_field( $data['user_id'] ) : 0;
            $user_login = ! empty( $data['username'] ) ? sanitize_text_field( $data['username'] ) : '';

            if ( empty( $user_login ) ) {
                continue;
            }

            $meta_data = isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ? $data['meta_data'] : array();
            $user_meta = isset( $meta_data['user_meta'] ) && is_array( $meta_data['user_meta'] ) ? $meta_data['user_meta'] : array();

            $meta                   = array();
            $meta['user_meta_json'] = wp_json_encode( $user_meta );
            $meta['client_ip'] = $data['client_ip'];

            if ( is_array( $meta_data ) ) {
                foreach ( $meta_data as $key => $val ) {
                    if ( in_array( $key, $ignored_meta_fields ) ) {
                        continue;
                    }
                    $meta[ $key ] = $val;
                }
            }

            $action = isset( $data['action_name'] ) ? $this->map_changes_action( $data['action_name'] ) : '';

            $context = '';

            if ( isset( $data['context'] ) ) {
                $context = $this->map_change_logs_context( $data['context'] );
            } elseif ( isset( $data['log_type_id'] ) ) {
                $context = $this->map_change_logs_context( '', $data['log_type_id'] );
            }

            $duration = isset( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : 0; // sanitize_text_field for seconds.
            $created  = isset( $data['created_on'] ) ? (float) ( $data['created_on'] ) : microtime( true );

            $record_mapping = array(
                'site_id'     => $site_id,
                'user_id'     => $user_id,
                'user_login'  => $user_login,
                'created'     => $created,
                'context'     => $context,
                'action'      => $action,
                'state'       => 1,
                'duration'    => $duration,
                'meta'        => $meta,
                'log_type_id' => $type_id,
            );

            $sum = 'wordpress' !== $record_mapping['context'] ? esc_html( ucfirst( rtrim( $record_mapping['context'], 's' ) ) ) : 'WordPress'; //phpcs:ignore -- wordpress text.
            $record_mapping['item'] = $sum;
            $inserted_id            = apply_filters( 'mainwp_sync_site_log_changes_logs', false, $website, $record_mapping );
            if ( $inserted_id ) {
                $created_success = true;
            }
        }

        // to fix last created sync.
        $update_sync_last_created = 0;
        if ( $new_last_created && $created_success ) {
            $update_sync_last_created = $new_last_created;
        }

        if ( $disabled_type_last_created && $update_sync_last_created < $disabled_type_last_created ) {
            $update_sync_last_created = $disabled_type_last_created;
        }

        if ( ! empty( $update_sync_last_created ) ) {
            MainWP_DB::instance()->update_website_option( $site_id, 'changes_logs_sync_last_created', $update_sync_last_created );
        }

        return true;
    }

    /**
     * Method map_changes_action.
     *
     * @param string $event Logs event.
     *
     * @return string Dashboard logs actions to store in db.
     */
    public function map_changes_action( $event ) {

        $map_actions = array(
            'installed'   => 'install',
            'deleted'     => 'delete',
            'activated'   => 'activate',
            'deactivated' => 'deactivate',
        );

        return isset( $map_actions[ $event ] ) ? $map_actions[ $event ] : $event;
    }

    /**
     * Method map_change_logs_context()
     *
     * @param string $context Log context|object.
     * @param int    $type_id Logs type code.
     *
     * @return string Dashboard logs context to store in db.
     */
    public function map_change_logs_context( $context, $type_id = 0 ) {
        if ( ! empty( $type_id ) ) {
            $context = isset( static::get_changes_logs_types( $type_id )['context'] ) ? static::get_changes_logs_types( $type_id )['context'] : '';
        }
        $context = 'cron-job' === $context ? 'cron' : $context;
        return \apply_filters( 'mainwp_module_log_changes_logs_mapping_contexts', $context, $type_id );
    }

    /**
     * Get custom event title.
     *
     * @param int    $log_type_id    - Event type id.
     * @param array  $data    - Event data.
     * @param string $type    - Title type.
     *
     * @return string
     */
    public static function get_changes_log_title( $log_type_id, $data, $type ) {

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        $title = '';

        if ( 'action' === $type ) {
            $title = isset( static::get_changes_logs_types( $log_type_id )['msg'] ) ? static::get_changes_logs_types( $log_type_id )['msg'] : '';
        } elseif ( 'object' === $type ) {
            $title = isset( static::get_changes_logs_types( $log_type_id )['object_msg'] ) ? static::get_changes_logs_types( $log_type_id )['object_msg'] : '';
        }

        if ( ! empty( $title ) ) {
            if ( isset( $data['action'] ) ) {
                $title = str_replace( '%action%', $data['action'], $title );
            }
            if ( isset( $data['plugin'] ) ) {
                $title = str_replace( '%plugin%', $data['plugin'], $title );
            }
            if ( isset( $data['theme'] ) ) {
                $title = str_replace( '%theme%', $data['theme'], $title );
            }
            if ( isset( $data['name'] ) ) {
                $title = str_replace( '%name%', $data['name'], $title );
            }
            return $title;
        }
        return '';
    }

    /**
     * Method get_changes_logs_types().
     *
     * @param int $type_id Logs type code.
     *
     * @return array data.
     */
    public static function get_changes_logs_types( $type_id = null ) { //phpcs:ignore -- NOSONAR - long function.

        $tran_loc = 'mainwp'; //phpcs:ignore -- NOSONAR - used in default-logs.php.

        static $defaults;
        if ( null === $defaults ) {
            $defaults = include MAINWP_MODULES_DIR . 'logs/includes/default-logs.php'; //phpcs:ignore -- NOSONAR ok.
        }

        if ( ! is_array( $defaults ) ) {
            $defaults = array();
        }

        if ( null !== $type_id ) {
            return is_scalar( $type_id ) && isset( $defaults[ $type_id ] ) ? $defaults[ $type_id ] : array();
        }
        return $defaults;
    }
}
