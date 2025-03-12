<?php
/**
 * MainWP Module Cost Tracker DB class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class Cost_Tracker_DB
 */
class Cost_Tracker_DB extends MainWP_Install {
    //phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
    /**
     * Variable to hold the db version.
     *
     * @var string Version.
     */
    private $cost_tracker_db_version = '1.0.18';


    /**
     * Static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Cost_Tracker_DB
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Init functions.
     */
    public function init() {
        $this->install();
    }

    /**
     * Install db.
     */
    public function install() {
        global $wpdb;

        $currentVersion = get_site_option( 'mainwp_module_cost_tracker_db_version' );

        $rslt = $this->query( "SHOW TABLES LIKE '" . $this->table_name( 'cost_tracker' ) . "'" );
        if ( empty( static::num_rows( $rslt ) ) ) {
            $currentVersion = false;
        }

        if ( $currentVersion === $this->cost_tracker_db_version ) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql             = array();

        $tbl = 'CREATE TABLE `' . $this->table_name( 'cost_tracker' ) . '` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`name` text NOT NULL,
`url` text NOT NULL,
`type` varchar(20) NOT NULL,
`product_type` varchar(20) NOT NULL,
`slug` varchar(191) NOT NULL DEFAULT "",
`license_type` varchar(20) NOT NULL,
`cost_status` varchar(20) NOT NULL,
`payment_method` varchar(50) NOT NULL,
`price` decimal(26,8) NOT NULL,
`renewal_type` varchar(20) NOT NULL,
`last_renewal` int(11) NOT NULL,
`next_renewal` int(11) NOT NULL,
`next_renewal_today` int(11) NOT NULL,
`last_alert` int(11) NOT NULL,
`cost_icon` varchar(64) NOT NULL DEFAULT "",
`cost_color` varchar(64) NOT NULL DEFAULT "",
`sites` text NOT NULL,
`groups` text NOT NULL,
`clients` text NOT NULL,
`note` text NOT NULL';

        if ( empty( $currentVersion ) ) {
            $tbl .= ',
PRIMARY KEY  (`id`)  ';
        }
        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WP compatible.
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
        $this->update_db_cost( $currentVersion );
        update_option( 'mainwp_module_cost_tracker_db_version', $this->cost_tracker_db_version );
    }


    /**
     * Method update_db_cost().
     *
     * @param array $current_version DB version number.
     *
     * @return void
     */
    public function update_db_cost( $current_version ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! empty( $current_version ) ) {
            if ( version_compare( $current_version, '1.0.8', '<' ) ) {
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'cost_tracker' ) . ' MODIFY COLUMN price decimal(26,8)' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
            if ( version_compare( $current_version, '1.0.9', '<' ) ) {
                $this->wpdb->query( 'ALTER TABLE ' . $this->table_name( 'cost_tracker' ) . ' DROP COLUMN author' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }

            if ( version_compare( $current_version, '1.0.13', '<' ) ) {
                $costs = $this->get_cost_tracker_by( 'all' );

                foreach ( $costs as $cost ) {
                    $obj_name = '';
                    if ( ! empty( $cost->sites ) ) {
                        $items    = json_decode( $cost->sites, true );
                        $obj_name = 'site';
                    } elseif ( ! empty( $cost->groups ) ) {
                        $items    = json_decode( $cost->groups, true );
                        $obj_name = 'tag';
                    } elseif ( ! empty( $cost->clients ) ) {
                        $items    = json_decode( $cost->clients, true );
                        $obj_name = 'client';
                    } else {
                        continue;
                    }

                    if ( ! empty( $items ) ) {
                        foreach ( $items as $it_id ) {
                            $this->wpdb->insert(
                                $this->table_name( 'lookup_item_objects' ),
                                array(
                                    'item_id'     => $cost->id,
                                    'item_name'   => 'cost',
                                    'object_id'   => $it_id,
                                    'object_name' => $obj_name,
                                )
                            );

                        }
                    }
                }
            }
        }
    }

