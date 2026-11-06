<?php
/**
 * MainWP Database Logs.
 *
 * This file handles all interactions with the Client DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Log_DB
 *
 * @package MainWP\Dashboard
 */
class Log_DB extends MainWP_DB {

    /**
     * Holds the driver instance
     *
     * @var Log_DB_Driver
     */
    public $driver;

    /**
     * Number of records in last request
     *
     * @var int
     */
    protected $found_records_count = 0;


    /**
     * Hold logs sites options.
     *
     * @var int
     */
    protected $logs_wp_options;


    /**
     * Hold logs extra data.
     *
     * @var int
     */
    protected $logs_extra_data = array();


    /**
     * Constructor.
     *
     * Run each time the class is called.
     *
     * @param array $driver  db driver.
     */
    public function __construct( $driver ) {
        parent::__construct();
        $this->driver = $driver;
    }

    /**
     * Insert a record
     *
     * @param array $record  New record.
     *
     * @return int
     */
    public function insert( $record ) {
        if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
            return false;
        }

        /**
         * Filter allows modification of record information
         *
         * @param array $record
         *
         * @return array
         */
        $record = apply_filters( 'mainwp_module_log_record_array', $record );

        /**
         * Hook mainwp_module_log_enable_insert_log_type.
         *
         * @since 5.5.
         */
        $enable_log_type = apply_filters( 'mainwp_module_log_enable_insert_log_type', true, $record );

        if ( ! $enable_log_type ) {
            return false;
        }

        $data = $this->sanitize_record( $record );

        if ( empty( $data ) ) {
            return false;
        }

        $record_id = $this->driver->insert_record( $data );

        if ( ! $record_id ) {
            /**
             * Fires on a record insertion error
             *
             * @param array $record
             * @param mixed $result
             */
            do_action( 'mainwp_module_log_record_insert_error', $record, false );

            return false;
        }

        /**
         * Fires after a record has been inserted
         *
         * @param int   $record_id
         * @param array $record
         */
        do_action( 'mainwp_module_log_record_inserted', $record_id, $record );

        return absint( $record_id );
    }

    /**
     * Ensure the record matches our schema.
     *
     * @param array $record Record to store.
     *
     * @return array
     */
    protected function sanitize_record( $record ) {
        if ( ! is_array( $record ) ) {
            return array();
        }

        $record_defaults = array(
            'site_id'     => null,
            'user_id'     => null,
            'user_login'  => null,
            'created'     => null,
            'log_type_id' => null,
            'item'        => null,
            'connector'   => null,
            'context'     => null,
            'action'      => null,
            'state'       => null,
            'duration'    => null,
            'meta'        => array(),
        );

        // Records can have only these fields.
        $record = array_intersect_key( $record, $record_defaults );

        // Sanitize all record values.
        return array_map(
            function ( $value ) {
                if ( ! is_array( $value ) ) {
                    return wp_strip_all_tags( $value );
                }

                return $value;
            },
            $record
        );
    }

    /**
     * Get logs records
     *
     * @param array $args  Arguments to filter result by.
     *
     * @return array Log Records
     */
    public function get_records( $args ) {
        $defaults = array(
            // Search param.
            'search'           => null,
            'search_field'     => 'item',
            'records_per_page' => get_option( 'posts_per_page', 20 ),
            'paged'            => 1,
            // Order.
            'order'            => 'desc',
            'orderby'          => 'date',
        );

        $args = wp_parse_args( $args, $defaults );

        /**
         * Filter allows additional arguments to query $args
         *
         * @return array  Array of query arguments
         */
        $args = apply_filters( 'mainwp_module_log_query_args', $args );

        $result                    = (array) $this->driver->get_records( $args );
        $this->found_records_count = isset( $result['count'] ) ? $result['count'] : 0;
        $this->logs_wp_options     = isset( $result['sites_opts'] ) && is_array( $result['sites_opts'] ) ? $result['sites_opts'] : array();
        $this->logs_extra_data     = isset( $result['extra_data'] ) && is_array( $result['extra_data'] ) ? $result['extra_data'] : array();

        return empty( $result['items'] ) ? array() : $result['items'];
    }

    /**
     * Helper function, backwards compatibility
     *
     * @param array $args  Argument to filter result by.
     *
     * @return array Log Records
     */
    public function query( $args ) {
        return $this->get_records( $args );
    }

    /**
     * Return the number of records found in last request
     *
     * @return int
     */
    public function get_found_records_count() {
        return $this->found_records_count;
    }

    /**
     * Return the number of records found in last request
     *
     * @return int
     */
    public function get_logs_sites_opts() {
        return $this->logs_wp_options;
    }

    /**
     * Return the number of records found in last request
     *
     * @return int
     */
    public function get_logs_extra_data() {
        return $this->logs_extra_data;
    }

    /**
     * Public getter to return table names
     *
     * @return array
     */
    public function get_table_names() {
        return $this->driver->get_table_names();
    }


    /**
     * Metho check if site action log existed.
     *
     * @param int    $site_id Site Id.
     * @param string $object_id Object Id.
     *
     * @return mixed Results.
     */
    public function is_site_action_log_existed( $site_id = false, $object_id = false ) { //phpcs:ignore -- NOSONAR compatible.
        return false;
    }
}
