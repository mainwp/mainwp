<?php
/**
 * MainWP Assistant Policies.
 *
 * Implements a three-tier access control system for the AI Assistant:
 *
 * ## Access Modes
 *
 * 1. **Read-Only Mode** (`MODE_READ_ONLY`)
 *    - Only allows abilities with `readonly: true` annotation
 *    - Suitable for monitoring and reporting use cases
 *    - Example: list-sites, get-site, count-clients
 *
 * 2. **Read-Write-Confirm Mode** (`MODE_READ_WRITE_CONFIRM`)
 *    - Allows read-only + non-destructive write operations
 *    - Requires confirmation for protected sites
 *    - Example: add-site, update-client, sync-sites
 *
 * 3. **Full Access Mode** (`MODE_FULL_ACCESS`)
 *    - Allows all abilities
 *    - Still subject to protected tags confirmation
 *    - Example: delete-site, disconnect-site, suspend-client
 *
 * ## Protected Tags
 *
 * Tags (groups) can be marked as "protected" per user. Operations on sites
 * with protected tags require explicit confirmation, regardless of access mode.
 *
 * ## Integration Points
 *
 * - Hooks into `mainwp_check_site_access` filter for site-level ACL
 * - Provides `check_policy_allows()` for ability execution validation
 * - Uses user meta for per-user settings
 * - Uses WordPress options for global settings
 *
 * @package MainWP/Dashboard
 * @since   6.0.0
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Assistant_Policies
 *
 * Handles access control policies for the AI Assistant feature.
 *
 * @since 6.0.0
 */
class MainWP_Assistant_Policies { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Access mode: Read-only (list, get, count operations only).
     *
     * @since 6.0.0
     * @var string
     */
    const MODE_READ_ONLY = 'read-only';

    /**
     * Access mode: Read-write with confirmation for destructive operations.
     *
     * @since 6.0.0
     * @var string
     */
    const MODE_READ_WRITE_CONFIRM = 'read-write-confirm';

    /**
     * Access mode: Full access to all abilities.
     *
     * @since 6.0.0
     * @var string
     */
    const MODE_FULL_ACCESS = 'full-access';

    /**
     * User meta key for access mode setting.
     *
     * @since 6.0.0
     * @var string
     */
    const USER_META_ACCESS_MODE = 'mainwp_assistant_access_mode';

    /**
     * User meta key for protected tags setting.
     *
     * @since 6.0.0
     * @var string
     */
    const USER_META_PROTECTED_TAGS = 'mainwp_assistant_protected_tags';

    /**
     * Confirmation level: Confirm all non-readonly mutations.
     *
     * @since 6.0.0
     * @var string
     */
    const CONFIRM_ALL = 'all';

    /**
     * Confirmation level: Confirm only destructive mutations.
     *
     * @since 6.0.0
     * @var string
     */
    const CONFIRM_DESTRUCTIVE = 'destructive_only';

    /**
     * User meta key for confirmation level setting.
     *
     * @since 6.0.0
     * @var string
     */
    const USER_META_CONFIRMATION_LEVEL = 'mainwp_assistant_confirmation_level';

    /**
     * Option key for global assistant enable/disable.
     *
     * @since 6.0.0
     * @var string
     */
    const OPTION_ASSISTANT_ENABLED = 'mainwp_assistant_enabled';

    /**
     * Option key for the site-wide daily AI spend limit in USD.
     *
     * @since 6.0.0
     * @var string
     */
    const OPTION_DAILY_BUDGET_USD = 'mainwp_assistant_daily_budget_usd';

    /**
     * Default site-wide daily AI spend limit in USD.
     *
     * Deliberately conservative; the cap ships on by default and admins can
     * raise it or set 0 for unlimited in Assistant settings.
     *
     * @since 6.0.0
     * @var float
     */
    const DEFAULT_DAILY_BUDGET_USD = 2.00;

    /**
     * Option key for the per-user daily AI spend limit in USD.
     *
     * Applies to every user individually, alongside the site-wide cap, so a
     * single user cannot exhaust the shared budget. Ships off (0), since there is
     * no legacy behavior to preserve.
     *
     * @since 6.0.0
     * @var string
     */
    const OPTION_USER_DAILY_BUDGET_USD = 'mainwp_assistant_user_daily_budget_usd';

    /**
     * Option key for assistant features consent.
     *
     * @since 6.0.0
     * @var string
     */
    const OPTION_CONSENT = 'mainwp_assistant_consent';

    /**
     * Option key for consent version tracking.
     *
     * @since 6.0.0
     * @var string
     */
    const OPTION_CONSENT_VERSION = 'mainwp_assistant_consent_version';

    /**
     * Current consent version. Bump to re-prompt consent after policy changes.
     *
     * @since 6.0.0
     * @var int
     */
    const CURRENT_CONSENT_VERSION = 1;

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     * @var MainWP_Assistant_Policies|null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create or return the single instance of the class.
     *
     * @static
     * @return MainWP_Assistant_Policies The single instance of the class.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Initializes the policy system and registers filters.
     *
     * @since 6.0.0
     */
    public function __construct() {
        add_filter( 'mainwp_check_site_access', array( $this, 'filter_site_access' ), 10, 3 );

        add_filter( 'mainwp_assistant_filter_abilities', array( $this, 'filter_abilities_by_mode' ), 10, 2 );
    }

    /**
     * Initialize AJAX handlers.
     *
     * Called from MainWP_System when assistant is enabled.
     *
     * @since 6.0.0
     *
     * @return void
     */
    public function init() {
        // Note: Access mode and protected tags handlers are in Page_MainWP_Assistant_Settings
        // to consolidate all settings page AJAX handling with consistent nonce verification.
        add_action( 'wp_ajax_mainwp_assistant_toggle', array( $this, 'ajax_toggle_assistant' ) );
    }

    // =========================================================================
    // ACCESS MODE MANAGEMENT
    // =========================================================================

    /**
     * Get the access mode for a user.
     *
     * @since 6.0.0
     *
     * @param int|null $user_id User ID. Defaults to current user.
     * @return string Access mode constant (MODE_READ_ONLY, MODE_READ_WRITE_CONFIRM, or MODE_FULL_ACCESS).
     */
    public function get_access_mode( $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $mode = get_user_meta( absint( $user_id ), self::USER_META_ACCESS_MODE, true );

        // Default to read-only if not set or invalid.
        if ( ! $this->validate_access_mode( $mode ) ) {
            return self::MODE_READ_ONLY;
        }

        return $mode;
    }