    /**
     * Method update_cost_tracker().
     *
     * @param array $update Cost tracker array data.
     *
     * @throws \MainWP_Exception Existed cost tracker error.
     * @return mixed Result
     */
    public function update_cost_tracker( $update ) { //phpcs:ignore -- NOSONAR - complex.
        /**
         * WP database.
         *
         * @global object
         */
        global $wpdb;

        if ( ! is_array( $update ) ) {
            return false;
        }

        $id = isset( $update['id'] ) ? $update['id'] : 0;

        if ( ! empty( $update['product_type'] ) && ! empty( $update['slug'] ) && in_array( $update['product_type'], array( 'plugin', 'theme' ), true ) ) {
            // check existed cost tracker for this plugin / theme .
            $current = $this->get_cost_tracker_by( 'slug', $update['slug'], $update['product_type'] );
            if ( is_array( $current ) && ! empty( $current ) ) {
                $existed = false;
                if ( 1 === count( $current ) ) {
                    $current = current( $current );
                    if ( is_object( $current ) ) {
                        if ( ! empty( $update['id'] ) ) {
                            if ( ! empty( $current->id ) && (int) $current->id !== (int) $update['id'] ) {
                                $existed = true; // to fix.
                            } elseif ( ! empty( $current->id ) ) {
                                $id = $current->id; // to update.
                            }
                        } else {
                            $existed = true; // to fix: existed one.
                        }
                    }
                } else {
                    $existed = true; // to fix found multi items.
                }

                if ( $existed ) {
                    $error = esc_html__( 'A cost tracker for this plugin already exists.', 'mainwp' );
                    if ( 'theme' === $update['product_type'] ) {
                        $error = esc_html__( 'A cost tracker for this theme already exists.', 'mainwp' );
                    }
                    throw new MainWP_Exception( esc_html( $error ) );
                }
            }
        }

        if ( isset( $update['id'] ) ) {
            unset( $update['id'] );
        }

        if ( ! empty( $id ) ) {

            $wpdb->update( $this->table_name( 'cost_tracker' ), $update, array( 'id' => intval( $id ) ) );
            return $this->get_cost_tracker_by( 'id', $id );
        } else {
            if ( isset( $update['id'] ) ) {
                unset( $update['id'] );
            }

            if ( $wpdb->insert( $this->table_name( 'cost_tracker' ), $update ) ) {
                return $this->get_cost_tracker_by( 'id', $wpdb->insert_id );
            }
        }
        return false;
    }

    /**
     * Method update_selected_lookup_cost().
     *
     * @param int   $item_id Cost id.
     * @param array $selected_sites selected sites.
     * @param array $selected_groups selected tags.
     * @param array $selected_clients selected clients.
     *
     * @return mixed Result
     */
    public function update_selected_lookup_cost( $item_id, $selected_sites = false, $selected_groups = false, $selected_clients = false ) {

        if ( empty( $item_id ) ) {
            return false;
        }

        $obj_name = '';

        $new_obj_ids = array();

        if ( ! empty( $selected_sites ) ) {
            $obj_name    = 'site';
            $new_obj_ids = $selected_sites;
        } elseif ( ! empty( $selected_groups ) ) {
            $obj_name    = 'tag';
            $new_obj_ids = $selected_groups;
        } elseif ( ! empty( $selected_clients ) ) {
            $obj_name    = 'client';
            $new_obj_ids = $selected_clients;
        }

        if ( ! empty( $obj_name ) ) {
            static::get_instance()->update_lookup_cost( $item_id, $obj_name, $new_obj_ids );
        } else {
            // to support saving cost without selected sites, tags, clients.
            MainWP_DB::instance()->delete_lookup_items(
                'object_name',
                array(
                    'item_name'    => 'cost',
                    'item_id'      => $item_id,
                    'object_names' => array( 'site', 'tag', 'client' ),
                )
            );

        }
        return true;
    }

