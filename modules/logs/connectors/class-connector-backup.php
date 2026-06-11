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
        'mainwp_backup_uploaded',
        'mainwp_backup_pruned',
        'mainwp_backup_restored',
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
            'uploaded'   => esc_html__( 'Uploaded', 'mainwp' ),
            'pruned'     => esc_html__( 'Pruned', 'mainwp' ),
            'restored'   => esc_html__( 'Restored', 'mainwp' ),
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

    /**
     * Log snapshot uploads to storage destinations.
     *
     * @action mainwp_backup_uploaded
     *
     * @param array $event Upload details: site_id, snapshot_id, destination_id,
     *                     destination_name, provider, objects, bytes.
     */
    public function callback_mainwp_backup_uploaded( $event ) {
        if ( ! is_array( $event ) || empty( $event['site_id'] ) || empty( $event['destination_id'] ) ) {
            return;
        }

        // translators: %s: Storage destination name.
        $message = esc_html__( 'Backup uploaded to storage destination: %s', 'mainwp' );

        $args = array(
            'destination'    => isset( $event['destination_name'] ) ? sanitize_text_field( $event['destination_name'] ) : '',
            'destination_id' => (int) $event['destination_id'],
            'snapshot_id'    => isset( $event['snapshot_id'] ) ? (int) $event['snapshot_id'] : 0,
            'provider'       => isset( $event['provider'] ) ? sanitize_text_field( $event['provider'] ) : '',
            'objects'        => isset( $event['objects'] ) ? (int) $event['objects'] : 0,
            'bytes'          => isset( $event['bytes'] ) ? (int) $event['bytes'] : 0,
        );

        $this->log(
            $message,
            $args,
            (int) $event['site_id'],
            'backup',
            'uploaded',
            1
        );
    }

    /**
     * Log retention pruning of restorable backups.
     *
     * @action mainwp_backup_pruned
     *
     * @param array $event Prune details: site_id, task_id, snapshot_ids,
     *                     reason, size_bytes.
     */
    public function callback_mainwp_backup_pruned( $event ) {
        if ( ! is_array( $event ) || empty( $event['site_id'] ) || empty( $event['snapshot_ids'] ) ) {
            return;
        }

        // translators: %s: Comma-separated snapshot ids.
        $message = esc_html__( 'Backup pruned by retention policy: %s', 'mainwp' );

        $args = array(
            'snapshot_ids' => implode( ',', array_map( 'intval', (array) $event['snapshot_ids'] ) ),
            'task_id'      => isset( $event['task_id'] ) ? (int) $event['task_id'] : 0,
            'reason'       => isset( $event['reason'] ) ? sanitize_text_field( $event['reason'] ) : '',
            'size_bytes'   => isset( $event['size_bytes'] ) ? (int) $event['size_bytes'] : 0,
        );

        $this->log(
            $message,
            $args,
            (int) $event['site_id'],
            'backup',
            'pruned',
            1
        );
    }

    /**
     * Log a completed restore.
     *
     * @action mainwp_backup_restored
     *
     * @param array $event Restore details: site_id, snapshot_id, job_id,
     *                     verification.
     */
    public function callback_mainwp_backup_restored( $event ) {
        if ( ! is_array( $event ) || empty( $event['site_id'] ) ) {
            return;
        }

        // translators: %s: Snapshot id.
        $message = esc_html__( 'Site restored from backup snapshot: %s', 'mainwp' );

        $args = array(
            'snapshot_id' => isset( $event['snapshot_id'] ) ? (int) $event['snapshot_id'] : 0,
            'job_id'      => isset( $event['job_id'] ) ? (int) $event['job_id'] : 0,
        );

        $this->log(
            $message,
            $args,
            (int) $event['site_id'],
            'backup',
            'restored',
            1
        );
    }
}
