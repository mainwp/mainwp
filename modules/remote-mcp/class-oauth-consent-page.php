<?php
/**
 * Remote MCP consent and error pages.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

use MainWP\Dashboard\MainWP_Abilities_Util;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the consent screen and the authorization error page as standalone templates with the
 * module's own stylesheet: no theme and no wp_head() on a page that issues codes.
 *
 * The app's identity always comes from our registry label or from what a full administrator
 * typed, never from a client document, so a vendor document change can't rename the app here.
 */
final class OAuth_Consent_Page {

    /**
     * Longest client document name shown, in characters.
     *
     * @var int
     */
    const CLIENT_NAME_DISPLAY = 80;

    /**
     * Catalog categories that are part of the connector itself rather than Dashboard data.
     *
     * @var array
     */
    const OWN_CATEGORIES = array( 'mainwp-remote-mcp' );

    /**
     * Module services.
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
     * The consent screen for a stored request.
     *
     * @param array $args `request_key`, `client` (descriptor), `redirect_uri`, `scope`, `return_url`.
     * @return Remote_MCP_Response
     */
    public function consent( array $args ): Remote_MCP_Response {
        $client = $args['client'];
        $user   = wp_get_current_user();
        $write  = in_array( OAuth_Store::SCOPE_WRITE, OAuth_Store::scope_tokens( $args['scope'] ), true );
        $access = $this->access_text();

        $view = array(
            'title'         => __( 'Connect an AI app', 'mainwp' ),
            'stylesheet'    => plugins_url( 'assets/consent.css', __FILE__ ),
            'headline_html' => $this->headline_html( $client, $args['redirect_uri'] ),
            'client_name'   => $this->document_name( $client ),
            'redirect_host' => self::host( $args['redirect_uri'] ),
            'loopback_only' => OAuth_Request_Validator::loopback_only( $client ),
            'signed_in'     => sprintf(
                /* translators: 1: display name, 2: user login, 3: role */
                __( 'Signed in as %1$s (%2$s), %3$s', 'mainwp' ),
                $user->display_name,
                $user->user_login,
                self::role_name( $user )
            ),
            'logout_url'    => wp_logout_url( $args['return_url'] ),
            'site_limit'    => $this->site_limit_text(),
            'action'        => (string) $this->services->urls()->authorization_endpoint(),
            'request_key'   => $args['request_key'],
            'nonce'         => wp_create_nonce( OAuth_Authorize_Endpoint::NONCE_ACTION . $args['request_key'] ),
            'write'         => $write,
            'view_text'     => $access['view'],
            'change_text'   => $access['change'],
            'policies'      => $write ? $this->policy_options() : array(),
        );
        return $this->respond( 200, 'consent', $view );
    }

    /**
     * The error page. It never redirects: until the client and its redirect are verified, and
     * whenever they stop being valid, nothing may go to the client.
     *
     * @param string     $code   Error code.
     * @param array|null $client Descriptor, when the client is known.
     * @return Remote_MCP_Response
     */
    public function error( string $code, ?array $client = null ): Remote_MCP_Response {
        $messages = self::error_messages();
        $message  = $messages[ $code ] ?? $messages['invalid_request'];
        $detail   = '';
        if ( 'redirect_mismatch' === $code && is_array( $client ) && 'bundled' === ( $client['kind'] ?? '' ) && '' !== (string) ( $client['label'] ?? '' ) ) {
            // A vendor that moved its callbacks lands here until a release ships its new document.
            $detail = sprintf(
                /* translators: %s: app name */
                __( 'If you use %s, updating MainWP Dashboard may fix this.', 'mainwp' ),
                (string) $client['label']
            );
        }
        $view = array(
            'title'      => $message[0],
            'stylesheet' => plugins_url( 'assets/consent.css', __FILE__ ),
            'code'       => isset( $messages[ $code ] ) ? $code : 'invalid_request',
            'message'    => $message[1],
            'detail'     => $detail,
        );
        return $this->respond( 400, 'error', $view );
    }

