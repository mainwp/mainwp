<?php
/**
 * REST Controller
 *
 * This class extend `WP_REST_Controller` in order to include /batch endpoint
 * for almost all endpoints in MainWP REST API.
 *
 * It's required to follow "Controller Classes" guide before extending this class:
 * <https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/>
 *
 * NOTE THAT ONLY CODE RELEVANT FOR MOST ENDPOINTS SHOULD BE INCLUDED INTO THIS CLASS.
 *
 * @class   MainWP_REST_Controller
 * @package MainWP\Dashboard
 * @see     https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Abstract Rest Controller Class
 *
 * @package MainWP\Dashboard
 * @extends  WP_REST_Controller
 * @version  5.2
 */
abstract class MainWP_REST_Controller extends WP_REST_Controller { //phpcs:ignore -- NOSONAR - maximumMethodThreshold.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.
    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'mainwp/v2';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = '';

    /**
     * Used to cache computed return fields.
     *
     * @var null|array
     */
    private $_fields = null; //phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

    /**
     * Used to verify if cached fields are for correct request object.
     *
     * @var null|WP_REST_Request
     */
    private $_request = null; //phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

    /**
     *
     * Add the schema from additional fields to an schema array.
     *
     * The type of object is inferred from the passed schema.
     *
     * @param array $schema Schema array.
     *
     * @return array
     */
    protected function add_additional_fields_schema( $schema ) {
        return $schema;
    }



    /**
     * Get site item by id or domain.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|Object Item.
     */
    public function get_site_item( $request ) {
        $route = $request->get_route();
        if ( MainWP_Utility::string_ends_by( $route, '/batch' ) ) {
            $by    = 'id';
            $value = $request['id'];
        } else {
            $value = $request['id_domain'];
            $by    = 'domain';
            if ( is_numeric( $value ) ) {
                $by = 'id';
            } else {
                $value = urldecode( $value );
            }
        }
        return $this->get_site_by( $by, $value );
    }


    /**
     * Get site by.
     *
     * @param  string $by Get by.
     * @param  mixed  $value Site id or domain.
     * @param  array  $args args.
     *
     * @return array|mixed Response data, ready for insertion into collection data.
     */
    public function get_client_by( $by, $value, $args = array() ) {
        if ( 'id' === $by ) {
            $_by = 'client_id';
        } elseif ( 'email' === $by ) {
            $_by = 'client_email';
        }
        $client = MainWP_DB_Client::instance()->get_wp_client_by( $_by, $value );
        if ( empty( $client ) ) {
            return $this->get_rest_data_error( $by, 'client' );
        }
        return $client;
    }

    /**
     * Compatibility functions for WP 5.5, since custom types are not supported anymore.
     * See @link https://core.trac.wordpress.org/changeset/48306
     *
     * @param string $method Optional. HTTP method of the request.
     *
     * @return array Endpoint arguments.
     */
    public function get_endpoint_args_for_item_schema( $method = WP_REST_Server::CREATABLE ) {

        $endpoint_args = $this->parent_get_endpoint_args_for_item_schema( $method );

        if ( false === strpos( WP_REST_Server::EDITABLE, $method ) ) {
            return $endpoint_args;
        }

        return $endpoint_args;
    }

    /**
     * Retrieves an array of endpoint arguments from the item schema for the controller.
     *
     * @uses rest_get_endpoint_args_for_schema()
     * @param string $method Optional. HTTP method of the request.
     * @return array Endpoint arguments.
     */
    public function parent_get_endpoint_args_for_item_schema( $method = \WP_REST_Server::CREATABLE ) {
        $schema        = $this->get_item_schema();
        $endpoint_args = rest_get_endpoint_args_for_schema( $schema, $method );
        $endpoint_args = $this->remove_arg_options( $endpoint_args );
        return $endpoint_args;
    }

