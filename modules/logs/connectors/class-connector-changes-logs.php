<?php
/**
 * Module Logs connector class.
 *
 * @package MainWP\Dashboard
 * @since 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_Installer
 *
 * @package MainWP\Dashboard
 */
class Connector_Changes_Logs extends Log_Connector {

    /**
     * Connector name.
     *
     * @var string Connector slug.
     * */
    public $name = 'changes-logs'; // same connector with connector at the file: class-connector-non-mainwp-changes.php.


    /**
     * Specific connector.
     *
     * @var string Specific connector slug.
     * */
    public $connector = 'non-mainwp-changes'; // same connector with connector at the file: class-connector-non-mainwp-changes.php.


    /**
     * Actions names.
     *
     * @var array Actions registered for this connector.
     * */
    public $filters = array(
        'mainwp_sync_site_log_changes_logs',
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
     * Log site changes logs.
     *
     * @action mainwp_sync_site_log_changes_logs.
     * @param mixed  $val Iuput value.
     * @param object $website  Website.
     * @param array  $record Logs data.
     *
     * @return mixed
     */
    public function callback_mainwp_sync_site_log_changes_logs( $val, $website, $record ) { //phpcs:ignore -- NOSONAR - complex method.
        if ( empty( $website ) || ! is_array( $record ) || empty( $record['created'] ) ) {
            return $val;
        }

        $record['connector'] = $this->connector;

        return $this->log_record( $record );
    }
}