    /**
     * Set the access mode for a user.
     *
     * @since 6.0.0
     *
     * @param string   $mode    Access mode (one of the MODE_* constants).
     * @param int|null $user_id User ID. Defaults to current user.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function set_access_mode( $mode, $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $permission_check = $this->validate_policy_settings_user( $user_id );
        if ( is_wp_error( $permission_check ) ) {
            return $permission_check;
        }

        if ( ! $this->validate_access_mode( $mode ) ) {
            return new \WP_Error(
                'mainwp_assistant_invalid_mode',
                __( 'Invalid access mode. Must be one of: read-only, read-write-confirm, full-access.', 'mainwp' )
            );
        }

        $result = update_user_meta( absint( $user_id ), self::USER_META_ACCESS_MODE, $mode );

        if ( false !== $result ) {
            return true;
        }

        return get_user_meta( absint( $user_id ), self::USER_META_ACCESS_MODE, true ) === $mode;
    }

    /**
     * Validate that a mode string is one of the valid access modes.
     *
     * @since 6.0.0
     *
     * @param string $mode The mode to validate.
     * @return bool True if valid, false otherwise.
     */
    public function validate_access_mode( $mode ) {
        return in_array(
            $mode,
            array(
                self::MODE_READ_ONLY,
                self::MODE_READ_WRITE_CONFIRM,
                self::MODE_FULL_ACCESS,
            ),
            true
        );
    }

    /**
     * Get all valid access modes with labels.
     *
     * @since 6.0.0
     *
     * @return array Array of mode => label pairs.
     */
    public function get_access_modes() {
        return array(
            self::MODE_READ_ONLY          => __( 'Read Only', 'mainwp' ),
            self::MODE_READ_WRITE_CONFIRM => __( 'Read & Write (with confirmation)', 'mainwp' ),
            self::MODE_FULL_ACCESS        => __( 'Full Access', 'mainwp' ),
        );
    }

    // =========================================================================
    // CONFIRMATION LEVEL MANAGEMENT
    // =========================================================================

    /**
     * Get the confirmation level for a user.
     *
     * @since 6.0.0
     *
     * @param int|null $user_id User ID. Defaults to current user.
     * @return string Confirmation level constant (CONFIRM_ALL or CONFIRM_DESTRUCTIVE).
     */
    public function get_confirmation_level( $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $level = get_user_meta( absint( $user_id ), self::USER_META_CONFIRMATION_LEVEL, true );

        // Default to 'all' if not set or invalid.
        if ( ! $this->validate_confirmation_level( $level ) ) {
            return self::CONFIRM_ALL;
        }

        return $level;
    }

    /**
     * Set the confirmation level for a user.
     *
     * @since 6.0.0
     *
     * @param string   $level   Confirmation level (one of the CONFIRM_* constants).
     * @param int|null $user_id User ID. Defaults to current user.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function set_confirmation_level( $level, $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $permission_check = $this->validate_policy_settings_user( $user_id );
        if ( is_wp_error( $permission_check ) ) {
            return $permission_check;
        }

        if ( ! $this->validate_confirmation_level( $level ) ) {
            return new \WP_Error(
                'mainwp_assistant_invalid_confirmation_level',
                __( 'Invalid confirmation level. Must be one of: all, destructive_only.', 'mainwp' )
            );
        }

        $result = update_user_meta( absint( $user_id ), self::USER_META_CONFIRMATION_LEVEL, $level );

        if ( false !== $result ) {
            return true;
        }

        return get_user_meta( absint( $user_id ), self::USER_META_CONFIRMATION_LEVEL, true ) === $level;
    }

    /**
     * Validate that a confirmation level string is valid.
     *
     * @since 6.0.0
     *
     * @param string $level The level to validate.
     * @return bool True if valid, false otherwise.
     */
    public function validate_confirmation_level( $level ) {
        return in_array(
            $level,
            array(
                self::CONFIRM_ALL,
                self::CONFIRM_DESTRUCTIVE,
            ),
            true
        );
    }

    /**
     * Get all valid confirmation levels with labels.
     *
     * @since 6.0.0
     *
     * @return array Array of level => label pairs.
     */
    public function get_confirmation_levels() {
        return array(
            self::CONFIRM_ALL         => __( 'Confirm all changes', 'mainwp' ),
            self::CONFIRM_DESTRUCTIVE => __( 'Destructive actions only', 'mainwp' ),
        );
    }

    // =========================================================================
    // PROTECTED TAGS MANAGEMENT
    // =========================================================================

    /**
     * Get the protected tags for a user.
     *
     * @since 6.0.0
     *
     * @param int|null $user_id User ID. Defaults to current user.
     * @return array Array of protected tag IDs.
     */
    public function get_protected_tags( $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $tags = get_user_meta( absint( $user_id ), self::USER_META_PROTECTED_TAGS, true );

        if ( ! is_array( $tags ) ) {
            return array();
        }

        return array_map( 'absint', $tags );
    }

    /**
     * Set the protected tags for a user.
     *
     * @since 6.0.0
     *
     * Returns a bare false on a permission denial, where the sibling setters return a
     * WP_Error. Callers that need the reason must check permissions themselves.
     *
     * @param array    $tag_ids Array of tag IDs to protect.
     * @param int|null $user_id User ID. Defaults to current user.
     * @return bool True on success, false on failure.
     */
    public function set_protected_tags( $tag_ids, $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $permission_check = $this->validate_policy_settings_user( $user_id );
        if ( is_wp_error( $permission_check ) ) {
            return false;
        }

        if ( ! is_array( $tag_ids ) ) {
            $tag_ids = array();
        }

        $tag_ids = array_values( array_unique( array_filter( array_map( 'absint', $tag_ids ) ) ) );
        sort( $tag_ids );

        // Normalize existing tags the same way for comparison.
        $existing = get_user_meta( absint( $user_id ), self::USER_META_PROTECTED_TAGS, true );
        if ( is_array( $existing ) ) {
            $existing = array_values( array_unique( array_filter( array_map( 'absint', $existing ) ) ) );
            sort( $existing );
        } else {
            $existing = array();
        }

        // update_user_meta() returns false for an unchanged value, so report success here.
        if ( $tag_ids === $existing ) {
            return true;
        }

        $result = update_user_meta( absint( $user_id ), self::USER_META_PROTECTED_TAGS, $tag_ids );

        return false !== $result;
    }

