<?php
/**
 * MainWP-CLI
 *
 * This file extends the WP-CLI and provides a set of SubCommands to Control your
 * Child Sites that are added to the MainWP Dashboard.
 *
 * @todo: allow to add or remove child sites
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if access directly.
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Class MainWP_WP_CLI_Command
 *
 * Manage all child sites added to the MainWP Dashboard.
 *
 * @package MainWP\Dashboard
 */
class MainWP_WP_CLI_Command extends \WP_CLI_Command {

	/**
	 * Method init()
	 *
	 * Initiate the MainWP CLI after all Plugins have loaded.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( self::class, 'init_wpcli_commands' ), 99999 );
	}

	/**
	 * Method init_wpcli_commands
	 *
	 * Adds the MainWP WP CLI Commands via WP_CLI::add_command
	 */
	public static function init_wpcli_commands() {
		\WP_CLI::add_command( 'mainwp', self::class );
	}

	/**
	 * List information about added child sites.
	 *
	 * ## OPTIONS
	 *
	 * [--list]
	 *  : Get a list of all child sites
	 *
	 * [--count]
	 *  : If set, count child sites.
	 *
	 *
	 * ## EXAMPLES
	 *
	 *     wp mainwp sites --list
	 *     wp mainwp sites --all-sites-count
	 *
	 * ## Synopsis [--list] [--all-sites-count]
	 *
	 * @param array $args Function arguments.
	 * @param array $assoc_args Function associate arguments.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 */
	public function sites( $args, $assoc_args ) {

		// support new mainwp sites cli commands.
		$handle = MainWP_WP_CLI_Handle::get_assoc_args_commands( 'sites', $assoc_args );
		if ( ! empty( $handle ) ) {
			MainWP_WP_CLI_Handle::handle_cli_callback( 'sites', $args, $assoc_args );
			return;
		}

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );

