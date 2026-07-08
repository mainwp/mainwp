<?php
/**
 * MainWP Archive Format.
 *
 * The archive container formats a child site can produce. The string
 * values are the wire format sent in the ext parameter of backup
 * requests and stored in backup settings, so they must not change.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Archive_Format
 */
final class MainWP_Archive_Format {

    const ZIP     = 'zip';
    const TAR_GZ  = 'tar.gz';
    const TAR_BZ2 = 'tar.bz2';
    const TAR     = 'tar';

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
        $known = array( self::ZIP, self::TAR_GZ, self::TAR_BZ2, self::TAR );
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
