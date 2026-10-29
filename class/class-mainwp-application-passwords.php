<?php
/**
 * MainWP Application Passwords
 *
 * This class handles Application Passwords functionality for MainWP.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Application_Passwords
 *
 * @package MainWP\Dashboard
 */
class MainWP_Application_Passwords {  // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR

    /**
     * The generated application password length.
     *
     * @var int
     */
    const PW_LENGTH = 24;

    /**
     * The option name used to store application passwords.
     *
     * @var string
     */
    const USERMETA_KEY_APPLICATION_PASSWORDS = '_application_passwords';

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Return the single instance of the class.
     *
     * @return mixed $instance The single instance of the class.
     */
    public static function instance() {
        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Creates a new application password.
     *
     * @param int   $user_id User ID.
     * @param array $args    Arguments used to create the application password.
     * @return array|\WP_Error Application password details, or a WP_Error instance if an error occurs.
     */
    public static function create_new_application_password( $user_id, $args = array() ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! empty( $args['name'] ) ) {
            $args['name'] = sanitize_text_field( $args['name'] );
        }

        if ( empty( $args['name'] ) ) {
            return new \WP_Error( 'application_password_empty_name', __( 'An application name is required to create an application password.', 'mainwp' ), array( 'status' => 400 ) );
        }

        // Check if name already exists.
        if ( static::application_name_exists_for_user( $user_id, $args['name'] ) ) {
            return new \WP_Error( 'application_password_exists', __( 'Each application name should be unique.', 'mainwp' ), array( 'status' => 409 ) );
        }

        $new_password    = wp_generate_password( static::PW_LENGTH, false );
        $hashed_password = wp_hash_password( $new_password );

        $new_item = array(
            'uuid'      => wp_generate_uuid4(),
            'app_id'    => empty( $args['app_id'] ) ? '' : $args['app_id'],
            'name'      => $args['name'],
            'password'  => $hashed_password,
            'created'   => time(),
            'last_used' => null,
            'last_ip'   => null,
        );

        $passwords   = static::get_user_application_passwords( $user_id );
        $passwords[] = $new_item;
        $saved       = static::set_user_application_passwords( $user_id, $passwords );

        if ( ! $saved ) {
            return new \WP_Error( 'db_error', __( 'Could not save application password.', 'mainwp' ) );
        }

        /**
         * Fires when an application password is created.
         *
         * @param int    $user_id      The user ID.
         * @param array  $new_item     The details about the created password.
         * @param string $new_password The generated application password in plain text.
         * @param array  $args         Arguments used to create the application password.
         */
        do_action( 'mainwp_create_application_password', $user_id, $new_item, $new_password, $args );

        return array( $new_password, $new_item );
    }

    /**
     * Gets a user's application passwords.
     *
     * @param int $user_id User ID.
     * @return array The list of application passwords.
     */
    public static function get_user_application_passwords( $user_id ) {
        $passwords = get_user_meta( $user_id, static::USERMETA_KEY_APPLICATION_PASSWORDS, true );

        if ( ! is_array( $passwords ) ) {
            return array();
        }

        $save = false;

        foreach ( $passwords as $i => $password ) {
            if ( ! isset( $password['uuid'] ) ) {
                $passwords[ $i ]['uuid'] = wp_generate_uuid4();
                $save                    = true;
            }
        }

        if ( $save ) {
            static::set_user_application_passwords( $user_id, $passwords );
        }

        return $passwords;
    }

    /**
     * Gets a user's application password with the given UUID.
     *
     * @param int    $user_id User ID.
     * @param string $uuid    The password's UUID.
     * @return array|null The application password if found, null otherwise.
     */
    public static function get_user_application_password( $user_id, $uuid ) {
        $passwords = static::get_user_application_passwords( $user_id );

        foreach ( $passwords as $password ) {
            if ( $password['uuid'] === $uuid ) {
                return $password;
            }
        }

        return null;
    }

