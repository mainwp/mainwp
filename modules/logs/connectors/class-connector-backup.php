<?php
/**
 * Module Backup connector class.
 *
 * Records backup artifact downloads in the activity log.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_Backup
 */
class Connector_Backup extends Log_Connector {
    /**
     * Connector slug
     *
     * @var string
     */
    public $name = 'backup';

    /**
     * Actions registered for this connector
     *
     * @var array
     */
    public $actions = array(
        'mainwp_backup_downloaded',
    );

    /**
     * Return translated connector label
     *
     * @return string Translated connector label
     */
    public function get_label() {
        return esc_html__( 'Backup', 'mainwp' );
    }

    /**
     * Return translated action term labels
     *
     * @return array Action terms label translation
     */
    public function get_action_labels() {
        return array(
            'downloaded' => esc_html__( 'Downloaded', 'mainwp' ),
        );
    }

    /**
     * Return translated context labels
     *
     * @return array Context label translations
     */
    public function get_context_labels() {
        return array(
            'backup' => esc_html__( 'Backup', 'mainwp' ),
        );
    }

    /**
     * Log backup artifact downloads.
     *
     * @action mainwp_backup_downloaded
     *
     * @param array $event Download details: site_id, file, size, hash_verified.
     */
    public function callback_mainwp_backup_downloaded( $event ) {
        if ( ! is_array( $event ) || empty( $event['site_id'] ) || empty( $event['file'] ) ) {
            return;
        }

        // translators: %s: Backup file name.
        $message = esc_html__( 'Backup file downloaded: %s', 'mainwp' );

        $args = array(
            'file'          => sanitize_text_field( $event['file'] ),
            'size'          => isset( $event['size'] ) ? (int) $event['size'] : 0,
            'hash_verified' => empty( $event['hash_verified'] ) ? 0 : 1,
        );

        $this->log(
            $message,
            $args,
            (int) $event['site_id'],
            'backup',
            'downloaded',
            1
        );
    }
}
