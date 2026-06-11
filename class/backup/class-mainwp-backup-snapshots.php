<?php
/**
 * MainWP Backup Snapshots.
 *
 * Durable per-run backup records. Each completed backup run is recorded
 * as a snapshot row carrying the run type, the chain parent, where the
 * downloaded artifacts and manifest live locally, and a status used by
 * retention. Incremental backups resolve their base through these rows.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Snapshots
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Snapshots extends MainWP_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, table identifiers cannot be prepared.

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Snapshots or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Snapshots
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Record a completed backup run as a snapshot row.
     *
     * @param array $data {
     *     Snapshot fields.
     *
     *     @type int      $task_id       Scheduled task id.
     *     @type int      $site_id       Child site id.
     *     @type string   $type          What the run produced: 'full' or 'incremental'.
     *     @type int|null $parent_id     Parent snapshot id, null for a base full.
     *     @type array    $archives      Downloaded artifact list: file, size, role per entry.
     *     @type string   $manifest_path Local path of the downloaded file manifest.
     * }
     *
     * @return int Snapshot id, 0 on failure.
     */
    public function record_snapshot( array $data ): int {
        $row = array(
            'task_id'       => isset( $data['task_id'] ) ? (int) $data['task_id'] : 0,
            'site_id'       => isset( $data['site_id'] ) ? (int) $data['site_id'] : 0,
            'type'          => isset( $data['type'] ) ? (string) $data['type'] : 'full',
            'created'       => time(),
            'archives'      => isset( $data['archives'] ) && is_array( $data['archives'] ) ? wp_json_encode( $data['archives'] ) : '[]',
            'manifest_path' => isset( $data['manifest_path'] ) ? (string) $data['manifest_path'] : '',
            'status'        => 'complete',
        );

        $format = array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' );

        if ( ! empty( $data['parent_id'] ) ) {
            $row['parent_id'] = (int) $data['parent_id'];
            $format[]         = '%d';
        }

        $inserted = $this->wpdb->insert( $this->table_name( 'wp_backup_snapshot' ), $row, $format );

        if ( ! $inserted ) {
            return 0;
        }

        $snapshot_id = (int) $this->wpdb->insert_id;

        /**
         * Fires after a completed backup run is recorded as a snapshot.
         *
         * The cloud upload driver listens here to enqueue upload jobs for
         * the task's configured storage destinations.
         *
         * @param int $snapshot_id Snapshot id.
         * @param int $task_id     Scheduled task id.
         * @param int $site_id     Child site id.
         */
        do_action( 'mainwp_backup_snapshot_recorded', $snapshot_id, $row['task_id'], $row['site_id'] );

        return $snapshot_id;
    }

    /**
     * Get a snapshot row by id, with the archives list decoded.
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return object|null Snapshot row or null when not found.
     */
    public function get_snapshot_by_id( int $snapshot_id ): ?object {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_snapshot' ) . ' WHERE id = %d',
                $snapshot_id
            )
        );
        return $this->decode_row( $row );
    }

    /**
     * Get the newest complete snapshot for a site and task - the base the
     * next incremental run diffs against. Null means no usable base exists
     * and the caller runs a full backup.
     *
     * @param int $site_id Child site id.
     * @param int $task_id Scheduled task id.
     *
     * @return object|null Snapshot row or null when none exists.
     */
    public function get_latest_complete( int $site_id, int $task_id ): ?object {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_snapshot' ) . " WHERE site_id = %d AND task_id = %d AND status = 'complete' ORDER BY id DESC LIMIT 1",
                $site_id,
                $task_id
            )
        );
        return $this->decode_row( $row );
    }

    /**
     * Walk a snapshot chain from a leaf back through its parents.
     *
     * Returns the chain base first so a restore can apply rows in order.
     * A parent link pointing at a missing row ends the walk at the break;
     * the caller treats a chain that does not start with a full backup as
     * having no usable base.
     *
     * @param int $snapshot_id Leaf snapshot id.
     *
     * @return object[] Snapshot rows, base first.
     */
    public function get_chain( int $snapshot_id ): array {
        $chain   = array();
        $current = $snapshot_id;
        $seen    = array();

        while ( $current && ! isset( $seen[ $current ] ) ) {
            $seen[ $current ] = true;

            $row = $this->get_snapshot_by_id( $current );
            if ( null === $row ) {
                break;
            }

            array_unshift( $chain, $row );
            $current = empty( $row->parent_id ) ? 0 : (int) $row->parent_id;
        }

        return $chain;
    }

    /**
     * Update a snapshot's status.
     *
     * @param int    $snapshot_id Snapshot id.
     * @param string $status      New status, e.g. 'pruned'.
     *
     * @return void
     */
    public function set_status( int $snapshot_id, string $status ): void {
        $this->wpdb->update(
            $this->table_name( 'wp_backup_snapshot' ),
            array( 'status' => $status ),
            array( 'id' => $snapshot_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Decode the archives JSON on a snapshot row and normalize parent_id.
     *
     * @param object|null $row Raw snapshot row.
     *
     * @return object|null Row with archives as an array, or null.
     */
    private function decode_row( ?object $row ): ?object {
        if ( null === $row ) {
            return null;
        }

        $decoded        = json_decode( (string) $row->archives, true );
        $row->archives  = is_array( $decoded ) ? $decoded : array();
        $row->parent_id = isset( $row->parent_id ) && null !== $row->parent_id ? (int) $row->parent_id : null;

        return $row;
    }
}
