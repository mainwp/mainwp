<?php
/**
 * Native backup abilities expose authorized evidence without advancing work.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/** Native backup reads share the service's authorization and refusal rules. */
class MainWP_Abilities_Backups {

    /**
     * Summary reads skip granular rights while retaining owner and site checks.
     *
     * @var array<string,string>
     */
    const READ_RIGHTS = array(
        'mainwp/get-backup-status-v1'        => 'execute_backups',
        'mainwp/list-backup-history-v1'      => 'execute_backups',
        'mainwp/list-backup-destinations-v1' => 'edit_backup_tasks',
        'mainwp/get-backup-destination-v1'   => 'edit_backup_tasks',
        'mainwp/get-backup-settings-v1'      => 'manage_dashboard_settings',
        'mainwp/list-backup-tasks-v1'        => '',
        'mainwp/get-backup-task-v1'          => 'edit_backup_tasks',
        'mainwp/get-backup-task-run-v1'      => 'run_backup_tasks',
        'mainwp/get-site-backup-progress-v1' => 'execute_backups',
        'mainwp/list-backup-snapshots-v1'    => 'execute_backups',
        'mainwp/get-backup-snapshot-v1'      => 'execute_backups',
        'mainwp/list-restore-jobs-v1'        => 'execute_backups',
        'mainwp/get-restore-job-v1'          => 'execute_backups',
    );