    /**
     * Error copy by code: [ title, message ].
     *
     * @return array
     */
    private static function error_messages(): array {
        $again = __( 'Start the connection again from your AI app.', 'mainwp' );
        return array(
            'invalid_request'           => array( __( 'This connection request isn\'t valid', 'mainwp' ), __( 'The app sent a request MainWP can\'t accept. Start the connection again from your AI app. If it keeps happening, contact the app\'s developer.', 'mainwp' ) ),
            'duplicate_parameter'       => array( __( 'This connection request isn\'t valid', 'mainwp' ), __( 'The app sent the same setting twice. Start the connection again from your AI app.', 'mainwp' ) ),
            'invalid_redirect_uri'      => array( __( 'This connection request isn\'t valid', 'mainwp' ), __( 'The app didn\'t say where to return you after you decide. Start the connection again from your AI app.', 'mainwp' ) ),
            'unsupported_response_type' => array( __( 'This connection request isn\'t valid', 'mainwp' ), __( 'The app asked for a kind of sign-in MainWP doesn\'t offer.', 'mainwp' ) ),
            'invalid_code_challenge'    => array( __( 'This connection request isn\'t valid', 'mainwp' ), __( 'The app didn\'t send the security check MainWP needs. Start the connection again from your AI app.', 'mainwp' ) ),
            'invalid_state'             => array( __( 'This connection request isn\'t valid', 'mainwp' ), __( 'The app sent more data than MainWP accepts. Start the connection again from your AI app.', 'mainwp' ) ),
            'invalid_target'            => array( __( 'This connection request isn\'t valid', 'mainwp' ), __( 'The app asked to connect to a different address than this Dashboard\'s MCP address. Check the address you added to the app.', 'mainwp' ) ),
            'unsupported_client'        => array( __( 'MainWP doesn\'t support this app yet', 'mainwp' ), __( 'This app isn\'t one the Dashboard knows, or its switch under AI > MainWP MCP > Connect directly is off. Ask an administrator to check, or use a connection token.', 'mainwp' ) ),
            'registration_ended'        => array( __( 'This app needs to be added again', 'mainwp' ), __( 'This app\'s registration with your Dashboard has ended. Remove MainWP from the app and add it again.', 'mainwp' ) ),
            'redirect_mismatch'         => array( __( 'This app can\'t connect right now', 'mainwp' ), __( 'The app asked to send you back to an address MainWP doesn\'t know for it, so nothing was sent.', 'mainwp' ) ),
            'insufficient_rights'       => array( __( 'You can\'t connect apps', 'mainwp' ), __( 'Only Dashboard administrators can connect AI apps.', 'mainwp' ) ),
            'https_required'            => array( __( 'HTTPS required', 'mainwp' ), __( 'This page only works over HTTPS.', 'mainwp' ) ),
            'login_required'            => array( __( 'Sign in first', 'mainwp' ), __( 'You\'re not signed in to your Dashboard. Sign in, then start the connection again from your AI app.', 'mainwp' ) ),
            'request_unavailable'       => array( __( 'This request has ended', 'mainwp' ), __( 'This request has expired or was already answered.', 'mainwp' ) . ' ' . $again ),
            'invalid_nonce'             => array( __( 'This page has expired', 'mainwp' ), __( 'This page has expired.', 'mainwp' ) . ' ' . $again ),
            'invalid_choice'            => array( __( 'That choice isn\'t available', 'mainwp' ), __( 'That choice isn\'t available for this connection.', 'mainwp' ) . ' ' . $again ),
            'already_answered'          => array( __( 'This request was already answered', 'mainwp' ), __( 'This request was already answered.', 'mainwp' ) ),
            'client_unavailable'        => array( __( 'This app can\'t connect right now', 'mainwp' ), __( 'This app is no longer set up to connect to your Dashboard, so nothing was sent to it.', 'mainwp' ) ),
            'code_failed'               => array( __( 'The connection didn\'t finish', 'mainwp' ), __( 'MainWP couldn\'t finish the connection.', 'mainwp' ) . ' ' . $again ),
            'server_error'              => array( __( 'The connection didn\'t start', 'mainwp' ), __( 'MainWP couldn\'t save this request.', 'mainwp' ) . ' ' . $again ),
        );
    }

    /**
     * The headline: who is asking, by client kind.
     *
     * @param array  $client       Descriptor.
     * @param string $redirect_uri Redirect URI of the request.
     * @return string Escaped HTML.
     */
    private function headline_html( array $client, string $redirect_uri ): string {
        $label = (string) ( $client['label'] ?? '' );
        $name  = is_string( $client['client_name'] ?? null ) ? trim( $client['client_name'] ) : '';
        switch ( $client['kind'] ?? '' ) {
            case 'bundled':
                $host = self::host( (string) $client['client_id'] );
                return '' === $label ? esc_html( $host ) : esc_html( $label ) . ' (' . esc_html( $host ) . ')';
            case 'registered':
                if ( '' === $name ) {
                    return esc_html__( 'An app on this computer', 'mainwp' );
                }
                return sprintf(
                    /* translators: %s: name the app gave itself */
                    esc_html__( '%s (name not checked by MainWP), an app on this computer', 'mainwp' ),
                    '<strong><bdi>' . esc_html( $name ) . '</bdi></strong>'
                );
            case 'webapp':
                return $this->webapp_headline( $client, $name, $redirect_uri );
        }
        return esc_html( $label );
    }

