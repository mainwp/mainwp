<?php
/**
 * Remote MCP endpoint.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The MCP route (`route_mcp`): bearer authentication, era selection and validation, JSON-RPC
 * dispatch, scope checks and the hand-off of tools/call to the executor and gate. It returns a
 * response object; the front controller emits it.
 */
class MCP_Endpoint implements Route_Handler {

    /**
     * Services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Bearer authentication.
     *
     * @var MCP_Bearer_Auth
     */
    private $auth;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services  $services Services.
     * @param MCP_Bearer_Auth|null $auth     Bearer authentication; built from the services when null.
     */
    public function __construct( Remote_MCP_Services $services, ?MCP_Bearer_Auth $auth = null ) {
        $this->services = $services;
        $this->auth     = $auth ? $auth : new MCP_Bearer_Auth( $services );
    }

    /**
     * Handle one MCP request.
     *
     * @param Remote_MCP_Request $request Request.
     * @return Remote_MCP_Response
     */
    public function handle( Remote_MCP_Request $request ): Remote_MCP_Response {
        $started = microtime( true );
        // Page caches read the constant, not headers; 401s included, so a cached
        // challenge can't loop a client.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( 'POST' !== $request->method() ) {
            return new Remote_MCP_Response( 405, array( 'Allow' => 'POST' ) );
        }
        if ( ! $this->origin_allowed( $request ) ) {
            return Remote_MCP_Response::jsonrpc_error( 403, MCP_Protocol::INVALID_REQUEST, 'Origin not allowed' );
        }

        $auth = $this->auth->authenticate( $request );
        if ( ! $auth['ok'] ) {
            return $auth['response'];
        }

        $parsed = MCP_Protocol::parse( $request->body() );
        if ( isset( $parsed['error'] ) ) {
            return $this->error( $parsed['error'] );
        }
        $message = $parsed['message'];
        $server  = $request->server();
        $id      = $message->id ?? null;

        if ( ! $this->modern_enabled() ) {
            $era = $this->legacy_only_era( $message );
            if ( null === $era ) {
                // Every client we tested falls back to the legacy era on exactly this reply.
                return Remote_MCP_Response::jsonrpc_error( 200, MCP_Protocol::METHOD_NOT_FOUND, MCP_Protocol::MESSAGES[ MCP_Protocol::METHOD_NOT_FOUND ], $id );
            }
        } else {
            $selected = MCP_Protocol::select_era( $message, $server );
            if ( isset( $selected['error'] ) ) {
                return $this->error( $selected['error'] );
            }
            $era = $selected['era'];
            if ( 'modern' === $era ) {
                $invalid = MCP_Protocol::validate_modern( $message, $server );
                if ( null !== $invalid ) {
                    return $this->error( $invalid );
                }
            }
        }

        if ( ! property_exists( $message, 'id' ) ) {
            return new Remote_MCP_Response( 202 );
        }

        $params  = MCP_Protocol::params( $message );
        $state   = 'modern' === $era && isset( $params->requestState ) && is_string( $params->requestState ) ? $params->requestState : null;
        $context = Remote_MCP_Context::from_check( $auth['check'], $era, 'modern' === $era && MCP_Protocol::supports_url_elicitation( $message ), $state );
        if ( null === $context ) {
            return $this->auth->challenge( 'invalid_token' );
        }
        wp_set_current_user( $context->user_id );

        $method = $message->method;
        if ( ! in_array( $method, MCP_Protocol::TOKEN_ONLY_METHODS, true ) && in_array( $method, MCP_Protocol::METHODS[ $era ], true ) && ! $context->has_scope( OAuth_Store::SCOPE_READ ) ) {
            $this->record_failure( $context, 'insufficient_scope' );
            if ( 'tools/call' === $method ) {
                // Scope is checked before tool resolution, so client-supplied names stay out of the log.
                Remote_MCP_Events::audit(
                    'mcp_call',
                    array(
                        'user_id'          => $context->user_id,
                        'grant_id'         => $context->grant_id,
                        'client_kind'      => $context->client_kind,
                        'approval_policy'  => $context->approval_policy,
                        'protocol_version' => 'modern' === $era ? MCP_Protocol::MODERN : MCP_Protocol::LEGACY,
                        'outcome'          => 'refused_scope',
                        'duration_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
                    )
                );
            }
            return $this->auth->insufficient_scope( OAuth_Store::SCOPE_READ, 'This connection needs read access', $id );
        }

