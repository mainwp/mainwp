<?php
/**
 * =======================================
 * MainWP API Backups Handler
 * =======================================
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

use WP_Error;

/**
 * MainWP API Backups Handler
 */
class Api_Backups_Handler {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return instance.
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }


    /**
     * Constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_init', array( &$this, 'admin_init' ) );
    }

    /**
     * Init ajax actions.
     */
    public function admin_init() {
        // Cloudways Ajax.
        do_action( 'mainwp_ajax_add_action', 'mainwp_api_backups_selected_websites', array( &$this, 'ajax_backups_selected_websites' ) );
    }

    /**
     * Bulk Action: action backup.
     *
     * Perform a backup on the selected Child Site.
     *
     * @return void
     */
    public function ajax_backups_selected_websites() {
        Api_Backups_Helper::security_nonce( 'mainwp_api_backups_selected_websites' );
        static::backups_selected_site( true );
        wp_die( esc_html__( 'Error: backup', 'mainwp' ) );
    }

    /**
     * Backups selected site.
     *
     * @param bool $die_output Die to output.
     *
     * @return mixed
     */
    public static function backups_selected_site( $die_output = true ) { //phpcs:ignore -- NOSONAR - complex method.
        $website_id = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( empty( $website_id ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Error: empty site ID.', 'mainwp' ) ) );
        }

        // Grab this from websites.
        $site_options = Api_Backups_Helper::get_website_options( $website_id, array( 'mainwp_3rd_party_instance_id', 'mainwp_3rd_party_app_id', 'mainwp_3rd_party_api' ) );

        if ( ! is_array( $site_options ) ) {
            $site_options = array();
        }

        $server_id  = isset( $site_options['mainwp_3rd_party_instance_id'] ) ? $site_options['mainwp_3rd_party_instance_id'] : null;
        $backup_api = isset( $site_options['mainwp_3rd_party_api'] ) ? strtolower( $site_options['mainwp_3rd_party_api'] ) : null;

        if ( 'cpanel' !== $backup_api && 'plesk' !== $backup_api && 'kinsta' !== $backup_api && ( empty( $server_id ) || empty( $backup_api ) ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Error: Check Backup API settings for the website. Server Id & or Backup API provider not set.', 'mainwp' ) ) );
        }

        $return = true;

        $data             = array();
        $data['provider'] = $backup_api;

        $result = null;

        switch ( $backup_api ) {
            case 'vultr':
                $result = Api_Backups_3rd_Party::vultr_action_create_snapshot( $website_id, $backup_api, $return );
                if ( $die_output ) {
                    $snapshot_response = $result;
                    // Store Last Backup timestamp.
                    if ( empty( $snapshot_response ) ) {
                        $error = new WP_Error( '400', __( 'There was an issue with creating your backup.', 'mainwp' ) );
                        static::send_backups_response( false, $error );
                    } else {
                        static::send_backups_response();
                    }
                }
                break;
            case 'digitalocean':
                $result = Api_Backups_3rd_Party::digitalocean_action_create_backup( $website_id, $return );
                if ( $die_output ) {
                    $api_response = $result;
                    if ( is_array( $api_response ) ) {
                        if ( 'true' === $api_response['status'] ) {
                            static::send_backups_response();
                        } else {
                            static::send_backups_response( false );
                        }
                    }
                    static::send_bulk_backups_error( false, esc_html__( 'Error: DigitalOcean Backup', 'mainwp' ) );
                }
                break;
            case 'linode':
                $result = Api_Backups_3rd_Party::linode_action_create_backup( $website_id, $return );
                if ( $die_output ) {
                    $linode_response = $result;
                    if ( is_object( $linode_response ) ) {
                        // Handle response.
                        if ( isset( $linode_response->errors ) ) {
                            $error = new WP_Error( '400', __( $linode_response->errors['0']->reason, 'Some information' ) );
                            static::send_backups_response( false, $error );
                        } else {
                            static::send_backups_response();
                        }
                    }
                }
                static::send_bulk_backups_error( false, esc_html__( 'Error: Linode Backup', 'mainwp' ) );
                break;
            case 'gridpane':
                $result = Api_Backups_3rd_Party::gridpane_action_create_backup( $website_id, $return );
                if ( $die_output ) {
                    $backup_status = (array) $result;
                    if ( array_key_exists( 'error', $backup_status ) ) {
                        $error = new WP_Error( $backup_status['error']->code, __( $backup_status['error']->message, 'mainwp' ) );
                        if ( is_wp_error( $error ) ) {
                            static::send_backups_response( false, $error->get_error_message() );
                        }
                    }
                    static::send_backups_response();
                    static::send_bulk_backups_error( false, esc_html__( 'Error: GridPane Backup', 'mainwp' ) );
                }

                break;
            case 'cloudways':
                $result = Api_Backups_3rd_Party::cloudways_action_create_backup( $website_id, $return );
                if ( $die_output ) {
                    $api_response = $result;
                    if ( is_array( $api_response ) ) {
                        if ( $api_response['status'] ) {
                            static::send_backups_response( false );
                        } else {
                            // Return success.
                            static::send_backups_response();
                        }
                    }
                    static::send_bulk_backups_error( false, esc_html__( 'Error: Cloudways Backup', 'mainwp' ) );
                }
                break;
            case 'cpanel':
                $result = Api_Backups_3rd_Party::cpanel_action_create_manual_backup( $return, $website_id );
                if ( $die_output ) {
                    $api_response     = $result;
                    $response_decoded = json_decode( $api_response['response'] );

                    if ( is_array( $api_response ) ) {
                        // Handle response.
                        if ( isset( $response_decoded->errors ) ) {
                            $error = $response_decoded->errors['0'];
                            static::send_backups_response( false, $error );
                        } else {
                            $database_backup_response = Api_Backups_3rd_Party::ajax_cpanel_action_create_database_backup( true, $website_id );

                            // If no errors, return true.
                            if ( 'GOOD' === $database_backup_response['result'] ) {
                                static::send_backups_response();
                            } else {
                                $error = 'There was an issue while creating the Database backup. Please check logs and try again.' . $database_backup_response['output'];
                                static::send_backups_response( false, $error );
                            }
                        }
                    }
                    static::send_bulk_backups_error( false, esc_html__( 'Error: cPanel Backup', 'mainwp' ) );
                }
                break;
            case 'plesk':
                $result = Api_Backups_3rd_Party::plesk_action_create_backup( $return, $website_id );

                if ( $die_output ) {
                    $api_response = $result;
                    if ( is_array( $api_response ) ) {
                        if ( 'true' !== $api_response['status'] ) {
                            $response_decoded = is_array( $api_response ) && ! empty( $api_response['response'] ) ? json_decode( $api_response['response'] ) : '';
                            $errors           = ! empty( $response_decoded ) && ! empty( $response_decoded->task->errors ) ? $response_decoded->task->errors : '';
                            static::send_backups_response( false, $errors );
                        } else {
                            // Return success.
                            static::send_backups_response();
                        }
                    }
                    static::send_bulk_backups_error( false, esc_html__( 'Error: Plesk Backup', 'mainwp' ) );
                }
                break;
            case 'kinsta':
                $result = Api_Backups_3rd_Party::kinsta_action_create_backup( $return, $website_id );

                if ( $die_output ) {
                    $api_response = $result;
                    if ( is_array( $api_response ) ) {
                        if ( true !== $api_response['status'] ) {
                            static::send_backups_response( false );
                        } else {
                            // Return success.
                            static::send_backups_response();
                        }
                    }
                    wp_die( esc_html__( 'Error: Kinsta Backup', 'mainwp' ) );
                }
                break;
            default:
                break;
        }

        if ( ! empty( $result ) ) {
            $data['result'] = $result;
        }

        return $data;
    }


    /**
     * Send backups response.
     *
     * @param bool  $success Suceess or not.
     * @param mixed $error error.
     * @return void
     */
    public static function send_backups_response( $success = true, $error = '' ) {
        if ( ! $success ) {
            if ( $error instanceof WP_Error ) {
                $error = $error->get_error_message();
            }
            wp_send_json( array( 'error' => ! empty( $error ) ? esc_html( $error ) : esc_html__( 'Send Backups request failed.', 'mainwp' ) ) );
        } else {
            wp_send_json( array( 'success' => true ) );
        }
    }

    /**
     * Send bulk backups error.
     *
     * @param bool  $success Suceess or not.
     * @param mixed $error error.
     * @return void
     */
    public static function send_bulk_backups_error( $success = true, $error = '' ) {
        if ( isset( $_POST['bulk_backups'] ) && ! empty( $_POST['bulk_backups'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ( ! $success ) {
                if ( $error instanceof WP_Error ) {
                    $error = $error->get_error_message();
                }
                wp_send_json( array( 'error' => ! empty( $error ) ? esc_html( $error ) : esc_html__( 'Send Backups request failed.', 'mainwp' ) ) );
            } else {
                wp_send_json( array( 'success' => true ) );
            }
        } else { // to compatible with previous error messages.
            wp_die( esc_html( $error ) );
        }
    }
}
