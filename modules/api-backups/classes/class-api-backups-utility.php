<?php
/**
 * Class Api_Backups_Utility
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class Api_Backups_Utility
 */
class Api_Backups_Utility { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Instance value.
     *
     * @var static Default null
     */
    public static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Api_Backups_Utility
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method __construct.
     */
    public function __construct() {
        // contructor.
    }

    /**
     * Method count_child_sites().
     */
    public function count_child_sites() {
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_wp_for_current_user() );

        if ( ! empty( $websites ) ) {
            $sites_count = MainWP_DB::num_rows( $websites );
        }
        // Free result set.
        Api_Backups_Helper::free_result( $websites );

        return $sites_count;
    }

    /**
     * Get timestamp.
     *
     * @param int $timestamp Holds Timestamp.
     *
     * @return float|int Return GMT offset.
     */
    public static function get_timestamp( $timestamp = false ) {
        if ( empty( $timestamp ) ) {
            $timestamp = time();
        }
        $gmtOffset = get_option( 'gmt_offset' );

        return $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp;
    }

    /**
     * Format timestamp.
     *
     * @param int  $timestamp Holds Timestamp.
     * @param bool $gmt Whether to set as General mountain time. Default: FALSE.
     *
     * @return string Return Timestamp.
     */
    public static function format_timestamp( $timestamp, $gmt = false ) {
        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, $gmt );
    }

    /**
     * Format datestamp.
     *
     * @param int  $timestamp Holds Timestamp.
     * @param bool $gmt Whether to set as General mountain time. Default: FALSE.
     *
     * @return string Return Timestamp.
     */
    public static function format_datestamp( $timestamp, $gmt = false ) {
        return date_i18n( get_option( 'date_format' ), $timestamp, $gmt );
    }

    /**
     * Method map_fields()
     *
     * Map Site.
     *
     * @param mixed $website Website to map.
     * @param mixed $keys Keys to map.
     * @param bool  $object_output Output format array|object.
     *
     * @return object $outputSite Mapped site.
     */
    public static function map_fields( &$website, $keys, $object_output = false ) { //phpcs:ignore -- NOSONAR - complex.
        $outputSite = array();
        if ( ! empty( $website ) ) {
            if ( is_object( $website ) ) {
                foreach ( $keys as $key ) {
                    if ( property_exists( $website, $key ) ) {
                        $outputSite[ $key ] = $website->$key;
                    }
                }
            } elseif ( is_array( $website ) ) {
                foreach ( $keys as $key ) {
                    $outputSite[ $key ] = $website[ $key ];
                }
            }
        }

        if ( $object_output ) {
            return (object) $outputSite;
        } else {
            return $outputSite;
        }
    }

    /**
     * Method array_sort()
     *
     * Sort given array by given flags.
     *
     * @param mixed  $arr Array to sort.
     * @param mixed  $key Array key.
     * @param string $sort_flag Flags to sort by. Default = SORT_STRING.
     */
    public static function array_sort( &$arr, $key, $sort_flag = SORT_STRING ) {
        $sorter = array();
        $ret    = array();
        reset( $arr );
        foreach ( $arr as $ii => $val ) {
            $sorter[ $ii ] = $val[ $key ];
        }
        asort( $sorter, $sort_flag );
        foreach ( $sorter as $ii => $val ) {
            $ret[ $ii ] = $arr[ $ii ];
        }
        $arr = $ret;
    }

    /**
     * Debugging log info.
     *
     * Sets logging for debugging purpose
     *
     * @param string $message Log info message.
     */
    public static function log_info( $message ) {
        static::log_debug( $message, 2 );
    }

    /**
     * Debugging log.
     *
     * Sets logging for debugging purpose.
     *
     * @param string $message Log debug message.
     * @param int    $log_color Log color.
     */
    public static function log_debug( $message, $log_color = 3 ) {
        if ( is_array( $message ) || is_object( $message ) ) {
            $message = print_r( $message, true ); //phpcs:ignore
        } elseif ( ! is_string( $message ) ) {
            $message = 'undefined';
        }

        if ( ! in_array( $log_color, array( 0, 1, 2, 3 ) ) ) {
            $log_color = 3;
        }
        do_action( 'mainwp_log_action', 'API Backups :: ' . $message, MainWP_Logger::API_BACKUPS_LOG_PRIORITY, $log_color );
    }

    /**
     *
     * Method save_lasttime_backup().
     *
     * @param int    $site_id  site id.
     * @param int    $available_backups Available backups.
     * @param string $backup_api API backups provider.
     *
     * @return mixed
     */
    public static function save_lasttime_backup( $site_id, $available_backups, $backup_api ) { //phpcs:ignore -- NOSONAR - complex method.

        static::log_debug( 'save backup time :: [available backups=' . ( is_string( $available_backups ) ? $available_backups : 'is not string' ) . ']' );

        if ( empty( $available_backups ) ) {
            return;
        }

        if ( is_string( $available_backups ) ) {
            $available_backups = json_decode( $available_backups );
        }

        $primaryBackup = MainWP_System_Utility::get_primary_backup();

        $website = Api_Backups_Helper::get_website_by_id( $site_id );

        $backup_method = '';
        if ( is_array( $website ) && isset( $website['primary_backup_method'] ) ) {
            if ( '' === $website['primary_backup_method'] || 'global' === $website['primary_backup_method'] ) {
                $backup_method = $primaryBackup;
            } else {
                $backup_method = $website['primary_backup_method'];
            }
        }

        if ( 'module-api-backups' !== $backup_method ) {
            return;
        }

        $lasttime_backup = 0;
        if ( 'plesk' === $backup_api ) {
            $available_backups = is_object( $available_backups ) && ! empty( $available_backups->value ) ? $available_backups->value : array();
            if ( is_array( $available_backups ) ) {
                foreach ( $available_backups as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->value->createdAt->value ) ) {
                        $backup_time = strtotime( $backup->value->createdAt->value );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
        } elseif ( 'kinsta' === $backup_api ) {
            $available_backups = is_array( $available_backups ) && ! empty( $available_backups ) ? $available_backups : array();
            if ( is_array( $available_backups ) ) {
                foreach ( $available_backups as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->created_at ) ) {
                        $milliseconds = $backup->created_at;
                        $epoch        = $milliseconds / 1000;
                        $backup_time  = strtotime( $epoch );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
        } elseif ( 'cpanel_auto' === $backup_api ) {
            if ( is_object( $available_backups ) && isset( $available_backups->data ) ) {
                $available_backups_automatic_list = $available_backups->data;
            } else {
                $available_backups_automatic_list = array();
            }

            if ( is_array( $available_backups_automatic_list ) ) {
                foreach ( $available_backups_automatic_list as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->backupID ) ) {
                        $backup_time = strtotime( $backup->backupID );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
        } elseif ( 'cpanel_manual' === $backup_api ) {
            $available_backups_manual_list = $available_backups;
            if ( is_array( $available_backups_manual_list ) ) {
                foreach ( $available_backups_manual_list as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->timestamp ) ) {
                        $backup_time = $backup->timestamp;
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
        } elseif ( 'cpanel_wp_toolkit' === $backup_api ) {
            $available_backups_manual_list = $available_backups;
            if ( is_array( $available_backups_manual_list ) ) {
                foreach ( $available_backups_manual_list as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->value->createdAt->value ) ) {
                        $backup_time = strtotime( $backup->value->createdAt->value );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
        } elseif ( 'gridpane' === $backup_api ) {
            if ( isset( $available_backups->automatic ) ) {
                $available_backups_automatic_list = explode( ',', $available_backups->automatic );
            } else {
                $available_backups_automatic_list = array();
            }
            if ( isset( $available_backups->manual ) ) {
                $available_backups_manual_list = explode( ',', $available_backups->manual );
            } else {
                $available_backups_manual_list = array();
            }
            // to do check date created: $api_response->backups->local.

            if ( is_array( $available_backups_automatic_list ) ) {
                foreach ( $available_backups_automatic_list as $backup_name ) {
                    $backup_time = substr( $backup_name, 0, 16 ); // get date time string in name.
                    $backup_time = strtotime( $backup_time );
                    if ( $backup_time > $lasttime_backup ) {
                        $lasttime_backup = $backup_time;
                    }
                }
            }

            if ( is_array( $available_backups_manual_list ) ) {
                foreach ( $available_backups_manual_list as $backup_name ) {
                    $backup_time = substr( $backup_name, 0, 16 ); // get date time string in name.
                    $backup_time = strtotime( $backup_time );
                    if ( $backup_time > $lasttime_backup ) {
                        $lasttime_backup = $backup_time;
                    }
                }
            }
        } elseif ( 'cloudways' === $backup_api ) {
            if ( isset( $available_backups->application_backup_exists ) && true === $available_backups->application_backup_exists ) {
                $available_backups = $available_backups->backup_dates;
            } else {
                $available_backups = array();
            }

            if ( is_array( $available_backups ) ) {
                foreach ( $available_backups as $backup ) {
                    $backup_time = strtotime( $backup );
                    if ( $backup_time > $lasttime_backup ) {
                        $lasttime_backup = $backup_time;
                    }
                }
            }
        } elseif ( 'vultr' === $backup_api ) {
            if ( isset( $available_backups->snapshots ) ) {
                $available_snapshots_list = $available_backups->snapshots;
            } else {
                $available_snapshots_list = array();
            }
            if ( isset( $available_backups->backups ) ) {
                $available_backups_list = $available_backups->backups;
            } else {
                $available_backups_list = array();
            }
            if ( is_array( $available_snapshots_list ) ) {
                foreach ( $available_snapshots_list as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->date_created ) ) {
                        $backup_time = strtotime( $backup->date_created );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
            if ( is_array( $available_backups_list ) ) {
                foreach ( $available_backups_list as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->date_created ) ) {
                        $backup_time = strtotime( $backup->date_created );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
        } elseif ( 'linode' === $backup_api ) {
            // Automatic Backups.
            if ( isset( $available_backups->automatic ) ) {
                $available_backups_automatic_list = $available_backups->automatic;
            } else {
                $available_backups_automatic_list = array();
            }
            // Manual Backups.
            if ( isset( $available_backups->snapshot ) ) {
                $available_backups_snapshot_list = $available_backups->snapshot;
            } else {
                $available_backups_snapshot_list = array();
            }
            if ( is_array( $available_backups_automatic_list ) ) {
                foreach ( $available_backups_automatic_list as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->created ) ) {
                        $backup_time = strtotime( $backup->created );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
            if ( is_array( $available_backups_snapshot_list ) ) {
                foreach ( $available_backups_snapshot_list as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->created ) ) {
                        $backup_time = strtotime( $backup->created );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
        } elseif ( 'digitalocean' === $backup_api ) {
            if ( isset( $available_backups->snapshots ) ) {
                $available_backups = $available_backups->snapshots;
            }
            if ( is_array( $available_backups ) ) {
                foreach ( $available_backups as $backup ) {
                    if ( is_object( $backup ) && ! empty( $backup->created_at ) ) {
                        $backup_time = strtotime( $backup->created_at );
                        if ( $backup_time > $lasttime_backup ) {
                            $lasttime_backup = $backup_time;
                        }
                    }
                }
            }
        }

        if ( empty( $lasttime_backup ) ) {
            return;
        }
        $lastBackup = MainWP_DB::instance()->get_website_option( $site_id, 'primary_lasttime_backup' );

        if ( ! empty( $lastBackup ) && $lastBackup > $lasttime_backup ) {
            return;
        }
        static::log_debug( 'save backup time :: [site-id=' . $site_id . '] :: [backup time=' . gmdate( 'Y-m-d H:i:s', $lasttime_backup ) . '] :: [api-backups=' . $backup_api . ']' );
        $site     = new \stdClass();
        $site->id = $site_id;
        MainWP_DB::instance()->update_website_option( $site, 'primary_lasttime_backup', $lasttime_backup );
    }

    /**
     * Show Info Messages
     *
     * Check whenther or not to show the MainWP Message.
     *
     * @param string $notice_id Notice ID.
     *
     * @return bool False if hidden, true to show.
     */
    public static function show_mainwp_message( $notice_id ) {
        $status = get_user_option( 'mainwp_notice_saved_status' );
        if ( ! is_array( $status ) ) {
            $status = array();
        }
        if ( isset( $status[ $notice_id ] ) ) {
            return false;
        }
        return true;
    }


    /**
     * Method human_filesize()
     *
     * Convert to human readable file size format,
     * (B|kB|MB|GB|TB|PB|EB|ZB|YB).
     *
     * @param mixed   $bytes File in bytes.
     * @param integer $decimals Number of decimals to output.
     *
     * @return string Human readable file size.
     */
    public static function human_filesize( $bytes, $decimals = 2 ) {
        $size   = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
        $factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

        return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . @$size[ $factor ]; //phpcs:ignore
    }


    /**
     * Get Color Code
     *
     * Returns CSS class to use correct color for the element.
     *
     * @param int $value value.
     *
     * @return string CSS class.
     */
    public static function color_code( $value ) {
        $color = '';
        if ( 5 < $value ) {
            $color = 'red';
        } elseif ( 0 < $value && 5 >= $value ) {
            $color = 'yellow';
        } else {
            $color = 'green';
        }
        return $color;
    }

    /**
     * Method update_option()
     *
     * Update option.
     *
     * @param mixed $option_name Option name.
     * @param mixed $option_value Option value.
     *
     * @return (boolean) False if value was not updated and true if value was updated.
     */
    public static function update_option( $option_name, $option_value ) {
        $success = add_option( $option_name, $option_value, '', 'no' );

        if ( ! $success ) {
            $success = update_option( $option_name, $option_value );
        }

        return $success;
    }


    /**
     * Method encrypt_api_keys
     *
     * @param string $data data.
     * @param int    $siteid site id.
     * @param string $file_key file key.
     * @param string $service_name service name.
     *
     * Encrypt data.
     */
    public function encrypt_api_keys( $data, $siteid = false, $file_key = false, $service_name = '' ) {
        if ( is_string( $data ) ) {

            $prefix = 'apibackups_';

            if ( ! empty( $siteid ) ) {
                $prefix = $prefix . intval( $siteid ) . '_';
            }

            if ( ! empty( $service_name ) && is_string( $service_name ) ) {
                $prefix = $prefix . $service_name . '_';
            }

            $result = apply_filters( 'mainwp_encrypt_key_value', false, $data, $prefix, $file_key );

            if ( is_array( $result ) && ! empty( $result['encrypted_val'] ) ) {
                return $result;
            }
        }
        return $data;
    }

    /**
     * Method decrypt_api_keys
     *
     * @param mixed  $encrypted_data encrypted data.
     * @param string $def_val default data.
     */
    public function decrypt_api_keys( $encrypted_data, $def_val = '' ) {
        if ( ! empty( $encrypted_data ) && is_array( $encrypted_data ) && ! empty( $encrypted_data['encrypted_val'] ) ) { // old format.
            $result = apply_filters( 'mainwp_decrypt_key_value', false, $encrypted_data, $def_val );
            if ( ! empty( $result ) && is_string( $result ) ) {
                return $result;
            }
        }
        return $def_val;
    }

    /**
     * Method get_compatible_site_api_key
     *
     * Encrypt data.
     *
     * @param string $name name data.
     * @param string $def_val default data.
     */
    public function get_api_key( $name, $def_val = '' ) {

        $names = array(
            'vultr',
            'gridpane',
            'linode',
            'digitalocean',
            'cloudways',
            'cpanel',
            'plesk',
            'kinsta',
        );

        if ( ! in_array( $name, $names ) ) {
            return $def_val;
        }

        $encrypted = get_option( 'mainwp_api_backups_' . $name . '_api_key' );
        if ( is_array( $encrypted ) && ! empty( $encrypted['file_key'] ) ) {
            $key = $this->decrypt_api_keys( $encrypted );
            if ( ! empty( $key ) && is_string( $key ) ) {
                return $key;
            }
        }
        return $def_val;
    }

    /**
     * Method update_compatible_site_api_key
     *
     * Encrypt data.
     *
     * @param string $name name data.
     * @param string $value value data.
     */
    public function update_api_key( $name, $value ) {

        $names = array(
            'vultr',
            'gridpane',
            'linode',
            'digitalocean',
            'cloudways',
            'cpanel',
            'plesk',
            'kinsta',
        );

        if ( ! in_array( $name, $names ) ) {
            return false;
        }

        $opt_name = 'mainwp_api_backups_' . $name . '_api_key';
        $current  = get_option( $opt_name );
        $key_file = '';

        if ( is_array( $current ) && ! empty( $current['file_key'] ) ) {
            $key_file = $current['file_key'];
        }

        if ( empty( $value ) ) {
            delete_option( $opt_name );
            if ( ! empty( $key_file ) ) {
                do_action( 'mainwp_delete_key_file', $key_file );
            }
            return true;
        }

        $encrypted = $this->encrypt_api_keys( $value, false, $key_file, $name );
        if ( is_array( $encrypted ) && ! empty( $encrypted['file_key'] ) ) {
            return update_option( $opt_name, $encrypted );
        }

        return false;
    }

    /**
     * Method get child site key.
     *
     * Encrypt data.
     *
     * @param int    $website_id name data.
     * @param string $name name data.
     * @param string $def_val default data.
     */
    public function get_child_api_key( $website_id, $name, $def_val = '' ) {

        $names = array(
            'vultr',
            'gridpane',
            'linode',
            'digitalocean',
            'cloudways',
            'cpanel',
            'plesk',
            'kinsta',
        );

        if ( ! in_array( $name, $names ) ) {
            return $def_val;
        }

        $opt_name    = 'mainwp_api_backups_' . $name . '_api_key';
        $opt_encoded = Api_Backups_Helper::get_website_options( $website_id, array( $opt_name ) );
        if ( array_key_exists( $opt_name, $opt_encoded ) ) {
            $encrypted = json_decode( $opt_encoded[ $opt_name ], true );
        } else {
            $encrypted = '';
        }

        if ( is_array( $encrypted ) && ! empty( $encrypted['file_key'] ) ) {
            $key = $this->decrypt_api_keys( $encrypted );

            if ( ! empty( $key ) && is_string( $key ) ) {
                return $key;
            }
        }
        return $def_val;
    }

    /**
     * Method update child api key.
     *
     * Encrypt data.
     *
     * @param int    $website_id name data.
     * @param string $name name data.
     * @param string $value value data.
     */
    public function update_child_api_key( $website_id, $name, $value ) {

        $names = array(
            'vultr',
            'gridpane',
            'linode',
            'digitalocean',
            'cloudways',
            'cpanel',
            'plesk',
            'kinsta',
        );

        if ( ! in_array( $name, $names ) ) {
            return false;
        }

        $opt_name    = 'mainwp_api_backups_' . $name . '_api_key';
        $opt_encoded = Api_Backups_Helper::get_website_options( $website_id, array( $opt_name ) );
        if ( array_key_exists( $opt_name, $opt_encoded ) ) {
            $current = json_decode( $opt_encoded[ $opt_name ], true );
        } else {
            $current = '';
        }
        $key_file = '';
        if ( is_array( $current ) && ! empty( $current['file_key'] ) ) {
            $key_file = $current['file_key'];
        }

        // If post $value is empty, delete option.
        if ( empty( $value ) ) {
            delete_option( $opt_name );
            if ( ! empty( $key_file ) ) {
                do_action( 'mainwp_delete_key_file', $key_file );
            }
            return true;
        }

        $encrypted = wp_json_encode( $this->encrypt_api_keys( $value, $website_id, $key_file, $name ) );

        if ( ! empty( $encrypted ) ) {
            return Api_Backups_Helper::update_website_option( $website_id, $opt_name, $encrypted );
        }

        return false;
    }
}