    /**
     * Web app headline: the name and the administrator who vouched for its address.
     *
     * @param array  $client       Descriptor.
     * @param string $name         App name a full administrator typed.
     * @param string $redirect_uri Redirect URI of the request.
     * @return string Escaped HTML.
     */
    private function webapp_headline( array $client, string $name, string $redirect_uri ): string {
        $store = $this->services->store();
        $row   = null === $store ? null : $store->get_client( (string) $client['client_id'] );
        $admin = is_array( $row ) && ! empty( $row['created_by'] ) ? get_userdata( (int) $row['created_by'] ) : false;
        $host  = '<strong>' . esc_html( self::host( $redirect_uri ) ) . '</strong>';
        if ( ! $admin || empty( $row['created_at'] ) ) {
            return sprintf(
                /* translators: 1: app name, 2: host the user returns to */
                esc_html__( '%1$s, added by a Dashboard administrator. You\'ll return to %2$s.', 'mainwp' ),
                '<strong>' . esc_html( $name ) . '</strong>',
                $host
            );
        }
        return sprintf(
            /* translators: 1: app name, 2: administrator's display name, 3: date added, 4: host the user returns to */
            esc_html__( '%1$s, added by %2$s on %3$s. You\'ll return to %4$s.', 'mainwp' ),
            '<strong>' . esc_html( $name ) . '</strong>',
            esc_html( $admin->display_name ),
            esc_html( (string) wp_date( (string) get_option( 'date_format' ), (int) $row['created_at'] ) ),
            $host
        );
    }

    /**
     * A bundled client's own name, shown only as secondary text. Static clients have none, and a
     * registered or web app's name is already in the headline.
     *
     * @param array $client Descriptor.
     * @return string
     */
    private function document_name( array $client ): string {
        if ( 'bundled' !== ( $client['kind'] ?? '' ) || ! is_string( $client['client_name'] ?? null ) ) {
            return '';
        }
        return mb_substr( trim( $client['client_name'] ), 0, self::CLIENT_NAME_DISPLAY );
    }

