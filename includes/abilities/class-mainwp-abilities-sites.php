<?php
/**
 * MainWP Sites Abilities
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Sites
 *
 * Registers and implements site-related abilities for the MainWP Dashboard.
 *
 * This class provides 5 abilities:
 * - mainwp/list-sites-v1: List MainWP child sites with pagination and filtering
 * - mainwp/get-site-v1: Get detailed information about a single site
 * - mainwp/sync-sites-v1: Trigger synchronization for one or more sites
 * - mainwp/get-site-plugins-v1: Get list of plugins installed on a site
 * - mainwp/get-site-themes-v1: Get list of themes installed on a site
 *
 * ## Input Handling for GET Requests
 *
 * Read-only abilities (readonly: true) use GET requests. WordPress REST API does NOT
 * auto-parse JSON from query strings, so:
 *
 * - Omit `?input` parameter entirely to use schema defaults (recommended)
 * - Use `?input=` (empty) which also triggers defaults
 * - DO NOT use `?input=%7B%7D` (URL-encoded JSON) - it arrives as a string and fails validation
 *
 * Our input schemas use `'type' => array('object', 'null')` with defaults, so callers
 * can simply call the endpoint without any input parameter.
 *
 * @see .mwpdev/docs/abilities-api-docs/known-issues.md for detailed explanation
 */
class MainWP_Abilities_Sites { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Register all site abilities.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        self::register_list_sites();
        self::register_get_site();
        self::register_sync_sites();
        self::register_get_site_plugins();
        self::register_get_site_themes();

        // Connection Management (8).
        self::register_add_site();
        self::register_update_site();
        self::register_delete_site();
        self::register_reconnect_site();
        self::register_disconnect_site();
        self::register_suspend_site();
        self::register_unsuspend_site();
        self::register_check_site();

        // Plugin Management (4).
        self::register_activate_site_plugins();
        self::register_deactivate_site_plugins();
        self::register_delete_site_plugins();
        self::register_get_abandoned_plugins();

        // Theme Management (3).
        self::register_activate_site_theme();
        self::register_delete_site_themes();
        self::register_get_abandoned_themes();

        // Security & Monitoring (2).
        self::register_get_site_security();
        self::register_get_site_changes();

        // Related Data (4).
        self::register_get_site_client();
        self::register_get_site_costs();
        self::register_count_sites();
        self::register_get_sites_basic();

