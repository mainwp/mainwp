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
 * The headline uses a registry label, the client_id host or an administrator-supplied name,
 * so a fetched document cannot claim a trusted app's identity. Registered apps are the exception:
 * their own name is the headline, always said to be the app's own.
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
        $who    = $this->identity( $client, $args['redirect_uri'] );
        $app    = '' === $who['name'] ? __( 'the app', 'mainwp' ) : $who['name'];

        $view = array(
            /* translators: %s: app name */
            'title'          => '' === $who['name'] ? __( 'Connect an AI app to MainWP', 'mainwp' ) : sprintf( __( 'Connect %s to MainWP', 'mainwp' ), $who['name'] ),
            'stylesheet'     => self::stylesheet_url(),
            'site'           => self::site_host(),
            'headline_html'  => $who['headline'],
            'secondary_html' => $who['secondary'],
            'signed_in'      => self::signed_in( $user ),
            'logout_url'     => wp_logout_url( $args['return_url'] ),
            'site_limit'     => $this->site_limit_text(),
            'action'         => (string) $this->services->urls()->authorization_endpoint(),
            'request_key'    => $args['request_key'],
            'nonce'          => wp_create_nonce( OAuth_Authorize_Endpoint::NONCE_ACTION . $args['request_key'] ),
            'write'          => $write,
            'view_text'      => $access['view'],
            'change_text'    => $access['change'],
            'policies'       => $write ? $this->policy_options() : array(),
            /* translators: %s: app name, or "the app" */
            'caution'        => sprintf( __( 'Only approve if you started this yourself, just now, in %s. If you didn\'t, choose Deny.', 'mainwp' ), $app ),
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
        if ( 'redirect_mismatch' === $code && is_array( $client ) && 'bundled' === ( $client['kind'] ?? '' ) && empty( $client['fetched'] ) && '' !== (string) ( $client['label'] ?? '' ) ) {
            // A vendor that moved its callbacks lands here until a release ships its new document.
            $detail = sprintf(
                /* translators: %s: app name */
                __( 'If you use %s, updating MainWP Dashboard may fix this.', 'mainwp' ),
                (string) $client['label']
            );
        }
        $view = array(
            'title'         => $message[0],
            'stylesheet'    => self::stylesheet_url(),
            'site'          => self::site_host(),
            'code'          => isset( $messages[ $code ] ) ? $code : 'invalid_request',
            'message'       => $message[1],
            'detail'        => $detail,
            'dashboard_url' => admin_url(),
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
            'invalid_target'            => array( __( 'This connection request isn\'t valid', 'mainwp' ), __( 'The app is trying to reach a different address than this Dashboard\'s MCP address. Check the address you pasted into the app.', 'mainwp' ) ),
            'unsupported_client'        => array( __( 'This app can\'t connect', 'mainwp' ), __( 'Your Dashboard doesn\'t know this app, or an administrator turned it off. An administrator can allow it under AI > MainWP MCP > Connect an app > Advanced > Which apps may connect: turn on the app\'s own switch, or Let other apps connect for apps that aren\'t listed. You can also connect it with a connection token from that page.', 'mainwp' ) ),
            'registration_ended'        => array( __( 'This app needs to be set up again', 'mainwp' ), __( 'The app\'s link to your Dashboard has expired. Remove MainWP from the app and add it again.', 'mainwp' ) ),
            'redirect_mismatch'         => array( __( 'This app can\'t connect right now', 'mainwp' ), __( 'The app asked to send you back to an address MainWP doesn\'t know for it, so nothing was sent.', 'mainwp' ) ),
            'insufficient_rights'       => array( __( 'You can\'t connect apps', 'mainwp' ), __( 'Only Dashboard administrators can connect AI apps.', 'mainwp' ) ),
            'https_required'            => array( __( 'Secure connection required', 'mainwp' ), __( 'This page only works over https://.', 'mainwp' ) ),
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
     * Who is asking, by client kind: the headline, one secondary line with what MainWP knows about
     * the app and where the user goes back to, and a plain name for the title and the caution.
     * A name the app gave itself is never the headline's only identity for a bundled or fetched
     * client, so a document can't claim a trusted app's name.
     *
     * @param array  $client       Descriptor.
     * @param string $redirect_uri Redirect URI of the request.
     * @return array `name` (plain, '' when MainWP has no name it can vouch for), `headline` and
     *               `secondary` (escaped HTML).
     */
    private function identity( array $client, string $redirect_uri ): array {
        $label = (string) ( $client['label'] ?? '' );
        $name  = is_string( $client['client_name'] ?? null ) ? trim( $client['client_name'] ) : '';
        $back  = $this->return_sentence( $client, $redirect_uri );
        /* translators: %s: app name */
        $wants = esc_html__( '%s wants to connect', 'mainwp' );
        switch ( $client['kind'] ?? '' ) {
            case 'bundled':
                $host  = '<strong>' . esc_html( self::host( (string) $client['client_id'] ) ) . '</strong>';
                $calls = '<bdi>' . esc_html( mb_substr( $name, 0, self::CLIENT_NAME_DISPLAY ) ) . '</bdi>';
                if ( ! empty( $client['fetched'] ) ) {
                    $where = '' === $name
                        /* translators: %s: host of the app's details */
                        ? sprintf( esc_html__( 'It\'s at %s.', 'mainwp' ), $host )
                        /* translators: 1: host of the app's details, 2: name the app gave itself */
                        : sprintf( esc_html__( 'It\'s at %1$s and calls itself "%2$s".', 'mainwp' ), $host, $calls );
                    return array(
                        'name'      => '',
                        'headline'  => esc_html__( 'An app MainWP doesn\'t know wants to connect', 'mainwp' ),
                        'secondary' => $where . ' ' . esc_html__( 'MainWP hasn\'t checked this app.', 'mainwp' ) . ' ' . $back,
                    );
                }
                $shown = '' === $label ? self::host( (string) $client['client_id'] ) : $label;
                /* translators: %s: host of the app's details */
                $from = sprintf( esc_html__( 'From %s.', 'mainwp' ), $host );
                if ( '' !== $name && $name !== $label ) {
                    /* translators: %s: name the app gave itself */
                    $from .= ' ' . sprintf( esc_html__( 'It calls itself "%s".', 'mainwp' ), $calls );
                }
                return array(
                    'name'      => $shown,
                    'headline'  => sprintf( $wants, esc_html( $shown ) ),
                    'secondary' => $from . ' ' . $back,
                );
            case 'registered':
                if ( '' === $name ) {
                    return array(
                        'name'      => '',
                        'headline'  => esc_html__( 'An app on this computer wants to connect', 'mainwp' ),
                        'secondary' => esc_html__( 'MainWP hasn\'t checked this app.', 'mainwp' ) . ' ' . $back,
                    );
                }
                return array(
                    'name'      => $name,
                    'headline'  => sprintf( $wants, '<strong><bdi>' . esc_html( $name ) . '</bdi></strong>' ),
                    'secondary' => esc_html__( 'An app on this computer. The name is the app\'s own; MainWP hasn\'t checked it.', 'mainwp' ) . ' ' . $back,
                );
            case 'webapp':
                return array(
                    'name'      => $name,
                    'headline'  => sprintf( $wants, '<strong>' . esc_html( $name ) . '</strong>' ),
                    'secondary' => $this->webapp_added( $client ) . ' ' . $back,
                );
            case 'static':
                $short = Remote_MCP_Admin::STATIC_CLIENTS[ (string) $client['client_id'] ] ?? $label;
                return array(
                    'name'      => $short,
                    'headline'  => sprintf( $wants, esc_html( $short ) ),
                    /* translators: %s: app name */
                    'secondary' => sprintf( esc_html__( 'Or another program using %s\'s settings.', 'mainwp' ), esc_html( $short ) ) . ' ' . $back,
                );
        }
        return array(
            'name'      => $label,
            'headline'  => sprintf( $wants, esc_html( $label ) ),
            'secondary' => $back,
        );
    }

    /**
     * Where the user goes after deciding. An app that can only call back to this computer is
     * named as such; any other app is named by the host of this request's redirect.
     *
     * @param array  $client       Descriptor.
     * @param string $redirect_uri Redirect URI of the request.
     * @return string Escaped HTML.
     */
    private function return_sentence( array $client, string $redirect_uri ): string {
        if ( OAuth_Request_Validator::loopback_only( $client ) ) {
            return 'registered' === ( $client['kind'] ?? '' )
                ? esc_html__( 'You\'ll go back to it after you decide.', 'mainwp' )
                : esc_html__( 'You\'ll go back to the app on this computer after you decide.', 'mainwp' );
        }
        $host = self::host( $redirect_uri );
        /* translators: %s: host the user returns to */
        return '' === $host ? '' : sprintf( esc_html__( 'You\'ll go back to %s after you decide.', 'mainwp' ), '<strong>' . esc_html( $host ) . '</strong>' );
    }

    /**
     * Who added a web app and when: the administrator vouched for its return address.
     *
     * @param array $client Descriptor.
     * @return string Escaped HTML.
     */
    private function webapp_added( array $client ): string {
        $store = $this->services->store();
        $row   = null === $store ? null : $store->get_client( (string) $client['client_id'] );
        $admin = is_array( $row ) && ! empty( $row['created_by'] ) ? get_userdata( (int) $row['created_by'] ) : false;
        if ( ! $admin || empty( $row['created_at'] ) ) {
            return esc_html__( 'Added to this Dashboard by an administrator.', 'mainwp' );
        }
        return sprintf(
            /* translators: 1: administrator's display name, 2: date added */
            esc_html__( 'Added to this Dashboard by %1$s on %2$s.', 'mainwp' ),
            esc_html( $admin->display_name ),
            esc_html( (string) wp_date( (string) get_option( 'date_format' ), (int) $row['created_at'] ) )
        );
    }

    /**
     * The signed-in line; the login is left out when it is the display name.
     *
     * @param \WP_User $user User.
     * @return string
     */
    private static function signed_in( \WP_User $user ): string {
        if ( $user->display_name === $user->user_login ) {
            /* translators: 1: display name, 2: role */
            return sprintf( __( 'Signed in as %1$s, %2$s', 'mainwp' ), $user->display_name, self::role_name( $user ) );
        }
        /* translators: 1: display name, 2: user login, 3: role */
        return sprintf( __( 'Signed in as %1$s (%2$s), %3$s', 'mainwp' ), $user->display_name, $user->user_login, self::role_name( $user ) );
    }

    /**
     * The stylesheet the consent, error and approval pages share.
     *
     * @return string
     */
    public static function stylesheet_url(): string {
        return plugins_url( 'assets/remote-mcp-pages.css', __FILE__ );
    }

    /**
     * This Dashboard's host, for the line that says whose Dashboard a page belongs to.
     *
     * @return string
     */
    public static function site_host(): string {
        return self::host( home_url() );
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
            ? __( 'See your Dashboard\'s sites and their data. Change nothing.', 'mainwp' )
            /* translators: %s: list of categories */
            : sprintf( __( 'See your %s data. Change nothing.', 'mainwp' ), $view_list );
        // Each category is named once: writable ones under "see and change", the rest under "see".
        $change_list = self::category_list( array_keys( $change ) );
        $read_list   = self::category_list( array_keys( array_diff_key( $view, $change ) ) );
        if ( '' === $change_list ) {
            $change_text = __( 'See and change your Dashboard\'s sites and their data. When it has to ask you first is set below.', 'mainwp' );
        } elseif ( '' === $read_list ) {
            /* translators: %s: list of categories */
            $change_text = sprintf( __( 'See and change your %s data. When it has to ask you first is set below.', 'mainwp' ), $change_list );
        } else {
            /* translators: 1: categories the app can change, 2: categories it can only see */
            $change_text = sprintf( __( 'See and change your %1$s data, and see your %2$s data. When it has to ask you first is set below.', 'mainwp' ), $change_list, $read_list );
        }
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
    public static function category_label( string $slug ): string {
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
            OAuth_Store::POLICY_STANDARD => array( __( 'Standard', 'mainwp' ), __( 'Ordinary changes run right away. Some are confirmed with you in the app, and the riskiest wait for your approval on this Dashboard.', 'mainwp' ) ),
            OAuth_Store::POLICY_STRICT   => array( __( 'Strict', 'mainwp' ), __( 'Every change waits for your approval on this Dashboard.', 'mainwp' ) ),
        );
        if ( Remote_MCP_Team_Control::user_has_no_limits( get_current_user_id() ) ) {
            // The exceptions are MCP_Tool_Catalog::HANDS_OFF_FLOOR, which keeps them at Dashboard approval.
            $options[ OAuth_Store::POLICY_HANDS_OFF ] = array( __( 'Hands-off', 'mainwp' ), __( 'Changes that would wait for your approval on this Dashboard are confirmed with you in the chat instead. Adding sites, restoring or deleting backups or backup tasks, changing backup retention, changing a site\'s URL or SSL settings, and replacing the custom dashboard snippet still wait for your approval here. MainWP can\'t tell whether a person approved in the chat, and instructions hidden in a site\'s content could get the app to make changes.', 'mainwp' ) );
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
