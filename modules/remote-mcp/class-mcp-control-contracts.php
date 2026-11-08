<?php
/**
 * Remote MCP control contracts.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The exact control values for a tiered ability's preview and execution calls, from its audited
 * or declared contract or derived from its input schema, and the native preview re-run that
 * renews a token before a token-bound entry executes.
 *
 * Tiers, contracts and sensitive fields come only from Tier_Registry::entry(); nothing here keeps
 * a copy.
 */
class MCP_Control_Contracts implements Control_Contract {

    /**
     * Module services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Module services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Contract for an ability.
     *
     * @param Remote_MCP_Context $context Connection.
     * @param string             $ability Ability name.
     * @return array|null
     */
    public function contract_for( Remote_MCP_Context $context, string $ability ): ?array {
        $entry = $this->services->get( 'tier_registry' )->entry( $ability );
        return is_array( $entry ) ? $this->from_entry( $ability, $entry ) : null;
    }

    /**
     * Declared contract for an ability, validated.
     *
     * @param string $ability Ability name.
     * @return array|null
     */
    public function declared_contract( string $ability ): ?array {
        return MCP_Extension_Contracts::declared( $ability );
    }

    /**
     * Contract for a registry entry: its own contract, or the default contract derived from the
     * ability's input schema. The entry's sensitive, gate-supplied and compare fields are merged in,
     * because the audited tier table records some of them on the entry rather than in a contract.
     *
     * @param string $ability Ability name.
     * @param array  $entry   Tier_Registry entry.
     * @return array|null Null when the entry is refused or no contract can be derived.
     */
    public function from_entry( string $ability, array $entry ): ?array {
        if ( 'refused' === ( $entry['source'] ?? null ) ) {
            return null;
        }
        $schema = self::input_schema( $ability );
        if ( null === $schema ) {
            return null;
        }
        $uncontracted = 'uncontracted' === ( $entry['source'] ?? null );
        if ( ! $uncontracted && is_array( $entry['contract'] ?? null ) && array() !== $entry['contract'] ) {
            $contract = MCP_Extension_Contracts::shape( $entry['contract'] );
        } else {
            $contract = self::derive( $schema['properties'], $schema['required'], $uncontracted );
        }
        if ( null === $contract ) {
            return null;
        }
        foreach ( array( 'sensitive', 'gate_supplied', 'compare_fields' ) as $list ) {
            $extra             = isset( $entry[ $list ] ) && is_array( $entry[ $list ] ) ? array_filter( $entry[ $list ], 'is_string' ) : array();
            $contract[ $list ] = array_values( array_unique( array_merge( $contract[ $list ], $extra ) ) );
        }
        // A declared or audited native token renewed at execution needs fields to compare, or a
        // changed effect would run on the user's earlier click. Refused here, not in
        // declared(): a null there falls through to the uncontracted path. Uncontracted writes
        // that need a native token are refused by derive() already.
        if ( ! $uncontracted && null !== ( $contract['execute']['native_token_field'] ?? null ) && array() === $contract['compare_fields'] ) {
            return null;
        }
        $contract['credential_bearing'] = $contract['credential_bearing'] || ! empty( $entry['credential_bearing'] );
        $contract['deferred']           = $contract['deferred'] || ! empty( $entry['deferred'] );
        return $contract;
    }