        // Batch Operations (4).
        self::register_reconnect_sites();
        self::register_disconnect_sites();
        self::register_check_sites();
        self::register_suspend_sites();
    }

    /**
     * Register mainwp/list-sites-v1 ability.
     *
     * @return void
     */
    private static function register_list_sites(): void {
        wp_register_ability(
            'mainwp/list-sites-v1',
            array(
                'label'               => __( 'List MainWP Sites', 'mainwp' ),
                'description'         => __( 'List MainWP child sites with pagination and filtering. Returns basic site information including ID, URL, name, and connection status.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_list_sites_input_schema(),
                'output_schema'       => self::get_list_sites_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_sites' ),
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
     * Register mainwp/get-site-v1 ability.
     *
     * @return void
     */
    private static function register_get_site(): void {
        wp_register_ability(
            'mainwp/get-site-v1',
            array(
                'label'               => __( 'Get MainWP Site', 'mainwp' ),
                'description'         => __( 'Get detailed information about a single MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_get_site_input_schema(),
                'output_schema'       => self::get_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
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
     * Register mainwp/sync-sites-v1 ability.
     *
     * @return void
     */
    private static function register_sync_sites(): void {
        wp_register_ability(
            'mainwp/sync-sites-v1',
            array(
                'label'               => __( 'Sync MainWP Sites', 'mainwp' ),
                'description'         => __( 'Trigger synchronization for one or more child sites. Operations with >200 sites are automatically queued for background processing.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_sync_sites_input_schema(),
                'output_schema'       => self::get_sync_output_schema(),
                'execute_callback'    => array( self::class, 'execute_sync_sites' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Pass site_ids_or_domains with specific IDs, or empty array for all applicable sites. Operations with >200 sites are automatically queued for background processing.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/get-site-plugins-v1 ability.
     *
     * @return void
     */
    private static function register_get_site_plugins(): void {
        wp_register_ability(
            'mainwp/get-site-plugins-v1',
            array(
                'label'               => __( 'Get Site Plugins', 'mainwp' ),
                'description'         => __( 'Get list of plugins installed on a child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_site_plugins_input_schema(),
                'output_schema'       => self::get_plugins_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site_plugins' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
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
     * Register mainwp/get-site-themes-v1 ability.
     *
     * @return void
     */
    private static function register_get_site_themes(): void {
        wp_register_ability(
            'mainwp/get-site-themes-v1',
            array(
                'label'               => __( 'Get Site Themes', 'mainwp' ),
                'description'         => __( 'Get list of themes installed on a child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_site_themes_input_schema(),
                'output_schema'       => self::get_themes_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site_themes' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
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
     * Get input schema for list-sites-v1.
     *
     * Note: Uses 'type' => array('object', 'null') to allow callers to omit the input
     * parameter entirely on GET requests. All properties have defaults, so no input
     * is required. See class docblock for GET request input handling details.
     *
     * @return array
     */
    public static function get_list_sites_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'page'      => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number (1-based).', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page'  => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
                'status'    => array(
                    'type'        => 'string',
                    'description' => __( 'Filter by connection status.', 'mainwp' ),
                    'enum'        => array( 'any', 'connected', 'disconnected', 'suspended', 'available_update' ),
                    'default'     => 'any',
                ),
                'search'    => array(
                    'type'        => 'string',
                    'description' => __( 'Search term for site name or URL.', 'mainwp' ),
                    'default'     => '',
                ),
                'client_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter by client ID.', 'mainwp' ),
                    'minimum'     => 1,
                ),
                'tag_id'    => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter by tag/group ID.', 'mainwp' ),
                    'minimum'     => 1,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for get-site-v1.
     *
     * Note: We use type: ["integer", "string"] instead of oneOf because JSON Schema
     * validators fail when a numeric string like "123" matches multiple oneOf branches.
     *
     * @return array
     */
    private static function get_get_site_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (integer) or domain/URL (string).', 'mainwp' ),
                ),
                'include_stats'     => array(
                    'type'        => 'boolean',
                    'description' => __( 'Include site statistics (updates count, health, etc.).', 'mainwp' ),
                    'default'     => false,
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for sync-sites-v1.
     *
     * @return array
     */
    private static function get_sync_sites_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'description' => __( 'Site IDs or domains to sync. Empty array means all sites.', 'mainwp' ),
                    'items'       => array(
                        'type' => array( 'integer', 'string' ),
                    ),
                    'default'     => array(),
                ),
                'site_ids'            => array(
                    'type'        => 'array',
                    'description' => __( 'Site IDs to sync. Empty array means all sites.', 'mainwp' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                    'default'     => array(),
                ),
                'exclude_ids'         => array(
                    'type'        => 'array',
                    'description' => __( 'Site IDs to exclude from sync.', 'mainwp' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                    'default'     => array(),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for get-site-plugins-v1.
     *
     * @return array
     */
    private static function get_site_plugins_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain/URL.', 'mainwp' ),
                ),
                'status'            => array(
                    'type'        => 'string',
                    'enum'        => array( 'all', 'active', 'inactive' ),
                    'default'     => 'all',
                    'description' => __( 'Filter by plugin status.', 'mainwp' ),
                ),
                'has_update'        => array(
                    'type'        => 'boolean',
                    'description' => __( 'Filter to only plugins with available updates.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for get-site-themes-v1.
     *
     * @return array
     */
    private static function get_site_themes_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain/URL.', 'mainwp' ),
                ),
                'status'            => array(
                    'type'        => 'string',
                    'enum'        => array( 'all', 'active', 'inactive' ),
                    'default'     => 'all',
                    'description' => __( 'Filter by theme status.', 'mainwp' ),
                ),
                'has_update'        => array(
                    'type'        => 'boolean',
                    'description' => __( 'Filter to only themes with available updates.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    // =========================================================================
    // Output Schema Definitions
    // =========================================================================

    /**
     * Get output schema for list-sites-v1.
     *
     * @return array
     */
    public static function get_list_sites_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'    => array(
                    'type'        => 'array',
                    'description' => __( 'List of sites.', 'mainwp' ),
                    'items'       => array(
                        'type'                 => 'object',
                        'properties'           => array(
                            'id'        => array(
                                'type'        => 'integer',
                                'description' => __( 'MainWP site ID.', 'mainwp' ),
                            ),
                            'url'       => array(
                                'type'        => 'string',
                                'format'      => 'uri',
                                'description' => __( 'Site URL.', 'mainwp' ),
                            ),
                            'name'      => array(
                                'type'        => 'string',
                                'description' => __( 'Site name or label.', 'mainwp' ),
                            ),
                            'status'    => array(
                                'type'        => 'string',
                                'enum'        => array( 'connected', 'disconnected', 'suspended' ),
                                'description' => __( 'Connection status.', 'mainwp' ),
                            ),
                            'client_id' => array(
                                'oneOf'       => array(
                                    array(
                                        'type'    => 'integer',
                                        'minimum' => 1,
                                    ),
                                    array( 'type' => 'null' ),
                                ),
                                'description' => __( 'Associated client ID if any.', 'mainwp' ),
                            ),
                        ),
                        'required'             => array( 'id', 'url', 'name', 'status' ),
                        'additionalProperties' => false,
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
                    'description' => __( 'Total number of sites matching filters.', 'mainwp' ),
                ),
            ),
            'required'   => array( 'items', 'page', 'per_page', 'total' ),
        );
    }

    /**
     * Get output schema for get-site-v1.
     *
     * @return array
     */
    private static function get_site_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'id'             => array(
                    'type'        => 'integer',
                    'description' => __( 'MainWP site ID.', 'mainwp' ),
                ),
                'url'            => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => __( 'Site URL.', 'mainwp' ),
                ),
                'name'           => array(
                    'type'        => 'string',
                    'description' => __( 'Site name or label.', 'mainwp' ),
                ),
                'status'         => array(
                    'type'        => 'string',
                    'enum'        => array( 'connected', 'disconnected', 'suspended' ),
                    'description' => __( 'Connection status.', 'mainwp' ),
                ),
                'client_id'      => array(
                    'oneOf'       => array(
                        array(
                            'type'    => 'integer',
                            'minimum' => 1,
                        ),
                        array( 'type' => 'null' ),
                    ),
                    'description' => __( 'Associated client ID.', 'mainwp' ),
                ),
                'wp_version'     => array(
                    'type'        => 'string',
                    'description' => __( 'WordPress version.', 'mainwp' ),
                ),
                'php_version'    => array(
                    'type'        => 'string',
                    'description' => __( 'PHP version.', 'mainwp' ),
                ),
                'last_sync'      => array(
                    'oneOf'       => array(
                        array(
                            'type'   => 'string',
                            'format' => 'date-time',
                        ),
                        array( 'type' => 'null' ),
                    ),
                    'description' => __( 'Last successful sync timestamp (ISO 8601).', 'mainwp' ),
                ),
                'admin_username' => array(
                    'type'        => 'string',
                    'description' => __( 'Admin username for child site.', 'mainwp' ),
                ),
                'child_version'  => array(
                    'type'        => 'string',
                    'description' => __( 'MainWP Child plugin version.', 'mainwp' ),
                ),
                'notes'          => array(
                    'type'        => 'string',
                    'description' => __( 'Site notes.', 'mainwp' ),
                ),
                'stats'          => array(
                    'type'        => 'object',
                    'description' => __( 'Site statistics (only if include_stats=true).', 'mainwp' ),
                    'properties'  => array(
                        'plugin_updates'      => array( 'type' => 'integer' ),
                        'theme_updates'       => array( 'type' => 'integer' ),
                        'wp_update_available' => array( 'type' => 'boolean' ),
                        'health_score'        => array(
                            'oneOf' => array(
                                array(
                                    'type'    => 'integer',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                ),
                                array( 'type' => 'null' ),
                            ),
                        ),
                    ),
                ),
            ),
            'required'   => array( 'id', 'url', 'name', 'status' ),
        );
    }

    /**
     * Get output schema for sync-sites-v1.
     *
     * @return array
     */
    private static function get_sync_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                // Immediate execution response (≤ threshold sites).
                'synced'       => array(
                    'type'        => 'array',
                    'description' => __( 'Sites successfully synced (immediate mode only).', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'   => array( 'type' => 'integer' ),
                            'url'  => array(
                                'type'   => 'string',
                                'format' => 'uri',
                            ),
                            'name' => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'id', 'url', 'name' ),
                    ),
                ),
                'errors'       => array(
                    'type'        => 'array',
                    'description' => __( 'Sites that failed to sync (immediate mode only).', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'identifier' => array(
                                'oneOf' => array(
                                    array( 'type' => 'integer' ),
                                    array( 'type' => 'string' ),
                                ),
                            ),
                            'code'       => array( 'type' => 'string' ),
                            'message'    => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'identifier', 'code', 'message' ),
                    ),
                ),
                'total_synced' => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of sites successfully synced.', 'mainwp' ),
                ),
                'total_errors' => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of sites that failed to sync.', 'mainwp' ),
                ),
                // Queued execution response (> threshold sites).
                'queued'       => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether the operation was queued for background processing.', 'mainwp' ),
                ),
                'job_id'       => array(
                    'type'        => 'string',
                    'description' => __( 'Background job ID for status polling (only when queued=true).', 'mainwp' ),
                ),
                'status_url'   => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => __( 'URL to poll for job status (only when queued=true).', 'mainwp' ),
                ),
                'sites_queued' => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of sites queued for sync (only when queued=true).', 'mainwp' ),
                ),
            ),
            // Note: Either (synced, errors, total_synced, total_errors) OR (queued, job_id, status_url, sites_queued) will be present.
            'required'   => array(),
        );
    }

    /**
     * Get output schema for get-site-plugins-v1.
     *
     * @return array
     */
    private static function get_plugins_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'site_id'  => array(
                    'type'        => 'integer',
                    'description' => __( 'MainWP site ID.', 'mainwp' ),
                ),
                'site_url' => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => __( 'Site URL.', 'mainwp' ),
                ),
                'plugins'  => array(
                    'type'        => 'array',
                    'description' => __( 'List of plugins.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'slug'           => array( 'type' => 'string' ),
                            'name'           => array( 'type' => 'string' ),
                            'version'        => array( 'type' => 'string' ),
                            'active'         => array( 'type' => 'boolean' ),
                            'update_version' => array(
                                'oneOf' => array(
                                    array( 'type' => 'string' ),
                                    array( 'type' => 'null' ),
                                ),
                            ),
                        ),
                        'required'   => array( 'slug', 'name', 'version', 'active' ),
                    ),
                ),
                'total'    => array(
                    'type'        => 'integer',
                    'description' => __( 'Total number of plugins.', 'mainwp' ),
                ),
            ),
            'required'   => array( 'site_id', 'site_url', 'plugins', 'total' ),
        );
    }

    /**
     * Get output schema for get-site-themes-v1.
     *
     * @return array
     */
    private static function get_themes_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'site_id'      => array(
                    'type'        => 'integer',
                    'description' => __( 'MainWP site ID.', 'mainwp' ),
                ),
                'site_url'     => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => __( 'Site URL.', 'mainwp' ),
                ),
                'active_theme' => array(
                    'type'        => 'string',
                    'description' => __( 'Currently active theme slug.', 'mainwp' ),
                ),
                'themes'       => array(
                    'type'        => 'array',
                    'description' => __( 'List of themes.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'slug'           => array( 'type' => 'string' ),
                            'name'           => array( 'type' => 'string' ),
                            'version'        => array( 'type' => 'string' ),
                            'active'         => array( 'type' => 'boolean' ),
                            'update_version' => array(
                                'oneOf' => array(
                                    array( 'type' => 'string' ),
                                    array( 'type' => 'null' ),
                                ),
                            ),
                        ),
                        'required'   => array( 'slug', 'name', 'version', 'active' ),
                    ),
                ),
                'total'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Total number of themes.', 'mainwp' ),
                ),
            ),
            'required'   => array( 'site_id', 'site_url', 'active_theme', 'themes', 'total' ),
        );
    }

    // =========================================================================
    // Execute Callbacks
    // =========================================================================

    /**
     * Execute callback for mainwp/list-sites-v1.
     *
     * @param array|null $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_list_sites( $input ) {
        $input = is_array( $input ) ? $input : array();
        $db    = MainWP_DB::instance();

        // Map ability input to DB method parameters.
        $db_params = array(
            'paged'          => $input['page'] ?? 1,
            'items_per_page' => $input['per_page'] ?? 20,
            's'              => $input['search'] ?? '',
            // Include status-related fields for format_site_for_output().
            'fields'         => array( 'suspended', 'offline_check_result', 'sync_errors' ),
        );

        // Status mapping: 'any' means no filter, otherwise wrap in array.
        // Note: 'connected' filter adds 'unsuspended' to exclude suspended sites,
        // ensuring returned items have consistent status='connected' in output.
        $status = $input['status'] ?? 'any';
        if ( 'any' !== $status ) {
            $db_status = array( $status );
            // When filtering for 'connected', also exclude suspended sites.
            if ( 'connected' === $status ) {
                $db_status[] = 'unsuspended';
            }
            $db_params['status'] = $db_status;
        }

        // Client filter.
        if ( isset( $input['client_id'] ) ) {
            $db_params['client'] = (string) $input['client_id'];
        }

        // Tag/group filter (map tag_id to group_id for DB).
        if ( isset( $input['tag_id'] ) ) {
            $db_params['group_id'] = $input['tag_id'];
        }

        // Get total count using efficient COUNT(*) query.
        $count_filters = array(
            'status'    => ( 'any' !== $status ) ? $status : '',
            'tags'      => isset( $input['tag_id'] ) ? array( (int) $input['tag_id'] ) : array(),
            'client_id' => isset( $input['client_id'] ) ? (int) $input['client_id'] : 0,
            's'         => $input['search'] ?? '',
        );
        $total         = $db->get_websites_count_for_current_user( $count_filters );

        // Now get the paginated subset.
        $websites = $db->get_websites_for_current_user( $db_params );

        if ( is_wp_error( $websites ) ) {
            return $websites;
        }

        // Map DB records to output schema shape.
        $items = array();
        if ( $websites ) {
            foreach ( $websites as $site ) {
                $items[] = MainWP_Abilities_Util::format_site_for_output( $site );
            }
        }

        return array(
            'items'    => $items,
            'page'     => (int) ( $input['page'] ?? 1 ),
            'per_page' => (int) ( $input['per_page'] ?? 20 ),
            'total'    => (int) $total,
        );
    }

    /**
     * Execute callback for mainwp/get-site-v1.
     *
     * NOTE: Site resolution and ACL check are performed in permission_callback
     * via make_site_permission_callback(). If we reach this execute callback,
     * permissions have already been verified.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_get_site( array $input ) {
        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] );

        if ( is_wp_error( $site ) ) {
            // This shouldn't happen if permission_callback passed, but handle gracefully.
            return $site;
        }

        $include_stats = $input['include_stats'] ?? false;

        return MainWP_Abilities_Util::format_site_for_output( $site, true, $include_stats );
    }

    /**
     * Execute callback for mainwp/sync-sites-v1.
     *
     * @param array|null $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_sync_sites( $input ) { // phpcs:ignore -- NOSONAR - complexity method.
        $input               = is_array( $input ) ? $input : array();
        $site_ids            = isset( $input['site_ids'] ) && is_array( $input['site_ids'] )
            ? array_values( array_filter( array_map( 'absint', $input['site_ids'] ) ) )
            : array();
        $site_ids_or_domains = isset( $input['site_ids_or_domains'] ) && is_array( $input['site_ids_or_domains'] )
            ? $input['site_ids_or_domains']
            : array();
        if ( empty( $site_ids_or_domains ) && ! empty( $site_ids ) ) {
            $site_ids_or_domains = $site_ids;
        }

        $exclude_ids = ! empty( $input['exclude_ids'] ) && is_array( $input['exclude_ids'] )
            ? array_map( 'absint', $input['exclude_ids'] )
            : array();
        $exclude_set = $exclude_ids ? array_fill_keys( $exclude_ids, true ) : array();

        // If empty, get all sites for current user.
        if ( empty( $site_ids_or_domains ) ) {
            $all_sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );

            // Handle potential errors from DB query.
            if ( is_wp_error( $all_sites ) ) {
                return $all_sites;
            }

            $site_ids_or_domains = array();
            if ( ! empty( $all_sites ) ) {
                foreach ( $all_sites as $s ) {
                    $id = (int) $s->id;
                    if ( empty( $exclude_set ) || ! isset( $exclude_set[ $id ] ) ) {
                        $site_ids_or_domains[] = $id;
                    }
                }
            }
        } elseif ( ! empty( $exclude_set ) ) {
            // Filter numeric IDs early; domains/URLs are left as-is.
            $filtered = array();
            foreach ( $site_ids_or_domains as $identifier ) {
                if ( is_numeric( $identifier ) ) {
                    $id = (int) $identifier;
                    if ( isset( $exclude_set[ $id ] ) ) {
                        continue;
                    }
                    $filtered[] = $id;
                } else {
                    $filtered[] = $identifier;
                }
            }
            $site_ids_or_domains = $filtered;
        }

        // Check per-site ACLs and filter to allowed sites.
        $access_check = MainWP_Abilities_Util::check_batch_site_access( $site_ids_or_domains, $input );

        // Queue if > threshold sites.
        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', 200 );
        if ( count( $access_check['allowed'] ) > $threshold ) {
            $job_id = MainWP_Abilities_Util::queue_batch_sync( $access_check['allowed'] );

            // Handle queue failure.
            if ( is_wp_error( $job_id ) ) {
                return $job_id;
            }

            return array(
                'queued'       => true,
                'job_id'       => $job_id,
                'status_url'   => rest_url( "mainwp/v2/jobs/{$job_id}" ),
                'sites_queued' => count( $access_check['allowed'] ),
            );
        }

        // Immediate execution for ≤ threshold sites.
        $synced = array();
        $errors = $access_check['denied']; // Start with ACL-denied sites.

        foreach ( $access_check['allowed'] as $site ) {
            // Check if site is known offline before attempting sync.
            if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
                $errors[] = array(
                    'identifier' => (int) $site->id,
                    'code'       => 'mainwp_site_offline',
                    'message'    => __( 'Site is known to be offline.', 'mainwp' ),
                );
                continue;
            }

            // Check child version before sync.
            $version_check = MainWP_Abilities_Util::check_child_version( $site );
            if ( is_wp_error( $version_check ) ) {
                $errors[] = array(
                    'identifier' => (int) $site->id,
                    'code'       => $version_check->get_error_code(),
                    'message'    => $version_check->get_error_message(),
                );
                continue;
            }

            // Perform sync using MainWP_Sync class.
            try {
                $result = MainWP_Sync::sync_site( $site );

                // Allow filtering of sync result for testing/extension purposes.
                $result = apply_filters( 'mainwp_sync_site_result', $result, (int) $site->id );

                if ( is_wp_error( $result ) ) {
                    $errors[] = array(
                        'identifier' => (int) $site->id,
                        'code'       => $result->get_error_code(),
                        'message'    => $result->get_error_message(),
                    );
                } elseif ( false === $result ) {
                    $errors[] = array(
                        'identifier' => (int) $site->id,
                        'code'       => 'mainwp_sync_failed',
                        'message'    => __( 'Sync operation failed.', 'mainwp' ),
                    );
                } else {
                    $synced[] = array(
                        'id'   => (int) $site->id,
                        'url'  => $site->url,
                        'name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                    );
                }
            } catch ( \Exception $e ) {
                $errors[] = array(
                    'identifier' => (int) $site->id,
                    'code'       => 'mainwp_sync_exception',
                    'message'    => $e->getMessage(),
                );
            }
        }

        return array(
            'queued'       => false,
            'synced'       => $synced,
            'errors'       => $errors,
            'total_synced' => count( $synced ),
            'total_errors' => count( $errors ),
        );
    }

    /**
     * Execute callback for mainwp/get-site-plugins-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_get_site_plugins( array $input ) { // phpcs:ignore -- NOSONAR - complexity method.
        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] );

        if ( is_wp_error( $site ) ) {
            return $site;
        }

        // ACL check is done in permission_callback, but double-check for safety.
        if ( ! MainWP_Abilities_Util::can_access_site( $site, $input ) ) {
            return new \WP_Error(
                'mainwp_access_denied',
                __( 'You do not have permission to access this site.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        // Check child version for plugin data.
        $version_check = MainWP_Abilities_Util::check_child_version( $site );
        if ( is_wp_error( $version_check ) ) {
            return $version_check;
        }

        // Get plugins from site data (stored as JSON).
        $plugins_data = ! empty( $site->plugins ) ? json_decode( $site->plugins, true ) : array();
        if ( ! is_array( $plugins_data ) ) {
            $plugins_data = array();
        }

        // Get plugin updates for this site.
        $plugin_updates = ! empty( $site->plugin_upgrades ) ? json_decode( $site->plugin_upgrades, true ) : array();
        if ( ! is_array( $plugin_updates ) ) {
            $plugin_updates = array();
        }

        $status_filter     = $input['status'] ?? 'all';
        $has_update_filter = $input['has_update'] ?? null;

        $plugins = array();
        foreach ( $plugins_data as $key => $plugin ) {
            // Support both formats:
            // - Associative array: slug is the key (e.g., 'akismet/akismet.php' => [...]).
            // - Indexed array: slug is inside the plugin data (e.g., 0 => ['slug' => 'akismet/akismet.php', ...]).
            $slug = is_string( $key ) && ! empty( $key ) ? $key : ( $plugin['slug'] ?? '' );

            // Skip plugins with invalid slugs.
            if ( empty( $slug ) || ! is_string( $slug ) ) {
                continue;
            }

            $is_active = ! empty( $plugin['active'] );

            // Apply status filter.
            if ( 'active' === $status_filter && ! $is_active ) {
                continue;
            }
            if ( 'inactive' === $status_filter && $is_active ) {
                continue;
            }

            $has_update = isset( $plugin_updates[ $slug ] );

            // Apply has_update filter if specified.
            if ( null !== $has_update_filter && $has_update_filter && ! $has_update ) {
                continue;
            }

            // Extract update version from plugin_upgrades structure.
            $update_version = null;
            if ( $has_update && isset( $plugin_updates[ $slug ]['update']['new_version'] ) ) {
                $update_version = $plugin_updates[ $slug ]['update']['new_version'];
            } elseif ( $has_update && isset( $plugin_updates[ $slug ]['new_version'] ) ) {
                // Alternative structure.
                $update_version = $plugin_updates[ $slug ]['new_version'];
            }

            $plugins[] = array(
                'slug'           => $slug,
                'name'           => $plugin['name'] ?? $slug,
                'version'        => $plugin['version'] ?? '',
                'active'         => $is_active,
                'update_version' => $update_version,
            );
        }

        return array(
            'site_id'  => (int) $site->id,
            'site_url' => $site->url,
            'plugins'  => $plugins,
            'total'    => count( $plugins ),
        );
    }

    /**
     * Execute callback for mainwp/get-site-themes-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_get_site_themes( array $input ) { // phpcs:ignore -- NOSONAR - complexity method.
        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] );

        if ( is_wp_error( $site ) ) {
            return $site;
        }

        // ACL check is done in permission_callback, but double-check for safety.
        if ( ! MainWP_Abilities_Util::can_access_site( $site, $input ) ) {
            return new \WP_Error(
                'mainwp_access_denied',
                __( 'You do not have permission to access this site.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        // Check child version for theme data.
        $version_check = MainWP_Abilities_Util::check_child_version( $site );
        if ( is_wp_error( $version_check ) ) {
            return $version_check;
        }

        // Get themes from site data (stored as JSON).
        $themes_data = ! empty( $site->themes ) ? json_decode( $site->themes, true ) : array();
        if ( ! is_array( $themes_data ) ) {
            $themes_data = array();
        }

        // Get theme updates for this site.
        $theme_updates = ! empty( $site->theme_upgrades ) ? json_decode( $site->theme_upgrades, true ) : array();
        if ( ! is_array( $theme_updates ) ) {
            $theme_updates = array();
        }

        // Determine active theme - check for 'active' flag in theme data.
        // Support both associative (slug as key) and indexed (slug inside theme) formats.
        $active_theme_slug = '';
        foreach ( $themes_data as $key => $theme ) {
            $theme_slug = is_string( $key ) && ! empty( $key ) ? $key : ( $theme['slug'] ?? '' );
            if ( ! empty( $theme['active'] ) && ! empty( $theme_slug ) ) {
                $active_theme_slug = $theme_slug;
                break;
            }
        }

        $status_filter     = $input['status'] ?? 'all';
        $has_update_filter = $input['has_update'] ?? null;

        $themes = array();
        foreach ( $themes_data as $key => $theme ) {
            // Support both formats:
            // - Associative array: slug is the key (e.g., 'twentytwentyfour' => [...]).
            // - Indexed array: slug is inside the theme data (e.g., 0 => ['slug' => 'twentytwentyfour', ...]).
            $slug = is_string( $key ) && ! empty( $key ) ? $key : ( $theme['slug'] ?? '' );

            // Skip themes with invalid slugs.
            if ( empty( $slug ) || ! is_string( $slug ) ) {
                continue;
            }

            $is_active = ( $slug === $active_theme_slug );

            // Apply status filter.
            if ( 'active' === $status_filter && ! $is_active ) {
                continue;
            }
            if ( 'inactive' === $status_filter && $is_active ) {
                continue;
            }

            $has_update = isset( $theme_updates[ $slug ] );

            // Apply has_update filter if specified.
            if ( null !== $has_update_filter && $has_update_filter && ! $has_update ) {
                continue;
            }

            // Extract update version from theme_upgrades structure.
            $update_version = null;
            if ( $has_update && isset( $theme_updates[ $slug ]['update']['new_version'] ) ) {
                $update_version = $theme_updates[ $slug ]['update']['new_version'];
            } elseif ( $has_update && isset( $theme_updates[ $slug ]['new_version'] ) ) {
                // Alternative structure.
                $update_version = $theme_updates[ $slug ]['new_version'];
            }

            $themes[] = array(
                'slug'           => $slug,
                'name'           => $theme['name'] ?? $slug,
                'version'        => $theme['version'] ?? '',
                'active'         => $is_active,
                'update_version' => $update_version,
            );
        }

        return array(
            'site_id'      => (int) $site->id,
            'site_url'     => $site->url,
            'active_theme' => $active_theme_slug,
            'themes'       => $themes,
            'total'        => count( $themes ),
        );
    }
    // =========================================================================
    // Connection Management Abilities (8)
    // =========================================================================

    /**
     * Register mainwp/add-site-v1 ability.
     *
     * @return void
     */
    private static function register_add_site(): void {
        wp_register_ability(
            'mainwp/add-site-v1',
            array(
                'label'               => __( 'Add MainWP Site', 'mainwp' ),
                'description'         => __( 'Add a new MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_add_site_input_schema(),
                'output_schema'       => self::get_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_add_site' ),
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
     * Get input schema for add-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_add_site_input_schema(): array {
        return array(
            'type'                 => 'object',
            'required'             => array( 'url', 'name', 'admin_username' ),
            'additionalProperties' => false,
            'properties'           => array(
                'url'                => array(
                    'type'        => 'string',
                    'description' => __( 'Site URL', 'mainwp' ),
                ),
                'name'               => array(
                    'type'        => 'string',
                    'description' => __( 'Site name', 'mainwp' ),
                ),
                'admin_username'     => array(
                    'type'        => 'string',
                    'description' => __( 'Admin username', 'mainwp' ),
                ),
                'verify_certificate' => array(
                    'type'        => 'integer',
                    'description' => __( 'SSL verification (0=off, 1=on, 2=global)', 'mainwp' ),
                    'default'     => 1,
                    'enum'        => array( 0, 1, 2 ),
                ),
                'ssl_version'        => array(
                    'type'        => 'integer',
                    'description' => __( 'SSL version', 'mainwp' ),
                    'default'     => 0,
                ),
                'http_user'          => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP auth username', 'mainwp' ),
                ),
                'http_pass'          => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP auth password', 'mainwp' ),
                ),
                'tag_ids'            => array(
                    'type'        => 'array',
                    'description' => __( 'Tag IDs to assign', 'mainwp' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                ),
                'client_id'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Client ID to assign', 'mainwp' ),
                ),
                'adminpassword'      => array(
                    'type'        => 'string',
                    'description' => __( 'WordPress admin password (optional, for sites requiring password authentication)', 'mainwp' ),
                ),
                'uniqueid'           => array(
                    'type'        => 'string',
                    'description' => __( 'Unique Security ID configured in MainWP Child plugin', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Execute add-site-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Site data or error.
     */
    public static function execute_add_site( $input ) { // phpcs:ignore -- NOSONAR - complexity method.
        $input = MainWP_Abilities_Util::normalize_input( $input );

        // Validate required fields.
        $url            = isset( $input['url'] ) ? sanitize_text_field( $input['url'] ) : '';
        $name           = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
        $admin_username = isset( $input['admin_username'] ) ? sanitize_text_field( $input['admin_username'] ) : '';

        if ( empty( $url ) || empty( $name ) || empty( $admin_username ) ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Missing required fields: url, name, or admin_username.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        // Validate URL format.
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new \WP_Error(
                'mainwp_invalid_url',
                __( 'Invalid site URL format.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        // Normalize URL for consistent storage and duplicate detection.
        // This lowercases the host (per RFC 4343) and ensures trailing slash.
        $url = MainWP_Abilities_Util::normalize_site_url( $url );

        // Check if site already exists.
        $existing = MainWP_DB::instance()->get_websites_by_url( $url );
        if ( ! empty( $existing ) ) {
            return new \WP_Error(
                'mainwp_site_already_exists',
                __( 'A site with this URL already exists.', 'mainwp' ),
                array( 'status' => 409 )
            );
        }

        // Prepare site data.
        $site_data = array(
            'url'         => $url,
            'name'        => $name,
            'admin'       => $admin_username,
            'ssl_verify'  => isset( $input['verify_certificate'] ) ? (int) $input['verify_certificate'] : 1,
            'ssl_version' => isset( $input['ssl_version'] ) ? (int) $input['ssl_version'] : 0,
        );

        if ( ! empty( $input['http_user'] ) ) {
            $site_data['http_user'] = sanitize_text_field( $input['http_user'] );
        }
        if ( ! empty( $input['http_pass'] ) ) {
            $site_data['http_pass'] = $input['http_pass'];
        }
        if ( ! empty( $input['adminpassword'] ) ) {
            $site_data['adminpassword'] = $input['adminpassword'];
        }
        if ( ! empty( $input['uniqueid'] ) ) {
            $site_data['uniqueid'] = sanitize_text_field( $input['uniqueid'] );
        }

        // Add site using REST API method (not AJAX handler which calls die()).
        $result = MainWP_Manage_Sites_Handler::rest_api_add_site( $site_data );

        // Handle error response from rest_api_add_site.
        if ( ! empty( $result['error'] ) ) {
            $message = $result['error'];

            if ( strpos( $message, 'connection' ) !== false || strpos( $message, 'connect' ) !== false ) {
                return new \WP_Error( 'mainwp_connection_failed', $message, array( 'status' => 503 ) );
            }
            if ( strpos( $message, 'credentials' ) !== false || strpos( $message, 'invalid' ) !== false ) {
                return new \WP_Error( 'mainwp_invalid_credentials', $message, array( 'status' => 401 ) );
            }

            // Generic error.
            return new \WP_Error( 'mainwp_site_add_failed', $message, array( 'status' => 500 ) );
        }

        // Get the newly added site.
        $site_id = isset( $result['siteid'] ) ? (int) $result['siteid'] : 0;
        if ( empty( $site_id ) ) {
            return new \WP_Error(
                'mainwp_site_add_failed',
                __( 'Failed to add site.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $site = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $site ) ) {
            return new \WP_Error(
                'mainwp_site_not_found',
                __( 'Site added but could not retrieve site data.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        // Assign tags if provided.
        if ( ! empty( $input['tag_ids'] ) && is_array( $input['tag_ids'] ) ) {
            foreach ( $input['tag_ids'] as $tag_id ) {
                MainWP_DB_Common::instance()->update_group_site( (int) $tag_id, $site_id );
            }
        }

        // Assign client if provided.
        if ( ! empty( $input['client_id'] ) ) {
            MainWP_DB::instance()->update_website_values( $site_id, array( 'client_id' => (int) $input['client_id'] ) );
        }

        // Re-fetch site to get updated data.
        $site = MainWP_DB::instance()->get_website_by_id( $site_id );

        return MainWP_Abilities_Util::format_site_for_output( $site, true );
    }

    /**
     * Register mainwp/update-site-v1 ability.
     *
     * @return void
     */
    private static function register_update_site(): void {
        wp_register_ability(
            'mainwp/update-site-v1',
            array(
                'label'               => __( 'Update MainWP Site', 'mainwp' ),
                'description'         => __( 'Update settings for a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_update_site_input_schema(),
                'output_schema'       => self::get_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_site' ),
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
     * Get input schema for update-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_update_site_input_schema(): array {
        return array(
            'type'                 => 'object',
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
            'properties'           => array(
                'site_id_or_domain'  => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
                'name'               => array(
                    'type'        => 'string',
                    'description' => __( 'New site name', 'mainwp' ),
                ),
                'url'                => array(
                    'type'        => 'string',
                    'description' => __( 'New site URL', 'mainwp' ),
                ),
                'admin_username'     => array(
                    'type'        => 'string',
                    'description' => __( 'New admin username', 'mainwp' ),
                ),
                'verify_certificate' => array(
                    'type'        => 'integer',
                    'description' => __( 'SSL verification (0=off, 1=on, 2=global)', 'mainwp' ),
                    'enum'        => array( 0, 1, 2 ),
                ),
                'ssl_version'        => array(
                    'type'        => 'integer',
                    'description' => __( 'SSL version', 'mainwp' ),
                ),
                'http_user'          => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP auth username', 'mainwp' ),
                ),
                'http_pass'          => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP auth password', 'mainwp' ),
                ),
                'tag_ids'            => array(
                    'type'        => 'array',
                    'description' => __( 'Tag IDs to assign', 'mainwp' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                ),
                'client_id'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Client ID to assign', 'mainwp' ),
                ),
                'suspended'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Suspended status (0 or 1)', 'mainwp' ),
                    'enum'        => array( 0, 1 ),
                ),
            ),
        );
    }

    /**
     * Execute update-site-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Site data or error.
     */
    public static function execute_update_site( $input ) { // phpcs:ignore -- NOSONAR - complexity method.
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $update_data = array();

        if ( isset( $input['name'] ) ) {
            $update_data['name'] = sanitize_text_field( $input['name'] );
        }
        if ( isset( $input['url'] ) ) {
            $update_data['url'] = sanitize_text_field( $input['url'] );
        }
        if ( isset( $input['admin_username'] ) ) {
            $update_data['adminname'] = sanitize_text_field( $input['admin_username'] );
        }
        if ( isset( $input['verify_certificate'] ) ) {
            $update_data['verify_certificate'] = (int) $input['verify_certificate'];
        }
        if ( isset( $input['ssl_version'] ) ) {
            $update_data['ssl_version'] = (int) $input['ssl_version'];
        }
        if ( isset( $input['http_user'] ) ) {
            $update_data['http_user'] = sanitize_text_field( $input['http_user'] );
        }
        if ( isset( $input['http_pass'] ) ) {
            $update_data['http_pass'] = $input['http_pass'];
        }
        if ( isset( $input['suspended'] ) ) {
            $update_data['suspended'] = (int) $input['suspended'];
        }

        if ( ! empty( $update_data ) ) {
            MainWP_DB::instance()->update_website_values( $site->id, $update_data );
        }

        if ( isset( $input['tag_ids'] ) && is_array( $input['tag_ids'] ) ) {
            global $wpdb;
            // Clear existing tag associations for this site.
            //phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- for testing results.
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . $wpdb->prefix . 'mainwp_wp_group WHERE wpid = %d',
                    $site->id
                )
            );
            // Add new tag associations.
            foreach ( $input['tag_ids'] as $tag_id ) {
                MainWP_DB_Common::instance()->update_group_site( (int) $tag_id, $site->id );
            }
        }

        if ( isset( $input['client_id'] ) ) {
            MainWP_DB::instance()->update_website_values( $site->id, array( 'client_id' => (int) $input['client_id'] ) );
        }

        $site = MainWP_DB::instance()->get_website_by_id( $site->id );

        return MainWP_Abilities_Util::format_site_for_output( $site, true );
    }

    /**
     * Register mainwp/delete-site-v1 ability.
     *
     * @return void
     */
    private static function register_delete_site(): void {
        wp_register_ability(
            'mainwp/delete-site-v1',
            array(
                'label'               => __( 'Delete MainWP Site', 'mainwp' ),
                'description'         => __( 'Delete a MainWP child site. Requires confirmation. Supports dry-run mode.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_delete_site_input_schema(),
                'output_schema'       => self::get_delete_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_delete_site' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Destructive operation - requires confirm:true or dry_run:true. Only call when user explicitly requests deletion.',
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for delete-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_delete_site_input_schema(): array {
        return array(
            'type'       => 'object',
            'required'   => array( 'site_id_or_domain' ),
            'properties' => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'description' => __( 'Must be true to execute deletion', 'mainwp' ),
                    'default'     => false,
                ),
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'description' => __( 'Preview mode - shows what would be deleted without executing', 'mainwp' ),
                    'default'     => false,
                ),
            ),
        );
    }

    /**
     * Get output schema for delete-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_delete_site_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'dry_run'      => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether this was a dry-run preview', 'mainwp' ),
                ),
                'would_affect' => array(
                    'type'        => 'object',
                    'description' => __( 'Site that would be deleted (dry-run only)', 'mainwp' ),
                ),
                'warnings'     => array(
                    'type'        => 'array',
                    'description' => __( 'Warnings about deletion impact (dry-run only)', 'mainwp' ),
                    'items'       => array( 'type' => 'string' ),
                ),
                'deleted'      => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether deletion was successful', 'mainwp' ),
                ),
                'site'         => array(
                    'type'        => 'object',
                    'description' => __( 'Deleted site information', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Execute delete-site-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_delete_site( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $dry_run = ! empty( $input['dry_run'] );
        $confirm = ! empty( $input['confirm'] );

        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        if ( $dry_run ) {
            $warnings = array(
                __( 'This action is irreversible. The site record will be permanently deleted from the MainWP Dashboard.', 'mainwp' ),
                __( 'All sync data, site options, and historical information stored in the dashboard database will be removed.', 'mainwp' ),
                __( 'Group/tag associations for this site will be deleted.', 'mainwp' ),
                __( 'The MainWP Child plugin on the remote site will NOT be removed - only the dashboard connection will be deleted.', 'mainwp' ),
            );

            return array(
                'dry_run'      => true,
                'would_affect' => array(
                    'id'   => (int) $site->id,
                    'url'  => $site->url,
                    'name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                ),
                'warnings'     => $warnings,
            );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Deletion requires confirm parameter to be true.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $site_info = array(
            'id'   => (int) $site->id,
            'url'  => $site->url,
            'name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
        );

        $result = MainWP_DB::instance()->remove_website( $site->id );

        if ( false === $result ) {
            return new \WP_Error(
                'mainwp_deletion_failed',
                __( 'Failed to delete site.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return array(
            'dry_run'      => false,
            'deleted'      => true,
            'site'         => $site_info,
            'would_affect' => array(),
            'warnings'     => array(),
        );
    }

    /**
     * Register mainwp/reconnect-site-v1 ability.
     *
     * @return void
     */
    private static function register_reconnect_site(): void {
        wp_register_ability(
            'mainwp/reconnect-site-v1',
            array(
                'label'               => __( 'Reconnect MainWP Site', 'mainwp' ),
                'description'         => __( 'Reconnect a disconnected MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_reconnect_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_reconnect_site' ),
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
     * Get input schema for single-site operations.
     *
     * @return array JSON Schema.
     */
    private static function get_single_site_input_schema(): array {
        return array(
            'type'       => 'object',
            'required'   => array( 'site_id_or_domain' ),
            'properties' => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Get output schema for reconnect-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_reconnect_site_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'reconnected' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether reconnection was successful', 'mainwp' ),
                ),
                'site'        => array(
                    'type'        => 'object',
                    'description' => __( 'Site information with status', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Execute reconnect-site-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_reconnect_site( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        // Use the View method directly (not AJAX handler which calls die()).
        try {
            $result = MainWP_Manage_Sites_View::m_reconnect_site( $site );
        } catch ( \Exception $e ) {
            return new \WP_Error(
                'mainwp_reconnect_failed',
                $e->getMessage(),
                array( 'status' => 503 )
            );
        }

        if ( ! $result ) {
            return new \WP_Error(
                'mainwp_reconnect_failed',
                __( 'Site reconnection failed.', 'mainwp' ),
                array( 'status' => 503 )
            );
        }

        $site = MainWP_DB::instance()->get_website_by_id( $site->id );

        return array(
            'reconnected' => true,
            'site'        => MainWP_Abilities_Util::format_site_for_output( $site, true ),
        );
    }

    /**
     * Register mainwp/disconnect-site-v1 ability.
     *
     * @return void
     */
    private static function register_disconnect_site(): void {
        wp_register_ability(
            'mainwp/disconnect-site-v1',
            array(
                'label'               => __( 'Disconnect MainWP Site', 'mainwp' ),
                'description'         => __( 'Disconnect a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_disconnect_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_disconnect_site' ),
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
     * Get output schema for disconnect-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_disconnect_site_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'disconnected' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether disconnection was successful', 'mainwp' ),
                ),
                'site'         => array(
                    'type'        => 'object',
                    'description' => __( 'Site information', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Execute disconnect-site-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_disconnect_site( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        MainWP_DB::instance()->update_website_sync_values( $site->id, array( 'sync_errors' => __( 'Manually disconnected', 'mainwp' ) ) );

        $site = MainWP_DB::instance()->get_website_by_id( $site->id );

        return array(
            'disconnected' => true,
            'site'         => MainWP_Abilities_Util::format_site_for_output( $site, true ),
        );
    }

    /**
     * Register mainwp/suspend-site-v1 ability.
     *
     * @return void
     */
    private static function register_suspend_site(): void {
        wp_register_ability(
            'mainwp/suspend-site-v1',
            array(
                'label'               => __( 'Suspend MainWP Site', 'mainwp' ),
                'description'         => __( 'Suspend a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_suspend_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_suspend_site' ),
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
     * Get output schema for suspend-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_suspend_site_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'suspended' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether suspension was successful', 'mainwp' ),
                ),
                'site'      => array(
                    'type'        => 'object',
                    'description' => __( 'Site information with updated suspended status', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Execute suspend-site-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_suspend_site( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        MainWP_DB::instance()->update_website_values( $site->id, array( 'suspended' => 1 ) );

        do_action( 'mainwp_site_suspended', $site, 1 );

        $site = MainWP_DB::instance()->get_website_by_id( $site->id );

        return array(
            'suspended' => true,
            'site'      => MainWP_Abilities_Util::format_site_for_output( $site, true ),
        );
    }

    /**
     * Register mainwp/unsuspend-site-v1 ability.
     *
     * @return void
     */
    private static function register_unsuspend_site(): void {
        wp_register_ability(
            'mainwp/unsuspend-site-v1',
            array(
                'label'               => __( 'Unsuspend MainWP Site', 'mainwp' ),
                'description'         => __( 'Unsuspend a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_unsuspend_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_unsuspend_site' ),
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
     * Get output schema for unsuspend-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_unsuspend_site_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'unsuspended' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether unsuspension was successful', 'mainwp' ),
                ),
                'site'        => array(
                    'type'        => 'object',
                    'description' => __( 'Site information with updated suspended status', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Execute unsuspend-site-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_unsuspend_site( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        MainWP_DB::instance()->update_website_values( $site->id, array( 'suspended' => 0 ) );

        do_action( 'mainwp_site_suspended', $site, 0 );

        $site = MainWP_DB::instance()->get_website_by_id( $site->id );

        return array(
            'unsuspended' => true,
            'site'        => MainWP_Abilities_Util::format_site_for_output( $site, true ),
        );
    }

    /**
     * Register mainwp/check-site-v1 ability.
     *
     * @return void
     */
    private static function register_check_site(): void {
        wp_register_ability(
            'mainwp/check-site-v1',
            array(
                'label'               => __( 'Check MainWP Site', 'mainwp' ),
                'description'         => __( 'Check connectivity status of a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_check_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_check_site' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
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
     * Get output schema for check-site-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_check_site_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'site_id'       => array(
                    'type'        => 'integer',
                    'description' => __( 'Site ID', 'mainwp' ),
                ),
                'response_time' => array(
                    'type'        => 'number',
                    'description' => __( 'Response time in seconds', 'mainwp' ),
                ),
                'checked'       => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether check was completed', 'mainwp' ),
                ),
                'site'          => array(
                    'type'        => 'object',
                    'description' => __( 'Site information', 'mainwp' ),
                ),
                'status'        => array(
                    'type'        => 'object',
                    'description' => __( 'Connectivity status details', 'mainwp' ),
                    'properties'  => array(
                        'online'        => array( 'type' => 'boolean' ),
                        'http_code'     => array( 'type' => 'integer' ),
                        'response_time' => array( 'type' => 'number' ),
                    ),
                ),
            ),
        );
    }

    /**
     * Execute check-site-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_check_site( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $start_time = microtime( true );
        $result     = MainWP_Monitoring_Handler::handle_check_website( $site );
        $end_time   = microtime( true );

        if ( is_wp_error( $result ) ) {
            return new \WP_Error(
                'mainwp_check_failed',
                $result->get_error_message(),
                array( 'status' => 503 )
            );
        }

        if ( ! is_array( $result ) ) {
            return new \WP_Error(
                'mainwp_check_failed',
                __( 'Unable to check site status.', 'mainwp' ),
                array( 'status' => 503 )
            );
        }

        $http_code = isset( $result['httpCode'] ) ? (int) $result['httpCode'] : 0;

        // Determine online status from uptime monitoring or legacy path.
        // Uptime monitoring: 0=DOWN, 1=UP, 2=PENDING.
        if ( isset( $result['new_uptime_status'] ) ) {
            $online = ( 1 === (int) $result['new_uptime_status'] );
        } else {
            // Legacy try_visit path — check HTTP code against ignored-codes list.
            $online = MainWP_Connect::check_ignored_http_code( $http_code, $site );
        }

        $response_time = round( $end_time - $start_time, 2 );

        return array(
            'site_id'       => (int) $site->id,
            'response_time' => $response_time,
            'checked'       => true,
            'site'          => array(
                'id'   => (int) $site->id,
                'url'  => $site->url,
                'name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
            ),
            'status'        => array(
                'online'        => (bool) $online,
                'http_code'     => $http_code,
                'response_time' => $response_time,
            ),
        );
    }

    // =========================================================================
    // Plugin Management Abilities (4)
    // =========================================================================

    /**
     * Register mainwp/activate-site-plugins-v1 ability.
     *
     * @return void
     */
    private static function register_activate_site_plugins(): void {
        wp_register_ability(
            'mainwp/activate-site-plugins-v1',
            array(
                'label'               => __( 'Activate Site Plugins', 'mainwp' ),
                'description'         => __( 'Activate plugins on a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_activate_site_plugins_input_schema(),
                'output_schema'       => self::get_activate_plugins_output_schema(),
                'execute_callback'    => array( self::class, 'execute_activate_site_plugins' ),
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
     * Get input schema for activate-site-plugins-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_activate_site_plugins_input_schema(): array {
        return array(
            'type'       => 'object',
            'required'   => array( 'site_id_or_domain', 'plugins' ),
            'properties' => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
                'plugins'           => array(
                    'type'        => 'array',
                    'description' => __( 'Plugin slugs to activate (e.g., akismet/akismet.php)', 'mainwp' ),
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                ),
            ),
        );
    }

    /**
     * Get output schema for activate-site-plugins-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_activate_plugins_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'activated' => array(
                    'type'        => 'array',
                    'description' => __( 'Successfully activated plugins', 'mainwp' ),
                    'items'       => array( 'type' => 'object' ),
                ),
                'errors'    => array(
                    'type'        => 'array',
                    'description' => __( 'Errors encountered', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'slug'    => array( 'type' => 'string' ),
                            'code'    => array( 'type' => 'string' ),
                            'message' => array( 'type' => 'string' ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Get output schema for deactivate-site-plugins-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_deactivate_plugins_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'deactivated' => array(
                    'type'        => 'array',
                    'description' => __( 'Successfully deactivated plugins', 'mainwp' ),
                    'items'       => array( 'type' => 'object' ),
                ),
                'errors'      => array(
                    'type'        => 'array',
                    'description' => __( 'Errors encountered', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'slug'    => array( 'type' => 'string' ),
                            'code'    => array( 'type' => 'string' ),
                            'message' => array( 'type' => 'string' ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Execute activate-site-plugins-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_activate_site_plugins( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        // Check child version for remote plugin operations.
        $version_check = MainWP_Abilities_Util::check_child_version( $site );
        if ( is_wp_error( $version_check ) ) {
            return $version_check;
        }

        $plugins = isset( $input['plugins'] ) && is_array( $input['plugins'] ) ? $input['plugins'] : array();
        if ( empty( $plugins ) ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'No plugins specified.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $activated = array();
        $errors    = array();

        foreach ( $plugins as $plugin_slug ) {
            $result = MainWP_Connect::fetch_url_authed(
                $site,
                'plugin_action',
                array(
                    'action' => 'activate',
                    'plugin' => $plugin_slug,
                )
            );

            if ( MainWP_Abilities_Util::is_child_response_success( $result ) ) {
                $activated[] = MainWP_Abilities_Util::format_plugin_for_output(
                    array(
                        'slug'   => $plugin_slug,
                        'Name'   => $plugin_slug,
                        'active' => true, // Plugin was just activated.
                    )
                );
            } else {
                $message  = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : __( 'Activation failed', 'mainwp' );
                $errors[] = array(
                    'slug'    => $plugin_slug,
                    'code'    => 'mainwp_activation_failed',
                    'message' => $message,
                );
            }
        }

        return array(
            'activated' => $activated,
            'errors'    => $errors,
        );
    }

    /**
     * Register mainwp/deactivate-site-plugins-v1 ability.
     *
     * @return void
     */
    private static function register_deactivate_site_plugins(): void {
        wp_register_ability(
            'mainwp/deactivate-site-plugins-v1',
            array(
                'label'               => __( 'Deactivate Site Plugins', 'mainwp' ),
                'description'         => __( 'Deactivate plugins on a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_deactivate_site_plugins_input_schema(),
                'output_schema'       => self::get_deactivate_plugins_output_schema(),
                'execute_callback'    => array( self::class, 'execute_deactivate_site_plugins' ),
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
     * Get input schema for deactivate-site-plugins-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_deactivate_site_plugins_input_schema(): array {
        return array(
            'type'       => 'object',
            'required'   => array( 'site_id_or_domain', 'plugins' ),
            'properties' => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
                'plugins'           => array(
                    'type'        => 'array',
                    'description' => __( 'Plugin slugs to deactivate', 'mainwp' ),
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                ),
            ),
        );
    }

    /**
     * Execute deactivate-site-plugins-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_deactivate_site_plugins( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        // Check child version for remote plugin operations.
        $version_check = MainWP_Abilities_Util::check_child_version( $site );
        if ( is_wp_error( $version_check ) ) {
            return $version_check;
        }

        $plugins = isset( $input['plugins'] ) && is_array( $input['plugins'] ) ? $input['plugins'] : array();
        if ( empty( $plugins ) ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'No plugins specified.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $deactivated = array();
        $errors      = array();

        foreach ( $plugins as $plugin_slug ) {
            $result = MainWP_Connect::fetch_url_authed(
                $site,
                'plugin_action',
                array(
                    'action' => 'deactivate',
                    'plugin' => $plugin_slug,
                )
            );

            if ( MainWP_Abilities_Util::is_child_response_success( $result ) ) {
                $deactivated[] = MainWP_Abilities_Util::format_plugin_for_output(
                    array(
                        'slug'   => $plugin_slug,
                        'Name'   => $plugin_slug,
                        'active' => false, // Plugin was just deactivated.
                    )
                );
            } else {
                $message  = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : __( 'Deactivation failed', 'mainwp' );
                $errors[] = array(
                    'slug'    => $plugin_slug,
                    'code'    => 'mainwp_deactivation_failed',
                    'message' => $message,
                );
            }
        }

        return array(
            'deactivated' => $deactivated,
            'errors'      => $errors,
        );
    }

    /**
     * Register mainwp/delete-site-plugins-v1 ability.
     *
     * @return void
     */
    private static function register_delete_site_plugins(): void {
        wp_register_ability(
            'mainwp/delete-site-plugins-v1',
            array(
                'label'               => __( 'Delete Site Plugins', 'mainwp' ),
                'description'         => __( 'Delete plugins from a MainWP child site. Requires confirmation. Supports dry-run mode.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_delete_site_plugins_input_schema(),
                'output_schema'       => self::get_delete_plugins_output_schema(),
                'execute_callback'    => array( self::class, 'execute_delete_site_plugins' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Destructive operation - requires confirm:true or dry_run:true. Only call when user explicitly requests deletion. Permanently removes plugins from the child site.',
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for delete-site-plugins-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_delete_site_plugins_input_schema(): array {
        return array(
            'type'       => 'object',
            'required'   => array( 'site_id_or_domain', 'plugins' ),
            'properties' => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
                'plugins'           => array(
                    'type'        => 'array',
                    'description' => __( 'Plugin slugs to delete', 'mainwp' ),
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'description' => __( 'Must be true to execute deletion', 'mainwp' ),
                    'default'     => false,
                ),
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'description' => __( 'Preview mode', 'mainwp' ),
                    'default'     => false,
                ),
            ),
        );
    }

    /**
     * Get output schema for delete-site-plugins-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_delete_plugins_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'dry_run'      => array( 'type' => 'boolean' ),
                'would_affect' => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'count'        => array( 'type' => 'integer' ),
                'warnings'     => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'string' ),
                ),
                'deleted'      => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'errors'       => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
            ),
        );
    }

    /**
     * Generic helper to delete remote items (plugins or themes) from a child site.
     *
     * This method implements the shared logic for plugin and theme deletion:
     * - Input validation (dry_run/confirm mutual exclusivity)
     * - Site resolution and access checks
     * - Dry-run mode with would_affect/warnings
     * - Confirmed deletion with remote action calls
     * - Consistent response shaping
     *
     * @param array $input   Raw input parameters.
     * @param array $adapter Configuration for item type with keys:
     *                       - 'input_key'       (string) Key in input array ('plugins' or 'themes').
     *                       - 'site_property'   (string) Site object property ('plugins' or 'themes').
     *                       - 'remote_action'   (string) MainWP Connect action ('plugin_action' or 'theme_action').
     *                       - 'remote_param'    (string) Remote action param key ('plugin' or 'theme').
     *                       - 'formatter'       (callable) Formatter function for output.
     *                       - 'empty_error'     (string) Error message when no items specified.
     *                       - 'active_warning'  (string) Warning message format for active items (%s = slug).
     *                       - 'active_singular' (bool) True if only one item can be active (themes).
     * @return array|\WP_Error Result or error.
     */
    private static function execute_delete_remote_items( array $input, array $adapter ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $dry_run = ! empty( $input['dry_run'] );
        $confirm = ! empty( $input['confirm'] );

        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $version_check = MainWP_Abilities_Util::check_child_version( $site );
        if ( is_wp_error( $version_check ) ) {
            return $version_check;
        }

        $items = isset( $input[ $adapter['input_key'] ] ) && is_array( $input[ $adapter['input_key'] ] )
            ? $input[ $adapter['input_key'] ]
            : array();

        if ( empty( $items ) ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                $adapter['empty_error'],
                array( 'status' => 400 )
            );
        }

        if ( $dry_run ) {
            return self::build_delete_dry_run_response( $site, $items, $adapter );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Deletion requires confirm parameter to be true.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        return self::execute_delete_remote_calls( $site, $items, $adapter );
    }

    /**
     * Build dry-run response for delete operations.
     *
     * @param object $site    Site object.
     * @param array  $items   Items to delete.
     * @param array  $adapter Item type adapter configuration.
     * @return array Dry-run response.
     */
    private static function build_delete_dry_run_response( $site, array $items, array $adapter ): array { // phpcs:ignore -- NOSONAR - complexity.
        $would_affect = array();
        $warnings     = array();

        // Get installed items from site data.
        $site_property   = $adapter['site_property'];
        $installed_items = ! empty( $site->$site_property ) ? json_decode( $site->$site_property, true ) : array();
        if ( ! is_array( $installed_items ) ) {
            $installed_items = array();
        }

        // Build lookup of active items.
        $active_items       = array();
        $active_singular    = ! empty( $adapter['active_singular'] );
        $single_active_slug = '';

        foreach ( $installed_items as $key => $item_data ) {
            $slug = is_string( $key ) && ! empty( $key ) ? $key : ( $item_data['slug'] ?? '' );
            if ( ! empty( $slug ) && ! empty( $item_data['active'] ) ) {
                if ( $active_singular ) {
                    $single_active_slug = $slug;
                    break;
                }
                $active_items[ $slug ] = true;
            }
        }

        $formatter = $adapter['formatter'];
        foreach ( $items as $slug ) {
            $would_affect[] = call_user_func(
                $formatter,
                array(
                    'slug' => $slug,
                    'Name' => $slug,
                )
            );

            // Check if item is active and add warning.
            $is_active = $active_singular
                ? ( $slug === $single_active_slug )
                : isset( $active_items[ $slug ] );

            if ( $is_active ) {
                $warnings[] = sprintf( $adapter['active_warning'], $slug );
            }
        }

        return array(
            'dry_run'      => true,
            'would_affect' => $would_affect,
            'count'        => count( $would_affect ),
            'warnings'     => $warnings,
        );
    }

    /**
     * Execute remote delete calls for items.
     *
     * @param object $site    Site object.
     * @param array  $items   Items to delete.
     * @param array  $adapter Item type adapter configuration.
     * @return array Response with deleted items and errors.
     */
    private static function execute_delete_remote_calls( $site, array $items, array $adapter ): array {
        $deleted = array();
        $errors  = array();

        $remote_action = $adapter['remote_action'];
        $remote_param  = $adapter['remote_param'];
        $formatter     = $adapter['formatter'];

        foreach ( $items as $slug ) {
            $result = MainWP_Connect::fetch_url_authed(
                $site,
                $remote_action,
                array(
                    'action'      => 'delete',
                    $remote_param => $slug,
                )
            );

            if ( is_wp_error( $result ) ) {
                $errors[] = array(
                    'slug'    => $slug,
                    'code'    => 'mainwp_' . $result->get_error_code(),
                    'message' => $result->get_error_message(),
                );
            } elseif ( MainWP_Abilities_Util::is_child_response_success( $result ) ) {
                $deleted[] = call_user_func(
                    $formatter,
                    array(
                        'slug' => $slug,
                        'Name' => $slug,
                    )
                );
            } else {
                $message  = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : __( 'Deletion failed', 'mainwp' );
                $errors[] = array(
                    'slug'    => $slug,
                    'code'    => 'mainwp_deletion_failed',
                    'message' => $message,
                );
            }
        }

        return array(
            'dry_run'  => false,
            'deleted'  => $deleted,
            'count'    => count( $deleted ),
            'errors'   => $errors,
            'warnings' => array(),
        );
    }

    /**
     * Execute delete-site-plugins-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_delete_site_plugins( $input ) {
        return self::execute_delete_remote_items(
            $input,
            array(
                'input_key'       => 'plugins',
                'site_property'   => 'plugins',
                'remote_action'   => 'plugin_action',
                'remote_param'    => 'plugin',
                'formatter'       => array( MainWP_Abilities_Util::class, 'format_plugin_for_output' ),
                'empty_error'     => __( 'No plugins specified.', 'mainwp' ),
                /* translators: %s: plugin name */
                'active_warning'  => __( 'Plugin %s is currently active.', 'mainwp' ),
                'active_singular' => false,
            )
        );
    }

    /**
     * Register mainwp/get-abandoned-plugins-v1 ability.
     *
     * @return void
     */
    private static function register_get_abandoned_plugins(): void {
        wp_register_ability(
            'mainwp/get-abandoned-plugins-v1',
            array(
                'label'               => __( 'Get Abandoned Plugins', 'mainwp' ),
                'description'         => __( 'Get list of abandoned plugins on a child site. Returns plugins detected during last sync. Data freshness depends on sync frequency.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_abandoned_plugins_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_abandoned_plugins' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
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
     * Get output schema for get-abandoned-plugins-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_abandoned_plugins_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'plugins' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'slug'              => array( 'type' => 'string' ),
                            'name'              => array( 'type' => 'string' ),
                            'version'           => array( 'type' => 'string' ),
                            'last_updated'      => array( 'type' => 'string' ),
                            'days_since_update' => array( 'type' => array( 'integer', 'null' ) ),
                        ),
                    ),
                ),
                'total'   => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute get-abandoned-plugins-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_get_abandoned_plugins( $input ) { // phpcs:ignore -- NOSONAR - complexity.
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $abandoned_data = MainWP_DB::instance()->get_website_option( $site, 'plugins_outdate_info' );
        $abandoned      = ! empty( $abandoned_data ) ? json_decode( $abandoned_data, true ) : array();

        if ( ! is_array( $abandoned ) ) {
            $abandoned = array();
        }

        $plugins = array();
        foreach ( $abandoned as $slug => $info ) {
            // Convert timestamp to ISO 8601 date string if numeric.
            $last_updated = isset( $info['last_updated'] ) ? $info['last_updated'] : '';
            if ( is_numeric( $last_updated ) ) {
                $last_updated = gmdate( 'c', (int) $last_updated );
            }

            // Calculate days since update only if we have a valid numeric timestamp.
            $days_since_update = null;
            if ( isset( $info['outdate_timestamp'] ) && is_numeric( $info['outdate_timestamp'] ) && $info['outdate_timestamp'] > 0 ) {
                $days_since_update = (int) ( ( time() - $info['outdate_timestamp'] ) / DAY_IN_SECONDS );
            }

            $plugins[] = array(
                'slug'              => $slug,
                'name'              => isset( $info['Name'] ) ? $info['Name'] : $slug,
                'version'           => isset( $info['Version'] ) ? $info['Version'] : '',
                'last_updated'      => (string) $last_updated,
                'days_since_update' => $days_since_update,
            );
        }

        return array(
            'plugins' => $plugins,
            'total'   => count( $plugins ),
        );
    }

    // =========================================================================
    // Theme Management Abilities (3)
    // =========================================================================

    /**
     * Register mainwp/activate-site-theme-v1 ability.
     *
     * @return void
     */
    private static function register_activate_site_theme(): void {
        wp_register_ability(
            'mainwp/activate-site-theme-v1',
            array(
                'label'               => __( 'Activate Site Theme', 'mainwp' ),
                'description'         => __( 'Activate a theme on a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_activate_site_theme_input_schema(),
                'output_schema'       => self::get_activate_theme_output_schema(),
                'execute_callback'    => array( self::class, 'execute_activate_site_theme' ),
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
     * Get input schema for activate-site-theme-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_activate_site_theme_input_schema(): array {
        return array(
            'type'       => 'object',
            'required'   => array( 'site_id_or_domain', 'theme' ),
            'properties' => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
                'theme'             => array(
                    'type'        => 'string',
                    'description' => __( 'Theme slug to activate', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Get output schema for activate-theme-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_activate_theme_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'activated' => array( 'type' => 'boolean' ),
                'theme'     => array( 'type' => 'object' ),
            ),
        );
    }

    /**
     * Execute activate-site-theme-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_activate_site_theme( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        // Check child version for remote theme operations.
        $version_check = MainWP_Abilities_Util::check_child_version( $site );
        if ( is_wp_error( $version_check ) ) {
            return $version_check;
        }

        $theme_slug = isset( $input['theme'] ) ? sanitize_text_field( $input['theme'] ) : '';
        if ( empty( $theme_slug ) ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Theme slug is required.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $result = MainWP_Connect::fetch_url_authed(
            $site,
            'theme_action',
            array(
                'action' => 'activate',
                'theme'  => $theme_slug,
            )
        );

        if ( ! MainWP_Abilities_Util::is_child_response_success( $result ) ) {
            $message = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : __( 'Activation failed', 'mainwp' );
            return new \WP_Error( 'mainwp_activation_failed', $message, array( 'status' => 500 ) );
        }

        return array(
            'activated' => true,
            'theme'     => MainWP_Abilities_Util::format_theme_for_output(
                array(
                    'slug' => $theme_slug,
                    'Name' => $theme_slug,
                )
            ),
        );
    }

    /**
     * Register mainwp/delete-site-themes-v1 ability.
     *
     * @return void
     */
    private static function register_delete_site_themes(): void {
        wp_register_ability(
            'mainwp/delete-site-themes-v1',
            array(
                'label'               => __( 'Delete Site Themes', 'mainwp' ),
                'description'         => __( 'Delete themes from a MainWP child site. Requires confirmation. Supports dry-run mode.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_delete_site_themes_input_schema(),
                'output_schema'       => self::get_delete_themes_output_schema(),
                'execute_callback'    => array( self::class, 'execute_delete_site_themes' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Destructive operation - requires confirm:true or dry_run:true. Only call when user explicitly requests deletion. Permanently removes themes from the child site.',
                        'readonly'     => false,
                        'destructive'  => true,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for delete-site-themes-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_delete_site_themes_input_schema(): array {
        return array(
            'type'       => 'object',
            'required'   => array( 'site_id_or_domain', 'themes' ),
            'properties' => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
                'themes'            => array(
                    'type'        => 'array',
                    'description' => __( 'Theme slugs to delete', 'mainwp' ),
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'description' => __( 'Must be true to execute deletion', 'mainwp' ),
                    'default'     => false,
                ),
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'description' => __( 'Preview mode', 'mainwp' ),
                    'default'     => false,
                ),
            ),
        );
    }

    /**
     * Get output schema for delete-site-themes-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_delete_themes_output_schema(): array { //phpcs:ignore -- NOSONAR - identical acceptance.
        return array(
            'type'       => 'object',
            'properties' => array(
                'dry_run'      => array( 'type' => 'boolean' ),
                'would_affect' => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'count'        => array( 'type' => 'integer' ),
                'warnings'     => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'string' ),
                ),
                'deleted'      => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'errors'       => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
            ),
        );
    }

    /**
     * Execute delete-site-themes-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_delete_site_themes( $input ) {
        return self::execute_delete_remote_items(
            $input,
            array(
                'input_key'       => 'themes',
                'site_property'   => 'themes',
                'remote_action'   => 'theme_action',
                'remote_param'    => 'theme',
                'formatter'       => array( MainWP_Abilities_Util::class, 'format_theme_for_output' ),
                'empty_error'     => __( 'No themes specified.', 'mainwp' ),
                /* translators: %s: theme name */
                'active_warning'  => __( 'Theme %s is the currently active theme.', 'mainwp' ),
                'active_singular' => true,
            )
        );
    }

    /**
     * Register mainwp/get-abandoned-themes-v1 ability.
     *
     * @return void
     */
    private static function register_get_abandoned_themes(): void {
        wp_register_ability(
            'mainwp/get-abandoned-themes-v1',
            array(
                'label'               => __( 'Get Abandoned Themes', 'mainwp' ),
                'description'         => __( 'Get list of abandoned themes on a child site. Returns themes detected during last sync. Data freshness depends on sync frequency.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_abandoned_themes_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_abandoned_themes' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
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
     * Get output schema for get-abandoned-themes-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_abandoned_themes_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'themes' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'slug'              => array( 'type' => 'string' ),
                            'name'              => array( 'type' => 'string' ),
                            'version'           => array( 'type' => 'string' ),
                            'last_updated'      => array( 'type' => 'string' ),
                            'days_since_update' => array( 'type' => array( 'integer', 'null' ) ),
                        ),
                    ),
                ),
                'total'  => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute get-abandoned-themes-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_get_abandoned_themes( $input ) { // phpcs:ignore -- NOSONAR - complexity.
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $abandoned_data = MainWP_DB::instance()->get_website_option( $site, 'themes_outdate_info' );
        $abandoned      = ! empty( $abandoned_data ) ? json_decode( $abandoned_data, true ) : array();

        if ( ! is_array( $abandoned ) ) {
            $abandoned = array();
        }

        $themes = array();
        foreach ( $abandoned as $slug => $info ) {
            // Convert timestamp to ISO 8601 date string if numeric.
            $last_updated = isset( $info['last_updated'] ) ? $info['last_updated'] : '';
            if ( is_numeric( $last_updated ) ) {
                $last_updated = gmdate( 'c', (int) $last_updated );
            }

            // Calculate days since update only if we have a valid numeric timestamp.
            $days_since_update = null;
            if ( isset( $info['outdate_timestamp'] ) && is_numeric( $info['outdate_timestamp'] ) && $info['outdate_timestamp'] > 0 ) {
                $days_since_update = (int) ( ( time() - $info['outdate_timestamp'] ) / DAY_IN_SECONDS );
            }

            $themes[] = array(
                'slug'              => $slug,
                'name'              => isset( $info['Name'] ) ? $info['Name'] : $slug,
                'version'           => isset( $info['Version'] ) ? $info['Version'] : '',
                'last_updated'      => (string) $last_updated,
                'days_since_update' => $days_since_update,
            );
        }

        return array(
            'themes' => $themes,
            'total'  => count( $themes ),
        );
    }

    // =========================================================================
    // Security & Monitoring Abilities (2)
    // =========================================================================

    /**
     * Register mainwp/get-site-security-v1 ability.
     *
     * @return void
     */
    private static function register_get_site_security(): void {
        wp_register_ability(
            'mainwp/get-site-security-v1',
            array(
                'label'               => __( 'Get Site Security', 'mainwp' ),
                'description'         => __( 'Get security status for a MainWP child site including vulnerability counts, security issues, and issue categories detected during last sync.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_site_security_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site_security' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Returns security data from last sync. Does NOT perform real-time scanning. Requires Security module on child site for full data.',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get output schema for get-site-security-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_site_security_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'site_id'         => array( 'type' => 'integer' ),
                'security_issues' => array( 'type' => 'object' ),
                'total_issues'    => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute get-site-security-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_get_site_security( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $security_data   = MainWP_DB::instance()->get_website_option( $site, 'security_stats' );
        $security_issues = ! empty( $security_data ) ? json_decode( $security_data, true ) : array();

        if ( ! is_array( $security_issues ) ) {
            $security_issues = array();
        }

        $total_issues = 0;
        foreach ( $security_issues as $value ) {
            if ( 'N' === $value || '0' === $value || 0 === $value ) {
                ++$total_issues;
            }
        }

        return array(
            'site_id'         => (int) $site->id,
            'security_issues' => $security_issues,
            'total_issues'    => $total_issues,
        );
    }

    /**
     * Register mainwp/get-site-changes-v1 ability.
     *
     * @return void
     */
    private static function register_get_site_changes(): void {
        wp_register_ability(
            'mainwp/get-site-changes-v1',
            array(
                'label'               => __( 'Get Site Changes', 'mainwp' ),
                'description'         => __( 'Get non-MainWP changes detected on a child site. Requires Logs module.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_site_changes_input_schema(),
                'output_schema'       => self::get_site_changes_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site_changes' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Detects changes made outside MainWP (direct edits, other plugins). Requires Logs module enabled. Results paginated (default 20, max 100).',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for get-site-changes-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_site_changes_input_schema(): array {
        return array(
            'type'       => 'object',
            'required'   => array( 'site_id_or_domain' ),
            'properties' => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain', 'mainwp' ),
                ),
                'page'              => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page', 'mainwp' ),
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
                'type'              => array(
                    'type'        => 'string',
                    'description' => __( 'Filter by change type', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Get output schema for get-site-changes-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_site_changes_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'    => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'page'     => array( 'type' => 'integer' ),
                'per_page' => array( 'type' => 'integer' ),
                'total'    => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute get-site-changes-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_get_site_changes( $input ) { // phpcs:ignore -- NOSONAR - complexity.
        $input = MainWP_Abilities_Util::normalize_input(
            $input,
            array(
                'page'     => 1,
                'per_page' => 20,
            )
        );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        if ( ! class_exists( 'MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
            return new \WP_Error(
                'mainwp_module_not_available',
                __( 'Logs module is not active.', 'mainwp' ),
                array( 'status' => 501 )
            );
        }

        $page     = max( 1, (int) $input['page'] );
        $per_page = max( 1, min( 100, (int) $input['per_page'] ) );
        $start    = ( $page - 1 ) * $per_page;

        // Build query arguments for Log_Query.
        $query_args = array(
            'wpid'             => (int) $site->id,
            'sources_conds'    => 'wp-admin-only', // Only non-MainWP changes.
            'start'            => $start,
            'records_per_page' => $per_page,
            'order'            => 'DESC',
            'orderby'          => 'created',
            'check_access'     => false, // Already checked above.
            'optimize'         => true,
        );

        // Optional type filter (maps to context).
        if ( ! empty( $input['type'] ) ) {
            $query_args['contexts'] = sanitize_text_field( $input['type'] );
        }

        // Use Log_Manager to ensure proper table name initialization.
        $log_db  = \MainWP\Dashboard\Module\Log\Log_Manager::instance()->db;
        $records = $log_db->query( $query_args );
        $total   = $log_db->get_found_records_count();

        // Format records for output.
        $items = array();
        if ( is_array( $records ) ) {
            foreach ( $records as $record ) {
                $items[] = array(
                    'id'        => isset( $record->log_id ) ? (int) $record->log_id : 0,
                    'type'      => isset( $record->context ) ? $record->context : '',
                    'action'    => isset( $record->action ) ? $record->action : '',
                    'item'      => isset( $record->item ) ? $record->item : '',
                    'user_id'   => isset( $record->user_id ) ? (int) $record->user_id : 0,
                    'user'      => isset( $record->user_login ) ? $record->user_login : '',
                    'timestamp' => isset( $record->created ) ? gmdate( 'c', (int) ( $record->created / 1000000 ) ) : '',
                );
            }
        }

        return array(
            'items'    => $items,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => (int) $total,
        );
    }

    // =========================================================================
    // Related Data Abilities (4)
    // =========================================================================

    /**
     * Register mainwp/get-site-client-v1 ability.
     *
     * @return void
     */
    private static function register_get_site_client(): void {
        wp_register_ability(
            'mainwp/get-site-client-v1',
            array(
                'label'               => __( 'Get Site Client', 'mainwp' ),
                'description'         => __( 'Get client assigned to a MainWP child site.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_site_client_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site_client' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
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
     * Get output schema for get-site-client-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_site_client_output_schema(): array {
        return array(
            'type'        => 'object',
            'description' => __( 'Client assignment result with client data or null.', 'mainwp' ),
            'properties'  => array(
                'client'  => array(
                    'type'                 => array( 'object', 'null' ),
                    'description'          => __( 'Client object or null if no client assigned.', 'mainwp' ),
                    'additionalProperties' => true,
                ),
                'message' => array(
                    'type'        => 'string',
                    'description' => __( 'Status message explaining the result.', 'mainwp' ),
                ),
            ),
            'required'    => array( 'client' ),
        );
    }

    /**
     * Execute get-site-client-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Client result object or error.
     */
    public static function execute_get_site_client( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $client_id = isset( $site->client_id ) ? (int) $site->client_id : 0;

        if ( empty( $client_id ) ) {
            return array(
                'client'  => null,
                'message' => __( 'No client assigned to this site.', 'mainwp' ),
            );
        }

        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );

        if ( empty( $client ) ) {
            return array(
                'client'  => null,
                'message' => __( 'Assigned client not found in database.', 'mainwp' ),
            );
        }

        return array(
            'client'  => MainWP_Abilities_Util::format_client_for_output( $client ),
            'message' => __( 'Client found.', 'mainwp' ),
        );
    }

    /**
     * Register mainwp/get-site-costs-v1 ability.
     *
     * @return void
     */
    private static function register_get_site_costs(): void {
        if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            return;
        }

        wp_register_ability(
            'mainwp/get-site-costs-v1',
            array(
                'label'               => __( 'Get Site Costs', 'mainwp' ),
                'description'         => __( 'Get costs associated with a MainWP child site. Requires Cost Tracker module to be active.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_single_site_input_schema(),
                'output_schema'       => self::get_site_costs_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site_costs' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
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
     * Get output schema for get-site-costs-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_site_costs_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'site_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Site ID', 'mainwp' ),
                ),
                'costs'   => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'total'   => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute get-site-costs-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_get_site_costs( $input ) {
        // Defensive check: module may have become unavailable after registration.
        if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            return new \WP_Error(
                'mainwp_module_not_available',
                __( 'Cost Tracker module is not active.', 'mainwp' ),
                array( 'status' => 501 )
            );
        }

        $input = MainWP_Abilities_Util::normalize_input( $input );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ?? null );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $costs_data = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB::get_instance()->get_all_cost_trackers_by_sites( array( $site->id ) );

        $costs = array();
        if ( is_array( $costs_data ) ) {
            foreach ( $costs_data as $cost ) {
                $costs[] = MainWP_Abilities_Util::format_cost_for_output( $cost );
            }
        }

        return array(
            'site_id' => (int) $site->id,
            'costs'   => $costs,
            'total'   => count( $costs ),
        );
    }

    /**
     * Register mainwp/count-sites-v1 ability.
     *
     * @return void
     */
    private static function register_count_sites(): void {
        wp_register_ability(
            'mainwp/count-sites-v1',
            array(
                'label'               => __( 'Count MainWP Sites', 'mainwp' ),
                'description'         => __( 'Count total MainWP child sites with optional filtering.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_count_sites_input_schema(),
                'output_schema'       => self::get_count_sites_output_schema(),
                'execute_callback'    => array( self::class, 'execute_count_sites' ),
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
     * Get input schema for count-sites-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_count_sites_input_schema(): array {
        return array(
            'type'       => array( 'object', 'null' ),
            'properties' => array(
                'status'    => array(
                    'type'        => 'string',
                    'description' => __( 'Filter by status', 'mainwp' ),
                    'enum'        => array( 'connected', 'disconnected', 'suspended' ),
                ),
                'tag_ids'   => array(
                    'type'        => 'array',
                    'description' => __( 'Filter by tag IDs', 'mainwp' ),
                    'items'       => array( 'type' => 'integer' ),
                ),
                'client_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter by client ID', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Get output schema for count-sites-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_count_sites_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'total' => array(
                    'type'        => 'integer',
                    'description' => __( 'Total count of sites', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Execute count-sites-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array Result.
     */
    public static function execute_count_sites( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $filters = array();

        if ( ! empty( $input['status'] ) ) {
            $filters['status'] = $input['status'];
        }

        if ( ! empty( $input['tag_ids'] ) && is_array( $input['tag_ids'] ) ) {
            $filters['tags'] = $input['tag_ids'];
        }

        if ( ! empty( $input['client_id'] ) ) {
            $filters['client_id'] = (int) $input['client_id'];
        }

        $total = MainWP_DB::instance()->get_websites_count_for_current_user( $filters );

        return array(
            'total' => (int) $total,
        );
    }

    /**
     * Register mainwp/get-sites-basic-v1 ability.
     *
     * @return void
     */
    private static function register_get_sites_basic(): void {
        wp_register_ability(
            'mainwp/get-sites-basic-v1',
            array(
                'label'               => __( 'Get Sites Basic', 'mainwp' ),
                'description'         => __( 'Get basic site info (id, url, name only) for fast bulk retrieval. Use list_sites_v1 for full details with filtering, or get_site_v1 for complete single-site information.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_sites_basic_input_schema(),
                'output_schema'       => self::get_sites_basic_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_sites_basic' ),
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
     * Get input schema for get-sites-basic-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_sites_basic_input_schema(): array {
        return array(
            'type'       => array( 'object', 'null' ),
            'properties' => array(
                'page'      => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page'  => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page', 'mainwp' ),
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
                'status'    => array(
                    'type' => 'string',
                    'enum' => array( 'connected', 'disconnected', 'suspended' ),
                ),
                'tag_ids'   => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'integer' ),
                ),
                'client_id' => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Get output schema for get-sites-basic-v1.
     *
     * @return array JSON Schema.
     */
    private static function get_sites_basic_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'    => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'   => array( 'type' => 'integer' ),
                            'url'  => array( 'type' => 'string' ),
                            'name' => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'page'     => array( 'type' => 'integer' ),
                'per_page' => array( 'type' => 'integer' ),
                'total'    => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute get-sites-basic-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array Result.
     */
    public static function execute_get_sites_basic( $input ) {
        $input = MainWP_Abilities_Util::normalize_input(
            $input,
            array(
                'page'     => 1,
                'per_page' => 20,
            )
        );

        $page     = max( 1, (int) $input['page'] );
        $per_page = max( 1, min( 100, (int) $input['per_page'] ) );

        $filters = array(
            'offset'       => ( $page - 1 ) * $per_page,
            'rowcount'     => $per_page,
            'selectgroups' => false,
        );

        if ( ! empty( $input['status'] ) ) {
            $filters['status'] = $input['status'];
        }

        if ( ! empty( $input['tag_ids'] ) && is_array( $input['tag_ids'] ) ) {
            $filters['tags'] = $input['tag_ids'];
        }

        if ( ! empty( $input['client_id'] ) ) {
            $filters['client_id'] = (int) $input['client_id'];
        }

        $sites = MainWP_DB::instance()->get_websites_for_current_user( $filters );

        if ( is_wp_error( $sites ) ) {
            return $sites;
        }

        $total = MainWP_DB::instance()->get_websites_count_for_current_user( $filters );

        if ( is_wp_error( $total ) ) {
            return $total;
        }

        $items = array();
        foreach ( $sites as $site ) {
            $items[] = array(
                'id'   => (int) $site->id,
                'url'  => $site->url,
                'name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
            );
        }

        return array(
            'items'    => $items,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => (int) $total,
        );
    }

    // =========================================================================
    // Batch Operations Abilities (4)
    // =========================================================================

    /**
     * Register mainwp/reconnect-sites-v1 ability.
     *
     * @return void
     */
    private static function register_reconnect_sites(): void {
        wp_register_ability(
            'mainwp/reconnect-sites-v1',
            array(
                'label'               => __( 'Reconnect MainWP Sites', 'mainwp' ),
                'description'         => __( 'Reconnect multiple MainWP child sites. Operations with >200 sites are automatically queued.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_batch_sites_input_schema(),
                'output_schema'       => self::get_batch_operation_output_schema(),
                'execute_callback'    => array( self::class, 'execute_reconnect_sites' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Pass site_ids_or_domains with specific IDs, or empty array for all applicable sites. Operations with >200 sites are automatically queued for background processing.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for batch site operations.
     *
     * @return array JSON Schema.
     */
    private static function get_batch_sites_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'description' => __( 'Site IDs or domains. Empty array means all sites.', 'mainwp' ),
                    'items'       => array(
                        'type' => array( 'integer', 'string' ),
                    ),
                    'default'     => array(),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for batch operations.
     *
     * @return array JSON Schema.
     */
    private static function get_batch_operation_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'queued'             => array( 'type' => 'boolean' ),
                'job_id'             => array( 'type' => 'string' ),
                'total'              => array( 'type' => 'integer' ),
                'reconnected'        => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'disconnected'       => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'checked'            => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'suspended'          => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'errors'             => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'object' ),
                ),
                'total_reconnected'  => array( 'type' => 'integer' ),
                'total_disconnected' => array( 'type' => 'integer' ),
                'total_checked'      => array( 'type' => 'integer' ),
                'total_suspended'    => array( 'type' => 'integer' ),
                'total_errors'       => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute reconnect-sites-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_reconnect_sites( $input ) { // phpcs:ignore -- NOSONAR - complexity.
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $identifiers = isset( $input['site_ids_or_domains'] ) && is_array( $input['site_ids_or_domains'] ) ? $input['site_ids_or_domains'] : array();

        // If empty, get all sites for current user.
        if ( empty( $identifiers ) ) {
            $all_sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );

            // Handle potential errors from DB query.
            if ( is_wp_error( $all_sites ) ) {
                return $all_sites;
            }

            $identifiers = array_map(
                function ( $s ) {
                    return (int) $s->id;
                },
                $all_sites ? $all_sites : array()
            );
        }

        // Resolve sites and enforce per-site ACLs.
        $access = MainWP_Abilities_Util::check_batch_site_access( $identifiers, $input );
        $sites  = $access['allowed'];
        $errors = $access['denied']; // Resolution + access-denied errors.

        if ( empty( $sites ) ) {
            return new \WP_Error(
                'mainwp_site_not_found',
                __( 'No valid sites found.', 'mainwp' ),
                array( 'status' => 404 )
            );
        }

        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', 200 );

        if ( count( $sites ) > $threshold ) {
            $job_id = MainWP_Abilities_Util::queue_batch_operation( 'reconnect', $sites );
            if ( is_wp_error( $job_id ) ) {
                return $job_id;
            }
            return array(
                'queued' => true,
                'job_id' => $job_id,
                'total'  => count( $sites ),
                'errors' => $errors, // Include resolution + ACL errors in queued response.
            );
        }

        $reconnected = array();

        foreach ( $sites as $site ) {
            // Use the View method directly (not AJAX handler which calls die()).
            try {
                $result = MainWP_Manage_Sites_View::m_reconnect_site( $site );
            } catch ( \Exception $e ) {
                $errors[] = array(
                    'identifier' => $site->url,
                    'code'       => 'mainwp_reconnect_failed',
                    'message'    => $e->getMessage(),
                );
                continue;
            }

            if ( ! $result ) {
                $errors[] = array(
                    'identifier' => $site->url,
                    'code'       => 'mainwp_reconnect_failed',
                    'message'    => __( 'Site reconnection failed.', 'mainwp' ),
                );
            } else {
                $reconnected[] = array(
                    'id'   => (int) $site->id,
                    'url'  => $site->url,
                    'name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                );
            }
        }

        return array(
            'reconnected'       => $reconnected,
            'errors'            => $errors,
            'total_reconnected' => count( $reconnected ),
            'total_errors'      => count( $errors ),
        );
    }

    /**
     * Register mainwp/disconnect-sites-v1 ability.
     *
     * @return void
     */
    private static function register_disconnect_sites(): void {
        wp_register_ability(
            'mainwp/disconnect-sites-v1',
            array(
                'label'               => __( 'Disconnect MainWP Sites', 'mainwp' ),
                'description'         => __( 'Disconnect multiple MainWP child sites. Operations with >200 sites are automatically queued.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_batch_sites_input_schema(),
                'output_schema'       => self::get_batch_operation_output_schema(),
                'execute_callback'    => array( self::class, 'execute_disconnect_sites' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Pass site_ids_or_domains with specific IDs, or empty array for all applicable sites. Operations with >200 sites are automatically queued for background processing.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Execute disconnect-sites-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_disconnect_sites( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $identifiers = isset( $input['site_ids_or_domains'] ) && is_array( $input['site_ids_or_domains'] ) ? $input['site_ids_or_domains'] : array();

        // If empty, get all sites for current user.
        if ( empty( $identifiers ) ) {
            $all_sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );

            // Handle potential errors from DB query.
            if ( is_wp_error( $all_sites ) ) {
                return $all_sites;
            }

            $identifiers = array_map(
                function ( $s ) {
                    return (int) $s->id;
                },
                $all_sites ? $all_sites : array()
            );
        }

        // Resolve sites and enforce per-site ACLs.
        $access = MainWP_Abilities_Util::check_batch_site_access( $identifiers, $input );
        $sites  = $access['allowed'];
        $errors = $access['denied']; // Resolution + access-denied errors.

        if ( empty( $sites ) ) {
            return new \WP_Error(
                'mainwp_site_not_found',
                __( 'No valid sites found.', 'mainwp' ),
                array( 'status' => 404 )
            );
        }

        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', 200 );

        if ( count( $sites ) > $threshold ) {
            $job_id = MainWP_Abilities_Util::queue_batch_operation( 'disconnect', $sites );
            if ( is_wp_error( $job_id ) ) {
                return $job_id;
            }
            return array(
                'queued' => true,
                'job_id' => $job_id,
                'total'  => count( $sites ),
                'errors' => $errors, // Include resolution + ACL errors in queued response.
            );
        }

        $disconnected = array();

        foreach ( $sites as $site ) {
            MainWP_DB::instance()->update_website_sync_values( $site->id, array( 'sync_errors' => __( 'Manually disconnected', 'mainwp' ) ) );

            $disconnected[] = array(
                'id'   => (int) $site->id,
                'url'  => $site->url,
                'name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
            );
        }

        return array(
            'disconnected'       => $disconnected,
            'errors'             => $errors,
            'total_disconnected' => count( $disconnected ),
            'total_errors'       => count( $errors ),
        );
    }

    /**
     * Register mainwp/check-sites-v1 ability.
     *
     * @return void
     */
    private static function register_check_sites(): void {
        wp_register_ability(
            'mainwp/check-sites-v1',
            array(
                'label'               => __( 'Check MainWP Sites', 'mainwp' ),
                'description'         => __( 'Check connectivity status of multiple MainWP child sites. Operations with >200 sites are automatically queued.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_batch_sites_input_schema(),
                'output_schema'       => self::get_batch_operation_output_schema(),
                'execute_callback'    => array( self::class, 'execute_check_sites' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Pass site_ids_or_domains with specific IDs, or empty array for all applicable sites. Operations with >200 sites are automatically queued for background processing.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Execute check-sites-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_check_sites( $input ) { // phpcs:ignore -- NOSONAR - complexity.
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $identifiers = isset( $input['site_ids_or_domains'] ) && is_array( $input['site_ids_or_domains'] ) ? $input['site_ids_or_domains'] : array();

        // If empty, get all sites for current user.
        if ( empty( $identifiers ) ) {
            $all_sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );

            // Handle potential errors from DB query.
            if ( is_wp_error( $all_sites ) ) {
                return $all_sites;
            }

            $identifiers = array_map(
                function ( $s ) {
                    return (int) $s->id;
                },
                $all_sites ? $all_sites : array()
            );
        }

        // Resolve sites and enforce per-site ACLs.
        $access = MainWP_Abilities_Util::check_batch_site_access( $identifiers, $input );
        $sites  = $access['allowed'];
        $errors = $access['denied']; // Resolution + access-denied errors.

        if ( empty( $sites ) ) {
            return new \WP_Error(
                'mainwp_site_not_found',
                __( 'No valid sites found.', 'mainwp' ),
                array( 'status' => 404 )
            );
        }

        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', 200 );

        if ( count( $sites ) > $threshold ) {
            $job_id = MainWP_Abilities_Util::queue_batch_operation( 'check', $sites );
            if ( is_wp_error( $job_id ) ) {
                return $job_id;
            }
            return array(
                'queued' => true,
                'job_id' => $job_id,
                'total'  => count( $sites ),
                'errors' => $errors, // Include resolution + ACL errors in queued response.
            );
        }

        $checked = array();

        foreach ( $sites as $site ) {
            $result = MainWP_Monitoring_Handler::handle_check_website( $site );

            if ( is_wp_error( $result ) ) {
                $errors[] = array(
                    'identifier' => $site->url,
                    'code'       => 'mainwp_check_failed',
                    'message'    => $result->get_error_message(),
                );
            } elseif ( ! is_array( $result ) ) {
                $errors[] = array(
                    'identifier' => $site->url,
                    'code'       => 'mainwp_check_failed',
                    'message'    => __( 'Unable to check site status.', 'mainwp' ),
                );
            } else {
                $http_code = isset( $result['httpCode'] ) ? (int) $result['httpCode'] : 0;

                if ( isset( $result['new_uptime_status'] ) ) {
                    $online = ( 1 === (int) $result['new_uptime_status'] );
                } else {
                    $online = MainWP_Connect::check_ignored_http_code( $http_code, $site );
                }

                $checked[] = array(
                    'id'     => (int) $site->id,
                    'url'    => $site->url,
                    'name'   => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                    'status' => array(
                        'online'    => (bool) $online,
                        'http_code' => $http_code,
                    ),
                );
            }
        }

        return array(
            'checked'       => $checked,
            'errors'        => $errors,
            'total_checked' => count( $checked ),
            'total_errors'  => count( $errors ),
        );
    }

    /**
     * Register mainwp/suspend-sites-v1 ability.
     *
     * @return void
     */
    private static function register_suspend_sites(): void {
        wp_register_ability(
            'mainwp/suspend-sites-v1',
            array(
                'label'               => __( 'Suspend MainWP Sites', 'mainwp' ),
                'description'         => __( 'Suspend multiple MainWP child sites. Operations with >200 sites are automatically queued.', 'mainwp' ),
                'category'            => 'mainwp-sites',
                'input_schema'        => self::get_batch_sites_input_schema(),
                'output_schema'       => self::get_batch_operation_output_schema(),
                'execute_callback'    => array( self::class, 'execute_suspend_sites' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Pass site_ids_or_domains with specific IDs, or empty array for all applicable sites. Operations with >200 sites are automatically queued for background processing.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Execute suspend-sites-v1 ability.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error Result or error.
     */
    public static function execute_suspend_sites( $input ) {
        $input = MainWP_Abilities_Util::normalize_input( $input );

        $identifiers = isset( $input['site_ids_or_domains'] ) && is_array( $input['site_ids_or_domains'] ) ? $input['site_ids_or_domains'] : array();

        // If empty, get all sites for current user.
        if ( empty( $identifiers ) ) {
            $all_sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );

            // Handle potential errors from DB query.
            if ( is_wp_error( $all_sites ) ) {
                return $all_sites;
            }

            $identifiers = array_map(
                function ( $s ) {
                    return (int) $s->id;
                },
                $all_sites ? $all_sites : array()
            );
        }

        // Resolve sites and enforce per-site ACLs.
        $access = MainWP_Abilities_Util::check_batch_site_access( $identifiers, $input );
        $sites  = $access['allowed'];
        $errors = $access['denied']; // Resolution + access-denied errors.

        if ( empty( $sites ) ) {
            return new \WP_Error(
                'mainwp_site_not_found',
                __( 'No valid sites found.', 'mainwp' ),
                array( 'status' => 404 )
            );
        }

        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', 200 );

        if ( count( $sites ) > $threshold ) {
            $job_id = MainWP_Abilities_Util::queue_batch_operation( 'suspend', $sites );
            if ( is_wp_error( $job_id ) ) {
                return $job_id;
            }
            return array(
                'queued' => true,
                'job_id' => $job_id,
                'total'  => count( $sites ),
                'errors' => $errors, // Include resolution + ACL errors in queued response.
            );
        }

        $suspended = array();

        foreach ( $sites as $site ) {
            MainWP_DB::instance()->update_website_values( $site->id, array( 'suspended' => 1 ) );
            do_action( 'mainwp_site_suspended', $site, 1 );

            $suspended[] = array(
                'id'        => (int) $site->id,
                'url'       => $site->url,
                'name'      => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                'suspended' => 1,
            );
        }

        return array(
            'suspended'       => $suspended,
            'errors'          => $errors,
            'total_suspended' => count( $suspended ),
            'total_errors'    => count( $errors ),
        );
    }
}
