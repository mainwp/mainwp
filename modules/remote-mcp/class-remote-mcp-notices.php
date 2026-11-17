<?php
/**
 * Remote MCP new-connection notice and expiry reminder.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Emails about connections: a notice on every new grant, every minted
 * connection token and every policy change that loosens a connection, and a reminder 7 days
 * before a connection token expires. The notice is the mitigation for consent phishing through
 * hosted apps and for a token minted from a stolen wp-admin session, so it goes to the
 * connection's owner, and full administrators see the same events in the panel.
 *
 * App names can come from a registration anyone could send before login, so every name
 * is stripped of control characters. HTML bodies and panel output are escaped at rendering.
 */
final class Remote_MCP_Notices {

    /**
     * Panel notices and sent reminders: `notices` (newest first) and `reminders` (grant id =>
     * expiry the reminder was sent for).
     *
     * @var string
     */
    const OPTION_STATE = 'mainwp_remote_mcp_notice_state';

    /**
     * Panel notices kept, and for how long.
     */
    const NOTICE_LIMIT = 20;
    const NOTICE_TTL   = WEEK_IN_SECONDS;

    /**
     * User meta holding the time of the newest notice the user dismissed. The Dashboard's shared
     * dismissal option is not used: "restore info messages" clears it, and generic readers treat
     * any entry there as hidden.
     *
     * @var string
     */
    const SEEN_META = 'mainwp_remote_mcp_notices_seen';

    /**
     * A connection token's owner hears about its expiry this long before.
     *
     * @var int
     */
    const REMINDER_LEAD = WEEK_IN_SECONDS;

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
     * Listen for new grants, and send reminders from the daily purge event.
     */
    public function register_hooks(): void {
        add_action( Remote_MCP_Events::GRANT_ISSUED, array( $this, 'on_grant_issued' ), 10, 5 );
        add_action( Remote_MCP_Settings::PURGE_HOOK, array( $this, 'send_expiry_reminders' ) );
    }

    /**
     * A grant became usable (an authorization code exchange or a minted connection token).
     *
     * @param mixed $grant_id    Grant id.
     * @param mixed $user_id     Owner.
     * @param mixed $client_id   Client id.
     * @param mixed $client_kind Client kind.
     * @param mixed $source      `code_exchange` or `connection_token`.
     */
    public function on_grant_issued( $grant_id, $user_id = 0, $client_id = '', $client_kind = '', $source = '' ): void {
        unset( $client_id, $client_kind );
        $this->send_new_connection_notice( (int) $grant_id, 'connection_token' === $source ? 'token' : 'new' );
    }

