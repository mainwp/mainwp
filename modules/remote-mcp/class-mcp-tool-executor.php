<?php
/**
 * Remote MCP tool executor.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The tool surface and the trusted plan-entry path, the `tool_executor` slot.
 *
 * The call_tool() method checks scope, the catalog and the switches, then hands the call to the approval gate
 * and returns what the gate delivers. It never consumes, enqueues or runs a plan itself:
 * execute_plan_entry() is reached only from the gate's deliver() and from a job tick.
 */
class MCP_Tool_Executor implements Tool_Executor {

    /**
     * Step-up text for a view-only connection token.
     *
     * @var string
     */
    const TOKEN_STEP_UP = 'This token can only view. Create a token with changes allowed in AI > MainWP MCP > Connect an app.';

    /**
     * Step-up text for clients that can't re-authorize on a challenge.
     *
     * @var string
     */
    const RESULT_STEP_UP = 'Reconnect with changes allowed.';

    /**
     * Step-up description in ChatGPT's challenge, pinned by the fixture mcp/chatgpt-step-up.json.
     *
     * @var string
     */
    const CHATGPT_STEP_UP = 'This connection can only view. Reconnect and allow changes.';

    /**
     * Gate decisions handed to the gate's deliver(); the executor answers `refused` itself.
     *
     * @var string[]
     */
    const DELIVERED = array( 'run', 'confirmation_required', 'approval_required', 'denied' );

    /**
     * Services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Every enabled tool, on every grant. Tiers are not a listing filter: write tools are
     * listed on read grants so ChatGPT's frozen snapshot holds them and step-up can work.
     *
     * @param Remote_MCP_Context $context Connection.
     * @return array
     */
    public function list_tools( Remote_MCP_Context $context ): array {
        $tools = array();
        foreach ( $this->rows() as $row ) {
            if ( self::callable_row( $row ) ) {
                $tools[] = MCP_Tool_Catalog::descriptor( $row );
            }
        }
        return $tools;
    }

    /**
     * Context-free enumeration for the panel and consent copy.
     *
     * @return array
     */
    public function catalog(): array {
        $rows = array();
        foreach ( $this->rows() as $row ) {
            $rows[] = array(
                'tool_name'     => $row['tool_name'],
                'ability'       => $row['ability'],
                'category'      => $row['category'],
                'standard_tier' => $row['standard_tier'],
                'availability'  => $row['availability'],
                'reason'        => $row['reason'],
            );
        }
        return $rows;
    }

