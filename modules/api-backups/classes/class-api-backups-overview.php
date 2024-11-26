<?php
/**
 * =======================================
 * MainWP API Backups Overview
 * =======================================
 *
 *  @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

/**
 * MainWP API Backups Overview
 */
class Api_Backups_Overview {


    /**
     * Public static variable to hold the single instance of the class.
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
     * @return Api_Backups_Overview
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }


    /**
     * Constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
    }

    /**
     * Sites Page Check
     *
     * Checks if the current page is individual site Cache Control page.
     *
     * @return bool True if correct, false if not.
     */
    public static function is_managesites_page() {
        if ( isset( $_GET['page'] ) && ( 'ManageSitesApiBackups' === $_GET['page'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }
        return false;
    }

    /**
     * Get current tab.
     */
    public function get_current_tab() {
        $curent_tab = 'backups';
        if ( isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $curent_tab = 'settings';
        }
        return $curent_tab;
    }

    /**
     * Render Tabs.
     *
     * Renders the page tabs.
     */
    public function render_individual_tabs() {
        do_action( 'mainwp_pageheader_sites', 'ApiBackups' );
        ?>
        <div>
        <?php
        if ( static::is_managesites_page() ) {
            $site_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $website = Api_Backups_Helper::get_website_by_id( $site_id );
            if ( empty( $site_id ) || empty( $website ) ) {
                echo '<div class="ui yellow message">' . esc_html__( 'Error: empty site ID', 'mainwp' ) . '</div>';
            } else {
                $curent_tab = $this->get_current_tab();
                if ( 'backups' === $curent_tab ) :
                        Api_Backups_3rd_Party::instance()->render_api_backups_site( $website );
                elseif ( 'settings' === $curent_tab ) :
                    Api_Backups_Settings::get_instance()->render_settings_content( true );
                endif;
            }
        }
        ?>
        </div>
        <?php
        do_action( 'mainwp_pagefooter_sites', 'ApiBackups' );
    }

    /**
     * Render backups list.
     */
    public function render_backups_list() {

        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_api_backups' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage api backups', 'mainwp' ) );
            return;
        }
        Api_Backups_Admin::render_header();
        ?>
        <div id="mainwp-module-api-backups-dashboard">
            <?php Api_Backups_3rd_Party::render_mainwp_backups_page(); ?>
        </div>
        <?php
    }
}
