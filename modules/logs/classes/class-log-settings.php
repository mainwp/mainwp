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
            $this->options['enabled']          = isset( $_POST['mainwp_module_log_enabled'] ) && ! empty( $_POST['mainwp_module_log_enabled'] ) ? 1 : 0;
            $this->options['records_logs_ttl'] = isset( $_POST['mainwp_module_log_records_ttl'] ) ? intval( $_POST['mainwp_module_log_records_ttl'] ) : 3 * YEAR_IN_SECONDS;
            $this->options['auto_archive']     = isset( $_POST['mainwp_module_log_enable_auto_archive'] ) && ! empty( $_POST['mainwp_module_log_enable_auto_archive'] ) ? 1 : 0;
            MainWP_Utility::update_option( 'mainwp_module_log_settings', $this->options );

            $logs_data = array(
                'dashboard'        => array(),
                'nonmainwpchanges' => array(),
            );

            if ( isset( $_POST['mainwp_settings_logs_data'] ) && is_array( $_POST['mainwp_settings_logs_data'] ) ) {
                $selected_data = $_POST['mainwp_settings_logs_data']; //phpcs:ignore -- NOSONAR -ok.
                foreach ( array( 'dashboard', 'nonmainwpchanges' ) as $type ) {
                    $selected_type = isset( $selected_data[ $type ] ) && is_array( $selected_data[ $type ] ) ? array_map( 'sanitize_text_field', wp_unslash( $selected_data[ $type ] ) ) : array();
                    foreach ( $selected_type as $name ) {
                        $logs_data[ $type ][ $name ] = 1;
                    }
                }
            }

            if ( isset( $_POST['mainwp_settings_logs_name'] ) && is_array( $_POST['mainwp_settings_logs_name'] ) ) {
                $name_data = $_POST['mainwp_settings_logs_name']; //phpcs:ignore -- NOSONAR -ok.
                foreach ( array( 'dashboard', 'nonmainwpchanges' ) as $type ) {
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
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Enable auto archive', 'mainwp' ); ?></label>
                            <div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements"  hide-parent="auto-archive" data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will auto archive logs.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" name="mainwp_module_log_enable_auto_archive" id="mainwp_module_log_enable_auto_archive" <?php echo $enabled_auto_archive ? 'checked="true"' : ''; ?> /><label><?php esc_html_e( 'Default: Off', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-general"  <?php echo $enabled && $enabled_auto_archive ? '' : 'style="display:none"'; ?> hide-element="auto-archive" default-indi-value="<?php echo 3 * YEAR_IN_SECONDS; ?>">
                            <label class="six wide column middle aligned">
                                <?php
                                $records_ttl = $this->options['records_logs_ttl'];
                                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_module_log_records_ttl', $records_ttl, true, 3 * YEAR_IN_SECONDS );
                                esc_html_e( 'Keep records for', 'mainwp' );
                                ?>
                                </label>
                                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Maximum number of days to keep activity records.', 'mainwp' ); ?>" data-inverted="" data-position="top left" >
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

        if ( ! in_array( $type, array( 'dashboard', 'nonmainwpchanges' ) ) ) {
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
            esc_html_e( 'Select sites changes to logging', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column" <?php echo $setting_page ? 'data-tooltip="' . esc_attr__( 'Select sites changes that you want to logs.', 'mainwp' ) . '"' : ''; ?> data-inverted="" data-position="top left">
                <?php
                foreach ( $list_logs as $type => $items ) {
                    ?>
                    <div class="ui header"><?php echo 'dashboard' === $type ? esc_html__( 'Dashboard', 'mainwp' ) : esc_html__( 'Non mainwp changes', 'mainwp' ); ?></div>
                    <ul class="mainwp_hide_wpmenu_checkboxes">
                    <?php
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
                'sites_added'           => __( 'Site added', 'mainwp' ), // site::added.
                'sites_updated'         => __( 'Site updated', 'mainwp' ),  // site::updated.
                'sites_sync'            => __( 'Site sync', 'mainwp' ),  // site::synced.
                'sites_deleted'         => __( 'Site deleted', 'mainwp' ),  // site::deleted.
                'sites_reconnect'       => __( 'Site reconnect', 'mainwp' ),  // site::reconnected.
                'sites_suspend'         => __( 'Site suspend', 'mainwp' ),  // site::updated suspended value.
                'sites_unsuspend'       => __( 'Site unsuspend', 'mainwp' ),  // site::updated suspended value.

                'tags_created'          => __( 'Tag created', 'mainwp' ),
                'tags_deleted'          => __( 'Tag deleted', 'mainwp' ),
                'tags_updated'          => __( 'Tag updated', 'mainwp' ),

                'theme_install'         => __( 'Install themes', 'mainwp' ),
                'theme_activate'        => __( 'Active themes', 'mainwp' ),
                'theme_deactivate'      => __( 'Deactive themes', 'mainwp' ),
                'theme_update'          => __( 'Update themes', 'mainwp' ),
                'theme_switch'          => __( 'Switch themes', 'mainwp' ),
                'theme_delete'          => __( 'Delete themes', 'mainwp' ),

                'plugin_install'        => __( 'Install plugins', 'mainwp' ),
                'plugin_activate'       => __( 'Active plugins', 'mainwp' ),
                'plugin_deactivate'     => __( 'Deactive plugins', 'mainwp' ),
                'plugin_updated'        => __( 'Update plugins', 'mainwp' ),
                'plugin_delete'         => __( 'Delete plugins', 'mainwp' ),

                'translation_updated'   => __( 'Translation update', 'mainwp' ),
                'core_updated'          => __( 'WordPress updated', 'mainwp' ),

                'post_created'          => __( 'Posts created', 'mainwp' ),
                'post_published'        => __( 'Posts publish', 'mainwp' ),
                'post_unpublished'      => __( 'Posts unpublish', 'mainwp' ),
                'post_updated'          => __( 'Posts updated', 'mainwp' ),
                'post_trashed'          => __( 'Posts trashed', 'mainwp' ),
                'post_deleted'          => __( 'Posts deleted', 'mainwp' ),
                'post_restored'         => __( 'Posts restored', 'mainwp' ),

                'page_created'          => __( 'Pages created', 'mainwp' ),
                'page_published'        => __( 'Pages publish', 'mainwp' ),
                'page_unpublished'      => __( 'Pages unpublish', 'mainwp' ),
                'page_updated'          => __( 'Pages updated', 'mainwp' ),
                'page_trashed'          => __( 'Pages trashed', 'mainwp' ),
                'page_deleted'          => __( 'Pages deleted', 'mainwp' ),
                'page_restored'         => __( 'Pages restored', 'mainwp' ),

                'clients_created'       => __( 'Client created', 'mainwp' ),
                'clients_updated'       => __( 'Client updated', 'mainwp' ),
                'clients_suspend'       => __( 'Client suspend', 'mainwp' ),
                'clients_unsuspend'     => __( 'Client unsuspend', 'mainwp' ),
                'clients_lead'          => __( 'Client lead', 'mainwp' ),
                'clients_lost'          => __( 'Client lost', 'mainwp' ),

                'users_created'         => __( 'User created', 'mainwp' ),
                'users_update'          => __( 'User update', 'mainwp' ),
                'users_delete'          => __( 'User delete', 'mainwp' ),
                'users_change_role'     => __( 'User change role', 'mainwp' ),
                'users_update_password' => __( 'Update admin password', 'mainwp' ),
            ),
            'nonmainwpchanges' => array(
                'theme_install'     => __( 'Install themes', 'mainwp' ),
                'theme_activate'    => __( 'Active themes', 'mainwp' ),
                'theme_deactivate'  => __( 'Deactive themes', 'mainwp' ),
                'theme_updated'     => __( 'Update themes', 'mainwp' ),
                'theme_switch'      => __( 'Switch themes', 'mainwp' ),
                'theme_delete'      => __( 'Delete themes', 'mainwp' ),
                'plugin_install'    => __( 'Install plugins', 'mainwp' ),
                'plugin_activate'   => __( 'Active plugins', 'mainwp' ),
                'plugin_deactivate' => __( 'Deactive plugins', 'mainwp' ),
                'plugin_updated'    => __( 'Update plugins', 'mainwp' ),
                'plugin_delete'     => __( 'Delete plugins', 'mainwp' ),
                'core_updated'      => __( 'WordPress updated', 'mainwp' ),
            ),
        );

        if ( 'unlogs' === $type ) {
            return $init_un_logs;
        }

        return $logs;
    }
}
