<?php
/**
 * Remote MCP extension-declared contracts and the deferred-work check.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contracts an extension ships through `mainwp_remote_mcp_extension_contracts`, validated here,
 * and the check behind the public `mainwp_remote_mcp_authorization_active()`
 * (remote-mcp-functions.php) that an extension's deferred worker calls before every dispatch.
 *
 * The filter returns `ability name => contract`. A contract holds `tier` (`confirm` or
 * `dashboard`, never `write`), `preview` (`kind` `dry_run`, `ability` or `plan`, plus
 * `ability`, `arg_map`, `controls`, `capture_token`), `execute` (`controls`,
 * `native_token_field`), and optionally `resolver`, `adapter`, `sensitive`, `gate_supplied`,
 * `compare_fields`, `deferred` and `credential_bearing`. Anything malformed or unknown makes
 * the whole contract invalid: a half-understood contract could hide a control the ability needs.
 */
final class MCP_Extension_Contracts {

    /**
     * Filter extensions add their contracts to.
     *
     * @var string
     */
    const FILTER = 'mainwp_remote_mcp_extension_contracts';

    /**
     * Keys a declared contract may carry.
     *
     * @var array
     */
    const DECLARED_KEYS = array( 'tier', 'preview', 'execute', 'resolver', 'adapter', 'sensitive', 'gate_supplied', 'compare_fields', 'deferred', 'credential_bearing' );

    /**
     * Keys any contract may carry once normalized.
     *
     * @var array
     */
    const CONTRACT_KEYS = array( 'source', 'tier', 'preview', 'execute', 'resolver', 'adapter', 'sensitive', 'gate_supplied', 'compare_fields', 'deferred', 'credential_bearing' );

    /**
     * The validated contract an extension declared for an ability.
     *
     * @param string $ability Ability name.
     * @return array|null
     */
    public static function declared( string $ability ): ?array {
        $all = apply_filters( self::FILTER, array() );
        if ( ! is_array( $all ) || ! isset( $all[ $ability ] ) || ! is_array( $all[ $ability ] ) ) {
            return null;
        }
        if ( array_diff( array_keys( $all[ $ability ] ), self::DECLARED_KEYS ) ) {
            return null;
        }
        // A declared contract must name either `confirm` or `dashboard`.
        $contract = self::shape( array( 'source' => 'declared' ) + $all[ $ability ] );
        if ( null === $contract || null === $contract['tier'] ) {
            return null;
        }
        $contract['resolver'] = $all[ $ability ]['resolver'] ?? 'none';
        $contract['adapter']  = $all[ $ability ]['adapter'] ?? 'atomic';
        if ( ! in_array( $contract['resolver'], Remote_MCP_Plan_Types::RESOLVERS, true ) || ! in_array( $contract['adapter'], Remote_MCP_Plan_Types::ADAPTERS, true ) ) {
            return null;
        }
        return $contract;
    }