    /**
     * Checks if an application password with the given name exists for this user.
     *
     * @param int    $user_id User ID.
     * @param string $name    Application name.
     * @return bool Whether the provided application name exists.
     */
    public static function application_name_exists_for_user( $user_id, $name ) {
        $passwords = static::get_user_application_passwords( $user_id );

        foreach ( $passwords as $password ) {
            if ( strtolower( $password['name'] ) === strtolower( $name ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Updates the application passwords list for a user.
     *
     * @param int   $user_id   User ID.
     * @param array $passwords Application passwords.
     * @return bool Whether the update was successful.
     */
    protected static function set_user_application_passwords( $user_id, $passwords ) {
        return update_user_meta( $user_id, static::USERMETA_KEY_APPLICATION_PASSWORDS, $passwords );
    }

    /**
     * Updates an application password.
     *
     * @param int    $user_id User ID.
     * @param string $uuid    The password's UUID.
     * @param array  $update  Information about the application password to update.
     * @return true|\WP_Error True if successful, otherwise a WP_Error instance is returned on error.
     */
    public static function update_application_password( $user_id, $uuid, $update = array() ) { // phpcs:ignore -- NOSONAR - complex.
        $passwords = get_user_meta( $user_id, static::USERMETA_KEY_APPLICATION_PASSWORDS, true );
        if ( ! is_array( $passwords ) ) {
            return new \WP_Error( 'application_password_not_found', static::get_error_message() );
        }

        foreach ( $passwords as &$item ) {
            if ( $item['uuid'] !== $uuid ) {
                continue;
            }

            if ( ! empty( $update['name'] ) ) {
                $update['name'] = sanitize_text_field( $update['name'] );
            }

            $save = false;

            if ( ! empty( $update['name'] ) && $item['name'] !== $update['name'] ) {
                $item['name'] = $update['name'];
                $save         = true;
            }

            if ( isset( $update['last_used'] ) ) {
                $item['last_used'] = $update['last_used'];
                $save              = true;
            }

            if ( isset( $update['last_ip'] ) ) {
                $item['last_ip'] = $update['last_ip'];
                $save            = true;
            }

            if ( $save ) {
                $saved = static::set_user_application_passwords( $user_id, $passwords );

                if ( ! $saved ) {
                    return new \WP_Error( 'db_error', __( 'Could not save application password.', 'mainwp' ) );
                }

                /**
                 * Fires when an application password is updated.
                 *
                 * @param int   $user_id The user ID.
                 * @param array $item    The updated application password details.
                 * @param array $update  The information to update.
                 */
                do_action( 'mainwp_update_application_password', $user_id, $item, $update );
            }

            return true;
        }

        return new \WP_Error( 'application_password_not_found', static::get_error_message() );
    }

    /**
     * Records that an application password has been used.
     *
     * @param int    $user_id User ID.
     * @param string $uuid    The password's UUID.
     * @return true|\WP_Error True if successful, otherwise a WP_Error instance is returned on error.
     */
    public static function record_application_password_usage( $user_id, $uuid ) {
        $remote_add = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
        $update     = array(
            'last_used' => time(),
            'last_ip'   => $remote_add,
        );

        return static::update_application_password( $user_id, $uuid, $update );
    }

    /**
     * Deletes an application password.
     *
     * @param int    $user_id User ID.
     * @param string $uuid    The password's UUID.
     * @return true|\WP_Error Whether the password was successfully found and deleted, a WP_Error otherwise.
     */
    public static function delete_application_password( $user_id, $uuid ) { // phpcs:ignore -- NOSONAR - complex.
        $passwords = get_user_meta( $user_id, static::USERMETA_KEY_APPLICATION_PASSWORDS, true );
        if ( ! is_array( $passwords ) ) {
            return new \WP_Error( 'application_password_not_found', static::get_error_message() );
        }

        foreach ( $passwords as $key => $item ) {
            if ( $item['uuid'] === $uuid ) {
                unset( $passwords[ $key ] );
                $saved = static::set_user_application_passwords( $user_id, $passwords );

                if ( ! $saved ) {
                    return new \WP_Error( 'db_error', __( 'Could not delete application password.', 'mainwp' ) );
                }

                /**
                 * Fires when an application password is deleted.
                 *
                 * @param int   $user_id The user ID.
                 * @param array $item    The application password that was deleted.
                 */
                do_action( 'mainwp_delete_application_password', $user_id, $item );

                return true;
            }
        }

        return new \WP_Error( 'application_password_not_found', static::get_error_message() );
    }

    /**
     * Sanitizes and then splits a password into smaller chunks.
     *
     * @param string $raw_password The raw application password.
     * @return string The chunked password.
     */
    public static function chunk_password( $raw_password ) {
        $raw_password = preg_replace( '/[^a-z\d]/i', '', $raw_password );

        return trim( chunk_split( $raw_password, 4, ' ' ) );
    }

    /**
     * Deletes all application passwords for the given user.
     *
     * @param int $user_id User ID.
     * @return int| \WP_Error The number of passwords that were deleted or a WP_Error on failure.
     */
    public static function delete_all_application_passwords( $user_id ) {
        $passwords = static::get_user_application_passwords( $user_id );
        $count     = count( $passwords );

        if ( $count > 0 ) {
            $saved = delete_user_meta( $user_id, static::USERMETA_KEY_APPLICATION_PASSWORDS );

            if ( ! $saved ) {
                return new \WP_Error( 'db_error', __( 'Could not delete application passwords.', 'mainwp' ) );
            }

            /**
             * Fires when all application passwords for a user are deleted.
             *
             * @param int $user_id The user ID.
             * @param int $count   The number of passwords that were deleted.
             */
            do_action( 'mainwp_delete_all_application_passwords', $user_id, $count );
        }

        return $count;
    }

    /**
     * Get error message.
     *
     * @return string Error message.
     */
    public static function get_error_message() {
        return esc_html__( 'Could not find an application password with that id.', 'mainwp' );
    }

    /**
     * Determine if user can view Application Passwords.
     *
     * @return bool
     */
    public static function can_access_application_passwords() { // phpcs:ignore -- used outside.
        if (
            static::can_view_manage_application_passwords() ||
            static::can_view_all_application_passwords() ||
            static::can_create_application_passwords() ||
            static::can_delete_application_passwords() ||
            static::can_edit_application_passwords()
        ) {
            return true;
        }
        return false;
    }

    /**
     * Determine if user can manage Application Passwords (create/delete).
     *
     * @return bool
     */
    public static function can_manage_application_passwords() {
        return static::can_view_manage_application_passwords();
    }

    /**
     * Determine if user can add Application Passwords.
     *
     * @return bool
     */
    public static function can_create_application_passwords() {
        if ( static::is_special_admin_user() ) {
            return true;
        }
        return \mainwp_current_user_can( 'rest_api', 'create_application_passwords' );
    }

    /**
     * Check if the current user can editApplication Passwords.
     *
     * @return bool
     */
    public static function can_edit_application_passwords() {
        if ( static::is_special_admin_user() ) {
            return true;
        }
        return \mainwp_current_user_can( 'rest_api', 'edit_application_passwords' );
    }

    /**
     * Determine if user can revoke Application Passwords.
     *
     * @return bool
     */
    public static function can_delete_application_passwords() {
        if ( static::is_special_admin_user() ) {
            return true;
        }
        return \mainwp_current_user_can( 'rest_api', 'delete_application_passwords' );
    }

    /**
     * Check if the current user can view all application passwords.
     *
     * @return bool
     */
    public static function can_view_all_application_passwords() {  // phpcs:ignore -- NOSONAR - complex.
        // 1. Special admin has full view-all.
        if ( static::is_special_admin_user() ) {
            return true;
        }

        // 2. If user is an administrator but NOT special admin, explicitly deny view-all.
        if ( static::is_admin_user_role() ) {
            return false;
        }

        // 3.  Non-admins: only allow if Team Control explicitly grants.
        $explicit_grant = MainWP_System::filter_current_user_permission( false, 'rest_api', 'view_all_application_passwords' );
        return (bool) $explicit_grant;
    }

    /**
     * Check if the current user can view and manage application passwords.
     *
     * @return bool
     */
    public static function can_view_manage_application_passwords() {
        // 1. Special admin can manage.
        if ( static::is_special_admin_user() ) {
            return true;
        }

        // 2. If user is an administrator but NOT special admin, explicitly deny manage-all.
        if ( static::is_admin_user_role() ) {
            return false;
        }

        // 3. Non-admins: only allow if Team Control explicitly grants.
        $explicit_grant = MainWP_System::filter_current_user_permission( false, 'rest_api', 'manage_application_passwords' );
        return (bool) $explicit_grant;
    }

    /**
     * Check if current user is the special admin (administrator role AND ID=1 or email==admin_email).
     *
     * @return bool
     */
    protected static function is_special_admin_user() {  // phpcs:ignore -- NOSONAR
        $user = wp_get_current_user();
        if ( ! ( $user instanceof \WP_User ) || 0 === (int) $user->ID ) {
            return false;
        }
        if ( ! static::is_admin_user_role() ) {
            return false;
        }
        if ( 1 === (int) $user->ID ) {
            return true;
        }
        $admin_email = get_option( 'admin_email' );
        if ( ! empty( $admin_email ) && is_string( $admin_email ) ) {
            return strtolower( (string) $user->user_email ) === strtolower( $admin_email );
        }
        return false;
    }

    /**
     * Check if current user has administrator role.
     *
     * @return bool
     */
    protected static function is_admin_user_role() {
        $user = wp_get_current_user();
        if ( ! ( $user instanceof \WP_User ) ) {
            return false;
        }
        return is_array( $user->roles ) && in_array( 'administrator', $user->roles, true );
    }
}
