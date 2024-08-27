<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

/**
 * Class MainWP_Rest_Global_Batch_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Global_Batch_Controller extends MainWP_REST_Controller{ //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

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
    protected $rest_base = 'batch';

    /**
     * Route base.
     *
     * @var string
     */
    protected $controller_names = array( 'sites', 'clients', 'updates', 'costs', 'tags' );

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
     * Method register_routes()
     *
     * Creates the necessary endpoints for the api.
     * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
     */
    public function register_routes() { // phpcs:ignore -- NOSONAR - complex.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'batch_items' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );
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

        foreach ( $this->controller_names as $con_name ) {

            $controller_obj = MainWP_Rest_Server::instance()->get_rest_controller( $this->namespace, $con_name );

            if ( false === $controller_obj ) {
                continue;
            }

            if ( ! empty( $items[ $con_name ]['create'] ) ) {
                foreach ( $items[ $con_name ]['create'] as $item ) {
                    $_item = new WP_REST_Request( 'POST', $request->get_route() );
                    // Default parameters.
                    $defaults = array();
                    $schema   = $controller_obj->get_public_item_schema();

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

                    $_response = $controller_obj->create_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['create'][] = array(
                            'id'    => 0,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['create'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }
        }

        $con_name = 'sites';

        $controller_obj = MainWP_Rest_Server::instance()->get_rest_controller( $this->namespace, $con_name );

        if ( $controller_obj ) {
            if ( ! empty( $items[ $con_name ]['sync'] ) ) {
                foreach ( $items[ $con_name ]['sync'] as $id ) {
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
                    $_response = $controller_obj->sync_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['sync'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['sync'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['reconnect'] ) ) {
                foreach ( $items[ $con_name ]['reconnect'] as $id ) {
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
                    $_response = $controller_obj->reconnect_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['reconnect'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['reconnect'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['disconnect'] ) ) {
                foreach ( $items[ $con_name ]['disconnect'] as $id ) {
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
                    $_response = $controller_obj->disconnect_site( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['disconnect'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['disconnect'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['suspend'] ) ) {
                foreach ( $items[ $con_name ]['suspend'] as $id ) {
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
                    $_response = $controller_obj->suspend_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['suspend'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['suspend'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['check'] ) ) {
                foreach ( $items[ $con_name ]['check'] as $id ) {
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
                    $_response = $controller_obj->check_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['check'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['check'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['remove'] ) ) {
                foreach ( $items[ $con_name ]['remove'] as $id ) {
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
                    $_response = $controller_obj->delete_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['remove'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['remove'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['security'] ) ) {
                foreach ( $items[ $con_name ]['security'] as $id ) {
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
                    $_response = $controller_obj->security_item( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['security'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['security'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['plugins'] ) ) {
                foreach ( $items[ $con_name ]['plugins'] as $id ) {
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
                    $_response = $controller_obj->get_site_plugins( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['plugins'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['plugins'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['themes'] ) ) {
                foreach ( $items[ $con_name ]['themes'] as $id ) {
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
                    $_response = $controller_obj->get_site_themes( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['themes'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['themes'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }

            if ( ! empty( $items[ $con_name ]['non-mainwp-changes'] ) ) {
                foreach ( $items[ $con_name ]['non-mainwp-changes'] as $id ) {
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
                    $_response = $controller_obj->get_non_mainwp_changes_of_site( $_item );

                    if ( is_wp_error( $_response ) ) {
                        $response[ $con_name ]['non-mainwp-changes'][] = array(
                            'id'    => $id,
                            'error' => array(
                                'code'    => $_response->get_error_code(),
                                'message' => $_response->get_error_message(),
                                'data'    => $_response->get_error_data(),
                            ),
                        );
                    } else {
                        $response[ $con_name ]['non-mainwp-changes'][] = $wp_rest_server->response_to_data( $_response, '' );
                    }
                }
            }
        }

        return $response;
    }


    /**
     * Check batch limit.
     *
     * @param array $items Request items.
     * @return bool|WP_Error
     */
    protected function check_batch_limit( $items ) { //phpcs:ignore -- NOSONAR complex function.
        $limit = apply_filters( 'mainwp_rest_batch_items_limit', 100, $this->get_normalized_rest_base() );
        $total = 0;

        foreach ( $this->controller_names as $con_name ) {
            if ( ! empty( $items[ $con_name ] ) && is_countable( $items[ $con_name ] ) ) {
                if ( ! empty( $items[ $con_name ]['create'] ) && is_countable( $items[ $con_name ]['create'] ) ) {
                    $total += count( $items[ $con_name ]['create'] );
                }

                if ( ! empty( $items[ $con_name ]['update'] ) && is_countable( $items[ $con_name ]['update'] ) ) {
                    $total += count( $items[ $con_name ]['update'] );
                }

                if ( ! empty( $items[ $con_name ]['delete'] ) && is_countable( $items[ $con_name ]['delete'] ) ) {
                    $total += count( $items[ $con_name ]['delete'] );
                }
            }
        }

        if ( ! empty( $items['sites']['sync'] ) && is_countable( $items['sites']['sync'] ) ) {
            $total += count( $items['sites']['sync'] );
        }

        if ( ! empty( $items['sites']['reconnect'] ) && is_countable( $items['sites']['reconnect'] ) ) {
            $total += count( $items['sites']['reconnect'] );
        }

        if ( ! empty( $items['sites']['disconnect'] ) && is_countable( $items['sites']['disconnect'] ) ) {
            $total += count( $items['sites']['disconnect'] );
        }

        if ( ! empty( $items['sites']['suspend'] ) && is_countable( $items['sites']['suspend'] ) ) {
            $total += count( $items['sites']['suspend'] );
        }

        if ( ! empty( $items['sites']['check'] ) && is_countable( $items['sites']['check'] ) ) {
            $total += count( $items['sites']['check'] );
        }

        if ( ! empty( $items['sites']['remove'] ) && is_countable( $items['sites']['remove'] ) ) {
            $total += count( $items['sites']['remove'] );
        }

        if ( ! empty( $items['sites']['security'] ) && is_countable( $items['sites']['security'] ) ) {
            $total += count( $items['sites']['security'] );
        }

        if ( ! empty( $items['sites']['plugins'] ) && is_countable( $items['sites']['plugins'] ) ) {
            $total += count( $items['sites']['plugins'] );
        }

        if ( ! empty( $items['sites']['themes'] ) && is_countable( $items['sites']['themes'] ) ) {
            $total += count( $items['sites']['themes'] );
        }

        if ( ! empty( $items['sites']['non-mainwp-changes'] ) && is_countable( $items['sites']['non-mainwp-changes'] ) ) {
            $total += count( $items['sites']['non-mainwp-changes'] );
        }

        if ( $total > $limit ) {
            /* translators: %s: items limit */
            return new WP_Error( 'mainwp_rest_request_entity_too_large', sprintf( __( 'Unable to accept more than %s items for this request.', 'mainwp' ), $limit ), array( 'status' => 413 ) );
        }

        return true;
    }
}
