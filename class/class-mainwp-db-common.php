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
 * Class MainWP_DB_Common
 *
 * @package MainWP\Dashboard
 */
class MainWP_DB_Common extends MainWP_DB {

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
	 * @return MainWP_DB_Common
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method get_last_sync_status()
	 *
	 * Get last sync status.
	 *
	 * @param string|null $userId User ID.
	 *
	 * @return string $return all_synced|not_synced|last_sync
	 */
	public function get_last_sync_status( $userId = null ) {
		$sql      = $this->get_sql_websites_for_current_user();
		$websites = $this->query( $sql );

		$return = array(
			'sync_status' => false,
			'last_sync'   => 0,
		);

		if ( ! $websites ) {
			$return['sync_status'] = 'all_synced';
			return $return;
		}

		$total_sites  = 0;
		$synced_sites = 0;
		$last_sync    = 0;
		self::data_seek( $websites, 0 );
		while ( $websites && ( $website      = self::fetch_object( $websites ) ) ) {
			if ( empty( $website ) || '' !== $website->sync_errors ) {
				continue;
			}
			++$total_sites;
			if ( 60 * 60 * 24 > time() - $website->dtsSync ) {
				++$synced_sites;
			}
			if ( $last_sync < $website->dtsSync ) {
				$last_sync = $website->dtsSync;
			}
		}

		if ( $total_sites === $synced_sites ) {
			$return['sync_status'] = 'all_synced';
		} elseif ( 0 === $synced_sites ) {
			$return['sync_status'] = 'not_synced';
		}
		$return['last_sync'] = $last_sync;
		return $return;
	}

