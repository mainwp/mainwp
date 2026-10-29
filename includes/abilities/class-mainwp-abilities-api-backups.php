<?php
/**
 * Read stored provider configuration and site backup request dates.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Register API Backups reads without loading provider credentials. */
class MainWP_Abilities_Api_Backups {

    /** Register the two read-only abilities and their schemas. */
    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'mainwp_modules_is_enabled' ) || ! mainwp_modules_is_enabled( 'api-backups' ) ) {
            return;
        }
        $provider = array(
            'type' => 'string',
            'enum' => array_keys( self::providers() ),
        );
        $integer  = array(
            'type'    => 'integer',
            'minimum' => 0,
        );
        foreach ( array( 'providers', 'site_status' ) as $kind ) {
            $is_providers = 'providers' === $kind;
            wp_register_ability(
                $is_providers ? 'mainwp/list-api-backups-providers-v1' : 'mainwp/list-api-backups-site-status-v1',
                array(
                    'label'               => $is_providers ? __( 'List API Backups Providers', 'mainwp' ) : __( 'List API Backups Site Status', 'mainwp' ),
                    'description'         => $is_providers ? __( 'List providers with stored credentials, their enabled indicators and accessible site counts. Presence does not verify credentials. No credentials are read or decrypted, and no provider is contacted.', 'mainwp' ) : __( 'List accessible sites and their stored backup request dates. Completion status is unknown because this module does not consistently store completed backup outcomes. Does not contact providers or child sites.', 'mainwp' ),
                    'category'            => 'mainwp-backups',
                    'input_schema'        => $is_providers ? array(
                        'type'                 => array( 'object', 'null' ),
                        'additionalProperties' => false,
                    ) : array(
                        'type'                 => array( 'object', 'null' ),
                        'additionalProperties' => false,
                        'properties'           => array(
                            'provider' => $provider,
                            'per_page' => array(
                                'type'    => 'integer',
                                'minimum' => 1,
                                'maximum' => 100,
                                'default' => 20,
                            ),
                            'page'     => array(
                                'anyOf'   => array(
                                    array(
                                        'type'    => 'integer',
                                        'minimum' => 1,
                                    ),
                                    array(
                                        'type'    => 'string',
                                        'pattern' => '^[1-9][0-9]*$',
                                    ),
                                ),
                                'default' => 1,
                            ),
                        ),
                    ),
                    'output_schema'       => $is_providers ? self::object_schema(
                        array(
                            'providers' => array(
                                'type'  => 'array',
                                'items' => self::object_schema(
                                    array(
                                        'provider'   => $provider,
                                        'name'       => array( 'type' => 'string' ),
                                        'connected'  => array( 'type' => 'boolean' ),
                                        'site_count' => $integer,
                                    )
                                ),
                            ),
                        )
                    ) : self::object_schema(
                        array(
                            'sites'             => array(
                                'type'  => 'array',
                                'items' => self::site_schema(),
                            ),
                            'page'              => array(
                                'type'    => 'integer',
                                'minimum' => 1,
                            ),
                            'per_page'          => array(
                                'type'    => 'integer',
                                'minimum' => 1,
                                'maximum' => 100,
                            ),
                            'total'             => $integer,
                            'hidden_site_count' => $integer,
                        )
                    ),
                    'permission_callback' => array( __CLASS__, $is_providers ? 'check_providers_permission' : 'check_permission' ),
                    'execute_callback'    => array( __CLASS__, 'execute_' . $kind ),
                    'meta'                => array(
                        'show_in_rest' => true,
                        'annotations'  => array(
                            'readonly'    => true,
                            'destructive' => false,
                            'idempotent'  => true,
                        ),
                    ),
                )
            );
        }
    }

    /**
     * Require Dashboard administrator access and the API Backups settings right.
     *
     * @param mixed $input Ability input.
     * @return true|\WP_Error
     */
    public static function check_permission( $input = null ) {
        if ( ! is_callable( array( '\MainWP\Dashboard\MainWP_Abilities_Util', 'check_rest_api_permission' ) ) || ! is_callable( array( MainWP_Abilities_Util::class, 'current_user_has_operation_right' ) ) || ! function_exists( 'mainwp_current_user_can' ) ) {
            return self::denied();
        }
        $permission = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( $input );
        if ( true !== $permission ) {
            return self::denied();
        }
        if ( ! current_user_can( 'manage_options' ) || ! mainwp_current_user_can( 'dashboard', 'manage_api_backups' ) || ! MainWP_Abilities_Util::current_user_has_operation_right( 'manage_api_backups' ) ) {
            return self::denied();
        }
        if ( ! function_exists( 'mainwp_modules_is_enabled' ) || ! mainwp_modules_is_enabled( 'api-backups' ) ) {
            return new \WP_Error( 'mainwp_module_disabled', __( 'API Backups is not available.', 'mainwp' ), array( 'status' => 503 ) );
        }
        return true;
    }

    /**
     * Keep global provider indicators behind the settings route's full-scope gate.
     *
     * @param mixed $input Ability input.
     * @return true|\WP_Error
     */
    public static function check_providers_permission( $input = null ) {
        $permission = self::check_permission( $input );
        if ( true !== $permission ) {
            return $permission;
        }
        if ( ! is_callable( array( MainWP_Abilities_Util::class, 'get_scoped_site_ids_or_all' ) ) || ! is_callable( array( MainWP_Abilities_Util::class, 'get_scoped_group_ids_or_all' ) ) ) {
            return self::denied();
        }
        if ( 'all' !== MainWP_Abilities_Util::get_scoped_site_ids_or_all( false ) || 'all' !== MainWP_Abilities_Util::get_scoped_group_ids_or_all( false ) ) {
            return new \WP_Error( 'mainwp_restricted_account', __( 'This action is not available for accounts restricted to a subset of sites or tags.', 'mainwp' ), array( 'status' => 403 ) );
        }
        return true;
    }

    /**
     * List configured providers using only database-projected booleans.
     *
     * @param mixed $input Ability input.
     * @return array|\WP_Error
     */
    public static function execute_providers( $input = null ) {
        $permission = self::check_providers_permission( $input );
        if ( true !== $permission ) {
            return $permission;
        }
        $config = self::configuration();
        $sites  = self::inventory( $input );
        if ( is_wp_error( $config ) || is_wp_error( $sites ) ) {
            return self::read_error();
        }
        $rows = array();
        foreach ( self::providers() as $slug => $name ) {
            $key        = 'mainwp_api_backups_' . $slug . '_api_key';
            $configured = ! empty( $config[ $key ]['present'] );
            if ( 'cloudways' === $slug ) {
                $configured = $configured || ! empty( $config['mainwp_api_backups_cloudways_access_token']['present'] );
            }
            $count = 0;
            foreach ( $sites['allowed'] as $id => $provider ) {
                if ( $slug === $provider ) {
                    ++$count;
                    $configured = $configured || ! empty( $sites['individual'][ $id ] );
                }
            }
            if ( ! $configured ) {
                continue;
            }
            $rows[] = array(
                'provider'   => $slug,
                'name'       => $name,
                'connected'  => ! empty( $config[ 'mainwp_enable_' . $slug . '_api' ]['enabled'] ),
                'site_count' => $count,
            );
        }
        return self::safe_response( array( 'providers' => $rows ) );
    }

    /**
     * List accessible sites without reading provider messages or credentials.
     *
     * @param mixed $input Paging and optional provider filter.
     * @return array|\WP_Error
     */
    public static function execute_site_status( $input = null ) {
        $permission = self::check_permission( $input );
        if ( true !== $permission ) {
            return $permission;
        }
        if ( null !== $input && ! is_array( $input ) ) {
            return self::invalid();
        }
        $input = (array) $input;
        if ( isset( $input['provider'] ) && ( ! is_string( $input['provider'] ) || ! isset( self::providers()[ $input['provider'] ] ) ) ) {
            return self::invalid();
        }
        $page = self::page_number( $input['page'] ?? 1 );
        if ( false === $page ) {
            return self::invalid();
        }
        $per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
        $sites    = self::inventory( $input );
        if ( is_wp_error( $sites ) ) {
            return $sites;
        }
        $allowed = $sites['allowed'];
        if ( isset( $input['provider'] ) ) {
            $allowed = array_filter(
                $allowed,
                static function ( $provider ) use ( $input ) {
                    return $provider === $input['provider'];
                }
            );
        }
        $total  = count( $allowed );
        $offset = ( $page - 1 ) * $per_page;
        $rows   = array();
        if ( $offset < $total ) {
            $ids  = array_slice( array_keys( $allowed ), $offset, $per_page );
            $data = self::site_rows( $ids );
            if ( is_wp_error( $data ) ) {
                return $data;
            }
            foreach ( $data as $site ) {
                $id       = (int) $site['id'];
                $provider = $allowed[ $id ];
                $rows[]   = array(
                    'site_id'     => $id,
                    'name'        => is_string( $site['name'] ) ? wp_strip_all_tags( $site['name'] ) : '',
                    'provider'    => $provider,
                    'last_backup' => self::date( $site['last_backup'] ),
                    'last_status' => 'unknown',
                );
            }
        }
        return self::safe_response(
            array(
                'sites'             => $rows,
                'page'              => $page,
                'per_page'          => $per_page,
                'total'             => $total,
                'hidden_site_count' => $sites['hidden'],
            )
        );
    }

    /**
     * Get configuration indicators without retrieving stored option values.
     *
     * @return array|\WP_Error
     */
    private static function configuration() {
        global $wpdb;
        $names = array();
        foreach ( array_keys( self::providers() ) as $slug ) {
            $names[] = 'mainwp_api_backups_' . $slug . '_api_key';
            $names[] = 'mainwp_enable_' . $slug . '_api';
        }
        $names[]      = 'mainwp_api_backups_cloudways_access_token';
        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
        $sql          = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated %s placeholders are interpolated; values and identifiers are prepared.
            "SELECT option_name, CASE WHEN option_value NOT IN ('','a:0:{}','b:0;','N;') THEN 1 ELSE 0 END AS present, CASE WHEN option_value = '1' THEN 1 ELSE 0 END AS enabled FROM %i WHERE option_name IN ($placeholders)",
            array_merge( array( $wpdb->options ), $names )
        );
        $rows = self::query( $sql );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }
        $result = array();
        foreach ( $rows as $row ) {
            $result[ $row['option_name'] ] = array(
                'present' => (bool) $row['present'],
                'enabled' => (bool) $row['enabled'],
            );
        }
        return $result;
    }

    /**
     * Read IDs and allowlisted provider slugs, then apply both access checks.
     *
     * @param mixed $input Ability input.
     * @return array|\WP_Error
     */
    private static function inventory( $input ) {
        global $wpdb;
        if ( ! is_callable( array( '\MainWP\Dashboard\MainWP_DB', 'instance' ) ) || ! is_callable( array( '\MainWP\Dashboard\MainWP_Abilities_Util', 'check_site_access' ) ) ) {
            return self::read_error();
        }
        $db               = \MainWP\Dashboard\MainWP_DB::instance();
        $cases            = array();
        $case_values      = array();
        $credential_names = array();
        foreach ( array_keys( self::providers() ) as $slug ) {
            $cases[]            = 'WHEN %s THEN %s';
            $case_values[]      = $slug;
            $case_values[]      = $slug;
            $credential_names[] = 'mainwp_api_backups_' . $slug . '_api_key';
        }
        $provider_cases = implode( ' ', $cases );
        $placeholders   = implode( ',', array_fill( 0, count( $credential_names ), '%s' ) );
        $sql            = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The argument array follows the generated CASE, table identifiers and credential names.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated placeholder fragments are interpolated; provider values and identifiers are prepared.
            "SELECT wp.id, CASE LOWER(provider.value) $provider_cases ELSE NULL END AS provider, CASE WHEN credential.value NOT IN ('','{}','[]','null','a:0:{}','b:0;','N;') THEN 1 ELSE 0 END AS individual_configured FROM %i wp LEFT JOIN %i provider ON provider.wpid = wp.id AND provider.name = %s LEFT JOIN %i credential ON credential.wpid = wp.id AND credential.name IN ($placeholders) AND credential.name = CONCAT('mainwp_api_backups_', LOWER(provider.value), '_api_key') ORDER BY wp.id",
            array_merge( $case_values, array( $db->get_table_name( 'wp' ), $db->get_table_name( 'wp_options' ), 'mainwp_3rd_party_api', $db->get_table_name( 'wp_options' ) ), $credential_names )
        );
        $rows = self::query( $sql );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }
        $allowed    = array();
        $individual = array();
        $hidden     = 0;
        foreach ( $rows as $row ) {
            $id = (int) $row['id'];
            if ( ! mainwp_current_user_can( 'site', $id ) || true !== \MainWP\Dashboard\MainWP_Abilities_Util::check_site_access( $id, $input ) ) {
                ++$hidden;
                continue;
            }
            $individual[ $id ] = ! empty( $row['individual_configured'] );
            $allowed[ $id ]    = isset( self::providers()[ $row['provider'] ?? '' ] ) ? $row['provider'] : null;
        }
        return array(
            'allowed'    => $allowed,
            'individual' => $individual,
            'hidden'     => $hidden,
        );
    }

    /**
     * Fetch only the current page's names and stored request dates.
     *
     * @param array $ids Accessible page IDs.
     * @return array|\WP_Error
     */
    private static function site_rows( $ids ) {
        global $wpdb;
        $db                    = \MainWP\Dashboard\MainWP_DB::instance();
        $providers             = array_keys( self::providers() );
        $provider_placeholders = implode( ',', array_fill( 0, count( $providers ), '%s' ) );
        $placeholders          = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql                   = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The argument array includes all identifiers, fixed names and generated ID placeholders.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated placeholders are interpolated; providers, site IDs and table names are prepared.
            "SELECT wp.id, wp.name, backup.value AS last_backup FROM %i wp LEFT JOIN %i provider ON provider.wpid = wp.id AND provider.name = %s LEFT JOIN %i backup ON backup.wpid = wp.id AND LOWER(provider.value) IN ($provider_placeholders) AND backup.name = CONCAT('mainwp_3rd_party_', LOWER(provider.value), '_last_backup') WHERE wp.id IN ($placeholders) ORDER BY wp.id",
            array_merge( array( $db->get_table_name( 'wp' ), $db->get_table_name( 'wp_options' ), 'mainwp_3rd_party_api', $db->get_table_name( 'wp_options' ) ), $providers, $ids )
        );
        return self::query( $sql );
    }

    /**
     * Run a read without printing database diagnostics.
     *
     * @param string $sql Prepared SELECT.
     * @return array|\WP_Error
     */
    private static function query( $sql ) {
        global $wpdb;
        $previous = $wpdb->suppress_errors( true );
        try {
            $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Every caller supplies a prepared SELECT with an explicit projection that excludes credentials.
            if ( null === $rows || ! empty( $wpdb->last_error ) ) {
                return self::read_error();
            }
            return $rows;
        } finally {
            $wpdb->suppress_errors( $previous );
        }
    }

    /**
     * Clamp before multiplication so even huge numeric strings are safe.
     *
     * @param mixed $value Requested page.
     * @return int|false
     */
    private static function page_number( $value ) {
        if ( ! is_int( $value ) && ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
            return false;
        }
        if ( $value < 1 ) {
            return false;
        }
        return min( intdiv( PHP_INT_MAX, 100 ), (int) $value );
    }

    /**
     * Convert the legacy local request timestamp to an ISO 8601 date.
     *
     * @param mixed $value Stored local timestamp.
     * @return string|null
     */
    private static function date( $value ) {
        if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || (float) $value < 1 || (float) $value > 253402300799 ) {
            return null;
        }
        return get_gmt_from_date( gmdate( 'Y-m-d H:i:s', (int) $value ), 'c' );
    }

    /**
     * Reject complete suspicious text values after composing the response.
     *
     * @param mixed $value Composed response value.
     * @return mixed
     */
    private static function safe_response( $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = self::safe_response( $item );
            }
            return $value;
        }
        if ( ! is_string( $value ) ) {
            return $value;
        }
        $text    = wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $pattern = '~(?:https?://|www\.|\b[^\s@]+@[^\s@]+\.[^\s@]+|(?:^|[\s"\x27])(?:/(?=\S)|[a-z]:[\\\\/])|-----BEGIN|\b(?:bearer)(?:\s|\xC2\xA0|[|,;:"\x27=])|\bbasic[\s:=]+[a-z0-9+/]{8,}={0,2}(?![a-z0-9+/])|\b(?:password|passwd|token|secret|api[_ -]?key|authorization)\b\s*[\]"\x27]*\s*[:=]|\beyJ[a-z0-9_-]+\.[a-z0-9_-]+\.|\b(?:FAKEKEY|FAKEPASS|sk_live_|sk_test_|ghp_|AKIA))~i';
        if ( strlen( $text ) > 4096 || 0 !== preg_match( $pattern, $text ) ) {
            return '';
        }
        return $text;
    }

    /**
     * Supported provider slugs and labels in this Dashboard module.
     * Matches the native get_child_api_key() and update_child_api_key() allowlists.
     *
     * @return array
     */
    private static function providers() {
        return array(
            'cloudways'    => 'Cloudways',
            'gridpane'     => 'GridPane',
            'vultr'        => 'Vultr',
            'linode'       => 'Akamai (Linode)',
            'digitalocean' => 'DigitalOcean',
            'cpanel'       => 'cPanel (WP Toolkit)',
            'plesk'        => 'Plesk (WP Toolkit)',
            'kinsta'       => 'Kinsta',
        );
    }

    /**
     * Build an exact object schema.
     *
     * @param array $properties Object fields.
     * @return array
     */
    private static function object_schema( $properties ) {
        return array(
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => array_keys( $properties ),
            'additionalProperties' => false,
        );
    }

    /**
     * Build the exact site row schema.
     *
     * @return array
     */
    private static function site_schema() {
        return self::object_schema(
            array(
                'site_id'     => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'name'        => array( 'type' => 'string' ),
                'provider'    => array(
                    'type' => array( 'string', 'null' ),
                    'enum' => array_merge( array_keys( self::providers() ), array( null ) ),
                ),
                'last_backup' => array(
                    'type'   => array( 'string', 'null' ),
                    'format' => 'date-time',
                ),
                'last_status' => array(
                    'type' => 'string',
                    'enum' => array( 'success', 'failed', 'unknown' ),
                ),
            )
        );
    }

    /**
     * Return the standard permission denial without input or diagnostics.
     *
     * @return \WP_Error
     */
    private static function denied() {
        return new \WP_Error( 'mainwp_permission_denied', __( 'You do not have permission to access API Backups.', 'mainwp' ), array( 'status' => 403 ) );
    }

    /**
     * Return a generic read error without provider or database diagnostics.
     *
     * @return \WP_Error
     */
    private static function read_error() {
        return new \WP_Error( 'mainwp_api_backups_read_failed', __( 'Stored API Backups data could not be read.', 'mainwp' ), array( 'status' => 500 ) );
    }

    /**
     * Return a stable validation error without echoing the request.
     *
     * @return \WP_Error
     */
    private static function invalid() {
        return new \WP_Error( 'mainwp_invalid_input', __( 'Invalid API Backups input.', 'mainwp' ), array( 'status' => 400 ) );
    }
}
