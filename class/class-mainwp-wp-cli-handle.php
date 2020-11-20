<?php
/**
 * MainWP-CLI
 *
 * This file extends the WP-CLI and provides a set of SubCommands to Control your
 * Child Sites that are added to the MainWP Dashboard.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if access directly.
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Class MainWP_WP_CLI_Handle
 *
 * Manage all child sites added to the MainWP Dashboard via WP CLI.
 *
 * @package MainWP\Dashboard
 */
class MainWP_WP_CLI_Handle extends \WP_CLI_Command {

	// phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

	/**
	 * Singleton.
	 *
	 * @var null $instance
	 */
	private static $instance = null;

	/**
	 * MainWP WP CLI Handle Instance.
	 *
	 * @return self $instance
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return available MainWP WP CLI Commands.
	 *
	 * @param string $comm MainWP WP CLI Command.
	 *
	 * @return array $cli_commands CLI Commands.
	 */
	public static function get_assoc_handle_commands( $comm ) {
		$cli_commands = array(
			'sites'   => array(
				'all-sites',
				'all-sites-count',
				'connected-sites',
				'connected-sites-count',
				'disconnected-sites',
				'disconnected-sites-count',
				'sync-sites',
				'check-sites',
				'disconnect-sites',
			),
			'site'    => array(
				'site',
				'site-info',
				'site-installed-plugins',
				'site-installed-plugins-count',
				'site-active-plugins',
				'site-active-plugins-count',
				'site-inactive-plugins',
				'site-inactive-plugins-count',
				'site-installed-themes',
				'site-installed-themes-count',
				'site-active-themes',
				'site-inactive-themes',
				'site-inactive-themes-count',
				'site-available-updates',
				'site-available-updates-count',
				'site-abandoned-plugins',
				'site-abandoned-plugins-count',
				'site-abandoned-themes',
				'site-abandoned-themes-count',
				'site-http-status',
				'site-health-score',
				'site-security-issues',
				'add-site',
				'edit-site',
				'sync-site',
				'reconnect-site',
				'disconnect-site',
				'remove-site',
				'site-update-wordpress',
				'site-update-plugins',
				'site-update-themes',
				'site-update-translations',
				'site-update-item',
				'site-manage-plugin',
				'site-manage-theme',
				'check-site-http-status',
			),
			'updates' => array(
				'available-updates',
				'ignored-plugins-updates',
				'site-ignored-plugins-updates',
				'ignored-themes-updates',
				'site-ignored-themes-updates',
				'ignore-updates',
				'ignore-update',
				'unignore-updates',
				'unignore-update',
			),
		);
		return isset( $cli_commands[ $comm ] ) ? $cli_commands[ $comm ] : array();
	}

	/**
	 * Gets associated agruments for the command.
	 *
	 * @param string $cli_com    MainWP WP CLI command.
	 * @param array  $assoc_args Associated arguments for the command.
	 *
	 * @return string $callback Callback method.
	 */
	public static function get_assoc_args_commands( $cli_com, $assoc_args ) {
		$commands = self::get_assoc_handle_commands( $cli_com );
		if ( empty( $commands ) ) {
			return false;
		}
		foreach ( $commands as $comm ) {
			if ( isset( $assoc_args[ $comm ] ) ) {
				$callback = str_replace( '-', '_', $comm );
				return $callback;
			}
		}
		return false;
	}

	/**
	 * Calls correct Callback.
	 *
	 * @param string $cli_com    CLI Command.
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Correct callback.
	 *
	 * @return bool True on success, false or error.
	 */
	public static function handle_cli_callback( $cli_com, $args, $assoc_args ) {
		$callback = self::get_assoc_args_commands( $cli_com, $assoc_args );
		if ( ! empty( $callback ) ) {
			if ( method_exists( self::class, 'callback_' . $cli_com . '_' . $callback ) ) {
				$website = false;

				$requires_site_id = false;

				if ( 'site' == $cli_com && ! isset( $assoc_args['add-site'] ) ) {
					$requires_site_id = true;
				} elseif ( 'updates' == $cli_com && ( isset( $assoc_args['site-ignored-plugins-updates'] ) || isset( $assoc_args['site-ignored-themes-updates'] ) || isset( $assoc_args['ignore-update'] ) || isset( $assoc_args['unignore_update'] ) ) ) {
					$requires_site_id = true;
				}

				if ( $requires_site_id ) {
					$site_id = self::get_cli_params( $args, $assoc_args, 'site_id' );
					if ( empty( $site_id ) ) {
						\WP_CLI::error( 'Empty site id.' );
						return false;
					}
					$website = MainWP_DB::instance()->get_website_by_id( $site_id );
					if ( empty( $website ) ) {
						\WP_CLI::error( 'Site not found.' );
						return false;
					}
				}
				call_user_func_array( array( self::class, 'callback_' . $cli_com . '_' . $callback ), array( $args, $assoc_args, $website ) );
				return true;
			}
		}
		return false;
	}


	/**
	 * Gets parameters.
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param string $what       Targetted action.
	 *
	 * @return array Required data.
	 */
	public static function get_cli_params( $args, $assoc_args, $what ) {
		if ( is_string( $what ) ) {
			if ( 'sites' == $what ) {
				$sites = array();
				if ( 0 < count( $args ) ) {
					$args_exploded = explode( ',', $args[0] );
					foreach ( $args_exploded as $arg ) {
						if ( ! is_numeric( trim( $arg ) ) ) {
							\WP_CLI::error( 'Child site ids should be numeric.' );
						}
						$sites[] = trim( $arg );
					}
				}
				return $sites;
			} elseif ( 'site_id' == $what ) {
				$site_id = $args[0];
				if ( ! is_numeric( trim( $site_id ) ) ) {
					\WP_CLI::error( 'Child site id should be numeric.' );
				}
				return $site_id;
			} elseif ( 'add-site' == $what ) {
				$allow_fields = array(
					'site-url',
					'name',
					'admin',
					'uniqueid',
					'ssl_verify',
					'force_use_ipv4',
					'ssl_version',
					'http_user',
					'http_pass',
					'groupids',
				);

				$data = self::map_assoc_args( $assoc_args, $allow_fields );
				if ( isset( $data['site-url'] ) ) {
					$data['url'] = $data['site-url'];
					unset( $data['site-url'] );
				}
				return $data;
			} elseif ( 'edit-site' == $what ) {
				$allow_fields = array(
					'http_user',
					'http_pass',
					'name',
					'admin',
					'sslversion',
					'uniqueid',
				);
				return self::map_assoc_args( $assoc_args, $allow_fields );
			}
		} elseif ( is_array( $what ) && ! empty( $what ) ) {
			$map_fields = $what;
			return self::map_assoc_args( $assoc_args, $map_fields );
		}

		return false;
	}

