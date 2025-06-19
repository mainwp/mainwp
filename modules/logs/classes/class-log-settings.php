<?php
/**
 * Centralized manager for WordPress backend functionality.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Settings_Indicator;

defined( 'ABSPATH' ) || exit;

/**
 * Class - Log_Settings
 */
class Log_Settings {

    /**
     * Holds Instance of manager object
     *
     * @var Log_manager
     */
    public $manager;

    /**
     * Holds settings values.
     *
     * @var options
     */
    public $options;

    /**
     * Current page.
     *
     * @static
     * @var string $page Current page.
     */
    public static $page;

    /**
     * Store enable logs items settings.
     *
     * @static
     * @var array $enable_logs_items Enabled logs items.
     */
    private static $enable_logs_items;

    /**
     * Holds the array with all the default built in links.
     *
     * @var array
     */
    private static $ws_al_built_links = array();

    /**
     * Class constructor.
     *
     * @param Log_Manager $manager Instance of manager object.
     */
    public function __construct( $manager ) {
        $this->manager = $manager;

        $this->load_settings();

        add_action( 'admin_init', array( $this, 'admin_init' ) );
        add_filter( 'mainwp_getsubpages_settings', array( $this, 'add_subpage_menu_settings' ) );
        add_filter( 'mainwp_init_primary_menu_items', array( $this, 'hook_init_primary_menu_items' ), 10, 2 );
    }


