<?php
/**
 * MainWP Updates Abilities
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Updates
 *
 * Registers and implements update-related abilities for the MainWP Dashboard.
 *
 * This class provides 13 abilities:
 * - mainwp/list-updates-v1: List available updates across sites
 * - mainwp/run-updates-v1: Execute updates (core, plugins, themes, translations)
 * - mainwp/list-ignored-updates-v1: List ignored updates
 * - mainwp/set-ignored-updates-v1: Manage ignored updates
 * - mainwp/get-site-updates-v1: Get available updates for a single site
 * - mainwp/update-site-core-v1: Update WordPress core for a single site
 * - mainwp/update-site-plugins-v1: Update plugins for a single site
 * - mainwp/update-site-themes-v1: Update themes for a single site
 * - mainwp/update-site-translations-v1: Update translations for a single site
 * - mainwp/ignore-site-core-v1: Manage core update ignore status for a single site
 * - mainwp/ignore-site-plugins-v1: Manage plugin ignore status for a single site
 * - mainwp/ignore-site-themes-v1: Manage theme ignore status for a single site
 * - mainwp/update-all-v1: Execute all available updates across all or selected sites
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
class MainWP_Abilities_Updates { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Batch operation threshold.
     *
     * Operations exceeding this number of sites will be queued for background processing.
     *
     * @var int
     */
    const BATCH_THRESHOLD = 200;

    /**
     * Register all update abilities.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        self::register_list_updates();
        self::register_run_updates();
        self::register_list_ignored_updates();
        self::register_set_ignored_updates();
        self::register_get_site_updates();
        self::register_update_site_core();
        self::register_update_site_plugins();
        self::register_update_site_themes();
        self::register_update_site_translations();
        self::register_ignore_site_core();
        self::register_ignore_site_plugins();
        self::register_ignore_site_themes();
        self::register_update_all();
    }

    // =========================================================================
    // Ability Registration Methods
    // =========================================================================

    /**
     * Register mainwp/list-updates-v1 ability.
     *
     * @return void
     */
    private static function register_list_updates(): void {
        wp_register_ability(
            'mainwp/list-updates-v1',
            array(
                'label'               => __( 'List Available Updates', 'mainwp' ),
                'description'         => __( 'List available updates across MainWP child sites with filtering and pagination.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_list_updates_input_schema(),
                'output_schema'       => self::get_list_updates_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_updates' ),
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
     * Register mainwp/run-updates-v1 ability.
     *
     * @return void
     */
    private static function register_run_updates(): void {
        wp_register_ability(
            'mainwp/run-updates-v1',
            array(
                'label'               => __( 'Run Updates', 'mainwp' ),
                'description'         => __( 'Execute updates on one or more MainWP child sites. Operations with >200 sites are automatically queued for background processing.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_run_updates_input_schema(),
                'output_schema'       => self::get_run_updates_output_schema(),
                'execute_callback'    => array( self::class, 'execute_run_updates' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Applies updates to child sites. Operations with >200 sites are automatically queued and return a job_id for status polling. Individual update failures do not fail the entire operation.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/list-ignored-updates-v1 ability.
     *
     * @return void
     */
    private static function register_list_ignored_updates(): void {
        wp_register_ability(
            'mainwp/list-ignored-updates-v1',
            array(
                'label'               => __( 'List Ignored Updates', 'mainwp' ),
                'description'         => __( 'List updates that have been marked as ignored.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_list_ignored_updates_input_schema(),
                'output_schema'       => self::get_list_ignored_updates_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_ignored_updates' ),
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
     * Register mainwp/set-ignored-updates-v1 ability.
     *
     * @return void
     */
    private static function register_set_ignored_updates(): void {
        wp_register_ability(
            'mainwp/set-ignored-updates-v1',
            array(
                'label'               => __( 'Set Ignored Updates', 'mainwp' ),
                'description'         => __( 'Add or remove items from the ignored updates list for a site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_set_ignored_updates_input_schema(),
                'output_schema'       => self::get_set_ignored_updates_output_schema(),
                'execute_callback'    => array( self::class, 'execute_set_ignored_updates' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
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
     * Register mainwp/get-site-updates-v1 ability.
     *
     * @return void
     */
    private static function register_get_site_updates(): void {
        wp_register_ability(
            'mainwp/get-site-updates-v1',
            array(
                'label'               => __( 'Get Site Updates', 'mainwp' ),
                'description'         => __( 'Get available updates for a single site with optional filtering by update type.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_get_site_updates_input_schema(),
                'output_schema'       => self::get_get_site_updates_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site_updates' ),
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
     * Register mainwp/update-site-core-v1 ability.
     *
     * @return void
     */
    private static function register_update_site_core(): void {
        wp_register_ability(
            'mainwp/update-site-core-v1',
            array(
                'label'               => __( 'Update Site Core', 'mainwp' ),
                'description'         => __( 'Update WordPress core for a single site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_update_site_core_input_schema(),
                'output_schema'       => self::get_update_site_core_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_site_core' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Updates WordPress core on a single site. For batch operations across multiple sites, use run_updates_v1 with types=[\'core\'].',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/update-site-plugins-v1 ability.
     *
     * @return void
     */
    private static function register_update_site_plugins(): void {
        wp_register_ability(
            'mainwp/update-site-plugins-v1',
            array(
                'label'               => __( 'Update Site Plugins', 'mainwp' ),
                'description'         => __( 'Update plugins for a single site. Optionally specify slugs to update only specific plugins.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_update_site_plugins_input_schema(),
                'output_schema'       => self::get_update_site_plugins_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_site_plugins' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Updates plugins on a single site. For batch operations across multiple sites, use run_updates_v1 with types=[\'plugins\'].',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/update-site-themes-v1 ability.
     *
     * @return void
     */
    private static function register_update_site_themes(): void {
        wp_register_ability(
            'mainwp/update-site-themes-v1',
            array(
                'label'               => __( 'Update Site Themes', 'mainwp' ),
                'description'         => __( 'Update themes for a single site. Optionally specify slugs to update only specific themes.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_update_site_themes_input_schema(),
                'output_schema'       => self::get_update_site_themes_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_site_themes' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Updates themes on a single site. For batch operations across multiple sites, use run_updates_v1 with types=[\'themes\'].',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/update-site-translations-v1 ability.
     *
     * @return void
     */
    private static function register_update_site_translations(): void {
        wp_register_ability(
            'mainwp/update-site-translations-v1',
            array(
                'label'               => __( 'Update Site Translations', 'mainwp' ),
                'description'         => __( 'Update translations for a single site. Optionally specify slugs to update only specific translations.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_update_site_translations_input_schema(),
                'output_schema'       => self::get_update_site_translations_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_site_translations' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Updates translations on a single site. For batch operations across multiple sites, use run_updates_v1 with types=[\'translations\'].',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/ignore-site-core-v1 ability.
     *
     * @return void
     */
    private static function register_ignore_site_core(): void {
        wp_register_ability(
            'mainwp/ignore-site-core-v1',
            array(
                'label'               => __( 'Ignore Site Core', 'mainwp' ),
                'description'         => __( 'Add or remove WordPress core from the ignored updates list for a single site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_ignore_site_core_input_schema(),
                'output_schema'       => self::get_ignore_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_ignore_site_core' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Manages core update ignore status. Use action=add to ignore core updates, action=remove to unignore.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/ignore-site-plugins-v1 ability.
     *
     * @return void
     */
    private static function register_ignore_site_plugins(): void {
        wp_register_ability(
            'mainwp/ignore-site-plugins-v1',
            array(
                'label'               => __( 'Ignore Site Plugins', 'mainwp' ),
                'description'         => __( 'Add or remove plugins from the ignored updates list for a single site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_ignore_site_plugins_input_schema(),
                'output_schema'       => self::get_ignore_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_ignore_site_plugins' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Manages plugin ignore status. Provide slugs array of plugins to ignore/unignore.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/ignore-site-themes-v1 ability.
     *
     * @return void
     */
    private static function register_ignore_site_themes(): void {
        wp_register_ability(
            'mainwp/ignore-site-themes-v1',
            array(
                'label'               => __( 'Ignore Site Themes', 'mainwp' ),
                'description'         => __( 'Add or remove themes from the ignored updates list for a single site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_ignore_site_themes_input_schema(),
                'output_schema'       => self::get_ignore_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_ignore_site_themes' ),
                'permission_callback' => MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Manages theme ignore status. Provide slugs array of themes to ignore/unignore.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/update-all-v1 ability.
     *
     * @return void
     */
    private static function register_update_all(): void {
        wp_register_ability(
            'mainwp/update-all-v1',
            array(
                'label'               => __( 'Update All', 'mainwp' ),
                'description'         => __( 'Execute ALL available updates (core, plugins, themes, translations) across all sites or specified sites. WARNING: This is a broad operation. For targeted updates, use run_updates_v1 with specific types[] and site_ids_or_domains[]. Operations with >200 sites are queued.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_update_all_input_schema(),
                'output_schema'       => self::get_update_all_output_schema(),
                'execute_callback'    => array( self::class, 'execute_update_all' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Applies all available updates (core, plugins, themes, translations) to specified sites or all sites. Operations with >200 sites are automatically queued and return a job_id for status polling.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => false,
                    ),
                ),
            )
        );
    }

    // =========================================================================
    // Input Schema Definitions
    // =========================================================================

    /**
     * Get input schema for list-updates-v1.
     *
     * Note: Uses 'type' => array('object', 'null') to allow callers to omit the input
     * parameter entirely on GET requests. All properties have defaults, so no input
     * is required. See class docblock for GET request input handling details.
     *
     * @return array
     */
    public static function get_list_updates_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'description' => __( 'Filter to specific sites. Empty array means all sites.', 'mainwp' ),
                    'items'       => array(
                        'type' => array( 'integer', 'string' ),
                    ),
                    'default'     => array(),
                ),
                'types'               => array(
                    'type'        => 'array',
                    'description' => __( 'Update types to include. Empty array means all types.', 'mainwp' ),
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'core', 'plugins', 'themes', 'translations' ),
                    ),
                    'default'     => array(),
                ),
                'page'                => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number (1-based).', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page'            => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                    'default'     => 50,
                    'minimum'     => 1,
                    'maximum'     => 200,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for run-updates-v1.
     *
     * @return array
     */
    public static function get_run_updates_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'description' => __( 'Sites to update. Empty array means all sites with available updates.', 'mainwp' ),
                    'items'       => array(
                        'type' => array( 'integer', 'string' ),
                    ),
                    'default'     => array(),
                ),
                'types'               => array(
                    'type'        => 'array',
                    'description' => __( 'Update types to apply. Empty array means all types.', 'mainwp' ),
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'core', 'plugins', 'themes', 'translations' ),
                    ),
                    'default'     => array(),
                ),
                'specific_items'      => array(
                    'type'        => 'array',
                    'description' => __( 'Specific items to update (by slug). If empty, updates all items of selected types.', 'mainwp' ),
                    'items'       => array( 'type' => 'string' ),
                    'default'     => array(),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for list-ignored-updates-v1.
     *
     * Note: Uses 'type' => array('object', 'null') to allow callers to omit the input
     * parameter entirely on GET requests. All properties have defaults, so no input
     * is required. See class docblock for GET request input handling details.
     *
     * @return array
     */
    public static function get_list_ignored_updates_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'description' => __( 'Filter to specific sites. Empty array means all sites.', 'mainwp' ),
                    'items'       => array(
                        'type' => array( 'integer', 'string' ),
                    ),
                    'default'     => array(),
                ),
                'types'               => array(
                    'type'        => 'array',
                    'description' => __( 'Filter by update type. Empty array means all types.', 'mainwp' ),
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'core', 'plugins', 'themes' ),
                    ),
                    'default'     => array(),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for set-ignored-updates-v1.
     *
     * @return array
     */
    public static function get_set_ignored_updates_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'            => array(
                    'type'        => 'string',
                    'enum'        => array( 'ignore', 'unignore' ),
                    'description' => __( 'Action to perform.', 'mainwp' ),
                ),
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain/URL.', 'mainwp' ),
                ),
                'type'              => array(
                    'type'        => 'string',
                    'enum'        => array( 'core', 'plugin', 'theme' ),
                    'description' => __( 'Type of update to ignore/unignore.', 'mainwp' ),
                ),
                'slug'              => array(
                    'type'        => 'string',
                    'description' => __( 'Slug of the item to ignore/unignore. Use "wordpress" for core updates.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'action', 'site_id_or_domain', 'type', 'slug' ),
            'additionalProperties' => false,
        );
    }

    // =========================================================================
    // Output Schema Definitions
    // =========================================================================

    /**
     * Get output schema for list-updates-v1.
     *
     * @return array
     */
    public static function get_list_updates_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'updates'  => array(
                    'type'        => 'array',
                    'description' => __( 'List of available updates.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'site_id'         => array( 'type' => 'integer' ),
                            'site_url'        => array(
                                'type'   => 'string',
                                'format' => 'uri',
                            ),
                            'site_name'       => array( 'type' => 'string' ),
                            'type'            => array(
                                'type' => 'string',
                                'enum' => array( 'core', 'plugin', 'theme', 'translation' ),
                            ),
                            'slug'            => array( 'type' => 'string' ),
                            'name'            => array( 'type' => 'string' ),
                            'current_version' => array( 'type' => 'string' ),
                            'new_version'     => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'name', 'current_version', 'new_version' ),
                    ),
                ),
                'summary'  => array(
                    'type'        => 'object',
                    'description' => __( 'Summary counts by type.', 'mainwp' ),
                    'properties'  => array(
                        'core'         => array( 'type' => 'integer' ),
                        'plugins'      => array( 'type' => 'integer' ),
                        'themes'       => array( 'type' => 'integer' ),
                        'translations' => array( 'type' => 'integer' ),
                        'total'        => array( 'type' => 'integer' ),
                    ),
                    'required'    => array( 'core', 'plugins', 'themes', 'translations', 'total' ),
                ),
                'page'     => array( 'type' => 'integer' ),
                'per_page' => array( 'type' => 'integer' ),
                'total'    => array(
                    'type'        => 'integer',
                    'description' => __( 'Total number of updates matching filters.', 'mainwp' ),
                ),
                'errors'   => array(
                    'type'        => 'array',
                    'description' => __( 'Site resolution or access errors. Check errors.length > 0 to detect partial failures.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'site_id'   => array( 'type' => 'integer' ),
                            'site_url'  => array(
                                'type'   => 'string',
                                'format' => 'uri',
                            ),
                            'site_name' => array( 'type' => 'string' ),
                            'type'      => array(
                                'type'        => 'string',
                                'description' => __( 'Value is "site" for site-level errors (resolution, access).', 'mainwp' ),
                            ),
                            'slug'      => array( 'type' => 'string' ),
                            'code'      => array(
                                'type'        => 'string',
                                'description' => __( 'Error code for programmatic handling (e.g., mainwp_site_not_found, mainwp_access_denied).', 'mainwp' ),
                            ),
                            'message'   => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'site_id', 'site_url', 'type', 'slug', 'code', 'message' ),
                    ),
                ),
            ),
            'required'   => array( 'updates', 'summary', 'page', 'per_page', 'total' ),
        );
    }

    /**
     * Get output schema for run-updates-v1.
     *
     * Response has two modes:
     * - Immediate mode (≤200 sites): Returns updated, errors, summary
     * - Queued mode (>200 sites): Returns queued, job_id, status_url, updates_queued, errors
     *
     * The errors array is present in BOTH modes - for immediate mode it contains update failures,
     * for queued mode it contains pre-queue failures (site resolution/access errors).
     *
     * @return array
     */
    public static function get_run_updates_output_schema(): array {
        // Common error item schema used in both response modes.
        $error_item_schema = array(
            'type'       => 'object',
            'properties' => array(
                'site_id'   => array( 'type' => 'integer' ),
                'site_url'  => array(
                    'type'   => 'string',
                    'format' => 'uri',
                ),
                'site_name' => array( 'type' => 'string' ),
                'type'      => array(
                    'type'        => 'string',
                    'description' => __( 'Update type (plugin, theme, core, translation) or "site" for site-level errors.', 'mainwp' ),
                ),
                'slug'      => array( 'type' => 'string' ),
                'code'      => array(
                    'type'        => 'string',
                    'description' => __( 'Error code for programmatic handling (e.g., mainwp_site_offline, mainwp_access_denied).', 'mainwp' ),
                ),
                'message'   => array( 'type' => 'string' ),
            ),
            'required'   => array( 'site_id', 'site_url', 'type', 'slug', 'code', 'message' ),
        );

        // Common errors array schema.
        $errors_schema = array(
            'type'        => 'array',
            'description' => __( 'Failed updates or site-level errors. Present in both immediate and queued modes.', 'mainwp' ),
            'items'       => $error_item_schema,
        );

        return array(
            'type'        => 'object',
            'description' => __( 'Response varies by operation mode. Immediate mode (≤200 sites) returns updated/errors/summary. Queued mode (>200 sites) returns queued/job_id/status_url/updates_queued/errors.', 'mainwp' ),
            'oneOf'       => array(
                // Immediate execution response (≤ threshold sites).
                array(
                    'type'        => 'object',
                    'description' => __( 'Immediate execution response for operations with ≤200 sites.', 'mainwp' ),
                    'properties'  => array(
                        'updated' => array(
                            'type'        => 'array',
                            'description' => __( 'Successfully updated items.', 'mainwp' ),
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'site_id'     => array( 'type' => 'integer' ),
                                    'site_url'    => array(
                                        'type'   => 'string',
                                        'format' => 'uri',
                                    ),
                                    'site_name'   => array( 'type' => 'string' ),
                                    'type'        => array( 'type' => 'string' ),
                                    'slug'        => array( 'type' => 'string' ),
                                    'name'        => array( 'type' => 'string' ),
                                    'old_version' => array( 'type' => 'string' ),
                                    'new_version' => array( 'type' => 'string' ),
                                ),
                                'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'name', 'old_version', 'new_version' ),
                            ),
                        ),
                        'errors'  => $errors_schema,
                        'summary' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'total_updated' => array( 'type' => 'integer' ),
                                'total_errors'  => array( 'type' => 'integer' ),
                                'sites_updated' => array( 'type' => 'integer' ),
                            ),
                            'required'   => array( 'total_updated', 'total_errors', 'sites_updated' ),
                        ),
                    ),
                    'required'    => array( 'updated', 'errors', 'summary' ),
                ),
                // Queued execution response (> threshold sites).
                array(
                    'type'        => 'object',
                    'description' => __( 'Queued execution response for operations with >200 sites.', 'mainwp' ),
                    'properties'  => array(
                        'queued'         => array(
                            'type'        => 'boolean',
                            'description' => __( 'Always true for queued responses.', 'mainwp' ),
                            'const'       => true,
                        ),
                        'job_id'         => array(
                            'type'        => 'string',
                            'description' => __( 'Background job ID for status polling.', 'mainwp' ),
                        ),
                        'status_url'     => array(
                            'type'        => 'string',
                            'format'      => 'uri',
                            'description' => __( 'URL to poll for job status.', 'mainwp' ),
                        ),
                        'updates_queued' => array(
                            'type'        => 'integer',
                            'description' => __( 'Number of update operations queued.', 'mainwp' ),
                        ),
                        'errors'         => $errors_schema,
                    ),
                    'required'    => array( 'queued', 'job_id', 'status_url', 'updates_queued', 'errors' ),
                ),
            ),
        );
    }

    /**
     * Get output schema for list-ignored-updates-v1.
     *
     * Note: This endpoint provides only a flat total count without per-type breakdown.
     * Use the 'type' field on each ignored item for client-side grouping if needed.
     *
     * @return array
     */
    public static function get_list_ignored_updates_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'ignored' => array(
                    'type'        => 'array',
                    'description' => __( 'List of ignored updates. Each item includes its type (core, plugin, theme) for client-side filtering/grouping.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'site_id'         => array( 'type' => 'integer' ),
                            'site_url'        => array(
                                'type'   => 'string',
                                'format' => 'uri',
                            ),
                            'site_name'       => array( 'type' => 'string' ),
                            'type'            => array(
                                'type'        => 'string',
                                'enum'        => array( 'core', 'plugin', 'theme' ),
                                'description' => __( 'Update type. Use for filtering or grouping results.', 'mainwp' ),
                            ),
                            'slug'            => array( 'type' => 'string' ),
                            'name'            => array( 'type' => 'string' ),
                            'ignored_version' => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'name', 'ignored_version' ),
                    ),
                ),
                'total'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Total number of ignored updates (flat count, no per-type breakdown).', 'mainwp' ),
                ),
                'errors'  => array(
                    'type'        => 'array',
                    'description' => __( 'Site resolution or access errors. Check errors.length > 0 to detect partial failures.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'site_id'   => array( 'type' => 'integer' ),
                            'site_url'  => array(
                                'type'   => 'string',
                                'format' => 'uri',
                            ),
                            'site_name' => array( 'type' => 'string' ),
                            'type'      => array(
                                'type'        => 'string',
                                'description' => __( 'Value is "site" for site-level errors (resolution, access).', 'mainwp' ),
                            ),
                            'slug'      => array( 'type' => 'string' ),
                            'code'      => array(
                                'type'        => 'string',
                                'description' => __( 'Error code for programmatic handling (e.g., mainwp_site_not_found, mainwp_access_denied).', 'mainwp' ),
                            ),
                            'message'   => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'site_id', 'site_url', 'type', 'slug', 'code', 'message' ),
                    ),
                ),
            ),
            'required'   => array( 'ignored', 'total' ),
        );
    }

    /**
     * Get output schema for set-ignored-updates-v1.
     *
     * @return array
     */
    public static function get_set_ignored_updates_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'success' => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether the action was successful.', 'mainwp' ),
                ),
                'action'  => array(
                    'type'        => 'string',
                    'enum'        => array( 'ignore', 'unignore' ),
                    'description' => __( 'Action that was performed.', 'mainwp' ),
                ),
                'site_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Site ID.', 'mainwp' ),
                ),
                'type'    => array(
                    'type'        => 'string',
                    'description' => __( 'Update type.', 'mainwp' ),
                ),
                'slug'    => array(
                    'type'        => 'string',
                    'description' => __( 'Item slug.', 'mainwp' ),
                ),
            ),
            'required'   => array( 'success', 'action', 'site_id', 'type', 'slug' ),
        );
    }

    /**
     * Get input schema for get-site-updates-v1.
     *
     * @return array
     */
    public static function get_get_site_updates_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (numeric) or domain.', 'mainwp' ),
                ),
                'types'             => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'core', 'plugins', 'themes', 'translations' ),
                    ),
                    'default'     => array( 'core', 'plugins', 'themes', 'translations' ),
                    'description' => __( 'Update types to retrieve.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for get-site-updates-v1.
     *
     * @return array
     */
    public static function get_get_site_updates_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'site_id'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Site ID.', 'mainwp' ),
                ),
                'site_url'       => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => __( 'Site URL.', 'mainwp' ),
                ),
                'site_name'      => array(
                    'type'        => 'string',
                    'description' => __( 'Site name.', 'mainwp' ),
                ),
                'updates'        => array(
                    'type'        => 'array',
                    'description' => __( 'Available updates.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'type'            => array(
                                'type' => 'string',
                                'enum' => array( 'core', 'plugin', 'theme', 'translation' ),
                            ),
                            'slug'            => array( 'type' => 'string' ),
                            'name'            => array( 'type' => 'string' ),
                            'current_version' => array( 'type' => 'string' ),
                            'new_version'     => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'rollback_items' => array(
                    'type'        => 'object',
                    'description' => __( 'Available rollback versions.', 'mainwp' ),
                    'properties'  => array(
                        'plugins' => array(
                            'type'  => 'array',
                            'items' => array( 'type' => 'object' ),
                        ),
                        'themes'  => array(
                            'type'  => 'array',
                            'items' => array( 'type' => 'object' ),
                        ),
                    ),
                ),
                'summary'        => array(
                    'type'        => 'object',
                    'description' => __( 'Update count summary.', 'mainwp' ),
                    'properties'  => array(
                        'core'         => array( 'type' => 'integer' ),
                        'plugins'      => array( 'type' => 'integer' ),
                        'themes'       => array( 'type' => 'integer' ),
                        'translations' => array( 'type' => 'integer' ),
                        'total'        => array( 'type' => 'integer' ),
                    ),
                ),
            ),
            'required'   => array( 'site_id', 'site_url', 'site_name', 'updates', 'rollback_items', 'summary' ),
        );
    }

    /**
     * Get input schema for update-site-core-v1.
     *
     * @return array
     */
    public static function get_update_site_core_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (numeric) or domain.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for update-site-core-v1.
     *
     * @return array
     */
    public static function get_update_site_core_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'updated' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'site_id'     => array( 'type' => 'integer' ),
                        'site_url'    => array(
                            'type'   => 'string',
                            'format' => 'uri',
                        ),
                        'site_name'   => array( 'type' => 'string' ),
                        'type'        => array(
                            'type'  => 'string',
                            'const' => 'core',
                        ),
                        'slug'        => array( 'type' => 'string' ),
                        'name'        => array( 'type' => 'string' ),
                        'old_version' => array( 'type' => 'string' ),
                        'new_version' => array( 'type' => 'string' ),
                    ),
                    'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'name', 'old_version', 'new_version' ),
                ),
            ),
            'required'   => array( 'updated' ),
        );
    }

    /**
     * Get input schema for update-site-plugins-v1.
     *
     * @return array
     */
    public static function get_update_site_plugins_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (numeric) or domain.', 'mainwp' ),
                ),
                'slugs'             => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'default'     => array(),
                    'description' => __( 'Specific plugin slugs to update. Empty = all available.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for update-site-plugins-v1.
     *
     * @return array
     */
    public static function get_update_site_plugins_output_schema(): array {
        $update_item_schema = array(
            'type'       => 'object',
            'properties' => array(
                'site_id'     => array( 'type' => 'integer' ),
                'site_url'    => array(
                    'type'   => 'string',
                    'format' => 'uri',
                ),
                'site_name'   => array( 'type' => 'string' ),
                'type'        => array(
                    'type'  => 'string',
                    'const' => 'plugin',
                ),
                'slug'        => array( 'type' => 'string' ),
                'name'        => array( 'type' => 'string' ),
                'old_version' => array( 'type' => 'string' ),
                'new_version' => array( 'type' => 'string' ),
            ),
            'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'name', 'old_version', 'new_version' ),
        );

        $error_item_schema = array(
            'type'       => 'object',
            'properties' => array(
                'site_id'   => array( 'type' => 'integer' ),
                'site_url'  => array(
                    'type'   => 'string',
                    'format' => 'uri',
                ),
                'site_name' => array( 'type' => 'string' ),
                'type'      => array(
                    'type'  => 'string',
                    'const' => 'plugin',
                ),
                'slug'      => array( 'type' => 'string' ),
                'code'      => array( 'type' => 'string' ),
                'message'   => array( 'type' => 'string' ),
            ),
            'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'code', 'message' ),
        );

        return array(
            'type'       => 'object',
            'properties' => array(
                'updated' => array(
                    'type'  => 'array',
                    'items' => $update_item_schema,
                ),
                'errors'  => array(
                    'type'  => 'array',
                    'items' => $error_item_schema,
                ),
                'summary' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'total_updated' => array( 'type' => 'integer' ),
                        'total_errors'  => array( 'type' => 'integer' ),
                    ),
                    'required'   => array( 'total_updated', 'total_errors' ),
                ),
            ),
            'required'   => array( 'updated', 'errors', 'summary' ),
        );
    }

    /**
     * Get input schema for update-site-themes-v1.
     *
     * @return array
     */
    public static function get_update_site_themes_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (numeric) or domain.', 'mainwp' ),
                ),
                'slugs'             => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'default'     => array(),
                    'description' => __( 'Specific theme slugs to update. Empty = all available.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for update-site-themes-v1.
     *
     * @return array
     */
    public static function get_update_site_themes_output_schema(): array {
        $update_item_schema = array(
            'type'       => 'object',
            'properties' => array(
                'site_id'     => array( 'type' => 'integer' ),
                'site_url'    => array(
                    'type'   => 'string',
                    'format' => 'uri',
                ),
                'site_name'   => array( 'type' => 'string' ),
                'type'        => array(
                    'type'  => 'string',
                    'const' => 'theme',
                ),
                'slug'        => array( 'type' => 'string' ),
                'name'        => array( 'type' => 'string' ),
                'old_version' => array( 'type' => 'string' ),
                'new_version' => array( 'type' => 'string' ),
            ),
            'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'name', 'old_version', 'new_version' ),
        );

        $error_item_schema = array(
            'type'       => 'object',
            'properties' => array(
                'site_id'   => array( 'type' => 'integer' ),
                'site_url'  => array(
                    'type'   => 'string',
                    'format' => 'uri',
                ),
                'site_name' => array( 'type' => 'string' ),
                'type'      => array(
                    'type'  => 'string',
                    'const' => 'theme',
                ),
                'slug'      => array( 'type' => 'string' ),
                'code'      => array( 'type' => 'string' ),
                'message'   => array( 'type' => 'string' ),
            ),
            'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'code', 'message' ),
        );

        return array(
            'type'       => 'object',
            'properties' => array(
                'updated' => array(
                    'type'  => 'array',
                    'items' => $update_item_schema,
                ),
                'errors'  => array(
                    'type'  => 'array',
                    'items' => $error_item_schema,
                ),
                'summary' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'total_updated' => array( 'type' => 'integer' ),
                        'total_errors'  => array( 'type' => 'integer' ),
                    ),
                    'required'   => array( 'total_updated', 'total_errors' ),
                ),
            ),
            'required'   => array( 'updated', 'errors', 'summary' ),
        );
    }

    /**
     * Get input schema for update-site-translations-v1.
     *
     * @return array
     */
    public static function get_update_site_translations_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (numeric) or domain.', 'mainwp' ),
                ),
                'slugs'             => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'default'     => array(),
                    'description' => __( 'Specific translation slugs to update. Empty = all available.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for update-site-translations-v1.
     *
     * @return array
     */
    public static function get_update_site_translations_output_schema(): array {
        $update_item_schema = array(
            'type'       => 'object',
            'properties' => array(
                'site_id'     => array( 'type' => 'integer' ),
                'site_url'    => array(
                    'type'   => 'string',
                    'format' => 'uri',
                ),
                'site_name'   => array( 'type' => 'string' ),
                'type'        => array(
                    'type'  => 'string',
                    'const' => 'translation',
                ),
                'slug'        => array( 'type' => 'string' ),
                'name'        => array( 'type' => 'string' ),
                'old_version' => array( 'type' => 'string' ),
                'new_version' => array( 'type' => 'string' ),
            ),
            'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'name', 'old_version', 'new_version' ),
        );

        $error_item_schema = array(
            'type'       => 'object',
            'properties' => array(
                'site_id'   => array( 'type' => 'integer' ),
                'site_url'  => array(
                    'type'   => 'string',
                    'format' => 'uri',
                ),
                'site_name' => array( 'type' => 'string' ),
                'type'      => array(
                    'type'  => 'string',
                    'const' => 'translation',
                ),
                'slug'      => array( 'type' => 'string' ),
                'code'      => array( 'type' => 'string' ),
                'message'   => array( 'type' => 'string' ),
            ),
            'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'code', 'message' ),
        );

        return array(
            'type'       => 'object',
            'properties' => array(
                'updated' => array(
                    'type'  => 'array',
                    'items' => $update_item_schema,
                ),
                'errors'  => array(
                    'type'  => 'array',
                    'items' => $error_item_schema,
                ),
                'summary' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'total_updated' => array( 'type' => 'integer' ),
                        'total_errors'  => array( 'type' => 'integer' ),
                    ),
                    'required'   => array( 'total_updated', 'total_errors' ),
                ),
            ),
            'required'   => array( 'updated', 'errors', 'summary' ),
        );
    }

    /**
     * Get input schema for ignore-site-core-v1.
     *
     * @return array
     */
    public static function get_ignore_site_core_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (numeric) or domain.', 'mainwp' ),
                ),
                'action'            => array(
                    'type'        => 'string',
                    'enum'        => array( 'add', 'remove' ),
                    'default'     => 'add',
                    'description' => __( 'Add to or remove from ignored list.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for ignore-site-plugins-v1.
     *
     * @return array
     */
    public static function get_ignore_site_plugins_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (numeric) or domain.', 'mainwp' ),
                ),
                'slugs'             => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => __( 'Plugin slugs to ignore.', 'mainwp' ),
                ),
                'action'            => array(
                    'type'        => 'string',
                    'enum'        => array( 'add', 'remove' ),
                    'default'     => 'add',
                    'description' => __( 'Add to or remove from ignored list.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain', 'slugs' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for ignore-site-themes-v1.
     *
     * @return array
     */
    public static function get_ignore_site_themes_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID (numeric) or domain.', 'mainwp' ),
                ),
                'slugs'             => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => __( 'Theme slugs to ignore.', 'mainwp' ),
                ),
                'action'            => array(
                    'type'        => 'string',
                    'enum'        => array( 'add', 'remove' ),
                    'default'     => 'add',
                    'description' => __( 'Add to or remove from ignored list.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain', 'slugs' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for ignore-site-* abilities (shared).
     *
     * @return array
     */
    public static function get_ignore_site_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'success'       => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether the action was successful.', 'mainwp' ),
                ),
                'message'       => array(
                    'type'        => 'string',
                    'description' => __( 'Success or error message.', 'mainwp' ),
                ),
                'ignored_count' => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of items affected.', 'mainwp' ),
                ),
            ),
            'required'   => array( 'success', 'message', 'ignored_count' ),
        );
    }

    /**
     * Get input schema for update-all-v1.
     *
     * @return array
     */
    public static function get_update_all_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => array( 'integer', 'string' ),
                    ),
                    'default'     => array(),
                    'description' => __( 'Sites to update. Empty array means all sites.', 'mainwp' ),
                ),
                'types'               => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'core', 'plugins', 'themes', 'translations' ),
                    ),
                    'default'     => array( 'core', 'plugins', 'themes', 'translations' ),
                    'description' => __( 'Update types to execute.', 'mainwp' ),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for update-all-v1.
     *
     * @return array
     */
    public static function get_update_all_output_schema(): array {
        $error_item_schema = array(
            'type'       => 'object',
            'properties' => array(
                'site_id'   => array( 'type' => 'integer' ),
                'site_url'  => array(
                    'type'   => 'string',
                    'format' => 'uri',
                ),
                'site_name' => array( 'type' => 'string' ),
                'type'      => array(
                    'type'        => 'string',
                    'description' => __( 'Update type or "site" for site-level errors.', 'mainwp' ),
                ),
                'slug'      => array( 'type' => 'string' ),
                'code'      => array(
                    'type'        => 'string',
                    'description' => __( 'Error code.', 'mainwp' ),
                ),
                'message'   => array( 'type' => 'string' ),
            ),
            'required'   => array( 'site_id', 'site_url', 'type', 'slug', 'code', 'message' ),
        );

        $errors_schema = array(
            'type'        => 'array',
            'description' => __( 'Failed updates or site-level errors.', 'mainwp' ),
            'items'       => $error_item_schema,
        );

        return array(
            'type'        => 'object',
            'description' => __( 'Response varies by operation mode. Immediate mode (≤200 sites) returns updated/errors/summary. Queued mode (>200 sites) returns queued/job_id/status_url/updates_queued/errors.', 'mainwp' ),
            'oneOf'       => array(
                array(
                    'type'        => 'object',
                    'description' => __( 'Immediate execution response for operations with ≤200 sites.', 'mainwp' ),
                    'properties'  => array(
                        'updated' => array(
                            'type'        => 'array',
                            'description' => __( 'Successfully updated items.', 'mainwp' ),
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'site_id'     => array( 'type' => 'integer' ),
                                    'site_url'    => array(
                                        'type'   => 'string',
                                        'format' => 'uri',
                                    ),
                                    'site_name'   => array( 'type' => 'string' ),
                                    'type'        => array( 'type' => 'string' ),
                                    'slug'        => array( 'type' => 'string' ),
                                    'name'        => array( 'type' => 'string' ),
                                    'old_version' => array( 'type' => 'string' ),
                                    'new_version' => array( 'type' => 'string' ),
                                ),
                                'required'   => array( 'site_id', 'site_url', 'site_name', 'type', 'slug', 'name', 'old_version', 'new_version' ),
                            ),
                        ),
                        'errors'  => $errors_schema,
                        'summary' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'total_updated' => array( 'type' => 'integer' ),
                                'total_errors'  => array( 'type' => 'integer' ),
                                'sites_updated' => array( 'type' => 'integer' ),
                            ),
                            'required'   => array( 'total_updated', 'total_errors', 'sites_updated' ),
                        ),
                    ),
                    'required'    => array( 'updated', 'errors', 'summary' ),
                ),
                array(
                    'type'        => 'object',
                    'description' => __( 'Queued execution response for operations with >200 sites.', 'mainwp' ),
                    'properties'  => array(
                        'queued'         => array(
                            'type'        => 'boolean',
                            'description' => __( 'Always true for queued responses.', 'mainwp' ),
                            'const'       => true,
                        ),
                        'job_id'         => array(
                            'type'        => 'string',
                            'description' => __( 'Background job ID for status polling.', 'mainwp' ),
                        ),
                        'status_url'     => array(
                            'type'        => 'string',
                            'format'      => 'uri',
                            'description' => __( 'URL to poll for job status.', 'mainwp' ),
                        ),
                        'updates_queued' => array(
                            'type'        => 'integer',
                            'description' => __( 'Number of update operations queued.', 'mainwp' ),
                        ),
                        'errors'         => $errors_schema,
                    ),
                    'required'    => array( 'queued', 'job_id', 'status_url', 'updates_queued', 'errors' ),
                ),
            ),
        );
    }

    // =========================================================================
    // Execute Callbacks
    // =========================================================================

    /**
     * Execute callback for mainwp/list-updates-v1.
     *
     * @param array|null $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_list_updates( $input ) { // phpcs:ignore -- NOSONAR - complex method.
        $input               = is_array( $input ) ? $input : array();
        $site_ids_or_domains = $input['site_ids_or_domains'] ?? array();
        $types               = $input['types'] ?? array();
        $page                = (int) ( $input['page'] ?? 1 );
        $per_page            = (int) ( $input['per_page'] ?? 50 );

        // If empty types, include all.
        if ( empty( $types ) ) {
            $types = array( 'core', 'plugins', 'themes', 'translations' );
        }

        $sites  = array();
        $errors = array();

        // When specific site identifiers are provided, enforce per-site ACLs.
        // When empty (all sites), rely on get_websites_for_current_user() which respects user access.
        if ( ! empty( $site_ids_or_domains ) ) {
            // Check per-site ACLs and filter to allowed sites.
            $access_check = MainWP_Abilities_Util::check_batch_site_access( $site_ids_or_domains, $input );

            $sites = $access_check['allowed'];

            // Normalize ACL-denied errors to match the documented error item schema.
            foreach ( $access_check['denied'] as $denied_entry ) {
                $errors[] = array(
                    'site_id'   => 0,
                    'site_url'  => is_scalar( $denied_entry['identifier'] ) ? (string) $denied_entry['identifier'] : '',
                    'site_name' => '',
                    'type'      => 'site',
                    'slug'      => '',
                    'code'      => $denied_entry['code'],
                    'message'   => $denied_entry['message'],
                );
            }
        } else {
            // Get all sites for current user (respects user access by default).
            // Must request update fields explicitly - default only returns id, url, name, client_id.
            $websites = MainWP_DB::instance()->get_websites_for_current_user(
                array(
                    'selectgroups' => false,
                    'fields'       => array(
                        'plugin_upgrades',
                        'theme_upgrades',
                        'translation_upgrades',
                        'ignored_plugins',
                        'ignored_themes',
                        'is_ignoreCoreUpdates',
                        'is_ignorePluginUpdates',
                        'is_ignoreThemeUpdates',
                    ),
                )
            );

            if ( is_wp_error( $websites ) ) {
                return $websites;
            }

            $sites = $websites ? $websites : array();
        }

        // Get global ignored lists.
        // Defensively handle case where user_extension row doesn't exist.
        $user_extension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_plugins = array();
        $global_ignored_themes  = array();
        $global_ignored_core    = array();

        if ( is_object( $user_extension ) ) {
            $global_ignored_plugins = ! empty( $user_extension->ignored_plugins ) ? json_decode( $user_extension->ignored_plugins, true ) : array();
            $global_ignored_themes  = ! empty( $user_extension->ignored_themes ) ? json_decode( $user_extension->ignored_themes, true ) : array();
            $global_ignored_core    = ! empty( $user_extension->ignored_wp_upgrades ) ? json_decode( $user_extension->ignored_wp_upgrades, true ) : array();
        }

        if ( ! is_array( $global_ignored_plugins ) ) {
            $global_ignored_plugins = array();
        }
        if ( ! is_array( $global_ignored_themes ) ) {
            $global_ignored_themes = array();
        }
        if ( ! is_array( $global_ignored_core ) ) {
            $global_ignored_core = array();
        }

        // Collect all updates.
        $all_updates = array();
        $summary     = array(
            'core'         => 0,
            'plugins'      => 0,
            'themes'       => 0,
            'translations' => 0,
            'total'        => 0,
        );

        foreach ( $sites as $site ) {
            $site_updates = self::get_site_updates(
                $site,
                $types,
                $global_ignored_plugins,
                $global_ignored_themes,
                $global_ignored_core
            );

            foreach ( $site_updates as $update ) {
                $all_updates[] = $update;

                // Update summary counts.
                $type_key = $update['type'];
                if ( 'plugin' === $type_key ) {
                    $type_key = 'plugins';
                } elseif ( 'theme' === $type_key ) {
                    $type_key = 'themes';
                } elseif ( 'translation' === $type_key ) {
                    $type_key = 'translations';
                }

                if ( isset( $summary[ $type_key ] ) ) {
                    ++$summary[ $type_key ];
                }
                ++$summary['total'];
            }
        }

        // Apply pagination.
        $total       = count( $all_updates );
        $offset      = ( $page - 1 ) * $per_page;
        $paged_items = array_slice( $all_updates, $offset, $per_page );

        return array(
            'updates'  => $paged_items,
            'summary'  => $summary,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
            'errors'   => $errors,
        );
    }

    /**
     * Execute callback for mainwp/run-updates-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_run_updates( ?array $input ) { // phpcs:ignore -- NOSONAR - complex method.
        // Handle null input (when called without input parameter).
        $input = $input ?? array();

        $site_ids_or_domains = $input['site_ids_or_domains'] ?? array();
        $types               = $input['types'] ?? array();
        $specific_items      = $input['specific_items'] ?? array();

        // If empty types, include all.
        if ( empty( $types ) ) {
            $types = array( 'core', 'plugins', 'themes', 'translations' );
        }

        // If empty, get all sites for current user.
        if ( empty( $site_ids_or_domains ) ) {
            $all_sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );

            if ( is_wp_error( $all_sites ) ) {
                return $all_sites;
            }

            $site_ids_or_domains = array_map(
                function ( $s ) {
                    return (int) $s->id;
                },
                $all_sites ? $all_sites : array()
            );
        }

        // Check per-site ACLs and filter to allowed sites.
        $access_check = MainWP_Abilities_Util::check_batch_site_access( $site_ids_or_domains, $input );

        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', self::BATCH_THRESHOLD );

        if ( count( $access_check['allowed'] ) > $threshold ) {
            $job_id = MainWP_Abilities_Util::queue_batch_updates(
                $access_check['allowed'],
                array(
                    'types'          => $types,
                    'specific_items' => $specific_items,
                )
            );

            if ( is_wp_error( $job_id ) ) {
                return $job_id;
            }

            // Normalize ACL-denied errors to match the documented error item schema.
            // This ensures clients can handle partial failures uniformly whether immediate or queued.
            $errors = array();
            foreach ( $access_check['denied'] as $denied_entry ) {
                $errors[] = array(
                    'site_id'  => 0, // Not resolvable.
                    'site_url' => is_scalar( $denied_entry['identifier'] ) ? (string) $denied_entry['identifier'] : '',
                    'type'     => 'site',
                    'slug'     => '',
                    'code'     => $denied_entry['code'],
                    'message'  => $denied_entry['message'],
                );
            }

            return array(
                'queued'         => true,
                'job_id'         => $job_id,
                'status_url'     => rest_url( "mainwp/v2/jobs/{$job_id}" ),
                'updates_queued' => count( $access_check['allowed'] ),
                'errors'         => $errors,
            );
        }

        // Immediate execution for ≤ BATCH_THRESHOLD sites.
        $updated       = array();
        $sites_updated = array();

        // Normalize ACL-denied errors to match the documented error item schema.
        // The check_batch_site_access() returns errors with 'identifier', 'code', 'message', 'status' keys,
        // but the output schema expects 'site_id', 'site_url', 'type', 'slug', 'code', 'message'.
        $errors = array();
        foreach ( $access_check['denied'] as $denied_entry ) {
            $errors[] = array(
                'site_id'  => 0, // Not resolvable.
                'site_url' => is_scalar( $denied_entry['identifier'] ) ? (string) $denied_entry['identifier'] : '',
                'type'     => 'site',
                'slug'     => '',
                'code'     => $denied_entry['code'],
                'message'  => $denied_entry['message'],
            );
        }

        // Get global ignored lists.
        // Defensively handle case where user_extension row doesn't exist.
        $user_extension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_plugins = array();
        $global_ignored_themes  = array();
        $global_ignored_core    = array();

        if ( is_object( $user_extension ) ) {
            $global_ignored_plugins = ! empty( $user_extension->ignored_plugins ) ? json_decode( $user_extension->ignored_plugins, true ) : array();
            $global_ignored_themes  = ! empty( $user_extension->ignored_themes ) ? json_decode( $user_extension->ignored_themes, true ) : array();
            $global_ignored_core    = ! empty( $user_extension->ignored_wp_upgrades ) ? json_decode( $user_extension->ignored_wp_upgrades, true ) : array();
        }

        if ( ! is_array( $global_ignored_plugins ) ) {
            $global_ignored_plugins = array();
        }
        if ( ! is_array( $global_ignored_themes ) ) {
            $global_ignored_themes = array();
        }
        if ( ! is_array( $global_ignored_core ) ) {
            $global_ignored_core = array();
        }

        foreach ( $access_check['allowed'] as $site ) {
            // Check if site is known offline before attempting updates.
            if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
                $errors[] = array(
                    'site_id'  => (int) $site->id,
                    'site_url' => $site->url,
                    'type'     => 'site',
                    'slug'     => '',
                    'code'     => 'mainwp_site_offline',
                    'message'  => __( 'Site is known to be offline.', 'mainwp' ),
                );
                continue;
            }

            // Check child version before updates.
            $version_check = MainWP_Abilities_Util::check_child_version( $site );
            if ( is_wp_error( $version_check ) ) {
                $errors[] = array(
                    'site_id'  => (int) $site->id,
                    'site_url' => $site->url,
                    'type'     => 'site',
                    'slug'     => '',
                    'code'     => $version_check->get_error_code(),
                    'message'  => $version_check->get_error_message(),
                );
                continue;
            }

            // Get available updates for this site.
            $site_updates = self::get_site_updates(
                $site,
                $types,
                $global_ignored_plugins,
                $global_ignored_themes,
                $global_ignored_core
            );

            // Filter by specific_items if provided.
            if ( ! empty( $specific_items ) ) {
                $site_updates = array_filter(
                    $site_updates,
                    function ( $update ) use ( $specific_items ) {
                        return in_array( $update['slug'], $specific_items, true );
                    }
                );
            }

            // Group updates by type.
            $grouped = array(
                'core'        => array(),
                'plugin'      => array(),
                'theme'       => array(),
                'translation' => array(),
            );

            foreach ( $site_updates as $update ) {
                $grouped[ $update['type'] ][] = $update;
            }

            // Execute updates by type.
            $site_had_updates = false;

            // Allow filtering of update result for testing/extension purposes.
            // Filter can return WP_Error to simulate site-level failure.
            $pre_result = apply_filters( 'mainwp_run_update_result', null, (int) $site->id, $types );
            if ( is_wp_error( $pre_result ) ) {
                $errors[] = array(
                    'site_id'  => (int) $site->id,
                    'site_url' => $site->url,
                    'type'     => 'site',
                    'slug'     => '',
                    'code'     => $pre_result->get_error_code(),
                    'message'  => $pre_result->get_error_message(),
                );
                continue;
            }

            // Core updates.
            if ( in_array( 'core', $types, true ) && ! empty( $grouped['core'] ) ) {
                $core_result = self::execute_core_update( $site, $grouped['core'][0] );
                if ( is_wp_error( $core_result ) ) {
                    $errors[] = array(
                        'site_id'  => (int) $site->id,
                        'site_url' => $site->url,
                        'type'     => 'core',
                        'slug'     => 'wordpress',
                        'code'     => $core_result->get_error_code(),
                        'message'  => $core_result->get_error_message(),
                    );
                } else {
                    $updated[]        = $core_result;
                    $site_had_updates = true;
                }
            }

            // Plugin updates.
            if ( in_array( 'plugins', $types, true ) && ! empty( $grouped['plugin'] ) ) {
                $plugin_results = self::execute_plugin_updates( $site, $grouped['plugin'] );
                foreach ( $plugin_results['updated'] as $result ) {
                    $updated[]        = $result;
                    $site_had_updates = true;
                }
                foreach ( $plugin_results['errors'] as $error ) {
                    $errors[] = $error;
                }
            }

            // Theme updates.
            if ( in_array( 'themes', $types, true ) && ! empty( $grouped['theme'] ) ) {
                $theme_results = self::execute_theme_updates( $site, $grouped['theme'] );
                foreach ( $theme_results['updated'] as $result ) {
                    $updated[]        = $result;
                    $site_had_updates = true;
                }
                foreach ( $theme_results['errors'] as $error ) {
                    $errors[] = $error;
                }
            }

            // Translation updates.
            if ( in_array( 'translations', $types, true ) && ! empty( $grouped['translation'] ) ) {
                $translation_results = self::execute_translation_updates( $site, $grouped['translation'] );
                foreach ( $translation_results['updated'] as $result ) {
                    $updated[]        = $result;
                    $site_had_updates = true;
                }
                foreach ( $translation_results['errors'] as $error ) {
                    $errors[] = $error;
                }
            }

            if ( $site_had_updates ) {
                $sites_updated[ (int) $site->id ] = true;
            }
        }

        return array(
            'updated' => $updated,
            'errors'  => $errors,
            'summary' => array(
                'total_updated' => count( $updated ),
                'total_errors'  => count( $errors ),
                'sites_updated' => count( $sites_updated ),
            ),
        );
    }

    /**
     * Execute callback for mainwp/list-ignored-updates-v1.
     *
     * @param array|null $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_list_ignored_updates( $input ) { // phpcs:ignore -- NOSONAR - complex method.
        $input               = is_array( $input ) ? $input : array();
        $site_ids_or_domains = $input['site_ids_or_domains'] ?? array();
        $types               = $input['types'] ?? array();

        // If empty types, include all.
        if ( empty( $types ) ) {
            $types = array( 'core', 'plugins', 'themes' );
        }

        $sites  = array();
        $errors = array();

        // When specific site identifiers are provided, enforce per-site ACLs.
        // When empty (all sites), rely on get_websites_for_current_user() which respects user access.
        if ( ! empty( $site_ids_or_domains ) ) {
            // Check per-site ACLs and filter to allowed sites.
            $access_check = MainWP_Abilities_Util::check_batch_site_access( $site_ids_or_domains, $input );

            $sites = $access_check['allowed'];

            // Normalize ACL-denied errors to match the documented error item schema.
            foreach ( $access_check['denied'] as $denied_entry ) {
                $errors[] = array(
                    'site_id'   => 0,
                    'site_url'  => is_scalar( $denied_entry['identifier'] ) ? (string) $denied_entry['identifier'] : '',
                    'site_name' => '',
                    'type'      => 'site',
                    'slug'      => '',
                    'code'      => $denied_entry['code'],
                    'message'   => $denied_entry['message'],
                );
            }
        } else {
            // Get all sites for current user (respects user access by default).
            // Must request ignored fields explicitly - default only returns id, url, name, client_id.
            $websites = MainWP_DB::instance()->get_websites_for_current_user(
                array(
                    'selectgroups' => false,
                    'fields'       => array(
                        'ignored_plugins',
                        'ignored_themes',
                    ),
                )
            );

            if ( is_wp_error( $websites ) ) {
                return $websites;
            }

            $sites = $websites ? $websites : array();
        }

        // Get global ignored lists.
        // Defensively handle case where user_extension row doesn't exist.
        $user_extension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_plugins = array();
        $global_ignored_themes  = array();
        $global_ignored_core    = array();

        if ( is_object( $user_extension ) ) {
            $global_ignored_plugins = ! empty( $user_extension->ignored_plugins ) ? json_decode( $user_extension->ignored_plugins, true ) : array();
            $global_ignored_themes  = ! empty( $user_extension->ignored_themes ) ? json_decode( $user_extension->ignored_themes, true ) : array();
            $global_ignored_core    = ! empty( $user_extension->ignored_wp_upgrades ) ? json_decode( $user_extension->ignored_wp_upgrades, true ) : array();
        }

        if ( ! is_array( $global_ignored_plugins ) ) {
            $global_ignored_plugins = array();
        }
        if ( ! is_array( $global_ignored_themes ) ) {
            $global_ignored_themes = array();
        }
        if ( ! is_array( $global_ignored_core ) ) {
            $global_ignored_core = array();
        }

        // Collect all ignored updates.
        $all_ignored = array();

        foreach ( $sites as $site ) {
            $site_id   = (int) $site->id;
            $site_url  = $site->url;
            $site_name = MainWP_Utility::remove_http_prefix( (string) $site->name, true );

            // Get per-site ignored lists.
            $site_ignored_plugins = ! empty( $site->ignored_plugins ) ? json_decode( $site->ignored_plugins, true ) : array();
            $site_ignored_themes  = ! empty( $site->ignored_themes ) ? json_decode( $site->ignored_themes, true ) : array();

            // Get core ignored from website option.
            $site_ignored_core = MainWP_DB::instance()->get_website_option( $site, 'ignored_wp_upgrades' );
            $site_ignored_core = ! empty( $site_ignored_core ) ? json_decode( $site_ignored_core, true ) : array();

            if ( ! is_array( $site_ignored_plugins ) ) {
                $site_ignored_plugins = array();
            }
            if ( ! is_array( $site_ignored_themes ) ) {
                $site_ignored_themes = array();
            }
            if ( ! is_array( $site_ignored_core ) ) {
                $site_ignored_core = array();
            }

            // Merge per-site with global (per-site takes precedence for plugins/themes keyed by slug).
            $merged_plugins = array_merge( $global_ignored_plugins, $site_ignored_plugins );
            $merged_themes  = array_merge( $global_ignored_themes, $site_ignored_themes );

            // Core ignored: Structure is { 'ignored_versions' => [...] }, not keyed by slug.
            // Per-site takes precedence if it has ignored_versions, otherwise use global.
            // We check both separately rather than merging, as they share the same key structure.
            if ( in_array( 'core', $types, true ) ) {
                // Determine the effective ignored_versions for core.
                // Per-site takes precedence if it has any ignored versions set.
                $effective_core_ignored = array();
                if ( ! empty( $site_ignored_core['ignored_versions'] ) && is_array( $site_ignored_core['ignored_versions'] ) ) {
                    $effective_core_ignored = $site_ignored_core;
                } elseif ( ! empty( $global_ignored_core['ignored_versions'] ) && is_array( $global_ignored_core['ignored_versions'] ) ) {
                    $effective_core_ignored = $global_ignored_core;
                }

                if ( ! empty( $effective_core_ignored['ignored_versions'] ) ) {
                    $ignored_version = implode( ', ', $effective_core_ignored['ignored_versions'] );

                    $all_ignored[] = array(
                        'site_id'         => $site_id,
                        'site_url'        => $site_url,
                        'site_name'       => $site_name,
                        'type'            => 'core',
                        'slug'            => 'wordpress',
                        'name'            => 'WordPress',
                        'ignored_version' => $ignored_version,
                    );
                }
            }

            // Plugin ignored.
            if ( in_array( 'plugins', $types, true ) ) {
                foreach ( $merged_plugins as $slug => $info ) {
                    $name            = is_array( $info ) && isset( $info['Name'] ) ? $info['Name'] : $slug;
                    $ignored_version = 'all_versions';

                    if ( is_array( $info ) && isset( $info['ignored_versions'] ) && is_array( $info['ignored_versions'] ) ) {
                        $ignored_version = implode( ', ', $info['ignored_versions'] );
                    } elseif ( is_string( $info ) ) {
                        // Old format: just the name.
                        $name = $info;
                    }

                    $all_ignored[] = array(
                        'site_id'         => $site_id,
                        'site_url'        => $site_url,
                        'site_name'       => $site_name,
                        'type'            => 'plugin',
                        'slug'            => $slug,
                        'name'            => $name,
                        'ignored_version' => $ignored_version,
                    );
                }
            }

            // Theme ignored.
            if ( in_array( 'themes', $types, true ) ) {
                foreach ( $merged_themes as $slug => $info ) {
                    $name            = is_array( $info ) && isset( $info['Name'] ) ? $info['Name'] : $slug;
                    $ignored_version = 'all_versions';

                    if ( is_array( $info ) && isset( $info['ignored_versions'] ) && is_array( $info['ignored_versions'] ) ) {
                        $ignored_version = implode( ', ', $info['ignored_versions'] );
                    } elseif ( is_string( $info ) ) {
                        // Old format: just the name.
                        $name = $info;
                    }

                    $all_ignored[] = array(
                        'site_id'         => $site_id,
                        'site_url'        => $site_url,
                        'site_name'       => $site_name,
                        'type'            => 'theme',
                        'slug'            => $slug,
                        'name'            => $name,
                        'ignored_version' => $ignored_version,
                    );
                }
            }
        }

        return array(
            'ignored' => $all_ignored,
            'total'   => count( $all_ignored ),
            'errors'  => $errors,
        );
    }

    /**
     * Execute callback for mainwp/set-ignored-updates-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_set_ignored_updates( array $input ) {
        $action            = $input['action'];
        $site_id_or_domain = $input['site_id_or_domain'];
        $type              = $input['type'];
        $slug              = $input['slug'];

        // Validate action (defense-in-depth beyond schema validation).
        if ( ! in_array( $action, array( 'ignore', 'unignore' ), true ) ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                /* translators: %s: The invalid action value provided */
                sprintf( __( 'Invalid action: %s. Must be "ignore" or "unignore".', 'mainwp' ), $action ),
                array( 'status' => 400 )
            );
        }

        // Validate type (defense-in-depth beyond schema validation).
        if ( ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                /* translators: %s: The invalid type value provided */
                sprintf( __( 'Invalid type: %s. Must be "core", "plugin", or "theme".', 'mainwp' ), $type ),
                array( 'status' => 400 )
            );
        }

        // Resolve site.
        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        // Check site access.
        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $site_id = (int) $site->id;

        if ( 'ignore' === $action ) {
            $result = self::add_to_ignored_list( $site, $type, $slug );
        } else {
            $result = self::remove_from_ignored_list( $site, $type, $slug );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'success' => true,
            'action'  => $action,
            'site_id' => $site_id,
            'type'    => $type,
            'slug'    => $slug,
        );
    }

    /**
     * Execute callback for mainwp/get-site-updates-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_get_site_updates( array $input ) { //phpcs:ignore -- NOSONAR - complex method.
        $site_id_or_domain = $input['site_id_or_domain'];
        $types             = $input['types'] ?? array( 'core', 'plugins', 'themes', 'translations' );

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $user_extension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_plugins = array();
        $global_ignored_themes  = array();
        $global_ignored_core    = array();

        if ( is_object( $user_extension ) ) {
            $global_ignored_plugins = ! empty( $user_extension->ignored_plugins ) ? json_decode( $user_extension->ignored_plugins, true ) : array();
            $global_ignored_themes  = ! empty( $user_extension->ignored_themes ) ? json_decode( $user_extension->ignored_themes, true ) : array();
            $global_ignored_core    = ! empty( $user_extension->ignored_wp_upgrades ) ? json_decode( $user_extension->ignored_wp_upgrades, true ) : array();
        }

        if ( ! is_array( $global_ignored_plugins ) ) {
            $global_ignored_plugins = array();
        }
        if ( ! is_array( $global_ignored_themes ) ) {
            $global_ignored_themes = array();
        }
        if ( ! is_array( $global_ignored_core ) ) {
            $global_ignored_core = array();
        }

        $updates = self::get_site_updates( $site, $types, $global_ignored_plugins, $global_ignored_themes, $global_ignored_core );

        $rollback_data = array(
            'plugins' => array(),
            'themes'  => array(),
        );

        if ( class_exists( '\MainWP\Dashboard\MainWP_Updates_Helper' ) ) {
            $rollback_items = MainWP_Updates_Helper::instance()->get_roll_items_updates_of_site( $site );
            if ( is_array( $rollback_items ) ) {
                // Merge returned data into defaults to ensure plugins/themes keys always exist.
                $rollback_data = array_merge( $rollback_data, $rollback_items );
            }
        }

        $summary = array(
            'core'         => 0,
            'plugins'      => 0,
            'themes'       => 0,
            'translations' => 0,
            'total'        => count( $updates ),
        );

        foreach ( $updates as $update ) {
            if ( 'core' === $update['type'] ) {
                ++$summary['core'];
            } elseif ( 'plugin' === $update['type'] ) {
                ++$summary['plugins'];
            } elseif ( 'theme' === $update['type'] ) {
                ++$summary['themes'];
            } elseif ( 'translation' === $update['type'] ) {
                ++$summary['translations'];
            }
        }

        return array(
            'site_id'        => (int) $site->id,
            'site_url'       => $site->url,
            'site_name'      => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
            'updates'        => $updates,
            'rollback_items' => $rollback_data,
            'summary'        => $summary,
        );
    }

    /**
     * Execute callback for mainwp/update-site-core-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_update_site_core( array $input ) {
        $site_id_or_domain = $input['site_id_or_domain'];

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
            return new \WP_Error(
                'mainwp_site_offline',
                __( 'Site is known to be offline.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $child_version_check = MainWP_Abilities_Util::check_child_version( $site, '4.0' );
        if ( is_wp_error( $child_version_check ) ) {
            return $child_version_check;
        }

        $wp_upgrades = MainWP_DB::instance()->get_website_option( $site, 'wp_upgrades' );
        $wp_upgrades = ! empty( $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();

        if ( empty( $wp_upgrades ) || ! isset( $wp_upgrades['current'] ) || ! isset( $wp_upgrades['new'] ) ) {
            return new \WP_Error(
                'mainwp_no_updates',
                __( 'No core updates available for this site.', 'mainwp' ),
                array( 'status' => 404 )
            );
        }

        $update_info = array(
            'current_version' => $wp_upgrades['current'],
            'new_version'     => $wp_upgrades['new'],
        );

        $result = self::execute_core_update( $site, $update_info );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'updated' => $result,
        );
    }

    /**
     * Execute callback for mainwp/update-site-plugins-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_update_site_plugins( array $input ) { // phpcs:ignore -- NOSONAR - complex method.
        $site_id_or_domain = $input['site_id_or_domain'];
        $slugs             = $input['slugs'] ?? array();

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
            return new \WP_Error(
                'mainwp_site_offline',
                __( 'Site is known to be offline.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $child_version_check = MainWP_Abilities_Util::check_child_version( $site, '4.0' );
        if ( is_wp_error( $child_version_check ) ) {
            return $child_version_check;
        }

        $user_extension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_plugins = array();
        if ( is_object( $user_extension ) ) {
            $global_ignored_plugins = ! empty( $user_extension->ignored_plugins ) ? json_decode( $user_extension->ignored_plugins, true ) : array();
        }
        if ( ! is_array( $global_ignored_plugins ) ) {
            $global_ignored_plugins = array();
        }

        $site_ignored_plugins = ! empty( $site->ignored_plugins ) ? json_decode( $site->ignored_plugins, true ) : array();
        if ( ! is_array( $site_ignored_plugins ) ) {
            $site_ignored_plugins = array();
        }

        $plugin_upgrades = ! empty( $site->plugin_upgrades ) ? json_decode( $site->plugin_upgrades, true ) : array();
        if ( ! is_array( $plugin_upgrades ) || empty( $plugin_upgrades ) ) {
            return array(
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_updated' => 0,
                    'total_errors'  => 0,
                ),
            );
        }

        if ( ! empty( $site_ignored_plugins ) ) {
            $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $site_ignored_plugins );
        }
        if ( ! empty( $global_ignored_plugins ) ) {
            $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $global_ignored_plugins );
        }

        if ( ! empty( $slugs ) ) {
            $plugin_upgrades = array_filter(
                $plugin_upgrades,
                function ( $slug ) use ( $slugs ) {
                    return in_array( $slug, $slugs, true );
                },
                ARRAY_FILTER_USE_KEY
            );
        }

        if ( empty( $plugin_upgrades ) ) {
            return array(
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_updated' => 0,
                    'total_errors'  => 0,
                ),
            );
        }

        $updates = array();
        foreach ( $plugin_upgrades as $slug => $plugin ) {

            $new_version = '';

            if ( isset( $plugin['update']['new_version'] ) ) {
                $new_version = $plugin['update']['new_version'];
            } elseif ( isset( $plugin['new_version'] ) ) {
                $new_version = $plugin['new_version'];
            }

            $updates[] = array(
                'slug'            => $slug,
                'name'            => isset( $plugin['Name'] ) ? $plugin['Name'] : $slug,
                'current_version' => isset( $plugin['Version'] ) ? $plugin['Version'] : '',
                'new_version'     => $new_version,
            );
        }

        $result = self::execute_plugin_updates( $site, $updates );

        return array(
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
            'summary' => array(
                'total_updated' => count( $result['updated'] ),
                'total_errors'  => count( $result['errors'] ),
            ),
        );
    }

    /**
     * Execute callback for mainwp/update-site-themes-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_update_site_themes( array $input ) { // phpcs:ignore -- NOSONAR - complex method.
        $site_id_or_domain = $input['site_id_or_domain'];
        $slugs             = $input['slugs'] ?? array();

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
            return new \WP_Error(
                'mainwp_site_offline',
                __( 'Site is known to be offline.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $child_version_check = MainWP_Abilities_Util::check_child_version( $site, '4.0' );
        if ( is_wp_error( $child_version_check ) ) {
            return $child_version_check;
        }

        $user_extension        = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_themes = array();
        if ( is_object( $user_extension ) ) {
            $global_ignored_themes = ! empty( $user_extension->ignored_themes ) ? json_decode( $user_extension->ignored_themes, true ) : array();
        }
        if ( ! is_array( $global_ignored_themes ) ) {
            $global_ignored_themes = array();
        }

        $site_ignored_themes = ! empty( $site->ignored_themes ) ? json_decode( $site->ignored_themes, true ) : array();
        if ( ! is_array( $site_ignored_themes ) ) {
            $site_ignored_themes = array();
        }

        $theme_upgrades = ! empty( $site->theme_upgrades ) ? json_decode( $site->theme_upgrades, true ) : array();
        if ( ! is_array( $theme_upgrades ) || empty( $theme_upgrades ) ) {
            return array(
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_updated' => 0,
                    'total_errors'  => 0,
                ),
            );
        }

        if ( ! empty( $site_ignored_themes ) ) {
            $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $site_ignored_themes );
        }
        if ( ! empty( $global_ignored_themes ) ) {
            $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $global_ignored_themes );
        }

        if ( ! empty( $slugs ) ) {
            $theme_upgrades = array_filter(
                $theme_upgrades,
                function ( $slug ) use ( $slugs ) {
                    return in_array( $slug, $slugs, true );
                },
                ARRAY_FILTER_USE_KEY
            );
        }

        if ( empty( $theme_upgrades ) ) {
            return array(
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_updated' => 0,
                    'total_errors'  => 0,
                ),
            );
        }

        $updates = array();
        foreach ( $theme_upgrades as $slug => $theme ) {
            $updates[] = array(
                'slug'            => $slug,
                'name'            => isset( $theme['Name'] ) ? $theme['Name'] : $slug,
                'current_version' => isset( $theme['Version'] ) ? $theme['Version'] : '',
                'new_version'     => $theme['update']['new_version'] ?? $theme['new_version'] ?? '',
            );
        }

        $result = self::execute_theme_updates( $site, $updates );

        return array(
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
            'summary' => array(
                'total_updated' => count( $result['updated'] ),
                'total_errors'  => count( $result['errors'] ),
            ),
        );
    }

    /**
     * Execute callback for mainwp/update-site-translations-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_update_site_translations( array $input ) { // phpcs:ignore -- NOSONAR - complex method.
        $site_id_or_domain = $input['site_id_or_domain'];
        $slugs             = $input['slugs'] ?? array();

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
            return new \WP_Error(
                'mainwp_site_offline',
                __( 'Site is known to be offline.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $child_version_check = MainWP_Abilities_Util::check_child_version( $site, '4.0' );
        if ( is_wp_error( $child_version_check ) ) {
            return $child_version_check;
        }

        $translation_upgrades = ! empty( $site->translation_upgrades ) ? json_decode( $site->translation_upgrades, true ) : array();
        if ( ! is_array( $translation_upgrades ) || empty( $translation_upgrades ) ) {
            return array(
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_updated' => 0,
                    'total_errors'  => 0,
                ),
            );
        }

        if ( ! empty( $slugs ) ) {
            $translation_upgrades = array_filter(
                $translation_upgrades,
                function ( $translation ) use ( $slugs ) {
                    return isset( $translation['slug'] ) && in_array( $translation['slug'], $slugs, true );
                }
            );
        }

        if ( empty( $translation_upgrades ) ) {
            return array(
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_updated' => 0,
                    'total_errors'  => 0,
                ),
            );
        }

        $updates = array();
        foreach ( $translation_upgrades as $translation ) {

            $name = '';

            if ( isset( $translation['name'] ) ) {
                $name = $translation['name'];
            } elseif ( isset( $translation['slug'] ) ) {
                $name = $translation['slug'];
            }

            $updates[] = array(
                'slug'            => isset( $translation['slug'] ) ? $translation['slug'] : '',
                'name'            => $name,
                'current_version' => isset( $translation['version'] ) ? $translation['version'] : '',
                'new_version'     => isset( $translation['new_version'] ) ? $translation['new_version'] : '',
            );
        }

        $result = self::execute_translation_updates( $site, $updates );

        return array(
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
            'summary' => array(
                'total_updated' => count( $result['updated'] ),
                'total_errors'  => count( $result['errors'] ),
            ),
        );
    }

    /**
     * Execute callback for mainwp/ignore-site-core-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_ignore_site_core( array $input ) {
        $site_id_or_domain = $input['site_id_or_domain'];
        $action            = $input['action'] ?? 'add';

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        if ( 'add' === $action ) {
            $result  = self::add_to_ignored_list( $site, 'core', 'wordpress' );
            $message = __( 'Core updates ignored for this site.', 'mainwp' );
        } else {
            $result  = self::remove_from_ignored_list( $site, 'core', 'wordpress' );
            $message = __( 'Core updates unignored for this site.', 'mainwp' );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'success'       => true,
            'message'       => $message,
            'ignored_count' => 1,
        );
    }

    /**
     * Execute callback for mainwp/ignore-site-plugins-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_ignore_site_plugins( array $input ) {
        $site_id_or_domain = $input['site_id_or_domain'];
        $slugs             = $input['slugs'];
        $action            = $input['action'] ?? 'add';

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $count = 0;
        foreach ( $slugs as $slug ) {
            if ( 'add' === $action ) {
                $result = self::add_to_ignored_list( $site, 'plugin', $slug );
            } else {
                $result = self::remove_from_ignored_list( $site, 'plugin', $slug );
            }

            if ( ! is_wp_error( $result ) ) {
                ++$count;
            }
        }

        if ( 'add' === $action && $count > 0 ) {
            $message = sprintf(
                /* translators: %d: Number of plugins */
                _n( '%d plugin ignored.', '%d plugins ignored.', $count, 'mainwp' ),
                $count
            );
        } elseif ( 'add' === $action ) {
            $message = __( 'No plugins were ignored.', 'mainwp' );
        } elseif ( $count > 0 ) {
            $message = sprintf(
                /* translators: %d: Number of plugins */
                _n( '%d plugin unignored.', '%d plugins unignored.', $count, 'mainwp' ),
                $count
            );
        } else {
            $message = __( 'No plugins were unignored.', 'mainwp' );
        }

        return array(
            'success'       => $count > 0,
            'message'       => $message,
            'ignored_count' => $count,
        );
    }

    /**
     * Execute callback for mainwp/ignore-site-themes-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_ignore_site_themes( array $input ) {
        $site_id_or_domain = $input['site_id_or_domain'];
        $slugs             = $input['slugs'];
        $action            = $input['action'] ?? 'add';

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $count = 0;
        foreach ( $slugs as $slug ) {
            if ( 'add' === $action ) {
                $result = self::add_to_ignored_list( $site, 'theme', $slug );
            } else {
                $result = self::remove_from_ignored_list( $site, 'theme', $slug );
            }

            if ( ! is_wp_error( $result ) ) {
                ++$count;
            }
        }

        if ( 'add' === $action && $count > 0 ) {
            $message = sprintf(
                /* translators: %d: Number of themes */
                _n( '%d theme ignored.', '%d themes ignored.', $count, 'mainwp' ),
                $count
            );
        } elseif ( 'add' === $action ) {
            $message = __( 'No themes were ignored.', 'mainwp' );
        } elseif ( $count > 0 ) {
            $message = sprintf(
                /* translators: %d: Number of themes */
                _n( '%d theme unignored.', '%d themes unignored.', $count, 'mainwp' ),
                $count
            );
        } else {
            $message = __( 'No themes were unignored.', 'mainwp' );
        }

        return array(
            'success'       => $count > 0,
            'message'       => $message,
            'ignored_count' => $count,
        );
    }

    /**
     * Execute callback for mainwp/update-all-v1.
     *
     * @param array|null $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_update_all( $input ) { // phpcs:ignore -- NOSONAR - complex method.
        $input               = is_array( $input ) ? $input : array();
        $site_ids_or_domains = $input['site_ids_or_domains'] ?? array();
        $types               = $input['types'] ?? array( 'core', 'plugins', 'themes', 'translations' );

        // If empty, get all sites for current user.
        if ( empty( $site_ids_or_domains ) ) {
            $all_sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );

            if ( is_wp_error( $all_sites ) ) {
                return $all_sites;
            }

            $site_ids_or_domains = array_map(
                function ( $s ) {
                    return (int) $s->id;
                },
                $all_sites ? $all_sites : array()
            );
        }

        // Check per-site ACLs and filter to allowed sites.
        $access_check = MainWP_Abilities_Util::check_batch_site_access( $site_ids_or_domains, $input );

        // Normalize ACL-denied errors to match the documented error item schema.
        $acl_errors = array();
        foreach ( $access_check['denied'] as $denied_entry ) {
            $acl_errors[] = array(
                'identifier' => $denied_entry['identifier'],
                'code'       => $denied_entry['code'],
                'message'    => $denied_entry['message'],
            );
        }

        $sites = $access_check['allowed'];

        if ( empty( $sites ) ) {
            $normalized_errors = self::normalize_resolution_errors( $acl_errors );
            return array(
                'updated' => array(),
                'errors'  => $normalized_errors,
                'summary' => array(
                    'total_updated' => 0,
                    'total_errors'  => count( $normalized_errors ),
                    'sites_updated' => 0,
                ),
            );
        }

        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', self::BATCH_THRESHOLD );

        if ( count( $sites ) > $threshold ) {
            $job_id = MainWP_Abilities_Util::queue_batch_updates(
                $sites,
                array(
                    'types' => $types,
                )
            );

            if ( is_wp_error( $job_id ) ) {
                return $job_id;
            }

            return array(
                'queued'         => true,
                'job_id'         => $job_id,
                'status_url'     => rest_url( "mainwp/v2/jobs/{$job_id}" ),
                'updates_queued' => count( $sites ),
                'errors'         => self::normalize_resolution_errors( $acl_errors ),
            );
        }

        $user_extension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_plugins = array();
        $global_ignored_themes  = array();
        $global_ignored_core    = array();

        if ( is_object( $user_extension ) ) {
            $global_ignored_plugins = ! empty( $user_extension->ignored_plugins ) ? json_decode( $user_extension->ignored_plugins, true ) : array();
            $global_ignored_themes  = ! empty( $user_extension->ignored_themes ) ? json_decode( $user_extension->ignored_themes, true ) : array();
            $global_ignored_core    = ! empty( $user_extension->ignored_wp_upgrades ) ? json_decode( $user_extension->ignored_wp_upgrades, true ) : array();
        }

        if ( ! is_array( $global_ignored_plugins ) ) {
            $global_ignored_plugins = array();
        }
        if ( ! is_array( $global_ignored_themes ) ) {
            $global_ignored_themes = array();
        }
        if ( ! is_array( $global_ignored_core ) ) {
            $global_ignored_core = array();
        }

        $all_updated       = array();
        $all_errors        = self::normalize_resolution_errors( $acl_errors );
        $sites_updated_set = array();

        foreach ( $sites as $site ) {
            // Check if site is known offline before attempting updates.
            if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
                $all_errors[] = array(
                    'site_id'   => (int) $site->id,
                    'site_url'  => $site->url,
                    'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                    'type'      => 'site',
                    'slug'      => '',
                    'code'      => 'mainwp_site_offline',
                    'message'   => __( 'Site is known to be offline.', 'mainwp' ),
                );
                continue;
            }

            $site_updates = self::get_site_updates( $site, $types, $global_ignored_plugins, $global_ignored_themes, $global_ignored_core );

            if ( empty( $site_updates ) ) {
                continue;
            }

            $child_version_check = MainWP_Abilities_Util::check_child_version( $site, '4.0' );
            if ( is_wp_error( $child_version_check ) ) {
                foreach ( $site_updates as $update ) {
                    $all_errors[] = array(
                        'site_id'   => (int) $site->id,
                        'site_url'  => $site->url,
                        'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                        'type'      => $update['type'],
                        'slug'      => $update['slug'],
                        'code'      => $child_version_check->get_error_code(),
                        'message'   => $child_version_check->get_error_message(),
                    );
                }
                continue;
            }

            $core_updates        = array();
            $plugin_updates      = array();
            $theme_updates       = array();
            $translation_updates = array();

            foreach ( $site_updates as $update ) {
                if ( 'core' === $update['type'] ) {
                    $core_updates[] = $update;
                } elseif ( 'plugin' === $update['type'] ) {
                    $plugin_updates[] = $update;
                } elseif ( 'theme' === $update['type'] ) {
                    $theme_updates[] = $update;
                } elseif ( 'translation' === $update['type'] ) {
                    $translation_updates[] = $update;
                }
            }

            if ( ! empty( $core_updates ) && in_array( 'core', $types, true ) ) {
                $core_update = $core_updates[0];
                $result      = self::execute_core_update( $site, $core_update );
                if ( is_wp_error( $result ) ) {
                    $all_errors[] = array(
                        'site_id'   => (int) $site->id,
                        'site_url'  => $site->url,
                        'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                        'type'      => 'core',
                        'slug'      => 'wordpress',
                        'code'      => $result->get_error_code(),
                        'message'   => $result->get_error_message(),
                    );
                } else {
                    $all_updated[]                        = $result;
                    $sites_updated_set[ (int) $site->id ] = true;
                }
            }

            if ( ! empty( $plugin_updates ) && in_array( 'plugins', $types, true ) ) {
                $result = self::execute_plugin_updates( $site, $plugin_updates );
                if ( ! empty( $result['updated'] ) ) {
                    $all_updated                          = array_merge( $all_updated, $result['updated'] );
                    $sites_updated_set[ (int) $site->id ] = true;
                }
                if ( ! empty( $result['errors'] ) ) {
                    $all_errors = array_merge( $all_errors, $result['errors'] );
                }
            }

            if ( ! empty( $theme_updates ) && in_array( 'themes', $types, true ) ) {
                $result = self::execute_theme_updates( $site, $theme_updates );
                if ( ! empty( $result['updated'] ) ) {
                    $all_updated                          = array_merge( $all_updated, $result['updated'] );
                    $sites_updated_set[ (int) $site->id ] = true;
                }
                if ( ! empty( $result['errors'] ) ) {
                    $all_errors = array_merge( $all_errors, $result['errors'] );
                }
            }

            if ( ! empty( $translation_updates ) && in_array( 'translations', $types, true ) ) {
                $result = self::execute_translation_updates( $site, $translation_updates );
                if ( ! empty( $result['updated'] ) ) {
                    $all_updated                          = array_merge( $all_updated, $result['updated'] );
                    $sites_updated_set[ (int) $site->id ] = true;
                }
                if ( ! empty( $result['errors'] ) ) {
                    $all_errors = array_merge( $all_errors, $result['errors'] );
                }
            }
        }

        return array(
            'updated' => $all_updated,
            'errors'  => $all_errors,
            'summary' => array(
                'total_updated' => count( $all_updated ),
                'total_errors'  => count( $all_errors ),
                'sites_updated' => count( $sites_updated_set ),
            ),
        );
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Normalize resolution errors to match the documented error schema.
     *
     * Converts errors from resolve_sites() format (identifier, code, message, status)
     * to the update-all-v1 output schema format (site_id, site_url, site_name, type, slug, code, message).
     *
     * @param array $resolution_errors Raw resolution errors from resolve_sites().
     * @return array Normalized errors matching the output schema.
     */
    private static function normalize_resolution_errors( array $resolution_errors ): array {
        $normalized = array();

        foreach ( $resolution_errors as $err ) {
            // Convert identifier to site_url if it's a scalar (string or int).
            $site_url = '';
            if ( isset( $err['identifier'] ) && is_scalar( $err['identifier'] ) ) {
                $site_url = (string) $err['identifier'];
            }

            $normalized[] = array(
                'site_id'   => 0,
                'site_url'  => $site_url,
                'site_name' => '',
                'type'      => 'site',
                'slug'      => '',
                'code'      => isset( $err['code'] ) ? $err['code'] : 'mainwp_unknown_error',
                'message'   => isset( $err['message'] ) ? $err['message'] : __( 'Unknown error during site resolution.', 'mainwp' ),
            );
        }

        return $normalized;
    }

    /**
     * Allowed columns for get_site_column_value() to prevent SQL injection.
     *
     * @var array
     */
    private static $allowed_site_columns = array(
        'plugin_upgrades',
        'theme_upgrades',
        'translation_upgrades',
        'ignored_plugins',
        'ignored_themes',
    );

    /**
     * Get a site column value with fallback to direct database query.
     *
     * This method first tries to read from the site object property. If not found
     * (empty), it falls back to a direct database query. This ensures update data
     * is retrieved regardless of how the site object was constructed.
     *
     * @param object $site   Site object.
     * @param string $column Column name from wp table (must be in allowed list).
     * @return string Column value or empty string.
     */
    private static function get_site_column_value( $site, string $column ): string {
        // Validate column is in allowed list (security).
        if ( ! in_array( $column, self::$allowed_site_columns, true ) ) {
            return '';
        }

        // Validate site has an ID.
        if ( empty( $site->id ) ) {
            return '';
        }

        // Try object property first (fast path).
        if ( ! empty( $site->{$column} ) ) {
            return $site->{$column};
        }

        // Fallback: direct database query.
        global $wpdb;
        $table = MainWP_DB::instance()->get_table_name( 'wp' );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fallback query; $column and $table are validated.
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT {$column} FROM {$table} WHERE id = %d",
                (int) $site->id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $value ?? '';
    }

    /**
     * Get updates for a single site.
     *
     * @param object $site                   Site object.
     * @param array  $types                  Update types to include.
     * @param array  $global_ignored_plugins Global ignored plugins list.
     * @param array  $global_ignored_themes  Global ignored themes list.
     * @param array  $global_ignored_core    Global ignored core list.
     * @return array Array of update items.
     */
    private static function get_site_updates( $site, array $types, array $global_ignored_plugins, array $global_ignored_themes, array $global_ignored_core ): array { // phpcs:ignore -- NOSONAR - complex method.
        $updates = array();

        $site_id   = (int) $site->id;
        $site_url  = $site->url;
        $site_name = MainWP_Utility::remove_http_prefix( (string) $site->name, true );

        // Get per-site ignored lists (with fallback to database query).
        $raw_ignored_plugins  = self::get_site_column_value( $site, 'ignored_plugins' );
        $site_ignored_plugins = ! empty( $raw_ignored_plugins ) ? json_decode( $raw_ignored_plugins, true ) : array();
        $raw_ignored_themes   = self::get_site_column_value( $site, 'ignored_themes' );
        $site_ignored_themes  = ! empty( $raw_ignored_themes ) ? json_decode( $raw_ignored_themes, true ) : array();

        if ( ! is_array( $site_ignored_plugins ) ) {
            $site_ignored_plugins = array();
        }
        if ( ! is_array( $site_ignored_themes ) ) {
            $site_ignored_themes = array();
        }

        // Core updates.
        // Guard against missing ignore flag property (treat missing/falsy as "not ignored").
        if ( in_array( 'core', $types, true ) && empty( $site->is_ignoreCoreUpdates ) ) {
            $wp_upgrades = MainWP_DB::instance()->get_website_option( $site, 'wp_upgrades' );
            $wp_upgrades = ! empty( $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();

            if ( is_array( $wp_upgrades ) && ! empty( $wp_upgrades ) ) {
                // Check if ignored.
                $site_ignored_core = MainWP_DB::instance()->get_website_option( $site, 'ignored_wp_upgrades' );
                $site_ignored_core = ! empty( $site_ignored_core ) ? json_decode( $site_ignored_core, true ) : array();

                $is_ignored = false;
                if ( ! empty( $site_ignored_core ) && MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $site_ignored_core, 'core' ) ) {
                    $is_ignored = true;
                }
                if ( ! $is_ignored && ! empty( $global_ignored_core ) && MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $global_ignored_core, 'core' ) ) {
                    $is_ignored = true;
                }

                if ( ! $is_ignored ) {
                    $updates[] = array(
                        'site_id'         => $site_id,
                        'site_url'        => $site_url,
                        'site_name'       => $site_name,
                        'type'            => 'core',
                        'slug'            => 'wordpress',
                        'name'            => 'WordPress',
                        'current_version' => isset( $wp_upgrades['current'] ) ? $wp_upgrades['current'] : '',
                        'new_version'     => isset( $wp_upgrades['new'] ) ? $wp_upgrades['new'] : '',
                    );
                }
            }
        }

        // Plugin updates.
        // Guard against missing ignore flag property (treat missing/falsy as "not ignored").
        if ( in_array( 'plugins', $types, true ) && empty( $site->is_ignorePluginUpdates ) ) {
            $raw_plugin_upgrades = self::get_site_column_value( $site, 'plugin_upgrades' );
            $plugin_upgrades     = ! empty( $raw_plugin_upgrades ) ? json_decode( $raw_plugin_upgrades, true ) : array();

            if ( is_array( $plugin_upgrades ) && ! empty( $plugin_upgrades ) ) {
                // Filter by per-site ignored.
                if ( ! empty( $site_ignored_plugins ) ) {
                    $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $site_ignored_plugins );
                }
                // Filter by global ignored.
                if ( ! empty( $global_ignored_plugins ) ) {
                    $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $global_ignored_plugins );
                }

                foreach ( $plugin_upgrades as $slug => $plugin ) {

                    $new_version = '';

                    if ( isset( $plugin['update']['new_version'] ) ) {
                        $new_version = $plugin['update']['new_version'];
                    } elseif ( isset( $plugin['new_version'] ) ) {
                        $new_version = $plugin['new_version'];
                    }

                    $updates[] = array(
                        'site_id'         => $site_id,
                        'site_url'        => $site_url,
                        'site_name'       => $site_name,
                        'type'            => 'plugin',
                        'slug'            => $slug,
                        'name'            => isset( $plugin['Name'] ) ? $plugin['Name'] : $slug,
                        'current_version' => isset( $plugin['Version'] ) ? $plugin['Version'] : '',
                        'new_version'     => $new_version,
                    );
                }
            }
        }

        // Theme updates.
        // Guard against missing ignore flag property (treat missing/falsy as "not ignored").
        if ( in_array( 'themes', $types, true ) && empty( $site->is_ignoreThemeUpdates ) ) {
            $raw_theme_upgrades = self::get_site_column_value( $site, 'theme_upgrades' );
            $theme_upgrades     = ! empty( $raw_theme_upgrades ) ? json_decode( $raw_theme_upgrades, true ) : array();

            if ( is_array( $theme_upgrades ) && ! empty( $theme_upgrades ) ) {
                // Filter by per-site ignored.
                if ( ! empty( $site_ignored_themes ) ) {
                    $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $site_ignored_themes );
                }
                // Filter by global ignored.
                if ( ! empty( $global_ignored_themes ) ) {
                    $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $global_ignored_themes );
                }

                foreach ( $theme_upgrades as $slug => $theme ) {

                    $new_version = '';

                    if ( isset( $theme['update']['new_version'] ) ) {
                        $new_version = $theme['update']['new_version'];
                    } elseif ( isset( $theme['new_version'] ) ) {
                        $new_version = $theme['new_version'];
                    }

                    $updates[] = array(
                        'site_id'         => $site_id,
                        'site_url'        => $site_url,
                        'site_name'       => $site_name,
                        'type'            => 'theme',
                        'slug'            => $slug,
                        'name'            => isset( $theme['Name'] ) ? $theme['Name'] : $slug,
                        'current_version' => isset( $theme['Version'] ) ? $theme['Version'] : '',
                        'new_version'     => $new_version,
                    );
                }
            }
        }

        // Translation updates.
        if ( in_array( 'translations', $types, true ) ) {
            $raw_translation_upgrades = self::get_site_column_value( $site, 'translation_upgrades' );
            $translation_upgrades     = ! empty( $raw_translation_upgrades ) ? json_decode( $raw_translation_upgrades, true ) : array();

            if ( is_array( $translation_upgrades ) && ! empty( $translation_upgrades ) ) {
                foreach ( $translation_upgrades as $translation ) {

                    $name = '';

                    if ( isset( $translation['name'] ) ) {
                        $name = $translation['name'];
                    } elseif ( isset( $translation['slug'] ) ) {
                        $name = $translation['slug'];
                    }

                    $updates[] = array(
                        'site_id'         => $site_id,
                        'site_url'        => $site_url,
                        'site_name'       => $site_name,
                        'type'            => 'translation',
                        'slug'            => isset( $translation['slug'] ) ? $translation['slug'] : '',
                        'name'            => $name,
                        'current_version' => isset( $translation['version'] ) ? $translation['version'] : '',
                        'new_version'     => isset( $translation['new_version'] ) ? $translation['new_version'] : '',
                    );
                }
            }
        }

        return $updates;
    }

    /**
     * Execute core update for a site.
     *
     * @param object $site        Site object.
     * @param array  $update_info Update information.
     * @return array|\WP_Error Update result or error.
     */
    private static function execute_core_update( $site, array $update_info ) {
        try {
            /**
             * Action: mainwp_before_wp_update
             *
             * Fires before WP update.
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_wp_update', $site );

            $information = MainWP_Connect::fetch_url_authed( $site, 'upgrade' );

            /**
             * Action: mainwp_after_wp_update
             *
             * Fires after WP update.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_wp_update', $information, $site );

            if ( is_array( $information ) && isset( $information['upgrade'] ) && 'SUCCESS' === $information['upgrade'] ) {
                // Clear wp_upgrades option.
                MainWP_DB::instance()->update_website_option( $site, 'wp_upgrades', wp_json_encode( array() ) );

                do_action( 'mainwp_after_upgrade_wp_success', $site, $information );

                return array(
                    'site_id'     => (int) $site->id,
                    'site_url'    => $site->url,
                    'site_name'   => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                    'type'        => 'core',
                    'slug'        => 'wordpress',
                    'name'        => 'WordPress',
                    'old_version' => $update_info['current_version'],
                    'new_version' => isset( $information['version'] ) ? $information['version'] : $update_info['new_version'],
                );
            }

            // Handle WP_Error from fetch_url_authed.
            if ( is_wp_error( $information ) ) {
                return new \WP_Error( 'mainwp_update_failed', $information->get_error_message() );
            }

            $error_message = __( 'Core update failed.', 'mainwp' );
            if ( is_array( $information ) ) {
                if ( isset( $information['error'] ) ) {
                    $error_message = $information['error'];
                } elseif ( isset( $information['upgrade'] ) ) {
                    if ( 'LOCALIZATION' === $information['upgrade'] ) {
                        $error_message = __( 'No update found for the set locale.', 'mainwp' );
                    } elseif ( 'NORESPONSE' === $information['upgrade'] ) {
                        $error_message = __( 'No response from the child site server.', 'mainwp' );
                    }
                }
            }

            return new \WP_Error( 'mainwp_update_failed', $error_message );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'mainwp_update_exception', $e->getMessage() );
        }
    }

    /**
     * Execute plugin updates for a site.
     *
     * @param object $site    Site object.
     * @param array  $updates Array of plugin updates.
     * @return array Array with 'updated' and 'errors' keys.
     */
    private static function execute_plugin_updates( $site, array $updates ): array { // phpcs:ignore -- NOSONAR - complex method.
        $updated = array();
        $errors  = array();

        $slugs = array_map(
            function ( $u ) {
                return $u['slug'];
            },
            $updates
        );

        // Build slug-to-update map.
        $update_map = array();
        foreach ( $updates as $update ) {
            $update_map[ $update['slug'] ] = $update;
        }

        try {
            /**
             * Action: mainwp_before_plugin_theme_translation_update
             *
             * Fires before plugin/theme/translation update actions.
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_plugin_theme_translation_update', 'plugin', implode( ',', $slugs ), $site );

            $information = MainWP_Connect::fetch_url_authed(
                $site,
                'upgradeplugintheme',
                array(
                    'type' => 'plugin',
                    'list' => implode( ',', $slugs ),
                ),
                true
            );

            /**
             * Action: mainwp_after_plugin_theme_translation_update
             *
             * Fires after plugin/theme/translation update actions.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'plugin', implode( ',', $slugs ), $site );

            if ( is_array( $information ) && isset( $information['upgrades'] ) ) {
                foreach ( $information['upgrades'] as $slug => $result ) {
                    if ( ! empty( $result ) && isset( $update_map[ $slug ] ) ) {
                        $updated[] = array(
                            'site_id'     => (int) $site->id,
                            'site_url'    => $site->url,
                            'site_name'   => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                            'type'        => 'plugin',
                            'slug'        => $slug,
                            'name'        => $update_map[ $slug ]['name'],
                            'old_version' => $update_map[ $slug ]['current_version'],
                            'new_version' => $update_map[ $slug ]['new_version'],
                        );
                    }
                }
            }

            if ( is_array( $information ) && isset( $information['upgrades_error'] ) ) {
                foreach ( $information['upgrades_error'] as $slug => $error_msg ) {
                    $errors[] = array(
                        'site_id'   => (int) $site->id,
                        'site_url'  => $site->url,
                        'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                        'type'      => 'plugin',
                        'slug'      => $slug,
                        'code'      => 'mainwp_update_failed',
                        'message'   => $error_msg,
                    );
                }
            }

            // Update stored plugin_upgrades to remove updated items.
            if ( ! empty( $updated ) ) {
                $current_upgrades = ! empty( $site->plugin_upgrades ) ? json_decode( $site->plugin_upgrades, true ) : array();
                if ( is_array( $current_upgrades ) ) {
                    foreach ( $updated as $u ) {
                        unset( $current_upgrades[ $u['slug'] ] );
                    }
                    MainWP_DB::instance()->update_website_values( $site->id, array( 'plugin_upgrades' => wp_json_encode( $current_upgrades ) ) );
                }
            }
        } catch ( \Exception $e ) {
            foreach ( $slugs as $slug ) {
                $errors[] = array(
                    'site_id'   => (int) $site->id,
                    'site_url'  => $site->url,
                    'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                    'type'      => 'plugin',
                    'slug'      => $slug,
                    'code'      => 'mainwp_update_exception',
                    'message'   => $e->getMessage(),
                );
            }
        }

        return array(
            'updated' => $updated,
            'errors'  => $errors,
        );
    }

    /**
     * Execute theme updates for a site.
     *
     * @param object $site    Site object.
     * @param array  $updates Array of theme updates.
     * @return array Array with 'updated' and 'errors' keys.
     */
    private static function execute_theme_updates( $site, array $updates ): array { // phpcs:ignore -- NOSONAR - complex method.
        $updated = array();
        $errors  = array();

        $slugs = array_map(
            function ( $u ) {
                return $u['slug'];
            },
            $updates
        );

        // Build slug-to-update map.
        $update_map = array();
        foreach ( $updates as $update ) {
            $update_map[ $update['slug'] ] = $update;
        }

        try {
            do_action( 'mainwp_before_plugin_theme_translation_update', 'theme', implode( ',', $slugs ), $site );

            $information = MainWP_Connect::fetch_url_authed(
                $site,
                'upgradeplugintheme',
                array(
                    'type' => 'theme',
                    'list' => implode( ',', $slugs ),
                ),
                true
            );

            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'theme', implode( ',', $slugs ), $site );

            if ( is_array( $information ) && isset( $information['upgrades'] ) ) {
                foreach ( $information['upgrades'] as $slug => $result ) {
                    if ( ! empty( $result ) && isset( $update_map[ $slug ] ) ) {
                        $updated[] = array(
                            'site_id'     => (int) $site->id,
                            'site_url'    => $site->url,
                            'site_name'   => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                            'type'        => 'theme',
                            'slug'        => $slug,
                            'name'        => $update_map[ $slug ]['name'],
                            'old_version' => $update_map[ $slug ]['current_version'],
                            'new_version' => $update_map[ $slug ]['new_version'],
                        );
                    }
                }
            }

            if ( is_array( $information ) && isset( $information['upgrades_error'] ) ) {
                foreach ( $information['upgrades_error'] as $slug => $error_msg ) {
                    $errors[] = array(
                        'site_id'   => (int) $site->id,
                        'site_url'  => $site->url,
                        'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                        'type'      => 'theme',
                        'slug'      => $slug,
                        'code'      => 'mainwp_update_failed',
                        'message'   => $error_msg,
                    );
                }
            }

            // Update stored theme_upgrades to remove updated items.
            if ( ! empty( $updated ) ) {
                $current_upgrades = ! empty( $site->theme_upgrades ) ? json_decode( $site->theme_upgrades, true ) : array();
                if ( is_array( $current_upgrades ) ) {
                    foreach ( $updated as $u ) {
                        unset( $current_upgrades[ $u['slug'] ] );
                    }
                    MainWP_DB::instance()->update_website_values( $site->id, array( 'theme_upgrades' => wp_json_encode( $current_upgrades ) ) );
                }
            }
        } catch ( \Exception $e ) {
            foreach ( $slugs as $slug ) {
                $errors[] = array(
                    'site_id'   => (int) $site->id,
                    'site_url'  => $site->url,
                    'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                    'type'      => 'theme',
                    'slug'      => $slug,
                    'code'      => 'mainwp_update_exception',
                    'message'   => $e->getMessage(),
                );
            }
        }

        return array(
            'updated' => $updated,
            'errors'  => $errors,
        );
    }

    /**
     * Execute translation updates for a site.
     *
     * @param object $site    Site object.
     * @param array  $updates Array of translation updates.
     * @return array Array with 'updated' and 'errors' keys.
     */
    private static function execute_translation_updates( $site, array $updates ): array { // phpcs:ignore -- NOSONAR - complex method.
        $updated = array();
        $errors  = array();

        $slugs = array_map(
            function ( $u ) {
                return $u['slug'];
            },
            $updates
        );

        // Build slug-to-update map.
        $update_map = array();
        foreach ( $updates as $update ) {
            $update_map[ $update['slug'] ] = $update;
        }

        try {
            do_action( 'mainwp_before_plugin_theme_translation_update', 'translation', implode( ',', $slugs ), $site );

            $information = MainWP_Connect::fetch_url_authed(
                $site,
                'upgradetranslation',
                array(
                    'type' => 'translation',
                    'list' => implode( ',', $slugs ),
                ),
                true
            );

            do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'translation', implode( ',', $slugs ), $site );

            if ( is_array( $information ) && isset( $information['upgrades'] ) ) {
                foreach ( $information['upgrades'] as $slug => $result ) {
                    if ( ! empty( $result ) && isset( $update_map[ $slug ] ) ) {
                        $updated[] = array(
                            'site_id'     => (int) $site->id,
                            'site_url'    => $site->url,
                            'site_name'   => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                            'type'        => 'translation',
                            'slug'        => $slug,
                            'name'        => $update_map[ $slug ]['name'],
                            'old_version' => $update_map[ $slug ]['current_version'],
                            'new_version' => $update_map[ $slug ]['new_version'],
                        );
                    }
                }
            }

            if ( is_array( $information ) && isset( $information['upgrades_error'] ) ) {
                foreach ( $information['upgrades_error'] as $slug => $error_msg ) {
                    $errors[] = array(
                        'site_id'   => (int) $site->id,
                        'site_url'  => $site->url,
                        'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                        'type'      => 'translation',
                        'slug'      => $slug,
                        'code'      => 'mainwp_update_failed',
                        'message'   => $error_msg,
                    );
                }
            }

            // Update stored translation_upgrades to remove updated items.
            if ( ! empty( $updated ) ) {
                $current_upgrades = ! empty( $site->translation_upgrades ) ? json_decode( $site->translation_upgrades, true ) : array();
                if ( is_array( $current_upgrades ) ) {
                    // Translation upgrades are stored as indexed array with 'slug' key, not keyed by slug.
                    $updated_slugs = array();
                    foreach ( $updated as $u ) {
                        $updated_slugs[] = $u['slug'];
                    }

                    $current_upgrades = array_filter(
                        $current_upgrades,
                        function ( $translation ) use ( $updated_slugs ) {
                            return ! isset( $translation['slug'] ) || ! in_array( $translation['slug'], $updated_slugs, true );
                        }
                    );

                    // Re-index the array.
                    $current_upgrades = array_values( $current_upgrades );

                    MainWP_DB::instance()->update_website_values( $site->id, array( 'translation_upgrades' => wp_json_encode( $current_upgrades ) ) );
                }
            }
        } catch ( \Exception $e ) {
            foreach ( $slugs as $slug ) {
                $errors[] = array(
                    'site_id'   => (int) $site->id,
                    'site_url'  => $site->url,
                    'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                    'type'      => 'translation',
                    'slug'      => $slug,
                    'code'      => 'mainwp_update_exception',
                    'message'   => $e->getMessage(),
                );
            }
        }

        return array(
            'updated' => $updated,
            'errors'  => $errors,
        );
    }

    /**
     * Add an item to the ignored list.
     *
     * @param object $site Site object.
     * @param string $type Update type (core, plugin, theme).
     * @param string $slug Item slug.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    private static function add_to_ignored_list( $site, string $type, string $slug ) {
        $site_id = (int) $site->id;

        if ( 'core' === $type ) {
            $ignored_info = MainWP_DB::instance()->get_website_option( $site, 'ignored_wp_upgrades' );
            $ignored_info = ! empty( $ignored_info ) ? json_decode( $ignored_info, true ) : array();

            if ( ! is_array( $ignored_info ) ) {
                $ignored_info = array();
            }

            $ignored_info['ignored_versions'] = array( 'all_versions' );

            MainWP_DB::instance()->update_website_option( $site, 'ignored_wp_upgrades', wp_json_encode( $ignored_info ) );

            return true;
        }

        if ( 'plugin' === $type ) {
            $ignored_plugins = ! empty( $site->ignored_plugins ) ? json_decode( $site->ignored_plugins, true ) : array();
            if ( ! is_array( $ignored_plugins ) ) {
                $ignored_plugins = array();
            }

            /**
             * Action: mainwp_before_plugin_ignore
             *
             * Fires before plugin ignore.
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_plugin_ignore', $ignored_plugins, $site );

            $ignored_plugins[ $slug ] = array(
                'Name'             => $slug,
                'ignored_versions' => array( 'all_versions' ),
            );

            $encoded = wp_json_encode( $ignored_plugins );
            MainWP_DB::instance()->update_website_values( $site_id, array( 'ignored_plugins' => $encoded ) );
            // Keep in-memory site object in sync for multiple operations in one request.
            $site->ignored_plugins = $encoded;

            /**
             * Action: mainwp_after_plugin_ignore
             *
             * Fires after plugin ignore.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_plugin_ignore', $ignored_plugins, $site );

            return true;
        }

        if ( 'theme' === $type ) {
            $ignored_themes = ! empty( $site->ignored_themes ) ? json_decode( $site->ignored_themes, true ) : array();
            if ( ! is_array( $ignored_themes ) ) {
                $ignored_themes = array();
            }

            /**
             * Action: mainwp_before_theme_ignore
             *
             * Fires before theme ignore.
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_theme_ignore', $ignored_themes, $site );

            $ignored_themes[ $slug ] = array(
                'Name'             => $slug,
                'ignored_versions' => array( 'all_versions' ),
            );

            $encoded = wp_json_encode( $ignored_themes );
            MainWP_DB::instance()->update_website_values( $site_id, array( 'ignored_themes' => $encoded ) );
            // Keep in-memory site object in sync for multiple operations in one request.
            $site->ignored_themes = $encoded;

            /**
             * Action: mainwp_after_theme_ignore
             *
             * Fires after theme ignore.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_theme_ignore', $site, $ignored_themes );

            return true;
        }

        return new \WP_Error( 'mainwp_invalid_type', __( 'Invalid update type.', 'mainwp' ) );
    }

    /**
     * Remove an item from the ignored list.
     *
     * @param object $site Site object.
     * @param string $type Update type (core, plugin, theme).
     * @param string $slug Item slug.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    private static function remove_from_ignored_list( $site, string $type, string $slug ) {
        $site_id = (int) $site->id;

        if ( 'core' === $type ) {
            /**
             * Action: mainwp_before_core_unignore
             *
             * Fires before core unignore.
             *
             * @since 5.2
             */
            do_action( 'mainwp_before_core_unignore', array(), $site );

            MainWP_DB::instance()->update_website_option( $site, 'ignored_wp_upgrades', wp_json_encode( array() ) );

            /**
             * Action: mainwp_after_core_unignore
             *
             * Fires after core unignore.
             *
             * @since 5.2
             */
            do_action( 'mainwp_after_core_unignore', array(), $site );

            return true;
        }

        if ( 'plugin' === $type ) {
            $ignored_plugins = ! empty( $site->ignored_plugins ) ? json_decode( $site->ignored_plugins, true ) : array();
            if ( ! is_array( $ignored_plugins ) ) {
                $ignored_plugins = array();
            }

            /**
             * Action: mainwp_before_plugin_unignore
             *
             * Fires before plugin unignore.
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_plugin_unignore', $ignored_plugins, $site );

            unset( $ignored_plugins[ $slug ] );

            $encoded = wp_json_encode( $ignored_plugins );
            MainWP_DB::instance()->update_website_values( $site_id, array( 'ignored_plugins' => $encoded ) );
            // Keep in-memory site object in sync for multiple operations in one request.
            $site->ignored_plugins = $encoded;

            /**
             * Action: mainwp_after_plugin_unignore
             *
             * Fires after plugin unignore.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_plugin_unignore', $ignored_plugins, $site );

            return true;
        }

        if ( 'theme' === $type ) {
            $ignored_themes = ! empty( $site->ignored_themes ) ? json_decode( $site->ignored_themes, true ) : array();
            if ( ! is_array( $ignored_themes ) ) {
                $ignored_themes = array();
            }

            /**
             * Action: mainwp_before_theme_unignore
             *
             * Fires before theme unignore.
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_theme_unignore', $ignored_themes, $site );

            unset( $ignored_themes[ $slug ] );

            $encoded = wp_json_encode( $ignored_themes );
            MainWP_DB::instance()->update_website_values( $site_id, array( 'ignored_themes' => $encoded ) );
            // Keep in-memory site object in sync for multiple operations in one request.
            $site->ignored_themes = $encoded;

            /**
             * Action: mainwp_after_theme_unignore
             *
             * Fires after theme unignore.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_theme_unignore', $ignored_themes, $site );

            return true;
        }

        return new \WP_Error( 'mainwp_invalid_type', __( 'Invalid update type.', 'mainwp' ) );
    }
}
