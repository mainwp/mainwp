<?php
/**
 * Remote MCP host detection.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which host or security layer probably sits in front of WordPress, from local signals only.
 * Nothing here makes a request: the guess only picks the help page a failure line links to.
 */
final class Remote_MCP_Host_Detection {

    /**
     * Troubleshooting pages on docs.mainwp.com, by host key. `generic` is the fallback.
     *
     * @var array
     */
    const HELP = array(
        'wpengine'       => 'https://docs.mainwp.com/mcp-server/remote/troubleshooting/wp-engine',
        'kinsta'         => 'https://docs.mainwp.com/mcp-server/remote/troubleshooting/kinsta',
        'cloudflare'     => 'https://docs.mainwp.com/mcp-server/remote/troubleshooting/cloudflare',
        'wordfence'      => 'https://docs.mainwp.com/mcp-server/remote/troubleshooting/wordfence',
        'sucuri'         => 'https://docs.mainwp.com/mcp-server/remote/troubleshooting/sucuri',
        'solid_security' => 'https://docs.mainwp.com/mcp-server/remote/troubleshooting/solid-security',
        'generic'        => 'https://docs.mainwp.com/mcp-server/remote/troubleshooting',
    );

    /**
     * Display names.
     *
     * @var array
     */
    const NAMES = array(
        'wpengine'       => 'WP Engine',
        'kinsta'         => 'Kinsta',
        'cloudflare'     => 'Cloudflare',
        'wordfence'      => 'Wordfence',
        'sucuri'         => 'Sucuri',
        'solid_security' => 'Solid Security',
    );

    /**
     * Plugin files that mark a security plugin as active.
     *
     * @var array
     */
    const PLUGINS = array(
        'wordfence/wordfence.php'                       => 'wordfence',
        'sucuri-scanner/sucuri.php'                     => 'sucuri',
        'better-wp-security/better-wp-security.php'     => 'solid_security',
        'ithemes-security-pro/ithemes-security-pro.php' => 'solid_security',
    );

    /**
     * Hosts detected from the given signals, most specific first.
     *
     * @param array $constants Names of defined constants that matter here.
     * @param array $server    Request server variables.
     * @param array $plugins   Active plugin files.
     * @return string[] Host keys.
     */
    public static function detect( array $constants, array $server, array $plugins ): array {
        $found = array();
        if ( in_array( 'IS_WPE', $constants, true ) || in_array( 'WPE_APIKEY', $constants, true ) ) {
            $found[] = 'wpengine';
        }
        if ( in_array( 'KINSTA_CACHE_ZONE', $constants, true ) || isset( $server['KINSTA_CACHE_ZONE'] ) ) {
            $found[] = 'kinsta';
        }
        if ( ! empty( $server['HTTP_CF_RAY'] ) ) {
            $found[] = 'cloudflare';
        }
        foreach ( self::PLUGINS as $file => $key ) {
            if ( in_array( $file, $plugins, true ) && ! in_array( $key, $found, true ) ) {
                $found[] = $key;
            }
        }
        return $found;
    }

    /**
     * Hosts detected for this request.
     *
     * @return string[]
     */
    public static function current(): array {
        $constants = array_values( array_filter( array( 'IS_WPE', 'WPE_APIKEY', 'KINSTA_CACHE_ZONE' ), 'defined' ) );
        $server    = array();
        foreach ( array( 'HTTP_CF_RAY', 'KINSTA_CACHE_ZONE' ) as $key ) {
            if ( isset( $_SERVER[ $key ] ) ) {
                $server[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            }
        }
        return self::detect( $constants, $server, (array) get_option( 'active_plugins', array() ) );
    }

    /**
     * Help page for the first detected host, or the generic page.
     *
     * @param string[]|null $hosts Hosts, or null to detect them now.
     * @return string
     */
    public static function help_url( ?array $hosts = null ): string {
        $hosts = null === $hosts ? self::current() : $hosts;
        return self::HELP[ $hosts[0] ?? 'generic' ] ?? self::HELP['generic'];
    }
}