    /**
     * Validate that the current user can update assistant policy settings for the target user.
     *
     * Users may update their own policy settings. Updating another user's assistant
     * policy settings requires the standard WordPress user-edit capability check.
     *
     * @param int $user_id Target user ID.
     *
     * @return true|\WP_Error True when allowed, WP_Error when denied.
     */
    private function validate_policy_settings_user( $user_id ) {
        $user_id         = absint( $user_id );
        $current_user_id = get_current_user_id();

        if ( ! $user_id || ! $current_user_id ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'Permission denied.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        if ( $current_user_id !== $user_id && ! current_user_can( 'edit_user', $user_id ) ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'You cannot change assistant settings for this user.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Add a single tag to the protected tags list.
     *
     * @since 6.0.0
     *
     * @param int      $tag_id  Tag ID to add.
     * @param int|null $user_id User ID. Defaults to current user.
     * @return bool True on success, false on failure.
     */
    public function add_protected_tag( $tag_id, $user_id = null ) {
        $tag_id       = absint( $tag_id );
        $current_tags = $this->get_protected_tags( $user_id );

        if ( in_array( $tag_id, $current_tags, true ) ) {
            return true; // Already protected.
        }

        $current_tags[] = $tag_id;
        return $this->set_protected_tags( $current_tags, $user_id );
    }

    /**
     * Remove a single tag from the protected tags list.
     *
     * @since 6.0.0
     *
     * @param int      $tag_id  Tag ID to remove.
     * @param int|null $user_id User ID. Defaults to current user.
     * @return bool True on success, false on failure.
     */
    public function remove_protected_tag( $tag_id, $user_id = null ) {
        $tag_id       = absint( $tag_id );
        $current_tags = $this->get_protected_tags( $user_id );

        $key = array_search( $tag_id, $current_tags, true );
        if ( false === $key ) {
            return true; // Already not protected.
        }

        unset( $current_tags[ $key ] );
        return $this->set_protected_tags( array_values( $current_tags ), $user_id );
    }

    /**
     * Check if a tag is protected for a user.
     *
     * @since 6.0.0
     *
     * @param int      $tag_id  Tag ID to check.
     * @param int|null $user_id User ID. Defaults to current user.
     * @return bool True if protected, false otherwise.
     */
    public function is_tag_protected( $tag_id, $user_id = null ) {
        $tag_id        = absint( $tag_id );
        $protected_ids = $this->get_protected_tags( $user_id );

        return in_array( $tag_id, $protected_ids, true );
    }

    // =========================================================================
    // SITE-LEVEL ACCESS VALIDATION
    // =========================================================================

    /**
     * Check if a site has any protected tags.
     *
     * @since 6.0.0
     *
     * @param int      $site_id Site ID to check.
     * @param int|null $user_id User ID. Defaults to current user.
     * @return array Array of matching protected tag IDs (empty if none).
     */
    public function site_has_protected_tags( $site_id, $user_id = null ) {
        $site_id       = absint( $site_id );
        $protected_ids = $this->get_protected_tags( $user_id );

        if ( empty( $protected_ids ) ) {
            return array();
        }

        $site_groups = MainWP_DB_Common::instance()->get_groups_by_website_id( $site_id );

        if ( empty( $site_groups ) ) {
            return array();
        }

        $matching_protected = array();

        foreach ( $site_groups as $group ) {
            $group_id = isset( $group->id ) ? absint( $group->id ) : 0;
            if ( in_array( $group_id, $protected_ids, true ) ) {
                $matching_protected[] = $group_id;
            }
        }

        return $matching_protected;
    }

    /**
     * Get the names of protected tags for a site.
     *
     * @since 6.0.0
     *
     * @param int      $site_id Site ID to check.
     * @param int|null $user_id User ID. Defaults to current user.
     * @return array Array of protected tag names (empty if none).
     */
    public function get_site_protected_tag_names( $site_id, $user_id = null ) {
        $site_id           = absint( $site_id );
        $protected_tag_ids = $this->site_has_protected_tags( $site_id, $user_id );

        if ( empty( $protected_tag_ids ) ) {
            return array();
        }

        $tag_names = array();

        foreach ( $protected_tag_ids as $tag_id ) {
            $tag = MainWP_DB_Common::instance()->get_group_by_id( $tag_id );
            if ( $tag && isset( $tag->name ) ) {
                $tag_names[] = $tag->name;
            }
        }

        return $tag_names;
    }

    /**
     * Filter site access for assistant operations.
     *
     * Hooked into `mainwp_check_site_access` filter. Returns WP_Error if
     * the site has protected tags that require confirmation.
     *
     * @since 6.0.0
     *
     * @param bool  $can_access Current access status.
     * @param int   $site_id    Site ID being checked.
     * @param mixed $input      The ability input (unused; context read from static property
     *                          to avoid schema validation issues with additionalProperties: false).
     * @return bool|\WP_Error True if access allowed, WP_Error if confirmation required.
     */
    public function filter_site_access( $can_access, $site_id, $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $input required by filter signature.
        // If already denied, don't override.
        if ( ! $can_access || is_wp_error( $can_access ) ) {
            return $can_access;
        }

        // Check if this is an assistant context.
        // Read from static execution context (not input) to avoid schema validation issues
        // with abilities that have additionalProperties: false.
        $context = MainWP_Assistant::$execution_context;
        if ( ! is_array( $context ) || empty( $context['assistant_context'] ) ) {
            return $can_access;
        }

        // Check for protected tags.
        $protected_tag_ids = $this->site_has_protected_tags( $site_id );

        if ( empty( $protected_tag_ids ) ) {
            return $can_access;
        }

        // A protected site runs only when the caller passed explicit confirmation.
        $confirmed_sites = $context['confirmed_protected_sites'] ?? array();
        if ( ! empty( $confirmed_sites ) && is_array( $confirmed_sites ) ) {
            if ( in_array( absint( $site_id ), array_map( 'absint', $confirmed_sites ), true ) ) {
                return $can_access; // Confirmation provided.
            }
        }

        // Get tag names for the error message.
        $tag_names = $this->get_site_protected_tag_names( $site_id );

        return new \WP_Error(
            'mainwp_assistant_confirmation_required',
            sprintf(
                /* translators: %s: comma-separated list of tag names */
                __( 'This site has protected tags (%s) and requires explicit confirmation.', 'mainwp' ),
                implode( ', ', $tag_names )
            ),
            array(
                'status'              => 403,
                'site_id'             => $site_id,
                'protected_tag_ids'   => $protected_tag_ids,
                'protected_tag_names' => $tag_names,
            )
        );
    }

    // =========================================================================
    // ABILITY FILTERING BY ACCESS MODE
    // =========================================================================

    /**
     * Get the list of abilities allowed for a user's access mode.
     *
     * @since 6.0.0
     *
     * @param int|null $user_id User ID. Defaults to current user.
     * @return array Array of allowed ability IDs.
     */
    public function get_allowed_abilities( $user_id = null ) {
        $mode = $this->get_access_mode( $user_id );
        if ( self::MODE_FULL_ACCESS === $mode ) {
            return $this->get_all_registered_abilities();
        }
        $groups = $this->get_ability_groups();

        switch ( $mode ) {
            case self::MODE_READ_ONLY:
                return $groups['readonly'];

            case self::MODE_READ_WRITE_CONFIRM:
                // Preserve established actions; native schemas still require their preview and confirmation.
                return array_merge(
                    $groups['readonly'],
                    $groups['non_destructive'],
                    array(
                        'mainwp/run-updates-v1',
                        'mainwp/update-all-v1',
                        'mainwp/update-site-core-v1',
                        'mainwp/update-site-plugins-v1',
                        'mainwp/update-site-themes-v1',
                        'mainwp/update-site-translations-v1',
                        'mainwp/activate-site-plugins-v1',
                        'mainwp/deactivate-site-plugins-v1',
                        'mainwp/activate-site-theme-v1',
                    )
                );

            default:
                return $groups['readonly'];
        }
    }

    /**
     * Check if an ability is allowed for a user.
     *
     * @since 6.0.0
     *
     * @param string   $ability_id Ability ID to check.
     * @param int|null $user_id    User ID. Defaults to current user.
     * @return bool True if allowed, false otherwise.
     */
    public function is_ability_allowed( $ability_id, $user_id = null ) {
        $allowed = $this->get_allowed_abilities( $user_id );
        return in_array( $ability_id, $allowed, true );
    }

    /**
     * Filter abilities array to only those allowed for a user.
     *
     * Hooked into `mainwp_assistant_filter_abilities` filter.
     *
     * @since 6.0.0
     *
     * @param array    $abilities Array of ability IDs.
     * @param int|null $user_id   User ID. Defaults to current user.
     * @return array Filtered array of allowed ability IDs.
     */
    public function filter_abilities_by_mode( $abilities, $user_id = null ) {
        $allowed = $this->get_allowed_abilities( $user_id );
        return array_values( array_intersect( $abilities, $allowed ) );
    }

    // =========================================================================
    // POLICY VALIDATION
    // =========================================================================

    /**
     * Check if the policy allows an ability execution.
     *
     * The main gate called by MainWP_Assistant before executing any ability. Checks
     * run in order: the global enable flag, then access-mode ability filtering, then
     * site-level protected tags when a site_id was passed.
     *
     * @since 6.0.0
     *
     * @param string     $ability_id Ability ID to check.
     * @param int|null   $site_id    Site ID (optional, for site-specific operations).
     * @param int|null   $user_id    User ID. Defaults to current user.
     * @param array|null $input      Optional. Ability input array. Used to check for
     *                               'confirmed_protected_sites' to bypass protected tag denial.
     * @return true|\WP_Error True if allowed, WP_Error with specific code on failure.
     */
    public function check_policy_allows( $ability_id, $site_id = null, $user_id = null, $input = null ) {
        if ( ! $this->is_assistant_enabled() ) {
            return new \WP_Error(
                'mainwp_assistant_disabled',
                __( 'The AI Assistant is disabled.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        if ( ! $this->is_ability_allowed( $ability_id, $user_id ) ) {
            $mode = $this->get_access_mode( $user_id );
            return new \WP_Error(
                'mainwp_assistant_ability_not_allowed',
                sprintf(
                    /* translators: 1: ability ID, 2: access mode */
                    __( 'The ability "%1$s" is not allowed in your current access mode (%2$s).', 'mainwp' ),
                    $ability_id,
                    $mode
                ),
                array(
                    'status'      => 403,
                    'ability_id'  => $ability_id,
                    'access_mode' => $mode,
                )
            );
        }

        if ( null !== $site_id ) {
            $site_id           = absint( $site_id );
            $protected_tag_ids = $this->site_has_protected_tags( $site_id, $user_id );

            if ( ! empty( $protected_tag_ids ) ) {
                $confirmed_sites = array();
                if ( is_array( $input ) && ! empty( $input['confirmed_protected_sites'] ) ) {
                    $confirmed_sites = array_map( 'absint', (array) $input['confirmed_protected_sites'] );
                }

                // Skip denial if site has been explicitly confirmed.
                if ( ! in_array( $site_id, $confirmed_sites, true ) ) {
                    $tag_names = $this->get_site_protected_tag_names( $site_id, $user_id );

                    return new \WP_Error(
                        'mainwp_assistant_confirmation_required',
                        sprintf(
                            /* translators: %s: comma-separated list of tag names */
                            __( 'This site has protected tags (%s) and requires explicit confirmation.', 'mainwp' ),
                            implode( ', ', $tag_names )
                        ),
                        array(
                            'status'              => 403,
                            'site_id'             => $site_id,
                            'protected_tag_ids'   => $protected_tag_ids,
                            'protected_tag_names' => $tag_names,
                        )
                    );
                }
            }
        }

        return true;
    }

    /**
     * Check if an ability requires confirmation (destructive operations).
     *
     * @since 6.0.0
     *
     * @param string $ability_id Ability ID to check.
     * @return bool True if confirmation required, false otherwise.
     */
    public function requires_confirmation( $ability_id ) {
        $destructive_abilities = $this->get_destructive_abilities();
        return in_array( $ability_id, $destructive_abilities, true );
    }

    // =========================================================================
    // GLOBAL ASSISTANT TOGGLE
    // =========================================================================

    /**
     * Check if the assistant is globally enabled.
     *
     * @since 6.0.0
     *
     * @return bool True if enabled, false if disabled.
     */
    public function is_assistant_enabled() {
        return MainWP_System::is_assistant_enabled();
    }

    /**
     * Set the global assistant enabled state.
     *
     * Only administrators can change this setting.
     *
     * @since 6.0.0
     *
     * @param bool $enabled Whether to enable the assistant.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function set_assistant_enabled( $enabled ) {
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'Only administrators with dashboard settings access can change this setting.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        $enabled = (bool) $enabled;
        $this->persist_availability_flags( $enabled );

        $assistant_enabled = (bool) get_option( self::OPTION_ASSISTANT_ENABLED, false );
        $guided_chatbase   = (int) get_option( 'mainwp_enable_guided_chatbase', 0 );

        if ( $assistant_enabled !== $enabled || $guided_chatbase !== (int) $enabled ) {
            return new \WP_Error(
                'mainwp_assistant_toggle_failed',
                __( 'Could not save the assistant availability setting.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        return true;
    }

    /**
     * Persist the paired assistant availability flags.
     *
     * The modern flag and the legacy chatbase flag must always agree, so this
     * is the single runtime write path for both, guarded by a drift test in
     * tests/assistant/test-assistant-policies.php. Install seeding in
     * class-mainwp-install.php reconciles the legacy flag on upgrade and
     * intentionally stays separate.
     *
     * @since 6.0.0
     *
     * @param bool $enabled Whether the assistant is enabled.
     * @return void
     */
    private function persist_availability_flags( $enabled ) {
        $enabled = (bool) $enabled;
        update_option( self::OPTION_ASSISTANT_ENABLED, $enabled );
        update_option( 'mainwp_enable_guided_chatbase', (int) $enabled );
    }

    // =========================================================================
    // DAILY SPEND LIMIT
    // =========================================================================

    /**
     * Get the site-wide daily AI spend limit in USD.
     *
     * An absent option means the conservative default; an explicit 0 (or
     * empty value) means the admin opted out of the cap entirely.
     *
     * @since 6.0.0
     *
     * @return float Limit in USD; 0.0 means unlimited.
     */
    public function get_daily_budget_usd() {
        $value = get_option( self::OPTION_DAILY_BUDGET_USD, false );

        if ( false === $value ) {
            return self::DEFAULT_DAILY_BUDGET_USD;
        }

        if ( '' === $value ) {
            return 0.0;
        }

        if ( ! is_numeric( $value ) ) {
            // Corrupted value: fall back to the conservative default rather
            // than silently lifting the cap.
            return self::DEFAULT_DAILY_BUDGET_USD;
        }

        return max( 0.0, round( (float) $value, 2 ) );
    }

    /**
     * Set the site-wide daily AI spend limit in USD.
     *
     * Only administrators can change this setting. 0 disables the cap.
     *
     * @since 6.0.0
     *
     * @param mixed $value Limit in USD; 0 for unlimited.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function set_daily_budget_usd( $value ) {
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'Only administrators with dashboard settings access can change this setting.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        if ( ! is_numeric( $value ) || (float) $value < 0 ) {
            return new \WP_Error(
                'invalid_budget',
                __( 'The daily spend limit must be zero or a positive amount.', 'mainwp' )
            );
        }

        update_option( self::OPTION_DAILY_BUDGET_USD, (string) round( (float) $value, 2 ) );

        return true;
    }

    /**
     * Get the per-user daily AI spend limit in USD.
     *
     * Unlike the site-wide cap there is no legacy default to preserve, so
     * absent, empty, and unparseable values all mean the per-user cap is off.
     *
     * @since 6.0.0
     *
     * @return float Limit in USD per user; 0.0 means no per-user cap.
     */
    public function get_user_daily_budget_usd() {
        $value = get_option( self::OPTION_USER_DAILY_BUDGET_USD, false );

        if ( false === $value || '' === $value || ! is_numeric( $value ) ) {
            return 0.0;
        }

        return max( 0.0, round( (float) $value, 2 ) );
    }

    /**
     * Set the per-user daily AI spend limit in USD.
     *
     * Only administrators can change this setting. 0 disables the per-user cap.
     *
     * @since 6.0.0
     *
     * @param mixed $value Limit in USD; 0 to disable.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function set_user_daily_budget_usd( $value ) {
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'Only administrators with dashboard settings access can change this setting.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        if ( ! is_numeric( $value ) || (float) $value < 0 ) {
            return new \WP_Error(
                'invalid_budget',
                __( 'The per-user daily spend limit must be zero or a positive amount.', 'mainwp' )
            );
        }

        update_option( self::OPTION_USER_DAILY_BUDGET_USD, (string) round( (float) $value, 2 ) );

        return true;
    }

    // =========================================================================
    // CONSENT MANAGEMENT
    // =========================================================================

    /**
     * Check if assistant features consent has been granted.
     *
     * Returns true only when consent is explicitly granted AND the stored
     * consent version matches the current version. A version mismatch
     * (e.g., after a privacy policy update) requires re-consent.
     *
     * @since 6.0.0
     *
     * @return bool True if consent is active and current.
     */
    public function has_consent() {
        $granted = (bool) get_option( self::OPTION_CONSENT, false );
        if ( ! $granted ) {
            return false;
        }

        $version = (int) get_option( self::OPTION_CONSENT_VERSION, 0 );
        return self::CURRENT_CONSENT_VERSION === $version;
    }

    /**
     * Re-check the consent boundary before delivering or persisting a result.
     *
     * The enabled/consent flags are checked when an async job starts, but the
     * provider call and tool loop can run for minutes; an admin who revokes
     * consent (or disables the assistant) mid-flight must not have that job
     * write conversation history or complete. Busts the option cache first so
     * a revoke saved by another request is visible to this long-running one.
     *
     * @since 6.0.0
     *
     * @return \WP_Error|null WP_Error when the boundary was withdrawn mid-job, null otherwise.
     */
    public function consent_boundary_error() {
        $this->refresh_policy_cache();

        if ( ! $this->is_assistant_enabled() ) {
            return new \WP_Error( 'assistant_disabled', __( 'The AI Assistant is disabled.', 'mainwp' ) );
        }

        if ( ! $this->has_consent() ) {
            return new \WP_Error( 'no_consent', __( 'Assistant features require privacy consent.', 'mainwp' ) );
        }

        return null;
    }

    /**
     * Drop cached copies of the policy options so the next read hits the database.
     *
     * Long-running requests (the async chat worker, the SSE relay loop) re-check
     * consent/enabled mid-flight, but without a persistent object cache
     * get_option() serves the per-request memory cache, so a revoke saved by
     * another request would stay invisible for the rest of this one. Call this
     * immediately before any mid-request policy re-check.
     *
     * @since 6.0.0
     *
     * @return void
     */
    public function refresh_policy_cache() {
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );

        $policy_options = array(
            self::OPTION_ASSISTANT_ENABLED,
            self::OPTION_CONSENT,
            self::OPTION_CONSENT_VERSION,
            'mainwp_enable_guided_chatbase',
        );
        foreach ( $policy_options as $option ) {
            wp_cache_delete( $option, 'options' );
        }
    }

    /**
     * Grant consent for assistant features.
     *
     * Stores consent flag and version. Only administrators can grant consent
     * since it applies dashboard-wide.
     *
     * @since 6.0.0
     *
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function grant_consent() {
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'Only administrators can grant assistant consent.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        update_option( self::OPTION_CONSENT, true );
        update_option( self::OPTION_CONSENT_VERSION, self::CURRENT_CONSENT_VERSION );
        // Revoking disables the assistant alongside consent, so granting restores both.
        // The settings-page Enable button (and the panel's consent modal) is a true
        // inverse of Disable Assistant Features.
        $this->persist_availability_flags( true );

        return true;
    }

    /**
     * Revoke consent for assistant features.
     *
     * Clears the consent flag and disables the assistant. Conversation data is
     * left in place; it is purged separately through the Clear History action.
     * Only administrators can revoke consent.
     *
     * @since 6.0.0
     *
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function revoke_consent() {
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'Only administrators can revoke assistant consent.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        // In-flight chat jobs are not enumerated here: the async worker re-checks
        // these flags before persisting and the SSE loop re-checks them each
        // iteration, so flipping the options is what terminates running work.
        update_option( self::OPTION_CONSENT, false );
        $this->persist_availability_flags( false );

        // Clean up bridge credentials.
        MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', false );
        delete_option( 'mainwp_assistant_bridge_uuid' );
        delete_transient( 'mainwp_bridge_quota_remaining' );
        delete_transient( 'mainwp_bridge_register_backoff' );

        return true;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get all tags (groups) available for the current user.
     *
     * @since 6.0.0
     *
     * @return array Array of tag objects with 'id' and 'name' keys.
     */
    public function get_all_tags() {
        $groups = MainWP_DB_Common::instance()->get_groups_for_current_user();

        if ( empty( $groups ) ) {
            return array();
        }

        $tags = array();
        foreach ( $groups as $group ) {
            $tags[] = array(
                'id'   => isset( $group->id ) ? (int) $group->id : 0,
                'name' => isset( $group->name ) ? (string) $group->name : '',
            );
        }

        return $tags;
    }

    /**
     * Get all registered MainWP abilities from the abilities registry.
     *
     * Reads the WordPress Abilities API registry rather than a static list, so
     * extension abilities and later core abilities reach full-access mode without a
     * code change here.
     *
     * @since 6.0.0
     *
     * @return array Array of ability IDs (e.g., 'mainwp/list-sites-v1').
     */
    public function get_all_registered_abilities() {
        $abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
        return empty( $abilities ) ? $this->get_fallback_abilities() : array_keys( $abilities );
    }

    /**
     * Get fallback list of MainWP abilities when registry is unavailable.
     *
     * @since 6.0.0
     *
     * @return array Array of known MainWP ability IDs.
     */
    private function get_fallback_abilities() {
        $groups = $this->get_fallback_ability_groups();

        return array_values(
            array_unique(
                array_merge(
                    $groups['readonly'],
                    $groups['non_destructive'],
                    $groups['destructive']
                )
            )
        );
    }

    /**
     * Get the list of destructive abilities.
     *
     * @since 6.0.0
     *
     * @return array Array of ability IDs that are destructive.
     */
    public function get_destructive_abilities() {
        $groups = $this->get_ability_groups();

        return $groups['destructive'];
    }

    /**
     * Get ability groups used for policy filtering.
     *
     * Dynamically classifies registered abilities by annotations, with
     * a conservative fallback to the known hardcoded groups when annotations
     * are unavailable.
     *
     * @since 6.0.0
     *
     * @return array{readonly: array, non_destructive: array, destructive: array}
     */
    private function get_ability_groups() {
        $fallback = $this->get_fallback_ability_groups();

        if ( ! function_exists( 'wp_get_ability' ) ) {
            return $fallback;
        }

        $groups = array(
            'readonly'        => array(),
            'non_destructive' => array(),
            'destructive'     => array(),
        );

        foreach ( $this->get_all_registered_abilities() as $ability_id ) {
            $classification = $this->classify_ability( $ability_id, $fallback );
            if ( null !== $classification ) {
                $groups[ $classification ][] = $ability_id;
            }
        }

        return $groups;
    }

    /**
     * Classify an ability into a policy group.
     *
     * @since 6.0.0
     *
     * For mainwp/* names the hardcoded groups win over the registry annotations,
     * deliberately: an ability this file lists as destructive stays destructive even if
     * its own annotations say readonly. Abilities in other namespaces are classified by
     * their own annotations, and anything not marked readonly or non-destructive is
     * treated as destructive.
     *
     * @param string $ability_id Ability ID.
     * @param array  $fallback   Fallback ability groups.
     * @return string|null Group name or null when unknown.
     */
    private function classify_ability( $ability_id, $fallback ) {
        if ( 0 !== strpos( $ability_id, 'mainwp/' ) ) {
            $annotations = $this->get_raw_ability_annotations( $ability_id );
            if ( true === ( $annotations['readonly'] ?? null ) ) {
                return 'readonly';
            }

            return false === ( $annotations['destructive'] ?? null ) ? 'non_destructive' : 'destructive';
        }

        foreach ( $fallback as $group => $ability_ids ) {
            if ( in_array( $ability_id, $ability_ids, true ) ) {
                return $group;
            }
        }

        $annotations = $this->get_registered_ability_annotations( $ability_id );

        if ( is_array( $annotations ) ) {
            if ( ! empty( $annotations['readonly'] ) ) {
                return 'readonly';
            }

            return ! empty( $annotations['destructive'] )
                ? 'destructive'
                : 'non_destructive';
        }

        return null;
    }

    /**
     * Get fallback ability groups when registry metadata is unavailable.
     *
     * @since 6.0.0
     *
     * @return array{readonly: array, non_destructive: array, destructive: array}
     */
    private function get_fallback_ability_groups() {
        return array(
            'readonly'        => array(
                // Sites - read operations.
                'mainwp/list-sites-v1',
                'mainwp/get-site-v1',
                'mainwp/count-sites-v1',
                'mainwp/get-sites-basic-v1',
                'mainwp/get-site-plugins-v1',
                'mainwp/get-site-themes-v1',
                'mainwp/get-abandoned-plugins-v1',
                'mainwp/get-abandoned-themes-v1',
                'mainwp/get-site-security-v1',
                'mainwp/get-site-changes-v1',
                'mainwp/get-site-client-v1',
                'mainwp/get-site-costs-v1',
                // Clients - read operations.
                'mainwp/list-clients-v1',
                'mainwp/get-client-v1',
                'mainwp/count-clients-v1',
                'mainwp/get-client-sites-v1',
                'mainwp/count-client-sites-v1',
                'mainwp/get-client-costs-v1',
                // Tags - read operations.
                'mainwp/list-tags-v1',
                'mainwp/get-tag-v1',
                'mainwp/get-tag-sites-v1',
                'mainwp/get-tag-clients-v1',
                // Updates - read operations.
                'mainwp/list-updates-v1',
                'mainwp/list-ignored-updates-v1',
                'mainwp/get-site-updates-v1',
                // Batch - status checking.
                'mainwp/get-batch-job-status-v1',
                // Insights - read operations.
                'mainwp/query-activity-v1',
                'mainwp/get-activity-summary-v1',
                'mainwp/get-network-snapshot-v1',
                'mainwp/list-upcoming-renewals-v1',
                // Fleet - read operations.
                'mainwp/query-sites-segment-v1',
                'mainwp/get-fleet-versions-v1',
                // Monitoring - read operations.
                'mainwp/get-uptime-stats-v1',
                'mainwp/list-monitor-incidents-v1',
                // Backups - read operations.
                'mainwp/get-backup-status-v1',
                'mainwp/list-api-backups-providers-v1',
                'mainwp/list-api-backups-site-status-v1',
                'mainwp/get-knowledge-record-v1',
                'mainwp/get-site-knowledge-v1',
                'mainwp/get-client-knowledge-v1',
                'mainwp/list-knowledge-v1',
                'mainwp/list-backup-history-v1',
                'mainwp/list-backup-destinations-v1',
                'mainwp/get-backup-destination-v1',
                'mainwp/get-backup-settings-v1',
                'mainwp/list-backup-tasks-v1',
                'mainwp/get-backup-task-v1',
                'mainwp/get-backup-task-run-v1',
                'mainwp/get-site-backup-progress-v1',
                'mainwp/list-backup-snapshots-v1',
                'mainwp/get-backup-snapshot-v1',
                'mainwp/list-restore-jobs-v1',
                'mainwp/get-restore-job-v1',
            ),
            'non_destructive' => array(
                // Sites - write non-destructive.
                'mainwp/add-site-v1',
                'mainwp/update-site-v1',
                'mainwp/sync-sites-v1',
                'mainwp/check-sites-v1',
                'mainwp/check-site-v1',
                'mainwp/reconnect-sites-v1',
                'mainwp/reconnect-site-v1',
                // Plugins/Themes - non-destructive.
                // Clients - write non-destructive.
                'mainwp/add-client-v1',
                'mainwp/update-client-v1',
                // Tags - write non-destructive.
                'mainwp/add-tag-v1',
                'mainwp/update-tag-v1',
                // Updates - install (non-destructive).
                'mainwp/set-ignored-updates-v1',
                'mainwp/ignore-site-core-v1',
                'mainwp/ignore-site-plugins-v1',
                'mainwp/ignore-site-themes-v1',
                'mainwp/create-backup-task-v1',
                'mainwp/pause-backup-task-v1',
                'mainwp/resume-backup-task-v1',
                'mainwp/test-backup-destination-v1',
                'mainwp/set-default-backup-destination-v1',
                'mainwp/update-backup-destination-v1',
            ),
            'destructive'     => array(
                // Sites - destructive.
                'mainwp/delete-site-v1',
                'mainwp/disconnect-site-v1',
                'mainwp/disconnect-sites-v1',
                'mainwp/suspend-site-v1',
                'mainwp/suspend-sites-v1',
                'mainwp/unsuspend-site-v1',
                'mainwp/delete-site-plugins-v1',
                'mainwp/delete-site-themes-v1',
                'mainwp/delete-client-v1',
                'mainwp/suspend-client-v1',
                'mainwp/unsuspend-client-v1',
                'mainwp/delete-tag-v1',
                'mainwp/unignore-site-updates-v1',
                'mainwp/create-knowledge-record-v1',
                'mainwp/update-knowledge-record-v1',
                'mainwp/delete-knowledge-record-v1',
                'mainwp/run-site-backup-v1',
                'mainwp/run-backup-task-v1',
                'mainwp/cancel-site-backup-v1',
                'mainwp/reset-backup-task-run-v1',
                'mainwp/cleanup-site-backups-v1',
                'mainwp/delete-backup-snapshot-v1',
                'mainwp/update-backup-task-v1',
                'mainwp/delete-backup-task-v1',
                'mainwp/restore-site-snapshot-v1',
                'mainwp/cancel-restore-v1',
                'mainwp/rollback-restore-v1',
                'mainwp/delete-backup-destination-v1',
                'mainwp/update-backup-settings-v1',
                'mainwp/activate-site-plugins-v1',
                'mainwp/deactivate-site-plugins-v1',
                'mainwp/activate-site-theme-v1',
                'mainwp/run-updates-v1',
                'mainwp/update-site-core-v1',
                'mainwp/update-site-plugins-v1',
                'mainwp/update-site-themes-v1',
                'mainwp/update-site-translations-v1',
                'mainwp/update-all-v1',
            ),
        );
    }

    /**
     * Get registered ability annotations when the ability registry provides them.
     *
     * @since 6.0.0
     *
     * @param string $ability_id Ability ID.
     * @return array|null Null when annotations are unavailable.
     */
    private function get_registered_ability_annotations( $ability_id ) {
        $annotations = $this->get_raw_ability_annotations( $ability_id );
        if ( ! is_array( $annotations ) ) {
            return null;
        }

        return array(
            'readonly'    => ! empty( $annotations['readonly'] ),
            'destructive' => ! empty( $annotations['destructive'] ),
            'idempotent'  => ! empty( $annotations['idempotent'] ),
        );
    }

    /**
     * Read annotations without collapsing WordPress's unknown values to false.
     *
     * @param string $ability_id Ability ID.
     * @return array|null Registered annotations, or null when unavailable.
     */
    public function get_raw_ability_annotations( $ability_id ) {
        if ( ! function_exists( 'wp_get_ability' ) ) {
            return null;
        }

        $ability = wp_get_ability( $ability_id );

        if ( ! is_object( $ability ) ) {
            return null;
        }

        $meta = array();
        if ( is_callable( array( $ability, 'get_meta' ) ) ) {
            $meta = $ability->get_meta();
        } elseif ( isset( $ability->meta ) ) {
            $meta = $ability->meta;
        }

        if ( is_object( $meta ) ) {
            $meta = (array) $meta;
        }

        if ( ! is_array( $meta ) || ! isset( $meta['annotations'] ) ) {
            return null;
        }

        $annotations = $meta['annotations'];
        if ( is_object( $annotations ) ) {
            $annotations = (array) $annotations;
        }

        if ( ! is_array( $annotations ) ) {
            return null;
        }

        return $annotations;
    }

    /**
     * Whether an ability belongs to the established policy lists.
     *
     * @param string $ability_id Ability ID.
     * @return bool
     */
    public function is_listed_ability( $ability_id ) {
        return in_array( $ability_id, $this->get_fallback_abilities(), true );
    }

    /**
     * Get the annotations for an ability.
     *
     * @since 6.0.0
     *
     * @param string $ability_id Ability ID.
     * @return array Array with 'readonly', 'destructive', 'idempotent' flags.
     */
    public function get_ability_annotations( $ability_id ) {
        $defaults = array(
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        );

        $annotations = $this->get_registered_ability_annotations( $ability_id );

        return is_array( $annotations ) ? $annotations : $defaults;
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX handler: Toggle global assistant enable/disable.
     *
     * Expected POST data:
     * - security: Nonce (mainwp_assistant_toggle)
     * - enabled: Boolean (1/0 or true/false)
     *
     * @since 6.0.0
     *
     * @return void Outputs JSON response and exits.
     */
    public function ajax_toggle_assistant() {
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_toggle' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        // Re-checked in set_assistant_enabled(); this is the fail-fast copy.
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mainwp' ) ), 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $enabled = isset( $_POST['enabled'] ) && filter_var( wp_unslash( $_POST['enabled'] ), FILTER_VALIDATE_BOOLEAN );

        $result = $this->set_assistant_enabled( $enabled );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
        }

        wp_send_json_success( array( 'enabled' => $enabled ) );
    }

    /**
     * Render a "disabled state" placeholder when the assistant is globally disabled.
     *
     * @param string $context Rendering context: 'settings' includes a "Learn More" link,
     *                        'insights' uses alternative messaging. Default 'settings'.
     */
    public static function render_disabled_state( $context = 'settings' ) {
        $can_manage = current_user_can( 'manage_options' ) && \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' );
        $title      = esc_html__( 'AI Assistant Disabled', 'mainwp' );

        // The house placeholder helpers are used instead of `ui placeholder
        // segment`, which four bundled themes render at 2% opacity.
        if ( 'settings' === $context ) {
            MainWP_UI::render_empty_page_placeholder(
                $title,
                esc_html__( 'Assistant features are off for every user on this dashboard.', 'mainwp' ),
                '<i class="power off icon"></i>'
            );
        } else {
            MainWP_UI::render_empty_element_placeholder(
                $title,
                esc_html__( 'No new insights are recorded while the assistant is off.', 'mainwp' ),
                '<i class="power off icon"></i>'
            );
        }
        ?>
        <div class="ui center aligned basic segment">
            <?php if ( $can_manage ) : ?>
                <?php
                $enable_url = esc_url(
                    add_query_arg(
                        array(
                            'page' => 'AssistantSettings',
                            'tab'  => 'policies',
                        ),
                        admin_url( 'admin.php' )
                    )
                );
                ?>
                <a href="<?php echo $enable_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" class="ui green button"<?php echo 'settings' === $context ? ' id="mainwp-assistant-enable-btn"' : ''; ?>>
                    <i class="toggle on icon"></i>
                    <?php esc_html_e( 'Enable Assistant', 'mainwp' ); ?>
                </a>
            <?php else : ?>
                <p class="ui small grey text">
                    <?php esc_html_e( 'Ask your administrator to enable it.', 'mainwp' ); ?>
                </p>
            <?php endif; ?>
            <?php if ( 'settings' === $context ) : ?>
                <a href="<?php echo esc_url( 'https://docs.mainwp.com/dashboard/overview/mainwp-ai' ); ?>" target="_blank" rel="noopener" class="ui basic button">
                    <i class="external alternate icon"></i>
                    <?php esc_html_e( 'Learn More', 'mainwp' ); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the widget disabled/logs guard and return whether rendering should continue.
     *
     * When the assistant is disabled or the logs module is unavailable, this renders
     * the full widget skeleton (header, insights-card body, footer) with the guard
     * message in the body, so the widget keeps its shape on the Insights grid.
     *
     * @param string $title Widget title text (already translated).
     *
     * @return bool True if the widget should continue rendering, false if a guard message was output.
     */
    public static function render_widget_guard( $title ) {
        $assistant_enabled = MainWP_System::is_assistant_enabled();

        if ( $assistant_enabled && \mainwp_modules_is_enabled( 'logs' ) ) {
            return true;
        }
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php echo esc_html( $title ); ?>
            </h2>
        </div>
        <div class="mainwp-widget-insights-card mainwp-scrolly-overflow">
            <?php if ( ! $assistant_enabled ) : ?>
                <?php static::render_disabled_state( 'insights' ); ?>
            <?php else : ?>
                <div class="ui yellow message">
                    <div class="header"><?php esc_html_e( 'Logs Module Required', 'mainwp' ); ?></div>
                    <p><?php esc_html_e( 'Assistant Insights needs the Logs module. Enable it to see assistant usage metrics.', 'mainwp' ); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <div class="mainwp-widget-footer ui four columns stackable grid">
            <div class="column"></div>
            <div class="column"></div>
        </div>
        <?php
        return false;
    }

    /**
     * Get the current user's policy settings summary.
     *
     * Useful for displaying in settings UI or debugging.
     *
     * @since 6.0.0
     *
     * @param int|null $user_id User ID. Defaults to current user.
     * @return array Policy settings array.
     */
    public function get_policy_summary( $user_id = null ) {
        return array(
            'assistant_enabled' => $this->is_assistant_enabled(),
            'access_mode'       => $this->get_access_mode( $user_id ),
            'protected_tags'    => $this->get_protected_tags( $user_id ),
            'allowed_abilities' => count( $this->get_allowed_abilities( $user_id ) ),
        );
    }
}