    /**
     * Recursive removal of arg_options.
     *
     * @param array $properties Schema properties.
     */
    protected function remove_arg_options( $properties ) {
        return array_map(
            function ( $property ) {
                if ( isset( $property['properties'] ) ) {
                    $property['properties'] = $this->remove_arg_options( $property['properties'] );
                } elseif ( isset( $property['items']['properties'] ) ) {
                    $property['items']['properties'] = $this->remove_arg_options( $property['items']['properties'] );
                }
                unset( $property['arg_options'] );
                return $property;
            },
            (array) $properties
        );
    }


    /**
     * Get normalized rest base.
     *
     * @return string
     */
    protected function get_normalized_rest_base() {
        return preg_replace( '/\(.*\)\//i', '', $this->rest_base );
    }

    /**
     * Check batch limit.
     *
     * @param array $items Request items.
     * @return bool|WP_Error
     */
    protected function check_batch_limit( $items ) { //phpcs:ignore -- NOSONAR - complex.
        $limit = apply_filters( 'mainwp_rest_batch_items_limit', 100, $this->get_normalized_rest_base() );
        $total = 0;

        if ( ! empty( $items['create'] ) && is_countable( $items['create'] ) ) {
            $total += count( $items['create'] );
        }

        if ( ! empty( $items['update'] ) && is_countable( $items['update'] ) ) {
            $total += count( $items['update'] );
        }

        if ( ! empty( $items['delete'] ) && is_countable( $items['delete'] ) ) {
            $total += count( $items['delete'] );
        }

        if ( ! empty( $items['sync'] ) && is_countable( $items['sync'] ) ) {
            $total += count( $items['sync'] );
        }

        if ( ! empty( $items['reconnect'] ) && is_countable( $items['reconnect'] ) ) {
            $total += count( $items['reconnect'] );
        }

        if ( ! empty( $items['disconnect'] ) && is_countable( $items['disconnect'] ) ) {
            $total += count( $items['disconnect'] );
        }

        if ( ! empty( $items['suspend'] ) && is_countable( $items['suspend'] ) ) {
            $total += count( $items['suspend'] );
        }

        if ( ! empty( $items['check'] ) && is_countable( $items['check'] ) ) {
            $total += count( $items['check'] );
        }

        if ( ! empty( $items['remove'] ) && is_countable( $items['remove'] ) ) {
            $total += count( $items['remove'] );
        }

        if ( ! empty( $items['security'] ) && is_countable( $items['security'] ) ) {
            $total += count( $items['security'] );
        }

        if ( ! empty( $items['plugins'] ) && is_countable( $items['plugins'] ) ) {
            $total += count( $items['plugins'] );
        }

        if ( ! empty( $items['themes'] ) && is_countable( $items['themes'] ) ) {
            $total += count( $items['themes'] );
        }

        if ( ! empty( $items['non-mainwp-changes'] ) && is_countable( $items['non-mainwp-changes'] ) ) {
            $total += count( $items['non-mainwp-changes'] );
        }

        if ( $total > $limit ) {
            /* translators: %s: items limit */
            return new WP_Error( 'mainwp_rest_request_entity_too_large', sprintf( __( 'Unable to accept more than %s items for this request.', 'mainwp' ), $limit ), array( 'status' => 413 ) );
        }

        return true;
    }


    /**
     * Method get_validate_args_params().
     *
     * @param string $slug to validate args.
     * @return array validate args info.
     */
    public function get_validate_args_params( $slug ) {

        switch ( $slug ) {
            case 'get_sites':
                return array(
                    'status' => array( 'any', 'connected', 'disconnected', 'suspended', 'available_update' ),
                );
            case 'site_plugins':
            case 'site_themes':
                return array(
                    'status' => array( 'any', 'active', 'inactive' ),
                );
            case 'get_updates':
                return array(
                    'type' => array( 'wp', 'plugins', 'themes', 'translations' ),
                );
            case 'get_costs':
                return array(
                    'type' => array( 'any', 'subscription', 'lifetime' ),
                );
            default:
                break;
        }
        return false;
    }