    /**
     * The default contract from the declared input schema. For an uncontracted write only the
     * execution half applies: its own `dry_run` is never trusted as a preview.
     *
     * @param array $properties   Schema properties.
     * @param array $required     Required property names.
     * @param bool  $uncontracted Whether the ability is on the uncontracted path.
     * @return array|null Null when a control can't be derived ("needs a MainWP contract").
     */
    public static function derive( array $properties, array $required, bool $uncontracted ): ?array {
        foreach ( array( 'dry_run', 'confirm' ) as $control ) {
            if ( isset( $properties[ $control ] ) && ! self::is_boolean( $properties[ $control ] ) ) {
                return null;
            }
        }
        $has_dry_run = isset( $properties['dry_run'] );
        $has_confirm = isset( $properties['confirm'] );
        $has_token   = isset( $properties['preview_token'] );
        // A native token needs a preview that mints it: never on the uncontracted path, and only
        // through a dry run otherwise.
        if ( $has_token && ( $uncontracted || ! $has_dry_run ) ) {
            return null;
        }
        $preview = array();
        if ( $has_dry_run && ! $uncontracted ) {
            $preview = array( 'dry_run' => true );
            if ( in_array( 'confirm', $required, true ) ) {
                $preview['confirm'] = false;
            }
            if ( $has_token && in_array( 'preview_token', $required, true ) ) {
                $preview['preview_token'] = null;
            }
        }
        $execute = array();
        if ( $has_dry_run ) {
            $execute['dry_run'] = false;
        }
        if ( $has_confirm ) {
            $execute['confirm'] = true;
        }
        return array(
            'source'             => $uncontracted ? 'uncontracted' : 'derived',
            'preview'            => array(
                'kind'          => $has_dry_run && ! $uncontracted ? 'dry_run' : 'plan',
                'ability'       => null,
                'arg_map'       => null,
                'controls'      => $preview,
                'capture_token' => $has_token ? 'preview_token' : null,
            ),
            'execute'            => array(
                'controls'           => $execute,
                'native_token_field' => $has_token ? 'preview_token' : null,
            ),
            'sensitive'          => array(),
            'deferred'           => false,
            'credential_bearing' => false,
            'gate_supplied'      => array(),
            'compare_fields'     => array(),
            'tier'               => null,
        );
    }

    /**
     * Re-run the native preview of a token-bound entry and renew its token. Freshness
     * compares only the contract's `compare_fields` digest with the entry's `preview_baseline`;
     * the token itself is never compared. An entry without a baseline is stale: only the gate's
     * in-memory entry of a plan-less row may run without one, and it captures its own preview.
     *
     * @param Call_Plan $plan    Frozen plan.
     * @param int       $index   Entry index.
     * @param int       $user_id User the entry runs as.
     * @return array|null
     */
    public function refresh_native_token( Call_Plan $plan, int $index, int $user_id ): ?array {
        $entries = $plan->entries();
        if ( $plan->is_pending() || ! isset( $entries[ $index ] ) ) {
            return null;
        }
        $entry    = $entries[ $index ];
        $registry = $this->services->get( 'tier_registry' )->entry( $entry['ability'] );
        $contract = is_array( $registry ) ? $this->from_entry( $entry['ability'], $registry ) : null;
        $field    = $contract['execute']['native_token_field'] ?? null;
        if ( null === $field ) {
            return null;
        }
        $args = self::to_array( $plan->args_object( $index ) );
        unset( $args[ $field ] );
        foreach ( array_keys( $contract['execute']['controls'] ) as $control ) {
            unset( $args[ $control ] );
        }
        $capture = $this->run_preview( $entry['ability'], $args, $contract, $user_id );
        if ( null === $capture || null === $capture['token'] ) {
            return null;
        }
        $baseline = ( new OAuth_Token_Hasher() )->preview_baseline( $capture['preview'], $contract['compare_fields'] );
        if ( null === $baseline ) {
            return null;
        }
        $fresh = self::with_token( $plan, $index, $field, $capture['token'] );
        if ( null === $fresh ) {
            return null;
        }
        return array(
            'plan'  => $fresh,
            'stale' => null === $entry['preview_baseline'] || ! hash_equals( (string) $entry['preview_baseline'], $baseline ),
        );
    }

    /**
     * Run a contract's native preview as a user: the ability itself with the preview controls, or
     * the separate preview ability with the mapped arguments. Its permission callback runs.
     *
     * @param string         $ability  Ability being previewed.
     * @param array          $args     Arguments, gate-supplied fields included, controls excluded.
     * @param array          $contract Contract.
     * @param int            $user_id  User.
     * @param \WP_Error|null $error    Set to the ability's error when it returned one.
     * @return array|null `preview` (array) and `token` (string or null), or null on failure.
     */
    public function run_preview( string $ability, array $args, array $contract, int $user_id, ?\WP_Error &$error = null ): ?array {
        $preview = $contract['preview'];
        if ( 'dry_run' === $preview['kind'] ) {
            $target = $ability;
            $input  = array_merge( $args, $preview['controls'] );
        } elseif ( 'ability' === $preview['kind'] && is_string( $preview['ability'] ) ) {
            $target = $preview['ability'];
            $input  = array();
            foreach ( (array) $preview['arg_map'] as $from => $to ) {
                if ( array_key_exists( $from, $args ) ) {
                    $input[ $to ] = $args[ $from ];
                }
            }
            $input = array_merge( $input, $preview['controls'] );
        } else {
            return null;
        }
        $result = self::run_ability( $target, $input, $user_id, $error );
        if ( ! is_array( $result ) ) {
            return null;
        }
        $token = null;
        if ( is_string( $preview['capture_token'] ) ) {
            $value = $result[ $preview['capture_token'] ] ?? null;
            $token = is_string( $value ) && '' !== $value ? $value : null;
        }
        return array(
            'preview' => $result,
            'token'   => $token,
        );
    }

