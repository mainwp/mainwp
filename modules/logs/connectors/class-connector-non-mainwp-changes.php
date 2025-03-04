<?php
/**
 * Module Logs Installer connector class.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_Installer
 *
 * @package MainWP\Dashboard
 */
class Connector_Non_Mainwp_Changes extends Log_Connector {

    /**
     * Connector name.
     *
     * @var string Connector slug.
     * */
    public $name = 'non-mainwp-changes';

    /**
     * Actions names.
     *
     * @var array Actions registered for this connector.
     * */
    public $actions = array(
        'mainwp_sync_site_log_install_actions',
    );


    /**
     * Return translated connector label.
     *
     * @return string Translated connector label.
     */
    public function get_label() {
        return esc_html__( 'Non-MainWP Changes', 'mainwp' );
    }

    /**
     * Return translated action labels.
     *
     * @return array Action label translations.
     */
    public function get_action_labels() {
        return array(
            'install'    => esc_html__( 'Installed', 'mainwp' ),
            'activate'   => esc_html__( 'Activated', 'mainwp' ),
            'deactivate' => esc_html__( 'Deactivated', 'mainwp' ),
            'delete'     => esc_html__( 'Deleted', 'mainwp' ),
            'updated'    => esc_html__( 'Updated', 'mainwp' ),
        );
    }

    /**
     * Return translated context labels.
     *
     * @return array Context label translations.
     */
    public function get_context_labels() {
        return array(
            'plugins'     => esc_html__( 'Plugins', 'mainwp' ),
            'themes'      => esc_html__( 'Themes', 'mainwp' ),
            'core'        => esc_html__( 'Core', 'mainwp' ),
            'translation' => esc_html__( 'Translation', 'mainwp' ),
        );
    }

    /**
     * Log site action.
     *
     * @action mainwp_sync_site_log_install_actions.
     *
     * @param object $website  website.
     * @param array  $record meta data.
     * @param string $object_id Object Id.
     */
    public function callback_mainwp_sync_site_log_install_actions( $website, $record, $object_id ) { //phpcs:ignore -- NOSONAR - complex method.

        if ( empty( $website ) || empty( $object_id ) || ! is_array( $record ) ) {
            return;
        }

        $record['context']   = 'installer';
        $record['connector'] = $this->name;

        $success = $this->log_record( $record );
        if ( $success ) {
            MainWP_DB::instance()->update_website_option( $website, 'non_mainwp_action_last_object_id', $record['created'] );
        }
    }
}
