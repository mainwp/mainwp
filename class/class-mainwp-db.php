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
	 * Create public static instance.
	 *
	 * @static
	 *
	 * @return MainWP_DB
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		self::$instance->test_connection();

		return self::$instance;
	}

	/**
	 * Get wp_options database table view.
	 *
	 * @param array $fields Extra option fields.
	 * @param bool  $default Whether or not to get default option fields.
	 *
	 * @return array wp_options view.
	 */
	public function get_option_view( $fields = array(), $default = true ) {

		$view = '(SELECT intwp.id AS wpid,';

		if ( empty( $fields ) || $default ) {
			$view .= ' (SELECT recent_comments.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_comments WHERE  recent_comments.wpid = intwp.id AND recent_comments.name = "recent_comments" LIMIT 1) AS recent_comments,
					(SELECT recent_posts.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_posts WHERE  recent_posts.wpid = intwp.id AND recent_posts.name = "recent_posts" LIMIT 1) AS recent_posts,
					(SELECT recent_pages.value FROM ' . $this->table_name( 'wp_options' ) . ' recent_pages WHERE  recent_pages.wpid = intwp.id AND recent_pages.name = "recent_pages" LIMIT 1) AS recent_pages,
					(SELECT phpversion.value FROM ' . $this->table_name( 'wp_options' ) . ' phpversion WHERE  phpversion.wpid = intwp.id AND phpversion.name = "phpversion" LIMIT 1) AS phpversion,
					(SELECT wp_upgrades.value FROM ' . $this->table_name( 'wp_options' ) . ' wp_upgrades WHERE  wp_upgrades.wpid = intwp.id AND wp_upgrades.name = "wp_upgrades" LIMIT 1) AS wp_upgrades ';
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
		if ( ( null == $userId ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userId = $current_user->ID;
		}
		$where = ( null == $userId ? '' : ' wp.userid = ' . $userId );
		if ( ! $all_access ) {
			$where .= $this->get_sql_where_allow_access_sites( 'wp' );
		}
		$qry = 'SELECT COUNT(wp.id) FROM ' . $this->table_name( 'wp' ) . ' wp WHERE 1 ' . $where;

		return $this->wpdb->get_var( $qry );
	}

	/**
	 * Get Child site wp_options database table.
	 *
	 * @param array $website Child Site array.
	 * @param mixed $option  Child Site wp_options table name.
	 *
	 * @return string|null Database query result (as string), or null on failure.
	 */
	public function get_website_option( $website, $option ) {

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
		}

		return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $site_id ) );
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
		}

		$options_name = implode( "','", $options );
		$options_name = "'" . $options_name . "'";

		$options_db = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name, value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name IN (' . $options_name . ')', $site_id ) );

		$fill_options = array(
			'primary_lasttime_backup',
		);

		$arr_options = array();

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
		if ( 0 < count( $rslt ) ) {
			$this->wpdb->delete(
				$this->table_name( 'wp_options' ),
				array(
					'wpid' => $website->id,
					'name' => $this->escape( $option ),
				)
			);
			$rslt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT name FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = "' . $this->escape( $option ) . '"', $website->id ) );
		}

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
				WHERE wp.disable_status_check <> 1 AND ( ( wp.status_check_interval = 0 AND wp.offline_checks_last < ' . intval( $last_check ) . ' )
				OR ( wp.status_check_interval <> 0 AND ( wp.offline_checks_last + wp.status_check_interval * 60 < UNIX_TIMESTAMP() ) ) )' .
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
				$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
                FROM ' . $this->table_name( 'wp' ) . ' wp
                LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.userid = ' . $userid . "
                $where
                GROUP BY wp.id
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
		$is_staging = 'no' ) {

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

		if ( 'wp.url' === $orderBy ) {
			$orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www', '')";
		}

		// wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
		if ( $selectgroups ) {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
            FROM ' . $this->table_name( 'wp' ) . ' wp
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . '
            GROUP BY wp.id
            ORDER BY ' . $orderBy;
		} else {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*
            FROM ' . $this->table_name( 'wp' ) . ' wp
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . '
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
		$extra_view   = isset( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
		$is_staging   = isset( $params['is_staging'] ) ? $params['is_staging'] : 'no';
		$for_manager  = false;

		$data = array( 'id', 'url', 'name' );

		$dbwebsites = array();
		$websites   = self::instance()->query( self::instance()->get_sql_websites_for_current_user( $selectgroups, $search_site, $orderBy, $offset, $rowcount, $extraWhere, $for_manager, $extra_view, $is_staging ) );
		while ( $websites && ( $website = self::fetch_object( $websites ) ) ) {
			$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data );
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

		$selectgroups = isset( $params['selectgroups'] ) && $params['selectgroups'] ? true : false;
		$search_site  = isset( $params['search'] ) ? $this->escape( trim( $params['search'] ) ) : null;
		$orderBy      = isset( $params['orderby'] ) ? $params['orderby'] : 'wp.url';
		$offset       = isset( $params['offset'] ) ? intval( $params['offset'] ) : false;
		$rowcount     = isset( $params['rowcount'] ) ? intval( $params['rowcount'] ) : false;
		$extraWhere   = isset( $params['extra_where'] ) ? $params['extra_where'] : null;
		$for_manager  = isset( $params['for_manager'] ) && $params['for_manager'] ? true : false;
		$extra_view   = isset( $params['extra_view'] ) ? $params['extra_view'] : array( 'favi_icon' );
		$is_staging   = isset( $params['is_staging'] ) && 'yes' == $params['is_staging'] ? 'yes' : 'no';
		$is_count     = isset( $params['count_only'] ) && $params['count_only'] ? true : false;
		$group_ids    = isset( $params['group_id'] ) && ! empty( $params['group_id'] ) ? $params['group_id'] : array();
		$is_not       = isset( $params['isnot'] ) && ! empty( $params['isnot'] ) ? true : false;

		if ( ! is_array( $group_ids ) ) {
			$group_ids = array();
		}

		// valid group ids.
		$group_ids = array_filter(
			$group_ids,
			function( $e ) {
				if ( 'nogroups' == $e ) {
					return true;
				}
				$e = intval( $e );
				return ( 0 < $e ) ? true : false;
			}
		);

		if ( $selectgroups ) {
			$staging_group = get_option( 'mainwp_stagingsites_group_id' );
			if ( $staging_group ) {
				if ( in_array( $staging_group, $group_ids ) ) {
					if ( 0 == count( $group_ids ) ) {
						$is_staging = 'yes';
					} else {
						$is_staging = 'nocheckstaging';
					}
				}
			}
		}

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
			$orderBy = "replace(replace(replace(replace(replace(wp.url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www', '')";
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
				function( $e ) {
					return 'nogroups' != $e;
				}
			);
			if ( 0 < count( $group_ids ) ) {
				$groups = implode( ',', $group_ids );
				if ( $is_not ) {
					$where_group = ' AND wpgroup.groupid IS NOT NULL AND wpgroup.groupid NOT IN (' . $groups . ') ';
				} else {
					$where_group = ' AND ( wpgroup.groupid IS NULL OR wpgroup.groupid IN (' . $groups . ') ) ';
				}
			} else {
				if ( $is_not ) {
					$where_group = ' AND wpgroup.groupid IS NOT NULL ';
				} else {
					$where_group = ' AND wpgroup.groupid IS NULL ';
				}
			}
		} elseif ( $group_ids && 0 < count( $group_ids ) ) {
			$groups     = implode( ',', $group_ids );
			$join_group = ' JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid ';
			if ( $is_not ) {
				$where_group = ' AND wpgroup.groupid NOT IN (' . $groups . ') ';
			} else {
				$where_group = ' AND wpgroup.groupid IN (' . $groups . ') ';
			}
		}

		// wpgroups to fix issue for mysql 8.0, as groups will generate error syntax.
		if ( $selectgroups ) {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
            FROM ' . $this->table_name( 'wp' ) . ' wp ' .
			$join_group . '
            LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
            LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
            WHERE 1 ' . $where . $where_group . '
			GROUP BY wp.id ' .
			$orderBy;
		} else {
			$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*
            FROM ' . $this->table_name( 'wp' ) . ' wp ' .
			$join_group . '
            JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
            JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
			WHERE 1 ' . $where . $where_group .
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
		if ( defined( 'MAINWP_REST_API' ) && MAINWP_REST_API ) {
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
			$_where .= ' AND ' . $site_table_alias . '.id IN (' . implode( ',', $allowed_sites ) . ') ';
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
		if ( defined( 'MAINWP_REST_API' ) && MAINWP_REST_API ) {
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
	 * @param array $fields       Get extra option fields.
	 *
	 * @return object|null Database query results or null on failure.
	 */
	public function get_website_by_id( $id, $selectGroups = false, $fields = array() ) {
		return $this->get_row_result( $this->get_sql_website_by_id( $id, $selectGroups, $fields ) );
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

		if ( empty( $extra_view ) ) {
			$extra_view = array( 'favi_icon' );
		}

		if ( MainWP_Utility::ctype_digit( $id ) ) {
			$where = $this->get_sql_where_allow_access_sites( 'wp', 'nocheckstaging' );
			if ( $selectGroups ) {
				return 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
                FROM ' . $this->table_name( 'wp' ) . ' wp
                LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                JOIN ' . $this->get_option_view( $extra_view ) . ' wp_optionview ON wp.id = wp_optionview.wpid
                WHERE wp.id = ' . $id . $where . '
                GROUP BY wp.id';
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
		if ( ( null == $userId ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userId = $current_user->ID;
		}
		$where = $this->get_sql_where_allow_access_sites();

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' . ( null != $userId ? ' AND userid = ' . $userId : '' ) . $where, OBJECT );
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
		if ( ( null == $userId ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userId = $current_user->ID;
		}

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' wp JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid WHERE wpgroup.groupid IN (' . implode( ',', $ids ) . ') ' . ( null != $userId ? ' AND wp.userid = ' . $userId : '' ), OBJECT );
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
		$search_site = null ) {

		$is_staging = 'no';
		if ( $selectgroups ) {
			$staging_group = get_option( 'mainwp_stagingsites_group_id' );
			if ( $staging_group ) {
				if ( $id == $staging_group ) {
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
				$qry = 'SELECT wp.*,wp_sync.*,wp_optionview.*, GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") as wpgroups
                 FROM ' . $this->table_name( 'wp' ) . ' wp
                 JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                 LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON wp.id = wpgr.wpid
                 LEFT JOIN ' . $this->table_name( 'group' ) . ' gr ON wpgr.groupid = gr.id
                 JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                 JOIN ' . $this->get_option_view() . ' wp_optionview ON wp.id = wp_optionview.wpid
                 WHERE wpgroup.groupid = ' . $id . ' ' .
				( null == $where ? '' : ' AND ' . $where ) . $where_allowed . $where_search . '
                 GROUP BY wp.id
                 ORDER BY ' . $orderBy;
			} else {
				$qry = 'SELECT wp.*,wp_sync.* FROM ' . $this->table_name( 'wp' ) . ' wp
                        JOIN ' . $this->table_name( 'wp_group' ) . ' wpgroup ON wp.id = wpgroup.wpid
                        JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid
                        WHERE wpgroup.groupid = ' . $id . ' ' . $where_allowed . $where_search .
				( null == $where ? '' : ' AND ' . $where ) . ' ORDER BY ' . $orderBy;
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
		if ( ( null == $userid ) && MainWP_System::instance()->is_multi_user() ) {

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
		if ( null != $userid ) {
			$sql .= ' AND g.userid = "' . $userid . '"';
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
	 * @param mixed  $nossl SSL suppoted connection.
	 * @param mixed  $nosslkey SSL not supported connection key.
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
		$nossl,
		$nosslkey,
		$groupids,
		$groupnames,
		$verifyCertificate = 1,
		$uniqueId = '',
		$http_user,
		$http_pass,
		$sslVersion = 0,
		$wpe = 0,
		$isStaging = 0 ) {

		if ( MainWP_Utility::ctype_digit( $userid ) && ( 0 == $nossl || 1 == $nossl ) ) {
			$values = array(
				'userid'                => $userid,
				'adminname'             => $this->escape( $admin ),
				'name'                  => $this->escape( wp_strip_all_tags( $name ) ),
				'url'                   => $this->escape( $url ),
				'pubkey'                => $this->escape( $pubkey ),
				'privkey'               => $this->escape( $privkey ),
				'nossl'                 => $nossl,
				'nosslkey'              => ( null == $nosslkey ? '' : $this->escape( $nosslkey ) ),
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
				'pages'                 => '',
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
		$archiveFormat,
		$uniqueId = '',
		$http_user = null,
		$http_pass = null,
		$sslVersion = 0,
		$disableChecking,
		$checkInterval,
		$disableHealthChecking,
		$healthThreshold,
		$wpe = 0 ) {

		if ( MainWP_Utility::ctype_digit( $websiteid ) && MainWP_Utility::ctype_digit( $userid ) ) {
			$website = self::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				// update admin.
				$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET url="' . $this->escape( $url ) . '", name="' . $this->escape( wp_strip_all_tags( $name ) ) . '", adminname="' . $this->escape( $siteadmin ) . '",pluginDir="' . $this->escape( $pluginDir ) . '",maximumFileDescriptorsOverride = ' . ( $maximumFileDescriptorsOverride ? 1 : 0 ) . ',maximumFileDescriptorsAuto= ' . ( $maximumFileDescriptorsAuto ? 1 : 0 ) . ',maximumFileDescriptors = ' . $maximumFileDescriptors . ', verify_certificate="' . intval( $verifyCertificate ) . '", ssl_version="' . intval( $sslVersion ) . '", wpe="' . intval( $wpe ) . '", uniqueId="' . $this->escape( $uniqueId ) . '", http_user="' . $this->escape( $http_user ) . '", http_pass="' . $this->escape( $http_pass ) . '", disable_status_check="' . $this->escape( $disableChecking ) . '", status_check_interval="' . $this->escape( $checkInterval ) . '", disable_health_check="' . $this->escape( $disableHealthChecking ) . '", health_threshold="' . $this->escape( $healthThreshold ) . '" WHERE id=%d', $websiteid ) );
				$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp_settings_backup' ) . ' SET archiveFormat = "' . $this->escape( $archiveFormat ) . '" WHERE wpid=%d', $websiteid ) );
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
		if ( '/' != substr( $url, - 1 ) ) {
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

		$url = str_replace( array( 'https://www.', 'http://www.', 'https://', 'http://', 'www' ), array( '', '', '', '', '' ), $url );

		return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . " WHERE  replace(replace(replace(replace(replace(url, 'https://www.',''), 'http://www.',''), 'https://', ''), 'http://', ''), 'www', '')  = %s ", $this->escape( $url ) ), OBJECT );

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
}
