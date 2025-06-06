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
                        foreach ( $items as $sub_items ) {
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
            esc_html__( 'Post', 'mainwp-child' )           => array(
                array(
                    2000,
                    esc_html__( 'Created a new post', 'mainwp-child' ),
                    esc_html__( 'Created the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'created',
                ),
                array(
                    2001,
                    esc_html__( 'Published a post', 'mainwp-child' ),
                    esc_html__( 'Published the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'published',
                ),
                array(
                    2002,
                    esc_html__( 'Modified a post', 'mainwp-child' ),
                    esc_html__( 'Modified the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2008,
                    esc_html__( 'Permanently deleted a post', 'mainwp-child' ),
                    esc_html__( 'Permanently deleted the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'deleted',
                ),
                array(
                    2012,
                    esc_html__( 'Moved a post to trash', 'mainwp-child' ),
                    esc_html__( 'Moved the post %PostTitle% to trash.', 'mainwp-child' ),
                    'post',
                    'deleted',
                ),
                array(
                    2014,
                    esc_html__( 'Restored a post from trash', 'mainwp-child' ),
                    esc_html__( 'Restored the post %PostTitle% from trash.', 'mainwp-child' ),
                    'post',
                    'restored',
                ),
                array(
                    2016,
                    esc_html__( 'Changed the category of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the category(ies) of the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2017,
                    esc_html__( 'Changed the URL of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the URL of the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2019,
                    esc_html__( 'Changed the author of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the author of the post %PostTitle% to %NewAuthor%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2021,
                    esc_html__( 'Changed the status of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the status of the post %PostTitle% to %NewStatus%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2047,
                    esc_html__( 'Changed the parent of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the parent of the post %PostTitle% to %NewParentName%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2048,
                    esc_html__( 'Changed the template of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the template of the post %PostTitle% to %NewTemplate%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2049,
                    esc_html__( 'Set a post as Sticky', 'mainwp-child' ),
                    esc_html__( 'Set the post %PostTitle% as sticky.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2050,
                    esc_html__( 'Removed post from Sticky', 'mainwp-child' ),
                    esc_html__( 'Removed the post %PostTitle% from sticky.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2053,
                    esc_html__( 'Created a custom field in a post', 'mainwp-child' ),
                    esc_html__( 'Created the new custom field %MetaKey% in the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2073,
                    esc_html__( 'Submitted post for review', 'mainwp-child' ),
                    esc_html__( 'Submitted the post %PostTitle% for review.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2074,
                    esc_html__( 'Scheduled a post for publishing', 'mainwp-child' ),
                    esc_html__( 'Scheduled the post %PostTitle% to be published on %PublishingDate%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2025,
                    esc_html__( 'User changed the visibility of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the visibility of the post %PostTitle% to %NewVisibility%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2027,
                    esc_html__( 'Changed the date of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the date of the post %PostTitle% to %NewDate%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2065,
                    esc_html__( 'Modified the content of a post', 'mainwp-child' ),
                    esc_html__( 'Modified the content of the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2086,
                    esc_html__( 'Changed title of a post', 'mainwp-child' ),
                    esc_html__( 'Changed the title of the post %OldTitle% to %NewTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2100,
                    esc_html__( 'Opened a post in editor', 'mainwp-child' ),
                    esc_html__( 'Opened the post %PostTitle% in the editor.', 'mainwp-child' ),
                    'post',
                    'opened',
                ),
                array(
                    2101,
                    esc_html__( 'Viewed a post', 'mainwp-child' ),
                    esc_html__( 'Viewed the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'viewed',
                ),
                array(
                    2111,
                    esc_html__( 'Enabled / disabled comments in a post', 'mainwp-child' ),
                    esc_html__( 'Comments in the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'enabled',
                ),
                array(
                    2112,
                    esc_html__( 'Enabled / disabled trackbacks in a post', 'mainwp-child' ),
                    esc_html__( 'Pingbacks and Trackbacks in the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'enabled',
                ),
                array(
                    2119,
                    esc_html__( 'Added tag(s) to a post', 'mainwp-child' ),
                    esc_html__( 'Added tag(s) to the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2120,
                    esc_html__( 'Removed tag(s) from a post', 'mainwp-child' ),
                    esc_html__( 'Removed tag(s) from the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2129,
                    esc_html__( 'Updated the excerpt of a post', 'mainwp-child' ),
                    esc_html__( 'The excerpt of the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                array(
                    2130,
                    esc_html__( 'Updated the feature image of a post', 'mainwp-child' ),
                    esc_html__( 'The featured image of the post %PostTitle%.', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
                // Post 9043 - Added / changed / removed a post’s featured image
                array(
                    2133,
                    esc_html__( 'Taken over a post from another user', 'mainwp-child' ),
                    esc_html__( 'Has taken over the post %PostTitle% from %user%', 'mainwp-child' ),
                    'post',
                    'modified',
                ),
            ),
            esc_html__( 'Custom field', 'mainwp-child' )   => array(
                array(
                    2131,
                    esc_html__( 'Added a relationship in an ACF custom field', 'mainwp-child' ),
                    esc_html__( 'Added relationships to the custom field %MetaKey% in the post %PostTitle%.', 'mainwp-child' ),
                    'custom-field',
                    'modified',
                ),
                array(
                    2132,
                    esc_html__( 'Removed a relationship from an ACF custom field', 'mainwp-child' ),
                    esc_html__( 'Removed relationships from the custom field %MetaKey% in the post %PostTitle%.', 'mainwp-child' ),
                    'custom-field',
                    'modified',
                ),
                array(
                    2054,
                    esc_html__( 'Changed the value of a custom field', 'mainwp-child' ),
                    esc_html__( 'Modified the value of the custom field %MetaKey% in the post %PostTitle%.', 'mainwp-child' ),
                    'custom-field',
                    'modified',
                ),
                array(
                    2055,
                    esc_html__( 'Deleted a custom field', 'mainwp-child' ),
                    esc_html__( 'Deleted the custom field %MetaKey% from the post %PostTitle%.', 'mainwp-child' ),
                    'custom-field',
                    'deleted',
                ),
                array(
                    2062,
                    esc_html__( 'Renamed a custom field', 'mainwp-child' ),
                    esc_html__( 'Renamed the custom field %MetaKeyOld% on post %PostTitle% to %MetaKeyNew%.', 'mainwp-child' ),
                    'custom-field',
                    'renamed',
                ),
            ),
            esc_html__( 'Categories', 'mainwp-child' )     => array(
                array(
                    2023,
                    esc_html__( 'Created a new category', 'mainwp-child' ),
                    esc_html__( 'Created the category %CategoryName%.', 'mainwp-child' ),
                    'category',
                    'created',
                ),
                array(
                    2024,
                    esc_html__( 'Deleted a category', 'mainwp-child' ),
                    esc_html__( 'Deleted the category %CategoryName%.', 'mainwp-child' ),
                    'category',
                    'deleted',
                ),
                array(
                    2052,
                    esc_html__( 'Changed the parent of a category', 'mainwp-child' ),
                    esc_html__( 'Changed the parent of the category %CategoryName% to %NewParent%.', 'mainwp-child' ),
                    'category',
                    'modified',
                ),
                array(
                    2127,
                    esc_html__( 'Renamed a category', 'mainwp-child' ),
                    esc_html__( 'Renamed the category %old_name% to %new_name%.', 'mainwp-child' ),
                    'category',
                    'renamed',
                ),
                array(
                    2128,
                    esc_html__( 'Renamed a category', 'mainwp-child' ),
                    esc_html__( 'Changed the slug of the category %CategoryName% to %new_slug%.', 'mainwp-child' ),
                    'category',
                    'modified',
                ),
            ),
            esc_html__( 'Tag', 'mainwp-child' )            => array(
                array(
                    2121,
                    esc_html__( 'Created a new tag', 'mainwp-child' ),
                    esc_html__( 'Created the tag %TagName%.', 'mainwp-child' ),
                    'tag',
                    'created',
                ),
                array(
                    2122,
                    esc_html__( 'Deleted a tag', 'mainwp-child' ),
                    esc_html__( 'Deleted the tag %TagName%.', 'mainwp-child' ),
                    'tag',
                    'deleted',
                ),
                array(
                    2123,
                    esc_html__( 'Renamed the tag %old_name% to %new_name%.', 'mainwp-child' ),
                    '',
                    'tag',
                    'renamed',
                ),
                array(
                    2124,
                    esc_html__( 'Changed the slug of a tag', 'mainwp-child' ),
                    esc_html__( 'Changed the slug of the tag %tag% to %new_slug%.', 'mainwp-child' ),
                    'tag',
                    'modified',
                ),
                array(
                    2125,
                    esc_html__( 'Changed the description of a tag', 'mainwp-child' ),
                    esc_html__( 'Changed the description of the tag %tag%.', 'mainwp-child' ),
                    'tag',
                    'modified',
                ),
            ),
            esc_html__( 'File', 'mainwp-child' )           => array(
                array(
                    2010,
                    esc_html__( 'Uploaded a file', 'mainwp-child' ),
                    esc_html__( 'Uploaded a file called %FileName%.', 'mainwp-child' ),
                    'file',
                    'uploaded',
                ),
                array(
                    2011,
                    esc_html__( 'Deleted a file', 'mainwp-child' ),
                    esc_html__( 'Deleted the file %FileName%.', 'mainwp-child' ),
                    'file',
                    'deleted',
                ),
            ),
            esc_html__( 'Widget', 'mainwp-child' )         => array(
                array(
                    2042,
                    esc_html__( 'Added a new widget', 'mainwp-child' ),
                    esc_html__( 'Added a new %WidgetName% widget in %Sidebar%.', 'mainwp-child' ),
                    'widget',
                    'added',
                ),
                array(
                    2043,
                    esc_html__( 'Modified a widget', 'mainwp-child' ),
                    esc_html__( 'Modified the %WidgetName% widget in %Sidebar%.', 'mainwp-child' ),
                    'widget',
                    'modified',
                ),
                array(
                    2044,
                    esc_html__( 'Deleted a widget', 'mainwp-child' ),
                    esc_html__( 'Deleted the %WidgetName% widget from %Sidebar%.', 'mainwp-child' ),
                    'widget',
                    'deleted',
                ),
                array(
                    2045,
                    esc_html__( 'Moved a widget in between sections', 'mainwp-child' ),
                    esc_html__( 'Moved the %WidgetName% widget.', 'mainwp-child' ),
                    'widget',
                    'modified',
                ),
                array(
                    2071,
                    esc_html__( 'Changed the position of a widget in a section', 'mainwp-child' ),
                    esc_html__( 'Changed the position of the %WidgetName% widget in %Sidebar%.', 'mainwp-child' ),
                    'widget',
                    'modified',
                ),
            ),
            esc_html__( 'Plugin', 'mainwp-child' )         => array(
                array(
                    2051,
                    esc_html__( 'Modified a file with the plugin editor', 'mainwp-child' ),
                    esc_html__( 'Modified the file %File% with the plugin editor.', 'mainwp-child' ),
                    'file',
                    'modified',
                ),
                array(
                    5028,
                    esc_html__( 'The automatic updates setting for a plugin was changed.', 'mainwp-child' ),
                    esc_html__( 'Changed the Automatic updates setting for the plugin %name%.', 'mainwp-child' ),
                    'plugin',
                    'enabled',
                ),
            ),
            esc_html__( 'Theme', 'mainwp-child' )          => array(
                array(
                    2046,
                    esc_html__( 'Modified a file with the theme editor', 'mainwp-child' ),
                    esc_html__( 'Modified the file %Theme%/%File% with the theme editor.', 'mainwp-child' ),
                    'file',
                    'modified',
                ),
                array(
                    5029,
                    esc_html__( 'The automatic updates setting for a theme was changed.', 'mainwp-child' ),
                    esc_html__( 'Changed the Automatic updates setting for the theme %name%.', 'mainwp-child' ),
                    'theme',
                    'enabled',
                ),
            ),
            esc_html__( 'Menu', 'mainwp-child' )           => array(
                array(
                    2078,
                    esc_html__( 'Created a menu', 'mainwp-child' ),
                    esc_html__( 'New menu called %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'created',
                ),
                array(
                    2079,
                    esc_html__( 'Added item(s) to a menu', 'mainwp-child' ),
                    esc_html__( 'Added the item %ContentName% to the menu %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'modified',
                ),
                array(
                    2080,
                    esc_html__( 'Removed item(s) from a menu', 'mainwp-child' ),
                    esc_html__( 'Removed the item %ContentName% from the menu %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'modified',
                ),
                array(
                    2081,
                    esc_html__( 'Deleted a menu', 'mainwp-child' ),
                    esc_html__( 'Deleted the menu %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'deleted',
                ),
                array(
                    2082,
                    esc_html__( 'Changed the settings of a menu', 'mainwp-child' ),
                    esc_html__( 'The setting %MenuSetting% in the menu %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'enabled',
                ),
                array(
                    2083,
                    esc_html__( 'Modified the item(s) in a menu', 'mainwp-child' ),
                    esc_html__( 'Modified the item %ContentName% in the menu %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'modified',
                ),
                array(
                    2084,
                    esc_html__( 'Renamed a menu', 'mainwp-child' ),
                    esc_html__( 'Renamed the menu %OldMenuName% to %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'renamed',
                ),
                array(
                    2085,
                    esc_html__( 'Changed the order of the objects in a menu.', 'mainwp-child' ),
                    esc_html__( 'Changed the order of the items in the menu %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'modified',
                ),
                array(
                    2089,
                    esc_html__( 'Moved an item as a sub-item in a menu', 'mainwp-child' ),
                    esc_html__( 'Moved items as sub-items in the menu %MenuName%.', 'mainwp-child' ),
                    'menu',
                    'modified',
                ),
            ),
            esc_html__( 'Comment', 'mainwp-child' )        => array(
                array(
                    2090,
                    esc_html__( 'Approved a comment', 'mainwp-child' ),
                    esc_html__( 'Approved the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                    'comment',
                    'approved',
                ),
                array(
                    2091,
                    esc_html__( 'Unapproved a comment', 'mainwp-child' ),
                    esc_html__( 'Unapproved the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                    'comment',
                    'unapproved',
                ),
                array(
                    2092,
                    esc_html__( 'Replied to a comment', 'mainwp-child' ),
                    esc_html__( 'Replied to the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                    'comment',
                    'created',
                ),
                array(
                    2093,
                    esc_html__( 'Edited a comment', 'mainwp-child' ),
                    esc_html__( 'Edited the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                    'comment',
                    'modified',
                ),
                array(
                    2094,
                    esc_html__( 'Marked a comment as spam', 'mainwp-child' ),
                    esc_html__( 'Marked the comment posted by %Author% on the post %PostTitle% as spam.', 'mainwp-child' ),
                    'comment',
                    'unapproved',
                ),
                array(
                    2095,
                    esc_html__( 'Marked a comment as not spam', 'mainwp-child' ),
                    esc_html__( 'Marked the comment posted by %Author% on the post %PostTitle% as not spam.', 'mainwp-child' ),
                    'comment',
                    'approved',
                ),
                array(
                    2096,
                    esc_html__( 'Moved a comment to trash', 'mainwp-child' ),
                    esc_html__( 'Moved the comment posted by %Author% on the post %PostTitle% to trash.', 'mainwp-child' ),
                    'comment',
                    'deleted',
                ),
                array(
                    2097,
                    esc_html__( 'Restored a comment from the trash', 'mainwp-child' ),
                    esc_html__( 'Restored the comment posted by %Author% on the post %PostTitle% from trash.', 'mainwp-child' ),
                    'comment',
                    'restored',
                ),
                array(
                    2098,
                    esc_html__( 'Permanently deleted a comment', 'mainwp-child' ),
                    esc_html__( 'Permanently deleted the comment posted by %Author% on the post %PostTitle%.', 'mainwp-child' ),
                    'comment',
                    'deleted',
                ),
                array(
                    2099,
                    esc_html__( 'Posted a comment', 'mainwp-child' ),
                    esc_html__( 'Posted a comment on the post %PostTitle%.', 'mainwp-child' ),
                    'comment',
                    'created',
                ),
            ),
            esc_html__( 'User', 'mainwp-child' )           => array(
                array(
                    1000,
                    esc_html__( 'Successfully logged in', 'mainwp-child' ),
                    esc_html__( 'User logged in.', 'mainwp-child' ),
                    'user',
                    'login',
                ),
                array(
                    1001,
                    esc_html__( 'Successfully logged out', 'mainwp-child' ),
                    esc_html__( 'User logged out.', 'mainwp-child' ),
                    'user',
                    'logout',
                ),
                array(
                    1005,
                    esc_html__( 'Successful log in but other sessions exist for user', 'mainwp-child' ),
                    esc_html__( 'User logged in however there are other session(s) already for this user.', 'mainwp-child' ),
                    'user',
                    'login',
                ),
                array(
                    1006,
                    esc_html__( 'Logged out all other sessions with same user', 'mainwp-child' ),
                    esc_html__( 'Logged out all other sessions with the same user.', 'mainwp-child' ),
                    'user',
                    'logout',
                ),
                array(
                    1009,
                    esc_html__( 'Terminated a user session', 'mainwp-child' ),
                    esc_html__( 'The plugin terminated an idle session for the user %username%.', 'mainwp-child' ),
                    'user',
                    'logout',
                ),
                array(
                    1008,
                    esc_html__( 'Switched to another user', 'mainwp-child' ),
                    esc_html__( 'Switched the session to being logged in as %TargetUserName%.', 'mainwp-child' ),
                    'user',
                    'login',
                ),
                array(
                    1010,
                    esc_html__( 'User requested a password reset', 'mainwp-child' ),
                    esc_html__( 'User requested a password reset. This does not mean that the password was changed.', 'mainwp-child' ),
                    'user',
                    'submitted',
                ),
                array(
                    4000,
                    esc_html__( 'A new user was created', 'mainwp-child' ),
                    __( 'A new user %NewUserData->Username% is created via registration.', 'mainwp-child' ),
                    'user',
                    'created',
                ),
                array(
                    4001,
                    esc_html__( 'User created a new user', 'mainwp-child' ),
                    __( 'Created the new user: %NewUserData->Username%.', 'mainwp-child' ),
                    'user',
                    'created',
                ),
                array(
                    4002,
                    esc_html__( 'Change the role of a user', 'mainwp-child' ),
                    esc_html__( 'Changed the role of user %TargetUsername% to %NewRole%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4003,
                    esc_html__( 'Changed the password', 'mainwp-child' ),
                    esc_html__( 'Changed the password.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4004,
                    esc_html__( 'Changed the password of a user', 'mainwp-child' ),
                    __( 'Changed the password of the user %TargetUserData->Username%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4005,
                    esc_html__( 'Changed the email address', 'mainwp-child' ),
                    esc_html__( 'Changed the email address to %NewEmail%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4006,
                    esc_html__( 'Changed the email address of a user', 'mainwp-child' ),
                    esc_html__( 'Changed the email address of the user %TargetUsername% to %NewEmail%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4007,
                    esc_html__( 'Deleted a user', 'mainwp-child' ),
                    __( 'Deleted the user %TargetUserData->Username%.', 'mainwp-child' ),
                    'user',
                    'deleted',
                ),
                array(
                    4008,
                    esc_html__( 'Granted super admin privileges to a user', 'mainwp-child' ),
                    esc_html__( 'Granted Super Admin privileges to the user %TargetUsername%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4009,
                    esc_html__( 'Revoked super admin privileges from a user', 'mainwp-child' ),
                    esc_html__( 'Revoked Super Admin privileges from %TargetUsername%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4012,
                    esc_html__( 'Added a network user to a site', 'mainwp-child' ),
                    __( 'Created the new network user %NewUserData->Username%.', 'mainwp-child' ),
                    'user',
                    'created',
                ),
                array(
                    4011,
                    esc_html__( 'Removed a network user from a site', 'mainwp-child' ),
                    esc_html__( 'Removed user %TargetUsername% from the site %SiteName%', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4010,
                    esc_html__( 'Created a new network user', 'mainwp-child' ),
                    esc_html__( 'Added user %TargetUsername% to the site %SiteName%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4013,
                    esc_html__( 'User has been activated on the network', 'mainwp-child' ),
                    __( 'User %NewUserData->Username% has been activated.', 'mainwp-child' ),
                    'user',
                    'activated',
                ),
                array(
                    4014,
                    esc_html__( 'Opened the profile page of a user', 'mainwp-child' ),
                    esc_html__( 'Opened the profile page of user %TargetUsername%.', 'mainwp-child' ),
                    'user',
                    'opened',
                ),
                array(
                    4015,
                    esc_html__( 'Changed a custom field value in user profile', 'mainwp-child' ),
                    esc_html__( 'Changed the value of the custom field %custom_field_name% in the user profile %TargetUsername%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4016,
                    esc_html__( 'Created a custom field in a user profile', 'mainwp-child' ),
                    esc_html__( 'Created the custom field %custom_field_name% in the user profile %TargetUsername%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4017,
                    esc_html__( 'Changed the first name (of a user)', 'mainwp-child' ),
                    esc_html__( 'Changed the first name of the user %TargetUsername% to %new_firstname%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4018,
                    esc_html__( 'Changed the last name (of a user)', 'mainwp-child' ),
                    esc_html__( 'Changed the last name of the user %TargetUsername% to %new_lastname%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4019,
                    esc_html__( 'Changed the nickname (of a user)', 'mainwp-child' ),
                    esc_html__( 'Changed the nickname of the user %TargetUsername% to %new_nickname%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4020,
                    esc_html__( 'Changed the display name (of a user)', 'mainwp-child' ),
                    esc_html__( 'Changed the display name of the user %TargetUsername% to %new_displayname%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4021,
                    esc_html__( 'Changed the website URL of the user', 'mainwp-child' ),
                    esc_html__( 'Changed the website URL of the user %TargetUsername% to %new_url%.', 'mainwp-child' ),
                    'user',
                    'modified',
                ),
                array(
                    4025,
                    esc_html__( 'User added / removed application password from own profile', 'mainwp-child' ),
                    esc_html__( 'The application password %friendly_name%.', 'mainwp-child' ),
                    'user',
                    'added',
                ),
                array(
                    4026,
                    esc_html__( 'User added / removed application password from another user’s profile', 'mainwp-child' ),
                    esc_html__( 'The application password %friendly_name% for the user %login%.', 'mainwp-child' ),
                    'user',
                    'added',
                ),
                array(
                    4028,
                    esc_html__( 'User revoked all application passwords from own profile', 'mainwp-child' ),
                    esc_html__( 'All application passwords from the user %login%.', 'mainwp-child' ),
                    'user',
                    'revoked',
                ),
                array(
                    4027,
                    esc_html__( 'User revoked all application passwords from another user’s profile', 'mainwp-child' ),
                    esc_html__( 'All application passwords.', 'mainwp-child' ),
                    'user',
                    'revoked',
                ),
            ),
            esc_html__( 'Database', 'mainwp-child' )       => array(
                array(
                    5010,
                    esc_html__( 'Plugin created database table(s)', 'mainwp-child' ),
                    __( 'The plugin %Plugin->Name% created this table in the database.', 'mainwp-child' ),
                    'database',
                    'created',
                ),
                array(
                    5011,
                    esc_html__( 'Plugin modified the structure of database table(s)', 'mainwp-child' ),
                    __( 'The plugin %Plugin->Name% modified the structure of a database table.', 'mainwp-child' ),
                    'database',
                    'modified',
                ),
                array(
                    5012,
                    esc_html__( 'Plugin deleted database table(s)', 'mainwp-child' ),
                    __( 'The plugin %Plugin->Name% deleted this table from the database.', 'mainwp-child' ),
                    'database',
                    'deleted',
                ),
                array(
                    5013,
                    esc_html__( 'Theme created database table(s)', 'mainwp-child' ),
                    __( 'The theme %Theme->Name% created this tables in the database.', 'mainwp-child' ),
                    'database',
                    'created',
                ),
                array(
                    5014,
                    esc_html__( 'Theme modified the structure of table(s) in the database', 'mainwp-child' ),
                    __( 'The theme %Theme->Name% modified the structure of this database table', 'mainwp-child' ),
                    'database',
                    'modified',
                ),
                array(
                    5015,
                    esc_html__( 'Theme deleted database table(s)', 'mainwp-child' ),
                    __( 'The theme %Theme->Name% deleted this table from the database.', 'mainwp-child' ),
                    'database',
                    'deleted',
                ),
                array(
                    5016,
                    esc_html__( 'Unknown component created database table(s)', 'mainwp-child' ),
                    esc_html__( 'An unknown component created these tables in the database.', 'mainwp-child' ),
                    'database',
                    'created',
                ),
                array(
                    5017,
                    esc_html__( 'Unknown component modified the structure of table(s )in the database', 'mainwp-child' ),
                    esc_html__( 'An unknown component modified the structure of these database tables.', 'mainwp-child' ),
                    'database',
                    'modified',
                ),
                array(
                    5018,
                    esc_html__( 'Unknown component deleted database table(s)', 'mainwp-child' ),
                    esc_html__( 'An unknown component deleted these tables from the database.', 'mainwp-child' ),
                    'database',
                    'deleted',
                ),
            ),
            esc_html__( 'System setting', 'mainwp-child' ) => array(
                array(
                    6001,
                    esc_html__( 'Changed the option anyone can register', 'mainwp-child' ),
                    __( 'The <strong>Membership</strong> setting <strong>Anyone can register</strong>.', 'mainwp-child' ),
                    'system-setting',
                    'enabled',
                ),
                array(
                    6002,
                    esc_html__( 'Changed the new user default role', 'mainwp-child' ),
                    __( 'Changed the <strong>New user default role</strong> WordPress setting.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6003,
                    esc_html__( 'Changed the WordPress administrator notification email address', 'mainwp-child' ),
                    __( 'Change the <strong>Administrator email address</strong> in the WordPress settings.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6005,
                    esc_html__( 'Changed the WordPress permalinks', 'mainwp-child' ),
                    __( 'Changed the <strong>WordPress permalinks</strong>.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6008,
                    esc_html__( 'Changed the setting: Discourage search engines from indexing this site', 'mainwp-child' ),
                    __( 'Changed the status of the WordPress setting <strong>Search engine visibility</strong> (Discourage search engines from indexing this site)', 'mainwp-child' ),
                    'system-setting',
                    'enabled',
                ),
                array(
                    6009,
                    esc_html__( 'Enabled / disabled comments on the website', 'mainwp-child' ),
                    __( 'Changed the status of the WordPress setting <strong>Allow people to submit comments on new posts</strong>.', 'mainwp-child' ),
                    'system-setting',
                    'enabled',
                ),

                array(
                    6010,
                    esc_html__( 'Changed the setting: Comment author must fill out name and email', 'mainwp-child' ),
                    __( 'Changed the status of the WordPress setting <strong>.Comment author must fill out name and email</strong>.', 'mainwp-child' ),
                    'system-setting',
                    'enabled',
                ),
                array(
                    6011,
                    esc_html__( 'Changed the setting: Users must be logged in and registered to comment', 'mainwp-child' ),
                    __( 'Changed the status of the WordPress setting <strong>Users must be registered and logged in to comment</strong>.', 'mainwp-child' ),
                    'system-setting',
                    'enabled',
                ),
                array(
                    6012,
                    esc_html__( 'Changed the setting: Automatically close comments after a number of days', 'mainwp-child' ),
                    __( 'Changed the status of the WordPress setting <strong>Automatically close comments after %Value% days</strong>.', 'mainwp-child' ),
                    'system-setting',
                    'enabled',
                ),
                array(
                    6013,
                    esc_html__( 'Changed the value of the setting: Automatically close comments after a number of days.', 'mainwp-child' ),
                    __( 'Changed the value of the WordPress setting <strong>Automatically close comments after a number of days</strong> to %NewValue%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6014,
                    esc_html__( 'Changed the setting: Comments must be manually approved', 'mainwp-child' ),
                    __( 'Changed the value of the WordPress setting <strong>Comments must be manualy approved</strong>.', 'mainwp-child' ),
                    'system-setting',
                    'enabled',
                ),
                array(
                    6015,
                    esc_html__( 'Changed the setting: Author must have previously approved comments for the comments to appear', 'mainwp-child' ),
                    __( 'Changed the value of the WordPress setting <strong>Comment author must have a previously approved comment</strong>.', 'mainwp-child' ),
                    'system-setting',
                    'enabled',
                ),
                array(
                    6016,
                    esc_html__( 'Changed the minimum number of links that a comment must have to be held in the queue', 'mainwp-child' ),
                    __( 'Changed the value of the WordPress setting <strong>Hold a comment in the queue if it contains links</strong> to %NewValue% links.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6017,
                    esc_html__( 'Modified the list of keywords for comments moderation', 'mainwp-child' ),
                    esc_html__( 'Modified the list of keywords for comments moderation in WordPress.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6018,
                    esc_html__( 'Modified the list of keywords for comments blacklisting', 'mainwp-child' ),
                    __( 'Modified the list of <strong>Disallowed comment keys</strong> (keywords) for comments blacklisting in WordPress.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6024,
                    esc_html__( 'Changed the WordPress address (URL)', 'mainwp-child' ),
                    __( 'Changed the <strong>WordPress address (URL)</strong> to %new_url%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6025,
                    esc_html__( 'Changed the site address (URL)', 'mainwp-child' ),
                    __( 'Changed the <strong>Site address (URL)</strong> to %new_url%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6035,
                    esc_html__( 'Changed the “Your homepage displays” WordPress setting', 'mainwp-child' ),
                    __( 'Changed the <strong>Your homepage displays</strong> WordPress setting to %new_homepage%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6036,
                    esc_html__( 'Changed the homepage in the WordPress setting', 'mainwp-child' ),
                    __( 'Changed the <strong>Homepage</strong> in the WordPress settings to %new_page%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6037,
                    esc_html__( 'Changed the posts page in the WordPress settings', 'mainwp-child' ),
                    __( 'Changed the <strong> Posts</strong>  page in the WordPress settings to %new_page%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),

                array(
                    6040,
                    esc_html__( 'Changed the Timezone in the WordPress settings', 'mainwp-child' ),
                    __( 'Changed the <strong>Timezone</strong> in the WordPress settings to %new_timezone%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6041,
                    esc_html__( 'Changed the Date format in the WordPress settings', 'mainwp-child' ),
                    __( 'Changed the <strong>Date format</strong> in the WordPress settings to %new_date_format%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6042,
                    esc_html__( 'Changed the Time format in the WordPress settings', 'mainwp-child' ),
                    __( 'Changed the <strong>Time format</strong> in the WordPress settings to %new_time_format%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6044,
                    esc_html__( 'User changed the WordPress automatic update settings', 'mainwp-child' ),
                    __( 'Changed the <strong>Automatic updates</strong> setting.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6045,
                    esc_html__( 'Changed the site language', 'mainwp-child' ),
                    __( 'Changed the <strong>Site Language</strong> to %new_value%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6059,
                    esc_html__( 'Changed the site title', 'mainwp-child' ),
                    __( 'Changed the <strong>Site Title</strong> to %new_value%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
                array(
                    6063,
                    esc_html__( 'Added site icon', 'mainwp-child' ),
                    __( 'Added a new website Site Icon %filename%.', 'mainwp-child' ),
                    'system-setting',
                    'added',
                ),
                array(
                    6064,
                    esc_html__( 'Changed site icon', 'mainwp-child' ),
                    __( 'Changed the Site Icon from %old_filename% to %filename%.', 'mainwp-child' ),
                    'system-setting',
                    'modified',
                ),
            ),
            esc_html__( 'WordPress Cron', 'mainwp-child' ) => array(
                array(
                    6066,
                    esc_html__( 'New one time task (cron job) created', 'mainwp-child' ),
                    __( 'A new one-time task called %task_name% has been scheduled.', 'mainwp-child' ),
                    'cron-job',
                    'created',
                ),
                array(
                    6067,
                    esc_html__( 'New recurring task (cron job) created', 'mainwp-child' ),
                    __( 'A new recurring task (cron job) called %task_name% has been created.', 'mainwp-child' ),
                    'cron-job',
                    'created',
                ),
                array(
                    6068,
                    esc_html__( 'Recurring task (cron job) modified', 'mainwp-child' ),
                    __( 'The schedule of recurring task (cron job) called %task_name% has changed.', 'mainwp-child' ),
                    'cron-job',
                    'modified',
                ),
                array(
                    6069,
                    esc_html__( 'One time task (cron job) executed', 'mainwp-child' ),
                    __( 'The one-time task called %task_name% has been executed.', 'mainwp-child' ),
                    'cron-job',
                    'executed',
                ),
                array(
                    6070,
                    esc_html__( 'Recurring task (cron job) executed', 'mainwp-child' ),
                    __( ' The recurring task (cron job) called %task_name% has been executed.', 'mainwp-child' ),
                    'cron-job',
                    'executed',
                ),
                array(
                    6071,
                    esc_html__( 'Deleted one-time task (cron job)', 'mainwp-child' ),
                    __( 'The one-time task  (cron job) called %task_name% has been deleted.', 'mainwp-child' ),
                    'cron-job',
                    'deleted',
                ),
                array(
                    6072,
                    esc_html__( 'Deleted recurring task (cron job)', 'mainwp-child' ),
                    __( 'The recurring task (cron job) called %task_name% has been deleted.', 'mainwp-child' ),
                    'cron-job',
                    'deleted',
                ),
            ),
        );

        return $changes_default_logs;
    }
}
