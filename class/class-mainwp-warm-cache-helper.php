<?php
/**
 * MainWP Warm Cache Helper.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_System
 *
 * @package MainWP\Dashboard
 */
class MainWP_Warm_Cache_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    const WPC_VER = '1';

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @static
     * @return MainWP_System
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Runs any time class is called.
     */
    public function __construct() { //phpcs:ignore -- NOSONAR - complex.
        add_action( 'current_screen', array( __CLASS__, 'init_no_cache_header' ), 999 );
        /**
         * Action to Invalide warm cache pages.
         *
         * @since 5.5.
         */
        add_action( 'mainwp_invalidate_warm_cache_pages', array( __CLASS__, 'hook_warm_cache_invalidate_pages' ), 10, 2 );
    }

    /**
     * Method init_no_cache_header()
     */
    public static function init_no_cache_header() {
        if ( MainWP_System::is_mainwp_pages() ) {
            $page = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( ! headers_sent() && ! static::is_excluded_warm_cache_pages( $page ) ) {
                $maxage = get_option( 'mainwp_warm_cache_pages_ttl', 10 );
                if ( empty( $maxage ) ) {
                    return;
                }
                static::verify_current_page_no_cache_header();
            }
        }
    }


    /**
     * Method hook_warm_cache_invalidate_pages().
     *
     * @param array $pages List pages to invalidate.
     */
    public static function hook_warm_cache_invalidate_pages( $pages = array() ) {
        if ( ! empty( $pages ) && is_array( $pages ) ) {
            foreach ( $pages as $page ) {
                self::invalidate_page_warm_cache( $page );
            }
        }
    }

    /**
     * Method verify_current_page_no_cache_header()
     */
    private static function verify_current_page_no_cache_header() {

        $page = isset($_GET['page']) ? preg_replace('/[^a-z0-9_\-]/i', '', $_GET['page']) : ''; //phpcs:ignore -- ok.

        if ( empty( $page ) ) {
            return;
        }

        $method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : ''; //phpcs:ignore -- WPCS: sanitization ok.
        if ( ! in_array( $method, array( 'GET', 'HEAD' ) ) ) {
            return;
        }

        // Your real "last time this page's data changed" (must be stable until content changes!).
        $lastChangedTs = (int) static::get_current_page_warm_cache_last_changed();

        if ( empty( $lastChangedTs ) ) {
            return;
        }

        $etag = static::get_current_page_etag_header();

        $lastModified = gmdate( 'D, d M Y H:i:s', $lastChangedTs ) . ' GMT';

        // Clear conflicting headers first.
        header_remove( 'Pragma' );
        header_remove( 'Expires' );
        header_remove( 'Cache-Control' );

        // ====== Freshness policy ======
        // Always revalidate before use (keeps a cached copy, but checks each navigation).
        header( 'Cache-Control: private, no-cache' );
        // If you prefer a TTL, use e.g.: header('Cache-Control: private, max-age=60, must-revalidate');.

        header( "ETag: $etag" );
        header( "Last-Modified: $lastModified" );
        header( 'Vary: Accept-Encoding' ); // safe variant separation.

        // ====== Conditional handling (304 if unchanged) ======.
        $ifNoneMatch     = $_SERVER['HTTP_IF_NONE_MATCH'] ?? ''; //phpcs:ignore -- ok.
        $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''; //phpcs:ignore -- ok.

        $normalize = static function ( string $t ): string {
            $t = trim( $t );
            if ( strncasecmp( $t, 'W/', 2 ) === 0 ) {
                $t = substr( $t, 2 ); // ignore weak prefix.
            }
            return $t;
        };

        $etagMatch = false;
        if ( '' !== $ifNoneMatch ) {
            foreach ( explode( ',', $ifNoneMatch ) as $cand ) {
                if ( $normalize( $cand ) === $etag ) {
                    $etagMatch = true;
                    break;
                }
            }
        }

        $lmMatch = ( '' !== $ifModifiedSince ) && ( strtotime( $ifModifiedSince ) >= $lastChangedTs );

        // If either validator matches, nothing changed → 304.
        if ( $etagMatch || $lmMatch ) {
            http_response_code( 304 );
            // Re-send validators with 304.
            header( "ETag: $etag" );
            header( "Last-Modified: $lastModified" );
            exit;
        }
    }


    /**
     * Method get_page_warm_cache_last_change_key().
     *
     * @param string $page The page.
     *
     * @return string The key.
     */
    public static function get_page_warm_cache_last_change_key( $page = '' ) {

        if ( empty( $page ) ) {
             $page = isset($_GET['page']) ? preg_replace('/[^a-z0-9_\-]/i', '', $_GET['page']) : ''; //phpcs:ignore -- ok.
            if ( empty( $page ) ) {
                return '';
            }
        }

        // Per-user id.
        if ( function_exists( 'get_current_user_id' ) ) {
            $userId = (int) get_current_user_id();
        } else {
            $userId = 0;
        }

        $key = sha1( 'v:' . static::WPC_VER . "|u:$userId|p:$page" );
        return 'mainwp_page_warm_cache_last_changed_key_' . $key;
    }

    /**
     * Method get_current_page_warm_cache_last_changed().
     *
     * @return int Last change timestamp.
     */
    public static function get_current_page_warm_cache_last_changed() {

        $lastchanged = (int) static::get_page_last_changed_cache();

        $maxage  = get_option( 'mainwp_warm_cache_pages_ttl', 10 );
        $seconds = $maxage * 60;

        if ( empty( $lastchanged ) || $lastchanged < time() - $seconds ) {
            $lastchanged      = time();
            $last_changed_key = static::get_page_warm_cache_last_change_key();
            if ( ! empty( $last_changed_key ) ) {
                set_transient( $last_changed_key, $lastchanged, $seconds );
            }
        }

        return $lastchanged;
    }


    /**
     * Method get_current_page_etag_header().
     *
     * @return string Etag.
     */
    public static function get_current_page_etag_header() {
        return static::get_page_etag_header();
    }

    /**
     * Method get_page_etag_header().
     *
     * @param string $page Page to get Etag header.
     *
     * @return string Etag.
     */
    public static function get_page_etag_header( $page = '' ) {

        if ( empty( $page ) ) {
             $page = isset($_GET['page']) ? preg_replace('/[^a-z0-9_\-]/i', '', $_GET['page']) : ''; //phpcs:ignore -- ok.
            if ( empty( $page ) ) {
                return '';
            }
        }

        $lastChangedTs = (int) static::get_page_last_changed_cache();
        // Per-user id.
        if ( function_exists( 'get_current_user_id' ) ) {
            $userId = (int) get_current_user_id();
        } else {
            $userId = 0;
        }
        // ====== Build ETag from user + page + last-modified ======.
        return '"mainwp_pages_' . sha1( "u:$userId|p:$page|ts:$lastChangedTs" ) . '"';
    }


    /**
     * Method get_page_last_changed_cache().
     *
     * @param string $page Page to get last changed cache.
     * @return mixed Page last change.
     */
    public static function get_page_last_changed_cache( $page = '' ) {
        $last_changed_key = static::get_page_warm_cache_last_change_key( $page );
        if ( empty( $last_changed_key ) ) {
            return 0;
        }
        return get_transient( $last_changed_key );
    }

    /**
     * Method invalidate_page_warm_cache().
     *
     * @param string $page Page to invalidate.
     *
     * @return int Invalidate last change timestamp.
     */
    public static function invalidate_page_warm_cache( $page ) {
        $last_changed_key = static::get_page_warm_cache_last_change_key( $page );
        $lastchanged      = time();
        $maxage           = get_option( 'mainwp_warm_cache_pages_ttl', 10 );
        $seconds          = $maxage * 60;
        set_transient( $last_changed_key, $lastchanged, $seconds );
        return $lastchanged;
    }


    /**
     * Method is_excluded_warm_cache_pages().
     *
     * @param mixed $page Check excluded pages or not.
     *
     * @return bool True|false, excluded or not.
     */
    public static function is_excluded_warm_cache_pages( $page = false ) {
        if ( empty( $page ) ) {
            return true;
        }

        /**
         * Excluded warm cache pages.
         *
         * @since 5.5.
         */
        $exclude_pages = apply_filters(
            'mainwp_warm_cache_excluded_pages',
            array(
                'ActionLogs',
                'mainwp-setup',
            )
        );
        return ! is_array( $exclude_pages ) || ( in_array( '_excluded_all', $exclude_pages ) && ! in_array( $page, $exclude_pages ) ) ? true : false;
    }
}