    /**
     * Method update_lookup_cost().
     *
     * @param int    $item_id item id to insert lookup value.
     * @param string $obj_name loockup object name.
     * @param array  $new_obj_ids New|Update object ids.
     *
     * @return mixed Result
     */
    public function update_lookup_cost( $item_id, $obj_name, $new_obj_ids ) { //phpcs:ignore -- NOSONAR - complex.

        $allows = array( 'site', 'tag', 'client' );

        if ( empty( $item_id ) || ! is_array( $new_obj_ids ) || ! in_array( $obj_name, $allows ) ) {
            return false;
        }

        $remove_obj_names = array_diff( $allows, array( $obj_name ) );

        $found_look_ids  = array();
        $existed_look_id = array();

        $results = MainWP_DB::instance()->get_lookup_items( 'cost', $item_id, $obj_name );
        if ( $results ) {
            foreach ( $results as $item ) {
                $found_look_ids[] = $item->lookup_id;
                if ( ! empty( $new_obj_ids ) && in_array( $item->object_id, $new_obj_ids ) ) {
                    $existed_look_id[ $item->object_id ] = $item->lookup_id;
                }
            }
        }

        $new_look_ids = array();

        foreach ( $new_obj_ids as $obj_id ) {
            if ( isset( $existed_look_id[ $obj_id ] ) ) {
                $new_look_ids[] = $existed_look_id[ $obj_id ];
                continue;
            }
            $insert_id = MainWP_DB::instance()->insert_lookup_item( 'cost', $item_id, $obj_name, $obj_id );
            if ( $insert_id ) {
                $new_look_ids[] = $this->wpdb->insert_id;
            }
        }
        $remove_ids = array_diff( $found_look_ids, $new_look_ids );
        if ( $remove_ids ) {
            MainWP_DB::instance()->delete_lookup_items( 'lookup_id', array( 'lookup_ids' => $remove_ids ) );
        }

        if ( ! empty( $remove_obj_names ) ) {
            MainWP_DB::instance()->delete_lookup_items(
                'object_name',
                array(
                    'item_name'    => 'cost',
                    'item_id'      => $item_id,
                    'object_names' => $remove_obj_names,

                )
            );
        }
    }

