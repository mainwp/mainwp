<?php
/**
 * =======================================
 * MainWP API Backups Admin
 * =======================================
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

use MainWP\Dashboard\MainWP_Settings_Indicator;


/**
 * MainWP API Backups Admin
 */
class Api_Backups_Settings {

    /**
     * Static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Api_Backups_Settings
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Constructor
     *
     * Runs each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_init', array( &$this, 'admin_init' ) );
        add_action( 'mainwp_manage_sites_edit', array( &$this, 'hook_render_mainwp_manage_sites_edit' ), 10, 2 );
        add_action( 'mainwp_update_site', array( &$this, 'hook_mainwp_update_site' ), 10, 2 );
    }

    /**
     * Initiate Hooks
     *
     * Initiates hooks for the API Backups extension.
     */
    public function admin_init() {
    }

    /**
     * Render settings
     *
     * Renders the settings page.
     */
    public function render_settings_page() {

        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_api_backups' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage api backups', 'mainwp' ) );
            return;
        }

        do_action( 'mainwp_pageheader_settings', 'ApiBackups' );

        ?>
        <div id="mainwp-module-log-settings-wrapper">
            <?php
            if ( isset( $_GET['message'] ) && ! empty( $_GET['message'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $message = esc_html__( 'Settings saved.', 'mainwp' );
                ?>
                <div class="ui green message" id="mainwp-module-api-backups-message-zone" >
                    <?php echo esc_html( $message ); ?>
                    <i class="ui close icon"></i>
                </div>
                <?php
            }
            ?>
            <form id="mainwp-module-api-backups-settings-form" method="post" action="admin.php?page=SettingsApiBackups" class="ui form">
                <?php $this->render_settings_content(); ?>
            </form>
        </div>
        <?php
        do_action( 'mainwp_pagefooter_settings', 'ApiBackups' );
    }

    /**
     * Render settings
     *
     * Renders the extension settings page.
     *
     * @param bool $individual Individual settings True|False.
     */
    public function render_settings_content( $individual = false ) {
        static::render_3rd_party_api_manager( $individual );
    }

    /**
     * Render 3rd party api settings
     *
     * Renders the extension settings page.
     *
     * @param bool $individual Individual settings True|False.
     */
    public static function render_3rd_party_api_manager( $individual = false  ) { //phpcs:ignore -- NOSONAR - complex method.
        $_nonce_slug = $individual ? 'cloudways_api_form_individual' : 'cloudways_api_form_general';
        ?>
        <div id="3rd-party-api-manager">
            <div class="ui segment">
                
                <div class="ui grid">
                    <div class="three wide column">
                        <div class="ui vertical fluid pointing menu">
                            <h3 class="item ui header"><?php esc_html_e( 'Backup API Providers', 'mainwp' ); ?></h3>
                            <a class="item active" data-tab="cloudways">
                                <?php esc_html_e( 'Cloudways', 'mainwp' ); ?>
                            </a>
                            <a class="item" data-tab="gridpane">
                                <?php esc_html_e( 'GridPane', 'mainwp' ); ?>
                            </a>
                            <a class="item" data-tab="vultr">
                                <?php echo esc_html__( 'Vultr', 'mainwp' ); ?>
                            </a>
                            <a class="item" data-tab="linode">
                                <?php echo esc_html__( 'Akamai (Linode)', 'mainwp' ); ?>
                            </a>
                            <a class="item" data-tab="digitalocean">
                                <?php echo esc_html__( 'DigitalOcean', 'mainwp' ); ?>
                            </a>
                            <a class="item" data-tab="cpanel">
                                <?php echo esc_html__( 'cPanel (WP Toolkit)', 'mainwp' ); ?>
                            </a>
                            <a class="item" data-tab="plesk">
                                <?php echo esc_html__( 'Plesk (WP Toolkit)', 'mainwp' ); ?>
                            </a>
                            <a class="item" data-tab="kinsta">
                                <?php echo esc_html__( 'Kinsta', 'mainwp' ); ?>
                            </a>
                        </div>
                    </div>
                    <div class="thirteen wide column">
                        <?php if ( Api_Backups_Utility::show_mainwp_message( 'mainwp-module-api-backups-manager-info-message' ) ) : ?>
                            <div class="ui blue message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-manager-info-message"></i>
                                <?php
                                printf(
                                    esc_html__(
                                        'This feature allows you to trigger and restore backups only for sites hosted on supported providers via their API. Backups cannot be created for sites hosted elsewhere or stored on these services if your site is not hosted with them. Check this %1$shelp document%2$s to see all available services & the endpoints that MainWP currently supports.',
                                        'mainwp'
                                    ),
                                    '<a href="https://mainwp.com/kb/api-backups-extension/" target="_blank">', // NOSONAR - noopener - open safe.
                                    '</a> <i class="external alternate icon"></i>'
                                );
                                ?>
                            </div>
                        <?php endif; ?>

                        <?php
                        // Save CloudWays Data.
                        //phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                        ?>
                        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce_cloudways'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce_cloudways'] ), 'cloudways_api_form_general' ) ) : ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_enable_cloudways_api', ( ! isset( $_POST['mainwp_enable_cloudways_api'] ) ? 0 : 1 ) ); ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_cloudways_api_account_email', ( isset( $_POST['mainwp_cloudways_api_account_email'] ) ? wp_unslash( $_POST['mainwp_cloudways_api_account_email'] ) : '' ) ); ?>
                            <?php Api_Backups_Utility::get_instance()->update_api_key( 'cloudways', ( isset( $_POST['mainwp_cloudways_api_key'] ) ? wp_unslash( $_POST['mainwp_cloudways_api_key'] ) : '' ) ); ?>
                            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'API credentials have been successfully saved.', 'mainwp' ); ?></div>
                            <?php Api_Backups_3rd_Party::cloudways_action_update_ids(); ?>
                        <?php endif; ?>
                        <?php // END Save CloudWays Data. ?>
                        <?php // Save GridPane Data. ?>
                        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce_gridpane'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce_gridpane'] ), 'cloudways_api_form_general' ) ) : ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_enable_gridpane_api', ( ! isset( $_POST['mainwp_enable_gridpane_api'] ) ? 0 : 1 ) ); ?>
                            <?php Api_Backups_Utility::get_instance()->update_api_key( 'gridpane', ( isset( $_POST['mainwp_gridpane_api_key'] ) ? wp_unslash( $_POST['mainwp_gridpane_api_key'] ) : '' ) ); ?>
                            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'API credentials have been successfully saved.', 'mainwp' ); ?></div>
                            <?php Api_Backups_3rd_Party::gridpane_action_update_ids(); ?>
                        <?php endif; ?>
                        <?php // END Save GridPane Data. ?>
                        <?php // Save Vultr Data. ?>
                        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce_vultr'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce_vultr'] ), 'cloudways_api_form_general' ) ) : ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_enable_vultr_api', ( ! isset( $_POST['mainwp_enable_vultr_api'] ) ? 0 : 1 ) ); ?>
                            <?php Api_Backups_Utility::get_instance()->update_api_key( 'vultr', ( isset( $_POST['mainwp_vultr_api_key'] ) ? wp_unslash( $_POST['mainwp_vultr_api_key'] ) : '' ) ); ?>
                            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'API credentials have been successfully saved.', 'mainwp' ); ?></div>
                        <?php endif; ?>
                        <?php // END Save Vultr Data. ?>
                        <?php // Save Linode Data. ?>
                        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce_linode'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce_linode'] ), 'cloudways_api_form_general' ) ) : ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_enable_linode_api', ( ! isset( $_POST['mainwp_enable_linode_api'] ) ? 0 : 1 ) ); ?>
                            <?php Api_Backups_Utility::get_instance()->update_api_key( 'linode', ( isset( $_POST['mainwp_linode_api_key'] ) ? wp_unslash( $_POST['mainwp_linode_api_key'] ) : '' ) ); ?>
                            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'API credentials have been successfully saved.', 'mainwp' ); ?></div>
                        <?php endif; ?>
                        <?php // END Save Linode Data. ?>
                        <?php // Save DigitalOcean Data. ?>
                        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce_digitalocean'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce_digitalocean'] ), 'cloudways_api_form_general' ) ) : ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_enable_digitalocean_api', ( ! isset( $_POST['mainwp_enable_digitalocean_api'] ) ? 0 : 1 ) ); ?>
                            <?php Api_Backups_Utility::get_instance()->update_api_key( 'digitalocean', ( isset( $_POST['mainwp_digitalocean_api_key'] ) ? wp_unslash( $_POST['mainwp_digitalocean_api_key'] ) : '' ) ); ?>
                            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'API credentials have been successfully saved.', 'mainwp' ); ?></div>
                        <?php endif; ?>
                        <?php // END Save Linode Data. ?>
                        <?php // Save cPanel Data. ?>
                        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce_cpanel'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce_cpanel'] ), 'cloudways_api_form_general' ) ) : ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_enable_cpanel_api', ( ! isset( $_POST['mainwp_enable_cpanel_api'] ) ? 0 : 1 ) ); ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_cpanel_url', ( isset( $_POST['mainwp_cpanel_url'] ) ? wp_unslash( $_POST['mainwp_cpanel_url'] ) : '' ) ); ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_cpanel_site_path', ( isset( $_POST['mainwp_cpanel_site_path'] ) ? wp_unslash( $_POST['mainwp_cpanel_site_path'] ) : '' ) ); ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_cpanel_account_username', ( isset( $_POST['mainwp_cpanel_account_username'] ) ? wp_unslash( $_POST['mainwp_cpanel_account_username'] ) : '' ) ); ?>
                            <?php Api_Backups_Utility::get_instance()->update_api_key( 'cpanel', ( isset( $_POST['mainwp_cpanel_account_password'] ) ? wp_unslash( $_POST['mainwp_cpanel_account_password'] ) : '' ) ); ?>
                            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'API credentials have been successfully saved.', 'mainwp' ); ?></div>
                        <?php endif; ?>
                        <?php // END Save cPanel Data. ?>
                        <?php // Save Plesk Data. ?>
                        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce_plesk'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce_plesk'] ), 'cloudways_api_form_general' ) ) : ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_enable_plesk_api', ( ! isset( $_POST['mainwp_enable_plesk_api'] ) ? 0 : 1 ) ); ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_plesk_api_url', ( isset( $_POST['mainwp_plesk_api_url'] ) ? wp_unslash( $_POST['mainwp_plesk_api_url'] ) : '' ) ); ?>
                            <?php Api_Backups_Utility::get_instance()->update_api_key( 'plesk', ( isset( $_POST['mainwp_plesk_api_key'] ) ? wp_unslash( $_POST['mainwp_plesk_api_key'] ) : '' ) ); ?>
                            <?php
                            if ( isset( $_POST['mainwp_plesk_installation_id'] ) ) {
                                Api_Backups_Utility::update_option( 'mainwp_plesk_installation_id', sanitize_text_field( wp_unslash( $_POST['mainwp_plesk_installation_id'] ) ) );
                            }
                            ?>
                            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'API credentials have been successfully saved.', 'mainwp' ); ?></div>
                        <?php endif; ?>
                        <?php // END Save Plesk Data. ?>
                        <?php // Save Kinsta Data. ?>
                        <?php if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'kinsta_api_form' ) ) : ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_enable_kinsta_api', ( ! isset( $_POST['mainwp_enable_kinsta_api'] ) ? 0 : 1 ) ); ?>
                            <?php Api_Backups_Utility::get_instance()->update_api_key( 'kinsta', ! empty( $_POST['mainwp_kinsta_api_key'] ) ? wp_unslash( $_POST['mainwp_kinsta_api_key'] ) : '' ); ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_kinsta_api_account_email', ( isset( $_POST['mainwp_kinsta_api_account_email'] ) ? wp_unslash( $_POST['mainwp_kinsta_api_account_email'] ) : '' ) ); ?>
                            <?php Api_Backups_Utility::update_option( 'mainwp_kinsta_company_id', ( isset( $_POST['mainwp_kinsta_company_id'] ) ? wp_unslash( $_POST['mainwp_kinsta_company_id'] ) : '' ) ); ?>
                            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'API credentials have been successfully saved.', 'mainwp' ); ?></div>
                        <?php endif; ?>
                        <?php // END Save Kinsta Data. ?>

                        <?php // Build Cloudways API Form. ?>
                        <div class="ui tab segment active" data-tab="cloudways">
                            <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-cloudways-settings' ); ?>
                            <?php esc_html_e( 'Cloudways API Settings', 'mainwp' ); ?></h3>
                            <ul>
                                <li><?php printf( esc_html__( "1. If you don't already have one, get a %s", 'mainwp' ), '<a target="_blank" href="https://mainwp.com/go/cloudways-mainwp/">Cloudways account</a>' ); // NOSONAR - noopener - open safe. ?></li>
                                <li><?php printf( esc_html__( '2. Get your API Key from here: %s', 'mainwp' ), '<a target="_blank" href="https://platform.cloudways.com/api">https://platform.cloudways.com/api</a>' ); // NOSONAR - noopener - open safe. ?></li>
                                <li><?php esc_html_e( '3. Enter in your account email address and API Key below.', 'mainwp' ); ?></li>
                            </ul>
                            <div class="ui hidden divider"></div>
                            <div class="ui form">
                                <form method="POST" action="">
                                    <?php
                                    wp_nonce_field( 'mainwp-admin-nonce' );
                                    ?>
                                    <input type="hidden" name="wp_nonce_cloudways" value="<?php echo esc_attr( wp_create_nonce( $_nonce_slug ) ); ?>" />
                                    <?php
                                    /**
                                     * Action: cloudways_api_form_top
                                     *
                                     * Fires at the top of CloudWays API form.
                                     *
                                     * @since 4.1
                                     */
                                    do_action( 'cloudways_api_form_top' );
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cloudways-settings">
                                        <label class="six wide column middle aligned">
                                            <?php MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_enable_cloudways_api', 0 ) ); ?>
                                            <?php esc_html_e( 'Enable Cloudways API', 'mainwp' ); ?></label>
                                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the Cloudways API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_cloudways_api" id="mainwp_enable_cloudways_api" <?php echo 1 === (int) get_option( 'mainwp_enable_cloudways_api', 0 ) ? 'checked="true"' : ''; ?> />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cloudways-settings">
                                        <label class="six wide column middle aligned">
                                            <?php
                                            MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_cloudways_api_account_email' ) );
                                            esc_html_e( 'Account Email', 'mainwp' );
                                            ?>
                                            </label>
                                            <div class="five wide column">
                                                <input type="text" class="settings-field-value-change-handler" name="mainwp_cloudways_api_account_email" id="mainwp_cloudways_api_account_email" value="<?php echo false === get_option( 'mainwp_cloudways_api_account_email' ) ? '' : esc_attr( get_option( 'mainwp_cloudways_api_account_email' ) ); ?>"  />
                                            </div>
                                    </div>
                                    <?php
                                        $_api_key = Api_Backups_3rd_Party::get_cloudways_api_key();
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cloudways-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $_api_key );
                                        esc_html_e( 'API Key', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="password" class="settings-field-value-change-handler" name="mainwp_cloudways_api_key" id="mainwp_cloudways_api_key" value="<?php echo esc_attr( $_api_key ); ?>"  />
                                        </div>
                                    </div>
                                    <?php
                                    /**
                                     * Action: cloudways_api_form_bottom
                                     *
                                     * Fires at the bottom of CloudWays API form.
                                     *
                                     * @since 4.1
                                     */
                                    do_action( 'cloudways_api_form_bottom' );
                                    ?>
                                    <div class="ui divider"></div>
                                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                                    <div style="clear:both"></div>
                                </form>
                            </div>
                        </div>

                        <div class="ui tab segment" data-tab="gridpane">
                            <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-gridpane-settings' ); ?>
                            <?php esc_html_e( 'GridPane API Settings', 'mainwp' ); ?></h3>
                            <div class="ui info message"><?php printf( esc_html__( 'Must be the %1$sGridPane Owners Account%2$s &amp; have a %1$sDeveloper Plan or above%2$s in order to use this feature.', 'mainwp' ), '<b>', '</b>' ); ?></div>
                            <ul>
                                <li><?php printf( esc_html__( "1. If you don't already have one, get a %s", 'mainwp' ), '<a target="_blank" href="https://mainwp.com/go/gridpane/">GridPane account</a>' ); // NOSONAR - noopener - open safe. ?></li>
                                <li><?php printf( esc_html__( '2. Get your %1$sAPI Personal Access Token%2$s here: %3$s', 'mainwp' ), '<b>', '</b>', '<a target="_blank" href="https://my.gridpane.com/settings">https://my.gridpane.com/settings</a>' ); ?></li>
                                <li><?php printf( esc_html__( '3. %1$sClick GridPare API%2$s in the left hand menu &amp; %1$sCreate your Personal Access Token%2$s', 'mainwp' ), '<b>', '</b>' ); ?></li>
                                <li><?php printf( esc_html__( '4. Copy &amp; Paste your %1$sAPI Personal Access Token%2$s below', 'mainwp' ), '<b>', '</b>' ); ?></li>
                            </ul>
                            <div class="ui hidden divider settings-field-indicator-wrapper settings-field-indicator-gridpane-settings"></div>
                            <div class="ui form">
                                <form method="POST" action="">
                                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                                    <input type="hidden" name="wp_nonce_gridpane" value="<?php echo esc_attr( wp_create_nonce( $_nonce_slug ) ); ?>" />
                                    <?php
                                    /**
                                     * Action: gridpane_api_form_top
                                     *
                                     * Fires at the top of GridPane API form.
                                     *
                                     * @since 4.1
                                     */
                                    do_action( 'gridpane_api_form_top' );
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-gridpane-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_enable_gridpane_api', 0 ) );
                                        esc_html_e( 'Enable GridPane API', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the GridPane API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_gridpane_api" id="mainwp_enable_gridpane_api" <?php echo 1 === (int) get_option( 'mainwp_enable_gridpane_api', 0 ) ? 'checked="true"' : ''; ?> />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-gridpane-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        $_api_key = Api_Backups_3rd_Party::get_gridpane_api_key();
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $_api_key );
                                        esc_html_e( 'Personal Access Token', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="password" class="settings-field-value-change-handler" name="mainwp_gridpane_api_key" id="mainwp_gridpane_api_key" value="<?php echo esc_attr( $_api_key ); ?>"  />
                                        </div>
                                    </div>
                                    <?php
                                    /**
                                     * Action: gridpane_api_form_bottom
                                     *
                                     * Fires at the bottom of GridPane API form.
                                     *
                                     * @since 4.1
                                     */
                                    do_action( 'gridpane_api_form_bottom' );
                                    ?>
                                    <div class="ui divider"></div>
                                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                                    <div style="clear:both"></div>
                                </form>
                            </div>
                        </div>

                        <div class="ui tab segment" data-tab="vultr">
                            <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-vultr-settings' ); ?>
                                <?php esc_html_e( 'Vultr API Settings', 'mainwp' ); ?>
                            </h3>
                            <ul>
                                <li><?php printf( esc_html__( "1. If you don't already have one, get a %s", 'mainwp' ), '<a target="_blank" href="https://mainwp.com/go/vultr/">Vultr account</a>' ); // NOSONAR - noopener - open safe. ?></li>
                                <li><?php printf( esc_html__( '2. Use this %3$s to find your MainWP Dashboard IP Address %1$s"Mask Bits"%2$s', 'mainwp' ), '<b>', '</b>', '<a target="_blank" href="https://www.vultr.com/resources/subnet-calculator/">Subnet Calculator Tool</a>' ); ?></b></li>
                                <li><?php printf( esc_html__( '3. Navigate to %3$s and Whitelist your MainWP Dashboard %1$sIP/Mask Bits%2$s and %1$sActivate/Copy%2$s your %1$sAPI Key%2$s', 'mainwp' ), '<b>', '</b>', '<a target="_blank" href="https://my.vultr.com/settings/#settingsapi">Vultr API Settings</a>' ); ?></li>
                                <li><?php esc_html_e( '4. Paste in your account API Key below and click the Save Settings button.', 'mainwp' ); ?></li>
                                <li><?php esc_html_e( '5. Once the API is connected, go to the Site Edit page (for all sites on this host) and set the correct Provider and Instance ID.', 'mainwp' ); ?></li>
                            </ul>
                            <div class="ui hidden divider"></div>
                            <div class="ui form">
                                <form method="POST" action="">
                                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                                    <input type="hidden" name="wp_nonce_vultr" value="<?php echo esc_attr( wp_create_nonce( $_nonce_slug ) ); ?>" />
                                    <?php
                                        /**
                                         * Action: vultr_api_form_top
                                         *
                                         * Fires at the top of Vultr API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'vultr_api_form_top' );
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-vultr-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_enable_vultr_api', 0 ) );
                                        esc_html_e( 'Enable Vultr API', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the Vultr API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_vultr_api" id="mainwp_enable_vultr_api" <?php echo 1 === (int) get_option( 'mainwp_enable_vultr_api', 0 ) ? 'checked="true"' : ''; ?> />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-vultr-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        $_api_key = Api_Backups_3rd_Party::get_vultr_api_key();
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $_api_key );
                                        esc_html_e( 'API Key', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="password" class="settings-field-value-change-handler" name="mainwp_vultr_api_key" id="mainwp_vultr_api_key" value="<?php echo esc_attr( $_api_key ); ?>"  />
                                        </div>
                                    </div>
                                    <?php
                                        /**
                                         * Action: vultr_api_form_bottom
                                         *
                                         * Fires at the bottom of Vultr API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'vultr_api_form_bottom' );
                                    ?>
                                    <div class="ui divider"></div>
                                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                                    <div style="clear:both"></div>
                                </form>
                            </div>
                        </div>

                        <div class="ui tab segment" data-tab="linode">
                            <h3 class="ui dividing header">
                                <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-linode-settings' ); ?>
                                <?php esc_html_e( 'Akamai (Linode) API Settings', 'mainwp' ); ?>
                            </h3>
                            <ul>
                                <li><?php printf( esc_html__( "1. If you don't already have one, get a %s", 'mainwp' ), '<a target="_blank" href="https://mainwp.com/go/akamai-linode/">Akamai (Linode) account</a>' ); // NOSONAR - noopener - open safe. ?></li>
                                <li><?php printf( esc_html__( '2. You may create a %1$sPersonal Access Token%2$s by navigating here: %3$s', 'mainwp' ), '<b>', '</b>', '<a target="_blank" href="https://cloud.linode.com/profile/tokens">https://cloud.linode.com/profile/tokens</a>' ); ?></b></li>
                                <li><?php printf( esc_html__( '3. Paste in your account %1$sPersonal Access Token%2$s below and click the Save Settings button.', 'mainwp' ), '<b>', '</b>' ); ?></li>
                                <li><?php esc_html_e( '4. Once the API is connected, go to the Site Edit page (for all sites on this host) and set the correct Provider and Instance ID.', 'mainwp' ); ?></li>
                            </ul>
                            <div class="ui hidden divider"></div>
                            <div class="ui form">
                                <form method="POST" action="">
                                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                                    <input type="hidden" name="wp_nonce_linode" value="<?php echo esc_attr( wp_create_nonce( $_nonce_slug ) ); ?>" />
                                    <?php
                                        /**
                                         * Action: linode_api_form_top
                                         *
                                         * Fires at the top of Vultr API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'linode_api_form_top' );
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-linode-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_enable_linode_api', 0 ) );
                                        esc_html_e( 'Enable Akamai (Linode) API', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the Akamai (Linode) API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_linode_api" id="mainwp_enable_linode_api" <?php echo 1 === (int) get_option( 'mainwp_enable_linode_api', 0 ) ? 'checked="true"' : ''; ?> />
                                        </div>
                                    </div>
                                    <?php
                                    $_api_key = Api_Backups_3rd_Party::get_linode_api_key();
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-linode-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $_api_key );
                                        esc_html_e( 'API Key', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="password" class="settings-field-value-change-handler" name="mainwp_linode_api_key" id="mainwp_linode_api_key" value="<?php echo esc_attr( $_api_key ); ?>"  />
                                        </div>
                                    </div>
                                    <?php
                                        /**
                                         * Action: linode_api_form_bottom
                                         *
                                         * Fires at the bottom of Vultr API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'linode_api_form_bottom' );
                                    ?>
                                    <div class="ui divider"></div>
                                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                                    <div style="clear:both"></div>
                                </form>
                            </div>
                        </div>

                        <div class="ui tab segment" data-tab="digitalocean">
                            <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-digitalocean-settings' ); ?>
                                <?php esc_html_e( 'DigitalOcean', 'mainwp' ); ?>
                            </h3>
                            <ul>
                                <li><?php printf( esc_html__( "1. If you don't already have one, get a %s", 'mainwp' ), '<a target="_blank" href="https://mainwp.com/go/digital-ocean/">DigitalOcean account</a>' ); // NOSONAR - noopener - open safe. ?></li>
                                <li><?php printf( esc_html__( '2. You can generate an %1$sOAuth token%2$s by visiting the %3$s section of the DigitalOcean control panel for your account.', 'mainwp' ), '<b>', '</b>', '<a target="_blank" href="https://cloud.digitalocean.com/account/api/tokens">Apps & API</a>' ); ?></b></li>
                                <li><?php printf( esc_html__( '3. Paste in your %1$sPersonal Access Token%2$s below and click the Save Settings button.', 'mainwp' ), '<b>', '</b>' ); ?></li>
                                <li><?php esc_html_e( '4. Once the API is connected, go to the Site Edit page (for all sites on this host) and set the correct Provider and Instance ID.', 'mainwp' ); ?></li>
                            </ul>
                            <div class="ui hidden divider"></div>
                            <div class="ui form">
                                <form method="POST" action="">
                                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                                    <input type="hidden" name="wp_nonce_digitalocean" value="<?php echo esc_attr( wp_create_nonce( $_nonce_slug ) ); ?>" />
                                    <?php
                                        /**
                                         * Action: digitalocean_api_form_top
                                         *
                                         * Fires at the top of DigitalOcean API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'digitalocean_api_form_top' );
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-digitalocean-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_enable_digitalocean_api', 0 ) );
                                        esc_html_e( 'Enable DigitalOcean API', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the DigitalOcean API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_digitalocean_api" id="mainwp_enable_digitalocean_api" <?php echo 1 === (int) get_option( 'mainwp_enable_digitalocean_api', 0 ) ? 'checked="true"' : ''; ?> />
                                        </div>
                                    </div>
                                    <?php
                                    $_api_key = Api_Backups_3rd_Party::get_digitalocean_api_key();
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-digitalocean-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $_api_key );
                                        esc_html_e( 'API Key', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="password" class="settings-field-value-change-handler" name="mainwp_digitalocean_api_key" id="mainwp_digitalocean_api_key" value="<?php echo esc_attr( $_api_key ); ?>"  />
                                        </div>
                                    </div>
                                    <?php
                                        /**
                                         * Action: digitalocean_api_form_bottom
                                         *
                                         * Fires at the bottom of DigitalOcean API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'digitalocean_api_form_bottom' );
                                    ?>
                                    <div class="ui divider"></div>
                                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                                    <div style="clear:both"></div>
                                </form>
                            </div>
                        </div>

                        <div class="ui tab segment" data-tab="cpanel">
                            <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-cpanel-settings' ); ?>
                                <?php esc_html_e( 'cPanel (WP Toolkit)', 'mainwp' ); ?>
                            </h3>
                            <ul>
                                <li><?php esc_html_e( '1. Enter in your cPanel URL below. ( eg. https://my-site.com:2083 )', 'mainwp' ); ?></li>
                                <li><?php esc_html_e( '2. Enter in your cPanel account Username and Password below.', 'mainwp' ); ?></li>
                                <li><?php esc_html_e( 'This information can be provided by your Child Site&#39;s Host.', 'mainwp' ); ?></li>
                            </ul>
                            <div class="ui hidden divider"></div>
                            <div class="ui form">
                                <form method="POST" action="">
                                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                                    <input type="hidden" name="wp_nonce_cpanel" value="<?php echo esc_attr( wp_create_nonce( $_nonce_slug ) ); ?>" />
                                    <?php
                                    /**
                                     * Action: cpanel_api_form
                                     *
                                     * Fires at the top of cPanel API form.
                                     *
                                     * @since 4.1
                                     */
                                    do_action( 'cpanel_api_form' );
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cpanel-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_enable_cpanel_api', 0 ) );
                                        esc_html_e( 'Enable cPanel API', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the cPanel API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_cpanel_api" id="mainwp_enable_cpanel_api" <?php echo 1 === (int) get_option( 'mainwp_enable_cpanel_api', 0 ) ? 'checked="true"' : ''; ?> />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cpanel-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_cpanel_url' ) );
                                        esc_html_e( 'cPanel URL', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="text" class="settings-field-value-change-handler" name="mainwp_cpanel_url" id="mainwp_cpanel_url" value="<?php echo false === get_option( 'mainwp_cpanel_url' ) ? '' : esc_attr( get_option( 'mainwp_cpanel_url' ) ); ?>"  />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cpanel-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_cpanel_account_username' ) );
                                        esc_html_e( 'Username', 'mainwp' );
                                        ?>
                                        </label>

                                        <div class="five wide column">
                                            <input type="text" class="settings-field-value-change-handler" name="mainwp_cpanel_account_username" id="mainwp_cpanel_account_username" value="<?php echo false === get_option( 'mainwp_cpanel_account_username' ) ? '' : esc_attr( get_option( 'mainwp_cpanel_account_username' ) ); ?>"  />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cpanel-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        $_api_key = Api_Backups_3rd_Party::get_cpanel_account_password();
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $_api_key );
                                        esc_html_e( 'Password', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="password" class="settings-field-value-change-handler" name="mainwp_cpanel_account_password" id="mainwp_cpanel_account_password" value="<?php echo esc_attr( $_api_key ); ?>"  />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cpanel-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_cpanel_site_path' ) );
                                        esc_html_e( 'cPanel Site Path', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="text" class="settings-field-value-change-handler" name="mainwp_cpanel_site_path" id="mainwp_cpanel_site_path" value="<?php echo false === get_option( 'mainwp_cpanel_site_path' ) ? '' : esc_attr( get_option( 'mainwp_cpanel_site_path' ) ); ?>"  />
                                        </div>
                                    </div>
                                    <?php
                                        /**
                                         * Action: cpanel_api_form_bottom
                                         *
                                         * Fires at the bottom of cPanel API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'cpanel_api_form_bottom' );
                                    ?>
                                    <div class="ui divider"></div>
                                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                                    <div style="clear:both"></div>
                                </form>
                            </div>
                        </div>

                        <div class="ui tab segment" data-tab="plesk">
                            <h3 class="ui dividing header">
                            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-plesk-settings' ); ?>
                                <?php esc_html_e( 'Plesk (WP Toolkit)', 'mainwp' ); ?>
                            </h3>
                            <ul>
                                <li><?php esc_html_e( '1. Login to your Plesk Account', 'mainwp' ); ?></li>
                                <li><?php esc_html_e( '2. Do a search for "Keychain for API Secret Keys" & install it.', 'mainwp' ); ?></li>
                                <li><?php esc_html_e( '3. Click Open > Click Add Secret Key > Add a description > Click OK', 'mainwp' ); ?></li>
                                <li><?php esc_html_e( '4. Copy & Paste your API Access Token below', 'mainwp' ); ?></li>
                            </ul>
                            <div class="ui hidden divider"></div>
                            <div class="ui form">
                                <form method="POST" action="">
                                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                                    <input type="hidden" name="wp_nonce_plesk" value="<?php echo esc_attr( wp_create_nonce( $_nonce_slug ) ); ?>" />
                                    <?php
                                    /**
                                     * Action: plesk_api_form_top
                                     *
                                     * Fires at the top of Plesk API form.
                                     *
                                     * @since 4.1
                                     */
                                    do_action( 'plesk_api_form_top' );
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-plesk-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_enable_plesk_api', 0 ) );
                                        esc_html_e( 'Enable Plesk (WP Toolkit) API', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the Plesk API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_plesk_api" id="mainwp_enable_plesk_api" <?php echo 1 === (int) get_option( 'mainwp_enable_plesk_api', 0 ) ? 'checked="true"' : ''; ?> />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-plesk-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_plesk_api_url' ) );
                                        esc_html_e( 'Plesk URL', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column" data-tooltip="<?php esc_attr_e( 'eg.: https://epic-snyder.123-111-123-143.plesk.page:8443', 'mainwp' ); ?>">
                                            <input type="text" class="settings-field-value-change-handler" name="mainwp_plesk_api_url" id="mainwp_plesk_api_url" value="<?php echo false === get_option( 'mainwp_plesk_api_url' ) ? '' : esc_attr( get_option( 'mainwp_plesk_api_url' ) ); ?>"  />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-plesk-settings">
                                        <label class="six wide column middle aligned">
                                        <?php
                                        $_api_key = Api_Backups_3rd_Party::get_plesk_api_key();
                                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $_api_key );
                                        esc_html_e( 'API Key', 'mainwp' );
                                        ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="password" class="settings-field-value-change-handler" name="mainwp_plesk_api_key" id="mainwp_plesk_api_key" value="<?php echo esc_attr( $_api_key ); ?>"  />
                                        </div>
                                    </div>
                                    <?php
                                    /**
                                     * Action: plesk_api_form_bottom
                                     *
                                     * Fires at the bottom of Plesk API form.
                                     *
                                     * @since 4.1
                                     */
                                    do_action( 'plesk_api_form_bottom' );
                                    ?>
                                    <div class="ui divider"></div>
                                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                                    <div style="clear:both"></div>
                                </form>
                            </div>
                        </div>

                        <div class="ui tab segment" data-tab="kinsta">
                            <h3 class="ui dividing header">
                                <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-kinsta-settings' ); ?>
                                <?php esc_html_e( 'Kinsta', 'mainwp' ); ?>
                            </h3>
                            <ul>
                                <li><?php printf( esc_html__( "1. If you don't already have one, get a %s", 'mainwp' ), '<a target="_blank" href="https://my.kinsta.com/">Kinsta account</a>' ); ?></li>
                                <li><?php printf( esc_html__( '2. You can generate an API Key by visiting the %1$s tab of the Company Settings Page.', 'mainwp' ), '<a target="_blank" href="https://my.kinsta.com/company/apiKeys">API Keys</a>' ); ?></b></li>
                                <li><?php printf( esc_html__( '3. Paste in your %1$sCredentials%2$s below and click the Save Settings button.', 'mainwp' ), '<b>', '</b>' ); ?></li>
                                <li><?php esc_html_e( '4. Once the API is connected, go to the Site Edit page (for all sites on this host) and set the correct Provider and Environment ID.', 'mainwp' ); ?></li>
                            </ul>
                            <div class="ui hidden divider"></div>
                            <div class="ui form">
                                <form method="POST" action="">
                                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'kinsta_api_form' ) ); ?>" />
                                    <?php
                                        /**
                                         * Action: kinsta_api_form_top
                                         *
                                         * Fires at the top of Kinsta API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'kinsta_api_form_top' );
                                    ?>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-kinsta-settings">
                                        <label class="six wide column middle aligned">
                                            <?php
                                            MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_enable_kinsta_api', 0 ) );
                                            esc_html_e( 'Enable Kinsta API', 'mainwp' );
                                            ?>
                                        </label>
                                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the Kinsta API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                            <input type="checkbox" name="mainwp_enable_kinsta_api" id="mainwp_enable_kinsta_api" <?php echo ( 1 === (int) get_option( 'mainwp_enable_kinsta_api', 0 ) ) ? 'checked="true"' : ''; ?> />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-cloudways-settings">
                                        <label class="six wide column middle aligned">
                                            <?php
                                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_kinsta_api_account_email' ) );
                                                esc_html_e( 'Account Email', 'mainwp' );
                                            ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="text" class="settings-field-value-change-handler" name="mainwp_kinsta_api_account_email" id="mainwp_kinsta_api_account_email" value="<?php echo false === get_option( 'mainwp_kinsta_api_account_email' ) ? '' : esc_attr( get_option( 'mainwp_kinsta_api_account_email' ) ); ?>"  />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-kinsta-settings">
                                        <label class="six wide column middle aligned">
                                            <?php
                                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', get_option( 'mainwp_kinsta_company_id' ) );
                                                esc_html_e( 'Company ID', 'mainwp' );
                                            ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="text" class="settings-field-value-change-handler" name="mainwp_kinsta_company_id" id="mainwp_kinsta_company_id" value="<?php echo false === get_option( 'mainwp_kinsta_company_id' ) ? '' : esc_attr( get_option( 'mainwp_kinsta_company_id' ) ); ?>"  />
                                        </div>
                                    </div>
                                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-kinsta-settings">
                                        <label class="six wide column middle aligned">
                                            <?php
                                                $_api_key = Api_Backups_3rd_Party::get_kinsta_api_key();
                                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $_api_key );
                                                esc_html_e( 'API Key', 'mainwp' );
                                            ?>
                                        </label>
                                        <div class="five wide column">
                                            <input type="password" name="mainwp_kinsta_api_key" id="mainwp_kinsta_api_key" value="<?php echo esc_attr( $_api_key ); ?>"  />
                                        </div>
                                    </div>

                                    <?php
                                        /**
                                         * Action: kinsta_api_form_bottom
                                         *
                                         * Fires at the bottom of Kinsta API form.
                                         *
                                         * @since 4.1
                                         */
                                        do_action( 'kinsta_api_form_bottom' );
                                    ?>
                                    <div class="ui divider"></div>
                                    <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                                    <div style="clear:both"></div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Method hook_mainwp_update_site().
     *
     * @param int $website_id Website ID.
     *
     * @return void
     */
    public function hook_mainwp_update_site( $website_id ) { //phpcs:ignore -- NOSONAR - complex method.
        /**
         * 3rd-Party Backup API Provider Settings.
         *
         * Update Backup API Provider Settings ( Individual Child Site Edit Page ),
         */

        //phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( ! isset( $_POST['mainwp_managesites_edit_module_api_backups_provider'] ) ) {
            return;
        }

        $api_backup_provider      = isset( $_POST['mainwp_managesites_edit_module_api_backups_provider'] ) && ! empty( $_POST['mainwp_managesites_edit_module_api_backups_provider'] ) ? intval( $_POST['mainwp_managesites_edit_module_api_backups_provider'] ) : '';
        $api_backup_provider_name = '';

        if ( ! empty( $api_backup_provider ) ) {
            if ( 1 === $api_backup_provider ) {
                $api_backup_provider_name = 'DigitalOcean';
            } elseif ( 2 === $api_backup_provider ) {
                $api_backup_provider_name = 'Linode';
            } elseif ( 3 === $api_backup_provider ) {
                $api_backup_provider_name = 'Vultr';
            } elseif ( 4 === $api_backup_provider ) {
                $api_backup_provider_name = 'cPanel';
            } elseif ( 5 === $api_backup_provider ) {
                $api_backup_provider_name = 'Plesk';
            } elseif ( 6 === $api_backup_provider ) {
                $api_backup_provider_name = 'Kinsta';
            }
        }

        // Store the 3rd Party Backup Provider.
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_api', $api_backup_provider_name );

        // Store the 3rd Party Backup Provider Instance ID.
        $api_backup_instance_id = isset( $_POST['edit_site_module_api_backups_provider_instance_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edit_site_module_api_backups_provider_instance_id'] ) ) : '';
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_instance_id', $api_backup_instance_id );

        // Store the cPanel Individual API URL.
        $cpanel_api_url = isset( $_POST['cpanel_api_url'] ) ? wp_unslash( $_POST['cpanel_api_url'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Helper::update_website_option( $website_id, 'cpanel_api_url', $cpanel_api_url );

        // Store cPanel Individual or Global toggle.
        $mainwp_enable_wp_toolkit = isset( $_POST['mainwp_enable_wp_toolkit'] ) ? wp_unslash( $_POST['mainwp_enable_wp_toolkit'] ) : '0'; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_enable_wp_toolkit', $mainwp_enable_wp_toolkit );

        // Store cPanel Individual or Global toggle.
        $enable_cpanel_individual = isset( $_POST['mainwp_enable_cpanel_individual'] ) ? wp_unslash( $_POST['mainwp_enable_cpanel_individual'] ) : '0';//phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_enable_cpanel_individual', $enable_cpanel_individual );

        // Store cPanel Individual Account Username.
        $cpanel_account_username = isset( $_POST['mainwp_cpanel_account_username'] ) ? wp_unslash( $_POST['mainwp_cpanel_account_username'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Helper::update_website_option( $website_id, 'cpanel_account_username', $cpanel_account_username );

        // Store cPanel Individual Account Password.
        $cpanel_account_password = isset( $_POST['mainwp_cpanel_account_password'] ) ? wp_unslash( $_POST['mainwp_cpanel_account_password'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Utility::get_instance()->update_child_api_key( $website_id, 'cpanel', $cpanel_account_password );

        // Store cPanel Individual Site Path.
        $cpanel_site_path = isset( $_POST['cpanel_site_path'] ) ? wp_unslash( $_POST['cpanel_site_path'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Helper::update_website_option( $website_id, 'cpanel_site_path', $cpanel_site_path );

        // Store Plesk Individual or Global toggle.
        $enable_plesk_individual = isset( $_POST['mainwp_enable_plesk_individual'] ) ? wp_unslash( $_POST['mainwp_enable_plesk_individual'] ) : '0'; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_enable_plesk_individual', $enable_plesk_individual );

        // Store Plesk Individual API URL.
        $plesk_api_url = isset( $_POST['plesk_api_url'] ) ? wp_unslash( $_POST['plesk_api_url'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Helper::update_website_option( $website_id, 'plesk_api_url', rtrim( $plesk_api_url, '/' ) );

        // Store Plesk Individual Installation ID.
        $plesk_installation_id = isset( $_POST['plesk_installation_id'] ) ? wp_unslash( $_POST['plesk_installation_id'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_plesk_installation_id', $plesk_installation_id );

        // Store Plesk Individual API Key.
        $plesk_api_key = isset( $_POST['mainwp_plesk_api_key'] ) ? wp_unslash( $_POST['mainwp_plesk_api_key'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        Api_Backups_Utility::get_instance()->update_child_api_key( $website_id, 'plesk', $plesk_api_key );

        // Store Kinsta Individual or Global toggle.
        $enable_kinsta_individual = isset( $_POST['mainwp_enable_kinsta_individual'] ) ? wp_unslash( $_POST['mainwp_enable_kinsta_individual'] ) : '0';
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_enable_kinsta_individual', $enable_kinsta_individual );

        // Store Kinsta Environment ID.
        $kinsta_environment_id = isset( $_POST['kinsta_environment_id'] ) ? wp_unslash( $_POST['kinsta_environment_id'] ) : '0';
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_kinsta_environment_id', $kinsta_environment_id );

        // Store Kinsta Account Email.
        $kinsta_account_email = isset( $_POST['kinsta_account_email'] ) ? wp_unslash( $_POST['kinsta_account_email'] ) : '0';
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_kinsta_account_email', $kinsta_account_email );

        // Store Kinsta Company ID.
        $kinsta_company_id = isset( $_POST['kinsta_company_id'] ) ? wp_unslash( $_POST['kinsta_company_id'] ) : '0';
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_kinsta_company_id', $kinsta_company_id );

        // Store Kinsta Individual API Key.
        $kinsta_api_key = isset( $_POST['mainwp_kinsta_api_key'] ) ? wp_unslash( $_POST['mainwp_kinsta_api_key'] ) : '';
        Api_Backups_Utility::get_instance()->update_child_api_key( $website_id, 'kinsta', $kinsta_api_key );
        //phpcs:enable
    }

    /**
     * Method hook_render_mainwp_manage_sites_edit().
     *
     * @param mixed $website Website.
     * @return mixed
     */
    public function hook_render_mainwp_manage_sites_edit( $website ) { //phpcs:ignore -- NOSONAR - complex method.

        if ( empty( $website ) ) {
            return;
        }

        $mainwp_3rd_party_api                 = '';
        $mainwp_3rd_party_instance_id         = '';
        $mainwp_3rd_party_cloudways_app_id    = '';
        $mainwp_3rd_party_cloudways_server_id = '';
        $mainwp_3rd_party_gridpane_site_id    = '';
        $mainwp_cpanel_api_url                = '';
        $mainwp_cpanel_site_path              = '';
        $mainwp_cpanel_account_username       = '';
        $mainwp_plesk_api_url                 = '';

        if ( is_object( $website ) && property_exists( $website, 'id' ) ) {
            $opts = Api_Backups_Helper::get_website_options(
                $website,
                array(
                    'mainwp_3rd_party_api',
                    'mainwp_3rd_party_instance_id',
                    'mainwp_3rd_party_app_id',
                    'mainwp_enable_wp_toolkit',
                    'mainwp_enable_cpanel_individual',
                    'mainwp_enable_plesk_individual',
                    'mainwp_enable_kinsta_individual',
                    'cpanel_api_url',
                    'cpanel_site_path',
                    'cpanel_account_username',
                    'plesk_api_url',
                    'mainwp_plesk_installation_id',
                    'mainwp_kinsta_environment_id',
                    'mainwp_kinsta_account_email',
                    'mainwp_kinsta_company_id',
                )
            );

            if ( is_array( $opts ) ) {
                $mainwp_3rd_party_api                 = isset( $opts['mainwp_3rd_party_api'] ) ? $opts['mainwp_3rd_party_api'] : '';
                $mainwp_3rd_party_instance_id         = isset( $opts['mainwp_3rd_party_instance_id'] ) ? $opts['mainwp_3rd_party_instance_id'] : '';
                $mainwp_3rd_party_cloudways_app_id    = isset( $opts['mainwp_3rd_party_app_id'] ) ? $opts['mainwp_3rd_party_app_id'] : '';
                $mainwp_3rd_party_cloudways_server_id = isset( $opts['mainwp_3rd_party_instance_id'] ) ? $opts['mainwp_3rd_party_instance_id'] : '';
                $mainwp_3rd_party_gridpane_site_id    = isset( $opts['mainwp_3rd_party_instance_id'] ) ? $opts['mainwp_3rd_party_instance_id'] : '';
                $mainwp_enable_wp_toolkit             = isset( $opts['mainwp_enable_wp_toolkit'] ) ? $opts['mainwp_enable_wp_toolkit'] : '0';
                $mainwp_enable_cpanel_individual      = isset( $opts['mainwp_enable_cpanel_individual'] ) ? $opts['mainwp_enable_cpanel_individual'] : '0';
                $mainwp_enable_plesk_individual       = isset( $opts['mainwp_enable_plesk_individual'] ) ? $opts['mainwp_enable_plesk_individual'] : '0';
                $mainwp_enable_kinsta_individual      = isset( $opts['mainwp_enable_kinsta_individual'] ) ? $opts['mainwp_enable_kinsta_individual'] : '0';
                $mainwp_cpanel_api_url                = isset( $opts['cpanel_api_url'] ) ? $opts['cpanel_api_url'] : '';
                $mainwp_cpanel_site_path              = isset( $opts['cpanel_site_path'] ) ? $opts['cpanel_site_path'] : '';
                $mainwp_cpanel_account_username       = isset( $opts['cpanel_account_username'] ) ? $opts['cpanel_account_username'] : '';
                $mainwp_plesk_api_url                 = isset( $opts['plesk_api_url'] ) ? $opts['plesk_api_url'] : '';
                $mainwp_plesk_installation_id         = isset( $opts['mainwp_plesk_installation_id'] ) ? $opts['mainwp_plesk_installation_id'] : '';
                $mainwp_kinsta_environment_id         = isset( $opts['mainwp_kinsta_environment_id'] ) ? $opts['mainwp_kinsta_environment_id'] : '';
                $mainwp_kinsta_account_email          = isset( $opts['mainwp_kinsta_account_email'] ) ? $opts['mainwp_kinsta_account_email'] : '';
                $mainwp_kinsta_company_id             = isset( $opts['mainwp_kinsta_company_id'] ) ? $opts['mainwp_kinsta_company_id'] : '';
            }
        }

        ?>
        <h3 class="ui dividing header">
            <?php esc_html_e( 'Backup API Provider Settings', 'mainwp' ); ?>
            <div class="sub header"><?php esc_html_e( 'Use the provided settings to set your Backup API provider. Sites hosted on Cloudways and Gridpane do not require these settings.', 'mainwp' ); ?></div>
            <div class="sub header"><?php esc_html_e( 'Use these settings to select Provider and instance ID only if the site is hosted on DigitalOcean, Akamai (Linode), or Vultr hosting.', 'mainwp' ); ?></div>
            <div class="sub header"><?php esc_html_e( 'Sites hosted on Cloudways and GridPane do not require these settings to be added manually. All the necessary info for the feature will be obtained automatically so you can leave these settings blank.', 'mainwp' ); ?></div>
        </h3>
        <?php if ( '' === $mainwp_3rd_party_api || 'cPanel' === $mainwp_3rd_party_api || 'Plesk' === $mainwp_3rd_party_api || 'Kinsta' === $mainwp_3rd_party_api ) : ?>
            <div class="ui grid field settings-field-indicator-wrapper">
                <label class="six wide column middle aligned">
                <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $mainwp_3rd_party_api );
                ?>
                <?php esc_html_e( 'Choose a provider', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Detected provider', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select class="ui dropdown settings-field-value-change-handler" id="mainwp_managesites_edit_module_api_backups_provider" name="mainwp_managesites_edit_module_api_backups_provider">
                        <option <?php echo ( '' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'None', 'mainwp' ); ?></option>
                        <option <?php echo ( 'DigitalOcean' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'DigitalOcean', 'mainwp' ); ?></option>
                        <option <?php echo ( 'Linode' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'Akamai (Linode)', 'mainwp' ); ?></option>
                        <option <?php echo ( 'Vultr' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="3"><?php esc_html_e( 'Vultr', 'mainwp' ); ?></option>
                        <option <?php echo ( 'cPanel' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="4"><?php esc_html_e( 'cPanel (WP Toolkit)', 'mainwp' ); ?></option>
                        <option <?php echo ( 'Plesk' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="5"><?php esc_html_e( 'Plesk (WP Toolkit)', 'mainwp' ); ?></option>
                        <option <?php echo ( 'Kinsta' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="6"><?php esc_html_e( 'Kinsta', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
            <div class="mainwp_cpanel_menu_container" style="display:none;">
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Enable WP Toolkit API', 'mainwp' ); ?></label>
                    <div id="enable_wp_toolkit_check" class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the WP Toolkit API will also be active.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                        <input type="checkbox" name="mainwp_enable_wp_toolkit" id="mainwp_enable_wp_toolkit" <?php echo 'on' === $mainwp_enable_wp_toolkit ? 'checked="true"' : 'off'; ?> />
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Overwrite cPanel Global Settings', 'mainwp' ); ?></label>
                    <div id="individual_settings_check" class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the cPanel Individual Settings will be used.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                        <input type="checkbox" name="mainwp_enable_cpanel_individual" id="mainwp_enable_cpanel_individual" <?php echo 'on' === $mainwp_enable_cpanel_individual ? 'checked="true"' : 'off'; ?> />
                    </div>
                </div>
                <div class="mainwp_cpanel_individual_container" style="display:none;">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'cPanel URL', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the cPanel API URL. eg.: https://yoursite.com:2083', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <input type="text" id="cpanel_api_url" name="cpanel_api_url" value="<?php echo empty( $mainwp_cpanel_api_url ) ? '' : esc_attr( $mainwp_cpanel_api_url ); ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Username', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the cPanel Account Username.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <input type="text" name="mainwp_cpanel_account_username" id="mainwp_cpanel_account_username" value="<?php echo empty( $mainwp_cpanel_account_username ) ? '' : esc_attr( $mainwp_cpanel_account_username ); ?>"  />
                            </div>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Password', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the cPanel Account Password.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <?php
                                    $cpanel_account_password = Api_Backups_Utility::get_instance()->get_child_api_key( $website, 'cpanel' );
                                ?>
                                <input type="password" name="mainwp_cpanel_account_password" id="mainwp_cpanel_account_password" value="<?php echo esc_attr( $cpanel_account_password ); ?>"  />
                            </div>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Site Path', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the directory the site is installed. eg.: /public_html/child/', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <input type="text" id="cpanel_site_path" name="cpanel_site_path" value="<?php echo empty( $mainwp_cpanel_site_path ) ? '' : esc_attr( $mainwp_cpanel_site_path ); ?>" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mainwp_plesk_menu_container" style="display:none;">
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Installation ID', 'mainwp' ); ?></label>
                    <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the Plesk Installation ID. eg.: "1"', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <div class="ui left labeled input">
                            <input type="text" id="plesk_installation_id" name="plesk_installation_id" value="<?php echo empty( $mainwp_plesk_installation_id ) ? '' : esc_html( $mainwp_plesk_installation_id ); ?>" />
                        </div>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Overwrite Global Settings', 'mainwp' ); ?></label>
                    <div id="individual_settings_check" class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the Plesk (WP Toolkit) Individual Settings will be used.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                        <input type="checkbox" name="mainwp_enable_plesk_individual" id="mainwp_enable_plesk_individual" <?php echo 'on' === $mainwp_enable_plesk_individual ? 'checked="true"' : 'off'; ?> />
                    </div>
                </div>
                <div class="mainwp_plesk_individual_container" style="display:none;">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Plesk URL', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'eg.: https://epic-snyder.123-111-123-143.plesk.page:8443', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <input type="text" id="plesk_api_url" name="plesk_api_url" value="<?php echo empty( $mainwp_plesk_api_url ) ? '' : esc_attr( $mainwp_plesk_api_url ); ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'API Key', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the Plesk API Key', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <?php
                                    $plesk_api_key = Api_Backups_Utility::get_instance()->get_child_api_key( $website, 'plesk' );
                                ?>
                                <input type="password" name="mainwp_plesk_api_key" id="mainwp_plesk_api_key" value="<?php echo esc_attr( $plesk_api_key ); ?>"  />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mainwp_kinsta_menu_container" style="display:none;">
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Environment ID', 'mainwp' ); ?></label>
                    <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the Kinsta Environment ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <div class="ui left labeled input">
                            <input type="text" id="kinsta_environment_id" name="kinsta_environment_id" value="<?php echo empty( $mainwp_kinsta_environment_id ) ? '' : esc_html( $mainwp_kinsta_environment_id ); ?>" />
                        </div>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Overwrite Global Settings', 'mainwp' ); ?></label>
                    <div id="individual_settings_check" class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the Kinsta Individual Settings will be used.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                        <input type="checkbox" name="mainwp_enable_kinsta_individual" id="mainwp_enable_kinsta_individual" <?php echo ( 'on' === $mainwp_enable_kinsta_individual ) ? 'checked="true"' : 'off'; ?> />
                    </div>
                </div>
                <div class="mainwp_kinsta_individual_container" style="display:none;">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Account Email', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the Kinsta Account Email.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <input type="text" id="kinsta_account_email" name="kinsta_account_email" value="<?php echo empty( $mainwp_kinsta_account_email ) ? '' : esc_html( $mainwp_kinsta_account_email ); ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Company ID', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the Kinsta Company  ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <input type="text" id="kinsta_company_id" name="kinsta_company_id" value="<?php echo empty( $mainwp_kinsta_company_id ) ? '' : esc_html( $mainwp_kinsta_company_id ); ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'API Key', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the Kinsta API Key', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <?php
                                    $kinsta_api_key = Api_Backups_Utility::get_instance()->get_child_api_key( $website, 'kinsta' );
                                ?>
                                <input type="password" name="mainwp_kinsta_api_key" id="mainwp_kinsta_api_key" value="<?php echo esc_attr( $kinsta_api_key ); ?>"  />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="ui hidden divider"></div>
        <?php elseif ( 'DigitalOcean' === $mainwp_3rd_party_api || 'Linode' === $mainwp_3rd_party_api || 'Vultr' === $mainwp_3rd_party_api ) : ?>
            <div class="ui grid field settings-field-indicator-wrapper">
                <label class="six wide column middle aligned">
                <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $mainwp_3rd_party_api );
                ?>
                <?php esc_html_e( 'Choose a provider', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Detected provider', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select class="ui dropdown settings-field-value-change-handler" id="mainwp_managesites_edit_module_api_backups_provider" name="mainwp_managesites_edit_module_api_backups_provider">
                        <option <?php echo ( '' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'None', 'mainwp' ); ?></option>
                        <option <?php echo ( 'DigitalOcean' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'DigitalOcean', 'mainwp' ); ?></option>
                        <option <?php echo ( 'Linode' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'Akamai (Linode)', 'mainwp' ); ?></option>
                        <option <?php echo ( 'Vultr' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="3"><?php esc_html_e( 'Vultr', 'mainwp' ); ?></option>
                        <option <?php echo ( 'cPanel' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="4"><?php esc_html_e( 'cPanel (WP Toolkit)', 'mainwp' ); ?></option>
                        <option <?php echo ( 'Plesk' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="5"><?php esc_html_e( 'Plesk (WP Toolkit)', 'mainwp' ); ?></option>
                        <option <?php echo ( 'Kinsta' === $mainwp_3rd_party_api ) ? 'selected' : ''; ?> value="6"><?php esc_html_e( 'Kinsta', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
            <?php $instance_id = $mainwp_3rd_party_instance_id; ?>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Instance ID', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the Instance ID (Droplet ID). This information is available in your provider account.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui left labeled input">
                        <input type="text" id="edit_site_module_api_backups_provider_instance_id" name="edit_site_module_api_backups_provider_instance_id" value="<?php echo empty( $instance_id ) ? '' : esc_attr( $instance_id ); ?>" />
                    </div>
                </div>
            </div>
            <?php elseif ( 'Cloudways' === $mainwp_3rd_party_api ) : ?>
            <div class="ui grid field settings-field-indicator-wrapper">
                <label class="six wide column middle aligned">
                <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $mainwp_3rd_party_api );
                ?>
                <?php esc_html_e( 'Choose a provider', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Detected provider', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select class="ui disabled dropdown settings-field-value-change-handler">
                        <option><?php esc_html_e( 'Cloudways', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
                <?php $app_id = $mainwp_3rd_party_cloudways_app_id; ?>
                <?php $server_id = $mainwp_3rd_party_cloudways_server_id; ?>
            <div class="ui grid field settings-field-indicator-wrapper">
                <label class="six wide column middle aligned">
                <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $app_id );
                ?>
                <?php esc_html_e( 'App ID', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Detected Cloudways site ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui left labeled input">
                        <input type="text" class="settings-field-value-change-handler" disabled value="<?php echo esc_attr( $app_id ); ?>" />
                    </div>
                </div>
            </div>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Instance ID', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Detected Cloudways site ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui left labeled input">
                        <input type="text" disabled value="<?php echo esc_attr( $server_id ); ?>" />
                    </div>
                </div>
            </div>
            <?php elseif ( 'GridPane' === $mainwp_3rd_party_api ) : ?>
            <div class="ui grid field settings-field-indicator-wrapper">
                <label class="six wide column middle aligned">
                <?php
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $mainwp_3rd_party_api );
                ?>
                <?php esc_html_e( 'Choose a provider', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Detected provider', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select class="ui disabled dropdown settings-field-value-change-handler">
                        <option><?php esc_html_e( 'GridPane (Developer or higher)', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
                <?php $instance_id = $mainwp_3rd_party_gridpane_site_id; ?>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Instance ID', 'mainwp' ); ?></label>
                <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Detected GridPane site ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui left labeled input">
                        <input type="text" disabled value="<?php echo esc_attr( $instance_id ); ?>" />
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <script type="text/javascript">
            jQuery( document ).ready( function() {

                // Check if cPanel or Plesk is selected when page loads.
                let dropdown_selection = jQuery("#mainwp_managesites_edit_module_api_backups_provider" ).val();
                if ( dropdown_selection === '4' ) {
                    jQuery('.mainwp_cpanel_menu_container').show();
                    jQuery('.mainwp_plesk_menu_container').hide();
                    jQuery('.mainwp_kinsta_menu_container').hide();
                } else if ( dropdown_selection === '5' ) {
                    jQuery('.mainwp_cpanel_menu_container').hide();
                    jQuery('.mainwp_plesk_menu_container').show();
                    jQuery('.mainwp_kinsta_menu_container').hide();
                } else if ( dropdown_selection === '6' ) {
                    jQuery('.mainwp_cpanel_menu_container').hide();
                    jQuery('.mainwp_plesk_menu_container').hide();
                    jQuery('.mainwp_kinsta_menu_container').show();
                } else {
                    jQuery('.mainwp_cpanel_menu_container').hide();
                    jQuery('.mainwp_plesk_menu_container').hide();
                    jQuery('.mainwp_kinsta_menu_container').hide();
                }

                // Check if cPanel Individual Settings is :checked when page loads.
                if ( jQuery( "#mainwp_enable_cpanel_individual" ).is( ":checked" ) ) {
                    jQuery('.mainwp_cpanel_individual_container').show();
                } else if ( jQuery( "#mainwp_enable_plesk_individual" ).is( ":checked" ) ) {
                    jQuery('.mainwp_plesk_individual_container').show();
                } else if ( jQuery( "#mainwp_enable_kinsta_individual" ).is( ":checked" ) ) {
                    jQuery('.mainwp_kinsta_individual_container').show();
                } else {
                    jQuery('.mainwp_cpanel_individual_container').hide();
                    jQuery('.mainwp_plesk_individual_container').hide();
                    jQuery('.mainwp_kinsta_individual_container').hide();
                }

                // Check which Provider is `selected` when using dropdown & show/hide the appropriate settings.
                jQuery( "#mainwp_managesites_edit_module_api_backups_provider" ).on( "change", function() {

                    let selected = jQuery(this).val();

                    if ( selected === '4' ) {
                        jQuery('.mainwp_cpanel_menu_container').show();
                        jQuery('.mainwp_plesk_menu_container').hide();
                        jQuery('.mainwp_kinsta_menu_container').hide();
                    } else if ( selected === '5' ) {
                        jQuery('.mainwp_cpanel_menu_container').hide();
                        jQuery('.mainwp_plesk_menu_container').show();
                        jQuery('.mainwp_kinsta_menu_container').hide();
                    } else if ( selected === '6' ) {
                        jQuery('.mainwp_cpanel_menu_container').hide();
                        jQuery('.mainwp_plesk_menu_container').hide();
                        jQuery('.mainwp_kinsta_menu_container').show();
                    } else {
                        jQuery('.mainwp_cpanel_menu_container').hide();
                        jQuery('.mainwp_plesk_menu_container').hide();
                        jQuery('.mainwp_kinsta_menu_container').hide();
                    }
                } );

                // Toggle cPanel Individual Settings on/off when clicked.
                jQuery( "#mainwp_enable_cpanel_individual" ).on( "change", function() {
                    jQuery('.mainwp_cpanel_individual_container').toggle();
                } );

                // Toggle Plesk Individual Settings on/off when clicked.
                jQuery( "#mainwp_enable_plesk_individual" ).on( "change", function() {
                    jQuery('.mainwp_plesk_individual_container').toggle();
                } );

                // Toggle Plesk Individual Settings on/off when clicked.
                jQuery( "#mainwp_enable_kinsta_individual" ).on( "change", function() {
                    jQuery('.mainwp_kinsta_individual_container').toggle();
                } );

            } );
        </script>
        <?php
    } // [ END: hook_render_mainwp_manage_sites_edit ]
} // [ END: class Api_Backups_Settings ]
