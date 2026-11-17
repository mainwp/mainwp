<?php
/**
 * Remote MCP platform requirements.
 *
 * PHP 7.4 syntax only: the bootstrap loads this file below the module's PHP floor too.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pure checks of the platform the connector needs. The decision takes versions as
 * arguments so a test can pass '7.4.33' or '6.8' without changing the runtime.
 */
final class Remote_MCP_Requirements {

    /**
     * PHP floor when the bootstrap's constant isn't defined.
     *
     * @var string
     */
    const MIN_PHP = '8.1';

    /**
     * WordPress floor: the Abilities API (wp_get_abilities, WP_Ability) arrived in 6.9.
     *
     * @var string
     */
    const MIN_WP = '6.9';

    /**
     * Decide whether the connector can run.
     *
     * @param string $php_version PHP version.
     * @param string $wp_version  WordPress version.
     * @param bool   $multisite   Whether the site is a multisite.
     * @return array `ok`, `reason` (null, `multisite`, `php` or `wordpress`), `required` and `running`.
     */
    public static function evaluate( $php_version, $wp_version, $multisite ) {
        if ( $multisite ) {
            return self::result( 'multisite', null, null );
        }
        if ( version_compare( (string) $php_version, self::min_php(), '<' ) ) {
            return self::result( 'php', self::min_php(), (string) $php_version );
        }
        // A pre-release such as 6.9-RC1 already ships the Abilities API, so compare the release part.
        $release = preg_replace( '/-.*$/', '', (string) $wp_version );
        if ( version_compare( (string) $release, self::MIN_WP, '<' ) ) {
            return self::result( 'wordpress', self::MIN_WP, (string) $wp_version );
        }
        return self::result( null, null, null );
    }

    /**
     * The decision for this server. The Abilities API must also really be there: a plugin can't
     * fake a version number, but a stripped-down core build could lack it.
     *
     * @return array As evaluate().
     */
    public static function current() {
        $result = self::evaluate( PHP_VERSION, (string) get_bloginfo( 'version' ), is_multisite() );
        if ( $result['ok'] && ( ! function_exists( 'wp_get_abilities' ) || ! class_exists( 'WP_Ability' ) ) ) {
            return self::result( 'wordpress', self::MIN_WP, (string) get_bloginfo( 'version' ) );
        }
        return $result;
    }

    /**
     * The PHP floor.
     *
     * @return string
     */
    public static function min_php() {
        return defined( 'MAINWP_REMOTE_MCP_MIN_PHP' ) ? (string) MAINWP_REMOTE_MCP_MIN_PHP : self::MIN_PHP;
    }

    /**
     * The sentence the panel shows for a failed requirement, unescaped.
     *
     * @param array $result Result of evaluate().
     * @return string Empty when every requirement passes.
     */
    public static function message( array $result ) {
        switch ( isset( $result['reason'] ) ? $result['reason'] : null ) {
            case 'multisite':
                return __( "Remote AI connections aren't available on multisite networks.", 'mainwp' );
            case 'php':
                /* translators: 1: required PHP version, 2: running PHP version. */
                return sprintf( __( 'Remote AI connections need PHP %1$s or newer; this server runs %2$s.', 'mainwp' ), $result['required'], $result['running'] );
            case 'wordpress':
                /* translators: %s: required WordPress version. */
                return sprintf( __( 'Remote AI connections need WordPress %s or newer.', 'mainwp' ), $result['required'] );
        }
        return '';
    }

    /**
     * Result shape.
     *
     * @param string|null $reason   Failing requirement.
     * @param string|null $required Required version.
     * @param string|null $running  Running version.
     * @return array
     */
    private static function result( $reason, $required, $running ) {
        return array(
            'ok'       => null === $reason,
            'reason'   => $reason,
            'required' => $required,
            'running'  => $running,
        );
    }
}