    /**
     * Method get_cost_tracker_by().
     *
     * @param string $by Get by.
     * @param mixed  $value Value.
     * @param array  $params Others params.
     *
     * @return mixed Result
     */
    public function get_cost_tracker_by( $by = 'id', $value = null, $params = array() ) { //phpcs:ignore -- NOSONAR - complex method.
        global $wpdb;

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $s       = '';
        $exclude = array();
        $include = array();
        $status  = array();
        $types   = array();

        $where = '';
        $limit = '';

        if ( $params && is_array( $params ) ) {
            $s       = isset( $params['s'] ) ? $params['s'] : '';
            $exclude = isset( $params['exclude'] ) && ! empty( $params['exclude'] ) ? wp_parse_id_list( $params['exclude'] ) : array();
            $include = isset( $params['include'] ) && ! empty( $params['include'] ) ? wp_parse_id_list( $params['include'] ) : array();

            $status       = isset( $params['status'] ) && ! empty( $params['status'] ) ? wp_parse_list( $params['status'] ) : array();
            $product_type = isset( $params['category'] ) && ! empty( $params['category'] ) ? wp_parse_list( $params['category'] ) : array();
            $types        = isset( $params['type'] ) && ! empty( $params['type'] ) ? wp_parse_list( $params['type'] ) : array();

            $page     = isset( $params['paged'] ) ? intval( $params['paged'] ) : false;
            $per_page = isset( $params['items_per_page'] ) ? intval( $params['items_per_page'] ) : false;

            if ( ! empty( $s ) ) {
                $where .= ' AND ( ct.id LIKE "%' . $this->escape( $s ) . '%" OR ct.name LIKE "%' . $this->escape( $s ) . '%" ) ';
            }

            if ( ! empty( $exclude ) ) {
                $where .= ' AND  ct.id NOT IN (' . implode( ',', $exclude ) . ') ';
            }

            if ( ! empty( $include ) ) {
                $where .= ' AND  ct.id IN (' . implode( ',', $include ) . ') ';
            }

            if ( ! empty( $status ) ) {
                $where .= ' AND  ct.cost_status IN (' . $this->prepare_fields_array( $status ) . ') ';
            }

            if ( ! empty( $product_type ) && ! in_array( 'any', $product_type ) ) {
                $where .= ' AND  ct.product_type IN (' . $this->prepare_fields_array( $product_type ) . ') ';
            }

            if ( ! empty( $types ) && ! in_array( 'any', $types ) ) {
                $where .= ' AND  ct.type IN (' . $this->prepare_fields_array( $types ) . ') ';
            }

            if ( ! empty( $page ) && ! empty( $per_page ) ) {
                $limit = ' LIMIT ' . ( $page - 1 ) * $per_page . ',' . $per_page;
            }
        }

        $product_type = isset( $params['product_type'] ) ? $params['product_type'] : '';
        $selected_ids = isset( $params['selected_ids'] ) ? $params['selected_ids'] : array();

        if ( is_array( $selected_ids ) ) {
            $selected_ids = MainWP_Utility::array_numeric_filter( $selected_ids );
        } else {
            $selected_ids = array();
        }

        if ( 'all' === $by ) {
            if ( ! empty( $selected_ids ) ) {
                $where .= ' AND ct.id IN (' . implode( ',', $selected_ids ) . ') ';
            }
            return $wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) . ' ct WHERE 1 ' . $where . $limit ); //phpcs:ignore -- good.
        }

        $where = '';
        $limit = '';

        $sql = '';
        if ( 'id' === $by && is_numeric( $value ) ) {
            $sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE `id`=%d ', $value ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return $wpdb->get_row( $sql, OBJECT ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } elseif ( 'count' === $by ) {
            $sql = 'SELECT count(*) FROM ' . $this->table_name( 'cost_tracker' ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return $wpdb->get_var( $sql ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } elseif ( 'id' === $by && false !== strpos( $value, ',' ) ) {
            $cost_ids = explode( ',', $value );
            $cost_ids = MainWP_Utility::array_numeric_filter( $cost_ids );
            if ( ! empty( $cost_ids ) ) {
                $sql = 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE `id` IN (' . implode( ',', $cost_ids ) . ' )';
            }
        } elseif ( 'site_id' === $by || 'client_id' === $by ) {
            $sql = 'SELECT * FROM ' . $this->table_name( 'cost_tracker' );
        } elseif ( 'slug' === $by && is_string( $value ) ) {
            if ( in_array( $product_type, array( 'plugin', 'theme' ), true ) ) {
                $sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE `slug`=%s AND product_type = %s ', $value, $product_type ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                return $wpdb->get_row( $sql, OBJECT ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            } else {
                $where = '';
                if ( ! empty( $product_type ) ) {
                    $where = $wpdb->prepare( ' AND product_type = %s ', $product_type );
                }
                $sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE `slug`=%s ' . $where, $value ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
        }

        $data = array();
        if ( ! empty( $sql ) ) {
            $result = $wpdb->get_results( $sql, OBJECT ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ( $result ) {
                if ( 'site_id' === $by ) {
                    $site_id = intval( $value );
                    foreach ( $result as $cost ) {
                        if ( empty( $cost->id ) ) {
                            continue;
                        }
                        $sites = ! empty( $cost->sites ) ? json_decode( $cost->sites, true ) : array();
                        if ( is_array( $sites ) && in_array( $site_id, $sites ) ) {
                            $data[] = $cost;
                        }
                    }
                } elseif ( 'client_id' === $by ) {
                    $client_id = intval( $value );
                    foreach ( $result as $cost ) {
                        if ( empty( $cost->id ) ) {
                            continue;
                        }
                        $clients = ! empty( $cost->clients ) ? json_decode( $cost->clients, true ) : array();
                        if ( is_array( $clients ) && in_array( $client_id, $clients ) ) {
                            $data[] = $cost;
                        }
                    }
                } else {
                    foreach ( $result as $cost ) {
                        if ( empty( $cost->id ) ) {
                            continue;
                        }
                        $data[] = $cost;
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Method prepare_fields_array().
     *
     * @param array $values array of string.
     *
     * @return string Escaped string result.
     */
    public function prepare_fields_array( $values = array() ) {
        $tmp = '';
        foreach ( $values as $value ) {
            $tmp .= '"' . $this->escape( $value ) . '",';
        }
        return rtrim( $tmp, ',' );
    }

    /**
     * Method delete_cost_tracker().
     *
     * @param string $by Delete by.
     * @param mixed  $value Value.
     *
     * @return mixed Result
     */
    public function delete_cost_tracker( $by = 'id', $value = null ) {
        global $wpdb;

        if ( empty( $value ) ) {
            return false;
        }

        if ( 'id' !== $by && 'site_id' !== $by ) {
            return false;
        }
        $deleted = false;

        if ( 'id' === $by ) {
            if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE id=%d ', $value ) ) ) { //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $deleted = true;
            }
        } elseif ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE site_id=%d ', $value ) ) ) { //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $deleted = true;
        }

        if ( $deleted ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'lookup_item_objects' ) . ' WHERE item_id=%d AND item_name = "cost"', $value ) ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        return $deleted;
    }

    /**
     * Method get_all_cost_trackers_by_clients().
     *
     * @param array $client_ids Client ids.
     * @param array $params Orther params.
     *
     * @return mixed Result
     */
    public function get_all_cost_trackers_by_clients( $client_ids, $params = array() ) { //phpcs:ignore -- NOSONAR - complex method.

        if ( is_string( $client_ids ) ) {
            $client_ids = explode( ',', $client_ids );
        }

        if ( ! is_array( $client_ids ) || empty( $client_ids ) ) {
            return array();
        }

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $get_sites_of_cost = isset( $params['get_cost_sites'] ) && $params['get_cost_sites'] ? true : false;
        $clients_site_ids  = array();

        $client_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client_ids );
        if ( $client_sites ) {
            foreach ( $client_sites as $website ) {
                if ( empty( $website->client_id ) ) {
                    continue;
                }
                if ( ! isset( $clients_site_ids[ $website->client_id ] ) ) {
                    $clients_site_ids[ $website->client_id ] = array();
                }
                $clients_site_ids[ $website->client_id ][] = $website->id;
            }
        }

        global $wpdb;

        $clients_costs     = array();
        $clients_costs_ids = array();
        $sql               = 'SELECT * FROM ' . $this->table_name( 'cost_tracker' );
        $result            = $wpdb->get_results( $sql, OBJECT ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- get all cost trackers.
        if ( $result ) {
            foreach ( $result as $cost ) {
                $sites   = ! empty( $cost->sites ) ? json_decode( $cost->sites, true ) : array();
                $groups  = ! empty( $cost->groups ) ? json_decode( $cost->groups, true ) : array();
                $clients = ! empty( $cost->clients ) ? json_decode( $cost->clients, true ) : array();

                if ( ! is_array( $sites ) ) {
                    $sites = array();
                }
                if ( ! is_array( $groups ) ) {
                    $groups = array();
                }
                if ( ! is_array( $clients ) ) {
                    $clients = array();
                }

                if ( empty( $sites ) && empty( $groups ) && empty( $clients ) ) {
                    continue;
                }

                // to reduce db  queries for this case.
                if ( ! empty( $sites ) && empty( $groups ) && empty( $clients ) ) {
                    foreach ( $clients_site_ids as $client_id => $client_site_ids ) {
                        foreach ( $sites as $siteid ) {
                            if ( in_array( $siteid, $client_site_ids ) ) {
                                if ( ! isset( $clients_costs[ $client_id ] ) ) {
                                    $clients_costs[ $client_id ] = array();
                                }

                                if ( ! isset( $clients_costs_ids[ $client_id ] ) ) {
                                    $clients_costs_ids[ $client_id ] = array();
                                }

                                if ( $get_sites_of_cost ) {
                                    $cost->cost_sites_ids = $sites; // sites ids.
                                }
                                if ( ! in_array( $cost->id, $clients_costs_ids[ $client_id ] ) ) {
                                    $cost->count_sites                 = count( $sites );
                                    $cost->number_client_costs_sites   = array_intersect( $client_site_ids, $sites );
                                    $clients_costs[ $client_id ][]     = $cost;
                                    $clients_costs_ids[ $client_id ][] = $cost->id;
                                }
                                break;
                            }
                        }
                    }
                    continue;
                }

                $params = array(
                    'sites'   => $sites,
                    'groups'  => $groups,
                    'clients' => $clients,
                );

                // to do: should create relation of: costs, sites, groups, clients in other way.
                // to make it more easier in queries.
                $cost_sites     = MainWP_DB::instance()->get_db_sites( $params ); // get sites of cost tracker.
                $cost_sites_ids = array();

                if ( is_array( $cost_sites ) ) {

                    foreach ( $cost_sites as $cost_site ) {
                        $cost_sites_ids[] = $cost_site->id; // sites ids of cost tracker.
                    }

                    foreach ( $clients_site_ids as $client_id => $client_site_ids ) {
                        foreach ( $cost_sites as $cost_site ) {
                            if ( in_array( $cost_site->id, $client_site_ids ) ) {
                                if ( ! isset( $clients_costs[ $client_id ] ) ) {
                                    $clients_costs[ $client_id ] = array();
                                }
                                if ( ! isset( $clients_costs_ids[ $client_id ] ) ) {
                                    $clients_costs_ids[ $client_id ] = array();
                                }
                                if ( $get_sites_of_cost ) {
                                    $cost->cost_sites_ids = $cost_sites_ids;
                                }
                                if ( ! in_array( $cost->id, $clients_costs_ids[ $client_id ] ) ) {
                                    $cost->count_sites                 = count( $cost_sites );
                                    $cost->number_client_costs_sites   = array_intersect( $client_site_ids, $cost_sites_ids );
                                    $clients_costs[ $client_id ][]     = $cost;
                                    $clients_costs_ids[ $client_id ][] = $cost->id;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $clients_costs;
    }


    /**
     * Method get_all_cost_trackers_by_sites().
     *
     * @param array $sites_ids Sites ids.
     *
     * @return mixed Result
     */
    public function get_all_cost_trackers_by_sites( $sites_ids ) { //phpcs:ignore -- NOSONAR - complex method.

        if ( is_string( $sites_ids ) ) {
            $sites_ids = explode( ',', $sites_ids );
        }

        if ( ! is_array( $sites_ids ) || empty( $sites_ids ) ) {
            return array();
        }

        $sites_ids = array_map( 'intval', $sites_ids );

        global $wpdb;

        $sites_costs     = array();
        $sites_costs_ids = array();
        $sql             = 'SELECT * FROM ' . $this->table_name( 'cost_tracker' );
        $result          = $wpdb->get_results( $sql, OBJECT ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $result ) {
            foreach ( $result as $cost ) {
                $sites   = ! empty( $cost->sites ) ? json_decode( $cost->sites, true ) : array();
                $groups  = ! empty( $cost->groups ) ? json_decode( $cost->groups, true ) : array();
                $clients = ! empty( $cost->clients ) ? json_decode( $cost->clients, true ) : array();

                if ( ! is_array( $sites ) ) {
                    $sites = array();
                }
                if ( ! is_array( $groups ) ) {
                    $groups = array();
                }
                if ( ! is_array( $clients ) ) {
                    $clients = array();
                }

                if ( empty( $sites ) && empty( $groups ) && empty( $clients ) ) {
                    continue;
                }

                // to reduce db  queries for this case.
                if ( ! empty( $sites ) && empty( $groups ) && empty( $clients ) ) {
                    foreach ( $sites_ids as $site_id ) {
                        if ( in_array( $site_id, $sites ) ) {
                            if ( ! isset( $sites_costs[ $site_id ] ) ) {
                                $sites_costs[ $site_id ] = array();
                            }

                            if ( ! isset( $sites_costs_ids[ $site_id ] ) ) {
                                $sites_costs_ids[ $site_id ] = array();
                            }

                            if ( ! in_array( $cost->id, $sites_costs_ids[ $site_id ] ) ) {
                                $cost->count_sites             = count( $sites );
                                $sites_costs[ $site_id ][]     = $cost;
                                $sites_costs_ids[ $site_id ][] = $cost->id;
                            }
                        }
                    }
                    continue;
                }

                $params = array(
                    'sites'   => $sites,
                    'groups'  => $groups,
                    'clients' => $clients,
                );

                // to do: should create relation of: costs, sites, groups, clients in other way.
                // to make it more easier in queries.
                $cost_sites = MainWP_DB::instance()->get_db_sites( $params );

                if ( is_array( $cost_sites ) ) {
                    foreach ( $sites_ids as $site_id ) {
                        foreach ( $cost_sites as $cost_site ) {
                            if ( (int) $cost_site->id === $site_id ) {
                                if ( ! isset( $sites_costs[ $site_id ] ) ) {
                                    $sites_costs[ $site_id ] = array();
                                }
                                if ( ! isset( $sites_costs_ids[ $site_id ] ) ) {
                                    $sites_costs_ids[ $site_id ] = array();
                                }
                                if ( ! in_array( $cost->id, $sites_costs_ids[ $site_id ] ) ) {
                                    $cost->count_sites             = count( $cost_sites );
                                    $sites_costs[ $site_id ][]     = $cost;
                                    $sites_costs_ids[ $site_id ][] = $cost->id;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $sites_costs;
    }

    /**
     * Method get_summary_data().
     *
     * @param array $params Params.
     *
     * @return mixed Result
     */
    public function get_summary_data( $params = array() ) {
        if ( ! is_array( $params ) ) {
            $params = array();
        }

        global  $wpdb;

        $sum_data = isset( $params['sum_data'] ) ? $params['sum_data'] : '';
        $where    = '';
        $sql      = '';
        if ( 'all' === $sum_data ) {
            $where .= ' AND co.cost_status = "active" AND co.type = "subscription" ';
            $sql   .= 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) . ' co WHERE 1 ' . $where . ' ORDER BY co.next_renewal ASC ';
        } else {
            return false;
        }
        return $wpdb->get_results( $sql ); //phpcs:ignore -- good.
    }

    /**
     * Method update_next_renewal_today()
     *
     * @return void
     */
    public function update_next_renewal_today() {

        $costs = $this->get_cost_tracker_by( 'all' );
        if ( $costs ) {
            foreach ( $costs as $cost ) {
                $next_renewal = Cost_Tracker_Admin::get_next_renewal( $cost->last_renewal, $cost->renewal_type );
                $next_today   = Cost_Tracker_Admin::calc_next_renewal_today( $cost, $next_renewal );
                if ( $next_today !== $cost->next_renewal_today ) {
                    $update = array(
                        'id'                 => $cost->id,
                        'next_renewal_today' => $next_today,
                    );
                    $this->update_cost_tracker( $update );
                }
            }
        }
        update_option( 'module_cost_tracker_calc_today_next_renewal', gmdate( 'Y-m-d' ) );
    }
}
