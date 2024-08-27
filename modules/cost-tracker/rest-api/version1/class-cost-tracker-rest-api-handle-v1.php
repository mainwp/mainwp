<?php
/**
 * MainWP Module Cost Tracker Rest Api class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;

/**
 * Class Cost_Tracker_Rest_Api_Handle_V1
 */
class Cost_Tracker_Rest_Api_Handle_V1 {

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
     * Method valid_api_request_data_by()
     *
     * @param string $by By.
     * @param string $value Value.
     *
     * @return mixed Result.
     */
    public function valid_api_request_data_by( $by, $value ) {

        $error = '';
        if ( 'client_id' === $by ) {
            $client_id = intval( $value );
            $client    = false;
            if ( ! empty( $client_id ) ) {
                $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
            }

            if ( empty( $client ) ) {
                $error = esc_html__( 'Invalid Client ID or Client not found. Please try again.', 'mainwp' );
            }
        } elseif ( 'site_id' === $by ) {
            $site_id = intval( $value );
            $site    = false;
            if ( ! empty( $site_id ) ) {
                $site = MainWP_DB::instance()->get_website_by_id( $site_id );
            }
            if ( empty( $site ) ) {
                $error = esc_html__( 'Invalid Site ID or Site not found. Please try again.', 'mainwp' );
            }
        }

        $result = array();
        if ( ! empty( $error ) ) {
            $result['ERROR'] = $error;
        }
        return $result;
    }

    /**
     * Method prepare_api_costs_data()
     *
     * Handle get all costs.
     *
     * @param array $costs costs.
     */
    public function prepare_api_costs_data( $costs ) {
        if ( ! is_array( $costs ) ) {
            $costs = array();
        }

        $fields = array(
            'id',
            'name',
            'url',
            'type',
            'product_type',
            'slug',
            'license_type',
            'cost_status',
            'payment_method',
            'price',
            'renewal_type',
            'last_renewal',
            'next_renewal',
            'last_alert', // to support assistant extension.
            'sites',
            'groups',
            'clients',
            'note',
        );

        $data = array();
        if ( ! empty( $costs ) ) {
            foreach ( $costs as $cost ) {
                $item = array();
                foreach ( $fields as $field ) {
                    $item[ $field ] = $this->get_cost_field_value( $cost, $field );
                }
                $data[ $cost->id ] = $item;
            }
        }
        return $data;
    }


    /**
     * Method get_cost_field_value().
     *
     * @param object $cost  Object containing the cost data.
     * @param string $field Data cost field.
     *
     * @return string value.
     */
    public function get_cost_field_value( $cost, $field ) { //phpcs:ignore -- NOSONAR - complex method.
        $value = '';
        switch ( $field ) {
            case 'id':
                $value = intval( $cost->id );
                break;
            case 'name':
                $value = esc_html( $cost->name );
                break;
            case 'url':
                $value = esc_html( $cost->url );
                break;
            case 'type':
                $value = esc_html( $cost->type );
                break;
            case 'product_type':
                $value = esc_html( $cost->product_type );
                break;
            case 'slug':
                $value = esc_html( $cost->slug );
                break;
            case 'license_type':
                $value = esc_html( $cost->license_type );
                break;
            case 'cost_status':
                $value = esc_html( $cost->cost_status );
                break;
            case 'payment_method':
                $value = esc_html( $cost->payment_method );
                break;
            case 'price':
                $value = esc_html( $cost->price );
                break;
            case 'renewal_type':
                $value = esc_html( $cost->renewal_type );
                break;
            case 'last_renewal':
                $value = ! empty( $cost->last_renewal ) ? esc_html( gmdate( 'Y-m-d H:i:s', (int) $cost->last_renewal ) ) : 0;
                break;
            case 'next_renewal':
                $value = ! empty( $cost->next_renewal ) ? esc_html( gmdate( 'Y-m-d H:i:s', (int) $cost->next_renewal ) ) : 0;
                break;
            case 'last_alert':
                $value = ! empty( $cost->last_alert ) ? esc_html( gmdate( 'Y-m-d H:i:s', (int) $cost->last_alert ) ) : 0;
                break;
            case 'sites':
                $value = esc_html( $cost->sites );
                break;
            case 'groups':
                $value = esc_html( $cost->groups );
                break;
            case 'clients':
                $value = esc_html( $cost->clients );
                break;
            case 'note':
                $value = apply_filters( 'mainwp_escape_content', $cost->note );
                break;
            default:
                $value = 'N/A';
                break;
        }
        return $value;
    }
}

// End of class.