    /**
     * Method validate_get_items_args().
     *
     * @param array $args args request.
     * @param array $valid_args Valid args array.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function validate_rest_args( $args, $valid_args ) { //phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $args ) ) {
            return $args;
        }

        if ( empty( $valid_args ) || ! is_array( $valid_args ) ) {
            return $args;
        }

        foreach ( $valid_args as $name => $valid_values ) {
            if ( ! empty( $args[ $name ] ) ) {
                $list_values = wp_parse_list( $args[ $name ] );
                if ( ! empty( $list_values ) && is_array( $valid_values ) ) {
                    $filtered_values = array_map(
                        function ( $val ) use ( $valid_values ) {
                            return in_array( $val, $valid_values ) ? $val : '';
                        },
                        $list_values
                    );
                    $filtered_values = array_filter(
                        $filtered_values,
                        function ( $val ) {
                            return '' !== $val;
                        }
                    );
                    if ( empty( $filtered_values ) && ! empty( $list_values ) ) {
                        return new WP_Error(
                            'mainwp_rest_invalid_param',
                            sprintf( __( 'The %s argument should be: %s', 'mainwp' ), $name, implode( ',', $valid_values ) ),
                            array( 'status' => 400 )
                        );
                    }
                }
            }
        }
        return $args;
    }

    /**
     * Prepare objects query.
     *
     * @since  5.2
     * @param  WP_REST_Request $request Full details about the request.
     * @param  string          $type Object type.
     *
     * @return array
     */
    protected function prepare_objects_query( $request, $type = 'object' ) {
        $args = array();
        if ( ! empty( $request['offset'] ) ) {
            $args['offset'] = $request['offset'];
        }

        if ( ! empty( $request['order'] ) ) {
            $args['order'] = $request['order'];
        }

        if ( ! empty( $request['orderby'] ) ) {
            $args['orderby'] = $request['orderby'];
        }

        if ( ! empty( $request['page'] ) ) {
            $args['paged'] = $request['page'];
        } elseif ( ! empty( $request['paged'] ) ) {
            $args['paged'] = $request['paged']; // compatible.
        }

        if ( ! empty( $request['per_page'] ) ) {
            $args['items_per_page'] = $request['per_page'];
        }

        if ( ! empty( $request['slug'] ) ) {
            $args['slug'] = $request['slug'];
        }

        if ( ! empty( $request['search'] ) ) {
            $args['s'] = $request['search'];
        }

        if ( ! empty( $request['type'] ) ) {
            $args['type'] = $request['type'];
        }

        if ( ! empty( $request['status'] ) ) {
            $args['status'] = $request['status'];
        }

        if ( ! empty( $request['exclude'] ) ) {
            $args['exclude'] = $request['exclude'];
        }

        if ( ! empty( $request['include'] ) ) {
            $args['include'] = $request['include'];
        }

        if ( ! empty( $request['must_use'] ) ) {
            $args['must_use'] = $request['must_use'];
        }

        if ( ! empty( $request['must_use'] ) ) {
            $args['must_use'] = $request['must_use'];
        }

        $args['fields'] = $this->get_fields_for_response( $request );

        /**
         * Filter the query arguments for a request.
         *
         * Enables adding extra arguments or setting defaults for a post
         * collection request.
         *
         * @param array           $args    Key value array of query var to query value.
         * @param WP_REST_Request $request The request used.
         */
        $args = apply_filters( "mainwp_rest_{$type}_object_query", $args, $request );

        return $args;
    }


