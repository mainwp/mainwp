<?php
/**
 * Remote MCP raw parameter parser.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses a raw query string or `application/x-www-form-urlencoded` body itself.
 * PHP's $_GET and $_POST keep only the last of repeated names and turn `name[]=` into arrays, so
 * they can't enforce OAuth 2.1's "MUST NOT be included more than once".
 */
final class Remote_MCP_Params {

    /**
     * Name and value pairs in order, percent-decoded with `+` as a space. A name with brackets
     * counts as its base name, so `client_id[]` can't smuggle a second value.
     *
     * @param string $raw Raw query string or form body.
     * @return array List of [ base name, value ].
     */
    public static function pairs( string $raw ): array {
        $pairs = array();
        foreach ( explode( '&', $raw ) as $piece ) {
            if ( '' === $piece ) {
                continue;
            }
            $split = strpos( $piece, '=' );
            $name  = urldecode( false === $split ? $piece : substr( $piece, 0, $split ) );
            $value = false === $split ? '' : urldecode( substr( $piece, $split + 1 ) );
            $open  = strpos( $name, '[' );
            if ( false !== $open ) {
                $name = substr( $name, 0, $open );
            }
            if ( '' !== $name ) {
                $pairs[] = array( $name, $value );
            }
        }
        return $pairs;
    }

    /**
     * Extract an endpoint's defined parameters.
     *
     * A parameter sent with an empty value counts as omitted. A defined single-valued parameter
     * appearing more than once with a non-empty value is an error, reported before the caller
     * looks anything up. Multi-valued parameters (`resource`, RFC 8707) are collected for the
     * caller's own count rule. Undefined parameters are ignored but their names are reported.
     *
     * @param string $raw     Raw query string or form body.
     * @param array  $defined Single-valued parameter names.
     * @param array  $multi   Multi-valued parameter names.
     * @return array `duplicate` (first repeated name, or null), `values` (name => string or null),
     *               `multi` (name => list of strings), `names` (every name sent with a value).
     */
    public static function extract( string $raw, array $defined, array $multi = array() ): array {
        $values    = array_fill_keys( $defined, null );
        $lists     = array_fill_keys( $multi, array() );
        $names     = array();
        $duplicate = null;
        foreach ( self::pairs( $raw ) as list( $name, $value ) ) {
            if ( '' === $value ) {
                continue;
            }
            $names[ $name ] = true;
            if ( array_key_exists( $name, $lists ) ) {
                $lists[ $name ][] = $value;
                continue;
            }
            if ( ! array_key_exists( $name, $values ) ) {
                continue;
            }
            if ( null !== $values[ $name ] && null === $duplicate ) {
                $duplicate = $name;
            }
            $values[ $name ] = $value;
        }
        if ( null !== $duplicate ) {
            $values = array_fill_keys( $defined, null );
        }
        return array(
            'duplicate' => $duplicate,
            'values'    => $values,
            'multi'     => $lists,
            'names'     => array_keys( $names ),
        );
    }
}
