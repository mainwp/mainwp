<?php
/**
 * MainWP Backup Type.
 *
 * The kinds of backup a child site can produce. The string values are
 * the wire format exchanged with child sites and stored in task rows,
 * so they must not change.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Backup_Type
 */
final class MainWP_Backup_Type {

    const FULL        = 'full';
    const DATABASE    = 'db';
    const INCREMENTAL = 'incremental';
    const FILES       = 'files';

    /**
     * Private constructor. This class is a static namespace, not meant to be instantiated.
     */
    private function __construct() {}

    /**
     * Resolve a wire value to itself, or null when unrecognized.
     *
     * @param string $value Wire value.
     *
     * @return string|null Matching value, or null when unknown.
     */
    public static function tryFrom( string $value ): ?string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors PHP's native enum tryFrom()/from() naming so consumer call sites need no rename.
        $known = array( self::FULL, self::DATABASE, self::INCREMENTAL, self::FILES );
        return in_array( $value, $known, true ) ? $value : null;
    }

    /**
     * Resolve a wire value to itself, throwing when unrecognized.
     *
     * @param string $value Wire value.
     *
     * @return string Matching value.
     *
     * @throws \InvalidArgumentException When the value is not a known case (7.4-safe stand-in for the native enum ValueError).
     */
    public static function from( string $value ): string {
        $result = self::tryFrom( $value );
        if ( null === $result ) {
            throw new \InvalidArgumentException( esc_html( sprintf( '"%s" is not a valid backing value for %s', $value, self::class ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $result;
    }
}