	/**
	 * Maps arguments.
	 *
	 * @param array $assoc_args Arguments.
	 * @param array $fields     Fields.
	 *
	 * @return array $data Required data.
	 */
	public static function map_assoc_args( $assoc_args, $fields ) {
		$data = array();
		foreach ( $fields as $field ) {
			if ( isset( $assoc_args[ $field ] ) ) {
				$data[ $field ] = $assoc_args[ $field ];
			} else {
				\WP_CLI::error( 'Missing field: ' . $field );
			}
		}
		return $data;
	}


	/**
	 * Lists all sites.
	 *
	 * Command Example: wp mainwp sites --all-sites.
	 */
	public static function callback_sites_all_sites() {
		// get data.
		$data = MainWP_DB::instance()->get_websites_for_current_user();
		if ( empty( $data ) ) {
			\WP_CLI::line( __( 'No child sites added to your MainWP Dashboard.', 'mainwp' ) );
		} else {
			self::print_sites( $data );
		}
	}


	/**
	 * Returns number of child sites.
	 *
	 * Command Example: wp mainwp sites --all-sites-count.
	 */
	public static function callback_sites_all_sites_count() {
		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
		$count    = MainWP_DB::num_rows( $websites );
		MainWP_DB::free_result( $websites );
		\WP_CLI::line( '' );
		\WP_CLI::line( __( 'Number of child sites: ', 'mainwp' ) . $count );
		\WP_CLI::line( '' );
	}

	/**
	 * Lists all connected child sites.
	 *
	 * Command Example: wp mainwp sites --connected-sites.
	 */
	public static function callback_sites_connected_sites() {
		$data = MainWP_DB::instance()->get_connected_websites();
		if ( empty( $data ) ) {
			\WP_CLI::line( __( 'No connected child sites fount.', 'mainwp' ) );
		} else {
			self::print_sites( $data );
		}
	}

	/**
	 * Returns number of connected sites.
	 *
	 * Command Example: wp mainwp sites --connected-sites-count.
	 */
	public static function callback_sites_connected_sites_count() {
		$websites = MainWP_DB::instance()->get_connected_websites();
		\WP_CLI::line( __( 'Number of connected child sites: ', 'mainwp' ) . count( $websites ) );
	}

	/**
	 * Lists all disconnected child sites.
	 *
	 * Command Example: wp mainwp sites --disconnected-sites.
	 */
	public static function callback_sites_disconnected_sites() {
		$data = MainWP_DB::instance()->get_disconnected_websites();
		if ( empty( $data ) ) {
			\WP_CLI::line( __( 'No disconnected child sites found.', 'mainwp' ) );
		} else {
			self::print_sites( $data );
		}
	}

	/**
	 * Returns number of disconnected child sites.
	 *
	 * Command Example: wp mainwp sites --disconnected-sites-count.
	 */
	public static function callback_sites_disconnected_sites_count() {
		$data = MainWP_DB::instance()->get_disconnected_websites();
		\WP_CLI::line( 'Number of disconnected child sites: ' . count( $data ) );
	}

	/**
	 * Syncs all child sites.
	 *
	 * Command Example: wp mainwp sites --sync-sites.
	 *
	 * @uses handle_sync_sites();
	 */
	public static function callback_sites_sync_sites() {
		self::handle_sync_sites();
	}