    /**
     * Mail the owner about a connection and record it for the panel.
     *
     * @param int    $grant_id Grant id.
     * @param string $why      `new`, `token` or `loosened`.
     * @return bool Whether a mail was sent.
     */
    public function send_new_connection_notice( int $grant_id, string $why ): bool {
        $store = $this->services->store();
        $grant = $store ? $store->get_grant( $grant_id ) : null;
        $user  = $grant ? get_userdata( (int) $grant['user_id'] ) : false;
        if ( ! $grant || ! $user || ! in_array( $grant['status'], array( 'pending', 'live' ), true ) ) {
            return false;
        }
        $label = Remote_MCP_Admin::app_label( $this->services, $grant );
        $app   = self::plain( $label );
        $ip    = $this->client_ip();
        $now   = time();

        switch ( $why ) {
            case 'token':
                /* translators: %s: token name. */
                $subject = sprintf( __( 'New MainWP connection token: %s', 'mainwp' ), $app );
                break;
            case 'loosened':
                /* translators: %s: app name. */
                $subject = sprintf( __( 'An AI app connection now needs fewer approvals: %s', 'mainwp' ), $app );
                break;
            default:
                /* translators: %s: app name. */
                $subject = sprintf( __( 'New AI app connected to MainWP: %s', 'mainwp' ), $app );
        }
        $lines = array(
            /* translators: 1: app name, 2: user login, 3: date and time, 4: IP address. */
            sprintf( __( '%1$s was connected to your MainWP Dashboard as %2$s on %3$s from %4$s.', 'mainwp' ), $app, self::plain( $user->user_login ), wp_date( 'Y-m-d H:i T', $now ), '' === $ip ? __( 'an unknown address', 'mainwp' ) : $ip ),
            '',
            __( 'If you didn\'t do this, revoke the connection now:', 'mainwp' ),
            $this->revoke_link( $grant_id ),
        );
        if ( 'token' === $why ) {
            // $app reads `Connection token "name"`, so the token line names the token itself.
            $lines[0] = sprintf(
                /* translators: 1: token name, 2: user login, 3: date and time, 4: IP address. */
                __( 'The connection token %1$s was created on your MainWP Dashboard as %2$s on %3$s from %4$s.', 'mainwp' ),
                self::plain( (string) $grant['label'] ),
                self::plain( $user->user_login ),
                wp_date( 'Y-m-d H:i T', $now ),
                '' === $ip ? __( 'an unknown address', 'mainwp' ) : $ip
            );
        }
        if ( 'loosened' === $why ) {
            $lines[0] = sprintf(
                /* translators: 1: app name, 2: user login, 3: policy. */
                __( 'The approval policy of %1$s (connected as %2$s) was changed to %3$s, so it needs fewer approvals on the Dashboard.', 'mainwp' ),
                $app,
                self::plain( $user->user_login ),
                Remote_MCP_Admin::policy_label( (string) $grant['approval_policy'] )
            );
        }
        // The subject's only variable part is $app, already made safe above.
        $sent = (bool) wp_mail( $user->user_email, $subject, self::mail_body( implode( "\n", $lines ) ) );

        $this->record(
            array(
                'grant_id' => $grant_id,
                'user_id'  => (int) $grant['user_id'],
                'app'      => $label,
                'why'      => $why,
                'ip'       => $ip,
                'at'       => $now,
            )
        );
        return $sent;
    }

    /**
     * Mail each connection token's owner once, within 7 days of its expiry.
     *
     * @return int Reminders sent.
     */
    public function send_expiry_reminders(): int {
        $store = $this->services->store();
        if ( ! $store ) {
            return 0;
        }
        $state = $this->state();
        $now   = time();
        $sent  = 0;
        $live  = array();
        foreach ( $store->all_live_grants( 'token' ) as $grant ) {
            $grant_id = (int) $grant['id'];
            $expires  = (int) $grant['session_expires_at'];
            $live[]   = $grant_id;
            if ( $expires - $now > self::REMINDER_LEAD || ( $state['reminders'][ $grant_id ] ?? null ) === $expires ) {
                continue;
            }
            $user = get_userdata( (int) $grant['user_id'] );
            if ( ! $user ) {
                continue;
            }
            $label = self::plain( (string) $grant['label'] );
            /* translators: %s: token name. */
            $subject = sprintf( __( 'Your MainWP connection token expires soon: %s', 'mainwp' ), $label );
            $body    = implode(
                "\n",
                array(
                    /* translators: 1: token name, 2: expiry date. */
                    sprintf( __( 'The connection token %1$s expires on %2$s. Apps using it will stop working then.', 'mainwp' ), $label, wp_date( 'Y-m-d', $expires ) ),
                    '',
                    __( 'Create a new one in AI > MainWP MCP > Connect an app:', 'mainwp' ),
                    Remote_MCP_Admin::panel_url(),
                )
            );
            if ( wp_mail( $user->user_email, $subject, self::mail_body( $body ) ) ) {
                $state['reminders'][ $grant_id ] = $expires;
                ++$sent;
            }
        }
        // Forget tokens that are gone, so the map can't grow without bound.
        $state['reminders'] = array_intersect_key( $state['reminders'], array_flip( $live ) );
        update_option( self::OPTION_STATE, $state, false );
        return $sent;
    }

