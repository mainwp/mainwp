<?php
/**
 * Remote MCP authorization endpoint.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * `route_authorize`: GET validates the request and shows consent; POST records the user's
 * decision. This is a browser page under cookie login, not a REST route, and a
 * bearer token means nothing here: only the WordPress session identifies the user.
 *
 * Nothing redirects to the client until its client_id and redirect URI are verified, and a
 * consent decision redirects only after the client passes the same checks again.
 */
final class OAuth_Authorize_Endpoint implements Route_Handler {

    /**
     * Consent form fields.
     *
     * @var array
     */
    const CONSENT_PARAMS = array( 'request_key', 'decision', 'access', 'policy', '_wpnonce' );

    /**
     * Nonce action prefix; the request key completes it, so a nonce works for one request only.
     *
     * @var string
     */
    const NONCE_ACTION = 'mainwp_oauth_consent_';

    /**
     * Fixed `error_description` per code; nothing from the request is echoed.
     *
     * @var array
     */
    const ERROR_DESCRIPTIONS = array(
        'invalid_scope'         => 'The requested scope is not supported.',
        'request_not_supported' => 'Request objects are not supported.',
        'access_denied'         => 'The user denied the request.',
    );

    /**
     * Module services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Pages.
     *
     * @var OAuth_Consent_Page
     */
    private $page;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
        $this->page     = new OAuth_Consent_Page( $services );
    }

    /**
     * Handle a GET or a consent POST.
     *
     * @param Remote_MCP_Request $request Request.
     * @return Remote_MCP_Response
     */
    public function handle( Remote_MCP_Request $request ): Remote_MCP_Response {
        OAuth_Metadata::no_page_cache();
        // Before any parameter is read, client looked up or row touched, also when reached
        // without the front controller.
        if ( ! is_ssl() ) {
            return $this->page->error( 'https_required' );
        }
        $validator = new OAuth_Request_Validator( $this->clients(), $this->services->urls() );
        if ( 'POST' === $request->method() ) {
            return $this->decide( $request, $validator );
        }
        return $this->authorize( $request, $validator );
    }

    /**
     * Validate the authorization request, then show the consent screen.
     *
     * @param Remote_MCP_Request      $request   Request.
     * @param OAuth_Request_Validator $validator Rules.
     * @return Remote_MCP_Response
     */
    private function authorize( Remote_MCP_Request $request, OAuth_Request_Validator $validator ): Remote_MCP_Response {
        $check = $validator->check_request( $request );
        if ( isset( $check['error'] ) ) {
            return $this->page->error( $check['error'] );
        }
        $client = $check['client'];
        $params = $check['params'];

        // Only a request naming a known client gets here, so the login hand-off can't be used to
        // dress up an unknown app.
        if ( ! is_user_logged_in() ) {
            return new Remote_MCP_Response( 302, array( 'Location' => wp_login_url( $this->return_url( $request ) ) ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return $this->page->error( 'insufficient_rights' );
        }
        if ( ! $validator->redirect_allowed( $client, $params['redirect_uri'] ) ) {
            return $this->page->error( 'redirect_mismatch', $client );
        }

        // The redirect is verified: from here on, errors go back to the client.
        if ( array_intersect( array( 'request', 'request_uri' ), $params['names'] ) ) {
            return $this->client_error( $params['redirect_uri'], $params['state'], 'request_not_supported' );
        }
        $scope = OAuth_Request_Validator::normalize_scope( $params['scope'] );
        if ( null === $scope ) {
            return $this->client_error( $params['redirect_uri'], $params['state'], 'invalid_scope' );
        }

        $store = $this->services->store();
        $row   = null === $store ? null : $store->create_request(
            array(
                'user_id'        => get_current_user_id(),
                'client_id'      => $params['client_id'],
                'client_host'    => self::client_host( $client, $params['redirect_uri'] ),
                'redirect_uri'   => $params['redirect_uri'],
                'scope'          => $scope,
                'resource'       => $params['resource'],
                'code_challenge' => $params['code_challenge'],
                'state'          => $params['state'],
            )
        );
        if ( null === $row ) {
            return $this->page->error( 'server_error' );
        }
        return $this->page->consent(
            array(
                'request_key'  => $row['request_key'],
                'client'       => $client,
                'redirect_uri' => $params['redirect_uri'],
                'scope'        => $scope,
                'return_url'   => $this->return_url( $request ),
            )
        );
    }

    /**
     * The consent POST. Every authorization parameter comes from the stored row; only the
     * decision and the two choices come from the form, and they are checked before the claim.
     *
     * @param Remote_MCP_Request      $request   Request.
     * @param OAuth_Request_Validator $validator Rules.
     * @return Remote_MCP_Response
     */
    private function decide( Remote_MCP_Request $request, OAuth_Request_Validator $validator ): Remote_MCP_Response {
        $parsed = $request->body_params( self::CONSENT_PARAMS );
        if ( null !== $parsed['duplicate'] ) {
            return $this->page->error( 'duplicate_parameter' );
        }
        $values = $parsed['values'];
        if ( ! is_user_logged_in() ) {
            return $this->page->error( 'login_required' );
        }
        $key = $values['request_key'];
        if ( null === $key ) {
            return $this->page->error( 'request_unavailable' );
        }
        if ( ! wp_verify_nonce( (string) $values['_wpnonce'], self::NONCE_ACTION . $key ) ) {
            return $this->page->error( 'invalid_nonce' );
        }
        $store = $this->services->store();
        if ( null === $store ) {
            return $this->page->error( 'request_unavailable' );
        }
        $user_id = get_current_user_id();
        $row     = $store->find_request( $key );
        // A missing `expired` flag counts as expired. The claim checks status, expiry and owner
        // again in its own statement; these checks decide which page the user sees.
        if ( ! is_array( $row ) || 'pending' !== ( $row['status'] ?? null ) || false !== ( $row['expired'] ?? true ) || (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
            return $this->page->error( 'request_unavailable' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return $this->page->error( 'insufficient_rights' );
        }
        $decision = $values['decision'];
        if ( 'approve' !== $decision && 'deny' !== $decision ) {
            return $this->page->error( 'invalid_choice' );
        }

        // A plugin update or an administrator can remove a client, switch it off or change its
        // redirects while consent is open. Checked for Deny too: access_denied is a redirect.
        $client = $this->clients()->lookup( (string) $row['client_id'], true );
        if ( ! OAuth_Request_Validator::accepts( $client, (string) $row['client_id'] ) || ! $validator->redirect_allowed( $client, (string) $row['redirect_uri'] ) ) {
            $store->fail_request( (int) $row['id'] );
            return $this->page->error( 'client_unavailable' );
        }

        $audit = array(
            'user_id'     => $user_id,
            'client_host' => (string) $row['client_host'],
            'client_kind' => (string) $client['kind'],
        );
        if ( 'deny' === $decision ) {
            if ( ! $store->claim_request( (int) $row['id'], $user_id, false ) ) {
                return $this->page->error( 'already_answered' );
            }
            Remote_MCP_Events::audit( 'consent_denied', $audit );
            return $this->client_error( (string) $row['redirect_uri'], $row['state'], 'access_denied' );
        }

        $choice = self::choice( (string) $row['scope'], $values['access'], $values['policy'] );
        if ( null === $choice ) {
            return $this->page->error( 'invalid_choice' );
        }
        // One affected row wins: nonces aren't consumed on use, so this claim is what stops a
        // second submission, a racing Deny or a replayed form.
        if ( ! $store->claim_request( (int) $row['id'], $user_id, true, $choice['scope'], $choice['policy'] ) ) {
            return $this->page->error( 'already_answered' );
        }
        $code = $store->issue_code( (int) $row['id'] );
        if ( null === $code ) {
            return $this->page->error( 'code_failed' );
        }
        if ( 'registered' === $client['kind'] ) {
            // A consented registration is kept until removed in the panel.
            $store->mark_client_consented( (string) $row['client_id'] );
        }
        $audit['approval_policy'] = null === $choice['policy'] ? OAuth_Store::POLICY_STRICT : $choice['policy'];
        Remote_MCP_Events::audit( 'consent_approved', $audit );
        Remote_MCP_Events::audit( 'code_issued', $audit );
        return $this->redirect( (string) $row['redirect_uri'], array( 'code' => $code ), $row['state'] );
    }

    /**
     * The user's access and policy choice, checked against what the consent screen offered.
     *
     * @param string      $requested Requested scope.
     * @param string|null $access    `read` or `write`.
     * @param string|null $policy    One of OAuth_Store::POLICIES.
     * @return array|null `scope` and `policy` for claim_request(), or null when not allowed.
     */
    private static function choice( string $requested, ?string $access, ?string $policy ): ?array {
        $tokens = OAuth_Store::scope_tokens( $requested );
        if ( ! in_array( OAuth_Store::SCOPE_WRITE, $tokens, true ) ) {
            // No choice was shown. Passing none makes the store record view only under Strict.
            if ( ( null !== $access && 'read' !== $access ) || null !== $policy ) {
                return null;
            }
            return array(
                'scope'  => null,
                'policy' => null,
            );
        }
        if ( ! in_array( $access, array( 'read', 'write' ), true ) || ! in_array( $policy, OAuth_Store::POLICIES, true ) ) {
            return null;
        }
        if ( OAuth_Store::POLICY_HANDS_OFF === $policy && ! Remote_MCP_Team_Control::user_has_no_limits( get_current_user_id() ) ) {
            return null;
        }
        return array(
            'scope'  => 'write' === $access ? $requested : implode( ' ', array_diff( $tokens, array( OAuth_Store::SCOPE_WRITE ) ) ),
            'policy' => $policy,
        );
    }

    /**
     * Error redirect to a verified redirect URI.
     *
     * @param string      $redirect_uri Verified redirect URI.
     * @param string|null $state        Client state.
     * @param string      $error        Error code.
     * @return Remote_MCP_Response
     */
    private function client_error( string $redirect_uri, ?string $state, string $error ): Remote_MCP_Response {
        return $this->redirect(
            $redirect_uri,
            array(
                'error'             => $error,
                'error_description' => self::ERROR_DESCRIPTIONS[ $error ],
            ),
            $state
        );
    }

    /**
     * Redirect to the client with `state` and RFC 9207 `iss` appended. The redirect URI is used
     * exactly as stored, never through a WordPress URL sanitizer, which would change bytes the
     * client compares.
     *
     * @param string      $redirect_uri Verified redirect URI.
     * @param array       $params       Response parameters.
     * @param string|null $state        Client state.
     * @return Remote_MCP_Response
     */
    private function redirect( string $redirect_uri, array $params, ?string $state ): Remote_MCP_Response {
        if ( null !== $state && '' !== $state ) {
            $params['state'] = $state;
        }
        $params['iss'] = (string) $this->services->urls()->issuer();
        $pairs         = array();
        foreach ( $params as $name => $value ) {
            $pairs[] = $name . '=' . rawurlencode( (string) $value );
        }
        $location = $redirect_uri . ( false === strpos( $redirect_uri, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
        return new Remote_MCP_Response( 302, array( 'Location' => $location ) );
    }

    /**
     * The URL this GET arrived at, for the login hand-off and "Log out", built from the canonical
     * endpoint so a forged Host can't send the user elsewhere.
     *
     * @param Remote_MCP_Request $request Request.
     * @return string
     */
    private function return_url( Remote_MCP_Request $request ): string {
        $urls  = $this->services->urls();
        $query = $request->raw_query();
        if ( $urls->plain_permalinks() ) {
            // The raw query already carries the `mainwp_oauth` selector.
            return $urls->base() . '/?' . $query;
        }
        return (string) $urls->authorization_endpoint() . ( '' === $query ? '' : '?' . $query );
    }

    /**
     * Host shown and logged for a request: the vendor's for a bundled client, otherwise where the
     * user returns.
     *
     * @param array  $client       Descriptor.
     * @param string $redirect_uri Redirect URI.
     * @return string
     */
    private static function client_host( array $client, string $redirect_uri ): string {
        $source = 'bundled' === $client['kind'] ? (string) $client['client_id'] : $redirect_uri;
        $host   = wp_parse_url( $source, PHP_URL_HOST );
        return is_string( $host ) ? substr( strtolower( $host ), 0, 255 ) : '';
    }

    /**
     * Client lookup.
     *
     * @return Known_Clients
     */
    private function clients(): Known_Clients {
        return $this->services->get( 'known_clients' );
    }
}