    /**
     * Handle admin_init action.
     */
    public function admin_init() {
        //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['mainwp_module_log_settings_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['mainwp_module_log_settings_nonce'] ), 'logs_settings_nonce' ) ) {
            $old_enable = is_array( $this->options ) && ! empty( $this->options['enabled'] ) && ! empty( $this->options['auto_archive'] ) ? true : false;

            $this->options['enabled']          = isset( $_POST['mainwp_module_log_enabled'] ) && ! empty( $_POST['mainwp_module_log_enabled'] ) ? 1 : 0;
            $this->options['records_logs_ttl'] = isset( $_POST['mainwp_module_log_records_ttl'] ) ? intval( $_POST['mainwp_module_log_records_ttl'] ) : 3 * YEAR_IN_SECONDS;
            $this->options['auto_archive']     = isset( $_POST['mainwp_module_log_enable_auto_archive'] ) && ! empty( $_POST['mainwp_module_log_enable_auto_archive'] ) ? 1 : 0;
            MainWP_Utility::update_option( 'mainwp_module_log_settings', $this->options );

            $new_enable = is_array( $this->options ) && ! empty( $this->options['enabled'] ) && ! empty( $this->options['auto_archive'] ) ? true : false;

            if ( $old_enable !== $new_enable ) {
                // To reset.
                $sched = wp_next_scheduled( 'mainwp_module_log_cron_job_auto_archive' );
                if ( false !== $sched ) {
                    wp_unschedule_event( $sched, 'mainwp_module_log_cron_job_auto_archive' );
                }
            }

            $logs_data = array(
                'dashboard'        => array(),
                'nonmainwpchanges' => array(),
                'changeslogs'      => array(),
            );

            if ( isset( $_POST['mainwp_settings_logs_data'] ) && is_array( $_POST['mainwp_settings_logs_data'] ) ) {
                $selected_data = $_POST['mainwp_settings_logs_data']; //phpcs:ignore -- NOSONAR -ok.
                foreach ( array( 'dashboard', 'nonmainwpchanges', 'changeslogs' ) as $type ) {
                    $selected_type = isset( $selected_data[ $type ] ) && is_array( $selected_data[ $type ] ) ? array_map( 'sanitize_text_field', wp_unslash( $selected_data[ $type ] ) ) : array();
                    foreach ( $selected_type as $name ) {
                        $logs_data[ $type ][ $name ] = 1;
                    }
                }
            }

            if ( isset( $_POST['mainwp_settings_logs_name'] ) && is_array( $_POST['mainwp_settings_logs_name'] ) ) {
                $name_data = $_POST['mainwp_settings_logs_name']; //phpcs:ignore -- NOSONAR -ok.
                foreach ( array( 'dashboard', 'nonmainwpchanges', 'changeslogs' ) as $type ) {
                    $name_type = isset( $name_data[ $type ] ) && is_array( $name_data[ $type ] ) ? array_map( 'sanitize_text_field', wp_unslash( $name_data[ $type ] ) ) : array();
                    foreach ( $name_type as $name ) {
                        if ( ! isset( $logs_data[ $type ][ $name ] ) ) {
                            $logs_data[ $type ][ $name ] = 0;
                        }
                    }
                }
            }
            MainWP_Utility::update_option( 'mainwp_module_log_settings_logs_selection_data', wp_json_encode( $logs_data ) );

        }
    }

    /**
     * Init sub menu logs settings.
     *
     * @param array $subpages Sub pages.
     *
     * @action init
     */
    public function add_subpage_menu_settings( $subpages = array() ) {
        $subpages[] = array(
            'title'    => esc_html__( 'Dashboard Insights', 'mainwp' ),
            'slug'     => 'Insights',
            'callback' => array( $this, 'render_settings_page' ),
            'class'    => '',
        );
        return $subpages;
    }

    /**
     * Init sub menu logs settings.
     *
     * @param array  $items Sub menu items.
     * @param string $which_menu first|second.
     *
     * @return array $tmp_items Menu items.
     */
    public function hook_init_primary_menu_items( $items, $which_menu ) {
        if ( ! is_array( $items ) || 'first' !== $which_menu ) {
            return $items;
        }
        $items[] = array(
            'slug'               => 'InsightsOverview',
            'menu_level'         => 2,
            'menu_rights'        => array(
                'dashboard' => array(
                    'access_insights_dashboard',
                ),
            ),
            'init_menu_callback' => array( static::class, 'init_menu' ),
            'leftbar_order'      => 2.9,
        );
        return $items;
    }

    /**
     * Method init_menu()
     *
     * Add Insights Overview sub menu "Insights".
     */
    public static function init_menu() {

        static::$page = add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Insights', 'mainwp' ),
            '<span id="mainwp-insights">' . esc_html__( 'Insights', 'mainwp' ) . '</span>',
            'read',
            'InsightsOverview',
            array(
                Log_Insights_Page::instance(),
                'render_insights_overview',
            )
        );

        Log_Insights_Page::init_left_menu();

        if ( isset( $_GET['page'] ) && 'InsightsOverview' === $_GET['page'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_filter( 'mainwp_enqueue_script_gridster', '__return_true' );
        }

        add_action( 'load-' . static::$page, array( static::class, 'on_load_page' ) );
    }

    /**
     * Method load_settings().
     */
    public function load_settings() {
        if ( null === $this->options ) {
            $this->options = get_option( 'mainwp_module_log_settings', array() );
            if ( ! is_array( $this->options ) ) {
                $this->options = array();
            }

            $update = false;

            if ( ! isset( $this->options['enabled'] ) ) {
                $this->options['enabled'] = 1;
                $update                   = true;
            }
            if ( ! isset( $this->options['records_logs_ttl'] ) ) {
                $this->options['records_logs_ttl'] = 3 * YEAR_IN_SECONDS;
                $update                            = true;
            }

            if ( $update ) {
                MainWP_Utility::update_option( 'mainwp_module_log_settings', $this->options );
            }
        }
    }

    /**
     * Method on_load_page()
     *
     * Run on page load.
     */
    public static function on_load_page() {
        Log_Insights_Page::instance()->on_load_page( static::$page );
    }

    /**
     * Render Insights settings page.
     */
    public function render_settings_page() {
        /** This action is documented in ../pages/page-mainwp-manage-sites.php */
        do_action( 'mainwp_pageheader_settings', 'Insights' );
        $enabled              = ! empty( $this->options['enabled'] ) ? true : false;
        $enabled_auto_archive = isset( $this->options['auto_archive'] ) && ! empty( $this->options['auto_archive'] ) ? true : false;

        ?>
        <div id="mainwp-module-log-settings-wrapper" class="ui segment">
            <div class="ui info message">
                <div><?php esc_html_e( 'Dashboard Insights is a feature that will provide you with analytics data about your MainWP Dashboard usage. This version of the MainWP Dashboard contains only the logging part of this feature, which only logs actions performed in the MainWP Dashboard. Once the feature is fully completed, a new version will be released, and the logged data will be available.', 'mainwp' ); ?></div>
                <div><?php esc_html_e( 'Important Note: Collected data stays on your server, and it will never be sent to MainWP servers or 3rd party. Logged data will only be used by you for informative purposes.', 'mainwp' ); ?></div>
            </div>
            <div class="ui form">
                <form method="post" class="mainwp-table-container">
                    <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
                        <h3 class="ui dividing header">
                        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-insights' ); ?>
                        <?php esc_html_e( 'Dashboard Insights Settings', 'mainwp' ); ?></h3>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-insights" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_module_log_enabled', (int) $enabled );
                            esc_html_e( 'Enable insights logging', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox"  data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will enable logging.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_module_log_enabled" id="mainwp_module_log_enabled" <?php echo $enabled ? 'checked="true"' : ''; ?> /><label><?php esc_html_e( 'Default: Enabled', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Auto-archive logs', 'mainwp' ); ?></label>
                            <div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements"  hide-parent="auto-archive" data-tooltip="<?php esc_attr_e( 'Automatically move older logs to the archive after a specified period of time. This helps keep your active logs organized while maintaining a searchable history.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" name="mainwp_module_log_enable_auto_archive" id="mainwp_module_log_enable_auto_archive" <?php echo $enabled_auto_archive ? 'checked="true"' : ''; ?> /><label><?php esc_html_e( 'Default: Off', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general"  <?php echo $enabled && $enabled_auto_archive ? '' : 'style="display:none"'; ?> hide-element="auto-archive" default-indi-value="<?php echo 3 * YEAR_IN_SECONDS; ?>">
                            <label class="six wide column middle aligned">
                                <?php
                                $records_ttl = $this->options['records_logs_ttl'];
                                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_module_log_records_ttl', $records_ttl, true, 3 * YEAR_IN_SECONDS );
                                esc_html_e( 'Insights data retention period', 'mainwp' );
                                ?>
                                </label>
                                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Define how long logs should remain active before being automatically moved to the archive.', 'mainwp' ); ?>" data-inverted="" data-position="top left" >
                                    <select name="mainwp_module_log_records_ttl" id="mainwp_module_log_records_ttl" class="ui dropdown settings-field-value-change-handler">
                                        <option value="<?php echo (int) MONTH_IN_SECONDS; ?>" <?php echo (int) MONTH_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'One month', 'mainwp' ); ?></option>
                                        <option value="<?php echo 2 * MONTH_IN_SECONDS; ?>" <?php echo 2 * MONTH_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Two months', 'mainwp' ); ?></option>
                                        <option value="<?php echo 3 * MONTH_IN_SECONDS; ?>" <?php echo 3 * MONTH_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Three months', 'mainwp' ); ?></option>
                                        <option value="<?php echo 6 * MONTH_IN_SECONDS; ?>" <?php echo 6 * MONTH_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Half a year', 'mainwp' ); ?></option>
                                        <option value="<?php echo (int) YEAR_IN_SECONDS; ?>" <?php echo (int) YEAR_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Year', 'mainwp' ); ?></option>
                                        <option value="<?php echo 2 * YEAR_IN_SECONDS; ?>" <?php echo 2 * YEAR_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Two years', 'mainwp' ); ?></option>
                                        <option value="<?php echo 3 * YEAR_IN_SECONDS; ?>" <?php echo 3 * YEAR_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Three years', 'mainwp' ); ?></option>
                                        <option value="0" <?php echo 0 === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Forever', 'mainwp' ); ?></option>
                                    </select>
                                </div>
                        </div>

                        <?php
                        static::render_logs_data_selection();
                        ?>
                        <div class="ui divider"></div>
                        <input type="submit" name="submit" id="submit" class="ui button green big" value="<?php esc_html_e( 'Save Settings', 'mainwp' ); ?>">
                        <input type="hidden" name="mainwp_module_log_settings_nonce" value="<?php echo esc_attr( wp_create_nonce( 'logs_settings_nonce' ) ); ?>">
                </div>
            </form>
        </div>

        <?php
        /** This action is documented in ../pages/page-mainwp-manage-sites.php */
        do_action( 'mainwp_pagefooter_settings', 'Insights' );
    }


    /**
     * Method is_action_log_enabled().
     *
     * @param string $name Log item name.
     * @param string $type Log type dashboard|nonmainwpchanges.
     *
     * @return bool Enable log or not.
     */
    public static function is_action_log_enabled( $name, $type = 'dashboard' ) {

        if ( ! in_array( $type, array( 'dashboard', 'nonmainwpchanges', 'changeslogs' ) ) ) {
            return true;
        }

        if ( null === static::$enable_logs_items ) {
            $enable_logs = get_option( 'mainwp_module_log_settings_logs_selection_data' );

            if ( ! empty( $enable_logs ) ) {
                static::$enable_logs_items = json_decode( $enable_logs, true );
            }

            if ( ! is_array( static::$enable_logs_items ) ) {
                static::$enable_logs_items = array();
            }
        }

        if ( isset( static::$enable_logs_items[ $type ][ $name ] ) ) {
            return ! empty( static::$enable_logs_items[ $type ][ $name ] ) ? true : false;
        } else {
            $un_logs = static::get_data_logs_default( 'unlogs' );
            if ( ! is_array( $un_logs ) ) {
                $un_logs = array();
            }
            if ( isset( $un_logs[ $name ] ) ) {
                return empty( $un_logs[ $name ] ) ? false : true; // default is enable log, if disabled in un logs list it will be disabled.
            }
        }

        return true;
    }

    /**
     * Method render_logs_data_selection().
     *
     * @return void
     */
    private static function render_logs_data_selection() {
        $list_logs    = static::get_data_logs_default();
        $setting_page = true;
        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-miscellaneous">
            <label class="six wide column top aligned">
            <?php
            MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-logs-data' );
            esc_html_e( 'Events to log', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column" <?php echo $setting_page ? 'data-tooltip="' . esc_attr__( 'Select which types of site changes should be recorded in the logs. Only checked items will generate log entries, helping you focus on the most relevant activity.', 'mainwp' ) . '"' : ''; ?> data-inverted="" data-position="top left">
                <?php
                foreach ( $list_logs as $type => $items ) {

                    if ( 'changeslogs' !== $type ) {
                        ?>
                        <div class="ui header"><?php echo 'dashboard' === $type ? esc_html__( 'Events triggered from MainWP Dashboard', 'mainwp' ) : esc_html__( 'Non-MainWP Changes - Events triggered on child sites', 'mainwp' ); ?></div>
                        <?php
                    }
                    ?>
                    <ul class="mainwp_hide_wpmenu_checkboxes">
                    <?php
                    if ( in_array( $type, array( 'dashboard', 'nonmainwpchanges' ) ) ) {
                        foreach ( $items as $name => $title ) {
                            $_selected = '';
                            if ( static::is_action_log_enabled( $name, $type ) ) {
                                $_selected = 'checked';
                            }
                            ?>
                            <li>
                                <div class="ui checkbox">
                                    <input type="checkbox" class="settings-field-value-change-handler" id="mainwp_select_logs_<?php echo esc_attr( $type ); ?>_<?php echo esc_attr( $name ); ?>" name="mainwp_settings_logs_data[<?php echo esc_attr( $type ); ?>][]" <?php echo esc_html( $_selected ); ?> value="<?php echo esc_attr( $name ); ?>">
                                    <label for="mainwp_select_logs_<?php echo esc_attr( $type ); ?>_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
                                </div>
                                <input type="hidden" name="mainwp_settings_logs_name[<?php echo esc_attr( $type ); ?>][]" value="<?php echo esc_attr( $name ); ?>">
                            </li>
                            <?php
                        }
                    } else {

                        $items = current( $items );

                        foreach ( $items as $group => $sub_items ) {
                            foreach ( $sub_items as $item ) {

                                $name  = $item[0];
                                $title = $item[1];

                                $_selected = '';
                                if ( static::is_action_log_enabled( $name, $type ) ) {
                                    $_selected = 'checked';
                                }

                                ?>
                                <li>
                                    <div class="ui checkbox">
                                        <input type="checkbox" class="settings-field-value-change-handler" id="mainwp_select_logs_<?php echo esc_attr( $type ); ?>_<?php echo esc_attr( $name ); ?>" name="mainwp_settings_logs_data[<?php echo esc_attr( $type ); ?>][]" <?php echo esc_html( $_selected ); ?> value="<?php echo esc_attr( $name ); ?>">
                                        <label for="mainwp_select_logs_<?php echo esc_attr( $type ); ?>_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
                                    </div>
                                    <input type="hidden" name="mainwp_settings_logs_name[<?php echo esc_attr( $type ); ?>][]" value="<?php echo esc_attr( $name ); ?>">
                                </li>
                                <?php
                            }
                        }
                    }
                    ?>
                    </ul>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
    }


    /**
     * Method get_data_logs_default().
     *
     * @param string $type Type of logs info.
     *
     * @return array data.
     */
    private static function get_data_logs_default( $type = '' ) {

        $init_un_logs = array(
            'sites_sync' => 0,
        );

        $logs = array(
            'dashboard'        => array(
                'sites_added'           => __( 'Site Added', 'mainwp' ),
                'sites_updated'         => __( 'Site Updated', 'mainwp' ),
                'sites_sync'            => __( 'Site Synchronized', 'mainwp' ),
                'sites_deleted'         => __( 'Site Deleted', 'mainwp' ),
                'sites_reconnect'       => __( 'Site Reconnected', 'mainwp' ),
                'sites_suspend'         => __( 'Site Suspended', 'mainwp' ),
                'sites_unsuspend'       => __( 'Site Unsuspended', 'mainwp' ),

                'tags_created'          => __( 'Tag Created', 'mainwp' ),
                'tags_deleted'          => __( 'Tag Deleted', 'mainwp' ),
                'tags_updated'          => __( 'Tag Updated', 'mainwp' ),

                'theme_install'         => __( 'Theme Installed', 'mainwp' ),
                'theme_activate'        => __( 'Theme Activated', 'mainwp' ),
                'theme_deactivate'      => __( 'Theme Deactivated', 'mainwp' ),
                'theme_update'          => __( 'Theme Updated', 'mainwp' ),
                'theme_switch'          => __( 'Theme Switched', 'mainwp' ),
                'theme_delete'          => __( 'Theme Deleted', 'mainwp' ),

                'plugin_install'        => __( 'Plugin Installed', 'mainwp' ),
                'plugin_activate'       => __( 'Plugin Activated', 'mainwp' ),
                'plugin_deactivate'     => __( 'Plugin Deactivated', 'mainwp' ),
                'plugin_updated'        => __( 'Plugin Updated', 'mainwp' ),
                'plugin_delete'         => __( 'Plugin Deleted', 'mainwp' ),

                'translation_updated'   => __( 'Translation Updated', 'mainwp' ),
                'core_updated'          => __( 'WordPress Core Updated', 'mainwp' ),

                'post_created'          => __( 'Post Created', 'mainwp' ),
                'post_published'        => __( 'Post Published', 'mainwp' ),
                'post_unpublished'      => __( 'Post Unpublished', 'mainwp' ),
                'post_updated'          => __( 'Post Updated', 'mainwp' ),
                'post_trashed'          => __( 'Post Trashed', 'mainwp' ),
                'post_deleted'          => __( 'Post Deleted', 'mainwp' ),
                'post_restored'         => __( 'Post Restored', 'mainwp' ),

                'page_created'          => __( 'Page Created', 'mainwp' ),
                'page_published'        => __( 'Page Published', 'mainwp' ),
                'page_unpublished'      => __( 'Page Unpublished', 'mainwp' ),
                'page_updated'          => __( 'Page Updated', 'mainwp' ),
                'page_trashed'          => __( 'Page Trashed', 'mainwp' ),
                'page_deleted'          => __( 'Page Deleted', 'mainwp' ),
                'page_restored'         => __( 'Page Restored', 'mainwp' ),

                'clients_created'       => __( 'Client Created', 'mainwp' ),
                'clients_updated'       => __( 'Client Updated', 'mainwp' ),
                'clients_suspend'       => __( 'Client Suspended', 'mainwp' ),
                'clients_unsuspend'     => __( 'Client Unsuspended', 'mainwp' ),
                'clients_lead'          => __( 'Client Marked as Lead', 'mainwp' ),
                'clients_lost'          => __( 'Client Marked as Lost', 'mainwp' ),

                'users_created'         => __( 'User Created', 'mainwp' ),
                'users_update'          => __( 'User Updated', 'mainwp' ),
                'users_delete'          => __( 'User Deleted', 'mainwp' ),
                'users_change_role'     => __( 'User Role Changed', 'mainwp' ),
                'users_update_password' => __( 'Admin Password Updated', 'mainwp' ),
            ),
            'nonmainwpchanges' => array(
                'theme_install'     => __( 'Theme Installed', 'mainwp' ),
                'theme_activate'    => __( 'Theme Activated', 'mainwp' ),
                'theme_deactivate'  => __( 'Theme Deactivated', 'mainwp' ),
                'theme_updated'     => __( 'Theme Updated', 'mainwp' ),
                'theme_switch'      => __( 'Theme Switched', 'mainwp' ),
                'theme_delete'      => __( 'Theme Deleted', 'mainwp' ),
                'plugin_install'    => __( 'Plugin Installed', 'mainwp' ),
                'plugin_activate'   => __( 'Plugin Activated', 'mainwp' ),
                'plugin_deactivate' => __( 'Plugin Deactivated', 'mainwp' ),
                'plugin_updated'    => __( 'Plugin Updated', 'mainwp' ),
                'plugin_delete'     => __( 'Plugin Deleted', 'mainwp' ),
                'core_updated'      => __( 'WordPress Core Updated', 'mainwp' ),
            ),
        );

        $logs['changeslogs'] = static::get_changes_logs();

        if ( 'unlogs' === $type ) {
            return $init_un_logs;
        }

        return $logs;
    }

    /**
     * Loads all the events for the core and extentions
     *
     * @return array
     */
    public static function get_changes_logs() {

        $changes_default_logs = array(
            esc_html__( 'Defaults Logs', 'mainwp' ) => array(
                esc_html__( 'Post', 'mainwp' )           => array(
                    array(
                        2000,
                        esc_html__( 'Created a new post', 'mainwp' ),
                        esc_html__( 'Created the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'created',
                    ),
                    array(
                        2001,
                        esc_html__( 'Published a post', 'mainwp' ),
                        esc_html__( 'Published the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'published',
                    ),
                    array(
                        2002,
                        esc_html__( 'Modified a post', 'mainwp' ),
                        esc_html__( 'Modified the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2008,
                        esc_html__( 'Permanently deleted a post', 'mainwp' ),
                        esc_html__( 'Permanently deleted the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        array(),
                        'post',
                        'deleted',
                    ),
                    array(
                        2012,
                        esc_html__( 'Moved a post to trash', 'mainwp' ),
                        esc_html__( 'Moved the post %PostTitle% to trash.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'PostUrlIfPublished' ) ),
                        'post',
                        'deleted',
                    ),
                    array(
                        2014,
                        esc_html__( 'Restored a post from trash', 'mainwp' ),
                        esc_html__( 'Restored the post %PostTitle% from trash.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'restored',
                    ),
                    array(
                        2016,
                        esc_html__( 'Changed the category of a post', 'mainwp' ),
                        esc_html__( 'Changed the category(ies) of the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )                => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )              => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )            => '%PostStatus%',
                            esc_html__( 'New category(ies)', 'mainwp' )      => '%NewCategories%',
                            esc_html__( 'Previous category(ies)', 'mainwp' ) => '%OldCategories%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2017,
                        esc_html__( 'Changed the URL of a post', 'mainwp' ),
                        esc_html__( 'Changed the URL of the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )      => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )    => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )  => '%PostStatus%',
                            esc_html__( 'Previous URL', 'mainwp' ) => '%OldUrl%',
                            esc_html__( 'New URL', 'mainwp' )      => '%NewUrl%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2019,
                        esc_html__( 'Changed the author of a post', 'mainwp' ),
                        esc_html__( 'Changed the author of the post %PostTitle% to %NewAuthor%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )         => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )       => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )     => '%PostStatus%',
                            esc_html__( 'Previous author', 'mainwp' ) => '%OldAuthor%',
                        ),
                        static::ws_al_defaults_build_links( array( 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2021,
                        esc_html__( 'Changed the status of a post', 'mainwp' ),
                        esc_html__( 'Changed the status of the post %PostTitle% to %NewStatus%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )         => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )       => '%PostType%',
                            esc_html__( 'Previous status', 'mainwp' ) => '%OldStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2047,
                        esc_html__( 'Changed the parent of a post', 'mainwp' ),
                        esc_html__( 'Changed the parent of the post %PostTitle% to %NewParentName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )         => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )       => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )     => '%PostStatus%',
                            esc_html__( 'Previous parent', 'mainwp' ) => '%OldParentName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2048,
                        esc_html__( 'Changed the template of a post', 'mainwp' ),
                        esc_html__( 'Changed the template of the post %PostTitle% to %NewTemplate%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )           => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )         => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )       => '%PostStatus%',
                            esc_html__( 'Previous template', 'mainwp' ) => '%OldTemplate%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2049,
                        esc_html__( 'Set a post as Sticky', 'mainwp' ),
                        esc_html__( 'Set the post %PostTitle% as sticky.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2050,
                        esc_html__( 'Removed post from Sticky', 'mainwp' ),
                        esc_html__( 'Removed the post %PostTitle% from sticky.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2053,
                        esc_html__( 'Created a custom field in a post', 'mainwp' ),
                        esc_html__( 'Created the new custom field %MetaKey% in the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )            => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )          => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )        => '%PostStatus%',
                            esc_html__( 'Custom field value', 'mainwp' ) => '%MetaValue%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'MetaLink', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2073,
                        esc_html__( 'Submitted post for review', 'mainwp' ),
                        esc_html__( 'Submitted the post %PostTitle% for review.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2074,
                        esc_html__( 'Scheduled a post for publishing', 'mainwp' ),
                        esc_html__( 'Scheduled the post %PostTitle% to be published on %PublishingDate%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2025,
                        esc_html__( 'User changed the visibility of a post', 'mainwp' ),
                        esc_html__( 'Changed the visibility of the post %PostTitle% to %NewVisibility%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )                    => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )                  => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )                => '%PostStatus%',
                            esc_html__( 'Previous visibility status', 'mainwp' ) => '%OldVisibility%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2027,
                        esc_html__( 'Changed the date of a post', 'mainwp' ),
                        esc_html__( 'Changed the date of the post %PostTitle% to %NewDate%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )       => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )     => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )   => '%PostStatus%',
                            esc_html__( 'Previous date', 'mainwp' ) => '%OldDate%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2065,
                        esc_html__( 'Modified the content of a post', 'mainwp' ),
                        esc_html__( 'Modified the content of the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'RevisionLink', 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2086,
                        esc_html__( 'Changed title of a post', 'mainwp' ),
                        esc_html__( 'Changed the title of the post %OldTitle% to %NewTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2100,
                        esc_html__( 'Opened a post in editor', 'mainwp' ),
                        esc_html__( 'Opened the post %PostTitle% in the editor.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'opened',
                    ),
                    array(
                        2101,
                        esc_html__( 'Viewed a post', 'mainwp' ),
                        esc_html__( 'Viewed the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'PostUrl', 'EditorLinkPost' ) ),
                        'post',
                        'viewed',
                    ),
                    array(
                        2111,
                        esc_html__( 'Enabled / disabled comments in a post', 'mainwp' ),
                        esc_html__( 'Comments in the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'enabled',
                    ),
                    array(
                        2112,
                        esc_html__( 'Enabled / disabled trackbacks in a post', 'mainwp' ),
                        esc_html__( 'Pingbacks and Trackbacks in the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'enabled',
                    ),
                    array(
                        2119,
                        esc_html__( 'Added tag(s) to a post', 'mainwp' ),
                        esc_html__( 'Added tag(s) to the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'ID', 'mainwp' )   => '%PostID%',
                            esc_html__( 'Type', 'mainwp' ) => '%PostType%',
                            esc_html__( 'Status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Added tag(s)', 'mainwp' ) => '%tag%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2120,
                        esc_html__( 'Removed tag(s) from a post', 'mainwp' ),
                        esc_html__( 'Removed tag(s) from the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'ID', 'mainwp' )   => '%PostID%',
                            esc_html__( 'Type', 'mainwp' ) => '%PostType%',
                            esc_html__( 'Status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Removed tag(s)', 'mainwp' ) => '%tag%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2129,
                        esc_html__( 'Updated the excerpt of a post', 'mainwp' ),
                        esc_html__( 'The excerpt of the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )                => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )              => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )            => '%PostStatus%',
                            esc_html__( 'Previous excerpt entry', 'mainwp' ) => '%old_post_excerpt%',
                            esc_html__( 'New excerpt entry', 'mainwp' )      => '%post_excerpt%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    array(
                        2130,
                        esc_html__( 'Updated the feature image of a post', 'mainwp' ),
                        esc_html__( 'The featured image of the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )        => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )      => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )    => '%PostStatus%',
                            esc_html__( 'Previous image', 'mainwp' ) => '%previous_image%',
                            esc_html__( 'New image', 'mainwp' )      => '%new_image%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                    // Post 9043 - Added / changed / removed a post’s featured image
                    array(
                        2133,
                        esc_html__( 'Taken over a post from another user', 'mainwp' ),
                        esc_html__( 'Has taken over the post %PostTitle% from %user%', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )        => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )      => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )    => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'post',
                        'modified',
                    ),
                ),
                esc_html__( 'Custom field', 'mainwp' )   => array(
                    array(
                        2131,
                        esc_html__( 'Added a relationship in an ACF custom field', 'mainwp' ),
                        esc_html__( 'Added relationships to the custom field %MetaKey% in the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )           => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )         => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )       => '%PostStatus%',
                            esc_html__( 'New relationships', 'mainwp' ) => '%Relationships%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'MetaLink' ) ),
                        'custom-field',
                        'modified',
                    ),
                    array(
                        2132,
                        esc_html__( 'Removed a relationship from an ACF custom field', 'mainwp' ),
                        esc_html__( 'Removed relationships from the custom field %MetaKey% in the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )               => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )             => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )           => '%PostStatus%',
                            esc_html__( 'Removed relationships', 'mainwp' ) => '%Relationships%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'MetaLink' ) ),
                        'custom-field',
                        'modified',
                    ),
                    array(
                        2054,
                        esc_html__( 'Changed the value of a custom field', 'mainwp' ),
                        esc_html__( 'Modified the value of the custom field %MetaKey% in the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )                     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )                   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' )                 => '%PostStatus%',
                            esc_html__( 'Previous custom field value', 'mainwp' ) => '%MetaValueOld%',
                            esc_html__( 'New custom field value', 'mainwp' )      => '%MetaValueNew%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'MetaLink', 'PostUrlIfPublished' ) ),
                        'custom-field',
                        'modified',
                    ),
                    array(
                        2055,
                        esc_html__( 'Deleted a custom field', 'mainwp' ),
                        esc_html__( 'Deleted the custom field %MetaKey% from the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'custom-field',
                        'deleted',
                    ),
                    array(
                        2062,
                        esc_html__( 'Renamed a custom field', 'mainwp' ),
                        esc_html__( 'Renamed the custom field %MetaKeyOld% on post %PostTitle% to %MetaKeyNew%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post', 'mainwp' ) => '%PostTitle%',
                            esc_html__( 'Post ID', 'mainwp' ) => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' ) => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditorLinkPost', 'PostUrlIfPublished' ) ),
                        'custom-field',
                        'renamed',
                    ),
                ),
                esc_html__( 'Categories', 'mainwp' )     => array(
                    array(
                        2023,
                        esc_html__( 'Created a new category', 'mainwp' ),
                        esc_html__( 'Created the category %CategoryName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Slug', 'mainwp' ) => 'Slug',
                        ),
                        static::ws_al_defaults_build_links( array( 'CategoryLink' ) ),
                        'category',
                        'created',
                    ),
                    array(
                        2024,
                        esc_html__( 'Deleted a category', 'mainwp' ),
                        esc_html__( 'Deleted the category %CategoryName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Slug', 'mainwp' ) => 'Slug',
                        ),
                        array(),
                        'category',
                        'deleted',
                    ),
                    array(
                        2052,
                        esc_html__( 'Changed the parent of a category', 'mainwp' ),
                        esc_html__( 'Changed the parent of the category %CategoryName% to %NewParent%.', 'mainwp' ),
                        array(
                            esc_html__( 'Slug', 'mainwp' ) => '%Slug%',
                            esc_html__( 'Previous parent', 'mainwp' ) => '%OldParent%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CategoryLink' ) ),
                        'category',
                        'modified',
                    ),
                    array(
                        2127,
                        esc_html__( 'Renamed a category', 'mainwp' ),
                        esc_html__( 'Renamed the category %old_name% to %new_name%.', 'mainwp' ),
                        array(
                            esc_html__( 'Slug', 'mainwp' ) => '%slug%',
                        ),
                        static::ws_al_defaults_build_links( array( 'cat_link' ) ),
                        'category',
                        'renamed',
                    ),
                    array(
                        2128,
                        esc_html__( 'Renamed a category', 'mainwp' ),
                        esc_html__( 'Changed the slug of the category %CategoryName% to %new_slug%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous slug', 'mainwp' ) => '%old_slug%',
                        ),
                        static::ws_al_defaults_build_links( array( 'cat_link' ) ),
                        'category',
                        'modified',
                    ),
                ),
                esc_html__( 'Tag', 'mainwp' )            => array(
                    array(
                        2121,
                        esc_html__( 'Created a new tag', 'mainwp' ),
                        esc_html__( 'Created the tag %TagName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Slug', 'mainwp' ) => 'Slug',
                        ),
                        static::ws_al_defaults_build_links( array( 'TagLink' ) ),
                        'tag',
                        'created',
                    ),
                    array(
                        2122,
                        esc_html__( 'Deleted a tag', 'mainwp' ),
                        esc_html__( 'Deleted the tag %TagName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Slug', 'mainwp' ) => 'Slug',
                        ),
                        array(),
                        'tag',
                        'deleted',
                    ),
                    array(
                        2123,
                        esc_html__( 'Renamed the tag %old_name% to %new_name%.', 'mainwp' ),
                        '',
                        array(
                            esc_html__( 'Slug', 'mainwp' ) => '%Slug%',
                        ),
                        static::ws_al_defaults_build_links( array( 'TagLink' ) ),
                        'tag',
                        'renamed',
                    ),
                    array(
                        2124,
                        esc_html__( 'Changed the slug of a tag', 'mainwp' ),
                        esc_html__( 'Changed the slug of the tag %tag% to %new_slug%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous slug', 'mainwp' ) => '%old_slug%',
                        ),
                        static::ws_al_defaults_build_links( array( 'TagLink' ) ),
                        'tag',
                        'modified',
                    ),
                    array(
                        2125,
                        esc_html__( 'Changed the description of a tag', 'mainwp' ),
                        esc_html__( 'Changed the description of the tag %tag%.', 'mainwp' ),
                        array(
                            esc_html__( 'Slug', 'mainwp' ) => '%Slug%',
                            esc_html__( 'Previous description', 'mainwp' ) => '%old_desc%',
                            esc_html__( 'New description', 'mainwp' ) => '%new_desc%',
                        ),
                        static::ws_al_defaults_build_links( array( 'TagLink' ) ),
                        'tag',
                        'modified',
                    ),
                ),
                esc_html__( 'File', 'mainwp' )           => array(
                    array(
                        2010,
                        esc_html__( 'Uploaded a file', 'mainwp' ),
                        esc_html__( 'Uploaded a file called %FileName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Directory', 'mainwp' ) => '%FilePath%',
                        ),
                        static::ws_al_defaults_build_links( array( 'AttachmentUrl' ) ),
                        'file',
                        'uploaded',
                    ),
                    array(
                        2011,
                        esc_html__( 'Deleted a file', 'mainwp' ),
                        esc_html__( 'Deleted the file %FileName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Directory', 'mainwp' ) => '%FilePath%',
                        ),
                        array(),
                        'file',
                        'deleted',
                    ),
                ),
                esc_html__( 'Widget', 'mainwp' )         => array(
                    array(
                        2042,
                        esc_html__( 'Added a new widget', 'mainwp' ),
                        esc_html__( 'Added a new %WidgetName% widget in %Sidebar%.', 'mainwp' ),
                        array(),
                        array(),
                        'widget',
                        'added',
                    ),
                    array(
                        2043,
                        esc_html__( 'Modified a widget', 'mainwp' ),
                        esc_html__( 'Modified the %WidgetName% widget in %Sidebar%.', 'mainwp' ),
                        array(),
                        array(),
                        'widget',
                        'modified',
                    ),
                    array(
                        2044,
                        esc_html__( 'Deleted a widget', 'mainwp' ),
                        esc_html__( 'Deleted the %WidgetName% widget from %Sidebar%.', 'mainwp' ),
                        array(),
                        array(),
                        'widget',
                        'deleted',
                    ),
                    array(
                        2045,
                        esc_html__( 'Moved a widget in between sections', 'mainwp' ),
                        esc_html__( 'Moved the %WidgetName% widget.', 'mainwp' ),
                        array(
                            esc_html__( 'From', 'mainwp' ) => '%OldSidebar%',
                            esc_html__( 'To', 'mainwp' )   => '%NewSidebar%',
                        ),
                        array(),
                        'widget',
                        'modified',
                    ),
                    array(
                        2071,
                        esc_html__( 'Changed the position of a widget in a section', 'mainwp' ),
                        esc_html__( 'Changed the position of the %WidgetName% widget in %Sidebar%.', 'mainwp' ),
                        array(),
                        array(),
                        'widget',
                        'modified',
                    ),
                ),
                esc_html__( 'Plugin', 'mainwp' )         => array(
                    array(
                        2051,
                        esc_html__( 'Modified a file with the plugin editor', 'mainwp' ),
                        esc_html__( 'Modified the file %File% with the plugin editor.', 'mainwp' ),
                        array(),
                        array(),
                        'file',
                        'modified',
                    ),
                    array(
                        5028,
                        esc_html__( 'The automatic updates setting for a plugin was changed.', 'mainwp' ),
                        esc_html__( 'Changed the Automatic updates setting for the plugin %name%.', 'mainwp' ),
                        array(
                            esc_html__( 'Install location', 'mainwp' )     => '%install_directory%',
                        ),
                        array(),
                        'plugin',
                        'enabled',
                    ),
                ),
                esc_html__( 'Theme', 'mainwp' )          => array(
                    array(
                        2046,
                        esc_html__( 'Modified a file with the theme editor', 'mainwp' ),
                        esc_html__( 'Modified the file %Theme%/%File% with the theme editor.', 'mainwp' ),
                        array(),
                        array(),
                        'file',
                        'modified',
                    ),
                    array(
                        5029,
                        esc_html__( 'The automatic updates setting for a theme was changed.', 'mainwp' ),
                        esc_html__( 'Changed the Automatic updates setting for the theme %name%.', 'mainwp' ),
                        array(
                            esc_html__( 'Install location', 'mainwp' )     => '%install_directory%',
                        ),
                        array(),
                        'theme',
                        'enabled',
                    ),
                ),
                esc_html__( 'Menu', 'mainwp' )           => array(
                    array(
                        2078,
                        esc_html__( 'Created a menu', 'mainwp' ),
                        esc_html__( 'New menu called %MenuName%.', 'mainwp' ),
                        array(),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'created',
                    ),
                    array(
                        2079,
                        esc_html__( 'Added item(s) to a menu', 'mainwp' ),
                        esc_html__( 'Added the item %ContentName% to the menu %MenuName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Item type', 'mainwp' ) => '%ContentType%',
                        ),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                    array(
                        2080,
                        esc_html__( 'Removed item(s) from a menu', 'mainwp' ),
                        esc_html__( 'Removed the item %ContentName% from the menu %MenuName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Item type', 'mainwp' ) => '%ContentType%',
                        ),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                    array(
                        2081,
                        esc_html__( 'Deleted a menu', 'mainwp' ),
                        esc_html__( 'Deleted the menu %MenuName%.', 'mainwp' ),
                        array(),
                        array(),
                        'menu',
                        'deleted',
                    ),
                    array(
                        2082,
                        esc_html__( 'Changed the settings of a menu', 'mainwp' ),
                        esc_html__( 'The setting %MenuSetting% in the menu %MenuName%.', 'mainwp' ),
                        array(),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'enabled',
                    ),
                    array(
                        2083,
                        esc_html__( 'Modified the item(s) in a menu', 'mainwp' ),
                        esc_html__( 'Modified the item %ContentName% in the menu %MenuName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Item type', 'mainwp' ) => '%ContentType%',
                        ),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                    array(
                        2084,
                        esc_html__( 'Renamed a menu', 'mainwp' ),
                        esc_html__( 'Renamed the menu %OldMenuName% to %MenuName%.', 'mainwp' ),
                        array(),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'renamed',
                    ),
                    array(
                        2085,
                        esc_html__( 'Changed the order of the objects in a menu.', 'mainwp' ),
                        esc_html__( 'Changed the order of the items in the menu %MenuName%.', 'mainwp' ),
                        array(),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                    array(
                        2089,
                        esc_html__( 'Moved an item as a sub-item in a menu', 'mainwp' ),
                        esc_html__( 'Moved items as sub-items in the menu %MenuName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Moved item', 'mainwp' )       => '%ItemName%',
                            esc_html__( 'as a sub-item of', 'mainwp' ) => '%ParentName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'MenuUrl' ) ),
                        'menu',
                        'modified',
                    ),
                ),
                esc_html__( 'Comment', 'mainwp' )        => array(
                    array(
                        2090,
                        esc_html__( 'Approved a comment', 'mainwp' ),
                        esc_html__( 'Approved the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'approved',
                    ),
                    array(
                        2091,
                        esc_html__( 'Unapproved a comment', 'mainwp' ),
                        esc_html__( 'Unapproved the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'unapproved',
                    ),
                    array(
                        2092,
                        esc_html__( 'Replied to a comment', 'mainwp' ),
                        esc_html__( 'Replied to the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'created',
                    ),
                    array(
                        2093,
                        esc_html__( 'Edited a comment', 'mainwp' ),
                        esc_html__( 'Edited the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'modified',
                    ),
                    array(
                        2094,
                        esc_html__( 'Marked a comment as spam', 'mainwp' ),
                        esc_html__( 'Marked the comment posted by %Author% on the post %PostTitle% as spam.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'unapproved',
                    ),
                    array(
                        2095,
                        esc_html__( 'Marked a comment as not spam', 'mainwp' ),
                        esc_html__( 'Marked the comment posted by %Author% on the post %PostTitle% as not spam.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'approved',
                    ),
                    array(
                        2096,
                        esc_html__( 'Moved a comment to trash', 'mainwp' ),
                        esc_html__( 'Moved the comment posted by %Author% on the post %PostTitle% to trash.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'deleted',
                    ),
                    array(
                        2097,
                        esc_html__( 'Restored a comment from the trash', 'mainwp' ),
                        esc_html__( 'Restored the comment posted by %Author% on the post %PostTitle% from trash.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'restored',
                    ),
                    array(
                        2098,
                        esc_html__( 'Permanently deleted a comment', 'mainwp' ),
                        esc_html__( 'Permanently deleted the comment posted by %Author% on the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'PostUrlIfPublished' ) ),
                        'comment',
                        'deleted',
                    ),
                    array(
                        2099,
                        esc_html__( 'Posted a comment', 'mainwp' ),
                        esc_html__( 'Posted a comment on the post %PostTitle%.', 'mainwp' ),
                        array(
                            esc_html__( 'Post ID', 'mainwp' )     => '%PostID%',
                            esc_html__( 'Post type', 'mainwp' )   => '%PostType%',
                            esc_html__( 'Post status', 'mainwp' ) => '%PostStatus%',
                            esc_html__( 'Comment ID', 'mainwp' )  => '%CommentID%',
                        ),
                        static::ws_al_defaults_build_links( array( 'CommentLink', 'PostUrlIfPublished' ) ),
                        'comment',
                        'created',
                    ),
                ),
                esc_html__( 'User', 'mainwp' )           => array(
                    array(
                        1000,
                        esc_html__( 'Successfully logged in', 'mainwp' ),
                        esc_html__( 'User logged in.', 'mainwp' ),
                        array(),
                        array(),
                        'user',
                        'login',
                    ),
                    array(
                        1001,
                        esc_html__( 'Successfully logged out', 'mainwp' ),
                        esc_html__( 'User logged out.', 'mainwp' ),
                        array(),
                        array(),
                        'user',
                        'logout',
                    ),
                    array(
                        1005,
                        esc_html__( 'Successful log in but other sessions exist for user', 'mainwp' ),
                        esc_html__( 'User logged in however there are other session(s) already for this user.', 'mainwp' ),
                        array(
                            esc_html__( 'IP address(es)', 'mainwp' ) => '%IPAddress%',
                        ),
                        array(),
                        'user',
                        'login',
                    ),
                    array(
                        1006,
                        esc_html__( 'Logged out all other sessions with same user', 'mainwp' ),
                        esc_html__( 'Logged out all other sessions with the same user.', 'mainwp' ),
                        array(),
                        array(),
                        'user',
                        'logout',
                    ),
                    array(
                        1009,
                        esc_html__( 'Terminated a user session', 'mainwp' ),
                        esc_html__( 'The plugin terminated an idle session for the user %username%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%TargetUserRole%',
                            esc_html__( 'Session ID', 'mainwp' ) => '%SessionID%',
                        ),
                        array(),
                        'user',
                        'logout',
                    ),
                    array(
                        1008,
                        esc_html__( 'Switched to another user', 'mainwp' ),
                        esc_html__( 'Switched the session to being logged in as %TargetUserName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%TargetUserRole%',
                        ),
                        array(),
                        'user',
                        'login',
                    ),
                    array(
                        1010,
                        esc_html__( 'User requested a password reset', 'mainwp' ),
                        esc_html__( 'User requested a password reset. This does not mean that the password was changed.', 'mainwp' ),
                        array(),
                        array(),
                        'user',
                        'submitted',
                    ),
                    array(
                        4000,
                        esc_html__( 'A new user was created', 'mainwp' ),
                        __( 'A new user %NewUserData->Username% is created via registration.', 'mainwp' ),
                        array(
                            esc_html__( 'User', 'mainwp' ) => '%NewUserData->Username%',
                            esc_html__( 'Email', 'mainwp' ) => '%NewUserData->Email%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'created',
                    ),
                    array(
                        4001,
                        esc_html__( 'User created a new user', 'mainwp' ),
                        __( 'Created the new user: %NewUserData->Username%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%NewUserData->Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%NewUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%NewUserData->LastName%',
                            esc_html__( 'Email', 'mainwp' ) => '%NewUserData->Email%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'created',
                    ),
                    array(
                        4002,
                        esc_html__( 'Change the role of a user', 'mainwp' ),
                        esc_html__( 'Changed the role of user %TargetUsername% to %NewRole%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous role', 'mainwp' ) => '%OldRole%',
                            esc_html__( 'First name', 'mainwp' )    => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' )     => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4003,
                        esc_html__( 'Changed the password', 'mainwp' ),
                        esc_html__( 'Changed the password.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%TargetUserData->Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%TargetUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%TargetUserData->LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4004,
                        esc_html__( 'Changed the password of a user', 'mainwp' ),
                        __( 'Changed the password of the user %TargetUserData->Username%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%TargetUserData->Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%TargetUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%TargetUserData->LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4005,
                        esc_html__( 'Changed the email address', 'mainwp' ),
                        esc_html__( 'Changed the email address to %NewEmail%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                            esc_html__( 'Previous email address', 'mainwp' ) => '%OldEmail%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4006,
                        esc_html__( 'Changed the email address of a user', 'mainwp' ),
                        esc_html__( 'Changed the email address of the user %TargetUsername% to %NewEmail%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                            esc_html__( 'Previous email address', 'mainwp' ) => '%OldEmail%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4007,
                        esc_html__( 'Deleted a user', 'mainwp' ),
                        __( 'Deleted the user %TargetUserData->Username%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%TargetUserData->Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%NewUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%NewUserData->LastName%',
                        ),
                        array(),
                        'user',
                        'deleted',
                    ),
                    array(
                        4008,
                        esc_html__( 'Granted super admin privileges to a user', 'mainwp' ),
                        esc_html__( 'Granted Super Admin privileges to the user %TargetUsername%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4009,
                        esc_html__( 'Revoked super admin privileges from a user', 'mainwp' ),
                        esc_html__( 'Revoked Super Admin privileges from %TargetUsername%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4012,
                        esc_html__( 'Added a network user to a site', 'mainwp' ),
                        __( 'Created the new network user %NewUserData->Username%.', 'mainwp' ),
                        array(
                            esc_html__( 'First name', 'mainwp' ) => '%NewUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp' )  => '%NewUserData->LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'created',
                    ),
                    array(
                        4011,
                        esc_html__( 'Removed a network user from a site', 'mainwp' ),
                        esc_html__( 'Removed user %TargetUsername% from the site %SiteName%', 'mainwp' ),
                        array(
                            esc_html__( 'Site role', 'mainwp' )  => '%TargetUserRole%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' )  => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4010,
                        esc_html__( 'Created a new network user', 'mainwp' ),
                        esc_html__( 'Added user %TargetUsername% to the site %SiteName%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%TargetUserRole%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4013,
                        esc_html__( 'User has been activated on the network', 'mainwp' ),
                        __( 'User %NewUserData->Username% has been activated.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%NewUserData->Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%NewUserData->FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%NewUserData->LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'activated',
                    ),
                    array(
                        4014,
                        esc_html__( 'Opened the profile page of a user', 'mainwp' ),
                        esc_html__( 'Opened the profile page of user %TargetUsername%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'opened',
                    ),
                    array(
                        4015,
                        esc_html__( 'Changed a custom field value in user profile', 'mainwp' ),
                        esc_html__( 'Changed the value of the custom field %custom_field_name% in the user profile %TargetUsername%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                            esc_html__( 'Previous value', 'mainwp' ) => '%old_value%',
                            esc_html__( 'New value', 'mainwp' ) => '%new_value%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink', 'MetaLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4016,
                        esc_html__( 'Created a custom field in a user profile', 'mainwp' ),
                        esc_html__( 'Created the custom field %custom_field_name% in the user profile %TargetUsername%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                            esc_html__( 'Custom field value', 'mainwp' ) => '%new_value%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink', 'MetaLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4017,
                        esc_html__( 'Changed the first name (of a user)', 'mainwp' ),
                        esc_html__( 'Changed the first name of the user %TargetUsername% to %new_firstname%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'Previous name', 'mainwp' ) => '%old_firstname%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4018,
                        esc_html__( 'Changed the last name (of a user)', 'mainwp' ),
                        esc_html__( 'Changed the last name of the user %TargetUsername% to %new_lastname%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Previous last name', 'mainwp' ) => '%old_lastname%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4019,
                        esc_html__( 'Changed the nickname (of a user)', 'mainwp' ),
                        esc_html__( 'Changed the nickname of the user %TargetUsername% to %new_nickname%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                            esc_html__( 'Previous nickname', 'mainwp' ) => '%old_nickname%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4020,
                        esc_html__( 'Changed the display name (of a user)', 'mainwp' ),
                        esc_html__( 'Changed the display name of the user %TargetUsername% to %new_displayname%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                            esc_html__( 'Previous display name', 'mainwp' ) => '%old_displayname%',
                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4021,
                        esc_html__( 'Changed the website URL of the user', 'mainwp' ),
                        esc_html__( 'Changed the website URL of the user %TargetUsername% to %new_url%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%Roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%FirstName%',
                            esc_html__( 'Last name', 'mainwp' ) => '%LastName%',
                            esc_html__( 'Previous website URL', 'mainwp' ) => '%old_url%',

                        ),
                        static::ws_al_defaults_build_links( array( 'EditUserLink' ) ),
                        'user',
                        'modified',
                    ),
                    array(
                        4025,
                        esc_html__( 'User added / removed application password from own profile', 'mainwp' ),
                        esc_html__( 'The application password %friendly_name%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%firstname%',
                            esc_html__( 'Last name', 'mainwp' ) => '%lastname%',
                        ),
                        array(),
                        'user',
                        'added',
                    ),
                    array(
                        4026,
                        esc_html__( 'User added / removed application password from another user’s profile', 'mainwp' ),
                        esc_html__( 'The application password %friendly_name% for the user %login%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%firstname%',
                            esc_html__( 'Last name', 'mainwp' ) => '%lastname%',
                        ),
                        array(),
                        'user',
                        'added',
                    ),
                    array(
                        4028,
                        esc_html__( 'User revoked all application passwords from own profile', 'mainwp' ),
                        esc_html__( 'All application passwords from the user %login%.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%firstname%',
                            esc_html__( 'Last name', 'mainwp' ) => '%lastname%',
                        ),
                        array(),
                        'user',
                        'revoked',
                    ),
                    array(
                        4027,
                        esc_html__( 'User revoked all application passwords from another user’s profile', 'mainwp' ),
                        esc_html__( 'All application passwords.', 'mainwp' ),
                        array(
                            esc_html__( 'Role', 'mainwp' ) => '%roles%',
                            esc_html__( 'First name', 'mainwp' ) => '%firstname%',
                            esc_html__( 'Last name', 'mainwp' ) => '%lastname%',
                        ),
                        array(),
                        'user',
                        'revoked',
                    ),
                ),
                esc_html__( 'Database', 'mainwp' )       => array(
                    array(
                        5010,
                        esc_html__( 'Plugin created database table(s)', 'mainwp' ),
                        __( 'The plugin %Plugin->Name% created this table in the database.', 'mainwp' ),
                        array(
                            esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'created',
                    ),
                    array(
                        5011,
                        esc_html__( 'Plugin modified the structure of database table(s)', 'mainwp' ),
                        __( 'The plugin %Plugin->Name% modified the structure of a database table.', 'mainwp' ),
                        array(
                            esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'modified',
                    ),
                    array(
                        5012,
                        esc_html__( 'Plugin deleted database table(s)', 'mainwp' ),
                        __( 'The plugin %Plugin->Name% deleted this table from the database.', 'mainwp' ),
                        array(
                            esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'deleted',
                    ),
                    array(
                        5013,
                        esc_html__( 'Theme created database table(s)', 'mainwp' ),
                        __( 'The theme %Theme->Name% created this tables in the database.', 'mainwp' ),
                        array(
                            esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'created',
                    ),
                    array(
                        5014,
                        esc_html__( 'Theme modified the structure of table(s) in the database', 'mainwp' ),
                        __( 'The theme %Theme->Name% modified the structure of this database table', 'mainwp' ),
                        array(
                            esc_html__( 'Table', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'modified',
                    ),
                    array(
                        5015,
                        esc_html__( 'Theme deleted database table(s)', 'mainwp' ),
                        __( 'The theme %Theme->Name% deleted this table from the database.', 'mainwp' ),
                        array(
                            esc_html__( 'Tables', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'deleted',
                    ),
                    array(
                        5016,
                        esc_html__( 'Unknown component created database table(s)', 'mainwp' ),
                        esc_html__( 'An unknown component created these tables in the database.', 'mainwp' ),
                        array(
                            esc_html__( 'Tables', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'created',
                    ),
                    array(
                        5017,
                        esc_html__( 'Unknown component modified the structure of table(s )in the database', 'mainwp' ),
                        esc_html__( 'An unknown component modified the structure of these database tables.', 'mainwp' ),
                        array(
                            esc_html__( 'Tables', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'modified',
                    ),
                    array(
                        5018,
                        esc_html__( 'Unknown component deleted database table(s)', 'mainwp' ),
                        esc_html__( 'An unknown component deleted these tables from the database.', 'mainwp' ),
                        array(
                            esc_html__( 'Tables', 'mainwp' ) => '%TableNames%',
                        ),
                        array(),
                        'database',
                        'deleted',
                    ),
                ),
                esc_html__( 'System setting', 'mainwp' ) => array(
                    array(
                        6001,
                        esc_html__( 'Changed the option anyone can register', 'mainwp' ),
                        __( 'The <strong>Membership</strong> setting <strong>Anyone can register</strong>.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6002,
                        esc_html__( 'Changed the new user default role', 'mainwp' ),
                        __( 'Changed the <strong>New user default role</strong> WordPress setting.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous role', 'mainwp' ) => '%OldRole%',
                            esc_html__( 'New role', 'mainwp' )      => '%NewRole%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6003,
                        esc_html__( 'Changed the WordPress administrator notification email address', 'mainwp' ),
                        __( 'Change the <strong>Administrator email address</strong> in the WordPress settings.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous address', 'mainwp' ) => '%OldEmail%',
                            esc_html__( 'New address', 'mainwp' )      => '%NewEmail%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6005,
                        esc_html__( 'Changed the WordPress permalinks', 'mainwp' ),
                        __( 'Changed the <strong>WordPress permalinks</strong>.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous permalinks', 'mainwp' ) => '%OldPattern%',
                            esc_html__( 'New permalinks', 'mainwp' )      => '%NewPattern%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6008,
                        esc_html__( 'Changed the setting: Discourage search engines from indexing this site', 'mainwp' ),
                        __( 'Changed the status of the WordPress setting <strong>Search engine visibility</strong> (Discourage search engines from indexing this site)', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6009,
                        esc_html__( 'Enabled / disabled comments on the website', 'mainwp' ),
                        __( 'Changed the status of the WordPress setting <strong>Allow people to submit comments on new posts</strong>.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),

                    array(
                        6010,
                        esc_html__( 'Changed the setting: Comment author must fill out name and email', 'mainwp' ),
                        __( 'Changed the status of the WordPress setting <strong>.Comment author must fill out name and email</strong>.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6011,
                        esc_html__( 'Changed the setting: Users must be logged in and registered to comment', 'mainwp' ),
                        __( 'Changed the status of the WordPress setting <strong>Users must be registered and logged in to comment</strong>.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6012,
                        esc_html__( 'Changed the setting: Automatically close comments after a number of days', 'mainwp' ),
                        __( 'Changed the status of the WordPress setting <strong>Automatically close comments after %Value% days</strong>.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6013,
                        esc_html__( 'Changed the value of the setting: Automatically close comments after a number of days.', 'mainwp' ),
                        __( 'Changed the value of the WordPress setting <strong>Automatically close comments after a number of days</strong> to %NewValue%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous value', 'mainwp' ) => '%OldValue%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6014,
                        esc_html__( 'Changed the setting: Comments must be manually approved', 'mainwp' ),
                        __( 'Changed the value of the WordPress setting <strong>Comments must be manualy approved</strong>.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6015,
                        esc_html__( 'Changed the setting: Author must have previously approved comments for the comments to appear', 'mainwp' ),
                        __( 'Changed the value of the WordPress setting <strong>Comment author must have a previously approved comment</strong>.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'enabled',
                    ),
                    array(
                        6016,
                        esc_html__( 'Changed the minimum number of links that a comment must have to be held in the queue', 'mainwp' ),
                        __( 'Changed the value of the WordPress setting <strong>Hold a comment in the queue if it contains links</strong> to %NewValue% links.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous value', 'mainwp' ) => '%OldValue%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6017,
                        esc_html__( 'Modified the list of keywords for comments moderation', 'mainwp' ),
                        esc_html__( 'Modified the list of keywords for comments moderation in WordPress.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6018,
                        esc_html__( 'Modified the list of keywords for comments blacklisting', 'mainwp' ),
                        __( 'Modified the list of <strong>Disallowed comment keys</strong> (keywords) for comments blacklisting in WordPress.', 'mainwp' ),
                        array(),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6024,
                        esc_html__( 'Changed the WordPress address (URL)', 'mainwp' ),
                        __( 'Changed the <strong>WordPress address (URL)</strong> to %new_url%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous URL', 'mainwp' ) => '%old_url%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6025,
                        esc_html__( 'Changed the site address (URL)', 'mainwp' ),
                        __( 'Changed the <strong>Site address (URL)</strong> to %new_url%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous URL', 'mainwp' ) => '%old_url%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6035,
                        esc_html__( 'Changed the “Your homepage displays” WordPress setting', 'mainwp' ),
                        __( 'Changed the <strong>Your homepage displays</strong> WordPress setting to %new_homepage%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous setting', 'mainwp' ) => '%old_homepage%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6036,
                        esc_html__( 'Changed the homepage in the WordPress setting', 'mainwp' ),
                        __( 'Changed the <strong>Homepage</strong> in the WordPress settings to %new_page%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous page', 'mainwp' ) => '%old_page%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6037,
                        esc_html__( 'Changed the posts page in the WordPress settings', 'mainwp' ),
                        __( 'Changed the <strong> Posts</strong>  page in the WordPress settings to %new_page%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous page', 'mainwp' ) => '%old_page%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),

                    array(
                        6040,
                        esc_html__( 'Changed the Timezone in the WordPress settings', 'mainwp' ),
                        __( 'Changed the <strong>Timezone</strong> in the WordPress settings to %new_timezone%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous timezone', 'mainwp' ) => '%old_timezone%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6041,
                        esc_html__( 'Changed the Date format in the WordPress settings', 'mainwp' ),
                        __( 'Changed the <strong>Date format</strong> in the WordPress settings to %new_date_format%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous format', 'mainwp' ) => '%old_date_format%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6042,
                        esc_html__( 'Changed the Time format in the WordPress settings', 'mainwp' ),
                        __( 'Changed the <strong>Time format</strong> in the WordPress settings to %new_time_format%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous format', 'mainwp' ) => '%old_time_format%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6044,
                        esc_html__( 'User changed the WordPress automatic update settings', 'mainwp' ),
                        __( 'Changed the <strong>Automatic updates</strong> setting.', 'mainwp' ),
                        array(
                            esc_html__( 'New setting status', 'mainwp' ) => '%updates_status%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6045,
                        esc_html__( 'Changed the site language', 'mainwp' ),
                        __( 'Changed the <strong>Site Language</strong> to %new_value%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous setting', 'mainwp' ) => '%previous_value%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6059,
                        esc_html__( 'Changed the site title', 'mainwp' ),
                        __( 'Changed the <strong>Site Title</strong> to %new_value%.', 'mainwp' ),
                        array(
                            esc_html__( 'Previous setting', 'mainwp' ) => '%previous_value%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                    array(
                        6063,
                        esc_html__( 'Added site icon', 'mainwp' ),
                        __( 'Added a new website Site Icon %filename%.', 'mainwp' ),
                        array(
                            esc_html__( 'New directory', 'mainwp' ) => '%new_path%',
                        ),
                        array(),
                        'system-setting',
                        'added',
                    ),
                    array(
                        6064,
                        esc_html__( 'Changed site icon', 'mainwp' ),
                        __( 'Changed the Site Icon from %old_filename% to %filename%.', 'mainwp' ),
                        array(
                            esc_html__( 'Old directory', 'mainwp' ) => '%old_path%',
                            esc_html__( 'New directory', 'mainwp' ) => '%new_path%',
                        ),
                        array(),
                        'system-setting',
                        'modified',
                    ),
                ),
                esc_html__( 'WordPress Cron', 'mainwp' ) => array(
                    array(
                        6066,
                        esc_html__( 'New one time task (cron job) created', 'mainwp' ),
                        __( 'A new one-time task called %task_name% has been scheduled.', 'mainwp' ),
                        array(
                            esc_html__( 'The task is scheduled to run on', 'mainwp' ) => '%timestamp%',
                        ),
                        array(),
                        'cron-job',
                        'created',
                    ),
                    array(
                        6067,
                        esc_html__( 'New recurring task (cron job) created', 'mainwp' ),
                        __( 'A new recurring task (cron job) called %task_name% has been created.', 'mainwp' ),
                        array(
                            esc_html__( 'Task\'s first run: ', 'mainwp' ) => '%timestamp%',
                            esc_html__( 'Task\'s interval: ', 'mainwp' ) => '%display_name%',
                        ),
                        array(),
                        'cron-job',
                        'created',
                    ),
                    array(
                        6068,
                        esc_html__( 'Recurring task (cron job) modified', 'mainwp' ),
                        __( 'The schedule of recurring task (cron job) called %task_name% has changed.', 'mainwp' ),
                        array(
                            esc_html__( 'Task\'s old schedule: ', 'mainwp' ) => '%old_display_name%',
                            esc_html__( 'Task\'s new schedule: ', 'mainwp' ) => '%new_display_name%',
                        ),
                        array(),
                        'cron-job',
                        'modified',
                    ),
                    array(
                        6069,
                        esc_html__( 'One time task (cron job) executed', 'mainwp' ),
                        __( 'The one-time task called %task_name% has been executed.', 'mainwp' ),
                        array(
                            esc_html__( 'Task\'s schedule was: ', 'mainwp' ) => '%timestamp%',
                        ),
                        array(),
                        'cron-job',
                        'executed',
                    ),
                    array(
                        6070,
                        esc_html__( 'Recurring task (cron job) executed', 'mainwp' ),
                        __( ' The recurring task (cron job) called %task_name% has been executed.', 'mainwp' ),
                        array(
                            esc_html__( 'Task\'s schedule was: ', 'mainwp' ) => '%display_name%',
                        ),
                        array(),
                        'cron-job',
                        'executed',
                    ),
                    array(
                        6071,
                        esc_html__( 'Deleted one-time task (cron job)', 'mainwp' ),
                        __( 'The one-time task  (cron job) called %task_name% has been deleted.', 'mainwp' ),
                        array(),
                        array(),
                        'cron-job',
                        'deleted',
                    ),
                    array(
                        6072,
                        esc_html__( 'Deleted recurring task (cron job)', 'mainwp' ),
                        __( 'The recurring task (cron job) called %task_name% has been deleted.', 'mainwp' ),
                        array(
                            esc_html__( 'Task\'s schedule was: ', 'mainwp' ) => '%display_name%',
                        ),
                        array(),
                        'cron-job',
                        'deleted',
                    ),
                ),
            ),
        );

        return $changes_default_logs;
    }


    /**
     * Builds a configuration object of links suitable for the events definition.
     *
     * @param string[] $link_aliases Link aliases.
     *
     * @return array
     */
    public static function ws_al_defaults_build_links( $link_aliases = array() ) {
        $result = array();

        if ( empty( self::$ws_al_built_links ) ) {
            self::$ws_al_built_links['CategoryLink']   = array( esc_html__( 'View category', 'mainwp' ) => '%CategoryLink%' );
            self::$ws_al_built_links['cat_link']       = array( esc_html__( 'View category', 'mainwp' ) => '%cat_link%' );
            self::$ws_al_built_links['ProductCatLink'] = array( esc_html__( 'View category', 'mainwp' ) => '%ProductCatLink%' );

            self::$ws_al_built_links['ContactSupport'] = array( esc_html__( 'Contact Support', 'mainwp' ) => 'https://melapress.com/contact/' );

            self::$ws_al_built_links['CommentLink'] = array(
                esc_html__( 'Comment', 'mainwp' ) => array(
                    'url'   => '%CommentLink%',
                    'label' => '%CommentDate%',
                ),
            );

            self::$ws_al_built_links['EditorLinkPage'] = array( esc_html__( 'View page in the editor', 'mainwp' ) => '%EditorLinkPage%' );

            self::$ws_al_built_links['EditorLinkPost'] = array( esc_html__( 'View the post in editor', 'mainwp' ) => '%EditorLinkPost%' );

            self::$ws_al_built_links['EditorLinkOrder'] = array( esc_html__( 'View the order', 'mainwp' ) => '%EditorLinkOrder%' );

            self::$ws_al_built_links['EditUserLink'] = array( esc_html__( 'User profile page', 'mainwp' ) => '%EditUserLink%' );

            self::$ws_al_built_links['LinkFile'] = array( esc_html__( 'Open the log file', 'mainwp' ) => '%LinkFile%' );

            self::$ws_al_built_links['MenuUrl'] = array( esc_html__( 'View menu', 'mainwp' ) => '%MenuUrl%' );

            self::$ws_al_built_links['PostUrl'] = array( esc_html__( 'URL', 'mainwp' ) => '%PostUrl%' );

            self::$ws_al_built_links['AttachmentUrl'] = array( esc_html__( 'View attachment page', 'mainwp' ) => '%AttachmentUrl%' );

            self::$ws_al_built_links['PostUrlIfPlublished'] = array( esc_html__( 'URL', 'mainwp' ) => '%PostUrlIfPlublished%' );

            self::$ws_al_built_links['PostUrlIfPublished'] = array( esc_html__( 'URL', 'mainwp' ) => '%PostUrlIfPlublished%' );

            self::$ws_al_built_links['RevisionLink'] = array( esc_html__( 'View the content changes', 'mainwp' ) => '%RevisionLink%' );

            self::$ws_al_built_links['TagLink'] = array( esc_html__( 'View tag', 'mainwp' ) => '%RevisionLink%' );

            /*
            * All these links are formatted (including any label) because they
            * contain non-trivial HTML markup that includes custom JS. We assume these will only be rendered
            * in the log viewer in WP admin UI.
            */
            self::$ws_al_built_links['LogFileText'] = array( '%LogFileText%' );
            self::$ws_al_built_links['MetaLink']    = array( '%MetaLink%' );

        }

        if ( ! empty( $link_aliases ) ) {
            foreach ( $link_aliases as $link_alias ) {
                if ( array_key_exists( $link_alias, self::$ws_al_built_links ) ) {
                    $result = array_merge( $result, self::$ws_al_built_links[ $link_alias ] );
                }
            }
        }

        return $result;
    }


    /**
     * Method get_changes_events_title_default().
     *
     * @param string $type_id Type of logs info.
     *
     * @return array data.
     */
    public static function get_changes_events_title_default( $type_id ) {
        $defaults = array(
            5028 => array(
                __( '%action% automatic update', 'mainwp' ),
            ),
        );
        return isset( $defaults[ $type_id ] ) ? $defaults[ $type_id ][0] : '';
    }
}
