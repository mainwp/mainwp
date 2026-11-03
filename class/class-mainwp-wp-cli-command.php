<?php
/**
 * MainWP-CLI
 *
 * This file extends the WP-CLI and provides a set of SubCommands to Control your
 * Child Sites that are added to the MainWP Dashboard.
 *
 * @devtodo: allow to add or remove child sites
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Exit if access directly.
if ( ! defined( 'WP_CLI' ) ) {
    return; // NOSONAR - jump to avoid CLI.
}

/**
 * Class MainWP_WP_CLI_Command
 *
 * Manage all child sites added to the MainWP Dashboard.
 *
 * @package MainWP\Dashboard
 */
class MainWP_WP_CLI_Command extends \WP_CLI_Command { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method init()
     *
     * Initiate the MainWP CLI after all Plugins have loaded.
     */
    public static function init() {
        add_action( 'plugins_loaded', array( static::class, 'init_wpcli_commands' ), 99999 );
    }

    /**
     * Method init_wpcli_commands
     *
     * Adds the MainWP WP CLI Commands via WP_CLI::add_command
     */
    public static function init_wpcli_commands() {
        \WP_CLI::add_command( 'mainwp', static::class );
    }

    // The sites, site and updates help blocks indent each description instead of
    // using WP-CLI's ": description" lines. A ":" line makes WP-CLI build a synopsis
    // and validate arguments against it. That would turn the undocumented --list and
    // --count, which still print the site table, into errors, and would drop a bare
    // --adminpassword or --ver before the handler can refuse it.

