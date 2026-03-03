<?php
/**
 * MainWP Abilities - Clients
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Clients
 *
 * Registers and implements client-related abilities for the MainWP Dashboard.
 *
 * This class provides 11 abilities:
 * - mainwp/list-clients-v1: List MainWP clients with pagination and filtering
 * - mainwp/count-clients-v1: Count total clients
 * - mainwp/get-client-v1: Get detailed information about a single client
 * - mainwp/add-client-v1: Create a new client
 * - mainwp/update-client-v1: Update an existing client
 * - mainwp/delete-client-v1: Delete a client (destructive operation)
 * - mainwp/get-client-sites-v1: Get sites associated with a client
 * - mainwp/count-client-sites-v1: Count sites associated with a client
 * - mainwp/get-client-costs-v1: Get cost tracker entries for a client (feature-gated)
 * - mainwp/suspend-client-v1: Suspend a client
 * - mainwp/unsuspend-client-v1: Unsuspend a client
 */
class MainWP_Abilities_Clients { //phpcs:ignore -- NOSONAR -- class complexity acceptable.

    /**
     * Register all client abilities.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        self::register_list_clients();
        self::register_count_clients();
        self::register_get_client();
        self::register_add_client();
        self::register_update_client();
        self::register_delete_client();
        self::register_get_client_sites();
        self::register_count_client_sites();

        // Feature-gated: Only register if Cost Tracker module is available.
        if ( class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            self::register_get_client_costs();
        }

        self::register_suspend_client();
        self::register_unsuspend_client();
    }

    /**
     * Register mainwp/list-clients-v1 ability.
     *
     * @return void
     */
    private static function register_list_clients(): void {
        wp_register_ability(
            'mainwp/list-clients-v1',
            array(
                'label'               => __( 'List MainWP Clients', 'mainwp' ),
                'description'         => __( 'List MainWP clients with pagination and filtering. Possible errors: mainwp_invalid_input, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_list_clients_input_schema(),
                'output_schema'       => self::get_list_clients_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_clients' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for list-clients.
     *
     * @return array Input schema definition.
     */
    private static function get_list_clients_input_schema(): array {
        return array(
            'type'       => array( 'object', 'null' ),
            'properties' => array(
                'page'     => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 10000,
                    'default' => 1,
                ),
                'per_page' => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 20,
                ),
                'status'   => array(
                    'type'    => 'string',
                    'enum'    => array( 'any', 'active', 'suspended' ),
                    'default' => 'any',
                ),
                'search'   => array(
                    'type'    => 'string',
                    'default' => '',
                ),
                'include'  => array(
                    'type'    => 'array',
                    'items'   => array( 'type' => 'integer' ),
                    'default' => array(),
                ),
                'exclude'  => array(
                    'type'    => 'array',
                    'items'   => array( 'type' => 'integer' ),
                    'default' => array(),
                ),
            ),
            'default'    => array(),
        );
    }

    /**
     * Get output schema for list-clients.
     *
     * @return array Output schema definition.
     */
    private static function get_list_clients_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'    => array(
                    'type'  => 'array',
                    'items' => self::get_client_object_schema(),
                ),
                'page'     => array( 'type' => 'integer' ),
                'per_page' => array( 'type' => 'integer' ),
                'total'    => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute list-clients ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_list_clients( array $input ) {
        $input = MainWP_Abilities_Util::normalize_input(
            $input,
            array(
                'page'     => 1,
                'per_page' => 20,
                'status'   => 'any',
                'search'   => '',
                'include'  => array(),
                'exclude'  => array(),
            )
        );

        $args = array(
            'page'     => (int) $input['page'],
            'per_page' => (int) $input['per_page'],
        );

        if ( 'active' === $input['status'] ) {
            $args['suspended'] = 0;
        } elseif ( 'suspended' === $input['status'] ) {
            $args['suspended'] = 1;
        }

        if ( ! empty( $input['search'] ) ) {
            $args['s'] = sanitize_text_field( trim( $input['search'] ) );
        }

        if ( ! empty( $input['include'] ) ) {
            $args['include'] = $input['include'];
        }

        if ( ! empty( $input['exclude'] ) ) {
            $args['exclude'] = $input['exclude'];
        }

        $db      = MainWP_DB_Client::instance();
        $clients = $db->get_wp_clients( $args );
        $total   = $db->get_wp_clients( array_merge( $args, array( 'count_only' => true ) ) );

        $items = array();
        if ( is_array( $clients ) ) {
            foreach ( $clients as $client ) {
                $items[] = MainWP_Abilities_Util::format_client_for_output( $client );
            }
        }

        return array(
            'items'    => $items,
            'page'     => (int) $input['page'],
            'per_page' => (int) $input['per_page'],
            'total'    => (int) $total,
        );
    }

    /**
     * Register mainwp/count-clients-v1 ability.
     *
     * @return void
     */
    private static function register_count_clients(): void {
        wp_register_ability(
            'mainwp/count-clients-v1',
            array(
                'label'               => __( 'Count MainWP Clients', 'mainwp' ),
                'description'         => __( 'Count total number of MainWP clients. Possible errors: ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_count_clients_input_schema(),
                'output_schema'       => self::get_count_clients_output_schema(),
                'execute_callback'    => array( self::class, 'execute_count_clients' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for count-clients.
     *
     * @return array Input schema definition.
     */
    private static function get_count_clients_input_schema(): array {
        return array(
            'type'    => array( 'object', 'null' ),
            'default' => array(),
        );
    }

    /**
     * Get output schema for count-clients.
     *
     * @return array Output schema definition.
     */
    private static function get_count_clients_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'total' => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute count-clients ability.
     *
     * @param array $input Validated input parameters (unused but required by ability signature).
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_count_clients( array $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- NOSONAR Required by ability signature.
        $total = MainWP_DB_Client::instance()->get_wp_clients( array( 'count_only' => true ) );

        return array(
            'total' => (int) $total,
        );
    }

    /**
     * Register mainwp/get-client-v1 ability.
     *
     * @return void
     */
    private static function register_get_client(): void {
        wp_register_ability(
            'mainwp/get-client-v1',
            array(
                'label'               => __( 'Get MainWP Client', 'mainwp' ),
                'description'         => __( 'Get detailed information about a single MainWP client. Possible errors: mainwp_client_not_found, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_get_client_input_schema(),
                'output_schema'       => self::get_client_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_client' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for get-client.
     *
     * @return array Input schema definition.
     */
    private static function get_get_client_input_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'client_id_or_email' => array(
                    'type' => array( 'integer', 'string' ),
                ),
            ),
            'required'   => array( 'client_id_or_email' ),
        );
    }

    /**
     * Get output schema for single client.
     *
     * @return array Output schema definition.
     */
    private static function get_client_output_schema(): array {
        return self::get_client_object_schema();
    }

    /**
     * Execute get-client ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_get_client( array $input ) {
        $client = MainWP_Abilities_Util::resolve_client( $input['client_id_or_email'] );

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        return MainWP_Abilities_Util::format_client_for_output( $client );
    }

    /**
     * Register mainwp/add-client-v1 ability.
     *
     * @return void
     */
    private static function register_add_client(): void {
        wp_register_ability(
            'mainwp/add-client-v1',
            array(
                'label'               => __( 'Add MainWP Client', 'mainwp' ),
                'description'         => __( 'Create a new MainWP client. Possible errors: mainwp_invalid_input, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_add_client_input_schema(),
                'output_schema'       => self::get_client_output_schema(),
                'execute_callback'    => array( self::class, 'execute_add_client' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for add-client.
     *
     * @return array Input schema definition.
     */
    private static function get_add_client_input_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name'             => array( 'type' => 'string' ),
                'client_email'     => array(
                    'type'   => 'string',
                    'format' => 'email',
                ),
                'client_phone'     => array( 'type' => 'string' ),
                'address_1'        => array( 'type' => 'string' ),
                'address_2'        => array( 'type' => 'string' ),
                'city'             => array( 'type' => 'string' ),
                'state'            => array( 'type' => 'string' ),
                'zip'              => array( 'type' => 'string' ),
                'country'          => array( 'type' => 'string' ),
                'note'             => array( 'type' => 'string' ),
                'selected_sites'   => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'integer' ),
                ),
                'client_facebook'  => array( 'type' => 'string' ),
                'client_twitter'   => array( 'type' => 'string' ),
                'client_instagram' => array( 'type' => 'string' ),
                'client_linkedin'  => array( 'type' => 'string' ),
            ),
            'required'   => array( 'name' ),
        );
    }

    /**
     * Execute add-client ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_add_client( array $input ) {
        if ( empty( $input['name'] ) ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Client name is required.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $result = MainWP_Client_Handler::rest_api_add_client( $input, false );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $result['success'] ) && ! $result['success'] ) {
            return new \WP_Error(
                'mainwp_client_creation_failed',
                isset( $result['error'] ) ? $result['error'] : __( 'Failed to create client.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $client_id = isset( $result['client_id'] ) ? (int) $result['client_id'] : 0;

        if ( ! $client_id ) {
            return new \WP_Error(
                'mainwp_client_creation_failed',
                __( 'Failed to create client.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );

        if ( ! $client ) {
            return new \WP_Error(
                'mainwp_client_not_found',
                __( 'Client was created but could not be retrieved.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return MainWP_Abilities_Util::format_client_for_output( $client );
    }

    /**
     * Register mainwp/update-client-v1 ability.
     *
     * @return void
     */
    private static function register_update_client(): void {
        wp_register_ability(
            'mainwp/update-client-v1',
            array(
                'label'               => __( 'Update MainWP Client', 'mainwp' ),
                'description'         => __( 'Update an existing MainWP client. Possible errors: mainwp_client_not_found, mainwp_invalid_input, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_update_client_input_schema(),
                'output_schema'       => self::get_client_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_client' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for update-client.
     *
     * @return array Input schema definition.
     */
    private static function get_update_client_input_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'client_id_or_email' => array( 'type' => array( 'integer', 'string' ) ),
                'name'               => array( 'type' => 'string' ),
                'client_email'       => array(
                    'type'   => 'string',
                    'format' => 'email',
                ),
                'client_phone'       => array( 'type' => 'string' ),
                'address_1'          => array( 'type' => 'string' ),
                'address_2'          => array( 'type' => 'string' ),
                'city'               => array( 'type' => 'string' ),
                'state'              => array( 'type' => 'string' ),
                'zip'                => array( 'type' => 'string' ),
                'country'            => array( 'type' => 'string' ),
                'note'               => array( 'type' => 'string' ),
                'selected_sites'     => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'integer' ),
                ),
                'client_facebook'    => array( 'type' => 'string' ),
                'client_twitter'     => array( 'type' => 'string' ),
                'client_instagram'   => array( 'type' => 'string' ),
                'client_linkedin'    => array( 'type' => 'string' ),
            ),
            'required'   => array( 'client_id_or_email' ),
        );
    }

    /**
     * Execute update-client ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_update_client( array $input ) {
        $client = MainWP_Abilities_Util::resolve_client( $input['client_id_or_email'] );

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $data              = $input;
        $data['client_id'] = $client->client_id;

        unset( $data['client_id_or_email'] );

        $result = MainWP_Client_Handler::rest_api_add_client( $data, true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $result['success'] ) && ! $result['success'] ) {
            return new \WP_Error(
                'mainwp_client_update_failed',
                isset( $result['error'] ) ? $result['error'] : __( 'Failed to update client.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $updated_client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id );

        if ( ! $updated_client ) {
            return new \WP_Error(
                'mainwp_client_not_found',
                __( 'Client was updated but could not be retrieved.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return MainWP_Abilities_Util::format_client_for_output( $updated_client );
    }

    /**
     * Register mainwp/delete-client-v1 ability.
     *
     * @return void
     */
    private static function register_delete_client(): void {
        wp_register_ability(
            'mainwp/delete-client-v1',
            array(
                'label'               => __( 'Delete MainWP Client', 'mainwp' ),
                'description'         => __( 'Delete a MainWP client. Requires confirm: true or dry_run: true. Possible errors: mainwp_client_not_found, mainwp_confirmation_required, mainwp_invalid_input, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_delete_client_input_schema(),
                'output_schema'       => self::get_delete_client_output_schema(),
                'execute_callback'    => array( self::class, 'execute_delete_client' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Destructive operation requires confirm: true. Only call when user explicitly requests deletion.',
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for delete-client.
     *
     * @return array Input schema definition.
     */
    private static function get_delete_client_input_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'client_id_or_email' => array( 'type' => array( 'integer', 'string' ) ),
                'confirm'            => array(
                    'type'    => 'boolean',
                    'default' => false,
                ),
                'dry_run'            => array(
                    'type'    => 'boolean',
                    'default' => false,
                ),
            ),
            'required'   => array( 'client_id_or_email' ),
        );
    }

    /**
     * Get output schema for delete-client.
     *
     * Uses required arrays to ensure oneOf branches are mutually exclusive:
     * - Deletion response requires 'id' and 'name' (client properties)
     * - Dry-run response requires 'dry_run' and 'would_affect'
     *
     * @return array Output schema definition.
     */
    private static function get_delete_client_output_schema(): array {
        $client_schema             = self::get_client_object_schema();
        $client_schema['required'] = array( 'id', 'name' );

        return array(
            'oneOf' => array(
                $client_schema,
                array(
                    'type'       => 'object',
                    'required'   => array( 'dry_run', 'would_affect' ),
                    'properties' => array(
                        'dry_run'      => array( 'type' => 'boolean' ),
                        'would_affect' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'client'                 => self::get_client_object_schema(),
                                'associated_sites_count' => array( 'type' => 'integer' ),
                            ),
                        ),
                        'count'        => array( 'type' => 'integer' ),
                        'warnings'     => array(
                            'type'  => 'array',
                            'items' => array( 'type' => 'string' ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Execute delete-client ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_delete_client( array $input ) {
        $input = MainWP_Abilities_Util::normalize_input(
            $input,
            array(
                'confirm' => false,
                'dry_run' => false,
            )
        );

        if ( $input['dry_run'] && $input['confirm'] ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm. Use dry_run for preview, confirm for actual deletion.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $client = MainWP_Abilities_Util::resolve_client( $input['client_id_or_email'] );

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        if ( $input['dry_run'] ) {
            $sites       = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client->client_id );
            $sites_count = is_array( $sites ) ? count( $sites ) : 0;

            $warnings = array();
            if ( $sites_count > 0 ) {
                $warnings[] = sprintf(
                    /* translators: %d: number of sites */
                    _n(
                        'This client has %d associated site. Deleting the client will not delete the site.',
                        'This client has %d associated sites. Deleting the client will not delete the sites.',
                        $sites_count,
                        'mainwp'
                    ),
                    $sites_count
                );
            }

            return array(
                'dry_run'      => true,
                'would_affect' => array(
                    'client'                 => MainWP_Abilities_Util::format_client_for_output( $client ),
                    'associated_sites_count' => $sites_count,
                ),
                'count'        => 1,
                'warnings'     => $warnings,
            );
        }

        if ( ! $input['confirm'] ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'This is a destructive operation. Set confirm: true or use dry_run: true for preview.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $formatted_client = MainWP_Abilities_Util::format_client_for_output( $client );

        $deleted = MainWP_DB_Client::instance()->delete_client( $client->client_id );

        if ( ! $deleted ) {
            return new \WP_Error(
                'mainwp_client_deletion_failed',
                __( 'Failed to delete client.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return $formatted_client;
    }

    /**
     * Register mainwp/get-client-sites-v1 ability.
     *
     * @return void
     */
    private static function register_get_client_sites(): void {
        wp_register_ability(
            'mainwp/get-client-sites-v1',
            array(
                'label'               => __( 'Get Client Sites', 'mainwp' ),
                'description'         => __( 'Get sites associated with a MainWP client. Possible errors: mainwp_client_not_found, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_count_client_sites_input_schema(),
                'output_schema'       => self::get_get_client_sites_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_client_sites' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get output schema for get-client-sites.
     *
     * @return array Output schema definition.
     */
    private static function get_get_client_sites_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'        => array( 'type' => 'integer' ),
                            'url'       => array( 'type' => 'string' ),
                            'name'      => array( 'type' => 'string' ),
                            'status'    => array( 'type' => 'string' ),
                            'client_id' => array( 'type' => array( 'integer', 'null' ) ),
                        ),
                    ),
                ),
                'total' => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute get-client-sites ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_get_client_sites( array $input ) {
        $client = MainWP_Abilities_Util::resolve_client( $input['client_id_or_email'] );

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client->client_id );

        $items = array();
        if ( is_array( $sites ) ) {
            foreach ( $sites as $site ) {
                $items[] = MainWP_Abilities_Util::format_site_for_output( $site );
            }
        }

        return array(
            'items' => $items,
            'total' => count( $items ),
        );
    }

    /**
     * Register mainwp/count-client-sites-v1 ability.
     *
     * @return void
     */
    private static function register_count_client_sites(): void {
        wp_register_ability(
            'mainwp/count-client-sites-v1',
            array(
                'label'               => __( 'Count Client Sites', 'mainwp' ),
                'description'         => __( 'Count sites associated with a MainWP client. Possible errors: mainwp_client_not_found, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_count_client_sites_input_schema(),
                'output_schema'       => self::get_count_client_sites_output_schema(),
                'execute_callback'    => array( self::class, 'execute_count_client_sites' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for count-client-sites.
     *
     * @return array Input schema definition.
     */
    private static function get_count_client_sites_input_schema(): array { // phpcs:ignore -- NOSONAR -- repeat function.
        return array(
            'type'       => 'object',
            'properties' => array(
                'client_id_or_email' => array( 'type' => array( 'integer', 'string' ) ),
            ),
            'required'   => array( 'client_id_or_email' ),
        );
    }

    /**
     * Get output schema for count-client-sites.
     *
     * @return array Output schema definition.
     */
    private static function get_count_client_sites_output_schema(): array { // phpcs:ignore - NOSONAR -- same get_count_clients_output_schema.
        return array(
            'type'       => 'object',
            'properties' => array(
                'total' => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute count-client-sites ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_count_client_sites( array $input ) {
        $client = MainWP_Abilities_Util::resolve_client( $input['client_id_or_email'] );

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client->client_id );
        $count = is_array( $sites ) ? count( $sites ) : 0;

        return array(
            'total' => $count,
        );
    }

    /**
     * Register mainwp/get-client-costs-v1 ability.
     *
     * Feature-gated: Only registered if Cost Tracker module is available.
     *
     * @return void
     */
    private static function register_get_client_costs(): void {
        wp_register_ability(
            'mainwp/get-client-costs-v1',
            array(
                'label'               => __( 'Get Client Costs', 'mainwp' ),
                'description'         => __( 'Get cost tracker entries for a MainWP client. Requires Cost Tracker module. Possible errors: mainwp_client_not_found, mainwp_module_not_available, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_get_client_costs_input_schema(),
                'output_schema'       => self::get_get_client_costs_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_client_costs' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Requires Cost Tracker module',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for get-client-costs.
     *
     * @return array Input schema definition.
     */
    private static function get_get_client_costs_input_schema(): array { // phpcs:ignore -- NOSONAR -- repeat function.
        return array(
            'type'       => 'object',
            'properties' => array(
                'client_id_or_email' => array( 'type' => array( 'integer', 'string' ) ),
            ),
            'required'   => array( 'client_id_or_email' ),
        );
    }

    /**
     * Get output schema for get-client-costs.
     *
     * @return array Output schema definition.
     */
    private static function get_get_client_costs_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'             => array( 'type' => 'integer' ),
                            'name'           => array( 'type' => 'string' ),
                            'type'           => array( 'type' => 'string' ),
                            'price'          => array( 'type' => 'number' ),
                            'renewal_type'   => array( 'type' => 'string' ),
                            'payment_method' => array( 'type' => 'string' ),
                            'product_type'   => array( 'type' => 'string' ),
                            'last_renewal'   => array( 'type' => array( 'string', 'null' ) ),
                            'next_renewal'   => array( 'type' => array( 'string', 'null' ) ),
                        ),
                    ),
                ),
                'total' => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute get-client-costs ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_get_client_costs( array $input ) {
        if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            return new \WP_Error(
                'mainwp_module_not_available',
                __( 'Cost Tracker module is not available.', 'mainwp' ),
                array( 'status' => 503 )
            );
        }

        $client = MainWP_Abilities_Util::resolve_client( $input['client_id_or_email'] );

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB' ) ) {
            return new \WP_Error(
                'mainwp_internal_error',
                __( 'Cost Tracker database class is not available.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $cost_db = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance();
        $costs   = $cost_db->get_all_cost_trackers_by_clients( array( $client->client_id ) );

        $items = array();
        if ( is_array( $costs ) ) {
            foreach ( $costs as $cost ) {
                $items[] = MainWP_Abilities_Util::format_cost_for_output( $cost );
            }
        }

        return array(
            'items' => $items,
            'total' => count( $items ),
        );
    }

    /**
     * Register mainwp/suspend-client-v1 ability.
     *
     * @return void
     */
    private static function register_suspend_client(): void {
        wp_register_ability(
            'mainwp/suspend-client-v1',
            array(
                'label'               => __( 'Suspend MainWP Client', 'mainwp' ),
                'description'         => __( 'Suspend a MainWP client. Possible errors: mainwp_client_not_found, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_suspend_client_input_schema(),
                'output_schema'       => self::get_client_output_schema(),
                'execute_callback'    => array( self::class, 'execute_suspend_client' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for suspend-client.
     *
     * @return array Input schema definition.
     */
    private static function get_suspend_client_input_schema(): array { // phpcs:ignore -- NOSONAR -- repeat function.
        return array(
            'type'       => 'object',
            'properties' => array(
                'client_id_or_email' => array( 'type' => array( 'integer', 'string' ) ),
            ),
            'required'   => array( 'client_id_or_email' ),
        );
    }

    /**
     * Execute suspend-client ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_suspend_client( array $input ) {
        $client = MainWP_Abilities_Util::resolve_client( $input['client_id_or_email'] );

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $updated = MainWP_DB_Client::instance()->update_client(
            array(
                'client_id' => $client->client_id,
                'suspended' => 1,
            )
        );

        if ( ! $updated ) {
            return new \WP_Error(
                'mainwp_client_update_failed',
                __( 'Failed to suspend client.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $updated_client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id );

        if ( ! $updated_client ) {
            return new \WP_Error(
                'mainwp_client_not_found',
                __( 'Client was suspended but could not be retrieved.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return MainWP_Abilities_Util::format_client_for_output( $updated_client );
    }

    /**
     * Register mainwp/unsuspend-client-v1 ability.
     *
     * @return void
     */
    private static function register_unsuspend_client(): void {
        wp_register_ability(
            'mainwp/unsuspend-client-v1',
            array(
                'label'               => __( 'Unsuspend MainWP Client', 'mainwp' ),
                'description'         => __( 'Unsuspend a MainWP client. Possible errors: mainwp_client_not_found, ability_invalid_permissions', 'mainwp' ),
                'category'            => 'mainwp-clients',
                'input_schema'        => self::get_unsuspend_client_input_schema(),
                'output_schema'       => self::get_client_output_schema(),
                'execute_callback'    => array( self::class, 'execute_unsuspend_client' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for unsuspend-client.
     *
     * @return array Input schema definition.
     */
    private static function get_unsuspend_client_input_schema(): array { // phpcs:ignore -- NOSONAR -- repeat function.
        return array(
            'type'       => 'object',
            'properties' => array(
                'client_id_or_email' => array( 'type' => array( 'integer', 'string' ) ),
            ),
            'required'   => array( 'client_id_or_email' ),
        );
    }

    /**
     * Execute unsuspend-client ability.
     *
     * @param array $input Validated input parameters.
     * @return array|\WP_Error Result array or error.
     */
    public static function execute_unsuspend_client( array $input ) {
        $client = MainWP_Abilities_Util::resolve_client( $input['client_id_or_email'] );

        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $updated = MainWP_DB_Client::instance()->update_client(
            array(
                'client_id' => $client->client_id,
                'suspended' => 0,
            )
        );

        if ( ! $updated ) {
            return new \WP_Error(
                'mainwp_client_update_failed',
                __( 'Failed to unsuspend client.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $updated_client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id );

        if ( ! $updated_client ) {
            return new \WP_Error(
                'mainwp_client_not_found',
                __( 'Client was unsuspended but could not be retrieved.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return MainWP_Abilities_Util::format_client_for_output( $updated_client );
    }

    /**
     * Get client object schema.
     *
     * @return array Client object schema definition.
     */
    private static function get_client_object_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'        => array( 'type' => 'integer' ),
                'name'      => array( 'type' => 'string' ),
                'email'     => array( 'type' => 'string' ),
                'phone'     => array( 'type' => 'string' ),
                'address_1' => array( 'type' => 'string' ),
                'address_2' => array( 'type' => 'string' ),
                'city'      => array( 'type' => 'string' ),
                'state'     => array( 'type' => 'string' ),
                'zip'       => array( 'type' => 'string' ),
                'country'   => array( 'type' => 'string' ),
                'note'      => array( 'type' => 'string' ),
                'suspended' => array( 'type' => 'integer' ),
                'created'   => array(
                    'type'   => array( 'string', 'null' ),
                    'format' => 'date-time',
                ),
                'facebook'  => array( 'type' => 'string' ),
                'twitter'   => array( 'type' => 'string' ),
                'instagram' => array( 'type' => 'string' ),
                'linkedin'  => array( 'type' => 'string' ),
            ),
        );
    }
}
