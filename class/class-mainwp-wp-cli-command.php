<?php

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Manage all child sites added to the MainWP Dashboard
 */
class MainWP_WP_CLI_Command extends WP_CLI_Command {

	public static function init() {
		add_action( 'plugins_loaded', array( 'MainWP_WP_CLI_Command', 'init_wpcli_commands'), 99999 );
	}

	public static function init_wpcli_commands() {
		WP_CLI::add_command( 'mainwp', 'MainWP_WP_CLI_Command' );
	}

	/**
	 * List information about added child sites
	 *
	 * ## OPTIONS
	 *
	 * [--list]
	 *  : Get a list of all child sites
	 *
	 * @todo: allow to add or remove child sites
	 * @synopsis [--list]
	 */
	public function sites( $args, $assoc_args ) {
		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.url', false, false, null, true) );
		$idLength = strlen('id');
		$nameLength = strlen('name');
		$urlLength = strlen('url');
		$versionLength = strlen('version');
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			if ( $idLength < strlen( $website->id ) ) $idLength = strlen( $website->id );
			if ( $nameLength < strlen( $website->name ) ) $nameLength = strlen( $website->name );
			if ( $urlLength < strlen( $website->url ) ) $urlLength = strlen( $website->url );
			if ( $versionLength < strlen( $website->version ) ) $versionLength = strlen( $website->version );
		}
		@MainWP_DB::data_seek( $websites, 0 );

		WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $urlLength + 2 ) . "s+%'--" . ( $versionLength + 2 ). "s+", '', '', '', '' ) );
		WP_CLI::line( sprintf( "| %-" . $idLength . "s | %-" . $nameLength . "s | %-" . $urlLength . "s | %-" . $versionLength . "s |", 'id', 'name', 'url', 'version' ) );
		WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $urlLength + 2 ) . "s+%'--" . ( $versionLength + 2 ). "s+", '', '', '', '' ) );

		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			WP_CLI::line( sprintf( "| %-" . $idLength . "s | %-" . $nameLength . "s | %-" . $urlLength . "s | %-" . $versionLength . "s |", $website->id, $website->name, $website->url, $website->version ) );
		}

		WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $urlLength + 2 ) . "s+%'--" . ( $versionLength + 2 ). "s+", '', '', '', '' ) );
		@MainWP_DB::free_result( $websites );
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
	 * @synopsis [<websiteid>] [--all]
	 */
	public function sync( $args, $assoc_args ) {
		$sites = array();
		if ( count( $args ) > 0 ) {
			$args_exploded = explode( ',', $args[0] );
			foreach ($args_exploded as $arg) {
				if ( ! is_numeric( trim( $arg ) ) ) {
					WP_CLI::error('Child site ids should be numeric.');
				}

				$sites[] = trim( $arg );
			}
		}

		if ( ( count($sites) == 0 ) && ( !isset( $assoc_args['all'] ) ) ) WP_CLI::error('Please specify one or more child sites, or use --all.');

		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.url', false, false, null, true) );
		WP_CLI::line( 'Sync started' );
		$warnings = 0;
		$errors   = 0;
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( count( $sites ) > 0 ) && ( !in_array( $website->id, $sites ) ) ) continue;

			WP_CLI::line( '  -> ' . $website->name . ' (' . $website->url . ')' );
			try {
				if ( MainWP_Sync::syncSite( $website ) ) {
					WP_CLI::success( '  Sync succeeded' );
				} else {
					WP_CLI::warning( '  Sync failed' );
					$warnings++;
				}
			} catch ( Exception $e ) {
				WP_CLI::error( '  Sync failed: ' . MainWP_Error_Helper::getConsoleErrorMessage( $e ) );
				$errors++;
			}
		}
		@MainWP_DB::free_result( $websites );
		if ( $errors > 0 ) {
			WP_CLI::error( 'Sync completed with errors' );
		} else if ( $warnings > 0 ) {
			WP_CLI::warning( 'Sync completed with warnings' );
		} else {
			WP_CLI::success( 'Sync completed' );
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
	 *     wp mainwp plugin 2,5 --upgrade-all
	 *     wp mainwp plugin 2,5 --upgrade=mainwpchild
	 *
	 * @synopsis [<websiteid>] [--list] [--upgrade=<pluginslug>] [--upgrade-all]
	 */
	public function plugin( $args, $assoc_args ) {
		$sites = array();
		if ( count( $args ) > 0 ) {
			$args_exploded = explode( ',', $args[0] );
			foreach ($args_exploded as $arg) {
				if ( ! is_numeric( trim( $arg ) ) ) {
					WP_CLI::error('Child site IDs should be numeric.');
				}

				$sites[] = trim( $arg );
			}
		}

		if ( isset( $assoc_args['list'] ) ) {
			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.url', false, false, null, true) );
			$userExtension = MainWP_DB::Instance()->getUserExtension();
			$websites_to_upgrade = array();
			while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites ) ) ) {
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
					foreach ($plugin_upgrades as $plugin_upgrade) {
						$tmp[] = array('name' => $plugin_upgrade['update']['slug'], 'version' => $plugin_upgrade['Version'], 'new_version' => $plugin_upgrade['update']['new_version']);
					}
					$websites_to_upgrade[] = array('id' => $website->id, 'name' => $website->name, 'plugins' => $tmp);
				}
			}

			$idLength = strlen('id');
			$nameLength = strlen('name');
			$pluginLength = strlen('plugin');
			$oldVersionLength = strlen('version');
			$newVersionLength = strlen('new version');
			foreach ( $websites_to_upgrade as $website_to_upgrade ) {
				if ( $idLength < strlen( $website_to_upgrade['id'] ) ) $idLength = strlen( $website_to_upgrade['id'] );
				if ( $nameLength < strlen( $website_to_upgrade['name'] ) ) $nameLength = strlen( $website_to_upgrade['name'] );

				foreach ( $website_to_upgrade['plugins'] as $plugin_to_upgrade ) {
					if ( $pluginLength < strlen( $plugin_to_upgrade['name'] ) ) $pluginLength = strlen( $plugin_to_upgrade['name'] );
					if ( $oldVersionLength < strlen( $plugin_to_upgrade['version'] ) ) $oldVersionLength = strlen( $plugin_to_upgrade['version'] );
					if ( $newVersionLength < strlen( $plugin_to_upgrade['new_version'] ) ) $newVersionLength = strlen( $plugin_to_upgrade['new_version'] );
				}
			}


			WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ). "s+%'--" . ( $newVersionLength + 2 ). "s+", '', '', '', '', '' ) );
			WP_CLI::line( sprintf( "| %-" . $idLength . "s | %-" . $nameLength . "s | %-" . $pluginLength . "s | %-" . $oldVersionLength . "s | %-" . $newVersionLength . "s |", 'id', 'name', 'plugin', 'version', 'new version' ) );
			WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ). "s+%'--" . ( $newVersionLength + 2 ). "s+", '', '', '', '', '' ) );

			foreach ( $websites_to_upgrade as $website_to_upgrade ) {
				if ( $idLength < strlen( $website_to_upgrade['id'] ) ) $idLength = strlen( $website_to_upgrade['id'] );
				if ( $nameLength < strlen( $website_to_upgrade['name'] ) ) $nameLength = strlen( $website_to_upgrade['name'] );

				$i = 0;
				foreach ( $website_to_upgrade['plugins'] as $plugin_to_upgrade ) {
					if ( $i == 0 ) {
						WP_CLI::line( sprintf( "| %-" . $idLength . "s | %-" . $nameLength . "s | %-" . $pluginLength . "s | %-" . $oldVersionLength . "s | %-" . $newVersionLength . "s |", $website_to_upgrade['id'], $website_to_upgrade['name'], $plugin_to_upgrade['name'], $plugin_to_upgrade['version'], $plugin_to_upgrade['new_version'] ) );
					} else {
						WP_CLI::line( sprintf( "| %-" . $idLength . "s | %-" . $nameLength . "s | %-" . $pluginLength . "s | %-" . $oldVersionLength . "s | %-" . $newVersionLength . "s |", '', '', $plugin_to_upgrade['name'], $plugin_to_upgrade['version'], $plugin_to_upgrade['new_version'] ) );
					}
					$i++;
				}
			}

			WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $pluginLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ). "s+%'--" . ( $newVersionLength + 2 ). "s+", '', '', '', '', '' ) );
		}
		else if ( isset( $assoc_args['upgrade'] ) || isset( $assoc_args['upgrade-all'] ) ) {
			//slugs to update

			$pluginSlugs = array();
			if ( isset( $assoc_args['upgrade'] ) ) {
				$pluginSlugs = explode( ',', $assoc_args['upgrade'] );
			}


			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.url', false, false, null, true) );
			$userExtension = MainWP_DB::Instance()->getUserExtension();
			while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites ) ) ) {
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
					foreach ($plugin_upgrades as $key => $plugin_upgrade) {
						if ( ( count( $pluginSlugs ) > 0 ) && ( !in_array( $plugin_upgrade['update']['slug'], $pluginSlugs ) ) ) continue;

						$tmp[] = $key;
					}

					if ( count( $tmp ) == 0 ) {
						WP_CLI::line( 'No available plugin updates for ' . $website->name);

						continue;
					}

					WP_CLI::line( 'Updating ' . count($tmp) . ' plugins for ' . $website->name);

					try {
						MainWP_Right_Now::upgradePluginThemeTranslation( $website->id, 'plugin', implode( ',', $tmp ) );
						WP_CLI::success( 'Updates completed' );
					} catch (Exception $e) {
						WP_CLI::error( 'Updates failed: ' . MainWP_Error_Helper::getConsoleErrorMessage( $e ) );
						if ( $e->getMesage() == 'WPERROR' ) {
							WP_CLI::debug( 'Error: ' . print_r( $e->getMessageExtra(), 1 ) );
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
	 * @synopsis [<websiteid>] [--list] [--upgrade=<theme>] [--upgrade-all]
	 */
	public function theme( $args, $assoc_args ) {
		$sites = array();
		if ( count( $args ) > 0 ) {
			$args_exploded = explode( ',', $args[0] );
			foreach ($args_exploded as $arg) {
				if ( ! is_numeric( trim( $arg ) ) ) {
					WP_CLI::error('Child site IDs should be numeric.');
				}

				$sites[] = trim( $arg );
			}
		}

		if ( isset( $assoc_args['list'] ) ) {
			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.url', false, false, null, true) );
			$userExtension = MainWP_DB::Instance()->getUserExtension();
			$websites_to_upgrade = array();
			while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites ) ) ) {
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
					foreach ($theme_upgrades as $theme_upgrade) {
						$tmp[] = array('name' => $theme_upgrade['update']['theme'], 'version' => $theme_upgrade['Version'], 'new_version' => $theme_upgrade['update']['new_version']);
					}
					$websites_to_upgrade[] = array('id' => $website->id, 'name' => $website->name, 'themes' => $tmp);
				}
			}

			$idLength = strlen('id');
			$nameLength = strlen('name');
			$themeLength = strlen('theme');
			$oldVersionLength = strlen('version');
			$newVersionLength = strlen('new version');
			foreach ( $websites_to_upgrade as $website_to_upgrade ) {
				if ( $idLength < strlen( $website_to_upgrade['id'] ) ) $idLength = strlen( $website_to_upgrade['id'] );
				if ( $nameLength < strlen( $website_to_upgrade['name'] ) ) $nameLength = strlen( $website_to_upgrade['name'] );

				foreach ( $website_to_upgrade['themes'] as $theme_to_upgrade ) {
					if ( $themeLength < strlen( $theme_to_upgrade['name'] ) ) $themeLength = strlen( $theme_to_upgrade['name'] );
					if ( $oldVersionLength < strlen( $theme_to_upgrade['version'] ) ) $oldVersionLength = strlen( $theme_to_upgrade['version'] );
					if ( $newVersionLength < strlen( $theme_to_upgrade['new_version'] ) ) $newVersionLength = strlen( $theme_to_upgrade['new_version'] );
				}
			}


			WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ). "s+%'--" . ( $newVersionLength + 2 ). "s+", '', '', '', '', '' ) );
			WP_CLI::line( sprintf( "| %-" . $idLength . "s | %-" . $nameLength . "s | %-" . $themeLength . "s | %-" . $oldVersionLength . "s | %-" . $newVersionLength . "s |", 'id', 'name', 'theme', 'version', 'new version' ) );
			WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ). "s+%'--" . ( $newVersionLength + 2 ). "s+", '', '', '', '', '' ) );

			foreach ( $websites_to_upgrade as $website_to_upgrade ) {
				if ( $idLength < strlen( $website_to_upgrade['id'] ) ) $idLength = strlen( $website_to_upgrade['id'] );
				if ( $nameLength < strlen( $website_to_upgrade['name'] ) ) $nameLength = strlen( $website_to_upgrade['name'] );

				$i = 0;
				foreach ( $website_to_upgrade['themes'] as $theme_to_upgrade ) {
					if ( $i == 0 ) {
						WP_CLI::line( sprintf( "| %-" . $idLength . "s | %-" . $nameLength . "s | %-" . $themeLength . "s | %-" . $oldVersionLength . "s | %-" . $newVersionLength . "s |", $website_to_upgrade['id'], $website_to_upgrade['name'], $theme_to_upgrade['name'], $theme_to_upgrade['version'], $theme_to_upgrade['new_version'] ) );
					} else {
						WP_CLI::line( sprintf( "| %-" . $idLength . "s | %-" . $nameLength . "s | %-" . $themeLength . "s | %-" . $oldVersionLength . "s | %-" . $newVersionLength . "s |", '', '', $theme_to_upgrade['name'], $theme_to_upgrade['version'], $theme_to_upgrade['new_version'] ) );
					}
					$i++;
				}
			}

			WP_CLI::line( sprintf( "+%'--" . ( $idLength + 2 ) . "s+%'--" . ( $nameLength + 2 ). "s+%'--" . ( $themeLength + 2 ) . "s+%'--" . ( $oldVersionLength + 2 ). "s+%'--" . ( $newVersionLength + 2 ). "s+", '', '', '', '', '' ) );
		}
		else if ( isset( $assoc_args['upgrade'] ) || isset( $assoc_args['upgrade-all'] ) ) {
			//slugs to update

			$themeSlugs = array();
			if ( isset( $assoc_args['upgrade'] ) ) {
				$themeSlugs = explode( ',', $assoc_args['upgrade'] );
			}


			$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.url', false, false, null, true) );
			$userExtension = MainWP_DB::Instance()->getUserExtension();
			while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
				if ( ( count( $sites ) > 0 ) && ( ! in_array( $website->id, $sites ) ) ) {
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
					foreach ($theme_upgrades as $key => $theme_upgrade) {
						if ( ( count( $themeSlugs ) > 0 ) && ( !in_array( $theme_upgrade['update']['slug'], $themeSlugs ) ) ) continue;

						$tmp[] = $key;
					}

					if ( count( $tmp ) == 0 ) {
						WP_CLI::line( 'No available theme updates for ' . $website->name);

						continue;
					}

					WP_CLI::line( 'Updating ' . count($tmp) . ' themes for ' . $website->name);

					try {
						MainWP_Right_Now::upgradePluginThemeTranslation( $website->id, 'theme', implode( ',', $tmp ) );
						WP_CLI::success( 'Updates completed' );
					} catch (Exception $e) {
						WP_CLI::error( 'Updates failed: ' . MainWP_Error_Helper::getConsoleErrorMessage( $e ) );
						if ( $e->getMesage() == 'WPERROR' ) {
							WP_CLI::debug( 'Error: ' . print_r( $e->getMessageExtra(), 1 ) );
						}
					}
				}
			}
		}
	}
}