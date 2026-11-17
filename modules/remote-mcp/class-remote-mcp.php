<?php
/**
 * Remote MCP module wiring.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hooks, enable and disable. No rewrite rules are registered at all: the front controller matches
 * paths itself, so there is nothing to flush either way.
 *
 * Slot implementations hook in through the Hook_Provider slots of Remote_MCP_Services and are
 * resolved through its other slots, so this class never names them.
 */
final class Remote_MCP {

    /**
     * The booted instance.
     *
     * @var Remote_MCP|null
     */
    private static $instance = null;

    /**
     * Services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Settings class; a test subclass replaces the kill switch constant name.
     *
     * @var string
     */
    private $settings;

    /**
     * Front controller, built on first use.
     *
     * @var MCP_Front_Controller|null
     */
    private $front_controller = null;

    /**
     * Boot once, from the module bootstrap.
     */
    public static function boot(): void {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self( new Remote_MCP_Services() );
        self::$instance->register_hooks();
    }

    /**
     * The booted instance, for the panel and the self-test.
     *
     * @return Remote_MCP|null
     */
    public static function instance(): ?self {
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Services.
     * @param string              $settings Remote_MCP_Settings or a subclass.
     */
    public function __construct( Remote_MCP_Services $services, string $settings = Remote_MCP_Settings::class ) {
        $this->services = $services;
        $this->settings = is_a( $settings, Remote_MCP_Settings::class, true ) ? $settings : Remote_MCP_Settings::class;
    }

    /**
     * Services.
     *
     * @return Remote_MCP_Services
     */
    public function services(): Remote_MCP_Services {
        return $this->services;
    }

    /**
     * Front controller.
     *
     * @return MCP_Front_Controller
     */
    public function front_controller(): MCP_Front_Controller {
        if ( null === $this->front_controller ) {
            $this->front_controller = new MCP_Front_Controller( $this->services, array( $this->settings, 'is_active' ) );
        }
        return $this->front_controller;
    }

    /**
     * Register the module's hooks.
     *
     * The front controller is hooked only while the stored toggle is on; while it is off,
     * only its not-found guard is, so module paths get a final 404 and no handler. The kill switch
     * is not read here but per request inside the callback, because a filter registered by a later
     * plugin or an mu-plugin must still take effect.
     */
    public function register_hooks(): void {
        // PHP_INT_MAX so no later filter can flip it back.
        add_filter( 'mainwp_rest_is_request_to_rest_api', array( $this, 'exclude_module_routes' ), PHP_INT_MAX );
        add_action( 'add_option_rewrite_rules', array( $this, 'refresh_competing_rules' ) );
        add_action( 'update_option_rewrite_rules', array( $this, 'refresh_competing_rules' ) );
        add_action( Remote_MCP_Settings::PURGE_HOOK, array( $this, 'run_purge' ) );
        add_action( 'init', array( $this, 'sync_purge_schedule' ) );

        if ( Remote_MCP_Install::is_installed() ) {
            register_deactivation_hook( MAINWP_PLUGIN_FILE, array( $this, 'disable' ) );
        }

        $settings = $this->settings;
        if ( $settings::enabled() ) {
            add_action( 'parse_request', array( $this->front_controller(), 'on_parse_request' ), 1 );
        } else {
            add_action( 'parse_request', array( $this->front_controller(), 'on_parse_request_disabled' ), 1 );
        }
        foreach ( Remote_MCP_Services::HOOK_SLOTS as $slot ) {
            $this->services->get( $slot )->register_hooks( $this->services );
        }
    }

    /**
     * Keep MainWP's API-key authentication off the module's routes. Otherwise an API key
     * in the query string next to a bearer header would authenticate the key's user and define
     * MAINWP_REST_API_DOING, which turns off Team Control site scoping.
     *
     * @param mixed $is_mainwp_api Whether the request is to the MainWP REST API.
     * @return mixed
     */
    public function exclude_module_routes( $is_mainwp_api ) {
        $route = isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ? ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' ) : '';
        if ( is_string( $route ) && 0 === strpos( '/' . strtolower( trim( $route, '/' ) ) . '/', Remote_MCP_Urls::NAMESPACE_ROUTE . '/' ) ) {
            return false;
        }
        return $is_mainwp_api;
    }

    /**
     * Competing well-known rules in a rewrite table: a rule whose regex contains the
     * literal path, with or without escaping. Core never generates one; an OAuth or OpenID plugin
     * serving these paths through rewrites does.
     *
     * @param array $rules Rewrite rules, regex => query.
     * @return array Bool `openid` and `authorization_server`.
     */
    public static function competing_rules( array $rules ): array {
        $found = array(
            'openid'               => false,
            'authorization_server' => false,
        );
        foreach ( array_keys( $rules ) as $regex ) {
            $plain = str_replace( '\\', '', (string) $regex );
            if ( false !== strpos( $plain, 'well-known/openid-configuration' ) ) {
                $found['openid'] = true;
            }
            if ( false !== strpos( $plain, 'well-known/oauth-authorization-server' ) ) {
                $found['authorization_server'] = true;
            }
        }
        return $found;
    }

    /**
     * Recompute and cache the competing-rule check. Runs on enable and whenever the rewrite table
     * is saved, never per request.
     *
     * @return array Bool `openid` and `authorization_server`.
     */
    public function refresh_competing_rules(): array {
        $rules = get_option( 'rewrite_rules' );
        $found = self::competing_rules( is_array( $rules ) ? $rules : array() );
        update_option( Remote_MCP_Settings::OPTION_COMPETING_RULES, $found, false );
        return $found;
    }

    /**
     * Turn the connector on. Refused with every failing condition named: the kill
     * switch, an insecure home, a competing authorization-server rule, and the self-test checks.
     *
     * @return string[] Reasons enabling was refused; empty when it is now on.
     */
    public function enable(): array {
        $settings = $this->settings;
        $blockers = array();
        if ( null !== $settings::forced_off() ) {
            $blockers[] = 'forced_off';
        }
        if ( ! $this->services->urls()->is_secure() ) {
            $blockers[] = 'insecure_home';
        }
        if ( $this->refresh_competing_rules()['authorization_server'] ) {
            $blockers[] = 'competing_authorization_server';
        }
        foreach ( (array) $this->services->get( 'enable_check' )->blocking_reasons() as $reason ) {
            $blockers[] = (string) $reason;
        }
        if ( ! empty( $blockers ) ) {
            return array_values( array_unique( $blockers ) );
        }
        if ( ! Remote_MCP_Install::install() ) {
            return array( 'install_failed' );
        }
        update_option( Remote_MCP_Settings::OPTION_BOUND_HOME, (string) $this->services->urls()->base(), false );
        update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
        $this->sync_purge_schedule();
        return array();
    }

    /**
     * Turn the connector off: every grant is revoked and the secret rotated, so turning it back on
     * never brings old tokens back.
     *
     * @return bool Whether every connection is confirmed ended.
     */
    public function disable(): bool {
        update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
        wp_clear_scheduled_hook( Remote_MCP_Settings::PURGE_HOOK );
        $hasher = new OAuth_Token_Hasher();
        $store  = new OAuth_Store( $hasher, (string) $this->services->urls()->mcp_url() );
        try {
            $hasher->rotate_secret();
            $rotated = true;
        } catch ( \RuntimeException $e ) {
            $rotated = false;
        }
        // Revoked whether or not the rotation landed: ending the grants never depends on it.
        return null !== $store->bump_site_generation( 'disabled' ) && $rotated;
    }

    /**
     * Daily purge, only while the connector is active.
     */
    public function run_purge(): void {
        $settings = $this->settings;
        $store    = $this->services->store();
        if ( $settings::is_active() && null !== $store ) {
            $store->purge();
        }
    }

    /**
     * Schedule the purge while active and unschedule it otherwise, kill switch included.
     */
    public function sync_purge_schedule(): void {
        $settings  = $this->settings;
        $scheduled = wp_next_scheduled( Remote_MCP_Settings::PURGE_HOOK );
        if ( $settings::is_active() ) {
            if ( false === $scheduled ) {
                wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Remote_MCP_Settings::PURGE_HOOK );
            }
        } elseif ( false !== $scheduled ) {
            wp_clear_scheduled_hook( Remote_MCP_Settings::PURGE_HOOK );
        }
    }
}