    /**
     * Lists, counts, syncs, checks or disconnects child sites.
     *
     * With no option, prints a table of all child sites.
     *
     * ## OPTIONS
     *
     * [<websiteids>]
     *     Comma-separated child site IDs for --check-sites or --disconnect-sites. Leave out to use every site.
     *
     * [--all-sites]
     *     List all child sites.
     *
     * [--all-sites-count]
     *     Show how many child sites there are.
     *
     * [--connected-sites]
     *     List connected child sites.
     *
     * [--connected-sites-count]
     *     Show how many child sites are connected.
     *
     * [--disconnected-sites]
     *     List disconnected child sites.
     *
     * [--disconnected-sites-count]
     *     Show how many child sites are disconnected.
     *
     * [--sync-sites]
     *     Sync all child sites.
     *
     * [--check-sites]
     *     Check the HTTP status of child sites.
     *
     * [--disconnect-sites]
     *     Disconnect child sites. Every site is tried, and the command fails at the end if any site could not be disconnected.
     *
     * ## EXAMPLES
     *
     *     wp mainwp sites
     *     wp mainwp sites --connected-sites-count
     *     wp mainwp sites --sync-sites
     *     wp mainwp sites --check-sites 2,5
     *     wp mainwp sites --disconnect-sites 2,5
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
     * Shows, adds, edits, updates or removes a child site.
     *
     * Every option except --add-site needs the child site ID.
     *
     * ## OPTIONS
     *
     * [<websiteid>]
     *     Child site ID. Required by every option except --add-site.
     *
     * [--site]
     *     Show the site's name, URL, admin username, HTTP response, notes and health details.
     *
     * [--site-info]
     *     Show the site's WordPress, PHP, MySQL and MainWP Child versions, memory limit, active theme and IP address.
     *
     * [--site-installed-plugins]
     *     List installed plugins.
     *
     * [--site-installed-plugins-count]
     *     Show how many plugins are installed.
     *
     * [--site-active-plugins]
     *     List active plugins.
     *
     * [--site-active-plugins-count]
     *     Show how many plugins are active.
     *
     * [--site-inactive-plugins]
     *     List inactive plugins.
     *
     * [--site-inactive-plugins-count]
     *     Show how many plugins are inactive.
     *
     * [--site-installed-themes]
     *     List installed themes.
     *
     * [--site-installed-themes-count]
     *     Show how many themes are installed.
     *
     * [--site-active-themes]
     *     Show the active theme.
     *
     * [--site-inactive-themes]
     *     List inactive themes.
     *
     * [--site-inactive-themes-count]
     *     Show how many themes are inactive.
     *
     * [--site-available-updates]
     *     List available WordPress, plugin, theme and translation updates.
     *
     * [--site-available-updates-count]
     *     Show how many updates are available.
     *
     * [--site-abandoned-plugins]
     *     List abandoned plugins.
     *
     * [--site-abandoned-plugins-count]
     *     Show how many plugins are abandoned.
     *
     * [--site-abandoned-themes]
     *     List abandoned themes.
     *
     * [--site-abandoned-themes-count]
     *     Show how many themes are abandoned.
     *
     * [--site-http-status]
     *     Run an HTTP status check on the site.
     *
     * [--site-health-score]
     *     Show whether the site health is Good or Should be improved.
     *
     * [--site-security-issues]
     *     List the results of the basic security checks.
     *
     * [--add-site]
     *     Add a child site. Needs --site-url, --name and --admin.
     *
     * [--site-url=<url>]
     *     Child site URL, for --add-site.
     *
     * [--name=<name>]
     *     Site name, for --add-site and --edit-site.
     *
     * [--admin=<username>]
     *     Child site administrator username, for --add-site and --edit-site.
     *
     * [--adminpassword=<password>]
     *     Child site administrator password, for --add-site when the MainWP Child plugin asks for it. Needs an https:// site URL. With --ssl_verify=0 an http:// URL is also accepted, and the password is then sent unencrypted.
     *
     * [--ssl_verify=<value>]
     *     Certificate check for --add-site: 1 verifies the HTTPS certificate, 2 uses the Dashboard's global SSL setting, 0 turns the check off. Defaults to 2, or to 1 with --adminpassword. Use 0 only if you accept the risk.
     *
     * [--uniqueid=<id>]
     *     Unique security ID set in the MainWP Child plugin, for --add-site and --edit-site.
     *
     * [--force_use_ipv4=<value>]
     *     Set to 1 to connect over IPv4 only, for --add-site.
     *
     * [--ssl_version=<version>]
     *     SSL version to use, for --add-site.
     *
     * [--sslversion=<version>]
     *     SSL version to use, for --edit-site.
     *
     * [--http_user=<username>]
     *     HTTP basic authentication username, for --add-site and --edit-site.
     *
     * [--http_pass=<password>]
     *     HTTP basic authentication password, for --add-site and --edit-site.
     *
     * [--groupids=<ids>]
     *     Comma-separated tag IDs to add the site to, for --add-site.
     *
     * [--edit-site]
     *     Change the settings given with --name, --admin, --uniqueid, --sslversion, --http_user or --http_pass.
     *
     * [--sync-site]
     *     Sync the site.
     *
     * [--reconnect-site]
     *     Reconnect the site.
     *
     * [--disconnect-site]
     *     Disconnect the site.
     *
     * [--remove-site]
     *     Remove the site from the MainWP Dashboard.
     *
     * [--site-update-wordpress]
     *     Update WordPress core.
     *
     * [--site-update-plugins]
     *     Update all plugins.
     *
     * [--site-update-themes]
     *     Update all themes.
     *
     * [--site-update-translations]
     *     Update all translations.
     *
     * [--site-update-item]
     *     Update one plugin or theme. Needs --type and --slug.
     *
     * [--type=<type>]
     *     plugin or theme, for --site-update-item.
     *
     * [--slug=<slug>]
     *     Plugin file, such as akismet/akismet.php, or theme folder name, for --site-update-item.
     *
     * [--site-manage-plugin]
     *     Activate, deactivate or delete a plugin. Needs --plugin and --action.
     *
     * [--plugin=<plugin>]
     *     Plugin file, such as akismet/akismet.php, for --site-manage-plugin. Separate several with ||.
     *
     * [--site-manage-theme]
     *     Activate or delete a theme. Needs --theme and --action.
     *
     * [--theme=<theme>]
     *     Theme folder name, for --site-manage-theme. Separate several with ||.
     *
     * [--action=<action>]
     *     activate, deactivate or delete for --site-manage-plugin; activate or delete for --site-manage-theme.
     *
     * [--check-site-http-status]
     *     Check the site's HTTP status and print the response code.
     *
     * ## EXAMPLES
     *
     *     wp mainwp site --site-info 3
     *     wp mainwp site --site-available-updates 3
     *     wp mainwp site --add-site --site-url=https://example.com --name="Example" --admin=admin
     *     wp mainwp site --edit-site 3 --name="New name"
     *     wp mainwp site --site-update-item 3 --type=plugin --slug=akismet/akismet.php
     *     wp mainwp site --site-manage-plugin 3 --plugin=akismet/akismet.php --action=deactivate
     *
     * @param array $args Function arguments.
     * @param array $assoc_args Function associate arguments.
     */
    public function site( $args, $assoc_args ) {
        MainWP_WP_CLI_Handle::handle_cli_callback( 'site', $args, $assoc_args );
    }


