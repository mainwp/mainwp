<?php
/**
 * Remote MCP approval page.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Dashboard approval page on the `route_approve` slot: a standalone
 * template, not wp-admin, so no theme, plugin, extension or Custom Dashboard script or style
 * loads on it and none can restyle or click it.
 *
 * Only the logged-in cookie session authenticates it: the page validates the logged-in cookie
 * itself and ignores whatever user another plugin's `determine_current_user` set, so a bearer
 * token, an API key in the query string or HTTP Basic credentials never count. Every decision is
 * a POST whose nonce is bound to the row, the decision and the user's session; a GET only shows
 * the page.
 */
class Remote_MCP_Approval_Page implements Route_Handler {

    /**
     * Decisions the page posts.
     *
     * @var array
     */
    const DECISIONS = array( 'approve', 'deny', 'run' );

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
     * Nonce action for one decision on one row.
     *
     * @param int    $approval_id Row.
     * @param string $decision    Decision.
     * @return string
     */
    public static function nonce_action( int $approval_id, string $decision ): string {
        return 'mainwp_remote_mcp_approval_' . $approval_id . '_' . $decision;
    }

    /**
     * Handle a GET or POST.
     *
     * @param Remote_MCP_Request $request Request.
     * @return Remote_MCP_Response
     */
    public function handle( Remote_MCP_Request $request ): Remote_MCP_Response {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        $previous = get_current_user_id();
        try {
            return $this->respond( $request );
        } finally {
            wp_set_current_user( $previous );
        }
    }

    /**
     * The response for a request.
     *
     * @param Remote_MCP_Request $request Request.
     * @return Remote_MCP_Response
     */
    private function respond( Remote_MCP_Request $request ): Remote_MCP_Response {
        if ( ! Remote_MCP_Settings::is_active() ) {
            return new Remote_MCP_Response( 404, self::headers( 'text/plain; charset=utf-8' ), 'Not found' );
        }
        if ( ! is_ssl() ) {
            return $this->message( 400, __( 'This page only works over https://.', 'mainwp' ) );
        }
        $query = $request->query_params( array( 'approval' ) );
        $id    = self::positive_int( $query['values']['approval'] );
        if ( null !== $query['duplicate'] || null === $id ) {
            return $this->message( 400, __( 'This approval link isn\'t valid.', 'mainwp' ) );
        }
        $post = null;
        if ( 'POST' === $request->method() ) {
            $post = $request->body_params( array( 'approval', 'decision', '_wpnonce' ) );
            if ( null !== $post['duplicate'] || ( null !== $post['values']['approval'] && self::positive_int( $post['values']['approval'] ) !== $id ) ) {
                return $this->message( 400, __( 'This approval link isn\'t valid.', 'mainwp' ) );
            }
        }

        $user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
        if ( $user_id <= 0 ) {
            return new Remote_MCP_Response( 302, self::headers() + array( 'Location' => wp_login_url( $this->services->urls()->approval_url( $id ) ) ) );
        }
        // Nonces are checked against this user and session, whatever ran before the page.
        wp_set_current_user( $user_id );

        $gate = $this->services->get( 'approval_gate' );
        if ( ! $gate instanceof MCP_Approval_Gate ) {
            return $this->message( 503, __( 'Approvals aren\'t available on this Dashboard right now.', 'mainwp' ) );
        }
        $view = $gate->page_view( $id, $user_id );
        if ( empty( $view['ok'] ) ) {
            return $this->message( 403, __( 'This approval belongs to another user, or no longer exists.', 'mainwp' ) );
        }

        $notice = null;
        if ( null !== $post ) {
            $decision = (string) $post['values']['decision'];
            $nonce    = (string) $post['values']['_wpnonce'];
            if ( ! in_array( $decision, self::DECISIONS, true ) || ! wp_verify_nonce( $nonce, self::nonce_action( $id, $decision ) ) ) {
                return $this->message( 403, __( 'This form has expired. Reload the page and try again.', 'mainwp' ) );
            }
            $notice = self::notice( $gate->page_decide( $id, $user_id, $decision ), (string) $view['app_label'] );
            $view   = $gate->page_view( $id, $user_id );
        }
        return $this->render( $view, $notice, $id );
    }

