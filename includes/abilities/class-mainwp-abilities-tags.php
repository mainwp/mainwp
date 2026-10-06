<?php
/**
 * MainWP Tags Abilities
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Tags
 *
 * Registers and implements tag-related abilities for the MainWP Dashboard.
 *
 * This class provides 7 abilities:
 * - mainwp/list-tags-v1: List MainWP tags with pagination and filtering
 * - mainwp/get-tag-v1: Get detailed information about a single tag
 * - mainwp/add-tag-v1: Create a new tag
 * - mainwp/update-tag-v1: Update an existing tag
 * - mainwp/delete-tag-v1: Delete a tag (with dry-run and confirmation safeguards)
 * - mainwp/get-tag-sites-v1: Get sites associated with a tag
 * - mainwp/get-tag-clients-v1: Get clients associated with a tag
 */
class MainWP_Abilities_Tags { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Register all tag abilities.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        self::register_list_tags();
        self::register_get_tag();
        self::register_add_tag();
        self::register_update_tag();
        self::register_delete_tag();
        self::register_get_tag_sites();
        self::register_get_tag_clients();
    }

    /**
     * Register mainwp/list-tags-v1 ability.
     *
     * @return void
     */
    private static function register_list_tags(): void {
        wp_register_ability(
            'mainwp/list-tags-v1',
            array(
                'label'               => __( 'List MainWP Tags', 'mainwp' ),
                'description'         => __( 'List MainWP tags with pagination and filtering. Returns tag information including ID, name, color, and sites count.', 'mainwp' ),
                'category'            => 'mainwp-tags',
                'input_schema'        => self::get_list_tags_input_schema(),
                'output_schema'       => self::get_list_tags_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_tags' ),
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
     * Register mainwp/get-tag-v1 ability.
     *
     * @return void
     */
    private static function register_get_tag(): void {
        wp_register_ability(
            'mainwp/get-tag-v1',
            array(
                'label'               => __( 'Get MainWP Tag', 'mainwp' ),
                'description'         => __( 'Get detailed information about a single MainWP tag.', 'mainwp' ),
                'category'            => 'mainwp-tags',
                'input_schema'        => self::get_get_tag_input_schema(),
                'output_schema'       => self::get_tag_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_tag' ),
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
     * Register mainwp/add-tag-v1 ability.
     *
     * @return void
     */
    private static function register_add_tag(): void {
        wp_register_ability(
            'mainwp/add-tag-v1',
            array(
                'label'               => __( 'Add MainWP Tag', 'mainwp' ),
                'description'         => __( 'Create a new MainWP tag.', 'mainwp' ),
                'category'            => 'mainwp-tags',
                'input_schema'        => self::get_add_tag_input_schema(),
                'output_schema'       => self::get_tag_output_schema(),
                'execute_callback'    => array( self::class, 'execute_add_tag' ),
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/add-tag-v1', array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ) ),
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
     * Register mainwp/update-tag-v1 ability.
     *
     * @return void
     */
    private static function register_update_tag(): void {
        wp_register_ability(
            'mainwp/update-tag-v1',
            array(
                'label'               => __( 'Update MainWP Tag', 'mainwp' ),
                'description'         => __( 'Update an existing MainWP tag.', 'mainwp' ),
                'category'            => 'mainwp-tags',
                'input_schema'        => self::get_update_tag_input_schema(),
                'output_schema'       => self::get_tag_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_tag' ),
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/update-tag-v1', array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ) ),
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
     * Register mainwp/delete-tag-v1 ability.
     *
     * @return void
     */
    private static function register_delete_tag(): void {
        wp_register_ability(
            'mainwp/delete-tag-v1',
            array(
                'label'               => __( 'Delete MainWP Tag', 'mainwp' ),
                'description'         => __( 'Delete a MainWP tag. Requires confirmation or supports dry-run mode.', 'mainwp' ),
                'category'            => 'mainwp-tags',
                'input_schema'        => self::get_delete_tag_input_schema(),
                'output_schema'       => self::get_delete_tag_output_schema(),
                'execute_callback'    => array( self::class, 'execute_delete_tag' ),
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/delete-tag-v1', array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Destructive operation - requires confirm:true or dry_run:true. Only call when user explicitly requests deletion.',
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/get-tag-sites-v1 ability.
     *
     * @return void
     */
    private static function register_get_tag_sites(): void {
        wp_register_ability(
            'mainwp/get-tag-sites-v1',
            array(
                'label'               => __( 'Get Tag Sites', 'mainwp' ),
                'description'         => __( 'Get list of sites associated with a tag.', 'mainwp' ),
                'category'            => 'mainwp-tags',
                'input_schema'        => self::get_tag_sites_input_schema(),
                'output_schema'       => self::get_tag_sites_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_tag_sites' ),
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
     * Register mainwp/get-tag-clients-v1 ability.
     *
     * @return void
     */
    private static function register_get_tag_clients(): void {
        wp_register_ability(
            'mainwp/get-tag-clients-v1',
            array(
                'label'               => __( 'Get Tag Clients', 'mainwp' ),
                'description'         => __( 'Get list of clients associated with a tag.', 'mainwp' ),
                'category'            => 'mainwp-tags',
                'input_schema'        => self::get_tag_clients_input_schema(),
                'output_schema'       => self::get_tag_clients_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_tag_clients' ),
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

    // =========================================================================
    // Input Schema Definitions
    // =========================================================================

    /**
     * Get input schema for list-tags-v1.
     *
     * @return array
     */
    private static function get_list_tags_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'page'     => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number (1-based).', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
                'search'   => array(
                    'type'        => 'string',
                    'description' => __( 'Search term for tag name or ID.', 'mainwp' ),
                    'default'     => '',
                ),
                'include'  => array(
                    'type'        => 'array',
                    'description' => __( 'Array of tag IDs to include.', 'mainwp' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                ),
                'exclude'  => array(
                    'type'        => 'array',
                    'description' => __( 'Array of tag IDs to exclude.', 'mainwp' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for get-tag-v1.
     *
     * @return array
     */
    private static function get_get_tag_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'tag_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Tag ID.', 'mainwp' ),
                    'minimum'     => 1,
                ),
            ),
            'required'             => array( 'tag_id' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for add-tag-v1.
     *
     * @return array
     */
    private static function get_add_tag_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'name'  => array(
                    'type'        => 'string',
                    'description' => __( 'Tag name.', 'mainwp' ),
                    'minLength'   => 1,
                ),
                'color' => array(
                    'type'        => 'string',
                    'description' => __( 'Tag color (hex format, e.g., #3498db).', 'mainwp' ),
                    'pattern'     => '^#[0-9A-Fa-f]{6}$',
                ),
            ),
            'required'             => array( 'name' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for update-tag-v1.
     *
     * @return array
     */
    private static function get_update_tag_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'tag_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Tag ID.', 'mainwp' ),
                    'minimum'     => 1,
                ),
                'name'   => array(
                    'type'        => 'string',
                    'description' => __( 'Tag name.', 'mainwp' ),
                    'minLength'   => 1,
                ),
                'color'  => array(
                    'type'        => 'string',
                    'description' => __( 'Tag color (hex format, e.g., #3498db).', 'mainwp' ),
                    'pattern'     => '^#[0-9A-Fa-f]{6}$',
                ),
            ),
            'required'             => array( 'tag_id' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for delete-tag-v1.
     *
     * @return array
     */
    private static function get_delete_tag_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'tag_id'  => array(
                    'type'        => 'integer',
                    'description' => __( 'Tag ID.', 'mainwp' ),
                    'minimum'     => 1,
                ),
                'confirm' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Confirm deletion (required unless dry_run is true).', 'mainwp' ),
                    'default'     => false,
                ),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Preview deletion without executing.', 'mainwp' ),
                    'default'     => false,
                ),
            ),
            'required'             => array( 'tag_id' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for get-tag-sites-v1.
     *
     * @return array
     */
    private static function get_tag_sites_input_schema(): array { // phpcs:ignore -- NOSONAR -- repeat function.
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'tag_id'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Tag ID.', 'mainwp' ),
                    'minimum'     => 1,
                ),
                'page'     => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number (1-based).', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
            ),
            'required'             => array( 'tag_id' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for get-tag-clients-v1.
     *
     * @return array
     */
    private static function get_tag_clients_input_schema(): array { // phpcs:ignore -- NOSONAR -- repeat function.
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'tag_id'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Tag ID.', 'mainwp' ),
                    'minimum'     => 1,
                ),
                'page'     => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number (1-based).', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
            ),
            'required'             => array( 'tag_id' ),
            'additionalProperties' => false,
        );
    }

    // =========================================================================
    // Output Schema Definitions
    // =========================================================================

    /**
     * Get output schema for list-tags-v1.
     *
     * @return array
     */
    private static function get_list_tags_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'    => array(
                    'type'        => 'array',
                    'description' => __( 'List of tags.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'          => array(
                                'type'        => 'integer',
                                'description' => __( 'Tag ID.', 'mainwp' ),
                            ),
                            'name'        => array(
                                'type'        => 'string',
                                'description' => __( 'Tag name.', 'mainwp' ),
                            ),
                            'color'       => array(
                                'type'        => array( 'string', 'null' ),
                                'description' => __( 'Tag color (hex format).', 'mainwp' ),
                            ),
                            'sites_count' => array(
                                'type'        => 'integer',
                                'description' => __( 'Number of sites with this tag.', 'mainwp' ),
                            ),
                            'sites_ids'   => array(
                                'type'        => 'array',
                                'description' => __( 'Array of site IDs with this tag.', 'mainwp' ),
                                'items'       => array(
                                    'type' => 'integer',
                                ),
                            ),
                        ),
                    ),
                ),
                'page'     => array(
                    'type'        => 'integer',
                    'description' => __( 'Current page number.', 'mainwp' ),
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                ),
                'total'    => array(
                    'type'        => 'integer',
                    'description' => __( 'Total number of tags matching the query.', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Get output schema for single tag (get-tag-v1, add-tag-v1, update-tag-v1).
     *
     * @return array
     */
    private static function get_tag_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Tag ID.', 'mainwp' ),
                ),
                'name'        => array(
                    'type'        => 'string',
                    'description' => __( 'Tag name.', 'mainwp' ),
                ),
                'color'       => array(
                    'type'        => array( 'string', 'null' ),
                    'description' => __( 'Tag color (hex format).', 'mainwp' ),
                ),
                'sites_count' => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of sites with this tag.', 'mainwp' ),
                ),
                'sites_ids'   => array(
                    'type'        => 'array',
                    'description' => __( 'Array of site IDs with this tag.', 'mainwp' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                ),
            ),
        );
    }

    /**
     * Get output schema for delete-tag.
     *
     * Uses required arrays to ensure oneOf branches are mutually exclusive:
     * - Deletion response requires 'deleted' and 'tag'
     * - Dry-run response requires 'dry_run' and 'would_affect'
     *
     * @return array
     */
    private static function get_delete_tag_output_schema(): array {
        return array(
            'oneOf' => array(
                array(
                    'type'        => 'object',
                    'description' => __( 'Successful deletion response.', 'mainwp' ),
                    'required'    => array( 'deleted', 'tag' ),
                    'properties'  => array(
                        'deleted' => array(
                            'type'        => 'boolean',
                            'description' => __( 'Whether the tag was deleted.', 'mainwp' ),
                        ),
                        'tag'     => array(
                            'type'       => 'object',
                            'properties' => array(
                                'id'   => array(
                                    'type' => 'integer',
                                ),
                                'name' => array(
                                    'type' => 'string',
                                ),
                            ),
                        ),
                    ),
                ),
                array(
                    'type'        => 'object',
                    'description' => __( 'Dry run response.', 'mainwp' ),
                    'required'    => array( 'dry_run', 'would_affect' ),
                    'properties'  => array(
                        'dry_run'      => array(
                            'type'        => 'boolean',
                            'description' => __( 'Indicates this is a dry run.', 'mainwp' ),
                        ),
                        'would_affect' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'id'            => array(
                                    'type' => 'integer',
                                ),
                                'name'          => array(
                                    'type' => 'string',
                                ),
                                'sites_count'   => array(
                                    'type' => 'integer',
                                ),
                                'clients_count' => array(
                                    'type' => 'integer',
                                ),
                            ),
                        ),
                        'warnings'     => array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'string',
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Get output schema for get-tag-sites-v1.
     *
     * @return array
     */
    private static function get_tag_sites_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'    => array(
                    'type'        => 'array',
                    'description' => __( 'List of sites.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'        => array(
                                'type'        => 'integer',
                                'description' => __( 'Site ID.', 'mainwp' ),
                            ),
                            'url'       => array(
                                'type'        => 'string',
                                'description' => __( 'Site URL.', 'mainwp' ),
                            ),
                            'name'      => array(
                                'type'        => 'string',
                                'description' => __( 'Site name.', 'mainwp' ),
                            ),
                            'status'    => array(
                                'type'        => 'string',
                                'description' => __( 'Connection status.', 'mainwp' ),
                            ),
                            'client_id' => array(
                                'type'        => array( 'integer', 'null' ),
                                'description' => __( 'Assigned client ID.', 'mainwp' ),
                            ),
                        ),
                    ),
                ),
                'page'     => array(
                    'type'        => 'integer',
                    'description' => __( 'Current page number.', 'mainwp' ),
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                ),
                'total'    => array(
                    'type'        => 'integer',
                    'description' => __( 'Total number of sites with this tag.', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Get output schema for get-tag-clients-v1.
     *
     * @return array
     */
    private static function get_tag_clients_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'    => array(
                    'type'        => 'array',
                    'description' => __( 'List of clients.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'client_id' => array(
                                'type'        => 'integer',
                                'description' => __( 'Client ID.', 'mainwp' ),
                            ),
                            'name'      => array(
                                'type'        => 'string',
                                'description' => __( 'Client name.', 'mainwp' ),
                            ),
                            'email'     => array(
                                'type'        => 'string',
                                'description' => __( 'Client email address.', 'mainwp' ),
                            ),
                            'suspended' => array(
                                'type'        => 'integer',
                                'description' => __( 'Suspension status (0=active, 1=suspended).', 'mainwp' ),
                            ),
                        ),
                    ),
                ),
                'page'     => array(
                    'type'        => 'integer',
                    'description' => __( 'Current page number.', 'mainwp' ),
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                ),
                'total'    => array(
                    'type'        => 'integer',
                    'description' => __( 'Total number of clients with this tag.', 'mainwp' ),
                ),
            ),
        );
    }

    // =========================================================================
    // Execute Callback Methods
    // =========================================================================

    /**
     * Trim per-site data in a formatted tag to the caller's allowed sites.
     *
     * A group grant does not imply every member site is allowed, because the
     * sites and groups filters are independent Team Control dimensions, so
     * sites_ids and sites_count must reflect only the caller's slice.
     *
     * @param array $formatted Formatted tag from format_tag_for_output().
     * @return array Formatted tag with scoped per-site data.
     */
    private static function scope_tag_output( array $formatted ) {
        $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();
        if ( 'all' === $allowed_sites ) {
            return $formatted;
        }

        if ( isset( $formatted['sites_ids'] ) && is_array( $formatted['sites_ids'] ) ) {
            $formatted['sites_ids']   = array_values( array_intersect( $formatted['sites_ids'], $allowed_sites ) );
            $formatted['sites_count'] = count( $formatted['sites_ids'] );
        } else {
            $formatted['sites_count'] = 0;
        }

        return $formatted;
    }

    /**
     * Execute list-tags-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error List of tags with pagination or error.
     */
    public static function execute_list_tags( $input ) {
        $allowed_groups = MainWP_Abilities_Util::get_allowed_group_ids();

        $input = ! is_null( $input ) ? (array) $input : array();

        // Sanitize and bound pagination parameters.
        $page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
        $per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;

        // Sanitize search string.
        $search = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';

        // Normalize and sanitize include/exclude arrays.
        $include = isset( $input['include'] ) && is_array( $input['include'] )
            ? array_map( 'absint', $input['include'] )
            : array();
        $exclude = isset( $input['exclude'] ) && is_array( $input['exclude'] )
            ? array_map( 'absint', $input['exclude'] )
            : array();

        if ( 'all' !== $allowed_groups ) {
            $include = empty( $include )
                ? $allowed_groups
                : array_values( array_intersect( $include, $allowed_groups ) );

            if ( empty( $include ) ) {
                return array(
                    'items'    => array(),
                    'page'     => $page,
                    'per_page' => $per_page,
                    'total'    => 0,
                );
            }
        }

        $params = array(
            'page'           => $page,
            'per_page'       => $per_page,
            's'              => $search,
            'include'        => $include,
            'exclude'        => $exclude,
            'with_sites_ids' => true,
        );

        $tags = MainWP_DB_Common::instance()->get_tags( $params );

        if ( ! is_array( $tags ) ) {
            return new \WP_Error(
                'mainwp_operation_failed',
                __( 'Failed to retrieve tags.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        // Get total count efficiently using count-only query.
        $count_params = array(
            's'       => $search,
            'include' => $include,
            'exclude' => $exclude,
            'count'   => true,
        );
        $total        = MainWP_DB_Common::instance()->get_tags( $count_params );

        $formatted_tags = array();
        foreach ( $tags as $tag ) {
            $formatted_tags[] = self::scope_tag_output( MainWP_Abilities_Util::format_tag_for_output( $tag ) );
        }

        return array(
            'items'    => $formatted_tags,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => (int) $total,
        );
    }

    /**
     * Execute get-tag-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error Tag object or error.
     */
    public static function execute_get_tag( $input ) {
        $tag_id = (int) $input['tag_id'];

        $tag = MainWP_Abilities_Util::resolve_tag( $tag_id );

        if ( is_wp_error( $tag ) ) {
            return $tag;
        }

        $access = MainWP_Abilities_Util::check_tag_access( $tag, $input );
        if ( is_wp_error( $access ) ) {
            return $access;
        }

        $params = array(
            'include'        => array( $tag_id ),
            'with_sites_ids' => true,
        );

        $tags = MainWP_DB_Common::instance()->get_tags( $params );

        if ( empty( $tags ) ) {
            return new \WP_Error(
                'mainwp_operation_failed',
                __( 'Failed to retrieve tag details.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $tag_with_data = reset( $tags );

        return self::scope_tag_output( MainWP_Abilities_Util::format_tag_for_output( $tag_with_data ) );
    }

    /**
     * Execute add-tag-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error Created tag object or error.
     */
    public static function execute_add_tag( $input ) {
        $name  = sanitize_text_field( $input['name'] );
        $color = isset( $input['color'] ) ? sanitize_hex_color( $input['color'] ) : null;
        // sanitize_hex_color() returns empty string for invalid input; normalize to null.
        if ( '' === $color ) {
            $color = null;
        }

        $existing = MainWP_DB_Common::instance()->get_group_by_name( $name );
        if ( ! empty( $existing ) ) {
            return new \WP_Error(
                'mainwp_already_exists',
                sprintf(
                    /* translators: %s: tag name */
                    __( 'A tag with name "%s" already exists.', 'mainwp' ),
                    $name
                ),
                array( 'status' => 400 )
            );
        }

        $params = array(
            'name' => $name,
        );

        if ( null !== $color ) {
            $params['color'] = $color;
        }

        $created_tag = MainWP_DB_Common::instance()->add_tag( $params );

        if ( empty( $created_tag ) || ! isset( $created_tag->id ) ) {
            return new \WP_Error(
                'mainwp_operation_failed',
                __( 'Failed to create tag.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $tags = MainWP_DB_Common::instance()->get_tags(
            array(
                'include'        => array( (int) $created_tag->id ),
                'with_sites_ids' => true,
            )
        );

        if ( empty( $tags ) ) {
            return new \WP_Error(
                'mainwp_operation_failed',
                __( 'Failed to retrieve created tag.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return MainWP_Abilities_Util::format_tag_for_output( reset( $tags ) );
    }

    /**
     * Execute update-tag-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error Updated tag object or error.
     */
    public static function execute_update_tag( $input ) {
        $tag_id = (int) $input['tag_id'];

        $tag = MainWP_Abilities_Util::resolve_tag( $tag_id );

        if ( is_wp_error( $tag ) ) {
            return $tag;
        }

        $access = MainWP_Abilities_Util::check_tag_access( $tag, $input );
        if ( is_wp_error( $access ) ) {
            return $access;
        }

        $params = array(
            'id' => $tag_id,
        );

        if ( isset( $input['name'] ) ) {
            $params['name'] = sanitize_text_field( $input['name'] );
        } else {
            $params['name'] = $tag->name;
        }

        // Check for duplicate name if the name is being changed.
        if ( $params['name'] !== $tag->name ) {
            $existing = MainWP_DB_Common::instance()->get_group_by_name( $params['name'] );
            if ( ! empty( $existing ) && (int) $existing->id !== $tag_id ) {
                return new \WP_Error(
                    'mainwp_already_exists',
                    sprintf(
                        /* translators: %s: tag name */
                        __( 'A tag with name "%s" already exists.', 'mainwp' ),
                        $params['name']
                    ),
                    array( 'status' => 400 )
                );
            }
        }

        if ( isset( $input['color'] ) ) {
            $sanitized_color = sanitize_hex_color( $input['color'] );
            if ( ! empty( $sanitized_color ) ) {
                $params['color'] = $sanitized_color;
            }
            // If sanitized_color is empty, do not set $params['color'] to preserve existing color.
        }

        // add_tag() is used as an upsert: when 'id' is present in params, it updates the existing tag;
        // when 'id' is absent, it creates a new tag. This allows unified insert/update logic.
        $updated_tag = MainWP_DB_Common::instance()->add_tag( $params );

        if ( empty( $updated_tag ) || ! isset( $updated_tag->id ) ) {
            return new \WP_Error(
                'mainwp_operation_failed',
                __( 'Failed to update tag.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $tags = MainWP_DB_Common::instance()->get_tags(
            array(
                'include'        => array( $tag_id ),
                'with_sites_ids' => true,
            )
        );

        if ( empty( $tags ) ) {
            return new \WP_Error(
                'mainwp_operation_failed',
                __( 'Failed to retrieve updated tag.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return self::scope_tag_output( MainWP_Abilities_Util::format_tag_for_output( reset( $tags ) ) );
    }

    /**
     * Execute delete-tag-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error Deletion result or error.
     */
    public static function execute_delete_tag( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $tag_id  = (int) $input['tag_id'];
        $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
        $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );

        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $tag = MainWP_Abilities_Util::resolve_tag( $tag_id );

        if ( is_wp_error( $tag ) ) {
            return $tag;
        }

        $access = MainWP_Abilities_Util::check_tag_access( $tag, $input );
        if ( is_wp_error( $access ) ) {
            return $access;
        }

        $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();
        if ( 'all' !== $allowed_sites ) {
            // Deletion removes every membership, so check the unscoped roster.
            $sites = MainWP_DB::instance()->get_websites_by_group_ids( array( $tag_id ) );
            if ( ! is_array( $sites ) || array_diff( array_map( static fn( $site ) => (int) $site->id, $sites ), $allowed_sites ) ) {
                return new \WP_Error(
                    'mainwp_access_denied',
                    __( 'You do not have permission to access all sites in this tag.', 'mainwp' ),
                    array( 'status' => 403 )
                );
            }
        }

        if ( $dry_run ) {
            // The full-coverage check above already proved every site in the
            // tag is allowed, so the global counts are the caller's counts.
            $tag_data    = MainWP_DB_Common::instance()->get_tags( array( 'include' => array( $tag_id ) ) );
            $sites_count = 0;
            if ( ! empty( $tag_data ) && is_array( $tag_data ) ) {
                $tag_obj     = reset( $tag_data );
                $sites_count = isset( $tag_obj->count_sites ) ? (int) $tag_obj->count_sites : 0;
            }

            $clients_count = MainWP_DB_Client::instance()->get_wp_client_by(
                'all',
                null,
                OBJECT,
                array(
                    'by_tags'    => array( $tag_id ),
                    'count_only' => true,
                )
            );
            $clients_count = is_numeric( $clients_count ) ? (int) $clients_count : 0;

            return array(
                'dry_run'      => true,
                'would_affect' => array(
                    'id'            => (int) $tag->id,
                    'name'          => (string) $tag->name,
                    'sites_count'   => $sites_count,
                    'clients_count' => $clients_count,
                ),
                'warnings'     => array(),
            );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Tag deletion requires confirmation. Set confirm:true to proceed.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $result = MainWP_DB_Common::instance()->remove_group( $tag_id );

        if ( empty( $result ) ) {
            return new \WP_Error(
                'mainwp_operation_failed',
                __( 'Failed to delete tag.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return array(
            'deleted' => true,
            'tag'     => array(
                'id'   => (int) $tag->id,
                'name' => (string) $tag->name,
            ),
        );
    }

    /**
     * Execute get-tag-sites-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error List of sites with pagination or error.
     */
    public static function execute_get_tag_sites( $input ) {
        $tag_id   = (int) $input['tag_id'];
        $page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
        $per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;

        $tag = MainWP_Abilities_Util::resolve_tag( $tag_id );

        if ( is_wp_error( $tag ) ) {
            return $tag;
        }

        $access = MainWP_Abilities_Util::check_tag_access( $tag, $input );
        if ( is_wp_error( $access ) ) {
            return $access;
        }

        $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();
        $offset        = ( $page - 1 ) * $per_page;

        if ( 'all' === $allowed_sites ) {
            $sites = MainWP_DB::instance()->get_websites_by_group_id( $tag_id, false, 'wp.url', $offset, $per_page );

            if ( ! is_array( $sites ) ) {
                return new \WP_Error(
                    'mainwp_operation_failed',
                    __( 'Failed to retrieve sites for tag.', 'mainwp' ),
                    array( 'status' => 500 )
                );
            }

            $total = MainWP_DB::instance()->get_websites_count_by_group_id( $tag_id );
        } else {
            // A group grant does not imply every member site is allowed:
            // fetch the full membership, trim to the caller's slice, and
            // paginate in PHP (SQL LIMIT would page before the trim).
            $sites = MainWP_DB::instance()->get_websites_by_group_id( $tag_id );

            if ( ! is_array( $sites ) ) {
                return new \WP_Error(
                    'mainwp_operation_failed',
                    __( 'Failed to retrieve sites for tag.', 'mainwp' ),
                    array( 'status' => 500 )
                );
            }

            $sites = array_values(
                array_filter(
                    $sites,
                    static fn( $site ) => in_array( (int) $site->id, $allowed_sites, true )
                )
            );
            $total = count( $sites );
            $sites = array_slice( $sites, $offset, $per_page );
        }

        $formatted_sites = array();
        foreach ( $sites as $site ) {
            $formatted_sites[] = MainWP_Abilities_Util::format_site_for_output( $site );
        }

        return array(
            'items'    => $formatted_sites,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
        );
    }

    /**
     * Execute get-tag-clients-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|WP_Error List of clients with pagination or error.
     */
    public static function execute_get_tag_clients( $input ) {
        $tag_id   = (int) $input['tag_id'];
        $page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
        $per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;

        $tag = MainWP_Abilities_Util::resolve_tag( $tag_id );

        if ( is_wp_error( $tag ) ) {
            return $tag;
        }

        $access = MainWP_Abilities_Util::check_tag_access( $tag, $input );
        if ( is_wp_error( $access ) ) {
            return $access;
        }

        $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();
        $offset        = ( $page - 1 ) * $per_page;

        if ( 'all' === $allowed_sites ) {
            // Get total count via efficient COUNT query.
            $total = MainWP_DB_Client::instance()->get_wp_client_by(
                'all',
                null,
                OBJECT,
                array(
                    'by_tags'    => array( $tag_id ),
                    'count_only' => true,
                )
            );

            // Get only the page of clients we need via SQL LIMIT/OFFSET.
            $clients = MainWP_DB_Client::instance()->get_wp_client_by(
                'all',
                null,
                OBJECT,
                array(
                    'by_tags' => array( $tag_id ),
                    'offset'  => $offset,
                    'limit'   => $per_page,
                )
            );

            if ( ! is_array( $clients ) ) {
                return new \WP_Error(
                    'mainwp_operation_failed',
                    __( 'Failed to retrieve clients for tag.', 'mainwp' ),
                    array( 'status' => 500 )
                );
            }
        } else {
            // The tag relation must come from an allowed site belonging to the client.
            $sites   = MainWP_DB::instance()->get_websites_by_group_ids( array( $tag_id ) );
            $clients = MainWP_DB_Client::instance()->get_wp_client_by(
                'all',
                null,
                OBJECT,
                array(
                    'by_tags' => array( $tag_id ),
                )
            );

            if ( ! is_array( $sites ) || ! is_array( $clients ) ) {
                return new \WP_Error(
                    'mainwp_operation_failed',
                    __( 'Failed to retrieve clients for tag.', 'mainwp' ),
                    array( 'status' => 500 )
                );
            }

            $eligible_client_ids = array();
            foreach ( $sites as $site ) {
                if ( in_array( (int) $site->id, $allowed_sites, true ) && (int) $site->client_id > 0 ) {
                    $eligible_client_ids[ (int) $site->client_id ] = true;
                }
            }

            $clients = array_values(
                array_filter(
                    $clients,
                    static fn( $client ) => isset( $eligible_client_ids[ (int) $client->client_id ] )
                )
            );
            $total   = count( $clients );
            $clients = array_slice( $clients, $offset, $per_page );
        }

        $formatted_clients = array();
        foreach ( $clients as $client ) {
            $formatted_clients[] = MainWP_Abilities_Util::format_client_for_output( $client );
        }

        return array(
            'items'    => $formatted_clients,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
        );
    }
}
