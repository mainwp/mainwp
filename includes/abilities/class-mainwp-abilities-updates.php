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
 * This class provides 14 abilities:
 * - mainwp/list-updates-v1: List available updates across sites
 * - mainwp/run-updates-v1: Execute updates (core, plugins, themes, translations)
 * - mainwp/list-ignored-updates-v1: List ignored updates
 * - mainwp/set-ignored-updates-v1: Add ignored updates
 * - mainwp/unignore-site-updates-v1: Preview or confirm removal of site update holds
 * - mainwp/get-site-updates-v1: Get available updates for a single site
 * - mainwp/update-site-core-v1: Update WordPress core for a single site
 * - mainwp/update-site-plugins-v1: Update plugins for a single site
 * - mainwp/update-site-themes-v1: Update themes for a single site
 * - mainwp/update-site-translations-v1: Update translations for a single site
 * - mainwp/ignore-site-core-v1: Ignore core updates for a single site
 * - mainwp/ignore-site-plugins-v1: Ignore plugin updates for a single site
 * - mainwp/ignore-site-themes-v1: Ignore theme updates for a single site
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
        self::register_unignore_site_updates();
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
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/run-updates-v1', array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Changes child sites. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. Operations with >200 sites are automatically queued and return a job_id for status polling. Individual update failures do not fail the entire operation.',
                        'readonly'     => false,
                        'destructive'  => true,
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
                'description'         => __( 'Add items to the ignored updates list for a site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_set_ignored_updates_input_schema(),
                'output_schema'       => self::get_set_ignored_updates_output_schema(),
                'execute_callback'    => array( self::class, 'execute_set_ignored_updates' ),
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/set-ignored-updates-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Use action=ignore to add a hold without confirmation. To remove holds, use mainwp/unignore-site-updates-v1.',
                        'readonly'     => false,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Register mainwp/unignore-site-updates-v1 ability.
     *
     * @return void
     */
    private static function register_unignore_site_updates(): void {
        wp_register_ability(
            'mainwp/unignore-site-updates-v1',
            array(
                'label'               => __( 'Remove Site Update Holds', 'mainwp' ),
                'description'         => __( 'Preview or confirm removal of items from a site\'s own ignored updates list.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_unignore_site_updates_input_schema(),
                'output_schema'       => self::get_unignore_site_updates_output_schema(),
                'execute_callback'    => array( self::class, 'execute_unignore_site_updates' ),
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/unignore-site-updates-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Removes update holds on the Dashboard. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. Removes only the site\'s own holds; global holds and whole-site ignore flags remain in effect.',
                        'readonly'     => false,
                        'destructive'  => true,
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
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/update-site-core-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Changes child sites. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. Updates WordPress core on a single site. For batch operations across multiple sites, use run_updates_v1 with types=[\'core\'].',
                        'readonly'     => false,
                        'destructive'  => true,
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
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/update-site-plugins-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Changes child sites. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. Updates plugins on a single site. For batch operations across multiple sites, use run_updates_v1 with types=[\'plugins\'].',
                        'readonly'     => false,
                        'destructive'  => true,
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
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/update-site-themes-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Changes child sites. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. Updates themes on a single site. For batch operations across multiple sites, use run_updates_v1 with types=[\'themes\'].',
                        'readonly'     => false,
                        'destructive'  => true,
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
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/update-site-translations-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Changes child sites. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. Updates translations on a single site. For batch operations across multiple sites, use run_updates_v1 with types=[\'translations\'].',
                        'readonly'     => false,
                        'destructive'  => true,
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
                'description'         => __( 'Add WordPress core to the ignored updates list for a single site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_ignore_site_core_input_schema(),
                'output_schema'       => self::get_ignore_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_ignore_site_core' ),
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/ignore-site-core-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Use action=add to ignore core updates without confirmation. To remove holds, use mainwp/unignore-site-updates-v1.',
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
                'description'         => __( 'Add plugins to the ignored updates list for a single site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_ignore_site_plugins_input_schema(),
                'output_schema'       => self::get_ignore_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_ignore_site_plugins' ),
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/ignore-site-plugins-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Provide a slugs array of plugins to ignore with action=add, without confirmation. To remove holds, use mainwp/unignore-site-updates-v1.',
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
                'description'         => __( 'Add themes to the ignored updates list for a single site.', 'mainwp' ),
                'category'            => 'mainwp-updates',
                'input_schema'        => self::get_ignore_site_themes_input_schema(),
                'output_schema'       => self::get_ignore_site_output_schema(),
                'execute_callback'    => array( self::class, 'execute_ignore_site_themes' ),
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/ignore-site-themes-v1', MainWP_Abilities_Util::make_site_permission_callback( 'site_id_or_domain' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Provide a slugs array of themes to ignore with action=add, without confirmation. To remove holds, use mainwp/unignore-site-updates-v1.',
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
                'permission_callback' => MainWP_Abilities_Util::make_operation_permission_callback( 'mainwp/update-all-v1', array( MainWP_Abilities_Util::class, 'check_manage_sites_permission' ) ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'Changes child sites. Requires confirm:true or dry_run:true. Call dry_run first and show the plan to the user. Applies all available updates (core, plugins, themes, translations) to specified sites or all sites. Operations with >200 sites are automatically queued and return a job_id for status polling.',
                        'readonly'     => false,
                        'destructive'  => true,
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
                'dry_run'             => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Preview the exact update plan without changing any site.', 'mainwp' ),
                ),
                'confirm'             => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Must be true to apply updates.', 'mainwp' ),
                ),
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
                    'description' => __( 'Use "ignore" to add a hold. "unignore" returns an error that names mainwp/unignore-site-updates-v1.', 'mainwp' ),
                ),
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain/URL.', 'mainwp' ),
                ),
                'type'              => array(
                    'type'        => 'string',
                    'enum'        => array( 'core', 'plugin', 'theme' ),
                    'description' => __( 'Type of update to ignore.', 'mainwp' ),
                ),
                'slug'              => array(
                    'type'        => 'string',
                    'description' => __( 'Slug of the item to ignore. Use "wordpress" for core updates.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'action', 'site_id_or_domain', 'type', 'slug' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get input schema for unignore-site-updates-v1.
     *
     * @return array
     */
    public static function get_unignore_site_updates_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'site_id_or_domain' => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Site ID or domain/URL.', 'mainwp' ),
                ),
                'type'              => array(
                    'type'        => 'string',
                    'enum'        => array( 'core', 'plugin', 'theme' ),
                    'description' => __( 'Type of update hold to remove.', 'mainwp' ),
                ),
                'slugs'             => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'minItems'    => 1,
                    'description' => __( 'Plugin or theme slugs whose site holds to remove. Required and non-empty for plugin and theme. Ignored for core, which has a single site hold.', 'mainwp' ),
                ),
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Preview hold removal without changing anything.', 'mainwp' ),
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Must be true to remove holds.', 'mainwp' ),
                ),
            ),
            'required'             => array( 'site_id_or_domain', 'type' ),
            'additionalProperties' => false,
        );
    }

    // =========================================================================
    // Output Schema Definitions
    // =========================================================================

    /**
     * Get output schema for unignore-site-updates-v1.
     *
     * @return array
     */
    public static function get_unignore_site_updates_output_schema(): array {
        return array(
            'type'  => 'object',
            'oneOf' => array(
                array(
                    'type'                 => 'object',
                    'description'          => __( 'Read-only preview of the selected site holds.', 'mainwp' ),
                    'properties'           => array(
                        'dry_run'      => array(
                            'type'        => 'boolean',
                            'const'       => true,
                            'description' => __( 'Always true for a preview.', 'mainwp' ),
                        ),
                        'would_affect' => array(
                            'type'                 => 'object',
                            'description'          => __( 'The site and each requested hold.', 'mainwp' ),
                            'properties'           => array(
                                'site_id'   => array(
                                    'type'        => 'integer',
                                    'description' => __( 'Resolved site ID.', 'mainwp' ),
                                ),
                                'site_url'  => array(
                                    'type'        => 'string',
                                    'format'      => 'uri',
                                    'description' => __( 'Site URL.', 'mainwp' ),
                                ),
                                'site_name' => array(
                                    'type'        => 'string',
                                    'description' => __( 'Site display name.', 'mainwp' ),
                                ),
                                'holds'     => array(
                                    'type'        => 'array',
                                    'description' => __( 'Requested items, including items with no site hold.', 'mainwp' ),
                                    'items'       => array(
                                        'type'       => 'object',
                                        'properties' => array(
                                            'type'    => array(
                                                'type' => 'string',
                                                'enum' => array( 'core', 'plugin', 'theme' ),
                                                'description' => __( 'Update type.', 'mainwp' ),
                                            ),
                                            'slug'    => array(
                                                'type' => 'string',
                                                'description' => __( 'Item slug; wordpress for core.', 'mainwp' ),
                                            ),
                                            'name'    => array(
                                                'type' => 'string',
                                                'description' => __( 'Name from pending data or the stored hold, otherwise the slug.', 'mainwp' ),
                                            ),
                                            'held'    => array(
                                                'type' => 'boolean',
                                                'description' => __( 'Whether the item is in the site\'s own hold list. False means removal is a no-op.', 'mainwp' ),
                                            ),
                                            'still_held_by' => array(
                                                'type' => array( 'string', 'null' ),
                                                'enum' => array( 'global', 'site_flag', null ),
                                                'description' => __( 'A hold outside the site list that still blocks this item: global for the Dashboard-wide ignored list, site_flag for the site\'s ignore setting for this type, null when nothing else holds it. When both apply, global is reported.', 'mainwp' ),
                                            ),
                                            'pending' => array(
                                                'type'     => array( 'object', 'null' ),
                                                'description' => __( 'The pending update the site reports, shown even while the item is held; null when no update is pending.', 'mainwp' ),
                                                'properties' => array(
                                                    'from' => array(
                                                        'type'        => 'string',
                                                        'description' => __( 'Installed version.', 'mainwp' ),
                                                    ),
                                                    'to'   => array(
                                                        'type'        => 'string',
                                                        'description' => __( 'Pending target version.', 'mainwp' ),
                                                    ),
                                                ),
                                                'required' => array( 'from', 'to' ),
                                                'additionalProperties' => false,
                                            ),
                                        ),
                                        'required'   => array( 'type', 'slug', 'name', 'held', 'still_held_by', 'pending' ),
                                        'additionalProperties' => false,
                                    ),
                                ),
                            ),
                            'required'             => array( 'site_id', 'site_url', 'site_name', 'holds' ),
                            'additionalProperties' => false,
                        ),
                        'count'        => array(
                            'type'        => 'integer',
                            'description' => __( 'Number of requested items with held: true.', 'mainwp' ),
                        ),
                        'warnings'     => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'string' ),
                            'description' => __( 'Consequences of removing the selected holds. Empty when count is zero.', 'mainwp' ),
                        ),
                        'errors'       => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'object' ),
                            'maxItems'    => 0,
                            'description' => __( 'Always empty for a successful preview. Resolution and permission failures return an error instead.', 'mainwp' ),
                        ),
                    ),
                    'required'             => array( 'dry_run', 'would_affect', 'count', 'warnings', 'errors' ),
                    'additionalProperties' => false,
                ),
                array(
                    'type'                 => 'object',
                    'description'          => __( 'Confirmed removal from the site\'s own hold list.', 'mainwp' ),
                    'properties'           => array(
                        'dry_run'  => array(
                            'type'        => 'boolean',
                            'const'       => false,
                            'description' => __( 'Always false for confirmed execution.', 'mainwp' ),
                        ),
                        'removed'  => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'string' ),
                            'description' => __( 'Slugs removed from the site\'s own hold list.', 'mainwp' ),
                        ),
                        'not_held' => array(
                            'type'        => 'array',
                            'items'       => array( 'type' => 'string' ),
                            'description' => __( 'Requested slugs with no site hold; no change was made for them.', 'mainwp' ),
                        ),
                        'count'    => array(
                            'type'        => 'integer',
                            'description' => __( 'Number of removed slugs.', 'mainwp' ),
                        ),
                    ),
                    'required'             => array( 'dry_run', 'removed', 'not_held', 'count' ),
                    'additionalProperties' => false,
                ),
            ),
        );
    }

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

        $schema = array(
            'type'        => 'object',
            'description' => __( 'Response varies by mode. Preview (dry_run: true) returns dry_run, would_affect, count, warnings and errors and changes nothing. Immediate execution (200 sites or fewer) returns dry_run, skipped, updated, errors and summary. Queued execution (more than 200 sites) returns dry_run, queued, job_id, status_url, updates_queued and errors.', 'mainwp' ),
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
                    'description' => __( 'Queued execution response for operations with more than 200 sites. Held items and unchanged sites are reported by get-batch-job-status-v1 once the job runs.', 'mainwp' ),
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
                self::get_update_preview_output_schema(),
            ),
        );
        $schema['oneOf'][0] = self::add_update_execution_schema( $schema['oneOf'][0] );
        $schema['oneOf'][1] = self::add_update_execution_schema( $schema['oneOf'][1], true );
        return $schema;
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
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Preview the exact update plan without changing any site.', 'mainwp' ),
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Must be true to apply updates.', 'mainwp' ),
                ),
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
        $execution_schema = array(
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

        return array(
            'type'  => 'object',
            'oneOf' => array( self::add_update_execution_schema( $execution_schema ), self::get_update_preview_output_schema() ),
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
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Preview the exact update plan without changing any site.', 'mainwp' ),
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Must be true to apply updates.', 'mainwp' ),
                ),
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

        $execution_schema = array(
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

        return array(
            'type'  => 'object',
            'oneOf' => array( self::add_update_execution_schema( $execution_schema ), self::get_update_preview_output_schema() ),
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
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Preview the exact update plan without changing any site.', 'mainwp' ),
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Must be true to apply updates.', 'mainwp' ),
                ),
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

        $execution_schema = array(
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

        return array(
            'type'  => 'object',
            'oneOf' => array( self::add_update_execution_schema( $execution_schema ), self::get_update_preview_output_schema() ),
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
                'dry_run'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Preview the exact update plan without changing any site.', 'mainwp' ),
                ),
                'confirm'           => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Must be true to apply updates.', 'mainwp' ),
                ),
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

        $execution_schema = array(
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

        return array(
            'type'  => 'object',
            'oneOf' => array( self::add_update_execution_schema( $execution_schema ), self::get_update_preview_output_schema() ),
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
                    'description' => __( 'Use "add" to create a hold. "remove" returns an error that names mainwp/unignore-site-updates-v1.', 'mainwp' ),
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
                    'description' => __( 'Use "add" to create a hold. "remove" returns an error that names mainwp/unignore-site-updates-v1.', 'mainwp' ),
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
                    'description' => __( 'Use "add" to create a hold. "remove" returns an error that names mainwp/unignore-site-updates-v1.', 'mainwp' ),
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
                'dry_run'             => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Preview the exact update plan without changing any site.', 'mainwp' ),
                ),
                'confirm'             => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => __( 'Must be true to apply updates.', 'mainwp' ),
                ),
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

        $schema             = array(
            'type'        => 'object',
            'description' => __( 'Response varies by mode. Preview (dry_run: true) returns dry_run, would_affect, count, warnings and errors and changes nothing. Immediate execution (200 sites or fewer) returns dry_run, skipped, updated, errors and summary. Queued execution (more than 200 sites) returns dry_run, queued, job_id, status_url, updates_queued and errors.', 'mainwp' ),
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
                    'description' => __( 'Queued execution response for operations with more than 200 sites. Held items and unchanged sites are reported by get-batch-job-status-v1 once the job runs.', 'mainwp' ),
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
                self::get_update_preview_output_schema(),
            ),
        );
        $schema['oneOf'][0] = self::add_update_execution_schema( $schema['oneOf'][0] );
        $schema['oneOf'][1] = self::add_update_execution_schema( $schema['oneOf'][1], true );
        return $schema;
    }

    /**
     * Add skip reporting to an update execution schema.
     *
     * @param array $schema Execution schema.
     * @param bool  $queued Whether execution queued a job.
     * @return array
     */
    private static function add_update_execution_schema( array $schema, bool $queued = false ): array {
        // WordPress does not enforce `const`; the branches stay exclusive through their required keys.
        $schema['properties']['dry_run'] = array(
            'type'  => 'boolean',
            'const' => false,
        );
        $schema['required'][]            = 'dry_run';
        if ( $queued ) {
            return $schema;
        }
        $schema['required'][] = 'skipped';

        $preview_schema  = self::get_update_preview_output_schema();
        $site_properties = $preview_schema['properties']['would_affect']['properties']['sites']['items']['properties'];
        $skip_schema     = $site_properties['skipped']['items'];
        foreach ( array( 'site_id', 'site_url', 'site_name' ) as $key ) {
            $skip_schema['properties'][ $key ] = $site_properties[ $key ];
            $skip_schema['required'][]         = $key;
        }
        $schema['properties']['skipped'] = array(
            'type'        => 'array',
            'description' => __( 'Pending updates that were not sent because a hold applied, with the site each belongs to.', 'mainwp' ),
            'items'       => $skip_schema,
        );
        if ( ! isset( $schema['properties']['summary'] ) ) {
            $schema['properties']['summary'] = array(
                'type'       => 'object',
                'properties' => array(),
                'required'   => array(),
            );
            $schema['required'][]            = 'summary';
        }
        $schema['properties']['summary']['properties']['total_skipped'] = array( 'type' => 'integer' );
        $schema['properties']['summary']['required'][]                  = 'total_skipped';
        return $schema;
    }

    /**
     * Get the output schema shared by update previews.
     *
     * @return array
     */
    private static function get_update_preview_output_schema(): array {
        $flags              = array(
            'type'       => 'object',
            'properties' => array(
                'core'  => array(
                    'type'        => 'boolean',
                    'description' => __( 'True for a WordPress core update.', 'mainwp' ),
                ),
                'major' => array(
                    'type'        => array( 'boolean', 'null' ),
                    'description' => __( 'True when the first version number changes, or for WordPress core the first or second. Always false for translations. Null when either version cannot be parsed.', 'mainwp' ),
                ),
            ),
            'required'   => array( 'core', 'major' ),
        );
        $item_properties    = array(
            'type'      => array(
                'type' => 'string',
                'enum' => array( 'core', 'plugin', 'theme', 'translation' ),
            ),
            'slug'      => array( 'type' => 'string' ),
            'name'      => array( 'type' => 'string' ),
            'from'      => array( 'type' => 'string' ),
            'to'        => array( 'type' => 'string' ),
            'requested' => array(
                'type'        => 'boolean',
                'description' => __( 'True when the caller named this slug in specific_items or slugs.', 'mainwp' ),
            ),
        );
        $item_schema        = array(
            'type'       => 'object',
            'properties' => $item_properties + array( 'flags' => $flags ),
            'required'   => array( 'type', 'slug', 'name', 'from', 'to', 'requested', 'flags' ),
        );
        $skip_schema        = array(
            'type'       => 'object',
            'properties' => $item_properties + array(
                'reason' => array(
                    'type'        => 'string',
                    'enum'        => array( 'ignored_site', 'ignored_global', 'ignored_site_flag' ),
                    'description' => __( 'Which hold applies. ignored_site: the site\'s own ignore list. ignored_global: the global ignore list. ignored_site_flag: the site ignores every update of this type. The first matching layer wins, in that order.', 'mainwp' ),
                ),
            ),
            'required'   => array( 'type', 'slug', 'name', 'from', 'to', 'reason', 'requested' ),
        );
        $summary_properties = array(
            'site_count'          => array(
                'type'        => 'integer',
                'description' => __( 'Sites with at least one planned update, counted over every selected site even when sites is truncated.', 'mainwp' ),
            ),
            'item_count'          => array( 'type' => 'integer' ),
            'skipped_count'       => array( 'type' => 'integer' ),
            'all_sites'           => array( 'type' => 'boolean' ),
            'queued'              => array( 'type' => 'boolean' ),
            'truncated'           => array( 'type' => 'boolean' ),
            'has_core'            => array( 'type' => 'boolean' ),
            'has_major'           => array( 'type' => 'boolean' ),
            'has_unknown_version' => array( 'type' => 'boolean' ),
            'requested_not_found' => array(
                'type'        => 'array',
                'description' => __( 'Requested slugs with no pending update on any selected site. A held requested slug is not listed here; it appears in skipped.', 'mainwp' ),
                'items'       => array( 'type' => 'string' ),
            ),
        );

        return array(
            'type'        => 'object',
            'description' => __( 'Preview returned for dry_run: true. No site was changed.', 'mainwp' ),
            'properties'  => array(
                'dry_run'      => array(
                    'type'  => 'boolean',
                    'const' => true,
                ),
                'would_affect' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'sites'   => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'site_id'   => array( 'type' => 'integer' ),
                                    'site_url'  => array(
                                        'type'   => 'string',
                                        'format' => 'uri',
                                    ),
                                    'site_name' => array( 'type' => 'string' ),
                                    'items'     => array(
                                        'type'  => 'array',
                                        'items' => $item_schema,
                                    ),
                                    'skipped'   => array(
                                        'type'  => 'array',
                                        'items' => $skip_schema,
                                    ),
                                ),
                                'required'   => array( 'site_id', 'site_url', 'site_name', 'items', 'skipped' ),
                            ),
                        ),
                        'by_item' => array(
                            'type'        => 'array',
                            'description' => __( 'Every planned update grouped by type and slug across all sites. Complete even when sites is truncated.', 'mainwp' ),
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'type'       => $item_properties['type'],
                                    'slug'       => array( 'type' => 'string' ),
                                    'name'       => array( 'type' => 'string' ),
                                    'to'         => array(
                                        'type'  => 'array',
                                        'items' => array( 'type' => 'string' ),
                                    ),
                                    'site_count' => array( 'type' => 'integer' ),
                                    'flags'      => $flags,
                                ),
                                'required'   => array( 'type', 'slug', 'name', 'to', 'site_count', 'flags' ),
                            ),
                        ),
                        'summary' => array(
                            'type'       => 'object',
                            'properties' => $summary_properties,
                            'required'   => array_keys( $summary_properties ),
                        ),
                    ),
                    'required'   => array( 'sites', 'by_item', 'summary' ),
                ),
                'count'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Updates that would run. Equals summary.item_count.', 'mainwp' ),
                ),
                'warnings'     => array(
                    'type'  => 'array',
                    'items' => array( 'type' => 'string' ),
                ),
                'errors'       => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'site_id'   => array( 'type' => 'integer' ),
                            'site_url'  => array( 'type' => 'string' ),
                            'site_name' => array( 'type' => 'string' ),
                            'type'      => array( 'type' => 'string' ),
                            'slug'      => array( 'type' => 'string' ),
                            'code'      => array( 'type' => 'string' ),
                            'message'   => array( 'type' => 'string' ),
                        ),
                        'required'   => array( 'site_id', 'site_url', 'type', 'slug', 'code', 'message' ),
                    ),
                ),
            ),
            'required'    => array( 'dry_run', 'would_affect', 'count', 'warnings', 'errors' ),
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
        // API-key authentication bypasses DB scoping, so all-sites results need an explicit intersection.
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
            // Apply the ability scope below because API-key requests bypass DB scoping.
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

            $sites   = $websites ? $websites : array();
            $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
            if ( 'all' !== $allowed ) {
                $sites = array_intersect_key( $sites, array_flip( $allowed ) );
            }
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

        foreach ( array( 'site_ids_or_domains', 'types', 'specific_items' ) as $parameter ) {
            if ( array_key_exists( $parameter, $input ) && ! is_array( $input[ $parameter ] ) ) {
                return new \WP_Error(
                    'mainwp_invalid_input',
                    /* translators: %s: input parameter name. */
                    sprintf( __( 'The %s parameter must be an array.', 'mainwp' ), $parameter ),
                    array( 'status' => 400 )
                );
            }
        }

        $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );
        $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

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

            $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
            if ( 'all' !== $allowed ) {
                $all_sites = array_intersect_key( $all_sites ? $all_sites : array(), array_flip( $allowed ) );
            }

            $site_ids_or_domains = array_map(
                function ( $s ) {
                    return (int) $s->id;
                },
                $all_sites ? $all_sites : array()
            );
        }

        // Check per-site ACLs and filter to allowed sites.
        $access_check            = MainWP_Abilities_Util::check_batch_site_access( $site_ids_or_domains, $input );
        $access_check['allowed'] = self::unique_update_sites( $access_check['allowed'] );

        if ( $dry_run ) {
            return self::get_update_preview(
                $access_check['allowed'],
                $types,
                $specific_items,
                empty( $input['site_ids_or_domains'] ),
                self::normalize_resolution_errors( $access_check['denied'] )
            );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Updates require confirm: true. Call with dry_run: true first to preview the plan.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

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
                'dry_run'        => false,
                'queued'         => true,
                'job_id'         => $job_id,
                'status_url'     => rest_url( "mainwp/v2/jobs/{$job_id}" ),
                'updates_queued' => count( $access_check['allowed'] ),
                'errors'         => $errors,
            );
        }

        $skipped = self::get_execution_skipped( $access_check['allowed'], $types, $specific_items );

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

            // Same floor as the plan builder, so a preview and its execution keep the same sites.
            $version_check = MainWP_Abilities_Util::check_child_version( $site, '4.0' );
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
            'dry_run' => false,
            'skipped' => $skipped,
            'updated' => $updated,
            'errors'  => $errors,
            'summary' => array(
                'total_skipped' => count( $skipped ),
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
        // API-key authentication bypasses DB scoping, so all-sites results need an explicit intersection.
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
            // Apply the ability scope below because API-key requests bypass DB scoping.
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

            $sites   = $websites ? $websites : array();
            $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
            if ( 'all' !== $allowed ) {
                $sites = array_intersect_key( $sites, array_flip( $allowed ) );
            }
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
        if ( 'unignore' === ( $input['action'] ?? '' ) ) {
            return self::get_unignore_moved_error();
        }

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

        $result = self::add_to_ignored_list( $site, $type, $slug );

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
     * Return the replacement for the old hold-removal actions.
     *
     * @return \WP_Error
     */
    private static function get_unignore_moved_error(): \WP_Error {
        return new \WP_Error(
            'mainwp_unignore_moved',
            __( 'Removing an item from the ignored list needs confirmation. Use mainwp/unignore-site-updates-v1.', 'mainwp' ),
            array(
                'status'      => 400,
                'replacement' => 'mainwp/unignore-site-updates-v1',
            )
        );
    }

    /**
     * Preview or confirm removal of the site's own update holds.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_unignore_site_updates( array $input ) {
        $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );
        $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
        if ( $dry_run && $confirm ) {
            return new \WP_Error( 'mainwp_invalid_input', __( 'Cannot specify both dry_run and confirm.', 'mainwp' ), array( 'status' => 400 ) );
        }

        $type = $input['type'] ?? '';
        if ( ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
            return new \WP_Error( 'mainwp_invalid_input', __( 'Invalid update type.', 'mainwp' ), array( 'status' => 400 ) );
        }
        $slugs = 'core' === $type ? array( 'wordpress' ) : ( $input['slugs'] ?? array() );
        if ( ! is_array( $slugs ) || empty( $slugs ) ) {
            return new \WP_Error( 'mainwp_invalid_input', __( 'A non-empty slugs array is required for plugins and themes.', 'mainwp' ), array( 'status' => 400 ) );
        }
        foreach ( $slugs as $slug ) {
            if ( ! is_string( $slug ) ) {
                return new \WP_Error( 'mainwp_invalid_input', __( 'Each slug must be a string.', 'mainwp' ), array( 'status' => 400 ) );
            }
        }
        $slugs = array_values( array_unique( $slugs ) );

        $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }
        if ( ! $dry_run && ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Removing a hold requires confirm: true. Call with dry_run: true first to preview it.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $holds = self::get_unignore_site_holds( $site, $type, $slugs );
        if ( $dry_run ) {
            $count               = 0;
            $has_eligible        = false;
            $has_remaining_holds = false;
            foreach ( $holds as $hold ) {
                if ( ! $hold['held'] ) {
                    continue;
                }
                ++$count;
                if ( null === $hold['still_held_by'] ) {
                    $has_eligible = true;
                } else {
                    $has_remaining_holds = true;
                }
            }
            $warnings = array();
            if ( $has_eligible ) {
                $warnings[] = __( 'Removing a hold makes the update eligible for the next run, including update-all and queued runs.', 'mainwp' );
            }
            if ( $has_remaining_holds ) {
                $warnings[] = __( 'Items with still_held_by set stay blocked by the global ignored list or the site\'s ignore setting for that type until that hold is removed too.', 'mainwp' );
            }
            return array(
                'dry_run'      => true,
                'would_affect' => array(
                    'site_id'   => (int) $site->id,
                    'site_url'  => (string) $site->url,
                    'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                    'holds'     => $holds,
                ),
                'count'        => $count,
                'warnings'     => $warnings,
                'errors'       => array(),
            );
        }

        $removed  = array();
        $not_held = array();
        foreach ( $holds as $hold ) {
            if ( ! $hold['held'] ) {
                $not_held[] = $hold['slug'];
                continue;
            }
            $result = self::remove_from_ignored_list( $site, $type, $hold['slug'] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $removed[] = $hold['slug'];
        }
        return array(
            'dry_run'  => false,
            'removed'  => $removed,
            'not_held' => $not_held,
            'count'    => count( $removed ),
        );
    }

    /**
     * Read requested holds and unfiltered pending data without creating settings.
     *
     * @param object $site  Resolved site.
     * @param string $type  Core, plugin or theme.
     * @param array  $slugs Requested slugs.
     * @return array
     */
    private static function get_unignore_site_holds( $site, string $type, array $slugs ): array {
        global $wpdb;

        $column  = 'core' === $type ? 'ignored_wp_upgrades' : 'ignored_' . $type . 's';
        $raw     = 'core' === $type ? MainWP_DB::instance()->get_website_option( $site, $column ) : self::get_site_column_value( $site, $column );
        $ignored = ! empty( $raw ) ? json_decode( $raw, true ) : array();
        $ignored = is_array( $ignored ) ? $ignored : array();

        // Match the existing default global settings lookup without inserting a missing row.
        $table = MainWP_DB_Common::instance()->get_table_name( 'users' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Read-only lookup; the existing getter inserts missing settings.
        $extension = $wpdb->get_row( $wpdb->prepare( 'SELECT ignored_plugins, ignored_themes, ignored_wp_upgrades FROM %i WHERE userid = %d', $table, 0 ) );
        $global    = is_object( $extension ) && ! empty( $extension->{$column} ) ? json_decode( $extension->{$column}, true ) : array();
        $global    = is_array( $global ) ? $global : array();
        $flag      = array(
            'core'   => 'is_ignoreCoreUpdates',
            'plugin' => 'is_ignorePluginUpdates',
            'theme'  => 'is_ignoreThemeUpdates',
        )[ $type ];

        $raw     = 'core' === $type ? MainWP_DB::instance()->get_website_option( $site, 'wp_upgrades' ) : self::get_site_column_value( $site, $type . '_upgrades' );
        $pending = ! empty( $raw ) ? json_decode( $raw, true ) : array();
        $pending = is_array( $pending ) ? $pending : array();
        $holds   = array();
        foreach ( $slugs as $slug ) {
            $entry  = 'core' === $type ? $ignored : ( $ignored[ $slug ] ?? null );
            $update = 'core' === $type ? $pending : ( $pending[ $slug ] ?? array() );
            $update = is_array( $update ) ? $update : array();
            $name   = is_array( $entry ) ? ( $entry['Name'] ?? $slug ) : ( is_string( $entry ) ? $entry : $slug );
            if ( ! empty( $update ) ) {
                $name = $update['Name'] ?? ( 'core' === $type ? 'WordPress' : $name );
            }
            $globally_held = 'core' === $type ? ! empty( $global['ignored_versions'] ) : array_key_exists( $slug, $global );
            if ( ! empty( $update ) ) {
                $globally_held = self::global_hold_blocks_pending_update( $update, $global, $type, $slug );
            }
            $holds[] = array(
                'type'          => $type,
                'slug'          => $slug,
                'name'          => (string) $name,
                'held'          => 'core' === $type ? ! empty( $ignored['ignored_versions'] ) : array_key_exists( $slug, $ignored ),
                'still_held_by' => $globally_held ? 'global' : ( ! empty( $site->{$flag} ) ? 'site_flag' : null ),
                'pending'       => ! empty( $update ) ? array(
                    'from' => (string) ( 'core' === $type ? ( $update['current'] ?? '' ) : ( $update['Version'] ?? '' ) ),
                    'to'   => (string) ( 'core' === $type ? ( $update['new'] ?? '' ) : ( $update['update']['new_version'] ?? $update['new_version'] ?? '' ) ),
                ) : null,
            );
        }
        return $holds;
    }

    /**
     * Check whether a global hold blocks a pending update.
     *
     * @param array  $update         Pending update data.
     * @param array  $global_ignored Global ignored list.
     * @param string $type           Core, plugin or theme.
     * @param string $slug           Requested slug.
     * @return bool
     */
    private static function global_hold_blocks_pending_update( array $update, array $global_ignored, string $type, string $slug ): bool {
        // Mirrors the filters get_site_updates() runs: is_ignored_updates() for core,
        // get_not_ignored_updates_themesplugins() for plugins and themes.
        if ( 'core' === $type ) {
            $entry       = $global_ignored;
            $new_version = $update['new'] ?? '';
            if ( empty( $new_version ) ) {
                $new_version = $update['new_version'] ?? '';
            }
            if ( empty( $new_version ) ) {
                return false;
            }
        } else {
            $entry = $global_ignored[ $slug ] ?? null;
            if ( is_string( $entry ) ) {
                return true;
            }
            // The plugin/theme run filter reads only the nested version, so a flat
            // new_version never matches a version-specific global hold there either.
            $new_version = $update['update']['new_version'] ?? '';
        }
        $versions = is_array( $entry ) && isset( $entry['ignored_versions'] ) && is_array( $entry['ignored_versions'] ) ? $entry['ignored_versions'] : array();
        return in_array( 'all_versions', $versions ) || in_array( $new_version, $versions );
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
        $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );
        $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $site_id_or_domain = $input['site_id_or_domain'];

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        if ( $dry_run ) {
            return self::get_update_preview( array( $site ), array( 'core' ), array() );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Updates require confirm: true. Call with dry_run: true first to preview the plan.', 'mainwp' ),
                array( 'status' => 400 )
            );
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

        $skipped = self::get_execution_skipped( array( $site ), array( 'core' ), array() );

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

        $updates = self::get_site_updates( $site, array( 'core' ), $global_ignored_plugins, $global_ignored_themes, $global_ignored_core );

        if ( empty( $updates ) || '' === $updates[0]['current_version'] || '' === $updates[0]['new_version'] ) {
            return new \WP_Error(
                'mainwp_no_updates',
                __( 'No core updates available for this site.', 'mainwp' ),
                array( 'status' => 404 )
            );
        }

        $result = self::execute_core_update( $site, $updates[0] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'dry_run' => false,
            'skipped' => $skipped,
            'updated' => $result,
            'summary' => array( 'total_skipped' => count( $skipped ) ),
        );
    }

    /**
     * Execute callback for mainwp/update-site-plugins-v1.
     *
     * @param array $input Validated input from Abilities API.
     * @return array|\WP_Error
     */
    public static function execute_update_site_plugins( array $input ) { // phpcs:ignore -- NOSONAR - complex method.
        if ( array_key_exists( 'slugs', $input ) && ! is_array( $input['slugs'] ) ) {
            return new \WP_Error( 'mainwp_invalid_input', __( 'The slugs parameter must be an array.', 'mainwp' ), array( 'status' => 400 ) );
        }

        $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );
        $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

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

        if ( $dry_run ) {
            return self::get_update_preview( array( $site ), array( 'plugins' ), $slugs );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Updates require confirm: true. Call with dry_run: true first to preview the plan.', 'mainwp' ),
                array( 'status' => 400 )
            );
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

        $skipped = self::get_execution_skipped( array( $site ), array( 'plugins' ), $slugs );

        $user_extension         = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_plugins = array();
        if ( is_object( $user_extension ) ) {
            $global_ignored_plugins = ! empty( $user_extension->ignored_plugins ) ? json_decode( $user_extension->ignored_plugins, true ) : array();
        }
        if ( ! is_array( $global_ignored_plugins ) ) {
            $global_ignored_plugins = array();
        }

        $updates = self::get_site_updates( $site, array( 'plugins' ), $global_ignored_plugins, array(), array() );

        if ( ! empty( $slugs ) ) {
            $updates = array_filter(
                $updates,
                function ( $update ) use ( $slugs ) {
                    return in_array( $update['slug'], $slugs, true );
                }
            );
        }

        if ( empty( $updates ) ) {
            return array(
                'dry_run' => false,
                'skipped' => $skipped,
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_skipped' => count( $skipped ),
                    'total_updated' => 0,
                    'total_errors'  => 0,
                ),
            );
        }

        $result = self::execute_plugin_updates( $site, $updates );

        return array(
            'dry_run' => false,
            'skipped' => $skipped,
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
            'summary' => array(
                'total_skipped' => count( $skipped ),
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
        if ( array_key_exists( 'slugs', $input ) && ! is_array( $input['slugs'] ) ) {
            return new \WP_Error( 'mainwp_invalid_input', __( 'The slugs parameter must be an array.', 'mainwp' ), array( 'status' => 400 ) );
        }

        $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );
        $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

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

        if ( $dry_run ) {
            return self::get_update_preview( array( $site ), array( 'themes' ), $slugs );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Updates require confirm: true. Call with dry_run: true first to preview the plan.', 'mainwp' ),
                array( 'status' => 400 )
            );
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

        $skipped = self::get_execution_skipped( array( $site ), array( 'themes' ), $slugs );

        $user_extension        = MainWP_DB_Common::instance()->get_user_extension_by_user_id();
        $global_ignored_themes = array();
        if ( is_object( $user_extension ) ) {
            $global_ignored_themes = ! empty( $user_extension->ignored_themes ) ? json_decode( $user_extension->ignored_themes, true ) : array();
        }
        if ( ! is_array( $global_ignored_themes ) ) {
            $global_ignored_themes = array();
        }

        $updates = self::get_site_updates( $site, array( 'themes' ), array(), $global_ignored_themes, array() );

        if ( ! empty( $slugs ) ) {
            $updates = array_filter(
                $updates,
                function ( $update ) use ( $slugs ) {
                    return in_array( $update['slug'], $slugs, true );
                }
            );
        }

        if ( empty( $updates ) ) {
            return array(
                'dry_run' => false,
                'skipped' => $skipped,
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_skipped' => count( $skipped ),
                    'total_updated' => 0,
                    'total_errors'  => 0,
                ),
            );
        }

        $result = self::execute_theme_updates( $site, $updates );

        return array(
            'dry_run' => false,
            'skipped' => $skipped,
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
            'summary' => array(
                'total_skipped' => count( $skipped ),
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
        if ( array_key_exists( 'slugs', $input ) && ! is_array( $input['slugs'] ) ) {
            return new \WP_Error( 'mainwp_invalid_input', __( 'The slugs parameter must be an array.', 'mainwp' ), array( 'status' => 400 ) );
        }

        $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );
        $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

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

        if ( $dry_run ) {
            return self::get_update_preview( array( $site ), array( 'translations' ), $slugs );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Updates require confirm: true. Call with dry_run: true first to preview the plan.', 'mainwp' ),
                array( 'status' => 400 )
            );
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

        $skipped = self::get_execution_skipped( array( $site ), array( 'translations' ), $slugs );

        $translation_upgrades = ! empty( $site->translation_upgrades ) ? json_decode( $site->translation_upgrades, true ) : array();
        if ( ! is_array( $translation_upgrades ) || empty( $translation_upgrades ) ) {
            return array(
                'dry_run' => false,
                'skipped' => $skipped,
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_skipped' => count( $skipped ),
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
                'dry_run' => false,
                'skipped' => $skipped,
                'updated' => array(),
                'errors'  => array(),
                'summary' => array(
                    'total_skipped' => count( $skipped ),
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
            'dry_run' => false,
            'skipped' => $skipped,
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
            'summary' => array(
                'total_skipped' => count( $skipped ),
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
        if ( 'remove' === ( $input['action'] ?? 'add' ) ) {
            return self::get_unignore_moved_error();
        }

        $site_id_or_domain = $input['site_id_or_domain'];

        $site = MainWP_Abilities_Util::resolve_site( $site_id_or_domain );
        if ( is_wp_error( $site ) ) {
            return $site;
        }

        $access_check = MainWP_Abilities_Util::check_site_access( $site, $input );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        $result  = self::add_to_ignored_list( $site, 'core', 'wordpress' );
        $message = __( 'Core updates ignored for this site.', 'mainwp' );

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
        if ( 'remove' === ( $input['action'] ?? 'add' ) ) {
            return self::get_unignore_moved_error();
        }

        $site_id_or_domain = $input['site_id_or_domain'];
        $slugs             = $input['slugs'];

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
            $result = self::add_to_ignored_list( $site, 'plugin', $slug );

            if ( ! is_wp_error( $result ) ) {
                ++$count;
            }
        }

        if ( $count > 0 ) {
            $message = sprintf(
                /* translators: %d: Number of plugins */
                _n( '%d plugin ignored.', '%d plugins ignored.', $count, 'mainwp' ),
                $count
            );
        } else {
            $message = __( 'No plugins were ignored.', 'mainwp' );
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
        if ( 'remove' === ( $input['action'] ?? 'add' ) ) {
            return self::get_unignore_moved_error();
        }

        $site_id_or_domain = $input['site_id_or_domain'];
        $slugs             = $input['slugs'];

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
            $result = self::add_to_ignored_list( $site, 'theme', $slug );

            if ( ! is_wp_error( $result ) ) {
                ++$count;
            }
        }

        if ( $count > 0 ) {
            $message = sprintf(
                /* translators: %d: Number of themes */
                _n( '%d theme ignored.', '%d themes ignored.', $count, 'mainwp' ),
                $count
            );
        } else {
            $message = __( 'No themes were ignored.', 'mainwp' );
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
        $input = is_array( $input ) ? $input : array();

        foreach ( array( 'site_ids_or_domains', 'types', 'specific_items' ) as $parameter ) {
            if ( array_key_exists( $parameter, $input ) && ! is_array( $input[ $parameter ] ) ) {
                return new \WP_Error(
                    'mainwp_invalid_input',
                    /* translators: %s: input parameter name. */
                    sprintf( __( 'The %s parameter must be an array.', 'mainwp' ), $parameter ),
                    array( 'status' => 400 )
                );
            }
        }

        $dry_run = MainWP_Abilities_Util::get_boolean_input( $input, 'dry_run' );
        $confirm = MainWP_Abilities_Util::get_boolean_input( $input, 'confirm' );
        if ( $dry_run && $confirm ) {
            return new \WP_Error(
                'mainwp_invalid_input',
                __( 'Cannot specify both dry_run and confirm.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $site_ids_or_domains = $input['site_ids_or_domains'] ?? array();
        $types               = $input['types'] ?? array( 'core', 'plugins', 'themes', 'translations' );

        // If empty, get all sites for current user.
        if ( empty( $site_ids_or_domains ) ) {
            $all_sites = MainWP_DB::instance()->get_websites_for_current_user( array( 'selectgroups' => false ) );

            if ( is_wp_error( $all_sites ) ) {
                return $all_sites;
            }

            $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
            if ( 'all' !== $allowed ) {
                $all_sites = array_intersect_key( $all_sites ? $all_sites : array(), array_flip( $allowed ) );
            }

            $site_ids_or_domains = array_map(
                function ( $s ) {
                    return (int) $s->id;
                },
                $all_sites ? $all_sites : array()
            );
        }

        // Check per-site ACLs and filter to allowed sites.
        $access_check            = MainWP_Abilities_Util::check_batch_site_access( $site_ids_or_domains, $input );
        $access_check['allowed'] = self::unique_update_sites( $access_check['allowed'] );

        if ( $dry_run ) {
            return self::get_update_preview(
                $access_check['allowed'],
                $types,
                array(),
                empty( $input['site_ids_or_domains'] ),
                self::normalize_resolution_errors( $access_check['denied'] )
            );
        }

        if ( ! $confirm ) {
            return new \WP_Error(
                'mainwp_confirmation_required',
                __( 'Updates require confirm: true. Call with dry_run: true first to preview the plan.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

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
                'dry_run' => false,
                'skipped' => array(),
                'updated' => array(),
                'errors'  => $normalized_errors,
                'summary' => array(
                    'total_skipped' => 0,
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
                'dry_run'        => false,
                'queued'         => true,
                'job_id'         => $job_id,
                'status_url'     => rest_url( "mainwp/v2/jobs/{$job_id}" ),
                'updates_queued' => count( $sites ),
                'errors'         => self::normalize_resolution_errors( $acl_errors ),
            );
        }

        $skipped = self::get_execution_skipped( $sites, $types, array() );

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
            'dry_run' => false,
            'skipped' => $skipped,
            'updated' => $all_updated,
            'errors'  => $all_errors,
            'summary' => array(
                'total_skipped' => count( $skipped ),
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
     * Flatten execution-time skips with their site details.
     *
     * Build one site at a time so the preview's site limit cannot truncate results.
     *
     * @param array $sites          Resolved sites.
     * @param array $types          Selected update types.
     * @param array $specific_items Selected slugs.
     * @return array
     */
    private static function get_execution_skipped( array $sites, array $types, array $specific_items ): array {
        $skipped = array();
        foreach ( $sites as $site ) {
            $plan = self::build_update_plan( array( $site ), $types, $specific_items );
            foreach ( $plan['sites'] as $entry ) {
                $site_info = array_intersect_key( $entry, array_flip( array( 'site_id', 'site_url', 'site_name' ) ) );
                foreach ( $entry['skipped'] as $item ) {
                    $skipped[] = $site_info + $item;
                }
            }
        }
        return $skipped;
    }

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
     * Keep repeated identifiers from counting or dispatching the same site twice.
     *
     * @param array $sites Resolved, accessible sites in resolution order.
     * @return array
     */
    private static function unique_update_sites( array $sites ): array {
        $unique = array();
        foreach ( $sites as $site ) {
            $site_id = (int) $site->id;
            if ( ! isset( $unique[ $site_id ] ) ) {
                $unique[ $site_id ] = $site;
            }
        }
        return array_values( $unique );
    }

    /**
     * Build a read-only plan using the execution path's ignore filters.
     *
     * @param array $sites          Resolved, accessible sites in resolution order.
     * @param array $types          Update types to include.
     * @param array $specific_items Requested slugs; empty selects every pending item.
     * @return array Sites, item aggregates, summary and site errors.
     */
    public static function build_update_plan( array $sites, array $types, array $specific_items ): array {
        global $wpdb;

        $sites = self::unique_update_sites( $sites );

        // Read the same default global settings as execution, without creating a missing row.
        $table = MainWP_DB_Common::instance()->get_table_name( 'users' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Read-only lookup; the existing getter inserts missing settings.
        $extension = $wpdb->get_row( $wpdb->prepare( 'SELECT ignored_plugins, ignored_themes, ignored_wp_upgrades FROM %i WHERE userid = %d', $table, 0 ) );
        $global    = array();
        foreach ( array( 'ignored_plugins', 'ignored_themes', 'ignored_wp_upgrades' ) as $column ) {
            $list              = is_object( $extension ) && ! empty( $extension->{$column} ) ? json_decode( $extension->{$column}, true ) : array();
            $global[ $column ] = is_array( $list ) ? $list : array();
        }

        $threshold     = apply_filters( 'mainwp_abilities_batch_threshold', self::BATCH_THRESHOLD );
        $plan          = array(
            'sites'   => array(),
            'by_item' => array(),
            'summary' => array(
                'site_count'          => 0,
                'item_count'          => 0,
                'skipped_count'       => 0,
                'all_sites'           => false,
                'queued'              => count( $sites ) > $threshold,
                'truncated'           => false,
                'has_core'            => false,
                'has_major'           => false,
                'has_unknown_version' => false,
                'requested_not_found' => array(),
            ),
            'errors'  => array(),
        );
        $found         = array();
        $by_item       = array();
        $by_item_sites = array();
        $order         = array(
            'core'        => 0,
            'plugin'      => 1,
            'theme'       => 2,
            'translation' => 3,
        );
        $sort          = static function ( $a, $b ) use ( $order ) {
            $type_order = $order[ $a['type'] ] <=> $order[ $b['type'] ];
            return 0 !== $type_order ? $type_order : strcmp( $a['slug'], $b['slug'] );
        };

        foreach ( $sites as $site ) {
            $site_info = array(
                'site_id'   => (int) $site->id,
                'site_url'  => $site->url,
                'site_name' => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
            );
            $failure   = MainWP_Abilities_Util::check_child_version( $site, '4.0' );
            if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
                $failure = new \WP_Error( 'mainwp_site_offline', __( 'Site is known to be offline.', 'mainwp' ) );
            }
            if ( is_wp_error( $failure ) ) {
                $plan['errors'][] = $site_info + array(
                    'type'    => 'site',
                    'slug'    => '',
                    'code'    => $failure->get_error_code(),
                    'message' => $failure->get_error_message(),
                );
                continue;
            }

            // Clones isolate each hold layer without changing the stored pending data.
            // Nonempty JSON prevents the column fallback from restoring cleared holds.
            $unheld = clone $site;
            foreach ( array( 'ignored_plugins', 'ignored_themes', 'ignored_wp_upgrades' ) as $column ) {
                $unheld->{$column} = '[]';
            }
            foreach ( array( 'is_ignoreCoreUpdates', 'is_ignorePluginUpdates', 'is_ignoreThemeUpdates' ) as $flag ) {
                $unheld->{$flag} = 0;
            }
            $site_holds                      = clone $unheld;
            $site_holds->ignored_plugins     = self::get_site_column_value( $site, 'ignored_plugins' );
            $site_holds->ignored_themes      = self::get_site_column_value( $site, 'ignored_themes' );
            $site_holds->ignored_wp_upgrades = MainWP_DB::instance()->get_website_option( $site, 'ignored_wp_upgrades' );
            $flag_holds                      = clone $unheld;
            foreach ( array( 'is_ignoreCoreUpdates', 'is_ignorePluginUpdates', 'is_ignoreThemeUpdates' ) as $flag ) {
                $flag_holds->{$flag} = $site->{$flag} ?? 0;
            }

            $pending = self::get_site_updates( $unheld, $types, array(), array(), array() );
            $layers  = array(
                'ignored_site'      => self::get_site_updates( $site_holds, $types, array(), array(), array() ),
                'ignored_global'    => self::get_site_updates( $unheld, $types, $global['ignored_plugins'], $global['ignored_themes'], $global['ignored_wp_upgrades'] ),
                'ignored_site_flag' => self::get_site_updates( $flag_holds, $types, array(), array(), array() ),
            );
            foreach ( $layers as $reason => $updates ) {
                $layers[ $reason ] = array();
                foreach ( $updates as $update ) {
                    $layers[ $reason ][ $update['type'] . ':' . $update['slug'] ] = true;
                }
            }

            usort( $pending, $sort );
            $entry = $site_info + array(
                'items'   => array(),
                'skipped' => array(),
            );
            foreach ( $pending as $update ) {
                $requested = in_array( $update['slug'], $specific_items, true );
                if ( ! empty( $specific_items ) && ! $requested ) {
                    continue;
                }
                $found[] = $update['slug'];
                $key     = $update['type'] . ':' . $update['slug'];
                $from    = $update['current_version'];
                $to      = $update['new_version'];
                // The Child reports a translation's target as `version` with no installed version.
                if ( 'translation' === $update['type'] && '' === (string) $to ) {
                    $to   = $from;
                    $from = '';
                }
                $item        = array(
                    'type'      => $update['type'],
                    'slug'      => $update['slug'],
                    'name'      => $update['name'],
                    'from'      => $from,
                    'to'        => $to,
                    'requested' => $requested,
                );
                $skip_reason = '';
                foreach ( $layers as $reason => $remaining ) {
                    if ( ! isset( $remaining[ $key ] ) ) {
                        $skip_reason = $reason;
                        break;
                    }
                }
                if ( '' !== $skip_reason ) {
                    $entry['skipped'][] = $item + array( 'reason' => $skip_reason );
                    ++$plan['summary']['skipped_count'];
                    continue;
                }

                $major            = self::is_major_update( $item['type'], $item['from'], $item['to'] );
                $item['flags']    = array(
                    'core'  => 'core' === $item['type'],
                    'major' => $major,
                );
                $entry['items'][] = $item;
                ++$plan['summary']['item_count'];
                $plan['summary']['has_core']            = $plan['summary']['has_core'] || $item['flags']['core'];
                $plan['summary']['has_major']           = $plan['summary']['has_major'] || true === $major;
                $plan['summary']['has_unknown_version'] = $plan['summary']['has_unknown_version'] || null === $major;

                if ( ! isset( $by_item[ $key ] ) ) {
                    $by_item[ $key ] = array(
                        'type'       => $item['type'],
                        'slug'       => $item['slug'],
                        'name'       => $item['name'],
                        'to'         => array(),
                        'site_count' => 0,
                        'flags'      => $item['flags'],
                    );
                }
                if ( ! in_array( $item['to'], $by_item[ $key ]['to'], true ) ) {
                    $by_item[ $key ]['to'][] = $item['to'];
                }
                // One site can list the same translation slug once per language.
                $by_item_sites[ $key ][ $site_info['site_id'] ] = true;
                $by_item[ $key ]['site_count']                  = count( $by_item_sites[ $key ] );
                $previous_major                                 = $by_item[ $key ]['flags']['major'];
                $by_item[ $key ]['flags']['major']              = true === $major || true === $previous_major ? true : ( null === $major || null === $previous_major ? null : false );
            }
            if ( ! empty( $entry['items'] ) ) {
                ++$plan['summary']['site_count'];
            }
            if ( ! empty( $entry['items'] ) || ! empty( $entry['skipped'] ) ) {
                if ( count( $plan['sites'] ) < $threshold ) {
                    $plan['sites'][] = $entry;
                } else {
                    $plan['summary']['truncated'] = true;
                }
            }
        }

        $plan['by_item'] = array_values( $by_item );
        usort( $plan['by_item'], $sort );
        $plan['summary']['requested_not_found'] = array_values( array_unique( array_diff( $specific_items, $found ) ) );
        return $plan;
    }

    /**
     * Determine whether a version change is major, or unknown.
     *
     * @param string $type Update type.
     * @param string $from Current version.
     * @param string $to   Target version.
     * @return bool|null
     */
    private static function is_major_update( string $type, string $from, string $to ): ?bool {
        if ( 'translation' === $type ) {
            return false;
        }
        if ( ! preg_match( '/^(\d+)(?:\.(\d+))?/', $from, $from_parts ) || ! preg_match( '/^(\d+)(?:\.(\d+))?/', $to, $to_parts ) ) {
            return null;
        }
        if ( (int) $from_parts[1] !== (int) $to_parts[1] ) {
            return true;
        }
        return 'core' === $type && (int) ( $from_parts[2] ?? 0 ) !== (int) ( $to_parts[2] ?? 0 );
    }

    /**
     * Format an update plan as the shared preview envelope.
     *
     * @param array $sites          Resolved, accessible sites.
     * @param array $types          Update types.
     * @param array $specific_items Requested slugs.
     * @param bool  $all_sites      Whether the site selection was empty.
     * @param array $errors         Normalized resolution and access errors.
     * @return array
     */
    private static function get_update_preview( array $sites, array $types, array $specific_items, bool $all_sites = false, array $errors = array() ): array {
        $plan                         = self::build_update_plan( $sites, $types, $specific_items );
        $plan['summary']['all_sites'] = $all_sites;
        $errors                       = array_merge( $errors, $plan['errors'] );
        $summary                      = $plan['summary'];
        $warnings                     = array();
        if ( $summary['has_core'] ) {
            $warnings[] = __( 'The plan includes WordPress core updates.', 'mainwp' );
        }
        if ( $summary['has_major'] ) {
            $warnings[] = __( 'The plan includes a major version change.', 'mainwp' );
        }
        $threshold = apply_filters( 'mainwp_abilities_batch_threshold', self::BATCH_THRESHOLD );
        if ( $summary['queued'] ) {
            /* translators: %d: batch threshold in sites. */
            $warnings[] = sprintf( __( 'More than %d sites are selected, so the updates will run as a background job.', 'mainwp' ), $threshold );
        }
        if ( $summary['truncated'] ) {
            /* translators: %d: maximum number of sites shown in the plan. */
            $warnings[] = sprintf( __( 'The site list is limited to the first %d sites. by_item and summary cover every site.', 'mainwp' ), $threshold );
        }
        if ( $summary['all_sites'] ) {
            $warnings[] = __( 'No sites were named, so the plan covers every site you can access.', 'mainwp' );
        }
        unset( $plan['errors'] );
        return array(
            'dry_run'      => true,
            'would_affect' => $plan,
            'count'        => $summary['item_count'],
            'warnings'     => $warnings,
            'errors'       => $errors,
        );
    }

    /**
     * Get updates for a single site.
     *
     * Public so the queued update job applies the same ignore rules as the immediate path.
     *
     * @param object $site                   Site object.
     * @param array  $types                  Update types to include.
     * @param array  $global_ignored_plugins Global ignored plugins list.
     * @param array  $global_ignored_themes  Global ignored themes list.
     * @param array  $global_ignored_core    Global ignored core list.
     * @return array Array of update items.
     */
    public static function get_site_updates( $site, array $types, array $global_ignored_plugins, array $global_ignored_themes, array $global_ignored_core ): array { // phpcs:ignore -- NOSONAR - complex method.
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

            MainWP_Updates_Handler::activity_log_upgrade( $site, $information ); // see do_action_mainwp_install_actions for more details about the upgrade: plugins, themes, translation.

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

            if ( is_array( $information ) && isset( $information['other_data'] ) ) { // ok.
                $output_array = $information['other_data']; // updated_data: plugins,themes,trans.
                mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions( $site, 'updated', $output_array, 'plugin' );
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

            if ( is_array( $information ) && isset( $information['other_data'] ) ) { // ok.
                $output_array = $information['other_data']; // updated_data: plugins,themes,trans.
                mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions( $site, 'updated', $output_array, 'theme' );
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
                        $old_version = $update_map[ $slug ]['current_version'];
                        $new_version = $update_map[ $slug ]['new_version'];
                        // Match the plan builder: the Child's `version` is the translation's target.
                        if ( '' === (string) $new_version ) {
                            $new_version = $old_version;
                            $old_version = '';
                        }
                        $updated[] = array(
                            'site_id'     => (int) $site->id,
                            'site_url'    => $site->url,
                            'site_name'   => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
                            'type'        => 'translation',
                            'slug'        => $slug,
                            'name'        => $update_map[ $slug ]['name'],
                            'old_version' => $old_version,
                            'new_version' => $new_version,
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

            if ( is_array( $information ) && isset( $information['other_data'] ) ) { // ok.
                $output_array = $information['other_data']; // updated_data: plugins,themes,trans.
                mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions( $site, 'updated', $output_array, 'trans' );
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
