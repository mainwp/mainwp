<?php
/**
 * MainWP Database Controller
 *
 * This file handles all interactions with the DB.
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB_Tool
 */
class MainWP_DB_Tool extends MainWP_DB {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

	/**
	 * @static
	 * instance of this
	 */
	private static $instance = null;

	/**
	 * @static
	 * @return MainWP_DB_Tool
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// Constructor.
	public function __construct() {
		parent::__construct();
	}

	public function get_last_sync_status( $userId = null ) {
		$sql      = $this->get_sql_websites_for_current_user();
		$websites = $this->query( $sql );

		$return = array(
			'sync_status'    => false,
			'last_sync'      => 0,
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
			$total_sites++;
			if ( 60 * 60 * 24 > time() - $website->dtsSync ) {
				$synced_sites++;
			}
			if ( $last_sync < $website->dtsSync ) {
				$last_sync = $website->dtsSync;
			}
		}

		if ( $total_sites == $synced_sites ) {
			$return['sync_status'] = 'all_synced';
		} elseif ( 0 === $synced_sites ) {
			$return['sync_status'] = 'not_synced';
		}
		$return['last_sync'] = $last_sync;
		return $return;
	}

	public function get_group_by_name( $name, $userid = null ) {
		if ( ( null == $userid ) && MainWP_System::instance()->is_multi_user() ) {
			global $current_user;
			$userid = $current_user->ID;
		}
		$where  = ( null != $userid ) ? ' AND userid=' . $userid : '';
		$where .= $this->get_where_allow_groups();

		return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'group' ) . ' WHERE 1 ' . $where . ' AND name= %s', $this->escape( $name ) ) );
	}

	public function get_group_by_id( $id ) {
		if ( MainWP_Utility::ctype_digit( $id ) ) {
			return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'group' ) . ' WHERE id= %d', $id ) );
		}

		return null;
	}

	public function get_groups_for_manage_sites() {
		$where = ' 1 ';
		if ( MainWP_System::instance()->is_multi_user() ) {
			global $current_user;
			$where = ' userid = ' . $current_user->ID . ' ';
		}
		$with_staging    = 'yes';
		$staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php' );

		if ( ! $staging_enabled ) {
			$with_staging = 'no';
		}

		$where .= $this->get_where_allow_groups( '', $with_staging );

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'group' ) . ' WHERE ' . $where . ' ORDER BY name', OBJECT_K );
	}

	public function get_groups_for_current_user() {
		$where = ' 1 ';
		if ( MainWP_System::instance()->is_multi_user() ) {
			global $current_user;
			$where = ' userid = ' . $current_user->ID . ' ';
		}
		$where .= $this->get_where_allow_groups();

		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'group' ) . ' WHERE ' . $where . ' ORDER BY name', OBJECT_K );
	}

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

	public function get_groups_and_count( $userid = null, $for_manager = false ) {
		if ( ( null == $userid ) && MainWP_System::instance()->is_multi_user() ) {
			global $current_user;
			$userid = $current_user->ID;
		}

		$where = '';

		if ( null != $userid ) {
			$where = ' AND gr.userid = ' . $userid;
		}

		if ( ! $for_manager ) {
			$where .= $this->get_where_allow_groups( 'gr' );
		}

		return $this->wpdb->get_results( 'SELECT gr.*, COUNT(DISTINCT(wpgr.wpid)) as nrsites FROM ' . $this->table_name( 'group' ) . ' gr LEFT JOIN ' . $this->table_name( 'wp_group' ) . ' wpgr ON gr.id = wpgr.groupid WHERE 1 ' . $where . ' GROUP BY gr.id ORDER BY gr.name', OBJECT_K );
	}

	public function get_not_empty_groups( $userid = null, $enableOfflineSites = true ) {
		if ( ( null == $userid ) && MainWP_System::instance()->is_multi_user() ) {
			global $current_user;
			$userid = $current_user->ID;
		}

		$where  = ' WHERE 1 ';
		$where .= $this->get_where_allow_groups( 'g' );

		if ( null != $userid ) {
			$where .= ' AND g.userid = ' . $userid;
		}
		if ( ! $enableOfflineSites ) {
			$where .= ' AND wp_sync.sync_errors = ""';
		}

		return $this->wpdb->get_results( 'SELECT DISTINCT(g.id), g.name, count(wp.wpid) FROM ' . $this->table_name( 'group' ) . ' g JOIN ' . $this->table_name( 'wp_group' ) . ' wp ON g.id = wp.groupid JOIN ' . $this->table_name( 'wp' ) . ' wpsite ON wp.wpid = wpsite.id JOIN ' . $this->table_name( 'wp_sync' ) . ' wp_sync ON wp.wpid = wp_sync.wpid ' . $where . ' GROUP BY g.id HAVING count(wp.wpid) > 0 ORDER BY g.name', OBJECT_K );
	}

	public function get_websites_by_url( $url ) {
		if ( '/' != substr( $url, - 1 ) ) {
			$url .= '/';
		}
		$where   = '';
		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' WHERE url = %s ' . $where, $this->escape( $url ) ), OBJECT );
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

		return $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp' ) . ' WHERE url = %s ' . $where, $this->escape( $url ) ), OBJECT );
	}

	public function insert_or_update_request_log( $wpid, $ip, $start, $stop ) {
		$updateValues = array();
		if ( null != $ip ) {
			$updateValues['ip'] = $ip;
		}
		if ( null != $start ) {
			$updateValues['micro_timestamp_start'] = $start;
		}
		if ( null != $stop ) {
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

	public function close_open_requests() {
		// Close requests open longer then 7 seconds.. something is wrong here.
		$this->wpdb->query( 'UPDATE ' . $this->table_name( 'request_log' ) . ' SET micro_timestamp_stop = micro_timestamp_start WHERE micro_timestamp_stop < micro_timestamp_start and ' . microtime( true ) . ' - micro_timestamp_start > 7' );
	}

	public function get_nrof_open_requests( $ip = null ) {
		if ( null == $ip ) {
			return $this->wpdb->get_var( 'select count(id) from ' . $this->table_name( 'request_log' ) . ' where micro_timestamp_stop < micro_timestamp_start' );
		}

		return $this->wpdb->get_var( 'select count(id) from ' . $this->table_name( 'request_log' ) . ' where micro_timestamp_stop < micro_timestamp_start and ip = "' . esc_sql( $ip ) . '"' );
	}

	public function get_last_request_timestamp( $ip = null ) {
		if ( null == $ip ) {
			return $this->wpdb->get_var( 'select micro_timestamp_start from ' . $this->table_name( 'request_log' ) . ' order by micro_timestamp_start desc limit 1' );
		}

		return $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT micro_timestamp_start FROM ' . $this->table_name( 'request_log' ) . ' WHERE ip = %s order by micro_timestamp_start desc limit 1', esc_sql( $ip ) ) );
	}

	public function update_group_site( $groupId, $websiteId ) {
		$this->wpdb->insert(
			$this->table_name( 'wp_group' ),
			array(
				'wpid'    => $websiteId,
				'groupid' => $groupId,
			)
		);
	}

	public function add_group( $userid, $name ) {
		if ( MainWP_Utility::ctype_digit( $userid ) ) {
			if ( $this->wpdb->insert(
				$this->table_name( 'group' ),
				array(
					'userid' => $userid,
					'name'   => $this->escape( $name ),
				)
			)
			) {
				return $this->wpdb->insert_id;
			}
		}

		return false;
	}

	public function remove_group( $groupid ) {
		if ( MainWP_Utility::ctype_digit( $groupid ) ) {
			$nr = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'group' ) . ' WHERE id=%d', $groupid ) );
			$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_group' ) . ' WHERE groupid=%d', $groupid ) );

			return $nr;
		}

		return false;
	}

	public function update_note( $websiteid, $note ) {
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp' ) . ' SET note= %s, note_lastupdate = %d WHERE id=%d', $this->escape( $note ), time(), $websiteid ) );
	}

	public function update_group( $groupid, $groupname ) {
		if ( MainWP_Utility::ctype_digit( $groupid ) ) {
			// update groupname.
			$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'group' ) . ' SET name=%s WHERE id=%d', $this->escape( $groupname ), $groupid ) );

			return true;
		}

		return false;
	}

	public function get_user_notification_email( $userid ) {
		$theUserId = $userid;
		if ( MainWP_System::instance()->is_single_user() ) {
			$theUserId = 0;
		}
		$user_email = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT user_email FROM ' . $this->table_name( 'users' ) . ' WHERE userid = %d', $theUserId ) );

		if ( null == $user_email || '' == $user_email ) {
			$user_email = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT user_email FROM ' . $this->wpdb->prefix . 'users WHERE id = %d', $userid ) );
		}

		return $user_email;
	}

	public function get_user_extension() {
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

	public function get_user_extension_by_user_id( $userid ) {
		if ( MainWP_System::instance()->is_single_user() ) {
			$userid = 0;
		}

		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		if ( null == $row ) {
			$this->create_user_extension( $userid );
			$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		}

		return $row;
	}

	protected function create_user_extension( $userId ) {
		$fields = array(
			'userid'                 => $userId,
			'user_email'             => '',
			'ignored_plugins'        => '',
			'trusted_plugins'        => '',
			'trusted_plugins_notes'  => '',
			'ignored_themes'         => '',
			'trusted_themes'         => '',
			'trusted_themes_notes'   => '',
			'pluginDir'              => '',
		);

		$this->wpdb->insert( $this->table_name( 'users' ), $fields );
	}

	public function update_user_extension( $userExtension ) {

		if ( is_object( $userExtension ) ) {
			$userid = $userExtension->userid;
		} elseif ( is_array( $userExtension ) ) {
			$userid = $userExtension['userid'];
		} else {
			$userid = null;
		}

		if ( null == $userid ) {
			if ( MainWP_System::instance()->is_single_user() ) {
				$userid = '0';
			} else {
				global $current_user;
				$userid = $current_user->ID;
			}
		}
		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );
		if ( null == $row ) {
			$this->create_user_extension( $userid );
		}

		$fields = array();
		foreach ( $userExtension as $field => $value ) {
			if ( $value != $row->$field ) {
				$fields[ $field ] = $value;
			}
		}

		if ( 0 < count( $fields ) ) {
			$this->wpdb->update( $this->table_name( 'users' ), $fields, array( 'userid' => $userid ) );
		}

		$row = $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_name( 'users' ) . ' WHERE userid= ' . $userid, OBJECT );

		return $row;
	}

}