    /**
     * What each access choice lets the app do, from the catalog's categories.
     *
     * @return array `view` and `change`.
     */
    private function access_text(): array {
        $view   = array();
        $change = array();
        foreach ( (array) $this->services->get( 'tool_executor' )->catalog() as $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['category'] ?? null ) || in_array( $row['category'], self::OWN_CATEGORIES, true ) ) {
                continue;
            }
            $tier  = $row['standard_tier'] ?? null;
            $state = $row['availability'] ?? null;
            if ( 'available' === $state && 'read' === $tier ) {
                $view[ $row['category'] ] = true;
            } elseif ( in_array( $state, array( 'available', 'not_reviewed' ), true ) && is_string( $tier ) && 'read' !== $tier ) {
                $change[ $row['category'] ] = true;
            }
        }
        $view_list = self::category_list( array_keys( $view ) );
        $view_text = '' === $view_list
            ? __( 'See your Dashboard\'s sites and their data', 'mainwp' )
            /* translators: %s: list of categories */
            : sprintf( __( 'See %s', 'mainwp' ), $view_list );
        $change_list = self::category_list( array_keys( $change ) );
        $change_text = '' === $change_list
            /* translators: %s: what the app can see */
            ? sprintf( __( '%s, and make the changes your approval policy allows; risky changes still need your approval', 'mainwp' ), $view_text )
            /* translators: 1: what the app can see, 2: list of categories it can change */
            : sprintf( __( '%1$s, and make the changes your approval policy allows to %2$s; risky changes still need your approval', 'mainwp' ), $view_text, $change_list );
        return array(
            'view'   => $view_text,
            'change' => $change_text,
        );
    }

    /**
     * Human list of category labels.
     *
     * @param array $slugs Category slugs.
     * @return string
     */
    private static function category_list( array $slugs ): string {
        if ( empty( $slugs ) ) {
            return '';
        }
        return wp_sprintf_l( '%l', array_map( array( self::class, 'category_label' ), self::category_order( $slugs ) ) );
    }

    /**
     * Core categories follow their registration order; extension labels sort alphabetically.
     *
     * @param string[] $slugs Category slugs.
     * @return string[]
     */
    public static function category_order( array $slugs ): array {
        $core = array_flip( array( 'mainwp-sites', 'mainwp-updates', 'mainwp-clients', 'mainwp-tags', 'mainwp-knowledge', 'mainwp-backups', 'mainwp-batch' ) );
        usort(
            $slugs,
            static function ( string $a, string $b ) use ( $core ): int {
                if ( isset( $core[ $a ] ) || isset( $core[ $b ] ) ) {
                    return ( $core[ $a ] ?? PHP_INT_MAX ) <=> ( $core[ $b ] ?? PHP_INT_MAX );
                }
                $order = strnatcasecmp( self::category_label( $a ), self::category_label( $b ) );
                return 0 === $order ? strcmp( $a, $b ) : $order;
            }
        );
        return $slugs;
    }

    /**
     * A category's registered label, or a readable form of its slug.
     *
     * @param string $slug Category slug.
     * @return string
     */
    private static function category_label( string $slug ): string {
        if ( function_exists( 'wp_has_ability_category' ) && did_action( 'init' ) && wp_has_ability_category( $slug ) ) {
            $category = wp_get_ability_category( $slug );
            if ( $category ) {
                return preg_replace( '/^MainWP /', '', $category->get_label() );
            }
        }
        return str_replace( '-', ' ', (string) preg_replace( '/^mainwp-/', '', $slug ) );
    }

    /**
     * The approval policies the signed-in user may pick. Hands-off is offered only to a
     * user with no Team Control limits; the POST checks it again.
     *
     * @return array Value => [ label, description ].
     */
    private function policy_options(): array {
        $options = array(
            OAuth_Store::POLICY_STANDARD => array( __( 'Standard', 'mainwp' ), __( 'Risky changes need your confirmation in the app or your approval on this Dashboard.', 'mainwp' ) ),
            OAuth_Store::POLICY_STRICT   => array( __( 'Strict', 'mainwp' ), __( 'Every change needs your approval on this Dashboard.', 'mainwp' ) ),
        );
        if ( Remote_MCP_Team_Control::user_has_no_limits( get_current_user_id() ) ) {
            $options[ OAuth_Store::POLICY_HANDS_OFF ] = array( __( 'Hands-off', 'mainwp' ), __( 'Changes that would need your approval on this Dashboard only need a confirmation in the app. MainWP can no longer check that a person approved them, and instructions hidden in site content could lead the app to make them.', 'mainwp' ) );
        }
        return $options;
    }

    /**
     * The Team Control line: the token acts as this user, so the user's limits cap every call.
     *
     * @return string Empty without limits.
     */
    private function site_limit_text(): string {
        if ( ! class_exists( MainWP_Abilities_Util::class ) ) {
            return '';
        }
        $allowed = MainWP_Abilities_Util::get_allowed_site_ids();
        if ( ! is_array( $allowed ) ) {
            return '';
        }
        $count = count( $allowed );
        return sprintf(
            /* translators: %d: number of sites */
            _n( 'Your Team Control role lets you see %d site; the app will see the same one.', 'Your Team Control role lets you see %d sites; the app will see the same ones.', $count, 'mainwp' ),
            $count
        );
    }

    /**
     * Name of the user's first role.
     *
     * @param \WP_User $user User.
     * @return string
     */
    private static function role_name( \WP_User $user ): string {
        $role  = $user->roles ? reset( $user->roles ) : '';
        $names = wp_roles()->role_names;
        return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : (string) $role;
    }

    /**
     * Host of a URI, for display only.
     *
     * @param string $uri URI.
     * @return string
     */
    private static function host( string $uri ): string {
        $host = wp_parse_url( $uri, PHP_URL_HOST );
        return is_string( $host ) ? strtolower( $host ) : '';
    }

    /**
     * Render a template into a framed-denied response. The authorization endpoint, the only
     * caller, has already turned off page caching for the request.
     *
     * @param int    $status   HTTP status.
     * @param string $template Template name.
     * @param array  $view     Template data.
     * @return Remote_MCP_Response
     */
    private function respond( int $status, string $template, array $view ): Remote_MCP_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the included template reads $view.
        ob_start();
        include __DIR__ . '/templates/' . $template . '.php';
        return Remote_MCP_Response::html( $status, (string) ob_get_clean() );
    }
}