	/**
	 * Method get_group_by_name()
	 *
	 * Get group by name.
	 *
	 * @param mixed $name Group name.
	 * @param null  $userid user ID.
	 *
	 * @return object|null Database query result for chosen group name or null on failure
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_group_by_name( $name, $userid = null ) {
		if ( ( null === $userid ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userid = $current_user->ID;
		}
		$where  = ( null !== $userid ) ? ' AND userid=' . intval( $userid ) : '';
		$where .= $this->get_sql_where_allow_groups();

		return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'group' ) . ' WHERE 1 ' . $where . ' AND name= %s', $this->escape( $name ) ) );
	}

	/**
	 * Method get_group_by_id()
	 *
	 * Get group by ID.
	 *
	 * @param mixed $id Group ID.
	 *
	 * @return object|null Database query result for chosen Group ID or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function get_group_by_id( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			$group = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'group' ) . ' WHERE id= %d', $id ) );
			return $group;
		}

		return null;
	}

	/**
	 * Method get_groups_for_manage_sites()
	 *
	 * Get groups for mananged sites.
	 *
	 * @return object|null Database query result for Managed Sites Groups or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_groups_for_manage_sites() {
		$where = ' 1 ';
		if ( MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$where = ' userid = ' . $current_user->ID . ' ';
		}
		$with_staging    = 'yes';
		$staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php' );

		if ( ! $staging_enabled ) {
			$with_staging = 'no';
		}

		$where .= $this->get_sql_where_allow_groups( '', $with_staging );

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'group' ) . ' WHERE ' . $where . ' ORDER BY name', OBJECT_K );
	}

	/**
	 * Method get_groups_for_current_user()
	 *
	 * Get groups for current user.
	 *
	 * @return object|null Database query result for Current User Groups or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_groups_for_current_user() {
		$where = ' 1 ';
		if ( MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$where = ' userid = ' . $current_user->ID . ' ';
		}
		$where .= $this->get_sql_where_allow_groups();

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'group' ) . ' WHERE ' . $where . ' ORDER BY name', OBJECT_K );
	}

	/**
	 * Method get_groups_by_website_id()
	 *
	 * Get groups by website ID.
	 *
	 * @param mixed $websiteid Child Site ID.
	 *
	 * @return object|null Database query result for groups by website ID or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function get_groups_by_website_id( $websiteid ) {
		if ( MainWP_Utility::ctype_digit( $websiteid ) ) {
			return $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT * FROM ' . $this->table_name( 'group' ) . ' gr JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON gr.id = wpgr.groupid WHERE wpgr.wpid = %d ORDER BY name',
					$websiteid
				),
				OBJECT_K
			);
		}

		return null;
	}

	/**
	 * Medthod get_groups_and_count()
	 *
	 * Get groups and count.
	 *
	 * @param null $userid      Current user ID.
	 * @param bool $for_manager Default: false.
	 *
	 * @return object|null Database query result for groups and count or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_groups_and_count( $userid = null, $for_manager = false ) {
		if ( ( null === $userid ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userid = $current_user->ID;
		}

		$where = '';

		if ( ! empty( $userid ) ) {
			$where = ' AND gr.userid = ' . intval( $userid );
		}

		if ( ! $for_manager ) {
			$where .= $this->get_sql_where_allow_groups( 'gr' );
		}

		return $this->wpdb->get_results( 'SELECT gr.*, COUNT(DISTINCT(wpgr.wpid)) as nrsites FROM ' . $this->table_name( 'group' ) . ' gr LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON gr.id = wpgr.groupid WHERE 1 ' . $where . ' GROUP BY gr.id ORDER BY gr.name', OBJECT_K );
	}

	/**
	 * Method get_not_empty_groups()
	 *
	 * Get non-empty groups.
	 *
	 * @param null $userid Current user ID.
	 * @param bool $enableOfflineSites Include offline sites? Default: true.
	 *
	 * @return object|null Database query result for non-empty groups or null on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_multi_user()
	 */
	public function get_not_empty_groups( $userid = null, $enableOfflineSites = true ) {
		if ( ( null === $userid ) && MainWP_System::instance()->is_multi_user() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			$userid = $current_user->ID;
		}

		$where  = ' WHERE 1 ';
		$where .= $this->get_sql_where_allow_groups( 'g' );

		if ( null !== $userid ) {
			$where .= ' AND g.userid = ' . intval( $userid );
		}
		if ( ! $enableOfflineSites ) {
			$where .= ' AND wp_sync.sync_errors = ""';
		}

		return $this->wpdb->get_results( 'SELECT DISTINCT(g.id), g.name, count(wp.wpid) FROM ' . $this->table_name( 'group' ) . ' g JOIN ' . $this->table_name( 'wp_group' ) . ' wp ON g.id = wp.groupid JOIN ' . $this->table_name( 'wp' ) . ' wpsite ON wp.wpid = wpsite.id JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.wpid = wp_sync.wpid ' . $where . ' GROUP BY g.id HAVING count(wp.wpid) > 0 ORDER BY g.name', OBJECT_K );
	}

	/**
	 * Method get_sql_log()
	 *
	 * Get sql log.
	 *
	 * @return string sql query.
	 */
	public function get_sql_log() {
		return 'SELECT log.*
                FROM ' . $this->table_name( 'action_log' ) . ' log
                WHERE 1 LIMIT 0, 300 ';
	}

	/**
	 * Method insert_action_log()
	 *
	 * Insert action log.
	 *
	 * @param array $data log data.
	 *
	 * @return void
	 */
	public function insert_action_log( $data ) {
		$this->wpdb->insert( $this->table_name( 'action_log' ), $data );
	}

	/**
	 * Method delete_action_log()
	 *
	 * Delete action log.
	 *
	 * @param int $days number days.
	 *
	 * @return void
	 */
	public function delete_action_log( $days = false ) {
		$where = '';
		if ( ! empty( $days ) ) {
			$where .= ' AND log_timestamp < ' . ( time() - $days * DAY_IN_SECONDS );
		}
		$this->wpdb->query( 'DELETE FROM `' . $this->table_name( 'action_log' ) . '` WHERE 1 ' . $where );
	}