    /**
     * Get site by.
     *
     * @param  string $by Get by.
     * @param  mixed  $value Site id or domain.
     * @param  array  $args args.
     *
     * @return array|mixed Response data, ready for insertion into collection data.
     */
    public function get_site_by( $by, $value, $args = array() ) {
        $site         = false;
        $selectgroups = ! empty( $args['with_tags'] );

        if ( 'id' === $by ) {
            $site_id = intval( $value );
        } elseif ( 'domain' === $by ) {
            $site = MainWP_DB::instance()->get_websites_by_url( $value );
            if ( empty( $site ) ) {
                return $this->get_rest_data_error( 'domain', 'site' );
            }
            $site    = current( $site );
            $site_id = $site->id;
        }

        $site = MainWP_DB::instance()->get_website_by_id( $site_id, $selectgroups );
        if ( empty( $site ) ) {
            return $this->get_rest_data_error( 'id', 'site' );
        }
        return $site;
    }


    /**
     * Bulk create, update and delete items.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return array Of WP_Error or WP_REST_Response.
     */
    public function batch_items( $request ) { //phpcs:ignore -- NOSONAR complex function.
        /**
         * REST Server
         *
         * @var WP_REST_Server $wp_rest_server
         */
        global $wp_rest_server;

        // Get the request params.
        $items    = array_filter( $request->get_params() );
        $query    = $request->get_query_params();
        $response = array();

        // Check batch limit.
        $limit = $this->check_batch_limit( $items );
        if ( is_wp_error( $limit ) ) {
            return $limit;
        }

        if ( ! empty( $items['create'] ) ) {
            foreach ( $items['create'] as $item ) {
                $_item = new WP_REST_Request( 'POST', $request->get_route() );

                // Default parameters.
                $defaults = array();
                $schema   = $this->get_public_item_schema();
                foreach ( $schema['properties'] as $arg => $options ) {
                    if ( isset( $options['default'] ) ) {
                        $defaults[ $arg ] = $options['default'];
                    }
                }
                $_item->set_default_params( $defaults );

                // Set request parameters.
                $_item->set_body_params( $item );

                // Set query (GET) parameters.
                $_item->set_query_params( $query );

                $_response = $this->create_item( $_item );

                if ( is_wp_error( $_response ) ) {
                    $response['create'][] = array(
                        'id'    => 0,
                        'error' => array(
                            'code'    => $_response->get_error_code(),
                            'message' => $_response->get_error_message(),
                            'data'    => $_response->get_error_data(),
                        ),
                    );
                } else {
                    $response['create'][] = $wp_rest_server->response_to_data( $_response, '' );
                }
            }
        }

        if ( ! empty( $items['update'] ) ) {
            foreach ( $items['update'] as $item ) {
                $_item = new WP_REST_Request( 'PUT', $request->get_route() );
                $_item->set_body_params( $item );
                $_response = $this->update_item( $_item );

                if ( is_wp_error( $_response ) ) {
                    $response['update'][] = array(
                        'id'    => $item['id'],
                        'error' => array(
                            'code'    => $_response->get_error_code(),
                            'message' => $_response->get_error_message(),
                            'data'    => $_response->get_error_data(),
                        ),
                    );
                } else {
                    $response['update'][] = $wp_rest_server->response_to_data( $_response, '' );
                }
            }
        }

        if ( ! empty( $items['delete'] ) ) {
            foreach ( $items['delete'] as $id ) {
                $id = (int) $id;

                if ( 0 === $id ) {
                    continue;
                }

                $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                $_item->set_query_params(
                    array(
                        'id'    => $id,
                        'force' => true,
                    )
                );
                $_response = $this->delete_item( $_item );

                if ( is_wp_error( $_response ) ) {
                    $response['delete'][] = array(
                        'id'    => $id,
                        'error' => array(
                            'code'    => $_response->get_error_code(),
                            'message' => $_response->get_error_message(),
                            'data'    => $_response->get_error_data(),
                        ),
                    );
                } else {
                    $response['delete'][] = $wp_rest_server->response_to_data( $_response, '' );
                }
            }
        }

        $route = $request->get_route();
        if ( MainWP_Utility::string_ends_by( $route, '/sites/batch' ) ) {
            if ( ! empty( $items['sync'] ) ) {
                foreach ( $items['sync'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->sync_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['sync'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['sync'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['reconnect'] ) ) {
                foreach ( $items['reconnect'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->reconnect_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['reconnect'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['reconnect'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['disconnect'] ) ) {
                foreach ( $items['disconnect'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->disconnect_site( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['disconnect'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['disconnect'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['suspend'] ) ) {
                foreach ( $items['suspend'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->suspend_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['suspend'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['suspend'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['check'] ) ) {
                foreach ( $items['check'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->check_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['check'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['check'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['remove'] ) ) {
                foreach ( $items['remove'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->delete_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['remove'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['remove'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['security'] ) ) {
                foreach ( $items['security'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->security_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['security'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['security'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['plugins'] ) ) {
                foreach ( $items['plugins'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->get_site_plugins( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['plugins'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['plugins'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['themes'] ) ) {
                foreach ( $items['themes'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->get_site_themes( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['themes'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['themes'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items['non-mainwp-changes'] ) ) {
                foreach ( $items['non-mainwp-changes'] as $id ) {
                    $id = (int) $id;

                    if ( 0 === $id ) {
                        continue;
                    }

                    $_item = new WP_REST_Request( 'DELETE', $request->get_route() );
                    $_item->set_query_params(
                        array(
                            'id' => $id,
                        )
                    );
                    $_response = $this->get_non_mainwp_changes_of_site( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response['non-mainwp-changes'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response['non-mainwp-changes'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Validate a text value for a text based setting.
     *
     * @since 5.2
     * @param string $value Value.
     * @param array  $setting Setting.
     * @return string
     */
    public function validate_setting_text_field( $value, $setting ) {
        $value = is_null( $value ) ? '' : $value;
        return wp_kses_post( trim( stripslashes( $value ) ) );
    }

    /**
     * Validate select based settings.
     *
     * @since 5.2
     * @param string $value Value.
     * @param array  $setting Setting.
     * @return string|WP_Error
     */
    public function validate_setting_select_field( $value, $setting ) {
        if ( array_key_exists( $value, $setting['options'] ) ) {
            return $value;
        } else {
            return new WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'mainwp' ), array( 'status' => 400 ) );
        }
    }

    /**
     * Validate multiselect based settings.
     *
     * @since 5.2
     * @param array $values Values.
     * @param array $setting Setting.
     * @return array|WP_Error
     */
    public function validate_setting_multiselect_field( $values, $setting ) {
        if ( empty( $values ) ) {
            return array();
        }

        if ( ! is_array( $values ) ) {
            return new WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'mainwp' ), array( 'status' => 400 ) );
        }

        $final_values = array();
        foreach ( $values as $value ) {
            if ( array_key_exists( $value, $setting['options'] ) ) {
                $final_values[] = $value;
            }
        }

        return $final_values;
    }

    /**
     * Validate image_width based settings.
     *
     * @since 5.2
     * @param array $values Values.
     * @param array $setting Setting.
     * @return string|WP_Error
     */
    public function validate_setting_image_width_field( $values, $setting ) {
        if ( ! is_array( $values ) ) {
            return new WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'mainwp' ), array( 'status' => 400 ) );
        }

        $current = $setting['value'];
        if ( isset( $values['width'] ) ) {
            $current['width'] = intval( $values['width'] );
        }
        if ( isset( $values['height'] ) ) {
            $current['height'] = intval( $values['height'] );
        }
        if ( isset( $values['crop'] ) ) {
            $current['crop'] = (bool) $values['crop'];
        }
        return $current;
    }

    /**
     * Validate radio based settings.
     *
     * @since 5.2
     * @param string $value Value.
     * @param array  $setting Setting.
     * @return string|WP_Error
     */
    public function validate_setting_radio_field( $value, $setting ) {
        return $this->validate_setting_select_field( $value, $setting );
    }

    /**
     * Validate checkbox based settings.
     *
     * @since 5.2
     * @param string $value Value.
     * @param array  $setting Setting.
     * @return string|WP_Error
     */
    public function validate_setting_checkbox_field( $value, $setting ) {
        if ( in_array( $value, array( 'yes', 'no' ) ) ) {
            return $value;
        } elseif ( empty( $value ) ) {
            return isset( $setting['default'] ) ? $setting['default'] : 'no';
        } else {
            return new WP_Error( 'rest_setting_value_invalid', __( 'An invalid setting value was passed.', 'mainwp' ), array( 'status' => 400 ) );
        }
    }

    /**
     * Validate textarea based settings.
     *
     * @since 5.2
     * @param string $value Value.
     * @param array  $setting Setting.
     * @return string
     */
    public function validate_setting_textarea_field( $value, $setting ) {
        $value = is_null( $value ) ? '' : $value;
        return wp_kses(
            trim( stripslashes( $value ) ),
            array_merge(
                array(
                    'iframe' => array(
                        'src'   => true,
                        'style' => true,
                        'id'    => true,
                        'class' => true,
                    ),
                ),
                wp_kses_allowed_html( 'post' )
            )
        );
    }


    /**
     * Get the batch schema, conforming to JSON Schema.
     *
     * @return array
     */
    public function get_public_batch_schema() {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'batch',
            'type'       => 'object',
            'properties' => array(
                'create' => array(
                    'description' => __( 'List of created resources.', 'mainwp' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'update' => array(
                    'description' => __( 'List of updated resources.', 'mainwp' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'delete' => array(
                    'description' => __( 'List of delete resources.', 'mainwp' ),
                    'type'        => 'array',
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                ),
            ),
        );
    }


    /**
     * Get formatted item data, not including orders count nor total spent.
     * This method is needed because v3 API doesn't return those two fields.
     *
     * @internal This method could disappear or have its name or signature changed in future releases.
     *
     * @param  object $obj data instance.
     * @return array
     */
    protected function get_formatted_item_data_core( $obj ) {
        return array();
    }

    /**
     * Get formatted item data, not including orders count nor total spent.
     * This method is needed because v3 API doesn't return those two fields.
     *
     * @internal This method could disappear or have its name or signature changed in future releases.
     *
     * @param  array $data data instance.
     * @return array
     */
    protected function get_pre_formatted_item_data( $data ) {
        return $data;
    }


    /**
     * Get formatted item data, not including orders count nor total spent.
     * This method is needed because v3 API doesn't return those two fields.
     *
     * @internal This method could disappear or have its name or signature changed in future releases.
     *
     * @param  array $data data instance.
     * @return array
     */
    protected function get_formatted_item_data( $data ) { //phpcs:ignore -- NOSONAR - compatible.
        return $data;
    }

    /**
     * Gets an array of fields to be included on the response.
     *
     * Included fields are based on item schema and `_fields=` request argument.
     * Updated from WordPress 5.3, included into this class to support old versions.
     *
     * @since 5.2
     * @param WP_REST_Request $request Full details about the request.
     * @return array Fields to be included in the response.
     */
    public function get_fields_for_response( $request ) { //phpcs:ignore -- NOSONAR - complex.
        // From xdebug profiling, this method could take upto 25% of request time in index calls.
        // Cache it and make sure _fields was cached on current request object!
        if ( isset( $this->_fields ) && is_array( $this->_fields ) && $request === $this->_request ) {
            return $this->_fields;
        }
        $this->_request = $request;

        $schema     = $this->get_item_schema();
        $properties = isset( $schema['properties'] ) ? $schema['properties'] : array();

        // Exclude fields that specify a different context than the request context.
        $context = $request['context'];
        if ( $context ) {
            foreach ( $properties as $name => $options ) {
                if ( ! empty( $options['context'] ) && ! in_array( $context, $options['context'], true ) ) {
                    unset( $properties[ $name ] );
                }
            }
        }

        $fields = array_keys( $properties );

        if ( ! isset( $request['_fields'] ) ) {
            $this->_fields = $fields;
            return $fields;
        }
        $requested_fields = wp_parse_list( $request['_fields'] );
        if ( empty( $requested_fields ) ) {
            $this->_fields = $fields;
            return $fields;
        }
        // Trim off outside whitespace from the comma delimited list.
        $requested_fields = array_map( 'trim', $requested_fields );
        // Always persist 'id', because it can be needed for add_additional_fields_to_object().
        if ( in_array( 'id', $fields, true ) ) {
            $requested_fields[] = 'id';
        }
        // Return the list of all requested fields which appear in the schema.
        $this->_fields = array_reduce(
            $requested_fields,
            function ( $response_fields, $field ) use ( $fields ) {
                if ( in_array( $field, $fields, true ) ) {
                    $response_fields[] = $field;
                    return $response_fields;
                }
                // Check for nested fields if $field is not a direct match.
                $nested_fields = explode( '.', $field );
                // A nested field is included so long as its top-level property.
                // is present in the schema.
                if ( in_array( $nested_fields[0], $fields, true ) ) {
                    $response_fields[] = $field;
                }
                return $response_fields;
            },
            array()
        );
        return $this->_fields;
    }

    /**
     * Returns the full item schema.
     *
     * @return array
     */
    public function get_item_schema() {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => $this->title,
            'type'       => 'object',
            'properties' => $this->get_properties(),
        );
    }


    /**
     * Returns the full item response.
     *
     * @param mixed $item Item to get response for.
     * @return array|stdClass
     */
    public function get_item_response( $item ) {
        return array();
    }

    /**
     * Return schema properties.
     *
     * @return array
     */
    public function get_properties() {
        return array();
    }

    /**
     * Get route error.
     *
     * @param string $type_slug Type slug.
     * @param string $type 'object'.
     *
     * @return WP_Error
     */
    public function get_rest_data_error( $type_slug, $type = 'object' ) {
        if ( empty( $type ) && is_string( $type_slug ) ) {
            $type = $type_slug;
        }
        switch ( $type_slug ) {
            case 'id':
                return new WP_Error( "mainwp_rest_invalid_{$type}_id", __( 'Invalid or not found ID.', 'mainwp' ), array( 'status' => 404 ) );
            case 'domain':
                return new WP_Error( "mainwp_rest_invalid_{$type}_data", __( 'Invalid or not found domain.', 'mainwp' ), array( 'status' => 404 ) );
            case 'email':
                return new WP_Error( "mainwp_rest_invalid_{$type}_data", __( 'Invalid or not found email.', 'mainwp' ), array( 'status' => 404 ) );
            default:
                return new WP_Error( "mainwp_rest_invalid_{$type}_data", __( 'Invalid data.', 'mainwp' ), array( 'status' => 500 ) );
        }
    }

    /**
     * Gets an array of fields to be included on the response.
     *
     * Included fields are based on item schema and `_fields=` request argument.
     * Updated from WordPress 5.3, included into this class to support old versions.
     *
     * @param array  $item data.
     * @param string $context fields to filter.
     * @return array Fields to be included in the response.
     */
    public function filter_response_data_by_allowed_fields( $item, $context = 'view' ) { //phpcs:ignore -- NOSONAR - complex.
        $data   = $this->filter_response_by_context( $item, 'view' );
        $fields = $this->get_allowed_fields_by_context( $context );
        if ( is_array( $fields ) && ! empty( $fields ) ) {
            $_data = array();
            foreach ( $fields as $field ) {
                if ( is_array( $data ) ) {
                    if ( isset( $data[ $field ] ) ) {
                        $_data[ $field ] = $data[ $field ];
                    } else {
                        $_data[ $field ] = '';
                    }
                } elseif ( is_object( $data ) ) {
                    if ( property_exists( $data, $field ) ) {
                        $_data[ $field ] = $data->{$field};
                    } else {
                        $_data[ $field ] = '';
                    }
                }
            }
            $_data = $this->get_formatted_item_data( $_data );
            return $_data;
        }
        return $data;
    }

    /**
     * Gets an array of fields to be included on the response.
     *
     * Included fields are based on item schema and `_fields=` request argument.
     * Updated from WordPress 5.3, included into this class to support old versions.
     *
     * @since 5.2
     * @param string $context context.
     * @return array Fields to be included in the response.
     */
    public function get_allowed_fields_by_context( $context ) {
        $schema     = $this->get_item_schema();
        $properties = isset( $schema['properties'] ) ? $schema['properties'] : array();
        if ( $context ) {
            foreach ( $properties as $name => $options ) {
                if ( ! empty( $options['context'] ) && ! in_array( $context, $options['context'], true ) ) {
                    unset( $properties[ $name ] );
                }
            }
        }
        return array_keys( $properties );
    }

    /**
     * Check rest permissions callback.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return bool
     */
    public function get_rest_permissions_check( $request ) {
        $valid = \MainWP_REST_Authentication::get_instance()->is_valid_permissions( $request );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        return true;
    }

    /**
     * Method get_rest_api_user().
     *
     * @return mixed
     */
    public function get_rest_api_user() {
        return \MainWP_REST_Authentication::get_instance()->get_rest_valid_user();
    }

    /**
     * Get the query params for collections of attachments.
     *
     * @return array
     */
    public function get_collection_params() {
        $params                       = array();
        $params['context']            = $this->get_context_param();
        $params['context']['default'] = 'view';

        $params['page']     = array(
            'description'       => __( 'Current page of the collection.', 'mainwp' ),
            'type'              => 'integer',
            'default'           => 1,
            'sanitize_callback' => 'absint',
            'validate_callback' => 'rest_validate_request_arg',
            'minimum'           => 1,
        );
        $params['per_page'] = array(
            'description'       => __( 'Maximum number of items to be returned in result set.', 'mainwp' ),
            'type'              => 'integer',
            'default'           => 10,
            'minimum'           => 1,
            'maximum'           => 100,
            'sanitize_callback' => 'absint',
            'validate_callback' => 'rest_validate_request_arg',
        );
        $params['search']   = array(
            'description'       => __( 'Limit results to those matching a string.', 'mainwp' ),
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => 'rest_validate_request_arg',
        );

        $params['slug'] = array(
            'default'           => '',
            'description'       => __( 'Slugs.', 'mainwp' ),
            'type'              => 'array',
            'items'             => array(
                'type' => 'string',
            ),
            'sanitize_callback' => 'wp_parse_list',
            'validate_callback' => 'rest_validate_request_arg',
        );

        $params['status'] = array(
            'default'           => '',
            'description'       => __( 'Status.', 'mainwp' ),
            'type'              => array( 'string' ),
            'sanitize_callback' => 'wp_parse_list',
            'validate_callback' => 'rest_validate_request_arg',
        );

        $params['exclude'] = array(
            'description'       => __( 'Exclude IDs.', 'mainwp' ),
            'type'              => 'array',
            'items'             => array(
                'type' => 'integer',
            ),
            'sanitize_callback' => 'wp_parse_id_list',
        );
        $params['include'] = array(
            'description'       => __( 'Include IDs.', 'mainwp' ),
            'type'              => 'array',
            'items'             => array(
                'type' => 'integer',
            ),
            'sanitize_callback' => 'wp_parse_id_list',
        );

        /**
         * Filter collection parameters for the controller.
         *
         * @param array        $query_params JSON Schema-formatted collection parameters.
         * @param object This object.
         */
        return apply_filters( 'mainwp_rest_collection_params', $params, $this );
    }

    /**
     * Method sanitize_request_slugs().
     *
     * @param array $slugs slugs.
     * @return array
     */
    public function sanitize_request_slugs( $slugs ) {
        if ( ! empty( $slugs ) ) {
            $slugs = array_map( 'sanitize_text_field', array_map( 'urldecode', $slugs ) );
        }
        return $slugs;
    }
}