    /**
     * Normalize any contract to the Control_Contract shape, or null when it is malformed.
     *
     * @param array $raw Contract.
     * @return array|null
     */
    public static function shape( array $raw ): ?array {
        if ( array_diff( array_keys( $raw ), self::CONTRACT_KEYS ) ) {
            return null;
        }
        $preview = $raw['preview'] ?? null;
        $execute = $raw['execute'] ?? null;
        if ( ! is_array( $preview ) || ! is_array( $execute ) ) {
            return null;
        }
        $kind = $preview['kind'] ?? null;
        if ( ! in_array( $kind, array( 'dry_run', 'ability', 'plan' ), true ) ) {
            return null;
        }
        $preview_ability = $preview['ability'] ?? null;
        if ( ( 'ability' === $kind ) !== is_string( $preview_ability ) || ( null !== $preview_ability && ( ! is_string( $preview_ability ) || 1 !== preg_match( Call_Plan::ABILITY_PATTERN, $preview_ability ) ) ) ) {
            return null;
        }
        $arg_map = $preview['arg_map'] ?? null;
        if ( null !== $arg_map && ! self::string_map( $arg_map ) ) {
            return null;
        }
        $preview_controls = $preview['controls'] ?? array();
        $execute_controls = $execute['controls'] ?? array();
        $capture          = $preview['capture_token'] ?? null;
        $native           = $execute['native_token_field'] ?? null;
        if ( ! self::controls( $preview_controls ) || ! self::controls( $execute_controls ) || ! self::optional_name( $capture ) || ! self::optional_name( $native ) ) {
            return null;
        }
        // A token can only be supplied on execution if the preview captures one.
        if ( null !== $native && ( null === $capture || 'plan' === $kind ) ) {
            return null;
        }
        $lists = array();
        foreach ( array( 'sensitive', 'gate_supplied', 'compare_fields' ) as $list ) {
            $lists[ $list ] = $raw[ $list ] ?? array();
            if ( ! self::names( $lists[ $list ] ) ) {
                return null;
            }
        }
        foreach ( array( 'deferred', 'credential_bearing' ) as $flag ) {
            if ( isset( $raw[ $flag ] ) && ! is_bool( $raw[ $flag ] ) ) {
                return null;
            }
        }
        $tier          = $raw['tier'] ?? null;
        $allowed_tiers = array( OAuth_Store::TIER_CONFIRM, OAuth_Store::TIER_DASHBOARD );
        if ( 'declared' !== ( $raw['source'] ?? null ) ) {
            $allowed_tiers[] = 'write';
        }
        if ( null !== $tier && ! in_array( $tier, $allowed_tiers, true ) ) {
            return null;
        }
        $contract = array(
            'source'             => in_array( $raw['source'] ?? null, array( 'audit', 'declared', 'derived', 'uncontracted' ), true ) ? $raw['source'] : 'audit',
            'preview'            => array(
                'kind'          => $kind,
                'ability'       => $preview_ability,
                'arg_map'       => $arg_map,
                'controls'      => $preview_controls,
                'capture_token' => $capture,
            ),
            'execute'            => array(
                'controls'           => $execute_controls,
                'native_token_field' => $native,
            ),
            'sensitive'          => array_values( $lists['sensitive'] ),
            'deferred'           => ! empty( $raw['deferred'] ),
            'credential_bearing' => ! empty( $raw['credential_bearing'] ),
            'gate_supplied'      => array_values( $lists['gate_supplied'] ),
            'compare_fields'     => array_values( $lists['compare_fields'] ),
            'tier'               => $tier,
        );
        foreach ( array( 'resolver', 'adapter' ) as $type ) {
            if ( isset( $raw[ $type ] ) ) {
                $contract[ $type ] = $raw[ $type ];
            }
        }
        return $contract;
    }

    /**
     * The public check behind `mainwp_remote_mcp_authorization_active()`: the
     * connector is on, the grant is live at that policy version with current generations, and
     * its scope still includes `mainwp:write`. A worker holds no token, so the grant's scope is
     * the ceiling: narrowing a connection to View only stops its queued work.
     *
     * @param int $grant_id       Grant that authorized the work.
     * @param int $policy_version Policy version it was authorized under.
     * @return bool
     */
    public static function authorization_active( int $grant_id, int $policy_version ): bool {
        if ( $grant_id <= 0 || ! Remote_MCP_Settings::is_active() ) {
            return false;
        }
        $store = ( new Remote_MCP_Services() )->store();
        if ( null === $store || ! $store->authorization_active( $grant_id, $policy_version ) ) {
            return false;
        }
        $grant = $store->get_grant( $grant_id );
        return is_array( $grant ) && in_array( OAuth_Store::SCOPE_WRITE, OAuth_Store::scope_tokens( (string) $grant['scope'] ), true );
    }

    /**
     * Exact control values: names to scalars or null.
     *
     * @param mixed $controls Controls.
     * @return bool
     */
    private static function controls( $controls ): bool {
        if ( ! is_array( $controls ) || ( array() !== $controls && array_is_list( $controls ) ) ) {
            return false;
        }
        foreach ( $controls as $name => $value ) {
            if ( ! is_string( $name ) || '' === $name || ( null !== $value && ! is_scalar( $value ) ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * A list of non-empty names.
     *
     * @param mixed $names Names.
     * @return bool
     */
    private static function names( $names ): bool {
        if ( ! is_array( $names ) || ! array_is_list( $names ) ) {
            return false;
        }
        foreach ( $names as $name ) {
            if ( ! is_string( $name ) || '' === $name ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Null or a non-empty name.
     *
     * @param mixed $name Name.
     * @return bool
     */
    private static function optional_name( $name ): bool {
        return null === $name || ( is_string( $name ) && '' !== $name );
    }

    /**
     * A map of argument names to argument names.
     *
     * @param mixed $map Map.
     * @return bool
     */
    private static function string_map( $map ): bool {
        if ( ! is_array( $map ) || ( array() !== $map && array_is_list( $map ) ) ) {
            return false;
        }
        foreach ( $map as $from => $to ) {
            if ( ! is_string( $from ) || '' === $from || ! is_string( $to ) || '' === $to ) {
                return false;
            }
        }
        return true;
    }
}