    /**
     * Lists available and ignored updates, and ignores or unignores them.
     *
     * ## OPTIONS
     *
     * [<websiteid>]
     *     Child site ID. Required by --site-ignored-plugins-updates, --site-ignored-themes-updates, --ignore-update and --unignore-update. Optional for --ignored-plugins-updates and --ignored-themes-updates.
     *
     * [--available-updates]
     *     List available updates on all child sites.
     *
     * [--ignored-plugins-updates]
     *     List globally ignored plugin updates. With a site ID, also list that site's ignored plugin updates.
     *
     * [--site-ignored-plugins-updates]
     *     List the plugin updates ignored on one site.
     *
     * [--ignored-themes-updates]
     *     List globally ignored theme updates. With a site ID, also list that site's ignored theme updates.
     *
     * [--site-ignored-themes-updates]
     *     List the theme updates ignored on one site.
     *
     * [--ignore-updates]
     *     Ignore all versions of a plugin or theme update on every site. Needs --type, --slug and --name.
     *
     * [--ignore-update]
     *     Ignore all versions of a plugin or theme update on one site. Needs --type, --slug and --name.
     *
     * [--unignore-updates]
     *     Stop ignoring a plugin or theme update globally. Needs --type and --slug.
     *
     * [--unignore-update]
     *     Stop ignoring a plugin or theme update on one site. Needs --type and --slug.
     *
     * [--type=<type>]
     *     plugin or theme.
     *
     * [--slug=<slug>]
     *     Plugin file, such as akismet/akismet.php, or theme folder name.
     *
     * [--name=<name>]
     *     Plugin or theme name to show in the ignored list, for --ignore-updates and --ignore-update.
     *
     * [--ver=<version>]
     *     Version to unignore, for --unignore-updates and --unignore-update. Leave out to unignore all versions.
     *
     * ## EXAMPLES
     *
     *     wp mainwp updates --available-updates
     *     wp mainwp updates --ignored-plugins-updates
     *     wp mainwp updates --site-ignored-plugins-updates 3
     *     wp mainwp updates --ignore-updates --type=plugin --slug=akismet/akismet.php --name="Akismet Anti-spam"
     *     wp mainwp updates --unignore-updates --type=plugin --slug=akismet/akismet.php
     *     wp mainwp updates --unignore-update 3 --type=plugin --slug=akismet/akismet.php
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
        if ( ! empty( $args ) ) {
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

        if ( empty( $sites ) && ( ! isset( $assoc_args['all'] ) ) ) {
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
    public function reconnect( $args, $assoc_args ) {  //phpcs:ignore -- NOSONAR - complex.
        $sites = array();
        if ( ! empty( $args ) ) {
            $args_exploded = explode( ',', $args[0] );
            foreach ( $args_exploded as $arg ) {
                if ( ! is_numeric( trim( $arg ) ) ) {
                    \WP_CLI::error( 'Child site ids should be numeric.' );
                }

                $sites[] = trim( $arg );
            }
        }

        if ( empty( $sites ) ) {
            \WP_CLI::error( 'Please specify one or more child sites.' );
        }

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
        \WP_CLI::line( 'Reconnect started' );
        $warnings = 0;
        $errors   = 0;
        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            if ( ( ! empty( $sites ) ) && ( ! in_array( $website->id, $sites, true ) ) ) {
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
    public function plugin( $args, $assoc_args ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $sites = array();
        if ( ! empty( $args ) ) {
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
                if ( ! empty( $sites ) && ( ! in_array( $website->id, $sites, true ) ) ) {
                    continue;
                }

                $plugin_upgrades = json_decode( $website->plugin_upgrades, true );

                if ( is_array( $plugin_upgrades ) ) {
                    $ignored_plugins = json_decode( $website->ignored_plugins, true );
                    if ( is_array( $ignored_plugins ) ) {
                        $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

                    }

                    $ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
                    if ( is_array( $ignored_plugins ) ) {
                        $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

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
                if ( ( ! empty( $sites ) ) && ( ! in_array( $website->id, $sites, true ) ) ) {
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
                if ( ! empty( $sites ) && ( ! in_array( $website->id, $sites, true ) ) ) {
                    continue;
                }

                $plugin_upgrades = json_decode( $website->plugin_upgrades, true );

                if ( is_array( $plugin_upgrades ) ) {
                    $ignored_plugins = json_decode( $website->ignored_plugins, true );
                    if ( is_array( $ignored_plugins ) ) {
                        $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

                    }

                    $ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
                    if ( is_array( $ignored_plugins ) ) {
                        $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

                    }

                    $tmp = array();
                    foreach ( $plugin_upgrades as $key => $plugin_upgrade ) {
                        if ( ! empty( $pluginSlugs ) && ( ! in_array( $plugin_upgrade['update']['slug'], $pluginSlugs, true ) ) ) {
                            continue;
                        }

                        $tmp[] = $key;
                    }

                    if ( empty( $tmp ) ) {
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
    public function theme( $args, $assoc_args ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $sites = array();
        if ( ! empty( $args ) ) {
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
                if ( ! empty( $sites ) && ( ! in_array( $website->id, $sites, true ) ) ) {
                    continue;
                }

                $theme_upgrades = json_decode( $website->theme_upgrades, true );

                if ( is_array( $theme_upgrades ) ) {
                    $ignored_themes = json_decode( $website->ignored_themes, true );
                    if ( is_array( $ignored_themes ) ) {
                        $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                    }

                    $ignored_themes = json_decode( $userExtension->ignored_themes, true );
                    if ( is_array( $ignored_themes ) ) {
                        $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
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
                if ( ! empty( $sites ) && ( ! in_array( $website->id, $sites, true ) ) ) {
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
                if ( ! empty( $sites ) && ( ! in_array( $website->id, $sites, true ) ) ) {
                    continue;
                }

                $theme_upgrades = json_decode( $website->theme_upgrades, true );

                if ( is_array( $theme_upgrades ) ) {
                    $ignored_themes = json_decode( $website->ignored_themes, true );
                    if ( is_array( $ignored_themes ) ) {
                        $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                    }

                    $ignored_themes = json_decode( $userExtension->ignored_themes, true );
                    if ( is_array( $ignored_themes ) ) {
                        $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                    }

                    $tmp = array();
                    foreach ( $theme_upgrades as $key => $theme_upgrade ) {
                        if ( ! empty( $themeSlugs ) && ( ! in_array( $theme_upgrade['update']['slug'], $themeSlugs, true ) ) ) {
                            continue;
                        }

                        $tmp[] = $key;
                    }

                    if ( empty( $tmp ) ) {
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

    /**
     * Restore a child site from a native backup snapshot.
     *
     * Drives a remote restore of a child site entirely from the dashboard
     * command line: it resolves the snapshot chain, enforces the same
     * destructive confirmation the dashboard requires, starts the restore
     * job, and follows it to completion. WooCommerce orders and any other
     * data written since the backup are lost on a database-bearing restore,
     * so it refuses to overwrite a live database without --confirm-destructive.
     *
     * ## OPTIONS
     *
     * <action>
     * : The backup action to run. Only "restore" is supported.
     *
     * [<snapshot-id>]
     * : The id of the snapshot to restore.
     *
     * [--site=<id>]
     * : The id of the child site to restore.
     *
     * [--pre-snapshot]
     * : Take a safety backup of the site before restoring, enabling file rollback.
     *
     * [--preserve-orders]
     * : Keep orders, customers, and product stock placed on the live store since the backup. WooCommerce stores using High-Performance Order Storage with synchronization off only; the restore stops with an explanation otherwise. No effect on files-only restores.
     *
     * [--confirm-destructive]
     * : Confirm a database-bearing restore that overwrites live data.
     *
     * [--yes]
     * : Alias for --confirm-destructive.
     *
     * [--timeout=<seconds>]
     * : Stop following the job after this many seconds; the restore then continues in the background. 0 (default) follows to the end.
     *
     * ## EXAMPLES
     *
     *     wp mainwp backup restore 42 --site=5 --confirm-destructive
     *     wp mainwp backup restore 42 --site=5 --pre-snapshot --yes
     *     wp mainwp backup restore 42 --site=5 --preserve-orders --confirm-destructive
     *
     * ## Synopsis <action> [<snapshot-id>] [--site=<id>] [--pre-snapshot] [--preserve-orders] [--confirm-destructive] [--yes] [--timeout=<seconds>]
     *
     * @param array $args       Function arguments.
     * @param array $assoc_args Function associate arguments.
     */
    public function backup( $args, $assoc_args ) {
        $action = isset( $args[0] ) ? $args[0] : '';
        if ( 'restore' !== $action ) {
            \WP_CLI::error( 'Unknown subcommand. Usage: wp mainwp backup restore <snapshot-id> --site=<id> [--pre-snapshot] [--confirm-destructive] [--yes] [--timeout=<seconds>]' );
            return;
        }

        $snapshot_id = isset( $args[1] ) ? (int) $args[1] : 0;
        if ( $snapshot_id <= 0 ) {
            \WP_CLI::error( 'Provide the snapshot id. Usage: wp mainwp backup restore <snapshot-id> --site=<id>' );
            return;
        }

        $site_id = isset( $assoc_args['site'] ) ? (int) $assoc_args['site'] : 0;
        if ( $site_id <= 0 ) {
            \WP_CLI::error( 'Provide the child site id with --site=<id>.' );
            return;
        }

        $pre_snapshot    = isset( $assoc_args['pre-snapshot'] );
        $preserve_orders = isset( $assoc_args['preserve-orders'] );
        $confirmed       = isset( $assoc_args['confirm-destructive'] ) || isset( $assoc_args['yes'] );
        $timeout         = isset( $assoc_args['timeout'] ) ? max( 0, (int) $assoc_args['timeout'] ) : 0;

        // The staging and transfer driver reads and writes files through
        // WP_Filesystem, which CLI does not initialise on its own.
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR - WP compatible.
        }
        WP_Filesystem();

