<?php
/**
 * Remote MCP OAuth store.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- this class is the module's data layer; every statement is prepared.

/**
 * All OAuth SQL and its atomic state transitions.
 *
 * Every transition that must happen once is one conditional statement on the code, request or
 * grant row, and the caller wins only when $wpdb->query() reports exactly one affected row. There
 * is no START TRANSACTION: the test harness already runs each test in a transaction, and a nested
 * START TRANSACTION in MySQL commits the outer one.
 *
 * Results are arrays with `outcome` (one of the outcome constants) and `event` (a reason for the
 * audit log, or null), plus outcome-specific fields.
 */
class OAuth_Store {

    const OK             = 'ok';
    const INVALID_GRANT  = 'invalid_grant';
    const INVALID_TARGET = 'invalid_target';
    const INVALID_TOKEN  = 'invalid_token';
    const SERVER_ERROR   = 'server_error';

    /**
     * A refresh asked for scope the grant doesn't hold.
     *
     * @var string
     */
    const INVALID_SCOPE = 'invalid_scope';

    /**
     * A store input outside the allowed set; nothing was written.
     *
     * @var string
     */
    const INVALID_REQUEST = 'invalid_request';

    /**
     * A per-user or per-grant cap is full; nothing older was revoked to make room.
     *
     * @var string
     */
    const CAP_REACHED = 'cap_reached';

    const SCOPE_READ    = 'mainwp:read';
    const SCOPE_WRITE   = 'mainwp:write';
    const SCOPE_OFFLINE = 'offline_access';

    /**
     * Scopes a grant may hold.
     *
     * @var array
     */
    const SCOPES = array( self::SCOPE_READ, self::SCOPE_WRITE, self::SCOPE_OFFLINE );

    const POLICY_STANDARD  = 'standard';
    const POLICY_STRICT    = 'strict';
    const POLICY_HANDS_OFF = 'hands_off';

    /**
     * Approval policies. Who may pick Hands-off is the consent and panel code's check.
     *
     * @var array
     */
    const POLICIES = array( self::POLICY_STANDARD, self::POLICY_STRICT, self::POLICY_HANDS_OFF );

    /**
     * Synthetic client id every connection token grant carries.
     *
     * @var string
     */
    const CONNECTION_TOKEN_CLIENT_ID = 'mainwp-connection-token';

    /**
     * Longest connection token lifetime the panel offers. There is no "never".
     *
     * @var int
     */
    const CONNECTION_TOKEN_MAX_LIFETIME = 31536000;

    const TIER_CONFIRM   = 'confirm';
    const TIER_DASHBOARD = 'dashboard';

    /**
     * Approval lifetimes: a Dashboard row waiting for its click, an approved
     * Dashboard row waiting for the retry, and a chat confirmation.
     */
    const APPROVAL_PENDING_LIFETIME  = 1800;
    const APPROVAL_APPROVED_LIFETIME = 900;
    const APPROVAL_CHAT_LIFETIME     = 600;

    /**
     * Open approvals per grant; the 21st expires the oldest.
     *
     * @var int
     */
    const OPEN_APPROVAL_CAP = 20;

    /**
     * Starting or running jobs per grant.
     *
     * @var int
     */
    const RUNNING_JOB_CAP = 3;

    /**
     * A job still `starting` after this long lost its consume and is purged.
     *
     * @var int
     */
    const STARTING_JOB_TTL = 600;

    /**
     * Registered clients never consented are purged after this long.
     *
     * @var int
     */
    const UNCONSENTED_CLIENT_TTL = 604800;

    /**
     * Lost the version race on every attempt: the endpoint answers 503 with Retry-After: 1.
     *
     * @var string
     */
    const RETRY = 'retry';

    const CODE_LIFETIME    = 120;
    const REQUEST_LIFETIME = 600;
    const ACCESS_LIFETIME  = 1800;
    const REFRESH_LIFETIME = 1209600;
    const SESSION_LIFETIME = 2592000;

    /**
     * Window after a rotation in which a duplicate refresh gets one sibling pair.
     *
     * @var int
     */
    const GRACE_SECONDS = 30;

    /**
     * Re-reads after a lost version race before giving up.
     *
     * @var int
     */
    const MAX_REREADS = 3;

    /**
     * Attempts for a statement whose failure would leave a revocation incomplete.
     *
     * @var int
     */
    const SQL_ATTEMPTS = 3;

    /**
     * Live grants per user and client_id.
     *
     * @var int
     */
    const LIVE_GRANT_CAP = 5;

    /**
     * Rows that are not replay evidence stay this long after their own expiry.
     *
     * @var int
     */
    const EXPIRED_RETENTION = 86400;

    /**
     * Grants and everything they own stay this long after revocation or session end.
     *
     * @var int
     */
    const GRANT_RETENTION = 2592000;

    /**
     * Database.
     *
     * @var \wpdb
     */
    private $db;

    /**
     * Hasher.
     *
     * @var OAuth_Token_Hasher
     */
    private $hasher;

    /**
     * Canonical MCP resource the hashing key is bound to.
     *
     * @var string
     */
    private $resource;

    /**
     * Constructor.
     *
     * @param OAuth_Token_Hasher $hasher             Hasher.
     * @param string             $canonical_resource Canonical MCP resource URL.
     */
    public function __construct( OAuth_Token_Hasher $hasher, string $canonical_resource ) {
        global $wpdb;
        $this->db       = $wpdb;
        $this->hasher   = $hasher;
        $this->resource = $canonical_resource;
    }

    /**
     * Current time. Tests override it instead of sleeping.
     *
     * @return int
     */
    protected function now(): int {
        return time();
    }

    /**
     * Seam: a live code was read (step 0) and nothing has been written yet.
     */
    protected function after_code_read(): void {
    }

    /**
     * Seam: the code was claimed (step 2) and the pending grant is not yet checked or published.
     *
     * @param int $grant_id Pending grant.
     */
    protected function after_claim( int $grant_id ): void {
    }

    /**
     * Seam: pair 1 is inserted (step 4) and the grant is not yet published.
     *
     * @param int   $grant_id Pending grant.
     * @param array $tokens   Minted tokens.
     */
    protected function before_publish( int $grant_id, array $tokens ): void {
    }

    /**
     * Seam: the grant was read and a version-conditional update is next.
     *
     * @param string $transition `access_first_use`, `access_select` or `rotate`.
     */
    protected function after_grant_read( string $transition ): void {
    }

    /**
     * Seam: a duplicate refresh passed the grace check and its update is next.
     */
    protected function after_grace_check(): void {
    }

    /**
     * Store a pending consent request, copying the current generations.
     *
     * @param array $params user_id, client_id, client_host, redirect_uri, scope, resource, code_challenge, state.
     * @return array|null `id` and `request_key`, or null when the insert failed.
     */
    public function create_request( array $params ): ?array {
        $now         = $this->now();
        $user_id     = (int) $params['user_id'];
        $generations = $this->current_generations( $user_id );
        $request_key = OAuth_Token_Hasher::base64url( random_bytes( 32 ) );

        $inserted = $this->db->insert(
            $this->table( 'requests' ),
            array(
                'request_key'     => $request_key,
                'user_id'         => $user_id,
                'client_id'       => (string) $params['client_id'],
                'client_host'     => (string) $params['client_host'],
                'redirect_uri'    => (string) $params['redirect_uri'],
                'scope'           => (string) $params['scope'],
                'resource'        => (string) $params['resource'],
                'code_challenge'  => (string) $params['code_challenge'],
                'state'           => isset( $params['state'] ) ? (string) $params['state'] : null,
                'status'          => 'pending',
                'user_generation' => $generations['user'],
                'site_generation' => $generations['site'],
                'created_at'      => $now,
                'expires_at'      => $now + self::REQUEST_LIFETIME,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
        );
        if ( 1 !== $inserted ) {
            return null;
        }
        return array(
            'id'          => (int) $this->db->insert_id,
            'request_key' => $request_key,
        );
    }

    /**
     * Claim a pending consent request for one decision. Nonces aren't consumed on use, so
     * this statement is what stops two submissions from both acting. Generations are compared inside
     * the statement, never against values read earlier.
     *
     * Approval records the user's access and policy choice in the same statement,
     * and the code and grant copy them from here. The approved scope can only narrow what the app
     * asked for. A caller that names no choice gets view only under Strict, so a consent screen that
     * forgets to pass the choice can never grant write.
     *
     * @param int         $request_id      Request id.
     * @param int         $user_id         Current user.
     * @param bool        $approve         Approve or deny.
     * @param string|null $approved_scope  Approved scope, a subset of the requested scope.
     * @param string|null $approval_policy One of POLICIES.
     * @return bool Whether this caller won the decision.
     */
    public function claim_request( int $request_id, int $user_id, bool $approve, ?string $approved_scope = null, ?string $approval_policy = null ): bool {
        $now  = $this->now();
        $set  = 'r.status = %s, r.decided_at = %d';
        $args = array( $approve ? 'approving' : 'denied', $now );
        $bind = '';
        if ( $approve ) {
            $requested = $this->db->get_var( $this->db->prepare( 'SELECT scope FROM %i WHERE id = %d AND user_id = %d', $this->table( 'requests' ), $request_id, $user_id ) );
            if ( ! is_string( $requested ) ) {
                return false;
            }
            $approved = $this->approved_scope( $requested, $approved_scope );
            $policy   = null === $approval_policy ? self::POLICY_STRICT : $approval_policy;
            if ( null === $approved || ! in_array( $policy, self::POLICIES, true ) ) {
                return false;
            }
            $set   .= ', r.approved_scope = %s, r.approval_policy = %s';
            $args[] = $approved;
            $args[] = $policy;
            // The choice was checked against this scope, so the claim only lands while it holds.
            $bind = ' AND r.scope = %s';
        }
        $args = array_merge( $args, array( $request_id, $user_id, $now ) );
        if ( $approve ) {
            $args[] = $requested;
        }
        return 1 === $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $set and $bind are fixed fragments holding only placeholders.
                'UPDATE %i r LEFT JOIN %i u ON u.subject_id = r.user_id LEFT JOIN %i st ON st.subject_id = 0
                SET ' . $set . '
                WHERE r.id = %d AND r.user_id = %d AND r.status = \'pending\' AND r.expires_at > %d' . $bind . '
                AND r.user_generation = COALESCE(u.generation, 0) AND r.site_generation = COALESCE(st.generation, 0)',
                $this->table( 'requests' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                ...$args
            )
        );
    }

    /**
     * The scope a consent approval records, or null when the choice isn't allowed.
     *
     * @param string      $requested Scope the app asked for.
     * @param string|null $chosen    Scope the user chose; null means view only.
     * @return string|null Normalized scope in the requested order.
     */
    private function approved_scope( string $requested, ?string $chosen ): ?string {
        $asked    = self::scope_tokens( $requested );
        $approved = null === $chosen ? array_diff( $asked, array( self::SCOPE_WRITE ) ) : self::scope_tokens( $chosen );
        if ( empty( $approved ) || array_diff( $approved, $asked ) || array_diff( $approved, self::SCOPES ) ) {
            return null;
        }
        // Write implies read; a write-only grant would be a scope nothing checks for.
        if ( in_array( self::SCOPE_WRITE, $approved, true ) && ! in_array( self::SCOPE_READ, $approved, true ) ) {
            return null;
        }
        return implode( ' ', array_values( array_intersect( $asked, $approved ) ) );
    }

