<?php
/**
 * MainWP Backup Retention Executor.
 *
 * Carries out a prune list from the retention policy. Per snapshot,
 * leaf first so a crash mid-chain never leaves a child without its
 * base: collect the remote keys from the upload checkpoints into one
 * resumable cloud delete job per destination (when the task deletes
 * cloud copies), hard-delete the upload rows, remove the local snapshot
 * directory, and flip the row to pruned - kept for audit. The logging
 * action fires once per pruned unit. Dry-run reports the same list
 * without mutating anything, and a time budget bounds each tick while
 * guaranteeing progress on at least one unit.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Retention_Executor
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Retention_Executor { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Default per-tick work budget in seconds.
     *
     * @var int
     */
    const DEFAULT_BUDGET_SECONDS = 120;

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Retention_Executor or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Retention_Executor
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Carry out a prune list for one task.
     *
     * @param array  $candidates     Prune candidates from the policy, oldest
     *                               first, each carrying snapshot_ids (base
     *                               first), reason and size_bytes.
     * @param object $task           Task row; delete_cloud_copies decides
     *                               whether remote objects are deleted too.
     * @param bool   $dry_run        Report without mutating.
     * @param int    $budget_seconds Work budget for this tick.
     *
     * @return array Result: pruned and skipped candidate counts,
     *               snapshots_pruned, errors, dry_run, candidates report.
     */
    public function run_prune( array $candidates, object $task, bool $dry_run = false, int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $now = null ): array {
        unset( $now );

        $deadline = microtime( true ) + $budget_seconds;

        $result = array(
            'pruned'           => 0,
            'snapshots_pruned' => 0,
            'skipped'          => 0,
            'errors'           => array(),
            'dry_run'          => $dry_run,
            'candidates'       => array(),
        );

        foreach ( $candidates as $position => $candidate ) {
            // Always make progress on the first candidate; check the budget
            // before each further one.
            if ( $position > 0 && microtime( true ) >= $deadline ) {
                $result['skipped'] = count( $candidates ) - $position;
                break;
            }

            $result['candidates'][] = array(
                'snapshot_ids' => array_map( 'intval', $candidate['snapshot_ids'] ),
                'reason'       => (string) $candidate['reason'],
            );

            if ( ! $dry_run ) {
                $this->prune_candidate( $candidate, $task, $result );
            }

            ++$result['pruned'];
        }

        return $result;
    }

    /**
     * Prune one candidate unit, leaf first.
     *
     * @param array  $candidate Candidate: snapshot_ids (base first), reason, size_bytes.
     * @param object $task      Task row.
     * @param array  $result    Result accumulator, modified in place.
     *
     * @return void
     */
    private function prune_candidate( array $candidate, object $task, array &$result ): void {
        $snapshots           = MainWP_Backup_Snapshots::instance();
        $store               = MainWP_Backup_Upload_Job_Store::instance();
        $delete_cloud_copies = ! empty( $task->delete_cloud_copies );

        $site_id = 0;

        // Leaf first: if the prune is interrupted, the remaining prefix of
        // the chain is still a complete restorable unit.
        foreach ( array_reverse( array_map( 'intval', $candidate['snapshot_ids'] ) ) as $snapshot_id ) {
            $snapshot = $snapshots->get_snapshot_by_id( $snapshot_id );
            if ( null === $snapshot ) {
                continue;
            }
            $site_id = (int) $snapshot->site_id;

            if ( $delete_cloud_copies ) {
                $this->enqueue_cloud_deletes( $snapshot, $store );
            }

            $store->purge_upload_jobs_for_snapshot( $snapshot_id );

            $this->remove_local_dir( $snapshot, $result['errors'] );

            $snapshots->set_status( $snapshot_id, 'pruned' );

            ++$result['snapshots_pruned'];
        }

        /**
         * Fires after a restorable unit is pruned by retention.
         *
         * The logs connector records the deletion; the cloud delete
         * driver independently drains the enqueued delete jobs.
         *
         * @param array $event Prune details: site_id, task_id, snapshot_ids,
         *                     reason, size_bytes.
         */
        do_action(
            'mainwp_backup_pruned',
            array(
                'site_id'      => $site_id,
                'task_id'      => (int) $task->id,
                'snapshot_ids' => array_map( 'intval', $candidate['snapshot_ids'] ),
                'reason'       => (string) $candidate['reason'],
                'size_bytes'   => isset( $candidate['size_bytes'] ) ? (int) $candidate['size_bytes'] : 0,
            )
        );
    }

    /**
     * Collect a snapshot's remote keys from its upload checkpoints and
     * enqueue one resumable delete job per destination. The keys seed the
     * resumable walk; the delete driver's final prefix sweep catches
     * anything a partial upload left behind that no checkpoint mentions.
     *
     * @param object                        $snapshot Snapshot row.
     * @param MainWP_Backup_Upload_Job_Store $store   Job store.
     *
     * @return void
     */
    private function enqueue_cloud_deletes( object $snapshot, MainWP_Backup_Upload_Job_Store $store ): void {
        $prefix = (int) $snapshot->site_id . '/' . (int) $snapshot->id . '/';

        foreach ( $store->get_jobs_for_snapshot( (int) $snapshot->id, MainWP_Backup_Upload_Job_Store::KIND_UPLOAD ) as $upload_job ) {
            $keys = array();

            $artifacts = isset( $upload_job->checkpoint['artifacts'] ) && is_array( $upload_job->checkpoint['artifacts'] ) ? $upload_job->checkpoint['artifacts'] : array();
            foreach ( $artifacts as $artifact ) {
                if ( isset( $artifact['mode'] ) && 'chunked' === $artifact['mode'] && ! empty( $artifact['chunks'] ) && is_array( $artifact['chunks'] ) ) {
                    foreach ( $artifact['chunks'] as $chunk ) {
                        if ( ! empty( $chunk['hash'] ) ) {
                            $keys[] = $prefix . 'chunks/' . $chunk['hash'] . '.bin';
                        }
                    }
                } elseif ( ! empty( $artifact['file'] ) ) {
                    $keys[] = $prefix . 'files/' . $artifact['file'];
                }
            }

            $keys[] = $prefix . 'manifest.json';

            $store->enqueue_delete_job(
                (int) $snapshot->id,
                (int) $upload_job->destination_id,
                array(
                    'prefix' => $prefix,
                    'keys'   => array_map(
                        static function ( $key ) {
                            return array(
                                'key'     => $key,
                                'deleted' => false,
                            );
                        },
                        array_values( array_unique( $keys ) )
                    ),
                    'cursor' => 0,
                    'swept'  => false,
                )
            );
        }
    }

    /**
     * Remove a snapshot's local directory: every artifact and manifest
     * lives in one snapshot dir under the dashboard uploads tree. Paths
     * outside uploads/mainwp are never touched, whatever the row says.
     *
     * @param object   $snapshot Snapshot row with decoded archives.
     * @param string[] $errors   Error accumulator, modified in place.
     *
     * @return void
     */
    private function remove_local_dir( object $snapshot, array &$errors ): void {
        $dirs = array();

        foreach ( (array) $snapshot->archives as $archive ) {
            if ( ! empty( $archive['path'] ) ) {
                $dirs[ dirname( (string) $archive['path'] ) ] = true;
            }
        }
        if ( ! empty( $snapshot->manifest_path ) ) {
            $dirs[ dirname( (string) $snapshot->manifest_path ) ] = true;
        }

        $upload = wp_upload_dir();
        $root   = trailingslashit( $upload['basedir'] ) . 'mainwp/';

        foreach ( array_keys( $dirs ) as $dir ) {
            $real = realpath( $dir );
            if ( false === $real || ! is_dir( $real ) ) {
                continue;
            }

            if ( 0 !== strpos( trailingslashit( $real ), $root ) ) {
                $errors[] = 'Refused to remove a directory outside the dashboard backup tree: ' . $dir;
                continue;
            }

            foreach ( glob( $real . DIRECTORY_SEPARATOR . '*' ) ?: array() as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty-glob guard.
                if ( is_file( $file ) ) {
                    unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- pruning the snapshot dir.
                }
            }

            if ( ! rmdir( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- pruning the snapshot dir.
                $errors[] = 'Could not remove snapshot directory: ' . $dir;
            }
        }
    }
}