    /**
     * One tools/call.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $name      Tool name.
     * @param array              $arguments Client arguments.
     * @return array CallToolResult, or `jsonrpc_error` for an unknown tool.
     */
    public function call_tool( Remote_MCP_Context $context, string $name, array $arguments ): array {
        $started = microtime( true );
        $audit   = array(
            'user_id'          => $context->user_id,
            'grant_id'         => $context->grant_id,
            'client_kind'      => $context->client_kind,
            'approval_policy'  => $context->approval_policy,
            'protocol_version' => $this->protocol_version( $context ),
        );
        if ( MCP_Tool_Catalog::MODULE_TOOL === $name && $this->module_tool_enabled() ) {
            $result = $this->job_status( $context, $arguments );
            // Only a missing read scope is a scope refusal; a bad job_id or an unknown job is an error result.
            $outcome = ! $result['isError'] ? 'ok' : ( $context->has_scope( OAuth_Store::SCOPE_READ ) ? 'ability_error' : 'refused_scope' );
            $this->audit( $audit + array( 'tool' => $name ), $outcome, $started );
            return $result;
        }

        // A fresh catalog in this request: the live ability's annotations and the switches decide,
        // not whatever was listed earlier.
        $row = null;
        foreach ( $this->rows() as $candidate ) {
            if ( $name === $candidate['tool_name'] && null !== $candidate['ability'] && self::callable_row( $candidate ) ) {
                $row = $candidate;
                break;
            }
        }
        $ability = null === $row ? null : (string) $row['ability'];
        $tier    = null === $ability ? null : $this->services->get( 'scope_policy' )->effective_tier( $context, $ability, $this->tier_arguments( $arguments ) );
        if ( null === $tier ) {
            $this->audit( $audit, 'unknown_tool', $started );
            return $this->unknown_tool();
        }
        $audit += array(
            'tool'    => $name,
            'ability' => $ability,
            'tier'    => $tier,
        );

        $required = 'read' === $tier ? OAuth_Store::SCOPE_READ : OAuth_Store::SCOPE_WRITE;
        if ( ! $context->has_scope( $required ) ) {
            $this->record_failure( $context, 'insufficient_scope' );
            $this->audit( $audit, 'refused_scope', $started );
            return OAuth_Store::SCOPE_WRITE === $required && $context->has_scope( OAuth_Store::SCOPE_READ ) ? $this->step_up_result( $context ) : $this->error_result( 'This connection needs read access.' );
        }

        $gate     = $this->services->get( 'approval_gate' );
        $decision = $gate->check( $context, $ability, $arguments );
        $verdict  = is_array( $decision ) && in_array( $decision['decision'] ?? null, array_merge( self::DELIVERED, array( 'refused' ) ), true ) ? $decision['decision'] : 'refused';
        if ( is_int( $decision['approval_id'] ?? null ) ) {
            $audit['approval_id'] = $decision['approval_id'];
        }
        if ( ( $decision['plan'] ?? null ) instanceof Call_Plan ) {
            $audit['site_ids'] = $decision['plan']->target_ids();
        }
        if ( 'refused' === $verdict ) {
            $reason = $this->reason_code( $decision['reason'] ?? null, 'refused' );
            $this->record_failure( $context, $reason );
            $this->audit( $audit + array( 'reason' => $reason ), 'refused', $started );
            // A failed preview can carry the ability's own reason. The audit above keeps the code.
            if ( is_string( $decision['gate']['reason_text'] ?? null ) ) {
                return $this->error_result( $decision['gate']['reason_text'] );
            }
            return $this->error_result( MCP_Approval_Gate::refusal_text( $reason ) );
        }
        // A retry after "Run it here instead" already ran the row: the work succeeded.
        $already_run = 'denied' === $verdict && 'already_run' === ( $decision['reason'] ?? null );
        if ( 'denied' === $verdict && ! $already_run ) {
            $this->record_failure( $context, $this->reason_code( $decision['reason'] ?? null, 'denied' ) );
        }

        $result = $gate->deliver( $context, $decision );
        if ( is_int( $result['structuredContent']['job_id'] ?? null ) ) {
            $audit['job_id'] = $result['structuredContent']['job_id'];
        }
        if ( 'run' === $verdict ) {
            $outcome = empty( $result['isError'] ) ? 'ok' : 'ability_error';
        } else {
            $outcome = $already_run ? 'already_run' : $verdict;
        }
        $this->audit( $audit, $outcome, $started );
        return $result;
    }