    /**
     * Issue the code for a request this caller claimed for approval. The code row
     * copies everything, generations included, from the claimed request row in one statement. Its
     * scope is the approved scope, not the requested one.
     *
     * @param int $request_id Request in `approving`.
     * @return string|null The code, or null when no code exists and the request is `failed`.
     */
    public function issue_code( int $request_id ): ?string {
        $now  = $this->now();
        $code = $this->hasher->generate( OAuth_Token_Hasher::TYPE_CODE );

        $inserted = $this->db->query(
            $this->db->prepare(
                'INSERT INTO %i (code_hash, user_id, client_id, redirect_uri, scope, resource, code_challenge, request_id, status, user_generation, site_generation, created_at, expires_at, approval_policy)
                SELECT %s, user_id, client_id, redirect_uri, approved_scope, resource, code_challenge, id, \'live\', user_generation, site_generation, %d, %d, approval_policy
                FROM %i WHERE id = %d AND status = \'approving\' AND approved_scope IS NOT NULL AND approval_policy IS NOT NULL',
                $this->table( 'codes' ),
                $this->hasher->hash( $code, $this->resource ),
                $now,
                $now + self::CODE_LIFETIME,
                $this->table( 'requests' ),
                $request_id
            )
        );

        $status = 1 === $inserted ? 'approved' : 'failed';
        $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = %s WHERE id = %d AND status = \'approving\'',
                $this->table( 'requests' ),
                $status,
                $request_id
            )
        );
        return 1 === $inserted ? $code : null;
    }

    /**
     * Consent request by its exact key, for the consent POST. The key is
     * stored in plain text: it only ties the form to its row, and the claim still requires the
     * row's own user, so it is returned with the row.
     *
     * @param string $request_key Key from the consent form.
     * @return array|null `id`, `user_id`, `client_id`, `client_host`, `redirect_uri`, `scope`,
     *                    `resource`, `state`, `status`, `request_key` and `expired` (bool), or null
     *                    when no row has this key.
     */
    public function find_request( string $request_key ): ?array {
        // The plain comparison uses the unique index; BINARY rejects a case variant the column's
        // case-insensitive collation would match.
        $row = $this->db->get_row(
            $this->db->prepare(
                'SELECT id, user_id, client_id, client_host, redirect_uri, scope, resource, state, status, request_key, expires_at
                FROM %i WHERE request_key = %s AND BINARY request_key = %s',
                $this->table( 'requests' ),
                $request_key,
                $request_key
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) {
            return null;
        }
        return array(
            'id'           => (int) $row['id'],
            'user_id'      => (int) $row['user_id'],
            'client_id'    => (string) $row['client_id'],
            'client_host'  => (string) $row['client_host'],
            'redirect_uri' => (string) $row['redirect_uri'],
            'scope'        => (string) $row['scope'],
            'resource'     => (string) $row['resource'],
            'state'        => null === $row['state'] ? null : (string) $row['state'],
            'status'       => (string) $row['status'],
            'request_key'  => (string) $row['request_key'],
            'expired'      => (int) $row['expires_at'] <= $this->now(),
        );
    }

    /**
     * End a pending consent request without a decision, once. A request
     * already claimed, approved or denied is left as it is.
     *
     * @param int $request_id Request id.
     * @return bool Whether this call failed it.
     */
    public function fail_request( int $request_id ): bool {
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = \'failed\' WHERE id = %d AND status = \'pending\'',
                $this->table( 'requests' ),
                $request_id
            )
        );
    }

    /**
     * Exchange an authorization code in the numbered steps below; a code that isn't live goes to
     * the replay rules.
     *
     * @param string      $code               Presented code.
     * @param string      $client_id          Presented client_id.
     * @param string      $code_verifier      PKCE verifier.
     * @param string|null $redirect_uri       redirect_uri, or null when omitted.
     * @param string|null $requested_resource resource, or null when omitted.
     * @return array Result; on success `grant_id`, `pair`, `access_token`, `refresh_token`, `expires_in`, `scope`.
     *               A confirmed code replay includes only `grant_id`, `user_id`, `client_id` beside outcome/event.
     */
    public function redeem_code( string $code, string $client_id, string $code_verifier, ?string $redirect_uri = null, ?string $requested_resource = null ): array {
        if ( ! $this->hasher->matches_type( $code, OAuth_Token_Hasher::TYPE_CODE ) ) {
            return $this->result( self::INVALID_GRANT );
        }
        $hash = $this->hasher->hash( $code, $this->resource );
        $now  = $this->now();

        // Step 0: anything but a live, unexpired code goes to the replay rules without writing, so
        // random-code spam doesn't churn grant rows.
        $row = $this->find_code( $hash );
        if ( null === $row || 'live' !== $row['status'] || (int) $row['expires_at'] <= $now ) {
            return $this->refuse_presented_code( $row, $client_id, $code_verifier, $redirect_uri, $requested_resource );
        }
        // Connection tokens are minted in the panel, never through an OAuth flow.
        if ( self::CONNECTION_TOKEN_CLIENT_ID === $row['client_id'] ) {
            return $this->result( self::INVALID_GRANT );
        }
        $this->after_code_read();

        // Step 1. The claim below compares expiry against the time it runs, not the time of the read.
        $now         = $this->now();
        $session_end = $now + self::SESSION_LIFETIME;
        $grant_id    = $this->insert_pending_grant( $row, $now, $session_end );
        if ( 0 === $grant_id ) {
            return $this->result( self::SERVER_ERROR );
        }

        // Step 2: claim the code and publish the grant id in one statement, so a replay arriving any
        // time after this finds the grant it has to revoke.
        $claimed = $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = \'redeemed\', redeemed_at = %d, grant_id = %d WHERE code_hash = %s AND status = \'live\' AND expires_at > %d',
                $this->table( 'codes' ),
                $now,
                $grant_id,
                $hash,
                $now
            )
        );
        if ( 1 !== $claimed ) {
            // Result ignored: a pending grant left behind is never published and has no tokens, and
            // purge removes it after its session window.
            $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE id = %d AND status = \'pending\'', $this->table( 'grants' ), $grant_id ) );
            if ( false === $claimed ) {
                // A SQL error, not a lost race: the code is still live, so the client may retry.
                return $this->result( self::SERVER_ERROR );
            }
            return $this->refuse_presented_code( $this->find_code( $hash ), $client_id, $code_verifier, $redirect_uri, $requested_resource );
        }
        $this->after_claim( $grant_id );

        // Step 3: only the claimant checks the request against the code and rechecks the user.
        $mismatch = $this->code_request_mismatch( $row, $client_id, $code_verifier, $redirect_uri, $requested_resource );
        if ( null === $mismatch && ( null !== $this->user_failure( (int) $row['user_id'] ) || ! $this->generations_current( $row ) ) ) {
            $mismatch = 'user';
        }
        if ( null !== $mismatch ) {
            // Results ignored: the grant is pending with no tokens and is never published, and a code
            // left `redeemed` only lets a later valid replay revoke that dead grant again.
            $this->db->query( $this->db->prepare( 'UPDATE %i SET status = \'burned\' WHERE id = %d AND status = \'redeemed\'', $this->table( 'codes' ), $row['id'] ) );
            $this->revoke_statement( $grant_id, 'issue_failed' );
            return $this->result( 'resource' === $mismatch ? self::INVALID_TARGET : self::INVALID_GRANT, 'issue_failed' );
        }

        // Step 4.
        $grant  = array(
            'id'                 => $grant_id,
            'scope'              => $row['scope'],
            'resource'           => $row['resource'],
            'session_expires_at' => $session_end,
        );
        $tokens = $this->insert_pair( $grant, 1, $now );
        if ( null === $tokens ) {
            // Result ignored: the grant is pending and never published, so any token already inserted is dead.
            $this->revoke_statement( $grant_id, 'issue_failed' );
            return $this->result( self::SERVER_ERROR, 'issue_failed' );
        }
        $this->before_publish( $grant_id, $tokens );

        // Step 5: publish only while the grant is still pending and both generations are current. A
        // generation bump lands either before this statement (it matches nothing) or after it (the
        // trigger's revoke, which runs after the bump, catches the live grant).
        $published = $this->publish_grant( $grant_id );
        if ( 1 !== $published ) {
            // Either a valid replay already revoked the grant, a generation moved, or the statement
            // failed. The pair-1 tokens are dead in every case, because the grant never became live,
            // so the revoke's result only names the event.
            $event = 1 === $this->revoke_statement( $grant_id, 'generation_changed' ) ? 'generation_changed' : 'grant_revoked';
            return $this->result( false === $published ? self::SERVER_ERROR : self::INVALID_GRANT, $event );
        }

        if ( ! $this->enforce_live_cap( (int) $row['user_id'], (string) $row['client_id'] ) ) {
            // More than five live grants may remain, so the new one gives way and its tokens are never handed out.
            $this->revoke_confirmed( $grant_id, 'cap_limit' );
            return $this->result( self::SERVER_ERROR, 'cap_limit' );
        }
        return $this->issued( $grant, 1, $tokens, $now );
    }

    /**
     * Check an access token and record its use.
     *
     * @param string $token Presented bearer token.
     * @return array Result; on success `grant_id`, `user_id`, `client_id`, `scope`, `resource`.
     */
    public function check_access_token( string $token ): array {
        for ( $attempt = 0; $attempt <= self::MAX_REREADS; $attempt++ ) {
            $row = $this->lookup_token( $token, OAuth_Token_Hasher::TYPE_ACCESS );
            $now = $this->now();
            if ( null === $row || ! $this->grant_usable( $row, $now ) || $now >= (int) $row['token_expires_at'] ) {
                return $this->result( self::INVALID_TOKEN );
            }

            $pair    = (int) $row['pair'];
            $current = (int) $row['current_pair'];
            $sibling = null === $row['sibling_pair'] ? null : (int) $row['sibling_pair'];
            if ( ! in_array( $pair, $this->accepted_access_pairs( $row ), true ) ) {
                return $this->result( self::INVALID_TOKEN );
            }

            if ( null !== $sibling && ( $pair === $current || $pair === $sibling ) ) {
                // First use of either grace twin selects it; the other stops being accepted.
                $this->after_grant_read( 'access_select' );
                $updated = $this->db->query(
                    $this->db->prepare(
                        'UPDATE %i SET current_pair = %d, sibling_pair = NULL, current_used = 1, version = version + 1 WHERE id = %d AND version = %d',
                        $this->table( 'grants' ),
                        $pair,
                        $row['id'],
                        $row['version']
                    )
                );
            } elseif ( $pair === $current && 0 === (int) $row['current_used'] ) {
                $this->after_grant_read( 'access_first_use' );
                $updated = $this->db->query(
                    $this->db->prepare(
                        'UPDATE %i SET current_used = 1, version = version + 1 WHERE id = %d AND version = %d',
                        $this->table( 'grants' ),
                        $row['id'],
                        $row['version']
                    )
                );
            } else {
                return $this->access_granted( $row, OAuth_Token_Hasher::TYPE_ACCESS );
            }

            if ( 1 === $updated ) {
                return $this->access_granted( $row, OAuth_Token_Hasher::TYPE_ACCESS );
            }
            // Lost the race: re-read, because the token may have become unusable (its twin won).
        }
        return $this->result( self::RETRY );
    }

    /**
     * Rotate a refresh token, with a grace window for a duplicate refresh and the replay rules.
     *
     * The new pair carries the grant's current scope, so a grant narrowed in the panel stays
     * narrowed. A `scope` sent with the refresh may narrow that further, never widen it.
     *
     * @param string      $token     Presented refresh token.
     * @param string      $client_id Presented client_id.
     * @param string|null $scope     Requested scope, or null when omitted.
     * @return array Result; on success the same fields as redeem_code().
     */
    public function refresh( string $token, string $client_id, ?string $scope = null ): array {
        if ( ! $this->hasher->matches_type( $token, OAuth_Token_Hasher::TYPE_REFRESH ) ) {
            return $this->result( self::INVALID_GRANT );
        }
        $requested = null === $scope ? null : self::scope_tokens( $scope );
        // Write implies read, refused before either refresh transition runs.
        if ( null !== $requested && ( empty( $requested ) || array_diff( $requested, self::SCOPES )
            || ( in_array( self::SCOPE_WRITE, $requested, true ) && ! in_array( self::SCOPE_READ, $requested, true ) ) ) ) {
            return $this->result( self::INVALID_SCOPE );
        }

        for ( $attempt = 0; $attempt <= self::MAX_REREADS; $attempt++ ) {
            $row = $this->lookup_token( $token, OAuth_Token_Hasher::TYPE_REFRESH );
            $now = $this->now();
            if ( null === $row || ! hash_equals( (string) $row['client_id'], $client_id ) ) {
                return $this->result( self::INVALID_GRANT );
            }
            if ( 'live' !== $row['status'] || $now >= (int) $row['session_expires_at'] ) {
                return $this->result( self::INVALID_GRANT );
            }
            // A stale generation is a revocation that hasn't reached this grant row yet, not a replay.
            if ( ! $this->generations_current( $row ) ) {
                // Result ignored: every lookup compares generations, so this grant's tokens are refused
                // whether or not the row says revoked.
                $this->revoke_statement( (int) $row['id'], 'generation_changed' );
                return $this->result( self::INVALID_GRANT, 'generation_changed' );
            }
            $user_failure = $this->user_failure( (int) $row['user_id'] );
            if ( null !== $user_failure ) {
                // Access checks don't recheck the user, so the family must be confirmed revoked here.
                if ( ! $this->revoke_confirmed( (int) $row['id'], $user_failure ) ) {
                    return $this->result( self::SERVER_ERROR, $user_failure );
                }
                return $this->result( self::INVALID_GRANT, $user_failure );
            }

            $pair     = (int) $row['pair'];
            $previous = null === $row['previous_pair'] ? null : (int) $row['previous_pair'];
            $sibling  = null === $row['sibling_pair'] ? null : (int) $row['sibling_pair'];
            // Computed in PHP: MySQL applies SET assignments left to right, so pair_seq = pair_seq + 1,
            // current_pair = pair_seq + 1 would skip a number.
            $next = (int) $row['pair_seq'] + 1;

            if ( (int) $row['current_pair'] === $pair || $sibling === $pair ) {
                // An idle-expired token of the pair in use is plain invalid_grant, not replay.
                if ( $now >= (int) $row['token_expires_at'] ) {
                    return $this->result( self::INVALID_GRANT );
                }
                $refusal = $this->narrow_for_refresh( $row, $requested );
                if ( null !== $refusal ) {
                    return $this->result( $refusal );
                }
                $this->after_grant_read( 'rotate' );
                $updated = $this->db->query(
                    $this->db->prepare(
                        'UPDATE %i SET previous_pair = %d, previous_rotated_at = %d, grace_used = 0, pair_seq = %d, current_pair = %d, current_used = 0, sibling_pair = NULL, version = version + 1 WHERE id = %d AND version = %d',
                        $this->table( 'grants' ),
                        $pair,
                        $now,
                        $next,
                        $next,
                        $row['id'],
                        $row['version']
                    )
                );
                if ( 1 === $updated ) {
                    return $this->mint( $row, $next, $now );
                }
                continue;
            }

            if ( $previous === $pair && $now - (int) $row['previous_rotated_at'] <= self::GRACE_SECONDS ) {
                // An expired token never earns a sibling. Inside the window it isn't replay either: the
                // same rule as an idle-expired token of the current pair.
                if ( $now >= (int) $row['token_expires_at'] ) {
                    return $this->result( self::INVALID_GRANT );
                }
                // A client that already used the successor, or already got the sibling, holds a working
                // pair: this is a stale duplicate, not a breach signal.
                if ( 0 !== (int) $row['grace_used'] || 0 !== (int) $row['current_used'] || null !== $sibling ) {
                    return $this->result( self::INVALID_GRANT, 'refresh_duplicate_late' );
                }
                $refusal = $this->narrow_for_refresh( $row, $requested );
                if ( null !== $refusal ) {
                    return $this->result( $refusal );
                }
                $this->after_grace_check();
                // Requires the version it read: a successor used since then bumped it, so no sibling
                // can be created after its twin was used.
                $updated = $this->db->query(
                    $this->db->prepare(
                        'UPDATE %i SET sibling_pair = %d, pair_seq = %d, grace_used = 1, version = version + 1 WHERE id = %d AND version = %d',
                        $this->table( 'grants' ),
                        $next,
                        $next,
                        $row['id'],
                        $row['version']
                    )
                );
                if ( 1 === $updated ) {
                    return $this->mint( $row, $next, $now );
                }
                continue;
            }

            // Any other pair, expired or not, is replay of an invalidated token: OAuth 2.1 4.3.1.
            if ( ! $this->revoke_confirmed( (int) $row['id'], 'refresh_replay' ) ) {
                return $this->result( self::SERVER_ERROR, 'refresh_replay' );
            }
            return $this->result( self::INVALID_GRANT, 'refresh_replay' );
        }
        return $this->result( self::RETRY );
    }

    /**
     * Find a stored token with its grant and the current generations.
     *
     * @param string $token Presented credential.
     * @param string $type  Expected OAuth_Token_Hasher::TYPE_ACCESS or TYPE_REFRESH.
     * @return array|null Grant columns plus token_id, pair, token_type, token_scope, token_resource,
     *                    secret_version, token_expires_at, current_user_generation, current_site_generation.
     */
    public function lookup_token( string $token, string $type ): ?array {
        if ( ! $this->hasher->matches_type( $token, $type ) ) {
            return null;
        }
        $hash = $this->hasher->hash( $token, $this->resource );
        $row  = $this->db->get_row(
            $this->db->prepare(
                'SELECT g.*, t.id AS token_id, t.token_hash, t.token_type, t.pair, t.scope AS token_scope, t.resource AS token_resource,
                t.secret_version, t.expires_at AS token_expires_at,
                COALESCE(u.generation, 0) AS current_user_generation, COALESCE(st.generation, 0) AS current_site_generation
                FROM %i t INNER JOIN %i g ON g.id = t.grant_id
                LEFT JOIN %i u ON u.subject_id = g.user_id LEFT JOIN %i st ON st.subject_id = 0
                WHERE t.token_hash = %s',
                $this->table( 'tokens' ),
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                $hash
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) || ! hash_equals( (string) $row['token_hash'], $hash ) ) {
            return null;
        }
        if ( (int) $row['secret_version'] !== $this->hasher->secret_version() || $type !== $row['token_type'] ) {
            return null;
        }
        return $row;
    }

    /**
     * Grant row.
     *
     * @param int $grant_id Grant id.
     * @return array|null
     */
    public function get_grant( int $grant_id ): ?array {
        $row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'grants' ), $grant_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Revoke one family. Not version-conditional: revocation always wins.
     *
     * @param int    $grant_id Grant id.
     * @param string $reason   Revoke reason.
     * @return bool Whether this call revoked it; false when it was already revoked.
     * @throws \RuntimeException When the statement keeps failing and the grant isn't revoked.
     */
    public function revoke_grant( int $grant_id, string $reason ): bool {
        for ( $attempt = 0; $attempt < self::SQL_ATTEMPTS; $attempt++ ) {
            $result = $this->revoke_statement( $grant_id, $reason );
            if ( false !== $result ) {
                return 1 === $result;
            }
        }
        if ( $this->grant_revoked( $grant_id ) ) {
            return false;
        }
        throw new \RuntimeException( 'The connection could not be revoked.' );
    }

    /**
     * Revoke a family and confirm it, so a SQL error is never reported as done while the family may
     * still be live. Zero affected rows means it was already revoked, which is fine; a failed
     * statement is retried, then the row is read back.
     *
     * @param int    $grant_id Grant id.
     * @param string $reason   Revoke reason.
     * @return bool Whether the grant is now revoked.
     */
    private function revoke_confirmed( int $grant_id, string $reason ): bool {
        for ( $attempt = 0; $attempt < self::SQL_ATTEMPTS; $attempt++ ) {
            if ( false !== $this->revoke_statement( $grant_id, $reason ) ) {
                break;
            }
        }
        return $this->grant_revoked( $grant_id );
    }

    /**
     * Whether a grant row reads as revoked.
     *
     * @param int $grant_id Grant id.
     * @return bool
     */
    private function grant_revoked( int $grant_id ): bool {
        $grant = $this->get_grant( $grant_id );
        return null !== $grant && 'revoked' === $grant['status'];
    }

    /**
     * Run a statement, retrying SQL errors.
     *
     * @param string $sql Prepared statement.
     * @return int|false Affected rows, or false when every attempt failed.
     */
    private function query_with_retry( string $sql ) {
        for ( $attempt = 0; $attempt < self::SQL_ATTEMPTS; $attempt++ ) {
            $result = $this->db->query( $sql );
            if ( false !== $result ) {
                return $result;
            }
        }
        return false;
    }

    /**
     * The family revoke statement.
     *
     * @param int    $grant_id Grant id.
     * @param string $reason   Revoke reason.
     * @return int|false Affected rows, or false on a SQL error.
     */
    private function revoke_statement( int $grant_id, string $reason ) {
        $result = $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = \'revoked\', revoke_reason = %s, revoked_at = %d, version = version + 1 WHERE id = %d AND status IN (\'pending\', \'live\')',
                $this->table( 'grants' ),
                $reason,
                $this->now(),
                $grant_id
            )
        );
        if ( 1 === $result ) {
            $this->end_grant_work( $grant_id );
        }
        return $result;
    }

    /**
     * Bump one generation atomically; a missing row reads as 0, so the first bump creates it at 1.
     * A failed statement is retried: a bump that never lands leaves requests and codes issued before
     * the trigger usable.
     *
     * @param int $subject_id User id, or 0 for the site.
     * @return bool Whether the bump persisted.
     */
    public function bump_generation( int $subject_id ): bool {
        $sql = $this->db->prepare(
            'INSERT INTO %i (subject_id, generation) VALUES (%d, 1) ON DUPLICATE KEY UPDATE generation = generation + 1',
            $this->table( 'generations' ),
            $subject_id
        );
        for ( $attempt = 0; $attempt < self::SQL_ATTEMPTS; $attempt++ ) {
            $result = $this->db->query( $sql );
            if ( is_int( $result ) && $result > 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * User revocation trigger: bump the user's generation, then revoke their pending and live grants.
     *
     * The grants are revoked even when the bump failed, since that is still right, but the failure is
     * reported so the caller can log it and refuse to report success.
     *
     * @param int    $user_id User id.
     * @param string $reason  Revoke reason.
     * @return int|null Grants revoked, or null when the generation bump or the revoke statement could
     *                  not be persisted.
     */
    public function bump_user_generation( int $user_id, string $reason ): ?int {
        if ( $user_id <= 0 ) {
            return 0;
        }
        $persisted = $this->bump_generation( $user_id );
        $revoked   = $this->query_with_retry(
            $this->db->prepare(
                'UPDATE %i SET status = \'revoked\', revoke_reason = %s, revoked_at = %d, version = version + 1 WHERE user_id = %d AND status IN (\'pending\', \'live\')',
                $this->table( 'grants' ),
                $reason,
                $this->now(),
                $user_id
            )
        );
        $this->end_grant_work( null );
        return $persisted && false !== $revoked ? $revoked : null;
    }

    /**
     * Site revocation trigger: bump the site generation, then revoke every pending and live grant.
     *
     * Grants are revoked even when the bump failed; see bump_user_generation().
     *
     * @param string $reason Revoke reason.
     * @return int|null Grants revoked, or null when the generation bump or the revoke statement could
     *                  not be persisted.
     */
    public function bump_site_generation( string $reason ): ?int {
        $persisted = $this->bump_generation( 0 );
        $revoked   = $this->query_with_retry(
            $this->db->prepare(
                'UPDATE %i SET status = \'revoked\', revoke_reason = %s, revoked_at = %d, version = version + 1 WHERE status IN (\'pending\', \'live\')',
                $this->table( 'grants' ),
                $reason,
                $this->now()
            )
        );
        $this->end_grant_work( null );
        return $persisted && false !== $revoked ? $revoked : null;
    }

    /**
     * Rotate the token secret and end every session.
     *
     * @return int New secret version.
     * @throws \RuntimeException When the secret or the site generation could not be written. Every
     *                           grant is revoked first either way, so sessions end even then.
     */
    public function rotate_secret(): int {
        $secret_failure = null;
        try {
            $version = $this->hasher->rotate_secret();
        } catch ( \RuntimeException $e ) {
            $secret_failure = $e;
        }
        $revoked = $this->bump_site_generation( 'secret_rotated' );
        if ( null !== $secret_failure ) {
            throw $secret_failure;
        }
        // Consent requests aren't hashed with the secret, so only the site bump ends the ones opened
        // before the rotation.
        if ( null === $revoked ) {
            throw new \RuntimeException( 'The secret was rotated, but consent requests opened before it could not be ended. Retry the rotation.' );
        }
        return $version;
    }

    /**
     * Delete rows past retention. Generation rows are never purged: deleting one would reset that
     * user's generation to 0 and could revive old rows.
     * Expired connection-token grants follow the grant rule, since their session ends at expiry.
     */
    public function purge(): void {
        $now           = $this->now();
        $expired_until = $now - self::EXPIRED_RETENTION;
        $grant_until   = $now - self::GRANT_RETENTION;
        $grants        = $this->table( 'grants' );
        $tokens        = $this->table( 'tokens' );
        $codes         = $this->table( 'codes' );

        // Grants 30 days after revocation or session end, with every token and code they own.
        $ids = $this->db->get_col(
            $this->db->prepare(
                'SELECT id FROM %i WHERE ( status = \'revoked\' AND revoked_at < %d ) OR ( status <> \'revoked\' AND session_expires_at < %d )',
                $grants,
                $grant_until,
                $grant_until
            )
        );
        foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
            $in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            // Children first, and the grant rows only when both child deletes ran, so a failure leaves the
            // grant row for tomorrow's purge instead of orphaned tokens or codes nothing would find again.
            $tokens_deleted = $this->db->query( $this->db->prepare( "DELETE FROM %i WHERE grant_id IN ({$in})", $tokens, ...$chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d placeholders, one per id.
            $codes_deleted  = $this->db->query( $this->db->prepare( "DELETE FROM %i WHERE grant_id IN ({$in})", $codes, ...$chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d placeholders, one per id.
            if ( false === $tokens_deleted || false === $codes_deleted ) {
                continue;
            }
            $work_deleted = $this->db->query( $this->db->prepare( "DELETE FROM %i WHERE grant_id IN ({$in})", $this->table( 'approvals' ), ...$chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d placeholders, one per id.
            if ( false === $work_deleted || false === $this->db->query( $this->db->prepare( "DELETE FROM %i WHERE grant_id IN ({$in})", $this->table( 'jobs' ), ...$chunk ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d placeholders, one per id.
                continue;
            }
            $this->db->query( $this->db->prepare( "DELETE FROM %i WHERE id IN ({$in})", $grants, ...$chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds only %d placeholders, one per id.
        }

        // Access tokens of pairs no longer accepted, a day after they expire. Access replay never
        // revokes, so they aren't evidence; refresh tokens and redeemed codes stay with their grant.
        $this->db->query(
            $this->db->prepare(
                'DELETE t FROM %i t INNER JOIN %i g ON g.id = t.grant_id
                WHERE t.token_type = \'access\' AND t.expires_at < %d
                AND ( g.status <> \'live\' OR t.pair NOT IN ( g.current_pair, COALESCE(g.sibling_pair, 0), COALESCE(g.previous_pair, 0) ) )',
                $tokens,
                $grants,
                $expired_until
            )
        );

        // Burned and expired unredeemed codes, and expired consent requests.
        $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE status IN (\'burned\', \'live\') AND expires_at < %d', $codes, $expired_until ) );
        $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE expires_at < %d', $this->table( 'requests' ), $expired_until ) );

        // Delayed jobs still need their consumed approval's authority on every tick.
        $this->db->query(
            $this->db->prepare(
                'DELETE FROM %i WHERE expires_at < %d
                AND id NOT IN (SELECT approval_id FROM %i WHERE status IN (\'starting\', \'running\') AND approval_id IS NOT NULL)',
                $this->table( 'approvals' ),
                $expired_until,
                $this->table( 'jobs' )
            )
        );

        // Jobs that lost their consume, and finished jobs on the grant schedule.
        $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE status = \'starting\' AND created_at < %d', $this->table( 'jobs' ), $now - self::STARTING_JOB_TTL ) );
        $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE status IN (\'done\', \'failed\', \'cancelled\') AND updated_at < %d', $this->table( 'jobs' ), $grant_until ) );

        // Registrations nobody ever consented to. Consented ones stay until removed.
        $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE kind = \'registered\' AND first_consented_at IS NULL AND created_at < %d', $this->table( 'clients' ), $now - self::UNCONSENTED_CLIENT_TTL ) );
    }

    /**
     * Full table name.
     *
     * @param string $name Short name.
     * @return string
     */
    private function table( string $name ): string {
        return Remote_MCP_Install::table( $name );
    }

    /**
     * Result array.
     *
     * @param string      $outcome Outcome constant.
     * @param string|null $event   Audit event.
     * @param array       $fields  Extra fields.
     * @return array
     */
    private function result( string $outcome, ?string $event = null, array $fields = array() ): array {
        return array_merge(
            array(
                'outcome' => $outcome,
                'event'   => $event,
            ),
            $fields
        );
    }

    /**
     * Current user and site generations, 0 for a missing row.
     *
     * @param int $user_id User id.
     * @return array `user` and `site`.
     */
    private function current_generations( int $user_id ): array {
        $rows        = $this->db->get_results(
            $this->db->prepare( 'SELECT subject_id, generation FROM %i WHERE subject_id IN (0, %d)', $this->table( 'generations' ), $user_id ),
            ARRAY_A
        );
        $generations = array(
            'user' => 0,
            'site' => 0,
        );
        foreach ( (array) $rows as $row ) {
            $generations[ 0 === (int) $row['subject_id'] ? 'site' : 'user' ] = (int) $row['generation'];
        }
        return $generations;
    }

    /**
     * Whether a row's recorded generations equal the current ones.
     *
     * @param array $row Code or lookup row.
     * @return bool
     */
    private function generations_current( array $row ): bool {
        if ( isset( $row['current_user_generation'] ) ) {
            $current = array(
                'user' => (int) $row['current_user_generation'],
                'site' => (int) $row['current_site_generation'],
            );
        } else {
            $current = $this->current_generations( (int) $row['user_id'] );
        }
        return (int) $row['user_generation'] === $current['user'] && (int) $row['site_generation'] === $current['site'];
    }

    /**
     * Grant-level usability from a lookup row: live, generations current, session not ended.
     *
     * @param array $row Lookup row.
     * @param int   $now Current time.
     * @return bool
     */
    private function grant_usable( array $row, int $now ): bool {
        return 'live' === $row['status'] && $this->generations_current( $row ) && $now < (int) $row['session_expires_at'];
    }

    /**
     * Pairs whose access tokens the grant accepts: current, sibling and previous, at most three.
     *
     * @param array $row Lookup row.
     * @return int[]
     */
    private function accepted_access_pairs( array $row ): array {
        $pairs = array( (int) $row['current_pair'] );
        foreach ( array( 'sibling_pair', 'previous_pair' ) as $column ) {
            if ( null !== $row[ $column ] ) {
                $pairs[] = (int) $row[ $column ];
            }
        }
        return $pairs;
    }

    /**
     * Why the code's user can no longer hold a connection, or null.
     *
     * @param int $user_id User id.
     * @return string|null `user_deleted`, `cap_lost` or null.
     */
    private function user_failure( int $user_id ): ?string {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return 'user_deleted';
        }
        return user_can( $user, 'manage_options' ) ? null : 'cap_lost';
    }

    /**
     * Code row by hash.
     *
     * @param string $hash Code hash.
     * @return array|null
     */
    private function find_code( string $hash ): ?array {
        $row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE code_hash = %s', $this->table( 'codes' ), $hash ), ARRAY_A );
        return is_array( $row ) && hash_equals( (string) $row['code_hash'], $hash ) ? $row : null;
    }

    /**
     * First mismatch between a token request and the code row, or null.
     * redirect_uri and resource are compared only when supplied (OAuth 2.1 10.2, RFC 8707 2.2).
     *
     * @param array       $row                Code row.
     * @param string      $client_id          Presented client_id.
     * @param string      $code_verifier      PKCE verifier.
     * @param string|null $redirect_uri       redirect_uri or null.
     * @param string|null $requested_resource resource or null.
     * @return string|null
     */
    private function code_request_mismatch( array $row, string $client_id, string $code_verifier, ?string $redirect_uri, ?string $requested_resource ): ?string {
        if ( ! hash_equals( (string) $row['client_id'], $client_id ) ) {
            return 'client_id';
        }
        if ( 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/D', $code_verifier )
            || ! hash_equals( (string) $row['code_challenge'], OAuth_Token_Hasher::base64url( hash( 'sha256', $code_verifier, true ) ) ) ) {
            return 'code_verifier';
        }
        if ( null !== $redirect_uri && $redirect_uri !== $row['redirect_uri'] ) {
            return 'redirect_uri';
        }
        if ( null !== $requested_resource && $requested_resource !== $row['resource'] ) {
            return 'resource';
        }
        return null;
    }

    /**
     * Refuse a code that isn't live. A redeemed code presented again with valid parameters
     * revokes its grant; with any invalid parameter it revokes nothing, so someone holding only a
     * stolen code can't end the legitimate connection.
     *
     * @param array|null  $row                Code row or null.
     * @param string      $client_id          Presented client_id.
     * @param string      $code_verifier      PKCE verifier.
     * @param string|null $redirect_uri       redirect_uri or null.
     * @param string|null $requested_resource resource or null.
     * @return array
     */
    private function refuse_presented_code( ?array $row, string $client_id, string $code_verifier, ?string $redirect_uri, ?string $requested_resource ): array {
        if ( null === $row || 'redeemed' !== $row['status'] ) {
            return $this->result( self::INVALID_GRANT );
        }
        if ( null !== $this->code_request_mismatch( $row, $client_id, $code_verifier, $redirect_uri, $requested_resource ) ) {
            return $this->result( self::INVALID_GRANT, 'code_replay_invalid' );
        }
        $outcome = $this->revoke_confirmed( (int) $row['grant_id'], 'code_replay' ) ? self::INVALID_GRANT : self::SERVER_ERROR;
        return $this->result(
            $outcome,
            'code_replay',
            array(
                'grant_id'  => (int) $row['grant_id'],
                'user_id'   => (int) $row['user_id'],
                'client_id' => (string) $row['client_id'],
            )
        );
    }

    /**
     * Insert the pending grant for a code (step 1), copying the code's generations, scope and
     * approval policy.
     *
     * @param array $code        Code row.
     * @param int   $now         Current time.
     * @param int   $session_end Absolute session end, set once.
     * @return int Grant id, or 0 on failure.
     */
    private function insert_pending_grant( array $code, int $now, int $session_end ): int {
        // Codes don't carry client_host; it is derived from the validated client_id the same way the
        // request's was, for display and logs only.
        $host     = wp_parse_url( (string) $code['client_id'], PHP_URL_HOST );
        $inserted = $this->db->insert(
            $this->table( 'grants' ),
            array(
                'user_id'            => (int) $code['user_id'],
                'client_id'          => (string) $code['client_id'],
                'client_host'        => is_string( $host ) ? $host : '',
                'scope'              => (string) $code['scope'],
                'resource'           => (string) $code['resource'],
                'status'             => 'pending',
                'version'            => 0,
                'pair_seq'           => 1,
                'current_pair'       => 1,
                'current_used'       => 0,
                'grace_used'         => 0,
                'user_generation'    => (int) $code['user_generation'],
                'site_generation'    => (int) $code['site_generation'],
                'created_at'         => $now,
                'session_expires_at' => $session_end,
                'last_used_at'       => $now,
                'approval_policy'    => (string) $code['approval_policy'],
                'policy_version'     => 1,
                'client_kind'        => self::client_kind_for( (string) $code['client_id'] ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
        );
        return 1 === $inserted ? (int) $this->db->insert_id : 0;
    }

    /**
     * Insert one pair: an access token, plus a refresh token when offline_access was granted.
     * Both expire no later than the session end.
     *
     * @param array $grant `id`, `scope`, `resource`, `session_expires_at`.
     * @param int   $pair  Pair number the grant row already names.
     * @param int   $now   Current time.
     * @return array|null `access_token`, `refresh_token` (or null), `access_expires_at`; null on failure.
     */
    private function insert_pair( array $grant, int $pair, int $now ): ?array {
        $session = (int) $grant['session_expires_at'];
        $minted  = array(
            'access_token'      => $this->hasher->generate( OAuth_Token_Hasher::TYPE_ACCESS ),
            'refresh_token'     => null,
            'access_expires_at' => min( $now + self::ACCESS_LIFETIME, $session ),
        );
        $rows    = array( array( OAuth_Token_Hasher::TYPE_ACCESS, $minted['access_token'], $minted['access_expires_at'] ) );
        if ( in_array( 'offline_access', explode( ' ', (string) $grant['scope'] ), true ) ) {
            $minted['refresh_token'] = $this->hasher->generate( OAuth_Token_Hasher::TYPE_REFRESH );
            $rows[]                  = array( OAuth_Token_Hasher::TYPE_REFRESH, $minted['refresh_token'], min( $now + self::REFRESH_LIFETIME, $session ) );
        }

        $version = $this->hasher->secret_version();
        foreach ( $rows as list( $type, $token, $expires_at ) ) {
            $inserted = $this->db->insert(
                $this->table( 'tokens' ),
                array(
                    'token_hash'     => $this->hasher->hash( $token, $this->resource ),
                    'token_type'     => $type,
                    'grant_id'       => (int) $grant['id'],
                    'pair'           => $pair,
                    'scope'          => (string) $grant['scope'],
                    'resource'       => (string) $grant['resource'],
                    'secret_version' => $version,
                    'created_at'     => $now,
                    'expires_at'     => $expires_at,
                ),
                array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d' )
            );
            if ( 1 !== $inserted ) {
                return null;
            }
        }
        return $minted;
    }

    /**
     * Insert the pair a refresh transition just allocated. The grant already names it, and nobody can
     * present these tokens before the response carries them. If the insert fails the client still holds
     * its old pair, now previous_pair, which is eligible for one grace retry.
     *
     * @param array $row  Lookup row.
     * @param int   $pair Allocated pair.
     * @param int   $now  Current time.
     * @return array
     */
    private function mint( array $row, int $pair, int $now ): array {
        $tokens = $this->insert_pair( $row, $pair, $now );
        if ( null === $tokens ) {
            return $this->result( self::SERVER_ERROR );
        }
        return $this->issued( $row, $pair, $tokens, $now );
    }

    /**
     * Successful token response fields.
     *
     * @param array $grant  Grant row with `id` and `scope`.
     * @param int   $pair   Pair number.
     * @param array $tokens Minted tokens.
     * @param int   $now    Current time.
     * @return array
     */
    private function issued( array $grant, int $pair, array $tokens, int $now ): array {
        return $this->result(
            self::OK,
            null,
            array(
                'grant_id'      => (int) $grant['id'],
                'pair'          => $pair,
                'access_token'  => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in'    => $tokens['access_expires_at'] - $now,
                'scope'         => (string) $grant['scope'],
            )
        );
    }

    /**
     * Successful bearer check fields. `scope` is the effective scope: the token's scope intersected
     * with the grant's current scope, so narrowing a grant takes effect on the next request without
     * reissuing tokens.
     *
     * @param array  $row  Lookup row.
     * @param string $type Token type that was checked.
     * @return array
     */
    private function access_granted( array $row, string $type ): array {
        return $this->result(
            self::OK,
            null,
            array(
                'grant_id'        => (int) $row['id'],
                'user_id'         => (int) $row['user_id'],
                'client_id'       => (string) $row['client_id'],
                'client_kind'     => (string) $row['client_kind'],
                'scope'           => self::intersect_scopes( (string) $row['token_scope'], (string) $row['scope'] ),
                'token_scope'     => (string) $row['token_scope'],
                'grant_scope'     => (string) $row['scope'],
                'resource'        => (string) $row['token_resource'],
                'approval_policy' => (string) $row['approval_policy'],
                'policy_version'  => (int) $row['policy_version'],
                'token_type'      => $type,
            )
        );
    }

    /**
     * Revoke the oldest live grants beyond the per-user, per-client cap. Two concurrent
     * publications may both revoke the same oldest grant; revocation is idempotent, so the end
     * state is the newest five.
     *
     * @param int    $user_id   User id.
     * @param string $client_id Client id.
     * @return bool Whether the cap is confirmed enforced.
     */
    private function enforce_live_cap( int $user_id, string $client_id ): bool {
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT id, client_id FROM %i WHERE user_id = %d AND client_id = %s AND status = \'live\' ORDER BY created_at DESC, id DESC',
                $this->table( 'grants' ),
                $user_id,
                $client_id
            ),
            ARRAY_A
        );
        // get_results() returns an empty array on a SQL error too, which would read as "under the cap".
        if ( '' !== $this->db->last_error ) {
            return false;
        }
        // The column's collation is usually case-insensitive, but client_id URLs are distinct clients
        // whenever their bytes differ.
        $ids = array();
        foreach ( (array) $rows as $row ) {
            if ( $client_id === $row['client_id'] ) {
                $ids[] = (int) $row['id'];
            }
        }
        foreach ( array_slice( $ids, self::LIVE_GRANT_CAP ) as $id ) {
            if ( ! $this->revoke_confirmed( (int) $id, 'cap_limit' ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Publish a pending grant, conditional on both generations (step 5 of redeem_code()). A
     * generation bump lands either before this statement (it matches nothing) or after it (the
     * trigger's revoke, which runs after the bump, catches the live grant). Code exchange and
     * connection-token minting both publish through here.
     *
     * @param int $grant_id Pending grant.
     * @return int|false Affected rows, or false on a SQL error.
     */
    private function publish_grant( int $grant_id ) {
        return $this->db->query(
            $this->db->prepare(
                'UPDATE %i g LEFT JOIN %i u ON u.subject_id = g.user_id LEFT JOIN %i st ON st.subject_id = 0
                SET g.status = \'live\', g.version = g.version + 1
                WHERE g.id = %d AND g.status = \'pending\'
                AND g.user_generation = COALESCE(u.generation, 0) AND g.site_generation = COALESCE(st.generation, 0)',
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                $grant_id
            )
        );
    }

    /**
     * Apply a refresh's scope to the pair about to be minted, or say why it can't be minted.
     *
     * @param array      $row       Lookup row; its `scope` becomes the new pair's scope.
     * @param array|null $requested Requested scope tokens, or null when omitted.
     * @return string|null Outcome to return, or null to continue.
     */
    private function narrow_for_refresh( array &$row, ?array $requested ): ?string {
        $held = self::scope_tokens( (string) $row['scope'] );
        if ( null !== $requested ) {
            if ( array_diff( $requested, $held ) ) {
                return self::INVALID_SCOPE;
            }
            $row['scope'] = implode( ' ', array_values( array_intersect( $held, $requested ) ) );
        }
        // A grant narrowed to drop offline_access no longer refreshes.
        if ( ! in_array( self::SCOPE_OFFLINE, $held, true ) ) {
            return self::INVALID_GRANT;
        }
        return null;
    }

    /**
     * Record a successful request on a connection for the panel's status line:
     * the time and the client IP. Not version-conditional and never part of a transition; written at
     * most once a minute, like last_used_at.
     *
     * @param int    $grant_id Grant id.
     * @param string $ip       Client IP, from REMOTE_ADDR or the mainwp_remote_mcp_client_ip filter.
     * @return bool Whether the row was written; false also when it was written under a minute ago.
     */
    public function record_grant_use( int $grant_id, string $ip ): bool {
        $now = $this->now();
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET last_used_at = %d, last_ip = NULLIF(%s, \'\') WHERE id = %d AND ( last_used_at < %d OR NOT ( last_ip <=> NULLIF(%s, \'\') ) )',
                $this->table( 'grants' ),
                $now,
                substr( $ip, 0, 45 ),
                $grant_id,
                $now - MINUTE_IN_SECONDS,
                substr( $ip, 0, 45 )
            )
        );
    }

    /**
     * Record why the last request on a connection failed, for the panel's status line.
     *
     * @param int    $grant_id Grant id.
     * @param string $reason   Short reason code, never request content.
     * @return bool
     */
    public function record_grant_failure( int $grant_id, string $reason ): bool {
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET last_failure = %s, last_failure_at = %d WHERE id = %d',
                $this->table( 'grants' ),
                substr( $reason, 0, 64 ),
                $this->now(),
                $grant_id
            )
        );
    }

    /**
     * Narrow a live grant's scope in place, for example from "View and make changes" to "View
     * only". Every token's effective scope follows on its next use. Widening needs new consent,
     * so it is refused here. The policy version moves too, so approvals and jobs created under the
     * wider scope stop being usable.
     *
     * @param int    $grant_id Grant id.
     * @param string $scope    New scope, a strict subset of the current one.
     * @return bool Whether the grant was narrowed.
     */
    public function narrow_grant_scope( int $grant_id, string $scope ): bool {
        $grant = $this->get_grant( $grant_id );
        if ( null === $grant || 'live' !== $grant['status'] ) {
            return false;
        }
        $current = self::scope_tokens( (string) $grant['scope'] );
        $wanted  = self::scope_tokens( $scope );
        if ( empty( $wanted ) || array_diff( $wanted, $current ) || count( $wanted ) === count( $current ) ) {
            return false;
        }
        if ( in_array( self::SCOPE_WRITE, $wanted, true ) && ! in_array( self::SCOPE_READ, $wanted, true ) ) {
            return false;
        }
        $narrowed = implode( ' ', array_values( array_intersect( $current, $wanted ) ) );
        $updated  = $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET scope = %s, policy_version = policy_version + 1 WHERE id = %d AND status = \'live\' AND scope = %s',
                $this->table( 'grants' ),
                $narrowed,
                $grant_id,
                (string) $grant['scope']
            )
        );
        if ( 1 !== $updated ) {
            return false;
        }
        $this->end_outdated_work( $grant_id );
        return true;
    }

    /**
     * Change a connection's approval policy and bump its policy version. Any change
     * bumps, looser or stricter, and approvals and jobs from the old version are ended.
     *
     * @param int    $grant_id Grant id.
     * @param string $policy   One of POLICIES.
     * @return bool Whether the policy changed.
     */
    public function set_approval_policy( int $grant_id, string $policy ): bool {
        if ( ! in_array( $policy, self::POLICIES, true ) ) {
            return false;
        }
        $updated = $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET approval_policy = %s, policy_version = policy_version + 1 WHERE id = %d AND status IN (\'pending\', \'live\') AND approval_policy <> %s',
                $this->table( 'grants' ),
                $policy,
                $grant_id,
                $policy
            )
        );
        if ( 1 !== $updated ) {
            return false;
        }
        $this->end_outdated_work( $grant_id );
        return true;
    }

    /**
     * Mark open approvals stale and cancel jobs whose policy version is no longer the grant's.
     * Results are ignored: consume and every job step compare the version themselves, so this only
     * makes the state visible and drops stored plans early.
     *
     * @param int $grant_id Grant id.
     */
    private function end_outdated_work( int $grant_id ): void {
        $this->db->query(
            $this->db->prepare(
                'UPDATE %i a INNER JOIN %i g ON g.id = a.grant_id
                SET a.status = \'stale\', a.call_plan = NULL, a.native_preview_token = NULL
                WHERE a.grant_id = %d AND a.status IN (\'pending\', \'approved\') AND a.policy_version <> g.policy_version',
                $this->table( 'approvals' ),
                $this->table( 'grants' ),
                $grant_id
            )
        );
        $this->db->query(
            $this->db->prepare(
                'UPDATE %i j INNER JOIN %i g ON g.id = j.grant_id
                SET j.status = \'cancelled\', j.last_error = \'policy_changed\', j.updated_at = %d
                WHERE j.grant_id = %d AND j.status IN (\'starting\', \'running\') AND j.policy_version <> g.policy_version',
                $this->table( 'jobs' ),
                $this->table( 'grants' ),
                $this->now(),
                $grant_id
            )
        );
    }

    /**
     * Revoke the approvals and cancel the jobs of revoked grants. Results are ignored:
     * consume and every job step require a live grant, so a failure here leaves nothing
     * runnable, only rows that look open until the purge.
     *
     * @param int|null $grant_id One grant, or null for every revoked grant.
     */
    private function end_grant_work( ?int $grant_id ): void {
        $only_approvals = null === $grant_id ? '' : $this->db->prepare( ' AND a.grant_id = %d', $grant_id );
        $only_jobs      = null === $grant_id ? '' : $this->db->prepare( ' AND j.grant_id = %d', $grant_id );
        $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $only_approvals is itself prepared.
                'UPDATE %i a INNER JOIN %i g ON g.id = a.grant_id
                SET a.status = \'revoked\', a.call_plan = NULL, a.native_preview_token = NULL
                WHERE g.status = \'revoked\' AND a.status IN (\'pending\', \'approved\')' . $only_approvals,
                $this->table( 'approvals' ),
                $this->table( 'grants' )
            )
        );
        $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $only_jobs is itself prepared.
                'UPDATE %i j INNER JOIN %i g ON g.id = j.grant_id
                SET j.status = \'cancelled\', j.last_error = \'grant_revoked\', j.updated_at = %d
                WHERE g.status = \'revoked\' AND j.status IN (\'starting\', \'running\')' . $only_jobs,
                $this->table( 'jobs' ),
                $this->table( 'grants' ),
                $this->now()
            )
        );
    }

    /**
     * Mint a connection token: a grant for the synthetic client id with one
     * `connect` token row, published through the same generation-checked statement as code
     * exchange. Pair 1 is the only pair; it never rotates or refreshes.
     *
     * @param int    $user_id    Owner, who must still hold manage_options.
     * @param string $scope      `mainwp:read` or `mainwp:read mainwp:write`.
     * @param string $policy     One of POLICIES.
     * @param string $label      Name, 1 to 64 characters.
     * @param int    $expires_at Absolute expiry, at most CONNECTION_TOKEN_MAX_LIFETIME away.
     * @return array Result; on success `grant_id`, `token` (shown once) and `expires_at`.
     */
    public function mint_connection_token( int $user_id, string $scope, string $policy, string $label, int $expires_at ): array {
        $now    = $this->now();
        $tokens = self::scope_tokens( $scope );
        sort( $tokens );
        $label = trim( $label );
        if ( ! in_array( $tokens, array( array( self::SCOPE_READ ), array( self::SCOPE_READ, self::SCOPE_WRITE ) ), true )
            || ! in_array( $policy, self::POLICIES, true )
            || '' === $label || mb_strlen( $label ) > 64
            || $expires_at <= $now || $expires_at > $now + self::CONNECTION_TOKEN_MAX_LIFETIME
            || null !== $this->user_failure( $user_id ) ) {
            return $this->result( self::INVALID_REQUEST );
        }
        $scope = implode( ' ', $tokens );

        $generations = $this->current_generations( $user_id );
        $inserted    = $this->db->insert(
            $this->table( 'grants' ),
            array(
                'user_id'            => $user_id,
                'client_id'          => self::CONNECTION_TOKEN_CLIENT_ID,
                'client_host'        => '',
                'scope'              => $scope,
                'resource'           => $this->resource,
                'status'             => 'pending',
                'version'            => 0,
                'pair_seq'           => 1,
                'current_pair'       => 1,
                'current_used'       => 0,
                'grace_used'         => 0,
                'user_generation'    => $generations['user'],
                'site_generation'    => $generations['site'],
                'created_at'         => $now,
                'session_expires_at' => $expires_at,
                'last_used_at'       => $now,
                'approval_policy'    => $policy,
                'policy_version'     => 1,
                'client_kind'        => 'token',
                'label'              => $label,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
        );
        if ( 1 !== $inserted ) {
            return $this->result( self::SERVER_ERROR );
        }
        $grant_id = (int) $this->db->insert_id;

        $token    = $this->hasher->generate( OAuth_Token_Hasher::TYPE_CONNECT );
        $inserted = $this->db->insert(
            $this->table( 'tokens' ),
            array(
                'token_hash'     => $this->hasher->hash( $token, $this->resource ),
                'token_type'     => OAuth_Token_Hasher::TYPE_CONNECT,
                'grant_id'       => $grant_id,
                'pair'           => 1,
                'scope'          => $scope,
                'resource'       => $this->resource,
                'secret_version' => $this->hasher->secret_version(),
                'created_at'     => $now,
                'expires_at'     => $expires_at,
            ),
            array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d' )
        );
        if ( 1 !== $inserted ) {
            // Result ignored: the grant is pending and never published.
            $this->revoke_statement( $grant_id, 'issue_failed' );
            return $this->result( self::SERVER_ERROR, 'issue_failed' );
        }
        $this->before_publish( $grant_id, array( 'connect_token' => $token ) );

        $published = $this->publish_grant( $grant_id );
        if ( 1 !== $published ) {
            $event = 1 === $this->revoke_statement( $grant_id, 'generation_changed' ) ? 'generation_changed' : 'grant_revoked';
            return $this->result( false === $published ? self::SERVER_ERROR : self::INVALID_GRANT, $event );
        }

        return $this->result(
            self::OK,
            null,
            array(
                'grant_id'   => $grant_id,
                'token'      => $token,
                'expires_at' => $expires_at,
            )
        );
    }

    /**
     * Check a connection token: the usability rules of check_access_token() plus the
     * type and kind checks. Nothing is written: the single pair is never selected or rotated.
     * The kill switch is the bearer handler's check, before this is called, as for access tokens.
     *
     * @param string $token Presented bearer token.
     * @return array Result; on success the same fields as check_access_token().
     */
    public function check_connect_token( string $token ): array {
        $row = $this->lookup_token( $token, OAuth_Token_Hasher::TYPE_CONNECT );
        $now = $this->now();
        if ( null === $row || ! $this->grant_usable( $row, $now ) || $now >= (int) $row['token_expires_at'] ) {
            return $this->result( self::INVALID_TOKEN );
        }
        if ( 'token' !== $row['client_kind'] || self::CONNECTION_TOKEN_CLIENT_ID !== $row['client_id'] || (int) $row['pair'] !== (int) $row['current_pair'] ) {
            return $this->result( self::INVALID_TOKEN );
        }
        return $this->access_granted( $row, OAuth_Token_Hasher::TYPE_CONNECT );
    }

    /**
     * Store an approval for a live grant. The row copies the grant's user,
     * client and policy version and the current generations in the same statement that checks the
     * grant, so an approval can't be created for a grant that was revoked or re-versioned a moment
     * earlier. A `confirm` row is the chat confirmation and starts `approved`; a `dashboard` row
     * starts `pending` until the user decides on the page.
     *
     * Raw arguments are never stored. The call plan is the only execution source; it and any native
     * preview token are sealed under the module secret. Gate-supplied values (for example a
     * `request_ref` idempotency id) are stored as canonical JSON, outside `args_digest`, so a retry
     * matches its approved row. Any other parameter is refused rather than ignored.
     *
     * @param array $params `grant_id`, `ability`, `tier`, `args_digest` (keyed, 64 hex), optional
     *                      `target_ids` (int list), `plan_digest`, `native_preview_token`,
     *                      `summary`, `call_plan` (Call_Plan) and `gate_values` (an object:
     *                      associative array or stdClass).
     * @return array Result; on success `approval_id`, `handle` (shown once), `status`, `expires_at`.
     */
    public function create_approval( array $params ): array {
        $now     = $this->now();
        $tier    = $params['tier'] ?? null;
        $ability = $params['ability'] ?? null;
        $digest  = $params['args_digest'] ?? null;
        $targets = $params['target_ids'] ?? null;
        $plan    = $params['call_plan'] ?? null;
        $gate    = null;
        if ( isset( $params['gate_values'] ) ) {
            $gate = Call_Plan::canonical_json( $params['gate_values'] );
            if ( null === $gate || 0 !== strpos( $gate, '{' ) || '{}' === $gate ) {
                return $this->result( self::INVALID_REQUEST );
            }
        }
        if ( array_diff( array_keys( $params ), self::APPROVAL_PARAMS )
            || ! in_array( $tier, array( self::TIER_CONFIRM, self::TIER_DASHBOARD ), true )
            || ! is_string( $ability ) || strlen( $ability ) > 191 || 1 !== preg_match( Call_Plan::ABILITY_PATTERN, $ability )
            || ! is_string( $digest ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $digest )
            || ( isset( $params['plan_digest'] ) && ( ! is_string( $params['plan_digest'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $params['plan_digest'] ) ) )
            || ( null !== $plan && ! $plan instanceof Call_Plan ) ) {
            return $this->result( self::INVALID_REQUEST );
        }
        $target_json = '';
        if ( null !== $targets ) {
            $normalized = Call_Plan::from_entries(
                array(
                    array(
                        'ability'    => $ability,
                        'args'       => array(),
                        'target_ids' => $targets,
                    ),
                )
            );
            if ( null === $normalized ) {
                return $this->result( self::INVALID_REQUEST );
            }
            $target_json = (string) wp_json_encode( $normalized->target_ids() );
        }

        $sealed = array();
        foreach ( array(
            'call_plan'            => null === $plan ? null : $plan->to_json(),
            'native_preview_token' => $params['native_preview_token'] ?? null,
        ) as $column => $value ) {
            $sealed[ $column ] = '';
            if ( null !== $value ) {
                $sealed[ $column ] = is_string( $value ) ? (string) $this->hasher->seal( 'approval_' . $column, $value ) : '';
                if ( '' === $sealed[ $column ] ) {
                    return $this->result( self::SERVER_ERROR );
                }
            }
        }

        $chat       = self::TIER_CONFIRM === $tier;
        $handle     = $this->hasher->generate( OAuth_Token_Hasher::TYPE_APPROVAL );
        $expires_at = $now + ( $chat ? self::APPROVAL_CHAT_LIFETIME : self::APPROVAL_PENDING_LIFETIME );
        $inserted   = $this->db->query(
            $this->db->prepare(
                'INSERT INTO %i (handle_hash, grant_id, user_id, client_id, ability, tier, policy_version, args_digest, target_ids, plan_digest, native_preview_token, summary, status, decided_via, call_plan, gate_values, user_generation, site_generation, created_at, expires_at, decided_at)
                SELECT %s, g.id, g.user_id, g.client_id, %s, %s, g.policy_version, %s, NULLIF(%s, \'\'), NULLIF(%s, \'\'), NULLIF(%s, \'\'), NULLIF(%s, \'\'), %s, NULLIF(%s, \'\'), NULLIF(%s, \'\'), NULLIF(%s, \'\'), COALESCE(u.generation, 0), COALESCE(st.generation, 0), %d, %d, NULLIF(%d, 0)
                FROM %i g LEFT JOIN %i u ON u.subject_id = g.user_id LEFT JOIN %i st ON st.subject_id = 0
                WHERE g.id = %d AND g.status = \'live\' AND g.session_expires_at > %d
                AND g.site_generation = COALESCE(st.generation, 0) AND g.user_generation = COALESCE(u.generation, 0)',
                $this->table( 'approvals' ),
                $this->hasher->hash( $handle, $this->resource ),
                $ability,
                $tier,
                $digest,
                $target_json,
                (string) ( $params['plan_digest'] ?? '' ),
                $sealed['native_preview_token'],
                isset( $params['summary'] ) ? (string) $params['summary'] : '',
                $chat ? 'approved' : 'pending',
                $chat ? 'chat' : '',
                $sealed['call_plan'],
                null === $gate ? '' : $gate,
                $now,
                $expires_at,
                $chat ? $now : 0,
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                (int) ( $params['grant_id'] ?? 0 ),
                $now
            )
        );
        if ( 1 !== $inserted ) {
            return $this->result( false === $inserted ? self::SERVER_ERROR : self::INVALID_GRANT );
        }
        $approval_id = (int) $this->db->insert_id;
        $this->expire_beyond_approval_cap( (int) $params['grant_id'] );
        return $this->result(
            self::OK,
            null,
            array(
                'approval_id' => $approval_id,
                'handle'      => $handle,
                'status'      => $chat ? 'approved' : 'pending',
                'expires_at'  => $expires_at,
            )
        );
    }

    /**
     * Parameters create_approval() accepts.
     *
     * @var array
     */
    const APPROVAL_PARAMS = array( 'grant_id', 'ability', 'tier', 'args_digest', 'target_ids', 'plan_digest', 'native_preview_token', 'summary', 'call_plan', 'gate_values' );

    /**
     * Expire the oldest open approvals beyond the per-grant cap. Results are ignored: the cap bounds
     * row growth, it isn't an authorization check.
     *
     * @param int $grant_id Grant id.
     */
    private function expire_beyond_approval_cap( int $grant_id ): void {
        $ids = $this->db->get_col(
            $this->db->prepare(
                'SELECT id FROM %i WHERE grant_id = %d AND status IN (\'pending\', \'approved\') ORDER BY id DESC',
                $this->table( 'approvals' ),
                $grant_id
            )
        );
        foreach ( array_slice( array_map( 'intval', (array) $ids ), self::OPEN_APPROVAL_CAP ) as $id ) {
            $this->db->query(
                $this->db->prepare(
                    'UPDATE %i SET status = \'expired\', call_plan = NULL, native_preview_token = NULL WHERE id = %d AND status IN (\'pending\', \'approved\')',
                    $this->table( 'approvals' ),
                    $id
                )
            );
        }
    }

    /**
     * Approval row by id. The id is not a secret; callers check the user.
     *
     * @param int $approval_id Approval id.
     * @return array|null
     */
    public function get_approval( int $approval_id ): ?array {
        $row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'approvals' ), $approval_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Approval row by its handle (the `confirmation_token`). The prefix is checked before hashing.
     *
     * @param string $handle Presented handle.
     * @return array|null
     */
    public function find_approval_by_handle( string $handle ): ?array {
        if ( ! $this->hasher->matches_type( $handle, OAuth_Token_Hasher::TYPE_APPROVAL ) ) {
            return null;
        }
        $hash = $this->hasher->hash( $handle, $this->resource );
        $row  = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE handle_hash = %s', $this->table( 'approvals' ), $hash ), ARRAY_A );
        return is_array( $row ) && hash_equals( (string) $row['handle_hash'], $hash ) ? $row : null;
    }

    /**
     * Newest Dashboard-tier approval for a retry: same grant, ability and
     * argument digest, the grant's current policy version and current generations. Any status but
     * `closed` is returned, so a pending row can be answered as pending; only consume decides what runs.
     *
     * @param int    $grant_id    Grant id.
     * @param string $ability     Ability name.
     * @param string $args_digest Keyed argument digest.
     * @return array|null
     */
    public function latest_dashboard_approval( int $grant_id, string $ability, string $args_digest ): ?array {
        $row = $this->db->get_row(
            $this->db->prepare(
                'SELECT a.* FROM %i a INNER JOIN %i g ON g.id = a.grant_id
                LEFT JOIN %i u ON u.subject_id = a.user_id LEFT JOIN %i st ON st.subject_id = 0
                WHERE a.grant_id = %d AND a.ability = %s AND a.args_digest = %s AND a.tier = \'dashboard\'
                AND a.policy_version = g.policy_version
                AND a.site_generation = COALESCE(st.generation, 0) AND a.user_generation = COALESCE(u.generation, 0)
                ORDER BY a.id DESC LIMIT 1',
                $this->table( 'approvals' ),
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                $grant_id,
                $ability,
                $args_digest
            ),
            ARRAY_A
        );
        // The columns compare case-insensitively; the digest and ability must match byte for byte.
        if ( ! is_array( $row ) || ! hash_equals( (string) $row['args_digest'], $args_digest ) || $ability !== $row['ability'] ) {
            return null;
        }
        // A closed denial is a boundary: the call starts over, and an older approval for the same
        // action never resurfaces past it.
        if ( 'closed' === $row['status'] ) {
            return null;
        }
        return $row;
    }

    /**
     * Record the user's decision on the approval page: one conditional `pending` to `approved` or
     * `denied` statement, by the row's own user, while the grant is live at the same policy version
     * and generations. Approval starts the 15-minute retry window.
     *
     * On Approve, the same statement writes the click-time capture, so a row can never be approved
     * with a capture half written, and the retry can't consume before the executable plan is in
     * place. Only the keys the capture names are written; an empty or null value counts as not
     * named. A deny ignores the capture. A capture that names any other key, holds a value of the
     * wrong shape, carries a pending plan or can't be sealed decides nothing: the row stays pending
     * for the page to show the failure.
     *
     * @param int        $approval_id Approval id.
     * @param int        $user_id     Logged-in user deciding.
     * @param bool       $approve     Approve or deny.
     * @param array|null $capture     Optional `call_plan` (executable Call_Plan, sealed),
     *                                `native_preview_token` (string, sealed), `gate_values` (the
     *                                full object: `fields` unchanged from the first call, plus
     *                                `preview_baseline`, 64 hex) and `summary` (string).
     * @return bool Whether this call decided the row.
     */
    public function decide_approval( int $approval_id, int $user_id, bool $approve, ?array $capture = null ): bool {
        $now     = $this->now();
        $written = array_fill_keys( self::APPROVAL_CAPTURE, '' );
        if ( $approve && ! empty( $capture ) ) {
            $written = $this->approval_capture( $capture );
            if ( null === $written ) {
                return false;
            }
        }
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i a INNER JOIN %i g ON g.id = a.grant_id
                LEFT JOIN %i u ON u.subject_id = a.user_id LEFT JOIN %i st ON st.subject_id = 0
                SET a.status = %s, a.decided_by = %d, a.decided_via = \'page\', a.decided_at = %d, a.expires_at = %d,
                a.call_plan = COALESCE(NULLIF(%s, \'\'), a.call_plan), a.native_preview_token = COALESCE(NULLIF(%s, \'\'), a.native_preview_token),
                a.gate_values = COALESCE(NULLIF(%s, \'\'), a.gate_values), a.summary = COALESCE(NULLIF(%s, \'\'), a.summary)
                WHERE a.id = %d AND a.user_id = %d AND a.status = \'pending\' AND a.tier = \'dashboard\' AND a.expires_at > %d
                AND g.status = \'live\' AND g.session_expires_at > %d AND a.policy_version = g.policy_version
                AND a.site_generation = COALESCE(st.generation, 0) AND a.user_generation = COALESCE(u.generation, 0)',
                $this->table( 'approvals' ),
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                $approve ? 'approved' : 'denied',
                $user_id,
                $now,
                $approve ? $now + self::APPROVAL_APPROVED_LIFETIME : $now,
                $written['call_plan'],
                $written['native_preview_token'],
                $written['gate_values'],
                $written['summary'],
                $approval_id,
                $user_id,
                $now,
                $now
            )
        );
    }

    /**
     * Keys decide_approval() accepts in its capture.
     *
     * @var array
     */
    const APPROVAL_CAPTURE = array( 'call_plan', 'native_preview_token', 'gate_values', 'summary' );

    /**
     * Column values for a click-time capture, sealed where secret, `''` for a key it doesn't name.
     *
     * @param array $capture Capture.
     * @return array|null Null when the capture is malformed or can't be sealed.
     */
    private function approval_capture( array $capture ): ?array {
        $plan    = $capture['call_plan'] ?? null;
        $token   = $capture['native_preview_token'] ?? null;
        $summary = $capture['summary'] ?? null;
        if ( array_diff( array_keys( $capture ), self::APPROVAL_CAPTURE )
            || ( null !== $plan && ( ! $plan instanceof Call_Plan || $plan->is_pending() ) )
            || ( null !== $token && ! is_string( $token ) )
            || ( null !== $summary && ! is_string( $summary ) ) ) {
            return null;
        }
        $written = array(
            'call_plan'            => '',
            'native_preview_token' => '',
            'gate_values'          => '',
            'summary'              => (string) $summary,
        );
        if ( isset( $capture['gate_values'] ) ) {
            $written['gate_values'] = (string) $this->captured_gate_values( $capture['gate_values'] );
            if ( '' === $written['gate_values'] ) {
                return null;
            }
        }
        foreach ( array(
            'call_plan'            => null === $plan ? null : $plan->to_json(),
            'native_preview_token' => $token,
        ) as $column => $value ) {
            // Sealing '' yields non-empty ciphertext, which the deciding UPDATE would write over
            // the column; an empty value means "nothing captured" and must leave it alone.
            if ( null !== $value && '' !== $value ) {
                $written[ $column ] = (string) $this->hasher->seal( 'approval_' . $column, $value );
                if ( '' === $written[ $column ] ) {
                    return null;
                }
            }
        }
        return $written;
    }

    /**
     * Canonical JSON of the `gate_values` an Approve writes. Only its two members, `fields` and
     * `preview_baseline`, are accepted, because the retry reads `preview_baseline` from here as the
     * approved preview and a malformed one must not reach that comparison.
     *
     * @param mixed $gate_values Associative array or stdClass.
     * @return string|null
     */
    private function captured_gate_values( $gate_values ): ?string {
        $json    = Call_Plan::canonical_json( $gate_values );
        $decoded = null === $json ? null : json_decode( $json );
        if ( ! $decoded instanceof \stdClass || '{}' === $json ) {
            return null;
        }
        $members = get_object_vars( $decoded );
        if ( array_diff( array_keys( $members ), array( 'fields', 'preview_baseline' ) )
            || ( array_key_exists( 'fields', $members ) && ! $members['fields'] instanceof \stdClass )
            || ( array_key_exists( 'preview_baseline', $members ) && ( ! is_string( $members['preview_baseline'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $members['preview_baseline'] ) ) ) ) {
            return null;
        }
        return $json;
    }

    /**
     * Close a denied approval once the retry has been told: one conditional
     * `denied` to `closed` statement. The retry lookup skips closed rows, so the next identical
     * call starts a new approval instead of hearing "denied" forever.
     *
     * @param int $approval_id Approval id.
     * @return bool Whether this call closed it.
     */
    public function acknowledge_denied_approval( int $approval_id ): bool {
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = \'closed\' WHERE id = %d AND status = \'denied\'',
                $this->table( 'approvals' ),
                $approval_id
            )
        );
    }

    /**
     * Mark an open approval stale and drop its stored plan.
     *
     * @param int $approval_id Approval id.
     * @return bool Whether this call ended it.
     */
    public function mark_approval_stale( int $approval_id ): bool {
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = \'stale\', call_plan = NULL, native_preview_token = NULL WHERE id = %d AND status IN (\'pending\', \'approved\')',
                $this->table( 'approvals' ),
                $approval_id
            )
        );
    }

    /**
     * The consume statement: one conditional UPDATE that wins only when the row
     * is approved, its tier equals the call's effective tier right now, its policy version is the
     * grant's current one, the grant is live, both generations are current and the row is
     * unexpired. A Dashboard-tier row also needs the page decision: a chat confirmation never
     * satisfies it. Stored secrets are wiped in the same statement, so read the plan first.
     *
     * @param int    $approval_id    Approval id.
     * @param string $effective_tier The call's tier now.
     * @return bool Whether this caller consumed it.
     */
    public function consume_approval( int $approval_id, string $effective_tier ): bool {
        $now = $this->now();
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i a INNER JOIN %i g ON g.id = a.grant_id
                LEFT JOIN %i u ON u.subject_id = g.user_id LEFT JOIN %i st ON st.subject_id = 0
                SET a.status = \'consumed\', a.consumed_at = %d, a.call_plan = NULL, a.native_preview_token = NULL
                WHERE a.id = %d AND a.status = \'approved\' AND a.tier = %s AND a.expires_at > %d
                AND ( a.tier <> \'dashboard\' OR a.decided_via = \'page\' )
                AND a.policy_version = g.policy_version AND a.user_id = g.user_id
                AND g.status = \'live\' AND g.session_expires_at > %d
                AND a.site_generation = COALESCE(st.generation, 0) AND a.user_generation = COALESCE(u.generation, 0)
                AND g.site_generation = COALESCE(st.generation, 0) AND g.user_generation = COALESCE(u.generation, 0)',
                $this->table( 'approvals' ),
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                $now,
                $approval_id,
                $effective_tier,
                $now,
                $now
            )
        );
    }

    /**
     * Store the redacted result of a run from the approval page.
     *
     * @param int    $approval_id Approval id.
     * @param string $summary     Redacted summary.
     * @return bool
     */
    public function record_result_summary( int $approval_id, string $summary ): bool {
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET result_summary = %s WHERE id = %d AND status = \'consumed\' AND decided_via = \'page\' AND result_summary IS NULL',
                $this->table( 'approvals' ),
                $summary,
                $approval_id
            )
        );
    }

    /**
     * The call plan stored on an approval row, or null when it has none or it can't be opened.
     *
     * @param array $row Approval row.
     * @return Call_Plan|null
     */
    public function approval_call_plan( array $row ): ?Call_Plan {
        $json = $this->open_column( $row, 'call_plan', 'approval_call_plan' );
        return null === $json ? null : Call_Plan::from_json( $json );
    }

    /**
     * A sealed approval column in plain text; only `native_preview_token` exists.
     *
     * @param array  $row    Approval row.
     * @param string $column Column.
     * @return string|null
     */
    public function approval_secret( array $row, string $column ): ?string {
        if ( 'native_preview_token' !== $column ) {
            return null;
        }
        return $this->open_column( $row, $column, 'approval_' . $column );
    }

    /**
     * Enqueue a `write`-tier job, which has no approval. The insert copies nothing the
     * caller could misstate: it only lands while the grant is live at the given policy version.
     *
     * @param int       $grant_id       Grant id.
     * @param int       $policy_version Policy version the caller authorized against.
     * @param Call_Plan $plan           Plan the job owns.
     * @return array Result; on success `job_id`.
     */
    public function enqueue_job( int $grant_id, int $policy_version, Call_Plan $plan ): array {
        $now    = $this->now();
        $sealed = $this->hasher->seal( 'job_call_plan', $plan->to_json() );
        if ( null === $sealed ) {
            return $this->result( self::SERVER_ERROR );
        }
        $open = $this->open_jobs( $grant_id );
        if ( null === $open ) {
            return $this->result( self::SERVER_ERROR );
        }
        if ( $open >= self::RUNNING_JOB_CAP ) {
            return $this->result( self::CAP_REACHED );
        }
        $inserted = $this->db->query(
            $this->db->prepare(
                'INSERT INTO %i (approval_id, grant_id, user_id, policy_version, call_plan, next_entry, status, created_at, updated_at)
                SELECT NULL, g.id, g.user_id, g.policy_version, %s, 0, \'running\', %d, %d
                FROM %i g LEFT JOIN %i u ON u.subject_id = g.user_id LEFT JOIN %i st ON st.subject_id = 0
                WHERE g.id = %d AND g.status = \'live\' AND g.policy_version = %d AND g.session_expires_at > %d
                AND g.site_generation = COALESCE(st.generation, 0) AND g.user_generation = COALESCE(u.generation, 0)',
                $this->table( 'jobs' ),
                $sealed,
                $now,
                $now,
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                $grant_id,
                $policy_version,
                $now
            )
        );
        if ( 1 !== $inserted ) {
            return $this->result( false === $inserted ? self::SERVER_ERROR : self::INVALID_GRANT );
        }
        $job_id = (int) $this->db->insert_id;
        return $this->job_within_cap( $grant_id, $job_id );
    }

    /**
     * Consume an approval into a job, in this order with no transaction: insert the job as
     * `starting` with its own copy of the plan, run the consume, then set the job running on a win
     * or delete it on a loss. A tick never runs a `starting` job, and
     * the unique approval_id means a second consumer can't even insert.
     *
     * @param int       $approval_id    Approval id.
     * @param string    $effective_tier The call's tier now.
     * @param Call_Plan $plan           Plan read from the approval before this call.
     * @return array Result; on success `job_id`.
     */
    public function consume_into_job( int $approval_id, string $effective_tier, Call_Plan $plan ): array {
        $approval = $this->get_approval( $approval_id );
        if ( null === $approval || 'approved' !== $approval['status'] ) {
            return $this->result( self::INVALID_GRANT );
        }
        $grant_id = (int) $approval['grant_id'];
        $sealed   = $this->hasher->seal( 'job_call_plan', $plan->to_json() );
        $open     = $this->open_jobs( $grant_id );
        if ( null === $sealed || null === $open ) {
            return $this->result( self::SERVER_ERROR );
        }
        if ( $open >= self::RUNNING_JOB_CAP ) {
            return $this->result( self::CAP_REACHED );
        }
        $now      = $this->now();
        $inserted = $this->db->insert(
            $this->table( 'jobs' ),
            array(
                'approval_id'    => $approval_id,
                'grant_id'       => $grant_id,
                'user_id'        => (int) $approval['user_id'],
                'policy_version' => (int) $approval['policy_version'],
                'call_plan'      => $sealed,
                'next_entry'     => 0,
                'status'         => 'starting',
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%d', '%d' )
        );
        if ( 1 !== $inserted ) {
            return $this->result( self::INVALID_GRANT );
        }
        $job_id = (int) $this->db->insert_id;
        $this->after_job_insert( $job_id );

        if ( ! $this->consume_approval( $approval_id, $effective_tier ) ) {
            // Result ignored: a `starting` row never ticks and the purge removes it.
            $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE id = %d AND status = \'starting\'', $this->table( 'jobs' ), $job_id ) );
            return $this->result( self::INVALID_GRANT );
        }
        $running = $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = \'running\', updated_at = %d WHERE id = %d AND status = \'starting\'',
                $this->table( 'jobs' ),
                $now,
                $job_id
            )
        );
        if ( 1 !== $running ) {
            // The approval is used up and nothing runs: the user approves again.
            return $this->result( self::SERVER_ERROR );
        }
        return $this->job_within_cap( $grant_id, $job_id );
    }

    /**
     * Seam: a consume-and-enqueue job row exists as `starting` and the consume is next.
     *
     * @param int $job_id Job id.
     */
    protected function after_job_insert( int $job_id ): void {
    }

    /**
     * Starting and running jobs of a grant.
     *
     * @param int $grant_id Grant id.
     * @return int|null Count, or null on a SQL error.
     */
    private function open_jobs( int $grant_id ): ?int {
        $count = $this->db->get_var(
            $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE grant_id = %d AND status IN (\'starting\', \'running\')', $this->table( 'jobs' ), $grant_id )
        );
        return null === $count || '' !== $this->db->last_error ? null : (int) $count;
    }

    /**
     * Keep a new job only while the grant stays within the running-job cap; two enqueues racing
     * past the count both insert, and the newer one is cancelled.
     *
     * @param int $grant_id Grant id.
     * @param int $job_id   New job.
     * @return array
     */
    private function job_within_cap( int $grant_id, int $job_id ): array {
        $open = $this->open_jobs( $grant_id );
        if ( null === $open || $open > self::RUNNING_JOB_CAP ) {
            $this->cancel_job( $job_id, 'cap_limit' );
            return $this->result( null === $open ? self::SERVER_ERROR : self::CAP_REACHED );
        }
        return $this->result( self::OK, null, array( 'job_id' => $job_id ) );
    }

    /**
     * Job row.
     *
     * @param int $job_id Job id.
     * @return array|null
     */
    public function get_job( int $job_id ): ?array {
        $row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'jobs' ), $job_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * A job's own plan.
     *
     * @param array $row Job row.
     * @return Call_Plan|null
     */
    public function job_call_plan( array $row ): ?Call_Plan {
        $json = $this->open_column( $row, 'call_plan', 'job_call_plan' );
        return null === $json ? null : Call_Plan::from_json( $json );
    }

    /**
     * Claim the next entry of a running job, once. Advancing before the entry runs
     * means an entry runs at most once; a tick that dies mid-entry never repeats a write. The
     * statement also requires the grant to be live at the job's policy version with current
     * generations, so a revoke, a policy change or a generation bump stops the job at the next entry.
     *
     * @param int $job_id Job id.
     * @param int $entry  Entry index the caller read as next.
     * @return bool Whether this caller may run that entry.
     */
    public function claim_job_entry( int $job_id, int $entry ): bool {
        $now = $this->now();
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i j INNER JOIN %i g ON g.id = j.grant_id
                LEFT JOIN %i u ON u.subject_id = g.user_id LEFT JOIN %i st ON st.subject_id = 0
                SET j.next_entry = j.next_entry + 1, j.updated_at = %d
                WHERE j.id = %d AND j.status = \'running\' AND j.next_entry = %d
                AND g.status = \'live\' AND g.session_expires_at > %d AND j.policy_version = g.policy_version
                AND g.site_generation = COALESCE(st.generation, 0) AND g.user_generation = COALESCE(u.generation, 0)',
                $this->table( 'jobs' ),
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                $now,
                $job_id,
                $entry,
                $now
            )
        );
    }

    /**
     * Finish a running job.
     *
     * @param int         $job_id Job id.
     * @param bool        $failed Whether it failed.
     * @param string|null $error  Short reason code, never argument values.
     * @return bool
     */
    public function finish_job( int $job_id, bool $failed, ?string $error = null ): bool {
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = %s, last_error = NULLIF(%s, \'\'), updated_at = %d WHERE id = %d AND status = \'running\'',
                $this->table( 'jobs' ),
                $failed ? 'failed' : 'done',
                substr( (string) $error, 0, 64 ),
                $this->now(),
                $job_id
            )
        );
    }

    /**
     * Cancel a starting or running job.
     *
     * @param int    $job_id Job id.
     * @param string $reason Short reason code.
     * @return bool Whether this call cancelled it.
     */
    public function cancel_job( int $job_id, string $reason ): bool {
        return 1 === $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET status = \'cancelled\', last_error = %s, updated_at = %d WHERE id = %d AND status IN (\'starting\', \'running\')',
                $this->table( 'jobs' ),
                substr( $reason, 0, 64 ),
                $this->now(),
                $job_id
            )
        );
    }

    /**
     * Whether deferred work authorized under a grant may still run: the grant is live
     * and unexpired at the same policy version, with current generations.
     *
     * @param int $grant_id       Grant id.
     * @param int $policy_version Policy version the work was authorized under.
     * @return bool
     */
    public function authorization_active( int $grant_id, int $policy_version ): bool {
        $found = $this->db->get_var(
            $this->db->prepare(
                'SELECT g.id FROM %i g LEFT JOIN %i u ON u.subject_id = g.user_id LEFT JOIN %i st ON st.subject_id = 0
                WHERE g.id = %d AND g.status = \'live\' AND g.policy_version = %d AND g.session_expires_at > %d
                AND g.site_generation = COALESCE(st.generation, 0) AND g.user_generation = COALESCE(u.generation, 0)',
                $this->table( 'grants' ),
                $this->table( 'generations' ),
                $this->table( 'generations' ),
                $grant_id,
                $policy_version,
                $this->now()
            )
        );
        return null !== $found && (int) $found === $grant_id;
    }

    /**
     * Store a registered or admin-added client. The redirect grammar is the registration and panel
     * code's check; the store refuses only shapes it can't hold.
     *
     * @param string $kind   `registered` or `webapp`.
     * @param array  $fields `redirect_uris` (strings), optional `client_name`, `created_by`, `created_ip`.
     * @return array Result; on success `client_id`, plus `client_secret` (shown once) for a web app.
     */
    public function create_client( string $kind, array $fields ): array {
        $limits   = array(
            'registered' => 5,
            'webapp'     => 3,
        );
        $redirect = $fields['redirect_uris'] ?? null;
        if ( ! isset( $limits[ $kind ] ) || ! is_array( $redirect ) || empty( $redirect ) || count( $redirect ) > $limits[ $kind ] || ! array_is_list( $redirect ) ) {
            return $this->result( self::INVALID_REQUEST );
        }
        foreach ( $redirect as $uri ) {
            if ( ! is_string( $uri ) || '' === $uri || strlen( $uri ) > 2048 ) {
                return $this->result( self::INVALID_REQUEST );
            }
        }
        $name = isset( $fields['client_name'] ) && is_string( $fields['client_name'] ) ? mb_substr( trim( $fields['client_name'] ), 0, 64 ) : '';
        if ( 'webapp' === $kind && '' === $name ) {
            return $this->result( self::INVALID_REQUEST );
        }

        $client_id = ( 'webapp' === $kind ? 'mwpwa_' : 'mwplc_' ) . OAuth_Token_Hasher::base64url( random_bytes( 32 ) );
        $secret    = 'webapp' === $kind ? 'mwpws_' . OAuth_Token_Hasher::base64url( random_bytes( 32 ) ) : null;
        $inserted  = $this->db->query(
            $this->db->prepare(
                'INSERT INTO %i (client_id, kind, client_name, redirect_uris, secret_hash, created_by, created_ip, created_at, status)
                VALUES (%s, %s, NULLIF(%s, \'\'), %s, NULLIF(%s, \'\'), NULLIF(%d, 0), NULLIF(%s, \'\'), %d, \'active\')',
                $this->table( 'clients' ),
                $client_id,
                $kind,
                $name,
                (string) wp_json_encode( array_values( $redirect ) ),
                null === $secret ? '' : hash( 'sha256', $secret ),
                (int) ( $fields['created_by'] ?? 0 ),
                substr( (string) ( $fields['created_ip'] ?? '' ), 0, 45 ),
                $this->now()
            )
        );
        if ( 1 !== $inserted ) {
            return $this->result( self::SERVER_ERROR );
        }
        $fields = array( 'client_id' => $client_id );
        if ( null !== $secret ) {
            $fields['client_secret'] = $secret;
        }
        return $this->result( self::OK, null, $fields );
    }

    /**
     * Client row by its exact client_id, with `redirect_uris` decoded.
     *
     * @param string $client_id Client id.
     * @return array|null
     */
    public function get_client( string $client_id ): ?array {
        $row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE client_id = %s', $this->table( 'clients' ), $client_id ), ARRAY_A );
        // The column compares case-insensitively; ids are distinct whenever their bytes differ.
        if ( ! is_array( $row ) || ! hash_equals( (string) $row['client_id'], $client_id ) ) {
            return null;
        }
        $uris                 = json_decode( (string) $row['redirect_uris'], true );
        $row['redirect_uris'] = is_array( $uris ) ? $uris : array();
        return $row;
    }

    /**
     * Whether a web app's presented secret is its current one. Compared as a plain
     * SHA-256: a 32-byte random secret leaves nothing to guess offline.
     *
     * @param string $client_id Client id.
     * @param string $secret    Presented secret.
     * @return bool
     */
    public function verify_client_secret( string $client_id, string $secret ): bool {
        $row = $this->get_client( $client_id );
        if ( null === $row || 'webapp' !== $row['kind'] || 'active' !== $row['status'] || ! is_string( $row['secret_hash'] ) ) {
            return false;
        }
        return hash_equals( $row['secret_hash'], hash( 'sha256', $secret ) );
    }

    /**
     * Record a client's first consent; a consented registration is kept until removed.
     *
     * @param string $client_id Client id.
     * @return bool
     */
    public function mark_client_consented( string $client_id ): bool {
        $now = $this->now();
        return false !== $this->db->query(
            $this->db->prepare(
                'UPDATE %i SET first_consented_at = COALESCE(first_consented_at, %d), last_used_at = %d WHERE client_id = %s',
                $this->table( 'clients' ),
                $now,
                $now,
                $client_id
            )
        );
    }

    /**
     * Record a client's use.
     *
     * @param string $client_id Client id.
     * @return bool
     */
    public function touch_client( string $client_id ): bool {
        return false !== $this->db->query(
            $this->db->prepare( 'UPDATE %i SET last_used_at = %d WHERE client_id = %s', $this->table( 'clients' ), $this->now(), $client_id )
        );
    }

    /**
     * Switch a client on or off. Switching off revokes its grants.
     *
     * @param string $client_id Client id.
     * @param bool   $active    On or off.
     * @return bool
     */
    public function set_client_active( string $client_id, bool $active ): bool {
        $updated = $this->db->query(
            $this->db->prepare( 'UPDATE %i SET status = %s WHERE client_id = %s', $this->table( 'clients' ), $active ? 'active' : 'disabled', $client_id )
        );
        if ( false === $updated ) {
            return false;
        }
        return $active || null !== $this->revoke_client_grants( $client_id );
    }

    /**
     * Replace a web app's secret and revoke its grants (the panel's "Reissue secret").
     *
     * @param string $client_id Client id.
     * @return string|null The new secret, shown once, or null on failure.
     */
    public function reissue_client_secret( string $client_id ): ?string {
        $row = $this->get_client( $client_id );
        if ( null === $row || 'webapp' !== $row['kind'] ) {
            return null;
        }
        $secret  = 'mwpws_' . OAuth_Token_Hasher::base64url( random_bytes( 32 ) );
        $updated = $this->db->query(
            $this->db->prepare( 'UPDATE %i SET secret_hash = %s WHERE id = %d', $this->table( 'clients' ), hash( 'sha256', $secret ), (int) $row['id'] )
        );
        if ( 1 !== $updated || null === $this->revoke_client_grants( $client_id ) ) {
            return null;
        }
        return $secret;
    }

    /**
     * Remove a client and revoke its grants first.
     *
     * @param string $client_id Client id.
     * @return bool
     */
    public function delete_client( string $client_id ): bool {
        $row = $this->get_client( $client_id );
        if ( null === $row || null === $this->revoke_client_grants( $client_id ) ) {
            return false;
        }
        return 1 === $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE id = %d', $this->table( 'clients' ), (int) $row['id'] ) );
    }

    /**
     * Registered clients that were never consented.
     *
     * @return int|null Count, or null on a SQL error.
     */
    public function count_unconsented_clients(): ?int {
        $count = $this->db->get_var(
            $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE kind = \'registered\' AND first_consented_at IS NULL', $this->table( 'clients' ) )
        );
        return null === $count || '' !== $this->db->last_error ? null : (int) $count;
    }

    /**
     * Remove the oldest never-consented registration older than $min_age seconds, to make room
     * when the site-wide cap is full.
     *
     * @param int $min_age Minimum age in seconds.
     * @return bool Whether one was removed.
     */
    public function evict_oldest_unconsented_client( int $min_age ): bool {
        $id = $this->db->get_var(
            $this->db->prepare(
                'SELECT id FROM %i WHERE kind = \'registered\' AND first_consented_at IS NULL AND created_at < %d ORDER BY created_at ASC, id ASC LIMIT 1',
                $this->table( 'clients' ),
                $this->now() - $min_age
            )
        );
        if ( null === $id ) {
            return false;
        }
        return 1 === $this->db->query(
            $this->db->prepare( 'DELETE FROM %i WHERE id = %d AND first_consented_at IS NULL', $this->table( 'clients' ), (int) $id )
        );
    }

    /**
     * Revoke every pending and live grant of one client id.
     *
     * @param string $client_id Client id.
     * @return int|null Grants revoked, or null on a SQL error.
     */
    public function revoke_client_grants( string $client_id ): ?int {
        // The column's collation may match ids that differ only in case; revoking those too is the
        // safe direction, and generated ids differing only in case don't occur in practice.
        $revoked = $this->query_with_retry(
            $this->db->prepare(
                'UPDATE %i SET status = \'revoked\', revoke_reason = %s, revoked_at = %d, version = version + 1 WHERE client_id = %s AND status IN (\'pending\', \'live\')',
                $this->table( 'grants' ),
                'manual',
                $this->now(),
                $client_id
            )
        );
        $this->end_grant_work( null );
        return false === $revoked ? null : $revoked;
    }

    /**
     * Open a sealed column.
     *
     * @param array  $row     Row.
     * @param string $column  Column.
     * @param string $purpose Seal purpose.
     * @return string|null
     */
    private function open_column( array $row, string $column, string $purpose ): ?string {
        if ( ! isset( $row[ $column ] ) || ! is_string( $row[ $column ] ) || '' === $row[ $column ] ) {
            return null;
        }
        return $this->hasher->open( $purpose, $row[ $column ] );
    }

    /**
     * Grant columns a panel list may show. Never a hash, a secret or the family lock state.
     *
     * @var array
     */
    const GRANT_LIST_COLUMNS = array( 'id', 'user_id', 'client_id', 'client_host', 'client_kind', 'label', 'scope', 'approval_policy', 'policy_version', 'status', 'created_at', 'session_expires_at', 'last_used_at', 'last_ip', 'last_failure', 'last_failure_at' );

    /**
     * Client columns a panel list may show; `secret_hash` is never one.
     *
     * @var array
     */
    const CLIENT_LIST_COLUMNS = array( 'id', 'client_id', 'kind', 'client_name', 'redirect_uris', 'created_by', 'created_ip', 'created_at', 'last_used_at', 'first_consented_at', 'status' );

    /**
     * Approval columns a panel list may show: no handle hash, digests, sealed columns or gate values.
     *
     * @var array
     */
    const APPROVAL_LIST_COLUMNS = array( 'id', 'grant_id', 'user_id', 'client_id', 'ability', 'tier', 'policy_version', 'target_ids', 'summary', 'status', 'created_at', 'expires_at' );

    /**
     * Job columns a panel list may show; the sealed plan is never one.
     *
     * @var array
     */
    const JOB_LIST_COLUMNS = array( 'id', 'approval_id', 'grant_id', 'user_id', 'policy_version', 'next_entry', 'status', 'last_error', 'created_at', 'updated_at' );

    /**
     * One user's usable connections for the panel: live and unexpired, newest first.
     *
     * @param int         $user_id     User id.
     * @param string|null $client_kind Only this client kind, or null for all.
     * @return array Rows with GRANT_LIST_COLUMNS.
     */
    public function grants_for_user( int $user_id, ?string $client_kind = null ): array {
        return $this->live_grants( $user_id, $client_kind );
    }

    /**
     * Every user's usable connections, for full administrators.
     *
     * @param string|null $client_kind Only this client kind, or null for all.
     * @return array Rows with GRANT_LIST_COLUMNS.
     */
    public function all_live_grants( ?string $client_kind = null ): array {
        return $this->live_grants( null, $client_kind );
    }

    /**
     * One user's usable connection tokens for the panel.
     *
     * @param int $user_id User id.
     * @return array Rows with `grant_id`, `label`, `scope`, `approval_policy`, `created_at`,
     *               `expires_at` and `last_used_at`.
     */
    public function connection_tokens_for_user( int $user_id ): array {
        $rows = array();
        foreach ( $this->live_grants( $user_id, 'token' ) as $grant ) {
            if ( self::CONNECTION_TOKEN_CLIENT_ID !== $grant['client_id'] ) {
                continue;
            }
            $rows[] = array(
                'grant_id'        => (int) $grant['id'],
                'label'           => (string) $grant['label'],
                'scope'           => (string) $grant['scope'],
                'approval_policy' => (string) $grant['approval_policy'],
                'created_at'      => (int) $grant['created_at'],
                'expires_at'      => (int) $grant['session_expires_at'],
                'last_used_at'    => (int) $grant['last_used_at'],
            );
        }
        return $rows;
    }

    /**
     * Registered or admin-added clients for the panel, newest first, with `redirect_uris` decoded.
     *
     * @param string $kind `registered` or `webapp`.
     * @return array Rows with CLIENT_LIST_COLUMNS; empty for any other kind.
     */
    public function clients_by_kind( string $kind ): array {
        if ( ! in_array( $kind, array( 'registered', 'webapp' ), true ) ) {
            return array();
        }
        $rows    = $this->db->get_results(
            $this->db->prepare(
                'SELECT ' . implode( ', ', self::CLIENT_LIST_COLUMNS ) . ' FROM %i WHERE kind = %s ORDER BY created_at DESC, id DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the column list is a class constant.
                $this->table( 'clients' ),
                $kind
            ),
            ARRAY_A
        );
        $clients = array();
        foreach ( (array) $rows as $row ) {
            $uris                 = json_decode( (string) $row['redirect_uris'], true );
            $row['redirect_uris'] = is_array( $uris ) ? $uris : array();
            $clients[]            = $row;
        }
        return $clients;
    }

    /**
     * One user's pending Dashboard-tier approvals, unexpired, newest first, with `target_ids`
     * decoded. Only the user's own rows, whatever their role.
     *
     * @param int $user_id User id.
     * @return array Rows with APPROVAL_LIST_COLUMNS.
     */
    public function pending_approvals_for_user( int $user_id ): array {
        $rows      = $this->db->get_results(
            $this->db->prepare(
                'SELECT ' . implode( ', ', self::APPROVAL_LIST_COLUMNS ) . ' FROM %i WHERE user_id = %d AND tier = \'dashboard\' AND status = \'pending\' AND expires_at > %d ORDER BY id DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the column list is a class constant.
                $this->table( 'approvals' ),
                $user_id,
                $this->now()
            ),
            ARRAY_A
        );
        $approvals = array();
        foreach ( (array) $rows as $row ) {
            $ids               = null === $row['target_ids'] ? null : json_decode( (string) $row['target_ids'], true );
            $row['target_ids'] = is_array( $ids ) ? array_map( 'intval', $ids ) : null;
            $approvals[]       = $row;
        }
        return $approvals;
    }

    /**
     * One user's jobs, newest first.
     *
     * @param int         $user_id User id.
     * @param string|null $status  Only this status, or null for all.
     * @return array Rows with JOB_LIST_COLUMNS.
     */
    public function jobs_for_user( int $user_id, ?string $status = null ): array {
        $sql  = 'SELECT ' . implode( ', ', self::JOB_LIST_COLUMNS ) . ' FROM %i WHERE user_id = %d';
        $args = array( $this->table( 'jobs' ), $user_id );
        if ( null !== $status ) {
            $sql   .= ' AND status = %s';
            $args[] = $status;
        }
        $rows = $this->db->get_results( $this->db->prepare( $sql . ' ORDER BY id DESC', ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments holding only placeholders.
        return (array) $rows;
    }

    /**
     * Live, unexpired grants, newest first.
     *
     * @param int|null    $user_id     One user, or null for every user.
     * @param string|null $client_kind One client kind, or null for all.
     * @return array Rows with GRANT_LIST_COLUMNS.
     */
    private function live_grants( ?int $user_id, ?string $client_kind ): array {
        $sql  = 'SELECT ' . implode( ', ', self::GRANT_LIST_COLUMNS ) . ' FROM %i WHERE status = \'live\' AND session_expires_at > %d';
        $args = array( $this->table( 'grants' ), $this->now() );
        if ( null !== $user_id ) {
            $sql   .= ' AND user_id = %d';
            $args[] = $user_id;
        }
        if ( null !== $client_kind ) {
            $sql   .= ' AND client_kind = %s';
            $args[] = $client_kind;
        }
        $rows = $this->db->get_results( $this->db->prepare( $sql . ' ORDER BY created_at DESC, id DESC', ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed fragments holding only placeholders.
        return (array) $rows;
    }

    /**
     * Space-separated scope as a list of distinct tokens, in order.
     *
     * @param string $scope Scope string.
     * @return string[]
     */
    public static function scope_tokens( string $scope ): array {
        return array_values( array_unique( array_filter( explode( ' ', $scope ), 'strlen' ) ) );
    }

    /**
     * Scope tokens present in both, in the order of the first.
     *
     * @param string $first  Scope.
     * @param string $second Scope.
     * @return string
     */
    public static function intersect_scopes( string $first, string $second ): string {
        return implode( ' ', array_values( array_intersect( self::scope_tokens( $first ), self::scope_tokens( $second ) ) ) );
    }

    /**
     * Client kind for a client_id, from the client id formats: the synthetic connection-token
     * id, `mwplc_` registrations, `mwpwa_` web apps, bundled CIMD URLs, and anything else (the
     * `mainwp-*` static ids) as static. Kept on the grant for display, purge and audit; which ids
     * are accepted at all is Known_Clients' decision.
     *
     * @param string $client_id Client id.
     * @return string
     */
    public static function client_kind_for( string $client_id ): string {
        if ( self::CONNECTION_TOKEN_CLIENT_ID === $client_id ) {
            return 'token';
        }
        if ( 0 === strpos( $client_id, 'mwplc_' ) ) {
            return 'registered';
        }
        if ( 0 === strpos( $client_id, 'mwpwa_' ) ) {
            return 'webapp';
        }
        return 0 === strpos( $client_id, 'https://' ) ? 'bundled' : 'static';
    }
}
