<?php
/**
 * Class Api Backups Utility
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

use MainWP\Dashboard\MainWP_DB;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     * Authorize an action before nonce deduplication, mappings or credentials.
     *
     * @param string      $right Required Dashboard right.
     * @param string|null $site_field Posted site field, or null for scoped enumeration.
     * @param mixed       $site_id Explicit site id for internal database-backup calls.
     * @return int Authorized site id, or zero for enumeration.
     */
    public static function authorize_ajax_action( $right, $site_field = 'website_id', $site_id = null ) {
        if ( wp_doing_ajax() && ! \MainWP\Dashboard\MainWP_System_Utility::is_admin() ) {
            die( 0 );
        }
        if ( ! \mainwp_current_user_can( 'dashboard', $right ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Error: insufficient permissions.', 'mainwp' ) ) );
        }
        if ( null === $site_field ) {
            return 0;
        }
        if ( null === $site_id ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate the raw site value before conversion; authorization must precede the nonce's persistent deduplication write.
            $site_id = wp_doing_ajax() ? ( $_POST[ $site_field ] ?? null ) : ( $_GET['id'] ?? null );
        }
        if ( ( ! is_int( $site_id ) && ! is_string( $site_id ) ) || ! preg_match( '/\A[0-9]+\z/', (string) $site_id ) || (int) $site_id < 1 ) {
            wp_send_json( array( 'error' => esc_html__( 'Error: invalid site ID.', 'mainwp' ) ) );
        }
        $site_id = (int) $site_id;
        if ( ! \mainwp_current_user_can( 'site', $site_id ) || empty( self::get_website_by_id( $site_id ) ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Error: site access denied.', 'mainwp' ) ) );
        }
        return $site_id;
    }

    /**
     * Read ownership evidence without entering mapping or credential filters.
     *
     * @param int    $site_id Already authorized site.
     * @param string $provider Provider name.
     * @return array Stored backup entries.
     */
    public static function get_owned_backups( $site_id, $provider ) {
        $stored = MainWP_DB::instance()->get_json_website_option( $site_id, 'mainwp_3rd_party_' . $provider . '_available_backups' );
        if ( 'kinsta' === $provider ) {
            $backups = $stored['environment']['backups'] ?? array();
            return is_array( $backups ) ? $backups : array();
        }
        if ( 'digitalocean' === $provider ) {
            $snapshots = $stored['snapshots'] ?? array();
            return is_array( $snapshots ) ? $snapshots : array();
        }
        if ( 'vultr' === $provider ) {
            $snapshots = $stored['snapshots'] ?? array();
            if ( isset( $snapshots['snapshot'] ) ) {
                $snapshots = array( $snapshots['snapshot'] );
            }
            $backups = $stored['backups'] ?? array();
            return array_merge( is_array( $snapshots ) ? $snapshots : array(), is_array( $backups ) ? $backups : array() );
        }
        return array();
    }

    /**
     * Match the exact snapshot or backup id used by the provider worker.
     *
     * @param int    $site_id Already authorized site.
     * @param string $provider Provider name.
     * @param mixed  $object_id Provider object id.
     * @return bool Whether the stored site list contains the object.
     */
    public static function owns_backup( $site_id, $provider, $object_id ) {
        if ( ! is_scalar( $object_id ) || '' === (string) $object_id ) {
            return false;
        }
        if ( 'vultr' === $provider ) {
            $snapshots = MainWP_DB::instance()->get_json_website_option( $site_id, 'mainwp_3rd_party_vultr_snapshot_list' );
            foreach ( $snapshots as $snapshot ) {
                if ( is_scalar( $snapshot ) && (string) $snapshot === (string) $object_id ) {
                    return true;
                }
            }
        }
        foreach ( self::get_owned_backups( $site_id, $provider ) as $backup ) {
            if ( is_array( $backup ) && isset( $backup['id'] ) && is_scalar( $backup['id'] ) && (string) $backup['id'] === (string) $object_id ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Require ownership before credentials or the nonce's persistent write.
     *
     * @param int    $site_id Authorized site.
     * @param string $provider Provider name.
     * @param string $field Posted object field.
     * @param bool   $integer Worker converts the object id to an integer.
     */
    public static function require_owned_backup( $site_id, $provider, $field, $integer = false ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Match the raw unslashed object exactly; ownership authorization precedes nonce deduplication.
        $object_id = isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
        if ( $integer ) {
            if ( ! preg_match( '/\A[0-9]+\z/', (string) $object_id ) || (int) $object_id < 1 ) {
                self::refuse_unlisted_backup();
            }
            $object_id = (int) $object_id;
        }
        if ( ! self::owns_backup( $site_id, $provider, $object_id ) ) {
            self::refuse_unlisted_backup();
        }
    }

    /** Resolve snapshot-only deletes among accessible sites without credentials. */
    public static function authorize_digitalocean_delete() {
        self::authorize_ajax_action( 'execute_backups', null );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Match the raw unslashed object exactly; ownership authorization precedes nonce deduplication.
        $snapshot_id = isset( $_POST['snapshot_id'] ) && is_scalar( $_POST['snapshot_id'] ) ? wp_unslash( $_POST['snapshot_id'] ) : '';
        foreach ( MainWP_DB::instance()->get_website_ids_with_nonempty_option( 'mainwp_3rd_party_digitalocean_available_backups', PHP_INT_MAX ) as $candidate ) {
            $site_id = (int) $candidate;
            if ( self::owns_backup( $site_id, 'digitalocean', $snapshot_id ) && \mainwp_current_user_can( 'site', $site_id ) && ! empty( self::get_website_by_id( $site_id ) ) ) {
                self::authorize_ajax_action( 'execute_backups', 'website_id', $site_id );
                return;
            }
        }
        self::refuse_unlisted_backup();
    }

    /**
     * Account-wide cPanel lists may describe an entire account rather than one site.
     *
     * @param int    $site_id Authorized site.
     * @param string $kind Automatic, database or manual backup.
     */
    public static function authorize_cpanel_restore( $site_id, $kind ) {
        $db         = MainWP_DB::instance();
        $individual = 'on' === $db->get_website_option( $site_id, 'mainwp_enable_cpanel_individual' );
        if ( 'manual' === $kind || ! $individual ) {
            if ( 'all' !== apply_filters( 'mainwp_currentuserallowedaccesssites', 'all' ) ) {
                wp_send_json( array( 'error' => esc_html__( 'Error: unrestricted site access is required to restore account-wide backups.', 'mainwp' ) ) );
            }
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Match the raw unslashed object exactly; ownership authorization precedes nonce deduplication.
        $backup_id = isset( $_POST['backup_id'] ) && is_scalar( $_POST['backup_id'] ) ? wp_unslash( $_POST['backup_id'] ) : '';
        if ( 'database' === $kind ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compare the exact unslashed directory later used by the worker.
            $path    = isset( $_POST['backup_path'] ) && is_scalar( $_POST['backup_path'] ) ? wp_unslash( $_POST['backup_path'] ) : '';
            $backups = $db->get_json_website_option( $site_id, 'mainwp_3rd_party_cpanel_manual_database_backups' );
            $field   = 'file_name';
        } else {
            // Automatic restores use the configured path, which cPanel may normalize in its list.
            $stored  = $db->get_json_website_option( $site_id, 'mainwp_3rd_party_cpanel_available_backups' );
            $backups = is_array( $stored['data'] ?? null ) ? $stored['data'] : array();
            $field   = 'backupID';
        }
        foreach ( $backups as $backup ) {
            if ( is_array( $backup ) && isset( $backup[ $field ] ) && is_scalar( $backup[ $field ] ) && (string) $backup[ $field ] === (string) $backup_id && '' !== $backup_id ) {
                if ( 'database' !== $kind || ( isset( $backup['absolute_dir'] ) && $backup['absolute_dir'] === $path && ! empty( $path ) ) ) {
                    return;
                }
            }
        }
        self::refuse_unlisted_backup();
    }

    /** Unknown objects may need a fresh provider list before they can be authorized. */
    private static function refuse_unlisted_backup() {
        wp_send_json( array( 'error' => esc_html__( 'Error: backup ownership could not be verified for this site. Refresh the backup list and try again.', 'mainwp' ) ) );
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
