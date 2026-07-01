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
     * Method add_backup_task_progress()
     *
     * Add backup task progress.
     *
     * @param mixed $task_id Task ID.
     * @param mixed $wp_id Child Site ID.
     * @param mixed $information Task info to add.
     *
     * @return (array|null) $this->get_backup_task_progress() or null on failure
     */
    public function add_backup_task_progress( $task_id, $wp_id, $information ) {
        $values = array(
            'task_id'        => $task_id,
            'wp_id'          => $wp_id,
            'dtsFetched'     => time(),
            'fetchResult'    => wp_json_encode( $information ),
            'removedFiles'   => 0,
            'downloadedDB'   => '',
            'downloadedFULL' => '',
        );

        if ( $this->wpdb->insert( $this->table_name( 'wp_backup_progress' ), $values ) ) {
            return $this->get_backup_task_progress( $task_id, $wp_id );
        }

        return null;
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
     * Remove backup task.
     *
     * @param mixed $id Task ID.
     *
     * @return void
     */
    public function remove_backup_task( $id ) {
        $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . ' WHERE id = %d', $id ) );
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
     *
     * @return int|false The number of rows added, or false on error.
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
        $zip_force_override = 0
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
     * Method get_manual_backup_task()
     *
     * Fetch the per-site hidden on-demand ("manual") backup task, if it
     * exists. The manual task is identified by template = 1, schedule =
     * 'manual', and a single-site sites value.
     *
     * @param int $site_id Child site id.
     *
     * @return object|null The task row, or null when none exists.
     */
    public function get_manual_backup_task( $site_id ) {
        $site_id = (int) $site_id;

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $this->table_name( 'wp_backup' ) ) . " WHERE template = 1 AND schedule = 'manual' AND sites = %s LIMIT 1",
                (string) $site_id
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
            // Hold a short lock so a double-click cannot create two rows.
            $lock_key = 'mwp_manual_backup_task_' . $site_id;
            if ( false === get_transient( $lock_key ) ) {
                set_transient( $lock_key, 1, 10 );
                $task = $this->get_manual_backup_task( $site_id );
                if ( empty( $task ) ) {
                    $this->add_backup_task(
                        (int) $website->userid,
                        'On-demand backup',
                        'manual',
                        'full',
                        '',                // exclude.
                        (string) $site_id, // sites.
                        '',                // groups.
                        '',                // subfolder.
                        '',                // filename.
                        1,                 // template: hidden from the schedule list.
                        1,                 // excludebackup.
                        1,                 // excludecache.
                        0,                 // excludenonwp.
                        0,                 // excludezip.
                        'site',            // archiveFormat: follow the site's format.
                        0,                 // maximumFileDescriptorsOverride.
                        1,                 // maximumFileDescriptorsAuto.
                        150,               // maximumFileDescriptors.
                        0                  // loadFilesBeforeZip.
                    );
                }
                delete_transient( $lock_key );
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
     * Update backup run.
     *
     * @param mixed $id Task ID.
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_backup_run( $id ) {
        if ( MainWP_Utility::ctype_digit( $id ) ) {
            return $this->wpdb->update(
                $this->table_name( 'wp_backup' ),
                array(
                    'last_run'        => time(),
                    'last'            => time(),
                    'completed_sites' => wp_json_encode( array() ),
                ),
                array( 'id' => $id )
            );
        }

        return false;
    }

    /**
     * Method update_backup_run_manually()
     *
     * Update backup run manually.
     *
     * @param mixed $id Task ID.
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_backup_run_manually( $id ) {
        if ( MainWP_Utility::ctype_digit( $id ) ) {
            return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'last_run_manually' => time() ), array( 'id' => $id ) );
        }

        return false;
    }

    /**
     * Method update_backup_completed()
     *
     * Update backup completed()
     *
     * @param mixed $id Task ID.
     *
     * @return int|false The number of rows updated, or false on error.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function update_backup_completed( $id ) {
        if ( MainWP_Utility::ctype_digit( $id ) ) {
            return $this->wpdb->update( $this->table_name( 'wp_backup' ), array( 'completed' => time() ), array( 'id' => $id ) );
        }

        return false;
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
