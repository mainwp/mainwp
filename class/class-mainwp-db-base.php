<?php
/**
 * MainWP Database Controller
 *
 * This file handles all interactions with the DB.
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB_Base
 */
class MainWP_DB_Base {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

	/**
	 * @static
	 * instance of this
	 */
	private static $instance = null;
	
	// Private.
	protected $table_prefix;

	/** @var $wpdb wpdb */
	protected $wpdb;

	// Constructor.
	public function __construct() {

		self::$instance = $this;

		/** @var $this ->wpdb wpdb */
		global $wpdb;

		$this->wpdb         = &$wpdb;
		$this->table_prefix = $wpdb->prefix . 'mainwp_';
	}

	protected function test_connection() {
		if ( ! self::ping( $this->wpdb->dbh ) ) {
			MainWP_Logger::instance()->info( __( 'Trying to reconnect WordPress database connection...', 'mainwp' ) );
			$this->wpdb->db_connect();
		}
	}

	protected function table_name( $suffix, $tablePrefix = null ) {
		return ( null == $tablePrefix ? $this->table_prefix : $tablePrefix ) . $suffix;
	}


	public function get_my_sql_version() {
		return $this->wpdb->get_var( 'SHOW VARIABLES LIKE "version"', 1 );
	}

	public function get_row_result( $sql ) {
		if ( null == $sql ) {
			return null;
		}

		return $this->wpdb->get_row( $sql, OBJECT );
	}

	public function get_results_result( $sql ) {
		if ( null == $sql ) {
			return null;
		}

		return $this->wpdb->get_results( $sql, OBJECT_K );
	}

	public function query( $sql ) {
		if ( null == $sql ) {
			return false;
		}

		$result = self::m_query( $sql, $this->wpdb->dbh );

		if ( ! $result || ( 0 == self::num_rows( $result ) ) ) {
			return false;
		}

		return $result;
	}

	protected function escape( $data ) {
		if ( function_exists( 'esc_sql' ) ) {
			return esc_sql( $data );
		} else {
			return $this->wpdb->escape( $data );
		}
	}

	// Support old & new versions of WordPress (3.9+).
	public static function use_mysqli() {
		/** @var $this ->wpdb wpdb */
		if ( ! function_exists( '\mysqli_connect' ) ) {
			return false;
		}
		return ( self::$instance->wpdb->dbh instanceof \mysqli );
	}

	public static function ping( $link ) {
		if ( self::use_mysqli() ) {
			return \mysqli_ping( $link );
		} else {
			return \mysql_ping( $link );
		}
	}

	public static function m_query( $query, $link ) {
		if ( self::use_mysqli() ) {
			return \mysqli_query( $link, $query );
		} else {
			return \mysql_query( $query, $link );
		}
	}

	public static function fetch_object( $result ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_fetch_object( $result );
		} else {
			return \mysql_fetch_object( $result );
		}
	}

	public static function free_result( $result ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_free_result( $result );
		} else {
			return \mysql_free_result( $result );
		}
	}

	public static function data_seek( $result, $offset ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_data_seek( $result, $offset );
		} else {
			return \mysql_data_seek( $result, $offset );
		}
	}

	public static function fetch_array( $result, $result_type = null ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_fetch_array( $result, ( null == $result_type ? MYSQLI_BOTH : $result_type ) );
		} else {
			return \mysql_fetch_array( $result, ( null == $result_type ? MYSQL_BOTH : $result_type ) );
		}
	}

	public static function num_rows( $result ) {
		if ( false === $result ) {
			return 0;
		}

		if ( self::use_mysqli() ) {
			return \mysqli_num_rows( $result );
		} else {
			return \mysql_num_rows( $result );
		}
	}

	public static function is_result( $result ) {
		if ( false === $result ) {
			return false;
		}

		if ( self::use_mysqli() ) {
			return ( $result instanceof \mysqli_result );
		} else {
			return is_resource( $result );
		}
	}
	// phpcs:enable
}
