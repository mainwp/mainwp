<?php
/**
 * Remote MCP authorization context.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Who a remote call runs for and what its connection allows, as the bearer check found it. Built
 * once per request from the store's check result and passed to every slot interface; nothing in
 * it comes from the client.
 */
final class Remote_MCP_Context {

    /**
     * Grant id.
     *
     * @var int
     */
    public readonly int $grant_id;

    /**
     * WordPress user the call runs as.
     *
     * @var int
     */
    public readonly int $user_id;

    /**
     * OAuth client_id, or `mainwp-connection-token`.
     *
     * @var string
     */
    public readonly string $client_id;

    /**
     * `bundled`, `static`, `registered`, `webapp` or `token`.
     *
     * @var string
     */
    public readonly string $client_kind;

    /**
     * Effective scopes: token scope intersected with the grant's current scope.
     *
     * @var string[]
     */
    public readonly array $scopes;

    /**
     * `standard`, `strict` or `hands_off`.
     *
     * @var string
     */
    public readonly string $approval_policy;

    /**
     * Grant policy version when the token was checked.
     *
     * @var int
     */
    public readonly int $policy_version;

    /**
     * `access` or `connect`.
     *
     * @var string
     */
    public readonly string $token_type;

    /**
     * Protocol era of this request: `modern` (2026-07-28), `legacy` (2025-11-25) or `none`.
     *
     * @var string
     */
    public readonly string $protocol_era;

    /**
     * Whether the client can take a URL-mode elicitation. The MCP endpoint sets it; the approval
     * gate's deliver() reads it.
     *
     * @var bool
     */
    public readonly bool $client_supports_url_elicitation;

    /**
     * The sealed `requestState` a modern retry carried, or null. Never part of ability arguments
     * or `args_digest`.
     *
     * @var string|null
     */
    public readonly ?string $request_state;

    /**
     * Constructor.
     *
     * @param int         $grant_id        Grant id.
     * @param int         $user_id         User id.
     * @param string      $client_id       Client id.
     * @param string      $client_kind     Client kind.
     * @param string[]    $scopes          Effective scopes.
     * @param string      $approval_policy Approval policy.
     * @param int         $policy_version  Policy version.
     * @param string      $token_type      Token type.
     * @param string      $protocol_era    `modern`, `legacy` or `none`; anything else reads as `none`.
     * @param bool        $url_elicitation Whether the client takes URL-mode elicitation.
     * @param string|null $request_state Sealed requestState from a modern retry.
     */
    public function __construct( int $grant_id, int $user_id, string $client_id, string $client_kind, array $scopes, string $approval_policy, int $policy_version, string $token_type, string $protocol_era = 'none', bool $url_elicitation = false, ?string $request_state = null ) {
        $this->grant_id        = $grant_id;
        $this->user_id         = $user_id;
        $this->client_id       = $client_id;
        $this->client_kind     = $client_kind;
        $this->scopes          = array_values( $scopes );
        $this->approval_policy = $approval_policy;
        $this->policy_version  = $policy_version;
        $this->token_type      = $token_type;
        // An unknown era is no era, so nothing era-specific can be chosen from it. requestState
        // exists only in the 2026-07-28 era.
        $this->protocol_era                    = in_array( $protocol_era, array( 'modern', 'legacy' ), true ) ? $protocol_era : 'none';
        $this->client_supports_url_elicitation = 'none' !== $this->protocol_era && $url_elicitation;
        $this->request_state                   = 'modern' === $this->protocol_era ? $request_state : null;
    }

    /**
     * Context from a successful OAuth_Store::check_access_token() or check_connect_token() result.
     *
     * @param array       $result          Store result.
     * @param string      $protocol_era    `modern`, `legacy` or `none`.
     * @param bool        $url_elicitation Whether the client takes URL-mode elicitation.
     * @param string|null $request_state   Sealed requestState from a modern retry.
     * @return self|null Null unless the result is a success.
     */
    public static function from_check( array $result, string $protocol_era = 'none', bool $url_elicitation = false, ?string $request_state = null ): ?self {
        if ( OAuth_Store::OK !== ( $result['outcome'] ?? null ) ) {
            return null;
        }
        return new self(
            (int) $result['grant_id'],
            (int) $result['user_id'],
            (string) $result['client_id'],
            (string) $result['client_kind'],
            OAuth_Store::scope_tokens( (string) $result['scope'] ),
            (string) $result['approval_policy'],
            (int) $result['policy_version'],
            (string) $result['token_type'],
            $protocol_era,
            $url_elicitation,
            $request_state
        );
    }

    /**
     * Whether the effective scope includes one scope.
     *
     * @param string $scope Scope.
     * @return bool
     */
    public function has_scope( string $scope ): bool {
        return in_array( $scope, $this->scopes, true );
    }
}