		$idLength      = strlen( 'id' );
		$nameLength    = strlen( 'name' );
		$urlLength     = strlen( 'url' );
		$versionLength = strlen( 'version' );
		while ( $websites && ( $website      = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( $idLength < strlen( $website->id ) ) {
				$idLength = strlen( $website->id );
			}
			if ( $nameLength < strlen( $website->name ) ) {
				$nameLength = strlen( $website->name );
			}
			if ( $urlLength < strlen( $website->url ) ) {
				$urlLength = strlen( $website->url );
			}
			if ( $versionLength < strlen( $website->version ) ) {
				$versionLength = strlen( $website->version );
			}
		}
		MainWP_DB::data_seek( $websites, 0 );

		\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $urlLength + 2 ) . "s+%'--" . ( $versionLength + 2 ) . 's+', '', '', '', '' ) );
		\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $urlLength . 's | %-' . $versionLength . 's |', 'id', 'name', 'url', 'version' ) );
		\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $urlLength + 2 ) . "s+%'--" . ( $versionLength + 2 ) . 's+', '', '', '', '' ) );

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $urlLength . 's | %-' . $versionLength . 's |', $website->id, $website->name, $website->url, $website->version ) );
		}

		\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $urlLength + 2 ) . "s+%'--" . ( $versionLength + 2 ) . 's+', '', '', '', '' ) );
		MainWP_DB::free_result( $websites );
	}

	/**
	 * Site commands.
	 *
	 * ## OPTIONS
	 *
	 * [--site]
	 *  : Get site data
	 *
	 * [--site-info]
	 *  : If set, get site info.
	 *
	 *
	 * ## EXAMPLES
	 *
	 *     wp mainwp site --site [<websiteid>]
	 *     wp mainwp site --site-info [<websiteid>]
	 *
	 * ## Synopsis [--site] [--site-info] [...]
	 *
	 * @param array $args Function arguments.
	 * @param array $assoc_args Function associate arguments.
	 */
	public function site( $args, $assoc_args ) {
		MainWP_WP_CLI_Handle::handle_cli_callback( 'site', $args, $assoc_args );
	}


	/**
	 * Updates commands.
	 *
	 * ## OPTIONS
	 *
	 * [--available-updates <websiteid>]
	 *  : Get available updates
	 *
	 * [--ignored-plugins-updates <websiteid>]
	 *  : If set, get ignored plugins updates.
	 *
	 * [--site-ignored-plugins-updates  <websiteid>]
	 *  : If set, get ignored plugins updates of site.
	 *
	 * [--ignored-themes-updates <websiteid>]
	 *  : If set, get ignored themes updates.
	 *
	 * [--site-ignored-themes-updates <websiteid>]
	 *  : If set, get ignored themes updates of site.
	 *
	 * [--ignore-updates --type=[type] --slug=[slug]]
	 *  : If set, ignored updates.
	 *
	 * [--ignore-update <websiteid> --type=[type] --slug=[slug]]
	 *  : If set, ignored update.
	 *
	 * [--unignore-updates --type=[type] --slug=[slug]]
	 *  : If set, unignore updates.
	 *
	 * [--unignore-update <websiteid> --type=[type] --slug=[slug]]
	 *  : If set, unignore update.
	 *
	 *
	 * ## EXAMPLES
	 *
	 *     wp mainwp updates --available-updates
	 *     wp mainwp updates --ignored-plugins-updates
	 *     wp mainwp updates --site-ignored-plugins-updates 3
	 *
	 * ## Synopsis [--available-updates] [--ignored-plugins-updates] [--site-ignored-plugins-updates] [...]
	 *
	 * @param array $args Function arguments.
	 * @param array $assoc_args Function associate arguments.
	 */
	public function updates( $args, $assoc_args ) {
		MainWP_WP_CLI_Handle::handle_cli_callback( 'updates', $args, $assoc_args );
	}

	/**
	 * Sync Data with Child Sites
	 *
	 * ## OPTIONS
	 *
	 * [<websiteid>]
	 * : The id (or ids, comma separated) of the child sites that need to be synced.
	 *
	 * [--all]
	 * : If set, all child sites will be synced.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mainwp sync 2,5
	 *     wp mainwp sync --all
	 *
	 * ## Synopsis [<websiteid>] [--all]
	 *
	 * @param array $args Function arguments.
	 * @param array $assoc_args Function associate arguments.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_console_error_message()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Sync::sync_site()
	 */
	public function sync( $args, $assoc_args ) {
		$sites = array();
		if ( count( $args ) > 0 ) {
			$args_exploded = explode( ',', $args[0] );
			foreach ( $args_exploded as $arg ) {
				if ( ! is_numeric( trim( $arg ) ) ) {
					\WP_CLI::error( 'Child site ids should be numeric.' );
				}

				$sites[] = trim( $arg );
			}
		}

		if ( is_array( $assoc_args ) && isset( $assoc_args['all'] ) ) {
			update_option( 'mainwp_last_synced_all_sites', time() );
		}

		if ( 0 === count( $sites ) && ( ! isset( $assoc_args['all'] ) ) ) {
			\WP_CLI::error( 'Please specify one or more child sites, or use --all.' );
		}
		MainWP_WP_CLI_Handle::handle_sync_sites( $args, $assoc_args );
	}

	/**
	 * Reconnect with Child Sites
	 *
	 * ## OPTIONS
	 *
	 * [<websiteid>]
	 * : The id (or ids, comma separated) of the child sites that need to be reconnect.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mainwp reconnect 2,5
	 *
	 * ## Synopsis [<websiteid>]
	 *
	 * @param array $args Function arguments.
	 * @param array $assoc_args Function associate arguments.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_console_error_message()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::m_reconnect_site()
	 */
	public function reconnect( $args, $assoc_args ) {
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

		if ( 0 === count( $sites ) ) {
			\WP_CLI::error( 'Please specify one or more child sites.' );
		}

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
		\WP_CLI::line( 'Reconnect started' );
		$warnings = 0;
		$errors   = 0;
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( 0 < count( $sites ) ) && ( ! in_array( $website->id, $sites, true ) ) ) {
				continue;
			}
			\WP_CLI::line( '  -> ' . $website->name . ' (' . $website->url . ')' );
			try {
				if ( MainWP_Manage_Sites_View::m_reconnect_site( $website ) ) {
					\WP_CLI::success( '  Reconnected successfully' );
				} else {
					\WP_CLI::warning( '  Reconnect failed' );
					++$warnings;
				}
			} catch ( \Exception $e ) {
				\WP_CLI::error( '  Reconnect failed: ' . MainWP_Error_Helper::get_console_error_message( $e ) );
				++$errors;
			}
		}
		MainWP_DB::free_result( $websites );
		if ( 0 < $errors ) {
			\WP_CLI::error( 'Reconnect completed with errors' );
		} elseif ( 0 < $warnings ) {
			\WP_CLI::warning( 'Reconnect completed with warnings' );
		} else {
			\WP_CLI::success( 'Reconnect completed' );
		}
	}


	/**
	 * List information about plugin updates
	 *
	 * ## OPTIONS
	 *
	 * [<websiteid>]
	 * : The id (or ids, comma separated) of the child sites that need to be listed/upgraded, when omitted all childsites are used.
	 *
	 * [--list]
	 * : Get a list of plugins with available updates
	 *
	 * [--list-all]
	 * : List all plugins
	 *
	 * [--upgrade=<pluginslug>]
	 * : Update the plugin slugs
	 *
	 * [--upgrade-all]
	 * : Update all plugins
	 *
	 * ## EXAMPLES
	 *
	 *     wp mainwp plugin 2,5 --list
	 *     wp mainwp plugin --list
	 *     wp mainwp plugin 2,5 --list-all
	 *     wp mainwp plugin 2,5 --upgrade-all
	 *     wp mainwp plugin 2,5 --upgrade=mainwpchild
	 *
	 * ## Synopsis [<websiteid>] [--list] [--list-all] [--upgrade=<pluginslug>] [--upgrade-all]
	 *
	 * @param array $args Function arguments.
	 * @param array $assoc_args Function associate arguments.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_console_error_message()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::upgrade_plugin_theme_translation()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::value_to_string()
	 */
	public function plugin( $args, $assoc_args ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$sites = array();
		if ( 0 < count( $args ) ) {
			$args_exploded = explode( ',', $args[0] );
			foreach ( $args_exploded as $arg ) {
				if ( ! is_numeric( trim( $arg ) ) ) {
					\WP_CLI::error( 'Child site IDs should be numeric.' );
				}

				$sites[] = trim( $arg );
			}
		}

		if ( isset( $assoc_args['list'] ) ) {
			$websites            = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
			$userExtension       = MainWP_DB_Common::instance()->get_user_extension();
			$websites_to_upgrade = array();
			while ( $websites && ( $website          = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites, true ) ) ) {
					continue;
				}

				$plugin_upgrades = json_decode( $website->plugin_upgrades, true );

				if ( is_array( $plugin_upgrades ) ) {
					$ignored_plugins = json_decode( $website->ignored_plugins, true );
					if ( is_array( $ignored_plugins ) ) {
						$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
					}

					$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
					if ( is_array( $ignored_plugins ) ) {
						$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
					}

					$tmp = array();
					foreach ( $plugin_upgrades as $plugin_upgrade ) {
						$tmp[] = array(
							'name'        => $plugin_upgrade['update']['slug'],
							'version'     => $plugin_upgrade['Version'],
							'new_version' => $plugin_upgrade['update']['new_version'],
						);
					}
					$websites_to_upgrade[] = array(
						'id'      => $website->id,
						'name'    => $website->name,
						'plugins' => $tmp,
					);
				}
			}

			$idLength         = strlen( 'id' );
			$nameLength       = strlen( 'name' );
			$pluginLength     = strlen( 'plugin' );
			$oldVersionLength = strlen( 'version' );
			$newVersionLength = strlen( 'new version' );
			foreach ( $websites_to_upgrade as $website_to_upgrade ) {
				if ( $idLength < strlen( $website_to_upgrade['id'] ) ) {
					$idLength = strlen( $website_to_upgrade['id'] );
				}
				if ( $nameLength < strlen( $website_to_upgrade['name'] ) ) {
					$nameLength = strlen( $website_to_upgrade['name'] );
				}

				foreach ( $website_to_upgrade['plugins'] as $plugin_to_upgrade ) {
					if ( $pluginLength < strlen( $plugin_to_upgrade['name'] ) ) {
						$pluginLength = strlen( $plugin_to_upgrade['name'] );
					}
					if ( $oldVersionLength < strlen( $plugin_to_upgrade['version'] ) ) {
						$oldVersionLength = strlen( $plugin_to_upgrade['version'] );
					}
					if ( $newVersionLength < strlen( $plugin_to_upgrade['new_version'] ) ) {
						$newVersionLength = strlen( $plugin_to_upgrade['new_version'] );
					}
				}
			}

			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . "s+%'--" . ( $newVersionLength + 2 ) . 's+', '', '', '', '', '' ) );
			\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $pluginLength . 's | %-' . $oldVersionLength . 's | %-' . $newVersionLength . 's |', 'id', 'name', 'plugin', 'version', 'new version' ) );
			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . "s+%'--" . ( $newVersionLength + 2 ) . 's+', '', '', '', '', '' ) );

			foreach ( $websites_to_upgrade as $website_to_upgrade ) {
				if ( $idLength < strlen( $website_to_upgrade['id'] ) ) {
					$idLength = strlen( $website_to_upgrade['id'] );
				}
				if ( $nameLength < strlen( $website_to_upgrade['name'] ) ) {
					$nameLength = strlen( $website_to_upgrade['name'] );
				}

				$i = 0;
				foreach ( $website_to_upgrade['plugins'] as $plugin_to_upgrade ) {
					if ( 0 === $i ) {
						\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $pluginLength . 's | %-' . $oldVersionLength . 's | %-' . $newVersionLength . 's |', $website_to_upgrade['id'], $website_to_upgrade['name'], $plugin_to_upgrade['name'], $plugin_to_upgrade['version'], $plugin_to_upgrade['new_version'] ) );
					} else {
						\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $pluginLength . 's | %-' . $oldVersionLength . 's | %-' . $newVersionLength . 's |', '', '', $plugin_to_upgrade['name'], $plugin_to_upgrade['version'], $plugin_to_upgrade['new_version'] ) );
					}
					++$i;
				}
			}

			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . "s+%'--" . ( $newVersionLength + 2 ) . 's+', '', '', '', '', '' ) );
		} elseif ( isset( $assoc_args['list-all'] ) ) {
			$websites         = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
			$userExtension    = MainWP_DB_Common::instance()->get_user_extension();
			$websites_to_list = array();
			while ( $websites && ( $website          = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( 0 < count( $sites ) ) && ( ! in_array( $website->id, $sites, true ) ) ) {
					continue;
				}

				$plugins_to_list = json_decode( $website->plugins, true );
				if ( is_array( $plugins_to_list ) ) {
					$tmp = array();
					foreach ( $plugins_to_list as $plugin ) {
						$tmp[] = array(
							'name'    => $plugin['slug'],
							'version' => $plugin['version'],
						);
					}
					$websites_to_list[] = array(
						'id'      => $website->id,
						'name'    => $website->name,
						'plugins' => $tmp,
					);
				}
			}

			$idLength         = strlen( 'id' );
			$nameLength       = strlen( 'name' );
			$pluginLength     = strlen( 'plugin' );
			$oldVersionLength = strlen( 'version' );
			foreach ( $websites_to_list as $website_item ) {
				if ( $idLength < strlen( $website_item['id'] ) ) {
					$idLength = strlen( $website_item['id'] );
				}
				if ( $nameLength < strlen( $website_item['name'] ) ) {
					$nameLength = strlen( $website_item['name'] );
				}

				foreach ( $website_item['plugins'] as $plugin_item ) {
					if ( $pluginLength < strlen( $plugin_item['name'] ) ) {
						$pluginLength = strlen( $plugin_item['name'] );
					}
					if ( $oldVersionLength < strlen( $plugin_item['version'] ) ) {
						$oldVersionLength = strlen( $plugin_item['version'] );
					}
				}
			}

			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . 's+', '', '', '', '' ) );
			\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $pluginLength . 's | %-' . $oldVersionLength . 's |', 'id', 'name', 'plugin', 'version' ) );
			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . 's+', '', '', '', '' ) );

			foreach ( $websites_to_list as $website_item ) {
				if ( $idLength < strlen( $website_item['id'] ) ) {
					$idLength = strlen( $website_item['id'] );
				}
				if ( $nameLength < strlen( $website_item['name'] ) ) {
					$nameLength = strlen( $website_item['name'] );
				}

				$i = 0;
				foreach ( $website_item['plugins'] as $plugin_item ) {
					if ( 0 === $i ) {
						\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $pluginLength . 's | %-' . $oldVersionLength . 's |', $website_item['id'], $website_item['name'], $plugin_item['name'], $plugin_item['version'] ) );
					} else {
						\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $pluginLength . 's | %-' . $oldVersionLength . 's |', '', '', $plugin_item['name'], $plugin_item['version'] ) );
					}
					++$i;
				}
			}

			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . 's+', '', '', '', '' ) );
		} elseif ( isset( $assoc_args['upgrade'] ) || isset( $assoc_args['upgrade-all'] ) ) {

			$pluginSlugs = array();
			if ( isset( $assoc_args['upgrade'] ) ) {
				$pluginSlugs = explode( ',', $assoc_args['upgrade'] );
			}

			$websites      = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
			$userExtension = MainWP_DB_Common::instance()->get_user_extension();
			while ( $websites && ( $website      = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites, true ) ) ) {
					continue;
				}

				$plugin_upgrades = json_decode( $website->plugin_upgrades, true );

				if ( is_array( $plugin_upgrades ) ) {
					$ignored_plugins = json_decode( $website->ignored_plugins, true );
					if ( is_array( $ignored_plugins ) ) {
						$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
					}

					$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
					if ( is_array( $ignored_plugins ) ) {
						$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
					}

					$tmp = array();
					foreach ( $plugin_upgrades as $key => $plugin_upgrade ) {
						if ( ( count( $pluginSlugs ) > 0 ) && ( ! in_array( $plugin_upgrade['update']['slug'], $pluginSlugs, true ) ) ) {
							continue;
						}

						$tmp[] = $key;
					}

					if ( 0 === count( $tmp ) ) {
						\WP_CLI::line( 'No available plugin updates for ' . $website->name );

						continue;
					}

					\WP_CLI::line( 'Updating ' . count( $tmp ) . ' plugins for ' . $website->name );

					try {
						MainWP_Updates_Handler::upgrade_plugin_theme_translation( $website->id, 'plugin', implode( ',', $tmp ) );
						\WP_CLI::success( 'Updates completed' );
					} catch ( \Exception $e ) {
						\WP_CLI::error( 'Updates failed: ' . MainWP_Error_Helper::get_console_error_message( $e ) );
						if ( $e->getMesage() === 'WPERROR' ) {
							\WP_CLI::debug( 'Error: ' . MainWP_Utility::value_to_string( $e->get_message_extra(), 1 ) );
						}
					}
				}
			}
		}
	}

	/**
	 * List information about theme updates
	 *
	 * ## OPTIONS
	 *
	 * [<websiteid>]
	 * : The id (or ids, comma separated) of the child sites that need to be listed/upgraded, when omitted all childsites are used.
	 *
	 * [--list]
	 * : Get a list of themes with available updates
	 *
	 * [--list-all]
	 * : list all themes
	 *
	 * [--upgrade=<theme>]
	 * : Update the themes
	 *
	 * [--upgrade-all]
	 * : Update all themes
	 *
	 * ## EXAMPLES
	 *
	 *     wp mainwp theme 2,5 --list
	 *     wp mainwp theme --list
	 *     wp mainwp theme 2,5 --upgrade-all
	 *     wp mainwp theme 2,5 --upgrade=twentysixteen
	 *
	 * ## Synopsis [<websiteid>] [--list] [--list-all] [--upgrade=<theme>] [--upgrade-all]
	 *
	 * @param array $args Function arguments.
	 * @param array $assoc_args Function associate arguments.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_console_error_message()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::upgrade_plugin_theme_translation()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::value_to_string()
	 */
	public function theme( $args, $assoc_args ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$sites = array();
		if ( count( $args ) > 0 ) {
			$args_exploded = explode( ',', $args[0] );
			foreach ( $args_exploded as $arg ) {
				if ( ! is_numeric( trim( $arg ) ) ) {
					\WP_CLI::error( 'Child site IDs should be numeric.' );
				}

				$sites[] = trim( $arg );
			}
		}

		if ( isset( $assoc_args['list'] ) ) {
			$websites            = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
			$userExtension       = MainWP_DB_Common::instance()->get_user_extension();
			$websites_to_upgrade = array();
			while ( $websites && ( $website          = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites, true ) ) ) {
					continue;
				}

				$theme_upgrades = json_decode( $website->theme_upgrades, true );

				if ( is_array( $theme_upgrades ) ) {
					$ignored_themes = json_decode( $website->ignored_themes, true );
					if ( is_array( $ignored_themes ) ) {
						$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
					}

					$ignored_themes = json_decode( $userExtension->ignored_themes, true );
					if ( is_array( $ignored_themes ) ) {
						$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
					}

					$tmp = array();
					foreach ( $theme_upgrades as $theme_upgrade ) {
						$tmp[] = array(
							'name'        => $theme_upgrade['update']['theme'],
							'version'     => $theme_upgrade['Version'],
							'new_version' => $theme_upgrade['update']['new_version'],
						);
					}
					$websites_to_upgrade[] = array(
						'id'     => $website->id,
						'name'   => $website->name,
						'themes' => $tmp,
					);
				}
			}

			$idLength         = strlen( 'id' );
			$nameLength       = strlen( 'name' );
			$themeLength      = strlen( 'theme' );
			$oldVersionLength = strlen( 'version' );
			$newVersionLength = strlen( 'new version' );
			foreach ( $websites_to_upgrade as $website_to_upgrade ) {
				if ( $idLength < strlen( $website_to_upgrade['id'] ) ) {
					$idLength = strlen( $website_to_upgrade['id'] );
				}
				if ( $nameLength < strlen( $website_to_upgrade['name'] ) ) {
					$nameLength = strlen( $website_to_upgrade['name'] );
				}

				foreach ( $website_to_upgrade['themes'] as $theme_to_upgrade ) {
					if ( $themeLength < strlen( $theme_to_upgrade['name'] ) ) {
						$themeLength = strlen( $theme_to_upgrade['name'] );
					}
					if ( $oldVersionLength < strlen( $theme_to_upgrade['version'] ) ) {
						$oldVersionLength = strlen( $theme_to_upgrade['version'] );
					}
					if ( $newVersionLength < strlen( $theme_to_upgrade['new_version'] ) ) {
						$newVersionLength = strlen( $theme_to_upgrade['new_version'] );
					}
				}
			}

			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . "s+%'--" . ( $newVersionLength + 2 ) . 's+', '', '', '', '', '' ) );
			\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $themeLength . 's | %-' . $oldVersionLength . 's | %-' . $newVersionLength . 's |', 'id', 'name', 'theme', 'version', 'new version' ) );
			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . "s+%'--" . ( $newVersionLength + 2 ) . 's+', '', '', '', '', '' ) );

			foreach ( $websites_to_upgrade as $website_to_upgrade ) {
				if ( $idLength < strlen( $website_to_upgrade['id'] ) ) {
					$idLength = strlen( $website_to_upgrade['id'] );
				}
				if ( $nameLength < strlen( $website_to_upgrade['name'] ) ) {
					$nameLength = strlen( $website_to_upgrade['name'] );
				}

				$i = 0;
				foreach ( $website_to_upgrade['themes'] as $theme_to_upgrade ) {
					if ( 0 === $i ) {
						\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $themeLength . 's | %-' . $oldVersionLength . 's | %-' . $newVersionLength . 's |', $website_to_upgrade['id'], $website_to_upgrade['name'], $theme_to_upgrade['name'], $theme_to_upgrade['version'], $theme_to_upgrade['new_version'] ) );
					} else {
						\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $themeLength . 's | %-' . $oldVersionLength . 's | %-' . $newVersionLength . 's |', '', '', $theme_to_upgrade['name'], $theme_to_upgrade['version'], $theme_to_upgrade['new_version'] ) );
					}
					++$i;
				}
			}

			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . "s+%'--" . ( $newVersionLength + 2 ) . 's+', '', '', '', '', '' ) );
		} elseif ( isset( $assoc_args['list-all'] ) ) {
			$websites            = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
			$userExtension       = MainWP_DB_Common::instance()->get_user_extension();
			$websites_to_upgrade = array();
			while ( $websites && ( $website          = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites, true ) ) ) {
					continue;
				}
				$theme_to_list = json_decode( $website->themes, true );
				if ( is_array( $theme_to_list ) ) {
					$tmp = array();
					foreach ( $theme_to_list as $theme ) {
						$tmp[] = array(
							'name'    => $theme['name'],
							'active'  => $theme['active'] ? 'yes' : '',
							'version' => $theme['version'],
						);
					}
					$websites_to_upgrade[] = array(
						'id'     => $website->id,
						'name'   => $website->name,
						'themes' => $tmp,
					);
				}
			}

			$idLength         = strlen( 'id' );
			$nameLength       = strlen( 'name' );
			$themeLength      = strlen( 'theme' );
			$oldVersionLength = strlen( 'version' );
			$activeLength     = strlen( 'active' );
			foreach ( $websites_to_upgrade as $website_item ) {
				if ( $idLength < strlen( $website_item['id'] ) ) {
					$idLength = strlen( $website_item['id'] );
				}
				if ( $nameLength < strlen( $website_item['name'] ) ) {
					$nameLength = strlen( $website_item['name'] );
				}

				foreach ( $website_item['themes'] as $theme_item ) {
					if ( $themeLength < strlen( $theme_item['name'] ) ) {
						$themeLength = strlen( $theme_item['name'] );
					}
					if ( $oldVersionLength < strlen( $theme_item['version'] ) ) {
						$oldVersionLength = strlen( $theme_item['version'] );
					}
				}
			}

			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $activeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . 's+', '', '', '', '', '' ) );
			\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $themeLength . 's | %-' . $activeLength . 's | %-' . $oldVersionLength . 's |', 'id', 'name', 'theme', 'active', 'version' ) );
			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $activeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . 's+', '', '', '', '', '' ) );

			foreach ( $websites_to_upgrade as $website_item ) {
				if ( $idLength < strlen( $website_item['id'] ) ) {
					$idLength = strlen( $website_item['id'] );
				}
				if ( $nameLength < strlen( $website_item['name'] ) ) {
					$nameLength = strlen( $website_item['name'] );
				}

				$i = 0;
				foreach ( $website_item['themes'] as $theme_item ) {
					if ( 0 === $i ) {
						\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $themeLength . 's | %-' . $activeLength . 's | %-' . $oldVersionLength . 's |', $website_item['id'], $website_item['name'], $theme_item['name'], $theme_item['active'], $theme_item['version'] ) );
					} else {
						\WP_CLI::line( sprintf( '| %-' . $idLength . 's | %-' . $nameLength . 's | %-' . $themeLength . 's | %-' . $activeLength . 's | %-' . $oldVersionLength . 's |', '', '', $theme_item['name'], $theme_item['active'], $theme_item['version'] ) );
					}
					++$i;
				}
			}

			\WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ) . "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $activeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ) . 's+', '', '', '', '', '' ) );
		} elseif ( isset( $assoc_args['upgrade'] ) || isset( $assoc_args['upgrade-all'] ) ) {

			$themeSlugs = array();
			if ( isset( $assoc_args['upgrade'] ) ) {
				$themeSlugs = explode( ',', $assoc_args['upgrade'] );
			}

			$websites      = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
			$userExtension = MainWP_DB_Common::instance()->get_user_extension();
			while ( $websites && ( $website      = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites, true ) ) ) {
					continue;
				}

				$theme_upgrades = json_decode( $website->theme_upgrades, true );

				if ( is_array( $theme_upgrades ) ) {
					$ignored_themes = json_decode( $website->ignored_themes, true );
					if ( is_array( $ignored_themes ) ) {
						$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
					}

					$ignored_themes = json_decode( $userExtension->ignored_themes, true );
					if ( is_array( $ignored_themes ) ) {
						$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
					}

					$tmp = array();
					foreach ( $theme_upgrades as $key => $theme_upgrade ) {
						if ( ( count( $themeSlugs ) > 0 ) && ( ! in_array( $theme_upgrade['update']['slug'], $themeSlugs, true ) ) ) {
							continue;
						}

						$tmp[] = $key;
					}

					if ( 0 === count( $tmp ) ) {
						\WP_CLI::line( 'No available theme updates for ' . $website->name );

						continue;
					}

					\WP_CLI::line( 'Updating ' . count( $tmp ) . ' themes for ' . $website->name );

					try {
						MainWP_Updates_Handler::upgrade_plugin_theme_translation( $website->id, 'theme', implode( ',', $tmp ) );
						\WP_CLI::success( 'Updates completed' );
					} catch ( \Exception $e ) {
						\WP_CLI::error( 'Updates failed: ' . MainWP_Error_Helper::get_console_error_message( $e ) );
						if ( $e->getMesage() === 'WPERROR' ) {
							\WP_CLI::debug( 'Error: ' . MainWP_Utility::value_to_string( $e->get_message_extra(), 1 ) );
						}
					}
				}
			}
		}
	}
}
