<?php
/**
 * MainWP Dashboard Connect Handle
 *
 * This Class handles building/Managing the
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Dashboard_Connect_Handle
 *
 * @package MainWP\Dashboard
 */
class MainWP_Dashboard_Connect_Handle { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Get Class Name
     *
     * @return __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

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
     * Instantiate Hooks for the Settings Page.
     */
    public function init() {
        add_action( 'admin_init', array( $this, 'admin_init' ) );
    }

    /** Run the export_sites method that exports the Child Sites .csv file */
    public function admin_init() { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $_GET['action'] ) && 'download-connect' === wp_unslash( $_GET['action'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'download-connect-nonce' ) && $this->is_zip_archive_supported() ) {
            $this->prepare_and_donwload_connect_helper();
        }
    }

    /**
     * Prepare and download connect helper.
     *
     * @return mixed value.
     */
    private function prepare_and_donwload_connect_helper() {
        $url     = 'https://mainwp.com/download/mainwp-dashboard-connect-v1.2.zip';
        $dirs    = MainWP_System_Utility::get_mainwp_dir( 'dashboard-connect', false );
        $zipfile = $this->download_connect_file_and_attach_rest_keys( $url, $dirs[0] );

        if ( ! is_wp_error( $zipfile ) ) {
            MainWP_System_Handler::instance()->upload_file( $zipfile );
        } else {
            // if failed download then redirect to the page.
            wp_safe_redirect( admin_url( 'admin.php?page=MainWPTools&message=-1' ) );
            exit();
        }
        return false;
    }


    /**
     * Download connect file and attach rest keys.
     *
     * @param  mixed $url url.
     * @param  mixed $destination_folder destination_folder.
     * @return mixed
     */
    private function download_connect_file_and_attach_rest_keys( $url, $destination_folder ) {

        // Include required WordPress libraries.
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; //phpcs:ignore -- NOSONAR - WPCS.
        }

        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );
        add_filter(
            'admin_memory_limit',
            function () {
                return '512M';
            }
        );

        // Download the file to a temporary location.
        $tmp_file = download_url( $url );

        if ( is_wp_error( $tmp_file ) ) {
            // Handle error if the download failed.
            return new \WP_Error( 'download_failed', $tmp_file->get_error_message() );
        }

        // Set up the filesystem.
        if ( ! function_exists( '\WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; //phpcs:ignore -- NOSONAR - WPCS.
        }

        global $wp_filesystem;

        // Initialize the filesystem.
        WP_Filesystem();

        // Define the destination folder (Ensure it ends with a trailing slash).
        $destination_folder = trailingslashit( $destination_folder );
        $tmp_base_dir       = $destination_folder . wp_generate_password( 12, false, false );

        // Ensure destination folder exists or create it.
        if ( ! $wp_filesystem->is_dir( $tmp_base_dir ) ) {
            $wp_filesystem->mkdir( $tmp_base_dir );
        }

        $filename = basename( parse_url( $url, PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

        $from            = $tmp_file;
        $to_connect_file = $tmp_base_dir . '/' . $filename;

        $result_addfile = false;

        if ( $wp_filesystem->move( $from, $to_connect_file ) ) {

            /*
            * When using an environment with shared folders,
            * there is a delay in updating the filesystem's cache.
            *
            * This is a known issue in environments with a VirtualBox provider.
            *
            * A 200ms delay gives time for the filesystem to update its cache,
            * prevents "Operation not permitted", and "No such file or directory" warnings.
            *
            * This delay is used in other projects, including Composer.
            * @link https://github.com/composer/composer/blob/2.5.1/src/Composer/Util/Platform.php#L228-L233
            */
            usleep( 200000 );
            wp_opcache_invalidate_directory( $to_connect_file );
            // Cleanup the temporary file.
            unlink( $from ); //phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

            $result_addfile = $this->put_rest_api_info_into_zip( $to_connect_file, $tmp_base_dir );

        }

        if ( ! $wp_filesystem->exists( $to_connect_file ) ) {
            return new \WP_Error( 'move_file_failed', __( 'Could not create directory.' ), $to_connect_file );
        }

        if ( true !== $result_addfile ) {
            if ( $wp_filesystem->exists( $tmp_base_dir ) ) {
                $wp_filesystem->delete( $tmp_base_dir, true );
            }
            return new \WP_Error( 'add_rest_api_keys_failed', 'Failed to add REST API keys to the ZIP file.' );
        }

        return $to_connect_file; // Success.
    }


    /**
     * Prepare rest api attached data.
     *
     * @return string data.
     */
    public function prepare_rest_api_data_to_attached() {

        $_consumer_key    = MainWP_Rest_Api_Page::mainwp_generate_rand_hash();
        $_consumer_secret = MainWP_Rest_Api_Page::mainwp_generate_rand_hash();

        $consumer_key    = 'ck_' . $_consumer_key;
        $consumer_secret = 'cs_' . $_consumer_secret;
        $desc            = sprintf( esc_html__( 'MainWP Dashboard Connect Key (auto-generated, can be deleted after successfully connecting your sites) %s', 'mainwp' ), gmdate( 'Y-m-d H:i:s' ) );
        $enabled         = 1;
        $scope           = 'read_write';
        $pass            = isset( $_GET['pass'] ) ? sanitize_key( $_GET['pass'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        MainWP_DB::instance()->insert_rest_api_key(
            $consumer_key,
            $consumer_secret,
            $scope,
            $desc,
            $enabled,
            array(
                'key_type' => 1,
                'key_pass' => $pass,
            )
        );
        $token   = $_consumer_secret . '==' . $_consumer_key;
        $api_url = get_site_url() . '/wp-json/mainwp/v2/sites/add';

        return base64_encode( $api_url . '||' . $token . '||' . $pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
    }

    /**
     * Is zip archive supported.
     *
     * @return bool supported.
     */
    public function is_zip_archive_supported() {
        if ( class_exists( '\ZipArchive' ) ) {
            return true;
        }
        return false;
    }


    /**
     * Put rest api info into zip.
     *
     * @param  mixed $zipfile zipfile.
     * @param  mixed $base_dir basedir.
     */
    public function put_rest_api_info_into_zip( $zipfile, $base_dir ) {

        if ( ! wp_zip_file_is_valid( $zipfile ) ) {
            wp_delete_file( $zipfile );
            return false;
        }

        $zip = new \ZipArchive();

        if ( true !== $zip->open( $zipfile ) ) {
            return new \WP_Error( 'unable_to_create_zip', __( 'Unable to open file (archive) for writing.' ) );
        }

        global $wp_filesystem;

        $encoded_string = $this->prepare_rest_api_data_to_attached();

        $temp_keys_file = $base_dir . '/delete-me.txt';

        $wp_filesystem->put_contents( $temp_keys_file, $encoded_string, FS_CHMOD_FILE );

        $relative_path = 'mainwp-dashboard-connect/includes/delete-me.txt';

        $success = false;

        if ( $zip->addFile( $temp_keys_file, $relative_path ) ) {
            $success = true;
        }
        // Close the archive.
        $zip->close();

        $wp_filesystem->delete( $temp_keys_file );

        return $success;
    }
}