    /**
     * Reads remain available after native backups are disabled.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        self::register_get_backup_status();
        self::register_list_backup_history();
        self::register_list_backup_destinations();
        self::register_get_backup_destination();
        self::register_get_backup_settings();
        self::register_list_backup_tasks();
        self::register_get_backup_task();
        self::register_get_backup_task_run();
        self::register_get_site_backup_progress();
        self::register_list_backup_snapshots();
        self::register_get_backup_snapshot();
        self::register_list_restore_jobs();
        self::register_get_restore_job();
    }

    /**
     * Keep the read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_list_backup_tasks(): void {
        wp_register_ability(
            'mainwp/list-backup-tasks-v1',
            array(
                'label'               => __( 'List Backup Tasks', 'mainwp' ),
                'description'         => __( 'Scheduled backup tasks you can access, with schedule, pause state, whether a run is open, and next and last run times. Filter by status or by a site. The hidden on-demand task is never listed. Possible errors: mainwp_site_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::list_backup_tasks_input_schema(),
                'output_schema'       => self::list_backup_tasks_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_backup_tasks' ),
                'permission_callback' => self::permission_callback( 'mainwp/list-backup-tasks-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => '',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_backup_tasks_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'additionalProperties' => false,
            'properties'           => array(
                'status'            => array(
                    'type'        => 'string',
                    'enum'        => array( 'any', 'active', 'paused', 'running' ),
                    'default'     => 'any',
                    'description' => 'active: not paused. running: the subset of active tasks with an open run. paused: paused tasks.',
                ),
                'site_id_or_domain' => self::site_id_or_domain_schema(),
                'page'              => self::page_schema(),
                'per_page'          => self::per_page_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_backup_tasks_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'items', 'total', 'page', 'per_page' ),
            'properties'           => array(
                'items'    => array(
                    'type'  => 'array',
                    'items' => self::task_summary_schema(),
                ),
                'total'    => array(
                    'type' => 'integer',
                ),
                'page'     => array(
                    'type' => 'integer',
                ),
                'per_page' => array(
                    'type' => 'integer',
                ),
            ),
        );
    }

    /**
     * Keep the read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_get_backup_task(): void {
        wp_register_ability(
            'mainwp/get-backup-task-v1',
            array(
                'label'               => __( 'Get Backup Task', 'mainwp' ),
                'description'         => __( 'Full configuration of one task: schedule, sites and groups, destinations, retention, archive settings, excludes, owner, and the errors and notices of its current or last run. Possible errors: mainwp_backup_task_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::get_backup_task_input_schema(),
                'output_schema'       => self::get_backup_task_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_backup_task' ),
                'permission_callback' => self::permission_callback( 'mainwp/get-backup-task-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => '',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_task_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'task_id' ),
            'properties'           => array(
                'task_id' => self::task_id_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_task_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'task', 'schedule_detail', 'scope', 'destinations', 'retention', 'archive_format', 'exclude', 'exclude_flags', 'archive_tuning', 'owner_user_id', 'last_run_summary' ),
            'properties'           => array(
                'task'             => self::task_summary_schema(),
                'schedule_detail'  => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'interval_hours', 'time_of_day', 'day_of_week', 'day_of_month' ),
                    'properties'           => array(
                        'interval_hours' => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 1,
                            'maximum' => 8760,
                        ),
                        'time_of_day'    => array(
                            'type'    => array( 'string', 'null' ),
                            'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$',
                        ),
                        'day_of_week'    => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                            'maximum' => 6,
                        ),
                        'day_of_month'   => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 1,
                            'maximum' => 28,
                        ),
                    ),
                ),
                'scope'            => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'site_ids', 'group_ids', 'authorized_site_ids' ),
                    'properties'           => array(
                        'site_ids'            => array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'integer',
                            ),
                        ),
                        'group_ids'           => array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'integer',
                            ),
                        ),
                        'authorized_site_ids' => array(
                            'type'        => array( 'array', 'null' ),
                            'items'       => array(
                                'type' => 'integer',
                            ),
                            'description' => 'The site set the scheduled runner backs up, stamped at the last save. Null when never stamped.',
                        ),
                    ),
                ),
                'destinations'     => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'id', 'name', 'provider' ),
                        'properties'           => array(
                            'id'       => array(
                                'type' => 'integer',
                            ),
                            'name'     => array(
                                'type' => 'string',
                            ),
                            'provider' => array(
                                'type' => 'string',
                                'enum' => array( 's3', 'gcs', 'dropbox', 'sftp', 'local' ),
                            ),
                        ),
                    ),
                ),
                'retention'        => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'count', 'days', 'storage_mb', 'delete_cloud_copies', 'max_chain_length' ),
                    'properties'           => array(
                        'count'               => array(
                            'type'        => 'integer',
                            'minimum'     => 0,
                            'description' => '0 means no limit.',
                        ),
                        'days'                => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                        ),
                        'storage_mb'          => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                        ),
                        'delete_cloud_copies' => array(
                            'type' => 'boolean',
                        ),
                        'max_chain_length'    => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                        ),
                    ),
                ),
                'archive_format'   => array(
                    'type' => 'string',
                    'enum' => array( 'site', 'global', 'zip', 'tar', 'tar.gz', 'tar.bz2', 'legacy' ),
                ),
                'exclude'          => array(
                    'type'  => 'array',
                    'items' => array(
                        'type' => 'string',
                    ),
                ),
                'exclude_flags'    => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'backup_archives', 'cache', 'non_wp_folders', 'zip_archives' ),
                    'properties'           => array(
                        'backup_archives' => array(
                            'type' => 'boolean',
                        ),
                        'cache'           => array(
                            'type' => 'boolean',
                        ),
                        'non_wp_folders'  => array(
                            'type' => 'boolean',
                        ),
                        'zip_archives'    => array(
                            'type' => 'boolean',
                        ),
                    ),
                ),
                'archive_tuning'   => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'max_file_descriptors_override', 'max_file_descriptors_auto', 'max_file_descriptors', 'load_files_before_zip', 'zip_force_override' ),
                    'properties'           => array(
                        'max_file_descriptors_override' => array(
                            'type' => 'boolean',
                        ),
                        'max_file_descriptors_auto'     => array(
                            'type' => 'boolean',
                        ),
                        'max_file_descriptors'          => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                        ),
                        'load_files_before_zip'         => array(
                            'type' => 'boolean',
                        ),
                        'zip_force_override'            => array(
                            'type' => 'boolean',
                        ),
                    ),
                ),
                'owner_user_id'    => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                ),
                'last_run_summary' => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'errors', 'notices' ),
                    'properties'           => array(
                        'errors'  => array(
                            'type'        => 'array',
                            'maxItems'    => 50,
                            'items'       => self::safe_error_schema(),
                            'description' => 'Errors of the current or last run only; they reset when a run opens.',
                        ),
                        'notices' => array(
                            'type'     => 'array',
                            'maxItems' => 50,
                            'items'    => self::safe_error_schema(),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Keep the read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_get_backup_task_run(): void {
        wp_register_ability(
            'mainwp/get-backup-task-run-v1',
            array(
                'label'               => __( 'Get Backup Task Run', 'mainwp' ),
                'description'         => __( 'Per-site state of a task\'s current or last run: status, phase, percent, error, the snapshot each site produced and its upload counts. Poll target for run-backup-task-v1. Reading it never advances the run; cron does. Possible errors: mainwp_backup_task_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::get_backup_task_run_input_schema(),
                'output_schema'       => self::get_backup_task_run_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_backup_task_run' ),
                'permission_callback' => self::permission_callback( 'mainwp/get-backup-task-run-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => 'Poll every 60 seconds until status is completed, completed_with_errors or failed. stalled true means the run can be closed with reset-backup-task-run-v1.',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_task_run_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'task_id' ),
            'properties'           => array(
                'task_id' => self::task_id_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_task_run_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'task_id', 'status', 'run_open', 'stalled', 'started_at', 'completed_at', 'sites', 'errors', 'notices' ),
            'properties'           => array(
                'task_id'      => array(
                    'type' => 'integer',
                ),
                'status'       => array(
                    'type' => 'string',
                    'enum' => array( 'never_run', 'running', 'completed', 'completed_with_errors', 'failed' ),
                ),
                'run_open'     => array(
                    'type' => 'boolean',
                ),
                'stalled'      => array(
                    'type'        => 'boolean',
                    'description' => 'Open longer than the 1 hour stall threshold; reset-backup-task-run-v1 can close it.',
                ),
                'started_at'   => self::ts_schema(),
                'completed_at' => self::ts_schema(),
                'sites'        => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'site_id', 'name', 'url', 'status', 'phase', 'percent', 'error', 'snapshot', 'uploads' ),
                        'properties'           => array(
                            'site_id'  => array(
                                'type' => 'integer',
                            ),
                            'name'     => array(
                                'type' => 'string',
                            ),
                            'url'      => array(
                                'type' => 'string',
                            ),
                            'status'   => array(
                                'type' => 'string',
                                'enum' => array( 'pending', 'running', 'completed', 'failed' ),
                            ),
                            'phase'    => array(
                                'type'        => array( 'string', 'null' ),
                                'enum'        => array( 'queued', 'dumping_db', 'archiving_files', 'uploading', 'submitting', 'completed', 'failed', 'unknown', null ),
                                'description' => 'The site\'s backup phase; unknown for a phase a newer MainWP Child reports.',
                            ),
                            'percent'  => array(
                                'type'    => array( 'integer', 'null' ),
                                'minimum' => 0,
                                'maximum' => 100,
                            ),
                            'error'    => self::safe_error_or_null_schema(),
                            'snapshot' => array(
                                'type'                 => array( 'object', 'null' ),
                                'additionalProperties' => false,
                                'required'             => array( 'id', 'type', 'parent_id', 'created_at' ),
                                'properties'           => array(
                                    'id'         => array(
                                        'type' => 'integer',
                                    ),
                                    'type'       => array(
                                        'type' => 'string',
                                        'enum' => array( 'full', 'db', 'files', 'incremental' ),
                                    ),
                                    'parent_id'  => self::id_or_null_schema(),
                                    'created_at' => self::ts_schema(),
                                ),
                            ),
                            'uploads'  => array(
                                'type'                 => array( 'object', 'null' ),
                                'additionalProperties' => false,
                                'required'             => array( 'total', 'complete', 'failed', 'pending' ),
                                'properties'           => array(
                                    'total'    => array(
                                        'type' => 'integer',
                                    ),
                                    'complete' => array(
                                        'type' => 'integer',
                                    ),
                                    'failed'   => array(
                                        'type' => 'integer',
                                    ),
                                    'pending'  => array(
                                        'type' => 'integer',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'errors'       => array(
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => self::safe_error_schema(),
                ),
                'notices'      => array(
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => self::safe_error_schema(),
                ),
            ),
        );
    }

    /**
     * Keep the read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_get_site_backup_progress(): void {
        wp_register_ability(
            'mainwp/get-site-backup-progress-v1',
            array(
                'label'               => __( 'Get Site Backup Progress', 'mainwp' ),
                'description'         => __( 'State of any backup on one site: the on-demand backup (status, phase, percent, files and bytes, snapshot_id on completion, error on failure) and open scheduled runs that cover the site. Poll target for run-site-backup-v1. Reading it never advances the backup. Possible errors: mainwp_site_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::get_site_backup_progress_input_schema(),
                'output_schema'       => self::get_site_backup_progress_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_site_backup_progress' ),
                'permission_callback' => self::permission_callback( 'mainwp/get-site-backup-progress-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => 'Pass since exactly as run-site-backup-v1 returned it. Poll every 30 seconds until on_demand.status is completed or failed. not_started means no run has opened for that since handle; call run-site-backup-v1 again.',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_site_backup_progress_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'site_id_or_domain' ),
            'properties'           => array(
                'site_id_or_domain' => self::site_id_or_domain_schema(),
                'since'             => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'default'     => 0,
                    'description' => 'Opaque handle. Pass back exactly as returned by run-site-backup-v1; it ties the result to that start.',
                ),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_site_backup_progress_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'site_id', 'on_demand', 'scheduled_runs' ),
            'properties'           => array(
                'site_id'        => array(
                    'type' => 'integer',
                ),
                'on_demand'      => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'status', 'phase', 'label', 'percent', 'files_processed', 'files_total', 'bytes_done', 'bytes_expected', 'snapshot_id', 'error', 'notice' ),
                    'properties'           => array(
                        'status'          => array(
                            'type'        => 'string',
                            'enum'        => array( 'idle', 'not_started', 'running', 'completed', 'failed' ),
                            'description' => 'not_started: no run has opened for the since handle you passed. Nothing retries it; call run-site-backup-v1 again.',
                        ),
                        'phase'           => array(
                            'type' => array( 'string', 'null' ),
                            'enum' => array( 'queued', 'dumping_db', 'archiving_files', 'uploading', 'submitting', 'completed', 'failed', 'unknown', null ),
                        ),
                        'label'           => array(
                            'type'        => 'string',
                            'maxLength'   => 200,
                            'description' => 'Fixed Dashboard label for the phase.',
                        ),
                        'percent'         => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                            'maximum' => 100,
                        ),
                        'files_processed' => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                        ),
                        'files_total'     => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                        ),
                        'bytes_done'      => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                        ),
                        'bytes_expected'  => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                        ),
                        'snapshot_id'     => self::id_or_null_schema(),
                        'error'           => self::safe_error_or_null_schema(),
                        'notice'          => self::safe_error_or_null_schema(),
                    ),
                ),
                'scheduled_runs' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'task_id', 'task_name', 'phase', 'percent' ),
                        'properties'           => array(
                            'task_id'   => array(
                                'type' => 'integer',
                            ),
                            'task_name' => array(
                                'type' => 'string',
                            ),
                            'phase'     => array(
                                'type' => array( 'string', 'null' ),
                                'enum' => array( 'queued', 'dumping_db', 'archiving_files', 'uploading', 'submitting', 'completed', 'failed', 'unknown', null ),
                            ),
                            'percent'   => array(
                                'type'    => array( 'integer', 'null' ),
                                'minimum' => 0,
                                'maximum' => 100,
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Keep the read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_list_backup_snapshots(): void {
        wp_register_ability(
            'mainwp/list-backup-snapshots-v1',
            array(
                'label'               => __( 'List Backup Snapshots', 'mainwp' ),
                'description'         => __( 'Snapshots recorded for one site, newest first: type, size, chain parent, whether it can be restored and why not, offsite copy state with its evidence, test-restore result, and upload status per destination. Also reports whether the site is disconnected or has a restore or on-demand backup running. Pruned snapshots appear only with include_pruned. Possible errors: mainwp_site_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::list_backup_snapshots_input_schema(),
                'output_schema'       => self::list_backup_snapshots_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_backup_snapshots' ),
                'permission_callback' => self::permission_callback( 'mainwp/list-backup-snapshots-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => 'restorable is the restore admission check, not file presence. offsite.state verified means a size check passed; no content hash is compared.',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_backup_snapshots_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'site_id_or_domain' ),
            'properties'           => array(
                'site_id_or_domain' => self::site_id_or_domain_schema(),
                'include_pruned'    => array(
                    'type'    => 'boolean',
                    'default' => false,
                ),
                'page'              => self::page_schema(),
                'per_page'          => self::per_page_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_backup_snapshots_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'site_id', 'items', 'total', 'page', 'per_page', 'site_disconnected', 'active_restore_job_id', 'on_demand_backup_running' ),
            'properties'           => array(
                'site_id'                  => array(
                    'type' => 'integer',
                ),
                'items'                    => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'snapshot', 'uploads' ),
                        'properties'           => array(
                            'snapshot' => self::snapshot_summary_schema(),
                            'uploads'  => array(
                                'type'  => 'array',
                                'items' => self::upload_item_schema(),
                            ),
                        ),
                    ),
                ),
                'total'                    => array(
                    'type' => 'integer',
                ),
                'page'                     => array(
                    'type' => 'integer',
                ),
                'per_page'                 => array(
                    'type' => 'integer',
                ),
                'site_disconnected'        => array(
                    'type' => 'boolean',
                ),
                'active_restore_job_id'    => self::id_or_null_schema(),
                'on_demand_backup_running' => array(
                    'type' => 'boolean',
                ),
            ),
        );
    }

    /**
     * Keep the read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_get_backup_snapshot(): void {
        wp_register_ability(
            'mainwp/get-backup-snapshot-v1',
            array(
                'label'               => __( 'Get Backup Snapshot', 'mainwp' ),
                'description'         => __( 'One snapshot in detail: archives by name and size, manifest presence, the chain a restore needs (base first), upload status per destination, and whether WooCommerce metadata was captured. Returns no download link or file path. Possible errors: mainwp_backup_snapshot_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::get_backup_snapshot_input_schema(),
                'output_schema'       => self::get_backup_snapshot_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_backup_snapshot' ),
                'permission_callback' => self::permission_callback( 'mainwp/get-backup-snapshot-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => '',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_snapshot_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'snapshot_id' ),
            'properties'           => array(
                'snapshot_id' => self::snapshot_id_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_snapshot_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'snapshot', 'archives', 'manifest_present', 'chain_ids', 'uploads', 'has_woocommerce_meta' ),
            'properties'           => array(
                'snapshot'             => self::snapshot_summary_schema(),
                'archives'             => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'file', 'size_bytes', 'role' ),
                        'properties'           => array(
                            'file'       => array(
                                'type'        => 'string',
                                'maxLength'   => 255,
                                'description' => 'Basename only.',
                            ),
                            'size_bytes' => array(
                                'type'    => 'integer',
                                'minimum' => 0,
                            ),
                            'role'       => array(
                                'type'      => 'string',
                                'maxLength' => 32,
                            ),
                        ),
                    ),
                ),
                'manifest_present'     => array(
                    'type' => 'boolean',
                ),
                'chain_ids'            => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'integer',
                    ),
                    'description' => 'Snapshot ids a restore of this snapshot needs, base first.',
                ),
                'uploads'              => array(
                    'type'  => 'array',
                    'items' => self::upload_item_schema(),
                ),
                'has_woocommerce_meta' => array(
                    'type' => 'boolean',
                ),
            ),
        );
    }

    /**
     * Keep the read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_list_restore_jobs(): void {
        wp_register_ability(
            'mainwp/list-restore-jobs-v1',
            array(
                'label'               => __( 'List Restore Jobs', 'mainwp' ),
                'description'         => __( 'Restore and rollback jobs for one site or for every site you can access, newest first: status, derived outcome, restore type, safety backup state, cancel confirmation and error. Possible errors: mainwp_site_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::list_restore_jobs_input_schema(),
                'output_schema'       => self::list_restore_jobs_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_restore_jobs' ),
                'permission_callback' => self::permission_callback( 'mainwp/list-restore-jobs-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => '',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_restore_jobs_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'additionalProperties' => false,
            'properties'           => array(
                'site_id_or_domain' => self::site_id_or_domain_schema(),
                'outcome'           => array(
                    'type'    => 'string',
                    'enum'    => array( 'any', 'in_progress', 'completed', 'failed', 'cancelled', 'cancel_unconfirmed' ),
                    'default' => 'any',
                ),
                'page'              => self::page_schema(),
                'per_page'          => self::per_page_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_restore_jobs_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'items', 'total', 'page', 'per_page' ),
            'properties'           => array(
                'items'    => array(
                    'type'  => 'array',
                    'items' => self::restore_job_summary_schema(),
                ),
                'total'    => array(
                    'type' => 'integer',
                ),
                'page'     => array(
                    'type' => 'integer',
                ),
                'per_page' => array(
                    'type' => 'integer',
                ),
            ),
        );
    }

    /**
     * Keep the read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_get_restore_job(): void {
        wp_register_ability(
            'mainwp/get-restore-job-v1',
            array(
                'label'               => __( 'Get Restore Job', 'mainwp' ),
                'description'         => __( 'One restore job: status and outcome, progress counters, next retry time, verification as recorded (site report, site-responds check and its rule, WooCommerce preserve result), partial-state risk with reasons, and whether a rollback is available. Poll target for restore-site-snapshot-v1 and rollback-restore-v1. Reading it never advances the restore. Possible errors: mainwp_backup_restore_job_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::get_restore_job_input_schema(),
                'output_schema'       => self::get_restore_job_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_restore_job' ),
                'permission_callback' => self::permission_callback( 'mainwp/get-restore-job-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => 'Poll every 30 seconds until job.outcome leaves in_progress. Read partial_state_risk before telling the user the site is back: a failed or cancelled restore can leave files or tables partly applied.',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_restore_job_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'restore_job_id' ),
            'properties'           => array(
                'restore_job_id' => self::restore_job_id_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_restore_job_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'job', 'progress', 'next_retry_at', 'verification', 'partial_state_risk', 'partial_state_reasons', 'files_rollback_available' ),
            'properties'           => array(
                'job'                      => self::restore_job_summary_schema(),
                'progress'                 => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'artifact_index', 'artifact_total', 'bytes_transferred', 'bytes_total' ),
                    'properties'           => array(
                        'artifact_index'    => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                        ),
                        'artifact_total'    => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                        ),
                        'bytes_transferred' => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                        ),
                        'bytes_total'       => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                        ),
                    ),
                ),
                'next_retry_at'            => self::ts_schema(),
                'verification'             => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'child_report_present', 'site_responds', 'site_responds_rule', 'woo_preserve_applied', 'woo_orphan_customer_orders' ),
                    'properties'           => array(
                        'child_report_present'       => array(
                            'type'        => 'boolean',
                            'description' => 'The site returned its verification report when it finished applying.',
                        ),
                        'site_responds'              => array(
                            'type'        => array( 'boolean', 'null' ),
                            'description' => 'Null until checked.',
                        ),
                        'site_responds_rule'         => array(
                            'type'        => 'string',
                            'enum'        => array( 'http_200_to_499' ),
                            'description' => 'The check passes on any HTTP status from 200 to 499. A login page or a 404 counts as responding.',
                        ),
                        'woo_preserve_applied'       => array(
                            'type' => array( 'boolean', 'null' ),
                        ),
                        'woo_orphan_customer_orders' => array(
                            'type'    => array( 'integer', 'null' ),
                            'minimum' => 0,
                        ),
                    ),
                ),
                'partial_state_risk'       => array(
                    'type' => 'string',
                    'enum' => array( 'none', 'possible', 'unknown' ),
                ),
                'partial_state_reasons'    => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'child_apply_started', 'no_files_rollback', 'db_applied_after_files', 'cancel_unconfirmed' ),
                    ),
                    'description' => 'child_apply_started: the site began applying the restore. no_files_rollback: the site keeps no copy to undo file changes. db_applied_after_files: files are applied before the database, so a failed database step leaves new files with the old database. cancel_unconfirmed: the site never confirmed it stopped.',
                ),
                'files_rollback_available' => array(
                    'type'        => 'boolean',
                    'enum'        => array( false ),
                    'description' => 'Always false: the site does not roll back files on a failed restore. Undo goes through the safety backup and rollback-restore-v1 (see job.rollback_available).',
                ),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function safe_error_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'code', 'message' ),
            'properties'           => array(
                'code'    => array(
                    'type'      => 'string',
                    'pattern'   => '^mainwp_[a-z0-9_]+$',
                    'maxLength' => 64,
                ),
                'message' => array(
                    'type'      => 'string',
                    'maxLength' => 500,
                ),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function safe_error_or_null_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'additionalProperties' => false,
            'required'             => array( 'code', 'message' ),
            'properties'           => array(
                'code'    => array(
                    'type'      => 'string',
                    'pattern'   => '^mainwp_[a-z0-9_]+$',
                    'maxLength' => 64,
                ),
                'message' => array(
                    'type'      => 'string',
                    'maxLength' => 500,
                ),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function site_id_or_domain_schema(): array {
        return array(
            'type'        => array( 'integer', 'string' ),
            'description' => 'Site ID (integer) or domain/URL (string).',
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function task_id_schema(): array {
        return array(
            'type'        => array( 'integer', 'string' ),
            'minimum'     => 1,
            'pattern'     => '^[1-9][0-9]*$',
            'description' => 'Backup task ID.',
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function snapshot_id_schema(): array {
        return array(
            'type'        => array( 'integer', 'string' ),
            'minimum'     => 1,
            'pattern'     => '^[1-9][0-9]*$',
            'description' => 'Snapshot ID.',
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function restore_job_id_schema(): array {
        return array(
            'type'        => array( 'integer', 'string' ),
            'minimum'     => 1,
            'pattern'     => '^[1-9][0-9]*$',
            'description' => 'Restore job ID.',
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function page_schema(): array {
        return array(
            'type'        => 'integer',
            'minimum'     => 1,
            'default'     => 1,
            'description' => 'Page number (1-based).',
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function per_page_schema(): array {
        return array(
            'type'        => 'integer',
            'minimum'     => 1,
            'maximum'     => 100,
            'default'     => 50,
            'description' => 'Items per page.',
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function ts_schema(): array {
        return array(
            'type'        => array( 'string', 'null' ),
            'description' => 'ISO 8601 UTC timestamp, or null when unknown.',
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function id_or_null_schema(): array {
        return array(
            'type'    => array( 'integer', 'null' ),
            'minimum' => 1,
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function snapshot_evidence_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'recorded_by', 'archive_integrity', 'skipped_files' ),
            'properties'           => array(
                'recorded_by'       => array(
                    'type'        => 'string',
                    'enum'        => array( 'dashboard_after_download' ),
                    'description' => 'status complete is written by the Dashboard when it records the downloaded archives. The site reports no content hash for the main archive.',
                ),
                'archive_integrity' => array(
                    'type'        => 'string',
                    'enum'        => array( 'transfer_hash_only' ),
                    'description' => 'The download hash is taken when the download token is issued. It proves the transfer, not the archive content.',
                ),
                'skipped_files'     => array(
                    'type'        => 'string',
                    'enum'        => array( 'not_reported' ),
                    'description' => 'The site can skip unreadable files without reporting them.',
                ),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function offsite_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'state', 'evidence', 'verified_at' ),
            'properties'           => array(
                'state'       => array(
                    'type'        => 'string',
                    'enum'        => array( 'none', 'pending', 'partial', 'failed', 'verified' ),
                    'description' => 'none: no offsite copy is tracked for this snapshot. verified: every copy passed the check named in evidence.',
                ),
                'evidence'    => array(
                    'type'        => 'string',
                    'enum'        => array( 'remote_size_match', 'none' ),
                    'description' => 'remote_size_match: every non-local copy answered a HEAD request at the expected size; no content hash is compared. none for every state except verified.',
                ),
                'verified_at' => self::ts_schema(),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function test_restore_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'state', 'attempted_at', 'result_at', 'checks', 'scope' ),
            'properties'           => array(
                'state'        => array(
                    'type'        => 'string',
                    'enum'        => array( 'never', 'pass', 'fail', 'unsupported', 'unavailable' ),
                    'description' => 'Result of the last completed test restore. A newer attempt that has not recorded a result does not change it.',
                ),
                'attempted_at' => array_merge(
                    self::ts_schema(),
                    array(
                        'description' => 'When a test restore of this snapshot was last started. Not a result time.',
                    )
                ),
                'result_at'    => array_merge(
                    self::ts_schema(),
                    array(
                        'description' => 'When state was recorded. Null for never, and for results recorded before result times were kept.',
                    )
                ),
                'checks'       => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'db_imported', 'db_has_rows', 'files_extracted' ),
                    ),
                    'description' => 'The checks a test restore of this snapshot\'s type runs: db snapshots get db_imported and db_has_rows, files snapshots get files_extracted, full and incremental get all three. A pass means these checks passed; nothing else.',
                ),
                'scope'        => array(
                    'type'        => 'string',
                    'enum'        => array( 'dashboard_sandbox' ),
                    'description' => 'Checks run in a Dashboard sandbox. They do not show that WordPress boots or that the site could apply the restore.',
                ),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function upload_item_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'destination_id', 'destination_name', 'provider', 'status', 'updated_at', 'last_error' ),
            'properties'           => array(
                'destination_id'   => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'destination_name' => array(
                    'type'        => array( 'string', 'null' ),
                    'description' => 'Null when the destination was deleted.',
                ),
                'provider'         => array(
                    'type'        => array( 'string', 'null' ),
                    'enum'        => array( 's3', 'gcs', 'dropbox', 'sftp', 'local', null ),
                    'description' => 'Null when the destination was deleted before the upload was queued.',
                ),
                'status'           => array(
                    'type' => 'string',
                    'enum' => array( 'pending', 'chunking', 'uploading', 'submitting', 'complete', 'failed_retryable', 'failed_permanent', 'credentials_invalid' ),
                ),
                'updated_at'       => self::ts_schema(),
                'last_error'       => self::safe_error_or_null_schema(),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function snapshot_summary_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'id', 'site_id', 'task_id', 'origin', 'type', 'parent_id', 'created_at', 'status', 'evidence', 'size_bytes', 'encrypted', 'source', 'restorable', 'restore_reason', 'offsite', 'test_restore' ),
            'properties'           => array(
                'id'             => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'site_id'        => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'task_id'        => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                ),
                'origin'         => array(
                    'type' => 'string',
                    'enum' => array( 'scheduled_task', 'on_demand', 'deleted_task' ),
                ),
                'type'           => array(
                    'type' => 'string',
                    'enum' => array( 'full', 'db', 'files', 'incremental' ),
                ),
                'parent_id'      => self::id_or_null_schema(),
                'created_at'     => self::ts_schema(),
                'status'         => array(
                    'type' => 'string',
                    'enum' => array( 'complete', 'pruned' ),
                ),
                'evidence'       => self::snapshot_evidence_schema(),
                'size_bytes'     => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                ),
                'encrypted'      => array(
                    'type' => 'boolean',
                ),
                'source'         => array(
                    'type' => 'string',
                    'enum' => array( 'local', 'remote', 'remote_chunked', 'unavailable' ),
                ),
                'restorable'     => array(
                    'type' => 'boolean',
                ),
                'restore_reason' => array_merge(
                    self::safe_error_or_null_schema(),
                    array(
                        'description' => 'Null when restorable. Otherwise why a restore of this snapshot would be refused, as a mainwp_backup_snapshot_unrestorable_* code.',
                    )
                ),
                'offsite'        => self::offsite_schema(),
                'test_restore'   => self::test_restore_schema(),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function task_summary_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'id', 'name', 'type', 'schedule', 'paused', 'run_open', 'next_run_at', 'last_run_at', 'last_completed_at', 'site_count', 'destination_count' ),
            'properties'           => array(
                'id'                => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'name'              => array(
                    'type' => 'string',
                ),
                'type'              => array(
                    'type'        => 'string',
                    'enum'        => array( 'full', 'db', 'files', 'incremental', 'legacy' ),
                    'description' => 'legacy: a stored backup type outside the supported set.',
                ),
                'schedule'          => array(
                    'type'        => 'string',
                    'enum'        => array( 'daily', 'weekly', 'monthly', 'interval', 'legacy' ),
                    'description' => 'legacy: a stored schedule value outside the supported set. The scheduler never picks such a task up.',
                ),
                'paused'            => array(
                    'type' => 'boolean',
                ),
                'run_open'          => array(
                    'type'        => 'boolean',
                    'description' => 'A run has started and not finished yet.',
                ),
                'next_run_at'       => self::ts_schema(),
                'last_run_at'       => self::ts_schema(),
                'last_completed_at' => self::ts_schema(),
                'site_count'        => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                ),
                'destination_count' => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                ),
            ),
        );
    }

    /**
     * Shared fragments keep repeated fields consistent across reads.
     *
     * @return array
     */
    private static function restore_job_summary_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'id', 'site_id', 'snapshot_id', 'status', 'outcome', 'restore_type', 'is_rollback', 'rollback_of', 'safety_backup', 'rollback_available', 'cancel_requested', 'cancel_confirmation', 'error', 'attempt_count', 'created_at', 'updated_at' ),
            'properties'           => array(
                'id'                  => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'site_id'             => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'snapshot_id'         => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'status'              => array(
                    'type' => 'string',
                    'enum' => array( 'pending', 'preflight', 'pre_snapshot', 'staging', 'transferring', 'running', 'verifying', 'failed_retryable', 'completed', 'failed_permanent', 'cancelled' ),
                ),
                'outcome'             => array(
                    'type'        => 'string',
                    'enum'        => array( 'in_progress', 'completed', 'failed', 'cancelled', 'cancel_unconfirmed' ),
                    'description' => 'cancel_unconfirmed: a cancel was requested but the site never confirmed it stopped restoring within 15 minutes, whether or not the restore had already failed. The site may be partly restored. Read from cancel_confirmation, not from the error code.',
                ),
                'restore_type'        => array(
                    'type' => 'string',
                    'enum' => array( 'full', 'db', 'files' ),
                ),
                'is_rollback'         => array(
                    'type' => 'boolean',
                ),
                'rollback_of'         => self::id_or_null_schema(),
                'safety_backup'       => array(
                    'type' => 'string',
                    'enum' => array( 'not_requested', 'pending', 'taken', 'failed', 'pruned' ),
                ),
                'rollback_available'  => array(
                    'type'        => 'boolean',
                    'description' => 'rollback-restore-v1 would accept this job: its safety backup was taken and is still kept, and the job is in a status a rollback can start from.',
                ),
                'cancel_requested'    => array(
                    'type' => 'boolean',
                ),
                'cancel_confirmation' => array(
                    'type'        => 'string',
                    'enum'        => array( 'not_requested', 'pending', 'confirmed', 'unconfirmed', 'not_needed' ),
                    'description' => 'Whether the site confirmed it stopped after a cancel. not_needed: the job was cancelled before the site had a restore task to stop.',
                ),
                'error'               => array_merge(
                    self::safe_error_or_null_schema(),
                    array(
                        'description' => 'Why the job failed, as a code with its fixed message.',
                    )
                ),
                'attempt_count'       => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                ),
                'created_at'          => self::ts_schema(),
                'updated_at'          => self::ts_schema(),
            ),
        );
    }

    /**
     * Keep each read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_get_backup_status(): void {
        wp_register_ability(
            'mainwp/get-backup-status-v1',
            array(
                'label'               => __( 'Get Backup Status', 'mainwp' ),
                'description'         => __( 'Last known backup per site across the fleet: the latest native snapshot, a backup time the site reported, or a legacy task run that left a backup archive, with a staleness threshold, the backup health bucket and the pre-update backup check verdict. Use it to answer which sites need a backup. Does not list snapshots; use list-backup-snapshots-v1 for one site\'s history. Possible errors: none beyond authentication and rights.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::get_backup_status_input_schema(),
                'output_schema'       => self::get_backup_status_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_backup_status' ),
                'permission_callback' => self::permission_callback( 'mainwp/get-backup-status-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => 'Sites with no known backup from any covered source report source none and count as stale. health comes from native snapshots only; update_gate uses the same evidence as the pre-update backup check. The evidence fields say what each status proves. Backups made by plugins the Dashboard cannot see are not covered.',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_status_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'additionalProperties' => false,
            'properties'           => array(
                'threshold_days'      => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 365,
                    'default'     => 7,
                    'description' => 'A site is stale when its last backup is older than this many days.',
                ),
                'only_stale'          => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Return only stale sites in items. Totals describe every site in scope, before this filter.',
                ),
                'site_ids_or_domains' => array(
                    'type'        => 'array',
                    'maxItems'    => 100,
                    'uniqueItems' => true,
                    'default'     => array(),
                    'items'       => self::site_id_or_domain_schema(),
                    'description' => 'Limit to these sites. Empty means every site you can access. Sites that do not resolve or that you cannot access are left out silently.',
                ),
                'page'                => self::page_schema(),
                'per_page'            => self::per_page_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_status_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'items', 'totals', 'page', 'per_page', 'filters', 'native_backups_enabled', 'update_gate_policy', 'note' ),
            'properties'           => array(
                'items'                  => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'site_id', 'url', 'name', 'last_backup', 'last_attempted', 'source', 'is_stale', 'latest_snapshot', 'health', 'update_gate' ),
                        'properties'           => array(
                            'site_id'         => array(
                                'type' => 'integer',
                            ),
                            'url'             => array(
                                'type' => 'string',
                            ),
                            'name'            => array(
                                'type' => 'string',
                            ),
                            'last_backup'     => self::ts_schema(),
                            'last_attempted'  => self::ts_schema(),
                            'source'          => array(
                                'type' => 'string',
                                'enum' => array( 'native_snapshot', 'child_reported', 'legacy_task', 'none' ),
                            ),
                            'is_stale'        => array(
                                'type' => 'boolean',
                            ),
                            'latest_snapshot' => array(
                                'type'                 => array( 'object', 'null' ),
                                'additionalProperties' => false,
                                'required'             => array( 'id', 'type', 'created_at', 'evidence', 'offsite', 'test_restore' ),
                                'properties'           => array(
                                    'id'           => array(
                                        'type'    => 'integer',
                                        'minimum' => 1,
                                    ),
                                    'type'         => array(
                                        'type' => 'string',
                                        'enum' => array( 'full', 'db', 'files', 'incremental' ),
                                    ),
                                    'created_at'   => self::ts_schema(),
                                    'evidence'     => self::snapshot_evidence_schema(),
                                    'offsite'      => self::offsite_schema(),
                                    'test_restore' => self::test_restore_schema(),
                                ),
                            ),
                            'health'          => array(
                                'type'        => 'string',
                                'enum'        => array( 'protected', 'at_risk', 'critical', 'unknown' ),
                                'description' => 'Same buckets as the Backup Health widget. protected needs a recent snapshot verified offsite.',
                            ),
                            'update_gate'     => array(
                                'type'                 => 'object',
                                'additionalProperties' => false,
                                'required'             => array( 'applies', 'verdict' ),
                                'properties'           => array(
                                    'applies' => array(
                                        'type'        => 'boolean',
                                        'description' => 'Whether the pre-update backup check applies to this site.',
                                    ),
                                    'verdict' => array(
                                        'type' => 'string',
                                        'enum' => array( 'pass', 'pending_offsite', 'missing', 'not_applicable' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'totals'                 => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'sites', 'stale', 'never', 'by_health' ),
                    'properties'           => array(
                        'sites'     => array(
                            'type' => 'integer',
                        ),
                        'stale'     => array(
                            'type' => 'integer',
                        ),
                        'never'     => array(
                            'type' => 'integer',
                        ),
                        'by_health' => array(
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => array( 'protected', 'at_risk', 'critical', 'unknown' ),
                            'properties'           => array(
                                'protected' => array(
                                    'type' => 'integer',
                                ),
                                'at_risk'   => array(
                                    'type' => 'integer',
                                ),
                                'critical'  => array(
                                    'type' => 'integer',
                                ),
                                'unknown'   => array(
                                    'type' => 'integer',
                                ),
                            ),
                        ),
                    ),
                ),
                'page'                   => array(
                    'type' => 'integer',
                ),
                'per_page'               => array(
                    'type' => 'integer',
                ),
                'filters'                => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'threshold_days', 'only_stale', 'site_ids' ),
                    'properties'           => array(
                        'threshold_days' => array(
                            'type' => 'integer',
                        ),
                        'only_stale'     => array(
                            'type' => 'boolean',
                        ),
                        'site_ids'       => array(
                            'type'        => 'array',
                            'items'       => array(
                                'type' => 'integer',
                            ),
                            'description' => 'The requested sites after resolution, as IDs.',
                        ),
                    ),
                ),
                'native_backups_enabled' => array(
                    'type' => 'boolean',
                ),
                'update_gate_policy'     => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'enabled', 'max_age_days', 'require_offsite' ),
                    'properties'           => array(
                        'enabled'         => array(
                            'type' => 'boolean',
                        ),
                        'max_age_days'    => array(
                            'type' => 'integer',
                        ),
                        'require_offsite' => array(
                            'type' => 'boolean',
                        ),
                    ),
                ),
                'note'                   => array(
                    'type' => 'string',
                ),
            ),
        );
    }

    /**
     * Keep each read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_list_backup_history(): void {
        wp_register_ability(
            'mainwp/list-backup-history-v1',
            array(
                'label'               => __( 'List Backup History', 'mainwp' ),
                'description'         => __( 'Record of backup runs per task and site for the last 90 days: what started each run (a schedule, a user, an agent, the pre-update backup check, or a restore\'s safety backup), its outcome and error code, the snapshot it produced, and updates that overrode the pre-update backup check. Use it for what happened earlier; get-backup-task-v1 carries only the current or last run. Possible errors: mainwp_site_not_found, mainwp_backup_task_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::list_backup_history_input_schema(),
                'output_schema'       => self::list_backup_history_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_backup_history' ),
                'permission_callback' => self::permission_callback( 'mainwp/list-backup-history-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => 'A running row records an unclosed run, not proof that work is still active. Deleted sites and resets or scope pauses awaiting reconciliation can leave rows running until the next claim or the 90-day prune.',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_backup_history_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'additionalProperties' => false,
            'properties'           => array(
                'site_id_or_domain' => self::site_id_or_domain_schema(),
                'task_id'           => self::task_id_schema(),
                'outcome'           => array(
                    'type' => 'string',
                    'enum' => array( 'running', 'completed', 'failed', 'cancelled', 'reset', 'incomplete', 'gate_overridden' ),
                ),
                'trigger'           => array(
                    'type' => 'string',
                    'enum' => array( 'schedule', 'manual', 'ability', 'update_gate', 'restore_safety' ),
                ),
                'days'              => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 90,
                    'default'     => 30,
                    'description' => 'How many days back to read. Rows older than 90 days are pruned.',
                ),
                'page'              => self::page_schema(),
                'per_page'          => self::per_page_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_backup_history_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'items', 'total', 'page', 'per_page', 'retention_days' ),
            'properties'           => array(
                'items'          => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => array( 'id', 'task_id', 'task_name', 'site_id', 'site_name', 'trigger', 'initiator_user_id', 'outcome', 'started_at', 'finished_at', 'error', 'snapshot_recorded', 'snapshot_id', 'restore_job_id', 'ref' ),
                        'properties'           => array(
                            'id'                => array(
                                'type'    => 'integer',
                                'minimum' => 1,
                            ),
                            'task_id'           => self::id_or_null_schema(),
                            'task_name'         => array(
                                'type' => array( 'string', 'null' ),
                            ),
                            'site_id'           => array(
                                'type'    => 'integer',
                                'minimum' => 1,
                            ),
                            'site_name'         => array(
                                'type'        => array( 'string', 'null' ),
                                'description' => 'Null when the site was deleted.',
                            ),
                            'trigger'           => array(
                                'type' => 'string',
                                'enum' => array( 'schedule', 'manual', 'ability', 'update_gate', 'restore_safety' ),
                            ),
                            'initiator_user_id' => array(
                                'type'        => 'integer',
                                'minimum'     => 0,
                                'description' => 'Dashboard user who started the work; 0 for update_gate, restore_safety or cron with no owner.',
                            ),
                            'outcome'           => array(
                                'type' => 'string',
                                'enum' => array( 'running', 'completed', 'failed', 'cancelled', 'reset', 'incomplete', 'gate_overridden' ),
                            ),
                            'started_at'        => self::ts_schema(),
                            'finished_at'       => self::ts_schema(),
                            'error'             => array_merge(
                                self::safe_error_or_null_schema(),
                                array(
                                    'description' => 'Why the run did not complete, as a code with its fixed message, for failed, cancelled, reset and incomplete rows; null otherwise.',
                                )
                            ),
                            'snapshot_recorded' => array(
                                'type'        => 'boolean',
                                'description' => 'False for completed pre-update backups: they are downloaded but never recorded as a snapshot.',
                            ),
                            'snapshot_id'       => self::id_or_null_schema(),
                            'restore_job_id'    => array_merge(
                                self::id_or_null_schema(),
                                array(
                                    'description' => 'The restore job a restore_safety backup was taken for; pass it to get-restore-job-v1. Null for other triggers.',
                                )
                            ),
                            'ref'               => array(
                                'type'        => array( 'string', 'null' ),
                                'maxLength'   => 191,
                                'description' => 'The child site\'s backup task handle for update_gate and restore_safety rows, or the queued update job id for gate_overridden rows. Null otherwise.',
                            ),
                        ),
                    ),
                ),
                'total'          => array(
                    'type' => 'integer',
                ),
                'page'           => array(
                    'type' => 'integer',
                ),
                'per_page'       => array(
                    'type' => 'integer',
                ),
                'retention_days' => array(
                    'type' => 'integer',
                    'enum' => array( 90 ),
                ),
            ),
        );
    }

    /**
     * Keep each read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_list_backup_destinations(): void {
        wp_register_ability(
            'mainwp/list-backup-destinations-v1',
            array(
                'label'               => __( 'List Backup Destinations', 'mainwp' ),
                'description'         => __( 'Storage destinations: name, provider, default flag, non-secret location (bucket, region, endpoint, host, port), whether stored credentials failed to load, and how many scheduled tasks use each. Never returns credentials, usernames, key ids or paths. Possible errors: none beyond authentication and rights.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::list_backup_destinations_input_schema(),
                'output_schema'       => self::list_backup_destinations_output_schema(),
                'execute_callback'    => array( self::class, 'execute_list_backup_destinations' ),
                'permission_callback' => self::permission_callback( 'mainwp/list-backup-destinations-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => '',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_backup_destinations_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'additionalProperties' => false,
            'properties'           => array(
                'provider' => array(
                    'type'    => 'string',
                    'enum'    => array( 'any', 's3', 'gcs', 'dropbox', 'sftp', 'local' ),
                    'default' => 'any',
                ),
                'page'     => self::page_schema(),
                'per_page' => self::per_page_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function list_backup_destinations_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'items', 'total', 'page', 'per_page' ),
            'properties'           => array(
                'items'    => array(
                    'type'  => 'array',
                    'items' => self::destination_schema(),
                ),
                'total'    => array(
                    'type' => 'integer',
                ),
                'page'     => array(
                    'type' => 'integer',
                ),
                'per_page' => array(
                    'type' => 'integer',
                ),
            ),
        );
    }

    /**
     * Keep each read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_get_backup_destination(): void {
        wp_register_ability(
            'mainwp/get-backup-destination-v1',
            array(
                'label'               => __( 'Get Backup Destination', 'mainwp' ),
                'description'         => __( 'One storage destination with the same fields as list-backup-destinations-v1. Possible errors: mainwp_backup_destination_not_found.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::get_backup_destination_input_schema(),
                'output_schema'       => self::get_backup_destination_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_backup_destination' ),
                'permission_callback' => self::permission_callback( 'mainwp/get-backup-destination-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => '',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_destination_input_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'destination_id' ),
            'properties'           => array(
                'destination_id' => self::destination_id_schema(),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_destination_output_schema(): array {
        return self::destination_schema();
    }

    /**
     * Keep each read's schemas and access checks together for discovery.
     *
     * @return void
     */
    private static function register_get_backup_settings(): void {
        wp_register_ability(
            'mainwp/get-backup-settings-v1',
            array(
                'label'               => __( 'Get Backup Settings', 'mainwp' ),
                'description'         => __( 'Native backups on or off, primary backup method, on-demand retention count, concurrency limit, diagnostics flag, the pre-update backup check policy, and whether encryption is on and a recovery passphrase is set. Never returns key material. Possible errors: none beyond authentication and rights.', 'mainwp' ),
                'category'            => 'mainwp-backups',
                'input_schema'        => self::get_backup_settings_input_schema(),
                'output_schema'       => self::get_backup_settings_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_backup_settings' ),
                'permission_callback' => self::permission_callback( 'mainwp/get-backup-settings-v1' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                        'instructions' => '',
                    ),
                ),
            )
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_settings_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'additionalProperties' => false,
            'properties'           => array(),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function get_backup_settings_output_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'native_backups_enabled', 'primary_backup_method', 'on_demand_retention_count', 'max_concurrent_backups', 'diagnostics_enabled', 'update_gate', 'encryption' ),
            'properties'           => array(
                'native_backups_enabled'    => array(
                    'type' => 'boolean',
                ),
                'primary_backup_method'     => array(
                    'type'        => 'string',
                    'description' => 'Empty when native backups (or none) are the primary method; otherwise the backup extension slug.',
                ),
                'on_demand_retention_count' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'As stored. No maximum: the Settings form accepts any value of 1 or more.',
                ),
                'max_concurrent_backups'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'As stored; no maximum.',
                ),
                'diagnostics_enabled'       => array(
                    'type' => 'boolean',
                ),
                'update_gate'               => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'enabled', 'max_age_days', 'require_offsite' ),
                    'properties'           => array(
                        'enabled'         => array(
                            'type' => 'boolean',
                        ),
                        'max_age_days'    => array(
                            'type'    => 'integer',
                            'minimum' => 1,
                        ),
                        'require_offsite' => array(
                            'type' => 'boolean',
                        ),
                    ),
                ),
                'encryption'                => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => array( 'enabled', 'recovery_passphrase_set' ),
                    'properties'           => array(
                        'enabled'                 => array(
                            'type'        => 'boolean',
                            'description' => 'Archive encryption is switched on in the backup settings.',
                        ),
                        'recovery_passphrase_set' => array(
                            'type'        => 'boolean',
                            'description' => 'A recovery passphrase is stored (wrapped key present). Never returns key material.',
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function destination_id_schema(): array {
        return array(
            'type'        => array( 'integer', 'string' ),
            'minimum'     => 1,
            'pattern'     => '^[1-9][0-9]*$',
            'description' => 'Storage destination ID.',
        );
    }

    /**
     * Closed schemas prevent undocumented input or evidence from escaping.
     *
     * @return array
     */
    private static function destination_schema(): array {
        return array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => array( 'id', 'name', 'provider', 'is_default', 'location', 'credentials_invalid', 'task_count', 'created_at', 'updated_at' ),
            'properties'           => array(
                'id'                  => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                ),
                'name'                => array(
                    'type' => 'string',
                ),
                'provider'            => array(
                    'type' => 'string',
                    'enum' => array( 's3', 'gcs', 'dropbox', 'sftp', 'local' ),
                ),
                'is_default'          => array(
                    'type' => 'boolean',
                ),
                'location'            => array(
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'description'          => 'Non-credential, non-path location fields only. Access keys, app keys, usernames, host fingerprints, every secret, and every filesystem or folder path are never returned.',
                    'properties'           => array(
                        'bucket'     => array(
                            'type' => 'string',
                        ),
                        'region'     => array(
                            'type' => 'string',
                        ),
                        'endpoint'   => array(
                            'type' => 'string',
                        ),
                        'path_style' => array(
                            'type' => 'boolean',
                        ),
                        'host'       => array(
                            'type' => 'string',
                        ),
                        'port'       => array(
                            'type'    => 'integer',
                            'minimum' => 1,
                            'maximum' => 65535,
                        ),
                    ),
                ),
                'credentials_invalid' => array(
                    'type'        => 'boolean',
                    'description' => 'The stored settings could not be decrypted. Fix it on the Backup Storage page.',
                ),
                'task_count'          => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                ),
                'created_at'          => self::ts_schema(),
                'updated_at'          => self::ts_schema(),
            ),
        );
    }

    /**
     * Rights must be checked before target resolution, including machine calls.
     *
     * @param string $ability Registered ability name.
     * @param mixed  $input Caller input.
     * @return true|\WP_Error
     */
    public static function authorize( string $ability, $input ) {
        try {
            return self::authorize_input( $ability, $input );
        } catch ( \Throwable $exception ) {
            return self::safe_wp_error( new \WP_Error( 'mainwp_internal_error', '', array( 'status' => 500 ) ) );
        }
    }

    /**
     * Service authorization remains independent of native backup enablement.
     *
     * @param string $ability Registered ability name.
     * @param mixed  $input Caller input.
     * @return true|\WP_Error
     */
    private static function authorize_input( string $ability, $input ) {
        $base = MainWP_Abilities_Util::check_rest_api_permission();
        if ( is_wp_error( $base ) ) {
            return self::safe_wp_error( $base );
        }
        if ( ! array_key_exists( $ability, self::READ_RIGHTS ) ) {
            return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
        }
        $right = self::READ_RIGHTS[ $ability ];
        if ( '' !== $right && ! MainWP_Abilities_Util::current_user_has_operation_right( $right ) ) {
            return self::safe_wp_error(
                new \WP_Error(
                    'mainwp_insufficient_right',
                    '',
                    array(
                        'status' => 403,
                        'right'  => $right,
                    )
                )
            );
        }
        $input = MainWP_Abilities_Util::normalize_input( $input );
        foreach ( array(
            'task_id'        => 'authorize_task',
            'snapshot_id'    => 'authorize_snapshot',
            'restore_job_id' => 'authorize_restore_job',
            'destination_id' => 'authorize_destination',
        ) as $key => $method ) {
            if ( isset( $input[ $key ] ) ) {
                $id = self::positive_int( $input[ $key ] );
                if ( null === $id ) {
                    return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
                }
                $target = 'task_id' === $key ? MainWP_Backup_Operations::$method( $id, $right ) : MainWP_Backup_Operations::$method( $id );
                if ( is_wp_error( $target ) ) {
                    return self::safe_wp_error( $target );
                }
            }
        }
        if ( isset( $input['site_id_or_domain'] ) ) {
            $site = MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] );
            if ( is_wp_error( $site ) ) {
                return self::safe_wp_error( $site );
            }
            $site = MainWP_Backup_Operations::authorize_site( (int) $site->id, $right );
            if ( is_wp_error( $site ) ) {
                return self::safe_wp_error( $site );
            }
        }
        return true;
    }

    /**
     * REST rewrites permission errors to 403, so target errors wait for execute.
     *
     * @param string $ability Ability name.
     * @return callable
     */
    private static function permission_callback( string $ability ): callable {
        return static function ( $input = null ) use ( $ability ) {
            $result = self::authorize( $ability, $input );
            if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'mainwp_invalid_input', 'mainwp_site_not_found', 'mainwp_backup_task_not_found', 'mainwp_backup_snapshot_not_found', 'mainwp_backup_restore_job_not_found', 'mainwp_backup_destination_not_found' ), true ) ) {
                return true;
            }
            return $result;
        };
    }

    /**
     * The one id an input names, used for both authorization and the read.
     *
     * Decimal strings ("+5", " 05 ", "5.0") are read digit by digit, so ids past 2^53 keep every digit, and
     * anything past PHP_INT_MAX is refused. Floats and exponent strings ("5e0") are exact only below 2^53;
     * at or past it one float stands for several ids, so they are refused.
     *
     * @param mixed $value Caller identifier.
     * @return int|null
     */
    private static function positive_int( $value ): ?int {
        if ( is_int( $value ) ) {
            return $value > 0 ? $value : null;
        }
        if ( is_string( $value ) && preg_match( '/^\s*\+?0*([1-9][0-9]*)(?:\.0*)?\s*$/D', $value, $match ) ) {
            $digits = $match[1];
            return strlen( $digits ) < 19 || ( 19 === strlen( $digits ) && strcmp( $digits, (string) PHP_INT_MAX ) <= 0 ) ? (int) $digits : null;
        }
        if ( ! ( is_float( $value ) || is_string( $value ) ) || ! rest_is_integer( $value ) ) {
            return null;
        }
        $number = (float) $value;
        return is_finite( $number ) && $number >= 1 && $number < 9007199254740992.0 ? (int) $number : null;
    }

    /**
     * Stored text and arbitrary error data must never reach an ability caller.
     *
     * @param \WP_Error $error Source refusal.
     * @return \WP_Error
     */
    private static function safe_wp_error( \WP_Error $error ): \WP_Error {
        $safe   = MainWP_Backup_Operations::safe_error( $error->get_error_code() );
        $source = $error->get_error_data();
        $data   = array( 'status' => is_array( $source ) && isset( $source['status'] ) ? (int) $source['status'] : 500 );
        if ( 'mainwp_insufficient_right' === $safe['code'] && is_array( $source ) && isset( $source['right'] ) && in_array( $source['right'], self::READ_RIGHTS, true ) ) {
            $data['right'] = $source['right'];
        }
        return new \WP_Error( $safe['code'], $safe['message'], $data );
    }

    /**
     * Schema projection excludes neutral source text and private record members.
     *
     * @param mixed  $value Neutral evidence.
     * @param array  $schema Output contract.
     * @param string $field Output field name.
     * @return mixed
     */
    private static function serialize( $value, array $schema, string $field = '' ) {
        if ( null === $value ) {
            return null;
        }
        if ( isset( $schema['properties']['code'], $schema['properties']['message'] ) ) {
            return MainWP_Backup_Operations::safe_error( (string) ( $value['code'] ?? '' ) );
        }
        if ( isset( $schema['properties'] ) ) {
            $output = array();
            foreach ( $schema['properties'] as $key => $property ) {
                if ( array_key_exists( $key, $value ) ) {
                    $output[ $key ] = self::serialize( $value[ $key ], $property, $key );
                }
            }
            // An empty PHP array encodes as JSON [], which typed clients reject for an object.
            return array() === $output ? new \stdClass() : $output;
        }
        if ( isset( $schema['items'] ) ) {
            return array_map(
                static function ( $item ) use ( $schema ) {
                    return self::serialize( $item, $schema['items'] );
                },
                $value
            );
        }
        if ( is_int( $value ) && ( '_at' === substr( $field, -3 ) || in_array( $field, array( 'last_backup', 'last_attempted' ), true ) ) ) {
            return $value > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $value ) : null;
        }
        return $value;
    }

    /**
     * Execute repeats authorization because callers can invoke callbacks directly.
     *
     * @param string $name Ability slug without namespace or version.
     * @param mixed  $input Caller input.
     * @return array|\WP_Error
     */
    private static function execute_read( string $name, $input ) {
        try {
            return self::read_result( $name, $input );
        } catch ( \Throwable $exception ) {
            return self::safe_wp_error( new \WP_Error( 'mainwp_internal_error', '', array( 'status' => 500 ) ) );
        }
    }

    /**
     * Only an authorized input reaches a pure reader and schema projection.
     *
     * @param string $name Ability slug.
     * @param mixed  $input Caller input.
     * @return array|\WP_Error
     */
    private static function read_result( string $name, $input ) {
        $ability = 'mainwp/' . str_replace( '_', '-', $name ) . '-v1';
        $allowed = self::authorize( $ability, $input );
        if ( is_wp_error( $allowed ) ) {
            return $allowed;
        }
        $schema_method = $name . '_input_schema';
        $valid         = rest_validate_value_from_schema( $input, self::$schema_method() );
        if ( is_wp_error( $valid ) ) {
            return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
        }
        $input = MainWP_Abilities_Util::normalize_input( $input );
        // authorize() checked the id positive_int() names; the read must use that same id, not a second cast.
        foreach ( array( 'task_id', 'snapshot_id', 'restore_job_id', 'destination_id' ) as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $input[ $key ] = self::positive_int( $input[ $key ] );
            }
        }
        // Schema validation accepts "false" and comma lists; REST coerces them, so PHP callers get the same reading.
        foreach ( array( 'only_stale', 'include_pruned' ) as $flag ) {
            if ( isset( $input[ $flag ] ) ) {
                $input[ $flag ] = MainWP_Abilities_Util::get_boolean_input( $input, $flag );
            }
        }
        if ( isset( $input['site_ids_or_domains'] ) && ! is_array( $input['site_ids_or_domains'] ) ) {
            $input['site_ids_or_domains'] = wp_parse_list( $input['site_ids_or_domains'] );
        }
        $site = isset( $input['site_id_or_domain'] ) ? MainWP_Abilities_Util::resolve_site( $input['site_id_or_domain'] ) : null;
        if ( is_wp_error( $site ) ) {
            return self::safe_wp_error( $site );
        }
        $site_id  = $site ? (int) $site->id : null;
        $page     = (int) ( $input['page'] ?? 1 );
        $per_page = (int) ( $input['per_page'] ?? 50 );
        switch ( $name ) {
            case 'get_backup_status':
                $result = MainWP_Backup_Read_Model::get_backup_status( (int) ( $input['threshold_days'] ?? 7 ), $input['only_stale'] ?? false, $input['site_ids_or_domains'] ?? array(), $page, $per_page );
                break;
            case 'list_backup_history':
                $filters = array_intersect_key( $input, array_flip( array( 'task_id', 'outcome', 'trigger', 'days' ) ) );
                if ( null !== $site_id ) {
                    $filters['site_id'] = $site_id;
                }
                $result = MainWP_Backup_Read_Model::list_history( $filters, $page, $per_page );
                break;
            case 'list_backup_destinations':
                $result = MainWP_Backup_Read_Model::list_destinations( $input['provider'] ?? 'any', $page, $per_page );
                break;
            case 'get_backup_destination':
                $result = MainWP_Backup_Read_Model::get_destination( (int) $input['destination_id'] );
                break;
            case 'get_backup_settings':
                $result = MainWP_Backup_Read_Model::get_settings();
                break;
            case 'list_backup_tasks':
                $result = MainWP_Backup_Read_Model::list_tasks( $input['status'] ?? 'any', $site_id, $page, $per_page );
                break;
            case 'get_backup_task':
            case 'get_backup_task_run':
                $task = MainWP_Backup_Operations::authorize_task( (int) $input['task_id'], self::READ_RIGHTS[ $ability ] );
                if ( is_wp_error( $task ) ) {
                    return self::safe_wp_error( $task );
                }
                $result = 'get_backup_task' === $name ? MainWP_Backup_Read_Model::get_task( $task ) : MainWP_Backup_Read_Model::task_run_details( $task );
                break;
            case 'get_site_backup_progress':
                $result = MainWP_Backup_Read_Model::site_backup_state( $site_id, (int) ( $input['since'] ?? 0 ) );
                break;
            case 'list_backup_snapshots':
                $result = MainWP_Backup_Read_Model::query_backup_snapshots( $site_id, $input['include_pruned'] ?? false, $page, $per_page );
                break;
            case 'get_backup_snapshot':
                $result = MainWP_Backup_Read_Model::snapshot_details( (int) $input['snapshot_id'] );
                break;
            case 'list_restore_jobs':
                $result = MainWP_Backup_Read_Model::query_restore_jobs( $site_id, $input['outcome'] ?? 'any', $page, $per_page );
                break;
            case 'get_restore_job':
                $result = MainWP_Backup_Read_Model::restore_job_details( (int) $input['restore_job_id'] );
                break;
            default:
                return self::safe_wp_error( new \WP_Error( 'mainwp_invalid_input', '', array( 'status' => 400 ) ) );
        }
        if ( is_wp_error( $result ) ) {
            return self::safe_wp_error( $result );
        }
        $schema_method = $name . '_output_schema';
        return self::serialize( $result, self::$schema_method() );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_list_backup_tasks( $input = null ) {
        return self::execute_read( 'list_backup_tasks', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_get_backup_task( $input = null ) {
        return self::execute_read( 'get_backup_task', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_get_backup_task_run( $input = null ) {
        return self::execute_read( 'get_backup_task_run', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_get_site_backup_progress( $input = null ) {
        return self::execute_read( 'get_site_backup_progress', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_list_backup_snapshots( $input = null ) {
        return self::execute_read( 'list_backup_snapshots', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_get_backup_snapshot( $input = null ) {
        return self::execute_read( 'get_backup_snapshot', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_list_restore_jobs( $input = null ) {
        return self::execute_read( 'list_restore_jobs', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_get_restore_job( $input = null ) {
        return self::execute_read( 'get_restore_job', $input );
    }
    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_get_backup_status( $input = null ) {
        return self::execute_read( 'get_backup_status', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_list_backup_history( $input = null ) {
        return self::execute_read( 'list_backup_history', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_list_backup_destinations( $input = null ) {
        return self::execute_read( 'list_backup_destinations', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_get_backup_destination( $input = null ) {
        return self::execute_read( 'get_backup_destination', $input );
    }

    /**
     * Rechecking access prevents direct callback calls from bypassing permissions.
     *
     * @param mixed $input Caller input.
     * @return array|\WP_Error
     */
    public static function execute_get_backup_settings( $input = null ) {
        return self::execute_read( 'get_backup_settings', $input );
    }
}
