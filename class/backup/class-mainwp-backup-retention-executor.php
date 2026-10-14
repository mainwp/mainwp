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
     *               snapshots_pruned, deleted_ids, remaining_ids, queued cloud
     *               snapshot/destination pairs, errors, dry_run and candidates.
     */
    public function run_prune( array $candidates, object $task, bool $dry_run = false, int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $now = null ): array {
        unset( $now );

        $deadline = microtime( true ) + $budget_seconds;

        $result = array(
            'pruned'               => 0,
            'snapshots_pruned'     => 0,
            'deleted_ids'          => array(),
            'remaining_ids'        => array(),
            'cloud_deletes_queued' => array(),
            'skipped'              => 0,
            'errors'               => array(),
            'dry_run'              => $dry_run,
            'candidates'           => array(),
        );

        foreach ( $candidates as $position => $candidate ) {
            // Always make progress on the first candidate; check the budget
            // before each further one.
            if ( $position > 0 && microtime( true ) >= $deadline ) {
                $result['skipped'] += count( $candidates ) - $position;
                foreach ( array_slice( $candidates, $position ) as $pending ) {
                    $result['remaining_ids'] = array_values( array_unique( array_merge( $result['remaining_ids'], array_map( 'intval', $pending['snapshot_ids'] ) ) ) );
                }
                break;
            }

            $result['candidates'][] = array(
                'snapshot_ids' => array_map( 'intval', $candidate['snapshot_ids'] ),
                'reason'       => (string) $candidate['reason'],
            );

            if ( $dry_run ) {
                // A preview counts every candidate it would attempt.
                ++$result['pruned'];
                $result['remaining_ids'] = array_merge( $result['remaining_ids'], array_map( 'intval', $candidate['snapshot_ids'] ) );
                continue;
            }

            if ( $this->prune_candidate( $candidate, $task, $result ) ) {
                ++$result['pruned'];
            }
        }

        return $result;
    }

    /**
     * Purge every restorable unit a deleted task owns, across all of its
     * sites. Task deletion is not the retention policy's prune-worthy
     * subset - there is nothing left to keep, so every complete unit
     * get_units_for_site_task() reports becomes its own candidate and
     * runs through the same leaf-first prune_candidate() sequence
     * retention uses: cloud delete enqueue when requested, local removal,
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
     * @return array Pruned unit and snapshot counts, deleted_ids, remaining_ids,
     *               cloud_deletes_queued (snapshot/destination pairs) and errors.
     */
    public function purge_snapshots_for_task( int $task_id, bool $delete_cloud_copies ): array {
        $task = (object) array(
            'id'                  => $task_id,
            'delete_cloud_copies' => $delete_cloud_copies,
        );

        $result = array(
            'pruned'               => 0,
            'snapshots_pruned'     => 0,
            'deleted_ids'          => array(),
            'remaining_ids'        => array(),
            'cloud_deletes_queued' => array(),
            'errors'               => array(),
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

                if ( $this->prune_candidate( $candidate, $task, $result ) ) {
                    ++$result['pruned'];
                }
            }
        }

        return $result;
    }

    /**
     * Clean up one site's backups on demand: sweep orphaned snapshot
     * directories, remove snapshots no task owns, then prune the on-demand
     * task down to a keep-count.
     *
     * The passes cover the ways a dashboard's backup tree grows without
     * bound. Interrupted runs leave snapshot-* directories that never
     * gained a snapshot row, so neither retention nor the task purge ever
     * revisits them - the orphan sweep removes those. A task deleted while
     * keeping its snapshots leaves rows retention never walks again - they
     * go through the task purge's own sequence. And
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
     *               snapshots_pruned, orphaned_snapshots_pruned (the share
     *               of snapshots_pruned no task owned), bytes_pruned and
     *               deleted_ids, remaining_ids, cloud_deletes_queued and errors.
     */
    public function cleanup_site_backups( int $site_id, ?int $keep = null, int $orphan_min_age = 6 * HOUR_IN_SECONDS ): array {
        $result = array(
            'orphan_dirs_removed'       => 0,
            'pruned'                    => 0,
            'snapshots_pruned'          => 0,
            'deleted_ids'               => array(),
            'remaining_ids'             => array(),
            'cloud_deletes_queued'      => array(),
            'orphaned_snapshots_pruned' => 0,
            'bytes_pruned'              => 0,
            'errors'                    => array(),
        );

        $plan = $this->plan_site_cleanup( $site_id, $keep, $orphan_min_age );
        $this->remove_orphan_dirs( $plan['orphan_dirs'], $result );
        $rows = array();
        foreach ( $plan['orphaned_task_snapshot_ids'] as $id ) {
            $row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
            if ( null !== $row ) {
                $rows[ $id ] = $row;
            }
        }
        $groups = array();
        foreach ( $rows as $id => $row ) {
            $root = $id;
            $seen = array();
            while ( ! empty( $rows[ $root ]->parent_id ) && isset( $rows[ (int) $rows[ $root ]->parent_id ] ) && ! isset( $seen[ $root ] ) ) {
                $seen[ $root ] = true;
                $root          = (int) $rows[ $root ]->parent_id;
            }
            $groups[ $row->task_id . ':' . $root ][] = $id;
        }
        $before = $result['snapshots_pruned'];
        foreach ( $groups as $ids ) {
            sort( $ids, SORT_NUMERIC );
            // The deleted task's cloud-copies setting went with its row. Cloud
            // copies are deleted too, because the prune drops the upload rows that
            // are the only record of the remote keys: keeping the copies would
            // orphan them for good.
            $task = (object) array(
                'id'                  => (int) $rows[ $ids[0] ]->task_id,
                'delete_cloud_copies' => true,
            );
            if ( $this->prune_candidate(
                array(
                    'snapshot_ids' => $ids,
                    'reason'       => 'task_deleted',
                    'size_bytes'   => $this->selected_snapshot_bytes( $ids ),
                ),
                $task,
                $result
            ) ) {
                ++$result['pruned'];
            }
        }
        $result['orphaned_snapshots_pruned'] += $result['snapshots_pruned'] - $before;
        $task                                 = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        // Complete units beyond the keep-count prune oldest-first through
        // the same prune_candidate() sequence retention uses, so cloud
        // copies and upload metadata follow the task's own settings.
        foreach ( $plan['on_demand_units'] as $unit ) {
            $unit['size_bytes'] = $this->selected_snapshot_bytes( $unit['snapshot_ids'] );
            $unit['reason']     = 'beyond_keep' === $unit['reason'] ? 'manual_cleanup' : $unit['reason'];
            if ( $task && $this->prune_candidate( $unit, $task, $result ) ) {
                ++$result['pruned'];
            }
        }

        return $result;
    }

    /**
     * Selection stays pure so a preview never starts a deletion or reservation.
     *
     * @param int      $site_id Child site identity.
     * @param int|null $keep Complete on-demand units to retain.
     * @param int      $orphan_min_age Minimum directory age in seconds.
     * @return array Internal paths, snapshot identities, units and byte estimate.
     */
    public function plan_site_cleanup( int $site_id, ?int $keep = null, int $orphan_min_age = 6 * HOUR_IN_SECONDS ): array {
        $plan = array(
            'orphan_dirs'                => $this->select_orphan_dirs( $site_id, $orphan_min_age ),
            'orphaned_task_snapshot_ids' => $this->select_orphaned_task_snapshot_ids( $site_id ),
            'on_demand_units'            => $this->select_manual_units( $site_id, $keep ),
            'bytes'                      => 0,
        );
        foreach ( $plan['orphan_dirs'] as $dir ) {
            foreach ( glob( $dir . DIRECTORY_SEPARATOR . '*' ) ?: array() as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty-glob guard.
                if ( is_file( $file ) ) {
                    $plan['bytes'] += (int) filesize( $file );
                }
            }
        }
        $ids = $plan['orphaned_task_snapshot_ids'];
        foreach ( $plan['on_demand_units'] as $unit ) {
            $ids = array_merge( $ids, $unit['snapshot_ids'] );
        }
        $plan['bytes'] += $this->selected_snapshot_bytes( array_unique( $ids ) );
        return $plan;
    }

    /**
     * Deletion events retain the same size evidence as the preview selection.
     *
     * @param array $ids Selected snapshot identities.
     * @return int Recorded artifact bytes.
     */
    private function selected_snapshot_bytes( array $ids ): int {
        $bytes = 0;
        foreach ( $ids as $id ) {
            $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $id );
            if ( null !== $snapshot ) {
                $bytes += (int) array_sum( array_column( (array) $snapshot->archives, 'size' ) );
            }
        }
        return $bytes;
    }

    /**
     * Select a site's complete snapshots whose task row is gone, unit by
     * unit, for the same sequence a task delete's purge uses.
     *
     * @param int $site_id Child site id.
     *
     * @return array Snapshot identities from deleted tasks.
     */
    private function select_orphaned_task_snapshot_ids( int $site_id ): array {
        $query = MainWP_Backup_Retention_Query::instance();
        $ids   = array();
        foreach ( $query->list_orphaned_task_ids_for_site( $site_id ) as $task_id ) {
            foreach ( $query->get_units_for_site_task( $site_id, $task_id ) as $unit ) {
                $unit_ids = array_map( 'intval', array_column( $unit['snapshots'], 'id' ) );
                sort( $unit_ids, SORT_NUMERIC );
                $ids = array_merge( $ids, $unit_ids );
            }
        }
        return $ids;
    }

    /**
     * Select snapshot-* directories under a site's backup tree that no
     * non-pruned snapshot row claims.
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
     * @param int $site_id Child site id.
     * @param int $min_age Seconds since the directory's newest write.
     *
     * @return array Unclaimed directories within this site's roots.
     */
    private function select_orphan_dirs( int $site_id, int $min_age ): array {
        $selected = array();
        $dirs     = array();
        foreach ( array( MainWP_Backup_Private_Root::site_path( $site_id ), MainWP_Backup_Private_Root::legacy_site_dir( $site_id ) ) as $root ) {
            if ( ! is_wp_error( $root ) ) {
                $dirs = array_merge( $dirs, glob( $root . 'snapshot-*', GLOB_ONLYDIR ) ?: array() ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty-glob guard.
            }
        }

        $protected_dirs = array();
        foreach ( array_keys( MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( $site_id ) ) as $path ) {
            $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( dirname( $path ) ) ] = true;
        }
        // A paused or starved run can idle past the freshness window before
        // its snapshot is recorded, and it still owns its staging directory.
        $open_run_names = array();
        foreach ( MainWP_DB_Backup::instance()->get_open_run_child_task_ids( $site_id ) as $child_task_id ) {
            $open_run_names[ MainWP_Backup_Executor::run_dir_name( $child_task_id ) ] = true;
        }

        foreach ( array_unique( $dirs ) as $dir ) {
            $real = realpath( $dir );
            if ( false === $real || ! MainWP_Backup_Private_Root::is_within_site_roots( $dir, $site_id ) ) {
                continue; // A symlinked or escaping entry is not ours to sweep.
            }
            if ( isset( $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( $dir ) ] )
                || isset( $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( $real ) ] ) ) {
                continue; // A non-pruned snapshot still owns this directory.
            }
            if ( isset( $open_run_names[ basename( $dir ) ] )
                || isset( $open_run_names[ basename( $real ) ] ) ) {
                continue; // An open run still owns this directory.
            }

            $files  = glob( $real . DIRECTORY_SEPARATOR . '*' ) ?: array(); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty-glob guard.
            $newest = (int) @filemtime( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- racing a concurrent delete is benign.
            foreach ( $files as $file ) {
                $newest = max( $newest, (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- racing a concurrent delete is benign.
            }
            if ( time() - $newest < $min_age ) {
                continue; // Recently written; a run may still own it.
            }

            $selected[] = $real;
        }
        return $selected;
    }

    /**
     * Only directories selected by the preview may be removed.
     *
     * @param array $dirs Selected directories.
     * @param array $result Deletion evidence.
     * @return void
     */
    private function remove_orphan_dirs( array $dirs, array &$result ): void {
        foreach ( $dirs as $real ) {
            $files = glob( $real . DIRECTORY_SEPARATOR . '*' ) ?: array(); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- empty-glob guard.
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
     * Select the site's on-demand units beyond a keep-count, newest first,
     * for synchronous cleanup.
     *
     * Broken units (a chain whose parent link is missing) are selected
     * unconditionally - the policy never selects them because it cannot
     * restore them, which is exactly why they otherwise sit forever.
     *
     * @param int      $site_id Child site id.
     * @param int|null $keep    Units to keep; null uses the task's retention
     *                          count (falling back to the on-demand default).
     *
     * @return array Selected on-demand units and their reasons.
     */
    private function select_manual_units( int $site_id, ?int $keep ): array {
        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        if ( empty( $task ) ) {
            return array(); // The site has never run an on-demand backup.
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

        $selected = array();
        foreach ( $prunable as list( $unit, $reason ) ) {
            $snapshot_ids = array_map( 'intval', array_column( $unit['snapshots'], 'id' ) );
            sort( $snapshot_ids, SORT_NUMERIC );
            $selected[] = array(
                'snapshot_ids' => $snapshot_ids,
                'reason'       => 'manual_cleanup' === $reason ? 'beyond_keep' : $reason,
            );
        }
        return $selected;
    }

    /**
     * Reserve the site before comparing consent with current descendants.
     *
     * @param int    $site_id Authorized site.
     * @param int    $snapshot_id Target identity.
     * @param int[]  $expected_ids Previewed identities.
     * @param object $task Task with the selected cloud policy.
     * @return array Exact outcomes, status and current selection.
     */
    public function delete_snapshot( int $site_id, int $snapshot_id, array $expected_ids, object $task ): array {
        $result      = array(
            'status'               => 'refused',
            'deleted_ids'          => array(),
            'remaining_ids'        => $expected_ids,
            'cloud_deletes_queued' => array(),
            'bytes_pruned'         => 0,
            'errors'               => array(),
            'snapshot_ids'         => array(),
        );
        $restores    = MainWP_Backup_Restore_Job_Store::instance();
        $reservation = $restores->reserve_site( $site_id );
        if ( $reservation <= 0 ) {
            $result['errors'][] = __( 'A restore or another backup action is running on this site. Try again when it finishes.', 'mainwp' );
            return $result;
        }
        try {
            $snapshot               = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
            $ids                    = $snapshot && (int) $snapshot->site_id === $site_id ? MainWP_Backup_Retention_Query::instance()->get_snapshot_delete_ids( $snapshot_id ) : array();
            $result['snapshot_ids'] = $ids;
            $sorted                 = $ids;
            sort( $sorted, SORT_NUMERIC );
            sort( $expected_ids, SORT_NUMERIC );
            if ( $sorted !== $expected_ids ) {
                $result['status']        = 'changed';
                $result['remaining_ids'] = $ids;
                $result['errors'][]      = __( 'This site\'s backups changed since the preview. Check the new list, then delete again.', 'mainwp' );
                return $result;
            }
            if ( MainWP_Backup_Retention_Query::instance()->has_running_backup_for_site( $site_id ) ) {
                $result['errors'][] = __( 'A backup is running on this site. Try again when it finishes.', 'mainwp' );
                return $result;
            }
            $unit             = $this->prune_reserved_candidate(
                array(
                    'snapshot_ids' => $ids,
                    'reason'       => 'single_snapshot_delete',
                ),
                $task
            );
            $result           = array_merge( $result, $unit );
            $result['status'] = empty( $unit['remaining_ids'] ) && empty( $unit['errors'] ) ? 'complete' : ( empty( $unit['deleted_ids'] ) ? 'refused' : 'partial' );
            return $result;
        } finally {
            $restores->release_reservation( $reservation );
        }
    }

    /**
     * Prune one candidate unit, leaf first.
     *
     * @param array  $candidate Candidate: snapshot_ids (base first), reason, size_bytes.
     * @param object $task      Task row.
     * @param array  $result    Result accumulator, modified in place.
     *
     * @return bool True when the entire unit was pruned;
     *              false when the unit was held (a restore or another
     *              backup action holds the site) or the first leaf could not
     *              be removed, so nothing was deleted.
     */
    private function prune_candidate( array $candidate, object $task, array &$result ): bool {
        $site_id = 0;
        foreach ( (array) $candidate['snapshot_ids'] as $snapshot_id ) {
            $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $snapshot_id );
            if ( null !== $snapshot ) {
                $site_id = (int) $snapshot->site_id;
                break;
            }
        }
        if ( $site_id <= 0 ) {
            $result['remaining_ids'] = array_merge( $result['remaining_ids'], array_map( 'intval', $candidate['snapshot_ids'] ) );
            return false;
        }

        // Restore admission takes the same site slot before it resolves a
        // snapshot, so holding it from the active-restore check to the last
        // delete keeps a restore from adopting files mid-delete. A held site
        // skips the unit; the next run retries it.
        $restores    = MainWP_Backup_Restore_Job_Store::instance();
        $reservation = $restores->reserve_site( $site_id );
        if ( $reservation <= 0 ) {
            $result['remaining_ids'] = array_merge( $result['remaining_ids'], array_map( 'intval', $candidate['snapshot_ids'] ) );
            $result['errors'][]      = sprintf(
                'Snapshots %s: a restore in progress or another backup action is working on this site, so they were kept. Try again once it finishes.',
                implode( ',', array_map( 'intval', (array) $candidate['snapshot_ids'] ) )
            );
            return false;
        }
        try {
            $unit = $this->prune_reserved_candidate( $candidate, $task );
            foreach ( array( 'deleted_ids', 'remaining_ids', 'cloud_deletes_queued', 'errors' ) as $key ) {
                $result[ $key ] = array_merge( $result[ $key ], $unit[ $key ] );
            }
            $result['snapshots_pruned'] += count( $unit['deleted_ids'] );
            if ( isset( $result['bytes_pruned'] ) ) {
                $result['bytes_pruned'] += $unit['bytes_pruned'];
            }
            return ! empty( $unit['deleted_ids'] ) && empty( $unit['remaining_ids'] );
        } finally {
            $restores->release_reservation( $reservation );
        }
    }

    /**
     * Prune a reserved unit leaf first, retaining exact deletion outcomes.
     *
     * @param array  $candidate Snapshot ids, reason and size estimate.
     * @param object $task      Task row.
     * @return array Deleted and remaining ids, queued cloud deletes, bytes and errors.
     */
    private function prune_reserved_candidate( array $candidate, object $task ): array {
        $ids       = array_map( 'intval', $candidate['snapshot_ids'] );
        $result    = array(
            'deleted_ids'          => array(),
            'remaining_ids'        => $ids,
            'cloud_deletes_queued' => array(),
            'bytes_pruned'         => 0,
            'errors'               => array(),
        );
        $snapshots = MainWP_Backup_Snapshots::instance();
        $first     = $snapshots->get_snapshot_by_id( $ids[0] );
        if ( null === $first ) {
            return $result;
        }
        $site_id = (int) $first->site_id;
        if ( MainWP_Backup_Restore_Job_Store::instance()->has_active_job_for_snapshots( $ids ) ) {
            $result['errors'][] = sprintf( 'Snapshots %s: a restore in progress uses this backup, so it was kept. Try again once the restore finishes.', implode( ',', $ids ) );
            return $result;
        }
        if ( MainWP_DB_Backup::instance()->has_inflight_incremental_for_site( $site_id ) ) {
            $result['errors'][] = $this->incremental_hold_message( $ids );
            return $result;
        }

        $store          = MainWP_Backup_Upload_Job_Store::instance();
        $token          = 'prune:' . wp_generate_password( 32, false );
        $child_task_ids = array();
        try {
            if ( ! $store->claim_uploads_for_prune( $ids, $token ) ) {
                $result['errors'][] = sprintf( 'Snapshots %s: an upload in progress uses this backup, so it was kept. Try again once the upload finishes.', implode( ',', $ids ) );
                return $result;
            }
            foreach ( array_reverse( $ids ) as $snapshot_id ) {
                $snapshot = $snapshots->get_snapshot_by_id( $snapshot_id );
                if ( null === $snapshot ) {
                    break;
                }
                if ( ! $this->remove_local_dir( $snapshot, $result['errors'], false ) ) {
                    break;
                }
                // A run that started after admission may already have picked this
                // snapshot as its parent; the completion backstop covers the instant after this.
                if ( 'single_snapshot_delete' === $candidate['reason'] && MainWP_Backup_Retention_Query::instance()->has_running_backup_for_site( $site_id ) ) {
                    $result['errors'][] = __( 'A backup is running on this site. Try again when it finishes.', 'mainwp' );
                    break;
                }
                if ( MainWP_DB_Backup::instance()->has_inflight_incremental_for_site( $site_id ) ) {
                    $result['errors'][] = $this->incremental_hold_message( array_values( array_diff( $ids, $result['deleted_ids'] ) ) );
                    break;
                }
                $inserted_deletes = array();
                $keep_deletes     = false;
                try {
                    // Enqueue while checkpoints and local files are intact. A failed
                    // write leaves the source available for the next cleanup.
                    if ( ! empty( $task->delete_cloud_copies ) && ! $this->enqueue_cloud_deletes( $snapshot, $store, $result['cloud_deletes_queued'], $inserted_deletes ) ) {
                        $result['errors'][] = sprintf( 'Snapshot %d: a cloud delete job could not be queued, so it was kept. Try again later.', $snapshot_id );
                        break;
                    }
                    if ( ! $this->remove_local_dir( $snapshot, $result['errors'] ) ) {
                        break;
                    }
                    $upload_jobs = $store->get_jobs_for_snapshot( $snapshot_id, MainWP_Backup_Upload_Job_Store::KIND_UPLOAD );
                    $store->purge_upload_jobs_for_snapshot( $snapshot_id );
                    foreach ( $upload_jobs as $upload_job ) {
                        MainWP_Backup_Upload_Driver::instance()->cleanup_staging_for( $snapshot_id, (int) $upload_job->destination_id );
                    }
                    // The local files are gone; cloud deletion proceeds even if the status write fails.
                    $keep_deletes = true;
                    if ( ! $snapshots->set_status( $snapshot_id, 'pruned' ) ) {
                        /* translators: %d: Snapshot ID. */
                        $result['errors'][] = sprintf( __( 'Snapshot %d: the files were deleted but the backup could not be marked as removed. Try again later.', 'mainwp' ), $snapshot_id );
                        break;
                    }
                    $child_task_id = $this->child_task_id_for( $snapshot );
                    if ( '' !== $child_task_id ) {
                        $child_task_ids[] = $child_task_id;
                    }
                    $result['deleted_ids'][] = $snapshot_id;
                    $result['bytes_pruned'] += (int) array_sum( array_column( (array) $snapshot->archives, 'size' ) );
                } finally {
                    if ( ! $keep_deletes && ! empty( $inserted_deletes ) ) {
                        $remaining                      = $store->withdraw_delete_jobs( $snapshot_id, $inserted_deletes );
                        $withdrawn                      = array_diff( $inserted_deletes, $remaining );
                        $result['cloud_deletes_queued'] = array_values(
                            array_filter(
                                $result['cloud_deletes_queued'],
                                static function ( $pair ) use ( $snapshot_id, $withdrawn ) {
                                    return $pair['snapshot_id'] !== $snapshot_id || ! in_array( $pair['destination_id'], $withdrawn, true );
                                }
                            )
                        );
                        if ( ! empty( $remaining ) ) {
                            /* translators: %d: Snapshot ID. */
                            $result['errors'][] = sprintf( __( 'Snapshot %d: its cloud copy is already being deleted, but the local files could not be removed. Try again later.', 'mainwp' ), $snapshot_id );
                        }
                    }
                }
            }
        } finally {
            // Holds and partial failures leave upload rows retryable; only the
            // successful snapshots have already purged their claims.
            $store->release_prune_claims( $token );
        }
        $result['remaining_ids'] = array_values( array_diff( $ids, $result['deleted_ids'] ) );
        $this->notify_child_prune( $site_id, $child_task_ids );
        if ( ! empty( $result['deleted_ids'] ) ) {
            /**
             * Fires after snapshots in a restorable unit are pruned.
             *
             * Cloud delete jobs are queued; the cloud driver drains them later.
             *
             * @param array $event Site, task, deleted snapshot ids, reason and bytes.
             */
            do_action(
                'mainwp_backup_pruned',
                array(
                    'site_id'      => $site_id,
                    'task_id'      => (int) $task->id,
                    'snapshot_ids' => array_reverse( $result['deleted_ids'] ),
                    'reason'       => (string) $candidate['reason'],
                    'size_bytes'   => $result['bytes_pruned'],
                )
            );
        }
        return $result;
    }

    /**
     * Hold message for snapshots kept while an incremental backup runs.
     *
     * @param int[] $ids Snapshot ids kept.
     * @return string Translated message.
     */
    private function incremental_hold_message( array $ids ): string {
        /* translators: %s: Snapshot IDs. */
        return sprintf( __( 'Snapshots %s: an incremental backup is running on this site, so they were kept. Try the cleanup again once that backup finishes.', 'mainwp' ), implode( ',', $ids ) );
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
     * @param array                          $queued_deletes Queued snapshot/destination pairs, appended in place.
     * @param int[]                          $inserted_deletes Destinations inserted by this pass, appended in place.
     *
     * @return bool True when every destination's delete job is queued.
     */
    private function enqueue_cloud_deletes( object $snapshot, MainWP_Backup_Upload_Job_Store $store, array &$queued_deletes, array &$inserted_deletes ): bool {
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

            $queued = $store->enqueue_delete_job_result(
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
            if ( 'failed' === $queued ) {
                $all_queued = false;
            } else {
                $queued_deletes[] = array(
                    'snapshot_id'    => (int) $snapshot->id,
                    'destination_id' => (int) $upload_job->destination_id,
                );
                if ( 'inserted' === $queued ) {
                    $inserted_deletes[] = (int) $upload_job->destination_id;
                }
            }
        }

        return $all_queued;
    }

    /**
     * The child-side task id a snapshot's run used.
     *
     * The run key ({site}:{task}:c:{child_task_id}) names it for every
     * backup type; full and database archives share the site folder, so the
     * folder name only identifies incremental runs. The folder is the
     * fallback for rows recorded before the run key existed. Legacy-flow
     * runs (an :l: run key) have no child task row to clean.
     *
     * @param object $snapshot Snapshot row with decoded archives.
     *
     * @return string Child task id, or '' when none applies.
     */
    private function child_task_id_for( object $snapshot ): string {
        $run_key = isset( $snapshot->run_key ) ? (string) $snapshot->run_key : '';
        if ( '' !== $run_key ) {
            $prefix = (int) $snapshot->site_id . ':' . (int) $snapshot->task_id . ':c:';
            return 0 === strpos( $run_key, $prefix ) ? substr( $run_key, strlen( $prefix ) ) : '';
        }

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
     * Recorded paths may use the private root or the legacy uploads tree.
     * Neither location permits deleting another site's artifacts.
     *
     * A failed artifact deletion returns false so the caller keeps the
     * snapshot's status honest; the directory sweep afterwards is
     * best-effort and only records diagnostics.
     *
     * @param object   $snapshot Snapshot row with decoded archives.
     * @param string[] $errors   Error accumulator, modified in place.
     *
     * @param bool     $remove False to check ownership and permissions before enqueueing deletes.
     *
     * @return bool True when every artifact this snapshot names is gone.
     */
    private function remove_local_dir( object $snapshot, array &$errors, bool $remove = true ): bool {
        $paths = array();
        foreach ( (array) $snapshot->archives as $archive ) {
            if ( ! empty( $archive['path'] ) ) {
                $paths[] = (string) $archive['path'];
            }
        }
        if ( ! empty( $snapshot->manifest_path ) ) {
            $paths[] = (string) $snapshot->manifest_path;
        }

        $protected_paths = MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( (int) $snapshot->site_id, (int) $snapshot->id );
        $protected_dirs  = array();
        foreach ( array_keys( $protected_paths ) as $path ) {
            $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( dirname( $path ) ) ] = true;
        }

        $clean = true;
        $dirs  = array();
        foreach ( $paths as $path ) {
            $dirs[ dirname( $path ) ] = true;

            $real = realpath( $path );
            if ( false === $real || ! is_file( $real ) ) {
                continue;
            }
            if ( isset( $protected_paths[ MainWP_Backup_Snapshots::normalise_path( $path ) ] )
                || isset( $protected_paths[ MainWP_Backup_Snapshots::normalise_path( $real ) ] ) ) {
                continue;
            }
            if ( 0 === strpos( basename( dirname( $real ) ), 'snapshot-' )
                && ( isset( $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( dirname( $path ) ) ] )
                    || isset( $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( dirname( $real ) ) ] ) ) ) {
                continue;
            }
            if ( ! MainWP_Backup_Private_Root::is_within_site_roots( $real, (int) $snapshot->site_id ) ) {
                // The named artifact still exists, so the snapshot must not
                // read as pruned. The preflight runs before cloud enqueue
                // or the upload-row purge. Migrated dashboards
                // whose rows still carry old-tree absolute paths land here,
                // and so does a row pointing at another site's subtree.
                $errors[] = 'Refused to remove a file outside its site\'s backup tree: ' . $path;
                $clean    = false;
                continue;
            }
            if ( ! $remove ) {
                if ( ! is_writable( dirname( $real ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- preflight the same local unlink permissions without changing the filesystem.
                    $errors[] = 'Could not remove local backup file: ' . basename( $real ) . ' (snapshot ' . (int) $snapshot->id . ')';
                    $clean    = false;
                }
                continue;
            }
            if ( ! @unlink( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- pruning the snapshot's own artifact; a failure is reported through the return value.
                $errors[] = 'Could not remove local backup file: ' . basename( $real ) . ' (snapshot ' . (int) $snapshot->id . ')';
                $clean    = false;
            } else {
                $sidecar = $path . '.reserved';
                if ( is_file( $sidecar ) && ! is_link( $sidecar ) && MainWP_Backup_Private_Root::is_within_site_roots( $sidecar, (int) $snapshot->site_id ) ) {
                    wp_delete_file( $sidecar );
                }
            }
        }

        if ( ! $clean || ! $remove ) {
            return $clean;
        }

        foreach ( array_keys( $dirs ) as $dir ) {
            $real = realpath( $dir );
            if ( false === $real || ! is_dir( $real ) ) {
                continue;
            }

            if ( isset( $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( $dir ) ] )
                || isset( $protected_dirs[ MainWP_Backup_Snapshots::normalise_path( $real ) ] ) ) {
                continue;
            }

            if ( 0 !== strpos( basename( $real ), 'snapshot-' ) ) {
                continue; // A shared directory; other snapshots' artifacts live here.
            }

            if ( ! MainWP_Backup_Private_Root::is_within_site_roots( $real, (int) $snapshot->site_id ) ) {
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
