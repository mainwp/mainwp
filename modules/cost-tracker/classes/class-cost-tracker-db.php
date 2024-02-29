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
	private $cost_tracker_db_version = '1.0.6';


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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
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
		if ( empty( self::num_rows( $rslt ) ) ) {
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
`author` text NOT NULL,
`price` decimal(12,2) NOT NULL,
`renewal_type` varchar(20) NOT NULL,
`last_renewal` int(11) NOT NULL,
`next_renewal` int(11) NOT NULL,
`last_alert` int(11) NOT NULL,
`sites` text NOT NULL,
`groups` text NOT NULL,
`clients` text NOT NULL,
`note` text NOT NULL';

		if ( empty( $currentVersion ) ) {
			$tbl .= ',
PRIMARY KEY  (`id`)  '; }
		$tbl  .= ') ' . $charset_collate;
		$sql[] = $tbl;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		update_option( 'mainwp_module_cost_tracker_db_version', $this->cost_tracker_db_version );
	}


	/**
	 * Method update_cost_tracker().
	 *
	 * @param array $update Cost tracker array data.
	 *
	 * @throws \Exception Existed cost tracker error.
	 * @return mixed Result
	 */
	public function update_cost_tracker( $update ) {
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

		if ( ! empty( $update['product_type'] ) && ! empty( $update['slug'] ) ) {
			if ( in_array( $update['product_type'], array( 'plugin', 'theme' ), true ) ) {
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
						throw new \Exception( esc_html( $error ) );
					}
				}
			}
		}

		if ( isset( $update['id'] ) ) {
			unset( $update['id'] );
		}

		$site_id = isset( $update['site_id'] ) ? $update['site_id'] : '';

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
	 * Method get_cost_tracker_by().
	 *
	 * @param string $by Get by.
	 * @param mixed  $value Value.
	 * @param array  $params Others params.
	 *
	 * @return mixed Result
	 */
	public function get_cost_tracker_by( $by = 'id', $value = null, $params = array() ) { //phpcs:ignore -- complex method.
		global $wpdb;

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$product_type = isset( $params['product_type'] ) ? $params['product_type'] : '';

		if ( 'all' === $by ) {
			return $wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) ); //phpcs:ignore -- good.
		}

		$sql = '';
		if ( 'id' === $by && is_numeric( $value ) ) {
			$sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE `id`=%d ', $value ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_row( $sql, OBJECT ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
				$sql    = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE `slug`=%s AND product_type = %s ', $value, $product_type ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result = $wpdb->get_row( $sql, OBJECT ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return $result;
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

		if ( 'id' === $by ) {
			if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE id=%d ', $value ) ) ) { //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return true;
			}
		} elseif ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'cost_tracker' ) . ' WHERE site_id=%d ', $value ) ) ) { //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return true;
		}
		return false;
	}

	/**
	 * Method get_cost_tracker_info_of_clients().
	 *
	 * @param array $client_ids Client ids.
	 * @param array $params Orther params.
	 *
	 * @return mixed Result
	 */
	public function get_cost_tracker_info_of_clients( $client_ids, $params = array() ) { //phpcs:ignore -- complex method.

		if ( is_string( $client_ids ) ) {
			$client_ids = explode( ',', $client_ids );
		}

		if ( ! is_array( $client_ids ) || empty( $client_ids ) ) {
			return array();
		}

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$with_sites = isset( $params['with_sites'] ) && $params['with_sites'] ? true : false;

		$clients_site_ids = array();
		$all_sites        = MainWP_DB::instance()->get_sites();

		if ( $all_sites ) {
			foreach ( $all_sites as $site ) {
				if ( ! empty( $site['client_id'] ) ) {
					if ( in_array( $site['client_id'], $client_ids ) ) { //phpcs:ignore -- in array compare.
						if ( ! isset( $clients_site_ids[ $site['client_id'] ] ) ) {
							$clients_site_ids[ $site['client_id'] ] = array();
						}
						$clients_site_ids[ $site['client_id'] ][] = $site['id'];
					}
				}
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

								if ( $with_sites ) {
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
								if ( $with_sites ) {
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
	 * Method get_cost_trackers_info_of_sites().
	 *
	 * @param array $sites_ids Sites ids.
	 *
	 * @return mixed Result
	 */
	public function get_cost_trackers_info_of_sites( $sites_ids ) { //phpcs:ignore -- complex method.

		if ( is_string( $sites_ids ) ) {
			$sites_ids = explode( ',', $sites_ids );
		}

		if ( ! is_array( $sites_ids ) || empty( $sites_ids ) ) {
			return array();
		}

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
							if ( $cost_site->id === $site_id ) {
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
}