    /**
     * Render the page.
     *
     * @param array       $view   page_view() result.
     * @param string|null $notice Outcome of a decision just made.
     * @param int         $id     Row.
     * @return Remote_MCP_Response
     */
    private function render( array $view, ?string $notice, int $id ): Remote_MCP_Response {
        $page = array(
            'view'       => $view,
            'notice'     => $notice,
            'action_url' => $this->services->urls()->approval_url( $id ),
            'css_url'    => OAuth_Consent_Page::stylesheet_url(),
            'site'       => OAuth_Consent_Page::site_host(),
            'nonces'     => array(),
        );
        foreach ( self::DECISIONS as $decision ) {
            $page['nonces'][ $decision ] = wp_create_nonce( self::nonce_action( $id, $decision ) );
        }
        ob_start();
        include __DIR__ . '/templates/approval.php';
        return new Remote_MCP_Response( 200, self::headers(), (string) ob_get_clean() );
    }

    /**
     * A page with one message and no forms.
     *
     * @param int    $status  HTTP status.
     * @param string $message Message.
     * @return Remote_MCP_Response
     */
    private function message( int $status, string $message ): Remote_MCP_Response {
        $page = array(
            'view'       => null,
            'notice'     => $message,
            'action_url' => '',
            'css_url'    => OAuth_Consent_Page::stylesheet_url(),
            'site'       => OAuth_Consent_Page::site_host(),
            'nonces'     => array(),
        );
        ob_start();
        include __DIR__ . '/templates/approval.php';
        return new Remote_MCP_Response( $status, self::headers(), (string) ob_get_clean() );
    }

    /**
     * Headers for every page response: framing denied, same-origin resources and form posts
     * only, never cached.
     *
     * @param string $type Content type.
     * @return array
     */
    private static function headers( string $type = 'text/html; charset=utf-8' ): array {
        return array(
            'Content-Type'            => $type,
            'Cache-Control'           => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'                  => 'no-cache',
            'X-Frame-Options'         => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'; default-src 'self'; style-src 'self'; form-action 'self'",
            'Referrer-Policy'         => 'no-referrer',
        );
    }

    /**
     * What to tell the user after a decision.
     *
     * @param array  $outcome page_decide() result.
     * @param string $app     App label, or '' for "your AI app".
     * @return string
     */
    private static function notice( array $outcome, string $app = '' ): string {
        $app = '' === $app ? __( 'your AI app', 'mainwp' ) : $app;
        switch ( $outcome['outcome'] ) {
            case 'approved':
                /* translators: %s: app name */
                return sprintf( __( 'Approved. Go back to %s and ask it to try again.', 'mainwp' ), $app );
            case 'denied':
                return __( 'Denied. Nothing was changed.', 'mainwp' );
            case 'ran':
                return __( 'Done. The action ran from the Dashboard.', 'mainwp' );
            case 'job_started':
                return __( 'Started. The action runs in the background.', 'mainwp' );
            case 'stale':
                /* translators: %s: app name */
                return sprintf( __( 'The sites or changes are no longer what you approved, so nothing ran. Ask %s to start again.', 'mainwp' ), $app );
        }
        // A failed preview can carry the ability's own reason.
        if ( isset( $outcome['text'] ) ) {
            return (string) $outcome['text'];
        }
        $reason = $outcome['reason'] ?? null;
        if ( 'activity_log_off' === $reason ) {
            return __( 'Nothing ran. Remote AI connections need the activity log: turn on Enable Network Activity logging under MainWP Settings > Network Activity Settings.', 'mainwp' );
        }
        // The backup refusals tell the user where to do the action instead.
        if ( in_array( $reason, array( 'backup_task_groups', 'backup_task_domains', 'backup_task_update', 'backup_preview_capped' ), true ) ) {
            return __( 'Nothing ran.', 'mainwp' ) . ' ' . MCP_Approval_Gate::refusal_text( $reason );
        }
        return __( 'Nothing ran. This approval can no longer be used this way.', 'mainwp' );
    }

    /**
     * A positive integer from a parameter, or null.
     *
     * @param mixed $value Value.
     * @return int|null
     */
    private static function positive_int( $value ): ?int {
        return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $value ) ? (int) $value : null;
    }
}
