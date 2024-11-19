<?php
/**
 * MainWP Monitoring Sites Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Monitoring
 *
 * @package MainWP\Dashboard
 */
class MainWP_Monitoring { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Sub pages
     *
     * @static
     * @var array $subPages Sub pages.
     */
    public static $subPages;

    /**
     * Current page.
     *
     * @static
     * @var string $page Current page.
     */
    public static $page;

    /**
     * Magage Sites table
     *
     * @var $sitesTable Magage Sites table.
     */
    public static $sitesTable;

    /**
     * Method init_menu()
     *
     * Add Monitoring Sub Menu.
     */
    public static function init_menu() {
        static::$page = add_submenu_page(
            'mainwp_tab',
            __( 'Monitoring', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Monitoring', 'mainwp' ) . '</div>',
            'read',
            'MonitoringSites',
            array(
                static::get_class_name(),
                'render_all_sites',
            )
        );
        add_action( 'load-' . static::$page, array( static::get_class_name(), 'on_load_page' ) );
    }


    /**
     * Method on_load_page()
     *
     * Run on page load.
     *
     * @uses \MainWP\Dashboard\MainWP_Monitoring_Sites_List_Table
     */
    public static function on_load_page() {
        add_filter( 'mainwp_header_actions_right', array( static::get_class_name(), 'screen_options' ), 10, 2 );
        static::$sitesTable = new MainWP_Monitoring_Sites_List_Table();
    }

    /**
     * Method screen_options()
     *
     * Create Page Settings button.
     *
     * @param mixed $input Page Settings button HTML.
     *
     * @return mixed Page Settings button.
     */
    public static function screen_options( $input ) {
        return $input .
                '<a class="ui button basic icon" onclick="mainwp_manage_sites_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
                    <i class="cog icon"></i>
                </a>';
    }

    /**
     * Method render_screen_options()
     *
     * Render Page Settings Modal.
     */
    public static function render_screen_options() {  // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $columns = static::$sitesTable->get_columns();

        if ( isset( $columns['cb'] ) ) {
            unset( $columns['cb'] );
        }

        if ( isset( $columns['favicon'] ) ) {
            $columns['favicon'] = esc_html__( 'Favicon', 'mainwp' );
        }

        if ( isset( $columns['login'] ) ) {
            $columns['login'] = esc_html__( 'Jump to WP Admin', 'mainwp' );
        }

        if ( isset( $columns['site_preview'] ) ) {
            $columns['site_preview'] = esc_html__( 'Site preview', 'mainwp' );
        }

        if ( isset( $columns['last24_status'] ) ) {
            $columns['last24_status'] = esc_html__( 'Last 24h Status', 'mainwp' );
        }

        if ( isset( $columns['lighthouse_desktop_score'] ) ) {
            $columns['lighthouse_desktop_score'] = esc_html__( 'Lighthouse Desktop Score', 'mainwp' );
        }

        if ( isset( $columns['lighthouse_mobile_score'] ) ) {
            $columns['lighthouse_mobile_score'] = esc_html__( 'Lighthouse Mobile Score', 'mainwp' );
        }

        $sites_per_page = get_option( 'mainwp_default_monitoring_sites_per_page', 25 );

        if ( isset( $columns['site_actions'] ) && empty( $columns['site_actions'] ) ) {
            $columns['site_actions'] = esc_html__( 'Actions', 'mainwp' );
        }

        $show_cols = get_user_option( 'mainwp_settings_show_monitoring_sites_columns' );
        if ( false === $show_cols ) { // to backwards.
            $show_cols = array();
            foreach ( $columns as $name => $title ) {
                $show_cols[ $name ] = 1;
            }
            $user = wp_get_current_user();
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_settings_show_monitoring_sites_columns', $show_cols, true );
            }
        }

        if ( ! is_array( $show_cols ) ) {
            $show_cols = array();
        }

        ?>
        <div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <form method="POST" action="" id="manage-sites-screen-options-form" name="manage_sites_screen_options_form">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'MonitoringSitesScrOptions' ) ); ?>" />
                    <div class="ui grid field">
                        <label class="six wide column"><?php esc_html_e( 'Default items per page value', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <div class="ui info message">
                                <ul>
                                    <li><?php esc_html_e( 'Based on your Dashboard server default large numbers can severely impact page load times.', 'mainwp' ); ?></li>
                                    <li><?php esc_html_e( 'Do not add commas for thousands (ex 1000).', 'mainwp' ); ?></li>
                                    <li><?php esc_html_e( '-1 to default to All of your Child Sites.', 'mainwp' ); ?></li>
                                </ul>
                            </div>
                            <input type="text" name="mainwp_default_monitoring_sites_per_page" id="mainwp_default_monitoring_sites_per_page" saved-value="<?php echo intval( $sites_per_page ); ?>" value="<?php echo intval( $sites_per_page ); ?>"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column"><?php esc_html_e( 'Show columns', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <ul class="mainwp_hide_wpmenu_checkboxes">
                                <?php
                                foreach ( $columns as $name => $title ) {
                                    if ( empty( $title ) ) {
                                        continue;
                                    }
                                    ?>
                                    <li>
                                        <div class="ui checkbox <?php echo ( 'site_preview' === $name ) ? 'site_preview not-auto-init' : ''; ?>">
                                            <input type="checkbox"
                                            <?php
                                            $show_col = ! isset( $show_cols[ $name ] ) || ( 1 === (int) $show_cols[ $name ] );

                                            if ( in_array( $name, array( 'site_preview', 'atarim_tasks' ) ) && ! isset( $show_cols[ $name ] ) ) {
                                                $show_col = false;
                                            }

                                            if ( $show_col ) {
                                                echo 'checked="checked"';
                                            }
                                            ?>
                                            id="mainwp_show_column_<?php echo esc_attr( $name ); ?>" name="mainwp_show_column_<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>">
                                            <label for="mainwp_show_column_<?php echo esc_attr( $name ); ?>" ><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
                                            <input type="hidden" value="<?php echo esc_attr( $name ); ?>" name="show_columns_name[]" />
                                        </div>
                                    </li>
                                    <?php
                                }
                                ?>
                            </ul>
                        </div>
                    </div>
                    <?php MainWP_Monitoring_View::render_settings(); ?>
                </div>
                <div class="actions">
                    <div class="ui two columns grid">
                        <div class="left aligned column">
                            <span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated columns.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-monitoringsites-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                        </div>
                        <div class="ui right aligned column">
                    <input type="submit" class="ui green button" name="btnSubmit" id="submit-monitoringsites-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />

                </div>
                    </div>
                </div>
                <input type="hidden" name="reset_monitoringsites_columns_order" value="0">
            </form>
        </div>
        <div class="ui small modal" id="mainwp-monitoring-sites-site-preview-screen-options-modal">
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <span><?php esc_html_e( 'Would you like to turn on home screen previews?  This function queries WordPress.com servers to capture a screenshot of your site the same way comments shows you preview of URLs.', 'mainwp' ); ?>
            </div>
            <div class="actions">
                <div class="ui ok button"><?php esc_html_e( 'Yes', 'mainwp' ); ?></div>
                <div class="ui cancel button"><?php esc_html_e( 'No', 'mainwp' ); ?></div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( '.ui.checkbox.not-auto-init.site_preview' ).checkbox( {
                    onChecked   : function() {
                        let $chk = jQuery( this );
                        jQuery( '#mainwp-monitoring-sites-site-preview-screen-options-modal' ).modal( {
                            allowMultiple: true, // multiple modals.
                            width: 100,
                            onDeny: function () {
                                $chk.prop('checked', false);
                            }
                        } ).modal( 'show' );
                    }
                } );
                jQuery('#reset-monitoringsites-settings').on( 'click', function () {
                    mainwp_confirm(__( 'Are you sure.' ), function(){
                        jQuery('input[name=mainwp_default_monitoring_sites_per_page]').val(25);
                        jQuery('.mainwp_hide_wpmenu_checkboxes input[id^="mainwp_show_column_"]').prop( 'checked', false );
                        //default columns: Site, Open Admin, URL, Site Health, Status Code and Actions.
                        let cols = ['site','status','site_actions'];
                        jQuery.each( cols, function ( index, value ) {
                            jQuery('.mainwp_hide_wpmenu_checkboxes input[id="mainwp_show_column_' + value + '"]').prop( 'checked', true );
                        } );
                        jQuery('input[name=reset_monitoringsites_columns_order]').attr('value',1);
                        jQuery('#submit-monitoringsites-settings').click();
                    }, false, false, true );
                    return false;
                });
            } );
        </script>
        <?php
    }

    /**
     * Method render_all_sites()
     *
     * Render monitoring sites content.
     */
    public static function render_all_sites() {

        if ( ! \mainwp_current_user_can( 'dashboard', 'monitoring_sites' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'monitoring sites', 'mainwp' ) );

            return;
        }

        $optimize_for_sites_table = apply_filters( 'mainwp_manage_sites_optimize_loading', 1, 'monitor-sites' ); // use ajax to load sites table .

        if ( ! $optimize_for_sites_table ) {
            static::$sitesTable->prepare_items( false );
        }

        /** This action is documented in ../pages/page-mainwp-manage-sites.php */
        do_action( 'mainwp_pageheader_sites', 'MonitoringSites' );
        ?>
        <div id="mainwp-manage-sites-content" class="ui segment">
            <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
            <form method="post" class="mainwp-table-container">
                <?php
                wp_nonce_field( 'mainwp-admin-nonce' );
                static::$sitesTable->display( $optimize_for_sites_table );
                static::$sitesTable->clear_items();
                ?>
            </form>
        </div>
        <?php
        static::render_screen_options();
        /** This action is documented in ../pages/page-mainwp-manage-sites.php */
        do_action( 'mainwp_pagefooter_sites', 'MonitoringSites' );
    }


    /**
     * Method ajax_optimize_display_rows()
     *
     * Display table rows, optimize for shared hosting or big networks.
     *
     * @uses \MainWP\Dashboard\MainWP_Monitoring_Sites_List_Table
     */
    public static function ajax_optimize_display_rows() {
        static::$sitesTable = new MainWP_Monitoring_Sites_List_Table();
        static::$sitesTable->prepare_items( true );
        $output = static::$sitesTable->ajax_get_datatable_rows();
        static::$sitesTable->clear_items();
        wp_send_json( $output );
    }
}
