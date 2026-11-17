<?php
/**
 * Plugin Name: MainWP remote MCP harness: whoami
 * Description: E2E harness only. Stands in for "another plugin": a REST route and an admin-ajax
 * action that report the user WordPress resolved for the request.
 *
 * @package MainWP\Dashboard\Tests
 */

add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'harness/v1',
            '/whoami',
            array(
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => function () {
                    return array( 'user_id' => get_current_user_id() );
                },
            )
        );
    }
);

$mainwp_rmcp_harness_whoami = function () {
    wp_send_json( array( 'user_id' => get_current_user_id() ) );
};
add_action( 'wp_ajax_mainwp_rmcp_harness_whoami', $mainwp_rmcp_harness_whoami );
add_action( 'wp_ajax_nopriv_mainwp_rmcp_harness_whoami', $mainwp_rmcp_harness_whoami );
