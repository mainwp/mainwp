<?php
/**
 * Remote MCP admin panel.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The remote connector's panel, shown as the "Connect an app" view of the MainWP MCP page, and
 * its actions.
 *
 * Every action is a POST with a nonce bound to the action and its target, and needs
 * `manage_options`. Site-wide settings and actions that touch other users or shared rows also
 * need a user without Team Control limits, checked here in the handler: hiding a button is
 * never the check. Policy and scope edits are the connection owner's alone.
 */
class Remote_MCP_Admin implements Hook_Provider {

    /**
     * A URL single-quoted for a POSIX shell. The snippets are pasted into the user's shell, which
     * escapeshellarg() can't know: it follows the server's OS.
     *
     * @param string $value Value.
     * @return string
     */
    private static function shell_quote( string $value ): string {
        return "'" . str_replace( "'", "'\\''", $value ) . "'";
    }

    /**
     * Actions that only end or narrow access, so they run on an installed store.
     */
    const REVOKE_ACTIONS = array( 'revoke', 'disconnect_mine', 'disconnect_all', 'narrow_scope', 'webapp_delete', 'registration_remove' );

    /**
     * The panel's place on the MainWP MCP page.
     *
     * @var string
     */
    const PAGE = 'admin.php?page=mainwp-mcp&tab=direct';

    /**
     * Who may run each action: `admin` (manage_options) or `full` (also no Team Control limits).
     * Revoke, policy and scope add their own ownership rule.
     *
     * @var array
     */
    const ACTIONS = array(
        'toggle'               => 'full',
        'revoke'               => 'admin',
        'disconnect_mine'      => 'admin',
        'disconnect_all'       => 'full',
        'narrow_scope'         => 'admin',
        'set_policy'           => 'admin',
        'mint_token'           => 'admin',
        'webapp_add'           => 'full',
        'webapp_delete'        => 'full',
        'webapp_reissue'       => 'full',
        'webapp_switch'        => 'full',
        'registration_remove'  => 'full',
        'registration_switch'  => 'full',
        'static_client_switch' => 'full',
        'tool_switch'          => 'full',
        'category_switch'      => 'full',
        'unaudited_switch'     => 'full',
        'save_tools'           => 'full',
        'self_test'            => 'admin',
        'external_check'       => 'admin',
        'dismiss_home_changed' => 'admin',
        'dismiss_notices'      => 'admin',
    );

    /**
     * The static clients and their switch labels.
     *
     * @var array
     */
    const STATIC_CLIENTS = array(
        'mainwp-cursor'     => 'Cursor',
        'mainwp-gemini-cli' => 'Gemini CLI',
    );

    /**
     * Connection token lifetimes on offer, in days. There is no "never".
     *
     * @var array
     */
    const TOKEN_DAYS = array( '30', '90', '365' );

    /**
     * Default token lifetime.
     *
     * @var string
     */
    const TOKEN_DEFAULT_DAYS = '90';

    /**
     * A connection with no request this long after consent gets the host help line.
     *
     * @var int
     */
    const NO_REQUEST_AFTER = 600;

    /**
     * How loose each policy is; a change to a higher rank sends the new-connection notice.
     *
     * @var array
     */
    const POLICY_RANK = array(
        'strict'    => 0,
        'standard'  => 1,
        'hands_off' => 2,
    );

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
     * Add the panel, the self-test's probe responder and the notices.
     *
     * @param Remote_MCP_Services $services Services.
     */
    public function register_hooks( Remote_MCP_Services $services ): void {
        add_filter( 'mainwp_mcp_direct_view', array( $this, 'direct_view' ) );
        // Before the front controller (priority 1): probes must be answered while the connector is off.
        add_action( 'parse_request', array( $this->self_test(), 'answer_probe' ), 0 );
        ( new Remote_MCP_Notices( $this->services ) )->register_hooks();
    }

    /**
     * Offer the panel as the MainWP MCP page's "Connect an app" view.
     *
     * @param mixed $view View an earlier callback offered.
     * @return mixed `render`, `preferred` and `apps`, or $view unchanged on a multisite.
     */
    public function direct_view( $view ) {
        $requirements = $this->requirements();
        if ( 'multisite' === $requirements['reason'] ) {
            return $view;
        }
        return array(
            'render'             => array( $this, 'render_page' ),
            'render_with_header' => array( $this, 'render_page' ),
            // Where the connector can't run, the local server is the better first view.
            'preferred'          => $requirements['ok'] && ( Remote_MCP_Settings::enabled() || $this->services->urls()->is_secure() ),
            'apps'               => self::known_app_labels(),
        );
    }

    /**
     * Names of the apps that can connect without being added first: the bundled clients and the
     * static ones. A bundled entry without a hash is refused, so it isn't named.
     *
     * @return array
     */
    public static function known_app_labels(): array {
        $labels = array();
        foreach ( OAuth_Known_Clients::REGISTRY as $entry ) {
            if ( ! empty( $entry['sha256'] ) ) {
                $labels[] = (string) $entry['label'];
            }
        }
        foreach ( array_keys( OAuth_Known_Clients::STATIC_CLIENTS ) as $client_id ) {
            $labels[] = self::STATIC_CLIENTS[ $client_id ] ?? $client_id;
        }
        return $labels;
    }

