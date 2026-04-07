<?php
/**
 * Centralized manager for WordPress backend functionality.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Settings;
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
    public function admin_init() { // phpcs:ignore -- NOSONAR - complex.
        //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['mainwp_module_log_settings_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['mainwp_module_log_settings_nonce'] ), 'logs_settings_nonce' ) ) {

            $old_settings = MainWP_Settings::get_all_settings_values();

            $old_enable = is_array( $this->options ) && ! empty( $this->options['enabled'] ) && ! empty( $this->options['auto_archive'] ) ? true : false;

            $this->options['enabled']          = isset( $_POST['mainwp_module_log_enabled'] ) && ! empty( $_POST['mainwp_module_log_enabled'] ) ? 1 : 0;
            $this->options['records_logs_ttl'] = isset( $_POST['mainwp_module_log_records_ttl'] ) ? intval( $_POST['mainwp_module_log_records_ttl'] ) : 3 * YEAR_IN_SECONDS;
            $this->options['child_logs_ttl']   = isset( $_POST['mainwp_module_log_child_activities_ttl'] ) ? intval( $_POST['mainwp_module_log_child_activities_ttl'] ) : 7;
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

            $new_settings = MainWP_Settings::get_all_settings_values();

            /**
            * Action: mainwp_after_save_settings
            *
            * Fires after save settings.
            *
            * @since 6.0
            *
            * @param array $new_settings The new settings.
            * @param array $old_settings The old settings.
            */
            do_action( 'mainwp_after_save_settings', $new_settings, $old_settings );

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
            'title'    => esc_html__( 'Network Activity Settings', 'mainwp' ),
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
     * Add Insights Operations sub menu "Insights".
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
     * Method get_settings().
     */
    public function get_settings() {
        return $this->options;
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
    public function render_settings_page() { // phpcs:ignore -- NOSONAR - complex method.
        /** This action is documented in ../pages/page-mainwp-manage-sites.php */
        do_action( 'mainwp_pageheader_settings', 'Insights' );
        $enabled              = ! empty( $this->options['enabled'] ) ? true : false;
        $enabled_auto_archive = isset( $this->options['auto_archive'] ) && ! empty( $this->options['auto_archive'] ) ? true : false;

        $child_logs_ttl = isset( $this->options['child_logs_ttl'] ) ? $this->options['child_logs_ttl'] : 7;

        ?>
        <div id="mainwp-module-log-settings-wrapper" class="ui padded segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-network-activity-settings-info-message' ) ) : ?>
            <div class="ui info message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-network-activity-settings-info-message"></i>
                <div class="ui header"><?php esc_html_e( 'Network Activity and Insights Data Logging', 'mainwp' ); ?></div>
                <p><?php esc_html_e( 'The Network Activity system records actions performed through your MainWP Dashboard or directly on your child sites. This data powers both the Network Activity feature which displays a detailed timeline of changes and events and Dashboard Insights, which summarizes the same information to help you analyze usage trends and overall activity.', 'mainwp' ); ?></p>
                <p><?php esc_html_e( 'All collected data is stored locally on your server. It is never sent to MainWP servers or shared with any third parties.', 'mainwp' ); ?></p>
            </div>
            <?php else : ?>
            <div class="ui info message">
                <div><?php esc_html_e( 'All collected data is stored locally on your server. It is never sent to MainWP servers or shared with any third parties.', 'mainwp' ); ?></div>
            </div>
            <?php endif; ?>
            <div class="ui form">
                <form method="post" class="mainwp-table-container">
                    <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
                        <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-insights' ); ?>
                            <?php esc_html_e( 'Dashboard Insights Settings', 'mainwp' ); ?>
                            <div class="sub header"><?php esc_html_e( 'Manage how MainWP records, stores, and displays Dashboard activity data used by Network Activity and Insights.', 'mainwp' ); ?></div>
                        </h3>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-insights" default-indi-value="1">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_module_log_enabled', (int) $enabled );
                            esc_html_e( 'Enable Network Activity logging', 'mainwp' );
                            ?>
                            </label>
                            <div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="auto-archive;child-logs-ttl">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_module_log_enabled" id="mainwp_module_log_enabled" <?php echo $enabled ? 'checked="true"' : ''; ?> /><label></label>
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Automatically archive logs', 'mainwp' ); ?></label>
                            <div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements"  hide-parent="auto-archive" data-tooltip="<?php esc_attr_e( 'Automatically move older logs to the archive after a specified period of time. This helps keep your active logs organized while maintaining a searchable history.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" name="mainwp_module_log_enable_auto_archive" id="mainwp_module_log_enable_auto_archive" <?php echo $enabled_auto_archive ? 'checked="true"' : ''; ?> /><label></label>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general"  <?php echo ( $enabled && $enabled_auto_archive ) ? '' : 'style="display:none"'; ?> hide-element="auto-archive" default-indi-value="<?php echo (int) 3 * YEAR_IN_SECONDS; ?>">
                            <label class="six wide column middle aligned">
                                <?php
                                $records_ttl = $this->options['records_logs_ttl'];
                                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_module_log_records_ttl', $records_ttl, true, 3 * YEAR_IN_SECONDS );
                                esc_html_e( 'Data retention period', 'mainwp' );
                                ?>
                                </label>
                                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Define how long logs should remain active before being automatically moved to the archive.', 'mainwp' ); ?>" data-inverted="" data-position="top left" >
                                    <select name="mainwp_module_log_records_ttl" id="mainwp_module_log_records_ttl" class="ui dropdown settings-field-value-change-handler">
                                        <option value="<?php echo (int) MONTH_IN_SECONDS; ?>" <?php echo (int) MONTH_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'One month', 'mainwp' ); ?></option>
                                        <option value="<?php echo (int) 2 * MONTH_IN_SECONDS; ?>" <?php echo 2 * MONTH_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Two months', 'mainwp' ); ?></option>
                                        <option value="<?php echo (int) 3 * MONTH_IN_SECONDS; ?>" <?php echo 3 * MONTH_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Three months', 'mainwp' ); ?></option>
                                        <option value="<?php echo (int) 6 * MONTH_IN_SECONDS; ?>" <?php echo 6 * MONTH_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Half a year', 'mainwp' ); ?></option>
                                        <option value="<?php echo (int) YEAR_IN_SECONDS; ?>" <?php echo (int) YEAR_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Year', 'mainwp' ); ?></option>
                                        <option value="<?php echo (int) 2 * YEAR_IN_SECONDS; ?>" <?php echo 2 * YEAR_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Two years', 'mainwp' ); ?></option>
                                        <option value="<?php echo (int) 3 * YEAR_IN_SECONDS; ?>" <?php echo 3 * YEAR_IN_SECONDS === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Three years', 'mainwp' ); ?></option>
                                        <option value="0" <?php echo 0 === (int) $records_ttl ? 'selected' : ''; ?>><?php esc_html_e( 'Forever', 'mainwp' ); ?></option>
                                    </select>
                                </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-insights" <?php echo ( $enabled ) ? '' : 'style="display:none"'; ?> hide-element="child-logs-ttl" default-indi-value="7">
                            <label class="six wide column middle aligned">
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_module_log_child_activities_ttl', (int) get_option( 'mainwp_module_log_child_activities_ttl', 7 ) );
                            esc_html_e( 'Retention period for Network Activity Logs on child sites', 'mainwp' );
                            ?>
                            </label>
                            <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Controls how long network activity logs are stored on child sites before local cleanup. This does not affect log retention on the Dashboard.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                                <input type="number" class="settings-field-value-change-handler small-text" name="mainwp_module_log_child_activities_ttl" id="mainwp_module_log_child_activities_ttl" placeholder="" min="1" max="9999" step="1" value="<?php echo intval( $child_logs_ttl ); ?>">
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
     * Method load_actions_logs_selection_settings().
     *
     * @return array Enable logs types.
     */
    public static function load_actions_logs_selection_settings() {
        if ( null === static::$enable_logs_items ) {
            $enable_logs = get_option( 'mainwp_module_log_settings_logs_selection_data' );

            if ( ! empty( $enable_logs ) ) {
                static::$enable_logs_items = json_decode( $enable_logs, true );
            }

            if ( ! is_array( static::$enable_logs_items ) ) {
                static::$enable_logs_items = array();
            }

            if ( ! isset( static::$enable_logs_items['changeslogs'] ) ) {
                static::$enable_logs_items['changeslogs'] = array_fill_keys( static::get_disabled_changes_logs_default_settings(), 0 );
            }
        }
        return static::$enable_logs_items;
    }

    /**
     * Method get_disabled_changes_logs_default_settings().
     *
     * @return array Disalbed default.
     */
    public static function get_disabled_changes_logs_default_settings() {
        return array( 1925, 1930, 1935, 1940, 1945, 1950, 1955 );
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

        static::load_actions_logs_selection_settings();

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
     * Method get_disabled_logs_type().
     *
     * @param string $type Log type dashboard|nonmainwpchanges|changeslogs.
     *
     * @return mixed Disabled logs settings.
     */
    public static function get_disabled_logs_type( $type = 'dashboard' ) {
        if ( ! in_array( $type, array( 'dashboard', 'nonmainwpchanges', 'changeslogs' ) ) ) {
            return false;
        }

        static::load_actions_logs_selection_settings();

        $selection_items_type = isset( static::$enable_logs_items[ $type ] ) ? static::$enable_logs_items[ $type ] : array();

        if ( ! is_array( $selection_items_type ) || empty( $selection_items_type ) ) {
            return array();
        }

        return array_keys(
            array_filter(
                $selection_items_type,
                function ( $value ) {
                    return empty( $value );
                }
            )
        );
    }

    /**
     * Method render_logs_data_selection().
     *
     * @return void
     */
    private static function render_logs_data_selection() { //phpcs:ignore -- NOSONAR - ok.
        $list_logs    = static::get_data_logs_default_to_render();
        $setting_page = true;
        ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-miscellaneous">
            <label class="six wide column top aligned">
            <?php
            MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-logs-data' );
            esc_html_e( 'Select events to log', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column" <?php echo $setting_page ? 'data-tooltip="' . esc_attr__( 'Select which types of site changes should be recorded in the logs. Only checked items will generate log entries, helping you focus on the most relevant activity.', 'mainwp' ) . '"' : ''; ?> data-inverted="" data-position="top left">
                <?php
                foreach ( $list_logs as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    if ( isset( $item['name_id'] ) && '_separator_title' === $item['name_id'] ) {
                        ?>
                        <div class="ui header"><?php echo esc_html( $item['label'] ); ?></div>
                        <?php
                        continue;
                    }
                    ?>
                    <ul class="mainwp_hide_wpmenu_checkboxes">
                    <?php

                    $type  = isset( $item['log_group'] ) ? $item['log_group'] : '';
                    $name  = isset( $item['name_id'] ) ? $item['name_id'] : '';
                    $title = isset( $item['label'] ) ? $item['label'] : '';

                    if ( in_array( $type, array( 'dashboard', 'nonmainwpchanges', 'changeslogs' ) ) ) {
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

        $logs['changeslogs'] = Log_Changes_Logs_Helper::get_changes_logs_types();

        if ( 'unlogs' === $type ) {
            return $init_un_logs;
        }

        return $logs;
    }

    /**
     * Method get_data_logs_default_to_render().
     *
     * @return array data.
     */
    private static function get_data_logs_default_to_render() {

        $list_compatible = array( 1960, 1965, 1970, 1975, 1980 );

        // Convert
        $list_to_render = array(

            array(
                'label'   => __( 'Events triggered from MainWP Dashboard', 'mainwp' ),
                'name_id' => '_separator_title',
            ),
            array(
                'label'     => __( 'Site Added', 'mainwp' ),
                'name_id'   => 'sites_added',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Site Updated', 'mainwp' ),
                'name_id'   => 'sites_updated',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Site Synchronized', 'mainwp' ),
                'name_id'   => 'sites_sync',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Site Deleted', 'mainwp' ),
                'name_id'   => 'sites_deleted',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Site Reconnected', 'mainwp' ),
                'name_id'   => 'sites_reconnect',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Site Suspended', 'mainwp' ),
                'name_id'   => 'sites_suspend',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Site Unsuspended', 'mainwp' ),
                'name_id'   => 'sites_unsuspend',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Tag Created', 'mainwp' ),
                'name_id'   => 'tags_created',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Tag Deleted', 'mainwp' ),
                'name_id'   => 'tags_deleted',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Tag Updated', 'mainwp' ),
                'name_id'   => 'tags_updated',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Theme Installed', 'mainwp' ),
                'name_id'   => 'theme_install',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Theme Activated', 'mainwp' ),
                'name_id'   => 'theme_activate',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Theme Deactivated', 'mainwp' ),
                'name_id'   => 'theme_deactivate',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Theme Updated', 'mainwp' ),
                'name_id'   => 'theme_update',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Theme Switched', 'mainwp' ),
                'name_id'   => 'theme_switch',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Theme Deleted', 'mainwp' ),
                'name_id'   => 'theme_delete',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Plugin Installed', 'mainwp' ),
                'name_id'   => 'plugin_install',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Plugin Activated', 'mainwp' ),
                'name_id'   => 'plugin_activate',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Plugin Deactivated', 'mainwp' ),
                'name_id'   => 'plugin_deactivate',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Plugin Updated', 'mainwp' ),
                'name_id'   => 'plugin_updated',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Plugin Deleted', 'mainwp' ),
                'name_id'   => 'plugin_delete',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Translation Updated', 'mainwp' ),
                'name_id'   => 'translation_updated',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'WordPress Core Updated', 'mainwp' ),
                'name_id'   => 'core_updated',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Post Created', 'mainwp' ),
                'name_id'   => 'post_created',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Post Published', 'mainwp' ),
                'name_id'   => 'post_published',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Post Unpublished', 'mainwp' ),
                'name_id'   => 'post_unpublished',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Post Updated', 'mainwp' ),
                'name_id'   => 'post_updated',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Post Trashed', 'mainwp' ),
                'name_id'   => 'post_trashed',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Post Deleted', 'mainwp' ),
                'name_id'   => 'post_deleted',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Post Restored', 'mainwp' ),
                'name_id'   => 'post_restored',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Page Created', 'mainwp' ),
                'name_id'   => 'page_created',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Page Published', 'mainwp' ),
                'name_id'   => 'page_published',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Page Unpublished', 'mainwp' ),
                'name_id'   => 'page_unpublished',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Page Updated', 'mainwp' ),
                'name_id'   => 'page_updated',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Page Trashed', 'mainwp' ),
                'name_id'   => 'page_trashed',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Page Deleted', 'mainwp' ),
                'name_id'   => 'page_deleted',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Page Restored', 'mainwp' ),
                'name_id'   => 'page_restored',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Client Created', 'mainwp' ),
                'name_id'   => 'clients_created',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Client Updated', 'mainwp' ),
                'name_id'   => 'clients_updated',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Client Suspended', 'mainwp' ),
                'name_id'   => 'clients_suspend',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Client Unsuspended', 'mainwp' ),
                'name_id'   => 'clients_unsuspend',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Client Marked as Lead', 'mainwp' ),
                'name_id'   => 'clients_lead',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Client Marked as Lost', 'mainwp' ),
                'name_id'   => 'clients_lost',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'User Created', 'mainwp' ),
                'name_id'   => 'users_created',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'User Updated', 'mainwp' ),
                'name_id'   => 'users_update',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'User Deleted', 'mainwp' ),
                'name_id'   => 'users_delete',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'User Role Changed', 'mainwp' ),
                'name_id'   => 'users_change_role',
                'log_group' => 'dashboard',
            ),
            array(
                'label'     => __( 'Admin Password Updated', 'mainwp' ),
                'name_id'   => 'users_update_password',
                'log_group' => 'dashboard',
            ),
            array(
                'label'   => __( 'Non-MainWP Changes - Events triggered on child sites', 'mainwp' ),
                'name_id' => '_separator_title',
            ),
            array(
                'label'     => __( 'Theme Installed', 'mainwp' ),
                'name_id'   => 1975,
                'log_group' => 'changeslogs',
            ),
            array(
                'label'     => __( 'Theme Activated', 'mainwp' ),
                'name_id'   => 'theme_activate',
                'log_group' => 'nonmainwpchanges',
            ),
            array(
                'label'     => __( 'Theme Deactivated', 'mainwp' ),
                'name_id'   => 'theme_deactivate',
                'log_group' => 'nonmainwpchanges',
            ),
            array(
                'label'     => __( 'Theme Updated', 'mainwp' ),
                'name_id'   => 1980,
                'log_group' => 'changeslogs',
            ),
            array(
                'label'     => __( 'Theme Switched', 'mainwp' ),
                'name_id'   => 'theme_switch',
                'log_group' => 'nonmainwpchanges',
            ),
            array(
                'label'     => __( 'Theme Deleted', 'mainwp' ),
                'name_id'   => 'theme_delete',
                'log_group' => 'nonmainwpchanges',
            ),
            array(
                'label'     => __( 'Plugin Installed', 'mainwp' ),
                'name_id'   => 1965,
                'log_group' => 'changeslogs',
            ),
            array(
                'label'     => __( 'Plugin Activated', 'mainwp' ),
                'name_id'   => 'plugin_activate',
                'log_group' => 'nonmainwpchanges',
            ),
            array(
                'label'     => __( 'Plugin Deactivated', 'mainwp' ),
                'name_id'   => 'plugin_deactivate',
                'log_group' => 'nonmainwpchanges',
            ),
            array(
                'label'     => __( 'Plugin Updated', 'mainwp' ),
                'name_id'   => 1970,
                'log_group' => 'changeslogs',
            ),
            array(
                'label'     => __( 'Plugin Deleted', 'mainwp' ),
                'name_id'   => 'plugin_delete',
                'log_group' => 'nonmainwpchanges',
            ),
            array(
                'label'     => __( 'WordPress Core Updated', 'mainwp' ),
                'name_id'   => 1960,
                'log_group' => 'changeslogs',
            ),
        );

        $raw_logs = Log_Changes_Logs_Helper::get_changes_logs_types();

        foreach ( $raw_logs as $item ) {

            if ( ! empty( $item['type_id'] ) && in_array( $item['type_id'], $list_compatible ) ) {
                continue;
            }

            $list_to_render[] = array(
                'label'     => $item['desc'],
                'name_id'   => $item['type_id'],
                'log_group' => 'changeslogs',
            );
        }

        return $list_to_render;
    }
}