        $runner = new MainWP_Backup_CLI_Restore_Runner( null, null, null, 60, 2, 120, $timeout );

        $progress = function ( $job ) {
            \WP_CLI::log( '  ' . $job->status );
        };

        $result = $runner->run(
            $site_id,
            $snapshot_id,
            array(
                'pre_snapshot'    => $pre_snapshot,
                'preserve_orders' => $preserve_orders,
            ),
            $confirmed,
            $progress
        );

        if ( ! empty( $result['needs_confirmation'] ) ) {
            \WP_CLI::warning( 'This restore overwrites the live database for site ' . $site_id . '.' );
            \WP_CLI::log( '  backup age: ' . (int) $result['age_days'] . ' day(s)' );
            if ( ! empty( $result['site_has_woocommerce'] ) ) {
                \WP_CLI::log( '  this site runs WooCommerce; orders placed since the backup will be lost unless you add --preserve-orders.' );
            }
            \WP_CLI::error( 'Re-run with --confirm-destructive (or --yes) to proceed.' );
            return;
        }

        if ( ! empty( $result['ok'] ) ) {
            \WP_CLI::success( 'Restore completed.' );
            return;
        }

        $message = '' !== (string) $result['error'] ? (string) $result['error'] : 'The restore failed.';
        if ( '' !== (string) $result['error_code'] ) {
            $message .= ' (' . $result['error_code'] . ')';
        }
        \WP_CLI::error( $message );
    }
}
