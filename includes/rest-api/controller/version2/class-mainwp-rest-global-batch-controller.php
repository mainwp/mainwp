<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     * Controller names handled by the batch endpoint.
     *
     * @var array
     */
    protected $controller_names = array( 'sites', 'clients', 'tags' );

    /**
     * Actions the dispatch below reads per group, each marked with the shape it reads its items in:
     * an 'item' action passes every item on as a request body, an 'id' action casts every item to an int.
     *
     * @var array
     */
    const GROUP_ACTIONS = array(
        'sites'   => array(
            'create'             => 'item',
            'sync'               => 'id',
            'reconnect'          => 'id',
            'disconnect'         => 'id',
            'suspend'            => 'id',
            'check'              => 'id',
            'remove'             => 'id',
            'security'           => 'id',
            'plugins'            => 'id',
            'themes'             => 'id',
            'non-mainwp-changes' => 'id',
        ),
        'clients' => array(
            'create' => 'item',
        ),
        'tags'    => array(
            'create' => 'item',
        ),
    );

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

        // Groups the batch endpoint cannot dispatch (updates has no batch-capable create handler,
        // costs has no controller at all, anything else is unknown) are reported once each instead
        // of being dropped without a word or failing per item with a 405 from the core stub. For an
        // unsupported name the value does not matter, so an empty or scalar group still gets the
        // error. Only the body names groups, so an array-valued query param is not mistaken for one.
        $body_groups = $request->get_json_params();
        if ( empty( $body_groups ) || ! is_array( $body_groups ) ) {
            $body_groups = (array) $request->get_body_params();
        }

        foreach ( array_keys( $body_groups ) as $group_name ) {
            if ( ! in_array( $group_name, $this->controller_names, true ) ) {
                $response[ $group_name ] = array(
                    'error' => array(
                        'code'    => 'rest_batch_group_not_supported',
                        /* translators: %s: batch group name */
                        'message' => sprintf( __( 'The %s group is not supported by the batch endpoint.', 'mainwp' ), $group_name ),
                        'data'    => array( 'status' => 400 ),
                    ),
                );
            }
        }

        // Shapes are checked against the items the dispatch below reads, not against the body, so a
        // group sent as a query parameter cannot skip the check. The group is dropped from the items
        // as well as reported, so nothing in it is dispatched.
        foreach ( $this->controller_names as $group_name ) {
            if ( isset( $items[ $group_name ] ) ) {
                $group_items = $items[ $group_name ];
            } elseif ( array_key_exists( $group_name, $body_groups ) ) {
                // A falsy group is filtered out of the items, so it is read back from the body to be
                // reported rather than passed over.
                $group_items = $body_groups[ $group_name ];
            } else {
                continue;
            }

            if ( ! $this->is_dispatchable_group( $group_name, $group_items ) ) {
                $response[ $group_name ] = array(
                    'error' => array(
                        'code'    => 'rest_invalid_param',
                        /* translators: %s: batch group name */
                        'message' => sprintf( __( 'The %s group must be an object of supported action arrays.', 'mainwp' ), $group_name ),
                        'data'    => array( 'status' => 400 ),
                    ),
                );
                unset( $items[ $group_name ] );
            }
        }

        // Counted after the malformed groups are dropped: nothing in them is dispatched, so their items
        // must not be what pushes a request over the cap and hides the per-group error behind a 413.
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
     * Check that a group carries only what the dispatch below can read.
     *
     * The dispatch indexes the group by action name and then walks each action list, so a scalar in
     * either place would reach a foreach over something that is not a list, a JSON list carries none
     * of the names it indexes by, and an action it does not read is silently dropped. Items are
     * checked too: a create item is passed on as a request body and an id item is cast to an int, so
     * neither can be an arbitrary value.
     *
     * @param string $group_name  Group name.
     * @param mixed  $group_items Group value taken from the request.
     * @return bool
     */
    private function is_dispatchable_group( $group_name, $group_items ) {
        if ( ! is_array( $group_items ) ) {
            return false;
        }

        // An empty group dispatches nothing and is not the caller getting the shape wrong. It is
        // taken out of the list test as well, which range() cannot answer for a count of zero.
        if ( array() === $group_items ) {
            return true;
        }

        if ( array_keys( $group_items ) === range( 0, count( $group_items ) - 1 ) ) {
            return false;
        }

        $actions = isset( self::GROUP_ACTIONS[ $group_name ] ) ? self::GROUP_ACTIONS[ $group_name ] : array();

        foreach ( $group_items as $action_name => $action_items ) {
            if ( ! isset( $actions[ $action_name ] ) || ! is_array( $action_items ) ) {
                return false;
            }

            foreach ( $action_items as $item ) {
                if ( 'item' === $actions[ $action_name ] ) {
                    if ( ! is_array( $item ) ) {
                        return false;
                    }
                } elseif ( ! ( is_int( $item ) && $item >= 0 ) && ! ( is_string( $item ) && '' !== $item && ctype_digit( $item ) ) ) {
                    // A fractional or exponent string is numeric, and the (int) cast below would read
                    // it as a whole id the caller never asked for, so only digits are accepted.
                    return false;
                }
            }
        }

        return true;
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

        // The updates and costs groups are rejected as a whole by batch_items(), but their items
        // still count toward the cap so an oversized request is refused before anything else is
        // dispatched.
        $count_names = array_merge( $this->controller_names, array( 'updates', 'costs' ) );

        foreach ( $count_names as $con_name ) {
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