    /**
     * Execute an ability as a user and return its result as plain arrays, or null on an error.
     *
     * @param string         $name    Ability name.
     * @param array          $input   Input.
     * @param int            $user_id User.
     * @param \WP_Error|null $error   Set to the ability's error when it returned one.
     * @return array|null
     */
    public static function run_ability( string $name, array $input, int $user_id, ?\WP_Error &$error = null ): ?array {
        $ability = function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
        if ( null === $ability || $user_id <= 0 ) {
            return null;
        }
        $before = get_current_user_id();
        wp_set_current_user( $user_id );
        try {
            $result = $ability->execute( $input );
        } catch ( \Throwable $e ) {
            $result = null;
        } finally {
            wp_set_current_user( $before );
        }
        if ( is_wp_error( $result ) ) {
            $error = $result;
            return null;
        }
        if ( null === $result || is_scalar( $result ) ) {
            return null;
        }
        $json = Call_Plan::canonical_json( json_decode( (string) wp_json_encode( $result ) ) );
        if ( null === $json ) {
            return null;
        }
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * The same plan with a fresh token in one entry's native token field. Entries are rebuilt from
     * their stdClass form so nested empty objects stay objects.
     *
     * @param Call_Plan $plan  Plan.
     * @param int       $index Entry.
     * @param string    $field Native token field.
     * @param string    $token Fresh token.
     * @return Call_Plan|null
     */
    public static function with_token( Call_Plan $plan, int $index, string $field, string $token ): ?Call_Plan {
        $entries = array();
        foreach ( $plan->entries() as $i => $entry ) {
            $args = $plan->args_object( $i );
            if ( $i === $index ) {
                $args->{$field} = $token;
            }
            $entries[] = array(
                'ability'          => $entry['ability'],
                'args'             => $args,
                'target_ids'       => $entry['target_ids'],
                'preview_baseline' => $entry['preview_baseline'],
            );
        }
        return Call_Plan::from_entries( $entries );
    }

    /**
     * Input schema properties and required names of a registered ability.
     *
     * @param string $ability Ability name.
     * @return array|null `properties` and `required`, or null when the ability isn't registered.
     */
    public static function input_schema( string $ability ): ?array {
        $registered = function_exists( 'wp_has_ability' ) && wp_has_ability( $ability ) ? wp_get_ability( $ability ) : null;
        if ( null === $registered ) {
            return null;
        }
        $schema       = (array) $registered->get_input_schema();
        $properties   = $schema['properties'] ?? array();
        $properties   = is_object( $properties ) ? get_object_vars( $properties ) : (array) $properties;
        $required     = isset( $schema['required'] ) && is_array( $schema['required'] ) ? array_values( array_filter( $schema['required'], 'is_string' ) ) : array();
        $alternatives = array();
        foreach ( array( 'anyOf', 'oneOf', 'allOf' ) as $key ) {
            if ( isset( $schema[ $key ] ) && is_array( $schema[ $key ] ) ) {
                $alternatives = array_merge( $alternatives, array_values( $schema[ $key ] ) );
            }
        }
        return array(
            'properties'   => $properties,
            'required'     => $required,
            'alternatives' => $alternatives,
        );
    }

    /**
     * A JSON value as plain PHP arrays.
     *
     * @param mixed $value Value.
     * @return array
     */
    public static function to_array( $value ): array {
        $decoded = json_decode( (string) wp_json_encode( $value ), true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Whether a schema property is a plain boolean (optionally nullable).
     *
     * @param mixed $schema Property schema.
     * @return bool
     */
    private static function is_boolean( $schema ): bool {
        $type = is_array( $schema ) ? ( $schema['type'] ?? null ) : null;
        if ( 'boolean' === $type ) {
            return true;
        }
        return is_array( $type ) && in_array( 'boolean', $type, true ) && ! array_diff( $type, array( 'boolean', 'null' ) );
    }
}
