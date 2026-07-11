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
     * Purge every restorable unit a deleted task owns, across all of its
     * sites. Task deletion is not the retention policy's prune-worthy
     * subset - there is nothing left to keep, so every complete unit
     * get_units_for_site_task() reports becomes its own candidate and
     * runs through the same leaf-first prune_candidate() sequence
     * retention uses: local removal, cloud deletes when requested, the
     * upload-row purge, then the status flip.
     *
     * The caller is responsible for authorizing the delete against the
     * task's historical sites first (remove_backup_result does): the purge
     * here is deliberately full-history so an authorized delete leaves
     * nothing orphaned - snapshot rows and their artifacts for every site
     * the task ever ran on are removed together, since retention only ever
     * revisits sites through a live task row.
     *
     * @param int  $task_id             Task id being deleted or removed.
     * @param bool $delete_cloud_copies The task's delete_cloud_copies
     *                                  column value; controls whether
     *                                  remote objects are queued for
     *                                  deletion too.
     *
     * @return array Result: pruned (unit) count, snapshots_pruned and errors.
     */
    public function purge_snapshots_for_task( int $task_id, bool $delete_cloud_copies ): array {
        $task = (object) array(
            'id'                  => $task_id,
            'delete_cloud_copies' => $delete_cloud_copies,
        );

        $result = array(
            'pruned'           => 0,
            'snapshots_pruned' => 0,
            'errors'           => array(),
        );

        $query = MainWP_Backup_Retention_Query::instance();

        foreach ( $query->list_site_ids_for_task( $task_id ) as $site_id ) {
            foreach ( $query->get_units_for_site_task( $site_id, $task_id ) as $unit ) {
                $snapshot_ids = array_map( 'intval', array_column( $unit['snapshots'], 'id' ) );
                // prune_candidate() reverses this list to walk leaf first;
                // it must arrive base first (ascending id), the same
                // ordering the policy's own candidates carry.
                sort( $snapshot_ids, SORT_NUMERIC );

                $candidate = array(
                    'snapshot_ids' => $snapshot_ids,
                    'reason'       => 'task_deleted',
                    'size_bytes'   => (int) $unit['size_bytes'],
                );

                $this->prune_candidate( $candidate, $task, $result );

                ++$result['pruned'];
            }
        }

        return $result;
    }

    /**
     * Clean up one site's backups on demand: sweep orphaned snapshot
     * directories, then prune the on-demand task down to a keep-count.
     *
     * The two passes cover the two ways a dashboard's backup tree grows
     * without bound. Interrupted runs leave snapshot-* directories that
     * never gained a complete row, so neither retention nor the task
     * purge ever revisits them - the orphan sweep removes those. And
     * retention for completed on-demand snapshots normally drains on
     * WP-Cron, which a low-traffic dashboard may never fire - the prune
     * pass applies the same leaf-first prune synchronously. Chains whose
     * parent link is missing (not restorable, never chosen by the
     * policy) are pruned regardless of the keep-count: nothing can
     * restore them, so they are only holding disk.
     *
     * @param int      $site_id            Child site id.
     * @param int|null $keep               Complete restorable units to keep for the
     *                                     on-demand task; null uses the task's own
     *                                     retention count.
     * @param int      $orphan_min_age     Seconds an orphaned directory must have
     *                                     been idle before it is swept; protects a
     *                                     run that is still writing.
     *
     * @return array Result: orphan_dirs_removed, pruned (unit count),
     *               snapshots_pruned and errors.
     */
    public function cleanup_site_backups( int $site_id, ?int $keep = null, int $orphan_min_age = 6 * HOUR_IN_SECONDS ): array {
        $result = array(
            'orphan_dirs_removed' => 0,
            'pruned'              => 0,
            'snapshots_pruned'    => 0,
            'errors'              => array(),
        );

        $this->purge_orphaned_snapshot_dirs( $site_id, $orphan_min_age, $result );
        $this->prune_manual_units_to_count( $site_id, $keep, $result );

        return $result;
    }

    /**
     * Sweep snapshot-* directories under a site's backup tree that no
     * complete snapshot row claims.
     *
     * A run that dies between staging files and record_snapshot() leaves
     * its directory rowless forever: retention only walks rows, and the
     * legacy download sweep skips everything it cannot count. Protection
     * is directory-level - any complete row's artifact keeps its whole
     * directory - and compared on both the literal and resolved forms so
     * a symlinked uploads tree cannot cause a false orphan. A freshness
     * window keeps the sweep off a directory an in-flight run touched
     * recently; live runs write continuously, so an idle directory past
     * the window has no writer left.
     *
     * @param int   $site_id Child site id.
     * @param int   $min_age Seconds since the directory's newest write.
     * @param array $result  Result accumulator, modified in place.
     *
     * @return void
     */
    private function purge_orphaned_snapshot_dirs( int $site_id, int $min_age, array &$result ): void {
        $upload = wp_upload_dir();
        $root   = trailingslashit( $upload['basedir'] ) . 'mainwp/0/' . $site_id . '/';

        $real_root = realpath( $root );
        if ( false === $real_root ) {
            return; // No backup tree for this site.
        }
        $real_root = trailingslashit( $real_root );

        $protected_dirs = array();
        foreach ( array_keys( MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( $site_id ) ) as $path ) {
            $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( dirname( $path ) ) ] = true;
        }

        foreach ( glob( $root . 'snapshot-*', GLOB_ONLYDIR ) ?: array() as $dir ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty-glob guard.
            $real = realpath( $dir );
            if ( false === $real || 0 !== strpos( trailingslashit( $real ), $real_root ) ) {
                continue; // A symlinked or escaping entry is not ours to sweep.
            }
            if ( isset( $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( $dir ) ] )
                || isset( $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( $real ) ] ) ) {
                continue; // A complete snapshot's artifacts live here.
            }

            $files  = glob( $real . DIRECTORY_SEPARATOR . '*' ) ?: array(); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty-glob guard.
            $newest = (int) @filemtime( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- racing a concurrent delete is benign.
            foreach ( $files as $file ) {
                $newest = max( $newest, (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- racing a concurrent delete is benign.
            }
            if ( time() - $newest < $min_age ) {
                continue; // Recently written; a run may still own it.
            }

            $clean = true;
            foreach ( $files as $file ) {
                if ( is_file( $file ) && ! @unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- sweeping an orphaned dir; failures are reported through errors.
                    $errors_msg         = 'Could not remove orphaned backup file: ' . basename( $file );
                    $result['errors'][] = $errors_msg;
                    $clean              = false;
                }
            }
            if ( $clean && @rmdir( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- sweeping an orphaned dir; a leftover empty dir is advisory.
                ++$result['orphan_dirs_removed'];
            }
        }
    }

    /**
     * Prune the site's on-demand task down to a keep-count, newest first,
     * synchronously.
     *
     * Broken units (a chain whose parent link is missing) are pruned
     * unconditionally - the policy never selects them because it cannot
     * restore them, which is exactly why they otherwise sit forever.
     * Complete units beyond the keep-count prune oldest-first through
     * the same prune_candidate() sequence retention uses, so cloud
     * copies and upload metadata follow the task's own settings.
     *
     * @param int      $site_id Child site id.
     * @param int|null $keep    Units to keep; null uses the task's retention
     *                          count (falling back to the on-demand default).
     * @param array    $result  Result accumulator, modified in place.
     *
     * @return void
     */
    private function prune_manual_units_to_count( int $site_id, ?int $keep, array &$result ): void {
        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        if ( empty( $task ) ) {
            return; // The site has never run an on-demand backup.
        }

        if ( null === $keep ) {
            $keep = ! empty( $task->retention_count ) ? (int) $task->retention_count : MainWP_Backup_Settings::get_on_demand_retention_count();
        }
        $keep = max( 1, $keep );

        $units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $site_id, (int) $task->id );

        $prunable = array();
        $complete = array();
        foreach ( $units as $unit ) {
            if ( empty( $unit['is_complete'] ) ) {
                $prunable[] = array( $unit, 'broken_chain' );
                continue;
            }
            $complete[] = $unit;
        }

        usort( $complete, static fn( $a, $b ) => $b['newest_created'] <=> $a['newest_created'] );
        foreach ( array_slice( $complete, $keep ) as $unit ) {
            $prunable[] = array( $unit, 'manual_cleanup' );
        }

        foreach ( $prunable as list( $unit, $reason ) ) {
            $snapshot_ids = array_map( 'intval', array_column( $unit['snapshots'], 'id' ) );
            sort( $snapshot_ids, SORT_NUMERIC );

            $this->prune_candidate(
                array(
                    'snapshot_ids' => $snapshot_ids,
                    'reason'       => $reason,
                    'size_bytes'   => (int) $unit['size_bytes'],
                ),
                $task,
                $result
            );

            ++$result['pruned'];
        }
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
        // A restore mid-flight stages its artifacts from this chain: pruning
        // any member destroys the recovery source, so the whole unit is held
        // for this sweep and revisited once the restore settles. This also
        // holds the task-delete purge - keeping a deleted task's unit
        // alive beats stranding a running restore without its source.
        if ( MainWP_Backup_Restore_Job_Store::instance()->has_active_job_for_snapshots( (array) $candidate['snapshot_ids'] ) ) {
            $result['errors'][] = sprintf(
                'Snapshots %s: a restore in progress references this backup; it is kept and the prune will retry on a later sweep.',
                implode( ',', array_map( 'intval', (array) $candidate['snapshot_ids'] ) )
            );
            return;
        }

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
            // order between them must not swap - and a failed enqueue must
            // stop BOTH: purging the upload rows destroys the only record
            // of the remote keys, and marking the row pruned would end the
            // retries, orphaning the cloud copy forever. The snapshot stays
            // 'complete' with its upload metadata so the next sweep retries,
            // and the chain stops here like any interruption.
            if ( $delete_cloud_copies && ! $this->enqueue_cloud_deletes( $snapshot, $store ) ) {
                $result['errors'][] = sprintf( 'Snapshot %d: a cloud delete job could not be queued; the prune will retry on the next sweep.', $snapshot_id );
                break;
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
     * @return bool True when every destination's delete job is queued.
     */
    private function enqueue_cloud_deletes( object $snapshot, MainWP_Backup_Upload_Job_Store $store ): bool {
        $prefix     = (int) $snapshot->site_id . '/' . (int) $snapshot->id . '/';
        $all_queued = true;

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

            $queued = $store->enqueue_delete_job(
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
            if ( ! $queued ) {
                $all_queued = false;
            }
        }

        return $all_queued;
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
     * Every native-backup writer (MainWP_System_Utility::get_mainwp_specific_dir())
     * lands artifacts under mainwp/0/{site_id}/, so a deletion is confined
     * to the snapshot's own site subtree, not merely the shared mainwp
     * root - a row whose path was tampered with (or copied from another
     * site's row) can otherwise reach across sites. Paths outside that
     * subtree are never touched, whatever the row says.
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
        $root   = trailingslashit( $upload['basedir'] ) . 'mainwp/0/' . (int) $snapshot->site_id . '/';

        // Canonicalize like purge_orphaned_snapshot_dirs() does: the paths
        // below are realpath()ed, so a symlinked uploads root would never
        // prefix-match an un-canonicalized $root and every removal would be
        // refused as outside the tree.
        $real_root = realpath( $root );
        if ( false !== $real_root ) {
            $root = trailingslashit( $real_root );
        }

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
                // whose rows still carry old-tree absolute paths land here,
                // and so does a row pointing at another site's subtree.
                $errors[] = 'Refused to remove a file outside its site\'s backup tree: ' . $path;
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
                $errors[] = 'Refused to remove a directory outside its site\'s backup tree: ' . $dir;
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