	/**
	 * Checks all child sites (HTTP Status).
	 *
	 * Command Example:  wp mainwp sites --check-sites.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Arguments.
	 */
	public static function callback_sites_check_sites( $args, $assoc_args ) {

		$sites = self::get_cli_params( $args, $assoc_args, 'sites' );

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
		\WP_CLI::line( '' );
		\WP_CLI::line( __( 'Check started. Please wait...', 'mainwp' ) );
		\WP_CLI::line( '' );
		$errors = 0;
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites, true ) ) ) {
				continue;
			}
			\WP_CLI::line( '  -> ' . $website->name . ' (' . $website->url . ')' );
			try {
				MainWP_Monitoring_Handler::handle_check_website( $website );
			} catch ( \Exception $e ) {
				\WP_CLI::error( '  Check failed: ' . MainWP_Error_Helper::get_console_error_message( $e ) );
				$errors++;
			}
		}
		MainWP_DB::free_result( $websites );
		if ( $errors > 0 ) {
			\WP_CLI::error( 'Check completed with errors' );
		} else {
			\WP_CLI::success( 'Check completed' );
		}
	}

	/**
	 * Disconnects all child sites.
	 *
	 * Command Example: wp mainwp sites --disconnect-sites.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Arguments.
	 */
	public static function callback_sites_disconnect_sites( $args = array(), $assoc_args = false ) {

		$sites = self::get_cli_params( $args, $assoc_args, 'sites' );

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
		\WP_CLI::line( 'Disconnect started' );
		$errors = 0;
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites, true ) ) ) {
				continue;
			}
			\WP_CLI::line( '  -> ' . $website->name . ' (' . $website->url . ')' );
			try {
				MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
			} catch ( \Exception $e ) {
				\WP_CLI::error( '  Disconnect failed: ' . MainWP_Error_Helper::get_console_error_message( $e ) );
				$errors++;
			}
		}
		MainWP_DB::free_result( $websites );
		if ( $errors > 0 ) {
			\WP_CLI::error( 'Disconnect completed with errors' );
		} else {
			\WP_CLI::success( 'Disconnect completed' );
		}
	}

	/**
	 * Lists child site data.
	 *
	 * Command Example: wp mainwp site --site [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site( $args = array(), $assoc_args = array(), $website = false ) {
		\WP_CLI::line( \WP_CLI::colorize( '%gSite Name:%n ' ) . $website->name );
		\WP_CLI::line( \WP_CLI::colorize( '%gSite URL:%n ' ) . $website->url );
		\WP_CLI::line( \WP_CLI::colorize( '%gID:%n ' ) . $website->id );
		\WP_CLI::line( \WP_CLI::colorize( '%gAdmin Username:%n ' ) . $website->adminname );
		\WP_CLI::line( \WP_CLI::colorize( '%gHTTP Response:%n ' ) . $website->http_response_code );
		\WP_CLI::line( \WP_CLI::colorize( '%gNotes:%n ' ) . $website->note );
		\WP_CLI::line( \WP_CLI::colorize( '%gSecurity Issues:%n ' ) . $website->securityIssues );
		\WP_CLI::line( \WP_CLI::colorize( '%gHealth Issues Total:%n ' ) . $website->health_issues_total );
		\WP_CLI::line( \WP_CLI::colorize( '%gHealth Issues:%n ' ) . $website->health_issues );
		\WP_CLI::line( \WP_CLI::colorize( '%gHealth Value:%n ' ) . $website->health_value );
	}

	/**
	 * Shows child site info.
	 *
	 * Command Example: wp mainwp site --site-info [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_info( $args = array(), $assoc_args = array(), $website = false ) {
		$data = json_decode( MainWP_DB::instance()->get_website_option( $website, 'site_info' ), true );

		\WP_CLI::line( \WP_CLI::colorize( '%gSite Name:%n ' ) . $website->name );
		\WP_CLI::line( \WP_CLI::colorize( '%gSite URL:%n ' ) . $website->url );
		\WP_CLI::line( \WP_CLI::colorize( '%gWP Version:%n ' ) . $data['wpversion'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gPHP Version:%n ' ) . $data['phpversion'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gMainWP Child Version:%n ' ) . $data['child_version'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gPHP Memory Limit:%n ' ) . $data['memory_limit'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gMySQL Version:%n ' ) . $data['mysql_version'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gActive Theme:%n ' ) . $data['themeactivated'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gIP Address:%n ' ) . $data['ip'] );
	}

	/**
	 * Lists installed plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-installed-plugins [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_installed_plugins( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins = json_decode( $website->plugins, 1 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Installed Plugins', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $plugins as $plugin ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Name:%n ' ) . $plugin['name'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Slug:%n ' ) . $plugin['slug'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Description:%n ' ) . $plugin['description'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Version:%n ' ) . $plugin['version'] );
			if ( '1' == $plugin['active'] ) {
				\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Status:%n ' ) . __( 'Active', 'mainwp' ) );
			} else {
				\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Status:%n ' ) . __( 'Inactive', 'mainwp' ) );
			}
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Returns the number of installed plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-installed-plugins-count [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_installed_plugins_count( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins = json_decode( $website->plugins, 1 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Installed plugins: ', 'mainwp' ) . '%n' . count( $plugins ) ) );
		\WP_CLI::line( '' );
	}


	/**
	 * Lists all active plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-active-plugins [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_active_plugins( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins = json_decode( $website->plugins, 1 );
		$data    = MainWP_Utility::get_sub_array_having( $plugins, 'active', 1 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Active Plugins', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $data as $plugin ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Name:%n ' ) . $plugin['name'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Slug:%n ' ) . $plugin['slug'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Description:%n ' ) . $plugin['description'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Version:%n ' ) . $plugin['version'] );
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Returns a number of active plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-active-plugins-count [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_active_plugins_count( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins = json_decode( $website->plugins, 1 );
		$data    = MainWP_Utility::get_sub_array_having( $plugins, 'active', 1 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Active plugins: ', 'mainwp' ) . '%n' . count( $data ) ) );
		\WP_CLI::line( '' );
	}

	/**
	 * Lists all inactive plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-inactive-plugins [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_inactive_plugins( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins = json_decode( $website->plugins, 1 );
		$data    = MainWP_Utility::get_sub_array_having( $plugins, 'active', 0 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Inactive Plugins', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $data as $plugin ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Name:%n ' ) . $plugin['name'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Slug:%n ' ) . $plugin['slug'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Description:%n ' ) . $plugin['description'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gPlugin Version:%n ' ) . $plugin['version'] );
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Returns the number of inactive plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-inactive-plugins-count [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_inactive_plugins_count( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins = json_decode( $website->plugins, 1 );
		$data    = MainWP_Utility::get_sub_array_having( $plugins, 'active', 0 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Inctive plugins: ', 'mainwp' ) . '%n' . count( $data ) ) );
		\WP_CLI::line( '' );
	}


	/**
	 * Lists all installed themes on a child site.
	 *
	 * Command Example: wp mainwp site --site-installed-themes [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_installed_themes( $args = array(), $assoc_args = array(), $website = false ) {
		$themes = json_decode( $website->themes, 1 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Installed Themes', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $themes as $theme ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Name:%n ' ) . $theme['name'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Slug:%n ' ) . $theme['slug'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Description:%n ' ) . $theme['description'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Version:%n ' ) . $theme['version'] );
			if ( '1' == $theme['active'] ) {
				\WP_CLI::line( \WP_CLI::colorize( '%gTheme Status:%n ' ) . __( 'Active', 'mainwp' ) );
			} else {
				\WP_CLI::line( \WP_CLI::colorize( '%gTheme Status:%n ' ) . __( 'Inactive', 'mainwp' ) );
			}
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Returns the number of installed themes.
	 *
	 * Command Example: wp mainwp site --site-installed-themes-count [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_installed_themes_count( $args = array(), $assoc_args = array(), $website = false ) {
		$themes = json_decode( $website->themes, 1 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Installed themes: ', 'mainwp' ) . '%n' . count( $themes ) ) );
		\WP_CLI::line( '' );
	}


	/**
	 * Shows the active theme on the child site.
	 *
	 * Command Example: wp mainwp site --site-active-themes [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_active_themes( $args = array(), $assoc_args = array(), $website = false ) {
		$themes = json_decode( $website->themes, 1 );
		$data   = MainWP_Utility::get_sub_array_having( $themes, 'active', 1 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Active Theme', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $data as $theme ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Name:%n ' ) . $theme['name'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Slug:%n ' ) . $theme['slug'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Description:%n ' ) . $theme['description'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Version:%n ' ) . $theme['version'] );
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Lists all inactive themes on a child site.
	 *
	 * Command Example: wp mainwp site --site-inactive-themes [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_inactive_themes( $args = array(), $assoc_args = array(), $website = false ) {
		$themes = json_decode( $website->themes, 1 );
		$data   = MainWP_Utility::get_sub_array_having( $themes, 'active', 0 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Inactive Themes', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $data as $theme ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Name:%n ' ) . $theme['name'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Slug:%n ' ) . $theme['slug'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Description:%n ' ) . $theme['description'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gTheme Version:%n ' ) . $theme['version'] );
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Returns the number of inactive themes.
	 *
	 * Command Example: wp mainwp site --site-inactive-themes-count [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_inactive_themes_count( $args = array(), $assoc_args = array(), $website = false ) {
		$themes = json_decode( $website->themes, 1 );
		$data   = MainWP_Utility::get_sub_array_having( $themes, 'active', 0 );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Inactive themes: ', 'mainwp' ) . '%n' . count( $data ) ) );
		\WP_CLI::line( '' );
	}

	/**
	 * Lists available updates for a child site.
	 *
	 * Command Example: wp mainwp site --site-available-updates [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_available_updates( $args = array(), $assoc_args = array(), $website = false ) {
		$wp_upgrades          = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );
		$plugin_upgrades      = json_decode( $website->plugin_upgrades, true );
		$theme_upgrades       = json_decode( $website->theme_upgrades, true );
		$translation_upgrades = json_decode( $website->translation_upgrades, true );
		$data                 = array(
			'wp_core'     => $wp_upgrades,
			'plugins'     => $plugin_upgrades,
			'themes'      => $theme_upgrades,
			'translation' => $translation_upgrades,
		);

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Available Updates', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		if ( 0 < count( $wp_upgrades ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'WordPress Core', 'mainwp' ) . '%n' ) );
			\WP_CLI::line( '' );

			\WP_CLI::line( \WP_CLI::colorize( '%gDetected:%n ' ) . $wp_upgrades['current'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gLatest:%n ' ) . $wp_upgrades['new'] );
		}

		if ( 0 < count( $plugin_upgrades ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Available Plugin Updates', 'mainwp' ) . '%n' ) );
			\WP_CLI::line( '' );

			foreach ( $plugin_upgrades as $plugin_upgrade ) {
				\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $plugin_upgrade['Name'] );
				\WP_CLI::line( \WP_CLI::colorize( '%gDetected:%n ' ) . $plugin_upgrade['Version'] );
				\WP_CLI::line( \WP_CLI::colorize( '%gLatest:%n ' ) . $plugin_upgrade['update']['new_version'] );
				\WP_CLI::line( '' );
			}
		}

		if ( 0 < count( $theme_upgrades ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Available Theme Updates', 'mainwp' ) . '%n' ) );
			\WP_CLI::line( '' );

			foreach ( $theme_upgrades as $theme_upgrade ) {
				\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $theme_upgrade['Name'] );
				\WP_CLI::line( \WP_CLI::colorize( '%gDetected:%n ' ) . $theme_upgrade['Version'] );
				\WP_CLI::line( \WP_CLI::colorize( '%gLatest:%n ' ) . $theme_upgrade['update']['new_version'] );
				\WP_CLI::line( '' );
			}
		}

		if ( 0 < count( $translation_upgrades ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Available Translation Updates', 'mainwp' ) . '%n' ) );
			\WP_CLI::line( '' );

			foreach ( $translation_upgrades as $translation_upgrade ) {
				\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $translation_upgrade['Name'] );
				\WP_CLI::line( \WP_CLI::colorize( '%gDetected:%n ' ) . $translation_upgrade['Version'] );
				\WP_CLI::line( \WP_CLI::colorize( '%gLatest:%n ' ) . $translation_upgrade['update']['new_version'] );
				\WP_CLI::line( '' );
			}
		}
	}

	/**
	 * Returns the number of available updates for a child site.
	 *
	 * Command Example: wp mainwp site --site-available-updates-count [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_available_updates_count( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins      = json_decode( $website->plugin_upgrades, true );
		$themes       = json_decode( $website->theme_upgrades, true );
		$translations = json_decode( $website->translation_upgrades, true );
		$wp           = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );
		if ( count( $wp ) > 0 ) {
			$wp = 1;
		} else {
			$wp = 0;
		}
		$total = array_merge( $plugins, $themes, $translations );
		$data  = array(
			'total'        => count( $total ) + $wp,
			'wp'           => $wp,
			'plugins'      => count( $plugins ),
			'themes'       => count( $themes ),
			'translations' => count( $translations ),
		);

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Available Updates', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		\WP_CLI::line( \WP_CLI::colorize( '%gWordPress:%n ' ) . $data['wp'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gPlugins:%n ' ) . $data['plugins'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gThemes:%n ' ) . $data['themes'] );
		\WP_CLI::line( \WP_CLI::colorize( '%gTranslations:%n ' ) . $data['translations'] );
		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%gTotal:%n ' ) . $data['total'] );
		\WP_CLI::line( '' );
	}

	/**
	 * Lists all abandoned plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-abandoned-plugins [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_abandoned_plugins( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Abandoned Plugins', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $plugins as $plugin ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $plugin['Name'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gURI:%n ' ) . $plugin['PluginURI'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gVersion:%n ' ) . $plugin['Version'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gLatest Update:%n ' ) . date( 'F j, Y', $plugin['last_updated'] ) ); // phpcs:ignore -- local time.
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Returns the number of abaindoned plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-abandoned-plugins-count [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_abandoned_plugins_count( $args = array(), $assoc_args = array(), $website = false ) {
		$plugins = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . ' ' . __( 'Abandoned plugins: ', 'mainwp' ) . '%n' . count( $plugins ) ) );
		\WP_CLI::line( '' );
	}

	/**
	 * Lists all abandoned themes on a child site.
	 *
	 * Command Example: wp mainwp site --site-abandoned-themes [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_abandoned_themes( $args = array(), $assoc_args = array(), $website = false ) {
		$themes = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Abandoned Themes', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $themes as $theme ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $theme['Name'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gVersion:%n ' ) . $theme['Version'] );
			\WP_CLI::line( \WP_CLI::colorize( '%gLatest Update:%n ' ) . date( 'F j, Y', $theme['last_updated'] ) ); // phpcs:ignore -- local time.
			\WP_CLI::line( '' );
		}
	}

	/**
	 * Returns the number of abandoned themes on a child site.
	 *
	 * Command Example: wp mainwp site --site-abandoned-themes-count [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_abandoned_themes_count( $args = array(), $assoc_args = array(), $website = false ) {
		$themes = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . ' ' . __( 'Abandoned themes: ', 'mainwp' ) . '%n' . count( $themes ) ) );
		\WP_CLI::line( '' );
	}

	/**
	 * Returns child site HTTP status.
	 *
	 * Command Example: wp mainwp site --site-http-status [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_http_status( $args = array(), $assoc_args = array(), $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );
		MainWP_Monitoring_Handler::handle_check_website( $website );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Process ran successfully on ', 'mainwp' ) . $website->name . '%n' ) );
	}

	/**
	 * Returns child site Health score.
	 *
	 * Command Example: wp mainwp site --site-health-score [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_health_score( $args = array(), $assoc_args = array(), $website = false ) {
		$website       = MainWP_DB::instance()->get_website_by_id( $website->id, false, array( 'health_site_status' ) );
		$health_status = isset( $website->health_site_status ) ? json_decode( $website->health_site_status, true ) : array();
		$hstatus       = MainWP_Utility::get_site_health( $health_status );
		$hval          = $hstatus['val'];
		$critical      = $hstatus['critical'];
		if ( 80 <= $hval && 0 == $critical ) {
			$health_score = 'Good';
		} else {
			$health_score = 'Should be improved';
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . ' Health: %n ' ) . $health_score );
		\WP_CLI::line( '' );
	}

	/**
	 * Lists child site security issues.
	 *
	 * Command Example: wp mainwp site --site-security-issues [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_security_issues( $args = array(), $assoc_args = array(), $website = false ) {
		$data = MainWP_Connect::fetch_url_authed( $website, 'security' );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Security Issues', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Directories listing prevented:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['listing'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'WordPress version hidden:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['wp_version'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Really Simple Discovery meta tag removed:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['rsd'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Windows Live Writer meta tag removed:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['wlw'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Database error reporting disabled:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['db_reporting'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'PHP error reporting disabled:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['php_reporting'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Version information removed from URLs:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['versions'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Registered version information removed from URLs:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['registered_versions'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'readme.html removed from WordPress root:', 'mainwp' ) . '%n ' ) . ( 'N' == $data['readme'] ? 'NO' : 'YES' ) );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Administrator username is not "admin":', 'mainwp' ) . '%n ' ) . ( 'N' == $data['admin'] ? 'NO' : 'YES' ) );
	}

	/**
	 * Adds child site.
	 *
	 * Command Example: wp mainwp site --add-site [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_add_site( $args = array(), $assoc_args, $website = false ) {
		$fields = self::get_cli_params( $args, $assoc_args, 'add-site' );
		$data   = MainWP_Manage_Sites_Handler::rest_api_add_site( $fields );
		\WP_CLI::line( '  -> Add site result: ' . print_r( $data, true ) ); // phpcs:ignore -- for cli result. 
	}

	/**
	 * Edits child site.
	 *
	 * Command Example: wp mainwp site --edit-site [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_edit_site( $args = array(), $assoc_args, $website = false ) {
		$fields  = self::get_cli_params( $args, $assoc_args, 'edit-site' );
		$data    = MainWP_DB_Common::instance()->rest_api_update_website( $website->id, $fields );
		$website = MainWP_DB::instance()->get_website_by_id( $website->id );
		\WP_CLI::line( '  -> ' . $website->name . ' (' . $website->url . ')' );
		\WP_CLI::line( '  -> Edit site result: ' . ( $data ? 'successed' : 'failed' ) );
	}

	/**
	 * Syncs child site.
	 *
	 * Command Example: wp mainwp site --sync-site [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_sync_site( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );
		$error = false;
		try {
			MainWP_Sync::sync_site( $website );
		} catch ( \Exception $e ) {
			$error = $e->getMessage();
		}

		if ( empty( $error ) ) {
			\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . __( ' synced successfully.', 'mainwp' ) . '%n' ) );
		} else {
			\WP_CLI::line( \WP_CLI::colorize( '%r' . __( 'Process failed with error:', 'mainwp' ) . '%n' ) );
			\WP_CLI::line( $error );
		}
	}

	/**
	 * Reconnects child site.
	 *
	 * Command Example: wp mainwp site --reconnect-site [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_reconnect_site( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );
		$error = false;
		try {
			MainWP_Manage_Sites_View::m_reconnect_site( $website );
		} catch ( \Exception $e ) {
			$error = $e->getMessage();
		}

		if ( empty( $error ) ) {
			\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . __( ' reconnected successfully.', 'mainwp' ) . '%n' ) );
		} else {
			\WP_CLI::line( \WP_CLI::colorize( '%r' . __( 'Process failed with error:', 'mainwp' ) . '%n' ) );
			\WP_CLI::line( $error );
		}
	}

	/**
	 * Disconnects child site.
	 *
	 * Command Example: wp mainwp site --disconnect-site [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_disconnect_site( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );

		$error = false;
		try {
			MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
		} catch ( \Exception $e ) {
			$error = $e->getMessage();
		}

		if ( empty( $error ) ) {
			\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . __( ' disconnected successfully.', 'mainwp' ) . '%n' ) );
		} else {
			\WP_CLI::line( \WP_CLI::colorize( '%r' . __( 'Process failed with error:', 'mainwp' ) . '%n' ) );
			\WP_CLI::line( $error );
		}
	}

	/**
	 * Removes child site from the MainWP Dashboard.
	 *
	 * Command Example: wp mainwp site --remove-site [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_remove_site( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );
		$data = MainWP_Manage_Sites_Handler::remove_website( $website->id );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'Site removed successfully.', 'mainwp' ) . '%n' ) );
	}

	/**
	 * Updates WP Core on a child site.
	 *
	 * Command Example: wp mainwp site --site-update-wordpress [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_update_wordpress( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );
		$data = MainWP_Updates_Handler::upgrade_website( $website );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . __( ' updated successfully.', 'mainwp' ) . '%n' ) );
	}

	/**
	 * Updates all plugins on a child site.
	 *
	 * Command Example: wp mainwp site --site-update-plugins [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_update_plugins( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );

		$plugin_upgrades = json_decode( $website->plugin_upgrades, true );
		$slugs           = array();
		foreach ( $plugin_upgrades as $slug => $plugin ) {
			$slugs[] = $slug;
		}
		$list = urldecode( implode( ',', $slugs ) );

		\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . __( ' plugins updated successfully.', 'mainwp' ) . '%n' ) );

		MainWP_Connect::fetch_url_authed(
			$website,
			'upgradeplugintheme',
			array(
				'type' => 'plugin',
				'list' => $list,
			)
		);
	}

	/**
	 * Updates all themes on a child site.
	 *
	 * Command Example: wp mainwp site --site-update-themes [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_update_themes( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );
		$theme_upgrades = json_decode( $website->theme_upgrades, true );
		$slugs          = array();
		foreach ( $theme_upgrades as $slug => $theme ) {
			$slugs[] = $slug;
		}
		$list = urldecode( implode( ',', $slugs ) );

		\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . __( ' themes updated successfully.', 'mainwp' ) . '%n' ) );

		MainWP_Connect::fetch_url_authed(
			$website,
			'upgradeplugintheme',
			array(
				'type' => 'theme',
				'list' => $list,
			)
		);
	}

	/**
	 * Updates translations on a child site.
	 *
	 * Command Example: wp mainwp site --site-update-translations [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_update_translations( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );

		$translation_upgrades = json_decode( $website->translation_upgrades, true );
		$slugs                = array();
		foreach ( $translation_upgrades as $translation_upgrade ) {
			$slugs[] = $translation_upgrade['slug'];
		}
		$list = urldecode( implode( ',', $slugs ) );

		\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . __( ' translations updated successfully.', 'mainwp' ) . '%n' ) );

		MainWP_Connect::fetch_url_authed(
			$website,
			'upgradetranslation',
			array(
				'type' => 'translation',
				'list' => $list,
			)
		);
	}

	/**
	 * Updates single item on a child site.
	 *
	 * Command Example: wp mainwp site --site-update-item [<websiteid>] --type=[type] --slug=[slug].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_update_item( $args = array(), $assoc_args, $website = false ) {

		$params = self::get_cli_params( $args, $assoc_args, array( 'type', 'slug' ) );

		$type = $params['type'];
		$slug = $params['slug'];

		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );

		$data = MainWP_Connect::fetch_url_authed(
			$website,
			'upgradeplugintheme',
			array(
				'type' => $type,
				'list' => urldecode( $slug ),
			)
		);

		\WP_CLI::line( \WP_CLI::colorize( '%g' . $website->name . __( ' item updated successfully.', 'mainwp' ) . '%n' ) );
	}

	/**
	 * Manages plgins on a child site.
	 *
	 * Command Example: wp mainwp site --site-manage-plugin [<websiteid>] --action=[action] --plugin=[plugin].
	 *
	 * Action: activate|deactivate|delete
	 * Plugin: plugin slugs separated by ||.
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_manage_plugin( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );

		$params = self::get_cli_params( $args, $assoc_args, array( 'plugin', 'action' ) );
		$plugin = $params['plugin'];
		$action = $params['action'];

		\WP_CLI::line( \WP_CLI::colorize( '%g' . $plugin . ' ' . $action . 'd' . __( ' successfully.', 'mainwp' ) . '%n' ) );

		try {
			MainWP_Connect::fetch_url_authed(
				$website,
				'plugin_action',
				array(
					'action' => $action,
					'plugin' => $plugin,
				)
			);
		} catch ( \Exception $e ) {
			// ok.
		}
	}

	/**
	 * Manages themes on a child site.
	 *
	 * Command Example: wp mainwp site --site-manage-theme [<websiteid>] --action=[action] --theme=[theme].
	 *
	 * action: activate|delete.
	 * theme: theme name.
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_site_manage_theme( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( __( 'Please wait... ', 'mainwp' ) );

		$params = self::get_cli_params( $args, $assoc_args, array( 'theme', 'action' ) );
		$theme  = $params['theme'];
		$action = $params['action'];

		\WP_CLI::line( \WP_CLI::colorize( '%g' . $theme . ' ' . $action . 'd' . __( ' successfully.', 'mainwp' ) . '%n' ) );

		try {
			MainWP_Connect::fetch_url_authed(
				$website,
				'theme_action',
				array(
					'action' => $action,
					'theme'  => $theme,
				)
			);
		} catch ( \Exception $e ) {
			// ok.
		}
	}

	/**
	 * Checks child site for HTTP Status.
	 *
	 * Command Example: wp mainwp site --check-site-http-status [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_site_check_site_http_status( $args = array(), $assoc_args, $website = false ) {
		\WP_CLI::line( '' );
		\WP_CLI::line( __( 'Checking ', 'mainwp' ) . $website->name . ' (' . $website->url . '). ' . __( 'Please wait... ', 'mainwp' ) );
		\WP_CLI::line( '' );
		$data = MainWP_Monitoring_Handler::handle_check_website( $website );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . __( 'HTTP Status: ', 'mainwp' ) . '%n' . $data['httpCode'] . ' (' . $data['httpCodeString'] . ')' ) );
		\WP_CLI::line( '' );
	}

	/**
	 * Lists all available update for all sites.
	 *
	 * Command Example: wp mainwp updates --available-updates [<websiteid>].
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Arguments.
	 */
	public static function callback_updates_available_updates( $args = array(), $assoc_args = array() ) {

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Available Updates', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		$all_updates = array();
		$websites    = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			$wp_upgrades          = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );
			$plugin_upgrades      = json_decode( $website->plugin_upgrades, true );
			$theme_upgrades       = json_decode( $website->theme_upgrades, true );
			$translation_upgrades = json_decode( $website->translation_upgrades, true );

			\WP_CLI::line( \WP_CLI::colorize( '%B' . $website->name . ' (' . $website->url . ')%n' ) );
			if ( 0 < count( $wp_upgrades ) ) {
				\WP_CLI::line( '' );
				\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'WordPress Core', 'mainwp' ) . '%n' ) );
				\WP_CLI::line( '' );

				\WP_CLI::line( \WP_CLI::colorize( '%gDetected:%n ' ) . $wp_upgrades['current'] );
				\WP_CLI::line( \WP_CLI::colorize( '%gLatest:%n ' ) . $wp_upgrades['new'] );
			}

			if ( 0 < count( $plugin_upgrades ) ) {
				\WP_CLI::line( '' );
				\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Available Plugin Updates', 'mainwp' ) . '%n' ) );
				\WP_CLI::line( '' );

				foreach ( $plugin_upgrades as $plugin_upgrade ) {
					\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $plugin_upgrade['Name'] );
					\WP_CLI::line( \WP_CLI::colorize( '%gDetected:%n ' ) . $plugin_upgrade['Version'] );
					\WP_CLI::line( \WP_CLI::colorize( '%gLatest:%n ' ) . $plugin_upgrade['update']['new_version'] );
					\WP_CLI::line( '' );
				}
			}

			if ( 0 < count( $theme_upgrades ) ) {
				\WP_CLI::line( '' );
				\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Available Theme Updates', 'mainwp' ) . '%n' ) );
				\WP_CLI::line( '' );

				foreach ( $theme_upgrades as $theme_upgrade ) {
					\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $theme_upgrade['Name'] );
					\WP_CLI::line( \WP_CLI::colorize( '%gDetected:%n ' ) . $theme_upgrade['Version'] );
					\WP_CLI::line( \WP_CLI::colorize( '%gLatest:%n ' ) . $theme_upgrade['update']['new_version'] );
					\WP_CLI::line( '' );
				}
			}

			if ( 0 < count( $translation_upgrades ) ) {
				\WP_CLI::line( '' );
				\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Available Translation Updates', 'mainwp' ) . '%n' ) );
				\WP_CLI::line( '' );

				foreach ( $translation_upgrades as $translation_upgrade ) {
					\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $translation_upgrade['Name'] );
					\WP_CLI::line( \WP_CLI::colorize( '%gDetected:%n ' ) . $translation_upgrade['Version'] );
					\WP_CLI::line( \WP_CLI::colorize( '%gLatest:%n ' ) . $translation_upgrade['update']['new_version'] );
					\WP_CLI::line( '' );
				}
			}
		}
		MainWP_DB::free_result( $websites );
	}

	/**
	 * Lists all ignored plugins for a child site.
	 *
	 * Command Example: wp mainwp updates --ignored-plugins-updates [<websiteid>].
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Arguments.
	 */
	public static function callback_updates_ignored_plugins_updates( $args = array(), $assoc_args = array() ) {
		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		$data          = json_decode( $userExtension->ignored_plugins, true );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Ignored Updates', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $data as $plugin => $value ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $value );
		}

		\WP_CLI::line( '' );
	}

	/**
	 * Lists all per site ignored plugin updates for a child site.
	 *
	 * Command Example: wp mainwp updates --site-ignored-plugins-updates [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_updates_site_ignored_plugins_updates( $args = array(), $assoc_args, $website = false ) {
		$data = json_decode( $website->ignored_plugins, true );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Ignored Updates', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $data as $plugin => $value ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $value );
		}

		\WP_CLI::line( '' );
	}

	/**
	 * Lists all ignored theme updates for a child site.
	 *
	 * Command Example: wp mainwp updates --ignored-themes-updates [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_updates_ignored_themes_updates( $args = array(), $assoc_args, $website = false ) {
		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		$data          = json_decode( $userExtension->ignored_themes, true );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Ignored Updates', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $data as $theme => $value ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $value );
		}

		\WP_CLI::line( '' );
	}

	/**
	 * Lists all per site ignored theme updates for a child site.
	 *
	 * Command Example: wp mainwp updates --site-ignored-themes-updates [<websiteid>].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_updates_site_ignored_themes_updates( $args = array(), $assoc_args, $website = false ) {

		$data = json_decode( $website->ignored_themes, true );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' ' . __( 'Ignored Updates', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		foreach ( $data as $theme => $value ) {
			\WP_CLI::line( \WP_CLI::colorize( '%gName:%n ' ) . $value );
		}

		\WP_CLI::line( '' );
	}

	/**
	 * Ignores an update globally.
	 *
	 * Command Example: wp mainwp updates --ignore-updates --type=[type] --slug=[slug] --name=[name].
	 *
	 * type: plugin|theme
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_updates_ignore_updates( $args = array(), $assoc_args, $website = false ) {

		$params = self::get_cli_params( $args, $assoc_args, array( 'type', 'slug', 'name' ) );

		$type = $params['type'];
		$slug = $params['slug'];
		$name = $params['name'];

		MainWP_Updates_Handler::ignore_plugins_themes( $type, $slug, $name );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . $name . __( ' ignorred successfully.', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );
	}


	/**
	 * Ignores an update on a child site.
	 *
	 * Command Example: wp mainwp updates --ignore-update  [<websiteid>] --type=[type] --slug=[slug] --name=[name].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_updates_ignore_update( $args = array(), $assoc_args, $website = false ) {

		$params = self::get_cli_params( $args, $assoc_args, array( 'type', 'slug', 'name' ) );
		$type   = $params['type'];
		$slug   = $params['slug'];
		$name   = $params['name'];

		MainWP_Updates_Handler::ignore_plugin_theme( $type, $slug, $name, $website->id );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . $name . __( ' ignorred successfully.', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );
	}

	/**
	 * Unignores an update.
	 *
	 * Command Example: wp mainwp updates --unignore-updates --type=[type] --slug=[slug].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_updates_unignore_updates( $args = array(), $assoc_args, $website = false ) {

		$params = self::get_cli_params( $args, $assoc_args, array( 'type', 'slug' ) );
		$type   = $params['type'];
		$slug   = $params['slug'];

		MainWP_Updates_Handler::unignore_plugins_themes( $type, $slug );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . $slug . __( ' unignorred successfully.', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );
	}


	/**
	 * Unitnores an update on a child site.
	 *
	 * Command Example: wp mainwp updates --unignore-update  [<websiteid>] --type=[type] --slug=[slug].
	 *
	 * @param array  $args       Arguments.
	 * @param array  $assoc_args Arguments.
	 * @param object $website    Object containing child site data.
	 */
	public static function callback_updates_unignore_update( $args = array(), $assoc_args, $website = false ) {

		$params = self::get_cli_params( $args, $assoc_args, array( 'type', 'slug' ) );
		$type   = $params['type'];
		$slug   = $params['slug'];

		MainWP_Updates_Handler::unignore_plugin_theme( $type, $slug, $website->id );

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%g' . $slug . __( ' unignorred successfully.', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );
	}

	/**
	 * Syncs all child sites.
	 *
	 * Command Example: wp mainwp sites --sync-sites.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Arguments.
	 */
	public static function handle_sync_sites( $args = array(), $assoc_args = false ) {

		$sites    = self::get_cli_params( $args, $assoc_args, 'sites' );
		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );

		$warnings = 0;
		$errors   = 0;

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%9' . __( 'Syncing sites. Please wait...', 'mainwp' ) . '%n' ) );
		\WP_CLI::line( '' );

		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites, true ) ) ) {
				continue;
			}

			\WP_CLI::line( \WP_CLI::colorize( '%9' . $website->name . ' (' . $website->url . ')%n' ) );

			try {
				if ( MainWP_Sync::sync_site( $website ) ) {
					\WP_CLI::success( __( 'Sync succeeded', 'mainwp' ) );
				} else {
					\WP_CLI::warning( __( 'Sync failed', 'mainwp' ) );
					$warnings++;
				}
			} catch ( \Exception $e ) {
				\WP_CLI::error( __( 'Sync failed: ', 'mainwp' ) . MainWP_Error_Helper::get_console_error_message( $e ) );
				$errors++;
			}
			\WP_CLI::line( '' );
		}
		MainWP_DB::free_result( $websites );

		if ( $errors > 0 ) {
			\WP_CLI::error( __( 'Sync process completed with errors.', 'mainwp' ) );
		} elseif ( $warnings > 0 ) {
			\WP_CLI::warning( __( 'Sync process completed with warnings.', 'mainwp' ) );
		} else {
			\WP_CLI::success( __( 'Sync process completed successfully.', 'mainwp' ) );
		}
		\WP_CLI::line( '' );
	}


	/**
	 * Prints process success message.
	 */
	public static function print_process_success() {
		\WP_CLI::success( 'Process ran.' );
	}

	/**
	 * Prints child sites list.
	 *
	 * @param array $data Array containing child sites.
	 */
	public static function print_sites( $data ) {
		$idLength   = strlen( 'id' );
		$nameLength = strlen( 'name' );
		$urlLength  = strlen( 'url' );

		foreach ( $data as $site ) {
			if ( $idLength < strlen( $site['id'] ) ) {
				$idLength = strlen( $site['id'] );
			}
			if ( $nameLength < strlen( $site['name'] ) ) {
				$nameLength = strlen( $site['name'] );
			}
			if ( $urlLength < strlen( $site['url'] ) ) {
				$urlLength = strlen( $site['url'] );
			}
		}

		\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $urlLength + 2 ) . 's+', '', '', '', '' ) );
		\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $urlLength . 's |', 'id', 'name', 'url', 'version' ) );
		\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $urlLength + 2 ) . 's+', '', '', '', '' ) );

		foreach ( $data as $site ) {
			\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $urlLength . 's |', $site['id'], $site['name'], $site['url'] ) );
		}

		\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $urlLength + 2 ) . 's+', '', '', '', '' ) );
	}

}
