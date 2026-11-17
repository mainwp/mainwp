<?php
/**
 * Remote MCP events.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hook names the slot implementations use to react to each other's state changes, and the one way
 * to emit an audit event. Slot implementations don't call each other's classes, so a new grant and
 * every audited outcome travel through these actions.
 */
final class Remote_MCP_Events {

    /**
     * Fired after a grant becomes usable:
     * ( int $grant_id, int $user_id, string $client_id, string $client_kind, string $source ),
     * with `$source` `code_exchange` (token endpoint) or `connection_token` (admin panel).
     *
     * @var string
     */
    const GRANT_ISSUED = 'mainwp_remote_mcp_grant_issued';

    /**
     * Fired only by audit(): ( string $event, array $fields ).
     *
     * @var string
     */
    const AUDIT = 'mainwp_remote_mcp_audit';

    /**
     * Filter an extension reads when it queues deferred work:
     * `apply_filters( 'mainwp_remote_mcp_deferred_context', null )` returns
     * `['grant_id' => int, 'policy_version' => int]` while Tool_Executor::execute_plan_entry() runs
     * an entry, and null at any other time. The extension stores both values with the queued item
     * and passes them to `mainwp_remote_mcp_authorization_active()` before each dispatch, so a
     * revoked, narrowed or re-policied connection stops its queued work. The values come from the
     * grant the caller passes to execute_plan_entry(), never from the user id, because one user can
     * hold several grants. The tool executor adds the callback around WP_Ability::execute() and
     * removes it in a `finally`; nothing else adds it.
     *
     * @var string
     */
    const DEFERRED_CONTEXT = 'mainwp_remote_mcp_deferred_context';

    /**
     * Every audit event, grouped by emitter. An event not listed here is never fired.
     *
     * @var array
     */
    const AUDIT_EVENTS = array(
        // Front controller.
        'http_refused',
        // Consent page and authorization codes.
        'consent_approved',
        'consent_denied',
        'code_issued',
        // Token endpoint.
        'token_issued',
        'token_refreshed',
        'code_replay',
        'refresh_replay',
        'refresh_duplicate_late',
        'rate_limited',
        // Client registration and the known-client list.
        'registration_created',
        'registration_limited',
        'known_client_invalid',
        // Tool calls.
        'mcp_call',
        // Approval gate and approval page.
        'confirmation_required',
        'confirmed_chat',
        'approval_required',
        'approved_page',
        'denied',
        'stale_plan',
        'run_from_page',
        'hands_off',
        'approval_forgery',
        // Background jobs.
        'job_chunk',
        'job_cancelled',
        // Admin panel: clients, connection tokens and grants.
        'client_added',
        'client_changed',
        'client_deleted',
        'client_secret_reissued',
        'connection_token_minted',
        'grant_revoked',
        'policy_changed',
        'scope_narrowed',
    );

    /**
     * Fields an audit event may carry, with their type, `endpoint` for http_refused and a `reason`
     * code included. Anything else, every argument value but site ids
     * included, is dropped.
     *
     * @var array
     */
    const LOGGED_FIELDS = array(
        'user_id'          => 'int',
        'client_host'      => 'string',
        'grant_id'         => 'int',
        'tool'             => 'string',
        'ability'          => 'string',
        'outcome'          => 'string',
        'duration_ms'      => 'int',
        'protocol_version' => 'string',
        'client_ip'        => 'string',
        'client_kind'      => 'string',
        'tier'             => 'string',
        'approval_policy'  => 'string',
        'approval_id'      => 'int',
        'job_id'           => 'int',
        'site_ids'         => 'int_list',
        'endpoint'         => 'string',
        'reason'           => 'string',
        'policy_before'    => 'policy',
        'policy_after'     => 'policy',
        'scope_before'     => 'scope',
        'scope_after'      => 'scope',
    );

    /**
     * Emit an audit event. Unknown events aren't fired; fields outside LOGGED_FIELDS, or of the
     * wrong type, are dropped, so an emitter can't put a token, an argument or a client-supplied
     * name into the log by passing it here.
     *
     * @param string $event  Event from AUDIT_EVENTS.
     * @param array  $fields Fields.
     * @return bool Whether the event was fired.
     */
    public static function audit( string $event, array $fields ): bool {
        if ( ! in_array( $event, self::AUDIT_EVENTS, true ) ) {
            return false;
        }
        $kept = array();
        foreach ( self::LOGGED_FIELDS as $name => $type ) {
            if ( array_key_exists( $name, $fields ) && self::has_type( $fields[ $name ], $type ) ) {
                $kept[ $name ] = 'string' === $type ? substr( $fields[ $name ], 0, 191 ) : $fields[ $name ];
            }
        }
        do_action( self::AUDIT, $event, $kept );
        return true;
    }

    /**
     * Whether a value has a field's type.
     *
     * @param mixed  $value Value.
     * @param string $type  `int`, `string`, `int_list`, `policy` or `scope`.
     * @return bool
     */
    private static function has_type( $value, string $type ): bool {
        if ( 'int' === $type ) {
            return is_int( $value );
        }
        if ( 'policy' === $type ) {
            return is_string( $value ) && in_array( $value, OAuth_Store::POLICIES, true );
        }
        if ( 'scope' === $type ) {
            // Space-separated known scope tokens, each once, nothing else.
            if ( ! is_string( $value ) || '' === $value ) {
                return false;
            }
            $tokens = explode( ' ', $value );
            return count( $tokens ) === count( array_unique( $tokens ) ) && ! array_diff( $tokens, OAuth_Store::SCOPES );
        }
        if ( 'string' === $type ) {
            return is_string( $value );
        }
        if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
            return false;
        }
        foreach ( $value as $id ) {
            if ( ! is_int( $id ) ) {
                return false;
            }
        }
        return true;
    }
}
