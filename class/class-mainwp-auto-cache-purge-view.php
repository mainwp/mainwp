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

    public function init() {
        //add_action( 'admin_init', array( &$this, 'admin_init' ) );
       // add_action( 'mainwp_settings_form_bottom', array( $this, 'your_custom_function_name' ), 10, 9 );
    }

    /**
     * MainWP Dashboard auto cache purge data.
     *
     * Run any time admin is loaded.
     */
    public function admin_init() {
        add_filter( 'mainwp_sync_others_data', array( $this, 'mydashboard_sync_others_data' ), 10, 2 );
        add_action( 'mainwp_settings_form_bottom', array( $this, 'render_cache_control_global_settings' ) );

    }

    public function render_cache_control_global_settings() {
        self::instance()->render_global_settings($website);
    }

    public function handle_cache_control_settings(){
        $auto_cache_purge = ( isset( $_POST['mainwp_auto_purge_cache'] ) ? 1 : 0 );
        MainWP_Utility::update_option( 'mainwp_auto_purge_cache', $auto_cache_purge );
    }


    /**
     * Sync Data with Child Site on Sync.
     *
     * @param $data
     * @param null $website
     * @return array|mixed
     */
    public function mydashboard_sync_others_data( $data, $website = null ) {
        if ( ! is_array( $data ) ) {
            $data = array();
        }

        if ($website->auto_purge_cache === '2') {
            $data['auto_purge_cache'] = get_option( 'mainwp_auto_purge_cache' );
        }else{
            $data['auto_purge_cache'] = $website->auto_purge_cache;
        }
        return $data;
    }

    /**
     * Render Global Auto Cache Purge settings.
     */
    public static function render_global_settings() {
        ?>
            <h3 class="ui dividing header"><?php esc_html_e( 'Cache Control Settings', 'mainwp' ); ?>
            <div class="sub header">Enable this setting to purge all cache after any update.</div></h3>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php echo __( 'Automatically purge cache', 'mainwp' ); ?></label>
                <div class="ten wide column ui toggle checkbox">
                    <input type="checkbox" value="1" name="mainwp_auto_purge_cache" <?php checked( get_option( 'mainwp_auto_purge_cache', 0 ), 1 ); ?> id="mainwp_auto_purge_cache">
                    <label><em><?php echo __( 'Enable to purge all cache after updates.', 'mainwp' ); ?></em></label>
                </div>
            </div>
        <?php
    }

    /**
     * Render Child Site ( edit page ) Auto Cache Purge settings.
     */
    public static function render_child_site_settings($website) {
        ?>
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
        <?php
    }
}
