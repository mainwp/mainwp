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
     * @param int    $now            Unused clock override, kept for signature parity.
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

        $site_id        = 0;
        $child_task_ids = array();

        $pruned_any = false;

        // Leaf first: if the prune is interrupted, the remaining prefix of
        // the chain is still a complete restorable unit.
        foreach ( array_reverse( array_map( 'intval', $candidate['snapshot_ids'] ) ) as $snapshot_id ) {
            $snapshot = $snapshots->get_snapshot_by_id( $snapshot_id );
            if ( null === $snapshot ) {
                continue;
            }
            $site_id = (int) $snapshot->site_id;

            if ( ! $this->remove_local_dir( $snapshot, $result['errors'] ) ) {
                // The artifacts are still on disk, so the snapshot stays
                // restorable and 'complete'; the next sweep retries. The
                // chain stops here like any interruption - pruning its
                // ancestors would orphan this kept leaf. Nothing offsite or
                // in the upload metadata has been touched yet, so a kept
                // snapshot keeps its cloud copies and its resumable record.
                break;
            }

            // The local artifacts are gone, so it is now safe to run the
            // irreversible offsite and metadata steps. enqueue_cloud_deletes
            // reads the upload checkpoints that purge then removes, so the
            // order between them must not swap.
            if ( $delete_cloud_copies ) {
                $this->enqueue_cloud_deletes( $snapshot, $store );
            }

            $store->purge_upload_jobs_for_snapshot( $snapshot_id );

            $child_task_id = $this->child_task_id_for( $snapshot );
            if ( '' !== $child_task_id ) {
                $child_task_ids[] = $child_task_id;
            }

            $snapshots->set_status( $snapshot_id, 'pruned' );

            $pruned_any = true;
            ++$result['snapshots_pruned'];
        }

        $this->notify_child_prune( $site_id, $child_task_ids );

        if ( ! $pruned_any ) {
            return;
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
     * @param object                         $snapshot Snapshot row.
     * @param MainWP_Backup_Upload_Job_Store $store    Job store.
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
     * The child-side task id a snapshot's run used, recovered from its
     * local directory name (snapshot-{child_task_id}). Runs downloaded
     * through the legacy flow use other directory layouts and have no
     * child task row to clean.
     *
     * @param object $snapshot Snapshot row with decoded archives.
     *
     * @return string Child task id, or '' when none applies.
     */
    private function child_task_id_for( object $snapshot ): string {
        $path = '';
        foreach ( (array) $snapshot->archives as $archive ) {
            if ( ! empty( $archive['path'] ) ) {
                $path = (string) $archive['path'];
                break;
            }
        }
        if ( '' === $path && ! empty( $snapshot->manifest_path ) ) {
            $path = (string) $snapshot->manifest_path;
        }
        if ( '' === $path ) {
            return '';
        }

        $dir_name = basename( dirname( $path ) );
        if ( 0 !== strpos( $dir_name, 'snapshot-' ) ) {
            return '';
        }

        return substr( $dir_name, strlen( 'snapshot-' ) );
    }

    /**
     * Tell the child site to clean up after its pruned runs: terminal
     * task rows, artifact files and stale legacy markers. Best effort -
     * an unreachable child never blocks the prune, and the child's
     * three-hour sweep eventually covers what this call would have.
     *
     * @param int      $site_id        Child site id.
     * @param string[] $child_task_ids Child task ids from the pruned snapshots.
     *
     * @return void
     */
    private function notify_child_prune( int $site_id, array $child_task_ids ): void {
        if ( empty( $site_id ) || empty( $child_task_ids ) ) {
            return;
        }

        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $website ) ) {
            return;
        }

        try {
            MainWP_Connect::fetch_url_authed(
                $website,
                'backup_task_prune',
                array(
                    'task_ids' => wp_json_encode( array_values( array_unique( $child_task_ids ) ) ),
                    'sweep'    => 1,
                ),
                false,
                false,
                false
            );
        } catch ( \Throwable $e ) {
            MainWP_Logger::instance()->debug( 'retention :: child prune call skipped :: ' . $e->getMessage() );
        }
    }

    /**
     * Remove a snapshot's local files. Artifacts and manifest delete by
     * name; the containing directory comes down only when it is a
     * per-snapshot dir (snapshot-{child_task_id}) this snapshot owns
     * alone. Legacy and db runs share one site directory with other
     * snapshots' artifacts - those siblings and the directory stay.
     * Paths outside uploads/mainwp are never touched, whatever the row
     * says.
     *
     * A failed artifact deletion returns false so the caller keeps the
     * snapshot's status honest; the directory sweep afterwards is
     * best-effort and only records diagnostics.
     *
     * @param object   $snapshot Snapshot row with decoded archives.
     * @param string[] $errors   Error accumulator, modified in place.
     *
     * @return bool True when every artifact this snapshot names is gone.
     */
    private function remove_local_dir( object $snapshot, array &$errors ): bool {
        $upload = wp_upload_dir();
        $root   = trailingslashit( $upload['basedir'] ) . 'mainwp/';

        $paths = array();
        foreach ( (array) $snapshot->archives as $archive ) {
            if ( ! empty( $archive['path'] ) ) {
                $paths[] = (string) $archive['path'];
            }
        }
        if ( ! empty( $snapshot->manifest_path ) ) {
            $paths[] = (string) $snapshot->manifest_path;
        }

        $clean = true;
        $dirs  = array();
        foreach ( $paths as $path ) {
            $dirs[ dirname( $path ) ] = true;

            $real = realpath( $path );
            if ( false === $real || ! is_file( $real ) ) {
                continue;
            }
            if ( 0 !== strpos( $real, $root ) ) {
                // The named artifact still exists, so the snapshot must not
                // read as pruned and nothing irreversible (cloud deletes,
                // upload-row purge) may run for it. Migrated dashboards
                // whose rows still carry old-tree absolute paths land here.
                $errors[] = 'Refused to remove a file outside the dashboard backup tree: ' . $path;
                $clean    = false;
                continue;
            }
            if ( ! @unlink( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- pruning the snapshot's own artifact; a failure is reported through the return value.
                $errors[] = 'Could not remove local backup file: ' . basename( $real ) . ' (snapshot ' . (int) $snapshot->id . ')';
                $clean    = false;
            }
        }

        if ( ! $clean ) {
            return false;
        }

        foreach ( array_keys( $dirs ) as $dir ) {
            $real = realpath( $dir );
            if ( false === $real || ! is_dir( $real ) ) {
                continue;
            }

            if ( 0 !== strpos( basename( $real ), 'snapshot-' ) ) {
                continue; // A shared directory; other snapshots' artifacts live here.
            }

            if ( 0 !== strpos( trailingslashit( $real ), $root ) ) {
                $errors[] = 'Refused to remove a directory outside the dashboard backup tree: ' . $dir;
                continue;
            }

            foreach ( glob( $real . DIRECTORY_SEPARATOR . '*' ) ?: array() as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty-glob guard.
                if ( is_file( $file ) && ! @unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- pruning the snapshot dir; leftovers are reported below.
                    $errors[] = 'Could not remove leftover file in snapshot directory: ' . basename( $file );
                }
            }

            if ( ! @rmdir( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- pruning the snapshot dir; a leftover directory is advisory once the artifacts are gone.
                $errors[] = 'Could not remove snapshot directory: ' . $dir;
            }
        }

        return true;
    }
}
