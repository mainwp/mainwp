<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB_Site_Actions
 *
 * @package MainWP\Dashboard
 */
class MainWP_DB_Site_Actions extends MainWP_DB {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

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
	 * Create public static instance.
	 *
	 * @static
	 * @return MainWP_DB_Site_Actions
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
		parent::__construct();
		add_filter( 'mainwp_db_install_tables', array( $this, 'hook_db_install_tables' ), 10, 3 );
		add_action( 'mainwp_delete_site', array( $this, 'hook_delete_site' ), 10, 3 );
	}

	/**
	 * Method hook_db_install_tables()
	 *
	 * Get the query to install db tables.
	 *
	 * @param array  $sql input filter.
	 * @param string $currentVersion Current db Version.
	 * @param string $charset_collate charset collate.
	 *
	 * @return array $sql queries.
	 */
	public function hook_db_install_tables( $sql, $currentVersion, $charset_collate ) {
		$tbl = 'CREATE TABLE ' . $this->table_name( 'wp_actions' ) . ' (
	action_id int(11) NOT NULL auto_increment,
	wpid int(11) NOT NULL,
	object_id varchar(20) NOT NULL,
	context varchar(20) NOT NULL,
	action varchar(100) NOT NULL,
	action_user text NOT NULL DEFAULT "",
	created int(11) NOT NULL DEFAULT 0, 
	meta_data text NOT NULL DEFAULT "",
	dismiss tinyint(1) NOT NULL DEFAULT 0,
	summary varchar(255) NOT NULL default ""';
		if ( empty( $currentVersion ) || version_compare( $currentVersion, '8.89', '<=' ) ) {
			$tbl .= ',
		PRIMARY KEY (action_id)';
		}
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		return $sql;
	}


	/**
	 * Method hook_delete_site()
	 *
	 * Installs the new DB.
	 *
	 * @param mixed $site site object.
	 *
	 * @return bool result.
	 */
	public function hook_delete_site( $site ) {
		if ( empty( $site ) ) {
			return false;
		}
		return $this->delete_action_by( 'wpid', $site->id );
	}

	/**
	 * Method sync_site_actions().
	 *
	 * Sync site actions data.
	 *
	 * @param int   $site_id site id.
	 * @param array $sync_actions action data.
	 *
	 * @return bool
	 */
	public function sync_site_actions( $site_id, $sync_actions ) {

		if ( empty( $sync_actions ) || ! is_array( $sync_actions ) ) {
			return false;
		}

		$default = array(
			'context'     => '',
			'action'      => '',
			'action_user' => '',
			'created'     => 0,
			'meta_data'   => '',
			'summary'     => '',
		);

		foreach ( $sync_actions as $index => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$meta_data   = array();
			$update_data = array_merge( $default, $data );
			if ( isset( $update_data['meta_data'] ) && ! empty( $update_data['meta_data'] ) ) {
				$meta_data                = $update_data['meta_data'];
				$update_data['meta_data'] = wp_json_encode( $update_data['meta_data'] );
			}

			$sum                    = is_array( $meta_data ) && ! empty( $meta_data['name'] ) ? esc_html( $meta_data['name'] ) : 'WP Core';
			$sum                   .= ' ';
			$sum .= 'wordpress' !== $data['context'] ? esc_html( ucfirst( rtrim( $data['context'], 's' ) ) ) : 'WordPress'; //phpcs:ignore -- wordpress text.
			$update_data['summary'] = $sum;

			if ( empty( $update_data['action_user'] ) ) {
				continue;
			}

			$update_data = array_filter(
				$update_data,
				function ( $e ) {
					return ! empty( $e );
				}
			);

			foreach ( $update_data as $idx => $val ) {
				if ( ! isset( $default[ $idx ] ) ) {
					unset( $update_data[ $idx ] );
				}
			}

			$update_data['object_id'] = $this->escape( $index );
			$update_data['wpid']      = $site_id;

			$this->add_site_action( $update_data );
		}

		return true;
	}

	/**
	 * Method add_new_action.
	 *
	 * Add new action.
	 *
	 * @param array $data action data.
	 *
	 * @return bool
	 */
	public function add_site_action( $data ) {
		if ( empty( $data ) ) {
			return false;
		}

		$object_id = isset( $data['object_id'] ) ? $this->escape( $data['object_id'] ) : '';
		$wpid      = isset( $data['wpid'] ) ? intval( $data['wpid'] ) : 0;

		if ( empty( $object_id ) || empty( $wpid ) ) {
			return false;
		}

		$params  = array(
			'wpid'         => $wpid,
			'object_id'    => $object_id,
			'check_access' => false,
		);
		$results = $this->get_wp_actions( $params );

		if ( ! empty( $results ) ) {
			return false;
		}

		if ( isset( $data['action_id'] ) ) {
			unset( $data['action_id'] );
		}

		if ( $this->wpdb->insert( $this->table_name( 'wp_actions' ), $data ) ) {
			return $this->get_wp_action_by_id( $this->wpdb->insert_id );
		}

		return false;
	}

	/**
	 * Method update_action_by_id.
	 *
	 * Create or update action.
	 *
	 * @param array $action_id action id.
	 * @param array $data action data.
	 *
	 * @return bool
	 */
	public function update_action_by_id( $action_id, $data ) {
		if ( empty( $action_id ) ) {
			return false;
		}
		return $this->wpdb->update( $this->table_name( 'wp_actions' ), $data, array( 'action_id' => intval( $action_id ) ) );
	}

	/**
	 * Method update_action.
	 *
	 * Create or update action.
	 *
	 * @param array $action_id action id.
	 *
	 * @return bool
	 */
	public function get_wp_action_by_id( $action_id ) {
		if ( empty( $action_id ) ) {
			return false;
		}
		return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_actions' ) . ' WHERE action_id = %d ', $action_id ) );
	}



	/**
	 * Method delete_action_by.
	 *
	 * Delete action by.
	 *
	 * @param string $by By what.
	 * @param mixed  $value By value.
	 * @return bool Results.
	 */
	public function delete_action_by( $by = 'action_id', $value = null ) {
		if ( empty( $value ) ) {
			return false;
		}
		if ( 'action_id' === $by ) {
			if ( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_actions' ) . ' WHERE action_id=%d ', $value ) ) ) {
				return true;
			}
		} elseif ( 'wpid' === $by ) {
			if ( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_actions' ) . ' WHERE wpid=%d ', $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Method delete_all_actions.
	 *
	 * Deletes all actions.
	 *
	 * @return bool Results.
	 */
	public function delete_all_actions() {
		return $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_actions' ) ) );
	}

	/**
	 * Method get_wp_actions.
	 *
	 * Get wp actions.
	 *
	 * @param bool  $params params.
	 * @param mixed $obj Format data.
	 *
	 * @return mixed $result result.
	 */
	public function get_wp_actions( $params = array(), $obj = OBJECT ) { //phpcs:ignore -- complex.

		$action_id     = isset( $params['action_id'] ) ? intval( $params['action_id'] ) : 0;
		$site_id       = isset( $params['wpid'] ) ? $params['wpid'] : 0;
		$object_id     = isset( $params['object_id'] ) ? $this->escape( $params['object_id'] ) : '';
		$where_extra   = isset( $params['where_extra'] ) ? $params['where_extra'] : ''; // compatible.
		$where_dismiss = isset( $params['dismiss'] ) ? ' AND dismiss = ' . ( $params['dismiss'] ? 1 : 0 ) : '';
		$check_access  = isset( $params['check_access'] ) ? $params['check_access'] : true;
		$search_str    = isset( $params['search'] ) ? $this->escape( trim( $params['search'] ) ) : null;

		$order_by    = isset( $params['order_by'] ) && ! empty( $params['order_by'] ) ? $params['order_by'] : ' wa.created DESC ';
		$offset      = isset( $params['offset'] ) ? intval( $params['offset'] ) : false;
		$rowcount    = isset( $params['rowcount'] ) ? intval( $params['rowcount'] ) : false;
		$total_count = isset( $params['total_count'] ) && $params['total_count'] ? true : false;

		$limit = isset( $params['limit'] ) ? intval( $params['limit'] ) : '';

		$limit_qry = '';

		if ( ! empty( $limit ) ) {
			$limit_qry = ' LIMIT ' . $limit;
		}

		$where_actions = '';

		$sql = '';

		if ( ! empty( $action_id ) ) {
			$where_actions .= ' AND wa.action_id = ' . $action_id;
		} else {
			$sql_and = '';
			if ( ! empty( $site_id ) ) {
				if ( is_array( $site_id ) ) {
					$site_ids = array_map( 'intval', $site_id );
					$site_ids = array_filter( $site_ids );
					if ( ! empty( $site_ids ) ) {
						$site_ids       = implode( ',', $site_ids );
						$sql_and        = ' AND ';
						$where_actions .= $sql_and . ' wa.wpid IN ( ' . $site_ids . ' )';
					}
				} elseif ( is_numeric( $site_id ) ) {
					$sql_and        = ' AND ';
					$where_actions .= $sql_and . ' wa.wpid = ' . intval( $site_id );
				}
			}
			if ( ! empty( $object_id ) ) {
				if ( empty( $sql_and ) ) {
					$sql_and = ' AND ';
				}
				$where_actions .= $sql_and . ' wa.object_id = "' . $object_id . '" ';
			}
		}

		if ( $check_access ) {
			$where_actions .= $this->get_sql_where_allow_access_sites( 'wp' );
		}

		$where = '';
		// for searching.
		if ( ! empty( $search_str ) ) {
			$where .= ' AND (wp.name LIKE "%' . $search_str . '%" OR wp.url LIKE  "%' . $search_str . '%" OR wa.action_user LIKE  "%' . $search_str . '%" OR wa.summary LIKE  "%' . $search_str . '%") ';
		}

		if ( $total_count ) {
			$sql  = ' SELECT count(*)';
			$sql .= ' FROM ' . $this->table_name( 'wp_actions' ) . ' wa ';
			$sql .= ' LEFT JOIN ' . $this->table_name( 'wp' ) . ' wp ON wp.id = wa.wpid';
			$sql .= ' WHERE 1 ' . $where . $where_actions . $where_dismiss . $where_extra;
			return $this->wpdb->get_var( $sql );
		}

		$sql  = ' SELECT wp.name,wp.url,wa.* ';
		$sql .= ' FROM ' . $this->table_name( 'wp_actions' ) . ' wa ';
		$sql .= ' LEFT JOIN ' . $this->table_name( 'wp' ) . ' wp ON wp.id = wa.wpid';
		$sql .= ' WHERE 1 ' . $where . $where_actions . $where_dismiss . $where_extra;
		$sql .= ' ORDER BY ' . $order_by;

		if ( empty( $limit_qry ) ) {
			if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
				$limit_qry = ' LIMIT ' . $offset . ', ' . $rowcount;
			} elseif ( false !== $rowcount ) {
				$limit_qry = ' LIMIT ' . $rowcount;
			}
		}

		$sql .= $limit_qry;

		if ( OBJECT === $obj ) {
			$result = $this->wpdb->get_results( $sql, OBJECT );
		} else {
			$result = $this->wpdb->get_results( $sql, ARRAY_A );
		}

		return $result;
	}
}
