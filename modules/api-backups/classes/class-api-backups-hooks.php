<?php
/**
 * MainWP Module API Backups Hooks class.
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

use MainWP\Dashboard\MainWP_Settings_Indicator;

/**
 * Class Api_Backups_Hooks
 */
class Api_Backups_Hooks {

    /**
     * Public static variable to hold the single instance of the class.
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
     * @return Api_Backups_Hooks
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor
     *
     * Runs each time the class is called.
     */
    public function __construct() {
        add_filter( 'mainwp_getsubpages_settings', array( $this, 'add_subpage_menu_settings' ) );
        add_filter( 'mainwp_init_primary_menu_items', array( $this, 'hook_init_primary_menu_items' ), 10, 2 );
        add_filter( 'mainwp_getsubpages_sites', array( &$this, 'managesites_subpage' ), 10, 1 );
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
            'slug'               => 'ManageApiBackups',
            'menu_level'         => 3,
            'menu_rights'        => array(
                'dashboard' => array(
                    'manage_api_backups',
                ),
            ),
            'init_menu_callback' => array( Api_Backups_Admin::class, 'init_menu' ),
        );
        return $items;
    }

    /**
     * Init sub menu manage sites.
     *
     * @param array $subPage Sub pages.
     */
    public function managesites_subpage( $subPage ) {
        $subPage[] = array(
            'title'       => esc_html__( 'API Backups', 'mainwp' ),
            'slug'        => 'ApiBackups',
            'sitetab'     => true,
            'menu_hidden' => true,
            'callback'    => array( Api_Backups_Overview::get_instance(), 'render_individual_tabs' ),
        );
        return $subPage;
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
            'title'    => esc_html__( 'API Backups', 'mainwp' ),
            'slug'     => 'ApiBackups',
            'callback' => array( Api_Backups_Settings::get_instance(), 'render_settings_page' ),
            'class'    => '',
        );
        return $subpages;
    }
}
