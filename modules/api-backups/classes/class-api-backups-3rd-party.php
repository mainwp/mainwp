<?php
/**
 * MainWP Module API Backups 3rd Party class.
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

use WP_Error;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Extensions_Handler;

/**
 * Class Api_Backups_3rd_Party
 *
 * This class is responsible for handling the 3rd party API requests for each available backup provider.
 *
 * @package MainWP\Dashboard
 *
 * @version 5.0
 */
class Api_Backups_3rd_Party { //phpcs:ignore -- NOSONAR - multi methods.

    // phpcs:disable WordPress.DB.RestrictedFunctions, Generic.Metrics.CyclomaticComplexity, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;


    /**
     * Public static variable.
     */
    const OPERATION = '/operation/'; // to clean code.

    /**
     * Public static variable.
     */
    const SNAPSHOTS = '/snapshots/'; // to clean code.

    /**
     * Public static variable.
     */
    const INSTANCES = '/instances/'; // to clean code.

    /**
     * Public static variable.
     */
    const DROPLETS = '/droplets/'; // to clean code.

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @static
     * @return Api_Backups_3rd_Party
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }


    /**
     * Method get_class_name()
     *
     * Get class name.
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Api_Backups_3rd_Party Constructor.
     *
     * Runs each time the class is called.
     */
    public function __construct() {
        // Initiate admin_init() function.
        add_action( 'admin_init', array( &$this, 'admin_init' ) );
    }

    /**
     * Admin init.
     */
    public function admin_init() {
        // Admin Init.
    }

    /**
     * Init ajax actions.
     */
    public function init_ajax_actions() {
        // Cloudways Ajax.
        do_action( 'mainwp_ajax_add_action', 'cloudways_action_backup', array( &$this, 'ajax_cloudways_action_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cloudways_action_individual_backup', array( &$this, 'ajax_cloudways_action_individual_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cloudways_action_update_ids', array( &$this, 'ajax_cloudways_action_update_ids' ) );
        do_action( 'mainwp_ajax_add_action', 'cloudways_action_refresh_available_backups', array( &$this, 'cloudways_action_refresh_available_backups' ) );
        do_action( 'mainwp_ajax_add_action', 'cloudways_action_restore_backup', array( &$this, 'cloudways_action_restore_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cloudways_action_delete_backup', array( &$this, 'cloudways_action_delete_backup' ) );

        // GridPane Ajax.
        do_action( 'mainwp_ajax_add_action', 'gridpane_action_create_backup', array( &$this, 'ajax_gridpane_action_create_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'gridpane_action_individual_create_backup', array( &$this, 'ajax_gridpane_action_individual_create_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'gridpane_action_update_ids', array( &$this, 'ajax_gridpane_action_update_ids' ) );
        do_action( 'mainwp_ajax_add_action', 'gridpane_action_refresh_available_backups', array( &$this, 'gridpane_action_refresh_available_backups' ) );
        do_action( 'mainwp_ajax_add_action', 'gridpane_action_restore_backup', array( &$this, 'gridpane_action_restore_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'gridpane_action_delete_backup', array( &$this, 'gridpane_action_delete_backup' ) );

        // Vultr Ajax.
        do_action( 'mainwp_ajax_add_action', 'vultr_action_create_snapshot', array( &$this, 'ajax_vultr_action_create_snapshot' ) );
        do_action( 'mainwp_ajax_add_action', 'vultr_action_individual_create_snapshot', array( &$this, 'ajax_vultr_action_individual_create_snapshot' ) );
        do_action( 'mainwp_ajax_add_action', 'vultr_action_refresh_available_backups', array( &$this, 'vultr_action_refresh_available_backups' ) );
        do_action( 'mainwp_ajax_add_action', 'vultr_action_restore_backup', array( &$this, 'ajax_vultr_action_restore_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'vultr_action_delete_backup', array( &$this, 'vultr_action_delete_backup' ) );

        // Linode Ajax.
        do_action( 'mainwp_ajax_add_action', 'linode_action_create_backup', array( &$this, 'ajax_linode_action_create_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'linode_action_individual_create_backup', array( &$this, 'ajax_linode_action_individual_create_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'linode_action_refresh_available_backups', array( &$this, 'linode_action_refresh_available_backups' ) );
        do_action( 'mainwp_ajax_add_action', 'linode_action_restore_backup', array( &$this, 'linode_action_restore_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'linode_action_cancel_backups', array( &$this, 'linode_action_cancel_backups' ) );

        // DigitalOcean Ajax.
        do_action( 'mainwp_ajax_add_action', 'digitalocean_action_create_backup', array( &$this, 'ajax_digitalocean_action_create_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'digitalocean_action_individual_create_backup', array( &$this, 'ajax_digitalocean_action_individual_create_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'digitalocean_action_refresh_available_backups', array( &$this, 'digitalocean_action_refresh_available_backups' ) );
        do_action( 'mainwp_ajax_add_action', 'digitalocean_action_restore_backup', array( &$this, 'digitalocean_action_restore_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'digitalocean_action_delete_backup', array( &$this, 'digitalocean_action_delete_backup' ) );

        // cPanel Ajax.
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_refresh_available_backups', array( &$this, 'ajax_cpanel_action_refresh_available_backups' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_create_manual_backup', array( &$this, 'ajax_cpanel_action_create_manual_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_create_wptk_backup', array( &$this, 'ajax_cpanel_action_create_wptk_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_restore_wptk_backup', array( &$this, 'ajax_cpanel_action_restore_wptk_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_delete_wptk_backup', array( &$this, 'ajax_cpanel_action_delete_wptk_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_restore_backup', array( &$this, 'ajax_cpanel_action_restore_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_restore_database_backup', array( &$this, 'ajax_cpanel_action_restore_database_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_restore_manual_backup', array( &$this, 'ajax_cpanel_action_restore_manual_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_create_database_backup', array( &$this, 'ajax_cpanel_action_create_database_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'cpanel_action_create_full_backup', array( &$this, 'ajax_cpanel_action_create_full_backup' ) );

        // Plesk Ajax.
        do_action( 'mainwp_ajax_add_action', 'plesk_action_refresh_available_backups', array( &$this, 'ajax_plesk_action_refresh_available_backups' ) );
        do_action( 'mainwp_ajax_add_action', 'plesk_action_create_backup', array( &$this, 'ajax_plesk_action_create_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'plesk_action_restore_backup', array( &$this, 'ajax_plesk_action_restore_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'plesk_action_delete_backup', array( &$this, 'ajax_plesk_action_delete_backup' ) );

        // Kinsta Ajax.
        do_action( 'mainwp_ajax_add_action', 'kinsta_action_refresh_available_backups', array( &$this, 'ajax_kinsta_action_refresh_available_backups' ) );
        do_action( 'mainwp_ajax_add_action', 'kinsta_action_create_backup', array( &$this, 'ajax_kinsta_action_create_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'kinsta_action_restore_backup', array( &$this, 'ajax_kinsta_action_restore_backup' ) );
        do_action( 'mainwp_ajax_add_action', 'kinsta_action_delete_backup', array( &$this, 'ajax_kinsta_action_delete_backup' ) );

        // Backup selected sites.
        do_action( 'mainwp_ajax_add_action', 'action_backup_selected_sites', array( &$this, 'action_backup_selected_sites' ) );

        // Fire off Cloudways & Gridpane ID auto lookup on site addition.
        add_action( 'mainwp_added_new_site', array( &$this, 'hook_added_new_site' ), 10, 2 );
    }


    /**
     * Hook after new site added.
     *
     * @param int    $id Site id just added.
     * @param object $website Site data just added.
     *
     * @return void
     */
    public function hook_added_new_site( $id, $website ) {
        unset( $id );
        $success = $this->fetch_cloudways_settings_for_site( $website );
        if ( ! $success ) {
            $this->fetch_gridpane_settings_for_site( $website );
        }
    }


    /**
     * Hook after new site added.
     *
     * @param object $website Site data just added.
     *
     * @return bool result.
     */
    public function fetch_cloudways_settings_for_site( $website ) {

        if ( empty( $website ) || empty( $website->id ) || empty( $website->url ) ) {
            return false;
        }

        $url = preg_replace( '#^[^:/.]*[:/]+#i', '', preg_replace( '{/$}', '', $website->url ) );

        // Fetch Cloudways Server List.
        $server_list = static::fetch_cloudways_server_list();

        /**
         * Search $server_list for Child Site app ID & server ID.
         *
         * Loop through each Server/App & check if app cname / fqdn name matches any Child Site domain name.
         * if so, save the server & app ID to that Child Site's options table.
         */
        if ( ! is_array( $server_list ) ) {
            return false;
        }

        $found_child_site_id = $website->id;

        foreach ( $server_list as $server ) {
            foreach ( $server->apps as $app ) {
                if ( $app->cname === $url || $app->app_fqdn === $url ) { // Check if app fqdn name matches any Child Site domain name, if so, save the server & app ID to that Child Site's options table.
                    $this->update_3rd_party_cloudways_data( $found_child_site_id, $app );
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Method update_3rd_party_data().
     *
     * @param int    $site_id site id.
     * @param object $app app data.
     *
     * @return void
     */
    public function update_3rd_party_cloudways_data( $site_id, $app ) {
        Api_Backups_Helper::update_website_option( $site_id, 'mainwp_3rd_party_app_id', $app->id );
        Api_Backups_Helper::update_website_option( $site_id, 'mainwp_3rd_party_instance_id', $app->server_id );
        Api_Backups_Helper::update_website_option( $site_id, 'mainwp_3rd_party_api', 'Cloudways' );
    }


    /**
     * Hook after new site added.
     *
     * @param object $website Site data just added.
     *
     * @return bool result.
     */
    public function fetch_gridpane_settings_for_site( $website ) {

        if ( empty( $website ) || empty( $website->id ) || empty( $website->url ) ) {
            return false;
        }

        $url             = rawurlencode( $website->url );
        $strip_protocall = preg_replace( '#^[^:/.]*[:/]+#i', '', preg_replace( '{/$}', '', urldecode( $url ) ) );
        $clean_url       = preg_replace( '/^www\./', '', $strip_protocall );

        // Get Sites list.
        $gridpane_list = static::gridpane_get_sites_list( 200 );

        // Check for Child Site IP @ each instance. Add to Child Site Options Table.
        if ( is_array( $gridpane_list ) ) {
            foreach ( $gridpane_list as $gpane_site ) {
                if ( $gpane_site->url === $clean_url ) {
                    // Grab Site options then update Child Site options.
                    Api_Backups_Helper::update_website_option( $website->id, 'mainwp_3rd_party_instance_id', $gpane_site->id );
                    Api_Backups_Helper::update_website_option( $website->id, 'mainwp_3rd_party_api', 'GridPane' );
                    return true;
                }
            }
        }
        return false;
    }


    /**
     * Global backup page Action notification handler.
     *
     * @return void
     */
    public static function action_notifications() {
        if ( isset( $_GET['backup'] ) && ( 'true' === $_GET['backup'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="ui message green"><i class="icon close"></i> ' . esc_html__( 'All Backups have been created.', 'mainwp' ) . '</div>';
        } elseif ( isset( $_GET['backup'] ) && ( 'false' === $_GET['backup'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="ui message red"><i class="icon close"></i> ' . esc_html__( 'There was an issue with creating your backup.', 'mainwp' ) . '</div>';
        }
    }

    /**
     * Render the main api backups page.
     *
     * @return void
     */
    public static function render_mainwp_backups_page() {
        ?>
            <div class="mainwp-sub-header">
                <div class="ui grid">
                    <div class="ui two column row">
                        <div class="middle aligned column ui">
                            <button id="action_backup_selected_sites" class="ui green mini button"><?php esc_html_e( 'Backup Selected Sites', 'mainwp' ); ?></button>
                        </div>
                        <div class="right aligned middle aligned column">
                            <a href="admin.php?page=SettingsApiBackups" class="ui mini green basic button"><?php esc_html_e( 'Manage API Backups Settings', 'mainwp' ); ?></a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="ui segment" id="mainwp-3rd-party-api-backups">
            <?php if ( Api_Backups_Utility::show_mainwp_message( 'mainwp-module-api-backups-info-message' ) ) : ?>
                    <div class="ui info message">
                        <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-message"></i>
                        <div><?php esc_html_e( 'This page will list the last backups created by the API Backups Extension for each Child Site that is setup with an API provider.', 'mainwp' ); ?></div>
                    </div>
                <?php endif; ?>
            <?php static::action_notifications(); ?>
            <?php static::render_api_backups_table( '3rd-party-api-backups' ); ?>

            </div>
            <?php
    }


    /**
     * Render the global 3rd party api backups table.
     *
     * @return void
     */
    public static function render_api_backups_table() { //phpcs:ignore -- NOSONAR - complex.

        // Get all Child Sites aloud by user role.
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_wp_for_current_user() );

        ?>
            <?php // Render action notifications. I get replaced by JS. ?>
            <div id="mainwp-api-backups-message-zone" class="ui message" style="display: none;">
                <i class="icon close"></i>
                <div class="content">
                    <div class="message"></div>
                </div>
            </div>
            <table id="mainwp-3rd-party-backups-table" class="ui mainwp-api-backup-table table" style="width:100%">
                <thead>
                <tr>
                    <th scope="col" class="no-sort collapsing check-column">
                        <span class="ui checkbox">
                            <label for="cb-select-all-top"></label>
                            <input id="cb-select-all-top" type="checkbox"/>
                        </span>
                    </th>
                    <th scope="col"><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"><i class="sign in alternate icon"></i></th>
                    <th scope="col"><?php esc_html_e( 'URL', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Provider', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Last Manual Backup Date', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"></th>
                </tr>
                </thead>
                <tbody>
            <?php while ( $websites && ( $website = Api_Backups_Helper::fetch_object( $websites ) ) ) { ?>
                    <?php
                    /**
                     * Grab Child Site data needed to display Table.
                     */

                    // Grab API Provider.
                    $api_provider_array   = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_api' ) );
                    $api_provider         = ( isset( $api_provider_array['mainwp_3rd_party_api'] ) && '' !== $api_provider_array['mainwp_3rd_party_api'] ) ? $api_provider_array['mainwp_3rd_party_api'] : 'None';
                    $api_provider_options = strtolower( $api_provider );

                    // Check for Plesk and change to WP-Toolkit.
                    if ( 'Plesk' === $api_provider ) {
                        $api_provider = 'Plesk (WP Toolkit)';
                    }

                    // Grab Last Backup date UTC.
                    $last_backup_array = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_' . $api_provider_options . '_last_backup' ) );
                    $last_backup_UTC   = isset( $last_backup_array[ 'mainwp_3rd_party_' . $api_provider_options . '_last_backup' ] ) ? $last_backup_array[ 'mainwp_3rd_party_' . $api_provider_options . '_last_backup' ] : '';

                    // If found Convert UTC into chosen WP DateTime formats or return "Awaiting first backup".
                    if ( '' === $last_backup_UTC ) {
                        $last_backup = esc_html__( 'Awaiting first backup', 'mainwp' );
                    } else {
                        $last_backup = Api_Backups_Utility::format_timestamp( $last_backup_UTC );
                    }

                    ?>
                    <tr website-id="<?php echo intval( $website->id ); ?>" provider-name="<?php echo esc_attr( $api_provider ); ?>">
                        <td class="check-column">
                            <?php if ( 'None' !== $api_provider ) : ?>
                                <span class="ui checkbox">
                                    <label for="table-checkbox"></label>
                                    <input id="table-checkbox" type="checkbox" value="<?php echo intval( $website->id ); ?>" status="queue" >
                                </span>
                            <?php else : ?>
                                <span class="ui disabled checkbox">
                                    <label for="table-checkbox"></label>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><a href="admin.php?page=ManageSitesApiBackups&id=<?php echo intval( $website->id ); ?>"><?php esc_html_e( $website->name, 'mainwp' ); ?></a> <span class="running"></span></td>
                        <td>
                            <a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_attr( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank">
                                <i class="sign in alternate icon"></i>
                            </a>
                        </td>
                        <td><a href="<?php echo esc_url( $website->url ); ?>" target="_blank"><?php echo esc_url( $website->url ); ?></a></td>
                        <td><?php esc_html_e( $api_provider, 'mainwp' ); ?></td>
                        <td class="last-backup-date" ><?php echo ( $last_backup ) ? esc_html( $last_backup ) : esc_html__( 'Awaiting first backup', 'mainwp' ); ?></td>
                        <td class="collapsing not-selectable">
                            <i class="ui notched circle loading icon" style="display:none;"></i>
                            <div class="ui right pointing dropdown" style="z-index: 999">
                                <a href="javascript:void(0)"><i class="ellipsis vertical icon"></i></a>
                                <div class="menu">
                                    <?php if ( 'cPanel' === $api_provider ) : ?>
                                        <a class="mainwp_3rd_party_api_<?php esc_attr_e( $api_provider_options ); ?>_action_full_backup item"
                                            website_id="<?php echo intval( $website->id ); ?>" href="javascript:void(0)">
                                            <?php esc_html_e( 'Backup', 'mainwp' ); ?>
                                        </a>
                                    <?php elseif ( 'None' !== $api_provider && 'cPanel' !== $api_provider ) : ?>
                                        <a class="mainwp_3rd_party_api_<?php esc_attr_e( $api_provider_options, 'mainwp' ); ?>_action_backup item"
                                            website_id="<?php echo intval( $website->id ); ?>" href="javascript:void(0)">
                                            <?php esc_html_e( 'Backup', 'mainwp' ); ?>
                                        </a>
                                    <?php endif; ?>
                                    <a class="item" href="admin.php?page=ManageSitesApiBackups&id=<?php echo intval( $website->id ); ?>"><?php esc_html_e( 'Manage Backups', 'mainwp' ); ?></a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
            }
            Api_Backups_Helper::free_result( $websites );
            ?>
                </tbody>
            </table>
            <script type="text/javascript">
                let responsive = true;
                if ( jQuery( window ).width() > 1140 ) {
                    responsive = false;
                }
                jQuery( document ).ready( function() {
                    jQuery( '.mainwp-api-backup-table' ).DataTable( {
                        "stateSave": true,
                        "stateDuration": 0,
                        "colReorder" : {columns:":not(.check-column):not(:last-child)"},
                        "responsive": responsive,
                        "scrollX": true,
                        "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
                        "columnDefs": [ { "targets": 'no-sort', "orderable": false } ],
                        "order": [ [ 1, "asc" ] ],
                        "language": { "emptyTable": "No backups found." },
                        "drawCallback": function( settings ) {
                            setTimeout(() => {
                                jQuery('#mainwp-3rd-party-backups-table .ui.checkbox').checkbox();
                                jQuery('.mainwp-api-backup-table .ui.dropdown').dropdown();
                                mainwp_datatable_fix_menu_overflow();
                            }, 1000);
                        },
                        select: {
                            items: 'row',
                            style: 'multi+shift',
                            selector: 'tr>td:not(.not-selectable)'
                        }
                    }).on('select', function (e, dt, type, indexes) {
                        if( 'row' == type ){
                            dt.rows(indexes)
                            .nodes()
                            .to$().find('td.check-column .ui.checkbox' ).checkbox('set checked');
                        }
                    }).on('deselect', function (e, dt, type, indexes) {
                        if( 'row' == type ){
                            dt.rows(indexes)
                            .nodes()
                            .to$().find('td.check-column .ui.checkbox' ).checkbox('set unchecked');
                        }
                    }).on( 'columns-reordered', function () {
                        console.log('columns-reordered');
                        setTimeout(() => {
                            jQuery( '#mainwp-3rd-party-backups-table .ui.dropdown' ).dropdown();
                            jQuery( '#mainwp-3rd-party-backups-table .ui.checkbox' ).checkbox();
                            mainwp_datatable_fix_menu_overflow();
                            mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
                        }, 1000);
                    });
                } );
            </script>
            <?php
    }

    /**
     * Render the Individual 3rd Party API Backup Page.
     *
     * @param mixed $website The Child Site Object.
     */
    public function render_api_backups_site( $website ) { //phpcs:ignore -- NOSONAR - complex function.

        $available_backups = array();

        if ( empty( $website ) ) {
            return;
        }

        $website_id = $website['id'];

        /**
         * Grab Child Site data needed to display Table.
         */

        // Grab backup api name.
        $site_api_name = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_api' ) );
        $backup_api    = isset( $site_api_name['mainwp_3rd_party_api'] ) ? strtolower( $site_api_name['mainwp_3rd_party_api'] ) : null;

        // Grab Child Sites available backups from DB to display in table.
        $site_available_backups = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_' . $backup_api . '_available_backups' ) );

        // Check if valid JSON or not. If not, then it's a string - do not decode it.
        if ( isset( $site_available_backups[ 'mainwp_3rd_party_' . $backup_api . '_available_backups' ] ) && ! empty( $site_available_backups[ 'mainwp_3rd_party_' . $backup_api . '_available_backups' ] ) ) {
            json_decode( $site_available_backups[ 'mainwp_3rd_party_' . $backup_api . '_available_backups' ] );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                // JSON is valid.
                $available_backups = json_decode( $site_available_backups[ 'mainwp_3rd_party_' . $backup_api . '_available_backups' ] );
            } else {
                // JSON is invalid treat it as a string.
                $available_backups = $site_available_backups[ 'mainwp_3rd_party_' . $backup_api . '_available_backups' ];
            }
        } else {
            $available_backups = array();
        }

        if ( empty( $backup_api ) ) {
            ?>
                <div class="ui placeholder segment">
                    <div class="ui icon header">
                        <i class="key icon"></i>
                    <?php
                        printf(
                            esc_html__(
                                '%1$sNo API Backup Solution has been chosen.%2$s
                    Please double check that you have set the %3$sAPI Key%4$s
                    on the %5$s page%6$s
                    and have set the %7$sInstance ID%8$s on the %9$s page.',
                                'mainwp'
                            ),
                            '<em>',
                            '</em> <br/><br>',
                            '<em>',
                            '</em>',
                            '<a href="admin.php?page=SettingsApiBackups">API Backups Settings</a>',
                            '</br>',
                            '<em>',
                            '</em>',
                            '<a href="admin.php?page=managesites&id=' . intval( $website_id ) . '">Child Site -> Edit</a>'
                        );
                    ?>
                    </div>
                </div>
            <?php } else { ?>

                <?php
                $columns = 'one';
                if ( 'cpanel' === $backup_api || 'plesk' === $backup_api ) {
                    $columns = 'two';
                }
                ?>
                <div class="mainwp-sub-header">
                    <div class="ui grid">
                        <div class="ui <?php echo esc_html( $columns ); ?> column row">
                                <div class="middle aligned column ui">
                                    <?php if ( 'cpanel' === $backup_api ) : ?>
                                        <div id="mainwp_api_cpanel_backup_tabs" class="ui top attached tabular menu">
                                            <div class="active item" data-tab="cpanel-native"><i class="fitted cpanel big icon"></i></div>
                                            <div class="item" data-tab="cpanel-wp-toolkit"><i class="fitted wordpress big icon"></i></div><?php //phpcs:ignore -- skip wordpress.?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <div class="right aligned middle aligned column">
                                <?php if ( 'cpanel' === $backup_api ) : ?>
                                    <button id="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_create_full_backup" website_id="<?php echo intval( $website['id'] ); ?>" class="ui mini green button"><?php esc_html_e( 'Backup Files &amp; Database', 'mainwp' ); ?></button>
                                    <button id="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_create_wptk_backup" website_id="<?php echo intval( $website['id'] ); ?>" class="ui mini green button hidden"><?php esc_html_e( 'Backup Now', 'mainwp' ); ?></button>
                                <?php else : ?>
                                    <button id="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_individual_create_backup" website_id="<?php echo intval( $website['id'] ); ?>" class="ui mini green button"><?php esc_html_e( 'Backup Now', 'mainwp' ); ?></button>
                                <?php endif; ?>
                                <button id="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_refresh_available_backups" website_id="<?php echo intval( $website['id'] ); ?>" class="ui mini green button"><?php esc_html_e( 'Refresh Available Backups', 'mainwp' ); ?></button>

                                <?php // Render DigitalOcean specific action buttons. ?>
                                <?php
                                if ( 'digitalocean' === $backup_api ) {
                                    // Grab Linode instance id.
                                    $digitalocean_droplet_id = Api_Backups_Helper::get_website_options(
                                        $website,
                                        array(
                                            'mainwp_3rd_party_instance_id',
                                        )
                                    );
                                    $droplet_id              = isset( $digitalocean_droplet_id['mainwp_3rd_party_instance_id'] ) ? $digitalocean_droplet_id['mainwp_3rd_party_instance_id'] : null;
                                    ?>
                                        <a href="https://cloud.digitalocean.com/droplets/<?php echo esc_attr( $droplet_id ); ?>/snapshots" target="_blank" class="ui mini button">
                                            <i class="external icon"></i>
                                        <?php esc_html_e( 'View on DigitalOcean', 'mainwp' ); ?>
                                        </a>
                                    <?php } ?>
                                <?php // Render Linode specific action buttons. ?>
                                <?php
                                if ( 'linode' === $backup_api ) {
                                    // Grab Linode instance id.
                                    $linode_instance_id = Api_Backups_Helper::get_website_options(
                                        $website,
                                        array(
                                            'mainwp_3rd_party_instance_id',
                                        )
                                    );
                                    $instance_id        = isset( $linode_instance_id['mainwp_3rd_party_instance_id'] ) ? $linode_instance_id['mainwp_3rd_party_instance_id'] : null;
                                    ?>
                                        <button id="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_cancel_backups" website_id="<?php echo intval( $website['id'] ); ?>" class="ui mini button"><?php esc_html_e( 'Disable & Remove Backups on This Linode', 'mainwp' ); ?></button>
                                        <a href="https://cloud.linode.com/linodes/<?php echo esc_attr( $instance_id ); ?>/backup" target="_blank" class="ui mini button">
                                            <i class="external icon"></i>
                                        <?php esc_html_e( 'View on Akamai (Linode)', 'mainwp' ); ?>
                                        </a>
                                    <?php } ?>
                                <?php // Render Cloudways specific action buttons. ?>
                                <?php
                                if ( 'cloudways' === $backup_api ) {
                                    // Grab Cloudways app id.
                                    $site_options = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_app_id' ) );
                                    $app_id       = isset( $site_options['mainwp_3rd_party_app_id'] ) ? $site_options['mainwp_3rd_party_app_id'] : null;
                                    ?>
                                        <button id="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_delete_backup" website_id="<?php echo intval( $website['id'] ); ?>" class="ui mini button"><?php esc_html_e( 'Delete 24hr Restore Point', 'mainwp' ); ?></button>
                                        <a href="https://platform.cloudways.com/apps/<?php echo esc_attr( $app_id ); ?>/restore" target="_blank" class="ui mini button">
                                            <i class="external icon"></i>
                                        <?php esc_html_e( 'View on Cloudways', 'mainwp' ); ?>
                                        </a>
                                    <?php } ?>
                                <?php // Render Vultr specific action buttons. ?>
                                <?php
                                if ( 'vultr' === $backup_api ) {
                                    // Grab Vultr Instance id.
                                    $site_options = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_instance_id' ) );
                                    $instance_id  = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;
                                    ?>
                                        <a href="https://my.vultr.com/subs/?id=<?php echo esc_attr( $instance_id ); ?>#subssnapshots" target="_blank" class="ui mini button">
                                            <i class="external icon"></i>
                                        <?php esc_html_e( 'View on Vultr', 'mainwp' ); ?>
                                        </a>
                                    <?php } ?>
                                <?php // Render GridPane specific action buttons. ?>
                                <?php if ( 'gridpane' === $backup_api ) { ?>
                                    <a href="https://my.gridpane.com/sites" target="_blank" class="ui mini button">
                                        <i class="external icon"></i>
                                        <?php esc_html_e( 'View on GridPane', 'mainwp' ); ?>
                                    </a>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ui segment">

                    <?php // Render action notifications. I get replaced by JS. ?>
                    <div id="mainwp-api-backups-message-zone" class="ui message" style="display: none;">
                        <i class="icon close"></i>
                        <div class="content">
                            <div class="message"></div>
                        </div>
                    </div>

                <?php // Render info notifications. ?>
                    <?php // Render Linode info notifications. ?>
                    <?php if ( 'linode' === $backup_api ) { ?>
                        <?php if ( Api_Backups_Utility::show_mainwp_message( 'mainwp-module-api-backups-info-common-message1' ) ) : ?>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-common-message1"></i>
                                <div><?php esc_html_e( 'Three backup slots are executed and rotated automatically: a daily backup, a 2-7 day old backup, and an 8-14 day old backup. You will need to enable backups on your Akamai (Linode) Instance in order to use this feature.', 'mainwp' ); ?></div>
                            </div>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-common-message1"></i>
                                <div><?php esc_html_e( 'Please note that when you click the Disable & Remove Backups button it cancels the Backup service on the given Linode. Deletes all of this Linode\'s existing backups forever.', 'mainwp' ); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php } ?>
                    <?php if ( 'cloudways' === $backup_api ) { ?>
                        <?php if ( Api_Backups_Utility::show_mainwp_message( 'mainwp-module-api-backups-info-common-message2' ) ) : ?>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-common-message2"></i>
                                <div><?php esc_html_e( ' This page allows you to back up and restore your currently available Cloudways backups.', 'mainwp' ); ?></div>
                            </div>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-common-message2"></i>
                                <p><?php esc_html_e( 'Please note that Cloudways does not allow you to delete individual backups.', 'mainwp' ); ?></p>
                                <p><?php esc_html_e( 'A local copy of your application will be saved automatically before you restore your application using the restore feature.', 'mainwp' ); ?></p>
                                <p><?php esc_html_e( 'To use the restore feature again, you will have to either delete this local copy by clicking the Delete 24hr Restore Point button or roll your application back to the state it was before you restored it via the Cloudways Account UI.', 'mainwp' ); ?></p>
                                <p><?php esc_html_e( 'This local copy is kept for the first 24 hours, after which it is automatically removed.', 'mainwp' ); ?></p>
                            </div>
                        <?php endif; ?>
                    <?php } ?>
                    <?php if ( 'digitalocean' === $backup_api ) { ?>
                        <?php if ( Api_Backups_Utility::show_mainwp_message( 'mainwp-module-api-backups-info-common-message3' ) ) : ?>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-common-message3"></i>
                                <div><?php esc_html_e( ' This page allows you to back up and restore your currently available DigitalOcean backups.', 'mainwp' ); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php } ?>
                    <?php if ( 'gridpane' === $backup_api ) { ?>
                        <?php if ( Api_Backups_Utility::show_mainwp_message( 'mainwp-module-api-backups-info-common-message4' ) ) : ?>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-common-message4"></i>
                                <div><?php esc_html_e( ' This page allows you to back up and restore your currently available GridPane backups.', 'mainwp' ); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php } ?>
                    <?php if ( 'vultr' === $backup_api ) { ?>
                        <?php if ( Api_Backups_Utility::show_mainwp_message( 'mainwp-module-api-backups-info-common-message5' ) ) : ?>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-common-message5"></i>
                                <div><?php esc_html_e( ' This page allows you to back up and restore your currently available Vultr backups.', 'mainwp' ); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php } ?>
                    <?php // Display Kinsta Table. ?>
                    <?php
                    if ( 'kinsta' === $backup_api ) {

                        if ( is_object( $available_backups ) ) {
                            $available_backups = $available_backups->environment->backups;
                        } else {
                            $available_backups = array();
                        }

                        ?>
                        <?php if ( Api_Backups_Utility::show_mainwp_message( 'mainwp-module-api-backups-info-message' ) ) : ?>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-module-api-backups-info-message"></i>
                                <div><?php esc_html_e( 'You can create up to 5 manual backups. Each manual backup will be stored for 14 days.', 'mainwp' ); ?></div>
                            </div>
                        <?php endif; ?>
                        <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                            <thead>
                            <tr>
                                <th  scope="col"><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                <th  scope="col"><?php esc_html_e( 'Note', 'mainwp' ); ?></th>
                                <th  scope="col"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                                <th  scope="col"><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th  scope="col" class="no-sort collapsing"></th>
                                <th  scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $available_backups as $backup ) { ?>
                                <?php
                                // Convert Kinsta backup date to human readable format.
                                $backup_date = Api_Backups_Utility::format_timestamp( intval( $backup->created_at / 1000 ) );
                                ?>
                                <tr>
                                    <td class="collapsing"><?php esc_html_e( $backup->name ); ?></td>
                                    <td class="collapsing"><?php echo esc_html( $backup->note ); ?></td>
                                    <td class="collapsing"><?php echo esc_html( $backup->type ); ?></td>
                                    <td class="collapsing"><?php echo esc_html( $backup_date ); ?></td>
                                    <td></td>
                                    <td class="collapsing right aligned">
                                        <button id="kinsta_restore_button" class="ui circular icon button mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup item"
                                                website_id="<?php echo intval( $website['id'] ); ?>"
                                                backup_id="<?php echo intval( $backup->id ); ?>"
                                                data-tooltip="<?php esc_attr_e( 'Restore Backup', 'mainwp' ); ?>"
                                                data-inverted=""
                                                data-position="top center">
                                            <i class="undo icon"></i>
                                        </button>
                                        <button id="kinsta_delete_button" class="ui circular icon button mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_delete_backup item"
                                                website_id="<?php echo intval( $website['id'] ); ?>"
                                                backup_id="<?php echo intval( $backup->id ); ?>"
                                                data-tooltip="<?php esc_attr_e( 'Delete Backup', 'mainwp' ); ?>"
                                                data-inverted=""
                                                data-position="top center">
                                            <i class="trash icon"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th  scope="col"><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                <th  scope="col"><?php esc_html_e( 'Environment', 'mainwp' ); ?></th>
                                <th  scope="col"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                                <th  scope="col"><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th  scope="col" class="no-sort collapsing"></th>
                                <th  scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </tfoot>
                        </table>
                        <div class="ui divider hidden"></div>
                        <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                            <thead>
                            <tr>
                                <th scope="col" colspan="3">
                                    <div class="ui equal width grid">
                                        <div class="left aligned middle aligned column">
                                            <?php esc_html_e( 'Downloadable Backups', 'mainwp' ); ?>
                                        </div>
                                    </div>
                                </th>
                            </tr>
                            <tr>
                                <th  scope="col"><?php esc_html_e( 'Created', 'mainwp' ); ?></th>
                                <th  scope="col"><?php esc_html_e( 'Expiry', 'mainwp' ); ?></th>
                                <th  scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                                // Grab Downloadable Backups.
                                $site_options         = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_kinsta_downloadable_backups' ) );
                                $downloadable_backups = isset( $site_options['mainwp_3rd_party_kinsta_downloadable_backups'] ) ? $site_options['mainwp_3rd_party_kinsta_downloadable_backups'] : '';
                                $downloadable_backups = json_decode( $downloadable_backups );
                            ?>
                            <?php if ( is_object( $downloadable_backups ) ) { ?>
                                <?php foreach ( $downloadable_backups->environment->downloadable_backups as $backup ) { ?>
                                    <?php

                                    // Convert Kinsta backup dates to human readable format.
                                    $created_date = Api_Backups_Utility::format_timestamp( intval( $backup->created_at / 1000 ) );
                                    $expiry_date  = Api_Backups_Utility::format_timestamp( intval( $backup->expires_at / 1000 ) );

                                    // Check if backup is expired, disable row including download button.
                                    if ( intval( $backup->expires_at / 1000 ) > time() ) {

                                        ?>

                                        <tr>
                                            <td class="collapsing"><?php esc_html_e( $created_date ); ?></td>
                                            <td class="collapsing"><?php esc_html_e( $expiry_date ); ?></td>
                                            <td>
                                                <a class="kinsta_download" href="<?php echo esc_attr( $backup->download_link ); ?>" target="_blank">
                                                    <button class="ui right labeled icon button">
                                                        <i class="right download icon"></i>
                                                        <?php echo esc_html__( 'Download', 'mainwp' ); ?>
                                                    </button>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php } else { ?>
                                        <tr class="disabled">
                                            <td class="collapsing"><?php esc_html_e( $created_date ); ?></td>
                                            <td class="collapsing"><?php esc_html_e( $expiry_date ); ?></td>
                                            <td>
                                                <a class="kinsta_download" href="<?php echo esc_attr( $backup->download_link ); ?>" target="_blank">
                                                    <button class="ui right labeled icon button disabled">
                                                        <i class="right download icon"></i>
                                                        <?php echo esc_html__( 'Download', 'mainwp' ); ?>
                                                    </button>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            <?php } ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Created', 'mainwp' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Expiry', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </tfoot>
                        </table>
                    <?php } ?>
                <?php // Display Plesk Table. ?>
                <?php
                if ( 'plesk' === $backup_api ) {
                    // Grab available cPanel Automatic Backups or set to empty array.
                    if ( is_object( $available_backups ) && isset( $available_backups->value ) ) {
                        $available_backups = $available_backups->value;
                    } else {
                        $available_backups = array();
                    }
                    ?>
                        <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                            <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $available_backups as $backup ) { ?>
                                <?php
                                $backup_date = gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $backup->value->createdAt->value ) );

                                    $opts = Api_Backups_Helper::get_website_options(
                                        $website['id'],
                                        array(
                                            'mainwp_plesk_installation_id', // Plesk installation id.
                                        )
                                    );

                                if ( is_array( $opts ) ) {
                                    $installation_id = $opts['mainwp_plesk_installation_id'] ?? '';
                                }
                                ?>
                                <tr>
                                    <td class="collapsing"><?php esc_html_e( $backup->value->fileName->value ); ?></td>
                                    <td class="collapsing"><?php echo esc_html( $backup_date ); ?></td>
                                    <td></td>
                                    <td class="collapsing right aligned">
                                        <button id="plesk_restore_button" class="ui circular mini icon button mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup item"
                                                installation_id="<?php echo intval( $installation_id ); ?>"
                                                website_id="<?php echo intval( $website['id'] ); ?>"
                                                backup_name="<?php echo esc_attr( $backup->value->fileName->value ); ?>"
                                                data-tooltip="<?php esc_attr_e( 'Restore Backup', 'mainwp' ); ?>"
                                                data-inverted=""
                                                data-position="top center">
                                            <i class="undo icon"></i>
                                        </button>
                                        <?php
                                            /**
                                             * Grab installation domain & build path to download.
                                             * Eg.: /var/www/vhosts/example.com/wordpress-backups/
                                             */
                                            $installation_domain = static::get_plesk_home_dir();
                                            $dirdl               = '/var/www/vhosts/' . $installation_domain . '/wordpress-backups/';
                                        ?>

                                        <a id="plesk_download_button" class="ui circular mini icon button  mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_downlaod_backup item"
                                                installation_id="<?php echo intval( $installation_id ); ?>"
                                                website_id="<?php echo intval( $website['id'] ); ?>"
                                                backup_name="<?php echo esc_attr( $backup->value->fileName->value ); ?>"
                                                href="admin.php?page=SiteOpen&websiteid=<?php echo intval( $website['id'] ); ?>&dirdl=<?php echo esc_attr( rawurlencode( $dirdl ) ); ?>&filedl=<?php echo esc_attr( rawurlencode( $backup->value->fileName->value ) ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"
                                                data-tooltip="<?php esc_attr_e( 'Download Backup', 'mainwp' ); ?>"
                                                data-inverted=""
                                                data-position="top center"
                                                target="_blank">
                                                <i class="download icon"></i>
                                            </a>
                                        <button id="plesk_delete_button" class="ui circular mini icon button mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_delete_backup item"
                                                installation_id="<?php echo intval( $installation_id ); ?>"
                                                website_id="<?php echo intval( $website['id'] ); ?>"
                                                backup_name="<?php echo esc_attr( $backup->value->fileName->value ); ?>"
                                                data-tooltip="<?php esc_attr_e( 'Delete Backup', 'mainwp' ); ?>"
                                                data-inverted=""
                                                data-position="top center">
                                            <i class="trash icon"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </tfoot>
                        </table>
                <?php } ?>
                <?php // Display cPanel Table. ?>
                <?php
                if ( 'cpanel' === $backup_api ) {

                    // Grab available cPanel Automatic Backups or set to empty array.
                    if ( is_object( $available_backups ) && isset( $available_backups->data ) ) {
                        $available_backups_automatic_list = $available_backups->data;
                    } else {
                        $available_backups_automatic_list = array();
                    }

                    // Grab available cPanel Manual Backups.
                    $available_backups_manual_list = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_' . $backup_api . '_manual_backups' ) );

                    // Check if valid JSON or not. If not, then it's a string - do not decode it.
                    if ( isset( $available_backups_manual_list[ 'mainwp_3rd_party_' . $backup_api . '_manual_backups' ] ) && ! empty( $available_backups_manual_list[ 'mainwp_3rd_party_' . $backup_api . '_manual_backups' ] ) ) {
                        json_decode( $available_backups_manual_list[ 'mainwp_3rd_party_' . $backup_api . '_manual_backups' ] );
                        if ( json_last_error() === JSON_ERROR_NONE ) {
                            // JSON is valid.
                            $available_backups_manual_list = json_decode( $available_backups_manual_list[ 'mainwp_3rd_party_' . $backup_api . '_manual_backups' ] );
                        } else {
                            // JSON is invalid treat it as a string.
                            $available_backups_manual_list = $available_backups_manual_list[ 'mainwp_3rd_party_' . $backup_api . '_manual_backups' ];
                        }
                    } else {
                        $available_backups_manual_list = array();
                    }

                    // Grab available cPanel Manual Database Backups.
                    $mainwp_3rd_party_cpanel_manual_database_backups = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_' . $backup_api . '_manual_database_backups' ) );

                    // Check if valid JSON or not. If not, then it's a string - do not decode it.
                    if ( isset( $mainwp_3rd_party_cpanel_manual_database_backups[ 'mainwp_3rd_party_' . $backup_api . '_manual_database_backups' ] ) && ! empty( $mainwp_3rd_party_cpanel_manual_database_backups[ 'mainwp_3rd_party_' . $backup_api . '_manual_database_backups' ] ) ) {
                        json_decode( $mainwp_3rd_party_cpanel_manual_database_backups[ 'mainwp_3rd_party_' . $backup_api . '_manual_database_backups' ] );
                        if ( json_last_error() === JSON_ERROR_NONE ) {
                            // JSON is valid.
                            $available_manual_database_backups = json_decode( $mainwp_3rd_party_cpanel_manual_database_backups[ 'mainwp_3rd_party_' . $backup_api . '_manual_database_backups' ] );
                        } else {
                            // JSON is invalid treat it as a string.
                            $available_manual_database_backups = $mainwp_3rd_party_cpanel_manual_database_backups[ 'mainwp_3rd_party_' . $backup_api . '_manual_database_backups' ];
                        }
                    } else {
                        $available_manual_database_backups = array();
                    }
                    ?>
                    <div class="ui bottom attached active tab" data-tab="cpanel-native">
                            <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                                <thead>
                                <tr>
                                    <th scope="col" colspan="4">
                                        <?php esc_html_e( 'Automatic Host Backups', 'mainwp' ); ?>
                                    </th>
                                </tr>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Backup Type', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Site Path', 'mainwp' ); ?></th>
                                    <th scope="col" class="no-sort collapsing"></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $available_backups_automatic_list as $backup ) { ?>
                                    <tr>
                                        <td><?php esc_html_e( $backup->backupID ); ?></td>
                                        <td><?php esc_html_e( ucfirst( $backup->backupType ), 'mainwp' ); ?></td>
                                        <td><?php echo esc_html_e( $backup->path ); ?></td>
                                        <td>
                                            <a id="cpanel_automatic_backup_button" class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup item"
                                                website_id="<?php echo intval( $website['id'] ); ?>"
                                                backup_type="<?php echo esc_attr( 'automatic' ); ?>"
                                                backup_path="<?php echo esc_attr( $backup->path ); ?>"
                                                backup_name="<?php echo esc_attr( $backup->backupID ); ?>"
                                                href="javascript:void(0)">
                                                <button class="ui right labeled icon button">
                                                    <i class="right undo icon"></i>
                                                    <?php esc_html_e( 'Restore', 'mainwp' ); ?>
                                                </button>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                                <tfoot>
                                <tr>
                                    <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                    <th scope="col" ><?php esc_html_e( 'Backup Type', 'mainwp' ); ?></th>
                                    <th scope="col" ><?php esc_html_e( 'Site Path', 'mainwp' ); ?></th>
                                    <th scope="col" class="no-sort collapsing"></th>
                                </tr>
                                </tfoot>
                        </table>
                        <div class="ui divider hidden"></div>
                        <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                                <thead>
                                <tr>
                                    <th scope="col" colspan="3">
                                        <div class="ui equal width grid">
                                            <div class="left aligned middle aligned column">
                                                <?php esc_html_e( 'Manual Account Backups', 'mainwp' ); ?>
                                            </div>
                                            <div class="right aligned middle aligned column">
                                                <button id="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_individual_create_backup" website_id="<?php echo intval( $website['id'] ); ?>" class="ui primary elastic green button"><?php esc_html_e( 'Backup', 'mainwp' ); ?></button>
                                            </div>
                                        </div>
                                    </th>
                                </tr>
                                <tr>
                                    <th scope="col" ><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                    <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                    <th scope="col" ="no-sort collapsing"></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $available_backups_manual_list as $backup ) { ?>
                                    <?php
                                    $my_unix_timestamp = $backup->timestamp;
                                    $backup_date       = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $my_unix_timestamp ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
                                    ?>
                                    <tr>
                                        <td class="collapsing"><?php esc_html_e( $backup->file_name ); ?></td>
                                        <td class="collapsing"><?php esc_html_e( $backup_date ); ?></td>
                                        <td>
                                            <?php
                                                $file_path = $backup->absolute_dir . '/';
                                            ?>
                                            <a class="open_newwindow_wpadmin" href="admin.php?page=SiteOpen&websiteid=<?php echo intval( $website['id'] ); ?>&dirdl=<?php echo esc_attr( rawurlencode( $file_path ) ); ?>&filedl=<?php echo esc_attr( rawurlencode( $backup->file_name ) ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank">
                                                <button class="ui right labeled icon button">
                                                    <i class="right download icon"></i>
                                                    <?php echo esc_html__( 'Download', 'mainwp' ); ?>
                                                </button>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                                <tfoot>
                                <tr>
                                    <th scope="col" ><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                    <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                    <th scope="col" class="no-sort collapsing"></th>
                                </tr>
                                </tfoot>
                            </table>
                        <div class="ui divider hidden"></div>
                            <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                                <thead>
                                <tr>
                                    <th scope="col" colspan="3">
                                        <div class="ui equal width grid">
                                            <div class="left aligned middle aligned column">
                                                <?php esc_html_e( 'Manual Database Backups', 'mainwp' ); ?>
                                            </div>
                                            <div class="right aligned middle aligned column">
                                                <button id="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_create_database_backup" website_id="<?php echo intval( $website['id'] ); ?>" class="ui primary elastic green button"><?php esc_html_e( 'Backup', 'mainwp' ); ?></button>
                                            </div>
                                        </div>
                                    </th>
                                </tr>
                                <tr>
                                    <th scope="col" ><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                    <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                    <th scope="col" class="no-sort collapsing"></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $available_manual_database_backups as $backup ) { ?>
                                    <?php
                                    $my_unix_timestamp = $backup->timestamp;
                                    $backup_date       = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $my_unix_timestamp ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
                                    ?>
                                    <tr>
                                        <td class="collapsing"><?php esc_html_e( $backup->file_name ); ?></td>
                                        <td class="collapsing"><?php esc_html_e( $backup_date ); ?></td>
                                        <td>
                                            <a id="database_backup_button" class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_database_backup item"
                                                website_id="<?php echo intval( $website['id'] ); ?>"
                                                backup_path="<?php echo esc_attr( $backup->absolute_dir ); ?>"
                                                backup_name="<?php echo esc_attr( $backup->file_name ); ?>"
                                                href="javascript:void(0)">
                                                <button class="ui right labeled icon button">
                                                    <i class="right undo icon"></i>
                                                    <?php esc_html_e( 'Restore', 'mainwp' ); ?>
                                                </button>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                                <tfoot>
                                <tr>
                                    <th scope="col" ><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                    <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                    <th scope="col" class="no-sort collapsing"></th>
                                </tr>
                                </tfoot>
                            </table>
                    </div>
                    <div class="ui bottom attached tab" data-tab="cpanel-wp-toolkit">
                        <?php
                        $available_wptk_backups = array();
                        /**
                         * Grab WP-Toolkit status & available backups.
                         */
                        $mainwp_enable_wp_toolkit = '0'; // Default to false.

                        if ( is_array( $website ) && key_exists( 'id', $website ) ) {
                            // Grab given website options.
                            $opts = Api_Backups_Helper::get_website_options(
                                $website['id'],
                                array(
                                    'mainwp_enable_wp_toolkit', // WP-Toolkit status.
                                    'mainwp_3rd_party_cpanel_wp_toolkit_backups', // WP-Toolkit backups.
                                )
                            );

                            if ( is_array( $opts ) ) {
                                // Grab WP-Toolkit status.
                                $mainwp_enable_wp_toolkit = $opts['mainwp_enable_wp_toolkit'] ?? '0';

                                // Grab WP-Toolkit backups.
                                $cpanel_wp_toolkit_backups = ! empty( $opts['mainwp_3rd_party_cpanel_wp_toolkit_backups'] ) ? json_decode( $opts['mainwp_3rd_party_cpanel_wp_toolkit_backups'] ) : false;
                                $available_wptk_backups    = is_object( $cpanel_wp_toolkit_backups ) && ! empty( $cpanel_wp_toolkit_backups->value ) ? $cpanel_wp_toolkit_backups->value : false;
                            }
                        }
                        ?>
                        <?php if ( $mainwp_enable_wp_toolkit ) : // Display WP-Toolkit Table if enabled. ?>
                                <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-wptk-table" class="ui mainwp-api-backup-table table" style="width:100%">
                                    <thead>
                                    <tr>
                                        <th scope="col" colspan="4">
                                            <?php esc_html_e( 'WP Toolkit Backups', 'mainwp' ); ?>
                                        </th>
                                    </tr>
                                    <tr>
                                        <th scope="col" ><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                        <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                        <th scope="col" class="no-sort collapsing"></th>
                                        <th scope="col" class="no-sort collapsing"></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if ( ! is_array( $available_wptk_backups ) ) {
                                        $available_wptk_backups = array();
                                    }
                                    foreach ( $available_wptk_backups as $backup ) {
                                        ?>
                                        <?php
                                        $my_unix_timestamp = strtotime( $backup->value->createdAt->value );
                                        $backup_date       = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $my_unix_timestamp ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
                                        ?>
                                        <tr>
                                            <td class=""><?php esc_html_e( $backup->value->fileName->value ); ?></td>
                                            <td class=""><?php echo esc_html( $backup_date ); ?></td>
                                            <td></td>
                                            <td class="right aligned">
                                                <button class="ui mini icon button mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_wptk_backup item"
                                                        website_id="<?php echo intval( $website['id'] ); ?>"
                                                        backup_name="<?php echo esc_attr( $backup->value->fileName->value ); ?>"
                                                        data-tooltip="<?php esc_attr_e( 'Restore Backup', 'mainwp' ); ?>"
                                                        data-inverted=""
                                                        data-position="top center">
                                                    <i class="undo icon"></i>
                                                </button>
                                                <?php
                                                    /**
                                                     * Grab home directory & build download directory.
                                                     * Eg.: /home/cpanel_username/wordpress-backups/
                                                     */
                                                    $home_dir = static::cpanel_action_get_home_directory();
                                                    $dirdl    = $home_dir . '/wordpress-backups/';
                                                ?>
                                                <button class="ui mini icon button mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_download_wptk_backup item"
                                                        website_id="<?php echo intval( $website['id'] ); ?>"
                                                        backup_name="<?php echo esc_attr( $backup->value->fileName->value ); ?>"
                                                        href="admin.php?page=SiteOpen&websiteid=<?php echo intval( $website['id'] ); ?>&dirdl=<?php echo esc_attr( rawurlencode( $dirdl ) ); ?>&filedl=<?php echo esc_attr( rawurlencode( $backup->value->fileName->value ) ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"
                                                        data-tooltip="<?php esc_attr_e( 'Download Backup', 'mainwp' ); ?>"
                                                        data-inverted=""
                                                        data-position="top center">
                                                    <i class="download icon"></i>
                                                </button>
                                                <button class="ui mini icon button mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_delete_wptk_backup item"
                                                        website_id="<?php echo intval( $website['id'] ); ?>"
                                                        backup_name="<?php echo esc_attr( $backup->value->fileName->value ); ?>"
                                                        data-tooltip="<?php esc_attr_e( 'Delete Backup', 'mainwp' ); ?>"
                                                        data-inverted=""
                                                        data-position="top center">
                                                    <i class="trash icon"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                    <tfoot>
                                    <tr>
                                        <th scope="col" ><?php esc_html_e( 'Backup Name', 'mainwp' ); ?></th>
                                        <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                        <th scope="col" class="no-sort collapsing"></th>
                                        <th scope="col" class="no-sort collapsing"></th>
                                    </tr>
                                    </tfoot>
                                </table>
                        <?php else : // Display message if WP-Toolkit is not enabled. ?>
                            <div class="ui placeholder segment">
                                <div class="ui icon header">
                                    <i class="key icon"></i>
                                    <?php
                                    printf(
                                        esc_html__(
                                            '%1$sThe WP-Toolkit API has not been enabled.%2$s
                                        Please double check that you have set the cPanel %3$sAPI Key%4$s
                                        on the %5$s page%6$s
                                        and have enabled the %7$sWP Toolkit API%8$s on the %9$s page.',
                                            'mainwp'
                                        ),
                                        '<em>',
                                        '</em> <br/><br>',
                                        '<em>',
                                        '</em>',
                                        '<a href="admin.php?page=SettingsApiBackups">API Backups Settings</a>',
                                        '</br>',
                                        '<em>',
                                        '</em>',
                                        '<a href="admin.php?page=managesites&id=' . intval( $website_id ) . '">Child Site -> Edit</a>'
                                    );
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php } ?>
                <?php // Display GridPane Table. ?>
                <?php
                if ( 'gridpane' === $backup_api ) {
                    if ( isset( $available_backups->automatic ) ) {
                        $available_backups_automatic_list = explode( ',', $available_backups->automatic );
                    } else {
                        $available_backups_automatic_list = array();
                    }
                    if ( isset( $available_backups->manual ) ) {
                        $available_backups_manual_list = explode( ',', $available_backups->manual );
                    } else {
                        $available_backups_manual_list = array();
                    }
                    ?>
                        <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                            <thead>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'GridPane Backup Name', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $available_backups_automatic_list as $backup ) { ?>
                                <tr>
                                    <td><i class="ui notched circle loading icon" style="display:none;"></i> <?php esc_html_e( $backup ); ?></td>
                                    <td><?php esc_html_e( 'Automatic', 'mainwp' ); ?></td>
                                    <td></td>
                                    <td class="collapsing right aligned">
                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup ui mini icon button"
                                            website_id="<?php echo intval( $website['id'] ); ?>"
                                            backup_type="<?php echo esc_attr( 'automatic' ); ?>"
                                            backup_name="<?php echo esc_attr( $backup ); ?>"
                                            href="javascript:void(0)">
                                            <i class="undo icon"></i>
                                        </a>
                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_delete_backup ui mini icon button"
                                            website_id="<?php echo intval( $website['id'] ); ?>"
                                            backup_type="<?php echo esc_attr( 'automatic' ); ?>"
                                            backup_name="<?php echo esc_attr( $backup ); ?>"
                                            href="javascript:void(0)">
                                            <i class="trash icon"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                            <?php foreach ( $available_backups_manual_list as $backup ) { ?>
                                <tr>
                                    <td><i class="ui notched circle loading icon" style="display:none;"></i> <?php esc_html_e( $backup ); ?></td>
                                    <td><?php esc_html_e( 'Manual', 'mainwp' ); ?></td>
                                    <td></td>
                                    <td class="collapsing right aligned">
                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup ui mini icon button"
                                            website_id="<?php echo intval( $website['id'] ); ?>"
                                            backup_type="<?php echo esc_attr( 'manual' ); ?>"
                                            backup_name="<?php echo esc_attr( $backup ); ?>"
                                            href="javascript:void(0)">
                                            <i class="undo icon"></i>
                                        </a>
                                        <a class="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_delete_backup ui mini icon button"
                                            website_id="<?php echo intval( $website['id'] ); ?>"
                                            backup_type="<?php echo esc_attr( 'manual' ); ?>"
                                            backup_name="<?php echo esc_attr( $backup ); ?>"
                                            href="javascript:void(0)">
                                            <i class="trash icon"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'GridPane Backup Name', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </tfoot>
                        </table>
                    <?php } ?>
                <?php // Display Cloudways Table. ?>
                <?php
                if ( 'cloudways' === $backup_api ) {

                    // Check if backups are available.
                    if ( isset( $available_backups->application_backup_exists ) && true === $available_backups->application_backup_exists ) {
                        $available_backups = $available_backups->backup_dates;
                    } else {
                        $available_backups = array();
                    }

                    ?>
                        <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                            <thead>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'Cloudways Backup Type', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $available_backups as $backup ) { ?>
                                <tr>
                                    <td><i class="ui notched circle loading icon" style="display:none;"></i> <?php esc_html_e( 'Application Backup', 'mainwp' ); ?></td>
                                    <td><?php echo esc_html( gmdate( 'm-d-Y ( h:i:s )', strtotime( $backup ) ) ); ?></td>
                                    <td></td>
                                    <td>
                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup ui mini icon button" website_id="<?php echo intval( $website['id'] ); ?>" backup_date="<?php echo esc_attr( $backup ); ?>" href="javascript:void(0)"><i class="undo icon"></i></a>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'Cloudways Backup Type', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </tfoot>
                        </table>
                    <?php } ?>
                <?php // Display Vultr Table. ?>
                <?php
                if ( 'vultr' === $backup_api ) {
                    if ( isset( $available_backups->snapshots ) ) {
                        $available_snapshots_list = $available_backups->snapshots;
                    } else {
                        $available_snapshots_list = array();
                    }
                    if ( isset( $available_backups->backups ) ) {
                        $available_backups_list = $available_backups->backups;
                    } else {
                        $available_backups_list = array();
                    }
                    ?>
                        <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                            <thead>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'Vultr Backup Label', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Size', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Compressed Size', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            foreach ( $available_snapshots_list as $backup ) {
                                if ( empty( $backup ) || ! is_object( $backup ) || empty( $backup->date_created ) ) {
                                    continue;
                                }
                                ?>
                                <tr>
                                    <td><i class="ui notched circle loading icon" style="display:none;"></i> <?php echo esc_html( $backup->description ); ?></td>
                                    <td><?php echo esc_html( Api_Backups_Utility::human_filesize( $backup->size ) ); ?></td>
                                    <td><?php echo esc_html( Api_Backups_Utility::human_filesize( $backup->compressed_size ) ); ?></td>
                                    <td><?php echo esc_html( Api_Backups_Utility::format_timestamp( strtotime( $backup->date_created ) ) ); ?></td>
                                    <td><?php echo esc_html( $backup->status ); ?></td>
                                    <td></td>
                                    <td class="collapsing right aligned">
                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup ui mini icon button" website_id="<?php echo intval( $website['id'] ); ?>" snapshot_id="<?php echo esc_attr( $backup->id ); ?>" href="javascript:void(0)"><i class="undo icon"></i></a>
                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_delete_backup ui mini icon button" website_id="<?php echo intval( $website['id'] ); ?>" snapshot_id="<?php echo esc_attr( $backup->id ); ?>" href="javascript:void(0)"><i class="trash icon"></i></a>
                                    </td>
                                </tr>
                            <?php } ?>
                            <?php
                            foreach ( $available_backups_list as $backup ) {
                                if ( empty( $backup ) || ! is_object( $backup ) || empty( $backup->date_created ) ) {
                                    continue;
                                }
                                ?>
                                <tr>
                                    <td><i class="ui notched circle loading icon" style="display:none;"></i> <?php echo esc_html( $backup->description ); ?></td>
                                    <td><?php echo esc_html( Api_Backups_Utility::human_filesize( $backup->size ) ); ?></td>
                                    <td><?php esc_html_e( 'N/A', 'mainwp' ); ?></td>
                                    <td><?php echo esc_html( Api_Backups_Utility::format_timestamp( strtotime( $backup->date_created ) ) ); ?></td>
                                    <td><?php echo esc_html( $backup->status ); ?></td>
                                    <td></td>
                                    <td class="collapsing right aligned">
                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup ui mini icon button"
                                            website_id="<?php echo intval( $website['id'] ); ?>"
                                            snapshot_id="<?php echo esc_attr( $backup->id ); ?>"
                                            href="javascript:void(0)">
                                            <i class="undo icon"></i>
                                        </a>
                                        <a class="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_delete_backup ui mini icon button"
                                            website_id="<?php echo intval( $website['id'] ); ?>"
                                            snapshot_id="<?php echo esc_attr( $backup->id ); ?>"
                                            href="javascript:void(0)">
                                            <i class="trash icon"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'Vultr Backup Label', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Size', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Compressed Size', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </tfoot>
                        </table>
                    <?php } ?>
                <?php // Display Linode Table. ?>
                <?php
                if ( 'linode' === $backup_api ) {

                        // Automatic Backups.
                    if ( isset( $available_backups->automatic ) ) {
                        $available_backups_automatic_list = $available_backups->automatic;
                    } else {
                        $available_backups_automatic_list = array();
                    }

                        // Manual Backups.
                    if ( isset( $available_backups->snapshot ) ) {
                        $available_backups_snapshot_list = $available_backups->snapshot;
                    } else {
                        $available_backups_snapshot_list = array();
                    }
                    ?>
                        <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                            <thead>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'Akamai (Linode) Backup Type', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $available_backups_automatic_list as $backup ) { ?>
                                <tr>
                                    <td><i class="ui notched circle loading icon" style="display:none;"></i> <?php esc_html_e( $backup->type ); ?></td>
                                    <td><?php echo esc_html( Api_Backups_Utility::format_timestamp( strtotime( $backup->created ) ) ); ?></td>
                                    <td class="operation_status"><?php echo esc_html( $backup->status ); ?></td>
                                    <td></td>
                                    <td></td>
                                    <td>
                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup ui mini icon button"
                                            website_id="<?php echo intval( $website['id'] ); ?>"
                                            backup_type="<?php echo esc_attr( $backup->type ); ?>"
                                            backup_id="<?php echo esc_attr( $backup->id ); ?>"
                                            href="javascript:void(0)">
                                            <i class="undo icon"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                            <?php foreach ( $available_backups_snapshot_list as $backup ) { ?>
                                <?php if ( null !== $backup ) { ?>
                                    <tr>
                                        <td><i class="ui notched circle loading icon" style="display:none;"></i> <?php esc_html_e( $backup->type ); ?></td>
                                        <td><?php echo esc_html( Api_Backups_Utility::format_timestamp( strtotime( $backup->created ) ) ); ?></td>
                                        <td><?php echo esc_html( $backup->status ); ?></td>
                                        <td></td>
                                        <td></td>
                                        <td>

                                        <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup ui mini icon button"
                                            website_id="<?php echo intval( $website['id'] ); ?>"
                                            backup_type="<?php echo esc_attr( $backup->type ); ?>"
                                            backup_id="<?php echo esc_attr( $backup->id ); ?>"
                                            href="javascript:void(0)">
                                            <i class="undo icon"></i>
                                        </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                            </tbody>
                            <tfoot>
                            <tr>
                                <th scope="col" ><?php esc_html_e( 'Akamai (Linode) Backup Type', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                                <th scope="col" ><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                                <th scope="col" class="no-sort collapsing"></th>
                            </tr>
                            </tfoot>
                        </table>
                    <?php } ?>
                <?php // Display DigitalOcean Table. ?>
                <?php
                if ( 'digitalocean' === $backup_api ) {
                    if ( isset( $available_backups->snapshots ) ) {
                        $available_backups = $available_backups->snapshots;
                    }
                    if ( ! is_array( $available_backups ) ) {
                        $available_backups = array();
                    }
                    ?>
                    <table id="mainwp-siteid-<?php echo intval( $website['id'] ); ?>-table" class="ui mainwp-api-backup-table table" style="width:100%">
                        <thead>
                        <tr>
                            <th scope="col" ><?php esc_html_e( 'DigitalOcean Backup Label', 'mainwp' ); ?></th>
                            <th scope="col" ><?php esc_html_e( 'Size', 'mainwp' ); ?></th>
                            <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                            <th scope="col" class="no-sort collapsing"></th>
                            <th scope="col" class="no-sort collapsing"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $available_backups as $backup ) { ?>
                            <tr>
                                <td><i class="ui notched circle loading icon" style="display:none;"></i> <?php esc_html_e( $backup->name ); ?></td>
                                <td><?php echo intval( $backup->size_gigabytes ); ?>GB</td>
                                <td><?php echo esc_html( Api_Backups_Utility::format_timestamp( strtotime( $backup->created_at ) ) ); ?></td>
                                <td></td>
                                <td class="collapsing right aligned">
                                    <a class="mainwp_3rd_party_api_<?php echo esc_attr( $backup_api ); ?>_action_restore_backup ui mini icon button"
                                        website_id="<?php echo intval( $website['id'] ); ?>"
                                        snapshot_id="<?php echo esc_attr( $backup->id ); ?>"
                                        href="javascript:void(0)">
                                        <i class="undo icon"></i>
                                    </a>
                                    <a class="mainwp_3rd_party_api_<?php esc_attr_e( $backup_api ); ?>_action_delete_backup ui mini icon button"
                                        website_id="<?php echo intval( $website['id'] ); ?>"
                                        snapshot_id="<?php echo esc_attr( $backup->id ); ?>"
                                        href="javascript:void(0)">
                                        <i class="trash icon"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <th scope="col" ><?php esc_html_e( 'DigitalOcean Backup Label', 'mainwp' ); ?></th>
                            <th scope="col" ><?php esc_html_e( 'Size', 'mainwp' ); ?></th>
                            <th scope="col" ><?php esc_html_e( 'Date Created', 'mainwp' ); ?></th>
                            <th scope="col" class="no-sort collapsing"></th>
                            <th scope="col" class="no-sort collapsing"></th>
                        </tr>
                        </tfoot>
                    </table>
                <?php } ?>

            </div>
                <script type="text/javascript">
                    let responsive = true;
                    if ( jQuery( window ).width() > 1140 ) {
                        responsive = false;
                    }
                    jQuery( document ).ready( function() {

                        jQuery( '.mainwp-api-backup-table' ).DataTable( {
                            "stateSave": true,
                            "stateDuration": 0,
                            "colReorder" : {columns:":not(.check-column):not(:last-child)"},
                            "responsive": responsive,
                            "scrollX": false,
                            "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
                            "columnDefs": [ { "targets": 'no-sort', "orderable": false } ],
                            "order": [ [ 1, "asc" ] ],
                            "language": { "emptyTable": "No backups found." },
                            "drawCallback": function( settings ) {
                                setTimeout(() => {
                                    jQuery('.mainwp-api-backup-table .ui.checkbox').checkbox();
                                    jQuery('.mainwp-api-backup-table .ui.dropdown').dropdown();
                                    mainwp_datatable_fix_menu_overflow('.mainwp-api-backup-table', -55, 10 );
                                }, 1000);
                            }
                        } ).on( 'columns-reordered', function () {
                            console.log('columns-reordered');
                            setTimeout(() => {
                                jQuery( '.mainwp-api-backup-table .ui.dropdown' ).dropdown();
                                jQuery( '.mainwp-api-backup-table .ui.checkbox' ).checkbox();
                                mainwp_datatable_fix_menu_overflow('.mainwp-api-backup-table', -55, 10 );
                                mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
                            }, 1000);
                        });
                    } );
                </script>
            <?php
            } // END if.
    }

    /*********************************************************************************
     * Cloudways API Methods.
     **********************************************************************************/

    /**
     * Call Cloudways API, Authenticate & perform given method.
     *
     * Use this function to contact CW API
     * We use OAuth, an open standard for authorization. Here are the steps involved:
     * 1. Get your API Key from here: https://platform.cloudways.com/api
     * 2. Enter in your account email address and API Key below.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url relative URL for the call.
     * @param string $accessToken Access token generated using OAuth Call.
     * @param array  $post Optional post data for the call.
     * @return object Output from CW API.
     */
    public static function call_cloudways_api( $method, $url, $accessToken, $post = array() ) {
        $baseURL = 'https://api.cloudways.com/api/v1';

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
        curl_setopt( $ch, CURLOPT_URL, $baseURL . $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

        // Set Authorization Header.
        if ( $accessToken ) {
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Authorization: Bearer ' . $accessToken ) );
        }

        // Set Post Parameters.
        $encoded = '';
        if ( count( $post ) ) {
            foreach ( $post as $name => $value ) {
                $encoded .= rawurlencode( $name ) . '=' . rawurlencode( $value ) . '&';
            }
            $encoded = substr( $encoded, 0, strlen( $encoded ) - 1 );

            curl_setopt( $ch, CURLOPT_POSTFIELDS, $encoded );
            curl_setopt( $ch, CURLOPT_POST, 1 );
        }

        $output = curl_exec( $ch );

        $httpcode = (string) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

        if ( '200' !== $httpcode ) {
            return false;
        }
        curl_close( $ch );
        return json_decode( $output );
    }

    /**
     * Fetch Cloudways oAuth Access Token.
     *
     * Fetch the Cloudways oAuth access token from the given API credentials.
     *
     * @return mixed Return Cloudways Access Token.
     */
    public static function fetch_cloudways_access_token() {
        $account_email = get_option( 'mainwp_cloudways_api_account_email' );
        $api_key       = static::get_cloudways_api_key();

        // Fetch Access Token.
        $tokenResponse = static::call_cloudways_api(
            'POST',
            '/oauth/access_token',
            null,
            array(
                'email'   => $account_email,
                'api_key' => $api_key,
            )
        );
        if ( empty( $tokenResponse ) ) {
            return '';
        }
        return $tokenResponse->access_token;
    }

    /**
     * Fetch all available servers.
     *
     * @return mixed Returns available list of servers.
     */
    public static function fetch_cloudways_server_list() { //phpcs:ignore -- NOSONAR - complex.
        // Grab access token.
        $accessToken = static::fetch_cloudways_access_token();

        // Fetch Server List.
        $serverList = (array) static::call_cloudways_api( 'GET', '/server', $accessToken );
        if ( ! array_key_exists( 'servers', $serverList ) ) {
            return;
        }
        // Return the server list.
        return $serverList['servers'];
    }


    /**
     * Ajax.
     * Save the server & app ID to that Child Site's options table.
     *
     * Compare connected Child Sites against connected Cloudways account.
     * Automatically check for Child Sites domain name in server list & save.
     * that app's Server ID & App ID to that Child Site's options table.
     */
    public function ajax_cloudways_action_update_ids() {
        Api_Backups_Helper::security_nonce( 'cloudways_action_update_ids' );
        static::cloudways_action_update_ids();
    }

    /**
     * Save the server & app ID to that Child Site's options table.
     *
     * Compare connected Child Sites against connected Cloudways account.
     * Automatically check for Child Sites domain name in server list & save.
     * that app's Server ID & App ID to that Child Site's options table.
     */
    public static function cloudways_action_update_ids() {
        // Fetch Child Site domains.
        $child_sites = array();
        $websites    = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_wp_for_current_user() );

        foreach ( $websites as $website ) {
            $child_sites[ $website['id'] ] = preg_replace( '#^[^:/.]*[:/]+#i', '', preg_replace( '{/$}', '', urldecode( $website['url'] ) ) );
        }

        // Fetch Cloudways Server List.
        $server_list = static::fetch_cloudways_server_list();

        /**
         * Search $server_list for Child Site app ID & server ID.
         *
         * Loop through each Server/App & check if app cname / fqdn name matches any Child Site domain name.
         * if so, save the server & app ID to that Child Site's options table.
         */
        if ( ! is_array( $server_list ) ) {
            return;
        }
        foreach ( $server_list as $server ) {
            foreach ( $server->apps as $app ) {
                if ( in_array( $app->cname, $child_sites ) ) {
                    $found_child_site_id = array_search( $app->cname, $child_sites );
                    // Update Child Site options table.
                    Api_Backups_Helper::update_website_option( $found_child_site_id, 'mainwp_3rd_party_app_id', $app->id );
                    Api_Backups_Helper::update_website_option( $found_child_site_id, 'mainwp_3rd_party_instance_id', $app->server_id );
                    Api_Backups_Helper::update_website_option( $found_child_site_id, 'mainwp_3rd_party_api', 'Cloudways' );
                } elseif ( in_array( $app->app_fqdn, $child_sites ) ) { // Check if app fqdn name matches any Child Site domain name, if so, save the server & app ID to that Child Site's options table.
                    $found_child_site_id = array_search( $app->app_fqdn, $child_sites );
                    // Update Child Site options table.
                    Api_Backups_Helper::update_website_option( $found_child_site_id, 'mainwp_3rd_party_app_id', $app->id );
                    Api_Backups_Helper::update_website_option( $found_child_site_id, 'mainwp_3rd_party_instance_id', $app->server_id );
                    Api_Backups_Helper::update_website_option( $found_child_site_id, 'mainwp_3rd_party_api', 'Cloudways' );
                }
            }
        }
    }


    /**
     * Cloudways: action backup.
     *
     * Perform a backup on the selected Cloudways server.
     *
     * @return void
     */
    public function ajax_cloudways_action_individual_backup() {
        Api_Backups_Helper::security_nonce( 'cloudways_action_individual_backup' );
        static::cloudways_action_backup();
    }

    /**
     * Cloudways: action backup.
     *
     * Perform a backup on the selected Cloudways server.
     *
     * @return void
     */
    public function ajax_cloudways_action_backup() {
        Api_Backups_Helper::security_nonce( 'cloudways_action_backup' );
        static::cloudways_action_create_backup();
    }

    /**
     * Cloudways: action backup.
     *
     * Perform a backup on the selected Cloudways server.
     *
     * @param int  $website_id Website ID.
     * @param bool $ret_val Return output or not.
     *
     * @return mixed
     */
    public static function cloudways_action_create_backup( $website_id = '', $ret_val = false ) {

        $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : $website_id; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

        // Grab this from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id', 'mainwp_3rd_party_app_id' ) );
        $server_id    = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;
        $app_id       = isset( $site_options['mainwp_3rd_party_app_id'] ) ? $site_options['mainwp_3rd_party_app_id'] : null;

        $data = array(
            'server_id' => $server_id,
            'app_id'    => $app_id,
        );

        // Grab Cloudways access token.
        $accessToken = static::fetch_cloudways_access_token();

        // Send Payload & create backup.
        $api_response = (array) static::call_cloudways_api( 'POST', '/app/manage/takeBackup', $accessToken, $data );

        $success = true;

        if ( true !== $api_response['status'] ) {
            $success = false;
        } else {
            // Save current backup operation.
            $backup_operation = (array) static::call_cloudways_api( 'GET', static::OPERATION . $api_response['operation_id'], $accessToken );
            $backup_operation = wp_json_encode( $backup_operation['operation'] );
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cloudways_backup_operation', $backup_operation );

            // Save Timestamp.
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cloudways_last_backup', $current_time );
            // Poll operations endpoint for backup status.
            // static::cloudways_action_poll_backup_operation( $website_id  );.
        }

        if ( $ret_val ) {
            return $api_response;
        }

        if ( ! $success ) {
            wp_die( 'false' );
        } else {
            // Return success.
            wp_send_json( 'true' );
            // Poll operations endpoint for backup status.
            // static::cloudways_action_poll_backup_operation( $website_id  );.
        }
    }

    /**
     * Cloudways: action poll backup operation.
     *
     * Poll the Cloudways operations endpoint for backup status.
     *
     * @param int $website_id Website ID.
     *
     * @return void
     */
    public static function cloudways_action_poll_backup_operation( $website_id ) {

        global $wp;

        // Grab needed site options from WP_MAINWP_WP_OPTIONS table.
        $site_options = Api_Backups_Helper::get_website_options(
            $website_id,
            array(
                'mainwp_3rd_party_cloudways_backup_operation',
            )
        );

        // Current backup operation.
        $backup_operation = isset( $site_options['mainwp_3rd_party_cloudways_backup_operation'] ) ? $site_options['mainwp_3rd_party_cloudways_backup_operation'] : null;
        $operation_id     = json_decode( $backup_operation, true )['id'];

        // Grab Cloudways access token.
        $accessToken = static::fetch_cloudways_access_token();

        // Send Payload & list backups. https://api.cloudways.com/api/v1/operation/{id}.
        $operation_polling       = static::call_cloudways_api( 'GET', static::OPERATION . $operation_id, $accessToken );
        $backup_polling_response = $operation_polling->operation;

        // If backup has not been completed yet, sleep for 30 seconds and check again.
        // If backup has been completed, save the backup operation results.
        if ( true !== $backup_polling_response->is_completed ) {
            sleep( 30 );
            static::cloudways_action_poll_backup_operation( $website_id );
        } else {
            // Save current backup operation.
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cloudways_backup_operation', wp_json_encode( $backup_polling_response ) );

            // Refresh the page.
            wp_safe_redirect( get_permalink( home_url( $wp->request ) ) );
        }
        // Return success.
        wp_send_json( 'true' );
    }

    /**
     * Cloudways: action refresh available backups.
     *
     * Save backups to DB for the selected Cloudways server.
     *
     * @return void
     */
    public static function cloudways_action_refresh_available_backups() {

        Api_Backups_Helper::security_nonce( 'cloudways_action_refresh_available_backups' );

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0;  //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        } else {
            // Grab $_GET['data'] from url if not Ajax call.
            $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        }

        // Grab Cloudways access token.
        $accessToken = static::fetch_cloudways_access_token();

        // Grab needed site options from WP_MAINWP_WP_OPTIONS table.
        $site_options = Api_Backups_Helper::get_website_options(
            $website_id,
            array(
                'mainwp_3rd_party_instance_id',
                'mainwp_3rd_party_app_id',
            )
        );
        $server_id    = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;
        $app_id       = isset( $site_options['mainwp_3rd_party_app_id'] ) ? $site_options['mainwp_3rd_party_app_id'] : null;

        // Grab Backup operation from API.
        $backup_operation = static::call_cloudways_api( 'GET', '/app/manage/backup?server_id=' . $server_id . '&app_id=' . $app_id, $accessToken );
        $operation_id     = $backup_operation->operation_id;

        sleep( 3 ); // REQUIRED! Wait 3 seconds for cURL connection to be closed before opening a new one.

        // Send Payload & list backups. https://api.cloudways.com/api/v1/operation/{id}.
        $operation_polling = static::call_cloudways_api( 'GET', static::OPERATION . $operation_id, $accessToken );
        $operation         = $operation_polling->operation;

        // Save backup operation.
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cloudways_available_backups', $operation->parameters );

        Api_Backups_Utility::save_lasttime_backup( $website_id, $operation->parameters, 'cloudways' );

        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            if ( 1 !== (int) $operation->is_completed ) {
                wp_die( 'false' );
            } else {
                // Return success.
                wp_send_json( 'true' );
            }
        }
    }

    /**
     * Cloudways: action restore backup.
     *
     * Restore a backup on the selected Cloudways server.
     *
     * @return void
     */
    public function cloudways_action_restore_backup() {

        Api_Backups_Helper::security_nonce( 'cloudways_action_restore_backup' );

        // Grab website_id & backup_date name from Ajax post.
        $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0;  //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $backup_date = isset( $_POST['backup_date'] ) ? wp_unslash( $_POST['backup_date'] ) : '';  //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Grab needed site options from WP_MAINWP_WP_OPTIONS table.
        $site_options = Api_Backups_Helper::get_website_options(
            $website_id,
            array(
                'mainwp_3rd_party_instance_id',
                'mainwp_3rd_party_app_id',
            )
        );
        $server_id    = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;
        $app_id       = isset( $site_options['mainwp_3rd_party_app_id'] ) ? $site_options['mainwp_3rd_party_app_id'] : null;

        // Payload to send to Cloudways API.
        $data = array(
            'server_id' => $server_id,
            'app_id'    => $app_id,
            'time'      => $backup_date,
            'type'      => 'complete',
        );

        // Grab Cloudways access token.
        $accessToken = static::fetch_cloudways_access_token();

        // Send Payload & create backup.
        $api_response = static::call_cloudways_api( 'POST', '/app/manage/restore', $accessToken, $data );

        // Handle API response.
        if ( 1 !== (int) $api_response->status ) {
            wp_die( 'false' );
        } else {
            wp_send_json( 'true' );
        }
    }

    /**
     * Cloudways: action delete backup.
     *
     * Delete a backup on the selected Cloudways server.
     *
     * @return void
     */
    public function cloudways_action_delete_backup() {

        Api_Backups_Helper::security_nonce( 'cloudways_action_delete_backup' );

        // Grab website_id from Ajax post.
        $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

        // Grab this from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id', 'mainwp_3rd_party_app_id' ) );
        $server_id    = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;
        $app_id       = isset( $site_options['mainwp_3rd_party_app_id'] ) ? $site_options['mainwp_3rd_party_app_id'] : null;

        $data = array(
            'server_id' => $server_id,
            'app_id'    => $app_id,
        );

        // Grab Cloudways access token.
        $accessToken = static::fetch_cloudways_access_token();

        // Send Payload & create backup.
        $api_response = static::call_cloudways_api( 'DELETE', '/app/manage/backup', $accessToken, $data );

        if ( 1 !== (int) $api_response->status ) {
            wp_die( 'false' );
        } else {
            wp_send_json( 'true' );
        }
    }

    /*********************************************************************************
     * Vultr API Methods.
     **********************************************************************************/

    /**
     * Call Vultr API, Authenticate & perform given method.
     *
     * Use this function to contact Vultr API
     * We use OAuth, an open standard for authorization. Here are the steps involved:
     * 1. Get your API Key from here: https://platform.cloudways.com/api
     * 2. Enter in your account email address and API Key below.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url relative URL for the call.
     * @param string $accessToken Access token generated using OAuth Call.
     * @param string $data Json encoded array - Optional post data for the call.
     * @param bool   $die_error die when error.
     *
     * @return array Response from Vultr API.
     */
    public static function call_vultr_api( $method, $url, $accessToken, $data = null, $die_error = true ) {

        $baseurl = 'https://api.vultr.com/v2';

        $curl = curl_init( $baseurl . $url );
        curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, $method );
        curl_setopt( $curl, CURLOPT_URL, $baseurl . $url );
        curl_setopt( $curl, CURLOPT_POST, true );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );

        $headers = array(
            "Authorization: Bearer $accessToken",
            'Content-Type: application/json',
        );
        curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $curl, CURLOPT_POSTFIELDS, $data );

        $ssl_verifyhost = ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) );

        if ( $ssl_verifyhost ) {
            curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 2 );
            curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
        } else {
            // for debug only!
            curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, false ); // NOSONAR.
            curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false ); // NOSONAR.
        }

        $resp = curl_exec( $curl );

        $response = array();
        // Basic Error reporting. ( should wrap in try catch MainWP Exceptions call during code refactoring ).
        $httpCode = (string) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
        if ( ( '201' !== $httpCode ) && ( '200' !== $httpCode ) && ( '202' !== $httpCode ) && ( '204' !== $httpCode ) ) {
            $decode_resp = json_decode( $resp );
            $error       = $decode_resp->error;
            if ( $die_error ) { // die when error.
                if ( isset( $_POST['bulk_backups'] ) && ! empty( $_POST['bulk_backups'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
                    wp_send_json( array( 'error' => 'Code: ' . esc_html( $httpCode ) . ' - ' . esc_html( $error ) ) ); // to fix for backup selected sites (bulk).
                } else {
                    die( 'Code: ' . esc_html( $httpCode ) . ' - ' . esc_html( $error ) );
                }
            } else {
                $response['response'] = $decode_resp;
                $response['status']   = false;
            }
        } else {
            $response['response'] = json_decode( $resp );
            $response['status']   = true;

        }
        curl_close( $curl );
        // Return Response.
        return $response;
    }



    /**
     * Ajax.
     * Vultr: action create snapshot.
     *
     * Create a snapshot on the selected Vultr server.
     *
     * This function is also called before any update. During this time a backup will be.
     * Created but no updates will be made this time around. A new update will need to be manually triggered.
     *
     * @return void
     */
    public function ajax_vultr_action_individual_create_snapshot() {
        Api_Backups_Helper::security_nonce( 'vultr_action_individual_create_snapshot' );
        static::vultr_action_create_snapshot();
    }

    /**
     * Ajax.
     * Vultr: action create snapshot.
     *
     * Create a snapshot on the selected Vultr server.
     *
     * This function is also called before any update. During this time a backup will be.
     * Created but no updates will be made this time around. A new update will need to be manually triggered.
     *
     * @return void
     */
    public function ajax_vultr_action_create_snapshot() {
        Api_Backups_Helper::security_nonce( 'vultr_action_create_snapshot' );
        static::vultr_action_create_snapshot();
    }

    /**
     * Method get_vultr_api_key().
     */
    public static function get_vultr_api_key() {
        return Api_Backups_Utility::get_instance()->get_api_key( 'vultr' );
    }

    /**
     * Method get_gridpane_api_key().
     */
    public static function get_gridpane_api_key() {
        return Api_Backups_Utility::get_instance()->get_api_key( 'gridpane' );
    }

    /**
     * Method get_linode_api_key().
     */
    public static function get_linode_api_key() {
        return Api_Backups_Utility::get_instance()->get_api_key( 'linode' );
    }

    /**
     * Method get_digitalocean_api_key().
     */
    public static function get_digitalocean_api_key() {
        return Api_Backups_Utility::get_instance()->get_api_key( 'digitalocean' );
    }

    /**
     * Method get_cloudways_api_key().
     */
    public static function get_cloudways_api_key() {
        return Api_Backups_Utility::get_instance()->get_api_key( 'cloudways' );
    }

    /**
     * Method get_cpanel_account_password().
     */
    public static function get_cpanel_account_password() {
        return Api_Backups_Utility::get_instance()->get_api_key( 'cpanel' );
    }

    /**
     * Method get_plesk_api_key().
     */
    public static function get_plesk_api_key() {
        return Api_Backups_Utility::get_instance()->get_api_key( 'plesk' );
    }

    /**
     * Method get_kinsta_api_key().
     */
    public static function get_kinsta_api_key() {
        return Api_Backups_Utility::get_instance()->get_api_key( 'kinsta' );
    }

    /**
     * Vultr: action create snapshot.
     *
     * Create a snapshot on the selected Vultr server.
     *
     * This function is also called before any update. During this time a backup will be
     * created but no updates will be made this time around. A new update will need to be manually triggered.
     *
     * @param int    $website_id website id.
     * @param string $backup_api backup api.
     *
     * @return mixed result.
     */
    public static function vultr_action_create_snapshot( $website_id = '', $backup_api = '' ) {

        // Grab $_POST data if set else use args.
        $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : intval( $website_id );  //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $backup_api = isset( $_POST['backup_api'] ) ? wp_unslash( $_POST['backup_api'] ) : $backup_api;  //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Grab Site options.
        $website = Api_Backups_Helper::get_website_by_id( $website_id );

        // Grab this from websites ( Static atm ).
        $site_options  = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id', 'mainwp_3rd_party_vultr_snapshot_list' ) );
        $instance_id   = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;
        $snapshot_list = isset( $site_options['mainwp_3rd_party_vultr_snapshot_list'] ) ? $site_options['mainwp_3rd_party_vultr_snapshot_list'] : null;

        // Grab Vultr Access token.
        $accessToken = static::get_vultr_api_key();

        // Remove http://, www., and slash(/) from the URL.
        $url       = ! empty( $website['url'] ) ? rawurlencode( $website['url'] ) : '';
        $clean_url = preg_replace( '#^[^:/.]*[:/]+#i', '', preg_replace( '{/$}', '', urldecode( $url ) ) );

        // Build payload data.
        $snapshot_data = array(
            'instance_id' => $instance_id,
            'description' => 'MainWP API Backups -' . $clean_url,
        );
        $snapshot_data = wp_json_encode( $snapshot_data );

        // Send Payload & create backup.
        $snapshot_response = static::call_vultr_api( 'POST', '/snapshots', $accessToken, $snapshot_data );
        $snapshot_id       = $snapshot_response['response']->snapshot->id;

        $snapshot_list   = json_decode( $snapshot_list );
        $snapshot_list[] = $snapshot_id;
        $snapshot_list   = wp_json_encode( $snapshot_list );

        $success = true;
        // Store Last Backup timestamp.
        if ( empty( $snapshot_response ) ) {
            $success = false;
        } else {
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_' . $backup_api . '_last_backup', $current_time );
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_vultr_snapshot_list', $snapshot_list );
        }

        if ( $ret_val ) {
            return $snapshot_response;
        }

        if ( $success ) {
            // Return Response.
            if ( ! empty( $_POST['website_id'] ) ) {  //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
                wp_send_json( 'true' ); // Equivalent to die() - but for ajax.
            } else {
                return true;
            }
        } elseif ( ! empty( $_POST['website_id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            $error = new WP_Error( '400', __( 'There was an issue with creating your backup.', 'mainwp' ) );
            wp_send_json_error( $error );
        } else {
            return false;
        }
    }

    /**
     * Vultr: action refresh available backups.
     *
     * Save backups to DB for the selected Vultr server.
     *
     * @return void
     */
    public function vultr_action_refresh_available_backups() { //phpcs:ignore -- NOSONAR - complex.
        Api_Backups_Helper::security_nonce( 'vultr_action_refresh_available_backups' );

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        } else {
            // Grab $_GET data from url if not Ajax call.
            $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        }

        // Grab this from websites ( Static atm ).
        $site_options  = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id', 'mainwp_3rd_party_vultr_snapshot_list' ) );
        $instance_id   = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;
        $snapshot_list = isset( $site_options['mainwp_3rd_party_vultr_snapshot_list'] ) ? json_decode( $site_options['mainwp_3rd_party_vultr_snapshot_list'] ) : null;

        // Grab Vultr Access token.
        $accessToken = static::get_vultr_api_key();

        $success   = false;
        $die_error = false;

        // Build available backups array.
        $available_backups = array();
        foreach ( $snapshot_list as $snapshot ) {
            $api_response                   = static::call_vultr_api( 'GET', static::SNAPSHOTS . $snapshot, $accessToken, null, $die_error );
            $snapshot                       = $api_response['response'];
            $available_backups['snapshots'] = $snapshot;
            if ( is_array( $api_response ) && ! empty( $api_response['status'] ) ) {
                $success = true;
            }
        }
        $automatic_backups            = static::call_vultr_api( 'GET', '/backups?instance_id=' . $instance_id, $accessToken, null, $die_error );
        $automatic_backups            = $automatic_backups['response']->backups;
        $available_backups['backups'] = $automatic_backups;
        $available_backups            = wp_json_encode( $available_backups );

        if ( ! $success && ! empty( $automatic_backups['status'] ) ) {
            $success = true;
        }

        // Grab Site options then update Child Site options.
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_vultr_available_backups', $available_backups, $die_error );

        if ( $success ) {
            Api_Backups_Utility::save_lasttime_backup( $website_id, $available_backups, 'vultr' );
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Available backups.
            if ( $success ) {
                wp_send_json( 'true' );
            } else {
                wp_send_json_error( $api_response );
            }
        }
    }

    /**
     * Vultr: action restore backup.
     *
     * Restore backup on the selected Vultr server.
     *
     * @return void
     */
    public function ajax_vultr_action_restore_backup() {
        Api_Backups_Helper::security_nonce( 'vultr_action_restore_backup' );
        static::vultr_action_restore_backup();
    }

    /**
     * Vultr: action restore backup.
     *
     * Restore backup on the selected Vultr server.
     *
     * @return void
     */
    public function vultr_action_restore_backup() {
        // Grab $_POST data.
        $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $snapshot_id = isset( $_POST['snapshot_id'] ) ? wp_unslash( $_POST['snapshot_id'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Grab backup info from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $instance_id  = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Access token.
        $accessToken = static::get_vultr_api_key();
        // Set which Backup to restore to.
        $backup_data = array(
            'snapshot_id' => $snapshot_id,
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & list backups. backups/restore/1038'.
        $api_response = (object) static::call_vultr_api( 'POST', static::INSTANCES . $instance_id . '/restore', $accessToken, $backup_data );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            if ( 1 !== (int) $api_response->status ) {
                wp_send_json_error( $api_response );
            } else {
                wp_send_json( 'true' );
            }
        }
    }

    /**
     * Vultr: action delete backup.
     *
     * Delete a backup on the selected Vultr server.
     *
     * @return void
     */
    public function vultr_action_delete_backup() {

        Api_Backups_Helper::security_nonce( 'vultr_action_delete_backup' );

        // Grab $_GET['data'] from url if not Ajax call.
        $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $snapshot_id = isset( $_POST['snapshot_id'] ) ? wp_unslash( $_POST['snapshot_id'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // $snapshot_id acecab84-7404-4193-bc9f-88e29956362f;

        // Grab Vultr Access token.
        $accessToken = static::get_vultr_api_key();

        // Send Payload & delete backup.
        $api_response = (object) static::call_vultr_api( 'DELETE', static::SNAPSHOTS . $snapshot_id, $accessToken );

        // Remove backup from snapshot list.
        $site_options  = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_vultr_snapshot_list' ) );
        $snapshot_list = isset( $site_options['mainwp_3rd_party_vultr_snapshot_list'] ) ? json_decode( $site_options['mainwp_3rd_party_vultr_snapshot_list'] ) : null;

        $key = array_search( $snapshot_id, $snapshot_list );
        if ( false !== $key ) {
            unset( $snapshot_list[ $key ] );
        }
        $snapshot_list = wp_json_encode( $snapshot_list );

        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_vultr_snapshot_list', $snapshot_list );
        // die.
        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Return Ajax response.
            if ( 1 !== (int) $api_response->status ) {
                wp_send_json_error( 'false' );

            } else {
                wp_send_json( 'true' );
            }
        }
    }

    /*********************************************************************************
     * GridPane API Methods.
     **********************************************************************************/

    /**
     * Call GridPane API, Authenticate & perform given method.
     *
     * Use this function to contact GridPane API
     * We use OAuth, an open standard for authorization. Here are the steps involved:
     * 1. Get your API Key from here: https://platform.cloudways.com/api
     * 2. Enter in your account email address and API Key below.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url relative URL for the call.
     * @param string $accessToken Access token generated using OAuth Call.
     * @param mixed  $backup_data Json encoded array - Optional post data for the call.
     *
     * @return string Output from GridPane API.
     */
    public static function call_gridpane_api( $method, $url, $accessToken, $backup_data = array() ) {

        $baseurl = 'https://my.gridpane.com/oauth/api/v1';
        $curl    = curl_init();
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $baseurl . $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $backup_data,
                CURLOPT_HTTPHEADER     => array(
                    "Authorization: Bearer $accessToken",
                    'Content-Type: application/json',
                ),
            )
        );
        $response = curl_exec( $curl );
        curl_close( $curl );
        return $response;
    }

    /**
     * Get GridPane Site List.
     *
     * Request for all sites for current user token.
     *
     * @param int $sites_count sites count.
     * @return object|bool Return Sites Object.
     */
    public static function gridpane_get_sites_list( $sites_count ) {

        // Grab Vultr Access token.
        $accessToken = static::get_gridpane_api_key();

        // Grab Sites List.
        $grid_response = (array) static::call_gridpane_api( 'GET', '/site?per_page=' . $sites_count, $accessToken );
        Api_Backups_Utility::log_debug( 'GridPane API get sites :: [response=' . ( is_array( $grid_response ) ? print_r( $grid_response, true ) : 'INVALID' ). '] :: '  . $accessToken  ); // phpcs:ignore -- NOSONAR - for debugging.
        $grid_response_decoded = json_decode( $grid_response[0] );
        if ( is_object( $grid_response_decoded ) && property_exists( $grid_response_decoded, 'data' ) ) {
            return $grid_response_decoded->data;
        }
        return false;
    }

    /**
     * Get GridPane Site List.
     *
     * Request for all sites for current user token.
     *
     * @return object|bool Return Sites Object.
     */
    public static function gridpane_get_domain_list() {

        // Grab Vultr Access token.
        $accessToken = static::get_gridpane_api_key();

        // Grab Sites List.
        $grid_response         = (array) static::call_gridpane_api( 'GET', '/domain', $accessToken );
        $grid_response_decoded = json_decode( $grid_response[0] );
        if ( is_object( $grid_response_decoded ) && property_exists( $grid_response_decoded, 'data' ) ) {
            return $grid_response_decoded->data;
        }
        return false;
    }

    /**
     * Ajax.
     * Assign Server:Apps to Child Sites options table.
     *
     * Compare connected Child Sites against connected GridPane account.
     * Automatically check for Child Sites domain name in server list & save
     * that app's Server ID & App ID to that Child Site's options table.
     *
     * @return void True|False Return True on success & False on failure
     */
    public function ajax_gridpane_action_update_ids() {
        Api_Backups_Helper::security_nonce( 'gridpane_action_update_ids' );
        static::gridpane_action_update_ids();
    }

    /**
     * Assign Server:Apps to Child Sites options table.
     *
     * Compare connected Child Sites against connected GridPane account.
     * Automatically check for Child Sites domain name in server list & save
     * that app's Server ID & App ID to that Child Site's options table.
     *
     * @return void True|False Return True on success & False on failure
     */
    public static function gridpane_action_update_ids() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.

        // Get child_sites list from MainWP.
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_wp_for_current_user() );

        // Build an array of websites by URL.
        $websites_by_urls = array();
        if ( $websites ) {
            while ( $websites && ( $website = Api_Backups_Helper::fetch_object( $websites ) ) ) {
                $clean_url                      = Api_Backups_Helper::clean_url( $website->url );
                $websites_by_urls[ $clean_url ] = $website->id;
            }
            MainWP_DB::data_seek( $websites, 0 );
        }

        // Get GP Sites list.
        $sites_list = static::gridpane_get_sites_list( 99999 ); // 99999 to pull all sites. High number at least for now.

        $logs_updated_sites = array();

        // Loop through Child Sites.
        while ( $websites && ( $website = Api_Backups_Helper::fetch_object( $websites ) ) ) {

            // Grab non-stagingChild Site ID.
            $website_id = $website->id;

            // Remove http(s)://, www., and trailing slash(/) from the URL.
            $clean_url = Api_Backups_Helper::clean_url( $website->url );

            /**
             * Check if the URLs of Child Sites match with those of GridPane Sites.
             * If they do, add the GridPane Site ID and the provider name 'GridPane'
             * to the Child Site options table.
             */
            if ( is_array( $sites_list ) ) {
                foreach ( $sites_list as $site ) {

                    // If the URL of the Child Site matches the URL of the GridPane Site.
                    if ( $site->url === $clean_url ) {

                        // GridPane Site found here....

                        // Grab GridPane Site ID.
                        $gp_site_id = $site->id;

                        // Update Production Child Site options.
                        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_instance_id', $gp_site_id );
                        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_api', 'GridPane' );
                        $logs_updated_sites[ $clean_url ] = $gp_site_id;
                        // Check if the site is a staging site.
                        if ( ! empty( $site->staging_site_built_at ) && isset( $websites_by_urls[ 'staging.' . $site->url ] ) ) {
                            $staging_child_site_id = $websites_by_urls[ 'staging.' . $site->url ];

                            $logs_updated_sites[ 'staging.' . $site->url ] = $gp_site_id + 1;

                            // Update Staging Child Site options.
                            Api_Backups_Helper::update_website_option( $staging_child_site_id, 'mainwp_3rd_party_instance_id', $gp_site_id + 1 );
                            Api_Backups_Helper::update_website_option( $staging_child_site_id, 'mainwp_3rd_party_api', 'GridPane' );
                        }
                        break;
                    }
                }
            }
        }
        Api_Backups_Utility::log_debug( 'Updated GridPane API Sites :: [total=' . count( $logs_updated_sites ) . '] :: [' . print_r( $logs_updated_sites, true ) . ']' ); // phpcs:ignore -- NOSONAR - for debugging.

        $logs_sites_lists = array();
        if ( is_array( $sites_list ) ) {
            foreach ( $sites_list as $site ) {
                $logs_sites_lists[ $site->url ] = $site->id;
            }
        }
        Api_Backups_Utility::log_debug( 'GridPane Sites Lists:: [total=' . count( $logs_sites_lists ) . '] :: [' . print_r( $logs_sites_lists, true ) . ']' ); // phpcs:ignore -- NOSONAR - for debugging.

        Api_Backups_Helper::free_result( $websites );
    }

    /**
     * Ajax.
     * GridPane: action create backup.
     *
     * Create a backup on the selected GridPane server.
     *
     * @return void
     */
    public function ajax_gridpane_action_individual_create_backup() {
        Api_Backups_Helper::security_nonce( 'gridpane_action_individual_create_backup' );
        static::gridpane_action_create_backup();
    }

    /**
     * Ajax.
     * GridPane: action create backup.
     *
     * Create a backup on the selected GridPane server.
     *
     * @return void
     */
    public function ajax_gridpane_action_create_backup() {
        Api_Backups_Helper::security_nonce( 'gridpane_action_create_backup' );
        static::gridpane_action_create_backup();
    }

    /**
     * GridPane: action create backup.
     *
     * @param int  $website_id Website ID.
     * @param bool $ret_val Return output or not.
     *
     * @return mixed
     */
    public static function gridpane_action_create_backup( $website_id = '', $ret_val = false ) {

        // Grab $_POST data.
        $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : intval( $website_id ); //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

        // Grab this from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $site_id      = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Gridpane Access token.
        $accessToken = static::get_gridpane_api_key();

        // Set Backup `type` & `tag`.
        $backup_data = array(
            'type' => 'local',
            'tag'  => 'MainWP-API-Backups',
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & create backup.
        $backup_response = static::call_gridpane_api( 'POST', '/backups/' . $site_id, $accessToken, $backup_data );
        $backup_status   = json_decode( $backup_response );

        $success = false;
        $error   = false;
        // Store Last Backup timestamp.
        if ( $backup_status && ! empty( $backup_status->success ) ) {
            $success      = true;
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_gridpane_last_backup', $current_time );
        } else {
            $error = new WP_Error( $backup_status->error->code, __( $backup_status->error->message, 'mainwp' ) );
        }

        if ( $ret_val ) {
            return $backup_status;
        }

        if ( $success ) {
            wp_send_json( 'true' );
        } else {
            if ( empty( $error ) ) {
                $error = esc_html__( 'Undefined error. Please try again.', 'mainwp' );
            }
            wp_send_json_error( $error );
        }
    }

    /**
     * GridPane: action refresh available backups.
     *
     * Save backups to DB for the selected GridPane server.
     *
     * @return void
     */
    public function gridpane_action_refresh_available_backups() {

        Api_Backups_Helper::security_nonce( 'gridpane_action_refresh_available_backups' );

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        } else {
            // Grab $_GET['data'] from url if not Ajax call.
            $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        }

        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $site_id      = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Gridpane Access token.
        $accessToken = static::get_gridpane_api_key();

        // Refresh backups.
        static::call_gridpane_api( 'GET', '/backups/refresh/' . $site_id, $accessToken );

        // Send Payload & grab backup list.
        $api_response = json_decode( static::call_gridpane_api( 'GET', '/backups/original/' . $site_id, $accessToken ) );

        $available_backups = '';
        if ( $api_response && ! empty( $api_response->backups ) && ! empty( $api_response->backups->local ) ) {
            $available_backups = wp_json_encode( $api_response->backups->local );
        }

        // Update Child Site option with available backup list.
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_gridpane_available_backups', $available_backups );

        Api_Backups_Utility::save_lasttime_backup( $website_id, $available_backups, 'gridpane' );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            if ( true !== $api_response->success ) {
                wp_send_json_error( 'No Backups Found' );

            } else {
                wp_send_json( 'true' );
            }
        }
    }

    /**
     * GridPane: action restore backup.
     *
     * Restore selected backup for the selected GridPane server.
     *
     * @return void
     */
    public function gridpane_action_restore_backup() {

        Api_Backups_Helper::security_nonce( 'gridpane_action_restore_backup' );

        // Grab $_POST data.
        $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $backup_type = isset( $_POST['backup_type'] ) ? wp_unslash( $_POST['backup_type'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $backup_name = isset( $_POST['backup_name'] ) ? wp_unslash( $_POST['backup_name'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Grab backup info from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $site_id      = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Gridpane Access token.
        $accessToken = static::get_gridpane_api_key();

        // Set which Backup to restore to.
        $backup_data = array(
            'backup_source' => 'local',
            'source_type'   => 'original',
            'backup_type'   => $backup_type,
            'backup'        => $backup_name,
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & list backups. backups/restore/1038'.
        $api_response = json_decode( static::call_gridpane_api( 'POST', '/backups/restore/' . $site_id, $accessToken, $backup_data ) );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            if ( '1' !== (string) $api_response->success ) {
                wp_send_json_error( 'false' );

            }
            wp_send_json( 'true' );
        }
    }

    /**
     * GridPane: action delete backup.
     *
     * Delete backup for the selected GridPane server.
     *
     * @return void
     */
    public function gridpane_action_delete_backup() {

        Api_Backups_Helper::security_nonce( 'gridpane_action_delete_backup' );

        // Grab $_POST data.
        $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $backup_type = isset( $_POST['backup_type'] ) ? wp_unslash( $_POST['backup_type'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $backup_name = isset( $_POST['backup_name'] ) ? wp_unslash( $_POST['backup_name'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Grab backup info from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $site_id      = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Gridpane Access token.
        $accessToken = static::get_gridpane_api_key();

        // Set which Backup to restore to.
        $backup_data = array(
            'backup_source' => 'local',
            'backup_type'   => $backup_type,
            'backup'        => $backup_name,
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & delete backup.
        $api_response = json_decode( static::call_gridpane_api( 'PUT', '/backups/purge/' . $site_id, $accessToken, $backup_data ) );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            if ( '1' !== (string) $api_response->success ) {
                wp_send_json_error( 'false' );
            }
            wp_send_json( 'true' );
        }
    }


    /*********************************************************************************
     * Linode API Methods.
     **********************************************************************************/

    /**
     * Call Linode API, Authenticate & perform given method.
     *
     * Use this function to contact Linode API
     * We use OAuth, an open standard for authorization. Here are the steps involved:
     * 1. Get your API Key from here: https://platform.cloudways.com/api
     * 2. Enter in your account email address and API Key below.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url relative URL for the call.
     * @param string $accessToken Access token generated using OAuth Call.
     * @param mixed  $backup_data Json encoded array - Optional post data for the call.
     * @return string Output from GridPane API.
     */
    public static function call_linode_api( $method, $url, $accessToken, $backup_data = array() ) {

        $baseurl = 'https://api.linode.com/v4/linode';
        $curl    = curl_init();
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $baseurl . $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $backup_data,
                CURLOPT_HTTPHEADER     => array(
                    "Authorization: Bearer $accessToken",
                    'Content-Type: application/json',
                ),
            )
        );

        $response = curl_exec( $curl );

        curl_close( $curl );
        return $response;
    }

    /**
     * Linode: action create backup.
     *
     * Create backup for the selected Linode server.
     *
     * @return void
     */
    public function ajax_linode_action_individual_create_backup() {
        Api_Backups_Helper::security_nonce( 'linode_action_individual_create_backup' );
        static::linode_action_create_backup();
        die();
    }

    /**
     * Linode: action create backup.
     *
     * Create backup for the selected Linode server.
     *
     * @return void
     */
    public function ajax_linode_action_create_backup() {
        Api_Backups_Helper::security_nonce( 'linode_action_create_backup' );
        static::linode_action_create_backup();
        die();
    }

    /**
     * Linode: action create backup.
     *
     * Create backup for the selected Linode server.
     *
     * @param int  $website_id Website ID.
     * @param bool $ret_val Return output or not.
     *
     * @return mixed
     */
    public static function linode_action_create_backup( $website_id = '', $ret_val = false ) {

        $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : $website_id; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $instance_id  = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Linode Access token.
        $accessToken = static::get_linode_api_key();

        // Send Payload & create backup.
        $linode_response = (array) static::call_linode_api( 'POST', static::INSTANCES . $instance_id . '/backups', $accessToken );
        $linode_response = json_decode( $linode_response[0] );

        // Handle response.
        if ( ! isset( $linode_response->errors ) ) {
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_linode_last_backup', $current_time );
        }

        if ( $ret_val ) {
            return $linode_response;
        }

        // Handle response.
        if ( isset( $linode_response->errors ) ) {
            $error = new WP_Error( '400', __( $linode_response->errors['0']->reason, 'mainwp' ) );
            // Return AJAX.
            if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                wp_send_json_error( $error );

            }
        } elseif ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            wp_send_json( 'true' ); // Return AJAX.
        }
        die();
    }

    /**
     * Linode: action Refresh available backups.
     *
     * Save backups to DB for the selected Linode server.
     *
     * @return void
     */
    public function linode_action_refresh_available_backups() {
        Api_Backups_Helper::security_nonce( 'linode_action_refresh_available_backups' );
        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        } else {
            // Grab $_GET['data'] from url if not Ajax call.
            $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        }

        // Grab Child Site options.
        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $instance_id  = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Vultr Access token.
        $accessToken = static::get_linode_api_key();

        // Send Payload & create backup.  https://api.linode.com/v4/linode/instances/{linodeId}/backups.
        $api_response = (object) static::call_linode_api( 'GET', static::INSTANCES . $instance_id . '/backups', $accessToken );
        $all_backups  = $api_response->scalar;

        // Grab Site options then update Child Site options.
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_linode_available_backups', $all_backups );

        Api_Backups_Utility::save_lasttime_backup( $website_id, $all_backups, 'linode' );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Available backups.
            if ( ! $all_backups ) {
                wp_send_json_error( $api_response );
            } else {
                wp_send_json( 'true' );
            }
        }
    }

    /**
     * Linode: action Restore backup.
     *
     * Restore backup for the selected Linode server.
     *
     * @return void
     */
    public function linode_action_restore_backup() {

        Api_Backups_Helper::security_nonce( 'linode_action_restore_backup' );

        // Grab $_POST data.
        $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $backup_id  = isset( $_POST['backup_id'] ) ? wp_unslash( $_POST['backup_id'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $instance_id  = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Linode Access token.
        $accessToken = static::get_linode_api_key();

        // Set which Backup to restore to.
        $backup_data = array(
            'instance_id' => $instance_id,
            'overwrite'   => true,
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & create backup. https://api.linode.com/v4/linode/instances/{linodeId}/backups/{backupId}/restore.
        $restore_response = (array) static::call_linode_api( 'POST', static::INSTANCES . $instance_id . '/backups/' . $backup_id . '/restore', $accessToken, $backup_data );
        $restore_response = (array) json_decode( $restore_response['0'] );

        sleep( 20 );
        static::linode_action_linode_status( $website_id );
    }

    /**
     * Linode: action Linode status.
     *
     * Check linode status to know when to reboot server.
     *
     * @param int $website_id Child Site ID.
     *
     * @return void
     */
    public static function linode_action_linode_status( $website_id ) {

        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $instance_id  = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Linode Access token.
        $accessToken = static::get_linode_api_key();

        // Send Payload & grab instance status. https://api.linode.com/v4/linode/instances/{linodeId}.
        $linode_instance_response = (array) static::call_linode_api( 'GET', static::INSTANCES . $instance_id, $accessToken );
        $linode_instance_response = json_decode( $linode_instance_response['0'] );
        $linode_instance_status   = $linode_instance_response->status;

        // Check if linode status is 'offline'.
        // Check linode status to know when to reboot server.
        switch ( $linode_instance_status ) {
            case 'running':
            case 'restoring':
            case 'Linode Busy.':
                // Re-Check status.
                sleep( 1 );
                static::linode_action_linode_status( $website_id );
                break;
            case 'offline':
                // Starting server.
                static::linode_action_linode_start( $instance_id );
                break;
            default:
                break;
        }
    }

    /**
     * Linode: action Linode start.
     *
     * @param int $instance_id Linode Instance ID.
     *
     * @return void
     */
    public static function linode_action_linode_start( $instance_id ) {
        sleep( 10 );
        // Grab Linode Access token.
        $accessToken = static::get_linode_api_key();

        // Send Payload & create backup. https://api.linode.com/v4/linode/instances/{linodeId}/boot.
        $linode_boot_response = (array) static::call_linode_api( 'POST', static::INSTANCES . $instance_id . '/boot', $accessToken );
        $linode_boot_response = (array) json_decode( $linode_boot_response['0'] );

        wp_send_json( 'true' );
    }

    /**
     * Linode: action cancel backups.
     *
     * Cancel all backups for a Linode Instance.
     *
     * @return void
     */
    public function linode_action_cancel_backups() {

        Api_Backups_Helper::security_nonce( 'linode_action_cancel_backups' );

        // Grab $_POST data.
        $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $instance_id  = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Vultr Access token.
        $accessToken = static::get_linode_api_key();

        // Send Payload & create backup. https://api.linode.com/v4/linode/instances/{linodeId}/backups/cancel.
        $api_response = (object) static::call_linode_api( 'POST', static::INSTANCES . $instance_id . '/backups/cancel', $accessToken );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Available backups.
            if ( ! $api_response ) {
                wp_send_json_error( 'false' );

            } else {
                $all_backups = array();
                // Grab Site options then update Child Site options.
                Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_linode_available_backups', $all_backups );
            }
            wp_send_json( 'true' );
        }
    }

    /*********************************************************************************
     * DigitalOcean API Methods.
     **********************************************************************************/

    /**
     * Call DigitalOcean API, Authenticate & perform given method.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url relative URL for the call.
     * @param string $accessToken Access token generated using OAuth Call.
     * @param mixed  $backup_data Json encoded array - Optional post data for the call.
     * @return array Output from DigitalOcean API.
     */
    public static function call_digitalocean_api( $method, $url, $accessToken, $backup_data = array() ) {

        $baseurl = 'https://api.digitalocean.com/v2';

        $curl = curl_init();
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $baseurl . $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $backup_data,
                CURLOPT_HTTPHEADER     => array(
                    "Authorization: Bearer $accessToken",
                    'Content-Type: application/json',
                ),
            )
        );

        $resp = curl_exec( $curl );

        $httpCode             = (string) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
        $response['httpCode'] = $httpCode;
        $response['response'] = $resp;
        if ( '201' !== $httpCode && '200' !== $httpCode && '204' !== $httpCode ) {
            $response['status'] = 'false';
        } else {
            $response['status'] = 'true';

        }
        curl_close( $curl );
        return $response;
    }


    /**
     * DigitalOcean: Action create backup.
     *
     * Create a backup of the droplet.
     *
     * @return void
     */
    public function ajax_digitalocean_action_create_backup() {
        Api_Backups_Helper::security_nonce( 'digitalocean_action_create_backup' );
        static::digitalocean_action_create_backup();
    }

    /**
     * DigitalOcean: Action create backup.
     *
     * Create a backup of the droplet.
     *
     * @return void
     */
    public function ajax_digitalocean_action_individual_create_backup() {
        Api_Backups_Helper::security_nonce( 'digitalocean_action_individual_create_backup' );
        static::digitalocean_action_create_backup();
    }

    /**
     * DigitalOcean: Action create backup.
     *
     * Create a backup of the droplet.
     *
     * @param int  $website_id Website ID.
     * @param bool $ret_val Return output or not.
     *
     * @return mixed
     */
    public static function digitalocean_action_create_backup( $website_id = '', $ret_val = false ) {

        // Grab $_POST data.
        $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : $website_id; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

        // Grab Child Site options.
        $website = Api_Backups_Helper::get_website_by_id( $website_id );

        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website, array( 'mainwp_3rd_party_instance_id' ) );
        $droplet_id   = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Access token.
        $accessToken = static::get_digitalocean_api_key();

        // Set backup type and name.
        $backup_data = array(
            'type' => 'snapshot',
            'name' => 'MainWP API Backups - ' . $website['name'],
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & create backup.
        $api_response = static::call_digitalocean_api( 'POST', static::DROPLETS . $droplet_id . '/actions', $accessToken, $backup_data );

        $success = true;

        // Store Last Backup timestamp.
        if ( 'true' === $api_response['status'] ) {
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website, 'mainwp_3rd_party_digitalocean_last_backup', $current_time );
        } else {
            $success = false;
        }

        if ( $ret_val ) {
            return $api_response;
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( $success ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     *
     * DigitalOcean: Action refresh available backups.
     *
     * Save backups to DB for the selected DigitalOcean server.
     *
     * @return void
     */
    public function digitalocean_action_refresh_available_backups() {

        Api_Backups_Helper::security_nonce( 'digitalocean_action_refresh_available_backups' );

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        } else {
            // Grab $_GET['data'] from url if not Ajax call.
            $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            // $backup_api is pulled from the DB below if not Ajax call.
        }

        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $droplet_id   = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Vultr Access token.
        $accessToken = static::get_digitalocean_api_key();

        // Send Payload & create backup.
        $api_response = static::call_digitalocean_api( 'GET', static::DROPLETS . $droplet_id . '/snapshots', $accessToken );
        $all_backups  = $api_response['response'];

        // Grab Site options then update Child Site options.
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_digitalocean_available_backups', $all_backups );
        Api_Backups_Utility::save_lasttime_backup( $website_id, $all_backups, 'digitalocean' );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'true' === $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     * DigitalOcean: Action restore backup.
     *
     * Restore a backup of the droplet.
     *
     * @return void
     */
    public function digitalocean_action_restore_backup() {

        Api_Backups_Helper::security_nonce( 'digitalocean_action_restore_backup' );

        // Grab $_POST data.
        $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0;  //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        $snapshot_id = isset( $_POST['snapshot_id'] ) ? wp_unslash( $_POST['snapshot_id'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Grab IDs from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id' ) );
        $droplet_id   = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;

        // Grab Access token.
        $accessToken = static::get_digitalocean_api_key();

        // Set backup type and name.
        $backup_data = array(
            'type'  => 'restore',
            'image' => $snapshot_id,
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & create backup.
        $api_response = static::call_digitalocean_api( 'POST', static::DROPLETS . $droplet_id . '/actions', $accessToken, $backup_data );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'true' === $api_response['status'] && '201' === $api_response['httpCode'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     * DigitalOcean: Action delete backup.
     *
     * Delete a backup of the droplet.
     *
     * @return void
     */
    public static function digitalocean_action_delete_backup() {

        Api_Backups_Helper::security_nonce( 'digitalocean_action_delete_backup' );

        // Grab $_POST data.
        $snapshot_id = isset( $_POST['snapshot_id'] ) ? wp_unslash( $_POST['snapshot_id'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Grab Access token.
        $accessToken = static::get_digitalocean_api_key();

        // Send Payload.
        $api_response = static::call_digitalocean_api( 'DELETE', static::SNAPSHOTS . $snapshot_id, $accessToken );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            if ( '' !== $api_response['response'] && '204' === $api_response['httpCode'] ) {
                wp_send_json_error( 'false' );
            }
            wp_send_json( 'true' );
        }
    }

    /*********************************************************************************
     * Plesk API Methods.
     **********************************************************************************/
    /**
     * Call Plesk API, Authenticate & perform given method.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url API endpoint for the call.
     * @param string $baseurl Base url.
     * @param string $api_key Plesk API Key.
     * @param mixed  $backup_data Json encoded array - Optional post data for the call.
     * @return array Output from cPanel API.
     */
    public static function call_plesk_api( $method, $url, $baseurl, $api_key, $backup_data = array() ) {

        $curl = curl_init();

        $api_Key = $api_key;
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $baseurl . $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $backup_data,
                CURLOPT_HTTPHEADER     => array(
                    'Accept: */*', // DO NOT REMOVE THIS LINE! Plesk API will DELETE ALL BACKUPS if this is not included!
                    'Content-Type: application/json',
                    "X-API-Key: $api_Key",
                ),
            )
        );

        $resp = curl_exec( $curl );

        $httpCode = (string) curl_getinfo( $curl, CURLINFO_HTTP_CODE );

        $response['httpCode'] = $httpCode;
        $response['response'] = $resp;

        if ( '201' !== $httpCode && '200' !== $httpCode && '204' !== $httpCode ) {
            $response['status'] = 'false';
        } else {
            $response['status'] = 'true';
        }
        curl_close( $curl );
        return $response;
    }

    /**
     *
     * Plesk: Grab Home Directory.
     *
     * Grab needed home directory for downloading backups.
     *
     * @return string|bool
     */
    public static function get_plesk_home_dir() {
        // Authenticate plesk account.
        $plesk_authentication_credentials = static::get_plesk_authentication_credentials();
        $plesk_baseurl                    = $plesk_authentication_credentials[0]['plesk_baseurl'];
        $plesk_api_key                    = $plesk_authentication_credentials[0]['plesk_api_key'];
        $plesk_installation_id            = $plesk_authentication_credentials[0]['plesk_installation_id'];

        $api_response = static::call_plesk_api( 'GET', '/api/modules/wp-toolkit/v1/installations?installationsIds%5B%5D=' . $plesk_installation_id, $plesk_baseurl, $plesk_api_key );

        $response_decoded = ! empty( $api_response['response'] ) ? json_decode( $api_response['response'] ) : '';
        return is_array( $response_decoded ) && ! empty( $response_decoded[0] ) && ! empty( $response_decoded['0']->domain->name ) ? $response_decoded['0']->domain->name : false;
    }

    /**
     *
     * Plesk: Authentication.
     *
     * Grab needed authentication credentials for Plesk API - either from global settings or individual settings.
     *
     * @param int $website_id Website ID.
     *
     * @return array
     */
    public static function get_plesk_authentication_credentials( $website_id = null ) { //phpcs:ignore -- NOSONAR - complex.

        $plesk_authentication_credentials = array();
        // Grab website_id & from Ajax post if $website_id is not set.
        if ( empty( $website_id ) ) {
            if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                if ( isset( $_POST['website_id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
                    // Grab $_POST data.
                    $website_id = intval( $_POST['website_id'] ); //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
                } else {
                    // Grab $_GET['data'] from url.
                    $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
                }
            } else {
                // Grab $_GET['data'] from url.
                $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            }
        }

        // Global / Individual check.
        $global_individual_check        = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_enable_plesk_individual' ) );
        $mainwp_enable_plesk_individual = isset( $global_individual_check['mainwp_enable_plesk_individual'] ) ? $global_individual_check['mainwp_enable_plesk_individual'] : '0';

        if ( 'on' === $mainwp_enable_plesk_individual ) {
            // Grab cPanel baseurl, username & password.
            $baseurl       = Api_Backups_Helper::get_website_options( $website_id, array( 'plesk_api_url' ) );
            $plesk_baseurl = isset( $baseurl['plesk_api_url'] ) ? $baseurl['plesk_api_url'] : null;

            // Grab Plesk password.
            $plesk_api_key = Api_Backups_Utility::get_instance()->get_child_api_key( $website_id, 'plesk' );

            // Grab Plesk Installation ID.
            $site_path             = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_plesk_installation_id' ) );
            $plesk_installation_id = isset( $site_path['mainwp_plesk_installation_id'] ) ? $site_path['mainwp_plesk_installation_id'] : null;

        } elseif ( '0' === $mainwp_enable_plesk_individual ) {
            // Grab Plesk baseurl, username & password.
            $plesk_baseurl = ( false === get_option( 'mainwp_plesk_api_url' ) ) ? null : get_option( 'mainwp_plesk_api_url' );

            // Grab Plesk password.
            $plesk_api_key = static::get_plesk_api_key();

            // Grab Plesk Installation ID.
            $site_path             = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_plesk_installation_id' ) );
            $plesk_installation_id = isset( $site_path['mainwp_plesk_installation_id'] ) ? $site_path['mainwp_plesk_installation_id'] : null;
        }

        // Build array.
        $plesk_authentication_credentials[] = array(
            'plesk_baseurl'         => $plesk_baseurl,
            'plesk_api_key'         => $plesk_api_key,
            'plesk_installation_id' => $plesk_installation_id,
            'website_id'            => $website_id,
        );

        return $plesk_authentication_credentials;
    }

    /**
     * Plesk: action refresh available backups.
     *
     * Refresh available backups for the selected server.
     *
     * @return void
     */
    public function ajax_plesk_action_refresh_available_backups() {
        Api_Backups_Helper::security_nonce( 'plesk_action_refresh_available_backups' );
        static::plesk_action_refresh_available_backups();
        die();
    }

    /**
     * Plesk: action create backup.
     *
     * Create backup for the selected server.
     *
     * @return void
     */
    public function ajax_plesk_action_create_backup() {
        Api_Backups_Helper::security_nonce( 'plesk_action_create_backup' );
        static::plesk_action_create_backup();
        die();
    }

    /**
     * Plesk: action restore backup.
     *
     * Create backup for the selected server.
     *
     * @return void
     */
    public function ajax_plesk_action_restore_backup() {
        Api_Backups_Helper::security_nonce( 'plesk_action_restore_backup' );
        static::plesk_action_restore_backup();
        die();
    }

    /**
     * Plesk: action delete backup.
     *
     * Delete backup for the selected server.
     *
     * @return void
     */
    public function ajax_plesk_action_delete_backup() {
        Api_Backups_Helper::security_nonce( 'plesk_action_delete_backup' );
        static::plesk_action_delete_backup();
        die();
    }

    /**
     *
     * Plesk: Action refresh available backups.
     *
     * Save backups to DB for the selected server.
     *
     * @return void
     */
    public static function plesk_action_refresh_available_backups() {

        // Authenticate plesk account.
        $plesk_authentication_credentials = static::get_plesk_authentication_credentials();
        $plesk_baseurl                    = $plesk_authentication_credentials[0]['plesk_baseurl'];
        $plesk_api_key                    = $plesk_authentication_credentials[0]['plesk_api_key'];
        $plesk_installation_id            = $plesk_authentication_credentials[0]['plesk_installation_id'];
        $website_id                       = $plesk_authentication_credentials[0]['website_id'];

        // Grab backup meta.
        $api_response = static::call_plesk_api( 'GET', '/api/modules/wp-toolkit/v1/installations/' . $plesk_installation_id . '/backups/meta', $plesk_baseurl, $plesk_api_key );

        $all_backups = $api_response['response'];
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_plesk_available_backups', $all_backups );
        Api_Backups_Utility::save_lasttime_backup( $website_id, $all_backups, 'plesk' );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'true' === $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     *
     * Plesk: Action create manual backup.
     *
     * Create a Plesk Backup.
     *
     * @param bool $ret_val Return output or not.
     * @param int  $website_id Website ID.
     *
     * @return mixed
     */
    public static function plesk_action_create_backup( $ret_val = false, $website_id = null ) { //phpcs:ignore -- NOSONAR - complex.

        // Authenticate plesk account.
        $plesk_authentication_credentials = static::get_plesk_authentication_credentials( $website_id );
        $plesk_baseurl                    = $plesk_authentication_credentials[0]['plesk_baseurl'];
        $plesk_api_key                    = $plesk_authentication_credentials[0]['plesk_api_key'];
        $plesk_installation_id            = $plesk_authentication_credentials[0]['plesk_installation_id'];
        $website_id                       = $plesk_authentication_credentials[0]['website_id'];

        $backup_data = array(
            'installationId' => $plesk_installation_id,
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & create backup. https://{your_plesk_url}/api/modules/wp-toolkit/v1/features/backups/creator.
        $api_response = static::call_plesk_api( 'POST', '/api/modules/wp-toolkit/v1/features/backups/creator', $plesk_baseurl, $plesk_api_key, $backup_data );

        $response_decoded = is_array( $api_response ) && ! empty( $api_response['response'] ) ? json_decode( $api_response['response'] ) : '';
        $errors           = ! empty( $response_decoded ) && ! empty( $response_decoded->task->errors ) ? $response_decoded->task->errors : '';

        $success = false;
        if ( is_array( $api_response ) && isset( $api_response['status'] ) && 'true' === $api_response['status'] ) {
            $success = true;
        }

        if ( empty( $errors ) ) {
            // Save Timestamp.
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_plesk_last_backup', $current_time );
        }

        if ( $ret_val ) {
            return $api_response;
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( $success ) {
                wp_send_json( 'true' );
            } else {
                if ( empty( $errors ) ) {
                    $errors = esc_html__( 'Undefined error. Please try again.', 'mainwp' );
                }
                wp_die( esc_html( $errors ) );
            }
        }
    }

    /**
     *
     * Plesk: Action restore backup.
     *
     * Restore Plesk Backup.
     */
    public static function plesk_action_restore_backup() {

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $backup_name = isset( $_POST['backup_name'] ) ? wp_unslash( $_POST['backup_name'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        }

        // Authenticate plesk account.
        $plesk_authentication_credentials = static::get_plesk_authentication_credentials( $website_id );
        $plesk_baseurl                    = $plesk_authentication_credentials[0]['plesk_baseurl'];
        $plesk_api_key                    = $plesk_authentication_credentials[0]['plesk_api_key'];
        $plesk_installation_id            = $plesk_authentication_credentials[0]['plesk_installation_id'];

        $backup_data = array(
            'installationId'        => $plesk_installation_id,
            'fileName'              => $backup_name,
            'clobber'               => 'false',
            'dropAllDatabaseTables' => 'false',
        );

        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & create backup. https://{your_plesk_url}/api/modules/wp-toolkit/v1/features/backups/restorer.
        $api_response = static::call_plesk_api( 'POST', '/api/modules/wp-toolkit/v1/features/backups/restorer', $plesk_baseurl, $plesk_api_key, $backup_data );

        $response_decoded = is_array( $api_response ) && ! empty( $api_response['response'] ) ? json_decode( $api_response['response'] ) : '';
        $errors           = ! empty( $response_decoded ) && ! empty( $response_decoded->task->errors ) ? $response_decoded->task->errors : '';

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( empty( $errors ) ) {
                wp_send_json( 'true' );
            } else {
                wp_die( esc_html( $errors ) );
            }
        }
    }

    /**
     *
     * Plesk: Action delete backup.
     *
     * Delete Plesk Backup.
     */
    public static function plesk_action_delete_backup() {

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $backup_name = isset( $_POST['backup_name'] ) ? wp_unslash( $_POST['backup_name'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        }

        // Authenticate plesk account.
        $plesk_authentication_credentials = static::get_plesk_authentication_credentials( $website_id );
        $plesk_baseurl                    = $plesk_authentication_credentials[0]['plesk_baseurl'];
        $plesk_api_key                    = $plesk_authentication_credentials[0]['plesk_api_key'];
        $plesk_installation_id            = $plesk_authentication_credentials[0]['plesk_installation_id'];

        $backup_data = wp_json_encode(
            array(
                'delete' => true,
            )
        );

        /**
         * Payload for deleting a backup.
         * plesk_baseurl must include the port number.
         * https://{plesk_baseurl:8443}/api/modules/wp-toolkit/v1/installations/{plesk_installation_id}/backups?files%5B%5D={backup_name}
         * Backup Name Example ( must be urlencoded ): sweet-lovelace.104-207-132-143.plesk.page_site-1__2024-01-12T16_37_25%2B0000.zip
         */

        $payload = '/api/modules/wp-toolkit/v1/installations/' . $plesk_installation_id . '/backups?files%5B%5D=' . rawurlencode( $backup_name );

        // Send Payload & create backup.
        $api_response = static::call_plesk_api( 'DELETE', $payload, $plesk_baseurl, $plesk_api_key, $backup_data );

        $response_decoded = ! empty( $api_response['response'] ) ? json_decode( $api_response['response'] ) : '';
        $errors           = ! empty( $response_decoded ) && ! empty( $response_decoded->task->errors ) ? $response_decoded->task->errors : '';

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( empty( $errors ) && '204' === (string) $api_response['httpCode'] && 'true' === (string) $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( esc_html( $errors ) );
            }
        }
    }

    /*********************************************************************************
     * CPanel API Methods.
     **********************************************************************************/
    /**
     * Call cPanel API, Authenticate & perform given method.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url API endpoint for the call.
     * @param string $baseurl Baseurl.
     * @param string $username cPanel account username.
     * @param string $password cPanel account password.
     * @param mixed  $backup_data Json encoded array - Optional post data for the call.
     * @return array Output from cPanel API.
     */
    public static function call_cpanel_api( $method, $url, $baseurl, $username, $password, $backup_data = array() ) {

        // base64encode cPanel Username & Password.
        $base64encoded = base64_encode( $username . ':' . $password ); //phpcs:ignore -- base64 encode.
        $curl          = curl_init();

        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $baseurl . $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $backup_data,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: text/plain',
                    "Authorization: Basic $base64encoded",
                ),
            )
        );

        $resp = curl_exec( $curl );

        $httpCode             = (string) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
        $response['httpCode'] = $httpCode;
        $response['response'] = $resp;
        if ( '201' !== $httpCode && '200' !== $httpCode && '204' !== $httpCode ) {
            $response['status'] = 'false';
        } else {
            $response['status'] = 'true';
        }
        curl_close( $curl );
        return $response;
    }

    /*********************************************************************************
     * CPanel API Methods.
     **********************************************************************************/
    /**
     * Call cPanel API, Authenticate & perform given method.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url API endpoint for the call.
     * @param string $baseurl base url.
     * @param string $username cPanel account username.
     * @param string $password cPanel account password.
     * @param mixed  $backup_data Json encoded array - Optional post data for the call.
     * @return array Output from cPanel API.
     */
    public static function call_cpanel_api_json( $method, $url, $baseurl, $username, $password, $backup_data = array() ) {

        // base64encode cPanel Username & Password.
        $base64encoded = base64_encode( $username . ':' . $password ); //phpcs:ignore -- base64 encode.
        $curl          = curl_init();

        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $baseurl . $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $backup_data,
                CURLOPT_HTTPHEADER     => array(
                    'Accept: */*', // DO NOT REMOVE THIS LINE! Plesk API will DELETE ALL BACKUPS if this is not included!
                    'Content-Type: application/json',
                    "Authorization: Basic $base64encoded",
                ),
            )
        );

        $resp = curl_exec( $curl );

        $httpCode             = (string) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
        $response['httpCode'] = $httpCode;
        $response['response'] = $resp;
        if ( '201' !== $httpCode && '200' !== $httpCode && '204' !== $httpCode ) {
            $response['status'] = 'false';
        } else {
            $response['status'] = 'true';
        }
        curl_close( $curl );
        return $response;
    }

    /**
     *
     * CPanel: Authentication.
     *
     * Grab needed authentication credentials for cPanel API - either from global settings or individual settings.
     *
     * @param int $website_id Website ID.
     *
     * @return array
     */
    public static function get_cpanel_authentication_credentials( $website_id = '' ) { //phpcs:ignore -- NOSONAR - complex.

        $cpanel_authentication_credentials = array();

        // Grab website_id & from Ajax post if $website_id is not set.
        if ( empty( $website_id ) ) {
            if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                // Grab $_POST data.
                $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            } else {
                // Grab $_GET['data'] from url if not Ajax call.
                $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            }
        }

        // Global / Individual check.
        $global_individual_check         = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_enable_cpanel_individual' ) );
        $mainwp_enable_cpanel_individual = isset( $global_individual_check['mainwp_enable_cpanel_individual'] ) ? $global_individual_check['mainwp_enable_cpanel_individual'] : '0';

        if ( 'on' === $mainwp_enable_cpanel_individual ) {
            // Grab cPanel baseurl, username & password.
            $baseurl        = Api_Backups_Helper::get_website_options( $website_id, array( 'cpanel_api_url' ) );
            $cpanel_baseurl = isset( $baseurl['cpanel_api_url'] ) ? $baseurl['cpanel_api_url'] : null;

            // Grab cPanel username.
            $username        = Api_Backups_Helper::get_website_options( $website_id, array( 'cpanel_account_username' ) );
            $cpanel_username = isset( $username['cpanel_account_username'] ) ? $username['cpanel_account_username'] : null;

            // Grab cPanel password.
            $cpanel_password = Api_Backups_Utility::get_instance()->get_child_api_key( $website_id, 'cpanel' );

            // Grab cPanel site path.
            $site_path        = Api_Backups_Helper::get_website_options( $website_id, array( 'cpanel_site_path' ) );
            $cpanel_site_path = isset( $site_path['cpanel_site_path'] ) ? $site_path['cpanel_site_path'] : null;

        } elseif ( '0' === $mainwp_enable_cpanel_individual ) {
            // Grab cPanel baseurl, username & password.
            $cpanel_baseurl = get_option( 'mainwp_cpanel_url' ); // Fixing when mergeing ???.
            // Grab cPanel username.
            $cpanel_username = get_option( 'mainwp_cpanel_account_username' ); // Fixing when mergeing ???.
            // Grab cPanel password.
            $cpanel_password = static::get_cpanel_account_password();

            // Grab cPanel site path.
            $cpanel_site_path = ( false === get_option( 'mainwp_cpanel_site_path' ) ) ? null : get_option( 'mainwp_cpanel_site_path' );
        }

        // Build array.
        $cpanel_authentication_credentials[] = array(
            'cpanel_baseurl'   => $cpanel_baseurl,
            'cpanel_username'  => $cpanel_username,
            'cpanel_password'  => $cpanel_password,
            'cpanel_site_path' => $cpanel_site_path,
            'website_id'       => $website_id,
        );

        return $cpanel_authentication_credentials;
    }

    /**
     *
     * CPanel: Action get cPanel Home Directory.
     *
     * Grab cPanel Home Directory for manual backup links.
     *
     * @return string
     */
    public static function cpanel_action_get_home_directory() {

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];

        // Send Payload.
        $api_response         = static::call_cpanel_api( 'GET', '/execute/Variables/get_user_information', $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $api_response_decoded = json_decode( $api_response['response'] );

        if ( is_object( $api_response_decoded ) && isset( $api_response_decoded->data->home ) ) {
            $cpanel_home_directory = $api_response_decoded->data->home;
        } else {
            $cpanel_home_directory = '';
        }

        return $cpanel_home_directory;
    }

    /**
     * Get cPanel manual account backups.
     *
     * @return false|string cPanel account backups
     */
    public static function get_cpanel_manual_account_backups() {

        // Define array for Manual Backups.
        $manual_backups = array();

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];

        // Send Payload.
        $api_response         = static::call_cpanel_api( 'GET', '/execute/Fileman/list_files?dir=/&include_mime=1&types=file', $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $api_response_decoded = json_decode( $api_response['response'] );

        // Build list.
        if ( is_object( $api_response_decoded ) && isset( $api_response_decoded->data ) ) {
            $files = $api_response_decoded->data;
            foreach ( $files as $file ) {
                if ( 'application/x-gzip' === $file->rawmimetype ) {
                    $manual_backups[] = array(
                        'file_name'    => $file->file,
                        'absolute_dir' => $file->absdir,
                        'timestamp'    => $file->ctime,
                    );
                }
            }
        } else {
            $manual_backups = array();
        }

        // return array of Manual Backups.
        return wp_json_encode( $manual_backups );
    }

    /**
     * Get cPanel manual database backups.
     *
     * Create manual database backup via mysqldump.
     * Backups will be stored within `/uploads/mainwp/api_db_backups` directory.
     *
     * @param int $website_id Website ID.
     *
     * @return false|string cPanel account backups
     */
    public static function get_cpanel_manual_database_backups( $website_id = '' ) {

        unset( $website_id );

        // Define array for Manual Backups.
        $manual_backups = array();

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];

        // Grab cPanel site path.
        $cpanel_db_backups_path = $cpanel_authentication_credentials[0]['cpanel_site_path'] . '/wp-content/uploads/mainwp/api_db_backups';

        // Send Payload.
        $api_response         = static::call_cpanel_api( 'GET', '/execute/Fileman/list_files?dir=' . $cpanel_db_backups_path . '&include_mime=1&types=file', $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $api_response_decoded = json_decode( $api_response['response'] );

        // Build list.
        if ( is_object( $api_response_decoded ) && isset( $api_response_decoded->data ) ) {
            $files = $api_response_decoded->data;
            foreach ( $files as $file ) {
                if ( 'application/x-gzip' === $file->rawmimetype ) {
                    $manual_backups[] = array(
                        'file_name'    => $file->file,
                        'absolute_dir' => $file->absdir,
                        'timestamp'    => $file->ctime,
                    );
                }
            }
        } else {
            $manual_backups = array();
        }

        // return array of Manual Backups.
        return wp_json_encode( $manual_backups );
    }

    /**
     *
     * Method get_3rdparty_installation_path().
     *
     * @param string $what what to get path.
     *
     * @return string api path.
     */
    public static function get_3rdparty_installation_path( $what = '' ) {
        $path = '/3rdparty/wpt/index.php/v1/installations/';
        if ( 'with_sort' === $what ) {
            $path .= '?sortBy=title&sortOrder=asc';
        }
        return $path;
    }

    /**
     *
     * CPanel: Action refresh available WP-Toolit backups.
     *
     * Save backups to DB for the selected server.
     *
     * @param int $website_id Website id.
     *
     * @return array
     */
    public function get_cpanel_wp_toolkit_backups( $website_id ) {

        // Grab Child Site options array.
        $website = Api_Backups_Helper::get_website_by_id( $website_id );

        // Grab website url & trim trailing slash.
        $website_url = trim( $website['url'], '/' );

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];

        $wp_toolkit_installation_id = '';

        // Grab list of WP Toolkit installations.
        $wp_toolkit_installation_list         = static::call_cpanel_api( 'GET', $this->get_3rdparty_installation_path( 'with_sort' ), $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $wp_toolkit_installation_list_decoded = json_decode( $wp_toolkit_installation_list['response'] );

        // Search for website url in list of WP Toolkit installations & grab Site ID.
        if ( is_array( $wp_toolkit_installation_list_decoded ) ) {
            foreach ( $wp_toolkit_installation_list_decoded as $site ) {
                if ( $site->url === $website_url ) {
                    $wp_toolkit_installation_id = $site->id;
                }
            }
        }

        // Grab backup meta for found Site ID.
        $wp_toolkit_backups = static::call_cpanel_api( 'GET', static::get_3rdparty_installation_path() . $wp_toolkit_installation_id . '/backups/meta', $cpanel_baseurl, $cpanel_username, $cpanel_password );
        if ( 'true' === $wp_toolkit_backups['status'] ) {
            return $wp_toolkit_backups['response'];
        } else {
            return array();
        }
    }

    /**
     * CPanel: action restore backup.
     *
     * Restore cPanel backup for the selected cPanel server.
     *
     * @return void
     */
    public function ajax_cpanel_action_restore_backup() {
        Api_Backups_Helper::security_nonce( 'cpanel_action_restore_backup' );
        static::cpanel_action_restore_backup();
        die();
    }

    /**
     * CPanel: action restore database backup.
     *
     * Restore cPanel backup for the selected cPanel server.
     *
     * @return void
     */
    public function ajax_cpanel_action_restore_database_backup() {
        Api_Backups_Helper::security_nonce( 'cpanel_action_restore_database_backup' );
        static::cpanel_action_restore_database_backup();
        die();
    }

    /**
     * CPanel: action restore manual backup.
     *
     * Restore cPanel backup for the selected cPanel server.
     *
     * @return void
     */
    public function ajax_cpanel_action_restore_manual_backup() {
        Api_Backups_Helper::security_nonce( 'cpanel_action_restore_manual_backup' );
        static::cpanel_action_restore_manual_backup();
        die();
    }

    /**
     * CPanel: action refresh available backups.
     *
     * Refresh available backups for the selected cPanel server.
     *
     * @return void
     */
    public function ajax_cpanel_action_refresh_available_backups() {
        Api_Backups_Helper::security_nonce( 'cpanel_action_refresh_available_backups' );
        static::cpanel_action_refresh_available_backups();
        die();
    }

    /**
     * CPanel: action create backup.
     *
     * Create backup for the selected cPanel server.
     *
     * @return void
     */
    public function ajax_cpanel_action_create_manual_backup() {
        Api_Backups_Helper::security_nonce( 'cpanel_action_create_manual_backup' );
        static::cpanel_action_create_manual_backup();
        die();
    }

    /**
     * CPanel: action create WP-Toolkit backup.
     *
     * Create backup for the selected cPanel server.
     *
     * @return void
     */
    public function ajax_cpanel_action_create_wptk_backup() {
        Api_Backups_Helper::security_nonce( 'cpanel_action_create_wptk_backup' );
        static::cpanel_action_create_wptk_backup();
        die();
    }

    /**
     * CPanel: action create WP-Toolkit backup.
     *
     * Create backup for the selected cPanel server.
     *
     * @return void
     */
    public function ajax_cpanel_action_restore_wptk_backup() {
        Api_Backups_Helper::security_nonce( 'cpanel_action_restore_wptk_backup' );
        static::cpanel_action_restore_wptk_backup();
        die();
    }

    /**
     * CPanel: action delete WP-Toolkit backup.
     *
     * Create backup for the selected cPanel server.
     *
     * @return void
     */
    public function ajax_cpanel_action_delete_wptk_backup() {
        Api_Backups_Helper::security_nonce( 'cpanel_action_delete_wptk_backup' );
        static::cpanel_action_delete_wptk_backup();
        die();
    }

    /**
     * CPanel: action create full backup.
     *
     * Create backup for the selected cPanel server.
     *
     * @return void
     */
    public static function ajax_cpanel_action_create_full_backup() { //phpcs:ignore -- NOSONAR - complex.
        Api_Backups_Helper::security_nonce( 'cpanel_action_create_full_backup' );

        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab Child Site ID from $_POST data.
            $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

            $opts = Api_Backups_Helper::get_website_options(
                $website_id,
                array(
                    'mainwp_enable_wp_toolkit',
                )
            );

            $mainwp_enable_wp_toolkit = '';

            if ( is_array( $opts ) ) {
                $mainwp_enable_wp_toolkit = $opts['mainwp_enable_wp_toolkit'] ?? '0';
            }

            // Create file backup.
            $file_backup_response = static::cpanel_action_create_manual_backup( true );
            $response_decoded     = json_decode( $file_backup_response['response'] );
            $errors               = $response_decoded->errors['0'];

            // If no errors with file backup, create database backup.
            if ( empty( $errors ) ) {

                // If no errors, create database backup.
                $database_backup_response = static::ajax_cpanel_action_create_database_backup( true, $website_id );

                // If no errors, & WP-Toolikit is enabled create WP-Toolkit backup or return true.
                if ( 'GOOD' === $database_backup_response['result'] ) {
                        // If WP Toolkit is enabled, create WP Toolkit backup.
                    if ( 'on' === $mainwp_enable_wp_toolkit ) {
                        $cpanel_wptk_backup_response = static::cpanel_action_create_wptk_backup( true );
                        if ( '201' !== $cpanel_wptk_backup_response['httpCode'] && '200' !== $cpanel_wptk_backup_response['httpCode'] && '204' !== $cpanel_wptk_backup_response['httpCode'] ) {
                            wp_die( 'There was an issue while creating the Database backup. Please check logs and try again.' );
                        } else {
                            // Return true for WP-Toolkit backup.
                            wp_send_json( 'true' );
                        }
                    } else {
                        // Return true for cPanel backup.
                        wp_send_json( 'true' );
                    }
                } else {
                    // Return error for cPanel database backup.
                    wp_die( 'There was an issue while creating the Cpanel Database backup. Please check logs and try again.' );
                }
            } else {
                // If cpanel file backup errors, return false.
                wp_die( esc_html( $errors ) );
            }
        }
    }

    /**
     * CPanel: action create database backup.
     *
     * Create database backup for the selected cPanel server.
     *
     * @param bool $ret_val Return output or not.
     * @param int  $website_id Website ID.
     *
     * @return mixed
     */
    public static function ajax_cpanel_action_create_database_backup( $ret_val = false, $website_id = null ) {

        // Grab website_id & from Ajax post if $website_id is not set.
        if ( empty( $website_id ) ) {

            // Check nonce.
            Api_Backups_Helper::security_nonce( 'cpanel_action_create_database_backup' );

            // Grab $_POST data.
            $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

        }

        $post_data = array( 'mwp_action' => 'cpanel_mysqldump' );

        $information = MainWP_Extensions_Handler::fetch_url_authed( $website_id, 'api_backups_mysqldump', $post_data );

        if ( $ret_val ) {
            return $information;
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'GOOD' === $information['result'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'There was an issue while creating the database backup. Please check logs and try again.' );
            }
        }
    }

    /**
     *
     * CPanel: Action create manual backup.
     *
     * Create a cPanel Manual Backup.
     *
     * @param bool $ret_val Return output or not.
     * @param int  $website_id Website ID.
     *
     * @return mixed
     */
    public static function cpanel_action_create_manual_backup( $ret_val = false, $website_id = null ) {

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials( $website_id );
        $website_id                        = $cpanel_authentication_credentials[0]['website_id'];

        // Send Payload & create backup.
        $api_response     = static::call_cpanel_api( 'GET', '/execute/Backup/fullbackup_to_homedir', $cpanel_authentication_credentials[0]['cpanel_baseurl'], $cpanel_authentication_credentials[0]['cpanel_username'], $cpanel_authentication_credentials[0]['cpanel_password'] );
        $response_decoded = is_array( $api_response ) && ! empty( $api_response['response'] ) ? json_decode( $api_response['response'] ) : '';
        $errors           = ! empty( $response_decoded ) && is_object( $response_decoded ) && ! empty( $response_decoded->errors ) ? $response_decoded->errors['0'] : '';

        if ( empty( $errors ) ) {
            // Save Timestamp.
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cpanel_last_backup', $current_time );
        }

        if ( $ret_val ) {
            return $api_response;
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( empty( $errors ) ) {
                wp_send_json( 'true' );
            } else {
                wp_die( esc_html( $errors ) );
            }
        }
    }

    /**
     *
     * CPanel: Action create WP-Toolkit backup.
     *
     * Create a cPanel Manual Backup.
     *
     * @param bool $ret_val Return output or not.
     *
     * @return array|void
     */
    public static function cpanel_action_create_wptk_backup( $ret_val = false ) { //phpcs:ignore -- NOSONAR - complex.

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];
        $website_id                        = $cpanel_authentication_credentials[0]['website_id'];

        // Grab Child Site options array.
        $website = Api_Backups_Helper::get_website_by_id( $website_id );

        // Grab website url & trim trailing slash.
        $website_url = trim( $website['url'], '/' );

        // Grab list of WP Toolkit installations.
        $wp_toolkit_installation_list         = static::call_cpanel_api( 'GET', static::get_3rdparty_installation_path( 'with_sort' ), $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $wp_toolkit_installation_list_decoded = json_decode( $wp_toolkit_installation_list['response'] );

        if ( '201' !== $wp_toolkit_installation_list['httpCode'] && '200' !== $wp_toolkit_installation_list['httpCode'] && '204' !== $wp_toolkit_installation_list['httpCode'] ) {
            $error = $wp_toolkit_installation_list_decoded->meta->message;
            wp_die( esc_html( $error ) );
        } elseif ( is_array( $wp_toolkit_installation_list_decoded ) ) {
            foreach ( $wp_toolkit_installation_list_decoded as $site ) {
                if ( $site->url === $website_url ) {
                    $wp_toolkit_installation_id = $site->id;
                }
            }
        } else {
            $wp_toolkit_installation_id = '';
        }

        // Prepare Backup Payload.
        $backup_data = array(
            'installationId' => $wp_toolkit_installation_id,
        );
        $backup_data = wp_json_encode( $backup_data );

        // Grab list of WP Toolkit installations.
        $wp_toolkit_backup_response         = static::call_cpanel_api_json( 'POST', '/3rdparty/wpt/index.php/v1/features/backups/creator', $cpanel_baseurl, $cpanel_username, $cpanel_password, $backup_data );
        $wp_toolkit_backup_response_decoded = is_array( $wp_toolkit_backup_response ) && ! empty( $wp_toolkit_backup_response['response'] ) ? json_decode( $wp_toolkit_backup_response['response'] ) : '';

        $errors = '';
        if ( '201' !== $wp_toolkit_backup_response['httpCode'] && '200' !== $wp_toolkit_backup_response['httpCode'] && '204' !== $wp_toolkit_backup_response['httpCode'] ) {
            $errors = ! empty( $wp_toolkit_backup_response_decoded ) && ! empty( $wp_toolkit_backup_response_decoded->meta->message ) ? $wp_toolkit_backup_response_decoded->meta->message : '';
        } else {
            // Save Timestamp.
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cpanel_last_backup', $current_time );
        }

        if ( $ret_val ) {
            return $wp_toolkit_backup_response;
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( empty( $errors ) ) {
                wp_send_json( 'true' );
            } else {
                wp_die( esc_html( $errors ) );
            }
        }
    }

    /**
     *
     * CPanel: Action create WP-Toolkit backup.
     *
     * Create a cPanel Manual Backup.
     *
     * @param bool $ret_val Return output or not.
     *
     * @return array|void
     */
    public static function cpanel_action_restore_wptk_backup( $ret_val = false ) {

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];
        $website_id                        = $cpanel_authentication_credentials[0]['website_id'];

        // Grab Child Site options array.
        $website = Api_Backups_Helper::get_website_by_id( $website_id );

        // Grab website url & trim trailing slash.
        $website_url = trim( $website['url'], '/' );

        // Grab list of WP Toolkit installations.
        $wp_toolkit_installation_list         = static::call_cpanel_api( 'GET', static::get_3rdparty_installation_path( 'with_sort' ), $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $wp_toolkit_installation_list_decoded = json_decode( $wp_toolkit_installation_list['response'] );

        if ( '201' !== $wp_toolkit_installation_list['httpCode'] && '200' !== $wp_toolkit_installation_list['httpCode'] && '204' !== $wp_toolkit_installation_list['httpCode'] ) {
            $error = $wp_toolkit_installation_list_decoded->meta->message;
            wp_die( esc_html( $error ) );
        } elseif ( is_array( $wp_toolkit_installation_list_decoded ) ) {
            foreach ( $wp_toolkit_installation_list_decoded as $site ) {
                if ( $site->url === $website_url ) {
                    $wp_toolkit_installation_id = $site->id;
                }
            }
        } else {
            $wp_toolkit_installation_id = '';
        }

        // Prepare Backup Payload.
        $backup_data = array(
            'installationId'        => $wp_toolkit_installation_id,
            'fileName'              => isset( $_POST['backup_name'] ) ? wp_unslash( $_POST['backup_name'] ) : '', //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            'clobber'               => false,
            'dropAllDatabaseTables' => false,

        );
        $backup_data = wp_json_encode( $backup_data );

        // Grab list of WP Toolkit installations.
        $wp_toolkit_backup_response         = static::call_cpanel_api_json( 'POST', '/3rdparty/wpt/index.php/v1/features/backups/restorer', $cpanel_baseurl, $cpanel_username, $cpanel_password, $backup_data );
        $wp_toolkit_backup_response_decoded = json_decode( $wp_toolkit_backup_response['response'] );

        if ( '201' !== $wp_toolkit_backup_response['httpCode'] && '200' !== $wp_toolkit_backup_response['httpCode'] && '204' !== $wp_toolkit_backup_response['httpCode'] ) {
            $error = $wp_toolkit_backup_response_decoded->meta->message;
            wp_die( esc_html( $error ) );
        } else {
            wp_send_json( 'true' );
        }

        if ( $ret_val ) {
            return $wp_toolkit_backup_response;
        }
    }

    /**
     *
     * Plesk: Action delete backup.
     *
     * Delete Plesk Backup.
     *
     * @return void
     */
    public static function cpanel_action_delete_wptk_backup() { //phpcs:ignore -- NOSONAR - complex.

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $backup_name = isset( $_POST['backup_name'] ) ? wp_unslash( $_POST['backup_name'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $website_id  = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        }

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];

        // Grab Child Site options array.
        $website = Api_Backups_Helper::get_website_by_id( $website_id );

        // Grab website url & trim trailing slash.
        $website_url = trim( $website['url'], '/' );

        $wp_toolkit_installation_id = '';

        // Grab list of WP Toolkit installations.
        $wp_toolkit_installation_list         = static::call_cpanel_api( 'GET', static::get_3rdparty_installation_path( 'with_sort' ), $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $wp_toolkit_installation_list_decoded = json_decode( $wp_toolkit_installation_list['response'] );

        if ( '201' !== $wp_toolkit_installation_list['httpCode'] && '200' !== $wp_toolkit_installation_list['httpCode'] && '204' !== $wp_toolkit_installation_list['httpCode'] ) {
            $error = $wp_toolkit_installation_list_decoded->meta->message;
            wp_die( esc_html( $error ) );
        } elseif ( is_array( $wp_toolkit_installation_list_decoded ) ) {
            foreach ( $wp_toolkit_installation_list_decoded as $site ) {
                if ( $site->url === $website_url ) {
                    $wp_toolkit_installation_id = $site->id;
                }
            }
        }

        $backup_data = wp_json_encode(
            array(
                'delete' => true,
            )
        );

        /**
         * Payload for deleting a backup.
         * plesk_baseurl must include the port number.
         * https://{plesk_baseurl:8443}/api/modules/wp-toolkit/v1/installations/{plesk_installation_id}/backups?files%5B%5D={backup_name}
         * Backup Name Example ( must be urlencoded ): sweet-lovelace.104-207-132-143.plesk.page_site-1__2024-01-12T16_37_25%2B0000.zip
         *
         * /v1/installations/{installationId}/backups
         */
        $payload = static::get_3rdparty_installation_path() . $wp_toolkit_installation_id . '/backups?files%5B%5D=' . rawurlencode( $backup_name );

        // Send Payload & create backup.
        $api_response     = static::call_cpanel_api_json( 'DELETE', $payload, $cpanel_baseurl, $cpanel_username, $cpanel_password, $backup_data );
        $response_decoded = json_decode( $api_response['response'] );
        $errors           = $response_decoded->task->errors;

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( empty( $errors ) && '204' === (string) $api_response['httpCode'] && 'true' === (string) $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( esc_html( $errors ) );
            }
        }
    }

    /**
     *
     * CPanel: Action refresh available backups.
     *
     * Save backups to DB for the selected cPanel server.
     *
     * @return void
     */
    public function cpanel_action_refresh_available_backups() {

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];
        $cpanel_site_path                  = $cpanel_authentication_credentials[0]['cpanel_site_path'];
        $website_id                        = $cpanel_authentication_credentials[0]['website_id'];

        // Grab automatic WHM backups.
        $api_response = static::call_cpanel_api( 'GET', '/execute/Restore/query_file_info?path=' . $cpanel_site_path . '&exists=1', $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $all_backups  = $api_response['response'];

        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cpanel_available_backups', $all_backups );
        Api_Backups_Utility::save_lasttime_backup( $website_id, $all_backups, 'cpanel_auto' );

        // Grab manual account backups.
        $manual_backups = static::get_cpanel_manual_account_backups();
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cpanel_manual_backups', $manual_backups );

        Api_Backups_Utility::save_lasttime_backup( $website_id, $manual_backups, 'cpanel_manual' );

        // Grab manual DB backups.
        $database_backups = static::get_cpanel_manual_database_backups( $website_id );
        Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cpanel_manual_database_backups', $database_backups );

        // Grab WP-Toolkit backups.
        $wp_toolkit_enabled = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_enable_wp_toolkit' ) );
        $wp_toolkit_enabled = isset( $wp_toolkit_enabled['mainwp_enable_wp_toolkit'] ) ? strtolower( $wp_toolkit_enabled['mainwp_enable_wp_toolkit'] ) : 0;
        if ( $wp_toolkit_enabled ) {
            $wp_toolkit_backups = static::get_cpanel_wp_toolkit_backups( $website_id );
            if ( $wp_toolkit_backups ) {
                Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_cpanel_wp_toolkit_backups', $wp_toolkit_backups );
                Api_Backups_Utility::save_lasttime_backup( $website_id, $wp_toolkit_backups, 'cpanel_wp_toolkit' );
            }
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'true' === $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     *
     * CPanel: Action restore selected backup.
     *
     * @return void
     */
    public function cpanel_action_restore_backup() {

        Api_Backups_Helper::security_nonce( 'cpanel_action_restore_backup' );

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();
        $cpanel_baseurl                    = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username                   = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password                   = $cpanel_authentication_credentials[0]['cpanel_password'];
        $cpanel_site_path                  = $cpanel_authentication_credentials[0]['cpanel_site_path'];

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $backup_id = isset( $_POST['backup_id'] ) ? wp_unslash( $_POST['backup_id'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        // Send Payload & create backup.
        $api_response = static::call_cpanel_api( 'GET', '/execute/Restore/restore_file?backupID=' . $backup_id . '&path=' . $cpanel_site_path . '&overwrite=1', $cpanel_baseurl, $cpanel_username, $cpanel_password );

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( '200' === (string) $api_response['httpCode'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     *
     * CPanel: Action restore selected database backup.
     *
     * @return void
     */
    public function cpanel_action_restore_database_backup() {

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();

        $cpanel_baseurl  = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password = $cpanel_authentication_credentials[0]['cpanel_password'];

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $backup_id   = isset( $_POST['backup_id'] ) ? wp_unslash( $_POST['backup_id'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $backup_path = isset( $_POST['backup_path'] ) ? wp_unslash( $_POST['backup_path'] ) : '';  //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        $backup_full_path = $backup_path . '/' . $backup_id;

        // Send Payload & create backup. https://{host}:{port}/execute/Backup/restore_databases?backup=database_file_name.sql.gz.
        $api_response = static::call_cpanel_api( 'POST', '/execute/Backup/restore_databases?backup=' . $backup_full_path, $cpanel_baseurl, $cpanel_username, $cpanel_password );
        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( '200' === (string) $api_response['httpCode'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     *
     * CPanel: Action restore selected manual backup.
     *
     * @return void
     */
    public function cpanel_action_restore_manual_backup() {

        // Authenticate cPanel account.
        $cpanel_authentication_credentials = static::get_cpanel_authentication_credentials();

        $cpanel_baseurl  = $cpanel_authentication_credentials[0]['cpanel_baseurl'];
        $cpanel_username = $cpanel_authentication_credentials[0]['cpanel_username'];
        $cpanel_password = $cpanel_authentication_credentials[0]['cpanel_password'];

        // Grab website_id & backup_api name from Ajax post.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Grab $_POST data.
            $backup_id   = isset( $_POST['backup_id'] ) ? wp_unslash( $_POST['backup_id'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $backup_path = isset( $_POST['backup_path'] ) ? wp_unslash( $_POST['backup_path'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        /**
         * Output example for api call.:
         * Endpoint: /execute/Backup/restore_files
         * Backup file: backup-1.4.2024_17-45-59_cpanel-user.tar.gz
         * Backup directory: /home/{cpanel-user}
         * Backup full path: /home/{cpanel-user}/backup-1.4.2024_17-45-59_cpanel-user.tar.gz
         *
         * Https://{host}:{port}/execute/Backup/restore_files?backup=/home/{cpanel-user}/backup-1.4.2024_17-45-59_cpanel-user.tar.gz&directory=/home/{cpanel-user}.
         */
        $backup_full_path = $backup_path . '/' . $backup_id;
        $directory        = $backup_path;

        // Send Payload & create backup.
        $api_response     = static::call_cpanel_api( 'POST', '/execute/Backup/restore_files?backup=' . $backup_full_path . '&directory=' . $directory, $cpanel_baseurl, $cpanel_username, $cpanel_password );
        $response_decoded = json_decode( $api_response['response'] );
        $errors           = $response_decoded->errors['0'];

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( '200' === (string) $api_response['httpCode'] && empty( $errors ) ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /*********************************************************************************
     * Kinsta API Methods.
     **********************************************************************************/

    /**
     * Kinsta: action refresh available backups.
     *
     * Refresh available backups for the selected server.
     *
     * @return void
     */
    public function ajax_kinsta_action_refresh_available_backups() {
        Api_Backups_Helper::security_nonce( 'kinsta_action_refresh_available_backups' );
        static::kinsta_action_refresh_available_backups();
        die();
    }

    /**
     * Kinsta: action create backup.
     *
     * Create backup for the selected server.
     *
     * @return void
     */
    public function ajax_kinsta_action_create_backup() {
        Api_Backups_Helper::security_nonce( 'kinsta_action_create_backup' );
        static::kinsta_action_create_backup();
        die();
    }

    /**
     * Kinsta: action delete backup.
     *
     * Create backup for the selected server.
     *
     * @return void
     */
    public function ajax_kinsta_action_delete_backup() {
        Api_Backups_Helper::security_nonce( 'kinsta_action_delete_backup' );
        static::kinsta_action_delete_backup();
        die();
    }

    /**
     * Kinsta: action restore backup.
     *
     * Restore backup for the selected server.
     *
     * @return void
     */
    public function ajax_kinsta_action_restore_backup() {
        Api_Backups_Helper::security_nonce( 'kinsta_action_restore_backup' );
        static::kinsta_action_restore_backup(); // ajax call.
        die();
    }

    /**
     *
     * Kinsta: Authentication.
     *
     * Grab needed authentication credentials for Kinsta API - either from global settings or individual settings.
     *
     * @param int $website_id Website ID.
     *
     * @return array
     */
    public static function get_kinsta_authentication_credentials( $website_id = null ) { //phpcs:ignore -- NOSONAR - complex.

        $kinsta_authentication_credentials = array();

        // Grab website_id & from Ajax post if $website_id is not set.
        if ( empty( $website_id ) ) {
            if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                // Grab : $_POST['data'] submit values.
                $website_id = isset( $_POST['website_id'] ) ? intval( $_POST['website_id'] ) : 0;  //phpcs:ignore -- nonce verified.
            } else {
                // Grab : $_GET['data'] from url if not Ajax call.
                $website_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;  //phpcs:ignore -- nonce verified.
            }
        }

        // Global / Individual check.
        $global_individual_check         = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_enable_kinsta_individual' ) );
        $mainwp_enable_kinsta_individual = isset( $global_individual_check['mainwp_enable_kinsta_individual'] ) ? $global_individual_check['mainwp_enable_kinsta_individual'] : '0';

        if ( 'on' === $mainwp_enable_kinsta_individual ) {
            // Grab cPanel baseurl, username & password.
            $kinsta_baseurl = 'https://api.kinsta.com/v2';

            // Grab Kinsta password.
            $kinsta_api_key = Api_Backups_Utility::get_instance()->get_child_api_key( $website_id, 'kinsta' );

            // Grab Kinsta Account Email.
            $account_email        = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_kinsta_account_email' ) );
            $kinsta_account_email = isset( $account_email['mainwp_kinsta_account_email'] ) ? $account_email['mainwp_kinsta_account_email'] : null;

            // Grab Kinsta Company ID.
            $company_id        = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_kinsta_company_id' ) );
            $kinsta_company_id = isset( $company_id['mainwp_kinsta_company_id'] ) ? $company_id['mainwp_kinsta_company_id'] : null;

            // Grab Kinsta Environment ID.
            $environment_id        = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_kinsta_environment_id' ) );
            $kinsta_environment_id = isset( $environment_id['mainwp_kinsta_environment_id'] ) ? $environment_id['mainwp_kinsta_environment_id'] : null;

        } elseif ( '0' === $mainwp_enable_kinsta_individual ) {
            // Grab Kinsta baseurl, username & password.
            $kinsta_baseurl = 'https://api.kinsta.com/v2';

            // Grab Kinsta password.
            $kinsta_api_key = static::get_kinsta_api_key();

            // Grab Kinsta Account Email.
            $kinsta_account_email = get_option( 'mainwp_kinsta_api_account_email' );

            // Grab Kinsta Company ID.
            $kinsta_company_id = get_option( 'mainwp_kinsta_company_id' );

            // Grab Kinsta Environment ID.
            $environment_id        = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_kinsta_environment_id' ) );
            $kinsta_environment_id = isset( $environment_id['mainwp_kinsta_environment_id'] ) ? $environment_id['mainwp_kinsta_environment_id'] : null;
        }

        // Build array.
        $kinsta_authentication_credentials[] = array(
            'kinsta_baseurl'        => $kinsta_baseurl,
            'kinsta_api_key'        => $kinsta_api_key,
            'kinsta_environment_id' => $kinsta_environment_id,
            'website_id'            => $website_id,
            'kinsta_company_id'     => $kinsta_company_id,
            'kinsta_company_email'  => $kinsta_account_email,
        );

        return $kinsta_authentication_credentials;
    }

    /**
     * Call Kinsta API, Authenticate & perform given method.
     *
     * @param string $method GET|POST|PUT|DELETE.
     * @param string $url API endpoint for the call.
     * @param string $baseurl baseurl.
     * @param string $api_key Kinsta API Key.
     * @param string $action action.
     * @param string $backup_data action.
     *
     * @return array Output from cPanel API.
     */
    public static function call_kinsta_api( $method, $url, $baseurl, $api_key, $action = '', $backup_data = array() ) {

        $curl = curl_init();

        $api_Key = $api_key;
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $baseurl . $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $backup_data,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: application/json',
                    "Authorization: Bearer $api_Key",
                ),
            )
        );

        $resp     = curl_exec( $curl );
        $httpCode = (string) curl_getinfo( $curl, CURLINFO_HTTP_CODE );

        $response['httpCode'] = $httpCode;
        $response['response'] = $resp;

        if ( '202' !== $httpCode && '200' !== $httpCode ) {
            $response['status'] = 'false';
        } else {
            $response['status'] = 'true';
        }

        // Log API call.
        $payload          = "[Status] $httpCode :: [Action] $action :: [EndPoint] $baseurl$url";
        $payload_response = "[Status] $httpCode :: [Action] $action :: [Response] $resp";
        Api_Backups_Utility::log_debug( $payload );
        Api_Backups_Utility::log_debug( $payload_response );

        curl_close( $curl );
        return $response;
    }

    /**
     *
     * Kinsta: Action get User ID.
     *
     * Get the User Id from the given Company ID & Email address.
     *
     * @return array
     */
    public static function kinsta_action_get_user_id() {

        // Authenticate kinsta account.
        $kinsta_authentication_credentials = static::get_kinsta_authentication_credentials();
        $kinsta_baseurl                    = $kinsta_authentication_credentials[0]['kinsta_baseurl'];
        $kinsta_api_key                    = $kinsta_authentication_credentials[0]['kinsta_api_key'];
        $kinsta_company_id                 = $kinsta_authentication_credentials[0]['kinsta_company_id'];
        $kinsta_company_email              = $kinsta_authentication_credentials[0]['kinsta_company_email'];

        $payload_url = '/company/' . $kinsta_company_id . '/users';

        // Send Payload.
        $api_response = static::call_kinsta_api( 'GET', $payload_url, $kinsta_baseurl, $kinsta_api_key, '' );

        // Decode Response.
        $response      = json_decode( $api_response['response'] );
        $company       = $response->company;
        $company_users = $company->users;

        // Grab correct user ID.
        foreach ( $company_users as $user ) {
            if ( $kinsta_company_email === $user->user->email ) {
                $user_id = $user->user->id;
            }
        }

        // Return user id.
        return $user_id;
    }

    /**
     *
     * Kinsta: Action refresh available backups.
     *
     * Save backups to DB for the selected server.
     *
     * @return void
     */
    public static function kinsta_action_refresh_available_backups() {

        // Authenticate kinsta account.
        $kinsta_authentication_credentials = static::get_kinsta_authentication_credentials();
        $kinsta_baseurl                    = $kinsta_authentication_credentials[0]['kinsta_baseurl'];
        $kinsta_api_key                    = $kinsta_authentication_credentials[0]['kinsta_api_key'];
        $kinsta_env_id                     = $kinsta_authentication_credentials[0]['kinsta_environment_id'];
        $website_id                        = $kinsta_authentication_credentials[0]['website_id'];
        $downloadable_backups              = '';

        $action = 'kinsta_action_refresh_available_backups';

        // Grab backup meta.
        $api_response = static::call_kinsta_api( 'GET', '/sites/environments/' . $kinsta_env_id . '/backups', $kinsta_baseurl, $kinsta_api_key, $action );

        if ( 'true' === $api_response['status'] ) {
            $all_backups = $api_response['response'];
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_kinsta_available_backups', $all_backups );
            Api_Backups_Utility::save_lasttime_backup( $website_id, $all_backups, 'kinsta' );

            $action = 'kinsta_action_refresh_available_backups_downloadable';

            $downloadable_backups = static::call_kinsta_api( 'GET', '/sites/environments/' . $kinsta_env_id . '/downloadable-backups', $kinsta_baseurl, $kinsta_api_key, $action );

        } else {
            Api_Backups_Utility::log_debug( 'Kinsta API Error: ' . $api_response['response'] );
        }
        if ( $downloadable_backups ) {
            if ( 'true' === $downloadable_backups['status'] ) {
                $downloadable_backups = $downloadable_backups['response'];
                Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_kinsta_downloadable_backups', $downloadable_backups );
            }
        } else {
            Api_Backups_Utility::log_debug( 'Kinsta API Error: There was an issue while refreshing available backups.' );
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'true' === $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     *
     * Kinsta: Action create manual backup.
     *
     * Create a Plesk Backup.
     *
     * @param bool $resturn_values resturn values.
     * @param int  $site_id website id.
     *
     * @return mixed result.
     */
    public static function kinsta_action_create_backup( $resturn_values = false, $site_id = null ) {

        // Authenticate kinsta account.
        $kinsta_authentication_credentials = static::get_kinsta_authentication_credentials( $site_id );
        $kinsta_baseurl                    = $kinsta_authentication_credentials[0]['kinsta_baseurl'];
        $kinsta_api_key                    = $kinsta_authentication_credentials[0]['kinsta_api_key'];
        $kinsta_env_id                     = $kinsta_authentication_credentials[0]['kinsta_environment_id'];
        $website_id                        = $kinsta_authentication_credentials[0]['website_id'];

        // Grab Child Site options.
        $website = Api_Backups_Helper::get_website_by_id( $website_id );

        // Prepare Backup Payload.
        $backup_data = array(
            'tag' => 'MainWP API Backups - ' . $website['name'],
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & create backup.
        $api_response = static::call_kinsta_api( 'POST', '/sites/environments/' . $kinsta_env_id . '/manual-backups', $kinsta_baseurl, $kinsta_api_key, $backup_data );

        if ( is_array( $api_response ) && isset( $api_response['status'] ) && 'true' === $api_response['status'] ) {
            // Save Timestamp.
            $local_time   = current_datetime();
            $current_time = $local_time->getTimestamp() + $local_time->getOffset();
            Api_Backups_Helper::update_website_option( $website_id, 'mainwp_3rd_party_kinsta_last_backup', $current_time );
        }

        if ( $resturn_values ) {
            return $api_response;
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'true' === $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     *
     * Kinsta: Action create manual backup.
     *
     * Create a Plesk Backup.
     *
     * @param bool $return_values return values.
     *
     * @return array
     */
    public static function kinsta_action_delete_backup( $return_values = false ) {

        $backup_id = isset( $_POST['backup_id'] ) ? wp_unslash( $_POST['backup_id'] ) : ''; //phpcs:ignore -- nonce verified.

        // Authenticate kinsta account.
        $kinsta_authentication_credentials = static::get_kinsta_authentication_credentials();
        $kinsta_baseurl                    = $kinsta_authentication_credentials[0]['kinsta_baseurl'];
        $kinsta_api_key                    = $kinsta_authentication_credentials[0]['kinsta_api_key'];

        $payload_url = '/sites/environments/backups/' . $backup_id;

        // Send Payload & create backup.
        $api_response = static::call_kinsta_api( 'DELETE', $payload_url, $kinsta_baseurl, $kinsta_api_key, '' );

        if ( $return_values ) {
            return $api_response;
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'true' === $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    /**
     *
     * Kinsta: Action restore manual backup.
     *
     * Restore a selected Backup.
     *
     * @param bool $return_values return or not.
     *
     * @return array
     */
    public static function kinsta_action_restore_backup( $return_values = false ) {

        $backup_id = isset( $_POST['backup_id'] ) ? intval( $_POST['backup_id'] ) : 0; //phpcs:ignore -- nonce verified.

        // Authenticate kinsta account.
        $kinsta_authentication_credentials = static::get_kinsta_authentication_credentials();
        $kinsta_baseurl                    = $kinsta_authentication_credentials[0]['kinsta_baseurl'];
        $kinsta_api_key                    = $kinsta_authentication_credentials[0]['kinsta_api_key'];
        $kinsta_env_id                     = $kinsta_authentication_credentials[0]['kinsta_environment_id'];

        $company_user_id = static::kinsta_action_get_user_id();

        $payload_url = '/sites/environments/' . $kinsta_env_id . '/backups/restore';

        // Prepare Backup Payload.
        $backup_data = array(
            'backup_id'        => $backup_id,
            'notified_user_id' => $company_user_id,
        );
        $backup_data = wp_json_encode( $backup_data );

        // Send Payload & create backup.
        $api_response = static::call_kinsta_api( 'POST', $payload_url, $kinsta_baseurl, $kinsta_api_key, '', $backup_data );

        if ( $return_values ) {
            return $api_response;
        }

        // Return AJAX.
        if ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // Store Last Backup timestamp.
            if ( 'true' === $api_response['status'] ) {
                wp_send_json( 'true' );
            } else {
                wp_die( 'false' );
            }
        }
    }

    // END class MainWP_3rdParty_Backup.
}
