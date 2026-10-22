<?php
/**
 * Manage Sites Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Manage_Sites_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Sites_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method check_site()
     *
     * Check to add site.
     *
     * @return mixed send json encode data
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
     */
    public static function check_site() { // phpcs:ignore -- NOSONAR - complex.
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
        $url = urldecode( $url );

        $is_valid = static::is_valid_wp_url( $url );

        if ( ! $is_valid ) { // to fix: valid url to check.
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid URL! Please enter valid URL to the Site URL field.', 'mainwp' ) ) ) );
        }

        $website = MainWP_DB::instance()->get_websites_by_url( $url );
        $ret     = array();

        if ( MainWP_System_Utility::can_edit_website( $website ) ) {
            $ret['response'] = esc_html__( 'ERROR Site is already connected to your MainWP Dashboard.', 'mainwp' );
        } else {
            try {

                $cur_id = MainWP_System_Utility::get_current_wpid();
                if ( empty( $cur_id ) && is_object( $website ) && isset( $website->id ) ) {
                    MainWP_System_Utility::set_current_wpid( $website->id );
                }

                MainWP_Logger::instance()->log_execution_sync( 'init', '', $website );

                $verify_cert    = empty( $_POST['verify_certificate'] ) ? false : intval( $_POST['verify_certificate'] );
                $ssl_version    = empty( $_POST['ssl_version'] ) ? 0 : intval( $_POST['ssl_version'] );
                $force_use_ipv4 = apply_filters( 'mainwp_manage_sites_force_use_ipv4', null, $url );
                $http_user      = ( isset( $_POST['http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['http_user'] ) ) : '' );
                $http_pass      = ( isset( $_POST['http_pass'] ) ? wp_unslash( $_POST['http_pass'] ) : '' );
                $admin          = ( isset( $_POST['admin'] ) ? sanitize_text_field( wp_unslash( $_POST['admin'] ) ) : '' );

                $output = array();

                $visit_track_id = MainWP_Execution_Helper::execute_call_track( 'start_point', $website );

                $information = MainWP_Connect::fetch_url_not_authed(
                    $url,
                    $admin,
                    'stats',
                    null,
                    false,
                    $verify_cert,
                    $http_user,
                    $http_pass,
                    $ssl_version,
                    array(
                        'force_use_ipv4' => $force_use_ipv4,
                    ),
                    $output
                ); // Fetch the stats with the given admin name.

                MainWP_Execution_Helper::execute_call_track( 'end_point', $website, $visit_track_id, 'visit site' );

                if ( isset( $information['wpversion'] ) ) {
                    $ret['response'] = 'OK';
                } elseif ( isset( $information['error'] ) ) {
                    $ret['response']    = 'ERROR ' . MainWP_Utility::esc_content( $information['error'] );
                    $ret['resp_data']   = MainWP_Connect_Failure_Ux::readable_response_from_output( $output );
                    $ret['failure_ux']  = isset( $output['failure_ux'] ) ? $output['failure_ux'] : MainWP_Connect_Failure_Ux::render_html( MainWP_Connect_Failure_Ux::from_exception( null, $output ) );
                } else {
                    $ret['response']   = 'ERROR';
                    $ret['resp_data']  = isset( $output['fetch_data'] ) ? $output['fetch_data'] : MainWP_Connect_Failure_Ux::readable_response_from_output( $output );
                    $ret['failure_ux'] = isset( $output['failure_ux'] ) ? $output['failure_ux'] : MainWP_Connect_Failure_Ux::render_html( MainWP_Connect_Failure_Ux::from_exception( null, $output ) );
                }
            } catch ( MainWP_Exception $e ) {
                $ret['response']   = $e->getMessage();
                $ret['resp_data']  = MainWP_Connect_Failure_Ux::readable_response_from_output( $output, $e );
                $ret['failure_ux'] = MainWP_Connect_Failure_Ux::render_html( MainWP_Connect_Failure_Ux::from_exception( $e, $output ) );
                if ( 'TIMEOUT' === $e->getMessage() ) {
                    $ret['timeout_kind'] = $e->get_message_error_code();
                    $ret['resp_data']    = '';
                }
            }
        }
        $ret['check_me'] = ( isset( $_POST['check_me'] ) ? intval( $_POST['check_me'] ) : null );
        do_action( 'mainwp_check_site_result', $ret, $_POST );
        die( wp_json_encode( $ret ) );
        // phpcs:enable
    }

    /**
     * Method is_valid_wp_url().
     *
     * @param string $url Site url.
     *
     * @return bool Valid or not.
     */
    public static function is_valid_wp_url( $url ) {

        if ( empty( $url ) ) {
            return false;
        }

        $valid = true;

        $info = wp_parse_url( $url );

        if ( is_array( $info ) && ! empty( $info['port'] ) && ( 21 === intval( $info['port'] ) || 22 === intval( $info['port'] ) ) ) { // port 21, 22.
            $valid = false;
        }

        if ( $valid && ( ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) || false !== strpos( $url, '?=' ) ) ) { // to fix: valid url to check.
            $valid = false;
        }

        return $valid;
    }


    /**
     * Method reconnect_site()
     *
     * Try to reconnect to Child Site.
     *
     * @throws \MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::m_reconnect_site()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function reconnect_site() { //phpcs:ignore -- NOSONAR - complexity.
        $siteId = isset( $_POST['siteid'] ) ? intval( $_POST['siteid'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        try {
            if ( MainWP_Utility::ctype_digit( $siteId ) ) {
                $website = MainWP_DB::instance()->get_website_by_id( $siteId );

                $params     = array();
                $sync_first = true;
                if ( is_array( $params ) && ! empty( $_POST['managesites_add_wpadmin'] ) && ! empty( $_POST['managesites_add_adminpwd'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    $params['wpadmin']  = isset( $_POST['managesites_add_wpadmin'] ) ? sanitize_text_field( wp_unslash( $_POST['managesites_add_wpadmin'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    $params['adminpwd']  = isset( $_POST['managesites_add_adminpwd'] ) ?   wp_unslash( $_POST['managesites_add_adminpwd'] ) : ''; //phpcs:ignore -- NOSONAR - requires urlencoded passwd, do not sanitize for specical chars.
                    $sync_first         = false; // reconnect use user's passwd so do not sync first.
                }

                MainWP_Manage_Sites_View::m_reconnect_site( $website, $sync_first, $params );
            } else {
                throw new MainWP_Exception( esc_html__( 'Site could not be connected. Please check the Status page and be sure that all system requirments pass.', 'mainwp' ) );
            }
        } catch ( \Exception $e ) {
            $msg     = $e->getMessage();
            $arr_msg = MainWP_Utility::parse_html_error_message( $msg );

            if ( $e instanceof MainWP_Exception ) {
                $error_code = $e->get_message_error_code();
                if ( 'reconnect_failed' === $error_code ) {
                    die( $error_code ); // phpcs:ignore WordPress.Security.EscapeOutput
                }
            }

            if ( is_array( $arr_msg ) ) {
                die( 'ERROR ' . wp_json_encode( $arr_msg ) ); // phpcs:ignore WordPress.Security.EscapeOutput
            }
            die( 'ERROR ' . $msg ); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        die( esc_html__( 'Site has been reconnected successfully!', 'mainwp' ) );
    }


    /**
     * Method add_site()
     *
     * Add new Child Site.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::add_site()
     */
    public static function add_site() {
        $ret     = array();
        $error   = '';
        $message = '';
        $site_id = 0;
        $output  = array();

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['managesites_add_wpurl'] ) && isset( $_POST['managesites_add_wpadmin'] ) ) {
            // Check if already in DB.
            $website                           = MainWP_DB::instance()->get_websites_by_url( sanitize_text_field( wp_unslash( $_POST['managesites_add_wpurl'] ) ) );
            list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_site( $website, $output );
        }

        $ret['add_me'] = ( isset( $_POST['add_me'] ) ? intval( $_POST['add_me'] ) : null );
        if ( '' !== $error ) {
            $ret['response']   = 'ERROR ' . $error;
            $ret['resp_data']  = isset( $output['fetch_data'] ) ? $output['fetch_data'] : MainWP_Connect_Failure_Ux::readable_response_from_output( $output );
            $ret['failure_ux'] = isset( $output['failure_ux'] ) ? $output['failure_ux'] : MainWP_Connect_Failure_Ux::render_html( MainWP_Connect_Failure_Ux::from_exception( null, $output ) );
            die( wp_json_encode( $ret ) );
        }
        $ret['response'] = $message;
        $ret['siteid']   = $site_id;

        if ( isset( $output['fetch_data'] ) ) {
            $ret['resp_data'] = $output['fetch_data'];
        }
        // phpcs:enable

        if ( 1 === MainWP_DB::instance()->get_websites_count() ) {
            $ret['redirectUrl'] = esc_url( admin_url( 'admin.php?page=managesites' ) );
        }

        die( wp_json_encode( $ret ) );
    }

    /**
     * Method rest_api_add_site().
     *
     * Rest API add website.
     *
     * @param array $data fields array.
     * @param array $output Output array.
     *
     * $data fields.
     * 'url'.
     * 'name'.
     * 'admin'.
     * 'uniqueid'.
     * 'ssl_verify'.
     * 'force_use_ipv4'.
     * 'ssl_version'.
     * 'http_user'.
     * 'http_pass'.
     * 'groupids'.
     *
     * @return mixed Results.
     */
    public static function rest_api_add_site( $data, &$output = array() ) {
        $params['url']                     = isset( $data['url'] ) ? sanitize_text_field( wp_unslash( $data['url'] ) ) : '';
        $params['name']                    = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';
        $params['wpadmin']                 = isset( $data['admin'] ) ? sanitize_text_field( wp_unslash( $data['admin'] ) ) : '';
        $params['adminpwd']                = isset( $data['adminpassword'] ) ? wp_unslash( $data['adminpassword'] ) : '';
        $params['unique_id']               = isset( $data['uniqueid'] ) ? sanitize_text_field( wp_unslash( $data['uniqueid'] ) ) : '';
        $params['ssl_verify']              = empty( $data['ssl_verify'] ) ? false : intval( $data['ssl_verify'] );
        $params['force_use_ipv4']          = ( ! isset( $data['force_use_ipv4'] ) || ( empty( $data['force_use_ipv4'] ) && ( '0' !== $data['force_use_ipv4'] ) ) ? null : intval( $data['force_use_ipv4'] ) );
        $params['http_user']               = isset( $data['http_user'] ) ? sanitize_text_field( wp_unslash( $data['http_user'] ) ) : '';
        $params['http_pass']               = isset( $data['http_pass'] ) ? wp_unslash( $data['http_pass'] ) : '';
        $params['groupids']                = isset( $data['groupids'] ) && ! empty( $data['groupids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $data['groupids'] ) ) ) : array();
        $website                           = MainWP_DB::instance()->get_websites_by_url( $params['url'] );
        list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_wp_site( $website, $params, $output );

        if ( '' !== $error ) {
            return array( 'error' => $error );
        }
        return array(
            'response' => $message,
            'siteid'   => $site_id,
        );
    }

    /**
     * Method apply_plugin_settings()
     *
     * Apply plugin settings.
     */
    public static function apply_plugin_settings() {
        $site_id      = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $ext_dir_slug = isset( $_POST['ext_dir_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['ext_dir_slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( empty( $site_id ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid site ID. Please try again.', 'mainwp' ) ) ) );
        }

        /**
         * Apply plugin settings
         *
         * Fires to apply certain plugin settigns automatically while adding a new site.
         *
         * @param int $site_id Child site ID.
         *
         * @since Unknown
         */
        do_action( 'mainwp_applypluginsettings_' . $ext_dir_slug, $site_id );
        die( wp_json_encode( array( 'error' => esc_html__( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
    }

    /**
     * Method save_note()
     *
     * Save Child Site Note.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
     */
    public static function save_note() {
        if ( isset( $_POST['websiteid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['websiteid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                $note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $esc_note = MainWP_Utility::esc_content( $note );
                if ( ! MainWP_DB_Common::instance()->update_note( $website->id, $esc_note ) ) {
                    wp_die(
                        wp_json_encode(
                            array(
                                'error' => esc_html__( 'The note could not be saved. Please try again.', 'mainwp' ),
                            )
                        )
                    );
                }

                wp_die(
                    wp_json_encode(
                        array(
                            'result'           => 'SUCCESS',
                            'esc_note_content' => $esc_note,
                        )
                    )
                );
            } else {
                die( wp_json_encode( array( 'error' => esc_html__( 'Are you sure this is your website?', 'mainwp' ) ) ) );
            }
        }
        die( wp_json_encode( array( 'undefined_error' => true ) ) );
    }

    /**
     * Method remove_wp_site()
     *
     * Try to remove Child Site.
     *
     * @throws MainWP_Extra_Exception Error message.
     *
     * @param intt $site_id The Site ID.
     *
     * @return mixed result.
     */
    public static function remove_wp_site( $site_id ) {
         // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $site_id ) ) {
            $info  = static::remove_website( intval( $site_id ) );
            $error = is_array( $info ) && isset( $info['error'] ) ? $info['error'] : '';
            if ( 'NOMAINWP' === $error ) {
                $error = esc_html__( 'Be sure to deactivate the child plugin on the child site to avoid potential security issues.', 'mainwp' );
            } elseif ( 'TIMEOUT' === $error ) {
                $timeout_kind = is_array( $info ) && isset( $info['timeout_kind'] ) ? $info['timeout_kind'] : 'operation';
                $error        = MainWP_Error_Helper::get_timeout_error_message( $timeout_kind );
            }
            if ( '' !== $error ) {
                throw new MainWP_Extra_Exception( 'mainwp_remove_site_error', $error ); //phpcs:ignore -- ok.
            } elseif ( is_array( $info ) && isset( $info['deactivated'] ) ) {
                $result = array( 'result' => 'success' );
            } elseif ( is_array( $info ) && isset( $info['removed'] ) ) {
                $result = array( 'result' => 'removed' );
            } else {
                throw new MainWP_Extra_Exception( 'mainwp_remove_site_error', esc_html__( 'Undefined error. Please try again.', 'mainwp' ) );
            }
            return $result;
        }

        return false;
    }

    /**
     * Method remove_site()
     *
     * Try to remove Child Site.
     */
    public static function remove_site() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['id'] ) ) {

            $result = static::remove_website( intval( $_POST['id'] ) );
            $error  = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : '';

            if ( 'NOMAINWP' === $error ) {
                $error = esc_html__( 'Be sure to deactivate the child plugin on the child site to avoid potential security issues.', 'mainwp' );
            } elseif ( 'TIMEOUT' === $error ) {
                $timeout_kind = is_array( $result ) && isset( $result['timeout_kind'] ) ? $result['timeout_kind'] : 'operation';
                $error        = MainWP_Error_Helper::get_timeout_error_message( $timeout_kind );
            }

            $resp = array(
                'removed_site' => ! empty( $result['removed_site'] ),
            );

            if ( '' !== $error ) {
                $resp['error'] = esc_html( $error );
            } elseif ( is_array( $result ) && isset( $result['deactivated'] ) ) {
                $resp['result'] = 'SUCCESS';
            } elseif ( is_array( $result ) && isset( $result['removed'] ) ) {
                $resp['result'] = 'REMOVED';
            } else {
                $resp['undefined_error'] = true;
            }
            die( wp_json_encode( $resp ) );
        }
        // phpcs:enable
        die( wp_json_encode( array( 'result' => 'NOSITE' ) ) );
    }

    /**
     * Method handle remove_site()
     *
     * Try to remove Child Site.
     *
     * @param object|int $site object or Child site ID.
     *
     * @return mixed|false result
     * @throws \MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::delete_website_favicon()
     */
    public static function remove_website( $site ) {  //phpcs:ignore -- NOSONAR - complex.

        if ( is_numeric( $site ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( intval( $site ) );
        } else {
            $website = $site;
        }

        $information = array();

        if ( MainWP_System_Utility::can_edit_website( $website ) ) {

            $is_demo_wp = MainWP_Demo_Handle::get_instance()->is_demo_website( $website );

            /**
             * Deactive child plugin on live site only,
             * DO NOT deactive child on staging site, it will deactive child plugin of source site.
             */
            if ( ! $website->is_staging && ! $is_demo_wp ) {
                try {
                    $information = MainWP_Connect::fetch_url_authed(
                        $website,
                        'deactivate',
                    );
                } catch ( MainWP_Exception $e ) {
                    $information['error'] = $e->getMessage();
                    if ( 'TIMEOUT' === $e->getMessage() ) {
                        $information['timeout_kind'] = $e->get_message_error_code();
                    }
                }
            } else {
                $information['removed'] = true;
            }

            // Delete the cached favicon before the DB row. A missing file or a
            // filesystem error must not stop the site from being removed.
            self::delete_website_favicon( $website );

            // Remove from DB.
            $removed = MainWP_DB::instance()->remove_website( $website->id );

            // Same success test as execute_delete_site(): false is a database
            // error and fires nothing. Zero rows (the site was already gone)
            // still counts as removed and fires the hooks, so this path and
            // the ability path agree and the Sites table drops the row.
            if ( false !== $removed ) {
                $information['removed_site'] = true;

                /**
                 * Delete Child Sites
                 *
                 * Fires after a child site has been removed from MainWP Dashboard.
                 * Demo sites skip the remote deactivate above, then fire the same
                 * hooks so extension rows for the site are removed.
                 *
                 * @param object $website Object containing child site data.
                 *
                 * @since 3.4
                 */
                do_action( 'mainwp_delete_site', $website );

                /**
                 * Fires immediately after a new website is deleted.
                 *
                 * @since 4.5.1.1
                 *
                 * @param object   $website  website data.
                 */
                do_action( 'mainwp_site_deleted', $website );
            }
        }

        return $information ? $information : false;
    }

    /**
     * Delete the cached favicon file for a child site.
     *
     * The file name comes from the stored favi_icon option, never from request
     * input. The file is removed only when that name is a single path segment
     * for this site and the file is a direct child of the MainWP icons
     * directory. A missing file, a rejected name, or a filesystem error is
     * ignored so site removal can continue.
     *
     * @param object $website Child site object.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_icons_dir()
     */
    public static function delete_website_favicon( $website ) {
        try {
            self::delete_stored_website_favicon( $website );
        } catch ( \Throwable $e ) {
            // Favicon cleanup must not block site removal.
            return;
        }
    }

    /**
     * Remove the stored favicon file when the name and path are safe.
     *
     * @param object $website Child site object.
     *
     * @return void
     */
    private static function delete_stored_website_favicon( $website ) {
        if ( ! is_object( $website ) || empty( $website->id ) ) {
            return;
        }

        $favi = MainWP_DB::instance()->get_website_option( $website, 'favi_icon', '' );
        if ( ! is_string( $favi ) || '' === $favi ) {
            return;
        }

        // Same site marker the UI path required: favi-{id}- in the stored name.
        if ( false === strpos( $favi, 'favi-' . $website->id . '-' ) ) {
            return;
        }

        if ( ! self::is_safe_site_favicon_name( $favi ) ) {
            return;
        }

        $has_fs = MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress files system object.
         *
         * @global object $wp_filesystem
         */
        global $wp_filesystem;

        if ( ! $has_fs || ! is_object( $wp_filesystem ) ) {
            return;
        }

        $dirs = MainWP_System_Utility::get_icons_dir();
        if ( empty( $dirs[0] ) || ! is_string( $dirs[0] ) ) {
            return;
        }

        $icons_dir = $dirs[0];
        $target    = $icons_dir . $favi;
        if ( ! self::favicon_target_is_inside_icons_dir( $target, $icons_dir ) ) {
            return;
        }

        // A directory must never be passed to delete(). WordPress defaults to
        // non-recursive, and is_file() keeps a favicon-named directory in place.
        if ( $wp_filesystem->is_file( $target ) ) {
            $wp_filesystem->delete( $target );
        }
    }

    /**
     * Whether a stored favicon name is one path segment with no traversal.
     *
     * @param string $favi Stored favicon file name.
     *
     * @return bool
     */
    private static function is_safe_site_favicon_name( $favi ) {
        if ( ! is_string( $favi ) || '' === $favi ) {
            return false;
        }

        if ( basename( $favi ) !== $favi ) {
            return false;
        }

        if ( false !== strpos( $favi, '/' ) || false !== strpos( $favi, '\\' ) || false !== strpos( $favi, "\0" ) ) {
            return false;
        }

        return 0 === validate_file( $favi );
    }

    /**
     * Whether $target is a direct file inside the icons directory.
     *
     * The joined path must stay inside the directory. When the file exists
     * locally, its resolved path must too, so a symlink cannot point outside.
     *
     * @param string $target    Candidate file path.
     * @param string $icons_dir Icons directory.
     *
     * @return bool
     */
    private static function favicon_target_is_inside_icons_dir( $target, $icons_dir ) {
        if ( ! self::path_is_direct_child( $target, $icons_dir ) ) {
            return false;
        }

        $real_dir = realpath( $icons_dir );
        if ( false === $real_dir || ! is_string( $real_dir ) ) {
            return true;
        }

        $real_file = realpath( $target );
        if ( false === $real_file || ! is_string( $real_file ) ) {
            // Missing locally. Still allow a non-local filesystem (FTP) to
            // delete a safe direct child. A local path that exists but does
            // not resolve is refused.
            if ( file_exists( $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists -- local containment check.
                return false;
            }
            return true;
        }

        return self::path_is_direct_child( $real_file, $real_dir );
    }

    /**
     * Whether $path is a direct child of $directory.
     *
     * @param string $path      File path.
     * @param string $directory Parent directory.
     *
     * @return bool
     */
    private static function path_is_direct_child( $path, $directory ) {
        if ( ! is_string( $path ) || ! is_string( $directory ) || '' === $path || '' === $directory ) {
            return false;
        }

        $directory = wp_normalize_path( untrailingslashit( $directory ) );
        $path      = wp_normalize_path( $path );
        $prefix    = trailingslashit( $directory );

        if ( 0 !== strpos( $path, $prefix ) ) {
            return false;
        }

        $remainder = substr( $path, strlen( $prefix ) );
        return '' !== $remainder && false === strpos( $remainder, '/' );
    }

    /**
     * Method update_child_site_value()
     *
     * Update Child Site ID.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function update_child_site_value() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['site_id'] ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['site_id'] ) );
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                $error    = '';
                $uniqueId = isset( $_POST['unique_id'] ) ? sanitize_text_field( wp_unslash( $_POST['unique_id'] ) ) : '';
                try {
                    $information = MainWP_Connect::fetch_url_authed( $website, 'update_values', array( 'uniqueId' => $uniqueId ) );
                } catch ( MainWP_Exception $e ) {
                    $error = $e->getMessage();
                }

                if ( '' !== $error ) {
                    die( wp_json_encode( array( 'error' => $error ) ) );
                } elseif ( isset( $information['result'] ) && ( 'ok' === $information['result'] ) ) {
                    die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
                } else {
                    die( wp_json_encode( array( 'undefined_error' => true ) ) );
                }
            }
        }
        // phpcs:enable
        die( wp_json_encode( array( 'error' => 'NO_SIDE_ID' ) ) );
    }
}