        $result = $this->dispatch( $context, $era, $method, $params, $id );
        if ( $result instanceof Remote_MCP_Response ) {
            return $result;
        }
        if ( 'modern' === $era ) {
            $result = $this->modern_result( $result );
        }
        return Remote_MCP_Response::json(
            200,
            array(
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => $result,
            )
        );
    }

    /**
     * Whether the 2026-07-28 era is served. A test seam for MCP_Protocol::MODERN_ENABLED.
     *
     * @return bool
     */
    protected function modern_enabled(): bool {
        return MCP_Protocol::MODERN_ENABLED;
    }

    /**
     * Run one method.
     *
     * @param Remote_MCP_Context $context Connection.
     * @param string             $era     `modern` or `legacy`.
     * @param string             $method  Method.
     * @param \stdClass          $params  Params.
     * @param int|string         $id      Request id.
     * @return array|\stdClass|Remote_MCP_Response Result, or a finished response.
     */
    private function dispatch( Remote_MCP_Context $context, string $era, string $method, \stdClass $params, $id ) {
        $executor = $this->services->get( 'tool_executor' );
        switch ( $era . ' ' . $method ) {
            case 'legacy initialize':
                // Every legacy request is answered as 2025-11-25 and the client decides whether
                // it can continue, as the legacy lifecycle specifies. No session id, ever.
                return array(
                    'protocolVersion' => MCP_Protocol::LEGACY,
                    'capabilities'    => MCP_Protocol::capabilities(),
                    'serverInfo'      => MCP_Server_Instructions::server_info(),
                    'instructions'    => MCP_Server_Instructions::TEXT,
                );
            case 'modern server/discover':
                return array(
                    'supportedVersions' => MCP_Protocol::SUPPORTED,
                    'capabilities'      => MCP_Protocol::capabilities(),
                    'instructions'      => MCP_Server_Instructions::TEXT,
                    'ttlMs'             => MCP_Protocol::TTL_MS,
                    'cacheScope'        => 'private',
                );
            case 'modern ping':
            case 'legacy ping':
                return new \stdClass();
            case 'modern tools/list':
            case 'legacy tools/list':
                $result = array( 'tools' => $executor->list_tools( $context ) );
                if ( 'modern' === $era ) {
                    // The list depends on the panel switches and the user's abilities, so it must
                    // never be shared across authorization contexts.
                    $result['ttlMs']      = MCP_Protocol::TTL_MS;
                    $result['cacheScope'] = 'private';
                }
                return $result;
            case 'modern tools/call':
            case 'legacy tools/call':
                return $this->tools_call( $context, $executor, $params, $id );
            case 'modern prompts/list':
            case 'legacy prompts/list':
                return array( 'prompts' => MCP_Server_Instructions::prompts() );
            case 'modern prompts/get':
            case 'legacy prompts/get':
                $name      = isset( $params->name ) && is_string( $params->name ) ? $params->name : '';
                $arguments = isset( $params->arguments ) && $params->arguments instanceof \stdClass ? get_object_vars( $params->arguments ) : array();
                $prompt    = ( ! isset( $params->arguments ) || $params->arguments instanceof \stdClass ) ? MCP_Server_Instructions::get( $name, $arguments ) : null;
                if ( null === $prompt ) {
                    return Remote_MCP_Response::jsonrpc_error( 200, MCP_Protocol::INVALID_PARAMS, MCP_Protocol::MESSAGES[ MCP_Protocol::INVALID_PARAMS ], $id );
                }
                return $prompt;
        }
        // Only a legacy request gets here: the modern table already answered unknown methods.
        return Remote_MCP_Response::jsonrpc_error( 200, MCP_Protocol::METHOD_NOT_FOUND, MCP_Protocol::MESSAGES[ MCP_Protocol::METHOD_NOT_FOUND ], $id );
    }

    /**
     * Run tools/call.
     *
     * @param Remote_MCP_Context $context  Connection.
     * @param Tool_Executor      $executor Executor.
     * @param \stdClass          $params   Params.
     * @param int|string         $id       Request id.
     * @return array|Remote_MCP_Response
     */
    private function tools_call( Remote_MCP_Context $context, Tool_Executor $executor, \stdClass $params, $id ) {
        $started = microtime( true );
        $name    = isset( $params->name ) && is_string( $params->name ) ? $params->name : null;
        if ( null === $name || ( isset( $params->arguments ) && ! $params->arguments instanceof \stdClass ) ) {
            return Remote_MCP_Response::jsonrpc_error( 200, MCP_Protocol::INVALID_PARAMS, MCP_Protocol::MESSAGES[ MCP_Protocol::INVALID_PARAMS ], $id );
        }
        $arguments = isset( $params->arguments ) ? (array) MCP_Protocol::to_array( $params->arguments ) : array();

        $challenge = $this->challenge_tool( $context, $executor, $name );
        if ( null !== $challenge ) {
            $this->record_failure( $context, 'insufficient_scope' );
            // The challenge returns before the executor can record this call.
            Remote_MCP_Events::audit(
                'mcp_call',
                array(
                    'user_id'          => $context->user_id,
                    'grant_id'         => $context->grant_id,
                    'client_kind'      => $context->client_kind,
                    'approval_policy'  => $context->approval_policy,
                    'protocol_version' => 'modern' === $context->protocol_era ? MCP_Protocol::MODERN : MCP_Protocol::LEGACY,
                    'tool'             => $challenge['tool_name'],
                    'ability'          => $challenge['ability'],
                    'tier'             => $this->services->get( 'scope_policy' )->effective_tier( $context, $challenge['ability'], $arguments ),
                    'outcome'          => 'refused_scope',
                    'duration_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
                )
            );
            return $this->auth->insufficient_scope( OAuth_Store::SCOPE_READ . ' ' . OAuth_Store::SCOPE_WRITE, 'This connection can only view', $id );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            // The inline budget is 90 s; this leaves room to answer after it.
            set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- the documented per-call budget.
        }
        $result = $executor->call_tool( $context, $name, $arguments );
        if ( isset( $result['jsonrpc_error'] ) && is_array( $result['jsonrpc_error'] ) ) {
            $error = $result['jsonrpc_error'];
            return Remote_MCP_Response::json(
                200,
                array(
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'error'   => $error,
                )
            );
        }
        return $result;
    }

    /**
     * The catalog row for a write call that gets the 403 step-up challenge: only for clients
     * whose entry says `challenge`; the others get a tool result from the executor.
     *
     * @param Remote_MCP_Context $context  Connection.
     * @param Tool_Executor      $executor Executor.
     * @param string             $name     Tool name.
     * @return array|null
     */
    private function challenge_tool( Remote_MCP_Context $context, Tool_Executor $executor, string $name ): ?array {
        if ( $context->has_scope( OAuth_Store::SCOPE_WRITE ) || 'token' === $context->client_kind ) {
            return null;
        }
        $client = $this->services->get( 'known_clients' )->lookup( $context->client_id );
        if ( ! is_array( $client ) || 'challenge' !== ( $client['step_up'] ?? null ) ) {
            return null;
        }
        foreach ( $executor->catalog() as $row ) {
            // A switched-off tool gets "Unknown tool" here too, never a challenge that reveals it exists.
            if ( $name === $row['tool_name'] && MCP_Tool_Executor::callable_row( $row ) ) {
                // Only read-tier tools run on a read grant; every other tier needs write.
                return 'read' !== $row['standard_tier'] ? $row : null;
            }
        }
        return null;
    }

    /**
     * Add the modern result fields, only when the result has no resultType of its own, so a
     * gate's `input_required` result survives.
     *
     * @param array|\stdClass $result Result.
     * @return array|\stdClass
     */
    private function modern_result( $result ) {
        $result = $result instanceof \stdClass ? get_object_vars( $result ) : $result;
        if ( isset( $result['resultType'] ) ) {
            return $result;
        }
        $meta                                   = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
        $meta[ MCP_Protocol::META_SERVER_INFO ] = MCP_Server_Instructions::server_info();
        $result['resultType']                   = 'complete';
        $result['_meta']                        = $meta;
        return $result;
    }

    /**
     * Era in legacy-only mode, or null when the request must get the fallback reply: any
     * modern _meta version, or server/discover. Nothing else of the modern table runs.
     *
     * @param \stdClass $message Message.
     * @return string|null
     */
    private function legacy_only_era( \stdClass $message ): ?string {
        $meta = MCP_Protocol::params( $message )->_meta ?? null;
        if ( 'server/discover' === $message->method || ( $meta instanceof \stdClass && property_exists( $meta, MCP_Protocol::META_VERSION ) ) ) {
            return null;
        }
        return 'legacy';
    }

    /**
     * Whether the Origin header is absent or the Dashboard's own origin. Defense in depth
     * against DNS rebinding; v1 sends no CORS headers either way.
     *
     * @param Remote_MCP_Request $request Request.
     * @return bool
     */
    private function origin_allowed( Remote_MCP_Request $request ): bool {
        $origin = $request->server_var( 'HTTP_ORIGIN' );
        if ( null === $origin ) {
            return true;
        }
        $base = (string) $this->services->urls()->base();
        $own  = '' === $base ? '' : (string) preg_replace( '#^(https://[^/]+).*$#', '$1', $base );
        return '' !== $own && $own === $origin;
    }

    /**
     * A JSON-RPC error response from an [ status, code, id, data ] tuple.
     *
     * @param array $error Error tuple.
     * @return Remote_MCP_Response
     */
    private function error( array $error ): Remote_MCP_Response {
        $body = array(
            'code'    => $error[1],
            'message' => MCP_Protocol::MESSAGES[ $error[1] ],
        );
        if ( isset( $error[3] ) && is_array( $error[3] ) ) {
            $body['data'] = $error[3];
        }
        $id = $error[2] ?? null;
        return Remote_MCP_Response::json(
            $error[0],
            array(
                'jsonrpc' => '2.0',
                'id'      => is_string( $id ) || is_int( $id ) ? $id : null,
                'error'   => $body,
            )
        );
    }

    /**
     * Record a scope refusal on the connection.
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
}