    /**
     * The panel link the notice carries. It only opens the panel; the revoke itself is a
     * nonce-checked POST there.
     *
     * @param int $grant_id Grant id.
     * @return string
     */
    public function revoke_link( int $grant_id ): string {
        return add_query_arg( 'revoke', $grant_id, Remote_MCP_Admin::panel_url() ) . '#connections';
    }

    /**
     * Tell full administrators in the panel that a web app was added, changed or deleted.
     *
     * @param string $event `client_added`, `client_changed`, `client_deleted` or `client_secret_reissued`.
     * @param string $name  App name, unescaped.
     */
    public function record_client_event( string $event, string $name ): void {
        $this->record(
            array(
                'grant_id' => 0,
                'user_id'  => get_current_user_id(),
                'app'      => $name,
                'why'      => $event,
                'ip'       => '',
                'at'       => time(),
            )
        );
    }

    /**
     * Recent notices for the panel, newest first.
     *
     * @param int $since Only notices after this time, the user's dismissal marker.
     * @return array
     */
    public function recent( int $since = 0 ): array {
        $cutoff = max( time() - self::NOTICE_TTL, $since );
        return array_values(
            array_filter(
                $this->state()['notices'],
                function ( $notice ) use ( $cutoff ) {
                    return (int) $notice['at'] > $cutoff;
                }
            )
        );
    }

    /**
     * Mark notices up to a time as seen by one user. The marker only moves forward, and never past
     * now, so a dismissal can't hide events that haven't happened yet.
     *
     * @param int $user_id User.
     * @param int $upto    Time of the newest notice the user's page showed.
     */
    public static function mark_seen( int $user_id, int $upto ): void {
        $upto = min( $upto, time() );
        if ( $upto > (int) get_user_meta( $user_id, self::SEEN_META, true ) ) {
            update_user_meta( $user_id, self::SEEN_META, $upto );
        }
    }

    /**
     * One user's dismissal marker.
     *
     * @param int $user_id User.
     * @return int
     */
    public static function seen_by( int $user_id ): int {
        return (int) get_user_meta( $user_id, self::SEEN_META, true );
    }

    /**
     * Keep a notice for the panel.
     *
     * @param array $notice Notice.
     */
    private function record( array $notice ): void {
        $state = $this->state();
        array_unshift( $state['notices'], $notice );
        $state['notices'] = array_slice( $state['notices'], 0, self::NOTICE_LIMIT );
        update_option( self::OPTION_STATE, $state, false );
    }

    /**
     * Stored state, with any malformed part dropped.
     *
     * @return array
     */
    private function state(): array {
        $stored  = get_option( self::OPTION_STATE );
        $notices = array();
        foreach ( is_array( $stored ) && is_array( $stored['notices'] ?? null ) ? $stored['notices'] : array() as $notice ) {
            if ( is_array( $notice ) && isset( $notice['grant_id'], $notice['app'], $notice['at'] ) ) {
                $notices[] = $notice;
            }
        }
        $reminders = array();
        foreach ( is_array( $stored ) && is_array( $stored['reminders'] ?? null ) ? $stored['reminders'] : array() as $grant_id => $expires ) {
            if ( is_int( $expires ) ) {
                $reminders[ (int) $grant_id ] = $expires;
            }
        }
        return array(
            'notices'       => $notices,
            'reminders'     => $reminders,
            'invalidations' => OAuth_Revocation::pending_invalidations(),
        );
    }

    /**
     * The address of the request that created the connection.
     *
     * @return string
     */
    private function client_ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ip = apply_filters( 'mainwp_remote_mcp_client_ip', $ip );
        return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    /**
     * Strip control characters from names used in mail subjects and bodies.
     *
     * @param string $text Text.
     * @return string
     */
    private static function plain( string $text ): string {
        return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
    }
    /**
     * Escape the body only when WordPress sends HTML mail. Subjects are always plain text.
     *
     * @param string $body Plain text body.
     * @return string
     */
    private static function mail_body( string $body ): string {
        return 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' ) ? nl2br( esc_html( $body ) ) : $body;
    }
}
