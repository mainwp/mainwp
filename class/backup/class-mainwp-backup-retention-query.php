<?php
/**
 * MainWP Backup Retention Query.
 *
 * Database reads for the retention engine. Groups a site and task
 * pair's complete snapshot rows into restorable units for the policy:
 * an incremental chain hangs together under its base full, standalone
 * full and db snapshots stand alone, and a chain whose parent link
 * points at a missing or pruned row is flagged incomplete so the policy
 * never treats it as restorable.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Retention_Query
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Retention_Query extends MainWP_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, table identifiers cannot be prepared.

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Retention_Query or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Retention_Query
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Group a site and task pair's complete snapshots into restorable
     * units for the policy engine.
     *
     * @param int $site_id Child site id.
     * @param int $task_id Scheduled task id.
     *
     * @return array Units, each carrying snapshots (rows, base first),
     *               is_complete, is_restorable, size_bytes, newest_created and
     *               oldest_created.
     */
    public function get_units_for_site_task( int $site_id, int $task_id ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table_name( 'wp_backup_snapshot' ) . " WHERE site_id = %d AND task_id = %d AND status = 'complete' ORDER BY id ASC",
                $site_id,
                $task_id
            )
        );

        if ( empty( $rows ) ) {
            return array();
        }

        $by_id = array();
        foreach ( $rows as $row ) {
            $decoded                 = json_decode( (string) $row->archives, true );
            $row->archives           = is_array( $decoded ) ? $decoded : array();
            $row->parent_id          = empty( $row->parent_id ) ? null : (int) $row->parent_id;
            $by_id[ (int) $row->id ] = $row;
        }

        // Resolve every row to its topmost reachable ancestor; rows sharing
        // a root form a unit. A walk that leaves the loaded set means the
        // parent is missing or pruned - the unit is not restorable.
        $groups = array();
        foreach ( $by_id as $id => $row ) {
            $current = $id;
            $broken  = false;
            $seen    = array();

            while ( null !== $by_id[ $current ]->parent_id && ! isset( $seen[ $current ] ) ) {
                $seen[ $current ] = true;
                $parent           = (int) $by_id[ $current ]->parent_id;
                if ( ! isset( $by_id[ $parent ] ) ) {
                    $broken = true;
                    break;
                }
                $current = $parent;
            }

            if ( ! isset( $groups[ $current ] ) ) {
                $groups[ $current ] = array(
                    'rows'   => array(),
                    'broken' => false,
                );
            }
            $groups[ $current ]['rows'][] = $row;
            if ( $broken ) {
                $groups[ $current ]['broken'] = true;
            }
        }

        $units = array();
        foreach ( $groups as $root_id => $group ) {
            $root        = $by_id[ $root_id ];
            $is_complete = ! $group['broken'] && null === $root->parent_id && in_array( $root->type, array( 'full', 'db', 'files' ), true );

            $size_bytes = 0;
            $newest     = 0;
            $oldest     = PHP_INT_MAX;
            foreach ( $group['rows'] as $row ) {
                foreach ( $row->archives as $archive ) {
                    $size_bytes += isset( $archive['size'] ) ? (int) $archive['size'] : 0;
                }
                $newest = max( $newest, (int) $row->created );
                $oldest = min( $oldest, (int) $row->created );
            }

            // Only a recorded integrity failure removes coverage. A missing or empty
            // local list keeps the old behaviour: Child-held copies and archives
            // outside the private root are tracked that way and must still count.
            // Restore's plan decides which archives are actually required, so a
            // superseded damaged dump or a damaged archive with a usable cloud copy
            // does not cost the unit its coverage.
            $is_restorable = $is_complete;
            if ( $is_complete && array_filter( $group['rows'], array( MainWP_Backup_Snapshots::class, 'snapshot_has_damage' ) ) ) {
                foreach ( MainWP_Backup_Snapshot_Catalog::instance()->assemble_plan( $group['rows'] ) as $entry ) {
                    if ( ! empty( $entry['damaged'] ) && 'unavailable' === $entry['source'] ) {
                        $is_restorable = false;
                        break;
                    }
                }
            }

            $ids                      = array_map( 'intval', array_column( $group['rows'], 'id' ) );
            $protection               = MainWP_Backup_Retention_Protection::remote_protection( $ids );
            $required_uploads_pending = (bool) array_filter(
                $group['rows'],
                static fn( $snapshot ) => MainWP_Backup_Retention_Protection::snapshot_requires_uploads( $snapshot ) && ! MainWP_Backup_Retention_Protection::required_uploads_verified( (int) $snapshot->id )
            );
            $required_upload_hold     = MainWP_Backup_Retention_Protection::task_removes_local_archives( $task_id ) && $required_uploads_pending;

            $units[] = array(
                'protected_until'          => $protection['protected_until'],
                'unknown_age'              => $protection['unknown_age'],
                'required_upload_hold'     => $required_upload_hold,
                'required_uploads_pending' => $required_uploads_pending,
                'snapshots'                => $group['rows'],
                'is_complete'              => $is_complete,
                'is_restorable'            => $is_restorable,
                'size_bytes'               => $size_bytes,
                'newest_created'           => $newest,
                'oldest_created'           => PHP_INT_MAX === $oldest ? 0 : $oldest,
            );
        }

        return array_values( $units );
    }

    /**
     * Walk descendants inside the target's retention unit, parent before child.
     *
     * @param int $snapshot_id Target identity.
     * @return int[] Complete target and descendant ids.
     */
    public function get_snapshot_delete_ids( int $snapshot_id ): array {
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        if ( ! $snapshot || 'complete' !== $snapshot->status ) {
            return array();
        }
        foreach ( $this->get_units_for_site_task( (int) $snapshot->site_id, (int) $snapshot->task_id ) as $unit ) {
            $rows = array();
            foreach ( $unit['snapshots'] as $row ) {
                $rows[ (int) $row->id ] = $row;
            }
            if ( ! isset( $rows[ $snapshot_id ] ) ) {
                continue;
            }
            $ids     = array();
            $pending = array( $snapshot_id );
            $seen    = array();
            while ( ! empty( $pending ) ) {
                $current = array_pop( $pending );
                if ( isset( $seen[ $current ] ) ) {
                    continue;
                }
                $ids[]            = $current;
                $seen[ $current ] = true;
                foreach ( array_reverse( $rows, true ) as $id => $row ) {
                    if ( (int) $row->parent_id === $current && ! isset( $seen[ $id ] ) ) {
                        $pending[] = $id;
                    }
                }
            }
            return $ids;
        }
        return array();
    }

    /**
     * Task progress, owned ad-hoc handles and request markers hold the site.
     *
     * @param int $site_id Site identity.
     * @return bool True on active evidence or a failed read.
     */
    public function has_running_backup_for_site( int $site_id ): bool {
        $raw = $this->wpdb->get_var(
            $this->wpdb->prepare( "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", MainWP_Backup_Executor::BACKUP_HOLD_OPTION_PREFIX . $site_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress supplies its options table name.
        );
        if ( '' !== $this->wpdb->last_error ) {
            return true;
        }
        if ( null !== $raw ) {
            $owners = json_decode( $raw, true );
            if ( ! is_array( $owners ) ) {
                return true;
            }
            foreach ( $owners as $expires ) {
                if ( ! is_int( $expires ) || $expires > time() ) {
                    return true;
                }
            }
        }
        $handles = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT value FROM ' . $this->table_name( 'wp_options' ) . ' WHERE wpid = %d AND name = %s',
                $site_id,
                MainWP_Backup_Executor::ADHOC_TASK_OPTION
            )
        );
        if ( '' !== $this->wpdb->last_error ) {
            return true;
        }
        foreach ( $handles as $raw ) {
            $handle = json_decode( (string) $raw, true );
            // Owners can resume old tasks; recent driving evidence bounds abandoned handles.
            if ( is_array( $handle ) && isset( $handle['task_id'] ) && is_scalar( $handle['task_id'] ) && '' !== (string) $handle['task_id'] && time() - max( (int) ( $handle['started'] ?? 0 ), (int) ( $handle['driven_at'] ?? 0 ) ) <= MainWP_Backup_Executor::ADHOC_TASK_ABANDON_SECONDS ) {
                return true;
            }
        }
        $tasks = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT b.manual_site_id, b.completed_sites FROM ' . $this->table_name( 'wp_backup' ) . ' b LEFT JOIN ' . $this->table_name( 'wp_backup_progress' ) . " p ON p.task_id = b.id AND p.wp_id = %d WHERE b.completed < b.last_run AND (b.manual_site_id = %d OR (p.dtsFetched >= b.last_run AND p.child_phase <> 'failed'))",
                $site_id,
                $site_id
            )
        );
        if ( ! is_array( $tasks ) || '' !== $this->wpdb->last_error ) {
            return true;
        }
        foreach ( $tasks as $task ) {
            // The runner resets these marks per run and writes them after terminal site handling.
            $completed = json_decode( (string) $task->completed_sites, true );
            if ( (int) $task->manual_site_id === $site_id || true !== ( $completed[ $site_id ] ?? null ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tasks carrying any retention constraint, for the daily prune cron.
     *
     * @return object[] Task rows.
     */
    public function list_tasks_with_policy(): array {
        $tasks = $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'wp_backup' ), OBJECT );
        return array_values(
            array_filter(
                (array) $tasks,
                static function ( $task ) {
                    return (int) $task->retention_count > 0 || (int) $task->retention_days > 0 || (int) $task->retention_storage_mb > 0
                    || MainWP_Backup_Retention_Protection::task_removes_local_archives( (int) $task->id );
                }
            )
        );
    }

    /**
     * Distinct site ids a task has complete snapshots for.
     *
     * @param int $task_id Scheduled task id.
     *
     * @return int[] Site ids.
     */
    public function list_site_ids_for_task( int $task_id ): array {
        $site_ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT DISTINCT site_id FROM ' . $this->table_name( 'wp_backup_snapshot' ) . " WHERE task_id = %d AND status = 'complete'",
                $task_id
            )
        );

        return array_map( 'intval', $site_ids );
    }

    /**
     * Task ids a site still has complete snapshots for whose task row is
     * gone: a task deleted while keeping its snapshots, or removed outside
     * the purge path. Retention only walks live tasks, so nothing else
     * revisits these.
     *
     * @param int $site_id Child site id.
     *
     * @return int[] Task ids.
     */
    public function list_orphaned_task_ids_for_site( int $site_id ): array {
        $task_ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT DISTINCT snapshot.task_id FROM ' . $this->table_name( 'wp_backup_snapshot' ) . ' snapshot LEFT JOIN ' . $this->table_name( 'wp_backup' ) . " task ON task.id = snapshot.task_id WHERE snapshot.site_id = %d AND snapshot.status = 'complete' AND task.id IS NULL",
                $site_id
            )
        );

        return array_map( 'intval', $task_ids );
    }
}
