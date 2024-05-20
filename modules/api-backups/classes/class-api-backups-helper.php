<?php
/**
 * Class Api Backups Utility
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

/**
 * Class Api_Backups_Utility
 */
class Api_Backups_Helper {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Api_Backups_Utility
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Construct.
     */
    public function __construct() {
        // constructor.
    }


    /**
     * Clean URL.
     * Remove http(s)://, www., and trailing slash(/) from the URL.
     *
     * @param string $url url.
     * @return string clean url.
     */
    public static function clean_url( $url ) {
        $clean_url = str_replace( array( 'https://www.', 'http://www.', 'https://', 'http://', 'www.' ), array( '', '', '', '', '' ), $url ); // NOSONAR - ok.
        $clean_url = rtrim( $clean_url, '/' );
        return $clean_url;
    }

    /**
     * Get sites by website ID.
     *
     * @param int   $website_id       User ID.
     * @param bool  $selectGroups     Select groups.
     * @param array $extra_view   get extra option fields.
     *
     * @return object|null Database query results or null on failure.
     */
    public static function get_website_by_id( $website_id, $selectGroups = false, $extra_view = array() ) {
        $website = apply_filters( 'mainwp_getwebsite_by_id', false, $website_id, $selectGroups, $extra_view );
        if ( ! empty( $website ) && is_object( $website ) ) {
            return (array) $website;
        }
        return array();
    }


    /**
     * Method update_website_option().
     *
     * Update the website option.
     *
     * @param object|int $website website object or ID.
     * @param string     $opt_name website.
     * @param string     $opt_value website.
     */
    public static function update_website_option( $website, $opt_name, $opt_value ) {
        return apply_filters( 'mainwp_updatewebsiteoptions', false, $website, $opt_name, $opt_value );
    }

    /**
     * Method get_website_options().
     *
     * Get the website options.
     *
     * @param object|int   $website website object or ID.
     * @param string|array $options Options name.
     */
    public static function get_website_options( $website, $options ) {
        return apply_filters( 'mainwp_getwebsiteoptions', false, $website, $options );
    }


    /**
     * Method fetch_object().
     *
     * Handle fetch object db.
     *
     * @param mixed $websites websites results.
     *
     * @return mixed results.
     */
    public static function fetch_object( $websites ) {
        return apply_filters( 'mainwp_db_fetch_object', false, $websites );
    }


    /**
     * Method free_result().
     *
     * Handle fetch result db.
     *
     * @param mixed $results websites results.
     *
     * @return mixed results.
     */
    public static function free_result( $results ) { //phpcs:ignore -- NOSONAR - complex.
        if ( empty( $results ) ) {
            return;
        }
        return apply_filters( 'mainwp_db_free_result', false, $results );
    }

    /**
     * Method security_nonce().
     *
     * Handle security nonce.
     *
     * @param string $action security action.
     */
    public static function security_nonce( $action ) {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            do_action( 'mainwp_secure_request', $action );
        }
    }
}
