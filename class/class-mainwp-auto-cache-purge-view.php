<?php
/**
 * MainWP Auto Cache Purge settings view
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Auto_Cache_Purge_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Auto_Cache_Purge_View {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

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
     * Public static variable to hold Subpages information.
     *
     * @var array $subPages
     */
    public static $subPages;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @static
     * @return MainWP_Auto_Cache_Purge_View
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * MainWP_Bulk_Post constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {

    }

    /**
     *  Instantiate Hooks for the Settings Page.
     *  Called from class-mainwp-system.php line 691.
     */
    public function init() {
        self::instance()->admin_init();
    }

    /**
     * Method admin_init() initiated by init()
     *
     * Instantiate Hooks for the page.
     */
    public function admin_init() {


        add_filter( 'mainwp_sync_others_data', array( $this, 'cache_control_sync_others_data' ), 10, 2 );
        add_filter( 'mainwp_page_navigation', array( $this, 'cache_control_navigation' ) );
        add_filter( 'mainwp_sitestable_getcolumns', array( $this,'cache_control_sitestable_column' ), 10, 1 );
        add_filter( 'mainwp_sitestable_item', array( $this,'cache_control_sitestable_item' ), 10, 1 );


    }

    /**
     * Cache Control page Header Navigation.
     *
     * @param $subPages $subPages is an Array of subpages.
     * @return array|mixed
     */
    public function cache_control_navigation( $subPages ){
        $currentScreen =  get_current_screen();

        // Only show on these subpages.
        $show = array(
            "mainwp_page_Settings",
            "mainwp_page_SettingsAdvanced",
            "mainwp_page_SettingsEmail",
            "mainwp_page_MainWPTools",
            "mainwp_page_RESTAPI",
            "mainwp_page_cache-control"
        );
        if ( in_array( $currentScreen->id, $show ) ) {
            if ( isset( $subPages ) && is_array( $subPages ) ) {
                $subPages[] = array(
                    'title' => __('Cache Control', 'mainwp'),
                    'href' => 'admin.php?page=cache-control',
                    'active' => ( 'cache-control' == $currentScreen ) ? true : false,
                );
            }
        }
        return $subPages;
    }

    /**
     * Force Re-sync after Child Site settings save.
     */
    public function cache_control_settings_sync( $website ){
        $website = MainWP_DB::instance()->get_website_by_id( $website->id );

        return MainWP_Sync::sync_website( $website, $pForceFetch = true );
    }

    /**
     * Sync Data with Child Site on Sync.
     *
     * @param $data
     * @param null $website
     * @return array|mixed
     */
    public function cache_control_sync_others_data( $data, $website = null ) {

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        if ( $website->auto_purge_cache === '2' ) {
            $data['auto_purge_cache'] = get_option( 'mainwp_auto_purge_cache' );
        }else{
            $data['auto_purge_cache'] = $website->auto_purge_cache;
        }
        $newData = json_encode($data['mainwp_cache_control_last_purged']);
        $newValues = array(
            'mainwp_cache_control_last_purged' => $newData, //current_time('mysql')
        );

        MainWP_DB::instance()->update_website_values( $website->id, $newValues );

        return $data;

    }

    /**
    * Handle Cache Control form $_POST.
    *
    * This method runs every time the page is loaded.
    */
    public function handle_cache_control_post(){
        if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'cache-control' ) ) {

            $auto_cache_purge = ( isset( $_POST['mainwp_auto_purge_cache'] ) ? 1 : 0 );
            MainWP_Utility::update_option( 'mainwp_auto_purge_cache', $auto_cache_purge );


            return true;
        }
        return false;
    }

    /**
     * Handle Cache Control form $_POST for Child Site edit page.
     */
    public function handle_cache_control_child_site_settings( $website ){

        $updated = false;
        if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'cache-control' ) ) {

            if ( mainwp_current_user_have_right('dashboard', 'edit_sites') ) {

                $auto_purge_cache = isset( $_POST['mainwp_auto_purge_cache'] ) ? intval( $_POST['mainwp_auto_purge_cache'] ) : 2;
                if ( 2 < $auto_purge_cache ) {
                    $auto_purge_cache = 2;
                }

                $newValues = array(
                    'auto_purge_cache' => $auto_purge_cache,
                );

                MainWP_DB::instance()->update_website_values( $website->id, $newValues );

                // Force Re-sync Child Site Data.
                self::instance()->cache_control_settings_sync( $website );

                $updated = true;
            }
        }
        return $updated;
    }


    /**
     * Sites Table Columns.
     */
    function cache_control_sitestable_column( $columns ) {
        $columns['mainwp_cache_control_last_purged'] = "Cache Last Purged";
        return $columns;
    }


    public function cache_control_sitestable_item( $item ){

            $website = MainWP_DB::instance()->get_website_by_id( $item['id'], true );


            if ( property_exists( $website, 'mainwp_cache_control_last_purged' ) && !empty(( $website->mainwp_cache_control_last_purged )) ) {
                $item['mainwp_cache_control_last_purged'] = $website->mainwp_cache_control_last_purged;
            } else {
                $item['mainwp_cache_control_last_purged'] = 'Never Purged';
            }
            return $item;
    }

    /**
     * Render Global Cache Control settings.
     */
    public static function render_global_settings( $updated ) {
        if ( ! mainwp_current_user_have_right( 'admin', 'manage_dashboard_settings' ) ) {
            mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );
            return;
        }

        ?>
            <div id="mainwp-cache-control-settings" class="ui segment">
                <?php if ( $updated ) : ?>
                    <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
                <?php endif; ?>

                <h3 class="ui dividing header"><?php esc_html_e( 'Cache Control Settings', 'mainwp' ); ?>
                <div class="sub header">Enable this setting to purge all cache after any update.</div></h3>
                <div class="ui form">
                    <form method="POST" action="admin.php?page=cache-control">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'cache-control' ); ?>" />
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php echo __( 'Automatically purge cache', 'mainwp' ); ?></label>
                            <div class="ten wide column ui toggle checkbox">
                                <input type="checkbox" value="1" name="mainwp_auto_purge_cache" <?php checked( get_option( 'mainwp_auto_purge_cache', 0 ), 1 ); ?> id="mainwp_auto_purge_cache">
                                <label><em><?php echo __( 'Enable to purge all cache after updates.', 'mainwp' ); ?></em></label>
                                <em><?php echo __( 'You must Sync Dashboard with Child Sites after saving these settings.', 'mainwp' ); ?></em>
                            </div>
                        </div>
                        <div class="ui divider"></div>
                        <input type="submit" name="submit" id="submit" class="ui green big button right floated" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                        <div style="clear:both"></div>
                    </form>
                </div>
            </div>
        <?php
    }

    /**
     * Render Child Site ( edit page ) Cache Control settings.
     */
    public static function render_child_site_settings( $websiteid, $updated ) {
        MainWP_Manage_Sites::render_header( 'cache-control' );
        if ( ! mainwp_current_user_have_right( 'admin', 'manage_dashboard_settings' ) ) {
            mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );
            return;
        }

        // Grab updated Child Site object.
        $website = MainWP_DB::instance()->get_website_by_id( $websiteid );

        ?>
        <div id="mainwp-cache-control-settings" class="ui segment">
            <?php if ( $updated ) : ?>
                <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
            <?php endif; ?>

            <h3 class="ui dividing header"><?php esc_html_e( 'Cache Control Settings', 'mainwp' ); ?>
                <div class="sub header">Enable this setting to purge all cache after any update.</div></h3>
            <div class="ui form">
                <form method="POST" action="admin.php?page=managesites&cacheControlId=<?php echo $website->id ?>" >
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'cache-control' ); ?>" />
                    <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Automatically purge cache', 'mainwp' ); ?></label>
                        <div class="ui six wide column">
                            <select class="ui dropdown" id="mainwp_auto_purge_cache" name="mainwp_auto_purge_cache">
                                <option <?php echo ( 1 == $website->auto_purge_cache ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'Yes', 'mainwp' ); ?></option>
                                <option <?php echo ( 0 == $website->auto_purge_cache ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'No', 'mainwp' ); ?></option>
                                <option <?php echo ( 2 == $website->auto_purge_cache ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
                            </select>
                            <label><em><?php echo __( 'Enable to purge all cache after updates.', 'mainwp' ); ?></em></label>
                        </div>
                    </div>

                    <div class="ui divider"></div>
                    <input type="submit" name="submit" id="submit" class="ui green big button right floated" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                    <div style="clear:both"></div>
                </form>
            </div>
        </div>
    <?php
        MainWP_Manage_Sites::render_footer( 'cache-control' );
    }
}
