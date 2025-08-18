<?php
/**
 * MainWP Caching Helper
 *
 * @package MainWP/Dashboard
 *
 * @since 5.5
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Cache_Helper
 *
 * @package MainWP\Dashboard
 */
final class MainWP_Cache_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * Cache Groups consts.
     */
    const GC_SITES   = 'manage_sites';
    const GC_UPDATES = 'updates';

    /**
     * Cache hits counter.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $metrics_list = array();

    /**
     * Cache hits counter.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $hits = 0;

    /**
     * Cache misses counter.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $misses = 0;

    /**
     * Cache sets counter.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $sets = 0;

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Cache_Helper
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        // contructor.
    }

    /**
     * Get transient version.
     *
     * @param  string  $group   Name for the group of transients we need to invalidate.
     * @param  boolean $refresh true to force a new version.
     * @return string transient version based on time(), 10 digits.
     */
    public static function get_transient_version( $group, $refresh = false ) {
        $transient_name  = 'mainwp-transient-' . $group . '-version';
        $transient_value = get_transient( $transient_name );

        if ( false === $transient_value || true === $refresh ) {
            $transient_value = (string) time();
            set_transient( $transient_name, $transient_value );
        }
        return $transient_value;
    }


    /**
     * Get cache key.
     *
     * @param  string $key_suffix Key suffix.
     * @param  string $group Group of cache to get.
     * @param  string $filters Filters params.
     *
     * @return string Cache key.
     */
    public static function get_cache_key( $key_suffix, $group = 'default', $filters = false ) {
        return self::get_cache_prefix( $group, $filters ) . $key_suffix;
    }

    /**
     * Get group cache prefix.
     *
     * @param  string $group Group of cache to get.
     *
     * @return string Group prefix.
     */
    public static function get_key_group( $group ) {
        return 'mainwp_cache_' . $group . '_prefix';
    }

    /**
     * Get prefix allows all cache in a group to be invalidated at once.
     *
     * @param  string $group Group of cache to get.
     * @param  string $filters Filters params.
     *
     * @return string Prefix.
     */
    public static function get_cache_prefix( $group, $filters = false ) {

        $key_group = self::get_key_group( $group );

        // Get cache key - to invalidate when needed.
        $prefix = wp_cache_get( $key_group, $group );

        if ( false === $prefix ) {
            $prefix = get_transient( $key_group );
        }

        if ( false === $prefix ) {
            $prefix = microtime( true );
            set_transient( $key_group, $prefix );
            wp_cache_set( $key_group, $prefix, $group );
        }

        // Dynamic filters prefix checks.
        $filters_prefix = '';
        if ( ! empty( $filters ) && is_array( $filters ) ) {
            $filters_prefix = '_' . self::get_normalized_params( $filters );
        }

        $userid = 0;
        if ( function_exists( '\get_current_user_id' ) ) {
            $userid = (int) get_current_user_id();
        }

        return 'mainwp_cache_' . $userid . '_' . $prefix . $filters_prefix . '_';
    }

    /**
     * Get cache group.
     *
     * @param string $group Group of cache to clear.
     */
    public static function get_group_cache( $group = 'default' ) {
        switch ( $group ) {
            case 'manage_sites':
                $cache = 'manage_sites';
                break;
            case 'updates':
                $cache = 'updates';
                break;
            default:
                $cache = 'default';
                break;
        }
        return $cache;
    }

    /**
     * Invalidate cache group.
     *
     * @param string $group Group of cache to clear.
     */
    public static function invalidate_cache_group( $group ) {
        $mct       = microtime( true );
        $key_group = self::get_key_group( $group );
        wp_cache_set( $key_group, $mct, $group );
        set_transient( $key_group, $mct, $group );
    }

    /**
     * Add cache.
     *
     * @param string $key Cache key.
     * @param string $group Group of cache.
     * @param mixed  $data Cache data.
     * @param int    $ttl TTL.
     * @param int    $object_cache_ttl Object cache ttl.
     */
    public static function add_cache(
        $key,
        $group,
        $data = false,
        $ttl = null,
        $object_cache_ttl = null
    ) {
        $ttl              = $ttl ?? self::CACHE_TTL;
        $object_cache_ttl = $object_cache_ttl ?? $ttl;
        $transient_key    = $key;
        wp_cache_set( $key, $data, $group, $object_cache_ttl );
        set_transient( $transient_key, $data, $ttl );
        self::record_metrics( 'set', $key, $group );
    }

    /**
     * Method get_normalized_params.
     *
     * @param array $params Params to get prefix.
     *
     * @return string Prefix.
     */
    private static function get_normalized_params( $params = array() ) {
        $normalized = wp_json_encode( self::clean_and_sort_params( $params ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return sha1( $normalized );
    }

    /**
     * Smart clean & sort for parameter arrays.
     *
     * Features:
     * - Removes nulls, empty strings, and (optionally) empty arrays
     * - Trims strings
     * - Coerces "true"/"false"/"on"/"off"/"yes"/"no" to bool
     * - Coerces numeric strings to int/float
     * - Sorts associative arrays by key (stable)
     * - Sorts list arrays by value (optional) or keeps original order
     * - Can ignore specific keys (e.g., nonce/timestamp) on associative arrays
     *
     * @param array $params Params to clean.
     * @param array $opts Clean opts.
     * {
     *   coerce_types?: bool,         // default true
     *   numeric_to_number?: bool,    // default true (only if coerce_types)
     *   sort_lists?: bool,           // default true (order-insensitive lists)
     *   drop_empty_arrays?: bool,    // default true
     *   ignore_keys?: string[]       // default []
     * }
     * @return array
     */
    private static function clean_and_sort_params( $params, $opts = array() ) {

        $coerceTypes     = $opts['coerce_types'] ?? true;
        $numericToNumber = $opts['numeric_to_number'] ?? true;
        $sortLists       = $opts['sort_lists'] ?? true;
        $dropEmptyArrays = $opts['drop_empty_arrays'] ?? true;
        $ignoreKeys      = array_flip( $opts['ignore_keys'] ?? array() );

        $isAssoc = static function ( array $a ) {
            $i = 0;
            foreach ( $a as $k => $_ ) {
                if ( $k !== $i++ ) {
                    return true;
                }
            }
            return false;
        };

        $coerce = static function ( $v ) use ( $coerceTypes, $numericToNumber ) {
            if ( ! $coerceTypes ) {
                if ( is_string( $v ) ) {
                    $s = trim( $v );
                    return '' === $s ? null : $s;
                }
                return $v;
            }

            if ( is_string( $v ) ) {
                $s = trim( $v );
                if ( '' === $s ) {
                    return null;
                }

                $ls = strtolower( $s );
                if ( in_array( $ls, array( 'true', 'yes', 'on' ), true ) ) {
                    return true;
                }
                if ( in_array( $ls, array( 'false', 'no', 'off' ), true ) ) {
                    return false;
                }

                if ( $numericToNumber && is_numeric( $s ) ) {
                    // keep integers as int; others as float.
                    return ctype_digit( ltrim( $s, '+-' ) ) ? (int) $s : (float) $s;
                }
                return $s;
            }
            return $v; // bool/int/float/null unchanged.
        };

        $walk = null;
        $walk = function ( $value ) use ( &$walk, $coerce, $isAssoc, $ignoreKeys, $sortLists, $dropEmptyArrays ) {
            if ( ! is_array( $value ) ) {
                return $coerce( $value );
            }

            if ( $isAssoc( $value ) ) {
                // Remove ignored keys first.
                if ( ! empty( $ignoreKeys ) ) {
                    foreach ( $ignoreKeys as $key => $_ ) {
                        if ( array_key_exists( $key, $value ) ) {
                            unset( $value[ $key ] );
                        }
                    }
                }

                $out = array();
                foreach ( $value as $k => $v ) {
                    $nv = $walk( $v );

                    // drop null.
                    if ( null === $nv ) {
                        continue;
                    }

                    // optionally drop empty arrays.
                    if ( is_array( $nv ) && $dropEmptyArrays && count( $nv ) === 0 ) {
                        continue;
                    }

                    $out[ $k ] = $nv;
                }
                ksort( $out );
                return $out;
            }

            // List (indexed) array.
            $tmp = array();
            foreach ( $value as $v ) {
                $nv = $walk( $v );
                if ( $nv === null ) {
                    continue;
                }
                if ( is_array( $nv ) && $dropEmptyArrays && count( $nv ) === 0 ) {
                    continue;
                }
                $tmp[] = $nv;
            }

            // If requested, make lists order-insensitive:
            // - If all scalars, sort naturally (case-insensitive for strings)
            // - If mixed/nested, JSON-stable sorting using string cast.
            if ( $sortLists && count( $tmp ) > 1 ) {
                $allScalars = true;
                foreach ( $tmp as $item ) {
                    if ( is_array( $item ) || is_object( $item ) ) {
                        $allScalars = false;
                        break;
                    }
                }

                if ( $allScalars ) {
                    sort( $tmp, SORT_NATURAL | SORT_FLAG_CASE );
                } else {
                    usort(
                        $tmp,
                        static function ( $a, $b ) {
                            $sa = is_scalar( $a ) ? (string) $a : wp_json_encode( $a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                            $sb = is_scalar( $b ) ? (string) $b : wp_json_encode( $b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                            return $sa <=> $sb;
                        }
                    );
                }
            }

            return array_values( $tmp );
        };

        return $walk( $params ) ?? array();
    }


    /**
     * Get data with fast object-cache + DB transient fallback.
     *
     * Flow:
     *  1) Try object cache (wp_cache_get).
     *  2) Fallback to transient (get_transient) and warm object cache if found.
     *  3) Otherwise compute via $generator(), store in both layers, and return.
     *
     * @param string      $key              Logical cache key (no group prefix).
     * @param string      $group            Object cache group name.
     * @param callable    $generator        Function that returns the fresh data when not cached.
     * @param array       $args             Args for generator
     * @param int         $ttl              Transient TTL in seconds (also used for object cache if $object_cache_ttl is null).
     * @param int|null    $object_cache_ttl Optional TTL for object cache layer; defaults to $ttl.
     * @param string|null $prefix           Optional prefix to avoid collisions (e.g., your plugin slug). If null, uses $group.
     *
     * @return mixed
     */
    public function get_cache(
        $key,
        $group,
        $generator = false,
        $args = array(),
        $ttl = null,
        $object_cache_ttl = null
    ) {
        $ttl              = $ttl ?? self::CACHE_TTL;
        $object_cache_ttl = $object_cache_ttl ?? $ttl;
        $transient_key    = $key;

        $data = wp_cache_get( $key, $group );

        if ( false !== $data ) {
            self::record_metrics( 'hit', $key, $group );
            return $data;
        }

        $data = get_transient( $transient_key );

        if ( false !== $data ) {
            self::record_metrics( 'hit', $key, $group );
            wp_cache_set( $key, $data, $group, $object_cache_ttl );
            return $data;
        }

        self::record_metrics( 'miss', $key, $group );

        if ( $generator && is_callable( $generator ) ) {
            // Pass args to the generator.
            $data = call_user_func_array( $generator, $args );
            self::record_metrics( 'set', $key, $group );
            wp_cache_set( $key, $data, $group, $object_cache_ttl );
            set_transient( $transient_key, $data, $ttl );
            return $data;
        }

        return '_get_cache_false';
    }


    /**
     * Delete both layers for a key - invalidation.
     *
     * @param string $key Cache key to delete.
     * @param string $group Cache group to delete.
     * @return void
     */
    public function delete_cache( $key, $group ) {
        wp_cache_delete( $key, $group );
        delete_transient( $key );
    }

    /**
     * Method record_metrics().
     *
     * @param string $metric Cache metric.
     * @param string $key Cache key.
     * @param string $group Cache group.
     *
     * @return void
     */
    public static function record_metrics( $metric, $key = '', $group = '' ) {

        self::$metrics_list[] = $group . '|' . $key;

        switch ( $metric ) {
            case 'set':
                ++self::$sets;
                break;
            case 'miss':
                ++self::$misses;
                break;
            case 'hit':
                ++self::$hits;
                break;
            default:
                break;
        }
    }

    /**
     * Method log_metrics().
     *
     * @return void
     */
    public static function log_metrics() {

        if ( empty( self::$metrics_list ) ) {
            return false;
        }

        $sec = MainWP_Execution_Helper::instance()->get_exec_time();
        MainWP_Logger::instance()->log_events( 'cache-metrics', sprintf( '[hits=%d] :: [misses=%d] :: [sets=%d] :: [execution time=%s]', self::$hits, self::$misses, self::$sets, round( $sec, 4 ) ) );
        MainWP_Logger::instance()->log_events( 'cache-metrics', sprintf( '[cache_keys=%s]', implode( "\n", self::$metrics_list ) ) );
    }
}