	/**
	 * Method insert_or_update_request_log()
	 *
	 * Insert or update request log.
	 *
	 * @param mixed $wpid WordPress ID.
	 * @param mixed $ip IP address.
	 * @param mixed $start Start time.
	 * @param mixed $stop Stop Time.
	 *
	 * @return void
	 */
	public function insert_or_update_request_log( $wpid, $ip, $start, $stop ) {
		$updateValues = array();
		if ( ! empty( $ip ) ) {
			$updateValues['ip'] = $ip;
		}
		if ( ! empty( $start ) ) {
			$updateValues['micro_timestamp_start'] = $start;
		}
		if ( ! empty( $stop ) ) {
			$updateValues['micro_timestamp_stop'] = $stop;
		}

		$var = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->table_name( 'request_log' ) . ' WHERE wpid = %d ', $wpid ) );
		if ( null !== $var ) {
			$this->wpdb->update( $this->table_name( 'request_log' ), $updateValues, array( 'wpid' => $wpid ) );
		} else {
			$updateValues['wpid'] = $wpid;
			$this->wpdb->insert( $this->table_name( 'request_log' ), $updateValues );
		}
	}

	/**
	 * Method close_open_requests()
	 *
	 * Close open request.
	 *
	 * @return void
	 */
	public function close_open_requests() {
		// Close requests open longer then 7 seconds.. something is wrong here.
		$this->wpdb->query( 'UPDATE ' . $this->table_name( 'request_log' ) . ' SET micro_timestamp_stop = micro_timestamp_start WHERE micro_timestamp_stop < micro_timestamp_start and ' . microtime( true ) . ' - micro_timestamp_start > 7' );
	}

	/**
	 * Method get_nrof_open_requests()
	 *
	 * Get number of requests.
	 *
	 * @param null $ip IP Address.
	 *
	 * @return (string|null) Database query result for number of requests or null on failure.
	 */
	public function get_nrof_open_requests( $ip = null ) {
		if ( null === $ip ) {
			return $this->wpdb->get_var( 'select count(id) from ' . $this->table_name( 'request_log' ) . ' where micro_timestamp_stop < micro_timestamp_start' );
		}

		return $this->wpdb->get_var( 'select count(id) from ' . $this->table_name( 'request_log' ) . ' where micro_timestamp_stop < micro_timestamp_start and ip = "' . esc_sql( $ip ) . '"' );
	}

	/**
	 * Method get_last_request_timestamp()
	 *
	 * Get timestamp of last request sent.
	 *
	 * @param null $ip Child Site IP address, default: null.
	 *
	 * @return (int|null) Database query result for timestamp of last request sent or null on failure.
	 */
	public function get_last_request_timestamp( $ip = null ) {
		if ( null === $ip ) {
			return $this->wpdb->get_var( 'select micro_timestamp_start from ' . $this->table_name( 'request_log' ) . ' order by micro_timestamp_start desc limit 1' );
		}

		return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT micro_timestamp_start FROM ' . $this->table_name( 'request_log' ) . ' WHERE ip = %s order by micro_timestamp_start desc limit 1', esc_sql( $ip ) ) );
	}

	/**
	 * Method update_group_site()
	 *
	 * @param mixed $groupId Group ID.
	 * @param mixed $websiteId Child Site ID.
	 *
	 * @return void
	 */
	public function update_group_site( $groupId, $websiteId ) {
		$this->wpdb->insert(
			$this->table_name( 'wp_group' ),
			array(
				'wpid'    => $websiteId,
				'groupid' => $groupId,
			)
		);
	}

	/**
	 * Method clear_group()
	 *
	 * Clear sites in group.
	 *
	 * @param mixed $groupId ID of group.
	 */
	public function clear_group( $groupId ) {
		$this->wpdb->query( 'DELETE FROM ' . $this->table_name( 'wp_group' ) . ' WHERE groupid=' . $groupId );
	}


	/**
	 * Method add_group()
	 *
	 * Add group.
	 *
	 * @param mixed $userid Current User ID.
	 * @param mixed $name Name of group to add.
	 * @param mixed $color  Color of group to add.
	 *
	 * @return boolean true
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function add_group( $userid, $name, $color = '' ) {
		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			if ( $this->wpdb->insert(
				$this->table_name( 'group' ),
				array(
					'userid' => $userid,
					'name'   => $this->escape( $name ),
					'color'  => $this->escape( $color ),
				)
			)
			) {

				$groupId = $this->wpdb->insert_id;

				$group = $this->get_group_by_id( $groupId );

				/**
				 * Fires after a new sites tag has been created.
				 *
				 * @param object $group group created.
				 * @param string group action.
				 */
				do_action( 'mainwp_site_tag_action', $group, 'created' );

				return $groupId;
			}
		}

		return false;
	}

	/**
	 * Method remove_group()
	 *
	 * Remove group.
	 *
	 * @param mixed $groupid Group ID.
	 *
	 * @return int|boolean Group that was deleted or false on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function remove_group( $groupid ) {
		if ( MainWP_Utility::ctype_digit( $groupid ) ) {
			$nr = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'group' ) . ' WHERE id=%d', $groupid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_group' ) . ' WHERE groupid=%d', $groupid ) );

			return $nr;
		}

		return false;
	}

	/**
	 * Method update_note()
	 *
	 * Update Note.
	 *
	 * @param mixed $websiteid Child Site ID.
	 * @param mixed $note Note data.
	 *
	 * @return void
	 */
	public function update_note( $websiteid, $note ) {
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET note= %s WHERE id=%d', $this->escape( $note ), $websiteid ) );
	}

	/**
	 * Method update_group()
	 *
	 * Update group.
	 *
	 * @param mixed  $groupid Group ID.
	 * @param mixed  $groupname Group Name.
	 * @param string $groupcolor Group Color.
	 *
	 * @return boolean true|false.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function update_group( $groupid, $groupname, $groupcolor ) {
		if ( MainWP_Utility::ctype_digit( $groupid ) ) {
			// update groupname.
			$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'group' ) . ' SET name=%s, color=%s WHERE id=%d', $this->escape( $groupname ), $this->escape( $groupcolor ), $groupid ) );

			return true;
		}

		return false;
	}

	/**
	 * Method get_user_notification_email()
	 *
	 * Get user notification email.
	 *
	 * @param mixed $userid Current user ID.
	 *
	 * @return string $user_email User email address.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
	 */
	public function get_user_notification_email( $userid = 0 ) {
		$theUserId = $userid;
		if ( MainWP_System::instance()->is_single_user() ) {
			$theUserId = 0;
		}
		$user_email = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT user_email FROM ' . $this->table_name( 'users' ) . ' WHERE userid = %d', $theUserId ) );

		if ( null === $user_email || empty( $user_email ) ) {
			$user_email = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT user_email FROM ' . $this->wpdb->prefix . 'users WHERE id = %d', $userid ) );
		}

		return $user_email;
	}

	/**
	 * Method get_user_extension()
	 *
	 * Get user extension.
	 *
	 * @return boolean|int false|get_user_extension_by_user_id()
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
	 */
	public function get_user_extension() {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		if ( empty( $current_user ) ) {
			if ( MainWP_System::instance()->is_single_user() ) {
				$userid = 0;
			} else {
				return false;
			}
		} else {
			$userid = $current_user->ID;
		}

		return $this->get_user_extension_by_user_id( $userid );
	}

	/**
	 * Method get_user_extension_by_user_id()
	 *
	 * Get user extension by user id.
	 *
	 * @param mixed $userid Current user ID.
	 *
	 * @return object $row User extension.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
	 */
	public function get_user_extension_by_user_id( $userid ) {
		if ( MainWP_System::instance()->is_single_user() ) {
			$userid = 0;
		}

		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		if ( null === $row ) {
			$this->create_user_extension( $userid );
			$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		}

		return $row;
	}

	/**
	 * Method create_user_extension()
	 *
	 * Create user extension
	 *
	 * @param mixed $userId Current user ID.
	 *
	 * @return void
	 */
	protected function create_user_extension( $userId ) {
		$fields = array(
			'userid'                => $userId,
			'user_email'            => '',
			'ignored_plugins'       => '',
			'trusted_plugins'       => '',
			'trusted_plugins_notes' => '',
			'ignored_themes'        => '',
			'trusted_themes'        => '',
			'trusted_themes_notes'  => '',
			'pluginDir'             => '',
		);

		$this->wpdb->insert( $this->table_name( 'users' ), $fields );
	}

	/**
	 * Method update_user_extension()
	 *
	 * Update user extension.
	 *
	 * @param mixed $userExtension User extention to update.
	 *
	 * @return object $row User extension.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
	 */
	public function update_user_extension( $userExtension ) {

		if ( is_object( $userExtension ) ) {
			$userid = $userExtension->userid;
		} elseif ( is_array( $userExtension ) ) {
			$userid = $userExtension['userid'];
		} else {
			$userid = null;
		}

		if ( null === $userid ) {
			if ( MainWP_System::instance()->is_single_user() ) {
				$userid = '0';
			} else {

				/**
				 * Current user global.
				 *
				 * @global string
				 */
				global $current_user;

				$userid = $current_user->ID;
			}
		}
		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		if ( null === $row ) {
			$this->create_user_extension( $userid );
		}

		$fields = array();
		foreach ( $userExtension as $field => $value ) {
			if ( $value != $row->$field ) { //phpcs:ignore -- to valid.
				$fields[ $field ] = $value;
			}
		}

		if ( 0 < count( $fields ) ) {
			$this->wpdb->update( $this->table_name( 'users' ), $fields, array( 'userid' => $userid ) );
		}

		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );

		return $row;
	}


	/**
	 * Method rest_api_update_website().
	 *
	 * Rest API update website.
	 *
	 * @param int   $websiteid website ID.
	 * @param array $data Update fields array.
	 * 'http_user'.
	 * 'http_pass'.
	 * 'name'.
	 * 'admin'.
	 * 'sslversion'.
	 * 'uniqueid'.
	 * 'verify'.
	 * 'protocol'.
	 * 'disablechecking'.
	 * 'checkinterval'.
	 * 'disablehealthchecking'.
	 * 'healththreshold'.
	 * 'groupids'.
	 * 'automatic_update'.
	 * 'backup_before_upgrade'.
	 * 'force_use_ipv4'.
	 * 'ignore_core_updates'.
	 * 'ignore_plugin_updates'.
	 * 'ignore_theme_updates'.
	 * 'monitoring_emails'.
	 *
	 * @return bool true|false.
	 */
	public function rest_api_update_website( $websiteid, $data ) { // phpcs:ignore -- complex function.

		$website = MainWP_DB::instance()->get_website_by_id( $websiteid );
		if ( empty( $website ) ) {
			return false;
		}

		$map_fields = array(
			'http_user'   => 'http_user',
			'http_pass'   => 'http_pass',
			'name'        => 'name',
			'adminname'   => 'admin',
			'ssl_version' => 'sslversion',
			'uniqueId'    => 'uniqueid',
		);

		$sql_set = '';

		foreach ( $map_fields as $field => $name ) {
			if ( isset( $data[ $name ] ) && empty( ! $data[ $name ] ) ) {
				$sql_set .= ' `' . $this->escape( $field ) . '` = "' . $this->escape( $data[ $name ] ) . '",';
			}
		}

		if ( isset( $data['verify'] ) ) {
			$verify   = intval( $data['verify'] );
			$sql_set .= ' verify_certificate = "' . $this->escape( $verify ) . '",';
		}

		if ( isset( $data['protocol'] ) && ( 'http' === $data['protocol'] || 'https' === $data['protocol'] ) ) {
			$url      = $data['protocol'] . '://' . MainWP_Utility::remove_http_prefix( $website->url, true );
			$sql_set .= ' url = "' . $this->escape( $url ) . '",';
		}

		if ( isset( $data['disablechecking'] ) ) {
			$sql_set .= ' disable_status_check= "' . ( $data['disablechecking'] ? 1 : 0 ) . '",';
		}

		if ( isset( $data['checkinterval'] ) ) {
			$sql_set .= ' status_check_interval= "' . intval( $data['checkinterval'] ) . '",';
		}

		if ( isset( $data['disablehealthchecking'] ) ) {
			$sql_set .= ' disable_health_check = "' . ( $data['disablehealthchecking'] ? 1 : 0 ) . '",';
		}

		if ( isset( $data['healththreshold'] ) ) {
			$sql_set .= ' health_threshold = "' . intval( $data['healththreshold'] ) . '",';
		}

		if ( isset( $data['suspended'] ) ) {
			$sql_set .= ' suspended = "' . ( 1 === intval( $data['suspended'] ) ? 1 : 0 ) . '",';
		}

		if ( ! empty( $sql_set ) ) {
			$sql_set = rtrim( $sql_set, ',' );
			$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET ' . $sql_set . ' WHERE id=%d', $websiteid ) );
		}

		$groupids = array();
		if ( isset( $data['groupids'] ) && ! empty( $data['groupids'] ) ) {
			$groupids = explode( ',', sanitize_text_field( wp_unslash( $data['groupids'] ) ) );
		}

		if ( ! empty( $groupids ) ) {
			// remove groups.
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_group' ) . ' WHERE wpid=%d', $websiteid ) );

			// update groups.
			foreach ( $groupids as $groupid ) {
				$this->wpdb->insert(
					$this->table_name( 'wp_group' ),
					array(
						'wpid'    => $websiteid,
						'groupid' => $groupid,
					)
				);
			}
		}

		$newValues = array();

		if ( isset( $data['automatic_update'] ) ) {
			$newValues['automatic_update'] = $data['automatic_update'] ? 1 : 0;
		}

		if ( isset( $data['backup_before_upgrade'] ) ) {
			$newValues['backup_before_upgrade'] = $data['backup_before_upgrade'] ? 1 : 0;
		}
		if ( isset( $data['force_use_ipv4'] ) ) {
			$forceuseipv4 = intval( $data['force_use_ipv4'] );
			if ( 2 < $forceuseipv4 ) {
				$forceuseipv4 = 0;
			}
			$newValues['force_use_ipv4'] = $forceuseipv4;
		}

		if ( isset( $data['ignore_core_updates'] ) ) {
			$newValues['is_ignoreCoreUpdates'] = $data['ignore_core_updates'] ? 1 : 0;
		}

		if ( isset( $data['ignore_plugin_updates'] ) ) {
			$newValues['is_ignorePluginUpdates'] = $data['ignore_plugin_updates'] ? 1 : 0;
		}

		if ( isset( $data['ignore_theme_updates'] ) ) {
			$newValues['is_ignoreThemeUpdates'] = $data['ignore_theme_updates'] ? 1 : 0;
		}

		if ( ! empty( $newValues ) ) {
			MainWP_DB::instance()->update_website_values( $website->id, $newValues );
		}

		if ( isset( $data['monitoring_emails'] ) ) {
			$monitoring_emails = MainWP_Utility::valid_input_emails( $data['monitoring_emails'] );
			MainWP_DB::instance()->update_website_option( $website, 'monitoring_notification_emails', $monitoring_emails );
		}

		return array(
			'message' => 'Site updated successfully.',
			'site'    => $website->url,
		);
	}
}
