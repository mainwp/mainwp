<?php
/**
 * MainWP Database Controller
 *
 * This file handles all interactions with the DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB_Base
 *
 * @package MainWP\Dashboard
 */
class MainWP_DB_Base {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

	/**
	 * Private static instance.
	 *
	 * @static
	 * @var $instance  MainWP_DB_Base.
	 */
	private static $instance = null;

	/**
	 * Table prefix.
	 *
	 * @var string $table_prefix
	 */
	protected $table_prefix;

	/**
	 * WordPress Database.
	 *
	 * @var mixed $wpdb WordPress Database.
	 */
	protected $wpdb;

	/**
	 * MainWP_DB_Base constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {

		self::$instance = $this;

		/**
		 * WordPress Database.
		 *
		 * @var mixed $wpdb Global WordPress Database.
		 */
		global $wpdb;

		$this->wpdb         = &$wpdb;
		$this->table_prefix = $wpdb->prefix . 'mainwp_';
	}

	/**
	 * Method test_connection()
	 *
	 * Test db connection.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::info()
	 */
	protected function test_connection() {
		if ( ! self::ping( $this->wpdb->dbh ) ) {
			MainWP_Logger::instance()->info( esc_html__( 'Trying to reconnect WordPress database connection...', 'mainwp' ) );
			$this->wpdb->db_connect();
		}
	}

	/**
	 * Method table_name()
	 *
	 * Create entire table name.
	 *
	 * @param mixed $suffix Table suffix.
	 * @param null  $tablePrefix Table prefix.
	 *
	 * @return string Table name.
	 */
	protected function table_name( $suffix, $tablePrefix = null ) {
		return ( null === $tablePrefix ? $this->table_prefix : $tablePrefix ) . $suffix;
	}


	/**
	 * Method get_table_name()
	 *
	 * Create entire table name.
	 *
	 * @param mixed $suffix Table suffix.
	 *
	 * @return string Table name.
	 */
	public function get_table_name( $suffix ) {
		return $this->table_name( $suffix );
	}

	/**
	 * Method get_my_sql_version()
	 *
	 * Get MySQL Version.
	 *
	 * @return mixed MySQL vresion.
	 */
	public function get_my_sql_version() {
		return $this->wpdb->get_var( 'SHOW VARIABLES LIKE "version"', 1 );
	}

	/**
	 * Method get_row_result()
	 *
	 * Get row result.
	 *
	 * @param mixed $sql SQL Query.
	 *
	 * @return mixed null|Row
	 */
	public function get_row_result( $sql ) {
		if ( null === $sql ) {
			return null;
		}

		return $this->wpdb->get_row( $sql, OBJECT );
	}

	/**
	 * Method get_results_result()
	 *
	 * Get Results of result.
	 *
	 * @param mixed $sql SQL query.
	 *
	 * @return mixed null|get_results()
	 */
	public function get_results_result( $sql ) {
		if ( null === $sql ) {
			return null;
		}

		return $this->wpdb->get_results( $sql, OBJECT_K );
	}

	/**
	 * Method query()
	 *
	 * SQL Query.
	 *
	 * @param mixed $sql SQL Query.
	 *
	 * @return mixed false|$result.
	 */
	public function query( $sql ) {
		if ( null === $sql ) {
			return false;
		}

		$result = self::m_query( $sql, $this->wpdb->dbh );

		if ( ! $result || ( empty( self::num_rows( $result ) ) ) ) {
			return false;
		}

		return $result;
	}

	/**
	 * Method escape()
	 *
	 * Escape SQL Data.
	 *
	 * @param mixed $data Data to escape.
	 *
	 * @return mixed Escapped SQL Data.
	 */
	public function escape( $data ) {
		if ( function_exists( 'esc_sql' ) ) {
			return esc_sql( $data );
		} else {
			return $this->wpdb->escape( $data );
		}
	}

	/**
	 * Method use_mysqli()
	 *
	 * Use MySQLi, Support old & new versions of WordPress (3.9+).
	 *
	 * @return boolean|self false|$instance Instance of \mysqli
	 */
	public static function use_mysqli() {
		if ( ! function_exists( '\mysqli_connect' ) ) {
			return false;
		}
		return ( self::$instance->wpdb->dbh instanceof \mysqli );
	}

	/**
	 * Method ping()
	 *
	 * Ping MySQLi.
	 *
	 * @param mixed $link Query link.
	 *
	 * @return mixed \mysqli_ping
	 */
	public static function ping( $link ) {
		if ( self::use_mysqli() ) {
			return \mysqli_ping( $link );
		} else {
			return \mysql_ping( $link );
		}
	}

	/**
	 * Method m_query()
	 *
	 * MySQLi m_query.
	 *
	 * @param mixed $query Query params.
	 * @param mixed $link Query link.
	 *
	 * @return mixed \mysqli_query
	 */
	public static function m_query( $query, $link ) {
		if ( self::use_mysqli() ) {
			return \mysqli_query( $link, $query );
		} else {
			return \mysql_query( $query, $link );
		}
	}

	/**
	 * Method fetch_object()
	 *
	 * Fetch object.
	 *
	 * @param mixed $result Query result.
	 *
	 * @return boolean|mixed false|\mysqli_fetch_object
	 */
	public static function fetch_object( $result ) {
		if ( is_bool( $result ) ) {
			return $result;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_fetch_object( $result );
		} else {
			return \mysql_fetch_object( $result );
		}
	}

	/**
	 * Method free_result()
	 *
	 * MySQLi free result.
	 *
	 * @param mixed $result Query result.
	 *
	 * @return boolean|mixed false|\mysqli_free_result
	 */
	public static function free_result( $result ) {
		if ( is_bool( $result ) ) {
			return $result;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_free_result( $result );
		} else {
			return \mysql_free_result( $result );
		}
	}

	/**
	 * Method data_seek()
	 *
	 * MySQLi data seek.
	 *
	 * @param mixed $result Query result.
	 * @param mixed $offset Query offset.
	 *
	 * @return boolean|mixed false|\mysqli_data_seek
	 */
	public static function data_seek( $result, $offset ) {
		if ( is_bool( $result ) ) {
			return $result;
		}

		if ( self::use_mysqli() ) {
			if ( ! ( $result instanceof \mysqli_result ) ) {
				return $result;
			}
			return \mysqli_data_seek( $result, $offset );
		} else {
			return \mysql_data_seek( $result, $offset );
		}
	}

	/**
	 * Method fetch_array()
	 *
	 * MySQLi fetch array.
	 *
	 * @param mixed $result Query result.
	 * @param null  $result_type Query result type.
	 *
	 * @return boolean|mixed false|\mysqli_fetch_array.
	 */
	public static function fetch_array( $result, $result_type = null ) {
		if ( is_bool( $result ) ) {
			return $result;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_fetch_array( $result, ( null === $result_type ? MYSQLI_BOTH : $result_type ) );
		} else {
			return \mysql_fetch_array( $result, ( null === $result_type ? MYSQL_BOTH : $result_type ) );
		}
	}


	/**
	 * Method num_rows()
	 *
	 * MySQLi number of rows.
	 *
	 * @param mixed $result Query result.
	 *
	 * @return boolean|mixed false|\mysqli_num_rows.
	 */
	public static function num_rows( $result ) {
		if ( ! self::is_result( $result ) ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_num_rows( $result );
		} else {
			return \mysql_num_rows( $result );
		}
	}

	/**
	 * Method is_result()
	 *
	 * Return instance of \mysqli_result
	 *
	 * @param mixed $result Query result.
	 *
	 * @return boolean|mixed false|\mysqli_result
	 */
	public static function is_result( $result ) {
		if ( is_bool( $result ) ) {
			return $result;
		}

		if ( self::use_mysqli() ) {
			return ( $result instanceof \mysqli_result );
		} else {
			return is_resource( $result );
		}
	}
	// phpcs:enable
}
