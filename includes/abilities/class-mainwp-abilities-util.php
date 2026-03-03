<?php
/**
 * MainWP Abilities Utilities
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Util
 *
 * Shared utility functions for MainWP abilities.
 */
class MainWP_Abilities_Util { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Minimum child plugin version required for Abilities API operations.
     *
     * This constant centralizes the version requirement to ensure consistency
     * across all ability executors and their corresponding tests.
     *
     * When bumping this version:
     * 1. Update this constant
     * 2. Update tests that verify the version check behavior
     * 3. Consider backwards compatibility implications
     *
     * @since 5.4
     * @var string
     */
    const MIN_CHILD_VERSION_FOR_ABILITIES = '4.0.0';

    /**
     * Check if current request has REST API permission.
     *
     * Prioritizes REST API key authentication; falls back to session-based
     * authentication for legacy/admin use. This ensures compatibility with
     * MainWP's consumer key/secret authentication which doesn't require a session.
     *
     * Note: Invalid or missing API key errors (e.g., 'mainwp_rest_authentication_error')
     * are handled earlier in the REST authentication stack by MainWP_REST_Authentication
     * via the 'rest_authentication_errors' filter. By the time this permission callback
     * runs, invalid API requests have already been rejected. We only need to check if
     * a valid REST user exists and verify their capabilities.
     *
     * IMPORTANT: Returns true on success or WP_Error on failure.
     * This is required for abilities to surface proper error codes to consumers.
     *
     * @param mixed $_input The ability input (unused but required by signature).
     * @return true|\WP_Error True on success, WP_Error with code on failure.
     */
    public static function check_rest_api_permission( $_input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by callback signature NOSONAR .
        // Prioritize MainWP REST API key authentication.
        // Note: Key-specific errors (invalid key, invalid secret, bad signature) are
        // handled by MainWP_REST_Authentication::check_authentication_error() which
        // hooks into 'rest_authentication_errors' and blocks requests before this runs.
        if ( class_exists( '\MainWP_REST_Authentication' ) ) {
            $auth      = \MainWP_REST_Authentication::get_instance();
            $rest_user = $auth->get_rest_valid_user();

            // If REST auth has validated a user, trust that authentication.
            if ( ! empty( $rest_user ) ) {
                // REST API key is valid, check capability of the associated user.
                if ( ! current_user_can( 'manage_options' ) ) {
                    return new \WP_Error(
                        'mainwp_permission_denied',
                        __( 'API key user does not have sufficient permissions.', 'mainwp' ),
                        array( 'status' => 403 )
                    );
                }
                return true;
            }
        }

        // Fallback: session-based authentication for admin/legacy use.
        if ( ! is_user_logged_in() ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'You must be logged in to access this ability.', 'mainwp' ),
                array( 'status' => 401 )
            );
        }

        // Check for manage_options capability.
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error(
                'mainwp_permission_denied',
                __( 'You do not have permission to perform this action.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Check if current user can manage sites (write operations).
     *
     * Currently a wrapper over check_rest_api_permission() which already verifies
     * manage_options capability. This method exists as an extension point for future
     * granular permissions (e.g., a dedicated 'mainwp_manage_sites' capability).
     *
     * @param mixed $input The ability input (unused but required by signature).
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public static function check_manage_sites_permission( $input = null ) {
        $base = self::check_rest_api_permission( $input );
        if ( is_wp_error( $base ) ) {
            return $base;
        }

        // Future: Add granular 'mainwp_manage_sites' capability check here.
        // For now, manage_options is already verified in check_rest_api_permission().

        return true;
    }

    /**
     * Check if current user can access a specific site.
     *
     * This enforces per-site ACLs beyond the basic REST API permission check.
     * Use this for all site-specific operations (get, sync, update, plugins, themes).
     *
     * IMPORTANT: Returns true on success or WP_Error on failure.
     * For execute callbacks that need a boolean check, use the helper method below.
     *
     * @param int|object $site Site ID or site object.
     * @param mixed      $input The ability input (unused but required by signature).
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public static function check_site_access( $site, $input = null ) {
        // First, verify basic REST API permission.
        $base_check = self::check_rest_api_permission( $input );
        if ( is_wp_error( $base_check ) ) {
            return $base_check;
        }

        $site_id = is_object( $site ) ? (int) $site->id : (int) $site;

        // Use MainWP's existing per-site access control.
        if ( class_exists( 'MainWP_System' ) ) {
            $system = MainWP_System::instance();
            if ( method_exists( $system, 'check_site_access' ) && ! $system->check_site_access( $site_id ) ) {
                return new \WP_Error(
                    'mainwp_access_denied',
                    __( 'You do not have permission to access this site.', 'mainwp' ),
                    array( 'status' => 403 )
                );
            }
        }

        /**
         * Filters whether the current user can access a specific site.
         *
         * Allows plugins and tests to override or extend access control.
         *
         * @since 6.0.0
         *
         * @param bool       $can_access Whether access is allowed (default true).
         * @param int        $site_id    The site ID being checked.
         * @param mixed|null $input      The ability input, if available.
         */
        $can_access = apply_filters( 'mainwp_check_site_access', true, $site_id, $input );

        if ( ! $can_access ) {
            return new \WP_Error(
                'mainwp_access_denied',
                __( 'You do not have permission to access this site.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Check site access and return boolean.
     *
     * Convenience method for execute callbacks that need bool-style checks.
     * Use check_site_access() for permission_callback (returns WP_Error for proper error responses).
     *
     * @param int|object $site Site ID or site object.
     * @param mixed      $input The ability input.
     * @return bool True if access allowed, false otherwise.
     */
    public static function can_access_site( $site, $input = null ): bool {
        return true === self::check_site_access( $site, $input );
    }

    /**
     * Check if site's child plugin meets minimum version requirement.
     *
     * Use this in execute callbacks before performing operations that require
     * child plugin communication. Returns WP_Error with 'mainwp_child_outdated'
     * code if the child version is too low.
     *
     * @param object $site        Site object with version property.
     * @param string $min_version Minimum required version. Defaults to MIN_CHILD_VERSION_FOR_ABILITIES.
     *                            Pass null or omit to use the class constant.
     * @return true|\WP_Error True if version OK, WP_Error if outdated.
     */
    public static function check_child_version( $site, ?string $min_version = null ) {
        // Use class constant as default when null or not provided.
        if ( null === $min_version ) {
            $min_version = self::MIN_CHILD_VERSION_FOR_ABILITIES;
        }
        // Validate site parameter.
        if ( ! is_object( $site ) || ! isset( $site->id ) ) {
            return new \WP_Error(
                'mainwp_internal_error',
                __( 'Invalid site object provided.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $child_version = $site->version ?? '0.0.0';
        if ( version_compare( $child_version, $min_version, '<' ) ) {
            return new \WP_Error(
                'mainwp_child_outdated',
                sprintf(
                    /* translators: %s: minimum version */
                    __( 'Child plugin version %s or higher required.', 'mainwp' ),
                    $min_version
                ),
                array(
                    'status'  => 400,
                    'site_id' => (int) $site->id,
                )
            );
        }
        return true;
    }

    /**
     * Check site access for batch operations.
     *
     * Filters a list of site identifiers to only those the user can access.
     * Returns both accessible sites and access-denied errors.
     *
     * @param array $site_ids_or_domains Array of site IDs or URLs/domains.
     * @param mixed $input               The ability input (unused but required by signature).
     * @return array Array with 'allowed' (accessible sites) and 'denied' (access errors).
     */
    public static function check_batch_site_access( array $site_ids_or_domains, $input = null ): array {  // phpcs:ignore -- NOSONAR
        $allowed     = array();
        $denied      = array();
        $exclude_ids = ( is_array( $input ) && ! empty( $input['exclude_ids'] ) && is_array( $input['exclude_ids'] ) )
            ? array_map( 'absint', $input['exclude_ids'] )
            : array();
        $exclude_set = $exclude_ids ? array_fill_keys( $exclude_ids, true ) : array();

        foreach ( $site_ids_or_domains as $identifier ) {
            $site = self::resolve_site( $identifier );

            if ( is_wp_error( $site ) ) {
                $error_data = $site->get_error_data();
                $denied[]   = array(
                    'identifier' => $identifier,
                    'code'       => $site->get_error_code(),
                    'message'    => $site->get_error_message(),
                    'status'     => isset( $error_data['status'] ) ? $error_data['status'] : null,
                );
                continue;
            }

            $access_check = self::check_site_access( $site, $input );
            if ( is_wp_error( $access_check ) ) {
                $error_data = $access_check->get_error_data();
                $denied[]   = array(
                    'identifier' => $identifier,
                    'code'       => $access_check->get_error_code(),
                    'message'    => $access_check->get_error_message(),
                    'status'     => isset( $error_data['status'] ) ? $error_data['status'] : null,
                );
                continue;
            }

            $allowed[] = $site;
        }

        if ( ! empty( $exclude_set ) ) {
            $filtered = array();
            foreach ( $allowed as $entry ) {
                $id = null;
                if ( is_object( $entry ) && isset( $entry->id ) ) {
                    $id = (int) $entry->id;
                } elseif ( is_array( $entry ) && isset( $entry['id'] ) ) {
                    $id = (int) $entry['id'];
                } elseif ( is_numeric( $entry ) ) {
                    $id = (int) $entry;
                }

                if ( null !== $id && isset( $exclude_set[ $id ] ) ) {
                    continue;
                }

                $filtered[] = $entry;
            }
            $allowed = array_values( $filtered );
        }

        return array(
            'allowed' => $allowed,
            'denied'  => $denied,
        );
    }

    /**
     * Resolve a site identifier to a MainWP site object.
     *
     * Resolution order:
     * 1. If numeric → treat as MainWP site ID
     * 2. Otherwise → treat as URL/domain and resolve
     *
     * @param int|string $site_id_or_domain Site ID or URL/domain.
     * @return object|\WP_Error Site object on success, WP_Error on failure.
     */
    public static function resolve_site( $site_id_or_domain ) {
        if ( ! class_exists( 'MainWP_DB' ) ) {
            return new \WP_Error(
                'mainwp_internal_error',
                __( 'MainWP database class is not available.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $db = MainWP_DB::instance();

        // Numeric → try as site ID.
        if ( is_numeric( $site_id_or_domain ) ) {
            $site = $db->get_website_by_id( (int) $site_id_or_domain );
            if ( $site ) {
                return $site;
            }
            return new \WP_Error(
                'mainwp_site_not_found',
                sprintf(
                    /* translators: %d: site ID */
                    __( 'No site found with ID %d.', 'mainwp' ),
                    (int) $site_id_or_domain
                ),
                array( 'status' => 404 )
            );
        }

        // Non-numeric → treat as URL/domain.
        $url  = self::normalize_url( $site_id_or_domain );
        $site = $db->get_websites_by_url( $url );

        if ( $site && ! empty( $site ) ) {
            return is_array( $site ) ? $site[0] : $site;
        }

        return new \WP_Error(
            'mainwp_site_not_found',
            sprintf(
                /* translators: %s: domain or URL */
                __( 'No site found matching "%s".', 'mainwp' ),
                $site_id_or_domain
            ),
            array( 'status' => 404 )
        );
    }

    /**
     * Resolve a client identifier to a MainWP client object.
     *
     * Resolution order:
     * 1. If numeric → treat as client ID
     * 2. Otherwise → treat as email and resolve (exact match, returns first by ID)
     *
     * Note: Client emails are not enforced as unique in the database.
     * Email lookup uses exact match and returns the first matching client
     * ordered by client_id ASC (first created). For precise lookup,
     * use numeric client ID.
     *
     * @param int|string $client_id_or_email Client ID or email address.
     * @return object|\WP_Error Client object on success, WP_Error on failure.
     */
    public static function resolve_client( $client_id_or_email ) {
        if ( ! class_exists( 'MainWP\Dashboard\MainWP_DB_Client' ) ) {
            return new \WP_Error(
                'mainwp_internal_error',
                __( 'MainWP client database class is not available.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $db = MainWP_DB_Client::instance();

        // Numeric → try as client ID.
        if ( is_numeric( $client_id_or_email ) ) {
            $client = $db->get_wp_client_by( 'client_id', (int) $client_id_or_email );
            if ( $client ) {
                return $client;
            }
            return new \WP_Error(
                'mainwp_client_not_found',
                sprintf(
                    /* translators: %d: client ID */
                    __( 'No client found with ID %d.', 'mainwp' ),
                    (int) $client_id_or_email
                ),
                array( 'status' => 404 )
            );
        }

        // Non-numeric → treat as email.
        // Use exact match on email with deterministic ordering.
        global $wpdb;
        $table = $wpdb->prefix . 'mainwp_wp_clients';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter  -- Direct query needed for client lookup by email. Table name from $wpdb->prefix is safe.
        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE client_email = %s ORDER BY client_id ASC LIMIT 1",
                $client_id_or_email
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $client ) {
            return $client;
        }

        return new \WP_Error(
            'mainwp_client_not_found',
            sprintf(
                /* translators: %s: email address */
                __( 'No client found with email "%s".', 'mainwp' ),
                $client_id_or_email
            ),
            array( 'status' => 404 )
        );
    }

    /**
     * Resolve a tag identifier to a tag object.
     *
     * @param int $tag_id Tag ID.
     * @return object|\WP_Error Tag object on success, WP_Error on failure.
     */
    public static function resolve_tag( int $tag_id ) {
        if ( ! class_exists( 'MainWP\Dashboard\MainWP_DB_Common' ) ) {
            return new \WP_Error(
                'mainwp_internal_error',
                __( 'MainWP database class is not available.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        $tag = MainWP_DB_Common::instance()->get_group_by_id( $tag_id );

        if ( empty( $tag ) ) {
            return new \WP_Error(
                'mainwp_tag_not_found',
                sprintf(
                    /* translators: %d: tag ID */
                    __( 'No tag found with ID %d.', 'mainwp' ),
                    $tag_id
                ),
                array( 'status' => 404 )
            );
        }

        return $tag;
    }

    /**
     * Map a client object or array to the standard output format.
     *
     * @param object|array $client Client object or array from database.
     * @param array        $options Optional formatting options.
     *                              - 'include_sites' (bool): Include associated sites count.
     *                              - 'include_contacts' (bool): Include client contacts.
     * @return array Formatted client data.
     */
    public static function format_client_for_output( $client, array $options = array() ): array { // phpcs:ignore -- NOSONAR - complex method.
        // Convert array to object for consistent access.
        if ( is_array( $client ) ) {
            $client = (object) $client;
        }

        $output = array(
            'id'        => (int) $client->client_id,
            'name'      => (string) $client->name,
            'email'     => isset( $client->client_email ) ? (string) $client->client_email : '',
            'phone'     => isset( $client->client_phone ) ? (string) $client->client_phone : '',
            'address_1' => isset( $client->address_1 ) ? (string) $client->address_1 : '',
            'address_2' => isset( $client->address_2 ) ? (string) $client->address_2 : '',
            'city'      => isset( $client->city ) ? (string) $client->city : '',
            'state'     => isset( $client->state ) ? (string) $client->state : '',
            'zip'       => isset( $client->zip ) ? (string) $client->zip : '',
            'country'   => isset( $client->country ) ? (string) $client->country : '',
            'note'      => isset( $client->note ) ? (string) $client->note : '',
            'suspended' => isset( $client->suspended ) ? (int) $client->suspended : 0,
            'created'   => isset( $client->created ) && $client->created > 0
                ? gmdate( 'c', (int) $client->created )
                : null,
            'facebook'  => isset( $client->client_facebook ) ? (string) $client->client_facebook : '',
            'twitter'   => isset( $client->client_twitter ) ? (string) $client->client_twitter : '',
            'instagram' => isset( $client->client_instagram ) ? (string) $client->client_instagram : '',
            'linkedin'  => isset( $client->client_linkedin ) ? (string) $client->client_linkedin : '',
        );

        // Include sites count if requested.
        if ( ! empty( $options['include_sites'] ) ) {
            $sites                 = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client->client_id );
            $output['sites_count'] = is_array( $sites ) ? count( $sites ) : 0;
        }

        return $output;
    }

    /**
     * Map a cost tracker entry to the standard output format.
     *
     * @param object $cost Cost tracker object from database.
     * @return array Formatted cost data.
     */
    public static function format_cost_for_output( $cost ): array {
        return array(
            'id'             => (int) $cost->id,
            'name'           => (string) $cost->name,
            'type'           => isset( $cost->type ) ? (string) $cost->type : '',
            'price'          => isset( $cost->price ) ? (float) $cost->price : 0.0,
            'renewal_type'   => isset( $cost->renewal_type ) ? (string) $cost->renewal_type : '',
            'payment_method' => isset( $cost->payment_method ) ? (string) $cost->payment_method : '',
            'product_type'   => isset( $cost->product_type ) ? (string) $cost->product_type : '',
            'last_renewal'   => isset( $cost->last_renewal ) && $cost->last_renewal > 0
                ? gmdate( 'c', (int) $cost->last_renewal )
                : null,
            'next_renewal'   => isset( $cost->next_renewal ) && $cost->next_renewal > 0
                ? gmdate( 'c', (int) $cost->next_renewal )
                : null,
        );
    }

    /**
     * Format a tag object for ability output.
     *
     * Standardizes tag data structure for consistent API responses.
     *
     * @param object $tag     Tag object from MainWP_DB_Common::get_tags().
     * @param array  $options Optional. Formatting options:
     *                        - 'include_sites_ids' (bool): Include array of site IDs. Default true.
     * @return array Formatted tag data.
     */
    public static function format_tag_for_output( object $tag, array $options = array() ): array {
        $defaults = array(
            'include_sites_ids' => true,
        );
        $options  = array_merge( $defaults, $options );

        $formatted = array(
            'id'          => (int) $tag->id,
            'name'        => (string) $tag->name,
            'color'       => ! empty( $tag->color ) ? (string) $tag->color : null,
            'sites_count' => isset( $tag->count_sites ) ? (int) $tag->count_sites : 0,
        );

        if ( $options['include_sites_ids'] ) {
            // Always include sites_ids key to match output schema.
            $formatted['sites_ids'] = array();

            if ( isset( $tag->sites_ids ) ) {
                if ( is_string( $tag->sites_ids ) && ! empty( $tag->sites_ids ) ) {
                    $formatted['sites_ids'] = array_map( 'intval', explode( ',', $tag->sites_ids ) );
                } elseif ( is_array( $tag->sites_ids ) ) {
                    $formatted['sites_ids'] = array_map( 'intval', $tag->sites_ids );
                }
            }
        }

        return $formatted;
    }

    /**
     * Resolve multiple site identifiers.
     *
     * @param array $site_ids_or_domains Array of site IDs or URLs/domains.
     * @return array Array with 'sites' (resolved) and 'errors' (failed).
     */
    public static function resolve_sites( array $site_ids_or_domains ): array {
        $sites  = array();
        $errors = array();

        foreach ( $site_ids_or_domains as $identifier ) {
            $site = self::resolve_site( $identifier );
            if ( is_wp_error( $site ) ) {
                $error_data = $site->get_error_data();
                $errors[]   = array(
                    'identifier' => $identifier,
                    'code'       => $site->get_error_code(),
                    'message'    => $site->get_error_message(),
                    'status'     => isset( $error_data['status'] ) ? $error_data['status'] : null,
                );
            } else {
                $sites[] = $site;
            }
        }

        return array(
            'sites'  => $sites,
            'errors' => $errors,
        );
    }

    /**
     * Resolve multiple site identifiers for batch operations.
     *
     * Convenience method that returns only successfully resolved sites,
     * silently skipping any that fail to resolve. Use resolve_sites() if
     * you need both resolved sites and error details.
     *
     * @param array $site_ids_or_domains Array of site IDs or URLs/domains.
     * @return array Array of site objects (successfully resolved only).
     */
    public static function resolve_sites_batch( array $site_ids_or_domains ): array {
        $result = self::resolve_sites( $site_ids_or_domains );
        return $result['sites'];
    }

    /**
     * Normalize a URL for site lookup.
     *
     * IMPORTANT: This normalization is for site resolution only.
     *
     * Handles:
     * - Protocol stripping (https://, http://)
     * - www prefix removal
     * - Trailing slash enforcement
     *
     * LIMITATIONS (prefer site ID for these cases):
     * - Port numbers (example.com:8080) - not stripped, may fail to match
     * - Subdirectory multisites (example.com/site1 vs example.com/site2) - may collide
     * - URL-encoded characters - not decoded
     * - IDN/punycode domains - not normalized
     *
     * RECOMMENDATION: For ambiguous cases or programmatic access, always use
     * site ID instead of URL/domain.
     *
     * @param string $url URL to normalize.
     * @return string Normalized URL.
     */
    public static function normalize_url( string $url ): string {
        // Remove protocol and www prefix.
        $url = preg_replace( '#^https?://(www\.)?#i', '', $url );

        // Ensure trailing slash.
        $url = trailingslashit( $url );

        return $url;
    }

    /**
     * Normalize a site URL for storage and duplicate detection.
     *
     * Unlike normalize_url() which strips protocols for lookup, this method
     * preserves the URL scheme while normalizing for consistent storage:
     * - Lowercases the host component (domain names are case-insensitive per RFC 4343)
     * - Ensures trailing slash on the path
     * - Preserves the scheme (http/https)
     *
     * Use this before storing site URLs or checking for duplicates to ensure
     * URLs like "HTTPS://EXAMPLE.COM" and "https://example.com/" are treated
     * as equivalent.
     *
     * @param string $url URL to normalize.
     * @return string Normalized URL with lowercase host and trailing slash.
     */
    public static function normalize_site_url( string $url ): string {
        $parsed = wp_parse_url( $url );

        if ( empty( $parsed ) || empty( $parsed['host'] ) ) {
            // Invalid URL, return as-is with trailing slash.
            return trailingslashit( $url );
        }

        // Build normalized URL.
        $scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : 'https';
        $host   = strtolower( $parsed['host'] );
        $port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
        $path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';

        // Ensure path has trailing slash (but not double slashes).
        $path = trailingslashit( $path );

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Create a permission callback for site-specific abilities.
     *
     * The Abilities API passes $input to the permission_callback, but our
     * check_site_access() method expects a resolved site object. This wrapper
     * resolves the site from input first, then performs the access check.
     *
     * IMPORTANT: Use this for any ability that operates on a single site
     * identified by 'site_id_or_domain' in the input schema.
     *
     * @param string $input_key The input key containing the site identifier (default: 'site_id_or_domain').
     * @return callable Permission callback closure.
     */
    public static function make_site_permission_callback( string $input_key = 'site_id_or_domain' ): callable {
        return function ( $input ) use ( $input_key ) {
            // First check basic REST API permission.
            $base_check = self::check_rest_api_permission( $input );
            if ( is_wp_error( $base_check ) ) {
                return $base_check;
            }

            // Resolve the site from input.
            $identifier = $input[ $input_key ] ?? null;
            if ( null === $identifier ) {
                return new \WP_Error(
                    'mainwp_invalid_input',
                    __( 'Site identifier is required.', 'mainwp' ),
                    array( 'status' => 400 )
                );
            }

            $site = self::resolve_site( $identifier );
            if ( is_wp_error( $site ) ) {
                return $site;
            }

            // Check per-site access.
            return self::check_site_access( $site, $input );
        };
    }

    /**
     * Map a site object to the standard output format.
     *
     * @param object $site          Site object from database.
     * @param bool   $full_details  Whether to include full site details (default: false).
     * @param bool   $include_stats Whether to include site statistics (default: false).
     * @return array Formatted site data.
     */
    public static function format_site_for_output( $site, bool $full_details = false, bool $include_stats = false ): array {
        $output = array(
            'id'        => (int) $site->id,
            'url'       => (string) $site->url,
            'name'      => MainWP_Utility::remove_http_prefix( (string) $site->name, true ),
            'status'    => self::get_site_status( $site ),
            'client_id' => isset( $site->client_id ) && $site->client_id > 0
                ? (int) $site->client_id
                : null,
        );

        if ( $full_details ) {
            // Add extended site details for single-site retrieval.
            $output['admin_username'] = $site->adminname ?? '';

            // Get site_info from DB option (stored as JSON).
            // Pass full $site object to allow property check before DB query.
            $site_info_raw = MainWP_DB::instance()->get_website_option( $site, 'site_info' );
            $site_info     = ! empty( $site_info_raw ) ? json_decode( $site_info_raw, true ) : array();
            // Ensure $site_info is an array (json_decode returns null on invalid JSON).
            if ( ! is_array( $site_info ) ) {
                $site_info = array();
            }

            $output['wp_version']    = isset( $site_info['wpversion'] ) ? $site_info['wpversion'] : '';
            $output['php_version']   = isset( $site_info['phpversion'] ) ? $site_info['phpversion'] : '';
            $output['child_version'] = $site->version ?? '';

            // Format last_sync as ISO 8601 timestamp.
            $output['last_sync'] = ! empty( $site->dtsSync ) ? gmdate( 'c', (int) $site->dtsSync ) : null;

            $output['notes'] = $site->note ?? '';
        }

        // Include site statistics if requested.
        if ( $include_stats ) {
            $output['stats'] = self::get_site_stats( $site );
        }

        return $output;
    }

    /**
     * Get site statistics for include_stats option.
     *
     * @param object $site Site object from database.
     * @return array Site statistics array.
     */
    public static function get_site_stats( $site ): array {
        // Count plugin updates.
        $plugin_updates = ! empty( $site->plugin_upgrades ) ? json_decode( $site->plugin_upgrades, true ) : array();
        $plugin_count   = is_array( $plugin_updates ) ? count( $plugin_updates ) : 0;

        // Count theme updates.
        $theme_updates = ! empty( $site->theme_upgrades ) ? json_decode( $site->theme_upgrades, true ) : array();
        $theme_count   = is_array( $theme_updates ) ? count( $theme_updates ) : 0;

        // Check for WordPress core update.
        // Pass full $site object to allow property check before DB query.
        $wp_upgrades         = MainWP_DB::instance()->get_website_option( $site, 'wp_upgrades' );
        $wp_upgrades         = ! empty( $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();
        $wp_update_available = is_array( $wp_upgrades ) && ! empty( $wp_upgrades );

        // Get health score if available.
        $health_score = null;
        if ( isset( $site->health_value ) ) {
            $health_score = (int) $site->health_value;
        }

        return array(
            'plugin_updates'      => $plugin_count,
            'theme_updates'       => $theme_count,
            'wp_update_available' => $wp_update_available,
            'health_score'        => $health_score,
        );
    }

    /**
     * Get site connection status string.
     *
     * @param object $site Site object.
     * @return string Status string: 'connected', 'disconnected', or 'suspended'.
     */
    public static function get_site_status( $site ): string {
        // Check if site is suspended.
        if ( isset( $site->suspended ) && 1 === (int) $site->suspended ) {
            return 'suspended';
        }

        // Check offline status.
        if ( isset( $site->offline_check_result ) && -1 === (int) $site->offline_check_result ) {
            return 'disconnected';
        }

        // Check for sync errors.
        if ( isset( $site->sync_errors ) && ! empty( $site->sync_errors ) ) {
            return 'disconnected';
        }

        return 'connected';
    }

    /**
     * Queue a batch sync operation for background processing.
     *
     * Used when >200 sites need to be synced to avoid request timeouts.
     * Stores job data in a transient and schedules a cron event for processing.
     *
     * @param array $sites Array of site objects to sync.
     * @return string|\WP_Error Job ID for status polling, or WP_Error on failure.
     */
    public static function queue_batch_sync( array $sites ) {
        // Generate unique job ID.
        $job_id = 'sync_' . wp_generate_uuid4();

        // Extract site IDs from site objects.
        $site_ids = array_map(
            function ( $site ) {
                return (int) $site->id;
            },
            $sites
        );

        // Store job data in transient (expires in 24 hours).
        // Structure aligns with REST v2 job status endpoint expectations.
        // Status values: 'queued' -> 'processing' -> 'completed' | 'failed'.
        $job_data = array(
            'job_type'  => 'sync',                  // Distinguishes from 'update' jobs.
            'sites'     => $site_ids,
            'status'    => 'queued',                // queued | processing | completed | failed.
            'created'   => time(),
            'started'   => null,
            'completed' => null,
            'synced'    => array(),                 // Successfully synced sites.
            'errors'    => array(),                 // Failed syncs array.
            'progress'  => 0,                       // 0-100 percentage.
            'total'     => count( $site_ids ),      // Total sites to process.
            'processed' => 0,                       // Sites processed so far.
        );

        set_transient( 'mainwp_sync_job_' . $job_id, $job_data, DAY_IN_SECONDS );

        // Verify transient was stored successfully.
        $stored = get_transient( 'mainwp_sync_job_' . $job_id );
        if ( empty( $stored ) || ! is_array( $stored ) ) {
            return new \WP_Error(
                'mainwp_queue_failed',
                __( 'Failed to store sync job data. Please try again.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        // Only schedule if no matching event already exists.
        if ( ! wp_next_scheduled( 'mainwp_process_sync_job', array( $job_id ) ) ) {
            wp_schedule_single_event( time() + 60, 'mainwp_process_sync_job', array( $job_id ) );
        }

        return $job_id;
    }

    /**
     * Get batch sync job status.
     *
     * @param string $job_id Job ID to check.
     * @return array|null Job data array or null if not found.
     */
    public static function get_batch_sync_status( string $job_id ): ?array {
        $job_data = get_transient( 'mainwp_sync_job_' . $job_id );
        return is_array( $job_data ) ? $job_data : null;
    }

    /**
     * Queue a batch update operation for background processing.
     *
     * Used when >200 sites need updates to avoid request timeouts.
     * Stores job data in a transient and schedules a cron event for processing.
     *
     * @param array $sites         Array of site objects to update.
     * @param array $update_params Update parameters with keys: types (array), specific_items (array).
     * @return string|\WP_Error Job ID for status polling, or WP_Error on failure.
     */
    public static function queue_batch_updates( array $sites, array $update_params ) {
        // Generate unique job ID.
        $job_id = 'update_' . wp_generate_uuid4();

        // Extract site IDs from site objects.
        $site_ids = array_map(
            function ( $site ) {
                return (int) $site->id;
            },
            $sites
        );

        // Normalize and validate types parameter.
        $allowed_types = array( 'core', 'plugins', 'themes', 'translations' );
        $types         = isset( $update_params['types'] ) && is_array( $update_params['types'] )
            ? array_values( array_intersect( $update_params['types'], $allowed_types ) )
            : array();

        // Normalize specific_items to array of strings.
        $specific_items = array();
        if ( isset( $update_params['specific_items'] ) && is_array( $update_params['specific_items'] ) ) {
            foreach ( $update_params['specific_items'] as $item ) {
                if ( is_string( $item ) && '' !== $item ) {
                    $specific_items[] = $item;
                }
            }
        }

        // Store job data in transient (expires in 24 hours).
        // Structure aligns with REST v2 job status endpoint expectations.
        // Status values: 'queued' -> 'processing' -> 'completed' | 'failed'.
        $job_data = array(
            'job_type'       => 'update',            // Distinguishes from 'sync' jobs.
            'sites'          => $site_ids,
            'types'          => $types,
            'specific_items' => $specific_items,
            'status'         => 'queued',            // queued | processing | completed | failed.
            'created'        => time(),
            'started'        => null,
            'completed'      => null,
            'updated'        => array(),             // Successful updates array.
            'errors'         => array(),             // Failed updates array.
            'progress'       => 0,                   // 0-100 percentage.
            'total'          => count( $site_ids ),  // Total sites to process.
            'processed'      => 0,                   // Sites processed so far.
        );

        set_transient( 'mainwp_update_job_' . $job_id, $job_data, DAY_IN_SECONDS );

        // Verify transient was stored successfully.
        $stored = get_transient( 'mainwp_update_job_' . $job_id );
        if ( empty( $stored ) || ! is_array( $stored ) ) {
            return new \WP_Error(
                'mainwp_queue_failed',
                __( 'Failed to store update job data. Please try again.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        // Only schedule if no matching event already exists.
        if ( ! wp_next_scheduled( 'mainwp_process_update_job', array( $job_id ) ) ) {
            wp_schedule_single_event( time() + 60, 'mainwp_process_update_job', array( $job_id ) );
        }

        return $job_id;
    }

    /**
     * Get batch update job status.
     *
     * @param string $job_id Job ID to check.
     * @return array|null Job data array or null if not found.
     */
    public static function get_batch_update_status( string $job_id ): ?array {
        $job_data = get_transient( 'mainwp_update_job_' . $job_id );
        return is_array( $job_data ) ? $job_data : null;
    }

    /**
     * Queue a batch site operation for background processing.
     *
     * Used when >200 sites need to be processed for batch operations like
     * reconnect, disconnect, check, or suspend. Stores job data in a transient
     * and schedules a cron event for processing.
     *
     * @param string $operation_type Operation type: 'reconnect', 'disconnect', 'check', 'suspend'.
     * @param array  $sites          Array of site objects to process.
     * @return string|\WP_Error Job ID for status polling, or WP_Error on failure.
     */
    public static function queue_batch_operation( string $operation_type, array $sites ) {
        // Validate operation type.
        $allowed_operations = array( 'reconnect', 'disconnect', 'check', 'suspend' );
        if ( ! in_array( $operation_type, $allowed_operations, true ) ) {
            return new \WP_Error(
                'mainwp_invalid_operation',
                __( 'Invalid batch operation type.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        // Generate unique job ID.
        $job_id = $operation_type . '_' . wp_generate_uuid4();

        // Extract site IDs from site objects.
        $site_ids = array_map(
            function ( $site ) {
                return (int) $site->id;
            },
            $sites
        );

        // Store job data in transient (expires in 24 hours).
        // Status values: 'queued' -> 'processing' -> 'completed' | 'failed'.
        $job_data = array(
            'job_type'   => $operation_type,
            'sites'      => $site_ids,
            'status'     => 'queued',
            'created'    => time(),
            'started'    => null,
            'completed'  => null,
            'successful' => array(),  // Successfully processed sites.
            'errors'     => array(),  // Failed operations array.
            'progress'   => 0,        // 0-100 percentage.
            'total'      => count( $site_ids ),
            'processed'  => 0,        // Sites processed so far.
        );

        set_transient( 'mainwp_batch_job_' . $job_id, $job_data, DAY_IN_SECONDS );

        // Verify transient was stored successfully.
        $stored = get_transient( 'mainwp_batch_job_' . $job_id );
        if ( empty( $stored ) || ! is_array( $stored ) ) {
            return new \WP_Error(
                'mainwp_queue_failed',
                __( 'Failed to store batch job data. Please try again.', 'mainwp' ),
                array( 'status' => 500 )
            );
        }

        // Schedule cron event for processing.
        // Only schedule if no matching event already exists.
        if ( ! wp_next_scheduled( 'mainwp_process_batch_job', array( $job_id ) ) ) {
            wp_schedule_single_event( time() + 60, 'mainwp_process_batch_job', array( $job_id ) );
        }

        return $job_id;
    }

    /**
     * Get batch operation job status.
     *
     * @param string $job_id Job ID to check.
     * @return array|null Job data array or null if not found.
     */
    public static function get_batch_operation_status( string $job_id ): ?array {
        $job_data = get_transient( 'mainwp_batch_job_' . $job_id );
        return is_array( $job_data ) ? $job_data : null;
    }

    /**
     * Normalize ability input to ensure it's always an array.
     *
     * Workaround for Abilities API REST controller bug where missing or empty
     * input is passed as null instead of an empty array. The REST controller
     * fails JSON Schema validation before schema defaults can be applied.
     *
     * Use this at the start of execute callbacks as defensive coding:
     *
     * ```php
     * public static function execute_list_sites( $input ) {
     *     $input = MainWP_Abilities_Util::normalize_input( $input, [
     *         'page' => 1,
     *         'per_page' => 20,
     *     ] );
     *     // ... continue with normalized input
     * }
     * ```
     *
     * NOTE: This workaround only helps if the ability passes REST validation.
     * The bug causes validation to fail BEFORE the execute callback is called.
     * This helper is provided for defensive coding once the upstream bug is fixed.
     *
     * @see .mwpdev/debugging/abilities-api-input-investigation.md
     * @see .mwpdev/docs/abilities-api-docs/known-issues.md
     *
     * @param mixed $input    Raw input from ability execution (may be null, array, or object).
     * @param array $defaults Default values from input schema properties.
     * @return array Normalized input array with defaults applied.
     */
    public static function normalize_input( $input, array $defaults = array() ): array {
        // Convert objects to arrays.
        if ( is_object( $input ) ) {
            $input = (array) $input;
        }

        // If input is null, empty, or not an array, start with empty array.
        if ( ! is_array( $input ) ) {
            $input = array();
        }

        // Merge with defaults (input values take precedence over defaults).
        return array_merge( $defaults, $input );
    }

    /**
     * Get default values from an input schema.
     *
     * Extracts default values from a JSON Schema's properties definitions.
     * Useful when you need to programmatically obtain defaults without hardcoding.
     *
     * Example:
     * ```php
     * $schema = self::get_list_sites_input_schema();
     * $defaults = MainWP_Abilities_Util::get_schema_defaults( $schema );
     * $input = MainWP_Abilities_Util::normalize_input( $input, $defaults );
     * ```
     *
     * @param array $schema JSON Schema with 'properties' containing 'default' values.
     * @return array Associative array of property names to their default values.
     */
    public static function get_schema_defaults( array $schema ): array {
        $defaults = array();

        if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
            return $defaults;
        }

        foreach ( $schema['properties'] as $property_name => $property_schema ) {
            if ( isset( $property_schema['default'] ) ) {
                $defaults[ $property_name ] = $property_schema['default'];
            }
        }

        return $defaults;
    }

    /**
     * Format plugin data for ability output.
     *
     * Standardizes plugin data structure for activate/deactivate/delete operations.
     * Handles missing fields gracefully as plugins may not have all metadata.
     *
     * @param array|object $plugin Plugin data with keys: slug, Name/name, Version/version, active, update.
     * @return array Standardized plugin structure: { slug, name, version, active, has_update, update_version }.
     */
    public static function format_plugin_for_output( $plugin ): array { // phpcs:ignore -- NOSONAR -complexity.
        if ( is_object( $plugin ) ) {
            $plugin = (array) $plugin;
        }

        if ( ! is_array( $plugin ) ) {
            $plugin = array();
        }

        $slug = isset( $plugin['slug'] ) ? sanitize_text_field( $plugin['slug'] ) : '';

        // Support both capitalized (Name) and lowercase (name) keys.
        $name = '';
        if ( isset( $plugin['Name'] ) ) {
            $name = sanitize_text_field( $plugin['Name'] );
        } elseif ( isset( $plugin['name'] ) ) {
            $name = sanitize_text_field( $plugin['name'] );
        } else {
            $name = $slug;
        }

        // Support both capitalized (Version) and lowercase (version) keys.
        $version = '';
        if ( isset( $plugin['Version'] ) ) {
            $version = sanitize_text_field( $plugin['Version'] );
        } elseif ( isset( $plugin['version'] ) ) {
            $version = sanitize_text_field( $plugin['version'] );
        }

        $active = isset( $plugin['active'] ) ? (bool) $plugin['active'] : false;

        // Determine if update is available: either a non-empty array in 'update' key,
        // or a root-level 'new_version' key indicates an update is available.
        $has_update_array = isset( $plugin['update'] ) && is_array( $plugin['update'] ) && ! empty( $plugin['update'] );
        $has_root_version = isset( $plugin['new_version'] );
        $has_update       = $has_update_array || $has_root_version;

        // Extract update_version from various possible structures.
        // Guard nested access with is_array() to avoid warnings when 'update' is boolean/scalar.
        $update_version = null;
        if ( $has_update ) {
            if ( $has_update_array && isset( $plugin['update']['new_version'] ) ) {
                $update_version = sanitize_text_field( $plugin['update']['new_version'] );
            } elseif ( $has_root_version ) {
                $update_version = sanitize_text_field( $plugin['new_version'] );
            }
        }

        return array(
            'slug'           => $slug,
            'name'           => $name,
            'version'        => $version,
            'active'         => $active,
            'has_update'     => $has_update,
            'update_version' => $update_version,
        );
    }

    /**
     * Format theme data for ability output.
     *
     * Standardizes theme data structure for activate/delete operations.
     * Handles missing fields gracefully as themes may not have all metadata.
     *
     * @param array|object $theme Theme data with keys: slug, Name/name, Version/version, active, update.
     * @return array Standardized theme structure: { slug, name, version, active, has_update, update_version }.
     */
    public static function format_theme_for_output( $theme ): array { // phpcs:ignore -- NOSONAR -complexity.
        if ( is_object( $theme ) ) {
            $theme = (array) $theme;
        }

        if ( ! is_array( $theme ) ) {
            $theme = array();
        }

        $slug = isset( $theme['slug'] ) ? sanitize_text_field( $theme['slug'] ) : '';

        // Support both capitalized (Name) and lowercase (name) keys.
        $name = '';
        if ( isset( $theme['Name'] ) ) {
            $name = sanitize_text_field( $theme['Name'] );
        } elseif ( isset( $theme['name'] ) ) {
            $name = sanitize_text_field( $theme['name'] );
        } else {
            $name = $slug;
        }

        // Support both capitalized (Version) and lowercase (version) keys.
        $version = '';
        if ( isset( $theme['Version'] ) ) {
            $version = sanitize_text_field( $theme['Version'] );
        } elseif ( isset( $theme['version'] ) ) {
            $version = sanitize_text_field( $theme['version'] );
        }

        $active = isset( $theme['active'] ) ? (bool) $theme['active'] : false;

        // Determine if update is available: either a non-empty array in 'update' key,
        // or a root-level 'new_version' key indicates an update is available.
        $has_update_array = isset( $theme['update'] ) && is_array( $theme['update'] ) && ! empty( $theme['update'] );
        $has_root_version = isset( $theme['new_version'] );
        $has_update       = $has_update_array || $has_root_version;

        // Extract update_version from various possible structures.
        // Guard nested access with is_array() to avoid warnings when 'update' is boolean/scalar.
        $update_version = null;
        if ( $has_update ) {
            if ( $has_update_array && isset( $theme['update']['new_version'] ) ) {
                $update_version = sanitize_text_field( $theme['update']['new_version'] );
            } elseif ( $has_root_version ) {
                $update_version = sanitize_text_field( $theme['new_version'] );
            }
        }

        return array(
            'slug'           => $slug,
            'name'           => $name,
            'version'        => $version,
            'active'         => $active,
            'has_update'     => $has_update,
            'update_version' => $update_version,
        );
    }

    /**
     * Check if a child site response indicates success.
     *
     * Handles both the new format ({ success: true }) and legacy format ({ status: "SUCCESS" })
     * returned by MainWP Child plugin for plugin/theme actions.
     *
     * @since 5.4
     *
     * @param mixed $result The response from MainWP_Connect::fetch_url_authed().
     * @return bool True if the response indicates success, false otherwise.
     */
    public static function is_child_response_success( $result ): bool {
        if ( ! is_array( $result ) ) {
            return false;
        }

        // New format: { success: true }.
        if ( isset( $result['success'] ) && $result['success'] ) {
            return true;
        }

        // Legacy format: { status: "SUCCESS" }.
        if ( isset( $result['status'] ) && 'SUCCESS' === $result['status'] ) {
            return true;
        }

        return false;
    }
}
