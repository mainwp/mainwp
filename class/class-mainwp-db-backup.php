<?php
/**
 * MainWP Database Controller
 *
 * This file handles all interactions with the DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_DB_Backup
 *
 * @package MainWP\Dashboard
 */
class MainWP_DB_Backup extends MainWP_DB { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_DB_Backup or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_DB
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method get_website_backup_settings()
     *
     * Get Child Site backup settings.
     *
     * @param mixed $websiteid Child Site ID.
     *
     * @return object|null Database query result for Child Site backup settings or null on failure
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function get_website_backup_settings( $websiteid ) {
        if ( ! MainWP_Utility::ctype_digit( $websiteid ) ) {
            return null;
        }

        return $this->get_row_result( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_settings_backup' ) . ' WHERE wpid = %d ', $websiteid ) );
    }

    /**
     * Method update_backup_task_progress()
     *
     * Update backup tasuk progress.
     *
     * @param mixed $task_id Task ID.
     * @param mixed $wp_id Child Site ID.
     * @param mixed $values Values to update.
     *
     * @return mixed get_backup_task_progres().
     */
    public function update_backup_task_progress( $task_id, $wp_id, $values ) {
        $this->wpdb->update(
            $this->table_name( 'wp_backup_progress' ),
            $values,
            array(
                'task_id' => $task_id,
                'wp_id'   => $wp_id,
            )
        );

        return $this->get_backup_task_progress( $task_id, $wp_id );
    }

    /**
     * Method update_backup_task_progress_if_marker()
     *
     * Update a progress row only while it still carries the creating
     * marker the caller wrote. A driver that lost its slot mid-create, or
     * one cleaning up after a refused create, must not overwrite a
     * replacement driver's claim - and reading the row first and updating
     * it after leaves exactly the window where that claim lands, so the
     * marker is part of the WHERE clause instead.
     *
     * @param mixed  $task_id Task ID.
     * @param mixed  $site_id Child Site ID.
     * @param string $marker  Creating marker the row must still carry.
     * @param array  $values  Values to update.
     *
     * @return int Rows updated: 1 when the marker was still there, 0 when it was not.
     */
    public function update_backup_task_progress_if_marker( $task_id, $site_id, $marker, array $values ): int {
        $updated = $this->wpdb->update(
            $this->table_name( 'wp_backup_progress' ),
            $values,
            array(
                'task_id'              => $task_id,
                'wp_id'                => $site_id,
                'child_backup_task_id' => $marker,
            )
        );

        return is_numeric( $updated ) ? (int) $updated : 0;
    }

    /**
     * Method add_backup_task_progress()
     *
     * Add backup task progress.
     *
     * @param mixed $task_id Task ID.
     * @param mixed $wp_id Child Site ID.
     * @param mixed $information Task info to add.
     *
     * @return (object|null) $this->get_backup_task_progress() or null on failure
     */
    public function add_backup_task_progress( $task_id, $wp_id, $information ) {
        // Callers reach here only after reading no row for the pair, which
        // races: a second driver reading the same gap inserts too. The pair
        // is UNIQUE, so the loser adopts the row that landed first instead
        // of failing - the self-assignment leaves the winner's row exactly
        // as written, and either way the caller gets the row back.
        $inserted = $this->wpdb->query(
            $this->wpdb->prepare(
                'INSERT INTO ' . $this->table_name( 'wp_backup_progress' ) . " ( task_id, wp_id, dtsFetched, fetchResult, removedFiles, downloadedDB, downloadedFULL ) VALUES ( %d, %d, %d, %s, 0, '', '' ) ON DUPLICATE KEY UPDATE task_id = task_id", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                $task_id,
                $wp_id,
                time(),
                wp_json_encode( $information )
            )
        );

        if ( false === $inserted ) {
            return null;
        }

        return $this->get_backup_task_progress( $task_id, $wp_id );
    }

    /**
     * Method get_backup_task_progress()
     *
     * Get backup task progress.
     *
     * @param mixed $task_id Task ID.
     * @param mixed $wp_id Child Site ID.
     *
     * @return (array|null) Backup Progress or null on failer.
     */
    public function get_backup_task_progress( $task_id, $wp_id ) {
        $progress = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup_progress' ) ) . ' WHERE task_id= %d AND wp_id = %d ', $task_id, $wp_id ) );

        if ( $progress && '' !== $progress->fetchResult ) {
            $progress->fetchResult = json_decode( $progress->fetchResult, true );
        }

        return $progress;
    }

    /**
     * Method backup_full_task_running()
     *
     * Check if full backup task is running.
     *
     * @param mixed $wp_id Child Site ID.
     *
     * @return boolean true|false.
     */
    public function backup_full_task_running( $wp_id ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            return false;
        }

        $progresses = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup_progress' ) ) . ' WHERE wp_id = %d AND dtsFetched > %d ', $wp_id, time() - ( 30 * 60 ) ) );
        if ( is_array( $progresses ) ) {
            foreach ( $progresses as $progress ) {
                if ( empty( $progress->downloadedDBComplete ) && empty( $progress->downloadedFULLComplete ) ) {
                    $task = $this->get_backup_task_by_id( $progress->task_id );
                    if ( $task && ( 'full' === $task->type ) && ! $task->paused ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Method remove_backup_task()
     *
     * Remove backup task. The task's per-site progress rows leave with it -
     * they key on the task id and are unreachable once the task row is gone.
     *
     * @param mixed $id Task ID.
     *
     * @return void
     */
    public function remove_backup_task( $id ) {
        $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE id = %d', $id ) );
        $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . esc_sql( $this->table_name( 'wp_backup_progress' ) ) . ' WHERE task_id = %d', $id ) );
    }

    /**
     * Method get_backup_task_by_id()
     *
     * Get backup task by id.
     *
     * @param mixed $id Task ID.
     *
     * @return (object|null) Database query result for Backup Task or null on failure
     */
    public function get_backup_task_by_id( $id ) {
        return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE id= %d ', $id ) );
    }

    /**
     * Method get_backup_tasks_for_user()
     *
     * Get backup tasks for current user.
     *
     * @param string $orderBy Task order.
     *
     * @return object|null Database query result for backup tasks for current user or null on failer.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
     */
    public function get_backup_tasks_for_user( $orderBy = 'name' ) {
        if ( MainWP_System::instance()->is_single_user() ) {
            return $this->get_backup_tasks( null, $orderBy );
        }

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;

        return $this->get_backup_tasks( $current_user->ID, $orderBy );
    }

    /**
     * Method get_backup_tasks()
     *
     * Get backup tasks for current user.
     *
     * @param null   $userid Current user ID.
     * @param string $orderBy Task order.
     *
     * @return (object|null) Database query result for backup tasks for current user or null on failer.
     */
    public function get_backup_tasks( $userid = null, $orderBy = null ) {
        return $this->wpdb->get_results( 'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE ' . ( null === $userid ? '' : 'userid= ' . absint( $userid ) . ' AND ' ) . ' template = 0 ' . ( null !== $orderBy ? 'ORDER BY ' . esc_sql( $orderBy ) : '' ), OBJECT );
    }

    /**
     * Method add_backup_task()
     *
     * Add backup task.
     *
     * @param mixed $userid                         Current user ID.
     * @param mixed $name                           Name of backup.
     * @param mixed $schedule                       Backup schedual.
     * @param mixed $type                           Type of backup, full|db.
     * @param mixed $exclude                        Files or directories to exclude.
     * @param mixed $sites                          Child Sites to backup.
     * @param mixed $groups                         Groups to backup.
     * @param mixed $subfolder                      Folder the backups are going into.
     * @param mixed $filename                       Filename of the backups.
     * @param mixed $template                       Backup template.
     * @param mixed $excludebackup                  Backup files to exclude.
     * @param mixed $excludecache                   Cache files to exclude.
     * @param mixed $excludenonwp                   Non-wp files to exclude.
     * @param mixed $excludezip                     Archives to exclude.
     * @param mixed $archiveFormat                  Format to store backups in.
     * @param mixed $maximumFileDescriptorsOverride Overide maximum file descriptors.
     * @param mixed $maximumFileDescriptorsAuto     Auto maximum file discriptors.
     * @param mixed $maximumFileDescriptors         Maximum file descriptors.
     * @param mixed $loadFilesBeforeZip             Load files before Zip.
     * @param mixed $zip_force_override             Keep Zip over the large-site guardrail (1) instead of auto-switching to tar.gz (0).
     * @param mixed $manual_site_id                 Site identity for hidden on-demand tasks; NULL for scheduled tasks.
     *
     * @return object|false The created task row, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses \MainWP\Dashboard\MainWP_Utility::remove_preslash_spaces()
     */
    public function add_backup_task(
        $userid,
        $name,
        $schedule,
        $type,
        $exclude,
        $sites,
        $groups,
        $subfolder,
        $filename,
        $template,
        $excludebackup,
        $excludecache,
        $excludenonwp,
        $excludezip,
        $archiveFormat,
        $maximumFileDescriptorsOverride,
        $maximumFileDescriptorsAuto,
        $maximumFileDescriptors,
        $loadFilesBeforeZip,
        $zip_force_override = 0,
        $manual_site_id = null
    ) {

        // to fix null value.
        if ( empty( $exclude ) ) {
            $exclude = '';
        }

        if ( MainWP_Utility::ctype_digit( $userid ) ) {
            $values = array(
                'userid'                         => $userid,
                'name'                           => $name,
                'schedule'                       => $schedule,
                'type'                           => $type,
                'exclude'                        => $exclude,
                'sites'                          => $sites,
                'groups'                         => $groups,
                'last'                           => 0,
                'last_run'                       => 0,
                'last_run_manually'              => 0,
                'completed_sites'                => '',
                'completed'                      => 0,
                'backup_errors'                  => '',
                'backup_notices'                 => '',
                'subfolder'                      => MainWP_Utility::remove_preslash_spaces( $subfolder ),
                'filename'                       => $filename,
                'paused'                         => 0,
                'template'                       => $template,
                'excludebackup'                  => $excludebackup,
                'excludecache'                   => $excludecache,
                'excludenonwp'                   => $excludenonwp,
                'excludezip'                     => $excludezip,
                'archiveFormat'                  => $archiveFormat,
                'loadFilesBeforeZip'             => $loadFilesBeforeZip,
                'maximumFileDescriptorsOverride' => $maximumFileDescriptorsOverride,
                'maximumFileDescriptorsAuto'     => $maximumFileDescriptorsAuto,
                'maximumFileDescriptors'         => $maximumFileDescriptors,
                'zip_force_override'             => $zip_force_override,
                'manual_site_id'                 => $manual_site_id,
            );

            if ( $this->wpdb->insert( $this->table_name( 'wp_backup' ), $values ) ) {
                return $this->get_backup_task_by_id( $this->wpdb->insert_id );
            }
        }

        return false;
    }

    /**
     * Method update_backup_task()
     *
     * Update backup task.
     *
     * @param mixed $id task id.
     * @param mixed $userid Current user ID.
     * @param mixed $name Name of backup.
     * @param mixed $schedule Backup schedual.
     * @param mixed $type Type of backup, full|db.
     * @param mixed $exclude Files or directories to exclude.
     * @param mixed $sites Child Sites to backup.
     * @param mixed $groups Groups to backup.
     * @param mixed $subfolder Folder the backups are going into.
     * @param mixed $filename Filename of the backups.
     * @param mixed $excludebackup Backup files to exclude.
     * @param mixed $excludecache Cache files to exclude.
     * @param mixed $excludenonwp Non-wp files to exclude.
     * @param mixed $excludezip Archives to exclude.
     * @param mixed $archiveFormat Format to store backups in.
     * @param mixed $maximumFileDescriptorsOverride Overide maximum file descriptors.
     * @param mixed $maximumFileDescriptorsAuto Auto maximum file discriptors.
     * @param mixed $maximumFileDescriptors Maximum file descriptors.
     * @param mixed $loadFilesBeforeZip Load files before Zip.
     * @param mixed $zip_force_override Keep Zip over the large-site guardrail (1) instead of auto-switching to tar.gz (0).
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses \MainWP\Dashboard\MainWP_Utility::remove_preslash_spaces()
     */
    public function update_backup_task(
        $id,
        $userid,
        $name,
        $schedule,
        $type,
        $exclude,
        $sites,
        $groups,
        $subfolder,
        $filename,
        $excludebackup,
        $excludecache,
        $excludenonwp,
        $excludezip,
        $archiveFormat,
        $maximumFileDescriptorsOverride,
        $maximumFileDescriptorsAuto,
        $maximumFileDescriptors,
        $loadFilesBeforeZip,
        $zip_force_override = 0
    ) {

        if ( MainWP_Utility::ctype_digit( $userid ) && MainWP_Utility::ctype_digit( $id ) ) {
            return $this->wpdb->update(
                $this->table_name( 'wp_backup' ),
                array(
                    'userid'                         => $userid,
                    'name'                           => $name,
                    'schedule'                       => $schedule,
                    'type'                           => $type,
                    'exclude'                        => $exclude,
                    'sites'                          => $sites,
                    'groups'                         => $groups,
                    'subfolder'                      => MainWP_Utility::remove_preslash_spaces( $subfolder ),
                    'filename'                       => $filename,
                    'excludebackup'                  => $excludebackup,
                    'excludecache'                   => $excludecache,
                    'excludenonwp'                   => $excludenonwp,
                    'excludezip'                     => $excludezip,
                    'archiveFormat'                  => $archiveFormat,
                    'loadFilesBeforeZip'             => $loadFilesBeforeZip,
                    'maximumFileDescriptorsOverride' => $maximumFileDescriptorsOverride,
                    'maximumFileDescriptorsAuto'     => $maximumFileDescriptorsAuto,
                    'maximumFileDescriptors'         => $maximumFileDescriptors,
                    'zip_force_override'             => $zip_force_override,
                ),
                array( 'id' => $id )
            );
        }

        return false;
    }

    /**
     * Method update_backup_task_with_values()
     *
     * Update backup task with values.
     *
     * @param mixed $id Task ID.
     * @param mixed $values values to update.
     *
     * @return (int|false) The number of rows updated, or false on error.
     */
    public function update_backup_task_with_values( $id, $values ) {
        if ( ! is_array( $values ) ) {
            return false;
        }

        return $this->wpdb->update( $this->table_name( 'wp_backup' ), $values, array( 'id' => $id ) );
    }

    /**
     * Preserve schedule edits made after a cron reader loaded the task.
     *
     * @param int $id          Task id.
     * @param int $observed    Previously read next_run.
     * @param int $replacement Computed next_run.
     *
     * @return int|false Rows updated, or false on error.
     */
    public function update_backup_task_next_run( $id, $observed, $replacement ) {
        return $this->wpdb->update(
            $this->table_name( 'wp_backup' ),
            array( 'next_run' => $replacement ),
            array(
                'id'       => $id,
                'next_run' => $observed,
            )
        );
    }

    /**
     * Method get_manual_backup_task()
     *
     * Fetch the per-site hidden on-demand ("manual") backup task, if it
     * exists. The unique site identity keeps concurrent requests attached
     * to the same task and snapshot history.
     *
     * @param int $site_id Child site id.
     *
     * @return object|null The task row, or null when none exists.
     */
    public function get_manual_backup_task( $site_id ) {
        $site_id = (int) $site_id;

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE manual_site_id = %d LIMIT 1',
                $site_id
            ),
            OBJECT
        );
    }

    /**
     * Method ensure_manual_backup_task()
     *
     * Get-or-create the per-site hidden on-demand backup task and refresh
     * its destinations and retention on every call. The task is a
     * standalone full backup (never incremental) so each on-demand
     * snapshot restores on its own; it runs as the site owner so the
     * executor permission check passes; and it stays off the schedule list
     * (template = 1) and out of the schedulers (schedule 'manual',
     * next_run 0), driven only on demand and by the existing continue cron.
     *
     * @param object $website Child site object (needs id and userid).
     *
     * @return object|false The refreshed task row, or false on failure.
     */
    public function ensure_manual_backup_task( $website ) {
        if ( empty( $website ) || empty( $website->id ) ) {
            return false;
        }

        $site_id = (int) $website->id;
        $task    = $this->get_manual_backup_task( $site_id );

        if ( empty( $task ) ) {
            $suppress = $this->wpdb->suppress_errors();
            try {
                $created = $this->add_backup_task(
                    (int) $website->userid,
                    'On-demand backup',
                    'manual',
                    'full',
                    '',
                    (string) $site_id,
                    '',
                    '',
                    '',
                    1,
                    1,
                    1,
                    0,
                    0,
                    'site',
                    0,
                    1,
                    150,
                    0,
                    0,
                    $site_id
                );
            } finally {
                $this->wpdb->suppress_errors( $suppress );
            }
            if ( false === $created && empty( $this->get_manual_backup_task( $site_id ) ) ) {
                return false;
            }
            if ( ! empty( $created ) ) {
                // The on-demand caller authorized this one site at creation.
                $this->update_backup_task_with_values( (int) $created->id, array( 'authorized_sites' => (string) $site_id ) );
            }
            $task = $this->get_manual_backup_task( $site_id );
        }

        if ( empty( $task ) ) {
            return false;
        }

        // Refresh destinations and retention each run so a settings or
        // schedule change takes effect on the next on-demand backup.
        $this->update_backup_task_with_values(
            (int) $task->id,
            array(
                'destinations'     => wp_json_encode( $this->manual_backup_task_destinations( $site_id ) ),
                'retention_count'  => MainWP_Backup_Settings::get_on_demand_retention_count(),
                'next_run'         => 0,
                'paused'           => 0,
                'max_chain_length' => 0,
            )
        );

        return $this->get_manual_backup_task( $site_id );
    }

    /**
     * Method manual_backup_task_destinations()
     *
     * Build the offsite destinations an on-demand backup should use for a
     * site: the deduped union of the destinations configured on every
     * scheduled task that targets the site, whether the site is listed
     * directly or reached through a group. An empty result means the site
     * has no configured offsite destination, so the on-demand backup stays
     * local on the dashboard.
     *
     * @param int $site_id Child site id.
     *
     * @return array List of destination ids (integers), deduped.
     */
    public function manual_backup_task_destinations( $site_id ) {
        $site_id = (int) $site_id;
        $union   = array();

        $tasks = $this->get_backup_tasks();
        if ( empty( $tasks ) ) {
            return array();
        }

        foreach ( $tasks as $task ) {
            if ( empty( $task->destinations ) || ! $this->task_targets_site( $task, $site_id ) ) {
                continue;
            }
            $ids = json_decode( (string) $task->destinations, true );
            if ( is_array( $ids ) ) {
                foreach ( $ids as $id ) {
                    $union[] = (int) $id;
                }
            }
        }

        return array_values( array_unique( $union ) );
    }

    /**
     * Method task_targets_site()
     *
     * Whether a scheduled task backs up a given site, either listed
     * directly in its sites column or reached through one of its groups.
     *
     * @param object $task    Backup task row.
     * @param int    $site_id Child site id.
     *
     * @return bool True when the task targets the site.
     */
    protected function task_targets_site( $task, $site_id ) {
        $site_id = (int) $site_id;

        if ( ! empty( $task->sites ) ) {
            foreach ( explode( ',', (string) $task->sites ) as $id ) {
                if ( (int) $id === $site_id ) {
                    return true;
                }
            }
        }

        if ( ! empty( $task->groups ) ) {
            foreach ( explode( ',', (string) $task->groups ) as $group_id ) {
                $group_sites = $this->get_websites_by_group_id( $group_id );
                if ( empty( $group_sites ) ) {
                    continue;
                }
                foreach ( $group_sites as $group_site ) {
                    if ( (int) $group_site->id === $site_id ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Method update_backup_run()
     *
     * Update backup run. The new last_run lands strictly after the
     * previous close: a reopen in the same clock second as that close
     * would leave completed = last_run, and the close gate
     * (completed < last_run) could never fire again - the run wedges
     * open forever.
     *
     * @param mixed $id Task ID.
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_backup_run( $id ) {
        if ( MainWP_Utility::ctype_digit( $id ) ) {
            $now = time();
            return $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the stamp must be computed against the row's own completed value.
                $this->wpdb->prepare(
                    'UPDATE ' . $this->table_name( 'wp_backup' ) . " SET last_run = GREATEST( %d, completed + 1 ), last = %d, completed_sites = '[]' WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                    $now,
                    $now,
                    $id
                )
            );
        }

        return false;
    }

    /**
     * Method claim_backup_run()
     *
     * Open a new run by stamping last_run, refusably. With no settle
     * window this is the scheduled cron path's stamp: it refuses a paused
     * task in the same statement that opens the run, so a pause landing
     * after the loop's re-read still takes effect, and it keeps recovering
     * a stuck open run. With
     * a settle window (the on-demand path) the stamp is a single guarded
     * UPDATE that refuses when another driver already holds the run open,
     * or when the previous run closed successfully within the window - a
     * duplicate initiate must not reopen the run that just finished and
     * poison its freshness check. A run that closed with errors may
     * retry immediately; failure retries are never debounced. Either way
     * the new last_run lands strictly after the previous close, so the
     * close gate (completed < last_run) stays reachable even for a
     * same-second retry.
     *
     * @param mixed $id            Task ID.
     * @param int   $grace_seconds Settle window in seconds; 0 allows unpaused open runs.
     * @param int   $claimed_started Exact timestamp written by this claim, or zero on refusal.
     *
     * @return bool True when this caller opened the run.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function claim_backup_run( $id, $grace_seconds = 0, &$claimed_started = 0 ) {
        $claimed_started = 0;
        if ( ! MainWP_Utility::ctype_digit( $id ) ) {
            return false;
        }

        $previous = $this->get_backup_task_by_id( $id );
        if ( empty( $previous ) ) {
            return false;
        }
        $now = time();

        // Compare both stamps so GREATEST uses the close we read. A lost race refuses the claim.
        if ( (int) $grace_seconds <= 0 ) {
            $claimed = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the pause guard and run identity must be atomic with the claim.
                $this->wpdb->prepare(
                    'UPDATE ' . $this->table_name( 'wp_backup' ) . " SET last_run = GREATEST( %d, completed + 1, last_run + 1 ), last = %d, completed_sites = '[]' WHERE id = %d AND paused = 0 AND last_run = %d AND completed = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                    $now,
                    $now,
                    $id,
                    $previous->last_run,
                    $previous->completed
                )
            );
        } else {
            $claimed = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the atomic claim gate; must hit the DB engine.
                $this->wpdb->prepare(
                    'UPDATE ' . $this->table_name( 'wp_backup' ) . " SET last_run = GREATEST( %d, completed + 1 ), last = %d, completed_sites = '[]' WHERE id = %d AND paused = 0 AND completed >= last_run AND ( completed < %d OR backup_errors <> '' ) AND last_run = %d AND completed = %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                    $now,
                    $now,
                    $id,
                    $now - (int) $grace_seconds,
                    $previous->last_run,
                    $previous->completed
                )
            );
        }

        // Only a successful guarded UPDATE owns the new identity.
        if ( 1 !== (int) $claimed ) {
            return false;
        }
        $claimed_started = max( $now, (int) $previous->completed + 1, (int) $previous->last_run + 1 );
        return true;
    }

    /**
     * Method update_backup_run_manually()
     *
     * Update backup run manually.
     *
     * @param mixed $id Task ID.
     * @param int   $started Restrict the stamp to this run when supplied.
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_backup_run_manually( $id, $started = 0 ) {
        if ( MainWP_Utility::ctype_digit( $id ) ) {
            $where = array( 'id' => $id );
            if ( $started > 0 ) {
                $where['last_run'] = $started;
            }
            return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'last_run_manually' => $started > 0 ? $started : time() ), $where );
        }

        return false;
    }

    /**
     * Method update_backup_completed()
     *
     * Close the open run by stamping completed, guarded so exactly one of
     * several concurrent drivers reaching the close block wins. The winner
     * owns the close side effects (the failure email); a repeat close of an
     * already-closed run is a no-op, which is what lets backup_errors stay
     * on the row for the on-demand poll to surface without the email firing
     * again.
     *
     * @param mixed $id Task ID.
     *
     * @return bool True when this call closed an open run.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_backup_completed( $id ) {
        if ( ! MainWP_Utility::ctype_digit( $id ) ) {
            return false;
        }

        $closed = $this->wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the atomic close gate; must hit the DB engine.
            $this->wpdb->prepare(
                'UPDATE ' . $this->table_name( 'wp_backup' ) . ' SET completed = GREATEST(%d, last_run) WHERE id = %d AND completed < last_run', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                time(),
                $id
            )
        );

        return 1 === (int) $closed;
    }

    /**
     * Method has_live_child_task_for_run()
     *
     * Whether any site under this task still carries a creating marker for
     * the current run cycle - a create request to a child that has not
     * resolved yet. Rows are scoped to dtsFetched at or after the task's
     * last_run so leftovers from earlier runs never count, and a marker
     * past its window reads as abandoned rather than live, so an orphaned
     * marker can never hold the close hostage. Real child task ids never
     * count either: a run that exhausted its retries must still close.
     *
     * @param mixed $id Task ID.
     *
     * @return bool True when a live create marker exists for the current cycle.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses \MainWP\Dashboard\MainWP_Backup_Executor::is_creating_marker()
     */
    public function has_live_child_task_for_run( $id ) {
        if ( ! MainWP_Utility::ctype_digit( $id ) ) {
            return false;
        }

        $task = $this->get_backup_task_by_id( $id );
        if ( ! $task ) {
            return false;
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT child_backup_task_id, dtsFetched FROM ' . $this->table_name( 'wp_backup_progress' ) . ' WHERE task_id = %d AND dtsFetched >= %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table_name() returns the prefixed identifier.
                $id,
                (int) $task->last_run
            )
        );

        if ( ! is_array( $rows ) ) {
            return false;
        }

        foreach ( $rows as $row ) {
            if ( MainWP_Backup_Executor::is_creating_marker( $row->child_backup_task_id )
                && ( time() - (int) $row->dtsFetched ) < MainWP_Backup_Executor::CREATE_MARKER_TTL_SECONDS ) {
                return true;
            }
        }

        return false;
    }

    /**
     * How long a downloaded-but-unrecorded artifact counts as a live,
     * resumable record-pending state, measured from the artifact's mtime.
     * Anchoring on the artifact (not the run-open stamp) keeps the guard
     * alive for sites that finish late in a long fleet run, while still
     * bounding it so a permanently failing record can never wedge a close.
     */
    const RECORD_PENDING_TTL_SECONDS = 900;

    /**
     * Method is_backup_site_resumable_for_run()
     *
     * Whether a site's progress row is still resumable for the task's
     * current open run - the site-loop catch consults this before marking
     * a site complete on an exception, because a terminal-looking throw
     * against a resumable row closes the run mid-flight and loses the
     * snapshot the next cycle would have landed.
     *
     * Two resumable shapes, classified over the row's SET artifact paths
     * so a mixed db+full state cannot misread:
     * - mid_download: a claimed terminal projection (that does not itself
     *   carry a child error - a stored error replays identically forever
     *   and must mark), downloads not complete, retry budget not
     *   exhausted. The caller consumes one attempt per yield so ANY
     *   repeating throw exhausts the budget within the cap.
     * - record_pending: downloads complete, every flagged artifact still
     *   on disk, no snapshot for this run yet, and the newest artifact
     *   younger than RECORD_PENDING_TTL_SECONDS. Not budget-consuming;
     *   the TTL bounds it.
     *
     * @param object $task  Task row (fresh read; uses id and last_run).
     * @param mixed  $wp_id Child site ID.
     *
     * @return string|false 'mid_download', 'record_pending', or false.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Snapshots::get_latest_complete()
     */
    public function is_backup_site_resumable_for_run( $task, $wp_id ) { // phpcs:ignore -- NOSONAR - complex state classification.
        if ( empty( $task ) || empty( $task->id ) || ! MainWP_Utility::ctype_digit( $wp_id ) ) {
            return false;
        }

        $progress = $this->get_backup_task_progress( (int) $task->id, (int) $wp_id );
        if ( empty( $progress ) || (int) $progress->dtsFetched < (int) $task->last_run ) {
            return false;
        }

        $set_paths = array();
        if ( '' !== (string) $progress->downloadedDB ) {
            $set_paths[] = array( (string) $progress->downloadedDB, ! empty( $progress->downloadedDBComplete ) );
        }
        if ( '' !== (string) $progress->downloadedFULL ) {
            $set_paths[] = array( (string) $progress->downloadedFULL, ! empty( $progress->downloadedFULLComplete ) );
        }

        $downloads_complete = ! empty( $set_paths );
        foreach ( $set_paths as $path ) {
            if ( ! $path[1] ) {
                $downloads_complete = false;
                break;
            }
        }

        if ( $downloads_complete ) {
            return $this->classify_record_pending( $task, $progress, $set_paths );
        }

        $claimed = is_array( $progress->fetchResult ) && ! empty( $progress->fetchResult );
        if ( $claimed && empty( $progress->fetchResult['error'] ) && (int) $progress->attempts < 5 ) {
            return 'mid_download';
        }

        return false;
    }

    /**
     * Method classify_record_pending()
     *
     * Whether a downloads-complete row is a live record-pending state:
     * every flagged artifact on disk, no snapshot for the current run,
     * newest artifact inside the TTL window.
     *
     * @param object $task      Task row.
     * @param object $progress  Progress row.
     * @param array  $set_paths Set artifact paths with their complete flags.
     *
     * @return string|false 'record_pending' or false.
     */
    private function classify_record_pending( $task, $progress, $set_paths ) {
        // Terminal record failures must prevent site re-drive and release the run-close guard.
        if ( MainWP_Backup_Task_Phase::FAILED === $progress->child_phase ) {
            return false;
        }

        $newest_mtime = 0;
        foreach ( $set_paths as $path ) {
            clearstatcache( true, $path[0] );
            if ( ! is_file( $path[0] ) ) {
                // A vanished artifact is the loud ERR_FILE_CORRUPT shape,
                // not a resumable state: the honest failure must mark.
                return false;
            }
            $mtime        = (int) filemtime( $path[0] );
            $newest_mtime = $mtime > $newest_mtime ? $mtime : $newest_mtime;
        }

        $snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( (int) $progress->wp_id, (int) $task->id );
        if ( null !== $snapshot && (int) $snapshot->created >= (int) $task->last_run ) {
            // The record already landed; nothing is pending.
            return false;
        }

        if ( ( time() - $newest_mtime ) >= self::RECORD_PENDING_TTL_SECONDS ) {
            // Past the window a failing record is a wedge, not a wait.
            return false;
        }

        return 'record_pending';
    }

    /**
     * Method has_pending_record_for_run()
     *
     * Whether any current-run progress row is a live record-pending
     * state: a validated artifact on disk whose snapshot has not landed
     * yet, inside the TTL window. The run close consults this alongside
     * the live-create guard, because closing over a pending record is
     * how a snapshot gets lost - the record that lands seconds later has
     * no open run to credit. The scan reads progress rows, never
     * completed_sites, so a site wrongly marked complete cannot blind it.
     *
     * @param mixed $id Task ID.
     *
     * @return bool True when a record-pending row exists for the current cycle.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function has_pending_record_for_run( $id ) {
        if ( ! MainWP_Utility::ctype_digit( $id ) ) {
            return false;
        }

        $task = $this->get_backup_task_by_id( $id );
        if ( ! $task ) {
            return false;
        }

        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT wp_id FROM ' . $this->table_name( 'wp_backup_progress' ) . ' WHERE task_id = %d AND dtsFetched >= %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table_name() returns the prefixed identifier.
                $id,
                (int) $task->last_run
            )
        );

        if ( ! is_array( $rows ) ) {
            return false;
        }

        foreach ( $rows as $wp_id ) {
            if ( 'record_pending' === $this->is_backup_site_resumable_for_run( $task, (int) $wp_id ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Method claim_backup_file_sweep()
     *
     * Claim the run's one local-file sweep, SQL-side. The sweep deletes
     * unrecorded archives in the site dir, and the current run's own
     * downloaded artifact is unrecorded until its snapshot lands - so a
     * driver re-running the sweep off a stale in-memory removedFiles
     * view deletes the winner's validated artifact and loses the backup.
     * The conditional write makes the gate the row's truth, not the
     * caller's memory: exactly one driver per run wins it.
     *
     * @param mixed $task_id Task ID.
     * @param mixed $wp_id   Child site ID.
     *
     * @return bool True when this caller won the sweep.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function claim_backup_file_sweep( $task_id, $wp_id ) {
        if ( ! MainWP_Utility::ctype_digit( $task_id ) || ! MainWP_Utility::ctype_digit( $wp_id ) ) {
            return false;
        }

        $affected = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table_name( 'wp_backup_progress' ) . ' SET removedFiles = 1 WHERE task_id = %d AND wp_id = %d AND removedFiles <> 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table_name() returns the prefixed identifier.
                $task_id,
                $wp_id
            )
        );

        return is_numeric( $affected ) && $affected > 0;
    }

    /**
     * Method consume_backup_attempt()
     *
     * Burn one retry attempt on the progress row, SQL-side so racing
     * drivers cannot double-count through a read-modify-write. The
     * site-loop catch calls this on every mid-download resumable yield,
     * which is what bounds a repeating exception to the retry cap.
     *
     * @param mixed $task_id Task ID.
     * @param mixed $wp_id   Child site ID.
     *
     * @return bool Whether a row was updated.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function consume_backup_attempt( $task_id, $wp_id ) {
        if ( ! MainWP_Utility::ctype_digit( $task_id ) || ! MainWP_Utility::ctype_digit( $wp_id ) ) {
            return false;
        }

        $affected = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table_name( 'wp_backup_progress' ) . ' SET attempts = attempts + 1 WHERE task_id = %d AND wp_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table_name() returns the prefixed identifier.
                $task_id,
                $wp_id
            )
        );

        return is_numeric( $affected ) && $affected > 0;
    }

    /**
     * Method claim_backup_fetch_result()
     *
     * Land a completed child task's terminal projection on the progress
     * row only when no other driver has landed one first. Concurrent
     * drivers can poll the same completed child task; the conditional
     * write makes exactly one of them the consumer, so the download and
     * snapshot-record phase is never entered twice for one terminal. An
     * unclaimed row holds either the column default or the entry reset's
     * encoded empty array.
     *
     * @param mixed  $task_id           Task ID.
     * @param mixed  $wp_id             Child site ID.
     * @param string $fetch_result_json Encoded terminal projection.
     *
     * @return bool True when this driver's projection landed, false when another driver already claimed the terminal.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function claim_backup_fetch_result( $task_id, $wp_id, $fetch_result_json ) {
        if ( ! MainWP_Utility::ctype_digit( $task_id ) || ! MainWP_Utility::ctype_digit( $wp_id ) ) {
            return false;
        }

        $affected = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table_name( 'wp_backup_progress' ) . " SET fetchResult = %s WHERE task_id = %d AND wp_id = %d AND ( fetchResult = '' OR fetchResult = '[]' )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table_name() returns the prefixed identifier.
                $fetch_result_json,
                $task_id,
                $wp_id
            )
        );

        return is_numeric( $affected ) && $affected > 0;
    }

    /**
     * Method  update_backup_errors()
     *
     * Update backup errors.
     *
     * @param mixed $id Task ID.
     * @param mixed $errors Backup errors.
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_backup_errors( $id, $errors ) {
        if ( MainWP_Utility::ctype_digit( $id ) ) {
            if ( '' === $errors ) {
                return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'backup_errors' => '' ), array( 'id' => $id ) );
            } else {
                $task = $this->get_backup_task_by_id( $id );

                return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'backup_errors' => $task->backup_errors . $errors ), array( 'id' => $id ) );
            }
        }

        return false;
    }

    /**
     * Method update_backup_notices()
     *
     * Append an informational run note to the task, or clear the channel
     * with an empty string. Notices are advisory (the large-site ZIP
     * auto-switch, a user cancel) and deliberately live apart from
     * backup_errors, whose consumers treat any content as a failed run.
     * A note already present is not appended again: a multi-site task
     * negotiates per site and would otherwise repeat the same message.
     *
     * @param mixed  $id      Task ID.
     * @param string $notices Note to append, or '' to clear.
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_backup_notices( $id, $notices ) {
        if ( ! MainWP_Utility::ctype_digit( $id ) ) {
            return false;
        }

        if ( '' === $notices ) {
            return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'backup_notices' => '' ), array( 'id' => $id ) );
        }

        return $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . esc_sql( $this->table_name( 'wp_backup' ) ) . " SET backup_notices = CONCAT( COALESCE( backup_notices, '' ), %s ) WHERE id = %d AND ( backup_notices IS NULL OR backup_notices NOT LIKE CAST( %s AS BINARY ) )",
                $notices,
                $id,
                '%' . $this->wpdb->esc_like( $notices ) . '%'
            )
        );
    }

    /**
     * Method update_completed_sites()
     *
     * Update completed sites.
     *
     * @param mixed $id Task ID.
     * @param mixed $completedSites Completed sites.
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_completed_sites( $id, $completedSites ) {
        if ( MainWP_Utility::ctype_digit( $id ) ) {
            return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'completed_sites' => wp_json_encode( $completedSites ) ), array( 'id' => $id ) );
        }

        return false;
    }

    /**
     * Method get_backup_tasks_to_complete()
     *
     * Get backup tasks to complete.
     *
     * @return object Backup tasks.
     */
    public function get_backup_tasks_to_complete() {
        return $this->wpdb->get_results( 'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE paused = 0 AND completed < last_run', OBJECT );
    }

    /**
     * Method get_backup_tasks_due_by_next_run()
     *
     * Get tasks whose computed next_run has arrived. Legacy elapsed-mode
     * tasks carry next_run = 0 and stay on the threshold queries below.
     *
     * @return object[] Backup tasks.
     */
    public function get_backup_tasks_due_by_next_run() {
        $table_wp_backup = esc_sql( $this->table_name( 'wp_backup' ) );
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM `{$table_wp_backup}` WHERE paused = 0 AND next_run > 0 AND next_run <= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name escaped via esc_sql, cutoff prepared.
                time()
            ),
            OBJECT
        );
    }

    /**
     * Method get backup tasks daily.
     *
     * Get daily backup tasks.
     *
     * @return object Backup tasks.
     */
    public function get_backup_tasks_todo_daily() {
        return $this->wpdb->get_results( 'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE paused = 0 AND schedule="daily" AND ' . absint( time() ) . ' - last_run >= ' . ( 60 * 60 * 24 ), OBJECT );
    }

    /**
     * Method get backup tasks weekly.
     *
     * Get weekly backup tasks.
     *
     * @return object Backup tasks.
     */
    public function get_backup_tasks_todo_weekly() {
        return $this->wpdb->get_results( 'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE paused = 0 AND schedule="weekly" AND ' . absint( time() ) . ' - last_run >= ' . ( 60 * 60 * 24 * 7 ), OBJECT );
    }

    /**
     * Method get backup tasks monthly.
     *
     * Get monthly backup tasks.
     *
     * @return object Backup tasks.
     */
    public function get_backup_tasks_todo_monthly() {
        return $this->wpdb->get_results( 'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE paused = 0 AND schedule="monthly" AND ' . absint( time() ) . ' - last_run >= ' . ( 60 * 60 * 24 * 30 ), OBJECT );
    }
}