    /**
     * View callback: run a posted action, then render.
     *
     * @param callable|null $render_header Page heading, rendered after page-level messages.
     */
    public function render_page( ?callable $render_header = null ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<div class="ui message">' . esc_html__( 'Only administrators can manage AI apps.', 'mainwp' ) . '</div>';
            return;
        }
        // The page enqueues its own scripts before it knows which tab renders, so the panel adds
        // its script here, to the footer.
        wp_enqueue_script( 'mainwp-remote-mcp-admin-panel', plugins_url( 'assets/admin-panel.js', __FILE__ ), array( 'jquery' ), MAINWP_VERSION, true );
        $result = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_post() verifies the per-action nonce first.
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['mainwp_remote_mcp_action'] ) ) {
            $result = $this->handle_post( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside.
        }
        echo $this->render( $result, $render_header ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes.
    }

    /**
     * Run one posted action.
     *
     * @param array $post Posted fields, unslashed.
     * @return array `ok`, `code`, `message`, `action`, plus action-specific fields.
     */
    public function handle_post( array $post ): array {
        $action = isset( $post['mainwp_remote_mcp_action'] ) && is_string( $post['mainwp_remote_mcp_action'] ) ? $post['mainwp_remote_mcp_action'] : '';
        $target = isset( $post['target'] ) && is_string( $post['target'] ) ? $post['target'] : '';
        if ( ! isset( self::ACTIONS[ $action ] ) ) {
            return self::fail( 'invalid', $action );
        }
        $nonce = isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) ? $post['_wpnonce'] : '';
        if ( ! wp_verify_nonce( $nonce, self::nonce_action( $action, $target ) ) ) {
            return self::fail( 'bad_nonce', $action, __( 'This form expired. Reload the page and try again.', 'mainwp' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return self::fail( 'forbidden', $action );
        }
        if ( 'full' === self::ACTIONS[ $action ] && ! Remote_MCP_Team_Control::user_has_no_limits( get_current_user_id() ) ) {
            return self::fail( 'forbidden', $action, __( 'Only administrators without Team Control limits can change this.', 'mainwp' ) );
        }
        $requirements = $this->requirements();
        if ( ! $requirements['ok'] ) {
            return self::fail( 'requirements', $action, Remote_MCP_Requirements::message( $requirements ) );
        }
        $store_actions = array( 'revoke', 'disconnect_mine', 'disconnect_all', 'narrow_scope', 'set_policy', 'mint_token', 'webapp_add', 'webapp_delete', 'webapp_reissue', 'webapp_switch', 'registration_remove' );
        if ( in_array( $action, $store_actions, true ) && ! Remote_MCP_Install::is_installed() ) {
            return self::fail( 'not_installed', $action, __( 'Turn on remote AI connections first.', 'mainwp' ) );
        }
        // Ending connections works whenever the tables exist, even while the home can't serve MCP;
        // anything that issues or loosens access still needs a servable home.
        $store = in_array( $action, self::REVOKE_ACTIONS, true ) ? $this->services->installed_store() : $this->services->store();
        if ( in_array( $action, $store_actions, true ) && ! $store ) {
            return self::fail( 'insecure_home', $action, __( 'Your site address has to start with https:// first.', 'mainwp' ) );
        }
        $method = 'action_' . $action;
        $result = $this->$method( $target, $post );
        return array_merge( array( 'action' => $action ), $result );
    }

    /**
     * Turn the connector on or off.
     *
     * @param string $target `on` or `off`.
     * @return array
     */
    private function action_toggle( string $target ): array {
        $remote = new Remote_MCP( $this->services );
        if ( 'on' === $target ) {
            $forced = Remote_MCP_Settings::forced_off();
            if ( null !== $forced ) {
                return self::fail( 'forced_off', 'toggle', self::forced_off_message( $forced ) );
            }
            $reasons = $remote->enable();
            if ( $reasons ) {
                return self::fail( 'blocked', 'toggle', __( 'Can\'t turn on yet. Fix the failed checks below first.', 'mainwp' ), array( 'reasons' => $reasons ) );
            }
            return self::ok( __( 'Remote AI connections are on.', 'mainwp' ) );
        }
        if ( 'off' === $target ) {
            $ended = $remote->disable();
            return self::ok( $ended ? __( 'Remote AI connections are off, and every connection has ended.', 'mainwp' ) : __( 'Remote AI connections are off. Ending every connection didn\'t fully complete; turn them off again to retry.', 'mainwp' ) );
        }
        return self::fail( 'invalid', 'toggle' );
    }

    /**
     * Revoke one connection: the owner's own, or anyone's for a full administrator.
     *
     * @param string $target Grant id.
     * @return array
     */
    private function action_revoke( string $target ): array {
        $store = $this->services->installed_store();
        $grant = $store ? $store->get_grant( (int) $target ) : null;
        if ( ! $grant ) {
            return self::fail( 'invalid', 'revoke' );
        }
        $me = get_current_user_id();
        if ( (int) $grant['user_id'] !== $me && ! Remote_MCP_Team_Control::user_has_no_limits( $me ) ) {
            return self::fail( 'forbidden', 'revoke' );
        }
        try {
            $store->revoke_grant( (int) $grant['id'], 'manual' );
        } catch ( \RuntimeException $e ) {
            return self::fail( 'failed', 'revoke' );
        }
        $this->audit( 'grant_revoked', $grant, array( 'reason' => 'manual' ) );
        return self::ok( __( 'Revoked. The app is signed out the next time it tries.', 'mainwp' ) );
    }

    /**
     * End every connection of the current user.
     *
     * @return array
     */
    private function action_disconnect_mine(): array {
        $store = $this->services->installed_store();
        if ( ! $store || null === $store->bump_user_generation( get_current_user_id(), 'manual' ) ) {
            return self::fail( 'failed', 'disconnect_mine' );
        }
        Remote_MCP_Events::audit(
            'grant_revoked',
            array(
                'user_id' => get_current_user_id(),
                'reason'  => 'disconnect_mine',
                'outcome' => 'ok',
            )
        );
        return self::ok( __( 'All your connections have ended.', 'mainwp' ) );
    }

    /**
     * End every connection on the Dashboard by rotating the secret, audited as `secret_rotated`.
     *
     * @return array
     */
    private function action_disconnect_all(): array {
        $store = $this->services->installed_store();
        if ( ! $store ) {
            return self::fail( 'insecure_home', 'disconnect_all' );
        }
        try {
            $store->rotate_secret();
        } catch ( \RuntimeException $e ) {
            return self::fail( 'failed', 'disconnect_all', __( 'Not every connection could be ended. Try again.', 'mainwp' ) );
        }
        Remote_MCP_Events::audit(
            'grant_revoked',
            array(
                'user_id' => get_current_user_id(),
                'reason'  => 'secret_rotated',
                'outcome' => 'ok',
            )
        );
        return self::ok( __( 'Every connection on this Dashboard has ended.', 'mainwp' ) );
    }

    /**
     * Narrow "View and make changes" to "View only", owner only.
     *
     * @param string $target Grant id.
     * @return array
     */
    private function action_narrow_scope( string $target ): array {
        $grant = $this->owned_grant( (int) $target, 'scope_narrowed' );
        if ( isset( $grant['code'] ) ) {
            return $grant;
        }
        $before = (string) $grant['scope'];
        $tokens = OAuth_Store::scope_tokens( $before );
        if ( ! in_array( OAuth_Store::SCOPE_WRITE, $tokens, true ) ) {
            return self::fail( 'invalid', 'narrow_scope', __( 'This connection is already view only.', 'mainwp' ) );
        }
        $after = implode( ' ', array_values( array_diff( $tokens, array( OAuth_Store::SCOPE_WRITE ) ) ) );
        if ( ! $this->services->installed_store()->narrow_grant_scope( (int) $grant['id'], $after ) ) {
            return self::fail( 'failed', 'narrow_scope' );
        }
        $this->audit(
            'scope_narrowed',
            $grant,
            array(
                'scope_before' => $before,
                'scope_after'  => $after,
            )
        );
        return self::ok( __( 'This connection is now view only, starting with its next request.', 'mainwp' ) );
    }

    /**
     * Change a connection's approval policy, owner only.
     *
     * @param string $target Grant id.
     * @param array  $post   Fields: `policy`.
     * @return array
     */
    private function action_set_policy( string $target, array $post ): array {
        $grant = $this->owned_grant( (int) $target, 'policy_changed' );
        if ( isset( $grant['code'] ) ) {
            return $grant;
        }
        $policy = isset( $post['policy'] ) && is_string( $post['policy'] ) ? $post['policy'] : '';
        if ( ! in_array( $policy, OAuth_Store::POLICIES, true ) ) {
            return self::fail( 'invalid', 'set_policy' );
        }
        if ( OAuth_Store::POLICY_HANDS_OFF === $policy && ! Remote_MCP_Team_Control::user_has_no_limits( (int) $grant['user_id'] ) ) {
            return self::fail( 'hands_off_not_allowed', 'set_policy', __( 'Hands-off is only for users without Team Control limits.', 'mainwp' ) );
        }
        $before = (string) $grant['approval_policy'];
        if ( ! $this->services->store()->set_approval_policy( (int) $grant['id'], $policy ) ) {
            return self::fail( 'unchanged', 'set_policy' );
        }
        $this->audit(
            'policy_changed',
            $grant,
            array(
                'policy_before' => $before,
                'policy_after'  => $policy,
            )
        );
        if ( ( self::POLICY_RANK[ $policy ] ?? 0 ) > ( self::POLICY_RANK[ $before ] ?? 0 ) ) {
            $this->notices()->send_new_connection_notice( (int) $grant['id'], 'loosened' );
        }
        return self::ok( __( 'Policy changed. Anything that was waiting under the old policy has been cancelled.', 'mainwp' ) );
    }

    /**
     * Mint a connection token for the current user.
     *
     * @param string $target Unused.
     * @param array  $post   Fields: `label`, `access`, `policy`, `expiry`.
     * @return array
     */
    private function action_mint_token( string $target, array $post ): array {
        unset( $target );
        $store  = $this->services->store();
        $me     = get_current_user_id();
        $label  = isset( $post['label'] ) && is_string( $post['label'] ) ? trim( $post['label'] ) : '';
        $access = $post['access'] ?? '';
        $policy = $post['policy'] ?? '';
        $expiry = $post['expiry'] ?? '';
        if ( '' === $label || mb_strlen( $label ) > 64 || ! in_array( $access, array( 'read', 'write' ), true ) || ! in_array( $policy, OAuth_Store::POLICIES, true ) || ! in_array( $expiry, self::TOKEN_DAYS, true ) ) {
            return self::fail( 'invalid', 'mint_token', __( 'Name the token (up to 64 characters) and pick its access, approval policy and expiry.', 'mainwp' ) );
        }
        if ( OAuth_Store::POLICY_HANDS_OFF === $policy && ! Remote_MCP_Team_Control::user_has_no_limits( $me ) ) {
            return self::fail( 'hands_off_not_allowed', 'mint_token', __( 'Hands-off is only for users without Team Control limits.', 'mainwp' ) );
        }
        if ( ! $store ) {
            return self::fail( 'insecure_home', 'mint_token' );
        }
        $scope  = 'write' === $access ? OAuth_Store::SCOPE_READ . ' ' . OAuth_Store::SCOPE_WRITE : OAuth_Store::SCOPE_READ;
        $minted = $store->mint_connection_token( $me, $scope, $policy, $label, time() + (int) $expiry * DAY_IN_SECONDS );
        if ( OAuth_Store::OK !== $minted['outcome'] ) {
            return self::fail( 'failed', 'mint_token' );
        }
        $grant_id = (int) $minted['grant_id'];
        do_action( Remote_MCP_Events::GRANT_ISSUED, $grant_id, $me, OAuth_Store::CONNECTION_TOKEN_CLIENT_ID, 'token', 'connection_token' );
        Remote_MCP_Events::audit(
            'connection_token_minted',
            array(
                'user_id'         => $me,
                'grant_id'        => $grant_id,
                'client_kind'     => 'token',
                'approval_policy' => $policy,
                'outcome'         => 'ok',
            )
        );
        return self::ok(
            __( 'Copy the token now. It isn\'t shown again.', 'mainwp' ),
            array(
                'grant_id'     => $grant_id,
                'expires_at'   => (int) $minted['expires_at'],
                'secret'       => (string) $minted['token'],
                'secret_label' => $label,
                'snippets'     => $this->token_snippets( (string) $minted['token'] ),
            )
        );
    }

    /**
     * Add a web app.
     *
     * @param string $target Unused.
     * @param array  $post   Fields: `name`, `redirect_uris` (one per line).
     * @return array
     */
    private function action_webapp_add( string $target, array $post ): array {
        unset( $target );
        $name = isset( $post['name'] ) && is_string( $post['name'] ) ? trim( $post['name'] ) : '';
        $uris = array();
        foreach ( preg_split( '/\R/', isset( $post['redirect_uris'] ) && is_string( $post['redirect_uris'] ) ? $post['redirect_uris'] : '' ) as $uri ) {
            if ( '' !== trim( $uri ) ) {
                $uris[] = trim( $uri );
            }
        }
        if ( '' === $name || mb_strlen( $name ) > 64 || count( $uris ) < 1 || count( $uris ) > 3 ) {
            return self::fail( 'invalid', 'webapp_add', __( 'Give the app a name of up to 64 characters and one to three return addresses.', 'mainwp' ) );
        }
        // The same rule authorize applies, so an app this form accepts is one that can connect.
        $clients = $this->services->get( 'known_clients' );
        $valid   = true;
        foreach ( $uris as $uri ) {
            $valid = $clients->validate_redirect( 'webapp', $uri ) && $valid;
        }
        if ( ! $valid ) {
            return self::fail( 'invalid_redirect', 'webapp_add', __( 'Each return address must be an exact https:// address: no query strings (?), fragments (#), wildcards (*), @, %, backslashes or spaces.', 'mainwp' ) );
        }
        $store   = $this->services->store();
        $created = $store ? $store->create_client(
            'webapp',
            array(
                'redirect_uris' => $uris,
                'client_name'   => $name,
                'created_by'    => get_current_user_id(),
                'created_ip'    => self::request_ip(),
            )
        ) : array( 'outcome' => OAuth_Store::SERVER_ERROR );
        if ( OAuth_Store::OK !== $created['outcome'] ) {
            return self::fail( 'failed', 'webapp_add' );
        }
        $this->audit_client( 'client_added', 'webapp' );
        $this->notices()->record_client_event( 'client_added', $name );
        return self::ok(
            __( 'Copy the secret now. It isn\'t shown again.', 'mainwp' ),
            array(
                'client_id'    => (string) $created['client_id'],
                'secret'       => (string) $created['client_secret'],
                'secret_label' => __( 'Client secret', 'mainwp' ),
            )
        );
    }

    /**
     * Delete a web app and revoke its grants.
     *
     * @param string $target Client id.
     * @return array
     */
    private function action_webapp_delete( string $target ): array {
        $client = $this->client_of_kind( $target, 'webapp' );
        if ( null === $client ) {
            return self::fail( 'invalid', 'webapp_delete' );
        }
        if ( ! $this->services->installed_store()->delete_client( $target ) ) {
            return self::fail( 'failed', 'webapp_delete' );
        }
        $this->audit_client( 'client_deleted', 'webapp' );
        $this->notices()->record_client_event( 'client_deleted', (string) $client['client_name'] );
        return self::ok( __( 'The web app was deleted and its connections ended.', 'mainwp' ) );
    }

    /**
     * Replace a web app's secret and revoke its grants.
     *
     * @param string $target Client id.
     * @return array
     */
    private function action_webapp_reissue( string $target ): array {
        $client = $this->client_of_kind( $target, 'webapp' );
        if ( null === $client ) {
            return self::fail( 'invalid', 'webapp_reissue' );
        }
        $secret = $this->services->store()->reissue_client_secret( $target );
        if ( null === $secret ) {
            return self::fail( 'failed', 'webapp_reissue' );
        }
        $this->audit_client( 'client_secret_reissued', 'webapp' );
        $this->notices()->record_client_event( 'client_secret_reissued', (string) $client['client_name'] );
        return self::ok(
            __( 'Copy the new client secret now. It isn\'t shown again, and the app\'s connections have ended.', 'mainwp' ),
            array(
                'client_id'    => $target,
                'secret'       => $secret,
                'secret_label' => __( 'Client secret', 'mainwp' ),
            )
        );
    }

    /**
     * Switch a web app on or off; off also revokes its grants.
     *
     * @param string $target Client id.
     * @param array  $post   Fields: `value`.
     * @return array
     */
    private function action_webapp_switch( string $target, array $post ): array {
        $value  = self::switch_value( $post );
        $client = $this->client_of_kind( $target, 'webapp' );
        if ( null === $value || null === $client ) {
            return self::fail( 'invalid', 'webapp_switch' );
        }
        if ( ! $this->services->store()->set_client_active( $target, $value ) ) {
            return self::fail( 'failed', 'webapp_switch' );
        }
        $this->audit_client( 'client_changed', 'webapp' );
        $this->notices()->record_client_event( 'client_changed', (string) $client['client_name'] );
        return self::ok( $value ? __( 'The web app can connect.', 'mainwp' ) : __( 'The web app is off and its connections ended.', 'mainwp' ) );
    }

    /**
     * Remove a registered app; every user's grants for it end.
     *
     * @param string $target Client id.
     * @return array
     */
    private function action_registration_remove( string $target ): array {
        if ( null === $this->client_of_kind( $target, 'registered' ) ) {
            return self::fail( 'invalid', 'registration_remove' );
        }
        if ( ! $this->services->installed_store()->delete_client( $target ) ) {
            return self::fail( 'failed', 'registration_remove' );
        }
        $this->audit_client( 'client_deleted', 'registered' );
        return self::ok( __( 'The app was removed and its connections ended.', 'mainwp' ) );
    }

    /**
     * The "Let other apps connect" switch: apps that register themselves, and apps that publish
     * their details at an https:// address.
     *
     * @param string $target Unused.
     * @param array  $post   Fields: `value`.
     * @return array
     */
    private function action_registration_switch( string $target, array $post ): array {
        unset( $target );
        $value = self::switch_value( $post );
        return null !== $value && Remote_MCP_Settings::set_registration_enabled( $value ) ? self::ok( __( 'Saved.', 'mainwp' ) ) : self::fail( 'invalid', 'registration_switch' );
    }

    /**
     * One static client's switch.
     *
     * @param string $target Static client id.
     * @param array  $post   Fields: `value`.
     * @return array
     */
    private function action_static_client_switch( string $target, array $post ): array {
        $value = self::switch_value( $post );
        if ( null === $value || ! isset( self::STATIC_CLIENTS[ $target ] ) ) {
            return self::fail( 'invalid', 'static_client_switch' );
        }
        if ( ! Remote_MCP_Settings::set_static_client_enabled( $target, $value ) ) {
            return self::fail( 'failed', 'static_client_switch' );
        }
        // Switching it back on must not revive tokens issued before it was switched off.
        $store = $this->services->installed_store();
        if ( ! $value && null !== $store && null === $store->revoke_client_grants( $target ) ) {
            return self::fail( 'failed', 'static_client_switch' );
        }
        return self::ok( $value ? __( 'Saved.', 'mainwp' ) : __( 'Saved. Connections that used it have ended.', 'mainwp' ) );
    }

    /**
     * One tool's switch; only names the catalog lists as switchable are stored.
     *
     * @param string $target Tool name.
     * @param array  $post   Fields: `value`.
     * @return array
     */
    private function action_tool_switch( string $target, array $post ): array {
        $value = self::switch_value( $post );
        $known = array();
        foreach ( $this->catalog_rows() as $row ) {
            if ( in_array( $row['availability'], array( 'available', 'not_reviewed' ), true ) ) {
                $known[] = $row['tool_name'];
            }
        }
        if ( null === $value || ! in_array( $target, $known, true ) ) {
            return self::fail( 'invalid', 'tool_switch' );
        }
        return Remote_MCP_Settings::set_tool_enabled( $target, $value ) ? self::ok( __( 'Saved.', 'mainwp' ) ) : self::fail( 'failed', 'tool_switch' );
    }

    /**
     * One category's switch.
     *
     * @param string $target Category.
     * @param array  $post   Fields: `value`.
     * @return array
     */
    private function action_category_switch( string $target, array $post ): array {
        $value = self::switch_value( $post );
        if ( null === $value || ! isset( $this->tool_lists()['available'][ $target ] ) ) {
            return self::fail( 'invalid', 'category_switch' );
        }
        return Remote_MCP_Settings::set_category_enabled( $target, $value ) ? self::ok( __( 'Saved.', 'mainwp' ) ) : self::fail( 'failed', 'category_switch' );
    }

    /**
     * The "Not reviewed by MainWP" section switch.
     *
     * @param string $target Unused.
     * @param array  $post   Fields: `value`.
     * @return array
     */
    private function action_unaudited_switch( string $target, array $post ): array {
        unset( $target );
        $value = self::switch_value( $post );
        return null !== $value && Remote_MCP_Settings::set_unaudited_extension_tools_enabled( $value ) ? self::ok( __( 'Saved.', 'mainwp' ) ) : self::fail( 'invalid', 'unaudited_switch' );
    }

    /**
     * Save all visible tool and category switches in one submission.
     *
     * @param string $target Unused.
     * @param array  $post   Complete tool settings form.
     * @return array
     */
    private function action_save_tools( string $target, array $post ): array {
        unset( $target );
        $lists     = $this->tool_lists();
        $expected  = array(
            'tools'      => array(),
            'categories' => array(),
        );
        $tool_rows = $lists['not_reviewed'];
        foreach ( $lists['available'] as $category => $rows ) {
            $expected['categories'][ $category ] = false;
            $tool_rows                           = array_merge( $tool_rows, $rows );
        }
        foreach ( $tool_rows as $row ) {
            $expected['tools'][ $row['tool_name'] ] = false;
        }
        // The last field detects PHP's max_input_vars truncating a large form.
        if ( '1' !== ( $post['tools_settings_complete'] ?? null ) ) {
            return self::fail( 'invalid', 'save_tools', __( 'The tool settings form was incomplete. Reload the page and try again.', 'mainwp' ) );
        }
        foreach ( $expected as $section => $keys ) {
            $values = $post[ $section ] ?? array();
            if ( ! is_array( $values ) || array_diff_key( $keys, $values ) || array_diff_key( $values, $keys ) ) {
                return self::fail( 'invalid', 'save_tools', __( 'The available tools changed or the form was incomplete. Reload the page and try again.', 'mainwp' ) );
            }
            foreach ( $values as $name => $value ) {
                if ( ! in_array( $value, array( 'on', 'off' ), true ) ) {
                    return self::fail( 'invalid', 'save_tools' );
                }
                $expected[ $section ][ $name ] = 'on' === $value;
            }
        }
        $unaudited = null;
        if ( $lists['not_reviewed'] ) {
            $unaudited = self::switch_value( array( 'value' => $post['unaudited'] ?? null ) );
            if ( null === $unaudited ) {
                return self::fail( 'invalid', 'save_tools' );
            }
        }
        return Remote_MCP_Settings::set_tool_settings( $expected['tools'], $expected['categories'], $unaudited )
            ? self::ok( __( 'Tool settings saved.', 'mainwp' ) )
            : self::fail( 'failed', 'save_tools' );
    }

    /**
     * Run the self-test.
     *
     * @return array
     */
    private function action_self_test(): array {
        $report = $this->self_test()->run();
        return self::ok( '', array( 'lines' => $report['lines'] ) );
    }

    /**
     * Run the check from the internet, one request per click.
     *
     * @return array
     */
    private function action_external_check(): array {
        return self::ok( '', array( 'lines' => $this->self_test()->external_check() ) );
    }

    /**
     * Hide the "site address changed" line.
     *
     * @return array
     */
    private function action_dismiss_home_changed(): array {
        OAuth_Revocation::clear_home_changed();
        return self::ok( '' );
    }

    /**
     * Hide the notices the user's page showed. `upto` is the newest of them, not the click time,
     * so an event recorded after the page loaded still shows on the next visit.
     *
     * @param string $target Unused.
     * @param array  $post   Fields: `upto`.
     * @return array
     */
    private function action_dismiss_notices( string $target, array $post ): array {
        unset( $target );
        $upto = isset( $post['upto'] ) && is_string( $post['upto'] ) ? absint( $post['upto'] ) : 0;
        Remote_MCP_Notices::mark_seen( get_current_user_id(), $upto );
        return self::ok( '' );
    }

    /**
     * Render the panel.
     *
     * @param array         $result        Result of the action just run, shown once.
     * @param callable|null $render_header Page heading, rendered after page-level messages.
     * @return string HTML.
     */
    public function render( array $result = array(), ?callable $render_header = null ): string {
        $view = $this->view( $result );
        ob_start();
        include __DIR__ . '/templates/admin-panel.php';
        return (string) ob_get_clean();
    }

    /**
     * Everything the template shows. Rows carry display columns only; no list method returns a
     * hash, secret or sealed column, and the only secret here is the one just created, in $result.
     *
     * @param array $result Action result.
     * @return array
     */
    private function view( array $result ): array {
        $requirements = $this->requirements();
        $me           = get_current_user_id();
        $view         = array(
            'result'       => $result,
            'requirements' => $requirements,
            'requirement'  => Remote_MCP_Requirements::message( $requirements ),
            'me'           => $me,
            'panel_url'    => self::panel_url(),
        );
        if ( ! $requirements['ok'] ) {
            return $view;
        }
        $store  = $this->services->installed_store();
        $urls   = $this->services->urls();
        $full   = Remote_MCP_Team_Control::user_has_no_limits( $me );
        $forced = Remote_MCP_Settings::forced_off();
        $view  += array(
            'enabled'             => Remote_MCP_Settings::enabled(),
            'active'              => Remote_MCP_Settings::is_active(),
            'forced'              => null === $forced ? '' : self::forced_off_message( $forced ),
            'secure'              => $urls->is_secure(),
            'home_changed'        => OAuth_Revocation::home_changed_at(),
            'invalidation_failed' => (bool) OAuth_Revocation::pending_invalidations(),
            'full'                => $full,
            'mcp_url'             => (string) $urls->mcp_url(),
            'app_snippets'        => $this->app_snippets(),
            'connections'         => array(),
            'pending'             => array(),
            'help'                => $this->no_request_help(),
            'notices'             => $full ? $this->notices()->recent( Remote_MCP_Notices::seen_by( $me ) ) : array(),
            'tools'               => $this->tool_lists(),
            'registration'        => Remote_MCP_Settings::registration_enabled(),
            'unaudited'           => Remote_MCP_Settings::unaudited_extension_tools_enabled(),
            'static_clients'      => array(),
            'registered'          => array(),
            'webapps'             => array(),
            'token_count'         => 0,
            'policies'            => $full ? OAuth_Store::POLICIES : array( OAuth_Store::POLICY_STANDARD, OAuth_Store::POLICY_STRICT ),
            'token_days'          => self::TOKEN_DAYS,
            'token_default'       => self::TOKEN_DEFAULT_DAYS,
            'requested_revoke'    => isset( $_GET['revoke'] ) ? absint( $_GET['revoke'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only highlights a row; the revoke itself is a nonce-checked POST.
        );
        foreach ( self::STATIC_CLIENTS as $client_id => $label ) {
            $view['static_clients'][] = array(
                'client_id' => $client_id,
                'label'     => $label,
                'enabled'   => Remote_MCP_Settings::static_client_enabled( $client_id ),
            );
        }
        if ( $store ) {
            foreach ( $this->visible_grants( $store, $me, $full ) as $grant ) {
                $owner                 = get_userdata( (int) $grant['user_id'] );
                $view['connections'][] = array(
                    'id'         => (int) $grant['id'],
                    'own'        => (int) $grant['user_id'] === $me,
                    'label'      => self::app_label( $this->services, $grant ),
                    'kind'       => (string) $grant['client_kind'],
                    'user'       => $owner ? $owner->display_name : __( 'Deleted user', 'mainwp' ),
                    'can_write'  => in_array( OAuth_Store::SCOPE_WRITE, OAuth_Store::scope_tokens( (string) $grant['scope'] ), true ),
                    'policy'     => (string) $grant['approval_policy'],
                    'hands_off'  => (int) $grant['user_id'] === $me && $full,
                    'created_at' => (int) $grant['created_at'],
                    'expires_at' => (int) $grant['session_expires_at'],
                    'last_used'  => self::has_request( $grant ) ? (int) $grant['last_used_at'] : 0,
                    'last_ip'    => (string) $grant['last_ip'],
                    'failure'    => (string) $grant['last_failure'],
                    'failure_at' => (int) $grant['last_failure_at'],
                ) + $this->connection_name( $store, $grant );
            }
            foreach ( $store->pending_approvals_for_user( $me ) as $approval ) {
                $view['pending'][] = $this->pending_line( $store, $approval );
            }
            $view['token_count'] = count( $store->connection_tokens_for_user( $me ) );
            $view['registered']  = $store->clients_by_kind( 'registered' );
            $view['webapps']     = $full ? $store->clients_by_kind( 'webapp' ) : array();
        }
        return $view;
    }

    /**
     * A connection's name for the table and the chip that qualifies it: `self_named` for a
     * registered app's own name, `unchecked` for an app MainWP doesn't know. app_label() keeps the
     * long form for the email and the notices, which have no chips.
     *
     * @param OAuth_Store $store Store.
     * @param array       $grant Grant row.
     * @return array `name` and `chip`.
     */
    private function connection_name( OAuth_Store $store, array $grant ): array {
        $kind = (string) $grant['client_kind'];
        if ( 'registered' === $kind ) {
            $client = $store->get_client( (string) $grant['client_id'] );
            $name   = $client ? trim( (string) $client['client_name'] ) : '';
            return array(
                'name' => '' === $name ? __( 'An app on this computer', 'mainwp' ) : $name,
                'chip' => '' === $name ? '' : 'self_named',
            );
        }
        // A fetched app keeps the bundled kind, so the registry is what tells them apart.
        $unknown = 'bundled' === $kind && ! isset( OAuth_Known_Clients::REGISTRY[ (string) $grant['client_id'] ] );
        return array(
            'name' => self::app_label( $this->services, $grant ),
            'chip' => $unknown ? 'unchecked' : '',
        );
    }

    /**
     * One pending approval as the panel says it. The summary column holds the gate's JSON, which
     * the panel never shows as it is.
     *
     * @param OAuth_Store $store    Store.
     * @param array       $approval Row with OAuth_Store::APPROVAL_LIST_COLUMNS.
     * @return array `id`, `url`, `action`, `site`, `site_count`, `app` and `created_at`.
     */
    private function pending_line( OAuth_Store $store, array $approval ): array {
        $summary = json_decode( (string) $approval['summary'], true );
        $ability = is_string( $approval['ability'] ) ? $approval['ability'] : '';
        $action  = __( 'An action', 'mainwp' );
        $site    = '';
        $count   = 0;
        if ( is_array( $summary ) ) {
            $registered = '' !== $ability && function_exists( 'wp_has_ability' ) && wp_has_ability( $ability ) ? wp_get_ability( $ability ) : null;
            if ( $registered ) {
                $action = (string) $registered->get_label();
            } elseif ( '' !== $ability ) {
                $action = $ability;
            }
            $first = $summary['sites'][0] ?? null;
            if ( is_array( $first ) && isset( $first['name'] ) && is_string( $first['name'] ) && '' !== $first['name'] ) {
                $site  = $first['name'];
                $count = max( 1, isset( $summary['site_count'] ) ? (int) $summary['site_count'] : 1 );
            }
        }
        $grant = $store->get_grant( (int) $approval['grant_id'] );
        return array(
            'id'         => (int) $approval['id'],
            'url'        => $this->services->urls()->approval_url( (int) $approval['id'] ),
            'action'     => $action,
            'site'       => $site,
            'site_count' => $count,
            'app'        => $grant ? self::app_label( $this->services, $grant ) : __( 'An AI app', 'mainwp' ),
            'created_at' => (int) $approval['created_at'],
        );
    }

    /**
     * Connections the current user may see: their own, or everyone's for a full administrator.
     *
     * @param OAuth_Store $store Store.
     * @param int         $me    Current user.
     * @param bool        $full  Whether the user has no Team Control limits.
     * @return array
     */
    private function visible_grants( OAuth_Store $store, int $me, bool $full ): array {
        return $full ? $store->all_live_grants() : $store->grants_for_user( $me );
    }

    /**
     * Visible connections with no request more than 10 minutes after consent or minting.
     *
     * @return array Each `grant_id`, `label` and `help_url`.
     */
    public function no_request_help(): array {
        $store = $this->services->installed_store();
        if ( ! $store ) {
            return array();
        }
        $me    = get_current_user_id();
        $help  = array();
        $url   = Remote_MCP_Host_Detection::help_url();
        $limit = time() - self::NO_REQUEST_AFTER;
        foreach ( $this->visible_grants( $store, $me, Remote_MCP_Team_Control::user_has_no_limits( $me ) ) as $grant ) {
            if ( ! self::has_request( $grant ) && (int) $grant['created_at'] < $limit ) {
                $help[] = array(
                    'grant_id' => (int) $grant['id'],
                    'label'    => self::app_label( $this->services, $grant ),
                    'help_url' => $url,
                );
            }
        }
        return $help;
    }

    /**
     * Catalog rows grouped for the panel: switchable tools by category, the "Not reviewed by
     * MainWP" section, and the two not-available lists. Only the catalog decides.
     *
     * @return array `available` (category => rows), `not_reviewed`, `not_available_yet`, `not_available`;
     *               each row gains a `label`.
     */
    public function tool_lists(): array {
        $lists = array(
            'available'         => array(),
            'not_reviewed'      => array(),
            'not_available_yet' => array(),
            'not_available'     => array(),
        );
        foreach ( $this->catalog_rows() as $row ) {
            $row['label'] = self::tool_label( $row );
            if ( 'available' === $row['availability'] ) {
                $lists['available'][ $row['category'] ][] = $row;
            } elseif ( isset( $lists[ $row['availability'] ] ) ) {
                $lists[ $row['availability'] ][] = $row;
            }
        }
        $ordered = array();
        foreach ( OAuth_Consent_Page::category_order( array_keys( $lists['available'] ) ) as $category ) {
            $ordered[ $category ] = $lists['available'][ $category ];
        }
        $lists['available'] = $ordered;
        return $lists;
    }

    /**
     * A tool's name for people: its ability's label, or the tool name made readable. The module's
     * own job-status tool has no ability.
     *
     * @param array $row Catalog row.
     * @return string
     */
    private static function tool_label( array $row ): string {
        $ability = $row['ability'] ?? null;
        if ( is_string( $ability ) && '' !== $ability && function_exists( 'wp_has_ability' ) && wp_has_ability( $ability ) ) {
            $label = (string) wp_get_ability( $ability )->get_label();
            if ( '' !== $label ) {
                return $label;
            }
        }
        return ucfirst( str_replace( '_', ' ', (string) preg_replace( '/_v[0-9]+$/', '', $row['tool_name'] ) ) );
    }

    /**
     * Well-formed catalog rows.
     *
     * @return array
     */
    private function catalog_rows(): array {
        $rows = array();
        foreach ( (array) $this->services->get( 'tool_executor' )->catalog() as $row ) {
            if ( is_array( $row ) && isset( $row['tool_name'], $row['availability'] ) && is_string( $row['tool_name'] ) && is_string( $row['availability'] ) ) {
                $row['category'] = isset( $row['category'] ) && is_string( $row['category'] ) && '' !== $row['category'] ? $row['category'] : 'uncategorized';
                $rows[]          = $row;
            }
        }
        return $rows;
    }

    /**
     * The app's display label by client kind. Registered names are self-declared, so they're
     * marked as not checked; nothing here is escaped.
     *
     * @param Remote_MCP_Services $services Services.
     * @param array               $grant    Grant row.
     * @return string
     */
    public static function app_label( Remote_MCP_Services $services, array $grant ): string {
        $client_id = (string) $grant['client_id'];
        $kind      = (string) $grant['client_kind'];
        if ( 'token' === $kind ) {
            /* translators: %s: token name. */
            return sprintf( __( 'Connection token "%s"', 'mainwp' ), (string) $grant['label'] );
        }
        if ( 'registered' === $kind || 'webapp' === $kind ) {
            $store  = $services->store();
            $client = $store ? $store->get_client( $client_id ) : null;
            $name   = $client ? trim( (string) $client['client_name'] ) : '';
            if ( 'webapp' === $kind ) {
                return '' === $name ? __( 'A web app', 'mainwp' ) : $name;
            }
            /* translators: %s: name the app gave itself. */
            return '' === $name ? __( 'An app on this computer', 'mainwp' ) : sprintf( __( '%s (name not checked by MainWP), an app on this computer', 'mainwp' ), $name );
        }
        $entry = $services->get( 'known_clients' )->lookup( $client_id );
        if ( is_array( $entry ) && isset( $entry['label'] ) && is_string( $entry['label'] ) && '' !== $entry['label'] ) {
            return $entry['label'];
        }
        if ( isset( self::STATIC_CLIENTS[ $client_id ] ) ) {
            return self::STATIC_CLIENTS[ $client_id ];
        }
        return '' !== (string) $grant['client_host'] ? (string) $grant['client_host'] : __( 'Unknown app', 'mainwp' );
    }

    /**
     * Display name of a policy.
     *
     * @param string $policy Policy.
     * @return string
     */
    public static function policy_label( string $policy ): string {
        switch ( $policy ) {
            case OAuth_Store::POLICY_STRICT:
                return __( 'Strict', 'mainwp' );
            case OAuth_Store::POLICY_HANDS_OFF:
                return __( 'Hands-off', 'mainwp' );
        }
        return __( 'Standard', 'mainwp' );
    }

    /**
     * Why the toggle can't be used while the kill switch is on.
     *
     * @param string $reason `constant` or `filter`.
     * @return string
     */
    public static function forced_off_message( string $reason ): string {
        if ( 'constant' === $reason ) {
            return __( 'Remote AI connections are turned off on this site by the MAINWP_DISABLE_REMOTE_MCP setting in wp-config.php.', 'mainwp' );
        }
        return __( 'Remote AI connections are turned off on this site by a plugin.', 'mainwp' );
    }

    /**
     * Nonce action bound to one action and its target.
     *
     * @param string $action Action.
     * @param string $target Target.
     * @return string
     */
    public static function nonce_action( string $action, string $target = '' ): string {
        return 'mainwp_remote_mcp_' . $action . '|' . $target;
    }

    /**
     * The panel's URL.
     *
     * @return string
     */
    public static function panel_url(): string {
        return admin_url( self::PAGE );
    }

    /**
     * Platform requirements; a test subclass replaces them.
     *
     * @return array As Remote_MCP_Requirements::evaluate().
     */
    protected function requirements(): array {
        return Remote_MCP_Requirements::current();
    }

    /**
     * Notices over the same services.
     *
     * @return Remote_MCP_Notices
     */
    private function notices(): Remote_MCP_Notices {
        return new Remote_MCP_Notices( $this->services );
    }

    /**
     * The self-test from the `enable_check` slot, or a new one when the slot holds none.
     *
     * @return Remote_MCP_Self_Test
     */
    private function self_test(): Remote_MCP_Self_Test {
        $slot = $this->services->get( 'enable_check' );
        return $slot instanceof Remote_MCP_Self_Test ? $slot : new Remote_MCP_Self_Test( $this->services );
    }

    /**
     * A grant the current user owns, or a refusal. A mismatch is refused and counted whoever posts
     * it, full administrators included.
     *
     * @param int    $grant_id Grant id.
     * @param string $event    Audit event for a refusal.
     * @return array The grant row, or a failure result.
     */
    private function owned_grant( int $grant_id, string $event ): array {
        $store = $this->services->installed_store();
        $grant = $store ? $store->get_grant( $grant_id ) : null;
        if ( ! $grant || 'live' !== $grant['status'] ) {
            return self::fail( 'invalid', $event );
        }
        if ( get_current_user_id() !== (int) $grant['user_id'] ) {
            $this->audit(
                $event,
                $grant,
                array(
                    'outcome' => 'refused',
                    'reason'  => 'not_owner',
                )
            );
            return self::fail( 'not_owner', $event, __( 'Only the person who connected this app can change its access or approval policy.', 'mainwp' ) );
        }
        return $grant;
    }

    /**
     * A client row of one kind.
     *
     * @param string $client_id Client id.
     * @param string $kind      `registered` or `webapp`.
     * @return array|null
     */
    private function client_of_kind( string $client_id, string $kind ): ?array {
        $store  = $this->services->installed_store();
        $client = $store && '' !== $client_id ? $store->get_client( $client_id ) : null;
        return $client && $kind === $client['kind'] ? $client : null;
    }

    /**
     * App configurations for the panel and the one-time token result. `copy` says what step one
     * hands over: the bare `address`, a `command`, or `settings` to paste; `where` says where it
     * goes in that app. Apps whose block carries a connection token get the token steps instead;
     * their optional `where` replaces the generic last step.
     *
     * @param string $token Token, or the placeholder used before minting.
     * @return array App label => `connect`, `copy`, `where` and an optional `token` snippet.
     */
    private function app_snippets( string $token = 'mwpct_...' ): array {
        $mcp         = (string) $this->services->urls()->mcp_url();
        $json        = static function ( array $config ): string {
            return (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        };
        $http        = array( 'url' => $mcp );
        $with_token  = $http + array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) );
        $server      = static function ( array $config ) use ( $json ): string {
            return $json( array( 'mcpServers' => array( 'mainwp' => $config ) ) );
        };
        $hosted      = "Name: MainWP\nURL: " . $mcp . "\nAuthentication: OAuth";
        $header      = 'Authorization: Bearer ' . $token;
        $anything    = $server( array( 'type' => 'streamable' ) + $with_token );
        $roo         = $server( array( 'type' => 'streamable-http' ) + $with_token );
        $local_token = $server( $with_token );
        $fiveire     = "Name: MainWP\nTransport: Streamable HTTP\nURL: " . $mcp . "\n" . $header;
        $msty        = "Name: MainWP\nType: HTTP\nURL: " . $mcp . "\n" . $header;
        $continue    = "mcpServers:\n  - name: MainWP\n    type: streamable-http\n    url: " . $mcp;
        $connector   = function ( string $app ): string {
            /* translators: %s: app name. */
            return sprintf( __( 'In %s, add a custom connector and paste the address. Sign in with your Dashboard account when asked.', 'mainwp' ), $app );
        };
        $sign_in     = function ( string $where, string $app ): string {
            /* translators: 1: where the settings go, 2: app name. */
            return sprintf( __( 'Add this to %1$s, then sign in with your Dashboard account when %2$s asks.', 'mainwp' ), $where, $app );
        };
        return array(
            'Claude'         => array(
                'connect' => $hosted,
                'copy'    => 'address',
                'where'   => $connector( 'Claude' ),
            ),
            'Claude Desktop' => array(
                'connect' => $hosted,
                'copy'    => 'address',
                'where'   => $connector( 'Claude Desktop' ),
            ),
            'Claude Code'    => array(
                'connect' => 'claude mcp add --transport http mainwp ' . self::shell_quote( $mcp ),
                'copy'    => 'command',
                'where'   => __( 'Run this in a terminal, then run /mcp in Claude Code and sign in with your Dashboard account.', 'mainwp' ),
            ),
            'ChatGPT'        => array(
                'connect' => $hosted,
                'copy'    => 'address',
                'where'   => __( 'In ChatGPT on the web, add a custom MCP server and paste the address. Sign in with your Dashboard account when asked.', 'mainwp' ),
            ),
            'VS Code'        => array(
                'connect' => $json( array( 'servers' => array( 'mainwp' => array( 'type' => 'http' ) + $http ) ) ),
                'copy'    => 'settings',
                'where'   => $sign_in( '.vscode/mcp.json', 'VS Code' ),
            ),
            'Codex CLI'      => array(
                'connect' => "[mcp_servers.mainwp]\nurl = " . wp_json_encode( $mcp, JSON_UNESCAPED_SLASHES ),
                'copy'    => 'settings',
                'where'   => __( 'Add this to ~/.codex/config.toml, then run codex mcp login mainwp and sign in with your Dashboard account.', 'mainwp' ),
            ),
            'Zed'            => array(
                'connect' => $json( array( 'context_servers' => array( 'mainwp' => $http ) ) ),
                'copy'    => 'settings',
                'where'   => $sign_in( __( 'Zed\'s settings.json', 'mainwp' ), 'Zed' ),
            ),
            'Goose'          => array(
                'connect' => "extensions:\n  mainwp:\n    name: MainWP\n    type: streamable_http\n    enabled: true\n    uri: " . $mcp,
                'copy'    => 'settings',
                'where'   => $sign_in( '~/.config/goose/config.yaml', 'Goose' ),
            ),
            'Cursor'         => array(
                'connect' => $server( $http + array( 'auth' => array( 'CLIENT_ID' => 'mainwp-cursor' ) ) ),
                'copy'    => 'settings',
                'where'   => $sign_in( '~/.cursor/mcp.json', 'Cursor' ),
            ),
            'Gemini CLI'     => array(
                'connect' => $server(
                    array(
                        'httpUrl' => $mcp,
                        'oauth'   => array(
                            'enabled'  => true,
                            'clientId' => 'mainwp-gemini-cli',
                        ),
                    )
                ),
                'copy'    => 'settings',
                'where'   => __( 'Add this to ~/.gemini/settings.json, then run /mcp auth mainwp in Gemini CLI and sign in with your Dashboard account.', 'mainwp' ),
            ),
            'Qwen Code'      => array(
                'connect' => $server( array( 'httpUrl' => $mcp ) ),
                'copy'    => 'settings',
                'where'   => $sign_in( '~/.qwen/settings.json', 'Qwen Code' ),
            ),
            'Cline'          => array(
                'connect' => $server( array( 'type' => 'streamableHttp' ) + $http ),
                'copy'    => 'settings',
                'where'   => $sign_in( __( 'Cline\'s MCP settings', 'mainwp' ), 'Cline' ),
            ),
            'Jan'            => array(
                'connect' => "Name: MainWP\nTransport: HTTP\nURL: " . $mcp,
                'token'   => $local_token,
                'copy'    => 'settings',
                'where'   => __( 'In Jan, add an MCP server with these settings, then choose Sign in and use your Dashboard account.', 'mainwp' ),
            ),
            'Continue'       => array(
                'connect' => $continue,
                'token'   => $continue . "\n    requestOptions:\n      headers:\n        Authorization: Bearer " . $token,
                'copy'    => 'settings',
                'where'   => __( 'Add this to Continue\'s config.yaml, then sign in with your Dashboard account if Continue asks.', 'mainwp' ),
            ),
            'Cherry Studio'  => array(
                'connect' => "Name: MainWP\nType: Streamable HTTP\nURL: " . $mcp . "\nAuthentication: OAuth",
                'copy'    => 'settings',
                'where'   => __( 'In Cherry Studio, add an MCP server with these settings. Sign in with your Dashboard account when it asks.', 'mainwp' ),
            ),
            'mcp-remote'     => array(
                'connect' => 'npx -y mcp-remote ' . self::shell_quote( $mcp ),
                'copy'    => 'command',
                'where'   => __( 'Use this command as the MCP server in an app that only runs local servers. It opens your browser so you can sign in with your Dashboard account.', 'mainwp' ),
            ),
            'LM Studio'      => array(
                'connect' => $server( $http ),
                'token'   => $local_token,
                'copy'    => 'settings',
                'where'   => $sign_in( __( 'LM Studio\'s mcp.json', 'mainwp' ), 'LM Studio' ),
            ),
            'Open WebUI'     => array(
                'connect' => "Name: MainWP\nType: MCP (Streamable HTTP)\nURL: " . $mcp . "\nAuthentication: OAuth 2.1 (Static)\nClient ID: <client ID from Add a web app>\nClient secret: <secret shown when adding the web app>",
                'copy'    => 'settings',
                'where'   => __( 'Add Open WebUI under Web apps you added first, for its client ID and secret. Then, as an Open WebUI admin, add an MCP tool server with these settings and sign in with your Dashboard account when it asks.', 'mainwp' ),
            ),
            'AnythingLLM'    => array(
                'connect' => $anything,
                'token'   => $anything,
                'copy'    => 'settings',
                'where'   => __( 'Add it to anythingllm_mcp_servers.json in AnythingLLM\'s storage/plugins folder, then reload the MCP servers.', 'mainwp' ),
            ),
            '5ire'           => array(
                'connect' => $fiveire,
                'token'   => $fiveire,
                'copy'    => 'settings',
            ),
            'Msty'           => array(
                'connect' => $msty,
                'token'   => $msty,
                'copy'    => 'settings',
                'where'   => __( 'In Msty, go to Toolbox > Tools > Add New Tool, choose HTTP, and enter these settings.', 'mainwp' ),
            ),
            'Roo Code'       => array(
                'connect' => $roo,
                'token'   => $roo,
                'copy'    => 'settings',
            ),
        );
    }

    /**
     * Per-app config snippets for a new connection token, shown only with the mint result. The bare
     * header line is rendered beside them from the token itself.
     *
     * @param string $token Token.
     * @return array Label => snippet.
     */
    private function token_snippets( string $token ): array {
        $snippets = array();
        foreach ( $this->app_snippets( $token ) as $label => $app ) {
            if ( isset( $app['token'] ) ) {
                $snippets[ $label ] = $app['token'];
            }
        }
        return $snippets;
    }

    /**
     * Audit an action on a connection.
     *
     * @param string $event  Event.
     * @param array  $grant  Grant row.
     * @param array  $fields Extra fields.
     */
    private function audit( string $event, array $grant, array $fields ): void {
        Remote_MCP_Events::audit(
            $event,
            array_merge(
                array(
                    'user_id'     => get_current_user_id(),
                    'grant_id'    => (int) $grant['id'],
                    'client_kind' => (string) $grant['client_kind'],
                    'client_host' => (string) $grant['client_host'],
                    'outcome'     => 'ok',
                ),
                $fields
            )
        );
    }

    /**
     * Audit an action on a client row. The name is never logged.
     *
     * @param string $event Event.
     * @param string $kind  Client kind.
     */
    private function audit_client( string $event, string $kind ): void {
        Remote_MCP_Events::audit(
            $event,
            array(
                'user_id'     => get_current_user_id(),
                'client_kind' => $kind,
                'outcome'     => 'ok',
            )
        );
    }

    /**
     * Whether a request ever used the connection. The store sets last_used_at to the creation time
     * and only record_grant_use() writes last_ip.
     *
     * @param array $grant Grant row.
     * @return bool
     */
    private static function has_request( array $grant ): bool {
        return '' !== (string) $grant['last_ip'] || (int) $grant['last_used_at'] > (int) $grant['created_at'];
    }

    /**
     * `on` or `off` from the form.
     *
     * @param array $post Fields.
     * @return bool|null
     */
    private static function switch_value( array $post ) {
        $value = $post['value'] ?? '';
        return 'on' === $value ? true : ( 'off' === $value ? false : null );
    }

    /**
     * The request's address, for a web app's creating IP.
     *
     * @return string
     */
    private static function request_ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    /**
     * A success result.
     *
     * @param string $message Message.
     * @param array  $extra   Extra fields.
     * @return array
     */
    private static function ok( string $message, array $extra = array() ): array {
        return array_merge(
            array(
                'ok'      => true,
                'code'    => 'ok',
                'message' => $message,
            ),
            $extra
        );
    }

    /**
     * A refusal.
     *
     * @param string $code    Code.
     * @param string $action  Action.
     * @param string $message Message.
     * @param array  $extra   Extra fields.
     * @return array
     */
    private static function fail( string $code, string $action, string $message = '', array $extra = array() ): array {
        $defaults = array(
            'invalid'   => __( 'That request wasn\'t valid.', 'mainwp' ),
            'forbidden' => __( 'You don\'t have permission to do that.', 'mainwp' ),
            'failed'    => __( 'That didn\'t work. Try again.', 'mainwp' ),
            'unchanged' => __( 'Nothing changed.', 'mainwp' ),
        );
        return array_merge(
            array(
                'ok'      => false,
                'code'    => $code,
                'action'  => $action,
                'message' => '' !== $message ? $message : ( $defaults[ $code ] ?? $defaults['failed'] ),
            ),
            $extra
        );
    }
}
