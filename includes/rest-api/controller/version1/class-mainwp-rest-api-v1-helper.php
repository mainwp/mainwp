<?php
/**
 * MainWP REST API Helper
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 * @author Martin Gibson
 */

namespace MainWP\Dashboard;

/**
 * Class Rest_Api
 */
class Rest_Api_V1_Helper { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;


    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return static::$instance
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method handle_site_update_item()
     *
     * @param int    $website_id website id.
     * @param string $update_type result update.
     * @param array  $result result update.
     *
     * @return array result
     */
    public function handle_site_update_item( $website_id, $update_type, $result ) { //phpcs:ignore -- NOSONAR - complex.

        $website = false;

        if ( $website_id ) {
            $website = MainWP_DB::instance()->get_website_by_id( $website_id, false, array( 'rollback_updates_data' ) ); // to fix loading premium_upgrades.
        }

        if ( $website && is_array( $result ) ) {

            $return_results = array( 'updates_info' => array() );

            // logging feature.
            $_type = '';
            if ( 'plugin' === $update_type || 'theme' === $update_type ) {
                $_type = $update_type;
            } elseif ( 'translation' === $update_type ) {
                $_type = 'trans';
            }

            if ( isset( $result['upgrades_error'] ) ) {
                foreach ( $result['upgrades_error'] as $k => $v ) {
                    $return_results['result_error'][ rawurlencode( $k ) ] = esc_html( $v );
                    $return_results['updates_info'][ rawurlencode( $k ) ] = array(
                        'success' => 0,
                        'error'   => esc_html( $v ),
                    );
                }
            }

            if ( ! empty( $_type ) && isset( $result['other_data'] ) ) { // ok.
                $output_array = $result['other_data']; // updated_data: plugins,themes,trans.
                mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions( $website, 'updated', $output_array, $_type );
                if ( is_array( $output_array ) ) {
                    $updated_data = isset( $output_array['updated_data'] ) ? $output_array['updated_data'] : array();
                    if ( is_array( $updated_data ) ) {

                        $saved_roll_items = ! empty( $website->rollback_updates_data ) ? json_decode( $website->rollback_updates_data, true ) : array();
                        if ( ! is_array( $saved_roll_items ) ) {
                            $saved_roll_items = array();
                        }

                        $update = false;
                        foreach ( $updated_data as $item ) {

                            if ( ! is_array( $item ) ) {
                                continue;
                            }

                            $success     = ! empty( $item['success'] ) ? 1 : 0;
                            $slug        = isset( $item['slug'] ) ? $item['slug'] : '';
                            $name        = isset( $item['name'] ) ? $item['name'] : '';
                            $version     = isset( $item['version'] ) ? $item['version'] : '';
                            $old_version = isset( $item['old_version'] ) ? $item['old_version'] : '';

                            if ( ! empty( $slug ) ) {
                                $return_results['updates_info'][ esc_html( $slug ) ] = array(
                                    'success'     => $success,
                                    'name'        => $name,
                                    'old_version' => $old_version,
                                    'version'     => $version,
                                );
                            }

                            if ( ! empty( $item ) && ! empty( $slug ) && ! empty( $item['rollback'] ) ) {
                                $msg = MainWP_Updates_Helper::get_roll_msg( $item );
                                $return_results['result_error'][ rawurlencode( $slug ) ] = esc_html( $msg );

                                if ( ! empty( $version ) ) {
                                    if ( ! isset( $saved_roll_items[ $_type ] ) ) {
                                        $saved_roll_items[ $_type ] = array();
                                    }

                                    $saved_item = array(
                                        'name'        => $name,
                                        'old_version' => $old_version,
                                        'version'     => $version,
                                        'created'     => time(),
                                    );

                                    if ( ! empty( $item['error'] ) ) {
                                        $saved_item['error'] = $item['error'];
                                    }

                                    $saved_roll_items[ $_type ][ $slug ][ $version ] = $saved_item;
                                    $update = true;
                                }
                            } elseif ( ! empty( $item ) && ! empty( $slug ) && ! empty( $success ) ) {
                                if ( ! empty( $version ) && isset( $saved_roll_items[ $_type ][ $slug ][ $version ] ) ) {
                                    unset( $saved_roll_items[ $_type ][ $slug ][ $version ] );
                                    $update = true;
                                }
                            }
                        }
                        if ( $update ) {
                            MainWP_DB::instance()->update_website_option( $website, 'rollback_updates_data', wp_json_encode( $saved_roll_items ) );
                        }
                    }
                }
            }

            return $return_results;
        }

        return array();
    }
}
// End of class.
