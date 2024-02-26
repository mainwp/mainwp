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
 * Class MainWP_DB
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_DB_Base
 */
class MainWP_DB extends MainWP_DB_Base {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, Generic.Metrics.CyclomaticComplexity -- This is the only way to achieve desired results, pull request solutions appreciated.

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Private static variable to hold the single instance.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $general_options = null;

	/**
	 * Possible options.
	 *
	 * @var array $possible_options
	 */
	private static $possible_options = array(
		'plugin_upgrades',
		'theme_upgrades',
		'premium_upgrades',
		'plugins',
		'themes',
		'dtsSync',
		'version',
		'sync_errors',
		'ignored_plugins',
		'wp_upgrades',
		'site_info',
		'client',
		'signature_algo',
		'verify_method',
		'pubkey',
	);

	/**
	 * Create public static instance.
	 *
	 * @static
	 *
	 * @return MainWP_DB
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		self::$instance->test_connection();

		return self::$instance;
	}

	/**
	 * Get wp_options database table view.
	 *
	 * @param array $fields Extra option fields.
	 * @param bool  $default_value Whether or not to get default option fields.
	 *
	 * @return array wp_options view.
	 */
	public function get_option_view( $fields = array(), $default_value = true ) {

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		$view = '(SELECT intwp.id AS wpid ';

		if ( empty( $fields ) || $default_value ) {
			$view .= ',(SELECT recent_comments.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_comments WHERE  recent_comments.wpid = intwp.id AND recent_comments.name = "recent_comments" LIMIT 1) AS recent_comments,
					(SELECT recent_posts.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_posts WHERE  recent_posts.wpid = intwp.id AND recent_posts.name = "recent_posts" LIMIT 1) AS recent_posts,
					(SELECT recent_pages.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_pages WHERE  recent_pages.wpid = intwp.id AND recent_pages.name = "recent_pages" LIMIT 1) AS recent_pages,
					(SELECT phpversion.value FROM ' . $this->table_name( 'wp_options' ) . ' phpversion WHERE  phpversion.wpid = intwp.id AND phpversion.name = "phpversion" LIMIT 1) AS phpversion,
					(SELECT added_timestamp.value FROM ' . $this->table_name( 'wp_options' ) . ' added_timestamp WHERE  added_timestamp.wpid = intwp.id AND added_timestamp.name = "added_timestamp" LIMIT 1) AS added_timestamp,
					(SELECT wp_upgrades.value FROM ' . $this->table_name( 'wp_options' ) . ' wp_upgrades WHERE  wp_upgrades.wpid = intwp.id AND wp_upgrades.name = "wp_upgrades" LIMIT 1) AS wp_upgrades ';
		}

		if ( ! in_array( 'signature_algo', $fields ) ) {
			$fields[] = 'signature_algo';
		}

		if ( ! in_array( 'verify_method', $fields ) ) {
			$fields[] = 'verify_method';
		}

		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				if ( empty( $field ) ) {
					continue;
				}
				$view .= ', ';
				$view .= '(SELECT ' . $this->escape( $field ) . '.value FROM ' . $this->table_name( 'wp_options' ) . ' ' . $this->escape( $field ) . ' WHERE  ' . $this->escape( $field ) . '.wpid = intwp.id AND ' . $this->escape( $field ) . '.name = "' . $this->escape( $field ) . '" LIMIT 1) AS ' . $this->escape( $field );
			}
		}

		$view .= ' FROM ' . $this->table_name( 'wp' ) . ' intwp)';

		return $view;
	}

	/**
	 * Get connected child sites.
	 *
	 * @param array $sites_ids Websites ids - option field.
	 *
	 * @return array $connected_sites Array of connected sites.
	 */
	public function get_connected_websites( $sites_ids = false ) {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );

		$sql = 'SELECT wp.*,wp_sync.*
				FROM ' . $this->table_name( 'wp' ) . ' wp
				JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync
				ON wp.id = wp_sync.wpid
				WHERE (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors = "") ' .
				$where;

		$websites        = $this->wpdb->get_results( $sql );
		$connected_sites = array();
		if ( $websites ) {
			foreach ( $websites as $website ) {

				if ( ! empty( $sites_ids ) ) {
					// filter sites.
					if ( ! in_array( $website->id, $sites_ids ) ) {
						continue;
					}
				}

				$connected_sites[] = array(
					'id'   => $website->id,
					'name' => $website->name,
					'url'  => $website->url,
				);
			}
		}
		return $connected_sites;
	}

	/**
	 * Get disconnected child sites.
	 *
	 * @param array $sites_ids Websites ids - option field.
	 *
	 * @return array $disc_sites Array of disonnected sites.
	 */
	public function get_disconnected_websites( $sites_ids = false ) {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );

		$sql = 'SELECT wp.*,wp_sync.*
				FROM ' . $this->table_name( 'wp' ) . ' wp
				JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync 
				ON wp.id = wp_sync.wpid
				WHERE (wp_sync.sync_errors IS NOT NULL) AND (wp_sync.sync_errors <> "") ' .
				$where;

		$websites   = $this->wpdb->get_results( $sql );
		$disc_sites = array();
		if ( $websites ) {
			foreach ( $websites as $website ) {

				if ( ! empty( $sites_ids ) ) {
					// filter sites.
					if ( ! in_array( $website->id, $sites_ids ) ) {
						continue;
					}
				}

				$disc_sites[] = array(
					'id'   => $website->id,
					'name' => $website->name,
					'url'  => $website->url,
				);
			}
		}
		return $disc_sites;
	}

	/**
	 * Get child site count.
	 *
	 * @param null $userId Current user ID.
	 * @param bool $all_access Check if user has access to all sites.
	 *
	 * @return int Child site count.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_websites_count( $userId = null, $all_access = false ) {
		if ( ( null === $userId ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userId = $current_user->ID;
		}
		$where = ( null === $userId ? '' : ' wp.userid = ' . $userId );
		if ( ! $all_access ) {
			$where .= $this->get_sql_where_allow_access_sites( 'wp' );
		}
		$qry = 'SELECT COUNT(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp WHERE 1 ' . $where;

		return $this->wpdb->get_var( $qry );
	}


	/**
	 * Get child sites stats count.
	 *
	 * @param array $params Params.
	 */
	public function get_websites_stats_count( $params = array() ) {
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( isset( $params['all_access'] ) ) {
			$all_access = ! empty( $params['all_access'] ) ? true : false;
		} else {
			$all_access = true;
		}

		$where = '';
		if ( ! $all_access ) {
			$where .= $this->get_sql_where_allow_access_sites( 'wp' );
		}

		$select_stats = ' ( SELECT COUNT(wp.id) as count_all ';
		if ( ! empty( $params['count_disconnected'] ) ) {
			$select_stats .= ',( SELECT COUNT(wp_disconnected.id) FROM ' . $this->table_name( 'wp' ) . ' wp_disconnected LEFT JOIN ' . $this->table_name( 'wp_sync' ) . ' as wp_sync ';
			$select_stats .= ' ON wp_disconnected.id = wp_sync.wpid WHERE wp_sync.sync_errors != "" ) as count_disconnected  ';
		}
		if ( ! empty( $params['count_suspended'] ) ) {
			$select_stats .= ',( SELECT COUNT(wp_suspended.id) FROM ' . $this->table_name( 'wp' ) . ' wp_suspended WHERE wp_suspended.suspended = 1 ) as count_suspended ';
		}
		$qry  = 'SELECT * FROM ' . $select_stats;
		$qry .= ' FROM ' . $this->table_name( 'wp' ) . ' wp ' . $where . ' ) as wp_stats ';

		return $this->wpdb->get_row( $qry, ARRAY_A ); //phpcs:ignore -- ok.
	}

	/**
	 * Get Child site wp_options database table.
	 *
	 * @param array $website Child Site array.
	 * @param mixed $option  Child Site wp_options table name.
	 * @param mixed $default_value  default value.
	 *
	 * @return string|null Database query result (as string), or null on failure.
	 */
	public function get_website_option( $website, $option, $default_value = null ) {

		if ( is_array( $website ) ) {
			if ( isset( $website[ $option ] ) ) {
				return $website[ $option ];
			}
			$site_id = $website['id'];
		} elseif ( is_object( $website ) ) {
			if ( property_exists( $website, $option ) ) {
				return $website->{$option};
			}
			$site_id = $website->id;
		} elseif ( is_numeric( $website ) ) { // to support $site_id = 0, for global options.
			$site_id = $website;
		} else {
			return false;
		}

		$var = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $site_id ) );

		if ( null === $var && null !== $default_value ) {
			$var = $default_value;
		}
		return $var;
	}

	/**
	 * Get child site options.
	 *
	 * @param array $website Child site.
	 * @param mixed $options Child site options name.
	 *
	 * @return string|null Database query result (as string), or null on failure.
	 */
	public function get_website_options_array( &$website, $options ) {

		if ( ! is_array( $options ) || empty( $options ) ) {
			return array();
		}

		if ( is_array( $website ) ) {
			$site_id = $website['id'];
		} elseif ( is_object( $website ) ) {
			$site_id = $website->id;
		} elseif ( is_numeric( $website ) ) { // to support $site_id = 0 for global options.
			$site_id = $website;
		} else {
			return array();
		}

		$arr_options = array();
		$get_options = array();

		foreach ( $options as $option ) {
			if ( is_array( $website ) ) {
				if ( isset( $website[ $option ] ) ) {
					$arr_options[ $option ] = $website[ $option ];
				} else {
					$get_options[] = $option;
				}
			} elseif ( is_object( $website ) ) {
				if ( property_exists( $website, $option ) ) {
					$arr_options[ $option ] = $website->{$option};
				} else {
					$get_options[] = $option;
				}
			} else {
				$get_options[] = $option;
			}
		}

		if ( empty( $get_options ) ) {
			return $arr_options; // all options.
		}

		$options_name = implode( "','", $get_options );
		$options_name = "'" . $options_name . "'";

		$options_db = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name, value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name IN (' . $options_name . ')', $site_id ) );

		$fill_options = array(
			'primary_lasttime_backup',
		);

		foreach ( (array) $options_db as $o ) {
			$arr_options[ $o->name ] = $o->value;
			if ( in_array( $o->name, $fill_options ) ) {
				if ( is_array( $website ) ) {
					if ( ! isset( $website[ $o->name ] ) ) {
						$website[ $o->name ] = $o->value;
					}
				} elseif ( is_object( $website ) ) {
					if ( ! property_exists( $website, $o->name ) ) {
						$website->{$o->name} = $o->value;
					}
				}
			}
		}
		return $arr_options;
	}

	/**
	 * Update child site options.
	 *
	 * @param object $website Child site object.
	 * @param mixed  $option  Option to update.
	 * @param mixed  $value   Value to update with.
	 */
	public function update_website_option( $website, $option, $value ) {
		$rslt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $website->id ) );
		if ( 0 === count( $rslt ) ) {
			$this->wpdb->insert(
				$this->table_name( 'wp_options' ),
				array(
					'wpid'  => $website->id,
					'name'  => $option,
					'value' => $value,
				)
			);
		} else {
			$this->wpdb->update(
				$this->table_name( 'wp_options' ),
				array( 'value' => $value ),
				array(
					'wpid' => $website->id,
					'name' => $option,
				)
			);
		}
	}


	/**
	 * Get general Child site option.
	 *
	 * @param mixed $option  Child Site option name.
	 *
	 * @return string|null Database query result (as string), or null on failure.
	 */
	private function get_general_website_option( $option ) {

		if ( null !== self::$general_options ) {
			if ( isset( self::$general_options[ $option ] ) ) {
				return self::$general_options[ $option ];
			}
		} else {
			self::$general_options[] = array();
		}

		$val = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', 0 ) );

		self::$general_options[ $option ] = $val;
		return $val;
	}

	/**
	 * Get child site options.
	 *
	 * @param mixed $options Child site options name.
	 *
	 * @return string|null Database query result (as string), or null on failure.
	 */
	public function get_general_options_array( $options ) {

		if ( ! is_array( $options ) || empty( $options ) ) {
			return array();
		}

		$return_options = array();
		if ( null !== self::$general_options ) {
			foreach ( self::$general_options as $opt => $val ) {
				if ( in_array( $opt, $options ) ) {
					$return_options[ $opt ] = $val;
				}
			}
		} else {
			self::$general_options[] = array();
		}

		$diff_options = array();
		foreach ( $options as $opt ) {
			if ( ! isset( $return_options[ $opt ] ) ) {
				$diff_options[] = $opt;
			}
		}

		$options_name = implode( "','", $diff_options );
		$options_name = "'" . $options_name . "'";

		$options_db = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name, value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name IN (' . $options_name . ')', 0 ) );

		foreach ( (array) $options_db as $o ) {
			$return_options[ $o->name ]        = $o->value;
			self::$general_options[ $o->name ] = $o->value;
		}
		return $return_options;
	}

	/**
	 * Update general site options.
	 *
	 * @param mixed  $option  Option to update.
	 * @param mixed  $value   Value to update with.
	 * @param string $type_value  Type values: single|array.
	 */
	public function update_general_option( $option, $value, $type_value = 'single' ) {

		if ( 'array' === $type_value ) {
			if ( empty( $value ) ) {
				$value = array();
			} elseif ( ! is_array( $value ) ) {
				return false;
			}
			$value = wp_json_encode( $value );
		}

		if ( null === self::$general_options ) {
			self::$general_options[] = array();
		}
		self::$general_options[ $option ] = $value;

		$rslt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', 0 ) );

		if ( 0 === count( $rslt ) ) {
			$this->wpdb->insert(
				$this->table_name( 'wp_options' ),
				array(
					'wpid'  => 0,
					'name'  => $option,
					'value' => $value,
				)
			);
		} else {
			$this->wpdb->update(
				$this->table_name( 'wp_options' ),
				array( 'value' => $value ),
				array(
					'wpid' => 0,
					'name' => $option,
				)
			);
		}
		return true;
	}

	/**
	 * Get general Child site option.
	 *
	 * @param mixed  $opt  Child Site option name.
	 * @param string $type_value  Type values: single|array.
	 *
	 * @return string|null Database query result (as string), or null on failure.
	 */
	public function get_general_option( $opt, $type_value = 'single' ) {
		if ( 'single' === $type_value ) {
			return $this->get_general_website_option( $opt );
		} elseif ( 'array' === $type_value ) {
			$json_value = $this->get_general_website_option( $opt );
			if ( empty( $json_value ) ) {
				return array();
			}
			return json_decode( $json_value, true );
		}
		return false;
	}

	/**
	 * Get child sites by user ID.
	 *
	 * @param int    $userid       User ID.
	 * @param bool   $selectgroups Selected groups.
	 * @param null   $search_site  Site search field value.
	 * @param string $orderBy      Order list by. Default: URL.
	 *
	 * @return array|object|null Database query results or null on failer.
	 */
	public function get_websites_by_user_id( $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url' ) {
		return $this->get_results_result( $this->get_sql_websites_by_user_id( $userid, $selectgroups, $search_site, $orderBy ) );
	}

	/**
	 * Get child sites.
	 *
	 * @return string SQL string.
	 */
	public function get_sql_websites() {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );

		return 'SELECT wp.*,wp_sync.*,wp_optionview.*
                FROM ' . $this->table_name( 'wp' ) . ' wp
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE 1 ' . $where;
	}

	/**
	 * Get child sites to run the status check process.
	 *
	 * @param int $last_check Time of the last check.
	 * @param int $count      Number of websites.
	 *
	 * @return string SQL string.
	 */
	public function get_sql_websites_to_check_status( $last_check, $count = 20 ) {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );
		$sql   = 'SELECT wp.*
				FROM ' . $this->table_name( 'wp' ) . ' wp
				WHERE wp.disable_status_check <> 1 AND ( wp.status_check_interval = 0 AND wp.offline_checks_last < ' . intval( $last_check ) . ' )' .
				$where . '
				LIMIT ' . intval( $count );
		return $sql;
	}


	/**
	 * Get child sites to run the status individual check process.
	 *
	 * @param int $count      Number of websites.
	 *
	 * @return string SQL string.
	 */
	public function get_sql_websites_to_check_individual_status( $count = 20 ) {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );
		$sql   = 'SELECT wp.*
				FROM ' . $this->table_name( 'wp' ) . ' wp
				WHERE wp.disable_status_check <> 1 AND ( wp.status_check_interval <> 0 AND ( wp.offline_checks_last + wp.status_check_interval * 60 < UNIX_TIMESTAMP() ) )' .
				$where . '
				LIMIT ' . intval( $count );
		return $sql;
	}

	/**
	 * Get child sites by user id via SQL.
	 *
	 * @param int    $userid       Given user ID.
	 * @param bool   $selectgroups Selected groups. Default: false.
	 * @param null   $search_site  Site search field value. Default: null.
	 * @param string $orderBy      Order list by. Default: URL.
	 * @param bool   $offset       Query offset. Default: false.
	 * @param bool   $rowcount     Row count. Default: falese.
	 *
	 * @return object|null Return database query or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function get_sql_websites_by_user_id( $userid, $selectgroups = false, $search_site = null, $orderBy = 'wp.url', $offset = false, $rowcount = false ) {
		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			$where = '';
			if ( null !== $search_site ) {
				$search_site = trim( $search_site );
				$where       = ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
			}

			$where .= $this->get_sql_where_allow_access_sites( 'wp' );

			if ( $selectgroups ) {
				$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids
                FROM ' . $this->table_name( 'wp' ) . ' wp
                LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.userid = ' . $userid . "
                $where
                GROUP BY wp.id, wp_sync.sync_id
                ORDER BY " . $orderBy;
			} else {
				$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*
                FROM ' . $this->table_name( 'wp' ) . ' wp
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.userid = ' . $userid . "
                $where
                ORDER BY " . $orderBy;
			}

			if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
				$qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
			} elseif ( false !== $rowcount ) {
				$qry .= ' LIMIT ' . $rowcount;
			}

			return $qry;
		}

		return null;
	}

	/**
	 * Get SQL to get child sites for current user.
	 *
	 * @param bool   $selectgroups Selected groups. Default: false.
	 * @param null   $search_site  Site search field value. Default: null.
	 * @param string $orderBy      Order list by. Default: URL.
	 * @param bool   $offset       Query offset. Default: false.
	 * @param bool   $rowcount     Row count. Default: false.
	 * @param null   $extraWhere   Extra WHERE. Default: null.
	 * @param bool   $for_manager  For role manager. Default: false.
	 * @param mixed  $extra_view   Extra view. Default favi_icon.
	 * @param string $is_staging   yes|no Is child site a staging site.
	 * @param array  $params   other params.
	 *
	 * @return object|null Database query results or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_sql_websites_for_current_user(
		$selectgroups = false,
		$search_site = null,
		$orderBy = 'wp.url',
		$offset = false,
		$rowcount = false,
		$extraWhere = null,
		$for_manager = false,
		$extra_view = array( 'favi_icon' ),
		$is_staging = 'no',
		$params = array()
	) {

		$where = '';
		if ( MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$where .= ' AND wp.userid = ' . $current_user->ID . ' ';
		}

		if ( null !== $search_site ) {
			$search_site = trim( $search_site );
			$where      .= ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
		}

		if ( null !== $extraWhere ) {
			$where .= ' AND ' . $extraWhere;
		}

		if ( ! $for_manager ) {
			$where .= $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
		}

		$connected_sql = '';
		if ( is_array( $params ) ) {
			if ( isset( $params['connected'] ) ) {
				if ( 'yes' === $params['connected'] ) {
					$connected_sql = ' AND wp_sync.sync_errors = "" ';
				} elseif ( 'no' === $params['connected'] ) {
					$connected_sql = '  AND wp_sync.sync_errors <> "" ';
				}
			}
		}

		if ( 'wp.url' === $orderBy ) {
			$orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')";
		}

		// wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
		if ( $selectgroups ) {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids,
            wpclient.name as client_name
            FROM ' . $this->table_name( 'wp' ) . ' wp
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
			LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $connected_sql . '
            GROUP BY wp.id, wp_sync.sync_id 
            ORDER BY ' . $orderBy;
		} else {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, wpclient.name as client_name
            FROM ' . $this->table_name( 'wp' ) . ' wp
			LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $connected_sql . '
            GROUP BY wp.id, wp_sync.sync_id 
            ORDER BY ' . $orderBy;
		}

		if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
			$qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
		} elseif ( false !== $rowcount ) {
			$qry .= ' LIMIT ' . $rowcount;
		}

		return $qry;
	}

	/**
	 * Get SQL to get wp child sites for current user.
	 *
	 * @since 4.3
	 *
	 * @param array $params params .
	 *
	 * @return object|null Database query results or null on failure.
	 */
	public function get_sql_wp_for_current_user( $params = array() ) {
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$selectgroups = ! empty( $params['select_groups'] ) ? true : false;
		$search_site  = isset( $params['search_site'] ) && ! empty( $params['search_site'] ) ? $params['search_site'] : null;
		$orderBy      = isset( $params['order_by'] ) && ! empty( $params['order_by'] ) ? $params['order_by'] : 'wp.url';
		$offset       = isset( $params['offset'] ) ? $params['offset'] : false;
		$rowcount     = isset( $params['row_count'] ) ? $params['row_count'] : false;
		$for_manager  = isset( $params['for_manager'] ) ? $params['for_manager'] : false;
		$extraWhere   = isset( $params['extra_where'] ) && ! empty( $params['extra_where'] ) ? $params['extra_where'] : null;
		$extra_view   = isset( $params['extra_view'] ) && is_array( $params['extra_view'] ) && ! empty( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
		$extra_join   = isset( $params['extra_join'] ) ? $params['extra_join'] : '';

		$extra_select_wp_fields  = isset( $params['extra_select_wp_fields'] ) && is_array( $params['extra_select_wp_fields'] ) && ! empty( $params['extra_select_wp_fields'] ) ? $params['extra_select_wp_fields'] : array();
		$extra_select_sql_fields = isset( $params['extra_select_sql_fields'] ) && ! empty( $params['extra_select_sql_fields'] ) ? $params['extra_select_sql_fields'] : '';

		$is_staging = isset( $params['is_staging'] ) && 'yes' === $params['is_staging'] ? 'yes' : 'no';
		$count_only = isset( $params['count_only'] ) && $params['count_only'] ? true : false;

		$where = '';

		if ( null !== $search_site ) {
			$search_site = trim( $search_site );
			$where      .= ' AND (wp.name LIKE "%' . $this->escape( $search_site ) . '%" OR wp.url LIKE  "%' . $this->escape( $search_site ) . '%") ';
		}

		if ( null !== $extraWhere ) {
			$where .= ' AND ' . $extraWhere;
		}

		if ( ! $for_manager ) {
			$where .= $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
		}

		if ( 'wp.url' === $orderBy ) {
			$orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')";
		}

		$select_wp_fields = $this->get_sql_select_wp_valid_fields( $extra_select_wp_fields );

		if ( ! empty( $extra_select_sql_fields ) ) {
			$extra_select_sql_fields = ',' . $extra_select_sql_fields;
		}

		// wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
		if ( $selectgroups ) {
			if ( $count_only ) {
				$select = ' COUNT(DISTINCT(wp.id)) ';
			} else {
				$select = $select_wp_fields . '
				' . $extra_select_sql_fields . '
				,wp_sync.sync_errors,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, wpclient.name as client_name ';
			}
			$qry = 'SELECT ' . $select . '
            FROM ' . $this->table_name( 'wp' ) . ' wp
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
			LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid ' .
			$extra_join . '
            WHERE 1 ' . $where;
			if ( ! $count_only ) {
				$qry .= ' GROUP BY wp.id, wp_sync.sync_id';
			}
			$qry .= ' ORDER BY ' . $orderBy;
		} else {
			if ( $count_only ) {
				$select = ' COUNT(DISTINCT(wp.id)) ';
			} else {
				$select = $select_wp_fields . '
				' . $extra_select_sql_fields . '
				,wp_sync.sync_errors,wp_optionview.*, wpclient.name as client_name ';
			}
			$qry = 'SELECT ' . $select . '
            FROM ' . $this->table_name( 'wp' ) . ' wp
			LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid ' .
			$extra_join . '
            WHERE 1 ' . $where;
			if ( ! $count_only ) {
				$qry .= ' GROUP BY wp.id, wp_sync.sync_id';
			}
			$qry .= ' ORDER BY ' . $orderBy;
		}

		if ( ! $count_only ) {
			if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
				$qry .= ' LIMIT ' . intval( $offset ) . ', ' . intval( $rowcount );
			} elseif ( false !== $rowcount ) {
				$qry .= ' LIMIT ' . intval( $rowcount );
			}
		}
		return $qry;
	}
	/**
	 * Get SQL select websites fields.
	 *
	 * @since 4.3
	 *
	 * @param array $other_fields extra select wp fields .
	 *
	 * @return string sql string.
	 */
	public function get_sql_select_wp_valid_fields( $other_fields = array() ) {

		$allow_other_fields = array(
			'offline_checks_last',
			'offline_check_result',
			'http_response_code',
			'disable_status_check',
			'disable_health_check',
			'status_check_interval',
			'health_threshold',
			'note',
			'statsUpdate',
			'directories',
			'plugin_upgrades',
			'theme_upgrades',
			'translation_upgrades',
			'premium_upgrades',
			'securityIssues',
			'themes',
			'ignored_themes',
			'plugins',
			'ignored_plugins',
			'users',
			'categories',
			'pluginDir',
			'automatic_update',
			'backup_before_upgrade',
			'mainwpdir',
			'is_ignoreCoreUpdates',
			'is_ignorePluginUpdates',
			'is_ignoreThemeUpdates',
			'verify_certificate',
			'force_use_ipv4',
			'ssl_version',
			'http_user',
			'http_pass',
			'wpe',
			'is_staging',
			'client_id',
		);

		$default_fields = array( 'id', 'url', 'name', 'adminname', 'verify_certificate', 'ssl_version', 'http_user', 'http_pass', 'suspended' );

		$select = ' ';

		foreach ( $default_fields as $field ) {
			$select .= 'wp.' . $this->escape( $field ) . ',';
		}
		foreach ( $other_fields as $field ) {
			if ( ! in_array( $field, $allow_other_fields ) ) {
				continue;
			}
			if ( in_array( $field, $default_fields ) ) {
				continue;
			}
			$select .= 'wp.' . $this->escape( $field ) . ',';
		}
		$select = rtrim( $select, ',' );
		return $select;
	}

	/**
	 * Get child sites for current user.
	 *
	 * @param array $params to get sites. Default: array().
	 *
	 * @return array Results or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
	 */
	public function get_websites_for_current_user( $params = array() ) {
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$selectgroups = isset( $params['selectgroups'] ) ? $params['selectgroups'] : false;
		$search_site  = isset( $params['search_site'] ) ? $params['search_site'] : null;
		$orderBy      = isset( $params['order_by'] ) ? $params['order_by'] : 'wp.url';
		$offset       = isset( $params['offset'] ) ? $params['offset'] : false;
		$rowcount     = isset( $params['rowcount'] ) ? $params['rowcount'] : false;
		$extraWhere   = isset( $params['where'] ) ? $params['where'] : null;
		$extra_view   = isset( $params['extra_view'] ) && is_array( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
		$is_staging   = isset( $params['is_staging'] ) ? $params['is_staging'] : 'no';
		$full_data    = isset( $params['full_data'] ) && $params['full_data'] && ( 'no' !== $params['full_data'] ) ? true : false;
		$select_data  = isset( $params['select_data'] ) && is_array( $params['select_data'] ) ? $params['select_data'] : false;
		$format       = isset( $params['format'] ) ? $params['format'] : '';
		$clients      = isset( $params['client'] ) ? $params['client'] : '';

		$for_manager = false;

		$urlsWhere = '';

		if ( isset( $params['urls'] ) && ! empty( $params['urls'] ) ) {
			$urls = explode( ';', $params['urls'] );
			foreach ( $urls as $url ) {
				$url = str_replace( array( 'https://www.', 'http://www.', 'https://', 'http://', 'www.' ), array( '', '', '', '', '' ), $url );
				if ( '/' !== substr( $url, - 1 ) ) {
					$url .= '/';
				}
				$urlsWhere .= '"' . $this->escape( $url ) . '", ';
			}
			$urlsWhere = rtrim( $urlsWhere, ', ' );
		}

		if ( ! empty( $urlsWhere ) ) {
			$urlsWhere = " ( replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '') IN ( " . $urlsWhere . ') ) ';

			if ( empty( $extraWhere ) ) {
				$extraWhere = $urlsWhere;
			} else {
				$extraWhere = $extraWhere . ' AND ' . $urlsWhere;
			}
		}

		$clientWhere = '';
		if ( ! empty( $clients ) ) {
			$clients = explode( ';', $clients );
			foreach ( $clients as $client ) {
				if ( is_numeric( $client ) ) {
					$clientWhere .= intval( $client ) . ', ';
				}
			}
			$clientWhere = rtrim( $clientWhere, ', ' );
		}

		if ( ! empty( $clientWhere ) ) {
			$clientWhere = ' ( wp.client_id IN ( ' . $clientWhere . ') ) ';
			if ( empty( $extraWhere ) ) {
				$extraWhere = $clientWhere;
			} else {
				$extraWhere = $extraWhere . ' AND ' . $clientWhere;
			}
		}

		$data = array( 'id', 'url', 'name', 'client_id' );

		if ( $full_data ) {
			$data = array(
				'id',
				'url',
				'name',
				'offline_checks_last',
				'offline_check_result',
				'http_response_code',
				'disable_status_check',
				'disable_health_check',
				'status_check_interval',
				'health_threshold',
				'note',
				'plugin_upgrades',
				'theme_upgrades',
				'translation_upgrades',
				'securityIssues',
				'themes',
				'plugins',
				'automatic_update',
				'sync_errors',
				'dtsAutomaticSync',
				'dtsAutomaticSyncStart',
				'dtsSync',
				'dtsSyncStart',
				'last_post_gmt',
				'health_value',
				'phpversion',
				'wp_upgrades',
				'security_stats',
				'client_id',
				'adminname',
				'privkey',
				'http_user',
				'http_pass',
				'ssl_version',
				'signature_algo',
				'verify_method',
				'verify_certificate',
			);

			if ( ! in_array( 'security_stats', $extra_view ) ) {
				$extra_view[] = 'security_stats';
			}
		}

		if ( ! empty( $select_data ) && is_array( $select_data ) ) {
			$data = $select_data;
		}

		if ( $selectgroups ) {
			$data[] = 'wpgroups';
		}

		$dbwebsites = array();
		$websites   = $this->query( $this->get_sql_websites_for_current_user( $selectgroups, $search_site, $orderBy, $offset, $rowcount, $extraWhere, $for_manager, $extra_view, $is_staging ) );
		while ( $websites && ( $website = self::fetch_object( $websites ) ) ) {
			$obj_data = MainWP_Utility::map_site( $website, $data );

			if ( $full_data ) {
				$sum_upgrades = 0;
				if ( '' !== $obj_data->plugin_upgrades ) {
					$plugin_upgrades = json_decode( $obj_data->plugin_upgrades, true );
					if ( is_array( $plugin_upgrades ) ) {
						$sum_upgrades += count( $plugin_upgrades );
					}
				}

				if ( '' !== $obj_data->theme_upgrades ) {
					$theme_upgrades = json_decode( $obj_data->theme_upgrades, true );
					if ( is_array( $theme_upgrades ) ) {
						$sum_upgrades += count( $theme_upgrades );
					}
				}

				if ( '' !== $obj_data->wp_upgrades ) {
					$wp_upgrades = json_decode( $obj_data->wp_upgrades, true );
					if ( is_array( $wp_upgrades ) ) {
						$sum_upgrades += count( $wp_upgrades );
					}
				}
				$obj_data->sum_of_upgrades = $sum_upgrades;
			}

			if ( 'array' === $format ) {
				$dbwebsites[] = $obj_data;
			} else {
				$dbwebsites[ $website->id ] = $obj_data;
			}
		}
		self::free_result( $websites );
		return $dbwebsites;
	}

	/**
	 * Get the child sites the current user has searched for.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return boolean|null $qry Database query results or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_sql_search_websites_for_current_user( $params ) {

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$selectgroups   = isset( $params['selectgroups'] ) && $params['selectgroups'] ? true : false;
		$search_site    = isset( $params['search'] ) ? $this->escape( trim( $params['search'] ) ) : null;
		$orderBy        = isset( $params['orderby'] ) ? $params['orderby'] : 'wp.url';
		$offset         = isset( $params['offset'] ) ? intval( $params['offset'] ) : false;
		$rowcount       = isset( $params['rowcount'] ) ? intval( $params['rowcount'] ) : false;
		$extraWhere     = isset( $params['extra_where'] ) ? $params['extra_where'] : null;
		$for_manager    = isset( $params['for_manager'] ) && $params['for_manager'] ? true : false;
		$extra_view     = isset( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
		$is_staging     = isset( $params['is_staging'] ) && 'yes' === $params['is_staging'] ? 'yes' : 'no';
		$is_count       = isset( $params['count_only'] ) && $params['count_only'] ? true : false;
		$group_ids      = isset( $params['group_id'] ) && ! empty( $params['group_id'] ) ? $params['group_id'] : array();
		$client_ids     = isset( $params['client_id'] ) && ! empty( $params['client_id'] ) ? $params['client_id'] : array();
		$is_not         = isset( $params['isnot'] ) && ! empty( $params['isnot'] ) ? true : false;
		$selected_sites = isset( $params['selected_sites'] ) ? $params['selected_sites'] : array();

		if ( ! is_array( $group_ids ) ) {
			$group_ids = array();
		}

		// valid group ids.
		$group_ids = array_filter(
			$group_ids,
			function ( $e ) {
				if ( 'nogroups' === $e ) {
					return true;
				}
				return ( is_numeric( $e ) && 0 < $e ) ? true : false;
			}
		);

		if ( ! is_array( $client_ids ) ) {
			$client_ids = array();
		}

		// valid group ids.
		$client_ids = array_filter(
			$client_ids,
			function ( $e ) {
				if ( 'noclients' === $e ) {
					return true;
				}
				return is_numeric( $e ) && ! empty( $e ) ? true : false; // to valid client ids.
			}
		);

		if ( $selectgroups ) {
			$staging_group = get_option( 'mainwp_stagingsites_group_id' );
			if ( $staging_group ) {
				if ( in_array( $staging_group, $group_ids ) ) {
					if ( 0 === count( $group_ids ) ) {
						$is_staging = 'yes';
					} else {
						$is_staging = 'nocheckstaging';
					}
				}
			}
		}

		if ( ! is_array( $selected_sites ) ) {
			$selected_sites = array();
		}
		$selected_sites = MainWP_Utility::array_numeric_filter( $selected_sites );

		$where = '';
		if ( MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$where .= ' AND wp.userid = ' . $current_user->ID . ' ';
		}

		if ( ! empty( $selected_sites ) ) {
			$where .= ' AND wp.id IN (' . implode( ',', $selected_sites ) . ') ';
		}

		// for searching.
		if ( null !== $search_site && '' !== $search_site ) {
			$where .= ' AND (wp.name LIKE "%' . $search_site . '%" OR wp.url LIKE  "%' . $search_site . '%") ';
		}

		if ( null !== $extraWhere ) {
			$where .= ' AND ' . $extraWhere;
		}

		if ( ! $for_manager ) {
			$where .= $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
		}

		if ( $is_count ) {
			$orderBy = '';
		} elseif ( 'wp.url' === $orderBy ) {
			$orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')";
		}

		if ( ! empty( $orderBy ) ) {
			$orderBy = ' ORDER BY ' . $orderBy;
		}

		$join_group  = '';
		$where_group = '';

		if ( in_array( 'nogroups', $group_ids ) ) {
			$join_group = ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
			$group_ids  = array_filter(
				$group_ids,
				function ( $e ) {
					return 'nogroups' !== $e;
				}
			);
			if ( 0 < count( $group_ids ) ) {
				$groups = implode( ',', $group_ids );
				if ( $is_not ) {
					$where_group = ' AND wpgroup.groupid IS NOT NULL AND wpgroup.groupid NOT IN (' . $groups . ') ';
					// to fix.
					$sub_select_is_not = ' SELECT wp.id FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . $groups . ') ';
					$where_group      .= ' AND wp.id NOT IN ( ' . $sub_select_is_not . ' ) ';
				} else {
					$where_group = ' AND ( wpgroup.groupid IS NULL OR wpgroup.groupid IN (' . $groups . ') ) ';
				}
			} elseif ( $is_not ) {
					$where_group = ' AND wpgroup.groupid IS NOT NULL ';
			} else {
				$where_group = ' AND wpgroup.groupid IS NULL ';
			}
		} elseif ( $group_ids && 0 < count( $group_ids ) ) {
			$groups = implode( ',', $group_ids );
			if ( $is_not ) {
				$join_group  = ' LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
				$where_group = ' AND ( wpgroup.groupid NOT IN (' . $groups . ') OR wpgroup.groupid IS NULL ) ';
				// to fix.
				$sub_select_is_not = ' SELECT wp.id FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . $groups . ') ';
				$where_group      .= ' AND wp.id NOT IN ( ' . $sub_select_is_not . ' ) ';
			} else {
				$join_group  = ' JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
				$where_group = ' AND wpgroup.groupid IN (' . $groups . ') ';
			}
		}

		$join_client  = '';
		$where_client = '';

		if ( in_array( 'noclients', $client_ids ) ) {
			$join_client = ' LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
			$client_ids  = array_filter(
				$client_ids,
				function ( $e ) {
					return 'noclients' !== $e;
				}
			);
			if ( 0 < count( $client_ids ) ) {
				$clients = implode( ',', $client_ids );
				if ( $is_not ) {
					$where_client = ' AND wpclient.client_id IS NOT NULL AND wp.client_id NOT IN (' . $clients . ') ';
				} else {
					$where_client = ' AND wpclient.client_id IN (' . $clients . ') ';
				}
			} elseif ( $is_not ) {
					$where_client = ' AND wpclient.client_id IS NOT NULL ';
			} else {
				$where_client = ' AND wpclient.client_id IS NULL ';
			}
		} elseif ( $client_ids && 0 < count( $client_ids ) ) {
			$clients = implode( ',', $client_ids );
			if ( $is_not ) {
				$join_client  = ' LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
				$where_client = ' AND ( wpclient.client_id NOT IN (' . $clients . ') OR wpclient.client_id IS NULL ) ';
			} else {
				$join_client  = ' JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
				$where_client = ' AND wpclient.client_id IN (' . $clients . ') ';
			}
		}

		if ( '' === $join_client ) {
			$join_client = ' LEFT JOIN ' . $this->table_name( 'wp_clients' ) . ' wpclient ON wp.client_id = wpclient.client_id ';
		}
		// wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
		if ( $selectgroups ) {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids, wpclient.name as client_name 
            FROM ' . $this->table_name( 'wp' ) . ' wp ' .
			$join_group . ' ' .
			$join_client . '
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
			
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $where_group . $where_client . '
			GROUP BY wp.id, wp_sync.sync_id ' .
			$orderBy;
		} else {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, wpclient.name as client_name
            FROM ' . $this->table_name( 'wp' ) . ' wp ' .
			$join_group . ' ' .
			$join_client . '
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
			WHERE 1 ' . $where . $where_group . $where_client . '
			GROUP BY wp.id, wp_sync.sync_id ' .
			$orderBy;
		}

		if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
			$qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
		} elseif ( false !== $rowcount ) {
			$qry .= ' LIMIT ' . $rowcount;
		}
		return $qry;
	}

	/**
	 * Get child sites where allowed access via SQL.
	 *
	 * @param string $site_table_alias Child site table alias.
	 * @param string $is_staging       yes|no Is child site a staging site.
	 *
	 * @return boolean|null $_where Database query results or null on failure.
	 */
	public function get_sql_where_allow_access_sites( $site_table_alias = '', $is_staging = 'no' ) {

		if ( empty( $site_table_alias ) ) {
			$site_table_alias = $this->table_name( 'wp' );
		}

		// check to filter the staging sites.
		$where_staging = ' AND ' . $site_table_alias . '.is_staging = 0 ';
		if ( 'no' === $is_staging ) {
			$where_staging = ' AND ' . $site_table_alias . '.is_staging = 0 ';
		} elseif ( 'yes' === $is_staging ) {
			$where_staging = ' AND ' . $site_table_alias . '.is_staging = 1 ';
		} elseif ( 'nocheckstaging' === $is_staging ) {
			$where_staging = '';
		}
		// end staging filter.

		$_where = $where_staging;
		// To fix bug run from cron job.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return $_where;
		}

		// To fix bug run from wp cli.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $_where;
		}

		// Run from Rest Api.
		if ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) {
			return $_where;
		}

		/**
		 * Filter: mainwp_currentuserallowedaccesssites
		 *
		 * Filters allowed sites for the current user.
		 *
		 * @since Unknown
		 */
		$allowed_sites = apply_filters( 'mainwp_currentuserallowedaccesssites', 'all' );

		if ( 'all' === $allowed_sites ) {
			return $_where;
		}

		if ( is_array( $allowed_sites ) && 0 < count( $allowed_sites ) ) {
			// valid group ids.
			$allowed_sites = array_filter(
				$allowed_sites,
				function ( $e ) {
					return is_numeric( $e ) ? true : false;
				}
			);
			$_where       .= ' AND ' . $site_table_alias . '.id IN (' . implode( ',', $allowed_sites ) . ') ';
		} else {
			$_where .= ' AND 0 ';
		}

		return $_where;
	}

	/**
	 * Get groupd where allowed access via SQL.
	 *
	 * @param string $group_table_alias Child site table alias.
	 * @param string $with_staging      yes|no Is child site a staging site.
	 *
	 * @return boolean|null $_where Database query results or null on failer.
	 */
	public function get_sql_where_allow_groups( $group_table_alias = '', $with_staging = 'no' ) {

		if ( empty( $group_table_alias ) ) {
			$group_table_alias = $this->table_name( 'group' );
		}

		// check to filter the staging group.
		$where_staging_group = '';
		$staging_group       = get_option( 'mainwp_stagingsites_group_id' );
		if ( $staging_group ) {
			$where_staging_group = ' AND ' . $group_table_alias . '.id <> ' . $staging_group . ' ';
			if ( 'yes' === $with_staging ) {
				$where_staging_group = '';
			}
		}

		// end staging filter.
		$_where = $where_staging_group;

		// To fix bug run from cron job.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return $_where;
		}

		// Run from wp cli.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $_where;
		}

		// Run from Rest Api.
		if ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) {
			return $_where;
		}

		/**
		 * Filter: mainwp_currentuserallowedaccessgroups
		 *
		 * Filters allowed groups for the current user.
		 *
		 * @since Unknown
		 */
		$allowed_groups = apply_filters( 'mainwp_currentuserallowedaccessgroups', 'all' );

		if ( 'all' === $allowed_groups ) {
			return $_where;
		}

		if ( is_array( $allowed_groups ) && 0 < count( $allowed_groups ) ) {

			// valid group ids.
			$allowed_groups = array_filter(
				$allowed_groups,
				function ( $e ) {
					return is_numeric( $e ) ? true : false;
				}
			);

			return ' AND ' . $group_table_alias . '.id IN (' . implode( ',', $allowed_groups ) . ') ' . $_where;
		} else {
			return ' AND 0 ';
		}
	}

	/**
	 * Get child site by id.
	 *
	 * @param int   $id           Child site ID.
	 * @param array $selectGroups Select groups.
	 * @param array $extra_view       Get extra option fields.
	 *
	 * @return object|null Database query results or null on failure.
	 */
	public function get_website_by_id( $id, $selectGroups = false, $extra_view = array() ) {
		return $this->get_row_result( $this->get_sql_website_by_id( $id, $selectGroups, $extra_view ) );
	}

	/**
	 * Get child site by id via SQL.
	 *
	 * @param int   $id           Child site ID.
	 * @param bool  $selectGroups Selected groups.
	 * @param mixed $extra_view   Extra view value.
	 *
	 * @return object|null Database query result or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function get_sql_website_by_id( $id, $selectGroups = false, $extra_view = array() ) {

		if ( ! is_array( $extra_view ) || empty( $extra_view ) ) {
			$extra_view = array( 'favi_icon', 'site_info' );
		}

		if ( MainWP_Utility::ctype_digit( $id ) ) {
			$where = $this->get_sql_where_allow_access_sites( 'wp', 'nocheckstaging' );
			if ( $selectGroups ) {
				return 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids
                FROM ' . $this->table_name( 'wp' ) . ' wp
                LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.id = ' . $id . $where . '
                GROUP BY wp.id, wp_sync.sync_id';
			}

			return 'SELECT wp.*,wp_sync.*,wp_optionview.*
                    FROM ' . $this->table_name( 'wp' ) . ' wp
                    JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                    JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                    WHERE id = ' . $id . $where;
		}

		return null;
	}

	/**
	 * Method get_websites_by_ids()
	 *
	 * Get child sites by child site IDs.
	 *
	 * @param array $ids Child site IDs.
	 * @param int   $userId User ID.
	 *
	 * @return object|null Database query result or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_websites_by_ids( $ids, $userId = null ) {
		if ( ( null === $userId ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userId = $current_user->ID;
		}

		// valid group ids.
		$ids = array_filter(
			$ids,
			function ( $e ) {
				return ( is_numeric( $e ) && 0 < $e ) ? true : false;
			}
		);

		$where = $this->get_sql_where_allow_access_sites();

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' . ( null !== $userId ? ' AND userid = ' . intval( $userId ) : '' ) . $where, OBJECT );
	}

	/**
	 * Get child sites by groups IDs.
	 *
	 * @param array $ids    Groups IDs.
	 * @param int   $userId User ID.
	 *
	 * @return object|null Database query result or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_websites_by_group_ids( $ids, $userId = null ) {
		if ( empty( $ids ) ) {
			return array();
		}
		if ( ( null === $userId ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userId = $current_user->ID;
		}

		// valid group ids.
		$group_ids = array_filter(
			$ids,
			function ( $e ) {
				return is_numeric( $e ) ? true : false;
			}
		);

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . implode( ',', $group_ids ) . ') ' . ( null !== $userId ? ' AND wp.userid = ' . intval( $userId ) : '' ), OBJECT );
	}

	/**
	 * Get child sites by group ID.
	 *
	 * @param int $id Group ID.
	 *
	 * @return object|null Database query result or null on failure.
	 */
	public function get_websites_by_group_id( $id ) {
		return $this->get_results_result( $this->get_sql_websites_by_group_id( $id ) );
	}

	/**
	 * Get child sites by group id via SQL.
	 *
	 * @param int    $id           Group ID.
	 * @param bool   $selectgroups Selected groups. Default: false.
	 * @param string $orderBy      Order list by. Default: URL.
	 * @param bool   $offset       Query offset. Default: false.
	 * @param bool   $rowcount     Row count. Default: falese.
	 * @param null   $where        SQL WHERE value.
	 * @param null   $search_site  Site search field value. Default: null.
	 *
	 * @return object|null Return database query or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function get_sql_websites_by_group_id(
		$id,
		$selectgroups = false,
		$orderBy = 'wp.url',
		$offset = false,
		$rowcount = false,
		$where = null,
		$search_site = null
	) {

		$is_staging = 'no';
		if ( $selectgroups ) {
			$staging_group = get_option( 'mainwp_stagingsites_group_id' );
			if ( $staging_group ) {
				if ( $id === $staging_group ) {
					$is_staging = 'yes';
				}
			}
		}

		$where_search = '';
		if ( ! empty( $search_site ) ) {
			$search_site   = trim( $search_site );
			$where_search .= ' AND (wp.name LIKE "%' . $this->escape( $search_site ) . '%" OR wp.url LIKE  "%' . $this->escape( $search_site ) . '%") ';
		}

		if ( MainWP_Utility::ctype_digit( $id ) ) {
			$where_allowed = $this->get_sql_where_allow_access_sites( 'wp', $is_staging );
			if ( $selectgroups ) {
				$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ",") as wpgroups, GROUP_CONCAT(gr.id ORDER BY gr.name SEPARATOR ",") as wpgroupids
                 FROM ' . $this->table_name( 'wp' ) . ' wp
                 JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                 LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                 LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                 JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                 JOIN ' . $this->get_option_view( array( 'site_info' ), true ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                 WHERE wpgroup.groupid = ' . $id . ' ' .
				( empty( $where ) ? '' : ' AND ' . $where ) . $where_allowed . $where_search . '
                 GROUP BY wp.id, wp_sync.sync_id
                 ORDER BY ' . $orderBy;
			} else {
				$qry = 'SELECT wp.*,wp_optionview.*, wp_sync.* FROM ' . $this->table_name( 'wp' ) . ' wp
                        JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                        JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
						JOIN ' . $this->get_option_view( array( 'site_info' ), false ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                        WHERE wpgroup.groupid = ' . $id . ' ' . $where_allowed . $where_search .
				( empty( $where ) ? '' : ' AND ' . $where ) . ' ORDER BY ' . $orderBy;
			}
			if ( ( false !== $offset ) && ( false !== $rowcount ) ) {
				$qry .= ' LIMIT ' . $offset . ', ' . $rowcount;
			} elseif ( false !== $rowcount ) {
				$qry .= ' LIMIT ' . $rowcount;
			}

			return $qry;
		}

		return null;
	}

	/**
	 * Get child sites by group name.
	 *
	 * @param int    $userid Current user ID.
	 * @param string $groupname Group name.
	 *
	 * @return object|null Database query result or null on failure.
	 */
	public function get_websites_by_group_name( $userid, $groupname ) {
		return $this->get_results_result( $this->get_sql_websites_by_group_name( $groupname, $userid ) );
	}

	/**
	 * Get child sites by group name.
	 *
	 * @param string $groupname Group name.
	 * @param int    $userid    Current user ID.
	 *
	 * @return object|null Database query result or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_sql_websites_by_group_name( $groupname, $userid = null ) {
		if ( ( null === $userid ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userid = $current_user->ID;
		}

		$sql = 'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp
                INNER JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                JOIN ' . $this->table_name( 'group' ) . ' g ON wpgroup.groupid = g.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE g.name="' . $this->escape( $groupname ) . '"';
		if ( null !== $userid ) {
			$sql .= ' AND g.userid = "' . intval( $userid ) . '"';
		}

		return $sql;
	}

	/**
	 * Get child site IP address.
	 *
	 * @param int $wpid Child site ID.
	 *
	 * @return string|null Child site IP address or null on failure.
	 */
	public function get_wp_ip( $wpid ) {
		return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ip FROM ' . $this->table_name( 'request_log' ) . ' WHERE wpid = %d', $wpid ) );
	}

	/**
	 * Add website to the MainWP Dashboard.
	 *
	 * @param int    $userid Current user ID.
	 * @param string $name Child site name.
	 * @param string $url Child site URL.
	 * @param string $admin Child site administrator username.
	 * @param string $pubkey OpenSSL public key.
	 * @param string $privkey OpenSSL private key.
	 * @param array  $groupids Group IDs.
	 * @param array  $groupnames Group names.
	 * @param int    $verifyCertificate Whether or not to verify SSL Certificate.
	 * @param string $uniqueId Unique security ID.
	 * @param string $http_user HTTP Basic Authentication username.
	 * @param string $http_pass HTTP Basic Authentication password.
	 * @param int    $sslVersion SSL Version.
	 * @param int    $wpe Is it WP Engine hosted site.
	 * @param int    $isStaging Whether or not child site is staging site.
	 *
	 * @return int|false Child site ID or false on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function add_website(
		$userid,
		$name,
		$url,
		$admin,
		$pubkey,
		$privkey,
		$groupids,
		$groupnames,
		$verifyCertificate = 1,
		$uniqueId = '',
		$http_user = null,
		$http_pass = null,
		$sslVersion = 0,
		$wpe = 0,
		$isStaging = 0
	) {

		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			if ( '/' !== substr( $url, - 1 ) ) {
				$url .= '/';
			}
			$values = array(
				'userid'                => $userid,
				'adminname'             => $this->escape( $admin ),
				'name'                  => $this->escape( wp_strip_all_tags( $name ) ),
				'url'                   => $this->escape( $url ),
				'pubkey'                => $this->escape( $pubkey ),
				'privkey'               => $this->escape( $privkey ),
				'siteurl'               => '',
				'ga_id'                 => '',
				'gas_id'                => 0,
				'offline_checks_last'   => 0,
				'offline_check_result'  => 0,
				'note'                  => '',
				'statsUpdate'           => 0,
				'directories'           => '',
				'plugin_upgrades'       => '',
				'theme_upgrades'        => '',
				'translation_upgrades'  => '',
				'securityIssues'        => '',
				'themes'                => '',
				'ignored_themes'        => '',
				'plugins'               => '',
				'ignored_plugins'       => '',
				'users'                 => '',
				'categories'            => '',
				'pluginDir'             => '',
				'automatic_update'      => 0,
				'backup_before_upgrade' => 2,
				'verify_certificate'    => intval( $verifyCertificate ),
				'ssl_version'           => $sslVersion,
				'uniqueId'              => $uniqueId,
				'mainwpdir'             => 0,
				'http_user'             => $http_user,
				'http_pass'             => $http_pass,
				'wpe'                   => $wpe,
				'is_staging'            => $isStaging,
			);

			$syncValues = array(
				'dtsSync'               => 0,
				'dtsSyncStart'          => 0,
				'dtsAutomaticSync'      => 0,
				'dtsAutomaticSyncStart' => 0,
				'totalsize'             => 0,
				'extauth'               => '',
				'sync_errors'           => '',
			);
			if ( $this->wpdb->insert( $this->table_name( 'wp' ), $values ) ) {
				$websiteid          = $this->wpdb->insert_id;
				$syncValues['wpid'] = $websiteid;
				$this->wpdb->insert( $this->table_name( 'wp_sync' ), $syncValues );
				$this->wpdb->insert(
					$this->table_name( 'wp_settings_backup' ),
					array(
						'wpid'          => $websiteid,
						'archiveFormat' => 'global',
					)
				);

				foreach ( $groupnames as $groupname ) {
					if ( $this->wpdb->insert(
						$this->table_name( 'group' ),
						array(
							'userid' => $userid,
							'name'   => $this->escape( htmlspecialchars( $groupname ) ),
						)
					)
					) {
						$groupids[] = $this->wpdb->insert_id;
					}
				}
				// add groupids.
				foreach ( $groupids as $groupid ) {
					$this->wpdb->insert(
						$this->table_name( 'wp_group' ),
						array(
							'wpid'    => $websiteid,
							'groupid' => $groupid,
						)
					);
				}

				return $websiteid;
			}
		}

		return false;
	}

	/**
	 * Remove child site from the MainWP Dashboard.
	 *
	 * @param int $websiteid Child site ID.
	 *
	 * @return int|boolean Return child site ID that was removed or false on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function remove_website( $websiteid ) {
		if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
			$nr = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp' ) . ' WHERE id=%d', $websiteid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_group' ) . ' WHERE wpid=%d', $websiteid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_sync' ) . ' WHERE wpid=%d', $websiteid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid=%d', $websiteid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_status' ) . ' WHERE wpid=%d', $websiteid ) );

			return $nr;
		}

		return false;
	}

	/**
	 * Update child site db values.
	 *
	 * @param int   $websiteid Child site ID.
	 * @param array $fields    Database fields to update.
	 *
	 * @return int|boolean The number of rows updated, or false on error.
	 */
	public function update_website_values( $websiteid, $fields ) {
		if ( 0 < count( $fields ) ) {
			return $this->wpdb->update( $this->table_name( 'wp' ), $fields, array( 'id' => $websiteid ) );
		}

		return false;
	}

	/**
	 * Update child site sync values.
	 *
	 * @param int   $websiteid Child site ID.
	 * @param array $fields    Database fields to update.
	 *
	 * @return int|boolean The number of rows updated, or false on error.
	 */
	public function update_website_sync_values( $websiteid, $fields ) {
		if ( 0 < count( $fields ) ) {
			return $this->wpdb->update( $this->table_name( 'wp_sync' ), $fields, array( 'wpid' => $websiteid ) );
		}

		return false;
	}

	/**
	 * Update child site.
	 *
	 * @param int    $websiteid Website ID.
	 * @param string $url Child site URL.
	 * @param int    $userid Current user ID.
	 * @param string $name Child site name.
	 * @param string $siteadmin Child site administrator username.
	 * @param array  $groupids Group IDs.
	 * @param array  $groupnames Group Names.
	 * @param string $pluginDir Plugin directory.
	 * @param mixed  $maximumFileDescriptorsOverride Overwrite the Maximum File Descriptors option.
	 * @param mixed  $maximumFileDescriptorsAuto Auto set the Maximum File Descriptors option.
	 * @param mixed  $maximumFileDescriptors Set the Maximum File Descriptors option.
	 * @param int    $verifyCertificate Whether or not to verify SSL Certificate.
	 * @param mixed  $archiveFormat Backup archive formate.
	 * @param string $uniqueId Unique security ID.
	 * @param string $http_user HTTP Basic Authentication username.
	 * @param string $http_pass HTTP Basic Authentication password.
	 * @param int    $sslVersion SSL Version.
	 * @param int    $disableChecking Wether or not disable sites status checking.
	 * @param int    $checkInterval Status checking interval.
	 * @param bool   $disableHealthChecking Disable Site health threshold.
	 * @param int    $healthThreshold Site health threshold.
	 * @param int    $wpe Is it WP Engine hosted site.
	 *
	 * @return boolean ture on success or false on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function update_website(
		$websiteid,
		$url,
		$userid,
		$name,
		$siteadmin,
		$groupids,
		$groupnames,
		$pluginDir,
		$maximumFileDescriptorsOverride,
		$maximumFileDescriptorsAuto,
		$maximumFileDescriptors,
		$verifyCertificate = 1,
		$archiveFormat = 'global',
		$uniqueId = '',
		$http_user = null,
		$http_pass = null,
		$sslVersion = 0,
		$disableChecking = 1,
		$checkInterval = 1440,
		$disableHealthChecking = 1,
		$healthThreshold = 80,
		$wpe = 0
	) {

		if ( MainWP_Utility::ctype_digit( $websiteid ) && MainWP_Utility::ctype_digit( $userid ) ) {
			$website = $this->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				// update admin.
				$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET url="' . $this->escape( $url ) . '", name="' . $this->escape( wp_strip_all_tags( $name ) ) . '", adminname="' . $this->escape( $siteadmin ) . '",pluginDir="' . $this->escape( $pluginDir ) . '", verify_certificate="' . intval( $verifyCertificate ) . '", ssl_version="' . intval( $sslVersion ) . '", wpe="' . intval( $wpe ) . '", uniqueId="' . $this->escape( $uniqueId ) . '", http_user="' . $this->escape( $http_user ) . '", http_pass="' . $this->escape( $http_pass ) . '", disable_status_check="' . $this->escape( $disableChecking ) . '", status_check_interval="' . $this->escape( $checkInterval ) . '", disable_health_check="' . $this->escape( $disableHealthChecking ) . '", health_threshold="' . $this->escape( $healthThreshold ) . '" WHERE id=%d', $websiteid ) );
				$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp_settings_backup' ) . ' SET archiveFormat = "' . $this->escape( $archiveFormat ) . '" WHERE wpid=%d', $websiteid ) );

				if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
					$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET maximumFileDescriptorsOverride = ' . ( $maximumFileDescriptorsOverride ? 1 : 0 ) . ',maximumFileDescriptorsAuto= ' . ( $maximumFileDescriptorsAuto ? 1 : 0 ) . ',maximumFileDescriptors = ' . $maximumFileDescriptors . ' WHERE id=%d', $websiteid ) );
				}

				// remove groups.
				$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_group' ) . ' WHERE wpid=%d', $websiteid ) );
				// Remove GA stats.
				$showErrors = $this->wpdb->hide_errors();

				/**
				 * Action: mainwp_ga_delete_site
				 *
				 * Fires upon site removal process in order to delete Google Analytics data.
				 *
				 * @param int $websiteid Child site ID.
				 *
				 * @since Unknown
				 */
				do_action( 'mainwp_ga_delete_site', $websiteid );

				if ( $showErrors ) {
					$this->wpdb->show_errors();
				}
				// add groups with groupnames.
				foreach ( $groupnames as $groupname ) {
					if ( $this->wpdb->insert(
						$this->table_name( 'group' ),
						array(
							'userid' => $userid,
							'name'   => $this->escape( $groupname ),
						)
					)
					) {
						$groupids[] = $this->wpdb->insert_id;
					}
				}
				// add groupids.
				foreach ( $groupids as $groupid ) {
					$this->wpdb->insert(
						$this->table_name( 'wp_group' ),
						array(
							'wpid'    => $websiteid,
							'groupid' => $groupid,
						)
					);
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Get websites check updates count.
	 *
	 * @param int $lasttime_start Lasttime start automatic update.
	 *
	 * @return int Child sites update count.
	 */
	public function get_websites_check_updates_count( $lasttime_start ) {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );

		return $this->wpdb->get_var( 'SELECT count(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE ( wp_sync.dtsAutomaticSyncStart = 0 OR wp_sync.dtsAutomaticSyncStart < ' . intval( $lasttime_start ) . ')' . $where );
	}

	/**
	 * Get child site count where date & time Session sync is smaller then start.
	 *
	 * @param int $lasttime_start Last time start automatic.
	 *
	 * @return int Returned child site count.
	 */
	public function get_websites_count_where_dts_automatic_sync_smaller_then_start( $lasttime_start ) {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );

		return $this->wpdb->get_var( 'SELECT count(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE (( wp_sync.dtsAutomaticSync < wp_sync.dtsAutomaticSyncStart AND wp_sync.dtsAutomaticSyncStart > ' . intval( $lasttime_start ) . ') OR (wp_sync.dtsAutomaticSyncStart = 0)) ' . $where );
	}

	/**
	 * Get child site last automatic sync date & time.
	 *
	 * @return string Date and time of last automatic sync.
	 */
	public function get_websites_last_automatic_sync() {
		return $this->wpdb->get_var( 'SELECT MAX(wp_sync.dtsAutomaticSync) FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid' );
	}

	/**
	 * Get child sites check updates.
	 *
	 * @param int $limit Query limit.
	 * @param int $lasttime_start Lasttime start automatic update.
	 *
	 * @return object|null Database query result or null on failure.
	 */
	public function get_websites_check_updates( $limit, $lasttime_start ) {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );

		return $this->wpdb->get_results( 'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid WHERE ( wp_sync.dtsAutomaticSync = 0 OR wp_sync.dtsAutomaticSyncStart = 0 OR wp_sync.dtsAutomaticSyncStart < ' . intval( $lasttime_start ) . ' ) ' . $where . ' ORDER BY wp_sync.dtsAutomaticSyncStart ASC LIMIT ' . $limit, OBJECT );
	}

	/**
	 * Get website update stats via SQL.
	 *
	 * @return object|null Database query result of null on failure.
	 */
	public function get_websites_stats_update_sql() {
		$where = $this->get_sql_where_allow_access_sites( 'wp' );
		return 'SELECT wp.*,wp_sync.sync_errors FROM ' . $this->table_name( 'wp' ) . ' wp  JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid WHERE (wp.statsUpdate = 0 OR ' . time() . ' - wp.statsUpdate >= ' . ( 60 * 60 * 24 ) . ')' . $where . ' ORDER BY wp.statsUpdate ASC';
	}

	/**
	 * Update child site statistics.
	 *
	 * Update whether or not a child site has been updated.
	 *
	 * @param mixed $websiteid Child site ID.
	 * @param mixed $statsUpdated Child site Update status.
	 *
	 * @return (int|boolean) Number of rows effected in update or false on failure.
	 */
	public function update_website_stats( $websiteid, $statsUpdated ) {
		return $this->wpdb->update(
			$this->table_name( 'wp' ),
			array( 'statsUpdate' => $statsUpdated ),
			array( 'id' => $websiteid )
		);
	}

	/**
	 * Get child site by url.
	 *
	 * @param string $url Child site URL.
	 *
	 * @return object|null Database query result or null on failure.
	 */
	public function get_websites_by_url( $url ) {
		if ( '/' !== substr( $url, - 1 ) ) {
			$url .= '/';
		}
		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' WHERE url = %s ', $this->escape( $url ) ), OBJECT );
		if ( $results ) {
			return $results;
		}

		if ( stristr( $url, '/www.' ) ) {
			// remove www if it's there!
			$url = str_replace( '/www.', '/', $url );
		} else {
			// add www if it's not there!
			$url = str_replace( 'https://', 'https://www.', $url );
			$url = str_replace( 'http://', 'http://www.', $url );
		}

		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' WHERE url = %s ', $this->escape( $url ) ), OBJECT );
		if ( $results ) {
			return $results;
		}

		$url = str_replace( array( 'https://www.', 'http://www.', 'https://', 'http://', 'www.' ), array( '', '', '', '', '' ), $url );

		return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . " WHERE  replace(replace(replace(replace(replace(url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www.', '')  = %s ", $this->escape( $url ) ), OBJECT );
	}

	/**
	 * Get websites offline status.
	 *
	 * @return array Child site monitoring status.
	 */
	public function get_websites_offline_status_to_send_notice() {
		$where      = $this->get_sql_where_allow_access_sites( 'wp' );
		$extra_view = array( 'monitoring_notification_emails', 'settings_notification_emails' );

		return $this->wpdb->get_results(
			'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp 
			JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
			JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
			WHERE wp.disable_status_check <> 1 AND wp.offline_check_result <> 1 AND wp.offline_check_result <> 0 AND wp.http_code_noticed = 0' . // http_code_noticed = 0: not noticed yet.
			$where,
			OBJECT
		);
	}

	/**
	 * Method get_websites_to_notice_health_threshold()
	 *
	 * Get websites to notice site health.
	 *
	 * @param int $globalThreshold Global site health threshold.
	 * @param int $count Limit count.
	 */
	public function get_websites_to_notice_health_threshold( $globalThreshold, $count = 10 ) {

		$where      = $this->get_sql_where_allow_access_sites( 'wp' );
		$extra_view = array( 'monitoring_notification_emails', 'settings_notification_emails' );

		if ( 80 >= $globalThreshold ) { // actual is 80.
			// should-be-improved site health.
			$where_global_threshold = '( wp.health_threshold = 0 AND wp_sync.health_value < 80 )';
		} else {
			// good site health.
			$where_global_threshold = '( wp.health_threshold = 0 AND wp_sync.health_value >= 80 )';
		}

		$where_site_threshold  = ' ( wp.health_threshold = 80 AND wp_sync.health_value < 80 ) '; // should-be-improved site health.
		$where_site_threshold .= ' OR ( wp.health_threshold = 100 AND wp_sync.health_value >= 80 ) '; // good site health.

		return $this->wpdb->get_results(
			'SELECT wp.*,wp_sync.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp 
			JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
			JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid			
			WHERE wp.disable_health_check <> 1 AND wp.offline_check_result = 1 AND ( ' . $where_global_threshold . ' OR' . $where_site_threshold . ' ) AND wp_sync.health_site_noticed = 0 ' .
			$where,
			OBJECT
		);
	}

	/**
	 * Get websites offline status.
	 *
	 * @return array Sites with offline status.
	 */
	public function get_websites_offline_check_status() {
		$where      = $this->get_sql_where_allow_access_sites( 'wp' );
		$extra_view = array( 'settings_notification_emails' );

		return $this->wpdb->get_results(
			'SELECT wp.*,wp_optionview.* FROM ' . $this->table_name( 'wp' ) . ' wp 
			JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
			WHERE wp.disable_status_check <> 1 AND wp.offline_check_result = -1' . // offline checked status.
			$where,
			OBJECT
		);
	}

	/**
	 * Get DB Sites.
	 *
	 * @since 4.6
	 *
	 * @param mixed $params params.
	 *
	 * @return array $dbwebsites.
	 */
	public static function get_db_sites( $params = array() ) {

		$dbwebsites = array();

		$data_fields   = MainWP_System_Utility::get_default_map_site_fields();
		$data_fields[] = 'verify_certificate';
		$data_fields[] = 'client_id';

		$fields  = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		$sites   = isset( $params['sites'] ) && is_array( $params['sites'] ) ? $params['sites'] : array();
		$groups  = isset( $params['groups'] ) && is_array( $params['groups'] ) ? $params['groups'] : array();
		$clients = isset( $params['clients'] ) && is_array( $params['clients'] ) ? $params['clients'] : array();

		if ( is_array( $fields ) ) {
			foreach ( $fields as $field_indx => $field_name ) {

				$get_field = $field_name;
				if ( is_numeric( $get_field ) || is_bool( $get_field ) ) { // to compatible fix.
					$get_field = $field_indx;
				}

				if ( in_array( $get_field, self::$possible_options ) ) {
					if ( ! in_array( $get_field, $data_fields ) ) {
						$data_fields[] = $get_field;
					}
				}
			}
		}

		if ( ! empty( $sites ) ) {
			foreach ( $sites as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$website = self::instance()->get_website_by_id( $v );
					if ( empty( $website ) ) {
						continue;
					}
					$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
				}
			}
		}

		if ( ! empty( $groups ) ) {
			foreach ( $groups as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$websites = self::instance()->query( self::instance()->get_sql_websites_by_group_id( $v ) );
					while ( $websites && ( $website = self::fetch_object( $websites ) ) ) {
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
					}
					self::free_result( $websites );
				}
			}
		}

		$params       = array(
			'full_data' => true,
		);
		$client_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $clients, $params );
		if ( $client_sites ) {
			foreach ( $client_sites as $website ) {
				$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
			}
		}
		return $dbwebsites;
	}

	/**
	 * Get Sites.
	 *
	 * @param int   $websiteid The id of the child site you wish to retrieve.
	 * @param bool  $for_manager Check Team Control.
	 * @param array $others Array of others.
	 *
	 * @return array $output Array of content to output.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
	 */
	public function get_sites( $websiteid = null, $for_manager = false, $others = array() ) { // phpcs:ignore -- not quite complex function.

		if ( ! is_array( $others ) ) {
			$others = array();
		}

		$search_site = null;
		$orderBy     = 'wp.url';
		$offset      = false;
		$rowcount    = false;
		$extraWhere  = null;

		if ( isset( $websiteid ) && ( null !== $websiteid ) ) {
			$website = self::instance()->get_website_by_id( $websiteid );

			if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
				return false;
			}

			if ( ! mainwp_current_user_have_right( 'site', $websiteid ) ) {
				return false;
			}

			return array(
				array(
					'id'          => $websiteid,
					'url'         => MainWP_Utility::get_nice_url( $website->url, true ),
					'name'        => $website->name,
					'totalsize'   => $website->totalsize,
					'sync_errors' => $website->sync_errors,
				),
			);
		} else {
			if ( isset( $others['orderby'] ) ) {
				if ( ( 'site' === $others['orderby'] ) ) {
					$orderBy = 'wp.name ' . ( 'asc' === $others['order'] ? 'asc' : 'desc' );
				} elseif ( ( 'url' === $others['orderby'] ) ) {
					$orderBy = 'wp.url ' . ( 'asc' === $others['order'] ? 'asc' : 'desc' );
				}
			}
			if ( isset( $others['search'] ) ) {
				$search_site = trim( $others['search'] );
			}

			if ( is_array( $others ) ) {
				if ( isset( $others['plugins_slug'] ) ) {

					$slugs      = explode( ',', $others['plugins_slug'] );
					$extraWhere = '';
					foreach ( $slugs as $slug ) {
						$slug        = wp_json_encode( $slug );
						$slug        = trim( $slug, '"' );
						$slug        = str_replace( '\\', '.', $slug );
						$extraWhere .= ' wp.plugins REGEXP "' . $slug . '" OR';
					}
					$extraWhere = trim( rtrim( $extraWhere, 'OR' ) );

					if ( '' === $extraWhere ) {
						$extraWhere = null;
					} else {
						$extraWhere = '(' . $extraWhere . ')';
					}
				}
			}
		}

		$totalRecords = '';

		if ( isset( $others['per_page'] ) && ! empty( $others['per_page'] ) ) {
			$sql            = self::instance()->get_sql_websites_for_current_user( false, $search_site, $orderBy, false, false, $extraWhere, $for_manager );
			$websites_total = self::instance()->query( $sql );
			$totalRecords   = ( $websites_total ? self::num_rows( $websites_total ) : 0 );

			if ( $websites_total ) {
				self::free_result( $websites_total );
			}

			$rowcount = absint( $others['per_page'] );
			$pagenum  = isset( $others['paged'] ) ? absint( $others['paged'] ) : 0;
			if ( $pagenum > $totalRecords ) {
				$pagenum = $totalRecords;
			}
			$pagenum = max( 1, $pagenum );
			$offset  = ( $pagenum - 1 ) * $rowcount;

		}

		$sql      = self::instance()->get_sql_websites_for_current_user( false, $search_site, $orderBy, $offset, $rowcount, $extraWhere, $for_manager );
		$websites = self::instance()->query( $sql );

		$output = array();
		while ( $websites && ( $website = self::fetch_object( $websites ) ) ) {
			$re = array(
				'id'          => $website->id,
				'url'         => MainWP_Utility::get_nice_url( $website->url, true ),
				'name'        => $website->name,
				'totalsize'   => $website->totalsize,
				'sync_errors' => $website->sync_errors,
				'client_id'   => $website->client_id,
			);

			if ( 0 < $totalRecords ) {
				$re['totalRecords'] = $totalRecords;
				$totalRecords       = 0;
			}

			$output[] = $re;
		}
		self::free_result( $websites );

		return $output;
	}
}
