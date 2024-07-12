<?php
/**
 * MainWP Updates Helper.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates_Helper
 *
 * @package MainWP\Dashboard
 */
class MainWP_Updates_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Gets Class Name.
     *
     * @return object
     */
    public static function get_class_name() {
        return __CLASS__;
    }


    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Updates_Helper
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Method get_roll_items_updates_of_site()
     *
     * @param object $website data.
     *
     * @return array roll items data.
     */
    public function get_roll_items_updates_of_site( $website ) { //phpcs:ignore -- NOSONAR - complex.

        if ( ! is_object( $website ) ) {
            return array();
        }

        if ( property_exists( $website, 'rollback_updates_data' ) ) {
            $saved_roll_items = ! empty( $website->rollback_updates_data ) ? json_decode( $website->rollback_updates_data, true ) : array();
        } else {
            $site_opts        = MainWP_DB::instance()->get_website_options_array( $website, array( 'rollback_updates_data' ) );
            $saved_roll_items = is_array( $site_opts ) && ! empty( $site_opts['rollback_updates_data'] ) ? json_decode( $site_opts['rollback_updates_data'], true ) : array();
        }

        if ( ! is_array( $saved_roll_items ) || empty( $saved_roll_items ) ) {
            return array();
        }

        $plugin_upgrades = json_decode( $website->plugin_upgrades, true );
        $theme_upgrades  = json_decode( $website->theme_upgrades, true );

        $roll_plugins = array();
        $roll_themes  = array();

        if ( ! empty( $plugin_upgrades ) || ! empty( $theme_upgrades ) ) {
            $saved_roll_items = ! empty( $website->rollback_updates_data ) ? json_decode( $website->rollback_updates_data, true ) : array();
            if ( is_array( $saved_roll_items ) ) {

                if ( ! empty( $plugin_upgrades ) && isset( $saved_roll_items['plugin'] ) && is_array( $saved_roll_items['plugin'] ) ) {
                    foreach ( $plugin_upgrades as $slug => $item ) {
                        $last_version = $item['update']['new_version'];

                        if ( isset( $saved_roll_items['plugin'][ $slug ][ $last_version ] ) ) {
                            $roll_plugins[ $slug ] = $saved_roll_items['plugin'][ $slug ][ $last_version ];
                        }
                    }
                }

                if ( ! empty( $theme_upgrades ) && isset( $saved_roll_items['theme'] ) && is_array( $saved_roll_items['theme'] ) ) {
                    foreach ( $saved_roll_items['theme'] as $item ) {
                        foreach ( $theme_upgrades as $slug => $item ) {
                            $last_version = $item['update']['new_version'];

                            if ( isset( $saved_roll_items['theme'][ $slug ][ $last_version ] ) ) {
                                $roll_themes[ $slug ] = $saved_roll_items['theme'][ $slug ][ $last_version ];
                            }
                        }
                    }
                }
            }
        }

        $data = array();

        if ( ! empty( $roll_plugins ) ) {
            $data['plugins'] = $roll_plugins;
        }
        if ( ! empty( $roll_themes ) ) {
            $data['themes'] = $roll_themes;
        }
        return $data;
    }

    /**
     * Method get_roll_msg().
     *
     * @param array  $item update item.
     * @param bool   $with_icon get msg with icon.
     * @param string $msg_type  default|notice.
     *
     * @return string icons.
     */
    public static function get_roll_msg( $item = array(), $with_icon = false, $msg_type = 'default' ) {

        if ( 'notice' === $msg_type ) {
            $msg = __( 'This version failed a previous update and was rolled back. Proceed with caution.', 'mainwp' );
        } else {
            if ( ! is_array( $item ) || empty( $item['name'] ) ) {
                return '';
            }
            $name        = isset( $item['name'] ) ? $item['name'] : '';
            $old_version = isset( $item['old_version'] ) ? $item['old_version'] : '';
            $version     = isset( $item['version'] ) ? $item['version'] : '';
            $msg         = sprintf( __( 'WordPress detected an error with %s and rolled it back from version %s to version %s to ensure site stability.', 'mainwp' ), $name, $version, $old_version );
        }

        if ( ! $with_icon ) {
            return $msg;
        }

        return static::get_roll_icon( $msg );
    }

    /**
     * Method get_roll_icon().
     *
     * @param string $ttip tooltip text.
     * @param bool   $icon_only get icon only.
     *
     * @return string icons.
     */
    public static function get_roll_icon( $ttip = '', $icon_only = false ) {
        $icon = '<i class="orange undo icon"></i>';
        if ( $icon_only ) {
            return $icon;
        }
        return ' <span data-inverted="" data-position="right center" data-tooltip="' . esc_html( $ttip ) . '">' . $icon . '</span>';
    }


    /**
     * Method get_roll_update_plugintheme_items().
     *
     * @param string $item_type plugin|theme.
     * @param array  $roll_data roll update data.
     *
     * @return array roll items.
     */
    public static function get_roll_update_plugintheme_items( $item_type, $roll_data ) {

        $rollItems = ! empty( $roll_data ) ? json_decode( $roll_data, true ) : array();

        if ( is_array( $rollItems ) ) {
            if ( 'plugin' === $item_type ) {
                return ! empty( $rollItems['plugin'] ) && is_array( $rollItems['plugin'] ) ? $rollItems['plugin'] : array();
            } elseif ( 'theme' === $item_type ) {
                return ! empty( $rollItems['theme'] ) && is_array( $rollItems['theme'] ) ? $rollItems['theme'] : array();
            }
        }

        return array();
    }
}
