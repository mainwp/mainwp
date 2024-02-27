<?php
/**
 * MainWP Settings Handle
 *
 * This Class handles building/Managing the
 * Settings MainWP DashboardPage & all SubPages.
 *
 * @package MainWP/Settings
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Settings
 *
 * @package MainWP\Dashboard
 */
class MainWP_Demo_Handle {

	//phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Protected static variable to hold the single instance of the demo website ids.
	 *
	 * @var mixed Default array().
	 */
	protected static $demo_website_ids = array();
	/**
	 * Get Class Name
	 *
	 * @return __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Return the single instance of the class.
	 *
	 * @return mixed $instance The single instance of the class.
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		if ( empty( self::$demo_website_ids ) ) {
			$added_demo = get_option( 'mainwp_demo_mode_added_ids', array() );
			if ( is_array( $added_demo ) && ! empty( $added_demo['sites_ids'] ) ) {
				self::$demo_website_ids = $added_demo['sites_ids'];
			}

			if ( empty( self::$demo_website_ids ) || ! array( self::$demo_website_ids ) ) {
				self::$demo_website_ids = array( -1 ); // for single assign.
			}
		}
		return self::$instance;
	}

	/**
	 * Method init_data_demo()
	 *
	 * Handle init data mode.
	 */
	public function init_data_demo() {
		if ( isset( $_GET['enable_demo_mode'] ) && 'yes' === $_GET['enable_demo_mode'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized.Recommended
			$this->import_data_demo();
			MainWP_Utility::update_option( 'mainwp_enable_guided_tours', 1 );
			?>
			<script type="text/javascript">
				jQuery( document ).ready( function( $ ) {
					setTimeout(function () {
						location.href = 'admin.php?page=mainwp_tab&message=enable-demo-mode';
					}, 100);
				});
			</script>
			<?php
		}
	}

	/**
	 *
	 * Handle detect if it's instawp site.
	 */
	public static function is_instawp_site() {
		$urlparts = wp_parse_url( home_url() );
		$domain   = $urlparts['host'];
		return false !== stripos( $domain, 'instawp.xyz' );
	}

	/**
	 * Method import_data_demo()
	 *
	 * Handle import data demo.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::starts_with()
	 */
	public function import_data_demo() { //phpcs:ignore -- complex method.

		$errors = array();

		$demo_files = array(
			'demo_mainwp_wp',
			'demo_mainwp_wp_clients',
			'demo_mainwp_wp_clients_contacts',
			'demo_mainwp_wp_options',
			'demo_mainwp_wp_actions',
		);

		$wp_data_rows         = array();
		$clients_data_rows    = array();
		$contacts_data_rows   = array();
		$wp_options_data_rows = array();
		$wp_action_data_rows  = array();

		foreach ( $demo_files as $file ) {
			$file_path    = MAINWP_PLUGIN_DIR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'demo' . DIRECTORY_SEPARATOR . 'json' . DIRECTORY_SEPARATOR . $file . '.json';
			$json_content = '';

			if ( file_exists( $file_path ) ) {
				ob_start();
				include $file_path;
				$json_content = ob_get_clean();
			}

			if ( ! empty( $json_content ) ) {
				$json_content = json_decode( $json_content, true );
			}

			if ( ! is_array( $json_content ) || ! isset( $json_content['data'] ) ) {
				continue;
			}

			if ( 'demo_mainwp_wp_clients' === $file ) {
				$clients_data_rows = $json_content['data'];
			} elseif ( 'demo_mainwp_wp' === $file ) {
				$wp_data_rows = $json_content['data'];
			} elseif ( 'demo_mainwp_wp_clients_contacts' === $file ) {
				$contacts_data_rows = $json_content['data'];
			} elseif ( 'demo_mainwp_wp_options' === $file ) {
				$wp_options_data_rows = $json_content['data'];
			} elseif ( 'demo_mainwp_wp_options' === $file ) {
				$wp_options_data_rows = $json_content['data'];
			} elseif ( 'demo_mainwp_wp_actions' === $file ) {
				$wp_action_data_rows = $json_content['data'];
			}
		}

		$count_inserted = 0;
		$index          = 0;
		$demo_added_ids = array(
			'sites_ids'    => array(),
			'clients_ids'  => array(),
			'contacts_ids' => array(),
		);
		if ( is_array( $wp_data_rows ) && ! empty( $wp_data_rows ) ) {
			foreach ( $wp_data_rows as $row ) {
				$websiteid = $this->add_demo_website( $row );
				if ( $websiteid ) {
					++$count_inserted;
					$client_id = 0;
					if ( isset( $clients_data_rows[ $index ] ) ) {
						$client_id = $this->add_demo_client( $clients_data_rows[ $index ], array( $websiteid ) );

						if ( $client_id && isset( $contacts_data_rows[ $index ] ) ) {
							$this->add_demo_contact( $contacts_data_rows[ $index ], $client_id );
						}
					}
					$this->add_demo_wp_option( $wp_options_data_rows, $index, $websiteid );
					$this->add_demo_none_mainwp_action( $wp_action_data_rows, $index, $websiteid );
					// saved import ids to clear.
					$demo_added_ids['sites_ids'][] = $websiteid;
					if ( $client_id ) {
						$demo_added_ids['clients_ids'][] = $client_id;
					}
				}
				++$index;
			}
			$data['count']    = $index;
			$data['inserted'] = $count_inserted;
			update_option( 'mainwp_demo_mode_added_ids', $demo_added_ids );
			MainWP_Utility::update_option( 'mainwp_setting_demo_mode_enabled', 1 );
		}

		if ( ! $index ) {
			$data['error'] = esc_html__( 'Invalid demo data.', 'mainwp' );
		}

		return $data;
	}


	/**
	 * Method add_demo_client()
	 *
	 * Handle to add demo Client.
	 *
	 * @param array $params Array of data.
	 * @param array $selected_sites client sites ids.
	 */
	private function add_demo_client( $params, $selected_sites ) {

		if ( isset( $params['client_id'] ) ) {
			unset( $params['client_id'] );
		}
		$params['primary_contact_id'] = 0;

		if ( empty( $params['created'] ) ) {
			$params['created'] = time();
		}

		$clientid = 0;
		try {
			$inserted = MainWP_DB_Client::instance()->update_client( $params );
			if ( $inserted ) {
				$clientid = $inserted->client_id;
			}
		} catch ( \Exception $e ) {
			$clientid = 0;
		}

		if ( $clientid && $selected_sites ) {
			MainWP_DB_Client::instance()->update_selected_sites_for_client( $clientid, $selected_sites );
		}
		return $clientid;
	}

	/**
	 * Method add_demo_contact()
	 *
	 * Handle to add demo contact.
	 *
	 * @param array $params Array of data.
	 * @param int   $client_id client id.
	 */
	private function add_demo_contact( $params, $client_id ) {
		if ( isset( $params['contact_id'] ) ) {
			unset( $params['contact_id'] );
		}
		$params['contact_client_id'] = $client_id;
		$contact_int                 = MainWP_DB_Client::instance()->update_client_contact( $params );
		if ( $contact_int ) {
			$update = array(
				'client_id'          => $client_id,
				'primary_contact_id' => $contact_int->contact_id,
			);
			MainWP_DB_Client::instance()->update_client( $update );
			return $contact_int->contact_id;
		}
		return false;
	}



	/**
	 * Method add_demo_website()
	 *
	 * Handle add demo website data.
	 *
	 * @param array $params Array of demo Site to add.
	 */
	private function add_demo_website( $params ) {
		global $wpdb;
		$userid = get_current_user_id();

		if ( MainWP_Utility::ctype_digit( $userid ) && is_array( $params ) && isset( $params['url'] ) && ! empty( $params['url'] ) && isset( $params['adminname'] ) && ! empty( $params['adminname'] ) ) {

			$existed = MainWP_DB::instance()->get_websites_by_url( $params['url'] );

			if ( ! empty( $existed ) ) {
				return false;
			}

			if ( isset( $params['id'] ) ) {
				unset( $params['id'] );
			}

			$url = $params['url'];

			if ( '/' !== substr( $url, - 1 ) ) {
				$url .= '/';
			}

			$params['url']       = $url;
			$params['userid']    = $userid;
			$params['adminname'] = $params['adminname'];
			$params['client_id'] = 0; // set: 0.

			$syncValues = array(
				'dtsSync'               => 0,
				'dtsSyncStart'          => 0,
				'dtsAutomaticSync'      => 0,
				'dtsAutomaticSyncStart' => 0,
				'totalsize'             => 0,
				'extauth'               => '',
				'sync_errors'           => '',
			);

			if ( $wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp' ), $params ) ) {
				$websiteid          = $wpdb->insert_id;
				$syncValues['wpid'] = $websiteid;
				$wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp_sync' ), $syncValues );
				$wpdb->insert(
					MainWP_DB::instance()->get_table_name( 'wp_settings_backup' ),
					array(
						'wpid'          => $websiteid,
						'archiveFormat' => 'global',
					)
				);

				$website     = new \stdClass();
				$website->id = $websiteid;

				$added = time();
				MainWP_DB::instance()->update_website_option( $website, 'added_timestamp', $added );

				return $websiteid;
			}
		}

		return false;
	}

	/**
	 * Method add_demo_wp_option()
	 *
	 * Handle add demo website options data.
	 *
	 * @param array $data_rows Array of Site options.
	 * @param index $index index of data.
	 * @param index $websiteid website id.
	 */
	private function add_demo_wp_option( $data_rows, $index, $websiteid ) {
		global $wpdb;
		if ( ! is_array( $data_rows ) || empty( $websiteid ) ) {
			return;
		}

		$opt_names = array(
			'recent_posts',
			'recent_pages',
		);

		foreach ( $opt_names as $opt_name ) {
			if ( isset( $data_rows[ $opt_name ] ) && ! empty( $data_rows[ $opt_name ][ $index ] ) ) {
				$row = $data_rows[ $opt_name ][ $index ];
				if ( isset( $row['opt_id'] ) ) {
					unset( $row['opt_id'] );
					$row['wpid'] = $websiteid;
					$wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp_options' ), $row );
				}
			}
		}
	}

	/**
	 * Method add_demo_none_mainwp_action()
	 *
	 * Handle add demo none mainwp actions.
	 *
	 * @param array $data_rows Array of actions.
	 * @param index $index index of data.
	 * @param index $websiteid website id.
	 */
	private function add_demo_none_mainwp_action( $data_rows, $index, $websiteid ) {
		global $wpdb;
		if ( ! is_array( $data_rows ) || empty( $websiteid ) ) {
			return;
		}
		foreach ( $data_rows as $row ) {
			if ( isset( $row['action_id'] ) && isset( $row['wpid'] ) && (int) $index === (int) $row['wpid'] ) {
				unset( $row['action_id'] );
				$row['wpid'] = $websiteid;
				$wpdb->insert( MainWP_DB::instance()->get_table_name( 'wp_actions' ), $row );
			}
		}
	}

	/**
	 * Method delete_data_demo()
	 *
	 * Handle delete demo  data .
	 */
	public function delete_data_demo() {
		global $wpdb;
		$demo_data_ids = get_option( 'mainwp_demo_mode_added_ids' );

		if ( is_array( $demo_data_ids ) ) {
			if ( ! empty( $demo_data_ids['sites_ids'] ) ) {
				foreach ( $demo_data_ids['sites_ids'] as $itemid ) {
					MainWP_Manage_Sites_Handler::remove_website( $itemid );
				}
			}
			// to fix issue: change clients of demo site.
			if ( ! empty( $demo_data_ids['clients_ids'] ) ) {
				foreach ( $demo_data_ids['clients_ids'] as $itemid ) {
					MainWP_DB_Client::instance()->delete_client( $itemid );
				}
			}
		}
		MainWP_Utility::update_option( 'mainwp_setting_demo_mode_enabled', 0 );
		$this->clear_session_cached_demo_data();
		return array(
			'success' => 1,
		);
	}

	/**
	 * Method delete_data_demo()
	 *
	 * Handle delete demo  data .
	 */
	public function is_new_instance() {
		global $wpdb;
		$count = $wpdb->get_var( 'SELECT count(id) FROM ' . MainWP_DB::instance()->get_table_name( 'wp' ) ); //phpcs:ignore WordPress.DB.PreparedSQL -- ok.
		if ( $count ) {
			return false;
		}
		$count = $wpdb->get_var( 'SELECT count(client_id) FROM ' . MainWP_DB::instance()->get_table_name( 'wp_clients' ) ); //phpcs:ignore WordPress.DB.PreparedSQL -- ok.
		if ( $count ) {
			return false;
		}

		$extensions_disabled = MainWP_Extensions_Handler::get_extensions_disabled();
		$extensions          = MainWP_Extensions_Handler::get_extensions();
		if ( 0 < count( $extensions ) && $extensions !== $extensions_disabled ) {
			return false;
		}

		return true;
	}

	/**
	 * Method is_demo_website()
	 *
	 * Check if website is demo data.
	 *
	 * @param array $website Site to check.
	 */
	public function is_demo_website( $website ) {
		if ( is_numeric( $website ) ) {
			if ( in_array( $website, self::$demo_website_ids ) ) {
				return true;
			}
		} elseif ( is_object( $website ) && property_exists( $website, 'id' ) && in_array( $website->id, self::$demo_website_ids ) ) {
			return true;
		}
		return false;
	}


	/**
	 * Method get_open_site_demo_url()
	 *
	 * Render open site demo url.
	 *
	 * @param mixed $website Site.
	 */
	public function get_open_site_demo_url( $website ) {
		$open_url = '';
		if ( is_numeric( $website ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $website );
		}
		if ( is_object( $website ) && property_exists( $website, 'url' ) ) {
			$open_url = $website->url . 'wp-admin.html';
		}
		return $open_url;
	}


	/**
	 * Method is_demo_mode()
	 *
	 * Check if mainwp is demo mode.
	 *
	 * @return bool True if demo mode, else False.
	 */
	public static function is_demo_mode() {
		return defined( 'MAINWP_DEMO_MODE' ) && MAINWP_DEMO_MODE;
	}

	/**
	 * Method render_demo_disable_button()
	 *
	 * Handle render disable demo buttons.
	 *
	 * @param string $content html content.
	 * @param bool   $echo_out to echo or return.
	 *
	 * @return mixed Button or echo.
	 */
	public function render_demo_disable_button( $content, $echo_out = true ) {
		$button = '<div style="display:inline-block;" data-tooltip="' . $this->get_demo_tooltip() . '" data-inverted="" data-variation="mini" data-position="top right">' . $content . '</div>';
		if ( $echo_out ) {
			echo $button; //phpcs:ignore WordPress.Security.EscapeOutput 
		}
		return $button;
	}

	/**
	 * Method get_markup_str()
	 *
	 * Get demo markup string.
	 *
	 * @return string markup string.
	 */
	public function get_markup_str() {
		return '::[adminwebsitedemo]';
	}

	/**
	 * Method get_demo_notice()
	 *
	 * Get demo message to notice.
	 */
	public function get_demo_tooltip() {
		return esc_html__( 'This function does not work in the demo mode!', 'mainwp' );
	}

	/**
	 * Method get_demo_notice()
	 *
	 * Get demo message to notice.
	 */
	public function get_demo_notice() {
		return '[Demo Website]';
	}

	/**
	 * Method handle_action_demo()
	 *
	 * Handle  action for demo website.
	 *
	 * @param object $pWebsite The demo site.
	 * @param string $what action.
	 */
	public function handle_action_demo( $pWebsite, $what ) {
		$output = array(
			'error' => $this->get_demo_notice(),
		);
		if ( 'stats' === $what || 'sync_site' === $what ) {
			$update_dm = array(
				'dtsSync'     => time(),
				'sync_errors' => '',
			);
			MainWP_DB::instance()->update_website_sync_values( $pWebsite->id, $update_dm );
			return true;
		} elseif ( 'perform_install' === $what || 'perform_upload' === $what ) {
			wp_die( wp_send_json( $output ) ); //phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $output;
	}

	/**
	 * Method handle_fetch_urls_demo()
	 *
	 * Handle action for demo website.
	 *
	 * @param mixed  $data action.
	 * @param object $website The demo site.
	 * @param mixed  $output output.
	 */
	public function handle_fetch_urls_demo( $data, $website, &$output ) {
		if ( $this->is_demo_website( $website ) ) {
			$output->errors[ $website->id ] = $this->get_demo_notice();
		}
	}

	/**
	 * Method clear_session_cached_demo_data()
	 *
	 * Handle clear session cached demo data.
	 */
	public function clear_session_cached_demo_data() {
		MainWP_Cache::init_session();
		$clear = array(
			'SNThemesAll',
			'SNThemesAllStatus',
			'MainWP_PluginsActiveStatus',
			'MainWP_PluginsActive',
			'MainWPPluginsSearchResult',
			'MainWPPluginsSearchContext',
			'MainWPPluginsSearch',
			'MainWPThemesSearchResult',
			'MainWPThemesSearchContext',
			'MainWPThemesSearch',
			'MainWPUsersSearchResult',
			'MainWPUsersSearchContext',
			'MainWPUsersSearch',
		);
		foreach ( $clear as $cl ) {
			if ( isset( $_SESSION[ $cl ] ) ) {
				unset( $_SESSION[ $cl ] );
			}
		}
	}
}