    /**
     * Run one entry of a frozen plan as the grant's user. Trusted path: no catalog, gate,
     * consume or target expansion; the ability's own permission callback still runs.
     *
     * @param Call_Plan $plan     Frozen plan.
     * @param int       $index    Entry index.
     * @param int       $user_id  User the entry runs as.
     * @param int       $grant_id Grant that authorized the work.
     * @return array
     */
    public function execute_plan_entry( Call_Plan $plan, int $index, int $user_id, int $grant_id ): array {
        if ( $plan->is_pending() ) {
            return $this->not_run( 'pending_plan' );
        }
        $entries = $plan->entries();
        if ( ! isset( $entries[ $index ] ) ) {
            return $this->not_run( 'no_entry' );
        }
        $store = $this->services->store();
        $grant = null === $store ? null : $store->get_grant( $grant_id );
        if ( null === $grant ) {
            return $this->not_run( 'grant_unreadable' );
        }
        // The deferred context answers for this grant, so it must be the user's own.
        if ( (int) $grant['user_id'] !== $user_id || $user_id <= 0 ) {
            return $this->not_run( 'grant_user_mismatch' );
        }
        if ( 'live' !== ( $grant['status'] ?? null ) ) {
            return $this->not_run( 'grant_not_live' );
        }
        $name = (string) $entries[ $index ]['ability'];
        if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $name ) ) {
            return $this->not_run( 'unknown_ability' );
        }
        $ability = wp_get_ability( $name );
        $args    = $entries[ $index ]['args'];
        $input   = empty( $args ) && empty( $ability->get_input_schema() ) ? null : (array) $args;

        $deferred = array(
            'grant_id'       => $grant_id,
            'policy_version' => (int) $grant['policy_version'],
        );
        $answer   = function () use ( $deferred ) {
            return $deferred;
        };
        // Network Activity detects MCP from an Application Password on a REST route, which this
        // connection doesn't use. A job tick is still this connection's action, not WP Cron's.
        $agent    = function () {
            return 'mainwp_mcp';
        };
        $previous = get_current_user_id();
        // Last priority, so no other callback on the filter can answer for this grant.
        add_filter( Remote_MCP_Events::DEFERRED_CONTEXT, $answer, PHP_INT_MAX, 0 );
        add_filter( 'mainwp_module_log_current_agent', $agent, PHP_INT_MAX, 0 );
        wp_set_current_user( $user_id );
        try {
            $result = $ability->execute( $input );
        } catch ( \Throwable $e ) {
            $result = new \WP_Error( 'ability_exception', 'The ability failed.' );
        } finally {
            remove_filter( Remote_MCP_Events::DEFERRED_CONTEXT, $answer, PHP_INT_MAX );
            remove_filter( 'mainwp_module_log_current_agent', $agent, PHP_INT_MAX );
            wp_set_current_user( $previous );
        }

        if ( is_wp_error( $result ) ) {
            return array(
                'ok'     => false,
                'reason' => 'ability_error',
                'result' => $result,
                'queued' => false,
            );
        }
        return array(
            'ok'     => true,
            'reason' => null,
            'result' => $result,
            // Core answered with its own batch job, which carries no grant.
            'queued' => is_array( $result ) && true === ( $result['queued'] ?? null ),
        );
    }

    /**
     * The tool result for a write call on a read grant, by the client's step-up form. The
     * `challenge` form is a 403 the endpoint sends before calling this; reaching here with it
     * falls back to the plain text.
     *
     * @param Remote_MCP_Context $context Connection.
     * @return array
     */
    public function step_up_result( Remote_MCP_Context $context ): array {
        if ( 'token' === $context->client_kind ) {
            return $this->error_result( self::TOKEN_STEP_UP );
        }
        if ( 'chatgpt_meta' === $this->step_up( $context ) ) {
            $result          = $this->error_result( self::CHATGPT_STEP_UP );
            $result['_meta'] = array(
                'mcp/www_authenticate' => array(
                    'Bearer resource_metadata="' . (string) $this->services->urls()->prm_url() . '", scope="' . OAuth_Store::SCOPE_READ . ' ' . OAuth_Store::SCOPE_WRITE . '", error="insufficient_scope", error_description="' . self::CHATGPT_STEP_UP . '"',
                ),
            );
            return $result;
        }
        return $this->error_result( self::RESULT_STEP_UP );
    }

    /**
     * The client's step-up form: `challenge`, `chatgpt_meta` or `result`.
     *
     * @param Remote_MCP_Context $context Connection.
     * @return string
     */
    public function step_up( Remote_MCP_Context $context ): string {
        if ( 'token' === $context->client_kind ) {
            return 'result';
        }
        $client = $this->services->get( 'known_clients' )->lookup( $context->client_id );
        $form   = is_array( $client ) ? ( $client['step_up'] ?? null ) : null;
        return in_array( $form, array( 'challenge', 'chatgpt_meta' ), true ) ? $form : 'result';
    }

    /**
     * Registered abilities. A test seam.
     *
     * @return array
     */
    protected function abilities(): array {
        return function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
    }

    /**
     * Abilities over the time budget. A test seam.
     *
     * @return string[]
     */
    protected function excluded_abilities(): array {
        return MCP_Tool_Catalog::EXCLUDED_ABILITIES;
    }

    /**
     * Catalog rows, built fresh.
     *
     * @return array
     */
    private function rows(): array {
        $catalog = new MCP_Tool_Catalog(
            $this->services->get( 'tier_registry' ),
            function () {
                return $this->abilities();
            },
            $this->excluded_abilities()
        );
        return $catalog->rows();
    }

    /**
     * Whether a row is listed and callable now: available or unreviewed, and switched on.
     *
     * @param array $row Catalog row.
     * @return bool
     */
    public static function callable_row( array $row ): bool {
        if ( ! in_array( $row['availability'], array( 'available', 'not_reviewed' ), true ) ) {
            return false;
        }
        if ( ! Remote_MCP_Settings::tool_enabled( $row['tool_name'] ) || ! Remote_MCP_Settings::category_enabled( $row['category'] ) ) {
            return false;
        }
        return 'not_reviewed' !== $row['availability'] || Remote_MCP_Settings::unaudited_extension_tools_enabled();
    }

    /**
     * Whether the module tool is switched on.
     *
     * @return bool
     */
    private function module_tool_enabled(): bool {
        return Remote_MCP_Settings::tool_enabled( MCP_Tool_Catalog::MODULE_TOOL ) && Remote_MCP_Settings::category_enabled( MCP_Tool_Catalog::MODULE_CATEGORY );
    }

    /**
     * The get_remote_job_status_v1 tool: read scope, then the job runner, which answers only for the
     * caller's own jobs. This tool isn't an ability, so it skips the gate.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param array              $arguments Arguments.
     * @return array
     */
    private function job_status( Remote_MCP_Context $context, array $arguments ): array {
        if ( ! $context->has_scope( OAuth_Store::SCOPE_READ ) ) {
            return $this->error_result( 'This connection needs read access.' );
        }
        $job_id = $arguments['job_id'] ?? null;
        if ( ! is_int( $job_id ) || $job_id < 1 ) {
            return $this->error_result( 'job_id must be a positive integer.' );
        }
        $status = $this->services->get( 'job_runner' )->status( $job_id, $context->user_id );
        if ( ! is_array( $status ) ) {
            // The same answer for an unknown job and another user's, so no job id leaks.
            return $this->error_result( 'Job not found' );
        }
        return array(
            'content'           => array(
                array(
                    'type' => 'text',
                    'text' => (string) wp_json_encode( $status ),
                ),
            ),
            'structuredContent' => $status,
            'isError'           => false,
        );
    }

    /**
     * Arguments the conditional tier reads: the client's, minus the gate's and the ability's own
     * controls, which never reach an ability.
     *
     * @param array $arguments Client arguments.
     * @return array
     */
    private function tier_arguments( array $arguments ): array {
        foreach ( array_merge( MCP_Tool_Catalog::ABILITY_CONTROLS, array( 'user_confirmed', 'confirmation_token' ) ) as $control ) {
            unset( $arguments[ $control ] );
        }
        return $arguments;
    }

    /**
     * The -32602 answer for any name that isn't listed now, with no hint why.
     *
     * @return array
     */
    private function unknown_tool(): array {
        return array(
            'jsonrpc_error' => array(
                'code'    => MCP_Protocol::INVALID_PARAMS,
                'message' => 'Unknown tool',
            ),
        );
    }

    /**
     * An error tool result with fixed text.
     *
     * @param string $text Text.
     * @return array
     */
    private function error_result( string $text ): array {
        return array(
            'content' => array(
                array(
                    'type' => 'text',
                    'text' => $text,
                ),
            ),
            'isError' => true,
        );
    }

    /**
     * A plan-entry result that ran nothing.
     *
     * @param string $reason Reason code.
     * @return array
     */
    private function not_run( string $reason ): array {
        return array(
            'ok'     => false,
            'reason' => $reason,
            'result' => null,
            'queued' => false,
        );
    }

    /**
     * A gate reason as a short code, never free text.
     *
     * @param mixed  $reason   Reason.
     * @param string $fallback Code when the reason isn't one.
     * @return string
     */
    private function reason_code( $reason, string $fallback ): string {
        return is_string( $reason ) && 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $reason ) ? $reason : $fallback;
    }

    /**
     * Record a refusal on the connection's status line: reason codes only.
     *
     * @param Remote_MCP_Context $context Connection.
     * @param string             $reason  Reason code.
     */
    private function record_failure( Remote_MCP_Context $context, string $reason ): void {
        $store = $this->services->store();
        if ( null !== $store ) {
            $store->record_grant_failure( $context->grant_id, $reason );
        }
    }

    /**
     * Protocol version of the context's era.
     *
     * @param Remote_MCP_Context $context Connection.
     * @return string
     */
    private function protocol_version( Remote_MCP_Context $context ): string {
        if ( 'modern' === $context->protocol_era ) {
            return MCP_Protocol::MODERN;
        }
        return 'legacy' === $context->protocol_era ? MCP_Protocol::LEGACY : 'none';
    }

    /**
     * Fire the per-call audit record. No argument values other than site ids, no results.
     *
     * @param array  $fields  Fields.
     * @param string $outcome Outcome code.
     * @param float  $started Start time.
     */
    private function audit( array $fields, string $outcome, float $started ): void {
        $fields['outcome']     = $outcome;
        $fields['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
        Remote_MCP_Events::audit( 'mcp_call', $fields );
    }
}
